<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.xlsx.php";
include "../../include/package.export.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.suivi.php";
include "../../include/package.message.php";
include "../../include/package.ics.php";
include "../../include/package.automate.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Rdv = new Rdv();
$Interaction = new Interaction();
$Tache = new Tache();
$Suivi = new Suivi();
$Message = new Message();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Rendez-vous (CDC §11). Du dossier, seule l'identité est servie ; motif et compte rendu, avec le droit famille.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    // Fiche ################################
    // GET ?id=N : le rendez-vous, la chaîne de ses reports (du premier créneau au dernier) et leur historique

    if (isset($_GET['id'])) {
        $id = (is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
        list($user, $rdv) = $Rdv->exigerRdv($id, 'L');
        $famille = $U->can($user, 'famille', 'L');

        $chaine = $Rdv->chaine($rdv, $famille);

        // Ce que le parent a déclaré en réservant en ligne : la note se lit avec le droit famille, comme toute donnée familiale
        $reservation = null;
        $declare = $Rdv->reservation($rdv);
        if ($declare !== null) {
            $reservation = array('voie' => $declare->voie, 'date' => $declare->date_creation);
            if ($famille) {
                $reservation += array('prenom' => $declare->prenom, 'nom' => $declare->nom, 'email' => $declare->email, 'telephone' => $declare->telephone, 'note' => $declare->note);
            }
        }

        $Response->success(array(
            'rdv' => $Rdv->sortie($rdv, $famille),
            'chaine' => $chaine,
            'reservation' => $reservation,
            // E-mails partis au parent pour ce rendez-vous, tous créneaux confondus
            'messages' => $Message->duDossier((int) $rdv->id_contact, $user, array_column($chaine, 'id_rdv')),
            'historique' => $Rdv->historique(array_column($chaine, 'id_rdv')),
            'proposition' => in_array($rdv->statut, array('annule', 'absent'), true) ? $Rdv->proposition($rdv->id_contact) : null,
        ));
    }

    // Agenda ################################
    // GET ?du=AAAA-MM-JJ&au=AAAA-MM-JJ : les rendez-vous de la période (62 jours au plus) qui tiennent ou ont eu lieu
    // (à confirmer, confirmé, effectué, absent), dans l'ordre, et les demandes encore sans créneau.
    // Les rendez-vous annulés ou déplacés se lisent dans le dossier et sur leur fiche.

    $user = $U->requireAccess('rendez_vous', 'L');
    $famille = $U->can($user, 'famille', 'L');

    $lisibles = $Contact->conditionLisibles($user);
    if ($lisibles === null) {
        $Response->forbidden("Vous n'avez pas accès aux dossiers.");
    }

    $du = $S->dateFiltre('du');
    $au = $S->dateFiltre('au');
    if ($du === null || $au === null || $au < $du) {
        $Response->validationError("Erreur paramètres DU et AU manquants (AAAA-MM-JJ)");
    }
    if ((strtotime($au) - strtotime($du)) / 86400 > 62) {
        $Response->validationError("La période ne peut pas dépasser 62 jours.");
    }

    $maintenant = date('Y-m-d H:i:s');
    $rdv = array();
    foreach ($Mysql->fetchAll(
        "SELECT " . Rdv::COLONNES . Rdv::SQL_FROM . "
         WHERE " . $lisibles[0] . " AND r.statut IN ('a_confirmer', 'confirme', 'effectue', 'absent')
           AND r.date_debut >= ? AND r.date_debut < ? + INTERVAL 1 DAY
         ORDER BY r.date_debut, r.id_rdv",
        array_merge($lisibles[1], array($du . ' 00:00:00', $au))
    ) as $r) {
        $rdv[] = $Rdv->sortie($r, $famille, $maintenant);
    }

    $demandes = array();
    foreach ($Mysql->fetchAll(
        "SELECT " . Rdv::COLONNES . Rdv::SQL_FROM . "
         WHERE " . $lisibles[0] . " AND r.statut = 'demande' AND c.date_archivage IS NULL
         ORDER BY r.date_statut, r.id_rdv",
        $lisibles[1]
    ) as $r) {
        $demandes[] = $Rdv->sortie($r, $famille, $maintenant);
    }

    // &format=xlsx : les rendez-vous de la période puis les demandes sans créneau, sans motif ni compte rendu
    Export::siDemande('rendez_vous', $user, array_merge($rdv, $demandes), count($rdv) + count($demandes));
    $Response->success(array('rdv' => $rdv, 'demandes' => $demandes, 'du' => $du, 'au' => $au, 'aujourdhui' => substr($maintenant, 0, 10)));
}

// Prise de rendez-vous ################################
// POST {id_contact, type?, canal?, duree?, motif?, id_users_responsable?, date?, heure?, statut?, prevenir?, cle_saisie?}
// prevenir (0|1) : la case « Prévenir le parent par e-mail ». Le rendez-vous garde ce choix ; le parent n'est prévenu
// que d'un créneau confirmé (confirmation avec invitation de calendrier, puis modification, annulation, rappel).
// Sans date ni heure : une demande, à planifier. Avec : « à confirmer » (défaut) ou « confirmé ».
// Un prospect avance tout seul : « RDV demandé », puis « RDV planifié » quand un créneau à venir est fixé.
// Un chevauchement ou un créneau passé sont signalés dans `avertissements`, jamais refusés.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('rendez_vous', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list(, $contact) = $Contact->exigerDossier($idc, 'L');

    $data = $S->lireChamps($R, $Rdv->spec(), false);
    if (isset($data['motif']) && !$U->can($user, 'famille', 'C')) {
        $Response->forbidden("Le motif est une note interne : vous n'avez pas le droit de l'écrire.");
    }
    $debut = $Rdv->lireCreneau($R);

    $statut = 'a_confirmer';
    if (isset($R->statut)) {
        if (!is_string($R->statut) || !in_array($R->statut, Rdv::PREVUS, true)) {
            $Response->validationError("À la prise de rendez-vous, le statut vaut : " . implode(', ', Rdv::PREVUS) . ".");
        }
        $statut = $R->statut;
    }
    $cle = $S->lireCle($R);

    $res = $Rdv->creer($contact, $data, $debut, $statut, $cle, (int) $user->id_users, array('prevenir' => isset($R->prevenir) && (int) $R->prevenir === 1));

    $Response->success($Suivi->reponse($idc, $user, $res['avertissements'], array('id_rdv' => $res['id_rdv'])), $res['deja'] ? 200 : 201);
}

// Correction des champs descriptifs ################################
// PUT {id_rdv, type?, duree?, canal?, motif?, id_users_responsable?, prevenir?}
// prevenir : change le choix gardé par le rendez-vous. Un rendez-vous confirmé dont la durée ou le canal change : le parent est prévenu.
// Le statut se change par v1/rendez-vous/statut/, le créneau se déplace par v1/rendez-vous/report/.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_rdv) && is_int($R->id_rdv)) ? $R->id_rdv : 0;
    list($user, $rdv) = $Rdv->exigerRdv($id, 'C');

    $data = $S->lireChamps($R, $Rdv->spec(), true);
    foreach (array('type' => 'type', 'duree' => 'durée', 'canal' => 'canal') as $champ => $lib) {
        if (array_key_exists($champ, $data) && $data[$champ] === null) {
            $Response->validationError("Le champ « $lib » est obligatoire.");
        }
    }
    if (array_key_exists('motif', $data) && !$U->can($user, 'famille', 'C')) {
        $Response->forbidden("Le motif est une note interne : vous n'avez pas le droit de l'écrire.");
    }

    if (isset($R->prevenir)) {
        $data['prevenir'] = (int) $R->prevenir === 1 ? 1 : 0;
    }

    $Rdv->modifier($rdv, $data, (int) $user->id_users);

    $Response->success($Suivi->reponse((int) $rdv->id_contact, $user, array(), array('id_rdv' => $id)));
}

$Response->methodNotAllowed();

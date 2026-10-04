<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.suivi.php";
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

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Appels et journal des interactions (CDC §12) : des faits, jamais dans le futur.
// Un appel à passer est une tâche de nature « appel » (v1/taches/?nature=appel). Motif et compte rendu : droit famille.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    // Journal ################################
    // GET ?canal=a,b&resultat=a,b&du=&au=&q=&id_contact=&sort=date|nom&dir=&page=&limit=

    $user = $U->requireAccess('appels', 'L');
    $famille = $U->can($user, 'famille', 'L');

    $where = array();
    $params = array();

    $lisibles = $Contact->conditionLisibles($user);
    if ($lisibles === null) {
        $Response->forbidden("Vous n'avez pas accès aux dossiers.");
    }
    $where[] = $lisibles[0];
    $params = array_merge($params, $lisibles[1]);

    foreach (array('canal' => Interaction::CANAUX, 'resultat' => Interaction::RESULTATS) as $cle => $valeurs) {
        if (isset($_GET[$cle]) && is_string($_GET[$cle]) && $_GET[$cle] !== '') {
            $choix = array_values(array_intersect(explode(',', $_GET[$cle]), $valeurs));
            if (count($choix) === 0) {
                $Response->validationError("Filtre inconnu : $cle");
            }
            $where[] = "i.$cle IN (" . implode(', ', array_fill(0, count($choix), '?')) . ")";
            $params = array_merge($params, $choix);
        }
    }

    if (isset($_GET['id_contact']) && is_string($_GET['id_contact']) && ctype_digit($_GET['id_contact'])) {
        $where[] = "i.id_contact = ?";
        $params[] = (int) $_GET['id_contact'];
    }

    // Période sur le jour de l'échange
    $du = $S->dateFiltre('du');
    if ($du !== null) {
        $where[] = "i.date_interaction >= ?";
        $params[] = $du . ' 00:00:00';
    }
    $au = $S->dateFiltre('au');
    if ($au !== null) {
        $where[] = "i.date_interaction < ? + INTERVAL 1 DAY";
        $params[] = $au;
    }

    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $cond = $Contact->conditionRecherche($_GET['q']);
        if ($cond === null) {
            $Response->validationError("Saisissez entre 2 et 80 caractères pour la recherche.");
        }
        $where[] = $cond[0];
        $params = array_merge($params, $cond[1]);
    }

    $sqlWhere = " WHERE " . implode(" AND ", $where);
    $tri = $S->tri(array('date' => 'i.date_interaction', 'nom' => 'c.nom'), 'date', 'i.id_interaction DESC');
    list($page, $limit, $offset) = $S->pagination();

    $total = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb" . Interaction::SQL_FROM . $sqlWhere, $params)->nb;
    $echanges = array();
    foreach ($Mysql->fetchAll(
        "SELECT " . Interaction::COLONNES . Interaction::SQL_FROM . $sqlWhere . " ORDER BY $tri LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset))
    ) as $row) {
        $echanges[] = $Interaction->sortie($row, $famille);
    }

    $Response->success(array('echanges' => $echanges, 'total' => $total, 'page' => $page, 'limit' => $limit));
}

// Noter un échange ################################
// POST {id_contact, canal?, sens?, date, heure, duree?, resultat?, date_rappel?, motif?, compte_rendu?, id_tache?, cle_saisie?}
// canal « appel » : `resultat` obligatoire (abouti, sans_reponse, a_rappeler) ; « a_rappeler » demande `date_rappel`
// et ouvre la tâche de rappel, qu'un appel abouti refermera. `id_tache` : l'appel prévu que cet échange solde.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('appels', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list(, $contact) = $Contact->exigerDossier($idc, 'L');

    $data = $S->lireChamps($R, $Interaction->spec(), false);
    if ((isset($data['motif']) || isset($data['compte_rendu'])) && !$U->can($user, 'famille', 'C')) {
        $Response->forbidden("Le motif et le compte rendu sont des notes internes : vous n'avez pas le droit de les écrire.");
    }
    $cle = $S->lireCle($R);

    $res = $Interaction->creer($contact, $data, $cle, (int) $user->id_users);

    $Response->success($Suivi->reponse($idc, $user, array(), array('id_interaction' => $res['id_interaction'])), $res['deja'] ? 200 : 201);
}

// Corriger un échange ################################
// PUT {id_interaction, sens?, date?, heure?, duree?, resultat?, date_rappel?, motif?, compte_rendu?}. Le canal ne se change pas.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_interaction) && is_int($R->id_interaction)) ? $R->id_interaction : 0;
    list($user, $echange) = $Interaction->exigerEchange($id, 'C');

    $spec = array_diff_key($Interaction->spec(), array_flip(array('canal', 'id_tache')));
    $data = $S->lireChamps($R, $spec, true);
    if ((array_key_exists('motif', $data) || array_key_exists('compte_rendu', $data)) && !$U->can($user, 'famille', 'C')) {
        $Response->forbidden("Le motif et le compte rendu sont des notes internes : vous n'avez pas le droit de les écrire.");
    }

    $Interaction->modifier($echange, $data, (int) $user->id_users);

    $Response->success($Suivi->reponse((int) $echange->id_contact, $user, array(), array('id_interaction' => $id)));
}

// Supprimer un échange noté par erreur ################################
// DELETE ?id=N : par son auteur ou un administrateur.

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    list($user, $echange) = $Interaction->exigerEchange($id, 'C');

    if ($user->profil !== 'admin' && (int) $echange->id_users !== (int) $user->id_users) {
        $Response->forbidden("Seul l'auteur d'un échange ou un administrateur peut le supprimer.");
    }

    $Interaction->supprimer($echange, (int) $user->id_users);

    $Response->success($Suivi->reponse((int) $echange->id_contact, $user));
}

$Response->methodNotAllowed();

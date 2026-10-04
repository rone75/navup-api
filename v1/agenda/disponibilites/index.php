<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.agenda.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Rdv = new Rdv();
$Agenda = new Agenda();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Disponibilités de l'utilisateur connecté (étape 6b) : ses plages hebdomadaires, ses absences, son lien de visio,
// et ce qu'un visiteur ou un parent inscrit se voit proposer. Chacun règle les siennes : droit complet sur les rendez-vous.

/** Ce que la page affiche : les réglages de l'utilisateur et les créneaux servis en ligne, par type. */
function disponibilites($user)
{
    global $Agenda, $_RDV_PRISE, $_RDV_PRISE_OUVERTE, $_RDV_PRISE_PLAFOND;

    $idu = (int) $user->id_users;
    $prise = array();
    foreach (array_keys($_RDV_PRISE) as $type) {
        $prise[] = $Agenda->prise($type, $type === 'decouverte' ? 'public' : 'espace');
    }

    return array(
        'plages' => $Agenda->plages($idu),
        'absences' => $Agenda->absences($idu),
        'lien_visio' => $Agenda->lienVisio($idu),
        'interrupteur' => !empty($_RDV_PRISE_OUVERTE),
        'plafond_atteint' => $Agenda->reservationsDuJour() >= (int) $_RDV_PRISE_PLAFOND,
        'prise' => $prise,
        'aujourdhui' => date('Y-m-d'),
    );
}

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireAccess('rendez_vous', 'C');

    $Response->success(disponibilites($user));
}

// Enregistrement ################################
// PUT {plages: [{jour: 1-7, debut: "HH:MM", fin: "HH:MM"}], lien_visio: "https://…" | null}
// Les plages envoyées remplacent les précédentes. Sans lien de visio, la visio n'est pas proposée en ligne.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('rendez_vous', 'C');
    $idu = (int) $user->id_users;
    $R = json_decode(file_get_contents("php://input"));
    if (!is_object($R) || !isset($R->plages)) {
        $Response->validationError("Erreur paramètre PLAGES manquant");
    }
    $plages = $Agenda->lirePlages($R->plages);

    $lien = null;
    if (isset($R->lien_visio) && $R->lien_visio !== '') {
        $lien = is_string($R->lien_visio) ? trim($R->lien_visio) : '';
        if (!Agenda::lienVisioValide($lien)) {
            $Response->validationError("Le lien de visio est une adresse complète qui commence par https://.");
        }
    }

    $SQL->begin_transaction();
    $Agenda->ecrirePlages($idu, $plages);
    $Agenda->ecrireLienVisio($idu, $lien);
    $U->audit($idu, 'agenda_update', array('plages' => count($plages), 'lien_visio' => $lien !== null));
    $SQL->commit();

    $Response->success(disponibilites($user));
}

$Response->methodNotAllowed();

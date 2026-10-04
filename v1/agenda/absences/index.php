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
$Agenda = new Agenda();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Absences de l'utilisateur connecté (étape 6b) : aucun créneau n'est proposé en ligne pendant ces périodes.
// Les rendez-vous déjà pris n'en sont pas touchés. La page relit ensuite v1/agenda/disponibilites/.

// POST {du: AAAA-MM-JJ, au: AAAA-MM-JJ, heure_du?: HH:MM, heure_au?: HH:MM}
// Sans heures : des journées entières, du premier au dernier jour compris.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('rendez_vous', 'C');
    $idu = (int) $user->id_users;
    $R = json_decode(file_get_contents("php://input"));

    $c = $S->lireChamps($R, array(
        'du' => array('type' => 'date', 'requis' => true, 'min' => date('Y-m-d', strtotime('-1 day')), 'max' => date('Y-m-d', strtotime('+2 years')), 'libelle' => 'du'),
        'au' => array('type' => 'date', 'requis' => true, 'max' => date('Y-m-d', strtotime('+2 years')), 'libelle' => 'au'),
        'heure_du' => array('type' => 'heure', 'libelle' => 'heure de début'),
        'heure_au' => array('type' => 'heure', 'libelle' => 'heure de fin'),
    ), false);

    $debut = $c['du'] . ' ' . ($c['heure_du'] ?? '00:00') . ':00';
    // Sans heure de fin : jusqu'à la fin du dernier jour
    $fin = isset($c['heure_au'])
        ? $c['au'] . ' ' . $c['heure_au'] . ':00'
        : date('Y-m-d', strtotime($c['au'] . ' 12:00:00 +1 day')) . ' 00:00:00';
    if ($fin <= $debut) {
        $Response->validationError("Une absence finit après son début.");
    }
    if ($fin <= date('Y-m-d H:i:s')) {
        $Response->validationError("Cette absence est déjà passée.");
    }
    if (count($Agenda->absences($idu)) >= Agenda::ABSENCES_MAX) {
        $Response->validationError("Trop d'absences à venir : retirez-en avant d'en ajouter.");
    }

    $SQL->begin_transaction();
    $id = $S->inserer('r_indisponibilite', array('id_users' => $idu, 'date_debut' => $debut, 'date_fin' => $fin));
    $U->audit($idu, 'agenda_absence_create', array('id_indisponibilite' => $id));
    $SQL->commit();

    $Response->success(array('id_indisponibilite' => $id, 'absences' => $Agenda->absences($idu)), 201);
}

// DELETE ?id=N : retire une de ses absences

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $U->requireAccess('rendez_vous', 'C');
    $idu = (int) $user->id_users;
    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;

    $SQL->begin_transaction();
    $nb = $Mysql->execute("DELETE FROM r_indisponibilite WHERE id_indisponibilite = ? AND id_users = ?", array($id, $idu), 'ii');
    if ($nb !== 1) {
        $SQL->rollback();
        $Response->notFound("Absence introuvable.");
    }
    $U->audit($idu, 'agenda_absence_delete', array('id_indisponibilite' => $id));
    $SQL->commit();

    $Response->success(array('absences' => $Agenda->absences($idu)));
}

$Response->methodNotAllowed();

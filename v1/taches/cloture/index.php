<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../require/param.php";

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

// Marquer une tâche traitée, ou la rouvrir (CDC §14) ################################
// PUT {id_tache, traitee: 0|1}. Une tâche automatique traitée à la main ne revient pas, même si sa cause persiste :
// pour la revoir plus tard, on la reporte. Une tâche que sa cause a fermée ne se rouvre pas.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_tache) && is_int($R->id_tache)) ? $R->id_tache : 0;
    list($user, $tache) = $Tache->exigerTache($id, 'C');

    if (!isset($R->traitee) || !in_array($R->traitee, array(0, 1), true)) {
        $Response->validationError("Erreur paramètre TRAITEE manquant (0 ou 1)");
    }

    $Tache->cloturer($tache, $R->traitee === 1, (int) $user->id_users);

    $Response->success($Tache->reponse($id, $tache->id_contact === null ? null : (int) $tache->id_contact, $user));
}

$Response->methodNotAllowed();

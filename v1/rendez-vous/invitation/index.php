<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.ics.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Rdv = new Rdv();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Invitation de calendrier d'un rendez-vous (étape 6b) ################################
// GET ?id=N → {nom, ics} : le fichier que le parent reçoit avec l'e-mail de confirmation, pour le lui transmettre
// autrement ou vérifier ce qu'il a reçu. Seul un rendez-vous confirmé en a une.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    list(, $rdv) = $Rdv->exigerRdv($id, 'L');

    if ($rdv->statut !== 'confirme' || $rdv->date_debut === null) {
        $Response->validationError("Seul un rendez-vous confirmé a une invitation.");
    }

    $Response->success(array('nom' => Ics::NOM, 'ics' => Ics::invitation($rdv, $Rdv->lienVisio($rdv))));
}

$Response->methodNotAllowed();

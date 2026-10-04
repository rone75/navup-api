<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.vente.php";
include "../../include/package.message.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Message = new Message();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// E-mails envoyés au parent d'un dossier (CDC §13) ################################
// GET ?id_contact=N : les messages du dossier, du plus récent au plus ancien. Le modèle, l'état et la date sont
// lisibles par qui lit le dossier ; le corps, seulement avec le droit du module du modèle (un e-mail de paiement
// se lit avec le droit sur les paiements).

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $idc = (isset($_GET['id_contact']) && is_string($_GET['id_contact']) && ctype_digit($_GET['id_contact'])) ? (int) $_GET['id_contact'] : 0;
    list($user) = $Contact->exigerDossier($idc, 'L');

    $Response->success(array('messages' => $Message->duDossier($idc, $user)));
}

$Response->methodNotAllowed();

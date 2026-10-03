<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Chronologie d'un dossier (CDC §5) ################################
// GET ?id=N&page=&limit= : faits datés, limités aux modules que l'utilisateur peut lire

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    list($user) = $Contact->exigerDossier($id, 'L');

    list($page, $limit, $offset) = $S->pagination(30, 100);
    list($total, $evenements) = $Contact->chronologie($id, $Contact->modulesLisibles($user), $limit, $offset);

    $Response->success(array('evenements' => $evenements, 'total' => $total, 'page' => $page, 'limit' => $limit));
}

$Response->methodNotAllowed();

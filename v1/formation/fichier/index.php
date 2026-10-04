<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include_once "../../../include/package.formation.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Formation = new Formation();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Fichier d'un sujet ################################
// GET ?id=N : le fichier lui-même (audio d'écoute, fiche PDF, annexe), pour l'écouter ou l'ouvrir depuis l'outil.
//     Les médias ne sont jamais servis par Apache : ils ne sortent que d'ici, avec un jeton de session.
// DELETE ?id=N : retire un fichier, ou abandonne un téléversement en cours. Le fichier courant d'un sujet publié se remplace.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireAccess('formation', 'L');

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $fichier = $id > 0 ? $Formation->chargerFichier($id) : null;
    if ($fichier === null) {
        $Response->notFound("Fichier introuvable.");
    }

    $Formation->servir($fichier);
}

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $U->requireAccess('formation', 'C');

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $fichier = $id > 0 ? $Formation->chargerFichier($id) : null;
    if ($fichier === null) {
        $Response->notFound("Fichier introuvable.");
    }
    $Formation->supprimerFichier($fichier, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger($fichier->id_formation))));
}

$Response->methodNotAllowed();

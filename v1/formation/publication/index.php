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

// Publier ou dépublier un sujet ################################
// PUT {id_sujet, publie: 0|1}. Un sujet ne se publie qu'avec son audio et sa fiche prêts ;
// en brouillon, il n'est pas vu des parents.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('formation', 'C');

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_sujet) && is_int($R->id_sujet)) ? $R->id_sujet : 0;
    $sujet = $id > 0 ? $Formation->chargerSujet($id) : null;
    if ($sujet === null) {
        $Response->notFound("Sujet introuvable.");
    }
    if (!isset($R->publie) || !in_array($R->publie, array(0, 1), true)) {
        $Response->validationError("Erreur paramètre PUBLIE manquant (0 ou 1)");
    }
    $Formation->publier($sujet, $R->publie === 1, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger($sujet->id_formation))));
}

$Response->methodNotAllowed();

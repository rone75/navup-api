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

// Ordre des sujets d'une semaine ################################
// PUT {id_semaine, sujets: [id_sujet, …]} : la liste complète des sujets de la semaine, dans le nouvel ordre.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('formation', 'C');

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_semaine) && is_int($R->id_semaine)) ? $R->id_semaine : 0;
    $semaine = $id > 0 ? $Formation->chargerSemaine($id) : null;
    if ($semaine === null) {
        $Response->notFound("Semaine introuvable.");
    }
    if (!isset($R->sujets) || !is_array($R->sujets) || count($R->sujets) > 200) {
        $Response->validationError("Erreur paramètre SUJETS manquant (liste d'identifiants)");
    }
    $ids = array();
    foreach ($R->sujets as $v) {
        if (!is_int($v) || $v <= 0 || in_array($v, $ids, true)) {
            $Response->validationError("Erreur paramètre SUJETS invalide");
        }
        $ids[] = $v;
    }
    $Formation->ordonner($semaine, $ids, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger($semaine->id_formation))));
}

$Response->methodNotAllowed();

<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include_once "../../include/package.formation.php";
include "../../require/param.php";

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

// La formation vendue par NavUp (cahier de l'écosystème §14.4) ################################
// GET : la formation entière (semaines, sujets, fichiers, ce qui manque à chaque sujet pour être publié).
// PUT {nom, description} : son nom et sa présentation.
// Chaque écriture de la rubrique renvoie la formation entière : la page se redessine d'après la réponse.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireAccess('formation', 'L');
    $formation = $Formation->charger();
    if ($formation === null) {
        $Response->notFound("Aucune formation n'est définie.");
    }

    $Response->success(array('formation' => $Formation->sortie($formation)));
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('formation', 'C');
    $formation = $Formation->charger();
    if ($formation === null) {
        $Response->notFound("Aucune formation n'est définie.");
    }

    $R = json_decode(file_get_contents("php://input"));
    $data = $S->lireChamps($R, $Formation->specFormation(), true);
    $Formation->modifier($formation, $data, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger())));
}

$Response->methodNotAllowed();

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

// Semaines de la formation ################################
// POST {titre?, description?, decalage_jours?} : ajoute une semaine à la suite (déblocage sept jours après la précédente, par défaut).
// PUT {id_semaine, titre?, description?, decalage_jours?} : modifie une semaine.
// DELETE ?id=N : retire la dernière semaine, si elle est vide.

$formation = null;
$exiger = function () use (&$formation, $U, $Formation, $Response) {
    $user = $U->requireAccess('formation', 'C');
    $formation = $Formation->charger();
    if ($formation === null) {
        $Response->notFound("Aucune formation n'est définie.");
    }

    return $user;
};

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $exiger();
    $R = json_decode(file_get_contents("php://input"));
    $data = $S->lireChamps($R, $Formation->specSemaine(), false);
    $id = $Formation->creerSemaine($formation, $data, (int) $user->id_users);

    $Response->success(array('id_semaine' => $id, 'formation' => $Formation->sortie($Formation->charger())), 201);
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $exiger();
    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_semaine) && is_int($R->id_semaine)) ? $R->id_semaine : 0;
    $semaine = $id > 0 ? $Formation->chargerSemaine($id) : null;
    if ($semaine === null || (int) $semaine->id_formation !== (int) $formation->id_formation) {
        $Response->notFound("Semaine introuvable.");
    }
    $data = $S->lireChamps($R, $Formation->specSemaine(), true);
    if (array_key_exists('decalage_jours', $data) && $data['decalage_jours'] === null) {
        $Response->validationError("Le champ « jour de déblocage » est obligatoire.");
    }
    $Formation->modifierSemaine($semaine, $data, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger())));
}

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $exiger();
    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $semaine = $id > 0 ? $Formation->chargerSemaine($id) : null;
    if ($semaine === null || (int) $semaine->id_formation !== (int) $formation->id_formation) {
        $Response->notFound("Semaine introuvable.");
    }
    $Formation->supprimerSemaine($semaine, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger())));
}

$Response->methodNotAllowed();

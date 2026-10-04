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

// Sujets de la formation ################################
// POST {id_semaine, titre, numero?, description?, pochette?} : ajoute un sujet (en brouillon) à la fin de sa semaine.
// PUT {id_sujet, …} : modifie un sujet ; changer de semaine le place à la fin de la nouvelle.
// DELETE ?id=N : retire un sujet en brouillon, avec ses fichiers.

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
    $data = $S->lireChamps($R, $Formation->specSujet($formation->id_formation), false);
    $id = $Formation->creerSujet($formation, $data, (int) $user->id_users);

    $Response->success(array('id_sujet' => $id, 'formation' => $Formation->sortie($Formation->charger())), 201);
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $exiger();
    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_sujet) && is_int($R->id_sujet)) ? $R->id_sujet : 0;
    $sujet = $id > 0 ? $Formation->chargerSujet($id) : null;
    if ($sujet === null || (int) $sujet->id_formation !== (int) $formation->id_formation) {
        $Response->notFound("Sujet introuvable.");
    }
    $data = $S->lireChamps($R, $Formation->specSujet($formation->id_formation), true);
    foreach (array('id_semaine' => 'semaine', 'numero' => 'numéro', 'titre' => 'titre public', 'pochette' => 'pochette') as $champ => $lib) {
        if (array_key_exists($champ, $data) && $data[$champ] === null) {
            $Response->validationError("Le champ « $lib » est obligatoire.");
        }
    }
    $Formation->modifierSujet($sujet, $data, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger())));
}

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $exiger();
    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $sujet = $id > 0 ? $Formation->chargerSujet($id) : null;
    if ($sujet === null || (int) $sujet->id_formation !== (int) $formation->id_formation) {
        $Response->notFound("Sujet introuvable.");
    }
    $Formation->supprimerSujet($sujet, (int) $user->id_users);

    $Response->success(array('formation' => $Formation->sortie($Formation->charger())));
}

$Response->methodNotAllowed();

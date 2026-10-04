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

// Reporter une tâche (CDC §14) ################################
// PUT {id_tache, date_echeance} : l'échéance change sur la même tâche, qui reste ouverte. Pas avant aujourd'hui.
// Une tâche automatique reportée garde ensuite l'échéance choisie.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_tache) && is_int($R->id_tache)) ? $R->id_tache : 0;
    list($user, $tache) = $Tache->exigerTache($id, 'C');

    $champs = $S->lireChamps($R, array(
        'date_echeance' => array('type' => 'date', 'requis' => true, 'min' => date('Y-m-d'), 'max' => date('Y-m-d', strtotime('+2 years')), 'libelle' => 'nouvelle échéance'),
    ), false);

    $Tache->reporter($tache, $champs['date_echeance'], (int) $user->id_users);

    $Response->success($Tache->reponse($id, $tache->id_contact === null ? null : (int) $tache->id_contact, $user));
}

$Response->methodNotAllowed();

<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.message.php";
include "../../../include/package.ics.php";
include "../../../include/package.automate.php";
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
$Message = new Message();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Déplacer un rendez-vous (CDC §11 : replanifier sans perdre l'historique) ################################
// PUT {id_rdv, date, heure, duree?, statut?, prevenir?}
// prevenir (0|1) : la case du formulaire ; absente, le choix de l'ancien créneau suit le rendez-vous.
// Un nouveau rendez-vous, chaîné à l'ancien, prend le nouveau créneau ; l'ancien garde le sien et devient « reporté »
// (ou reste « absent » / « annulé »). Envoyé deux fois, le déplacement n'écrit qu'une fois : `id_rdv` est le nouveau rendez-vous.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_rdv) && is_int($R->id_rdv)) ? $R->id_rdv : 0;
    list($user, $rdv) = $Rdv->exigerRdv($id, 'C');

    $debut = $Rdv->lireCreneau($R, true);
    $champs = $S->lireChamps($R, array(
        'duree' => array('type' => 'int', 'min' => 5, 'max' => 600, 'libelle' => 'durée'),
        'statut' => array('type' => 'enum', 'valeurs' => Rdv::PREVUS, 'libelle' => 'statut'),
    ), true);

    $res = $Rdv->replanifier($rdv, $debut, $champs['duree'] ?? null, $champs['statut'] ?? 'a_confirmer', (int) $user->id_users, array('prevenir' => isset($R->prevenir) ? (int) $R->prevenir === 1 : null));

    $Response->success($Suivi->reponse((int) $rdv->id_contact, $user, $res['avertissements'], array('id_rdv' => $res['id_rdv'])), $res['deja'] ? 200 : 201);
}

$Response->methodNotAllowed();

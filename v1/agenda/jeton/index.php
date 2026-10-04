<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.agenda.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Agenda = new Agenda();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Flux d'agenda de l'utilisateur connecté (étape 6b) : l'adresse à laquelle il abonne son calendrier.
// GET    → {flux: {actif, date_jeton, date_dernier_acces}}
// POST   → {adresse, flux} : crée le flux, ou le renouvelle (l'ancienne adresse ne répond plus). L'adresse n'est rendue
//          qu'ici, une seule fois : seule l'empreinte de son jeton est gardée. Le front ne la garde nulle part.
// DELETE → {flux} : coupe le flux.
// Droit de lecture sur les rendez-vous : le flux ne sert que ceux que l'utilisateur mène.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireAccess('rendez_vous', 'L');

    $Response->success(array('flux' => $Agenda->etatFlux((int) $user->id_users)));
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('rendez_vous', 'L');
    $idu = (int) $user->id_users;

    $SQL->begin_transaction();
    $adresse = $Agenda->ouvrirFlux($idu);
    $U->audit($idu, 'agenda_flux_create', null, 'user', $idu);
    $SQL->commit();

    $Response->success(array('adresse' => $adresse, 'flux' => $Agenda->etatFlux($idu)), 201);
}

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $U->requireAccess('rendez_vous', 'L');
    $idu = (int) $user->id_users;

    $SQL->begin_transaction();
    $Agenda->couperFlux($idu);
    $U->audit($idu, 'agenda_flux_delete', null, 'user', $idu);
    $SQL->commit();

    $Response->success(array('flux' => $Agenda->etatFlux($idu)));
}

$Response->methodNotAllowed();

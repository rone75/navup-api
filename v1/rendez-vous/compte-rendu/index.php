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

// Compte rendu d'un rendez-vous effectué (CDC §11) ################################
// PUT {id_rdv, compte_rendu} : l'écrit, le corrige, ou le retire (null). Note interne NavUp : droit famille.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_rdv) && is_int($R->id_rdv)) ? $R->id_rdv : 0;
    list($user, $rdv) = $Rdv->exigerRdv($id, 'C');
    if (!$U->can($user, 'famille', 'C')) {
        $Response->forbidden("Le compte rendu est une note interne : vous n'avez pas le droit de l'écrire.");
    }
    if (!is_object($R) || !property_exists($R, 'compte_rendu')) {
        $Response->validationError("Erreur paramètre COMPTE_RENDU manquant");
    }

    $champs = $S->lireChamps($R, array(
        'compte_rendu' => array('type' => 'text', 'max' => 5000, 'libelle' => 'compte rendu'),
    ), true);

    $Rdv->ecrireCompteRendu($rdv, $champs['compte_rendu'], (int) $user->id_users);

    $Response->success($Suivi->reponse((int) $rdv->id_contact, $user, array(), array('id_rdv' => $id)));
}

$Response->methodNotAllowed();

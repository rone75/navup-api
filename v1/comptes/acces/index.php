<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Réinitialiser l'accès d'un parent à son espace personnel ################################
// PUT {id_contact} : le mot de passe, les sessions et les liens en cours ne valent plus rien (un appareil perdu, une
// invitation partie à la mauvaise adresse). Le compte, ses dates et la progression ne changent pas : le parent
// retrouve tout avec une nouvelle invitation, à envoyer ensuite par v1/comptes/invitation/.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list($user) = $Contact->exigerDossier($idc, 'C');

    $Contact->verrouiller($idc);
    if (!Compte::revoquer($idc, (int) $user->id_users, 'utilisateur')) {
        $SQL->rollback();
        $Response->validationError("Ce dossier n'a pas de compte : il s'ouvre au premier paiement.");
    }
    $SQL->commit();

    $Response->success(array(
        'compte' => Compte::sortie(Compte::charger($idc)),
        'consentements' => Compte::consentements($idc),
        'contact' => $Contact->sortie($Contact->charger($idc)),
    ));
}

$Response->methodNotAllowed();

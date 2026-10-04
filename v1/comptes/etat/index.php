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

// Désactiver ou réactiver un compte NavUp Academy (CDC §8 : « suspendre / réactiver un accès ») ####################
// PUT {id_contact, actif: 0|1}. Les dates du programme ne changent pas : réactiver rend l'accès tel qu'il était.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list($user) = $Contact->exigerDossier($idc, 'C');

    if (!isset($R->actif) || !in_array($R->actif, array(0, 1), true)) {
        $Response->validationError("Erreur paramètre ACTIF manquant (0 ou 1)");
    }

    $Contact->verrouiller($idc);
    if (Compte::charger($idc) === null) {
        $SQL->rollback();
        $Response->validationError("Ce dossier n'a pas de compte : il s'ouvre au premier paiement.");
    }
    if ($R->actif === 1) {
        Compte::reactiver($idc, (int) $user->id_users);
    } else {
        Compte::desactiver($idc, (int) $user->id_users, 'utilisateur');
    }
    $Tache->synchroniser($idc);
    $SQL->commit();

    $Response->success(array(
        'compte' => Compte::sortie(Compte::charger($idc)),
        'consentements' => Compte::consentements($idc),
        'contact' => $Contact->sortie($Contact->charger($idc)),
    ));
}

$Response->methodNotAllowed();

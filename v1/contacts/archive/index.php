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
$Tache = new Tache();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Classer un dossier sans suite, ou le rouvrir ################################
// PUT {id_contact, archive: 0|1}. Un dossier classé quitte les listes sans être supprimé.
// Ses tâches automatiques de suivi (rendez-vous, rappels) se ferment ; celles de paiement restent.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user, $contact) = $Contact->exigerDossier($id, 'C');

    if (!isset($R->archive) || !in_array($R->archive, array(0, 1), true)) {
        $Response->validationError("Erreur paramètre ARCHIVE manquant (0 ou 1)");
    }
    $SQL->begin_transaction();
    if ($Contact->archiver($contact, $R->archive === 1, (int) $user->id_users)) {
        // Les alertes de suivi d'un dossier classé s'arrêtent (ses alertes de paiement restent) ; elles reprennent s'il est rouvert
        $Tache->synchroniser($id);
    }
    $SQL->commit();

    $Response->success(array('contact' => $Contact->sortie($Contact->charger($id))));
}

$Response->methodNotAllowed();

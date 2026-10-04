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

// Changement de statut (CDC §4) ################################
// PUT {id_contact, statut}. Manuel à l'étape 2 ; les ventes et le programme l'automatiseront.
// Passer d'un groupe à l'autre (prospect devenu client) exige l'accès complet aux deux.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user, $contact) = $Contact->exigerDossier($id, 'C');

    if (!isset($R->statut) || !is_string($R->statut) || !in_array($R->statut, Contact::STATUTS, true)) {
        $Response->validationError("Statut invalide.");
    }
    if (!$U->can($user, Contact::groupeDe($R->statut), 'C')) {
        $Response->forbidden("Vous n'avez pas les droits nécessaires pour ce statut.");
    }

    $SQL->begin_transaction();
    $Contact->changerStatut($contact, $R->statut, (int) $user->id_users);
    $SQL->commit();

    $Response->success(array('contact' => $Contact->sortie($Contact->charger($id))));
}

$Response->methodNotAllowed();

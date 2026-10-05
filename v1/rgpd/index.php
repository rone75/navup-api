<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.automate.php";
include "../../include/package.rgpd.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$Contact = new Contact();
$Rgpd = new Rgpd();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// RGPD (CDC §23 ; étape 8) ################################
// L'outil propose, l'administrateur confirme : rien ne s'efface sans lui, sauf le journal et les e-mails anciens.

$admin = $U->requireAccess('parametres', 'C');
$id_admin = (int) $admin->id_users;

function etat()
{
    global $Rgpd;
    return array(
        'delais' => Rgpd::delais(),
        'demandes' => $Rgpd->demandes(),
        'propositions' => $Rgpd->echeances(),
    );
}

// GET → {delais, demandes: {en_attente, traitees}, propositions}
if ($_SERVER['REQUEST_METHOD'] === "GET") {
    $Response->success(etat());
}

// POST {id_contact, niveau: familial|complet, reference} : la référence du dossier, retapée, confirme l'effacement.
// Irréversible. Le journal n'en garde que le fait et le degré.
if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (isset($R->id_contact) && is_numeric($R->id_contact)) ? (int) $R->id_contact : 0;
    $niveau = (isset($R->niveau) && is_string($R->niveau)) ? $R->niveau : '';
    $saisie = (isset($R->reference) && is_string($R->reference)) ? strtoupper(preg_replace('/\s/', '', $R->reference)) : '';

    if ($id <= 0 || !in_array($niveau, Rgpd::NIVEAUX, true)) {
        $Response->validationError("Dossier ou degré d'effacement manquant.");
    }
    if ($saisie !== Contact::reference($id)) {
        $Response->validationError("La référence retapée ne correspond pas au dossier : rien n'a été effacé.");
    }

    try {
        $Rgpd->effacer($id, $niveau, $id_admin);
    } catch (ErreurMetier $e) {
        $Response->validationError($e->getMessage());
    }

    $Response->success(etat());
}

$Response->methodNotAllowed();

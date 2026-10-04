<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.suivi.php";
include "../../require/param.php";

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

// Compte NavUp Academy d'un dossier (CDC §8, §15) ################################
// Le compte s'ouvre tout seul au premier encaissement (Vente::automatismes) : il ne se crée pas ici.
// GET ?id_contact=N : le compte et son programme (état, semaine en cours, début, fin), ou null ; et les consentements
//     que le parent a déclarés (achat en ligne).
// PUT {id_contact, date_debut, date_fin} : change le début du programme ou prolonge l'accès.
// Chaque écriture renvoie le compte et le dossier (son statut peut suivre).

$reponse = function ($idc) use ($Contact, $Response) {
    $compte = Compte::charger($idc);
    $Response->success(array(
        'compte' => $compte === null ? null : Compte::sortie($compte),
        'consentements' => Compte::consentements($idc),
        'contact' => $Contact->sortie($Contact->charger($idc)),
    ));
};

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $idc = (isset($_GET['id_contact']) && is_string($_GET['id_contact']) && ctype_digit($_GET['id_contact'])) ? (int) $_GET['id_contact'] : 0;
    $Contact->exigerDossier($idc, 'L');

    $reponse($idc);
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list($user) = $Contact->exigerDossier($idc, 'C');

    $data = $S->lireChamps($R, array(
        'date_debut' => array('type' => 'date', 'requis' => true, 'libelle' => 'début du programme', 'min' => '2020-01-01'),
        'date_fin' => array('type' => 'date', 'requis' => true, 'libelle' => 'fin du programme', 'max' => date('Y-m-d', strtotime('+3 years'))),
    ), false);
    if ($data['date_fin'] < $data['date_debut']) {
        $Response->validationError("Le programme ne peut pas finir avant d'avoir commencé.");
    }

    $Contact->verrouiller($idc);
    $compte = Compte::charger($idc);
    if ($compte === null) {
        $SQL->rollback();
        $Response->validationError("Ce dossier n'a pas de compte : il s'ouvre au premier paiement.");
    }
    Compte::modifierDates($Contact->charger($idc), $compte, $data['date_debut'], $data['date_fin'], (int) $user->id_users);
    $Tache->synchroniser($idc);
    $SQL->commit();

    $reponse($idc);
}

$Response->methodNotAllowed();

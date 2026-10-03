<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Annulation d'une écriture saisie par erreur ################################
// PUT {id_paiement, motif}. L'écriture reste lisible, avec son motif, et sort de toutes les sommes.
// Un fait réel ne s'annule pas : un chèque rejeté est un impayé, une somme rendue est un remboursement.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $idp = (is_object($R) && isset($R->id_paiement) && is_int($R->id_paiement)) ? $R->id_paiement : 0;
    $paiement = $idp > 0 ? $Mysql->fetchOne("SELECT id_paiement, id_vente FROM v_paiement WHERE id_paiement = ?", array($idp), 'i') : null;
    list($user, $vente) = $Vente->exigerVente($paiement === null ? 0 : (int) $paiement->id_vente, 'paiements', 'C');

    $champs = $S->lireChamps($R, array(
        'motif' => array('type' => 'str', 'max' => 255, 'requis' => true, 'libelle' => 'motif'),
    ), false);

    $avertissements = $Vente->annulerEcriture($paiement, $champs['motif'], (int) $user->id_users);

    $idc = (int) $vente->id_contact;
    $Response->success(array(
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc), $U->can($user, 'famille', 'L')),
        'avertissements' => $avertissements,
    ));
}

$Response->methodNotAllowed();

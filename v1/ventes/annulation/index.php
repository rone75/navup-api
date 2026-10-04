<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.suivi.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();
$Tache = new Tache();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Annulation d'une vente ################################
// PUT {id_vente, motif, remboursement?: {montant, date_paiement, code_moyen, reference?}}
// Les échéances non soldées ne sont plus dues ; l'encaissé reste acquis tant qu'il n'est pas remboursé.
// Une vente annulée ne se rétablit pas : on en enregistre une nouvelle.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_vente) && is_int($R->id_vente)) ? $R->id_vente : 0;
    list($user, $vente) = $Vente->exigerVente($id, 'ventes', 'C');

    $champs = $S->lireChamps($R, array(
        'motif' => array('type' => 'str', 'max' => 255, 'requis' => true, 'libelle' => 'motif'),
    ), false);

    $remboursement = null;
    if (isset($R->remboursement) && $R->remboursement !== null) {
        if (!$U->can($user, 'paiements', 'C')) {
            $Response->forbidden("Vous n'avez pas les droits nécessaires pour enregistrer un remboursement.");
        }
        $remboursement = $S->lireChamps($R->remboursement, array(
            'montant' => array('type' => 'int', 'min' => 1, 'requis' => true, 'libelle' => 'montant remboursé'),
            'date_paiement' => array('type' => 'date', 'requis' => true, 'max' => date('Y-m-d'), 'libelle' => 'date du remboursement'),
            'code_moyen' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_moyen_paiement', 'col' => 'code', 'where' => 'actif = 1', 'requis' => true, 'libelle' => 'moyen de paiement'),
            'reference' => array('type' => 'str', 'max' => 100, 'defaut' => null, 'libelle' => 'référence'),
        ), false);
    }

    $avertissements = $Vente->annuler($id, $champs['motif'], $remboursement, (int) $user->id_users);

    $idc = (int) $vente->id_contact;
    // Les tâches de relance du dossier suivent l'écriture (échéance en retard, paiement échoué)
    $Tache->synchroniser($idc);

    $Response->success(array(
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc)),
        'avertissements' => $avertissements,
    ));
}

$Response->methodNotAllowed();

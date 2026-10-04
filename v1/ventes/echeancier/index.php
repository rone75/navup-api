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

// Révision de l'échéancier d'une vente ################################
// PUT {id_vente, echeances: [{date_prevue, montant}], remise?, motif_remise?}
// Les échéances soldées sont figées : `echeances` remplace toutes les autres. La somme des échéances soldées
// et des nouvelles doit être égale au total (prix de l'offre moins la remise), lui-même au moins égal à l'encaissé.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_vente) && is_int($R->id_vente)) ? $R->id_vente : 0;
    list($user, $vente) = $Vente->exigerVente($id, 'ventes', 'C');

    $echeances = $Vente->lireEcheances(isset($R->echeances) ? $R->echeances : null, 0, Vente::MAX_ECHEANCES);
    $champs = $S->lireChamps($R, array(
        'remise' => array('type' => 'int', 'min' => 0, 'libelle' => 'remise'),
        'motif_remise' => array('type' => 'str', 'max' => 255, 'libelle' => 'motif de la remise'),
    ), true);
    $remise = isset($champs['remise']) ? $champs['remise'] : null;
    $motif = isset($champs['motif_remise']) ? $champs['motif_remise'] : $vente->motif_remise;

    $avertissements = $Vente->reviserEcheancier($id, $echeances, $remise, $motif, (int) $user->id_users);

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

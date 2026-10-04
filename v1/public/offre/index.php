<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.automate.php";
include "../../../include/package.stripe.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

$Sortie = new Response();
Automate::demarrer(getcwd() . "/index.php");

// L'offre en vente sur la page publique (landing page de l'appli des parents) ################################
// Endpoint PUBLIC, en lecture : GET → {offre: {libelle, prix, ouvert, modalites: [{fois, echeances: [montants]}]}}.
// Les montants sont ceux que v1/public/commande/ appliquera : le prix de l'offre dans p_offre, et pour chaque
// modalité l'échéancier de Vente::echeancier(). La page n'écrit aucun prix en dur et n'additionne rien.
// `ouvert` : l'achat en ligne est possible (offre active, paiement configuré, vente ouverte par $_VENTE_EN_LIGNE_OUVERTE).
// Fermé, la page présente le programme sans formulaire d'achat.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    Automate::limiter('offre', (int) $_LIMITE_PAIEMENT[0] * 4, (int) $_LIMITE_PAIEMENT[1]);

    $offre = $Mysql->fetchOne("SELECT code, libelle, prix, actif FROM p_offre WHERE code = ?", array($_VENTE_EN_LIGNE_OFFRE), 's');
    if ($offre === null) {
        $Sortie->notFound("Cette offre n'est pas en vente pour le moment.");
    }

    $modalites = array();
    foreach ($_VENTE_EN_LIGNE_FOIS as $fois) {
        $modalites[] = array(
            'fois' => (int) $fois,
            'echeances' => array_map('intval', array_column(Vente::echeancier((int) $offre->prix, (int) $fois, date('Y-m-d')), 'montant')),
        );
    }

    $Sortie->success(array('offre' => array(
        'libelle' => $offre->libelle,
        'prix' => (int) $offre->prix,
        'ouvert' => (int) $offre->actif === 1 && $Stripe->configure() && !empty($_VENTE_EN_LIGNE_OUVERTE),
        'modalites' => $modalites,
    )));
}

$Sortie->methodNotAllowed();

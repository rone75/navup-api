#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/essai-stripe.php
// Description: contrôle de bout en bout du paiement en ligne, contre le vrai Stripe en mode test : commande en trois
//              fois, premier paiement, vente et compte créés, carte enregistrée, avis puis prélèvement de la deuxième
//              échéance, prélèvement refusé de la troisième, lien de paiement, remboursement fait dans Stripe.
//              Chaque fait est rejoué pour vérifier qu'il ne s'écrit qu'une fois.
//              Le paiement sur la page Checkout est remplacé par un paiement créé par l'API avec les mêmes métadonnées
//              (les cartes d'essai de Stripe) : la page elle-même se contrôle à la main, une fois.
//              Refusé en production et sans clé de test. Laisse un dossier d'essai (essai.stripe…@navup.local), que
//              purge-essais.php efface.
// Usage:       php script-cgi/essai-stripe.php
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../include/package.saisie.php";
include __DIR__ . "/../include/package.contact.php";
include __DIR__ . "/../include/package.vente.php";
include __DIR__ . "/../include/package.suivi.php";
include __DIR__ . "/../include/package.message.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../include/package.stripe.php";
include __DIR__ . "/../require/param.php";

if (!empty($_PROD) || !isset($_STRIPE_CLE_SECRETE) || strpos((string) $_STRIPE_CLE_SECRETE, 'sk_test_') !== 0) {
    fwrite(STDERR, "Refusé : ce script ne s'exécute qu'en développement, avec une clé de test Stripe.\n");
    exit(1);
}

Automate::demarrer(__FILE__);
$stripe = new \Stripe\StripeClient(array('api_key' => $_STRIPE_CLE_SECRETE));

$echecs = 0;
$ok = function ($nom, $condition, $detail = '') use (&$echecs) {
    if (!$condition) {
        $echecs++;
    }
    echo ($condition ? "OK    " : "ÉCHEC ") . $nom . ($detail !== '' ? " : $detail" : '') . "\n";
};
$un = function ($sql, $params = array()) use ($Mysql) {
    return $Mysql->fetchOne($sql, $params);
};
// Le rattrapage lit la liste des événements de Stripe : un fait y paraît une à deux secondes après l'appel
$rattraper = function ($attendu) use ($Stripe, $un) {
    for ($i = 0; $i < 10; $i++) {
        sleep(2);
        $Stripe->rattraper();
        if ($attendu()) {
            return true;
        }
    }

    return false;
};

$marque = bin2hex(random_bytes(3));
$email = "essai.stripe.$marque@navup.local";
$jour = date('Y-m-d');

// 1. Commande en trois fois ####################################
$SQL->begin_transaction();
$idc = $Contact->creer(array('prenom' => 'Inès', 'nom' => 'Essai-Stripe', 'email' => $email), 'prospect', null, 'automatique', array('canal' => 'achat_en_ligne'));
$idco = $S->inserer('s_commande', array(
    'cle_saisie' => sprintf('e55a1e55-0000-4000-8000-%012s', bin2hex(random_bytes(6))),
    'id_contact' => $idc, 'prenom' => 'Inès', 'nom' => 'Essai-Stripe', 'email' => $email,
    'code_offre' => 'programme_navup', 'nb_echeances' => 3, 'montant' => 29900,
));
$SQL->commit();
$commande = $un("SELECT * FROM s_commande WHERE id_commande = ?", array($idco));

$url = $Stripe->sessionCommande($commande);
$ok("la commande ouvre une page de paiement Stripe", strpos((string) $url, 'https://checkout.stripe.com/') === 0);
$ok("un second appel rend la même page", $Stripe->sessionCommande($commande) === $url);
$ok("aucune vente n'existe avant le paiement", (int) $un("SELECT COUNT(*) AS n FROM v_vente WHERE id_contact = ?", array($idc))->n === 0);
$commande = $un("SELECT * FROM s_commande WHERE id_commande = ?", array($idco));
$client = $commande->stripe_customer_id;
$ok("le client Stripe est noté sur la commande", strpos((string) $client, 'cus_') === 0);

// 2. Premier paiement (carte d'essai), comme sur la page de paiement ####################################
$payer = function ($montant, $metadata, $carte = 'pm_card_visa', $enregistrer = true) use ($stripe, $client) {
    $params = array(
        'amount' => $montant, 'currency' => 'eur', 'customer' => $client, 'payment_method' => $carte,
        'payment_method_types' => array('card'), 'confirm' => true,
        'metadata' => array_merge(array('outil' => 'navup', 'mode' => 'session'), $metadata),
    );
    if ($enregistrer) {
        $params['setup_future_usage'] = 'off_session';
    }

    return $stripe->paymentIntents->create($params);
};
$pi = $payer(9968, array('id_commande' => (string) $idco));
$ok("le paiement d'essai réussit chez Stripe", $pi->status === 'succeeded', $pi->status);

$vu = $rattraper(function () use ($un, $idc) {
    return (int) $un("SELECT COUNT(*) AS n FROM v_vente WHERE id_contact = ?", array($idc))->n === 1;
});
$ok("le rattrapage crée la vente", $vu);
$vente = $un("SELECT * FROM v_vente WHERE id_contact = ?", array($idc));
if ($vente === null) {
    echo "Vente absente : le contrôle s'arrête ici.\n";
    exit(1);
}
$idv = (int) $vente->id_vente;
$ok("la vente vient de Stripe, en trois échéances", $vente->source === 'stripe' && $vente->modalite === 'fractionne' && (int) $un("SELECT COUNT(*) AS n FROM v_echeance WHERE id_vente = ?", array($idv))->n === 3);
$p1 = $un("SELECT * FROM v_paiement WHERE id_vente = ? ORDER BY id_paiement LIMIT 1", array($idv));
$ok("le premier encaissement est écrit avec ses identifiants Stripe", $p1 !== null && $p1->type === 'encaissement' && (int) $p1->montant === 9968 && $p1->source === 'stripe' && strpos((string) $p1->stripe_id, 'ch_') === 0 && $p1->stripe_payment_intent_id === $pi->id);
$ok("la carte est enregistrée, les prélèvements actifs", $vente->prelevement === 'actif' && strpos((string) $vente->stripe_payment_method_id, 'pm_') === 0 && $vente->date_carte !== null);
$contact = $Contact->charger($idc);
$ok("le dossier est « Client actif », son programme en cours", $contact->statut === 'client_actif' && Compte::programmeDuDossier($contact)['etat'] === 'en_cours');
$ok("la commande est payée et rattachée à la vente", $un("SELECT etat, id_vente FROM s_commande WHERE id_commande = ?", array($idco))->etat === 'payee');
$bienvenue = $un("SELECT * FROM m_message WHERE cle = ?", array('bienvenue:' . $idv));
$ok("l'e-mail de bienvenue est parti (en essai), sans lien secret", $bienvenue !== null && $bienvenue->etat === 'envoye' && $bienvenue->mode === 'essai' && strpos($bienvenue->corps, 'prélevées') !== false);
$ok("le fait « e-mail » est dans le fil du dossier", $un("SELECT COUNT(*) AS n FROM d_evenement WHERE id_contact = ? AND type = 'email'", array($idc))->n >= 1);

// 3. Rejouer : rien ne s'écrit deux fois ####################################
$Mysql->execute("UPDATE s_evenement SET statut = 'recu' WHERE id_vente = ?", array($idv));
$Stripe->rattraper();
$ok("le fait rejoué n'écrit rien de plus", (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ?", array($idv))->n === 1 && (int) $un("SELECT COUNT(*) AS n FROM v_vente WHERE id_contact = ?", array($idc))->n === 1);

// 4. Deuxième échéance : avis, puis prélèvement ####################################
$Mysql->execute("UPDATE v_echeance SET date_prevue = ? WHERE id_vente = ? AND rang = 2", array($jour, $idv));
$ok("un avis de prélèvement est déposé", $Stripe->aviser() >= 1);
$Message->envoyerEnAttente($idc);
try {
    $Stripe->preleverVente($idv, null, false);
    $ok("sans délai depuis l'avis, pas de prélèvement", false);
} catch (ErreurMetier $e) {
    $SQL->rollback();
    $ok("sans délai depuis l'avis, pas de prélèvement", true);
}
$Mysql->execute("UPDATE m_message SET date_envoi = DATE_SUB(NOW(), INTERVAL 4 DAY) WHERE id_contact = ? AND modele = 'prelevement_avis'", array($idc));
$issue = $Stripe->preleverVente($idv, null, false);
$ok("le prélèvement de la deuxième échéance est traité", $issue === 'traite', (string) $issue);
$ok("deux encaissements, le second par prélèvement réussi", (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ? AND type = 'encaissement'", array($idv))->n === 2 && $un("SELECT etat FROM s_prelevement WHERE id_vente = ? ORDER BY id_prelevement DESC LIMIT 1", array($idv))->etat === 'reussi');
$Stripe->rattraper();
$ok("l'événement du prélèvement, relu par le rattrapage, n'écrit rien de plus", (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ?", array($idv))->n === 2);

// 5. Troisième échéance : carte qui refuse ####################################
$refus = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', array('customer' => $client));
$Mysql->execute("UPDATE v_vente SET stripe_payment_method_id = ? WHERE id_vente = ?", array($refus->id, $idv));
$Mysql->execute("UPDATE v_echeance SET date_prevue = ? WHERE id_vente = ? AND rang = 3", array($jour, $idv));
$Stripe->aviser();
$Message->envoyerEnAttente($idc);
$Mysql->execute("UPDATE m_message SET date_envoi = DATE_SUB(NOW(), INTERVAL 4 DAY) WHERE id_contact = ? AND modele = 'prelevement_avis'", array($idc));
$issue = $Stripe->preleverVente($idv, null, false);
$ok("le prélèvement refusé est traité", $issue === 'traite', (string) $issue);
$echec = $un("SELECT * FROM v_paiement WHERE id_vente = ? AND type = 'echec'", array($idv));
$ok("une écriture « échec » porte la raison du refus", $echec !== null && $echec->motif !== null && $echec->source === 'stripe', $echec === null ? '' : (string) $echec->motif);
$ok("la tâche « paiement échoué » est ouverte", (int) $un("SELECT COUNT(*) AS n FROM t_tache WHERE id_contact = ? AND alerte = 'paiement_echoue' AND date_cloture IS NULL", array($idc))->n === 1);
$avertissement = $un("SELECT * FROM m_message WHERE id_contact = ? AND modele = 'paiement_echoue'", array($idc));
$ok("l'e-mail « paiement échoué » est parti, son lien n'est pas gardé", $avertissement !== null && $avertissement->etat === 'envoye' && strpos($avertissement->corps, Message::MARQUE_LIEN) !== false && strpos($avertissement->corps, 'paiement/?j=') === false);
try {
    $Stripe->preleverVente($idv, null, false);
    $ok("après un échec, l'outil ne retente pas seul", false);
} catch (ErreurMetier $e) {
    $SQL->rollback();
    $ok("après un échec, l'outil ne retente pas seul", true);
}

// 6. Lien de paiement : le parent règle l'échéance refusée ####################################
$lien = $Stripe->creerLien($idv, null);
$jeton = substr((string) $lien, strpos((string) $lien, '?j=') + 3);
$ok("le lien de paiement mène à la vente", $Stripe->venteDuLien($jeton) === $idv && $Stripe->venteDuLien(strrev($jeton)) === null);
$ok("seule l'empreinte du jeton est en base", $un("SELECT COUNT(*) AS n FROM s_lien WHERE jeton = ?", array($jeton))->n == 0 && $un("SELECT COUNT(*) AS n FROM s_lien WHERE jeton = ?", array(hash('sha256', $jeton)))->n == 1);
$page = $Stripe->sessionVente($idv);
$ok("le lien ouvre une page de paiement de l'échéance due", strpos((string) $page, 'https://checkout.stripe.com/') === 0 && (int) $un("SELECT montant FROM s_session WHERE id_vente = ? ORDER BY id_session DESC LIMIT 1", array($idv))->montant === 9966);
$pi3 = $payer(9966, array('id_vente' => (string) $idv), 'pm_card_visa', false);
$solde = $rattraper(function () use ($un, $idv) {
    return $un("SELECT statut FROM v_vente WHERE id_vente = ?", array($idv))->statut === 'paye';
});
$ok("le paiement par lien solde la vente", $solde);
$ok("la tâche « paiement échoué » est fermée", (int) $un("SELECT COUNT(*) AS n FROM t_tache WHERE id_contact = ? AND alerte = 'paiement_echoue' AND date_cloture IS NULL", array($idc))->n === 0);
$ok("vente soldée : plus de lien, plus de page", $Stripe->creerLien($idv, null) === null && $Stripe->sessionVente($idv) === null);

// 7. Trop-perçu : un paiement de plus ne s'écrit pas, il reste en erreur visible ####################################
$trop = $payer(5000, array('id_vente' => (string) $idv), 'pm_card_visa', false);
$rattraper(function () use ($un, $trop) {
    return $un("SELECT COUNT(*) AS n FROM s_evenement WHERE objet_id = ? AND statut = 'erreur'", array($trop->id))->n >= 1;
});
$erreur = $un("SELECT * FROM s_evenement WHERE objet_id = ? AND statut = 'erreur'", array($trop->id));
$ok("un paiement en trop reste en erreur, avec sa raison", $erreur !== null && strpos((string) $erreur->erreur, 'soldée') !== false, $erreur === null ? '' : mb_substr((string) $erreur->erreur, 0, 90));
$ok("il n'est pas écrit", (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE stripe_payment_intent_id = ?", array($trop->id))->n === 0);

// 8. Remboursement fait dans Stripe ####################################
$stripe->refunds->create(array('payment_intent' => $pi->id, 'amount' => 1000));
$rembourse = $rattraper(function () use ($un, $idv) {
    return (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ? AND type = 'remboursement'", array($idv))->n === 1;
});
$r = $un("SELECT * FROM v_paiement WHERE id_vente = ? AND type = 'remboursement'", array($idv));
$ok("le remboursement fait dans Stripe est constaté", $rembourse && $r !== null && (int) $r->montant === 1000 && $r->source === 'stripe');
$Mysql->execute("UPDATE s_evenement SET statut = 'recu' WHERE type = 'charge.refunded' AND id_vente = ?", array($idv));
$Stripe->rattraper();
$ok("rejoué, il n'est pas écrit deux fois", (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ? AND type = 'remboursement'", array($idv))->n === 1);

// 9. Frais et cohérence ####################################
$Mysql->execute("UPDATE v_paiement SET date_creation = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id_vente = ? AND frais IS NULL", array($idv));
$Stripe->completerFrais();
$ok("les frais Stripe des encaissements sont connus", (int) $un("SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ? AND type = 'encaissement' AND frais IS NULL", array($idv))->n === 0);
$ok("aucun montant dans le journal d'audit ni dans le fil", (int) $un("SELECT COUNT(*) AS n FROM u_audit WHERE cible_type = 'contact' AND cible_id = ? AND (details LIKE '%9968%' OR details LIKE '%9966%')", array($idc))->n === 0
    && (int) $un("SELECT COUNT(*) AS n FROM d_evenement WHERE id_contact = ? AND (details LIKE '%9968%' OR details LIKE '%9966%')", array($idc))->n === 0);

echo "\n" . ($echecs === 0 ? "Paiement en ligne : tous les contrôles passent." : "$echecs contrôle(s) en échec.") . " Dossier d'essai : " . Contact::reference($idc) . ", vente " . Vente::reference($idv) . ".\n";
exit($echecs === 0 ? 0 : 1);

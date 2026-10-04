#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/essai-parent.php
// Description: prépare un parent d'essai pour contrôler l'appli des parents sans passer par l'outil : un dossier
//              (essai.…@navup.local), une vente payée comptant par la vraie chaîne (Vente::creer → compte ouvert),
//              puis le programme placé où on le veut (semaine en cours, pas commencé, terminé, accès fermé, suspendu)
//              et un lien d'accès tout neuf. Rejouable : le même e-mail retrouve son dossier, ses dates sont reposées
//              et un nouveau lien annule l'ancien. purge-essais.php efface tout.
//              Refusé en production.
// Usage:       php script-cgi/essai-parent.php [--email=essai.parent@navup.local] [--prenom=Camille]
//                  [--debut=-20]            début du programme, en jours par rapport à aujourd'hui (défaut 0)
//                  [--suspendu]             compte désactivé
//                  [--appli=http://127.0.0.1:4201/]   adresse de l'appli pour le lien, si $_APP_PARENTS_URL est vide
//              Sortie : une ligne JSON {id_contact, id_compte, email, date_debut, date_fin, date_fin_acces, etat, lien}.
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
include __DIR__ . "/../require/param.php";

if (!empty($_PROD)) {
    fwrite(STDERR, "Refusé en production.\n");
    exit(1);
}

$options = getopt('', array('email::', 'prenom::', 'debut::', 'suspendu', 'appli::'));
$email = isset($options['email']) ? strtolower($options['email']) : 'essai.parent@navup.local';
$prenom = isset($options['prenom']) ? $options['prenom'] : 'Camille';
$decalage = isset($options['debut']) ? (int) $options['debut'] : 0;
if (!preg_match('/^essai\.[a-z0-9.-]+@navup\.local$/', $email)) {
    fwrite(STDERR, "L'e-mail d'un parent d'essai est en essai.…@navup.local (purge-essais.php l'efface).\n");
    exit(1);
}
if (isset($options['appli']) && (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '')) {
    $_APP_PARENTS_URL = $options['appli'];
}

Automate::demarrer(__FILE__);

$jour = date('Y-m-d');

try {
    $contact = $Mysql->fetchOne("SELECT id_contact FROM d_contact WHERE email = ?", array($email), 's');
    if ($contact === null) {
        $SQL->begin_transaction();
        $idc = $Contact->creer(array('prenom' => $prenom, 'nom' => 'Essai-Parent', 'email' => $email), 'prospect', null, 'automatique', array('canal' => 'essai'));
        $SQL->commit();
    } else {
        $idc = (int) $contact->id_contact;
    }

    // Le compte s'ouvre par la vraie chaîne : une vente payée comptant aujourd'hui
    if (Compte::charger($idc) === null) {
        $offre = $Mysql->fetchOne("SELECT code, prix FROM p_offre WHERE code = ?", array($_VENTE_EN_LIGNE_OFFRE), 's');
        Automate::proteger(function () use ($Vente, $Contact, $idc, $offre, $jour) {
            return $Vente->creer(
                $Contact->charger($idc),
                array('code_offre' => $offre->code, 'date_vente' => $jour, 'code_moyen' => 'virement'),
                Vente::echeancier((int) $offre->prix, 1, $jour),
                array('date_paiement' => $jour, 'code_moyen' => 'virement', 'reference' => null),
                null,
                null,
                'utilisateur'   // une vente saisie à la main, sans auteur : « automatique » en ferait une écriture Stripe
            );
        });
    }

    // Le programme placé au jour voulu ; la fin de l'accès suit la règle ordinaire
    $Contact->verrouiller($idc);
    $compte = Compte::charger($idc);
    if ($compte === null) {
        throw new ErreurMetier("Le compte ne s'est pas ouvert : la formation de l'offre « " . $_VENTE_EN_LIGNE_OFFRE . " » existe-t-elle ?");
    }
    $jours = Formation::structure($compte->id_formation)['jours'];
    $debut = date('Y-m-d', strtotime($jour . ' 12:00:00') + $decalage * 86400);
    $fin = date('Y-m-d', strtotime($debut . ' 12:00:00') + ($jours - 1) * 86400);
    $finAcces = date('Y-m-d', strtotime($fin . ' 12:00:00') + (int) $_ACCES_APRES_FIN_JOURS * 86400);
    Compte::modifierDates($Contact->charger($idc), $compte, $debut, $fin, $finAcces, null);
    if (isset($options['suspendu'])) {
        Compte::desactiver($idc, null, 'utilisateur');
    } else {
        Compte::reactiver($idc, null);
    }
    $SQL->commit();

    $compte = Compte::charger($idc);
    $lien = Compte::lienAcces($compte->id_compte, 'creation');
} catch (Throwable $e) {
    $SQL->rollback();
    fwrite(STDERR, "Échec : " . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode(array(
    'id_contact' => $idc,
    'id_compte' => (int) $compte->id_compte,
    'email' => $email,
    'date_debut' => $compte->date_debut,
    'date_fin' => $compte->date_fin,
    'date_fin_acces' => $compte->date_fin_acces,
    'etat' => $compte->etat,
    'lien' => $lien,
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

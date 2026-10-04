#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/inviter-comptes.php
// Description: campagne d'invitation à l'espace personnel, pour les comptes ouverts avant que l'appli des parents
//              n'existe, ou par une vente saisie à la main (seul un achat en ligne envoie l'invitation tout seul).
//              Sont invités les comptes actifs dont l'accès est encore ouvert, dont le dossier a une adresse et n'est
//              pas classé, et qui n'ont ni mot de passe ni lien d'accès déjà créé. L'e-mail est le modèle
//              « invitation » ; le lien est composé à l'envoi. Rejouable : un compte déjà invité est laissé.
//              À lancer une fois $_APP_PARENTS_URL renseigné, avant d'annoncer l'appli. Utilisable en production.
// Usage:       php script-cgi/inviter-comptes.php --simuler     compte ce qui partirait, sans rien écrire
//              php script-cgi/inviter-comptes.php               dépose et envoie
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
include __DIR__ . "/../include/package.message.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../require/param.php";

Automate::demarrer(__FILE__);

if (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
    fwrite(STDERR, "L'appli des parents n'a pas d'adresse (\$_APP_PARENTS_URL) : il n'y a rien à ouvrir.\n");
    exit(1);
}

$simuler = in_array('--simuler', $argv, true);
$comptes = $Mysql->fetchAll(
    "SELECT c.id_contact, c.prenom, c.email
     FROM a_compte a INNER JOIN d_contact c ON c.id_contact = a.id_contact
     WHERE a.etat = 'actif' AND a.date_fin_acces >= ? AND c.date_archivage IS NULL AND c.email IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM e_acces e WHERE e.id_compte = a.id_compte)
       AND NOT EXISTS (SELECT 1 FROM a_jeton j WHERE j.id_compte = a.id_compte)
     ORDER BY a.id_compte",
    array(date('Y-m-d')),
    's'
);

$envoyes = 0;
$erreurs = 0;
foreach ($comptes as $c) {
    if ($simuler) {
        continue;
    }
    try {
        $idm = Compte::inviter($c);
        if ($idm !== null && $Message->envoyer($idm)) {
            $envoyes++;
        } else {
            $erreurs++;
        }
    } catch (Throwable $e) {
        $SQL->rollback();
        $erreurs++;
    }
}

echo count($comptes) . " compte(s) à inviter" . ($simuler ? " (simulation : rien n'est parti)." : " : $envoyes e-mail(s) envoyé(s), $erreurs en erreur (à relancer depuis l'onglet « Connexions »).") . "\n";
exit($erreurs > 0 ? 1 : 0);

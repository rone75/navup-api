#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/sauvegarder.php
// Description: sauvegarde de la nuit (étape 8) : la base entière (mariadb-dump, transaction cohérente) et les médias de
//              la formation, dans une seule archive chiffrée (AES-256, clé $_CLE_SAUVEGARDE de require/secret.php),
//              scellée par un HMAC-SHA256 noté dans son manifeste. Rotation : les 7 dernières, et la dernière de chacune
//              des 4 dernières semaines. Le passage est consigné dans t_planifie (onglet Connexions).
//              Les identifiants de la base ne passent jamais sur une ligne de commande : fichier temporaire en 0600.
//              La copie hors du serveur (autre machine, stockage de l'hébergeur) se fait à part : voir deploiement/.
// Usage:       php script-cgi/sauvegarder.php [--dossier=<destination>] [--sans-medias]
//              Destination par défaut : $_DOSSIER_SAUVEGARDES. Relire une sauvegarde : script-cgi/restaurer.php.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.sauvegarde.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');
umask(0077);

$opts = getopt('', array('dossier:', 'sans-medias'));
$dossier = isset($opts['dossier']) ? (string) $opts['dossier'] : (isset($_DOSSIER_SAUVEGARDES) ? (string) $_DOSSIER_SAUVEGARDES : '');

$Response = new Response();
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$Mysql->execute(
    "INSERT INTO t_planifie (passe, date_debut, date_fin, code, resume) VALUES ('sauvegarde', NOW(), NULL, 0, NULL)
     ON DUPLICATE KEY UPDATE date_debut = NOW(), date_fin = NULL, code = 0, resume = NULL"
);

try {
    $m = Sauvegarde::faire($dossier, !isset($opts['sans-medias']));
    $retirees = Sauvegarde::rotation($dossier);
    $resume = Sauvegarde::taille($m['taille']) . ', ' . $m['tables'] . ' tables, ' . $m['medias'] . ' fichiers de médias'
        . ($retirees > 0 ? ", $retirees ancienne" . ($retirees > 1 ? 's' : '') . ' retirée' . ($retirees > 1 ? 's' : '') : '');
    $Mysql->execute("UPDATE t_planifie SET date_fin = NOW(), code = 0, resume = ? WHERE passe = 'sauvegarde'", array(mb_substr($resume, 0, 255)), 's');
    echo $m['fichier'] . " : $resume\n";
    exit(0);
} catch (Throwable $e) {
    $Mysql->execute("UPDATE t_planifie SET date_fin = NOW(), code = 1, resume = ? WHERE passe = 'sauvegarde'", array(mb_substr('Échec : ' . $e->getMessage(), 0, 255)), 's');
    fwrite(STDERR, "Sauvegarde impossible : " . $e->getMessage() . "\n");
    exit(1);
}

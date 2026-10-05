#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/restaurer.php
// Description: preuve qu'une sauvegarde se relit (étape 8) : vérifie son sceau (HMAC), la déchiffre, la charge dans une
//              base de TEST, puis y passe les contrôles de cohérence (verifier-finances, verifier-suivi,
//              verifier-connexions, verifier-statistiques). Refusé vers la base en service.
//              Une vraie remise en service (base et médias) se fait à la main : deploiement/README.md.
// Usage:       php script-cgi/restaurer.php --vers=<base de test> [--dossier=<sauvegardes>] [--fichier=<navup-….json>]
//              Sans --fichier : la plus récente. La base de test doit exister, avec tous les droits pour l'utilisateur
//              de l'API (deploiement/README.md).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.sauvegarde.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');
umask(0077);

$opts = getopt('', array('vers:', 'dossier:', 'fichier:'));
$vers = isset($opts['vers']) ? (string) $opts['vers'] : '';
$dossier = isset($opts['dossier']) ? (string) $opts['dossier'] : (isset($_DOSSIER_SAUVEGARDES) ? (string) $_DOSSIER_SAUVEGARDES : '');
if ($vers === '' || $dossier === '') {
    fwrite(STDERR, "Usage : php script-cgi/restaurer.php --vers=<base de test> [--dossier=<sauvegardes>] [--fichier=<navup-….json>]\n");
    exit(1);
}

$liste = Sauvegarde::liste($dossier);
if (isset($opts['fichier'])) {
    $liste = array_values(array_filter($liste, function ($m) use ($opts) {
        return basename($m['manifeste']) === basename($opts['fichier']) || $m['fichier'] === basename($opts['fichier']);
    }));
}
if (count($liste) === 0) {
    fwrite(STDERR, "Aucune sauvegarde trouvée dans $dossier\n");
    exit(1);
}
$m = $liste[0];

try {
    $r = Sauvegarde::restaurer($dossier, $m, $vers);
} catch (Throwable $e) {
    fwrite(STDERR, "Restauration refusée : " . $e->getMessage() . "\n");
    exit(1);
}
echo "{$m['fichier']} ({$m['date']}) chargée dans $vers ; {$r['medias']} fichiers de médias relus dans l'archive.\n";

// Les contrôles, sur la base de test (NAVUP_BASE, lue par Mysql::OuvrirBase en ligne de commande seulement)
$ecarts = 0;
foreach (array('verifier-finances', 'verifier-suivi', 'verifier-connexions', 'verifier-statistiques') as $script) {
    $sortie = array();
    exec('NAVUP_BASE=' . escapeshellarg($vers) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$script.php") . ' 2>&1', $sortie, $code);
    echo ($code === 0 ? "OK      " : "ÉCART   ") . "$script" . ($code === 0 ? '' : ' — ' . implode(' / ', array_slice($sortie, -3))) . "\n";
    if ($code !== 0) {
        $ecarts++;
    }
}
echo $ecarts === 0 ? "Sauvegarde relue et cohérente.\n" : "$ecarts contrôle(s) en écart.\n";
exit($ecarts === 0 ? 0 : 1);

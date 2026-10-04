#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/rendre-fiches.php
// Description: rend en images les pages des fiches PDF déjà rangées (fiches importées ou téléversées avant l'appli des
//              parents, ou sur un serveur où pdftoppm manquait). Une fiche téléversée depuis est rendue à son rangement.
//              Rejouable : une page déjà rendue n'est pas refaite. Utilisable en production.
// Usage:       php script-cgi/rendre-fiches.php [--tout]     (--tout : aussi les fiches qui ont déjà leurs pages)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../include/package.saisie.php";
include __DIR__ . "/../include/package.formation.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../require/param.php";

Automate::demarrer(__FILE__);

$tout = in_array('--tout', $argv, true);
$rendues = 0;
$echecs = 0;
$fiches = $Mysql->fetchAll(
    "SELECT id_fichier, id_sujet, empreinte, chemin, pages FROM f_fichier
     WHERE role = 'fiche' AND etat = 'pret' AND type_mime = 'application/pdf' AND chemin IS NOT NULL" . ($tout ? "" : " AND pages IS NULL") . " ORDER BY id_fichier"
);
foreach ($fiches as $f) {
    $pages = $Formation->rendrePages($f->empreinte, $Formation->chemin($f));
    if ($pages === null) {
        $echecs++;
        fwrite(STDERR, "Fiche n° " . (int) $f->id_fichier . " (sujet " . (int) $f->id_sujet . ") : pages non rendues\n");
        continue;
    }
    $Mysql->execute("UPDATE f_fichier SET pages = ? WHERE id_fichier = ?", array($pages, (int) $f->id_fichier), 'ii');
    $rendues++;
}

echo count($fiches) . " fiche(s) à rendre : " . $rendues . " rendue(s), " . $echecs . " en échec.\n";
exit($echecs > 0 ? 1 : 0);

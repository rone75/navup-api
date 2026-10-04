#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/essai-publication.php
// Description: publie pour un temps des sujets de la formation, le temps de contrôler l'appli des parents sur un
//              poste de développement, puis rend à chacun son état. La publication passe par Formation::publier() :
//              un sujet sans audio ou sans fiche est laissé de côté. Les sujets publiés par ce script sont notés dans
//              un fichier du dossier temporaire ; --retablir ne dépublie que ceux-là et retire ses lignes d'audit.
//              Refusé en production : là, publier est une décision qui se prend dans l'outil.
// Usage:       php script-cgi/essai-publication.php --publier=1-11     numéros des sujets (un intervalle)
//              php script-cgi/essai-publication.php --retablir
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

if (!empty($_PROD)) {
    fwrite(STDERR, "Refusé en production.\n");
    exit(1);
}

Automate::demarrer(__FILE__);

$memoire = sys_get_temp_dir() . '/navup-essai-publication.json';
$options = getopt('', array('publier::', 'retablir'));
$etat = is_file($memoire) ? json_decode(file_get_contents($memoire), true) : null;
if (!is_array($etat)) {
    $etat = array('sujets' => array(), 'depuis' => date('Y-m-d H:i:s'));
}

if (isset($options['publier']) && preg_match('/^(\d+)-(\d+)$/', (string) $options['publier'], $m)) {
    $publies = 0;
    $ecartes = 0;
    foreach ($Mysql->fetchAll("SELECT * FROM f_sujet WHERE numero BETWEEN ? AND ? AND publie = 0 ORDER BY numero", array((int) $m[1], (int) $m[2]), 'ii') as $sujet) {
        try {
            $Formation->publier($sujet, true, null);
            $etat['sujets'][] = (int) $sujet->id_sujet;
            $publies++;
        } catch (ErreurMetier $e) {
            $ecartes++;
        }
    }
    file_put_contents($memoire, json_encode($etat));
    echo $publies . " sujet(s) publié(s) pour l'essai, " . $ecartes . " écarté(s) (audio ou fiche manquant).\n";
    exit(0);
}

if (isset($options['retablir'])) {
    $rendus = 0;
    foreach (array_unique($etat['sujets']) as $id) {
        $sujet = $Formation->chargerSujet($id);
        if ($sujet !== null && (int) $sujet->publie === 1) {
            $Formation->publier($sujet, false, null);
            $rendus++;
        }
        $Mysql->execute(
            "DELETE FROM u_audit WHERE id_users IS NULL AND cible_type = 'sujet' AND cible_id = ? AND action IN ('formation_publication', 'formation_depublication') AND date >= ?",
            array((int) $id, $etat['depuis']),
            'is'
        );
    }
    @unlink($memoire);
    echo $rendus . " sujet(s) rendu(s) à l'état de brouillon.\n";
    exit(0);
}

fwrite(STDERR, "Usage : php script-cgi/essai-publication.php --publier=1-11 | --retablir\n");
exit(1);

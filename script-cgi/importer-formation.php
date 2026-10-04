#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/importer-formation.php
// Description: charge dans la Tour de contrôle la formation fournie par NavUp : les douze semaines et les quarante
//              sujets de la répartition officielle (cahier de l'écosystème §10), l'audio et la fiche PDF de chacun.
//              L'association se fait par le numéro du sujet (01 à 40), jamais par l'ordre des noms de fichiers.
//              Les audios fournis en WAV sont convertis en MP3 d'écoute (ffmpeg) ; les originaux ne sont pas copiés.
//              Rejouable : une semaine, un sujet ou un fichier déjà là n'est pas refait, un titre déjà saisi n'est pas écrasé.
//              Les sujets restent en brouillon : la publication se décide dans l'outil.
// Usage:       php script-cgi/importer-formation.php --dossier=<dossier du client> [--sujets=1-4] [--sans-audio]
//              Le dossier contient « Album Programme NavUp/NN - titre.wav » et « Fiches pratiques pour application/N.pdf ».
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../include/package.saisie.php";
include_once __DIR__ . "/../include/package.formation.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../require/param.php";

// Répartition officielle des quarante sujets sur douze semaines
$REPARTITION = array(
    1 => array(1 => "Le corps qui change", 2 => "Besoin de plaire et insécurité", 3 => "Sexualité et construction identitaire", 4 => "Stress et anxiété"),
    2 => array(5 => "La peur de décevoir et le regard du parent", 6 => "Accepter de ne pas être parfait", 7 => "La manipulation"),
    3 => array(8 => "L'insolence", 9 => "Manque de considération", 10 => "Être à l'écoute sans perdre son autorité"),
    4 => array(11 => "Laisser respirer sans abandonner le cadre", 12 => "Poser un cadre et comprendre son rôle de responsable légal", 13 => "Parents divorcés", 14 => "Familles nombreuses"),
    5 => array(15 => "Enfant unique", 16 => "Enfant malade", 17 => "Violence dans le couple et violence sur son enfant", 18 => "Impact socio-économique et pression financière"),
    6 => array(19 => "Harcèlement scolaire et harcèlement social", 20 => "Harcèlement en ligne", 21 => "Comparaison sociale et pression matérielle"),
    7 => array(22 => "Décrochage scolaire", 23 => "Lycée professionnel et SEGPA", 24 => "Vie scolaire et cadre scolaire"),
    8 => array(25 => "Le regard des autres adultes", 26 => "Inégalité sociale et impact sur la scolarité", 27 => "Sentiment d'exclusion"),
    9 => array(28 => "Mauvaises fréquentations", 29 => "La délinquance", 30 => "Vie en dehors de la maison"),
    10 => array(31 => "Drogues et substances toxiques", 32 => "Violence agie ou subie", 33 => "Réseaux sociaux", 34 => "Temps d'écran"),
    11 => array(35 => "Le sommeil", 36 => "Activité physique", 37 => "Santé générale"),
    12 => array(38 => "Stress chronique", 39 => "Insécurité ressentie", 40 => "Épreuves de la vie"),
);

$options = getopt('', array('dossier:', 'sujets:', 'sans-audio'));
$dossier = isset($options['dossier']) ? rtrim((string) $options['dossier'], '/') : '';
if ($dossier === '' || !is_dir($dossier)) {
    fwrite(STDERR, "Usage : php script-cgi/importer-formation.php --dossier=<dossier du client> [--sujets=1-4] [--sans-audio]\n");
    exit(2);
}
$premier = 1;
$dernier = 40;
if (isset($options['sujets']) && preg_match('/^(\d{1,2})(?:-(\d{1,2}))?$/', (string) $options['sujets'], $m)) {
    $premier = (int) $m[1];
    $dernier = isset($m[2]) ? (int) $m[2] : $premier;
}
$avecAudio = !isset($options['sans-audio']);

Automate::demarrer(__FILE__);

$formation = $Formation->charger();
if ($formation === null) {
    fwrite(STDERR, "Aucune formation : appliquer sql/040_formation.sql.\n");
    exit(1);
}
$idf = (int) $formation->id_formation;

$compte = array('semaines' => 0, 'sujets' => 0, 'fiches' => 0, 'audios' => 0, 'absents' => array());

try {
    $Formation->dossier();

    foreach ($REPARTITION as $numero => $sujets) {
        $semaine = $Mysql->fetchOne("SELECT * FROM f_semaine WHERE id_formation = ? AND numero = ?", array($idf, $numero), 'ii');
        if ($semaine === null) {
            // Les semaines se créent dans l'ordre : creerSemaine() numérote à la suite
            $existantes = (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM f_semaine WHERE id_formation = ?", array($idf), 'i')->n;
            if ($existantes !== $numero - 1) {
                fwrite(STDERR, "La formation a déjà des semaines qui ne suivent pas la répartition officielle : import abandonné.\n");
                exit(1);
            }
            $ids = $Formation->creerSemaine($formation, array(), null);
            $semaine = $Formation->chargerSemaine($ids);
            $compte['semaines']++;
        }

        foreach ($sujets as $n => $titre) {
            if ($n < $premier || $n > $dernier) {
                continue;
            }
            $sujet = $Mysql->fetchOne("SELECT * FROM f_sujet WHERE id_formation = ? AND numero = ?", array($idf, $n), 'ii');
            if ($sujet === null) {
                $id = $Formation->creerSujet($formation, array(
                    'id_semaine' => (int) $semaine->id_semaine,
                    'numero' => $n,
                    'titre' => $titre,
                    // Deux illustrations officielles, en alternance d'un sujet à l'autre
                    'pochette' => $n % 2 === 1 ? 'jaune' : 'bleu',
                ), null);
                $sujet = $Formation->chargerSujet($id);
                $compte['sujets']++;
            }
            $ids = (int) $sujet->id_sujet;

            $present = function ($role) use ($Mysql, $ids) {
                return $Mysql->fetchOne("SELECT 1 AS x FROM f_fichier WHERE id_sujet = ? AND role = ? AND etat = 'pret' LIMIT 1", array($ids, $role), 'is') !== null;
            };

            if (!$present('fiche')) {
                $pdf = $dossier . "/Fiches pratiques pour application/$n.pdf";
                if (is_file($pdf) && $Formation->importer($sujet, 'fiche', $pdf, "$n.pdf") !== null) {
                    $compte['fiches']++;
                } else {
                    $compte['absents'][] = sprintf("fiche %02d", $n);
                }
            }

            if ($avecAudio && !$present('audio')) {
                $trouves = glob($dossier . "/Album Programme NavUp/" . sprintf('%02d', $n) . " - *.{wav,mp3,m4a}", GLOB_BRACE);
                if (is_array($trouves) && count($trouves) === 1 && $Formation->importer($sujet, 'audio', $trouves[0], basename($trouves[0])) !== null) {
                    $compte['audios']++;
                    echo sprintf("  audio %02d converti\n", $n);
                } else {
                    $compte['absents'][] = sprintf("audio %02d", $n);
                }
            }
        }
    }
} catch (ErreurMetier $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "{$compte['semaines']} semaine(s), {$compte['sujets']} sujet(s), {$compte['fiches']} fiche(s) et {$compte['audios']} audio(s) ajouté(s).\n";
if (count($compte['absents']) > 0) {
    echo "Introuvables ou d'un type inattendu : " . implode(', ', $compte['absents']) . ".\n";
}
exit(0);

#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-statistiques.php
// Description: lecture seule. Recoupe les statistiques (étape 7a) entre elles, pour chaque période et pour un
//              administrateur actif : les répartitions redonnent leur total, les rendez-vous se partagent sans reste,
//              le mois en cours de l'évolution est celui de la période « mois », le classeur et le rapport se
//              fabriquent. L'égalité de chaque chiffre avec sa liste se contrôle dans navup-front (npm run parcours).
// Usage:       php script-cgi/verifier-statistiques.php      (code 1 au premier écart)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

foreach (array('mysql', 'response', 'user', 'saisie', 'contact', 'vente', 'pilotage', 'xlsx', 'export', 'pdf', 'statistiques', 'automate') as $p) {
    include __DIR__ . "/../include/package.$p.php";
}
include __DIR__ . "/../require/param.php";

Automate::demarrer(__FILE__);
$Pilotage = new Pilotage();

$user = $Mysql->fetchOne("SELECT * FROM u_users WHERE profil = 'admin' AND actif = 1 ORDER BY id_users LIMIT 1");
if ($user === null) {
    fwrite(STDERR, "Aucun administrateur actif.\n");
    exit(1);
}
$aujourdhui = date('Y-m-d');
$ecarts = array();
$n = 0;

foreach (array('jour', '7j', 'mois', 'trimestre', 'annee') as $code) {
    $_GET = array('periode' => $code);
    $periode = $Pilotage->resoudrePeriode($aujourdhui);
    $s = $Pilotage->statistiques($user, $periode, $aujourdhui);
    $n++;

    $v = $s['finances']['ventes'];
    if ($v['comptant']['nb'] + $v['fractionne']['nb'] !== $v['nb'] || $v['comptant']['vendu'] + $v['fractionne']['vendu'] !== $v['vendu']) {
        $ecarts[] = "$code : comptant et en plusieurs fois ne redonnent pas les ventes";
    }
    $somme = array_sum(array_column($s['finances']['modalites'], 'nb'));
    if ($somme !== $v['nb']) {
        $ecarts[] = "$code : modalités $somme, ventes {$v['nb']}";
    }
    $moyens = array_sum(array_column($s['finances']['moyens'], 'encaisse'));
    $sansMoyen = (int) $Mysql->fetchOne(
        "SELECT COALESCE(SUM(CASE WHEN type = 'encaissement' THEN montant WHEN type = 'impaye' THEN -montant ELSE 0 END), 0) AS m FROM v_paiement
         WHERE date_annulation IS NULL AND code_moyen IS NULL AND date_paiement >= ? AND date_paiement <= ?",
        array($periode['du'], $periode['au'])
    )->m;
    if ($moyens + $sansMoyen !== $s['finances']['ecritures']['encaisse']) {
        $ecarts[] = "$code : moyens $moyens (+ $sansMoyen sans moyen), encaissé {$s['finances']['ecritures']['encaisse']}";
    }
    $origines = array_sum(array_column($s['origines']['lignes'], 'dossiers'));
    if ($origines !== $s['origines']['total']) {
        $ecarts[] = "$code : origines $origines, dossiers {$s['origines']['total']}";
    }
    $nonClasses = $s['nouveaux']['total'] - array_sum($s['nouveaux']['classes']);
    if ($s['origines']['total'] !== $nonClasses) {
        $ecarts[] = "$code : dossiers des origines {$s['origines']['total']}, nouveaux non classés $nonClasses";
    }
    $r = $s['rendez_vous'];
    if ($r['prevus'] + $r['effectues'] + $r['absents'] + $r['annules'] !== $r['total']) {
        $ecarts[] = "$code : les rendez-vous ne se partagent pas sans reste";
    }
    if (array_sum(array_column($r['types'], 'nb')) !== $r['total'] - $r['annules']) {
        $ecarts[] = "$code : les types de rendez-vous ne redonnent pas les rendez-vous tenus";
    }
    $semaines = array_sum(array_column($s['programme']['semaines'], 'nb'));
    if ($semaines !== $s['programme']['en_cours']) {
        $ecarts[] = "$code : semaines $semaines, programmes en cours {$s['programme']['en_cours']}";
    }
    foreach (array('modalites', 'moyens') as $cle) {
        foreach ($s['finances'][$cle] as $l) {
            if ($l['part'] < 0 || $l['part'] > 100) {
                $ecarts[] = "$code : part hors bornes ($cle)";
            }
        }
    }
    if ($code === 'mois') {
        $m = end($s['evolution']);
        if ($m['vendu'] !== $v['vendu'] || $m['encaisse'] !== $s['finances']['ecritures']['encaisse'] || $m['nouveaux'] !== $s['nouveaux']['total']) {
            $ecarts[] = "mois : le dernier mois de l'évolution diffère de la période « mois »";
        }
    }
    // Le classeur et le rapport se fabriquent, avec une feuille et une section par bloc
    $x = Statistiques::classeur($s);
    $chemin = $x->ecrire();
    $z = new ZipArchive();
    if ($z->open($chemin) !== true || $z->getFromName('xl/workbook.xml') === false) {
        $ecarts[] = "$code : classeur illisible";
    }
    $z->close();
    unlink($chemin);
    $pdf = Statistiques::rapport($s, 'Contrôle', __DIR__ . '/../assets/logo-navup.jpg');
    if (strncmp($pdf, '%PDF-1.4', 8) !== 0 || strpos($pdf, '%%EOF') === false) {
        $ecarts[] = "$code : rapport illisible";
    }
}

if (count($ecarts) > 0) {
    fwrite(STDERR, implode("\n", $ecarts) . "\n");
    echo count($ecarts) . " écart(s).\n";
    exit(1);
}
echo "Statistiques vérifiées sur $n périodes : aucun écart.\n";

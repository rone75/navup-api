#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/vieillir-essais.php
// Description: étale dans le passé les événements des dossiers d'essai (e-mail en essai.…@navup.local),
//              pour que les captures d'écran du front montrent un fil du temps sur plusieurs jours.
//              Du plus récent au plus ancien : deux événements restent aujourd'hui, puis deux tous les trois jours.
//              La note liée à un événement suit sa date ; le dossier prend la date de son premier événement.
//              Ne touche à aucun autre dossier. Refusé en production ($_PROD = 1).
// Usage:       php script-cgi/vieillir-essais.php
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../require/param.php";

if (!empty($_PROD)) {
    fwrite(STDERR, "Refusé : ce script ne s'exécute pas en production.\n");
    exit(1);
}

date_default_timezone_set('Europe/Paris');

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$dossiers = $Mysql->fetchAll("SELECT id_contact FROM d_contact WHERE email LIKE ?", array('essai.%@navup.local'), 's');
$nb = 0;

foreach ($dossiers as $d) {
    $idc = (int) $d->id_contact;
    $evenements = $Mysql->fetchAll(
        "SELECT id_evenement, type, objet_type, objet_id FROM d_evenement WHERE id_contact = ? ORDER BY id_evenement DESC",
        array($idc),
        'i'
    );
    if (count($evenements) < 2) {
        continue;
    }

    foreach ($evenements as $i => $e) {
        $jours = intdiv($i, 2) * 3;
        $minutes = $i * 37;
        $Mysql->execute(
            "UPDATE d_evenement SET date_evenement = NOW() - INTERVAL ? DAY - INTERVAL ? MINUTE WHERE id_evenement = ?",
            array($jours, $minutes, (int) $e->id_evenement),
            'iii'
        );
        if ($e->objet_type === 'note' && $e->objet_id !== null) {
            $Mysql->execute(
                "UPDATE d_note SET date_creation = NOW() - INTERVAL ? DAY - INTERVAL ? MINUTE WHERE id_note = ?",
                array($jours, $minutes, (int) $e->objet_id),
                'iii'
            );
        }
        // Une déclaration ou une problématique est déclarée le jour où elle entre au dossier (premier événement qui la concerne)
        if ($e->type === 'declaration' && $e->objet_id !== null) {
            $Mysql->execute("UPDATE d_declaration SET date_declaration = CURDATE() - INTERVAL ? DAY WHERE id_declaration = ?", array($jours, (int) $e->objet_id), 'ii');
        }
        if ($e->type === 'problematique' && $e->objet_id !== null) {
            $Mysql->execute("UPDATE d_problematique SET date_declaration = CURDATE() - INTERVAL ? DAY WHERE id_problematique = ?", array($jours, (int) $e->objet_id), 'ii');
        }
        $nb++;
    }

    $Mysql->execute(
        "UPDATE d_contact SET date_creation = (SELECT MIN(date_evenement) FROM d_evenement WHERE id_contact = ?) WHERE id_contact = ?",
        array($idc, $idc),
        'ii'
    );
}

echo count($dossiers) . " dossier(s) d'essai, $nb événement(s) redaté(s).\n";

exit(0);

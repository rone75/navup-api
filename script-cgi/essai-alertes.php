#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/essai-alertes.php
// Description: poste de développement. Place un dossier d'essai (e-mail en essai.…@navup.local) dans la situation d'une
//              alerte de l'étape 7b, puis synchronise ses tâches automatiques. Refusé en production ; aucun autre
//              dossier n'est touché. Sortie : une ligne JSON {alertes: [codes des alertes ouvertes du dossier]}.
// Usage:       php script-cgi/essai-alertes.php --email=essai.x@navup.local --vieillir=30      faits du dossier reculés de 30 jours
//              php script-cgi/essai-alertes.php --email=… --fin-acces=5                       l'accès au programme se ferme dans 5 jours
//              php script-cgi/essai-alertes.php --email=… --programme-debut=-30               programme commencé il y a 30 jours
//              php script-cgi/essai-alertes.php --email=…                                     seulement synchroniser et lire
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

foreach (array('mysql', 'response', 'user', 'saisie', 'contact', 'suivi', 'automate') as $p) {
    include_once __DIR__ . "/../include/package.$p.php";
}
include __DIR__ . "/../require/param.php";

if (!empty($_PROD)) {
    fwrite(STDERR, "Refusé en production.\n");
    exit(1);
}
$o = getopt('', array('email:', 'vieillir::', 'fin-acces::', 'programme-debut::'));
$email = strtolower($o['email'] ?? '');
if (!preg_match('/^essai\.[a-z0-9.-]+@navup\.local$/', $email)) {
    fwrite(STDERR, "Un dossier d'essai : --email=essai.…@navup.local\n");
    exit(1);
}
Automate::demarrer(__FILE__);
$c = $Mysql->fetchOne("SELECT id_contact FROM d_contact WHERE email = ?", array($email), 's');
if ($c === null) {
    fwrite(STDERR, "Dossier introuvable.\n");
    exit(1);
}
$idc = (int) $c->id_contact;

if (isset($o['vieillir'])) {
    $n = max(1, (int) $o['vieillir']);
    $Mysql->execute("UPDATE d_evenement SET date_evenement = date_evenement - INTERVAL ? DAY WHERE id_contact = ?", array($n, $idc), 'ii');
    $Mysql->execute("UPDATE d_contact SET date_creation = date_creation - INTERVAL ? DAY, date_premier_contact = COALESCE(date_premier_contact, DATE(date_creation)) - INTERVAL ? DAY WHERE id_contact = ?", array($n, $n, $idc), 'iii');
}
if (isset($o['fin-acces'])) {
    // Les affectations se font dans l'ordre : début, fin, puis fin d'accès (contraintes : début ≤ fin ≤ fin d'accès)
    $n = (int) $o['fin-acces'];
    $Mysql->execute(
        "UPDATE a_compte SET date_debut = LEAST(date_debut, CURDATE() + INTERVAL ? DAY), date_fin = LEAST(date_fin, CURDATE() + INTERVAL ? DAY),
                             date_fin_acces = CURDATE() + INTERVAL ? DAY WHERE id_contact = ?",
        array($n, $n, $n, $idc),
        'iiii'
    );
}
if (isset($o['programme-debut'])) {
    $j = (int) $o['programme-debut'];
    $Mysql->execute("UPDATE a_compte SET date_debut = CURDATE() + INTERVAL ? DAY, date_fin = GREATEST(date_fin, CURDATE() + INTERVAL 30 DAY), date_fin_acces = GREATEST(date_fin_acces, CURDATE() + INTERVAL 60 DAY) WHERE id_contact = ?", array($j, $idc), 'ii');
}

$Tache->synchroniser($idc);
$alertes = array();
foreach ($Mysql->fetchAll("SELECT alerte FROM t_tache WHERE id_contact = ? AND alerte IS NOT NULL AND date_cloture IS NULL ORDER BY alerte", array($idc), 'i') as $t) {
    $alertes[] = $t->alerte;
}
echo json_encode(array('id_contact' => $idc, 'alertes' => $alertes)) . "\n";

#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/purge-essais.php
// Description: efface ce que laissent les parcours automatisés du front (navup-front/outils) :
//              les comptes d'essai (identifiant commençant par « essai. », jamais un admin), leurs sessions,
//              les dossiers d'essai (e-mail en essai.…@navup.local), leurs lignes d'audit, les événements et
//              sessions du navigateur sans tête, et le compteur d'échecs de connexion des adresses locales.
//              Refusé en production ($_PROD = 1).
// Usage:       php script-cgi/purge-essais.php
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

$comptes = $Mysql->fetchAll(
    "SELECT id_users, identifiant FROM u_users WHERE identifiant LIKE ? AND profil <> 'admin'",
    array('essai.%'),
    's'
);

$nb_audit = 0;

foreach ($comptes as $c) {
    $id = (int) $c->id_users;
    // Actions menées par le compte, puis actions menées sur lui
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE id_users = ?", array($id), 'i');
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE cible_type = 'user' AND cible_id = ?", array($id), 'i');
    // Les sessions partent avec le compte (clé étrangère ON DELETE CASCADE)
    $Mysql->execute("DELETE FROM u_users WHERE id_users = ?", array($id), 'i');
}

// Dossiers d'essai : e-mail en essai.…@navup.local (leurs déclarations, enfants, problématiques, notes et événements partent en cascade)
$dossiers = $Mysql->fetchAll("SELECT id_contact FROM d_contact WHERE email LIKE ?", array('essai.%@navup.local'), 's');
foreach ($dossiers as $d) {
    $idc = (int) $d->id_contact;
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE cible_type = 'contact' AND cible_id = ?", array($idc), 'i');
    $Mysql->execute("DELETE FROM d_contact WHERE id_contact = ?", array($idc), 'i');
}

// Échecs de connexion sur un identifiant d'essai inconnu, puis traces du navigateur sans tête des parcours
$nb_audit += $Mysql->execute(
    "DELETE FROM u_audit WHERE id_users IS NULL AND JSON_VALUE(details, '$.ident') LIKE ?",
    array('essai.%'),
    's'
);
$nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE user_agent LIKE ?", array('%HeadlessChrome%'), 's');
$nb_sessions = $Mysql->execute("DELETE FROM u_token WHERE user_agent LIKE ?", array('%HeadlessChrome%'), 's');

$Mysql->execute("DELETE FROM u_login_ip WHERE ip IN (?, ?)", array('::1', '127.0.0.1'), 'ss');

echo count($comptes) . " compte(s) et " . count($dossiers) . " dossier(s) d'essai supprimé(s), $nb_audit ligne(s) d'audit, $nb_sessions session(s) du navigateur sans tête.\n";

exit(0);

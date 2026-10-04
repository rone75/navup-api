#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/synchroniser-taches.php
// Description: synchronisation générale des tâches automatiques (CDC §14) : échéance en retard, paiement échoué,
//              rendez-vous à planifier, à confirmer ou sans compte rendu, appel à rappeler.
//              L'API la fait d'elle-même à la lecture des tâches et des listes de dossiers (au plus toutes les cinq
//              minutes) : ce script n'est nécessaire que pour la brancher sur une tâche planifiée, par exemple
//              chaque matin, afin que les tâches du jour existent avant la première connexion.
//              Utilisable en production. Silencieux pour les utilisateurs : ni audit, ni fil du temps.
// Usage:       php script-cgi/synchroniser-taches.php
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../include/package.contact.php";
include __DIR__ . "/../include/package.suivi.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$Tache = new Tache();

$maintenant = date('Y-m-d H:i:s');
$differences = $Tache->synchroniser(null, $maintenant);
$Mysql->execute("UPDATE t_synchro SET date_synchro = ? WHERE id = 1", array($maintenant), 's');

$compte = array('ouvrir' => 0, 'rouvrir' => 0, 'redater' => 0, 'fermer' => 0);
foreach ($differences as $d) {
    $compte[$d[0]]++;
}

echo "Tâches automatiques : {$compte['ouvrir']} ouverte(s), {$compte['rouvrir']} rouverte(s), {$compte['redater']} redatée(s), {$compte['fermer']} fermée(s).\n";
exit(0);

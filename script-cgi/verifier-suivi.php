#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-suivi.php
// Description: contrôle de cohérence du suivi, en lecture seule (utilisable en production) :
//              - le « dernier échange » de chaque dossier (cache d_contact.date_derniere_interaction) est celui que
//                donnent ses rendez-vous effectués et ses échanges aboutis ;
//              - les tâches automatiques sont à jour : aucune cause sans tâche, aucune tâche ouverte sans cause
//                (à lancer après une synchronisation : entre deux, une échéance peut être passée en retard) ;
//              - une tâche automatique n'a pas d'intitulé et vise le bon type d'objet ; une tâche manuelle a un intitulé ;
//              - un rendez-vous « reporté » a un successeur ; un successeur remplace un rendez-vous reporté, manqué ou annulé ;
//              - chaque rendez-vous a son fait de création dans le fil ; aucun échange n'est daté dans le futur ;
//              - aucune tâche de suivi n'est attribuée à quelqu'un qui ne peut pas la lire.
//              Sort avec le code 1 au premier écart, 0 si tout est cohérent.
// Usage:       php script-cgi/verifier-suivi.php
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

$Interaction = new Interaction();
$Tache = new Tache();
$ecarts = array();

// Dernier échange
$nb_dossiers = 0;
foreach ($Mysql->fetchAll("SELECT id_contact, date_derniere_interaction FROM d_contact ORDER BY id_contact") as $c) {
    $nb_dossiers++;
    $d = $Interaction->dernier($c->id_contact);
    $attendu = $d === null ? null : $d['date'];
    if ($attendu !== $c->date_derniere_interaction) {
        $ecarts[] = Contact::reference($c->id_contact) . " : dernier échange « " . ($c->date_derniere_interaction ?? 'aucun') . " » en base, « " . ($attendu ?? 'aucun') . " » d'après les faits";
    }
}

// Tâches automatiques : ce qu'une synchronisation changerait
foreach ($Tache->synchroniser(null, null, true) as $d) {
    $ecarts[] = "tâche automatique à {$d[0]} : {$d[1]}, objet n° {$d[2]}";
}

foreach ($Mysql->fetchAll("SELECT id_tache, alerte, objet_type, objet_id, titre FROM t_tache") as $t) {
    if ($t->alerte === null) {
        if ($t->titre === null || $t->objet_id !== null) {
            $ecarts[] = "tâche n° {$t->id_tache} : tâche manuelle sans intitulé, ou rattachée à un objet";
        }
    } elseif (!isset(Tache::ALERTES[$t->alerte]) || Tache::ALERTES[$t->alerte]['objet'] !== $t->objet_type || $t->objet_id === null || $t->titre !== null) {
        $ecarts[] = "tâche n° {$t->id_tache} : alerte « {$t->alerte} » mal formée";
    }
}

$profils = "'" . implode("', '", Suivi::profils('famille', 'L')) . "'";
foreach ($Mysql->fetchAll(
    "SELECT t.id_tache FROM t_tache t INNER JOIN u_users u ON u.id_users = t.id_users_assigne
     WHERE t.categorie = 'suivi' AND t.date_cloture IS NULL AND u.profil NOT IN ($profils)"
) as $t) {
    $ecarts[] = "tâche n° {$t->id_tache} : tâche de suivi attribuée à un profil sans accès aux données familiales";
}

// Rendez-vous
$nb_rdv = 0;
foreach ($Mysql->fetchAll(
    "SELECT r.id_rdv, r.statut, r.date_debut, r.id_rdv_precedent, p.statut AS statut_precedent,
            (SELECT COUNT(*) FROM r_rdv s WHERE s.id_rdv_precedent = r.id_rdv) AS successeurs,
            (SELECT COUNT(*) FROM d_evenement e WHERE e.objet_type = 'rdv' AND e.objet_id = r.id_rdv
               AND JSON_VALUE(e.details, '$.action') IN ('creation', 'report')) AS faits
     FROM r_rdv r LEFT JOIN r_rdv p ON p.id_rdv = r.id_rdv_precedent ORDER BY r.id_rdv"
) as $r) {
    $nb_rdv++;
    if ($r->statut === 'reporte' && (int) $r->successeurs !== 1) {
        $ecarts[] = "rendez-vous n° {$r->id_rdv} : « reporté » sans successeur";
    }
    if ($r->id_rdv_precedent !== null && !in_array($r->statut_precedent, array('reporte', 'absent', 'annule'), true)) {
        $ecarts[] = "rendez-vous n° {$r->id_rdv} : remplace un rendez-vous « {$r->statut_precedent} »";
    }
    if ((int) $r->faits !== 1) {
        $ecarts[] = "rendez-vous n° {$r->id_rdv} : {$r->faits} fait(s) de création dans le fil (1 attendu)";
    }
}

// Échanges
$futurs = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM i_interaction WHERE date_interaction > ?", array(date('Y-m-d H:i:s')), 's')->nb;
if ($futurs > 0) {
    $ecarts[] = "$futurs échange(s) daté(s) dans le futur";
}
$nb_echanges = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM i_interaction")->nb;

if (count($ecarts) > 0) {
    fwrite(STDERR, implode("\n", $ecarts) . "\n");
    echo count($ecarts) . " écart(s).\n";
    exit(1);
}

echo "$nb_dossiers dossier(s), $nb_rdv rendez-vous, $nb_echanges échange(s) vérifié(s) : aucun écart.\n";
exit(0);

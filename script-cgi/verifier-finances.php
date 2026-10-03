#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-finances.php
// Description: contrôle de cohérence des ventes, en lecture seule (utilisable en production).
//              Recalcule chaque vente depuis ses écritures (Vente::calculer) et compare avec ce qui est en base :
//              - les sommes servies par les listes (fragment SQL Vente::SQL_SOMMES) ;
//              - les caches : statut, modalité, part payée et date de solde de chaque échéance ;
//              - la somme des échéances actives d'une vente en cours, égale à son total ;
//              - aucun trop-perçu, aucun remboursement au-delà de l'encaissé, aucune écriture avant la vente ;
//              - vendu = encaissé + reste dû, vente par vente.
//              Sort avec le code 1 au premier écart, 0 si tout est cohérent.
// Usage:       php script-cgi/verifier-finances.php
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.contact.php";
include __DIR__ . "/../include/package.vente.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$Vente = new Vente();
$ecarts = array();
$nb = 0;

foreach ($Mysql->fetchAll("SELECT " . Vente::COLONNES . Vente::SQL_FROM . " ORDER BY v.id_vente") as $v) {
    $nb++;
    $ref = Vente::reference($v->id_vente);
    $c = $Vente->calculer($v->id_vente);

    if ($c['encaisse'] !== (int) $v->encaisse) {
        $ecarts[] = "$ref : encaissé {$v->encaisse} dans les listes, {$c['encaisse']} d'après les écritures";
    }
    if ($c['rembourse'] !== (int) $v->rembourse) {
        $ecarts[] = "$ref : remboursé {$v->rembourse} dans les listes, {$c['rembourse']} d'après les écritures";
    }
    if ($c['statut'] !== $v->statut) {
        $ecarts[] = "$ref : statut « {$v->statut} » en base, « {$c['statut']} » d'après les écritures";
    }
    if ($c['modalite'] !== $v->modalite) {
        $ecarts[] = "$ref : modalité « {$v->modalite} » en base, « {$c['modalite']} » d'après l'échéancier";
    }
    if ((int) $v->vendu !== (int) $v->encaisse + (int) $v->reste_du) {
        $ecarts[] = "$ref : vendu {$v->vendu} différent de encaissé {$v->encaisse} + reste dû {$v->reste_du}";
    }
    if ($v->date_annulation === null && (int) $v->encaisse > (int) $v->montant) {
        $ecarts[] = "$ref : trop-perçu ({$v->encaisse} encaissés pour {$v->montant} dus)";
    }
    if ((int) $v->rembourse > max(0, (int) $v->encaisse)) {
        $ecarts[] = "$ref : remboursé {$v->rembourse} pour {$v->encaisse} encaissés";
    }

    $somme = 0;
    foreach ($Mysql->fetchAll("SELECT id_echeance, rang, montant, montant_paye, date_solde, date_dernier_echec FROM v_echeance WHERE id_vente = ? AND date_annulation IS NULL", array((int) $v->id_vente), 'i') as $e) {
        $somme += (int) $e->montant;
        $n = $c['echeances'][(int) $e->id_echeance];
        if ($n['montant_paye'] !== (int) $e->montant_paye || $n['date_solde'] !== $e->date_solde || $n['date_dernier_echec'] !== $e->date_dernier_echec) {
            $ecarts[] = "$ref, échéance {$e->rang} : cache différent du calcul (payé {$e->montant_paye} / {$n['montant_paye']})";
        }
    }
    if ($v->date_annulation === null && $somme !== (int) $v->montant) {
        $ecarts[] = "$ref : somme des échéances $somme pour un total de {$v->montant}";
    }

    $avant = (int) $Mysql->fetchOne(
        "SELECT COUNT(*) AS nb FROM v_paiement WHERE id_vente = ? AND date_annulation IS NULL AND date_paiement < ?",
        array((int) $v->id_vente, $v->date_vente),
        'is'
    )->nb;
    if ($avant > 0) {
        $ecarts[] = "$ref : $avant écriture(s) datée(s) avant la vente";
    }
}

// Un impayé rejette un encaissement précis, pour son montant
foreach ($Mysql->fetchAll(
    "SELECT i.id_paiement, i.id_vente FROM v_paiement i LEFT JOIN v_paiement o ON o.id_paiement = i.id_paiement_origine
     WHERE i.type = 'impaye' AND i.date_annulation IS NULL
       AND (o.id_paiement IS NULL OR o.type <> 'encaissement' OR o.montant <> i.montant OR o.id_vente <> i.id_vente OR o.date_annulation IS NOT NULL)"
) as $i) {
    $ecarts[] = Vente::reference($i->id_vente) . " : l'impayé n° {$i->id_paiement} ne correspond à aucun encaissement valide de même montant";
}

if (count($ecarts) > 0) {
    fwrite(STDERR, implode("\n", $ecarts) . "\n");
    echo count($ecarts) . " écart(s) sur $nb vente(s).\n";
    exit(1);
}

echo "$nb vente(s) vérifiée(s) : aucun écart.\n";
exit(0);

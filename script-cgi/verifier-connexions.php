#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-connexions.php
// Description: contrôle de cohérence des connexions, en lecture seule (utilisable en production) :
//              - Stripe : aucun signal resté « reçu » depuis plus d'une heure ;
//              - e-mails : aucun message en attente depuis plus d'une heure ; chaque message envoyé a son fait dans le
//                fil du dossier ; aucun corps conservé ne contient de lien à jeton ;
//              - comptes : tout dossier qui a payé une vente non défaite a un compte ; un compte actif a des dates ;
//              - commandes : une commande payée a sa vente ; une vente née d'une commande est de source « stripe » ;
//              - formation : chaque sujet publié a son audio et sa fiche prêts ; chaque fichier prêt existe sur le
//                disque, avec la taille et l'empreinte notées ; le dossier des médias refuse l'accès HTTP direct ;
//              - appli des parents : la vue a_semaine et Compte::programme() donnent la même semaine en cours ; l'accès
//                ne se ferme pas avant la fin du programme ; une fiche prête a ses pages en images ; aucun corps
//                d'e-mail conservé ne contient un lien d'accès ;
//              - tâche planifiée : aucune passe en échec (et, en production, dernier passage de moins d'une heure).
//              Sort avec le code 1 au premier écart, 0 si tout est cohérent.
// Usage:       php script-cgi/verifier-connexions.php [--rapide]   (--rapide : sans recalcul des empreintes)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.compte.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$rapide = in_array('--rapide', $argv, true);
$ecarts = array();
$nombre = function ($sql, $params = array()) use ($Mysql) {
    return (int) $Mysql->fetchOne($sql, $params)->n;
};

// Stripe
$n = $nombre("SELECT COUNT(*) AS n FROM s_evenement WHERE statut = 'recu' AND date_creation < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
if ($n > 0) {
    $ecarts[] = "$n signal(aux) Stripe reçu(s) depuis plus d'une heure sans être traité(s)";
}

// E-mails
$n = $nombre("SELECT COUNT(*) AS n FROM m_message WHERE etat = 'a_envoyer' AND date_creation < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
if ($n > 0) {
    $ecarts[] = "$n e-mail(s) en attente depuis plus d'une heure";
}
$n = $nombre(
    "SELECT COUNT(*) AS n FROM m_message m WHERE m.etat = 'envoye'
       AND NOT EXISTS (SELECT 1 FROM d_evenement e WHERE e.id_contact = m.id_contact AND e.type = 'email' AND e.objet_type = 'message' AND e.objet_id = m.id_message)"
);
if ($n > 0) {
    $ecarts[] = "$n e-mail(s) envoyé(s) sans fait dans le fil du dossier";
}
$n = $nombre("SELECT COUNT(*) AS n FROM m_message WHERE corps LIKE '%paiement/?j=%' OR corps LIKE '%mot-de-passe#%'");
if ($n > 0) {
    $ecarts[] = "$n e-mail(s) conservé(s) avec un lien à jeton";
}

// Appli des parents : ce que la base lui sert doit dire la même chose que la Tour de contrôle
// - la vue a_semaine (date de déblocage de chaque semaine) et Compte::programme() (semaine en cours) ;
// - la fin de l'accès ne précède jamais la fin du programme ;
// - une fiche prête a ses pages rendues en images, présentes sur le disque (quand le serveur sait les rendre).
$jour = date('Y-m-d');
$desaccords = 0;
foreach ($Mysql->fetchAll("SELECT id_compte, id_formation, etat, date_debut, date_fin FROM a_compte WHERE etat = 'actif' AND date_debut <= ? AND date_fin >= ?", array($jour, $jour), 'ss') as $c) {
    $vue = $Mysql->fetchOne("SELECT MAX(numero) AS semaine FROM a_semaine WHERE id_compte = ? AND date_deblocage <= ?", array((int) $c->id_compte, $jour), 'is');
    $programme = Compte::programme($c->etat, $c->id_formation, $c->date_debut, $c->date_fin, $jour);
    if ($vue === null || (int) $vue->semaine !== (int) $programme['semaine']) {
        $desaccords++;
    }
}
if ($desaccords > 0) {
    $ecarts[] = "$desaccords compte(s) dont la semaine en cours diffère entre la vue a_semaine (appli des parents) et Compte::programme()";
}
$n = $nombre("SELECT COUNT(*) AS n FROM a_compte WHERE date_fin_acces < date_fin");
if ($n > 0) {
    $ecarts[] = "$n compte(s) dont l'accès se ferme avant la fin du programme";
}
if (isset($_PDFTOPPM, $_PDFINFO) && $_PDFTOPPM !== '' && is_executable($_PDFTOPPM) && $_PDFINFO !== '' && is_executable($_PDFINFO)) {
    $sansPages = 0;
    foreach ($Mysql->fetchAll("SELECT empreinte, pages FROM f_fichier WHERE role = 'fiche' AND etat = 'pret' AND type_mime = 'application/pdf'") as $f) {
        if ($f->pages === null || (int) $f->pages < 1) {
            $sansPages++;
            continue;
        }
        for ($p = 1; $p <= (int) $f->pages; $p++) {
            if (!is_file(rtrim($_DOSSIER_MEDIAS, '/') . '/' . Formation::nomPage($f->empreinte, $p))) {
                $sansPages++;
                break;
            }
        }
    }
    if ($sansPages > 0) {
        $ecarts[] = "$sansPages fiche(s) prête(s) sans leurs pages en images (php script-cgi/rendre-fiches.php)";
    }
}

// Comptes
$n = $nombre(
    "SELECT COUNT(DISTINCT v.id_contact) AS n FROM v_vente v
     INNER JOIN f_formation f ON f.code_offre = v.code_offre
     LEFT JOIN a_compte a ON a.id_contact = v.id_contact
     WHERE a.id_compte IS NULL AND v.statut NOT IN ('annule', 'rembourse')
       AND EXISTS (SELECT 1 FROM v_paiement p WHERE p.id_vente = v.id_vente AND p.type = 'encaissement' AND p.date_annulation IS NULL)"
);
if ($n > 0) {
    $ecarts[] = "$n dossier(s) avec une vente payée et sans compte NavUp Academy";
}

// Commandes en ligne
$n = $nombre("SELECT COUNT(*) AS n FROM s_commande c LEFT JOIN v_vente v ON v.id_vente = c.id_vente WHERE c.etat = 'payee' AND (v.id_vente IS NULL OR v.source <> 'stripe' OR v.id_contact <> c.id_contact)");
if ($n > 0) {
    $ecarts[] = "$n commande(s) payée(s) sans leur vente";
}

// Formation
foreach ($Mysql->fetchAll(
    "SELECT s.numero FROM f_sujet s WHERE s.publie = 1 AND (
         NOT EXISTS (SELECT 1 FROM f_fichier f WHERE f.id_sujet = s.id_sujet AND f.role = 'audio' AND f.etat = 'pret')
         OR NOT EXISTS (SELECT 1 FROM f_fichier f WHERE f.id_sujet = s.id_sujet AND f.role = 'fiche' AND f.etat = 'pret'))"
) as $s) {
    $ecarts[] = "sujet {$s->numero} : publié sans son audio ou sans sa fiche";
}
$dossier = isset($_DOSSIER_MEDIAS) ? rtrim((string) $_DOSSIER_MEDIAS, '/') : '';
// Sous la racine d'Apache, le dossier des médias doit porter sa règle de refus : sans elle, un fichier se lirait sans session
if ($dossier !== '' && is_dir($dossier) && strpos($dossier . '/', '/var/www/') === 0 && !is_file($dossier . '/.htaccess')) {
    $ecarts[] = "dossier des médias : la règle de refus HTTP (.htaccess) est absente";
}
$fichiers = 0;
foreach ($Mysql->fetchAll("SELECT id_fichier, chemin, taille, empreinte FROM f_fichier WHERE etat = 'pret'") as $f) {
    $fichiers++;
    $chemin = $dossier . '/' . $f->chemin;
    if ($f->chemin === null || !is_file($chemin)) {
        $ecarts[] = "fichier n° {$f->id_fichier} : absent du dossier des médias";
    } elseif (filesize($chemin) !== (int) $f->taille) {
        $ecarts[] = "fichier n° {$f->id_fichier} : taille différente de celle notée";
    } elseif (!$rapide && hash_file('sha256', $chemin) !== $f->empreinte) {
        $ecarts[] = "fichier n° {$f->id_fichier} : empreinte différente de celle notée";
    }
}

// Tâche planifiée
foreach ($Mysql->fetchAll("SELECT passe, code FROM t_planifie WHERE code <> 0") as $p) {
    $ecarts[] = "tâche planifiée : la passe « {$p->passe} » a échoué à son dernier passage";
}
if (!empty($_PROD) && $nombre("SELECT COUNT(*) AS n FROM t_planifie WHERE date_debut > DATE_SUB(NOW(), INTERVAL 1 HOUR)") === 0) {
    $ecarts[] = "tâche planifiée : aucun passage depuis une heure";
}

if (count($ecarts) > 0) {
    fwrite(STDERR, implode("\n", $ecarts) . "\n");
    echo count($ecarts) . " écart(s).\n";
    exit(1);
}

echo "Connexions vérifiées ($fichiers fichier(s) de formation) : aucun écart.\n";
exit(0);

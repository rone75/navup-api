#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/purge-essais.php
// Description: efface ce que laissent les parcours automatisés du front (navup-front/outils) :
//              les comptes d'essai (identifiant commençant par « essai. », jamais un admin), leurs sessions,
//              les dossiers d'essai (e-mail en essai.…@navup.local) avec leurs ventes, paiements, rendez-vous, échanges et tâches,
//              les tâches sans dossier créées par un compte d'essai ou par un essai (clé de saisie en e55a1e55-…),
//              leurs lignes d'audit, les événements et sessions du navigateur sans tête, et le compteur d'échecs de
//              connexion des adresses locales. Depuis l'étape 6 : avec chaque dossier d'essai, ses commandes en ligne,
//              pages et liens de paiement, prélèvements, signaux Stripe, e-mails et compte ; les sujets et semaines
//              d'essai de la formation (titre commençant par « Essai ») et leurs fichiers ; le limiteur d'adresses locales.
//              Refusé en production ($_PROD = 1).
//              Depuis l'étape 6b : les rendez-vous pris en ligne, leurs liens de gestion et ce que le parent a déclaré
//              partent avec le dossier, les plages, absences et flux d'agenda d'un compte d'essai avec ce compte (cascades).
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

// Tâches sans dossier laissées par les essais : celles d'un compte d'essai, et celles dont la clé de saisie porte la marque des essais
$taches = $Mysql->fetchAll(
    "SELECT t.id_tache FROM t_tache t LEFT JOIN u_users u ON u.id_users = t.id_users
     WHERE t.id_contact IS NULL AND (t.cle_saisie LIKE ? OR (u.identifiant LIKE ? AND u.profil <> 'admin'))",
    array('e55a1e55-%', 'essai.%'),
    'ss'
);
foreach ($taches as $t) {
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE cible_type = 'tache' AND cible_id = ?", array((int) $t->id_tache), 'i');
    $Mysql->execute("DELETE FROM t_tache WHERE id_tache = ?", array((int) $t->id_tache), 'i');
}

foreach ($comptes as $c) {
    $id = (int) $c->id_users;
    // Actions menées par le compte, puis actions menées sur lui
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE id_users = ?", array($id), 'i');
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE cible_type = 'user' AND cible_id = ?", array($id), 'i');
    // Les sessions partent avec le compte (clé étrangère ON DELETE CASCADE)
    $Mysql->execute("DELETE FROM u_users WHERE id_users = ?", array($id), 'i');
}

// Dossiers d'essai : e-mail en essai.…@navup.local (leurs déclarations, enfants, problématiques, notes, événements,
// rendez-vous, échanges et tâches partent en cascade)
$dossiers = $Mysql->fetchAll("SELECT id_contact FROM d_contact WHERE email LIKE ?", array('essai.%@navup.local'), 's');
foreach ($dossiers as $d) {
    $idc = (int) $d->id_contact;
    $nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE cible_type = 'contact' AND cible_id = ?", array($idc), 'i');
    // Demandes d'effacement déposées par l'appli des parents (étape 8) : elles survivraient au compte (SET NULL)
    $Mysql->execute("DELETE d FROM e_demande d LEFT JOIN a_compte a ON a.id_compte = d.id_compte WHERE a.id_contact = ? OR d.id_contact = ?", array($idc, $idc), 'ii');
    // Les ventes ne partent pas en cascade avec un dossier : historique, écritures et échéances s'effacent d'abord
    foreach (array('s_prelevement', 's_session', 's_lien', 'v_historique', 'v_paiement', 'v_echeance') as $table) {
        $Mysql->execute("DELETE FROM $table WHERE id_vente IN (SELECT id_vente FROM v_vente WHERE id_contact = ?)", array($idc), 'i');
    }
    // Signaux Stripe de ses ventes et de ses commandes (journal sans clé étrangère) : détachés et marqués ignorés,
    // pour que le rattrapage ne les relise pas chez Stripe ; les commandes, leurs pages de paiement, les consentements,
    // les e-mails et le compte partent en cascade avec le dossier
    $Mysql->execute("UPDATE s_evenement SET statut = 'ignore', erreur = NULL, id_vente = NULL, id_commande = NULL WHERE id_vente IN (SELECT id_vente FROM v_vente WHERE id_contact = ?) OR id_commande IN (SELECT id_commande FROM s_commande WHERE id_contact = ?)", array($idc, $idc), 'ii');
    $Mysql->execute("UPDATE s_commande SET id_vente = NULL WHERE id_contact = ?", array($idc), 'i');
    $Mysql->execute("DELETE FROM v_vente WHERE id_contact = ?", array($idc), 'i');
    $Mysql->execute("DELETE FROM d_contact WHERE id_contact = ?", array($idc), 'i');
}

// Signaux Stripe simulés par les parcours (identifiants en evt_essai… ou pi_essai…), tâches automatiques d'un compte
// disparu. Un signal ignoré reste consigné : effacé, il serait relu chez Stripe au prochain rattrapage.
$Mysql->execute("DELETE FROM s_evenement WHERE stripe_event_id LIKE ? OR objet_id LIKE ?", array('evt\\_essai%', 'pi\\_essai%'), 'ss');
$Mysql->execute("DELETE FROM t_tache WHERE objet_type = 'compte' AND objet_id NOT IN (SELECT id_compte FROM a_compte)");

// Sujets et semaines d'essai de la formation (titre commençant par « Essai »), avec leurs fichiers
$medias = isset($_DOSSIER_MEDIAS) ? rtrim((string) $_DOSSIER_MEDIAS, '/') : '';
foreach ($Mysql->fetchAll("SELECT fi.id_fichier, fi.chemin FROM f_fichier fi INNER JOIN f_sujet s ON s.id_sujet = fi.id_sujet WHERE s.titre LIKE ?", array('Essai%'), 's') as $f) {
    $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array((int) $f->id_fichier), 'i');
    foreach (array('part', 'source', 'mp3') as $suffixe) {
        @unlink($medias . '/.' . (int) $f->id_fichier . '.' . $suffixe);
    }
    if ($medias !== '' && $f->chemin !== null && $Mysql->fetchOne("SELECT 1 AS x FROM f_fichier WHERE chemin = ? LIMIT 1", array($f->chemin), 's') === null) {
        @unlink($medias . '/' . $f->chemin);
    }
}
$Mysql->execute("DELETE FROM u_audit WHERE cible_type IN ('sujet', 'semaine', 'fichier', 'formation') AND user_agent LIKE ?", array('%HeadlessChrome%'), 's');
$Mysql->execute("DELETE FROM f_sujet WHERE titre LIKE ?", array('Essai%'), 's');
$Mysql->execute("DELETE FROM f_semaine WHERE titre LIKE ? AND NOT EXISTS (SELECT 1 FROM f_sujet s WHERE s.id_semaine = f_semaine.id_semaine)", array('Essai%'), 's');
$Mysql->execute("DELETE FROM u_limite_ip WHERE ip IN (?, ?)", array('::1', '127.0.0.1'), 'ss');
// Appli des parents : ses comptes d'essai partent avec leur dossier (cascade) ; reste son limiteur, pour ce poste
$Mysql->execute("DELETE FROM e_limite WHERE ip IN (?, ?, ?)", array('::1', '127.0.0.1', ''), 'sss');

// Échecs de connexion sur un identifiant d'essai inconnu, puis traces du navigateur sans tête des parcours
$nb_audit += $Mysql->execute(
    "DELETE FROM u_audit WHERE id_users IS NULL AND JSON_VALUE(details, '$.ident') LIKE ?",
    array('essai.%'),
    's'
);
$nb_audit += $Mysql->execute("DELETE FROM u_audit WHERE user_agent LIKE ?", array('%HeadlessChrome%'), 's');
$nb_sessions = $Mysql->execute("DELETE FROM u_token WHERE user_agent LIKE ?", array('%HeadlessChrome%'), 's');

$Mysql->execute("DELETE FROM u_login_ip WHERE ip IN (?, ?)", array('::1', '127.0.0.1'), 'ss');

// Étape 7b : valeurs de listes ajoutées par les contrôles (libellé commençant par « Essai », jamais utilisées),
// vues enregistrées, versions de modèles d'e-mails et réglages écrits par un compte d'essai (l'administrateur temporaire compris)
foreach (array('p_origine' => 'd_contact WHERE code_origine', 'p_categorie_problematique' => 'd_problematique WHERE code_categorie', 'p_moyen_paiement' => 'v_paiement WHERE code_moyen') as $table => $usage) {
    foreach ($Mysql->fetchAll("SELECT code FROM $table WHERE libelle LIKE 'Essai%'") as $v) {
        if ((int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM $usage = ?", array($v->code), 's')->n === 0) {
            $Mysql->execute("DELETE FROM $table WHERE code = ?", array($v->code), 's');
        }
    }
}
$Mysql->execute("DELETE v FROM u_vue v INNER JOIN u_users u ON u.id_users = v.id_users WHERE u.identifiant LIKE 'essai.%'");
$Mysql->execute("DELETE m FROM m_modele m INNER JOIN u_users u ON u.id_users = m.id_users WHERE u.identifiant LIKE 'essai.%'");
$Mysql->execute("DELETE r FROM p_reglage r INNER JOIN u_users u ON u.id_users = r.id_users WHERE u.identifiant LIKE 'essai.%'");

echo count($comptes) . " compte(s), " . count($dossiers) . " dossier(s) et " . count($taches) . " tâche(s) sans dossier d'essai supprimé(s), $nb_audit ligne(s) d'audit, $nb_sessions session(s) du navigateur sans tête.\n";

exit(0);

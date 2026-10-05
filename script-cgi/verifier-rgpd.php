#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-rgpd.php
// Description: contrôle de l'effacement RGPD (étape 8) sur un dossier d'essai complet, monté puis retiré par le script :
//              famille, notes, rendez-vous et réservation, échange, tâche, e-mails, vente payée (identifiants Stripe),
//              commande, compte de l'appli (accès, session, progression), consentement, demande d'effacement.
//                - données remises au parent : ce qui est promis, aucune note interne ;
//                - propositions : un prospect ancien, un client dont l'accès a fini, un client sans écriture depuis 10 ans ;
//                - demande du parent : une tâche s'ouvre, puis se ferme avec l'effacement ;
//                - effacement familial : plus aucun texte familial ni note, identité et montants intacts ;
//                - effacement complet : ni nom, ni e-mail, ni téléphone, ni identifiant Stripe, cherchés dans toutes
//                  les colonnes de texte de toutes les tables ; montants intacts ; un second effacement refusé ;
//                - purge du journal et des e-mails anciens ; puis verifier-finances sans écart.
// Usage:       php script-cgi/verifier-rgpd.php     (code de sortie 1 au premier écart ; refusé en production)
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../include/package.saisie.php";
include __DIR__ . "/../include/package.contact.php";
include __DIR__ . "/../include/package.vente.php";
include __DIR__ . "/../include/package.suivi.php";
include __DIR__ . "/../include/package.message.php";
include_once __DIR__ . "/../include/package.formation.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../include/package.rgpd.php";
include __DIR__ . "/../require/param.php";

if (!empty($_PROD)) {
    exit("Refusé en production\n");
}

Automate::demarrer(__FILE__);
$Rgpd = new Rgpd();
$ecarts = 0;

function controle($cond, $libelle)
{
    global $ecarts;
    echo ($cond ? "OK      " : "ÉCART   ") . $libelle . "\n";
    if (!$cond) {
        $ecarts++;
    }
}
function un($sql, $p = array())
{
    global $Mysql;
    return $Mysql->fetchOne($sql, $p, str_repeat('s', count($p)));
}
function ecrire($sql, $p = array())
{
    global $Mysql;
    $Mysql->execute($sql, $p, str_repeat('s', count($p)));
    return $Mysql->lastId();
}

/** Lignes, dans toute la base, dont une colonne de texte contient l'un des marqueurs : « table.colonne » => nombre. */
function trouver($marqueurs)
{
    global $Mysql;
    $out = array();
    foreach ($Mysql->fetchAll(
        "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS c
         WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','json')
           AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE')"
    ) as $col) {
        foreach ($marqueurs as $m) {
            $n = (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM `{$col->t}` WHERE CONVERT(`{$col->c}` USING utf8mb4) LIKE ?", array('%' . $m . '%'), 's')->n;
            if ($n > 0) {
                $out["{$col->t}.{$col->c} ($m)"] = $n;
            }
        }
    }
    return $out;
}

/** Ce qui compte en finances pour un dossier : ventes, échéances, écritures, avec leurs montants. */
function finances($idc)
{
    return json_encode(array(
        un("SELECT COUNT(*) n, SUM(montant) m, SUM(montant_catalogue) c, GROUP_CONCAT(statut) s FROM v_vente WHERE id_contact = ?", array($idc)),
        un("SELECT COUNT(*) n, SUM(e.montant) m, SUM(e.montant_paye) p FROM v_echeance e JOIN v_vente v USING (id_vente) WHERE v.id_contact = ?", array($idc)),
        un("SELECT COUNT(*) n, SUM(p.montant) m, GROUP_CONCAT(p.type) t FROM v_paiement p JOIN v_vente v USING (id_vente) WHERE v.id_contact = ?", array($idc)),
    ));
}

$admin = un("SELECT id_users FROM u_users WHERE profil = 'admin' AND actif = 1 ORDER BY id_users LIMIT 1");
$id_admin = (int) $admin->id_users;
$formation = un("SELECT f.id_formation, f.code_offre, (SELECT s.id_sujet FROM f_sujet s WHERE s.id_formation = f.id_formation ORDER BY s.id_sujet LIMIT 1) AS id_sujet FROM f_formation f ORDER BY f.id_formation LIMIT 1");
$categorie = un("SELECT code FROM p_categorie_problematique ORDER BY ordre LIMIT 1")->code;
$moyen = un("SELECT code FROM p_moyen_paiement ORDER BY ordre LIMIT 1")->code;
$origine = un("SELECT code FROM p_origine ORDER BY ordre LIMIT 1")->code;

// Dossier d'essai ##################################################
$PRENOM = 'Zéphyrine';
$NOM = 'Rgpdessaï';
$EMAIL = 'essai.rgpd@navup.local';
$TEL = '+33600004242';
$SECRET = 'SECRET-FAMILLE-RGPD';
$INTERNE = 'NOTE-INTERNE-RGPD';

$idc = (int) ecrire("INSERT INTO d_contact (prenom, nom, email, telephone, statut, code_origine, origine_precision, date_premier_contact)
    VALUES (?, ?, ?, ?, 'client_actif', ?, ?, CURDATE())", array($PRENOM, $NOM, $EMAIL, $TEL, $origine, "par $SECRET"));
$ref = Contact::reference($idc);
ecrire("INSERT INTO d_declaration (id_contact, source, date_declaration, situation_familiale, motif, objectifs) VALUES (?, 'formulaire', CURDATE(), ?, ?, ?)",
    array($idc, "Situation $SECRET", "Motif $SECRET", "Objectifs $SECRET"));
$ide = ecrire("INSERT INTO d_enfant (id_contact, prenom, age, date_age) VALUES (?, 'Ézéchiel', 9, CURDATE())", array($idc));
$idp = ecrire("INSERT INTO d_problematique (id_contact, id_enfant, code_categorie, intitule, description, objectif_parent, date_declaration) VALUES (?, ?, ?, 'Écrans', ?, ?, CURDATE())",
    array($idc, $ide, $categorie, "Description $SECRET", "Objectif $SECRET"));
ecrire("INSERT INTO d_note (id_contact, id_problematique, texte, id_users) VALUES (?, ?, ?, ?)", array($idc, $idp, "Note $INTERNE", $id_admin));
ecrire("INSERT INTO d_evenement (id_contact, type, module, objet_type, objet_id) VALUES (?, 'note', 'famille', 'note', 1)", array($idc));
$idr = ecrire("INSERT INTO r_rdv (id_contact, type, statut, date_debut, duree, canal, motif, compte_rendu)
    VALUES (?, 'suivi', 'effectue', NOW() - INTERVAL 2 DAY, 45, 'visio', ?, ?)",
    array($idc, "Motif $INTERNE", "Compte rendu $INTERNE"));
ecrire("INSERT INTO r_reservation (id_rdv, voie, prenom, nom, email, telephone, note) VALUES (?, 'espace', ?, ?, ?, ?, ?)",
    array($idr, $PRENOM, $NOM, $EMAIL, $TEL, "Message du parent $SECRET"));
ecrire("INSERT INTO i_interaction (id_contact, date_interaction, resultat, motif, compte_rendu) VALUES (?, NOW() - INTERVAL 1 DAY, 'abouti', ?, ?)",
    array($idc, "Appel $INTERNE", "Échange $INTERNE"));
ecrire("INSERT INTO t_tache (id_contact, titre, date_echeance, cle_saisie) VALUES (?, ?, CURDATE(), 'e55a1e55-rgpd')", array($idc, "Tâche $INTERNE"));
ecrire("INSERT INTO m_message (id_contact, modele, module, destinataire, sujet, corps, cle, etat, date_envoi) VALUES (?, 'bienvenue', 'dossier', ?, 'Bienvenue chez NavUp', ?, ?, 'envoye', NOW())",
    array($idc, $EMAIL, "Bonjour $PRENOM, $SECRET", "essai-rgpd:$idc"));
$idv = (int) ecrire("INSERT INTO v_vente (id_contact, code_offre, date_vente, montant_catalogue, remise, modalite, code_moyen, commentaire, stripe_customer_id, stripe_payment_method_id, source)
    VALUES (?, ?, CURDATE(), 29900, 0, 'comptant', ?, ?, 'cus_essai_rgpd', 'pm_essai_rgpd', 'stripe')",
    array($idc, $formation->code_offre, $moyen, "Commentaire $INTERNE"));
$idech = ecrire("INSERT INTO v_echeance (id_vente, rang, date_prevue, montant, montant_paye, date_solde) VALUES (?, 1, CURDATE(), 29900, 29900, CURDATE())", array($idv));
ecrire("INSERT INTO v_paiement (id_vente, id_echeance, type, montant, date_paiement, code_moyen, commentaire, stripe_id, stripe_payment_intent_id, source)
    VALUES (?, ?, 'encaissement', 29900, CURDATE(), ?, ?, 'ch_essai_rgpd', 'pi_essai_rgpd', 'stripe')", array($idv, $idech, $moyen, "Paiement $INTERNE"));
$Vente->recalculer($idv);
ecrire("INSERT INTO s_commande (cle_saisie, id_contact, id_vente, prenom, nom, email, telephone, code_offre, montant, stripe_customer_id)
    VALUES ('e55a1e55-rgpd-commande', ?, ?, ?, ?, ?, ?, ?, 29900, 'cus_essai_rgpd')", array($idc, $idv, $PRENOM, $NOM, $EMAIL, $TEL, $formation->code_offre));
ecrire("INSERT INTO d_consentement (id_contact, type, accorde, version) VALUES (?, 'cgv', 1, 'essai')", array($idc));
$idcpt = (int) ecrire("INSERT INTO a_compte (id_contact, id_formation, date_debut, date_fin, date_fin_acces) VALUES (?, ?, CURDATE() - INTERVAL 7 DAY, CURDATE() + INTERVAL 80 DAY, CURDATE() + INTERVAL 110 DAY)",
    array($idc, $formation->id_formation));
ecrire("INSERT INTO e_acces (id_compte, mot_de_passe, date_mot_de_passe) VALUES (?, ?, NOW())", array($idcpt, password_hash('essai', PASSWORD_BCRYPT)));
ecrire("INSERT INTO e_session (jeton, id_compte, date_expiration) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", array(hash('sha256', 'essai-rgpd-' . $idc), $idcpt));
if ($formation->id_sujet !== null) {
    ecrire("INSERT INTO e_progression (id_compte, id_sujet, date_termine) VALUES (?, ?, NOW())", array($idcpt, $formation->id_sujet));
}
$finances_avant = finances($idc);

// Données remises au parent ########################################
$export = $Rgpd->exporter($idc);
$json = json_encode($export, JSON_UNESCAPED_UNICODE);
controle($export['identite']['email'] === $EMAIL && $export['reference'] === $ref, "export : identité et référence");
controle(count($export['declarations']) === 1 && strpos($json, "Situation $SECRET") !== false && strpos($json, "Message du parent $SECRET") !== false, "export : ce que le parent a déclaré et écrit");
controle(count($export['enfants']) === 1 && count($export['problematiques']) === 1, "export : enfants et problématiques");
controle(count($export['achats']) === 1 && $export['achats'][0]['montant'] === '299,00 €' && count($export['achats'][0]['paiements']) === 1, "export : achats et paiements, montants TTC");
controle(count($export['rendez_vous']) === 1 && count($export['emails_recus']) === 1 && count($export['consentements']) === 1, "export : rendez-vous, e-mails reçus, consentements");
controle(count($export['programmes']) === 1, "export : programme");
controle(strpos($json, $INTERNE) === false, "export : aucune note interne, aucun motif ni compte rendu de NavUp");
controle(strpos($json, 'cus_essai') === false && strpos($json, 'pi_essai') === false, "export : aucun identifiant technique");

// Propositions #####################################################
$propose = function ($idc) use ($Rgpd) {
    foreach ($Rgpd->echeances() as $p) {
        if ($p['id_contact'] === $idc) {
            return $p['niveau'] . ':' . $p['raison'];
        }
    }
    return null;
};
controle($propose($idc) === null, "client en programme : rien n'est proposé");
ecrire("UPDATE a_compte SET date_debut = date_debut - INTERVAL 4 YEAR, date_fin = date_fin - INTERVAL 4 YEAR, date_fin_acces = date_fin_acces - INTERVAL 4 YEAR WHERE id_compte = ?", array($idcpt));
ecrire("UPDATE v_vente SET date_vente = date_vente - INTERVAL 4 YEAR WHERE id_vente = ?", array($idv));
ecrire("UPDATE v_paiement SET date_paiement = date_paiement - INTERVAL 4 YEAR WHERE id_vente = ?", array($idv));
controle($propose($idc) === 'familial:client', "accès fini depuis plus de 3 ans : effacement familial proposé");
ecrire("UPDATE v_vente SET date_vente = date_vente - INTERVAL 7 YEAR WHERE id_vente = ?", array($idv));
ecrire("UPDATE v_paiement SET date_paiement = date_paiement - INTERVAL 7 YEAR WHERE id_vente = ?", array($idv));
ecrire("UPDATE a_compte SET date_debut = date_debut - INTERVAL 7 YEAR, date_fin = date_fin - INTERVAL 7 YEAR, date_fin_acces = date_fin_acces - INTERVAL 7 YEAR WHERE id_compte = ?", array($idcpt));
controle($propose($idc) === 'complet:comptable', "dernière écriture vieille de plus de 10 ans : effacement complet proposé");
$GLOBALS['_RGPD_COMPTABLE_ANS'] = 12;
controle($propose($idc) === 'familial:client', "délai réglé à 12 ans : de nouveau l'effacement familial seulement");
$GLOBALS['_RGPD_COMPTABLE_ANS'] = 10;

$idpr = (int) ecrire("INSERT INTO d_contact (prenom, nom, email, statut, date_statut, date_creation) VALUES ('Essai', 'Prospect ancien', 'essai.rgpd.prospect@navup.local', 'a_relancer', NOW() - INTERVAL 4 YEAR, NOW() - INTERVAL 4 YEAR)");
controle($propose($idpr) === 'complet:prospect', "prospect sans nouvelle depuis plus de 3 ans : effacement complet proposé");
ecrire("INSERT INTO d_evenement (id_contact, type, module, date_evenement) VALUES (?, 'note', 'dossier', NOW() - INTERVAL 1 YEAR)", array($idpr));
controle($propose($idpr) === null, "un fait récent dans le fil : plus proposé");

// Demande du parent ################################################
$idd = (int) ecrire("INSERT INTO e_demande (id_compte, type) VALUES (?, 'suppression')", array($idcpt));
$Tache->synchroniser($idc);
controle((int) un("SELECT COUNT(*) n FROM t_tache WHERE id_contact = ? AND alerte = 'demande_effacement' AND date_cloture IS NULL", array($idc))->n === 1, "demande du parent : une tâche s'ouvre");
$dem = $Rgpd->demandes();
$enAttente = array_values(array_filter($dem['en_attente'], function ($d) use ($idd) { return $d['id_demande'] === $idd; }));
controle(count($enAttente) === 1 && $enAttente[0]['id_contact'] === $idc && $enAttente[0]['niveau'] === 'familial', "demande listée, effacement familial proposé (client)");

// Effacement familial ##############################################
$Rgpd->effacer($idc, 'familial', $id_admin);
$reste = trouver(array($SECRET, $INTERNE));
controle(count($reste) === 0, "familial : plus aucun texte familial ni note interne dans la base" . (count($reste) ? ' — ' . json_encode($reste, JSON_UNESCAPED_UNICODE) : ''));
foreach (array('d_declaration', 'd_enfant', 'd_problematique', 'd_note', 'a_compte', 'm_message', 't_tache') as $t) {
    controle((int) un("SELECT COUNT(*) n FROM $t WHERE id_contact = ?", array($idc))->n === 0, "familial : $t vide pour le dossier");
}
controle((int) un("SELECT COUNT(*) n FROM e_acces WHERE id_compte = ?", array($idcpt))->n === 0 && (int) un("SELECT COUNT(*) n FROM e_session WHERE id_compte = ?", array($idcpt))->n === 0, "familial : accès et sessions de l'appli effacés");
$c = un("SELECT * FROM d_contact WHERE id_contact = ?", array($idc));
controle($c->nom === $NOM && $c->email === $EMAIL && $c->niveau_anonymisation === 'familial', "familial : l'identité reste, le degré est noté");
controle(finances($idc) === $finances_avant, "familial : ventes, échéances et écritures intactes");
$d = un("SELECT * FROM e_demande WHERE id_demande = ?", array($idd));
controle($d->date_traitement !== null && (int) $d->id_contact === $idc && $d->id_compte === null, "familial : la demande est close, rattachée au dossier");
$Tache->synchroniser($idc);
controle((int) un("SELECT COUNT(*) n FROM t_tache WHERE id_contact = ? AND alerte = 'demande_effacement'", array($idc))->n === 0, "la demande traitée n'ouvre plus de tâche");
controle((int) un("SELECT COUNT(*) n FROM u_audit WHERE action = 'rgpd_effacement' AND cible_id = ?", array($idc))->n === 1, "familial : inscrit au journal");
$refus = null;
try {
    $Rgpd->effacer($idc, 'familial', $id_admin);
} catch (ErreurMetier $e) {
    $refus = $e->getMessage();
}
controle($refus !== null, "un second effacement familial est refusé");

// Effacement complet ###############################################
$Rgpd->effacer($idc, 'complet', $id_admin);
$reste = trouver(array($PRENOM, $NOM, $EMAIL, substr($TEL, 3), 'cus_essai_rgpd', 'pm_essai_rgpd', 'pi_essai_rgpd', 'ch_essai_rgpd'));
controle(count($reste) === 0, "complet : ni nom, ni e-mail, ni téléphone, ni identifiant Stripe nulle part" . (count($reste) ? ' — ' . json_encode($reste, JSON_UNESCAPED_UNICODE) : ''));
$c = un("SELECT * FROM d_contact WHERE id_contact = ?", array($idc));
controle($c->nom === Rgpd::NOM_ANONYME && $c->niveau_anonymisation === 'complet' && $c->date_archivage !== null, "complet : « Dossier anonymisé », classé");
controle(finances($idc) === $finances_avant, "complet : ventes, échéances et écritures intactes");
$refus = null;
try {
    $Rgpd->effacer($idc, 'complet', $id_admin);
} catch (ErreurMetier $e) {
    $refus = $e->getMessage();
}
controle($refus !== null, "un dossier anonymisé ne s'efface plus");
controle($propose($idc) === null, "un dossier anonymisé n'est plus proposé");

// Purge du journal et des e-mails ##################################
$ida = ecrire("INSERT INTO u_audit (action, `date`) VALUES ('essai_rgpd', NOW() - INTERVAL 13 MONTH)");
$idb = ecrire("INSERT INTO u_audit (action, `date`) VALUES ('essai_rgpd', NOW() - INTERVAL 11 MONTH)");
$idm1 = ecrire("INSERT INTO m_message (id_contact, modele, module, destinataire, sujet, corps, cle, etat, date_envoi, date_creation) VALUES (?, 'bienvenue', 'dossier', 'x@navup.local', 's', 'c', 'essai-rgpd-vieux', 'envoye', NOW() - INTERVAL 13 MONTH, NOW() - INTERVAL 13 MONTH)", array($idpr));
$idm2 = ecrire("INSERT INTO m_message (id_contact, modele, module, destinataire, sujet, corps, cle, etat, date_creation) VALUES (?, 'bienvenue', 'dossier', 'x@navup.local', 's', 'c', 'essai-rgpd-attente', 'a_envoyer', NOW() - INTERVAL 13 MONTH)", array($idpr));
$Rgpd->purger();
controle(un("SELECT 1 x FROM u_audit WHERE id_audit = ?", array($ida)) === null && un("SELECT 1 x FROM u_audit WHERE id_audit = ?", array($idb)) !== null, "purge : journal de plus de 12 mois effacé, le plus récent gardé");
controle(un("SELECT 1 x FROM m_message WHERE id_message = ?", array($idm1)) === null && un("SELECT 1 x FROM m_message WHERE id_message = ?", array($idm2)) !== null, "purge : e-mail envoyé ancien effacé, e-mail en attente gardé");

// Retrait du dossier d'essai #######################################
foreach (array($idc, $idpr) as $id) {
    ecrire("DELETE FROM u_audit WHERE cible_type = 'contact' AND cible_id = ?", array($id));
    foreach (array('s_prelevement', 's_session', 's_lien', 'v_historique', 'v_paiement', 'v_echeance') as $table) {
        ecrire("DELETE FROM $table WHERE id_vente IN (SELECT id_vente FROM v_vente WHERE id_contact = ?)", array($id));
    }
    ecrire("UPDATE s_commande SET id_vente = NULL WHERE id_contact = ?", array($id));
    ecrire("DELETE FROM v_vente WHERE id_contact = ?", array($id));
    ecrire("DELETE FROM d_contact WHERE id_contact = ?", array($id));
}
ecrire("DELETE FROM e_demande WHERE id_demande = ?", array($idd));
ecrire("DELETE FROM u_audit WHERE action = 'essai_rgpd'");

// Finances de toute la base ########################################
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/verifier-finances.php') . ' 2>&1', $sortie, $code);
controle($code === 0, "verifier-finances sans écart" . ($code === 0 ? '' : ' — ' . implode(' / ', array_slice($sortie, -3))));

echo $ecarts === 0 ? "\nAucun écart.\n" : "\n$ecarts écart(s).\n";
exit($ecarts === 0 ? 0 : 1);

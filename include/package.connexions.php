<?php

//=======================================================================
// File:        package.connexions.php
// Description: supervision des connexions (CDC §23, §27 : « les automatisations échouées sont détectables et
//              relançables ») : état de Stripe, des e-mails, des médias de la formation et de la tâche planifiée,
//              ce qui est en erreur, et l'alerte par mail quand quelque chose demande un humain.
//              Aucun secret ni corps d'e-mail ici ; la raison d'un signal Stripe en erreur peut citer un montant :
//              elle n'est servie qu'à l'administrateur.
//              Requiert package.stripe.php ($Stripe), package.formation.php, package.mysql.php ($Mysql).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Connexions
{
    // Sans passage de la tâche planifiée depuis ce nombre de minutes, elle est en retard
    const RETARD_MINUTES = 60;

    private static function dossier($l)
    {
        if ($l->id_contact === null) {
            return null;
        }

        return array(
            'id_contact' => (int) $l->id_contact,
            'reference' => Contact::reference($l->id_contact),
            'prenom' => $l->prenom,
            'nom' => $l->nom,
            'groupe' => Contact::groupeDe($l->contact_statut),
        );
    }

    /** Tâche planifiée : dernier passage de chaque passe, et retard éventuel. */
    public static function planifie()
    {
        global $Mysql;

        $passes = array();
        $dernier = null;
        foreach ($Mysql->fetchAll("SELECT passe, date_debut, date_fin, code, resume FROM t_planifie WHERE passe <> 'alerte' ORDER BY date_debut, passe") as $p) {
            $passes[] = array(
                'passe' => $p->passe,
                'date_debut' => $p->date_debut,
                'date_fin' => $p->date_fin,
                'echec' => (int) $p->code !== 0 || ($p->date_fin === null && strtotime($p->date_debut) < time() - 1800),
                'resume' => $p->resume,
            );
            $dernier = $dernier === null ? $p->date_debut : max($dernier, $p->date_debut);
        }

        return array(
            'passes' => $passes,
            'dernier' => $dernier,
            'retard' => $dernier === null || strtotime($dernier) < time() - self::RETARD_MINUTES * 60,
        );
    }

    /** Réservations faites depuis la page publique en 24 heures, et le plafond au-delà duquel elle ne propose plus rien. */
    public static function plafondRdv()
    {
        global $Mysql, $_RDV_PRISE_PLAFOND;

        $nb = (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM r_reservation WHERE voie = 'public' AND date_creation > NOW() - INTERVAL 1 DAY")->n;
        $plafond = isset($_RDV_PRISE_PLAFOND) ? (int) $_RDV_PRISE_PLAFOND : 0;

        return array('reservations' => $nb, 'plafond' => $plafond, 'atteint' => $plafond > 0 && $nb >= $plafond);
    }

    /**
     * Nombre de choses qui demandent un humain : signaux Stripe en erreur, e-mails en erreur, audios dont la conversion
     * a échoué, plafond des rendez-vous en ligne atteint, passes en échec ; en production, la tâche planifiée en retard compte aussi (en développement, elle
     * se lance à la main).
     */
    public static function erreurs()
    {
        global $Mysql, $_PROD;

        $n = (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM s_evenement WHERE statut = 'erreur'")->n
            + (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM m_message WHERE etat = 'erreur'")->n
            + (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM f_fichier WHERE etat = 'erreur'")->n
            // Plafond des réservations publiques atteint : la prise de rendez-vous en ligne s'est fermée, quelqu'un doit regarder
            + (self::plafondRdv()['atteint'] ? 1 : 0);
        $planifie = self::planifie();
        foreach ($planifie['passes'] as $p) {
            if ($p['echec']) {
                $n++;
            }
        }
        if (!empty($_PROD) && $planifie['retard']) {
            $n++;
        }

        return $n;
    }

    /** État complet, pour l'onglet « Connexions » des paramètres. */
    public static function etat()
    {
        global $Mysql, $Stripe, $_MAIL_MODE, $_APP_PARENTS_URL, $_DOSSIER_MEDIAS, $_FFMPEG, $_PROD;

        $stripe = $Stripe->etat();
        $stripe['evenements'] = array();
        foreach ($Mysql->fetchAll(
            "SELECT e.*, c.id_contact, c.prenom, c.nom, c.statut AS contact_statut
             FROM s_evenement e
             LEFT JOIN v_vente v ON v.id_vente = e.id_vente
             LEFT JOIN s_commande co ON co.id_commande = e.id_commande
             LEFT JOIN d_contact c ON c.id_contact = COALESCE(v.id_contact, co.id_contact)
             WHERE e.statut IN ('erreur', 'recu') ORDER BY (e.statut = 'erreur') DESC, e.id_evenement DESC LIMIT 50"
        ) as $e) {
            $stripe['evenements'][] = array(
                'id_evenement' => (int) $e->id_evenement,
                'type' => $e->type,
                'canal' => $e->canal,
                'statut' => $e->statut,
                'essais' => (int) $e->essais,
                'erreur' => $e->erreur,
                'date_stripe' => $e->date_stripe,
                'date_traitement' => $e->date_traitement,
                'vente' => $e->id_vente === null ? null : array('id_vente' => (int) $e->id_vente, 'reference' => Vente::reference($e->id_vente)),
                'contact' => self::dossier($e),
                'lien_stripe' => strpos($e->objet_id, 'pi_') === 0 ? Vente::lienStripe('payments', $e->objet_id) : null,
            );
        }

        $messages = array();
        foreach ($Mysql->fetchAll(
            "SELECT m.id_message, m.modele, m.etat, m.essais, m.erreur, m.date_creation, m.id_contact, c.prenom, c.nom, c.statut AS contact_statut
             FROM m_message m INNER JOIN d_contact c ON c.id_contact = m.id_contact
             WHERE m.etat IN ('erreur', 'a_envoyer') ORDER BY (m.etat = 'erreur') DESC, m.id_message DESC LIMIT 50"
        ) as $m) {
            $messages[] = array(
                'id_message' => (int) $m->id_message,
                'modele' => $m->modele,
                'libelle' => Message::MODELES[$m->modele]['libelle'] ?? $m->modele,
                'etat' => $m->etat,
                'essais' => (int) $m->essais,
                'erreur' => $m->erreur,
                'date_creation' => $m->date_creation,
                'contact' => self::dossier($m),
            );
        }

        $fichiers = array();
        foreach ($Mysql->fetchAll(
            "SELECT f.id_fichier, f.id_sujet, f.nom, f.etat, f.erreur, f.date_creation, s.numero, s.titre
             FROM f_fichier f INNER JOIN f_sujet s ON s.id_sujet = f.id_sujet
             WHERE f.etat IN ('erreur', 'a_convertir') ORDER BY (f.etat = 'erreur') DESC, f.id_fichier DESC LIMIT 50"
        ) as $f) {
            $fichiers[] = array(
                'id_fichier' => (int) $f->id_fichier,
                'id_sujet' => (int) $f->id_sujet,
                'numero' => (int) $f->numero,
                'titre' => $f->titre,
                'nom' => $f->nom,
                'etat' => $f->etat,
                'erreur' => $f->erreur,
                'date_creation' => $f->date_creation,
            );
        }
        $dossier = isset($_DOSSIER_MEDIAS) ? rtrim((string) $_DOSSIER_MEDIAS, '/') : '';

        return array(
            'production' => !empty($_PROD),
            'erreurs' => self::erreurs(),
            'stripe' => $stripe,
            'emails' => array(
                'mode' => (isset($_MAIL_MODE) && $_MAIL_MODE === 'reel') ? 'reel' : 'essai',
                'en_attente' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM m_message WHERE etat = 'a_envoyer'")->n,
                'en_erreur' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM m_message WHERE etat = 'erreur'")->n,
                'envoyes_7j' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM m_message WHERE etat = 'envoye' AND date_envoi > DATE_SUB(NOW(), INTERVAL 7 DAY)")->n,
                'messages' => $messages,
            ),
            'medias' => array(
                'dossier' => $dossier !== '' && is_dir($dossier) && is_writable($dossier),
                'conversion' => isset($_FFMPEG) && $_FFMPEG !== '' && is_executable($_FFMPEG),
                'a_convertir' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM f_fichier WHERE etat = 'a_convertir'")->n,
                'en_erreur' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM f_fichier WHERE etat = 'erreur'")->n,
                'fichiers' => $fichiers,
            ),
            'planifie' => self::planifie(),
            // Appli des parents : son adresse, et les comptes ouverts dont le parent n'a pas encore de mot de passe
            'appli' => array(
                'configuree' => isset($_APP_PARENTS_URL) && $_APP_PARENTS_URL !== '',
                'adresse' => isset($_APP_PARENTS_URL) ? $_APP_PARENTS_URL : '',
                'comptes' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM a_compte WHERE etat = 'actif' AND date_fin_acces >= CURDATE()")->n,
                'sans_mot_de_passe' => (int) $Mysql->fetchOne(
                    "SELECT COUNT(*) AS n FROM a_compte a LEFT JOIN e_acces e ON e.id_compte = a.id_compte
                     WHERE a.etat = 'actif' AND a.date_fin_acces >= CURDATE()
                       AND (e.id_compte IS NULL OR (a.date_revocation IS NOT NULL AND e.date_mot_de_passe <= a.date_revocation))"
                )->n,
                'jamais_invites' => (int) $Mysql->fetchOne(
                    "SELECT COUNT(*) AS n FROM a_compte a
                     WHERE a.etat = 'actif' AND a.date_fin_acces >= CURDATE()
                       AND NOT EXISTS (SELECT 1 FROM a_jeton j WHERE j.id_compte = a.id_compte)
                       AND NOT EXISTS (SELECT 1 FROM e_acces e WHERE e.id_compte = a.id_compte)"
                )->n,
            ),
            'rdv' => self::rdv(),
        );
    }

    /**
     * Rendez-vous en ligne : la prise est-elle ouverte, qui reçoit, combien de créneaux sont proposés, où en est le
     * plafond des réservations publiques, combien de calendriers sont abonnés. Des nombres, aucune donnée de dossier.
     */
    public static function rdv()
    {
        global $Mysql, $_RDV_PRISE_OUVERTE;

        $profils = "'" . implode("', '", Suivi::profils('rendez_vous')) . "'";
        $receveurs = "u.actif = 1 AND u.profil IN ($profils) AND EXISTS (SELECT 1 FROM r_disponibilite d WHERE d.id_users = u.id_users)";
        $agenda = class_exists('Agenda', false) ? new Agenda() : null;

        return array(
            'ouverte' => !empty($_RDV_PRISE_OUVERTE),
            'plafond' => self::plafondRdv(),
            'receveurs' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM u_users u WHERE $receveurs")->n,
            'sans_visio' => (int) $Mysql->fetchOne(
                "SELECT COUNT(*) AS n FROM u_users u LEFT JOIN u_agenda a ON a.id_users = u.id_users
                 WHERE $receveurs AND (a.lien_visio IS NULL OR a.lien_visio = '')"
            )->n,
            // Créneaux proposés à cet instant : sur la page publique (découverte), dans l'espace des parents (accompagnement)
            'creneaux' => $agenda === null ? null : array(
                'decouverte' => count($agenda->libres('decouverte')),
                'suivi' => count($agenda->libres('suivi')),
            ),
            'a_venir' => (int) $Mysql->fetchOne(
                "SELECT COUNT(*) AS n FROM r_rdv r INNER JOIN r_reservation v ON v.id_rdv = r.id_rdv_origine
                 WHERE r.statut IN ('a_confirmer', 'confirme') AND r.date_debut > NOW()"
            )->n,
            'flux' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM u_agenda a INNER JOIN u_users u ON u.id_users = a.id_users WHERE a.jeton IS NOT NULL AND u.actif = 1")->n,
        );
    }

    /**
     * Passe « surveillance » : prévient l'administrateur par mail quand quelque chose est en erreur, une fois par jour
     * au plus. Le mail ne porte que des nombres. Retourne le résumé de la passe.
     */
    public static function surveiller()
    {
        global $Mysql, $_MAIL_ERREUR, $_MAIL_EXPEDITEUR, $_URL_TOUR;

        $n = self::erreurs();
        if ($n === 0) {
            return "rien en erreur";
        }
        $recent = $Mysql->fetchOne("SELECT 1 AS x FROM t_planifie WHERE passe = 'alerte' AND date_debut > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        if ($recent !== null || !isset($_MAIL_ERREUR) || $_MAIL_ERREUR === '' || !Automate::mailPossible()) {
            return ($n > 1 ? "$n éléments" : "1 élément") . " en erreur, pas de mail (déjà prévenu, pas de destinataire, ou pas de messagerie sur ce serveur)";
        }
        $de = (isset($_MAIL_EXPEDITEUR) && $_MAIL_EXPEDITEUR !== '') ? $_MAIL_EXPEDITEUR : "noreply@navup.fr";
        $entetes = 'From: "NavUp" <' . $de . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=\"UTF-8\"\r\nContent-Transfer-Encoding: 8bit\r\n";
        $corps = ($n > 1 ? "$n éléments demandent" : "1 élément demande") . " votre attention dans la Tour de contrôle NavUp (paiements Stripe, e-mails, médias, rendez-vous en ligne ou tâche planifiée).\r\n"
            . (isset($_URL_TOUR) ? "Détail : " . rtrim($_URL_TOUR, '/') . "/parametres/connexions\r\n" : '');
        if (!@mail($_MAIL_ERREUR, "NavUp : connexions en erreur", $corps, $entetes)) {
            return ($n > 1 ? "$n éléments" : "1 élément") . " en erreur, le mail d'alerte n'est pas parti";
        }
        $Mysql->execute(
            "INSERT INTO t_planifie (passe, date_debut, date_fin, code, resume) VALUES ('alerte', NOW(), NOW(), 0, NULL)
             ON DUPLICATE KEY UPDATE date_debut = NOW(), date_fin = NOW()"
        );

        return ($n > 1 ? "$n éléments" : "1 élément") . " en erreur, administrateur prévenu";
    }
}

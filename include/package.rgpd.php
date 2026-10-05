<?php

//=======================================================================
// File:        package.rgpd.php
// Description: RGPD (CDC §23 ; étape 8). Durées de conservation : l'outil propose les dossiers arrivés à échéance,
//              un administrateur confirme chaque effacement (v1/rgpd/). Deux degrés :
//                - familial : déclarations, enfants, problématiques, notes, textes des rendez-vous et des échanges,
//                  tâches, e-mails, compte de l'appli des parents et ses traces. L'identité et les pièces comptables restent.
//                - complet : de plus, l'identité (dossier, commandes, réservations) et les identifiants Stripe.
//              Les ventes, échéances et paiements restent toujours, avec leurs montants (obligation comptable) ;
//              leurs commentaires sont vidés. Le journal ne garde que le fait, sans donnée personnelle.
//              Aussi : les données remises au parent (« Télécharger mes données »), et les demandes d'effacement
//              déposées depuis l'appli des parents (e_demande).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Rgpd
{
    const NIVEAUX = array('familial', 'complet');
    const NOM_ANONYME = 'Dossier anonymisé';

    private static function reglage($nom, $defaut)
    {
        return isset($GLOBALS[$nom]) ? max(1, (int) $GLOBALS[$nom]) : $defaut;
    }

    public static function delais()
    {
        return array(
            'prospect_ans' => self::reglage('_RGPD_PROSPECT_ANS', 3),
            'client_ans' => self::reglage('_RGPD_CLIENT_ANS', 3),
            'comptable_ans' => self::reglage('_RGPD_COMPTABLE_ANS', 10),
            'journal_mois' => self::reglage('_RGPD_JOURNAL_MOIS', 12),
        );
    }

    // PROPOSITIONS ###################################################

    /**
     * Les dossiers arrivés à échéance, du plus ancien au plus récent : chacun avec le degré proposé, la date qui fonde
     * la proposition et sa raison écrite. Rien n'est effacé ici.
     */
    public function echeances()
    {
        global $Mysql;
        $d = self::delais();
        $out = array();

        // Prospects sans vente : le dernier fait du dossier (création, statut, échange, rendez-vous, fil)
        $prospects = $Mysql->fetchAll(
            "SELECT c.id_contact, c.prenom, c.nom, c.statut, c.niveau_anonymisation, x.dernier
             FROM d_contact c
             INNER JOIN (
                SELECT c2.id_contact, GREATEST(c2.date_creation, c2.date_statut, COALESCE(c2.date_derniere_interaction, c2.date_creation),
                       COALESCE((SELECT MAX(r.date_debut) FROM r_rdv r WHERE r.id_contact = c2.id_contact), c2.date_creation),
                       COALESCE((SELECT MAX(e.date_evenement) FROM d_evenement e WHERE e.id_contact = c2.id_contact), c2.date_creation)) AS dernier
                FROM d_contact c2
                WHERE NOT EXISTS (SELECT 1 FROM v_vente v WHERE v.id_contact = c2.id_contact)
                  AND (c2.niveau_anonymisation IS NULL OR c2.niveau_anonymisation = 'familial')
             ) x ON x.id_contact = c.id_contact
             WHERE x.dernier < NOW() - INTERVAL ? YEAR
             ORDER BY x.dernier",
            array($d['prospect_ans']),
            'i'
        );
        foreach ($prospects as $r) {
            $out[] = $this->proposition($r, 'complet', 'prospect', $r->dernier);
        }

        // Clients, données familiales : la fin de l'accès à l'espace, à défaut la dernière vente
        $clients = $Mysql->fetchAll(
            "SELECT c.id_contact, c.prenom, c.nom, c.statut, c.niveau_anonymisation, x.fin
             FROM d_contact c
             INNER JOIN (
                SELECT v.id_contact, GREATEST(MAX(v.date_vente), COALESCE((SELECT MAX(a.date_fin_acces) FROM a_compte a WHERE a.id_contact = v.id_contact), '1000-01-01')) AS fin
                FROM v_vente v GROUP BY v.id_contact
             ) x ON x.id_contact = c.id_contact
             WHERE c.niveau_anonymisation IS NULL AND x.fin < CURDATE() - INTERVAL ? YEAR
             ORDER BY x.fin",
            array($d['client_ans']),
            'i'
        );
        $vus = array();
        foreach ($clients as $r) {
            $vus[(int) $r->id_contact] = true;
        }

        // Clients, identité : la dernière écriture comptable (vente, paiement, annulation)
        $comptables = $Mysql->fetchAll(
            "SELECT c.id_contact, c.prenom, c.nom, c.statut, c.niveau_anonymisation, x.derniere
             FROM d_contact c
             INNER JOIN (
                SELECT v.id_contact, GREATEST(MAX(v.date_vente), COALESCE(MAX(DATE(v.date_annulation)), '1000-01-01'),
                       COALESCE(MAX(p.date_paiement), '1000-01-01'), COALESCE(MAX(DATE(p.date_annulation)), '1000-01-01')) AS derniere
                FROM v_vente v LEFT JOIN v_paiement p ON p.id_vente = v.id_vente
                GROUP BY v.id_contact
             ) x ON x.id_contact = c.id_contact
             WHERE (c.niveau_anonymisation IS NULL OR c.niveau_anonymisation = 'familial') AND x.derniere < CURDATE() - INTERVAL ? YEAR
             ORDER BY x.derniere",
            array($d['comptable_ans']),
            'i'
        );
        $complets = array();
        foreach ($comptables as $r) {
            $complets[(int) $r->id_contact] = true;
            $out[] = $this->proposition($r, 'complet', 'comptable', $r->derniere);
        }
        foreach ($clients as $r) {
            if (!isset($complets[(int) $r->id_contact])) {
                $out[] = $this->proposition($r, 'familial', 'client', $r->fin);
            }
        }

        usort($out, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $out;
    }

    private function proposition($r, $niveau, $raison, $date)
    {
        return array(
            'id_contact' => (int) $r->id_contact,
            'reference' => Contact::reference($r->id_contact),
            'prenom' => $r->prenom,
            'nom' => $r->nom,
            'statut' => $r->statut,
            'niveau_actuel' => $r->niveau_anonymisation,
            'niveau' => $niveau,
            'raison' => $raison,
            'date' => substr((string) $date, 0, 10),
        );
    }

    /**
     * Demandes d'effacement déposées depuis l'appli des parents. En attente : du plus ancien au plus récent ; traitées :
     * les vingt dernières. Le degré proposé : familial pour un client (les pièces comptables gardent son identité),
     * complet sinon.
     */
    public function demandes()
    {
        global $Mysql;
        $sql = "SELECT d.id_demande, d.type, d.date_creation, d.date_traitement, COALESCE(a.id_contact, d.id_contact) AS id_contact,
                       c.prenom, c.nom, c.statut, c.niveau_anonymisation,
                       EXISTS (SELECT 1 FROM v_vente v WHERE v.id_contact = c.id_contact) AS a_ventes,
                       u.identifiant AS traitee_par
                FROM e_demande d
                LEFT JOIN a_compte a ON a.id_compte = d.id_compte
                LEFT JOIN d_contact c ON c.id_contact = COALESCE(a.id_contact, d.id_contact)
                LEFT JOIN u_users u ON u.id_users = d.id_users_traitement";
        $sortie = function ($r) {
            return array(
                'id_demande' => (int) $r->id_demande,
                'type' => $r->type,
                'date' => $r->date_creation,
                'date_traitement' => $r->date_traitement,
                'traitee_par' => $r->traitee_par,
                'id_contact' => $r->id_contact === null ? null : (int) $r->id_contact,
                'reference' => $r->id_contact === null ? null : Contact::reference($r->id_contact),
                'prenom' => $r->prenom,
                'nom' => $r->nom,
                'statut' => $r->statut,
                'niveau_actuel' => $r->niveau_anonymisation,
                'niveau' => (int) $r->a_ventes === 1 ? 'familial' : 'complet',
            );
        };
        return array(
            'en_attente' => array_map($sortie, $Mysql->fetchAll($sql . " WHERE d.date_traitement IS NULL ORDER BY d.date_creation, d.id_demande")),
            'traitees' => array_map($sortie, $Mysql->fetchAll($sql . " WHERE d.date_traitement IS NOT NULL ORDER BY d.date_traitement DESC LIMIT 20")),
        );
    }

    // EFFACEMENT #####################################################

    /**
     * Efface un dossier au degré donné, dans une transaction. Refuse un degré déjà atteint ou dépassé.
     * Retourne le nombre de lignes touchées par table (pour le contrôle, jamais montré tel quel).
     */
    public function effacer($id_contact, $niveau, $id_users)
    {
        global $SQL, $Mysql, $U;

        $id = (int) $id_contact;
        if (!in_array($niveau, self::NIVEAUX, true)) {
            throw new ErreurMetier("Degré d'effacement inconnu.");
        }

        $SQL->begin_transaction();
        try {
            $c = $Mysql->fetchOne("SELECT id_contact, niveau_anonymisation FROM d_contact WHERE id_contact = ? FOR UPDATE", array($id), 'i');
            if ($c === null) {
                throw new ErreurMetier("Dossier introuvable.");
            }
            if ($c->niveau_anonymisation === 'complet' || ($c->niveau_anonymisation === 'familial' && $niveau === 'familial')) {
                throw new ErreurMetier("Ce dossier est déjà effacé à ce degré.");
            }

            $n = array();
            $x = function ($cle, $sql, $params = array(), $types = '') use (&$n, $Mysql) {
                $n[$cle] = ($n[$cle] ?? 0) + $Mysql->execute($sql, $params, $types);
            };

            // Demandes du parent : rattachées au dossier et closes avant que le compte ne disparaisse
            $x('e_demande', "UPDATE e_demande d LEFT JOIN a_compte a ON a.id_compte = d.id_compte
                SET d.id_contact = ?, d.date_traitement = NOW(), d.id_users_traitement = ?
                WHERE (a.id_contact = ? OR d.id_contact = ?) AND d.date_traitement IS NULL", array($id, (int) $id_users, $id, $id), 'iiii');

            // Familial
            $x('a_compte', "DELETE FROM a_compte WHERE id_contact = ?", array($id), 'i');
            $x('d_note', "DELETE FROM d_note WHERE id_contact = ?", array($id), 'i');
            $x('d_problematique', "DELETE FROM d_problematique WHERE id_contact = ?", array($id), 'i');
            $x('d_enfant', "DELETE FROM d_enfant WHERE id_contact = ?", array($id), 'i');
            $x('d_declaration', "DELETE FROM d_declaration WHERE id_contact = ?", array($id), 'i');
            $x('d_evenement', "DELETE FROM d_evenement WHERE id_contact = ? AND module = 'famille'", array($id), 'i');
            $x('r_rdv', "UPDATE r_rdv SET motif = NULL, motif_cloture = NULL, compte_rendu = NULL
                WHERE id_contact = ? AND (motif IS NOT NULL OR motif_cloture IS NOT NULL OR compte_rendu IS NOT NULL)", array($id), 'i');
            $x('r_reservation', "UPDATE r_reservation rr INNER JOIN r_rdv r ON r.id_rdv = rr.id_rdv SET rr.note = NULL
                WHERE r.id_contact = ? AND rr.note IS NOT NULL", array($id), 'i');
            $x('r_lien', "DELETE l FROM r_lien l INNER JOIN r_rdv r ON r.id_rdv = l.id_rdv WHERE r.id_contact = ?", array($id), 'i');
            $x('i_interaction', "UPDATE i_interaction SET motif = NULL, compte_rendu = NULL
                WHERE id_contact = ? AND (motif IS NOT NULL OR compte_rendu IS NOT NULL)", array($id), 'i');
            $x('t_tache', "DELETE FROM t_tache WHERE id_contact = ?", array($id), 'i');
            $x('m_message', "DELETE FROM m_message WHERE id_contact = ?", array($id), 'i');
            $x('v_vente', "UPDATE v_vente SET commentaire = NULL WHERE id_contact = ? AND commentaire IS NOT NULL", array($id), 'i');
            $x('v_paiement', "UPDATE v_paiement p INNER JOIN v_vente v ON v.id_vente = p.id_vente SET p.commentaire = NULL
                WHERE v.id_contact = ? AND p.commentaire IS NOT NULL", array($id), 'i');

            if ($niveau === 'complet') {
                $x('d_contact', "UPDATE d_contact SET prenom = NULL, nom = ?, email = NULL, telephone = NULL,
                    date_archivage = COALESCE(date_archivage, NOW()) WHERE id_contact = ?", array(self::NOM_ANONYME, $id), 'si');
                $x('s_commande', "UPDATE s_commande SET prenom = NULL, nom = ?, email = '', telephone = NULL, stripe_customer_id = NULL
                    WHERE id_contact = ?", array(self::NOM_ANONYME, $id), 'si');
                $x('r_reservation', "UPDATE r_reservation rr INNER JOIN r_rdv r ON r.id_rdv = rr.id_rdv
                    SET rr.prenom = NULL, rr.nom = NULL, rr.email = NULL, rr.telephone = NULL WHERE r.id_contact = ?", array($id), 'i');
                $x('v_vente', "UPDATE v_vente SET stripe_customer_id = NULL, stripe_id = NULL, stripe_payment_method_id = NULL
                    WHERE id_contact = ?", array($id), 'i');
                $x('v_echeance', "UPDATE v_echeance e INNER JOIN v_vente v ON v.id_vente = e.id_vente SET e.stripe_id = NULL
                    WHERE v.id_contact = ? AND e.stripe_id IS NOT NULL", array($id), 'i');
                $x('v_paiement', "UPDATE v_paiement p INNER JOIN v_vente v ON v.id_vente = p.id_vente
                    SET p.stripe_id = NULL, p.stripe_payment_intent_id = NULL WHERE v.id_contact = ?", array($id), 'i');
                $x('s_session', "UPDATE s_session s INNER JOIN v_vente v ON v.id_vente = s.id_vente
                    SET s.stripe_session_id = NULL, s.url = NULL WHERE v.id_contact = ?", array($id), 'i');
                $x('s_prelevement', "UPDATE s_prelevement s INNER JOIN v_vente v ON v.id_vente = s.id_vente
                    SET s.stripe_payment_intent_id = NULL WHERE v.id_contact = ?", array($id), 'i');
                $x('s_lien', "DELETE l FROM s_lien l INNER JOIN v_vente v ON v.id_vente = l.id_vente WHERE v.id_contact = ?", array($id), 'i');
            } else {
                $x('d_contact', "UPDATE d_contact SET origine_precision = NULL WHERE id_contact = ?", array($id), 'i');
            }

            $Mysql->execute(
                "UPDATE d_contact SET niveau_anonymisation = ?, date_anonymisation = NOW(), date_modif = NOW() WHERE id_contact = ?",
                array($niveau, $id),
                'si'
            );

            // Le fait seul, sans donnée personnelle : au journal et dans le fil du dossier
            $U->audit((int) $id_users, 'rgpd_effacement', array('niveau' => $niveau), 'contact', $id);
            $Mysql->execute(
                "INSERT INTO d_evenement (id_contact, type, module, details, id_users) VALUES (?, 'rgpd', 'dossier', ?, ?)",
                array($id, json_encode(array('niveau' => $niveau)), (int) $id_users),
                'isi'
            );

            $SQL->commit();
        } catch (Throwable $e) {
            $SQL->rollback();
            throw $e;
        }

        return $n;
    }

    // PURGE AUTOMATIQUE ##############################################

    /**
     * Passe rgpd de planifie.php : le journal d'audit et les e-mails envoyés (ou abandonnés) au-delà de la durée réglée.
     * Les messages encore à envoyer restent. Rend le nombre de lignes effacées par table.
     */
    public function purger()
    {
        global $Mysql;
        $mois = self::delais()['journal_mois'];

        return array(
            'u_audit' => $Mysql->execute("DELETE FROM u_audit WHERE `date` < NOW() - INTERVAL ? MONTH", array($mois), 'i'),
            'm_message' => $Mysql->execute(
                "DELETE FROM m_message WHERE etat <> 'a_envoyer' AND COALESCE(date_envoi, date_creation) < NOW() - INTERVAL ? MONTH",
                array($mois),
                'i'
            ),
        );
    }

    // DONNÉES REMISES AU PARENT ######################################

    /**
     * Ce que NavUp garde sur un parent, tel qu'il le reçoit (« Télécharger mes données ») : identité, déclarations,
     * enfants, problématiques déclarées, achats et paiements, rendez-vous (dates et façon d'échanger, avec le message
     * qu'il a laissé), e-mails reçus (objet et date), consentements, programme et progression.
     * Jamais une note interne, un motif ou un compte rendu de NavUp, ni un identifiant technique.
     */
    public function exporter($id_contact)
    {
        global $Mysql;
        $id = (int) $id_contact;

        $c = $Mysql->fetchOne(
            "SELECT c.*, o.libelle AS origine FROM d_contact c LEFT JOIN p_origine o ON o.code = c.code_origine WHERE c.id_contact = ?",
            array($id),
            'i'
        );
        if ($c === null) {
            return null;
        }

        $euros = function ($centimes) {
            return $centimes === null ? null : number_format(((int) $centimes) / 100, 2, ',', ' ') . ' €';
        };
        $provenance = function ($source) {
            return $source === 'formulaire' ? 'écrit par vous' : 'rapporté par NavUp de vos échanges';
        };

        $declarations = array_map(function ($d) use ($provenance) {
            return array(
                'date' => $d->date_declaration,
                'provenance' => $provenance($d->source),
                'situation_familiale' => $d->situation_familiale,
                'motif' => $d->motif,
                'objectifs' => $d->objectifs,
                'disponibilites' => $d->disponibilites,
            );
        }, $Mysql->fetchAll("SELECT * FROM d_declaration WHERE id_contact = ? ORDER BY date_declaration, id_declaration", array($id), 'i'));

        $enfants = array_map(function ($e) use ($provenance) {
            return array(
                'prenom' => $e->prenom,
                'age' => $e->age === null ? null : (int) $e->age,
                'age_declare_le' => $e->date_age,
                'niveau_scolaire' => $e->niveau_scolaire,
                'provenance' => $provenance($e->source),
            );
        }, $Mysql->fetchAll("SELECT * FROM d_enfant WHERE id_contact = ? ORDER BY id_enfant", array($id), 'i'));

        $problematiques = array_map(function ($p) use ($provenance) {
            return array(
                'date' => $p->date_declaration,
                'categorie' => $p->categorie,
                'intitule' => $p->intitule,
                'description' => $p->description,
                'objectif' => $p->objectif_parent,
                'enfant' => $p->enfant,
                'provenance' => $provenance($p->source),
            );
        }, $Mysql->fetchAll(
            "SELECT p.*, k.libelle AS categorie, e.prenom AS enfant FROM d_problematique p
             LEFT JOIN p_categorie_problematique k ON k.code = p.code_categorie LEFT JOIN d_enfant e ON e.id_enfant = p.id_enfant
             WHERE p.id_contact = ? ORDER BY p.date_declaration, p.id_problematique",
            array($id),
            'i'
        ));

        $achats = array();
        foreach ($Mysql->fetchAll(
            "SELECT v.*, o.libelle AS offre FROM v_vente v LEFT JOIN p_offre o ON o.code = v.code_offre WHERE v.id_contact = ? ORDER BY v.date_vente, v.id_vente",
            array($id),
            'i'
        ) as $v) {
            $paiements = array_map(function ($p) use ($euros) {
                return array(
                    'date' => $p->date_paiement,
                    'nature' => $p->type,
                    'montant' => $euros($p->montant),
                    'moyen' => $p->moyen,
                    'annule' => $p->date_annulation !== null,
                );
            }, $Mysql->fetchAll(
                "SELECT p.*, m.libelle AS moyen FROM v_paiement p LEFT JOIN p_moyen_paiement m ON m.code = p.code_moyen
                 WHERE p.id_vente = ? ORDER BY p.date_paiement, p.id_paiement",
                array((int) $v->id_vente),
                'i'
            ));
            $echeances = array_map(function ($e) use ($euros) {
                return array('date_prevue' => $e->date_prevue, 'montant' => $euros($e->montant), 'paye' => $euros($e->montant_paye));
            }, $Mysql->fetchAll(
                "SELECT * FROM v_echeance WHERE id_vente = ? AND date_annulation IS NULL ORDER BY rang",
                array((int) $v->id_vente),
                'i'
            ));
            $achats[] = array(
                'date' => $v->date_vente,
                'offre' => $v->offre,
                'montant' => $euros($v->montant),
                'modalite' => $v->modalite,
                'annule_le' => $v->date_annulation,
                'echeances' => $echeances,
                'paiements' => $paiements,
            );
        }

        $rdv = array_map(function ($r) {
            return array(
                'type' => $r->type,
                'debut' => $r->date_debut,
                'duree_minutes' => (int) $r->duree,
                'facon_d_echanger' => $r->canal,
                'statut' => $r->statut,
                'votre_message' => $r->note,
            );
        }, $Mysql->fetchAll(
            "SELECT r.type, r.date_debut, r.duree, r.canal, r.statut, rr.note FROM r_rdv r LEFT JOIN r_reservation rr ON rr.id_rdv = r.id_rdv
             WHERE r.id_contact = ? AND r.date_debut IS NOT NULL ORDER BY r.date_debut",
            array($id),
            'i'
        ));

        // E-mails reçus : objet et date ; jamais les avis internes adressés à l'équipe
        $internes = array();
        foreach (Message::MODELES as $code => $m) {
            if (!empty($m['interne'])) {
                $internes[] = $code;
            }
        }
        $emails = array_map(function ($m) {
            return array('objet' => $m->sujet, 'envoye_le' => $m->date_envoi);
        }, $Mysql->fetchAll(
            "SELECT sujet, date_envoi FROM m_message WHERE id_contact = ? AND etat = 'envoye'"
                . (count($internes) ? " AND modele NOT IN ('" . implode("','", $internes) . "')" : '') . " ORDER BY date_envoi",
            array($id),
            'i'
        ));

        $consentements = array_map(function ($k) {
            return array('objet' => $k->type, 'version' => $k->version, 'accorde' => (int) $k->accorde === 1, 'date' => $k->date_consentement);
        }, $Mysql->fetchAll("SELECT * FROM d_consentement WHERE id_contact = ? ORDER BY date_consentement", array($id), 'i'));

        $programmes = array_map(function ($a) use ($Mysql) {
            $termines = $Mysql->fetchAll(
                "SELECT s.titre, p.date_termine FROM e_progression p INNER JOIN f_sujet s ON s.id_sujet = p.id_sujet
                 WHERE p.id_compte = ? AND p.date_termine IS NOT NULL ORDER BY p.date_termine",
                array((int) $a->id_compte),
                'i'
            );
            return array(
                'programme' => $a->formation,
                'debut' => $a->date_debut,
                'fin' => $a->date_fin,
                'acces_jusqu_au' => $a->date_fin_acces,
                'derniere_connexion' => $a->date_derniere_connexion,
                'sujets_termines' => array_map(function ($t) {
                    return array('sujet' => $t->titre, 'le' => $t->date_termine);
                }, $termines),
            );
        }, $Mysql->fetchAll(
            "SELECT a.*, f.nom AS formation, e.date_derniere_connexion FROM a_compte a
             INNER JOIN f_formation f ON f.id_formation = a.id_formation LEFT JOIN e_acces e ON e.id_compte = a.id_compte
             WHERE a.id_contact = ? ORDER BY a.date_debut",
            array($id),
            'i'
        ));

        return array(
            'document' => 'Vos données chez NavUp Academy',
            'cree_le' => date('Y-m-d H:i:s'),
            'note' => "Ce fichier contient ce que NavUp Academy garde à votre sujet. Les montants sont TTC. Pour toute question, ou pour faire corriger une information, écrivez-nous.",
            'reference' => Contact::reference($id),
            'identite' => array(
                'prenom' => $c->prenom,
                'nom' => $c->nom,
                'email' => $c->email,
                'telephone' => $c->telephone,
                'premier_contact' => $c->date_premier_contact,
                'nous_avez_connus_par' => $c->origine,
            ),
            'declarations' => $declarations,
            'enfants' => $enfants,
            'problematiques' => $problematiques,
            'achats' => $achats,
            'rendez_vous' => $rdv,
            'emails_recus' => $emails,
            'consentements' => $consentements,
            'programmes' => $programmes,
        );
    }

    /**
     * Le compte que désigne un billet « donnees » de l'appli des parents, ou null. Comme Agenda::billet, mais un accès
     * fermé n'empêche pas de télécharger ses données : seuls comptent la durée du billet, la session encore ouverte et
     * l'absence de révocation.
     */
    public function billet($brut)
    {
        global $Mysql, $_DONNEES_BILLET_MINUTES;

        if (!is_string($brut) || preg_match('/^[0-9a-f]{48}$/', $brut) !== 1) {
            return null;
        }
        $minutes = isset($_DONNEES_BILLET_MINUTES) ? (int) $_DONNEES_BILLET_MINUTES : 5;
        $r = $Mysql->fetchOne(
            "SELECT b.id_billet, c.id_compte, c.id_contact
             FROM e_billet b
             INNER JOIN e_session s ON s.id_session = b.id_session AND s.id_compte = b.id_compte
             INNER JOIN a_compte c ON c.id_compte = b.id_compte
             WHERE b.jeton = ? AND b.objet = 'donnees' AND b.date_creation > NOW() - INTERVAL ? MINUTE
               AND s.date_expiration > NOW() AND (c.date_revocation IS NULL OR s.date_creation > c.date_revocation)",
            array(hash('sha256', $brut), $minutes),
            'si'
        );
        if ($r !== null) {
            // Un billet de données ne sert qu'une fois
            $Mysql->execute("DELETE FROM e_billet WHERE id_billet = ?", array((int) $r->id_billet), 'i');
        }
        return $r;
    }
}

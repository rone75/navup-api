<?php

//=======================================================================
// File:        package.contact.php
// Description: dossiers (prospects et clients) : cycle de vie, droits par dossier, recherche,
//              données familiales (déclaration, enfants, problématiques, notes), chronologie.
//              Requiert package.saisie.php ($S), package.user.php ($U), package.mysql.php ($Mysql).
// Created:     2026-10-03
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Contact
{
    // Cycle de vie d'un contact (CDC §4)
    const STATUTS = array('prospect', 'rdv_demande', 'rdv_planifie', 'a_relancer', 'client', 'client_actif', 'programme_termine', 'annule_rembourse');

    // Le groupe d'un dossier décide du module de droits qui s'applique (prospects ou clients)
    const GROUPES = array(
        'prospects' => array('prospect', 'rdv_demande', 'rdv_planifie', 'a_relancer'),
        'clients' => array('client', 'client_actif', 'programme_termine', 'annule_rembourse'),
    );

    const PRIORITES = array('basse', 'moyenne', 'haute');
    const STATUTS_PROBLEMATIQUE = array('ouverte', 'en_cours', 'close');
    const NIVEAUX_SCOLAIRES = array('maternelle', 'primaire', 'college', 'lycee', 'superieur', 'autre');

    // Colonnes servies par la liste, la recherche et la fiche : identité et suivi uniquement.
    // Jamais de c.* : une colonne ajoutée à d_contact ne doit pas sortir par accident.
    const COLONNES = "c.id_contact, c.prenom, c.nom, c.email, c.telephone, c.statut, c.date_statut, c.code_origine,
            o.libelle AS origine, c.origine_precision, c.date_premier_contact, c.date_inscription, c.date_prochaine_action,
            c.date_derniere_interaction, c.date_archivage, c.date_creation, c.date_modif";

    const SQL_FROM = " FROM d_contact c LEFT JOIN p_origine o ON o.code = c.code_origine";

    // IDENTITÉ ET DROITS #############################################

    public static function groupeDe($statut)
    {
        return in_array($statut, self::GROUPES['clients'], true) ? 'clients' : 'prospects';
    }

    /** Identifiant client affiché (CDC §5) : déduit du numéro de dossier, jamais stocké. */
    public static function reference($id_contact)
    {
        return 'NU-' . str_pad((string) (int) $id_contact, 5, '0', STR_PAD_LEFT);
    }

    public function charger($id_contact)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT " . self::COLONNES . ", c.prochaine_action" . self::SQL_FROM . " WHERE c.id_contact = ?",
            array((int) $id_contact),
            'i'
        );
    }

    /**
     * Exige un utilisateur ayant le niveau demandé sur le groupe du dossier (prospects ou clients, d'après son statut).
     * Le droit dépend de la ligne : il ne peut pas se vérifier avant de l'avoir lue.
     * Retourne array($user, $contact) ; sinon 401 / 403 / 404 et exit.
     */
    public function exigerDossier($id_contact, $niveau = 'L')
    {
        global $U, $Response;

        $user = $U->requireUser();

        // Sans aucun droit sur les dossiers : même réponse que le dossier existe ou non
        if (!$U->can($user, 'prospects', 'L') && !$U->can($user, 'clients', 'L')) {
            $Response->forbidden("Vous n'avez pas accès à ce module.");
        }

        $contact = (int) $id_contact > 0 ? $this->charger($id_contact) : null;
        if ($contact === null) {
            $Response->notFound("Dossier introuvable.");
        }
        if (!$U->can($user, self::groupeDe($contact->statut), $niveau)) {
            $Response->forbidden("Vous n'avez pas les droits nécessaires sur ce dossier.");
        }

        return array($user, $contact);
    }

    /** Comme exigerDossier, plus le droit sur les données familiales (module famille). */
    public function exigerFamille($id_contact, $niveau = 'L')
    {
        global $U, $Response;

        list($user, $contact) = $this->exigerDossier($id_contact, 'L');
        if (!$U->can($user, 'famille', $niveau)) {
            $Response->forbidden("Vous n'avez pas accès aux données familiales.");
        }

        return array($user, $contact);
    }

    /**
     * Dossier tel que servi au front. Le texte de la prochaine action est une note interne :
     * il n'est joint qu'avec le droit famille ($avecAction) ; sa date reste visible de tous.
     */
    public function sortie($row, $avecAction = false)
    {
        $out = array(
            'id_contact' => (int) $row->id_contact,
            'reference' => self::reference($row->id_contact),
            'prenom' => $row->prenom,
            'nom' => $row->nom,
            'email' => $row->email,
            'telephone' => $row->telephone,
            'statut' => $row->statut,
            'groupe' => self::groupeDe($row->statut),
            'date_statut' => $row->date_statut,
            'code_origine' => $row->code_origine,
            'origine' => $row->origine,
            'origine_precision' => $row->origine_precision,
            'date_premier_contact' => $row->date_premier_contact,
            'date_inscription' => $row->date_inscription,
            'date_prochaine_action' => $row->date_prochaine_action,
            'date_derniere_interaction' => $row->date_derniere_interaction,
            'archive' => $row->date_archivage !== null,
            'date_archivage' => $row->date_archivage,
            'date_creation' => $row->date_creation,
            'date_modif' => $row->date_modif,
        );
        if ($avecAction) {
            $out['prochaine_action'] = property_exists($row, 'prochaine_action') ? $row->prochaine_action : null;
        }

        return $out;
    }

    // SPÉCIFICATIONS DE SAISIE #######################################

    public function specContact()
    {
        return array(
            'prenom' => array('type' => 'str', 'max' => 100, 'libelle' => 'prénom'),
            'nom' => array('type' => 'str', 'max' => 100, 'requis' => true),
            'email' => array('type' => 'email', 'max' => 255, 'libelle' => 'e-mail'),
            'telephone' => array('type' => 'tel', 'libelle' => 'téléphone'),
            'code_origine' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_origine', 'col' => 'code', 'where' => 'actif = 1', 'libelle' => 'origine'),
            'origine_precision' => array('type' => 'str', 'max' => 150, 'libelle' => "précision sur l'origine"),
            'date_premier_contact' => array('type' => 'date', 'libelle' => 'date du premier contact'),
            'date_inscription' => array('type' => 'date', 'libelle' => "date d'inscription"),
            'date_prochaine_action' => array('type' => 'date', 'libelle' => 'échéance de la prochaine action'),
            'prochaine_action' => array('type' => 'str', 'max' => 255, 'libelle' => 'prochaine action'),
        );
    }

    public function specDeclaration()
    {
        return array(
            'situation_familiale' => array('type' => 'text', 'max' => 4000, 'libelle' => 'situation familiale'),
            'motif' => array('type' => 'text', 'max' => 4000, 'libelle' => "motif de l'inscription"),
            'objectifs' => array('type' => 'text', 'max' => 4000),
            'disponibilites' => array('type' => 'str', 'max' => 500, 'libelle' => 'disponibilités'),
        );
    }

    public function specEnfant()
    {
        return array(
            'prenom' => array('type' => 'str', 'max' => 60, 'libelle' => 'prénom ou surnom'),
            'age' => array('type' => 'int', 'min' => 0, 'max' => 30, 'libelle' => 'âge'),
            'niveau_scolaire' => array('type' => 'enum', 'valeurs' => self::NIVEAUX_SCOLAIRES, 'libelle' => 'niveau scolaire'),
        );
    }

    /** L'enfant rattaché à une problématique doit appartenir au même dossier. */
    public function specProblematique($id_contact)
    {
        return array(
            'code_categorie' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_categorie_problematique', 'col' => 'code', 'where' => 'actif = 1', 'requis' => true, 'libelle' => 'catégorie'),
            'id_enfant' => array('type' => 'fk', 'table' => 'd_enfant', 'col' => 'id_enfant', 'where' => 'id_contact = ?', 'where_params' => array((int) $id_contact), 'libelle' => 'enfant concerné'),
            'intitule' => array('type' => 'str', 'max' => 80, 'libelle' => 'intitulé'),
            'description' => array('type' => 'text', 'max' => 4000),
            'objectif_parent' => array('type' => 'text', 'max' => 4000, 'libelle' => 'objectif du parent'),
            'priorite' => array('type' => 'enum', 'valeurs' => self::PRIORITES, 'defaut' => 'moyenne', 'libelle' => 'priorité'),
            'statut' => array('type' => 'enum', 'valeurs' => self::STATUTS_PROBLEMATIQUE, 'defaut' => 'ouverte'),
            'date_declaration' => array('type' => 'date', 'libelle' => 'date de déclaration'),
        );
    }

    // RECHERCHE ######################################################

    /**
     * Condition SQL de recherche sur d_contact (identifiant client, téléphone, e-mail, nom, prénom),
     * partagée par la recherche globale et le filtre « q » des listes. Ne lit jamais une table familiale.
     * Retourne array(sql, params) ou null si le terme est inexploitable (moins de 2 caractères, plus de 80).
     */
    public function conditionRecherche($q)
    {
        if (!is_string($q) || !mb_check_encoding($q, 'UTF-8')) {
            return null;
        }
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 80) {
            return null;
        }

        // Les caractères joker de LIKE saisis par l'utilisateur sont pris au pied de la lettre
        $litteral = function ($t) {
            return addcslashes($t, '\\%_');
        };

        $ou = array();
        $params = array();
        $numerique = (bool) preg_match('/^[\d\s.()+-]+$/', $q);

        // Identifiant client : NU-00012, nu12, 12
        if (preg_match('/^(?:nu-?)?0*(\d{1,9})$/i', $q, $m)) {
            $ou[] = "c.id_contact = ?";
            $params[] = (int) $m[1];
        }

        // Téléphone : au moins quatre chiffres ; l'indicatif français et le zéro national ne comptent pas
        $chiffres = preg_replace('/\D/', '', $q);
        if ($numerique && strlen($chiffres) >= 4) {
            $ou[] = "c.telephone LIKE ?";
            $params[] = '%' . preg_replace('/^(0033|33|0)/', '', $chiffres) . '%';
        }

        if (strpos($q, '@') !== false) {
            $ou[] = "c.email LIKE ?";
            $params[] = $litteral(strtolower($q)) . '%';
        } elseif (!$numerique) {
            // Chaque mot doit se retrouver dans le nom, le prénom ou l'e-mail (quatre mots au plus)
            $et = array();
            foreach (array_slice(explode(' ', $q), 0, 4) as $mot) {
                $et[] = "(c.nom LIKE ? OR c.prenom LIKE ? OR c.email LIKE ?)";
                $p = '%' . $litteral($mot) . '%';
                array_push($params, $p, $p, $p);
            }
            $ou[] = '(' . implode(' AND ', $et) . ')';
        }

        if (count($ou) === 0) {
            return null;
        }

        return array('(' . implode(' OR ', $ou) . ')', $params);
    }

    // ÉCRITURES TRACÉES ##############################################

    /**
     * Trace une écriture sur un dossier : journal d'audit (sécurité, hors dossier) et, si $evenement est fourni,
     * chronologie du dossier (faits importants, lus par les utilisateurs). À appeler dans la même transaction que la donnée.
     * Ni l'un ni l'autre ne reçoit de valeur de champ : noms de champs, identifiants et codes seulement.
     * $evenement : array('type', 'module' => 'dossier'|'famille', 'objet_type', 'objet_id', 'details', 'origine').
     */
    public function tracer($id_contact, $id_users, $action, $details = null, $evenement = null)
    {
        global $Mysql, $U;

        $U->audit($id_users, $action, $details, 'contact', (int) $id_contact);

        if ($evenement === null) {
            return;
        }
        $d = isset($evenement['details']) ? json_encode($evenement['details'], JSON_UNESCAPED_UNICODE) : null;
        $Mysql->execute(
            "INSERT INTO d_evenement (id_contact, type, module, objet_type, objet_id, details, origine, id_users) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            array(
                (int) $id_contact,
                $evenement['type'],
                $evenement['module'] ?? 'dossier',
                $evenement['objet_type'] ?? null,
                isset($evenement['objet_id']) ? (int) $evenement['objet_id'] : null,
                $d,
                $evenement['origine'] ?? 'utilisateur',
                $id_users === null ? null : (int) $id_users,
            ),
            'isssissi'
        );
    }

    /**
     * Seul point d'écriture de d_contact.statut. Aux étapes suivantes, les ventes et le programme
     * l'appelleront avec $origine = 'automatique'. Retourne false si le statut ne change pas.
     */
    public function changerStatut($contact, $nouveau, $id_users, $origine = 'utilisateur')
    {
        global $Mysql;

        $ancien = $contact->statut;
        if ($ancien === $nouveau) {
            return false;
        }
        $id = (int) $contact->id_contact;

        $Mysql->execute(
            "UPDATE d_contact SET statut = ?, date_statut = NOW(), date_modif = NOW() WHERE id_contact = ?",
            array($nouveau, $id),
            'si'
        );
        $codes = array('avant' => $ancien, 'apres' => $nouveau);
        $this->tracer($id, $id_users, 'contact_statut', $codes, array('type' => 'statut', 'details' => $codes, 'origine' => $origine));

        return true;
    }

    // DONNÉES FAMILIALES (module famille) ############################

    private function auteur($row)
    {
        if (!isset($row->auteur_identifiant) || $row->auteur_identifiant === null) {
            return null;
        }
        $nom = trim((string) $row->auteur_prenom . ' ' . (string) $row->auteur_nom);

        return $nom !== '' ? $nom : $row->auteur_identifiant;
    }

    public function noteSortie($n)
    {
        return array(
            'id_note' => (int) $n->id_note,
            'id_problematique' => $n->id_problematique === null ? null : (int) $n->id_problematique,
            'texte' => $n->texte,
            'id_users' => $n->id_users === null ? null : (int) $n->id_users,
            'auteur' => $this->auteur($n),
            'date_creation' => $n->date_creation,
        );
    }

    /**
     * Bloc familial d'un dossier : déclarations du parent, enfants, problématiques (chacune avec ses notes d'évolution)
     * et notes internes générales. Réservé aux endpoints protégés par exigerFamille().
     */
    public function famille($id_contact)
    {
        global $Mysql;

        $id = (int) $id_contact;
        $auteur = "u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom";

        $declarations = array();
        foreach ($Mysql->fetchAll(
            "SELECT d.*, $auteur FROM d_declaration d LEFT JOIN u_users u ON u.id_users = d.id_users
             WHERE d.id_contact = ? ORDER BY d.source, d.id_declaration",
            array($id),
            'i'
        ) as $d) {
            $declarations[] = array(
                'id_declaration' => (int) $d->id_declaration,
                'source' => $d->source,
                'date_declaration' => $d->date_declaration,
                'situation_familiale' => $d->situation_familiale,
                'motif' => $d->motif,
                'objectifs' => $d->objectifs,
                'disponibilites' => $d->disponibilites,
                'auteur' => $this->auteur($d),
                'date_modif' => $d->date_modif,
            );
        }

        $enfants = array();
        foreach ($Mysql->fetchAll("SELECT * FROM d_enfant WHERE id_contact = ? ORDER BY id_enfant", array($id), 'i') as $e) {
            $enfants[] = array(
                'id_enfant' => (int) $e->id_enfant,
                'prenom' => $e->prenom,
                'age' => $e->age === null ? null : (int) $e->age,
                'date_age' => $e->date_age,
                'niveau_scolaire' => $e->niveau_scolaire,
                'source' => $e->source,
            );
        }

        $notes = array();
        $evolution = array();
        foreach ($Mysql->fetchAll(
            "SELECT n.*, $auteur FROM d_note n LEFT JOIN u_users u ON u.id_users = n.id_users
             WHERE n.id_contact = ? ORDER BY n.date_creation DESC, n.id_note DESC",
            array($id),
            'i'
        ) as $n) {
            if ($n->id_problematique === null) {
                $notes[] = $this->noteSortie($n);
            } else {
                $evolution[(int) $n->id_problematique][] = $this->noteSortie($n);
            }
        }

        $problematiques = array();
        foreach ($Mysql->fetchAll(
            "SELECT p.*, c.libelle AS categorie, $auteur
             FROM d_problematique p
             INNER JOIN p_categorie_problematique c ON c.code = p.code_categorie
             LEFT JOIN u_users u ON u.id_users = p.id_users
             WHERE p.id_contact = ?
             ORDER BY (p.statut = 'close'), FIELD(p.priorite, 'haute', 'moyenne', 'basse'), p.id_problematique",
            array($id),
            'i'
        ) as $p) {
            $idp = (int) $p->id_problematique;
            $problematiques[] = array(
                'id_problematique' => $idp,
                'id_enfant' => $p->id_enfant === null ? null : (int) $p->id_enfant,
                'code_categorie' => $p->code_categorie,
                'categorie' => $p->categorie,
                'intitule' => $p->intitule,
                'description' => $p->description,
                'objectif_parent' => $p->objectif_parent,
                'priorite' => $p->priorite,
                'statut' => $p->statut,
                'source' => $p->source,
                'date_declaration' => $p->date_declaration,
                'auteur' => $this->auteur($p),
                'date_modif' => $p->date_modif,
                'notes' => $evolution[$idp] ?? array(),
            );
        }

        return array('declarations' => $declarations, 'enfants' => $enfants, 'problematiques' => $problematiques, 'notes' => $notes);
    }

    // CHRONOLOGIE ####################################################

    /** Modules d'événements que l'utilisateur peut lire dans la chronologie d'un dossier. */
    public function modulesLisibles($user)
    {
        global $U;

        $modules = array('dossier');
        foreach (array('famille', 'ventes', 'paiements') as $module) {
            if ($U->can($user, $module, 'L')) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * Événements d'un dossier, du plus récent au plus ancien, limités aux modules lisibles.
     * Le texte d'une note et le libellé d'une problématique sont joints à la lecture : ils ne sont pas recopiés dans l'événement.
     * De même, le montant d'une vente ou d'un paiement est lu dans son écriture (clé `objet`), jamais dans l'événement.
     * Retourne array(total, événements).
     */
    public function chronologie($id_contact, $modules, $limit, $offset)
    {
        global $Mysql;

        $id = (int) $id_contact;
        $in = implode(', ', array_fill(0, count($modules), '?'));
        $params = array_merge(array($id), $modules);

        $total = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM d_evenement e WHERE e.id_contact = ? AND e.module IN ($in)", $params)->nb;

        $rows = $Mysql->fetchAll(
            "SELECT e.id_evenement, e.type, e.module, e.objet_type, e.objet_id, e.details, e.origine, e.date_evenement,
                    u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom,
                    n.texte AS note_texte,
                    COALESCE(p.intitule, cat.libelle) AS problematique_libelle,
                    en.prenom AS enfant_prenom,
                    v.id_vente AS vente_id, v.montant AS vente_montant, vo.libelle AS vente_offre,
                    pa.id_vente AS paiement_id_vente, pa.type AS paiement_type, pa.montant AS paiement_montant, pa.date_paiement AS paiement_date,
                    pm.libelle AS paiement_moyen, pa.date_annulation AS paiement_date_annulation
             FROM d_evenement e
             LEFT JOIN u_users u ON u.id_users = e.id_users
             LEFT JOIN d_note n ON e.objet_type = 'note' AND n.id_note = e.objet_id
             LEFT JOIN d_problematique p ON e.objet_type = 'problematique' AND p.id_problematique = e.objet_id
             LEFT JOIN p_categorie_problematique cat ON cat.code = p.code_categorie
             LEFT JOIN d_enfant en ON e.objet_type = 'enfant' AND en.id_enfant = e.objet_id
             LEFT JOIN v_vente v ON e.objet_type = 'vente' AND v.id_vente = e.objet_id
             LEFT JOIN p_offre vo ON vo.code = v.code_offre
             LEFT JOIN v_paiement pa ON e.objet_type = 'paiement' AND pa.id_paiement = e.objet_id
             LEFT JOIN p_moyen_paiement pm ON pm.code = pa.code_moyen
             WHERE e.id_contact = ? AND e.module IN ($in)
             ORDER BY e.date_evenement DESC, e.id_evenement DESC
             LIMIT ? OFFSET ?",
            array_merge($params, array((int) $limit, (int) $offset))
        );

        $evenements = array();
        foreach ($rows as $e) {
            // Libellé de l'objet concerné, lu dans sa table (null s'il a été supprimé depuis)
            $libelle = null;
            if ($e->objet_type === 'note') {
                $libelle = $e->note_texte;
            } elseif ($e->objet_type === 'problematique') {
                $libelle = $e->problematique_libelle;
            } elseif ($e->objet_type === 'enfant') {
                $libelle = $e->enfant_prenom;
            }
            // Vente ou écriture concernée : montants lus dans leur table, au moment de l'affichage
            $objet = null;
            if ($e->objet_type === 'vente' && $e->vente_id !== null) {
                $objet = array(
                    'id_vente' => (int) $e->vente_id,
                    'reference' => 'VE-' . str_pad((string) (int) $e->vente_id, 5, '0', STR_PAD_LEFT),
                    'offre' => $e->vente_offre,
                    'montant' => (int) $e->vente_montant,
                );
            } elseif ($e->objet_type === 'paiement' && $e->paiement_type !== null) {
                $objet = array(
                    'id_vente' => (int) $e->paiement_id_vente,
                    'type' => $e->paiement_type,
                    'montant' => (int) $e->paiement_montant,
                    'date_paiement' => $e->paiement_date,
                    'moyen' => $e->paiement_moyen,
                    'annulee' => $e->paiement_date_annulation !== null,
                );
            }
            $evenements[] = array(
                'id_evenement' => (int) $e->id_evenement,
                'type' => $e->type,
                'module' => $e->module,
                'objet_type' => $e->objet_type,
                'objet_id' => $e->objet_id === null ? null : (int) $e->objet_id,
                'objet_libelle' => $libelle,
                'objet' => $objet,
                'details' => $e->details === null ? null : json_decode($e->details, true),
                'origine' => $e->origine,
                'auteur' => $this->auteur($e),
                'date_evenement' => $e->date_evenement,
            );
        }

        return array($total, $evenements);
    }
}

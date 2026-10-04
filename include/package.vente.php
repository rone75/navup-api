<?php

//=======================================================================
// File:        package.vente.php
// Description: ventes, échéanciers et paiements (CDC §9, §10) : lecture, écritures verrouillées,
//              répartition des encaissements sur les échéances, statut calculé, automatismes du dossier.
//              Requiert package.saisie.php ($S), package.contact.php ($Contact), package.user.php ($U),
//              package.mysql.php ($Mysql, $SQL).
//              Montants en centimes entiers. v_paiement est la seule source des sommes ; les caches
//              (statut d'une vente, montant payé d'une échéance) ne s'écrivent que dans recalculer().
// Created:     2026-10-03
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Vente
{
    // Statuts d'une vente (CDC §9), calculés par recalculer()
    const STATUTS = array('en_attente', 'paye_partiellement', 'paye', 'echoue', 'rembourse_partiellement', 'rembourse', 'annule');
    // Vente en cours : non annulée, avec un reste dû. Un statut n'y suffit pas : une vente remboursée en partie
    // peut encore attendre des échéances. Condition SQL à poser sur l'alias `v`, avec le fragment SQL_SOMMES.
    const SQL_EN_COURS = "v.date_annulation IS NULL AND v.montant > COALESCE(s.encaisse, 0)";
    // Vente défaite : elle ne compte plus pour le statut du dossier
    const DEFAITES = array('annule', 'rembourse');

    // Natures d'écriture. impaye : encaissement rejeté après coup ; echec : tentative qui n'a rien encaissé
    const TYPES = array('encaissement', 'remboursement', 'impaye', 'echec');

    const MAX_ECHEANCES = 12;

    // Motif posé sur une vente qu'un remboursement total a fermée (sert à la rouvrir si ce remboursement était une erreur de saisie)
    const MOTIF_REMBOURSEMENT_TOTAL = 'Remboursement total';

    // Sommes d'une vente, lues dans le grand livre. Fragment unique : les lignes d'une liste et ses totaux s'en servent,
    // ce qui garantit que les totaux sont ceux des écritures sources (CDC §27).
    const SQL_SOMMES = "LEFT JOIN (
            SELECT id_vente,
                   SUM(CASE type WHEN 'encaissement' THEN montant WHEN 'impaye' THEN -montant ELSE 0 END) AS encaisse,
                   SUM(CASE type WHEN 'remboursement' THEN montant ELSE 0 END) AS rembourse,
                   SUM(CASE WHEN type IN ('encaissement', 'remboursement') THEN COALESCE(frais, 0) ELSE 0 END) AS frais
            FROM v_paiement WHERE date_annulation IS NULL GROUP BY id_vente
        ) s ON s.id_vente = v.id_vente";

    // Vendu : le total d'une vente en cours ou soldée ; pour une vente annulée, ce qui a été encaissé.
    // Reste dû : nul sur une vente annulée. Pour toute vente : vendu = encaissé + reste dû.
    const SQL_VENDU = "CASE WHEN v.date_annulation IS NULL THEN v.montant ELSE COALESCE(s.encaisse, 0) END";
    const SQL_RESTE = "CASE WHEN v.date_annulation IS NULL THEN GREATEST(v.montant - COALESCE(s.encaisse, 0), 0) ELSE 0 END";

    const SQL_FROM = " FROM v_vente v
        INNER JOIN d_contact c ON c.id_contact = v.id_contact
        INNER JOIN p_offre o ON o.code = v.code_offre
        LEFT JOIN p_moyen_paiement m ON m.code = v.code_moyen
        " . self::SQL_SOMMES;

    // Journal des écritures et échéances : mêmes jointures pour les lignes d'une liste et pour ses totaux
    const SQL_FROM_JOURNAL = " FROM v_paiement p
        INNER JOIN v_vente v ON v.id_vente = p.id_vente
        INNER JOIN d_contact c ON c.id_contact = v.id_contact
        LEFT JOIN p_moyen_paiement m ON m.code = p.code_moyen
        LEFT JOIN u_users u ON u.id_users = p.id_users";

    const SQL_FROM_ECHEANCES = " FROM v_echeance e
        INNER JOIN v_vente v ON v.id_vente = e.id_vente
        INNER JOIN d_contact c ON c.id_contact = v.id_contact";

    // Colonnes servies : la vente, ses sommes, et du dossier l'identité seulement (aucune donnée familiale).
    const COLONNES = "v.id_vente, v.id_contact, v.code_offre, o.libelle AS offre, v.date_vente, v.montant_catalogue, v.remise, v.motif_remise,
        v.montant, v.modalite, v.code_moyen, m.libelle AS moyen, v.statut, v.commentaire, v.date_annulation, v.motif_annulation, v.source,
        v.stripe_customer_id, v.stripe_payment_method_id, v.prelevement, v.date_carte,
        v.date_creation, v.date_modif,
        c.prenom, c.nom, c.statut AS contact_statut, c.date_archivage AS contact_date_archivage,
        COALESCE(s.encaisse, 0) AS encaisse, COALESCE(s.rembourse, 0) AS rembourse, COALESCE(s.frais, 0) AS frais,
        " . self::SQL_VENDU . " AS vendu, " . self::SQL_RESTE . " AS reste_du,
        (SELECT COUNT(*) FROM v_echeance e WHERE e.id_vente = v.id_vente AND e.date_annulation IS NULL) AS nb_echeances,
        (SELECT COUNT(*) FROM v_echeance e WHERE e.id_vente = v.id_vente AND e.date_annulation IS NULL AND e.montant_paye >= e.montant) AS nb_soldees,
        (SELECT MIN(e.date_prevue) FROM v_echeance e WHERE e.id_vente = v.id_vente AND e.date_annulation IS NULL AND e.montant_paye < e.montant) AS prochaine_date";

    // LECTURE ########################################################

    /** Référence affichée d'une vente : déduite du numéro, jamais stockée. */
    public static function reference($id_vente)
    {
        return 'VE-' . str_pad((string) (int) $id_vente, 5, '0', STR_PAD_LEFT);
    }

    /** « 99,68 € » pour un montant en centimes (messages d'erreur). */
    public static function euros($centimes)
    {
        return number_format(((int) $centimes) / 100, 2, ',', "\u{202F}") . "\u{00A0}€";
    }

    /**
     * Adresse d'un objet dans le tableau de bord de Stripe (client, paiement), pour l'ouvrir depuis l'outil ; null sans
     * identifiant. Le mode (essai ou réel) se lit dans la clé configurée : le front ne compose pas cette adresse.
     */
    public static function lienStripe($rubrique, $id)
    {
        global $_STRIPE_CLE_SECRETE;

        if ($id === null || $id === '') {
            return null;
        }
        $essai = isset($_STRIPE_CLE_SECRETE) && strpos((string) $_STRIPE_CLE_SECRETE, 'sk_test_') === 0;

        return 'https://dashboard.stripe.com/' . ($essai ? 'test/' : '') . $rubrique . '/' . rawurlencode((string) $id);
    }

    /**
     * Échéancier proposé pour un total en $n fois : parts égales, les centimes de reste sur la première échéance,
     * une échéance par mois à partir de $debut, bornée à la fin du mois. Même règle que proposerEcheancier() du front.
     */
    public static function echeancier($total, $n, $debut)
    {
        $part = intdiv((int) $total, (int) $n);
        $reste = (int) $total - $part * (int) $n;
        list($a, $m, $j) = array_map('intval', explode('-', $debut));
        $echeances = array();
        for ($i = 0; $i < $n; $i++) {
            $premier = mktime(12, 0, 0, $m + $i, 1, $a);
            $jour = min($j, (int) date('t', $premier));
            $echeances[] = array('date_prevue' => date('Y-m-', $premier) . str_pad((string) $jour, 2, '0', STR_PAD_LEFT), 'montant' => $part + ($i === 0 ? $reste : 0));
        }

        return $echeances;
    }

    public function charger($id_vente)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT " . self::COLONNES . self::SQL_FROM . " WHERE v.id_vente = ?", array((int) $id_vente), 'i');
    }

    /**
     * Exige le droit demandé sur le module ($module : ventes ou paiements), puis une vente dont le dossier est lisible.
     * Retourne array($user, $vente) ; sinon 401 / 403 / 404 et exit.
     */
    public function exigerVente($id_vente, $module, $niveau = 'L')
    {
        global $U, $Response;

        $user = $U->requireAccess($module, $niveau);
        $vente = (int) $id_vente > 0 ? $this->charger($id_vente) : null;
        if ($vente === null) {
            $Response->notFound("Vente introuvable.");
        }
        if (!$U->can($user, Contact::groupeDe($vente->contact_statut), 'L')) {
            $Response->forbidden("Vous n'avez pas accès au dossier de cette vente.");
        }

        return array($user, $vente);
    }

    /** Vente telle que servie au front. */
    public function sortie($v)
    {
        $annulee = $v->date_annulation !== null;

        return array(
            'id_vente' => (int) $v->id_vente,
            'reference' => self::reference($v->id_vente),
            'contact' => array(
                'id_contact' => (int) $v->id_contact,
                'reference' => Contact::reference($v->id_contact),
                'prenom' => $v->prenom,
                'nom' => $v->nom,
                'statut' => $v->contact_statut,
                'groupe' => Contact::groupeDe($v->contact_statut),
                'archive' => $v->contact_date_archivage !== null,
            ),
            'code_offre' => $v->code_offre,
            'offre' => $v->offre,
            'date_vente' => $v->date_vente,
            'montant_catalogue' => (int) $v->montant_catalogue,
            'remise' => (int) $v->remise,
            'motif_remise' => $v->motif_remise,
            'montant' => (int) $v->montant,
            'modalite' => $v->modalite,
            'code_moyen' => $v->code_moyen,
            'moyen' => $v->moyen,
            'statut' => $v->statut,
            'commentaire' => $v->commentaire,
            'annulee' => $annulee,
            'date_annulation' => $v->date_annulation,
            'motif_annulation' => $v->motif_annulation,
            'source' => $v->source,
            // Paiement en ligne : la carte est enregistrée chez Stripe, l'outil n'en garde que l'identifiant
            'carte' => $v->stripe_payment_method_id !== null,
            'date_carte' => $v->date_carte,
            'prelevement' => $v->prelevement,
            'lien_stripe' => self::lienStripe('customers', $v->stripe_customer_id),
            'encaisse' => (int) $v->encaisse,
            'rembourse' => (int) $v->rembourse,
            'frais' => (int) $v->frais,
            'vendu' => (int) $v->vendu,
            'reste_du' => (int) $v->reste_du,
            'nb_echeances' => (int) $v->nb_echeances,
            'nb_soldees' => (int) $v->nb_soldees,
            'prochaine_date' => $v->prochaine_date,
            'en_retard' => !$annulee && $v->prochaine_date !== null && $v->prochaine_date < date('Y-m-d'),
            'date_creation' => $v->date_creation,
            'date_modif' => $v->date_modif,
        );
    }

    /**
     * État d'une échéance, calculé à la lecture (le retard dépend du jour).
     * annulee, payee, echouee, en_retard, payee_partiellement, a_venir : première condition vraie.
     */
    public static function etatEcheance($e, $aujourdhui)
    {
        if ($e->date_annulation !== null) {
            return 'annulee';
        }
        if ((int) $e->montant_paye >= (int) $e->montant) {
            return 'payee';
        }
        if ($e->date_dernier_echec !== null) {
            return 'echouee';
        }
        if ($e->date_prevue < $aujourdhui) {
            return 'en_retard';
        }

        return (int) $e->montant_paye > 0 ? 'payee_partiellement' : 'a_venir';
    }

    public function echeanceSortie($e, $aujourdhui)
    {
        $soldee = (int) $e->montant_paye >= (int) $e->montant;

        return array(
            'id_echeance' => (int) $e->id_echeance,
            'id_vente' => (int) $e->id_vente,
            'rang' => (int) $e->rang,
            'date_prevue' => $e->date_prevue,
            'montant' => (int) $e->montant,
            'montant_paye' => (int) $e->montant_paye,
            'reste' => $e->date_annulation !== null ? 0 : (int) $e->montant - (int) $e->montant_paye,
            'date_solde' => $e->date_solde,
            'date_dernier_echec' => $e->date_dernier_echec,
            'etat' => self::etatEcheance($e, $aujourdhui),
            'en_retard' => $e->date_annulation === null && !$soldee && $e->date_prevue < $aujourdhui,
        );
    }

    /** Échéancier d'une vente : les échéances actives dans l'ordre, puis celles annulées avec la vente. */
    public function echeances($id_vente)
    {
        global $Mysql;

        $aujourdhui = date('Y-m-d');
        // Prélèvement des échéances non soldées : prévu si l'outil prélève cette vente, en cours pendant l'appel à Stripe
        $v = $Mysql->fetchOne(
            "SELECT v.prelevement, v.date_annulation,
                    (SELECT MAX(pr.id_echeance) FROM s_prelevement pr WHERE pr.id_vente = v.id_vente AND pr.etat = 'en_cours') AS en_cours
             FROM v_vente v WHERE v.id_vente = ?",
            array((int) $id_vente),
            'i'
        );
        $preleve = $v !== null && $v->prelevement === 'actif' && $v->date_annulation === null;
        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT * FROM v_echeance WHERE id_vente = ? ORDER BY (date_annulation IS NOT NULL), rang, id_echeance",
            array((int) $id_vente),
            'i'
        ) as $e) {
            $ligne = $this->echeanceSortie($e, $aujourdhui);
            $ligne['prelevement'] = null;
            if ($e->date_annulation === null && (int) $e->montant_paye < (int) $e->montant) {
                if ($v !== null && $v->en_cours !== null && (int) $v->en_cours === (int) $e->id_echeance) {
                    $ligne['prelevement'] = 'en_cours';
                } elseif ($preleve && $e->date_dernier_echec === null) {
                    $ligne['prelevement'] = 'prevu';
                }
            }
            $out[] = $ligne;
        }

        return $out;
    }

    private function auteur($row)
    {
        if (!isset($row->auteur_identifiant) || $row->auteur_identifiant === null) {
            return null;
        }
        $nom = trim((string) $row->auteur_prenom . ' ' . (string) $row->auteur_nom);

        return $nom !== '' ? $nom : $row->auteur_identifiant;
    }

    public function paiementSortie($p)
    {
        return array(
            'id_paiement' => (int) $p->id_paiement,
            'id_vente' => (int) $p->id_vente,
            'id_echeance' => $p->id_echeance === null ? null : (int) $p->id_echeance,
            'type' => $p->type,
            'montant' => (int) $p->montant,
            'date_paiement' => $p->date_paiement,
            'code_moyen' => $p->code_moyen,
            'moyen' => $p->moyen,
            'reference' => $p->reference,
            'frais' => $p->frais === null ? null : (int) $p->frais,
            'motif' => $p->motif,
            'commentaire' => $p->commentaire,
            'id_paiement_origine' => $p->id_paiement_origine === null ? null : (int) $p->id_paiement_origine,
            // Encaissement rejeté par un impayé enregistré ensuite
            'rejete' => isset($p->rejete) && (int) $p->rejete === 1,
            'source' => $p->source,
            'lien_stripe' => self::lienStripe('payments', isset($p->stripe_payment_intent_id) ? $p->stripe_payment_intent_id : null),
            'annulee' => $p->date_annulation !== null,
            'date_annulation' => $p->date_annulation,
            'motif_annulation' => $p->motif_annulation,
            'auteur' => $this->auteur($p),
            'date_creation' => $p->date_creation,
        );
    }

    /** Écritures d'une vente, des plus récentes aux plus anciennes ; les écritures annulées restent lisibles. */
    public function paiements($id_vente)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT p.*, m.libelle AS moyen,
                    u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom,
                    EXISTS (SELECT 1 FROM v_paiement i WHERE i.id_paiement_origine = p.id_paiement AND i.type = 'impaye' AND i.date_annulation IS NULL) AS rejete
             FROM v_paiement p
             LEFT JOIN p_moyen_paiement m ON m.code = p.code_moyen
             LEFT JOIN u_users u ON u.id_users = p.id_users
             WHERE p.id_vente = ?
             ORDER BY p.date_paiement DESC, p.id_paiement DESC",
            array((int) $id_vente),
            'i'
        ) as $p) {
            $out[] = $this->paiementSortie($p);
        }

        return $out;
    }

    /** Historique des changements financiers d'une vente (CDC §9), du plus récent au plus ancien. */
    public function historique($id_vente)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT h.*, u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom
             FROM v_historique h LEFT JOIN u_users u ON u.id_users = h.id_users
             WHERE h.id_vente = ? ORDER BY h.id_historique DESC",
            array((int) $id_vente),
            'i'
        ) as $h) {
            $out[] = array(
                'id_historique' => (int) $h->id_historique,
                'objet' => $h->objet,
                'objet_id' => $h->objet_id === null ? null : (int) $h->objet_id,
                'action' => $h->action,
                'avant' => $h->avant === null ? null : json_decode($h->avant, true),
                'apres' => $h->apres === null ? null : json_decode($h->apres, true),
                'origine' => $h->origine,
                'auteur' => $this->auteur($h),
                'date' => $h->date_creation,
            );
        }

        return $out;
    }

    /**
     * Bloc « ventes » d'un dossier : ses ventes de la plus récente à la plus ancienne, chacune avec son échéancier,
     * et ses écritures si l'utilisateur peut lire les paiements.
     */
    public function blocDossier($id_contact, $user)
    {
        global $Mysql, $U;

        $avecEcritures = $U->can($user, 'paiements', 'L');
        $ventes = array();
        foreach ($Mysql->fetchAll(
            "SELECT " . self::COLONNES . self::SQL_FROM . " WHERE v.id_contact = ? ORDER BY v.date_vente DESC, v.id_vente DESC",
            array((int) $id_contact),
            'i'
        ) as $v) {
            $out = $this->sortie($v);
            $out['echeances'] = $this->echeances($v->id_vente);
            if ($avecEcritures) {
                $out['paiements'] = $this->paiements($v->id_vente);
            }
            $ventes[] = $out;
        }

        return $ventes;
    }

    // FILTRES DES LISTES #############################################

    /**
     * Condition SQL limitant une liste aux ventes dont le dossier est lisible par l'utilisateur
     * (alias `c` sur d_contact). Retourne array(sql, params), ou null s'il ne peut lire aucun dossier.
     */
    public function conditionDossiersLisibles($user)
    {
        global $Contact;

        return $Contact->conditionLisibles($user);
    }

    /**
     * Recherche d'une liste financière : référence de vente (VE-00012, ve12) ou recherche de dossier
     * (nom, prénom, e-mail, téléphone, NU-…). Alias `v` sur v_vente et `c` sur d_contact.
     * Retourne array(sql, params) ou null si le terme est inexploitable.
     */
    public function conditionRecherche($q)
    {
        global $Contact;

        if (is_string($q) && preg_match('/^\s*ve-?0*(\d{1,9})\s*$/i', $q, $m)) {
            return array("v.id_vente = ?", array((int) $m[1]));
        }

        return $Contact->conditionRecherche($q);
    }

    /** Date AAAA-MM-JJ lue dans $_GET (voir Saisie::dateFiltre). */
    public function dateFiltre($cle)
    {
        global $S;

        return $S->dateFiltre($cle);
    }

    // TOTAUX D'UNE SÉLECTION #########################################
    // Les totaux d'une liste et le chiffre du tableau de bord qui y renvoie sortent de la même requête :
    // seul le WHERE change. C'est ce qui garantit qu'ils sont égaux (CDC §27).
    // $where : conditions jointes par AND, sans le mot WHERE (chaîne vide : toute la table).

    /** Totaux d'une sélection de ventes (alias `v`, `c`, `s` de SQL_FROM) : nombre, vendu, encaissé, remboursé, reste dû. */
    public function totauxVentes($where, $params = array())
    {
        global $Mysql;

        $t = $Mysql->fetchOne(
            "SELECT COUNT(*) AS nb,
                    COALESCE(SUM(" . self::SQL_VENDU . "), 0) AS vendu,
                    COALESCE(SUM(COALESCE(s.encaisse, 0)), 0) AS encaisse,
                    COALESCE(SUM(COALESCE(s.rembourse, 0)), 0) AS rembourse,
                    COALESCE(SUM(" . self::SQL_RESTE . "), 0) AS reste_du"
            . self::SQL_FROM . ($where !== '' ? " WHERE $where" : ''),
            $params
        );

        return array(
            'nb' => (int) $t->nb,
            'vendu' => (int) $t->vendu,
            'encaisse' => (int) $t->encaisse,
            'rembourse' => (int) $t->rembourse,
            'reste_du' => (int) $t->reste_du,
        );
    }

    /**
     * Totaux d'une sélection d'écritures (alias `p`, `v`, `c` de SQL_FROM_JOURNAL) : nombre, encaissé (encaissements
     * moins impayés), remboursé, frais connus, net (encaissé moins frais), et le nombre d'encaissements, de remboursements
     * et d'impayés qui font ces sommes. Une écriture annulée ne compte dans aucune somme ni dans ces trois nombres.
     */
    public function totauxJournal($where, $params = array())
    {
        global $Mysql;

        $t = $Mysql->fetchOne(
            "SELECT COUNT(*) AS nb,
                    COALESCE(SUM(p.date_annulation IS NULL AND p.type = 'encaissement'), 0) AS nb_encaissements,
                    COALESCE(SUM(p.date_annulation IS NULL AND p.type = 'remboursement'), 0) AS nb_remboursements,
                    COALESCE(SUM(p.date_annulation IS NULL AND p.type = 'impaye'), 0) AS nb_impayes,
                    COALESCE(SUM(CASE WHEN p.date_annulation IS NOT NULL THEN 0 WHEN p.type = 'encaissement' THEN p.montant WHEN p.type = 'impaye' THEN -p.montant ELSE 0 END), 0) AS encaisse,
                    COALESCE(SUM(CASE WHEN p.date_annulation IS NULL AND p.type = 'remboursement' THEN p.montant ELSE 0 END), 0) AS rembourse,
                    COALESCE(SUM(CASE WHEN p.date_annulation IS NULL AND p.type IN ('encaissement', 'remboursement') THEN COALESCE(p.frais, 0) ELSE 0 END), 0) AS frais"
            . self::SQL_FROM_JOURNAL . ($where !== '' ? " WHERE $where" : ''),
            $params
        );

        return array(
            'nb' => (int) $t->nb,
            'encaisse' => (int) $t->encaisse,
            'rembourse' => (int) $t->rembourse,
            'frais' => (int) $t->frais,
            'net' => (int) $t->encaisse - (int) $t->frais,
            'nb_encaissements' => (int) $t->nb_encaissements,
            'nb_remboursements' => (int) $t->nb_remboursements,
            'nb_impayes' => (int) $t->nb_impayes,
        );
    }

    /**
     * Totaux d'une sélection d'échéances (alias `e`, `v`, `c` de SQL_FROM_ECHEANCES) : nombre, reste à encaisser,
     * dont la part et le nombre d'échéances en retard au jour $jour (AAAA-MM-JJ, donné par PHP).
     */
    public function totauxEcheances($where, $params, $jour)
    {
        global $Mysql;

        $t = $Mysql->fetchOne(
            "SELECT COUNT(*) AS nb,
                    COALESCE(SUM(e.montant - e.montant_paye), 0) AS a_encaisser,
                    COALESCE(SUM(CASE WHEN e.montant_paye < e.montant AND e.date_prevue < ? THEN e.montant - e.montant_paye ELSE 0 END), 0) AS en_retard,
                    COALESCE(SUM(CASE WHEN e.montant_paye < e.montant AND e.date_prevue < ? THEN 1 ELSE 0 END), 0) AS nb_retard"
            . self::SQL_FROM_ECHEANCES . ($where !== '' ? " WHERE $where" : ''),
            array_merge(array($jour, $jour), $params)
        );

        return array(
            'nb' => (int) $t->nb,
            'a_encaisser' => (int) $t->a_encaisser,
            'en_retard' => (int) $t->en_retard,
            'nb_retard' => (int) $t->nb_retard,
        );
    }

    // SAISIE #########################################################

    /** Champs descriptifs d'une vente. Le prix vient de l'offre, lu côté serveur : il ne se saisit pas. */
    public function specVente()
    {
        return array(
            'code_offre' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_offre', 'col' => 'code', 'where' => 'actif = 1', 'requis' => true, 'libelle' => 'offre'),
            'date_vente' => array('type' => 'date', 'requis' => true, 'max' => date('Y-m-d'), 'libelle' => 'date de la vente'),
            'remise' => array('type' => 'int', 'min' => 0, 'defaut' => 0, 'libelle' => 'remise'),
            'motif_remise' => array('type' => 'str', 'max' => 255, 'libelle' => 'motif de la remise'),
            'code_moyen' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_moyen_paiement', 'col' => 'code', 'where' => 'actif = 1', 'libelle' => 'moyen de paiement'),
            'commentaire' => array('type' => 'str', 'max' => 255, 'libelle' => 'commentaire'),
        );
    }

    public function specPaiement()
    {
        return array(
            'type' => array('type' => 'enum', 'valeurs' => self::TYPES, 'requis' => true, 'libelle' => 'nature'),
            'montant' => array('type' => 'int', 'min' => 1, 'libelle' => 'montant'),
            'date_paiement' => array('type' => 'date', 'requis' => true, 'max' => date('Y-m-d'), 'libelle' => 'date'),
            'code_moyen' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_moyen_paiement', 'col' => 'code', 'where' => 'actif = 1', 'libelle' => 'moyen de paiement'),
            'reference' => array('type' => 'str', 'max' => 100, 'libelle' => 'référence'),
            'frais' => array('type' => 'int', 'min' => 0, 'libelle' => 'frais'),
            'motif' => array('type' => 'str', 'max' => 255, 'libelle' => 'motif'),
            'commentaire' => array('type' => 'str', 'max' => 255, 'libelle' => 'commentaire'),
            'id_paiement_origine' => array('type' => 'int', 'min' => 1, 'libelle' => 'encaissement rejeté'),
        );
    }

    /** Clé de saisie d'un formulaire (voir Saisie::lireCle). */
    public function lireCle($R)
    {
        global $S;

        return $S->lireCle($R);
    }

    /**
     * Lit un échéancier [{date_prevue, montant}] : dates valides et croissantes, montants entiers positifs.
     * $min / $max : nombre d'échéances admis. Retourne la liste normalisée.
     */
    public function lireEcheances($liste, $min = 1, $max = self::MAX_ECHEANCES)
    {
        global $Response;

        if (!is_array($liste) || count($liste) < $min || count($liste) > $max) {
            $Response->validationError($min === $max ? "L'échéancier doit compter $min échéance(s)." : "L'échéancier doit compter entre $min et $max échéances.");
        }
        $out = array();
        $precedente = null;
        foreach ($liste as $i => $e) {
            $n = $i + 1;
            if (!is_object($e) || !isset($e->date_prevue, $e->montant)) {
                $Response->validationError("Échéance $n : la date et le montant sont obligatoires.");
            }
            $ok = is_string($e->date_prevue) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $e->date_prevue, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
            if (!$ok) {
                $Response->validationError("Échéance $n : la date doit être au format AAAA-MM-JJ.");
            }
            // Un montant est un nombre entier de centimes : jamais un nombre à virgule
            if (!is_int($e->montant) || $e->montant <= 0) {
                $Response->validationError("Échéance $n : le montant doit être un nombre entier de centimes, supérieur à zéro.");
            }
            if ($precedente !== null && $e->date_prevue < $precedente) {
                $Response->validationError("Échéance $n : les dates doivent se suivre.");
            }
            $precedente = $e->date_prevue;
            $out[] = array('date_prevue' => $e->date_prevue, 'montant' => $e->montant);
        }

        return $out;
    }

    // CALCUL #########################################################

    /**
     * Calcule, depuis le grand livre, ce que valent les caches d'une vente : statut, modalité, part payée de chaque
     * échéance. Les encaissements soldent les échéances dans l'ordre des rangs (la dette la plus ancienne d'abord) :
     * paiement partiel, groupé ou anticipé se traitent sans cas particulier. Ne modifie rien.
     * Retourne array(encaisse, rembourse, statut, modalite, echeances => array(id => array(montant_paye, date_solde, date_dernier_echec))).
     */
    public function calculer($id_vente)
    {
        global $Mysql;

        $id = (int) $id_vente;
        $vente = $Mysql->fetchOne("SELECT montant, date_annulation FROM v_vente WHERE id_vente = ?", array($id), 'i');
        $lignes = $Mysql->fetchAll(
            "SELECT id_paiement, type, montant, date_paiement, id_paiement_origine FROM v_paiement
             WHERE id_vente = ? AND date_annulation IS NULL ORDER BY id_paiement",
            array($id),
            'i'
        );

        // Encaissements effectifs : ceux qu'aucun impayé n'a rejetés
        $rejetes = array();
        $rembourse = 0;
        foreach ($lignes as $l) {
            if ($l->type === 'impaye' && $l->id_paiement_origine !== null) {
                $rejetes[(int) $l->id_paiement_origine] = true;
            } elseif ($l->type === 'remboursement') {
                $rembourse += (int) $l->montant;
            }
        }
        $effectifs = array();
        $encaisse = 0;
        $derniere = null; // dernière tentative en date de saisie : encaissement effectif, échec ou impayé
        foreach ($lignes as $l) {
            if ($l->type === 'encaissement') {
                if (isset($rejetes[(int) $l->id_paiement])) {
                    continue;
                }
                $effectifs[] = $l;
                $encaisse += (int) $l->montant;
                $derniere = $l;
            } elseif ($l->type === 'echec' || $l->type === 'impaye') {
                $derniere = $l;
            }
        }
        $echecRecent = $derniere !== null && $derniere->type !== 'encaissement';

        usort($effectifs, function ($a, $b) {
            return strcmp($a->date_paiement, $b->date_paiement) ?: ((int) $a->id_paiement - (int) $b->id_paiement);
        });

        // Répartition dans l'ordre des rangs
        $caches = array();
        $seuil = 0;      // cumul dû jusqu'à l'échéance courante
        $cumul = 0;      // cumul des encaissements consommés
        $j = 0;
        $dateAtteinte = null;
        $premiereNonSoldee = true;
        foreach ($Mysql->fetchAll(
            "SELECT id_echeance, montant FROM v_echeance WHERE id_vente = ? AND date_annulation IS NULL ORDER BY rang, id_echeance",
            array($id),
            'i'
        ) as $e) {
            $montant = (int) $e->montant;
            $paye = max(0, min($montant, $encaisse - $seuil));
            $seuil += $montant;
            while ($j < count($effectifs) && $cumul < $seuil) {
                $cumul += (int) $effectifs[$j]->montant;
                $dateAtteinte = $effectifs[$j]->date_paiement;
                $j++;
            }
            $soldee = $paye >= $montant;
            $dateEchec = null;
            if (!$soldee && $premiereNonSoldee) {
                $premiereNonSoldee = false;
                if ($echecRecent && $vente->date_annulation === null) {
                    $dateEchec = $derniere->date_paiement;
                }
            }
            $caches[(int) $e->id_echeance] = array('montant_paye' => $paye, 'date_solde' => $soldee ? $dateAtteinte : null, 'date_dernier_echec' => $dateEchec);
        }

        // Statut : première condition vraie
        $annulee = $vente->date_annulation !== null;
        if ($annulee && $encaisse > 0 && $rembourse >= $encaisse) {
            $statut = 'rembourse';
        } elseif ($annulee && $rembourse > 0) {
            $statut = 'rembourse_partiellement';
        } elseif ($annulee) {
            $statut = 'annule';
        } elseif ($rembourse > 0) {
            $statut = 'rembourse_partiellement';
        } elseif ($encaisse >= (int) $vente->montant) {
            $statut = 'paye';
        } elseif ($encaisse > 0) {
            $statut = 'paye_partiellement';
        } elseif ($echecRecent) {
            $statut = 'echoue';
        } else {
            $statut = 'en_attente';
        }

        $nb = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM v_echeance WHERE id_vente = ?", array($id), 'i')->nb;

        return array(
            'encaisse' => $encaisse,
            'rembourse' => $rembourse,
            'statut' => $statut,
            'modalite' => $nb > 1 ? 'fractionne' : 'comptant',
            'echeances' => $caches,
        );
    }

    /**
     * Seul point d'écriture des caches d'une vente (statut, modalité, part payée de chaque échéance), d'après calculer().
     * À appeler dans la transaction de chaque écriture, dossier verrouillé. Retourne array(encaisse, rembourse, statut).
     */
    public function recalculer($id_vente)
    {
        global $Mysql;

        $id = (int) $id_vente;
        $c = $this->calculer($id);

        foreach ($Mysql->fetchAll("SELECT id_echeance, montant_paye, date_solde, date_dernier_echec FROM v_echeance WHERE id_vente = ? AND date_annulation IS NULL", array($id), 'i') as $e) {
            $n = $c['echeances'][(int) $e->id_echeance];
            if ($n['montant_paye'] !== (int) $e->montant_paye || $n['date_solde'] !== $e->date_solde || $n['date_dernier_echec'] !== $e->date_dernier_echec) {
                $Mysql->execute(
                    "UPDATE v_echeance SET montant_paye = ?, date_solde = ?, date_dernier_echec = ?, date_modif = NOW() WHERE id_echeance = ?",
                    array($n['montant_paye'], $n['date_solde'], $n['date_dernier_echec'], (int) $e->id_echeance),
                    'issi'
                );
            }
        }

        $vente = $Mysql->fetchOne("SELECT statut, modalite FROM v_vente WHERE id_vente = ?", array($id), 'i');
        if ($c['statut'] !== $vente->statut || $c['modalite'] !== $vente->modalite) {
            $Mysql->execute("UPDATE v_vente SET statut = ?, modalite = ?, date_modif = NOW() WHERE id_vente = ?", array($c['statut'], $c['modalite'], $id), 'ssi');
        }

        return array('encaisse' => $c['encaisse'], 'rembourse' => $c['rembourse'], 'statut' => $c['statut']);
    }

    // ÉCRITURES ######################################################

    /**
     * Ouvre la transaction d'une écriture financière en verrouillant le dossier : deux envois rapprochés
     * passent l'un après l'autre, et le second relit l'état laissé par le premier.
     * Les contrôles de saisie (lireChamps) se font avant : une lecture faite avant le verrou serait périmée.
     */
    private function verrouiller($id_contact)
    {
        global $Contact;

        $Contact->verrouiller($id_contact);
    }

    private function historiser($id_vente, $objet, $objet_id, $action, $avant, $apres, $id_users, $origine = 'utilisateur')
    {
        global $Mysql;

        $Mysql->execute(
            "INSERT INTO v_historique (id_vente, objet, objet_id, action, avant, apres, origine, id_users) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            array(
                (int) $id_vente,
                $objet,
                $objet_id === null ? null : (int) $objet_id,
                $action,
                $avant === null ? null : json_encode($avant, JSON_UNESCAPED_UNICODE),
                $apres === null ? null : json_encode($apres, JSON_UNESCAPED_UNICODE),
                $origine,
                $id_users === null ? null : (int) $id_users,
            ),
            'isissssi'
        );
    }

    /**
     * Automatismes du dossier après une écriture, dans la même transaction.
     * - Premier paiement (l'encaissé passe de zéro à plus de zéro) : le compte NavUp Academy s'ouvre, le programme
     *   commence le jour du paiement et le dossier devient « Client actif » (« Client » si l'offre n'a pas de formation).
     * - Vente annulée ou remboursée en totalité, sans autre vente : le compte est désactivé, un client devient
     *   « Annulé / remboursé ».
     * - Écriture annulée pour erreur de saisie (premier paiement, ou remboursement total) : le dossier reprend
     *   son statut précédent si le dernier changement de statut est bien celui que l'écriture avait provoqué,
     *   et son compte suit (désactivé si le paiement disparaît, réactivé si la vente revit).
     * Ils s'appliquent quel que soit le droit de l'auteur sur les dossiers : c'est l'écriture financière qui les déclenche.
     * Retourne les avertissements à montrer à l'utilisateur.
     */
    private function automatismes($id_contact, $id_vente, $avant, $apres, $id_users, $erreurDeSaisie = false)
    {
        global $Contact, $Mysql;

        $avertissements = array();
        $contact = $Contact->charger($id_contact);
        // Autres ventes du dossier qui comptent encore : tant qu'il en reste une, ni le statut ni le compte ne se défont
        $autres = function () use ($Mysql, $id_contact, $id_vente) {
            return (int) $Mysql->fetchOne(
                "SELECT COUNT(*) AS nb FROM v_vente WHERE id_contact = ? AND id_vente <> ? AND statut NOT IN ('annule', 'rembourse')",
                array((int) $id_contact, (int) $id_vente),
                'ii'
            )->nb;
        };

        if ($avant['encaisse'] <= 0 && $apres['encaisse'] > 0 && !in_array($apres['statut'], self::DEFAITES, true)) {
            $actif = Compte::ouvrir($id_contact, $id_vente, $id_users, 'automatique');
            $arrivant = Contact::groupeDe($contact->statut) === 'prospects' || $contact->statut === 'annule_rembourse';
            if ($actif && ($arrivant || in_array($contact->statut, array('client', 'programme_termine'), true))) {
                $Contact->changerStatut($contact, 'client_actif', $id_users, 'automatique');
            } elseif (!$actif && $arrivant) {
                $Contact->changerStatut($contact, 'client', $id_users, 'automatique');
            }
        } elseif (in_array($apres['statut'], self::DEFAITES, true) && !in_array($avant['statut'], self::DEFAITES, true)) {
            if ($autres() === 0) {
                Compte::desactiver($id_contact, $id_users, 'automatique');
                if (in_array($contact->statut, array('client', 'client_actif', 'programme_termine'), true)) {
                    $Contact->changerStatut($contact, 'annule_rembourse', $id_users, 'automatique');
                }
            }
        } elseif ($erreurDeSaisie) {
            // Le changement à défaire : « Client actif » (ou « Client ») si le premier paiement disparaît,
            // « Annulé / remboursé » si la vente revit
            $aDefaire = null;
            if ($avant['encaisse'] > 0 && $apres['encaisse'] <= 0) {
                if ($autres() === 0) {
                    Compte::desactiver($id_contact, $id_users, 'automatique');
                }
                if (in_array($contact->statut, array('client', 'client_actif'), true)) {
                    $aDefaire = $contact->statut;
                }
            } elseif (in_array($avant['statut'], self::DEFAITES, true) && !in_array($apres['statut'], self::DEFAITES, true)) {
                Compte::reactiver($id_contact, $id_users, true);
                if ($contact->statut === 'annule_rembourse') {
                    $aDefaire = 'annule_rembourse';
                }
            }
            if ($aDefaire !== null) {
                $dernier = $Mysql->fetchOne(
                    "SELECT details, origine FROM d_evenement WHERE id_contact = ? AND type = 'statut' ORDER BY id_evenement DESC LIMIT 1",
                    array((int) $id_contact),
                    'i'
                );
                $d = $dernier === null ? array() : (array) json_decode((string) $dernier->details, true);
                $precedent = isset($d['avant']) ? $d['avant'] : null;
                if ($dernier !== null && $dernier->origine === 'automatique' && isset($d['apres']) && $d['apres'] === $aDefaire && in_array($precedent, Contact::STATUTS, true)) {
                    $Contact->changerStatut($contact, $precedent, $id_users, 'automatique');
                } else {
                    $avertissements[] = "Le statut du dossier n'a pas été modifié : vérifiez qu'il est toujours juste.";
                }
            }
        }

        return $avertissements;
    }

    /** Insère une écriture du grand livre, son historique et sa trace. Sans transaction : l'appelant la tient. */
    private function inscrire($vente, $ligne, $id_users, $origine)
    {
        global $S, $Contact;

        $ligne['id_vente'] = (int) $vente->id_vente;
        $ligne['id_users'] = $id_users;
        $ligne['source'] = $origine === 'automatique' ? 'stripe' : 'manuel';
        $idp = $S->inserer('v_paiement', $ligne);
        // Après un encaissement, une page de paiement restée ouverte ferait payer deux fois la même échéance
        if ($ligne['type'] === 'encaissement') {
            $this->fermerSessions($vente->id_vente);
        }

        $this->historiser($vente->id_vente, 'paiement', $idp, $ligne['type'], null, array(
            'montant' => $ligne['montant'],
            'date_paiement' => $ligne['date_paiement'],
            'code_moyen' => isset($ligne['code_moyen']) ? $ligne['code_moyen'] : null,
        ), $id_users, $origine);

        $codes = array('id_vente' => (int) $vente->id_vente, 'id_paiement' => $idp, 'type' => $ligne['type']);
        $Contact->tracer($vente->id_contact, $id_users, 'paiement_create', $codes, array(
            'type' => 'paiement', 'module' => 'paiements', 'objet_type' => 'paiement', 'objet_id' => $idp,
            'details' => array('action' => $ligne['type'], 'id_vente' => (int) $vente->id_vente), 'origine' => $origine,
        ));

        return $idp;
    }

    /** Première échéance active non soldée d'une vente (celle qu'un paiement vise par défaut), ou null. */
    private function premiereNonSoldee($id_vente)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT id_echeance, montant, montant_paye FROM v_echeance
             WHERE id_vente = ? AND date_annulation IS NULL AND montant_paye < montant ORDER BY rang, id_echeance LIMIT 1",
            array((int) $id_vente),
            'i'
        );
    }

    /** Annule les échéances non soldées d'une vente (vente annulée ou remboursée en totalité). */
    private function fermer($id_vente, $motif, $id_users)
    {
        global $Mysql;

        $Mysql->execute(
            "UPDATE v_vente SET date_annulation = NOW(), motif_annulation = ?, id_users_annulation = ?, date_modif = NOW() WHERE id_vente = ? AND date_annulation IS NULL",
            array($motif, $id_users, (int) $id_vente),
            'sii'
        );
        $Mysql->execute(
            "UPDATE v_echeance SET date_annulation = NOW(), date_modif = NOW() WHERE id_vente = ? AND date_annulation IS NULL AND montant_paye < montant",
            array((int) $id_vente),
            'i'
        );
        $this->fermerSessions($id_vente);
    }

    /**
     * Une page de paiement ouverte ne doit plus servir quand la vente change (annulation, révision de l'échéancier) :
     * elle est marquée expirée ici, dans la transaction ; la tâche planifiée la ferme ensuite chez Stripe.
     */
    private function fermerSessions($id_vente)
    {
        global $Mysql;

        $Mysql->execute("UPDATE s_session SET etat = 'expiree', date_modif = NOW() WHERE id_vente = ? AND etat = 'ouverte'", array((int) $id_vente), 'i');
    }

    /** Refuse de modifier une vente pendant qu'un prélèvement est parti chez Stripe : son issue n'est pas encore connue. */
    private function exigerSansPrelevement($id_vente)
    {
        global $Mysql, $Response;

        if ($Mysql->fetchOne("SELECT 1 AS x FROM s_prelevement WHERE id_vente = ? AND etat = 'en_cours' LIMIT 1", array((int) $id_vente), 'i') !== null) {
            $Response->validationError("Un prélèvement est en cours sur cette vente : attendez son issue (quelques minutes) avant de la modifier.");
        }
    }

    /**
     * Carte enregistrée chez Stripe au paiement d'une échéance : l'outil garde l'identifiant du client et du moyen de
     * paiement, et prélèvera les échéances suivantes à leur date. Des prélèvements suspendus à la main le restent.
     * Ouvre et valide sa transaction, dossier verrouillé.
     */
    public function enregistrerCarte($id_vente, $client, $moyen, $id_users = null, $origine = 'automatique')
    {
        global $SQL, $Mysql, $Contact;

        $vente = $this->charger($id_vente);
        $this->verrouiller($vente->id_contact);
        $vente = $this->charger($id_vente);

        if ($vente->stripe_payment_method_id !== $moyen || $vente->stripe_customer_id !== $client) {
            $prelevement = $vente->prelevement === 'suspendu' ? 'suspendu' : 'actif';
            $Mysql->execute(
                "UPDATE v_vente SET stripe_customer_id = ?, stripe_payment_method_id = ?, prelevement = ?, date_carte = NOW(), date_modif = NOW() WHERE id_vente = ?",
                array($client, $moyen, $prelevement, (int) $id_vente),
                'sssi'
            );
            $this->historiser($id_vente, 'vente', $id_vente, 'carte', array('prelevement' => $vente->prelevement), array('prelevement' => $prelevement), $id_users, $origine);
            $Contact->tracer($vente->id_contact, $id_users, 'vente_carte', array('id_vente' => (int) $id_vente), array(
                'type' => 'vente', 'module' => 'ventes', 'objet_type' => 'vente', 'objet_id' => (int) $id_vente,
                'details' => array('action' => 'carte'), 'origine' => $origine,
            ));
        }
        $SQL->commit();
    }

    /** Client Stripe d'une vente, posé avant l'ouverture de sa première page de paiement. Ni historique ni fil : rien n'a encore été payé. */
    public function noterClientStripe($id_vente, $client)
    {
        global $Mysql;

        $Mysql->execute("UPDATE v_vente SET stripe_customer_id = ?, date_modif = NOW() WHERE id_vente = ? AND stripe_customer_id IS NULL", array($client, (int) $id_vente), 'si');
    }

    /**
     * Suspend ou reprend les prélèvements d'une vente dont la carte est enregistrée (geste d'un utilisateur).
     * Ouvre et valide sa transaction, dossier verrouillé.
     */
    public function reglerPrelevement($id_vente, $actif, $id_users)
    {
        global $SQL, $Mysql, $Contact, $Response;

        $vente = $this->charger($id_vente);
        $this->verrouiller($vente->id_contact);
        $vente = $this->charger($id_vente);

        if ($vente->stripe_payment_method_id === null) {
            $Response->validationError("Aucune carte n'est enregistrée pour cette vente : il n'y a pas de prélèvement à régler.");
        }
        if ($vente->date_annulation !== null) {
            $Response->validationError("Cette vente est annulée : il n'y a plus rien à prélever.");
        }
        $nouveau = $actif ? 'actif' : 'suspendu';
        if ($vente->prelevement !== $nouveau) {
            $Mysql->execute("UPDATE v_vente SET prelevement = ?, date_modif = NOW() WHERE id_vente = ?", array($nouveau, (int) $id_vente), 'si');
            $this->historiser($id_vente, 'vente', $id_vente, 'prelevement', array('prelevement' => $vente->prelevement), array('prelevement' => $nouveau), $id_users);
            $Contact->tracer($vente->id_contact, $id_users, 'vente_prelevement', array('id_vente' => (int) $id_vente, 'prelevement' => $nouveau), array(
                'type' => 'vente', 'module' => 'ventes', 'objet_type' => 'vente', 'objet_id' => (int) $id_vente,
                'details' => array('action' => 'prelevement', 'prelevement' => $nouveau),
            ));
        }
        $SQL->commit();
    }

    /**
     * Enregistre une vente et son échéancier ; avec $encaissement (array date_paiement, code_moyen, reference),
     * encaisse tout de suite la première échéance.
     * $data : champs de specVente() ; $echeances : liste de lireEcheances() ; $cle : clé de saisie ou null.
     * $stripe (vente née d'un paiement en ligne) : array('stripe_id' => paiement à l'origine de la vente,
     * 'stripe_customer_id') ; $encaissement porte alors aussi 'stripe_id', 'stripe_payment_intent_id' et 'frais'.
     * Retourne array(id_vente, avertissements, deja) ; `deja` : la clé de saisie ou le paiement Stripe avait déjà servi,
     * rien n'a été écrit.
     */
    public function creer($contact, $data, $echeances, $encaissement, $cle, $id_users, $origine = 'utilisateur', $stripe = null)
    {
        global $SQL, $Mysql, $S, $Contact, $Response;

        $idc = (int) $contact->id_contact;
        $offre = $Mysql->fetchOne("SELECT prix FROM p_offre WHERE code = ?", array($data['code_offre']), 's');
        $catalogue = (int) $offre->prix;
        $remise = isset($data['remise']) ? (int) $data['remise'] : 0;
        if ($remise >= $catalogue) {
            $Response->validationError("La remise doit rester inférieure au prix de l'offre (" . self::euros($catalogue) . ").");
        }
        $du = $catalogue - $remise;
        if (array_sum(array_column($echeances, 'montant')) !== $du) {
            $Response->validationError("La somme des échéances doit être égale au total de la vente (" . self::euros($du) . ").");
        }
        if ($echeances[0]['date_prevue'] < $data['date_vente']) {
            $Response->validationError("La première échéance ne peut pas précéder la date de la vente.");
        }
        if ($encaissement !== null && $encaissement['date_paiement'] < $data['date_vente']) {
            $Response->validationError("Le paiement ne peut pas précéder la date de la vente.");
        }

        $this->verrouiller($idc);

        if ($cle !== null) {
            $deja = $Mysql->fetchOne("SELECT id_vente FROM v_vente WHERE cle_saisie = ?", array($cle), 's');
            if ($deja !== null) {
                $SQL->commit();

                return array('id_vente' => (int) $deja->id_vente, 'avertissements' => array(), 'deja' => true);
            }
        }
        // Même garde pour un paiement Stripe signalé deux fois (webhook, rattrapage, retour de la page de paiement)
        if ($stripe !== null && isset($stripe['stripe_id'])) {
            $deja = $Mysql->fetchOne("SELECT id_vente FROM v_vente WHERE stripe_id = ?", array($stripe['stripe_id']), 's');
            if ($deja !== null) {
                $SQL->commit();

                return array('id_vente' => (int) $deja->id_vente, 'avertissements' => array(), 'deja' => true);
            }
        }

        // Garde-fous relus sous le verrou
        $etat = $Mysql->fetchOne("SELECT date_archivage FROM d_contact WHERE id_contact = ?", array($idc), 'i');
        if ($etat->date_archivage !== null) {
            $Response->validationError("Ce dossier est classé sans suite : rouvrez-le avant d'enregistrer une vente.");
        }
        $enCours = (int) $Mysql->fetchOne(
            "SELECT COUNT(*) AS nb FROM v_vente v " . self::SQL_SOMMES . " WHERE v.id_contact = ? AND " . self::SQL_EN_COURS,
            array($idc),
            'i'
        )->nb;
        if ($enCours > 0) {
            $Response->validationError("Ce dossier a déjà une vente en cours : soldez-la ou annulez-la avant d'en enregistrer une autre.");
        }

        $idv = $S->inserer('v_vente', array(
            'id_contact' => $idc,
            'code_offre' => $data['code_offre'],
            'date_vente' => $data['date_vente'],
            'montant_catalogue' => $catalogue,
            'remise' => $remise,
            'motif_remise' => $remise > 0 && isset($data['motif_remise']) ? $data['motif_remise'] : null,
            'modalite' => count($echeances) > 1 ? 'fractionne' : 'comptant',
            'code_moyen' => isset($data['code_moyen']) ? $data['code_moyen'] : null,
            'commentaire' => isset($data['commentaire']) ? $data['commentaire'] : null,
            'source' => $origine === 'automatique' ? 'stripe' : 'manuel',
            'stripe_id' => $stripe !== null && isset($stripe['stripe_id']) ? $stripe['stripe_id'] : null,
            'stripe_customer_id' => $stripe !== null && isset($stripe['stripe_customer_id']) ? $stripe['stripe_customer_id'] : null,
            'cle_saisie' => $cle,
            'id_users' => $id_users,
        ));
        foreach ($echeances as $i => $e) {
            $S->inserer('v_echeance', array('id_vente' => $idv, 'rang' => $i + 1, 'date_prevue' => $e['date_prevue'], 'montant' => $e['montant']));
        }

        $this->historiser($idv, 'vente', $idv, 'creation', null, array(
            'montant_catalogue' => $catalogue, 'remise' => $remise, 'echeances' => $echeances,
        ), $id_users, $origine);
        $codes = array('id_vente' => $idv, 'modalite' => count($echeances) > 1 ? 'fractionne' : 'comptant', 'nb_echeances' => count($echeances));
        $Contact->tracer($idc, $id_users, 'vente_create', $codes, array(
            'type' => 'vente', 'module' => 'ventes', 'objet_type' => 'vente', 'objet_id' => $idv,
            'details' => array('action' => 'creation', 'nb_echeances' => count($echeances)), 'origine' => $origine,
        ));

        if ($encaissement !== null) {
            $vente = $this->charger($idv);
            $premiere = $this->premiereNonSoldee($idv);
            $ligne = array(
                'type' => 'encaissement',
                'montant' => $echeances[0]['montant'],
                'date_paiement' => $encaissement['date_paiement'],
                'code_moyen' => $encaissement['code_moyen'],
                'reference' => isset($encaissement['reference']) ? $encaissement['reference'] : null,
                'id_echeance' => (int) $premiere->id_echeance,
            );
            foreach (array('stripe_id', 'stripe_payment_intent_id', 'frais') as $champ) {
                if (isset($encaissement[$champ])) {
                    $ligne[$champ] = $encaissement[$champ];
                }
            }
            $this->inscrire($vente, $ligne, $id_users, $origine);
        }

        $apres = $this->recalculer($idv);
        $avertissements = $this->automatismes($idc, $idv, array('encaisse' => 0, 'rembourse' => 0, 'statut' => 'en_attente'), $apres, $id_users);
        $SQL->commit();

        return array('id_vente' => $idv, 'avertissements' => $avertissements, 'deja' => false);
    }

    /**
     * Enregistre une écriture (encaissement, échec, impayé, remboursement) sur une vente.
     * $ligne : champs de specPaiement(). Les règles sont relues sous le verrou du dossier.
     * Retourne array(id_paiement, avertissements, deja).
     */
    public function ecrire($id_vente, $ligne, $cle, $id_users, $origine = 'utilisateur')
    {
        global $SQL, $Mysql, $Response;

        $vente = $this->charger($id_vente);
        $this->verrouiller($vente->id_contact);

        if ($cle !== null) {
            $deja = $Mysql->fetchOne("SELECT id_paiement FROM v_paiement WHERE cle_saisie = ?", array($cle), 's');
            if ($deja !== null) {
                $SQL->commit();

                return array('id_paiement' => (int) $deja->id_paiement, 'avertissements' => array(), 'deja' => true);
            }
        }
        // Même garde pour un fait Stripe signalé deux fois : l'identifiant Stripe de l'écriture ne sert qu'une fois
        if (isset($ligne['stripe_id'])) {
            $deja = $Mysql->fetchOne("SELECT id_paiement FROM v_paiement WHERE stripe_id = ?", array($ligne['stripe_id']), 's');
            if ($deja !== null) {
                $SQL->commit();

                return array('id_paiement' => (int) $deja->id_paiement, 'avertissements' => array(), 'deja' => true);
            }
        }

        // État relu sous le verrou
        $vente = $this->charger($id_vente);
        $avant = array('encaisse' => (int) $vente->encaisse, 'rembourse' => (int) $vente->rembourse, 'statut' => $vente->statut);
        $type = $ligne['type'];
        $annulee = $vente->date_annulation !== null;
        $date = $ligne['date_paiement'];

        if ($date < $vente->date_vente) {
            $Response->validationError("La date ne peut pas précéder celle de la vente.");
        }
        if ($annulee && in_array($type, array('encaissement', 'echec'), true)) {
            $Response->validationError("Cette vente est annulée : seuls un remboursement, un impayé ou l'annulation d'une écriture restent possibles.");
        }

        if ($type === 'impaye') {
            // Un impayé rejette un encaissement précis, pour son montant, une seule fois
            $idOrigine = isset($ligne['id_paiement_origine']) ? (int) $ligne['id_paiement_origine'] : 0;
            $origineLigne = $idOrigine > 0 ? $Mysql->fetchOne(
                "SELECT id_paiement, montant, date_paiement, code_moyen, id_echeance FROM v_paiement
                 WHERE id_paiement = ? AND id_vente = ? AND type = 'encaissement' AND date_annulation IS NULL",
                array($idOrigine, (int) $vente->id_vente),
                'ii'
            ) : null;
            if ($origineLigne === null) {
                $Response->validationError("Indiquez l'encaissement qui a été rejeté.");
            }
            if ($Mysql->fetchOne("SELECT 1 AS x FROM v_paiement WHERE id_paiement_origine = ? AND type = 'impaye' AND date_annulation IS NULL", array($idOrigine), 'i') !== null) {
                $Response->validationError("Cet encaissement est déjà noté comme impayé.");
            }
            if ($date < $origineLigne->date_paiement) {
                $Response->validationError("Le rejet ne peut pas précéder l'encaissement.");
            }
            if ((int) $vente->encaisse - (int) $origineLigne->montant < (int) $vente->rembourse) {
                $Response->validationError("Un remboursement a été enregistré sur cette vente : annulez-le avant de noter cet impayé.");
            }
            $ligne['montant'] = (int) $origineLigne->montant;
            $ligne['code_moyen'] = isset($ligne['code_moyen']) ? $ligne['code_moyen'] : $origineLigne->code_moyen;
            $ligne['id_echeance'] = $origineLigne->id_echeance === null ? null : (int) $origineLigne->id_echeance;
        } else {
            unset($ligne['id_paiement_origine']);
            if (!isset($ligne['montant'])) {
                $Response->validationError("Le champ « montant » est obligatoire.");
            }
            $montant = (int) $ligne['montant'];

            if ($type === 'remboursement') {
                $remboursable = (int) $vente->encaisse - (int) $vente->rembourse;
                if ($montant > $remboursable) {
                    $Response->validationError($remboursable > 0
                        ? "Le remboursement dépasse ce qui a été encaissé et non remboursé (" . self::euros($remboursable) . ")."
                        : "Rien n'est remboursable sur cette vente.");
                }
                if (!isset($ligne['motif'])) {
                    $Response->validationError("Le motif du remboursement est obligatoire.");
                }
                $ligne['id_echeance'] = null;
            } else {
                $reste = (int) $vente->reste_du;
                if ($reste <= 0) {
                    $Response->validationError("Cette vente est soldée : il ne reste rien à encaisser.");
                }
                if ($montant > $reste) {
                    $Response->validationError("Le montant dépasse le reste dû de " . self::euros($montant - $reste) . " (reste dû : " . self::euros($reste) . ").");
                }
                $premiere = $this->premiereNonSoldee($vente->id_vente);
                $ligne['id_echeance'] = $premiere === null ? null : (int) $premiere->id_echeance;
                if ($type === 'echec') {
                    unset($ligne['frais']);
                }
            }
        }

        $ligne['cle_saisie'] = $cle;
        $idp = $this->inscrire($vente, $ligne, $id_users, $origine);

        // Un remboursement total défait la vente : les échéances restantes ne sont plus dues
        if ($type === 'remboursement' && !$annulee && (int) $vente->rembourse + (int) $ligne['montant'] >= (int) $vente->encaisse) {
            $this->fermer($vente->id_vente, self::MOTIF_REMBOURSEMENT_TOTAL, $id_users);
            $this->historiser($vente->id_vente, 'vente', $vente->id_vente, 'annulation', array('statut' => $vente->statut), array('motif' => self::MOTIF_REMBOURSEMENT_TOTAL), $id_users, $origine);
        }

        $apres = $this->recalculer($vente->id_vente);
        $avertissements = $this->automatismes($vente->id_contact, $vente->id_vente, $avant, $apres, $id_users);
        $SQL->commit();

        return array('id_paiement' => $idp, 'avertissements' => $avertissements, 'deja' => false);
    }

    /**
     * Annule une écriture saisie par erreur : elle reste lisible, avec son motif, et sort de toutes les sommes.
     * Sans effet si elle est déjà annulée. Retourne les avertissements.
     */
    public function annulerEcriture($paiement, $motif, $id_users)
    {
        global $SQL, $Mysql, $Contact, $Response;

        $vente = $this->charger($paiement->id_vente);
        $this->verrouiller($vente->id_contact);

        $p = $Mysql->fetchOne("SELECT * FROM v_paiement WHERE id_paiement = ?", array((int) $paiement->id_paiement), 'i');
        if ($p->date_annulation !== null) {
            $SQL->commit();

            return array();
        }
        if ($p->source !== 'manuel') {
            $Response->validationError("Une écriture reçue de Stripe ne s'annule pas ici : elle se corrige par un remboursement ou un impayé.");
        }
        $vente = $this->charger($p->id_vente);
        $avant = array('encaisse' => (int) $vente->encaisse, 'rembourse' => (int) $vente->rembourse, 'statut' => $vente->statut);

        if ($p->type === 'encaissement') {
            $rejete = $Mysql->fetchOne("SELECT 1 AS x FROM v_paiement WHERE id_paiement_origine = ? AND type = 'impaye' AND date_annulation IS NULL", array((int) $p->id_paiement), 'i') !== null;
            if ($rejete) {
                $Response->validationError("Cet encaissement porte un impayé : annulez d'abord l'impayé.");
            }
            if ((int) $vente->encaisse - (int) $p->montant < (int) $vente->rembourse) {
                $Response->validationError("Un remboursement a été enregistré sur cette vente : annulez-le avant cet encaissement.");
            }
        }
        if ($p->type === 'impaye' && $vente->date_annulation === null && (int) $vente->encaisse + (int) $p->montant > (int) $vente->montant) {
            $Response->validationError("Annuler cet impayé ferait dépasser le total de la vente : un autre paiement a été enregistré depuis.");
        }

        $Mysql->execute(
            "UPDATE v_paiement SET date_annulation = NOW(), motif_annulation = ?, id_users_annulation = ?, date_modif = NOW() WHERE id_paiement = ?",
            array($motif, $id_users, (int) $p->id_paiement),
            'sii'
        );
        $this->historiser($p->id_vente, 'paiement', $p->id_paiement, 'annulation', array('type' => $p->type, 'montant' => (int) $p->montant, 'date_paiement' => $p->date_paiement), null, $id_users);

        // Un remboursement total saisi par erreur avait fermé la vente : elle reprend son cours, échéances comprises
        if ($p->type === 'remboursement' && $vente->date_annulation !== null && $vente->motif_annulation === self::MOTIF_REMBOURSEMENT_TOTAL) {
            $Mysql->execute("UPDATE v_vente SET date_annulation = NULL, motif_annulation = NULL, id_users_annulation = NULL, date_modif = NOW() WHERE id_vente = ?", array((int) $p->id_vente), 'i');
            $Mysql->execute("UPDATE v_echeance SET date_annulation = NULL, date_modif = NOW() WHERE id_vente = ? AND date_annulation IS NOT NULL", array((int) $p->id_vente), 'i');
            $this->historiser($p->id_vente, 'vente', $p->id_vente, 'reouverture', array('motif' => self::MOTIF_REMBOURSEMENT_TOTAL), null, $id_users);
        }
        $codes = array('id_vente' => (int) $p->id_vente, 'id_paiement' => (int) $p->id_paiement, 'type' => $p->type);
        $Contact->tracer($vente->id_contact, $id_users, 'paiement_annulation', $codes, array(
            'type' => 'paiement', 'module' => 'paiements', 'objet_type' => 'paiement', 'objet_id' => (int) $p->id_paiement,
            'details' => array('action' => 'annulation', 'type' => $p->type, 'id_vente' => (int) $p->id_vente),
        ));

        $apres = $this->recalculer($p->id_vente);
        $avertissements = $this->automatismes($vente->id_contact, $p->id_vente, $avant, $apres, $id_users, true);
        $SQL->commit();

        return $avertissements;
    }

    /** Corrige les champs descriptifs d'une écriture (moyen, référence, frais, commentaire). Retourne les champs modifiés. */
    public function corrigerEcriture($paiement, $data, $id_users, $origine = 'utilisateur')
    {
        global $SQL, $S, $Contact;

        $vente = $this->charger($paiement->id_vente);
        $avant = array();
        foreach (array_keys($data) as $champ) {
            $avant[$champ] = $paiement->$champ === null || $champ !== 'frais' ? $paiement->$champ : (int) $paiement->$champ;
        }
        $modifies = $S->differences($paiement, $data);
        if (count($modifies) > 0) {
            $SQL->begin_transaction();
            $S->mettreAJour('v_paiement', 'id_paiement', (int) $paiement->id_paiement, $data);
            $this->historiser($paiement->id_vente, 'paiement', $paiement->id_paiement, 'correction', array_intersect_key($avant, $data), $data, $id_users, $origine);
            $Contact->tracer($vente->id_contact, $id_users, 'paiement_update', array('id_vente' => (int) $paiement->id_vente, 'id_paiement' => (int) $paiement->id_paiement, 'champs' => $modifies));
            $SQL->commit();
        }

        return $modifies;
    }

    /** Corrige les champs descriptifs d'une vente (date, moyen prévu, commentaire). Retourne les champs modifiés. */
    public function modifier($vente, $data, $id_users)
    {
        global $SQL, $Mysql, $S, $Contact, $Response;

        if (isset($data['date_vente'])) {
            $premier = $Mysql->fetchOne("SELECT MIN(date_paiement) AS d FROM v_paiement WHERE id_vente = ? AND date_annulation IS NULL", array((int) $vente->id_vente), 'i')->d;
            if ($premier !== null && $data['date_vente'] > $premier) {
                $Response->validationError("La date de la vente ne peut pas suivre celle de son premier paiement.");
            }
        }
        $avant = array();
        foreach (array_keys($data) as $champ) {
            $avant[$champ] = $vente->$champ;
        }
        $modifies = $S->differences($vente, $data);
        if (count($modifies) > 0) {
            $SQL->begin_transaction();
            $S->mettreAJour('v_vente', 'id_vente', (int) $vente->id_vente, $data);
            $this->historiser($vente->id_vente, 'vente', $vente->id_vente, 'modification', array_intersect_key($avant, $data), $data, $id_users);
            $Contact->tracer($vente->id_contact, $id_users, 'vente_update', array('id_vente' => (int) $vente->id_vente, 'champs' => $modifies), array(
                'type' => 'vente', 'module' => 'ventes', 'objet_type' => 'vente', 'objet_id' => (int) $vente->id_vente,
                'details' => array('action' => 'modification', 'champs' => $modifies),
            ));
            $SQL->commit();
        }

        return $modifies;
    }

    /**
     * Révise l'échéancier d'une vente : les échéances soldées sont figées, les autres sont remplacées par $echeances.
     * $remise (ou null pour la garder) et $motif_remise permettent une remise accordée après coup.
     * La somme des échéances soldées et des nouvelles doit être égale au nouveau total, lui-même au moins égal à l'encaissé.
     */
    public function reviserEcheancier($id_vente, $echeances, $remise, $motif_remise, $id_users)
    {
        global $SQL, $Mysql, $S, $Contact, $Response;

        $vente = $this->charger($id_vente);
        $this->verrouiller($vente->id_contact);
        $vente = $this->charger($id_vente);
        $avant = array('encaisse' => (int) $vente->encaisse, 'rembourse' => (int) $vente->rembourse, 'statut' => $vente->statut);

        if ($vente->date_annulation !== null) {
            $Response->validationError("Cette vente est annulée : son échéancier ne se modifie plus.");
        }
        $this->exigerSansPrelevement($id_vente);
        $catalogue = (int) $vente->montant_catalogue;
        $nouvelleRemise = $remise === null ? (int) $vente->remise : (int) $remise;
        if ($nouvelleRemise >= $catalogue) {
            $Response->validationError("La remise doit rester inférieure au prix de l'offre (" . self::euros($catalogue) . ").");
        }
        $du = $catalogue - $nouvelleRemise;
        if ($du < (int) $vente->encaisse) {
            $Response->validationError("Le total ne peut pas descendre sous ce qui est déjà encaissé (" . self::euros($vente->encaisse) . ").");
        }

        $actuelles = $Mysql->fetchAll(
            "SELECT id_echeance, rang, date_prevue, montant, montant_paye FROM v_echeance WHERE id_vente = ? AND date_annulation IS NULL ORDER BY rang, id_echeance",
            array((int) $id_vente),
            'i'
        );
        $soldees = 0;
        $rang = 0;
        $ancien = array();
        foreach ($actuelles as $e) {
            $ancien[] = array('date_prevue' => $e->date_prevue, 'montant' => (int) $e->montant);
            if ((int) $e->montant_paye >= (int) $e->montant) {
                $soldees += (int) $e->montant;
                $rang = max($rang, (int) $e->rang);
            }
        }
        if ($soldees + array_sum(array_column($echeances, 'montant')) !== $du) {
            $Response->validationError("La somme des échéances doit être égale au total de la vente (" . self::euros($du) . ", dont " . self::euros($soldees) . " déjà soldés).");
        }
        if (count($echeances) > 0 && $echeances[0]['date_prevue'] < $vente->date_vente) {
            $Response->validationError("Une échéance ne peut pas précéder la date de la vente.");
        }

        // Les échéances non soldées sont des prévisions : elles sont remplacées, l'ancien échéancier reste dans l'historique
        $Mysql->execute("DELETE FROM v_echeance WHERE id_vente = ? AND date_annulation IS NULL AND montant_paye < montant", array((int) $id_vente), 'i');
        // Une page de paiement ouverte porte l'ancien montant : elle ne doit plus servir
        $this->fermerSessions($id_vente);
        if ($nouvelleRemise !== (int) $vente->remise) {
            $Mysql->execute(
                "UPDATE v_vente SET remise = ?, motif_remise = ?, date_modif = NOW() WHERE id_vente = ?",
                array($nouvelleRemise, $nouvelleRemise > 0 ? $motif_remise : null, (int) $id_vente),
                'isi'
            );
        }
        $nouveau = array();
        foreach ($actuelles as $e) {
            if ((int) $e->montant_paye >= (int) $e->montant) {
                $nouveau[] = array('date_prevue' => $e->date_prevue, 'montant' => (int) $e->montant);
            }
        }
        foreach ($echeances as $e) {
            $rang++;
            $S->inserer('v_echeance', array('id_vente' => (int) $id_vente, 'rang' => $rang, 'date_prevue' => $e['date_prevue'], 'montant' => $e['montant']));
            $nouveau[] = $e;
        }

        $this->historiser($id_vente, 'echeancier', $id_vente, 'revision', array('remise' => (int) $vente->remise, 'echeances' => $ancien), array('remise' => $nouvelleRemise, 'echeances' => $nouveau), $id_users);
        $champs = $nouvelleRemise !== (int) $vente->remise ? array('echeances', 'remise') : array('echeances');
        $Contact->tracer($vente->id_contact, $id_users, 'vente_echeancier', array('id_vente' => (int) $id_vente, 'champs' => $champs, 'nb_echeances' => count($nouveau)), array(
            'type' => 'vente', 'module' => 'ventes', 'objet_type' => 'vente', 'objet_id' => (int) $id_vente,
            'details' => array('action' => 'echeancier', 'champs' => $champs),
        ));

        $apres = $this->recalculer($id_vente);
        $avertissements = $this->automatismes($vente->id_contact, $id_vente, $avant, $apres, $id_users);
        $SQL->commit();

        return $avertissements;
    }

    /**
     * Annule une vente : ses échéances non soldées ne sont plus dues, l'encaissé reste acquis tant qu'il n'est pas remboursé.
     * $remboursement (array montant, date_paiement, code_moyen, reference) : écriture de remboursement dans la même transaction.
     * Sans effet si la vente est déjà annulée.
     */
    public function annuler($id_vente, $motif, $remboursement, $id_users)
    {
        global $SQL, $Contact, $Response;

        $vente = $this->charger($id_vente);
        $this->verrouiller($vente->id_contact);
        $vente = $this->charger($id_vente);
        if ($vente->date_annulation !== null) {
            $SQL->commit();

            return array();
        }
        $avant = array('encaisse' => (int) $vente->encaisse, 'rembourse' => (int) $vente->rembourse, 'statut' => $vente->statut);
        $this->exigerSansPrelevement($id_vente);

        if ($remboursement !== null) {
            $remboursable = (int) $vente->encaisse - (int) $vente->rembourse;
            if ($remboursement['montant'] > $remboursable) {
                $Response->validationError($remboursable > 0
                    ? "Le remboursement dépasse ce qui a été encaissé et non remboursé (" . self::euros($remboursable) . ")."
                    : "Rien n'est remboursable sur cette vente.");
            }
            if ($remboursement['date_paiement'] < $vente->date_vente) {
                $Response->validationError("La date du remboursement ne peut pas précéder celle de la vente.");
            }
        }

        $this->fermer($id_vente, $motif, $id_users);
        $this->historiser($id_vente, 'vente', $id_vente, 'annulation', array('statut' => $vente->statut), array('motif' => $motif), $id_users);
        $Contact->tracer($vente->id_contact, $id_users, 'vente_annulation', array('id_vente' => (int) $id_vente), array(
            'type' => 'vente', 'module' => 'ventes', 'objet_type' => 'vente', 'objet_id' => (int) $id_vente,
            'details' => array('action' => 'annulation'),
        ));
        if ($remboursement !== null) {
            $this->inscrire($vente, array(
                'type' => 'remboursement',
                'montant' => (int) $remboursement['montant'],
                'date_paiement' => $remboursement['date_paiement'],
                'code_moyen' => $remboursement['code_moyen'],
                'reference' => $remboursement['reference'],
                'motif' => $motif,
                'id_echeance' => null,
            ), $id_users, 'utilisateur');
        }

        $apres = $this->recalculer($id_vente);
        $avertissements = $this->automatismes($vente->id_contact, $id_vente, $avant, $apres, $id_users);
        $SQL->commit();

        return $avertissements;
    }
}

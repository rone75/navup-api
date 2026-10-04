<?php

/**
 * Pilotage : les chiffres du tableau de bord (CDC §3) et des statistiques (CDC §17, étape 7a).
 *
 * Aucune somme n'est écrite ici : les montants sortent des méthodes de totaux de Vente, celles des listes,
 * avec les mêmes conditions que la liste qu'ouvre le chiffre. Les dossiers se comptent avec les conditions de
 * v1/contacts/ (statuts lisibles, classés à part, Contact::SQL_ARRIVEE pour la période). Un chiffre et sa liste
 * ne peuvent donc pas diverger (CDC §27).
 *
 * Un bloc par droit, jamais l'union : chaque méthode rend null quand l'utilisateur n'a pas le droit du bloc.
 */
class Pilotage
{
    const PERIODES = array('jour', '7j', 'mois', 'trimestre', 'annee', 'perso');

    // En dessous de ce nombre de dossiers, un pourcentage de conversion ne veut rien dire : il n'est pas servi
    const SEUIL_TAUX = 10;

    // Ventes « remboursées et annulées » : la vue du même nom dans la liste des ventes
    const STATUTS_DEFAITES = array('rembourse_partiellement', 'rembourse', 'annule');

    // PÉRIODE ########################################################

    /**
     * Période demandée (?periode=jour|7j|mois|trimestre|annee|perso&du=&au=), le mois par défaut.
     * Périodes civiles, à ce jour : « mois » va du 1er du mois à aujourd'hui, « trimestre » du premier jour du
     * trimestre civil, « annee » du 1er janvier ; « 7j » compte aujourd'hui. « perso » exige deux dates valides, dans l'ordre.
     * $aujourdhui vient de PHP (AAAA-MM-JJ). Retourne array(code, du, au) ; 400 sinon.
     */
    public function resoudrePeriode($aujourdhui)
    {
        global $S, $Response;

        $code = 'mois';
        if (isset($_GET['periode']) && $_GET['periode'] !== '') {
            if (!is_string($_GET['periode']) || !in_array($_GET['periode'], self::PERIODES, true)) {
                $Response->validationError("Période inconnue.");
            }
            $code = $_GET['periode'];
        }

        if ($code === 'perso') {
            $du = $S->dateFiltre('du');
            $au = $S->dateFiltre('au');
            if ($du === null || $au === null) {
                $Response->validationError("Indiquez les deux dates de la période.");
            }
            if ($du > $au) {
                $Response->validationError("La période se termine avant de commencer : vérifiez les deux dates.");
            }

            return array('code' => $code, 'du' => $du, 'au' => $au);
        }

        $jour = new DateTimeImmutable($aujourdhui);
        $annee = (int) $jour->format('Y');
        $mois = (int) $jour->format('n');
        if ($code === 'jour') {
            $debut = $jour;
        } elseif ($code === '7j') {
            $debut = $jour->modify('-6 days');
        } elseif ($code === 'trimestre') {
            $debut = $jour->setDate($annee, $mois - (($mois - 1) % 3), 1);
        } elseif ($code === 'annee') {
            $debut = $jour->setDate($annee, 1, 1);
        } else {
            $debut = $jour->setDate($annee, $mois, 1);
        }

        return array('code' => $code, 'du' => $debut->format('Y-m-d'), 'au' => $aujourdhui);
    }

    // DOSSIERS #######################################################

    /**
     * Dossiers lisibles comptés par statut, les classés sans suite à part. Avec $periode : ceux qui sont arrivés
     * pendant la période seulement. Retourne array(groupe => array(statuts => array(statut => nb), total, classes)),
     * `total` étant le nombre de dossiers non classés du groupe ; null si aucun groupe n'est lisible.
     */
    private function compter($user, $periode = null)
    {
        global $Mysql, $Contact, $U;

        $lisibles = $Contact->conditionLisibles($user);
        if ($lisibles === null) {
            return null;
        }
        $groupes = array();
        foreach (Contact::GROUPES as $groupe => $statuts) {
            if ($U->can($user, $groupe, 'L')) {
                $groupes[$groupe] = array('statuts' => array_fill_keys($statuts, 0), 'total' => 0, 'classes' => 0);
            }
        }

        $where = $lisibles[0];
        $params = $lisibles[1];
        if ($periode !== null) {
            $where .= " AND " . Contact::SQL_ARRIVEE . " >= ? AND " . Contact::SQL_ARRIVEE . " <= ?";
            $params[] = $periode['du'];
            $params[] = $periode['au'];
        }
        $rows = $Mysql->fetchAll(
            "SELECT c.statut, (c.date_archivage IS NOT NULL) AS classe, COUNT(*) AS nb
             FROM d_contact c WHERE $where GROUP BY c.statut, classe",
            $params
        );
        foreach ($rows as $row) {
            $groupe = Contact::groupeDe($row->statut);
            if ((int) $row->classe === 1) {
                $groupes[$groupe]['classes'] += (int) $row->nb;
            } else {
                $groupes[$groupe]['statuts'][$row->statut] = (int) $row->nb;
                $groupes[$groupe]['total'] += (int) $row->nb;
            }
        }

        return $groupes;
    }

    /**
     * À ce jour : les programmes en cours, et parmi eux ceux qui s'achèvent bientôt. Chaque nombre est le total de la
     * liste des clients filtrée par `programme` (v1/contacts/), avec la même condition (Compte::conditionVue) :
     * clients non classés seulement. Null sans le droit sur les clients.
     */
    public function programmes($user, $aujourdhui)
    {
        global $Mysql, $U;

        if (!$U->can($user, 'clients', 'L')) {
            return null;
        }
        $statuts = Contact::GROUPES['clients'];
        $base = "c.statut IN (" . implode(', ', array_fill(0, count($statuts), '?')) . ") AND c.date_archivage IS NULL AND ";
        $nombres = array();
        foreach (array('en_cours', 'fin_proche') as $vue) {
            $cond = Compte::conditionVue($vue, $aujourdhui);
            $nombres[$vue] = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM d_contact c WHERE " . $base . $cond[0], array_merge($statuts, $cond[1]))->nb;
        }

        return $nombres;
    }

    /** À ce jour : les dossiers de chaque groupe lisible, par statut (chaque nombre est le total d'une liste de dossiers). */
    public function dossiers($user)
    {
        return $this->compter($user);
    }

    /**
     * Nouveaux dossiers de la période (premier contact, à défaut création) : prospects, clients, classés sans suite.
     * Trois nombres et non un seul : chacun est le total d'une liste. `total` est la cohorte de la conversion.
     */
    public function nouveaux($user, $periode)
    {
        $groupes = $this->compter($user, $periode);
        if ($groupes === null) {
            return null;
        }
        $out = array();
        $classes = array();
        $total = 0;
        foreach ($groupes as $groupe => $g) {
            $out[$groupe] = $g['total'];
            $classes[$groupe] = $g['classes'];
            $total += $g['total'] + $g['classes'];
        }
        $out['classes'] = $classes;
        $out['total'] = $total;

        return $out;
    }

    /**
     * Parcours des nouveaux dossiers, par cohorte : parmi les dossiers arrivés pendant la période (classés compris),
     * combien ont, à ce jour, pris un rendez-vous (un créneau a été fixé, quel que soit son sort), une vente non annulée,
     * le statut client actif ou programme terminé. Les étapes ne sont pas emboîtées : une vente sans rendez-vous existe.
     * Chaque étape n'est servie qu'avec le droit de lire ce qu'elle compte ; `taux` (pourcentage entier) est null
     * sous SEUIL_TAUX dossiers.
     */
    public function conversion($user, $periode)
    {
        global $Mysql, $Contact, $U;

        $lisibles = $Contact->conditionLisibles($user);
        if ($lisibles === null) {
            return null;
        }
        $t = $Mysql->fetchOne(
            "SELECT COUNT(*) AS cohorte,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM r_rdv r WHERE r.id_contact = c.id_contact AND r.date_debut IS NOT NULL)), 0) AS rdv,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM v_vente v WHERE v.id_contact = c.id_contact AND v.date_annulation IS NULL)), 0) AS vente,
                    COALESCE(SUM(c.statut IN ('client_actif', 'programme_termine')), 0) AS actifs
             FROM d_contact c
             WHERE " . $lisibles[0] . " AND " . Contact::SQL_ARRIVEE . " >= ? AND " . Contact::SQL_ARRIVEE . " <= ?",
            array_merge($lisibles[1], array($periode['du'], $periode['au']))
        );

        $cohorte = (int) $t->cohorte;
        $out = array('cohorte' => $cohorte);
        foreach (array('rdv' => 'rendez_vous', 'vente' => 'ventes', 'actifs' => 'clients') as $etape => $module) {
            if ($U->can($user, $module, 'L')) {
                $nb = (int) $t->$etape;
                $out[$etape] = array('nb' => $nb, 'taux' => $cohorte >= self::SEUIL_TAUX ? (int) round($nb * 100 / $cohorte) : null);
            }
        }

        return $out;
    }

    // FINANCES #######################################################

    /**
     * Ventes datées de la période, telles que la liste « Toutes » les compte (une vente annulée y reste, pour ce qu'elle
     * a encaissé) : nombre et vendu, dont comptant, dont en plusieurs fois, et le nombre de ventes remboursées ou annulées.
     */
    public function ventes($user, $periode)
    {
        global $Vente, $U;

        $lisibles = $Vente->conditionDossiersLisibles($user);
        if ($lisibles === null || !$U->can($user, 'ventes', 'L')) {
            return null;
        }
        $where = $lisibles[0] . " AND v.date_vente >= ? AND v.date_vente <= ?";
        $params = array_merge($lisibles[1], array($periode['du'], $periode['au']));

        $toutes = $Vente->totauxVentes($where, $params);
        $out = array('nb' => $toutes['nb'], 'vendu' => $toutes['vendu']);
        foreach (array('comptant', 'fractionne') as $modalite) {
            $t = $Vente->totauxVentes($where . " AND v.modalite = ?", array_merge($params, array($modalite)));
            $out[$modalite] = array('nb' => $t['nb'], 'vendu' => $t['vendu']);
        }
        $in = implode(', ', array_fill(0, count(self::STATUTS_DEFAITES), '?'));
        $t = $Vente->totauxVentes($where . " AND v.statut IN ($in)", array_merge($params, self::STATUTS_DEFAITES));
        $out['defaites'] = array('nb' => $t['nb']);

        return $out;
    }

    /**
     * Écritures datées de la période, hors écritures annulées, telles que le journal des paiements les totalise :
     * encaissé (un impayé se retranche), remboursé, frais connus, net, et le nombre d'encaissements, de remboursements
     * et d'impayés qui font ces sommes (`nb` compte toutes les écritures du journal, tentatives échouées comprises).
     * Un paiement reçu ce mois sur une vente du mois dernier compte dans ce mois.
     */
    public function ecritures($user, $periode)
    {
        global $Vente, $U;

        $lisibles = $Vente->conditionDossiersLisibles($user);
        if ($lisibles === null || !$U->can($user, 'paiements', 'L')) {
            return null;
        }

        return $Vente->totauxJournal(
            $lisibles[0] . " AND p.date_annulation IS NULL AND p.date_paiement >= ? AND p.date_paiement <= ?",
            array_merge($lisibles[1], array($periode['du'], $periode['au']))
        );
    }

    /**
     * Reste à encaisser à ce jour, toutes ventes en cours (hors période) : les échéances non soldées de la vue
     * « À encaisser » des paiements, dont la part et le nombre en retard au jour $aujourdhui.
     */
    public function aEncaisser($user, $aujourdhui)
    {
        global $Vente, $U;

        $lisibles = $Vente->conditionDossiersLisibles($user);
        if ($lisibles === null || !$U->can($user, 'paiements', 'L')) {
            return null;
        }
        $t = $Vente->totauxEcheances(
            "e.date_annulation IS NULL AND " . $lisibles[0] . " AND e.montant_paye < e.montant AND v.date_annulation IS NULL",
            $lisibles[1],
            $aujourdhui
        );

        return array('nb' => $t['nb'], 'montant' => $t['a_encaisser'], 'en_retard' => $t['en_retard'], 'nb_retard' => $t['nb_retard']);
    }

    // STATISTIQUES (étape 7a) ########################################

    // Sous ce nombre de dossiers, une catégorie de problématique s'écrit « moins de 3 » : une famille ne se reconnaît pas dans un chiffre
    const SEUIL_PROBLEMATIQUE = 3;

    /** Période de même durée qui précède immédiatement la période (comparaison en toutes lettres). */
    public static function periodePrecedente($periode)
    {
        $du = new DateTimeImmutable($periode['du']);
        $jours = (int) $du->diff(new DateTimeImmutable($periode['au']))->days + 1;

        return array('du' => $du->modify('-' . $jours . ' days')->format('Y-m-d'), 'au' => $du->modify('-1 day')->format('Y-m-d'));
    }

    /** Part entière (pour cent) d'un nombre dans un total : la longueur d'une barre, calculée ici et non dans le front. */
    private static function part($n, $total)
    {
        return (int) $total > 0 ? (int) round(((int) $n) * 100 / (int) $total) : 0;
    }

    /** Liste de référence (p_origine, p_moyen_paiement, p_categorie_problematique) : code => libellé, dans l'ordre. */
    private static function reference($table)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll("SELECT code, libelle FROM $table ORDER BY ordre, libelle") as $r) {
            $out[$r->code] = $r->libelle;
        }

        return $out;
    }

    /**
     * Finances de la période : les ventes datées de la période (et leur panier moyen), les écritures datées de la
     * période, et leurs répartitions. Par modalité : la liste des ventes filtrée par `modalite` ; par moyen : le journal
     * des paiements filtré par `moyen` (encaissé, impayés retranchés, et le nombre d'encaissements). null sans aucun
     * des deux droits ; chaque partie n'est servie qu'avec le sien.
     */
    public function finances($user, $periode)
    {
        global $Vente;

        $ventes = $this->ventes($user, $periode);
        $ecritures = $this->ecritures($user, $periode);
        if ($ventes === null && $ecritures === null) {
            return null;
        }
        $out = array();
        if ($ventes !== null) {
            $out['ventes'] = $ventes;
            $out['panier_moyen'] = $ventes['nb'] > 0 ? (int) round($ventes['vendu'] / $ventes['nb']) : null;
            $out['modalites'] = array();
            foreach (array('comptant' => 'Comptant', 'fractionne' => 'En plusieurs fois') as $code => $libelle) {
                $out['modalites'][] = array('code' => $code, 'libelle' => $libelle, 'nb' => $ventes[$code]['nb'], 'vendu' => $ventes[$code]['vendu'], 'part' => self::part($ventes[$code]['nb'], $ventes['nb']));
            }
        }
        if ($ecritures !== null) {
            $out['ecritures'] = $ecritures;
            $lisibles = $Vente->conditionDossiersLisibles($user);
            // La requête du journal filtré par `moyen` : l'encaissé y retranche les impayés, comme la bande du journal
            $base = $lisibles[0] . " AND p.date_annulation IS NULL AND p.date_paiement >= ? AND p.date_paiement <= ?";
            $params = array_merge($lisibles[1], array($periode['du'], $periode['au']));
            $out['moyens'] = array();
            foreach (self::reference('p_moyen_paiement') as $code => $libelle) {
                $t = $Vente->totauxJournal($base . " AND p.code_moyen = ?", array_merge($params, array($code)));
                if ($t['nb_encaissements'] > 0) {
                    $out['moyens'][] = array('code' => $code, 'libelle' => $libelle, 'nb' => $t['nb_encaissements'], 'encaisse' => $t['encaisse'], 'part' => self::part($t['encaisse'], $ecritures['encaisse']));
                }
            }
        }

        return $out;
    }

    /**
     * Origine des dossiers arrivés pendant la période et des ventes de la période. Les dossiers se comptent par groupe,
     * non classés, comme la liste des prospects ou des clients filtrée par `origine` et la période ; les ventes comme la
     * liste des ventes filtrée par `origine`. Une origine sans aucun dossier ni vente n'est pas servie ; les dossiers
     * sans origine renseignée forment une ligne sans code (aucune liste ne les isole).
     */
    public function origines($user, $periode)
    {
        global $Mysql, $Contact, $Vente, $U;

        $lisibles = $Contact->conditionLisibles($user);
        if ($lisibles === null) {
            return null;
        }
        $groupes = array();
        foreach (array_keys(Contact::GROUPES) as $groupe) {
            if ($U->can($user, $groupe, 'L')) {
                $groupes[] = $groupe;
            }
        }
        $avecVentes = $U->can($user, 'ventes', 'L') && $Vente->conditionDossiersLisibles($user) !== null;

        $rows = $Mysql->fetchAll(
            "SELECT c.code_origine, c.statut, COUNT(*) AS nb FROM d_contact c
             WHERE " . $lisibles[0] . " AND c.date_archivage IS NULL AND " . Contact::SQL_ARRIVEE . " >= ? AND " . Contact::SQL_ARRIVEE . " <= ?
             GROUP BY c.code_origine, c.statut",
            array_merge($lisibles[1], array($periode['du'], $periode['au']))
        );
        $parOrigine = array();
        $totalDossiers = 0;
        foreach ($rows as $r) {
            $code = $r->code_origine ?? '';
            $groupe = Contact::groupeDe($r->statut);
            $parOrigine[$code][$groupe] = ($parOrigine[$code][$groupe] ?? 0) + (int) $r->nb;
            $totalDossiers += (int) $r->nb;
        }

        $ventes = array();
        if ($avecVentes) {
            $vl = $Vente->conditionDossiersLisibles($user);
            $where = $vl[0] . " AND v.date_vente >= ? AND v.date_vente <= ?";
            $params = array_merge($vl[1], array($periode['du'], $periode['au']));
            foreach (array_keys(self::reference('p_origine')) as $code) {
                $t = $Vente->totauxVentes($where . " AND c.code_origine = ?", array_merge($params, array($code)));
                if ($t['nb'] > 0) {
                    $ventes[$code] = array('nb' => $t['nb'], 'vendu' => $t['vendu']);
                }
            }
        }

        $out = array();
        $libelles = self::reference('p_origine') + array('' => 'Non renseignée');
        foreach ($libelles as $code => $libelle) {
            if (!isset($parOrigine[$code]) && !isset($ventes[$code])) {
                continue;
            }
            $ligne = array('code' => $code === '' ? null : $code, 'libelle' => $libelle);
            $n = 0;
            foreach ($groupes as $groupe) {
                $ligne[$groupe] = $parOrigine[$code][$groupe] ?? 0;
                $n += $ligne[$groupe];
            }
            $ligne['dossiers'] = $n;
            $ligne['part'] = self::part($n, $totalDossiers);
            if ($avecVentes) {
                $ligne['ventes'] = $ventes[$code] ?? array('nb' => 0, 'vendu' => 0);
            }
            $out[] = $ligne;
        }

        return array('total' => $totalDossiers, 'groupes' => $groupes, 'lignes' => $out);
    }

    /**
     * Rendez-vous dont le créneau tombe dans la période, à ce jour, des dossiers lisibles. Un rendez-vous déplacé ne compte
     * qu'une fois, à son dernier créneau (les créneaux « reportés » sont écartés). `demandes` : les demandes sans créneau
     * enregistrées pendant la période. Par type, pour les rendez-vous qui tiennent ou ont eu lieu.
     */
    public function rendezVous($user, $periode)
    {
        global $Mysql, $Contact, $U;

        $lisibles = $Contact->conditionLisibles($user);
        if ($lisibles === null || !$U->can($user, 'rendez_vous', 'L')) {
            return null;
        }
        $t = $Mysql->fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(r.statut IN ('a_confirmer', 'confirme')), 0) AS prevus,
                    COALESCE(SUM(r.statut = 'effectue'), 0) AS effectues,
                    COALESCE(SUM(r.statut = 'absent'), 0) AS absents,
                    COALESCE(SUM(r.statut = 'annule'), 0) AS annules,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM r_reservation v WHERE v.id_rdv = r.id_rdv_origine)), 0) AS en_ligne
             FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
             WHERE " . $lisibles[0] . " AND r.statut <> 'reporte' AND r.date_debut >= ? AND r.date_debut < ? + INTERVAL 1 DAY",
            array_merge($lisibles[1], array($periode['du'] . ' 00:00:00', $periode['au']))
        );
        $demandes = (int) $Mysql->fetchOne(
            "SELECT COUNT(*) AS nb FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
             WHERE " . $lisibles[0] . " AND r.date_debut IS NULL AND r.date_creation >= ? AND r.date_creation < ? + INTERVAL 1 DAY",
            array_merge($lisibles[1], array($periode['du'] . ' 00:00:00', $periode['au']))
        )->nb;

        $types = array('decouverte' => 'Découverte', 'suivi' => 'Suivi', 'bilan' => 'Bilan', 'autre' => 'Autre');
        $parType = array();
        $tenus = (int) $t->total - (int) $t->annules;
        foreach ($Mysql->fetchAll(
            "SELECT r.type, COUNT(*) AS nb FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
             WHERE " . $lisibles[0] . " AND r.statut IN ('a_confirmer', 'confirme', 'effectue', 'absent') AND r.date_debut >= ? AND r.date_debut < ? + INTERVAL 1 DAY
             GROUP BY r.type",
            array_merge($lisibles[1], array($periode['du'] . ' 00:00:00', $periode['au']))
        ) as $r) {
            $parType[$r->type] = (int) $r->nb;
        }
        $lignes = array();
        foreach ($types as $code => $libelle) {
            if (isset($parType[$code])) {
                $lignes[] = array('code' => $code, 'libelle' => $libelle, 'nb' => $parType[$code], 'part' => self::part($parType[$code], $tenus));
            }
        }

        return array(
            'total' => (int) $t->total,
            'prevus' => (int) $t->prevus,
            'effectues' => (int) $t->effectues,
            'absents' => (int) $t->absents,
            'annules' => (int) $t->annules,
            'en_ligne' => (int) $t->en_ligne,
            'demandes' => $demandes,
            'types' => $lignes,
        );
    }

    /**
     * Problématiques ouvertes (non closes) des dossiers non classés, à ce jour, par catégorie : le nombre de dossiers
     * de chaque groupe, comme la liste des prospects ou des clients filtrée par `categorie`. Agrégé, sans aucun nom ;
     * sous SEUIL_PROBLEMATIQUE dossiers, le nombre n'est pas servi (`moins_de`). Droit des statistiques : un nombre
     * agrégé n'est pas une donnée familiale ; le lien vers la liste, lui, demande le droit famille (le front le sait).
     */
    public function problematiques($user)
    {
        global $Mysql, $Contact, $U;

        $lisibles = $Contact->conditionLisibles($user);
        if ($lisibles === null || !$U->can($user, 'statistiques', 'L')) {
            return null;
        }
        $rows = $Mysql->fetchAll(
            "SELECT p.code_categorie, c.statut, COUNT(DISTINCT c.id_contact) AS nb
             FROM d_contact c INNER JOIN d_problematique p ON p.id_contact = c.id_contact AND p.statut <> 'close'
             WHERE " . $lisibles[0] . " AND c.date_archivage IS NULL
             GROUP BY p.code_categorie, c.statut",
            $lisibles[1]
        );
        $par = array();
        foreach ($rows as $r) {
            $groupe = Contact::groupeDe($r->statut);
            $par[$r->code_categorie][$groupe] = ($par[$r->code_categorie][$groupe] ?? 0) + (int) $r->nb;
        }
        $dossiers = (int) $Mysql->fetchOne(
            "SELECT COUNT(DISTINCT c.id_contact) AS nb FROM d_contact c INNER JOIN d_problematique p ON p.id_contact = c.id_contact AND p.statut <> 'close'
             WHERE " . $lisibles[0] . " AND c.date_archivage IS NULL",
            $lisibles[1]
        )->nb;

        $lignes = array();
        foreach (self::reference('p_categorie_problematique') as $code => $libelle) {
            if (!isset($par[$code])) {
                continue;
            }
            $ligne = array('code' => $code, 'libelle' => $libelle);
            $n = 0;
            foreach ($par[$code] as $groupe => $nb) {
                $n += $nb;
                $ligne[$groupe] = $nb >= self::SEUIL_PROBLEMATIQUE ? $nb : null;
            }
            $masque = $n < self::SEUIL_PROBLEMATIQUE;
            $ligne['nb'] = $masque ? null : $n;
            $ligne['part'] = $masque ? null : self::part($n, $dossiers);
            $lignes[] = $ligne;
        }
        usort($lignes, function ($a, $b) {
            return ($b['nb'] ?? 0) <=> ($a['nb'] ?? 0);
        });

        return array('dossiers' => $dossiers >= self::SEUIL_PROBLEMATIQUE ? $dossiers : null, 'seuil' => self::SEUIL_PROBLEMATIQUE, 'lignes' => $lignes);
    }

    /**
     * Programme, à ce jour : les programmes en cours (tableau de bord) et leur répartition par semaine, puis, sur la
     * période, ce que les parents ont réellement fait dans l'appli : sujets marqués « terminé » (geste du parent,
     * jamais déduit d'une écoute ou d'une connexion) et nombre de parents qui en ont terminé au moins un.
     */
    public function progression($user, $periode, $aujourdhui)
    {
        global $Mysql;

        $programmes = $this->programmes($user, $aujourdhui);
        if ($programmes === null) {
            return null;
        }
        $statuts = Contact::GROUPES['clients'];
        $in = implode(', ', array_fill(0, count($statuts), '?'));
        $cond = Compte::conditionVue('en_cours', $aujourdhui);
        $semaines = array();
        foreach ($Mysql->fetchAll(
            // La semaine en cours est la dernière débloquée (vue a_semaine, comme Compte::programme() et l'appli des parents)
            "SELECT s.semaine, COUNT(*) AS nb FROM d_contact c INNER JOIN a_compte a ON a.id_contact = c.id_contact
             INNER JOIN (SELECT id_compte, MAX(numero) AS semaine FROM a_semaine WHERE date_deblocage <= ? GROUP BY id_compte) s ON s.id_compte = a.id_compte
             WHERE c.statut IN ($in) AND c.date_archivage IS NULL AND " . $cond[0] . "
             GROUP BY s.semaine ORDER BY s.semaine",
            array_merge(array($aujourdhui), $statuts, $cond[1])
        ) as $r) {
            $semaines[] = array('semaine' => (int) $r->semaine, 'nb' => (int) $r->nb, 'part' => self::part($r->nb, $programmes['en_cours']));
        }
        $t = $Mysql->fetchOne(
            "SELECT COUNT(*) AS sujets, COUNT(DISTINCT p.id_compte) AS parents
             FROM e_progression p INNER JOIN a_compte a ON a.id_compte = p.id_compte INNER JOIN d_contact c ON c.id_contact = a.id_contact
             WHERE c.statut IN ($in) AND p.date_termine >= ? AND p.date_termine < ? + INTERVAL 1 DAY",
            array_merge($statuts, array($periode['du'] . ' 00:00:00', $periode['au']))
        );

        return array(
            'en_cours' => $programmes['en_cours'],
            'fin_proche' => $programmes['fin_proche'],
            'semaines' => $semaines,
            'sujets_termines' => (int) $t->sujets,
            'parents_actifs' => (int) $t->parents,
        );
    }

    /**
     * Les douze derniers mois civils (le mois en cours compris, arrêté à ce jour) : nouveaux dossiers (la cohorte du mois),
     * ventes et vendu, encaissé. Chaque nombre est celui de la période « mois » correspondante. `part` : longueur de la barre
     * du vendu, relative au plus grand mois.
     */
    public function evolution($user, $aujourdhui)
    {
        $mois = array();
        $debut = (new DateTimeImmutable($aujourdhui))->modify('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $d = $debut->modify("-$i months");
            $fin = $d->modify('last day of this month')->format('Y-m-d');
            $p = array('du' => $d->format('Y-m-d'), 'au' => min($fin, $aujourdhui));
            $nouveaux = $this->nouveaux($user, $p);
            $ventes = $this->ventes($user, $p);
            $ecritures = $this->ecritures($user, $p);
            $mois[] = array(
                'mois' => $d->format('Y-m'),
                'du' => $p['du'],
                'au' => $p['au'],
                'nouveaux' => $nouveaux === null ? null : $nouveaux['total'],
                'ventes' => $ventes === null ? null : $ventes['nb'],
                'vendu' => $ventes === null ? null : $ventes['vendu'],
                'encaisse' => $ecritures === null ? null : $ecritures['encaisse'],
            );
        }
        $max = 0;
        foreach ($mois as $m) {
            $max = max($max, (int) $m['vendu']);
        }
        foreach ($mois as $k => $m) {
            $mois[$k]['part'] = self::part((int) $m['vendu'], $max);
        }

        return $mois;
    }

    /**
     * Toutes les statistiques d'une période, telles que la page, l'export et le rapport les servent : un seul calcul
     * pour les trois. Un bloc par droit, absent sans ce droit.
     */
    public function statistiques($user, $periode, $aujourdhui)
    {
        $precedente = self::periodePrecedente($periode);
        $blocs = array(
            'nouveaux' => $this->nouveaux($user, $periode),
            'nouveaux_precedent' => $this->nouveaux($user, $precedente),
            'conversion' => $this->conversion($user, $periode),
            'finances' => $this->finances($user, $periode),
            'ventes_precedent' => $this->ventes($user, $precedente),
            'ecritures_precedent' => $this->ecritures($user, $precedente),
            'a_encaisser' => $this->aEncaisser($user, $aujourdhui),
            'origines' => $this->origines($user, $periode),
            'rendez_vous' => $this->rendezVous($user, $periode),
            'rendez_vous_precedent' => $this->rendezVous($user, $precedente),
            'problematiques' => $this->problematiques($user),
            'programme' => $this->progression($user, $periode, $aujourdhui),
            'evolution' => $this->evolution($user, $aujourdhui),
        );
        $out = array('aujourdhui' => $aujourdhui, 'periode' => $periode, 'precedente' => $precedente);
        foreach ($blocs as $cle => $bloc) {
            if ($bloc !== null) {
                $out[$cle] = $bloc;
            }
        }

        return $out;
    }
}

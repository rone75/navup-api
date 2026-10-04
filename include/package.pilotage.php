<?php

/**
 * Pilotage : les chiffres du tableau de bord (CDC §3), que les statistiques de l'étape 7 étendront.
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
}

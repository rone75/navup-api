<?php

//=======================================================================
// File:        package.statistiques.php
// Description: mise en forme des statistiques d'une période (Pilotage::statistiques) en sections de lignes typées,
//              une seule fois pour le classeur Excel et pour le rapport PDF : les deux disent les mêmes chiffres,
//              dans le même ordre que la page. Aucun calcul ici : les valeurs viennent telles quelles de Pilotage.
//              Une section : array(titre, colonnes => array(array(titre, type, largeur)), lignes, note?).
//              Types : ceux de Xlsx (texte, entier, euros, date, moment, pourcent).
//              Requiert package.xlsx.php, package.export.php (libellés, Export::jour) et, pour le rapport, package.pdf.php.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Statistiques
{
    const MOIS = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');

    /** « Du 1er au 4 octobre 2026 », « Le 4 octobre 2026 », « Du 28 septembre au 4 octobre 2026 ». */
    public static function periodeEcrite($p)
    {
        if ($p['du'] === $p['au']) {
            return 'Le ' . Export::jour($p['au']);
        }
        list($a1, $m1, $j1) = array_map('intval', explode('-', $p['du']));
        list($a2, $m2) = array_map('intval', explode('-', $p['au']));
        $debut = $a1 !== $a2 ? Export::jour($p['du']) : ($m1 !== $m2 ? ($j1 === 1 ? '1er' : $j1) . ' ' . self::MOIS[$m1] : ($j1 === 1 ? '1er' : (string) $j1));

        return 'Du ' . $debut . ' au ' . Export::jour($p['au']);
    }

    /** « octobre 2026 » pour AAAA-MM. */
    public static function moisEcrit($m)
    {
        list($a, $n) = array_map('intval', explode('-', $m));

        return self::MOIS[$n] . ' ' . $a;
    }

    /** Titre du document : « Statistiques NavUp, du 1er au 4 octobre 2026 ». */
    public static function titre($stats)
    {
        $p = self::periodeEcrite($stats['periode']);

        return 'Statistiques NavUp, ' . mb_strtolower(mb_substr($p, 0, 1)) . mb_substr($p, 1);
    }

    private static function col($titre, $type, $largeur = 14)
    {
        return array('titre' => $titre, 'type' => $type, 'largeur' => $largeur);
    }

    /** Les sections, dans l'ordre de la page. Une section dont le bloc est absent (droit) n'est pas écrite. */
    public static function sections($s)
    {
        $out = array();
        $prec = 'Période précédente';

        if (isset($s['nouveaux'])) {
            $n = $s['nouveaux'];
            $np = $s['nouveaux_precedent'] ?? array();
            $lignes = array();
            foreach (array('prospects' => 'Nouveaux prospects', 'clients' => 'Nouveaux clients') as $g => $lib) {
                if (array_key_exists($g, $n)) {
                    $lignes[] = array($lib, $n[$g], $np[$g] ?? null);
                }
            }
            $lignes[] = array('Classés sans suite', array_sum($n['classes']), isset($np['classes']) ? array_sum($np['classes']) : null);
            $lignes[] = array('Dossiers arrivés', $n['total'], $np['total'] ?? null);
            $out[] = array('titre' => 'Nouveaux dossiers', 'colonnes' => array(self::col('Indicateur', 'texte', 30), self::col('Période', 'entier', 12), self::col($prec, 'entier', 18)), 'lignes' => $lignes,
                'note' => 'Dossiers dont le premier contact (à défaut, la création) tombe dans la période.');
        }

        if (isset($s['conversion'])) {
            $c = $s['conversion'];
            $lignes = array(array('Dossiers arrivés pendant la période', $c['cohorte'], null));
            foreach (array('rdv' => 'Ont eu un rendez-vous', 'vente' => 'Ont une vente', 'actifs' => 'Sont clients actifs ou ont terminé') as $k => $lib) {
                if (isset($c[$k])) {
                    $lignes[] = array($lib, $c[$k]['nb'], $c[$k]['taux']);
                }
            }
            $out[] = array('titre' => 'Conversion', 'colonnes' => array(self::col('Étape', 'texte', 36), self::col('Dossiers', 'entier', 10), self::col('Taux', 'pourcent', 8)), 'lignes' => $lignes,
                'note' => 'Parmi les dossiers arrivés pendant la période, ce qu\'ils ont fait à ce jour. Le taux n\'est donné qu\'à partir de ' . Pilotage::SEUIL_TAUX . ' dossiers.');
        }

        if (isset($s['finances']['ventes'])) {
            $f = $s['finances'];
            $v = $f['ventes'];
            $vp = $s['ventes_precedent'] ?? null;
            $lignes = array(
                array('Ventes', $v['nb'], $v['vendu'], $vp['nb'] ?? null, $vp['vendu'] ?? null),
                array('dont comptant', $v['comptant']['nb'], $v['comptant']['vendu'], $vp['comptant']['nb'] ?? null, $vp['comptant']['vendu'] ?? null),
                array('dont en plusieurs fois', $v['fractionne']['nb'], $v['fractionne']['vendu'], $vp['fractionne']['nb'] ?? null, $vp['fractionne']['vendu'] ?? null),
                array('Remboursées ou annulées', $v['defaites']['nb'], null, $vp['defaites']['nb'] ?? null, null),
                array('Panier moyen', null, $f['panier_moyen'], null, ($vp !== null && $vp['nb'] > 0) ? (int) round($vp['vendu'] / $vp['nb']) : null),
            );
            $out[] = array('titre' => 'Ventes', 'colonnes' => array(self::col('Indicateur', 'texte', 26), self::col('Nombre', 'entier', 9), self::col('Montant', 'euros', 14), self::col('Nombre (préc.)', 'entier', 13), self::col('Montant (préc.)', 'euros', 15)), 'lignes' => $lignes,
                'note' => 'Ventes datées de la période. Montants TTC.');
        }

        if (isset($s['finances']['ecritures']) || isset($s['a_encaisser'])) {
            $e = $s['finances']['ecritures'] ?? null;
            $ep = $s['ecritures_precedent'] ?? null;
            $lignes = array();
            if ($e !== null) {
                $lignes[] = array('Encaissé', $e['nb_encaissements'] ?? null, $e['encaisse'], $ep['encaisse'] ?? null);
                $lignes[] = array('Remboursé', $e['nb_remboursements'] ?? null, $e['rembourse'], $ep['rembourse'] ?? null);
                $lignes[] = array('Frais connus', null, $e['frais'], $ep['frais'] ?? null);
                $lignes[] = array('Net', null, $e['net'], $ep['net'] ?? null);
            }
            if (isset($s['a_encaisser'])) {
                $a = $s['a_encaisser'];
                $lignes[] = array('Reste à encaisser, à ce jour', $a['nb'], $a['montant'], null);
                $lignes[] = array('dont en retard', $a['nb_retard'], $a['en_retard'], null);
            }
            $out[] = array('titre' => 'Encaissements', 'colonnes' => array(self::col('Indicateur', 'texte', 30), self::col('Écritures', 'entier', 10), self::col('Montant', 'euros', 14), self::col('Montant (préc.)', 'euros', 15)), 'lignes' => $lignes,
                'note' => 'Écritures datées de la période, hors écritures annulées. Le reste à encaisser compte toutes les ventes en cours, quelle que soit la période.');
        }

        if (!empty($s['finances']['modalites']) || !empty($s['finances']['moyens'])) {
            $lignes = array();
            foreach ($s['finances']['moyens'] ?? array() as $m) {
                $lignes[] = array($m['libelle'], $m['nb'], $m['encaisse'], $m['part']);
            }
            if (count($lignes) > 0) {
                $out[] = array('titre' => 'Moyens de paiement', 'colonnes' => array(self::col('Moyen', 'texte', 26), self::col('Encaissements', 'entier', 13), self::col('Montant', 'euros', 14), self::col('Part', 'pourcent', 8)), 'lignes' => $lignes,
                    'note' => 'Encaissements de la période, par moyen, impayés retranchés. La part est celle du montant encaissé.');
            }
        }

        if (isset($s['origines']) && count($s['origines']['lignes']) > 0) {
            $o = $s['origines'];
            $avecVentes = isset($o['lignes'][0]['ventes']);
            $colonnes = array(self::col('Origine', 'texte', 26));
            foreach ($o['groupes'] as $g) {
                $colonnes[] = self::col($g === 'prospects' ? 'Prospects' : 'Clients', 'entier', 10);
            }
            $colonnes[] = self::col('Dossiers', 'entier', 10);
            $colonnes[] = self::col('Part', 'pourcent', 8);
            if ($avecVentes) {
                $colonnes[] = self::col('Ventes', 'entier', 8);
                $colonnes[] = self::col('Vendu', 'euros', 14);
            }
            $lignes = array();
            foreach ($o['lignes'] as $l) {
                $ligne = array($l['libelle']);
                foreach ($o['groupes'] as $g) {
                    $ligne[] = $l[$g];
                }
                $ligne[] = $l['dossiers'];
                $ligne[] = $l['part'];
                if ($avecVentes) {
                    $ligne[] = $l['ventes']['nb'];
                    $ligne[] = $l['ventes']['vendu'];
                }
                $lignes[] = $ligne;
            }
            $out[] = array('titre' => 'Origines', 'colonnes' => $colonnes, 'lignes' => $lignes,
                'note' => 'Dossiers arrivés pendant la période (non classés) et ventes de la période, selon l\'origine du dossier.');
        }

        if (isset($s['rendez_vous'])) {
            $r = $s['rendez_vous'];
            $rp = $s['rendez_vous_precedent'] ?? array();
            $lignes = array();
            foreach (array('total' => 'Rendez-vous de la période', 'effectues' => 'Effectués', 'prevus' => 'Prévus (à confirmer ou confirmés)', 'absents' => 'Absences', 'annules' => 'Annulés', 'en_ligne' => 'Pris en ligne par le parent', 'demandes' => 'Demandes sans créneau') as $k => $lib) {
                $lignes[] = array($lib, $r[$k], $rp[$k] ?? null);
            }
            foreach ($r['types'] as $t) {
                $lignes[] = array('Type : ' . $t['libelle'], $t['nb'], null);
            }
            $out[] = array('titre' => 'Rendez-vous', 'colonnes' => array(self::col('Indicateur', 'texte', 34), self::col('Période', 'entier', 10), self::col($prec, 'entier', 18)), 'lignes' => $lignes,
                'note' => 'Rendez-vous dont le créneau tombe dans la période ; un rendez-vous déplacé compte une fois, à son dernier créneau.');
        }

        if (isset($s['problematiques']) && count($s['problematiques']['lignes']) > 0) {
            $p = $s['problematiques'];
            $moins = 'moins de ' . $p['seuil'];
            $lignes = array();
            foreach ($p['lignes'] as $l) {
                $lignes[] = array($l['libelle'], $l['prospects'] ?? (array_key_exists('prospects', $l) ? $moins : null), $l['clients'] ?? (array_key_exists('clients', $l) ? $moins : null), $l['nb'] ?? $moins, $l['part']);
            }
            $out[] = array('titre' => 'Problématiques', 'colonnes' => array(self::col('Catégorie', 'texte', 30), self::col('Prospects', 'entier', 11), self::col('Clients', 'entier', 11), self::col('Dossiers', 'entier', 11), self::col('Part', 'pourcent', 8)), 'lignes' => $lignes,
                'note' => 'Dossiers non classés qui ont une problématique ouverte de la catégorie, à ce jour. Aucun nom ; sous ' . $p['seuil'] . ' dossiers, le nombre n\'est pas donné.');
        }

        if (isset($s['programme'])) {
            $g = $s['programme'];
            $lignes = array(array('Programmes en cours, à ce jour', $g['en_cours']), array('dont fin proche', $g['fin_proche']));
            foreach ($g['semaines'] as $w) {
                $lignes[] = array('En semaine ' . $w['semaine'], $w['nb']);
            }
            $lignes[] = array('Sujets marqués « terminé » pendant la période', $g['sujets_termines']);
            $lignes[] = array('Parents qui en ont terminé au moins un', $g['parents_actifs']);
            $out[] = array('titre' => 'Programme', 'colonnes' => array(self::col('Indicateur', 'texte', 44), self::col('Nombre', 'entier', 10)), 'lignes' => $lignes,
                'note' => 'Seulement ce que les parents ont réellement fait dans l\'appli : « terminé » est leur geste, jamais déduit d\'une écoute ou d\'une connexion.');
        }

        if (isset($s['evolution'])) {
            $lignes = array();
            foreach ($s['evolution'] as $m) {
                $lignes[] = array(self::moisEcrit($m['mois']), $m['nouveaux'], $m['ventes'], $m['vendu'], $m['encaisse']);
            }
            $out[] = array('titre' => 'Évolution sur 12 mois', 'colonnes' => array(self::col('Mois', 'texte', 18), self::col('Nouveaux dossiers', 'entier', 16), self::col('Ventes', 'entier', 9), self::col('Vendu', 'euros', 14), self::col('Encaissé', 'euros', 14)), 'lignes' => $lignes,
                'note' => 'Mois civils ; le mois en cours est arrêté à ce jour.');
        }

        return $out;
    }

    /** Le classeur : une feuille par section, titrée de la période. */
    public static function classeur($stats)
    {
        $x = new Xlsx();
        foreach (self::sections($stats) as $sec) {
            $x->feuille($sec['titre'], $sec['colonnes'], $sec['lignes'], $sec['titre'] . ' : ' . mb_strtolower(mb_substr(self::periodeEcrite($stats['periode']), 0, 1)) . mb_substr(self::periodeEcrite($stats['periode']), 1));
        }

        return $x;
    }

    // RAPPORT PDF ####################################################

    /** Valeur mise en forme pour le rapport : « 1 234 », « 1 234,56 € », « 42 % » ; une absence s'écrit « — ». */
    public static function valeur($v, $type)
    {
        if ($v === null || $v === '') {
            return "\u{2014}";
        }
        if (is_string($v) && !is_numeric($v) && $type !== 'texte') {
            return $v;
        }
        switch ($type) {
            case 'entier':
                return number_format((int) $v, 0, ',', "\u{00A0}");
            case 'euros':
                return number_format(((int) $v) / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
            case 'pourcent':
                return (int) $v . "\u{00A0}%";
            default:
                return (string) $v;
        }
    }

    /**
     * Rapport de synthèse de la période : en-tête (logo, titre, période, période de comparaison, date d'édition), puis
     * chaque section en tableau avec sa note. Les mêmes sections que le classeur, dans l'ordre de la page.
     * Retourne les octets du PDF.
     */
    public static function rapport($stats, $auteur, $logo = null)
    {
        $periode = self::periodeEcrite($stats['periode']);
        $pdf = new Pdf(self::titre($stats), 'Statistiques NavUp · ' . $periode . ' · document interne, à ne pas diffuser');

        if ($logo !== null && is_file($logo)) {
            $pdf->image($logo, Pdf::MARGE, 92);
            $pdf->descendre(14);
        }
        $pdf->paragraphe('Statistiques', 22, true);
        $pdf->descendre(2);
        $pdf->paragraphe($periode, 13, true, Pdf::BLEU);
        $pdf->descendre(4);
        $pdf->paragraphe('Comparée à la période précédente de même durée : ' . mb_strtolower(mb_substr(self::periodeEcrite($stats['precedente']), 0, 1)) . mb_substr(self::periodeEcrite($stats['precedente']), 1) . '.', 9.5, false, Pdf::SECONDE);
        $pdf->paragraphe('Édité le ' . Export::jour($stats['aujourdhui']) . ' à ' . (int) date('G') . "\u{00A0}h\u{00A0}" . date('i') . ($auteur !== '' ? ', par ' . $auteur : '') . '. Montants TTC.', 9.5, false, Pdf::SECONDE);
        $pdf->descendre(10);

        foreach (self::sections($stats) as $sec) {
            // Un titre ne reste jamais seul en bas de page : sa note, l'en-tête du tableau et trois lignes l'accompagnent
            $pdf->place(150 + 9 * count(Pdf::couper($sec['note'] ?? '', Pdf::LARGEUR - 2 * Pdf::MARGE, 8.5)));
            $pdf->descendre(8);
            $pdf->paragraphe($sec['titre'], 13, true);
            $pdf->descendre(1);
            $pdf->filet(Pdf::MARGE, $pdf->y(), Pdf::LARGEUR - Pdf::MARGE, 1.4, Pdf::ENCRE);
            $pdf->descendre(6);
            if (!empty($sec['note'])) {
                $pdf->paragraphe($sec['note'], 8.5, false, Pdf::SECONDE, 1.35);
                $pdf->descendre(4);
            }
            $colonnes = array();
            foreach ($sec['colonnes'] as $k => $c) {
                $colonnes[] = array($c['titre'], $k === 0 ? max(2.2, $c['largeur'] / 10) : max(1, $c['largeur'] / 10), $c['type'] === 'texte' ? 'gauche' : 'droite');
            }
            $lignes = array();
            foreach ($sec['lignes'] as $l) {
                $ligne = array();
                foreach ($sec['colonnes'] as $k => $c) {
                    $ligne[] = self::valeur($l[$k] ?? null, $c['type']);
                }
                $lignes[] = $ligne;
            }
            $pdf->tableau($colonnes, $lignes);
            $pdf->descendre(14);
        }

        return $pdf->sortie();
    }
}

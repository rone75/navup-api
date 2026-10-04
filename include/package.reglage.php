<?php

//=======================================================================
// File:        package.reglage.php
// Description: réglages modifiables depuis l'outil (étape 7b ; CDC §21). Le catalogue ci-dessous dit lesquels, leur
//              type et leurs bornes ; leur valeur par défaut est celle de require/param.php. Une valeur enregistrée
//              (p_reglage) remplace la valeur par défaut dans la variable globale elle-même, à l'ouverture de la base
//              (Reglage::appliquer, appelé par Mysql::OuvrirBase) : tout le code qui lit $_RDV_PRISE,
//              $_PROGRAMME_FIN_PROCHE_JOURS… lit ainsi le réglage en vigueur, sans rien changer à ses lectures.
//              Une clé « A.b.c » désigne $_A['b']['c'].
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Reglage
{
    // Valeurs par défaut (celles de param.php), relevées avant d'appliquer les réglages enregistrés
    private static $defauts = null;

    /**
     * Le catalogue : groupe, libellé, type (entier, booleen, canaux), bornes et unité. Rien d'autre ne se règle.
     * L'ordre est celui de l'écran des réglages.
     */
    public static function catalogue()
    {
        $alertes = array(
            'echeance_retard' => 'Échéance en retard',
            'paiement_echoue' => 'Paiement échoué',
            'rdv_a_planifier' => 'Rendez-vous à planifier',
            'rdv_a_confirmer' => 'Rendez-vous à confirmer',
            'rdv_compte_rendu' => 'Compte rendu à écrire',
            'appel_a_rappeler' => 'Appel à rappeler',
            'programme_fin_proche' => 'Fin de programme proche',
            'prospect_sans_suivi' => 'Prospect sans suivi',
            'information_manquante' => 'Information manquante',
            'acces_fin_proche' => "Fin d'accès proche",
            'programme_inactif' => 'Prendre des nouvelles (programme)',
        );
        $c = array();
        foreach ($alertes as $code => $libelle) {
            $c['ALERTES_ACTIVES.' . $code] = array('groupe' => 'alertes', 'libelle' => $libelle, 'type' => 'booleen');
        }
        $c['TACHE_JOURS_AVANT_RDV'] = array('groupe' => 'delais', 'libelle' => 'Confirmer un rendez-vous', 'aide' => 'jours avant le rendez-vous', 'type' => 'entier', 'min' => 0, 'max' => 14, 'unite' => 'jours');
        $c['PROGRAMME_FIN_PROCHE_JOURS'] = array('groupe' => 'delais', 'libelle' => 'Fin de programme proche', 'aide' => 'jours avant la fin du programme', 'type' => 'entier', 'min' => 1, 'max' => 30, 'unite' => 'jours');
        $c['TACHE_JOURS_APRES_PROGRAMME'] = array('groupe' => 'delais', 'libelle' => 'Fin de programme à traiter', 'aide' => 'jours après la fin du programme', 'type' => 'entier', 'min' => 0, 'max' => 60, 'unite' => 'jours');
        $c['PROSPECT_SANS_SUIVI_JOURS'] = array('groupe' => 'delais', 'libelle' => 'Prospect sans suivi', 'aide' => 'jours sans échange, rendez-vous ni note', 'type' => 'entier', 'min' => 3, 'max' => 90, 'unite' => 'jours');
        $c['ACCES_FIN_PROCHE_JOURS'] = array('groupe' => 'delais', 'libelle' => "Fin d'accès proche", 'aide' => "jours avant la fermeture de l'espace du parent", 'type' => 'entier', 'min' => 1, 'max' => 30, 'unite' => 'jours');
        $c['PROGRAMME_INACTIF_JOURS'] = array('groupe' => 'delais', 'libelle' => 'Prendre des nouvelles', 'aide' => 'jours sans sujet terminé, en cours de programme', 'type' => 'entier', 'min' => 7, 'max' => 60, 'unite' => 'jours');
        $c['ACCES_APRES_FIN_JOURS'] = array('groupe' => 'programme', 'libelle' => "Accès après la fin du programme", 'aide' => "jours pendant lesquels les contenus restent consultables (pour les comptes ouverts ensuite)", 'type' => 'entier', 'min' => 0, 'max' => 365, 'unite' => 'jours');

        $c['RDV_PRISE_OUVERTE'] = array('groupe' => 'rdv', 'libelle' => 'Prise de rendez-vous en ligne', 'type' => 'booleen');
        foreach (array('decouverte' => 'découverte (page publique)', 'suivi' => "d'accompagnement (espace des parents)") as $type => $nom) {
            $c["RDV_PRISE.$type.duree"] = array('groupe' => 'rdv', 'libelle' => "Durée du rendez-vous $nom", 'type' => 'entier', 'min' => 15, 'max' => 120, 'unite' => 'minutes');
            $c["RDV_PRISE.$type.canaux"] = array('groupe' => 'rdv', 'libelle' => "Façons d'échanger proposées, rendez-vous $nom", 'type' => 'canaux');
            $c["RDV_PRISE.$type.pas"] = array('groupe' => 'rdv', 'libelle' => "Écart entre deux créneaux, rendez-vous $nom", 'type' => 'entier', 'min' => 10, 'max' => 120, 'unite' => 'minutes');
            $c["RDV_PRISE.$type.delai_heures"] = array('groupe' => 'rdv', 'libelle' => "Délai avant le premier créneau, rendez-vous $nom", 'type' => 'entier', 'min' => 0, 'max' => 168, 'unite' => 'heures');
            $c["RDV_PRISE.$type.horizon_jours"] = array('groupe' => 'rdv', 'libelle' => "Créneaux proposés jusqu'à, rendez-vous $nom", 'type' => 'entier', 'min' => 1, 'max' => 90, 'unite' => 'jours');
            $c["RDV_PRISE.$type.max_a_venir"] = array('groupe' => 'rdv', 'libelle' => "Rendez-vous $nom à venir admis par dossier", 'type' => 'entier', 'min' => 1, 'max' => 5, 'unite' => '');
        }
        $c['RDV_PRISE_PLAFOND'] = array('groupe' => 'rdv', 'libelle' => 'Réservations publiques admises en 24 heures', 'type' => 'entier', 'min' => 1, 'max' => 500, 'unite' => '');
        $c['RDV_MODIFIABLE_HEURES'] = array('groupe' => 'rdv', 'libelle' => 'Annuler ou déplacer en ligne jusqu’à', 'type' => 'entier', 'min' => 0, 'max' => 72, 'unite' => 'heures avant');
        $c['RDV_DEPLACEMENTS_MAX'] = array('groupe' => 'rdv', 'libelle' => 'Déplacements en ligne d’un même rendez-vous', 'type' => 'entier', 'min' => 0, 'max' => 10, 'unite' => '');
        $c['RDV_RAPPEL_HEURES'] = array('groupe' => 'rdv', 'libelle' => 'Rappel par e-mail', 'type' => 'entier', 'min' => 1, 'max' => 72, 'unite' => 'heures avant');

        return $c;
    }

    /** Chemin d'une clé dans les variables globales : « RDV_PRISE.decouverte.duree » → array('_RDV_PRISE', 'decouverte', 'duree'). */
    private static function chemin($cle)
    {
        $parties = explode('.', $cle);
        $parties[0] = '_' . $parties[0];

        return $parties;
    }

    private static function lireGlobale($cle)
    {
        $v = $GLOBALS;
        foreach (self::chemin($cle) as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) {
                return null;
            }
            $v = $v[$p];
        }

        return $v;
    }

    private static function poserGlobale($cle, $valeur)
    {
        $chemin = self::chemin($cle);
        $nom = array_shift($chemin);
        $GLOBALS[$nom] = self::poser($GLOBALS[$nom] ?? null, $chemin, $valeur);
    }

    /** Copie de $tableau avec $valeur au bout de $chemin (PHP n'admet pas de référence sur $GLOBALS). */
    private static function poser($tableau, $chemin, $valeur)
    {
        if (count($chemin) === 0) {
            return $valeur;
        }
        $tableau = is_array($tableau) ? $tableau : array();
        $p = array_shift($chemin);
        $tableau[$p] = self::poser($tableau[$p] ?? null, $chemin, $valeur);

        return $tableau;
    }

    /**
     * Applique les réglages enregistrés par-dessus les valeurs par défaut (appelé à l'ouverture de la base). Les valeurs
     * par défaut sont relevées une fois, avant. Une valeur enregistrée qui ne passe plus la validation est ignorée.
     */
    public static function appliquer($mysqli)
    {
        if (self::$defauts === null) {
            self::$defauts = array();
            foreach (array_keys(self::catalogue()) as $cle) {
                self::$defauts[$cle] = self::lireGlobale($cle);
            }
        }
        $res = @$mysqli->query("SELECT cle, valeur FROM p_reglage");
        if ($res === false) {
            return;
        }
        $catalogue = self::catalogue();
        while ($r = $res->fetch_assoc()) {
            if (!isset($catalogue[$r['cle']])) {
                continue;
            }
            $valeur = json_decode($r['valeur'], true);
            if (self::controler($catalogue[$r['cle']], $valeur) === null) {
                self::poserGlobale($r['cle'], $valeur);
            }
        }
        $res->free();
    }

    /** Raison du refus d'une valeur, ou null si elle convient. */
    public static function controler($def, $valeur)
    {
        switch ($def['type']) {
            case 'booleen':
                return is_bool($valeur) ? null : 'oui ou non attendu';
            case 'entier':
                if (!is_int($valeur)) {
                    return 'un nombre entier est attendu';
                }

                return ($valeur < $def['min'] || $valeur > $def['max']) ? "entre {$def['min']} et {$def['max']}" : null;
            case 'canaux':
                if (!is_array($valeur) || count($valeur) === 0 || count(array_diff($valeur, array('visio', 'telephone'))) > 0 || count(array_unique($valeur)) !== count($valeur)) {
                    return 'au moins une façon d’échanger : visio, téléphone';
                }

                return null;
        }

        return 'type inconnu';
    }

    /** Les réglages tels que l'écran les montre : valeur en vigueur, valeur par défaut, et date de la dernière modification. */
    public static function tous()
    {
        global $Mysql;

        $enregistres = array();
        foreach ($Mysql->fetchAll("SELECT cle, date_modif FROM p_reglage") as $r) {
            $enregistres[$r->cle] = $r->date_modif;
        }
        $out = array();
        foreach (self::catalogue() as $cle => $def) {
            $out[] = array_merge(array('cle' => $cle), $def, array(
                'valeur' => self::lireGlobale($cle),
                'defaut' => self::$defauts[$cle] ?? self::lireGlobale($cle),
                'modifie' => $enregistres[$cle] ?? null,
            ));
        }

        return $out;
    }

    /**
     * Enregistre une valeur (ou, $valeur === null, revient à la valeur par défaut). Sans transaction.
     * Retourne array(avant, apres) ; ErreurMetier ou 400 par $Response si la valeur ne convient pas.
     */
    public static function ecrire($cle, $valeur, $id_users)
    {
        global $Mysql, $Response;

        $catalogue = self::catalogue();
        if (!isset($catalogue[$cle])) {
            $Response->validationError("Réglage inconnu.");
        }
        $avant = self::lireGlobale($cle);
        if ($valeur === null) {
            $Mysql->execute("DELETE FROM p_reglage WHERE cle = ?", array($cle), 's');
            $apres = self::$defauts[$cle] ?? $avant;
        } else {
            $raison = self::controler($catalogue[$cle], $valeur);
            if ($raison !== null) {
                $Response->validationError("« " . $catalogue[$cle]['libelle'] . " » : " . $raison . ".");
            }
            $Mysql->execute(
                "INSERT INTO p_reglage (cle, valeur, id_users, date_modif) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), id_users = VALUES(id_users), date_modif = NOW()",
                array($cle, json_encode($valeur), $id_users === null ? null : (int) $id_users),
                'ssi'
            );
            $apres = $valeur;
        }
        self::poserGlobale($cle, $apres);

        return array($avant, $apres);
    }
}

<?php

//=======================================================================
// File:        package.vue.php
// Description: vues enregistrées (étape 7b ; CDC §16) : les réglages d'une liste (vue, filtres, tri) gardés sous un
//              nom, pour l'utilisateur qui les a enregistrés. Chaque réglage passe une liste blanche : sa forme, et pour
//              un choix, ses valeurs permises. Jamais le terme de recherche, jamais une donnée de dossier, jamais la page.
//              Copie des états des listes du front (contacts/liste-memoire.ts, ventes-page.ts, paiements-page.ts,
//              taches-page.ts) : modifier les deux ensemble.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Vue
{
    const MAX_PAR_LISTE = 20;

    const CODE = '/^[a-z0-9_]{0,30}$/';
    const DATE = '/^(\d{4}-\d{2}-\d{2})?$/';
    const TRI = '/^[a-z_]{1,20}:(asc|desc)$/';

    /** Réglages permis par liste : nom => motif (expression) ou liste de valeurs. Le module de droit de la liste. */
    public static function listes()
    {
        $dossier = array(
            'vue' => array('tous', 'nouveaux', 'relance', 'action', 'classes', 'actifs', 'termines'),
            'statut' => '/^[a-z_,]{0,200}$/',
            'programme' => array('', 'en_cours', 'fin_proche', 'termine'),
            'origine' => self::CODE,
            'categorie' => self::CODE,
            'du' => self::DATE,
            'au' => self::DATE,
            'tri' => self::TRI,
        );

        return array(
            'prospects' => array('module' => 'prospects', 'reglages' => $dossier),
            'clients' => array('module' => 'clients', 'reglages' => $dossier),
            'ventes' => array('module' => 'ventes', 'reglages' => array(
                'vue' => array('en_cours', 'retard', 'payees', 'defaites', 'toutes'),
                'modalite' => array('', 'comptant', 'fractionne'),
                'moyen' => self::CODE,
                'origine' => self::CODE,
                'du' => self::DATE,
                'au' => self::DATE,
                'tri' => self::TRI,
            )),
            'paiements' => array('module' => 'paiements', 'reglages' => array(
                'vue' => array('a_encaisser', 'retard', 'journal', 'remboursements'),
                'moyen' => self::CODE,
                'du' => self::DATE,
                'au' => self::DATE,
            )),
            'taches' => array('module' => 'taches', 'reglages' => array(
                'vue' => array('ouvertes', 'traitees'),
                'nature' => array('', 'tache', 'appel'),
                'categorie' => array('', 'suivi', 'gestion'),
                'miennes' => 'booleen',
            )),
        );
    }

    /**
     * Réglages validés d'une vue : seulement les noms permis, chacun contrôlé ; un réglage inconnu (la recherche,
     * la page) est écarté sans bruit, une valeur hors liste est refusée. Le filtre par problématique demande le droit famille.
     * Retourne array(reglages, raison|null).
     */
    public static function valider($liste, $reglages, $user)
    {
        global $U;

        $permis = self::listes()[$liste]['reglages'];
        if (!is_array($reglages)) {
            return array(null, 'Réglages illisibles.');
        }
        $out = array();
        foreach ($permis as $nom => $regle) {
            if (!array_key_exists($nom, $reglages)) {
                continue;
            }
            $v = $reglages[$nom];
            if ($regle === 'booleen') {
                if (!is_bool($v)) {
                    return array(null, "Réglage « $nom » invalide.");
                }
            } elseif (is_array($regle)) {
                if (!is_string($v) || !in_array($v, $regle, true)) {
                    return array(null, "Réglage « $nom » invalide.");
                }
            } elseif (!is_string($v) || preg_match($regle, $v) !== 1) {
                return array(null, "Réglage « $nom » invalide.");
            }
            if ($nom === 'categorie' && in_array($liste, array('prospects', 'clients'), true) && $v !== '' && !$U->can($user, 'famille', 'L')) {
                return array(null, "Le filtre par problématique demande l'accès aux données familiales.");
            }
            $out[$nom] = $v;
        }
        if (isset($out['du'], $out['au']) && $out['du'] !== '' && $out['au'] !== '' && $out['du'] > $out['au']) {
            return array(null, 'La période se termine avant de commencer.');
        }

        return array($out, null);
    }

    /** Vues d'un utilisateur pour une liste, dans leur ordre. */
    public static function de($id_users, $liste)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT id_vue, nom, filtres, ordre, date_creation FROM u_vue WHERE id_users = ? AND liste = ? ORDER BY ordre, id_vue",
            array((int) $id_users, $liste),
            'is'
        ) as $v) {
            $out[] = array('id_vue' => (int) $v->id_vue, 'nom' => $v->nom, 'reglages' => json_decode($v->filtres, true), 'ordre' => (int) $v->ordre);
        }

        return $out;
    }
}

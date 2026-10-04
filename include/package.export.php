<?php

//=======================================================================
// File:        package.export.php
// Description: exports Excel des listes (étape 7a ; CDC §18). Un export n'a pas sa propre requête : la liste, appelée
//              avec `format=xlsx`, garde ses filtres, ses droits et sa mise en forme, et ne change que deux choses :
//              Export::pagination() (toutes les lignes, jusqu'au plafond) au lieu de Saisie::pagination(), et
//              Export::siDemande() juste avant sa réponse JSON, qui envoie le classeur à la place.
//              Les colonnes de chaque liste sont déclarées ici, à partir de ce que la liste sert au front :
//              ce qui n'est pas servi (données familiales, notes internes sans droit) ne peut pas être exporté.
//              Droit `exports`, en plus du droit de lecture que la liste exige déjà. Chaque export est inscrit au
//              journal d'audit (liste, filtres, nombre de lignes, aucune valeur). Le fichier n'est jamais gardé.
//              Requiert package.xlsx.php, package.user.php ($U), package.response.php ($Response).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Export
{
    // Au-delà, l'export est refusé : resserrer les filtres (un classeur plus gros ne s'ouvre plus confortablement)
    const MAX_LIGNES = 20000;

    // Paramètres d'une liste qui ne sont pas des filtres : ils ne vont pas au journal
    const HORS_FILTRES = array('format', 'modele', 'page', 'limit');

    // Libellés : copies de ceux du front (core/contact-format.ts, vente-format.ts, suivi-format.ts)
    const STATUTS_DOSSIER = array(
        'prospect' => 'Prospect', 'rdv_demande' => 'RDV demandé', 'rdv_planifie' => 'RDV planifié', 'a_relancer' => 'À relancer',
        'client' => 'Client', 'client_actif' => 'Client actif', 'programme_termine' => 'Programme terminé', 'annule_rembourse' => 'Annulé / remboursé',
    );
    const STATUTS_VENTE = array(
        'en_attente' => 'En attente', 'paye_partiellement' => 'Payée partiellement', 'paye' => 'Payée', 'echoue' => 'Paiement échoué',
        'rembourse_partiellement' => 'Remboursée partiellement', 'rembourse' => 'Remboursée', 'annule' => 'Annulée',
    );
    const MODALITES = array('comptant' => 'Comptant', 'fractionne' => 'En plusieurs fois');
    const TYPES_PAIEMENT = array('encaissement' => 'Encaissement', 'remboursement' => 'Remboursement', 'impaye' => 'Impayé', 'echec' => 'Échec');
    const TYPES_RDV = array('decouverte' => 'Découverte', 'suivi' => 'Suivi', 'bilan' => 'Bilan', 'autre' => 'Autre');
    const CANAUX_RDV = array('visio' => 'Visio', 'telephone' => 'Téléphone', 'presentiel' => 'En présence');
    const STATUTS_RDV = array(
        'demande' => 'Demandé', 'a_confirmer' => 'À confirmer', 'confirme' => 'Confirmé', 'effectue' => 'Effectué',
        'absent' => 'Absent', 'annule' => 'Annulé', 'reporte' => 'Reporté',
    );
    const ALERTES = array(
        'echeance_retard' => 'Échéance en retard', 'paiement_echoue' => 'Paiement échoué', 'rdv_a_planifier' => 'Rendez-vous à planifier',
        'rdv_a_confirmer' => 'Rendez-vous à confirmer', 'rdv_compte_rendu' => 'Compte rendu à écrire', 'appel_a_rappeler' => 'Appel à rappeler',
        'programme_fin_proche' => 'Fin de programme proche',
    );
    const SITUATIONS = array('retard' => 'En retard', 'aujourdhui' => "Aujourd'hui", 'semaine' => 'Cette semaine', 'plus_tard' => 'Plus tard');
    const PROGRAMMES = array('pas_commence' => 'Pas commencé', 'en_cours' => 'En cours', 'termine' => 'Terminé', 'suspendu' => 'Suspendu');

    /** L'export est-il demandé ? (`format=xlsx` dans l'adresse de la liste) */
    public static function demande()
    {
        return isset($_GET['format']) && $_GET['format'] === 'xlsx';
    }

    /**
     * À la place de Saisie::pagination() : en export, une seule « page » qui contient tout (dans la limite du plafond),
     * après avoir exigé le droit `exports`. Sinon, la pagination ordinaire.
     */
    public static function pagination($user)
    {
        global $S, $U, $Response;

        if (!self::demande()) {
            return $S->pagination();
        }
        self::exiger($user);

        return array(1, self::MAX_LIGNES + 1, 0);
    }

    /** Le droit `exports`, ou 403. Le droit de lecture de la liste, lui, est déjà exigé par la liste. */
    public static function exiger($user)
    {
        global $U, $Response;

        if (!$U->can($user, 'exports', 'L')) {
            $Response->forbidden("Vous n'avez pas le droit d'exporter.");
        }
    }

    /**
     * Juste avant la réponse JSON d'une liste : si l'export est demandé, envoie le classeur et termine la requête.
     * $liste : clé de self::listes() ; $lignes : ce que la liste allait servir ; $total : son total (le même filtre).
     */
    public static function siDemande($liste, $user, $lignes, $total, $totaux = null)
    {
        global $Response;

        if (!self::demande()) {
            return;
        }
        self::exiger($user);
        if ((int) $total > self::MAX_LIGNES || count($lignes) > self::MAX_LIGNES) {
            $Response->validationError("Cet export compterait " . (int) $total . " lignes, au-delà des " . self::MAX_LIGNES . " admises : resserrez les filtres.");
        }
        $def = self::listes()[$liste];
        $x = new Xlsx();
        $x->feuille($def['feuille'], self::colonnes($def), self::lignes($def, $lignes), $def['feuille'] . ' : export du ' . self::jour(date('Y-m-d')));
        // Export comptable : une seconde feuille reprend les totaux de la liste (ceux de l'écran), à égaler par les lignes
        if ($liste === 'comptable' && $totaux !== null) {
            $x->feuille('Totaux', array(
                array('titre' => 'Total', 'type' => 'texte', 'largeur' => 34),
                array('titre' => 'Montant', 'type' => 'euros', 'largeur' => 14),
            ), array(
                array('Encaissé (impayés déduits)', $totaux['encaisse']),
                array('Remboursé', $totaux['rembourse']),
                array('Frais connus', $totaux['frais']),
                array('Net (encaissé moins frais)', $totaux['net']),
            ), 'Totaux des écritures, du ' . self::jour($_GET['du'] ?? '') . ' au ' . self::jour($_GET['au'] ?? ''));
        }
        self::envoyer($x, $liste . '-' . date('Y-m-d') . '.xlsx', $liste, $user, count($lignes));
    }

    /** Envoie un classeur au navigateur, l'inscrit au journal, et termine la requête. */
    public static function envoyer($classeur, $nomFichier, $liste, $user, $nbLignes, $filtres = null)
    {
        global $U;

        $U->audit((int) $user->id_users, 'export', array('liste' => $liste, 'lignes' => (int) $nbLignes, 'filtres' => $filtres ?? self::filtres()));
        $chemin = $classeur->ecrire();
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
            header('Content-Length: ' . filesize($chemin));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($chemin);
        } finally {
            @unlink($chemin);
        }
        exit();
    }

    /**
     * Filtres de la liste, pour le journal : leurs noms et leurs valeurs, sauf la recherche libre (`q`), qui peut
     * contenir un nom ou un téléphone : seule sa présence est notée.
     */
    public static function filtres()
    {
        $out = array();
        foreach ($_GET as $cle => $valeur) {
            if (!is_string($cle) || in_array($cle, self::HORS_FILTRES, true) || !is_string($valeur) || $valeur === '') {
                continue;
            }
            $out[mb_substr($cle, 0, 30)] = $cle === 'q' ? '(recherche)' : mb_substr($valeur, 0, 60);
        }

        return $out;
    }

    // COLONNES DES LISTES ############################################

    private static function colonnes($def)
    {
        $out = array();
        foreach ($def['colonnes'] as $c) {
            $out[] = array('titre' => $c[0], 'type' => $c[1], 'largeur' => $c[2]);
        }

        return $out;
    }

    private static function lignes($def, $lignes)
    {
        $out = array();
        foreach ($lignes as $l) {
            $ligne = array();
            foreach ($def['colonnes'] as $c) {
                $ligne[] = $c[3]($l);
            }
            $out[] = $ligne;
        }

        return $out;
    }

    /** « 4 octobre 2026 ». */
    public static function jour($date)
    {
        $mois = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');
        $t = explode('-', substr((string) $date, 0, 10));

        return ((int) $t[2] === 1 ? '1er' : (int) $t[2]) . ' ' . $mois[(int) $t[1]] . ' ' . $t[0];
    }

    private static function libelle($table, $code)
    {
        return $code === null ? null : ($table[$code] ?? $code);
    }

    private static function oui($b)
    {
        return $b ? 'oui' : 'non';
    }

    /**
     * Colonnes de chaque liste : array(titre, type, largeur, fonction qui lit la valeur dans une ligne servie au front).
     * Montants en centimes (type euros), dates telles que l'API les sert.
     */
    public static function listes()
    {
        $l = function ($table, $champ) {
            return function ($r) use ($table, $champ) {
                return self::libelle($table, $r[$champ] ?? null);
            };
        };
        $v = function ($champ) {
            return function ($r) use ($champ) {
                return $r[$champ] ?? null;
            };
        };
        $dossier = function ($champ) {
            return function ($r) use ($champ) {
                return $r['contact'][$champ] ?? null;
            };
        };

        return array(
            'dossiers' => array('feuille' => 'Dossiers', 'colonnes' => array(
                array('Référence', 'texte', 11, $v('reference')),
                array('Prénom', 'texte', 16, $v('prenom')),
                array('Nom', 'texte', 18, $v('nom')),
                array('E-mail', 'texte', 28, $v('email')),
                array('Téléphone', 'texte', 15, $v('telephone')),
                array('Statut', 'texte', 18, $l(self::STATUTS_DOSSIER, 'statut')),
                array('Classé sans suite', 'texte', 10, function ($r) {
                    return self::oui(!empty($r['archive']));
                }),
                array('Origine', 'texte', 18, $v('origine')),
                array('Premier contact', 'date', 12, $v('date_premier_contact')),
                array('Inscription', 'date', 12, $v('date_inscription')),
                array('Dernier échange', 'moment', 16, $v('date_derniere_interaction')),
                array('Prochaine action', 'date', 12, $v('date_prochaine_action')),
                array('Programme', 'texte', 13, function ($r) {
                    return isset($r['programme']['etat']) ? self::libelle(self::PROGRAMMES, $r['programme']['etat']) : null;
                }),
                array('Semaine', 'entier', 8, function ($r) {
                    return $r['programme']['semaine'] ?? null;
                }),
                array('Créé le', 'moment', 16, $v('date_creation')),
            )),
            'ventes' => array('feuille' => 'Ventes', 'colonnes' => array(
                array('Vente', 'texte', 11, $v('reference')),
                array('Date', 'date', 12, $v('date_vente')),
                array('Dossier', 'texte', 11, $dossier('reference')),
                array('Prénom', 'texte', 16, $dossier('prenom')),
                array('Nom', 'texte', 18, $dossier('nom')),
                array('Offre', 'texte', 24, $v('offre')),
                array('Prix catalogue', 'euros', 13, $v('montant_catalogue')),
                array('Remise', 'euros', 11, $v('remise')),
                array('Montant', 'euros', 12, $v('montant')),
                array('Modalité', 'texte', 15, $l(self::MODALITES, 'modalite')),
                array('Échéances', 'entier', 10, $v('nb_echeances')),
                array('Statut', 'texte', 22, $l(self::STATUTS_VENTE, 'statut')),
                array('Encaissé', 'euros', 12, $v('encaisse')),
                array('Remboursé', 'euros', 12, $v('rembourse')),
                array('Reste dû', 'euros', 12, $v('reste_du')),
                array('Frais', 'euros', 10, $v('frais')),
                array('Prochaine échéance', 'date', 14, $v('prochaine_date')),
                array('En retard', 'texte', 9, function ($r) {
                    return self::oui(!empty($r['en_retard']));
                }),
                array('Origine', 'texte', 10, function ($r) {
                    return ($r['source'] ?? null) === 'stripe' ? 'en ligne' : 'saisie';
                }),
            )),
            'paiements' => array('feuille' => 'Paiements', 'colonnes' => array(
                array('Date', 'date', 12, $v('date_paiement')),
                array('Nature', 'texte', 15, $l(self::TYPES_PAIEMENT, 'type')),
                array('Montant', 'euros', 12, $v('montant')),
                array('Frais', 'euros', 10, $v('frais')),
                array('Moyen', 'texte', 18, $v('moyen')),
                array('Référence', 'texte', 22, $v('reference')),
                array('Vente', 'texte', 11, function ($r) {
                    return $r['vente']['reference'] ?? null;
                }),
                array('Dossier', 'texte', 11, $dossier('reference')),
                array('Prénom', 'texte', 16, $dossier('prenom')),
                array('Nom', 'texte', 18, $dossier('nom')),
                array('Rejeté', 'texte', 8, function ($r) {
                    return self::oui(!empty($r['rejete']));
                }),
                array('Annulée', 'texte', 8, function ($r) {
                    return self::oui(!empty($r['annulee']));
                }),
                array('Origine', 'texte', 10, function ($r) {
                    return ($r['source'] ?? null) === 'stripe' ? 'Stripe' : 'saisie';
                }),
                array('Saisi par', 'texte', 18, $v('auteur')),
            )),
            // Écritures de la période pour la comptabilité : encaissé signé (un impayé se retranche), remboursé, frais
            'comptable' => array('feuille' => 'Écritures', 'colonnes' => array(
                array('Date', 'date', 12, $v('date_paiement')),
                array('Nature', 'texte', 15, $l(self::TYPES_PAIEMENT, 'type')),
                array('Encaissé', 'euros', 12, function ($r) {
                    return $r['type'] === 'encaissement' ? (int) $r['montant'] : ($r['type'] === 'impaye' ? -(int) $r['montant'] : null);
                }),
                array('Remboursé', 'euros', 12, function ($r) {
                    return $r['type'] === 'remboursement' ? (int) $r['montant'] : null;
                }),
                array('Frais', 'euros', 10, $v('frais')),
                array('Moyen', 'texte', 18, $v('moyen')),
                array('Référence', 'texte', 26, $v('reference')),
                array('Vente', 'texte', 11, function ($r) {
                    return $r['vente']['reference'] ?? null;
                }),
                array('Dossier', 'texte', 11, $dossier('reference')),
                array('Client', 'texte', 26, function ($r) {
                    return trim(($r['contact']['prenom'] ?? '') . ' ' . ($r['contact']['nom'] ?? ''));
                }),
                array('Origine', 'texte', 10, function ($r) {
                    return ($r['source'] ?? null) === 'stripe' ? 'Stripe' : 'saisie';
                }),
            )),
            'echeances' => array('feuille' => 'Échéances', 'colonnes' => array(
                array('Date prévue', 'date', 12, $v('date_prevue')),
                array('Montant', 'euros', 12, $v('montant')),
                array('Payé', 'euros', 12, $v('montant_paye')),
                array('Reste', 'euros', 12, $v('reste')),
                array('En retard', 'texte', 9, function ($r) {
                    return self::oui(!empty($r['en_retard']));
                }),
                array('Vente', 'texte', 11, function ($r) {
                    return $r['vente']['reference'] ?? null;
                }),
                array('Dossier', 'texte', 11, $dossier('reference')),
                array('Prénom', 'texte', 16, $dossier('prenom')),
                array('Nom', 'texte', 18, $dossier('nom')),
            )),
            'taches' => array('feuille' => 'Tâches', 'colonnes' => array(
                array('Échéance', 'date', 12, $v('date_echeance')),
                array('Situation', 'texte', 13, function ($r) {
                    return !empty($r['cloture']) ? 'Traitée' : self::libelle(self::SITUATIONS, $r['situation'] ?? null);
                }),
                array('Tâche', 'texte', 36, function ($r) {
                    // L'intitulé d'une tâche de suivi est une note interne : la liste ne le sert qu'avec son droit
                    return $r['titre'] ?? self::libelle(self::ALERTES, $r['alerte'] ?? null) ?? (($r['lisible'] ?? true) ? null : '(note interne)');
                }),
                array('Catégorie', 'texte', 10, $v('categorie')),
                array('Nature', 'texte', 8, $v('nature')),
                array('Dossier', 'texte', 11, $dossier('reference')),
                array('Prénom', 'texte', 16, $dossier('prenom')),
                array('Nom', 'texte', 18, $dossier('nom')),
                array('Attribuée à', 'texte', 18, function ($r) {
                    return $r['assigne']['nom'] ?? null;
                }),
                array('Reports', 'entier', 8, $v('nb_reports')),
                array('Traitée le', 'moment', 16, $v('date_cloture')),
            )),
            'rendez_vous' => array('feuille' => 'Rendez-vous', 'colonnes' => array(
                array('Date et heure', 'moment', 16, $v('date_debut')),
                array('Durée (min)', 'entier', 10, $v('duree')),
                array('Type', 'texte', 12, $l(self::TYPES_RDV, 'type')),
                array('Canal', 'texte', 12, $l(self::CANAUX_RDV, 'canal')),
                array('Statut', 'texte', 12, $l(self::STATUTS_RDV, 'statut')),
                array('Dossier', 'texte', 11, $dossier('reference')),
                array('Prénom', 'texte', 16, $dossier('prenom')),
                array('Nom', 'texte', 18, $dossier('nom')),
                array('Mené par', 'texte', 18, function ($r) {
                    return $r['responsable']['nom'] ?? null;
                }),
                array('Pris en ligne', 'texte', 10, function ($r) {
                    return ($r['en_ligne'] ?? null) === null ? 'non' : 'oui';
                }),
                array('Compte rendu', 'texte', 10, function ($r) {
                    return self::oui(!empty($r['a_compte_rendu']));
                }),
            )),
            'audit' => array('feuille' => "Journal d'audit", 'colonnes' => array(
                array('Date', 'moment', 16, $v('date')),
                array('Utilisateur', 'texte', 18, function ($r) {
                    return $r['identifiant'] ?? null;
                }),
                array('Action', 'texte', 22, $v('action')),
                array('Cible', 'texte', 10, $v('cible_type')),
                array('N° de la cible', 'entier', 10, $v('cible_id')),
                array('Détails', 'texte', 50, function ($r) {
                    return $r['details'] === null ? null : json_encode($r['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }),
                array('Adresse IP', 'texte', 16, $v('ip')),
            )),
        );
    }
}

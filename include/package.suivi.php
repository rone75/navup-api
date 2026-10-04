<?php

//=======================================================================
// File:        package.suivi.php
// Description: suivi au quotidien (CDC §11, §12, §14) : rendez-vous (Rdv), échanges avec le parent (Interaction),
//              tâches et alertes (Tache), bloc de suivi d'un dossier (Suivi).
//              Requiert package.saisie.php ($S), package.contact.php ($Contact), package.user.php ($U),
//              package.mysql.php ($Mysql, $SQL). Les classes s'appellent par $Rdv, $Interaction, $Tache et $Suivi :
//              un endpoint de suivi les instancie toutes ; un endpoint financier n'a besoin que de $Tache.
//              Dates et heures de Paris. Les textes libres (motif, compte rendu, intitulé d'une tâche de suivi)
//              sont des notes internes : ils ne sortent qu'avec le droit sur les données familiales.
//              Toute écriture sur un dossier se fait sous son verrou (Contact::verrouiller).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Suivi
{
    /** Nom affiché d'un utilisateur joint à une ligne (colonnes <préfixe>_identifiant, _prenom, _nom), ou null. */
    public static function personne($row, $prefixe)
    {
        $ident = $row->{$prefixe . '_identifiant'} ?? null;
        if ($ident === null) {
            return null;
        }
        $nom = trim((string) $row->{$prefixe . '_prenom'} . ' ' . (string) $row->{$prefixe . '_nom'});

        return $nom !== '' ? $nom : $ident;
    }

    /**
     * Identité du dossier jointe à une ligne (aucune donnée familiale) ; null pour une tâche sans dossier.
     * Le téléphone en fait partie : un appel à passer ou un rendez-vous téléphonique se lance depuis la liste.
     */
    public static function dossier($row)
    {
        if ($row->id_contact === null) {
            return null;
        }

        return array(
            'id_contact' => (int) $row->id_contact,
            'reference' => Contact::reference($row->id_contact),
            'prenom' => $row->prenom,
            'nom' => $row->nom,
            'telephone' => $row->telephone,
            'statut' => $row->contact_statut,
            'groupe' => Contact::groupeDe($row->contact_statut),
            'archive' => $row->contact_date_archivage !== null,
        );
    }

    /** Profils qui ont le niveau demandé sur un module : sert à restreindre le choix d'une personne. */
    public static function profils($module, $niveau = 'C')
    {
        $out = array();
        foreach (User::MATRICE[$module] as $profil => $droit) {
            if ($droit === 'C' || ($niveau === 'L' && $droit === 'L')) {
                $out[] = $profil;
            }
        }

        return $out;
    }

    /** Dimanche de la semaine d'un jour (AAAA-MM-JJ) : les semaines vont du lundi au dimanche. */
    public static function finSemaine($jour)
    {
        $d = new DateTime($jour);

        return $d->modify('+' . (7 - (int) $d->format('N')) . ' days')->format('Y-m-d');
    }

    /** « 18 h 30 » pour une date et heure (messages). */
    public static function heure($moment)
    {
        return (int) substr($moment, 11, 2) . "\u{00A0}h\u{00A0}" . substr($moment, 14, 2);
    }

    /**
     * Bloc de suivi d'un dossier, pour la fiche 360° : synthèse (prochain rendez-vous, dernier échange, prochaine action),
     * rendez-vous, derniers échanges et tâches ouvertes. Chaque partie n'est présente qu'avec son droit ;
     * `aujourdhui` donne le jour du serveur : le front affiche, il ne déduit pas.
     */
    public function bloc($id_contact, $user)
    {
        global $U, $Rdv, $Interaction, $Tache;

        $id = (int) $id_contact;
        $famille = $U->can($user, 'famille', 'L');
        $maintenant = date('Y-m-d H:i:s');
        $out = array('aujourdhui' => substr($maintenant, 0, 10), 'synthese' => array());

        if ($U->can($user, 'rendez_vous', 'L')) {
            $out['rdv'] = $Rdv->duDossier($id, $famille, $maintenant);
            // Le plus proche à venir parmi ceux qui tiennent ; à défaut, une demande sans créneau
            $prochain = null;
            $demande = null;
            foreach ($out['rdv'] as $r) {
                if (in_array($r['statut'], Rdv::PREVUS, true) && !$r['passe'] && ($prochain === null || $r['date_debut'] < $prochain['date_debut'])) {
                    $prochain = $r;
                } elseif ($r['statut'] === 'demande') {
                    $demande = $r;
                }
            }
            $out['synthese']['prochain_rdv'] = $prochain ?? $demande;
        }

        // La date du dernier échange est une donnée de suivi, lisible de tous ; sa nature suit le droit sur son module
        $dernier = $Interaction->dernier($id);
        if ($dernier !== null && !$U->can($user, $dernier['nature'] === 'rdv' ? 'rendez_vous' : 'appels', 'L')) {
            $dernier['nature'] = null;
        }
        $out['synthese']['dernier_echange'] = $dernier;

        if ($U->can($user, 'appels', 'L')) {
            $out['echanges'] = $Interaction->duDossier($id, $famille, 10);
        }

        if ($U->can($user, 'taches', 'L')) {
            $out['taches'] = $Tache->duDossier($id, $user);
            $out['synthese']['prochaine_action'] = count($out['taches']) > 0 ? $out['taches'][0] : null;
        }

        return $out;
    }

    /**
     * Réponse d'une écriture de suivi : le bloc de suivi du dossier, le dossier lui-même (son statut ou sa prochaine
     * action ont pu changer) et les avertissements, comme les écritures financières. $plus : clés propres à l'endpoint.
     */
    public function reponse($id_contact, $user, $avertissements = array(), $plus = array())
    {
        global $Contact;

        return array_merge($plus, array(
            'suivi' => $this->bloc($id_contact, $user),
            'contact' => $Contact->sortie($Contact->charger($id_contact)),
            'avertissements' => $avertissements,
        ));
    }
}

class Rdv
{
    const TYPES = array('decouverte', 'suivi', 'bilan', 'autre');
    const CANAUX = array('visio', 'telephone', 'presentiel');
    const STATUTS = array('demande', 'a_confirmer', 'confirme', 'effectue', 'absent', 'annule', 'reporte');

    // Rendez-vous qui tient : il a un créneau et n'est ni clôturé ni remplacé
    const PREVUS = array('a_confirmer', 'confirme');
    const CLOS = array('effectue', 'absent', 'annule');

    // Changements de statut admis (CDC §11). « reporte » ne s'écrit que par replanifier().
    // Un rendez-vous clôturé se rétablit : une issue cochée par erreur doit pouvoir se corriger.
    const TRANSITIONS = array(
        'demande' => array('a_confirmer', 'confirme', 'annule'),
        'a_confirmer' => array('confirme', 'effectue', 'absent', 'annule'),
        'confirme' => array('a_confirmer', 'effectue', 'absent', 'annule'),
        'effectue' => array('absent', 'confirme'),
        'absent' => array('effectue', 'confirme'),
        'annule' => array('demande', 'a_confirmer', 'confirme'),
        'reporte' => array(),
    );

    const LIBELLES = array(
        'demande' => 'demandé', 'a_confirmer' => 'à confirmer', 'confirme' => 'confirmé', 'effectue' => 'effectué',
        'absent' => 'absent', 'annule' => 'annulé', 'reporte' => 'reporté',
    );

    // Du dossier, l'identité seulement. Les textes sont lus ici pour les écritures ; sortie() ne les sert qu'avec le droit famille.
    const COLONNES = "r.id_rdv, r.id_contact, r.id_rdv_precedent, r.id_rdv_origine, r.rang, r.type, r.statut, r.date_statut, r.date_debut, r.duree, r.date_fin, r.canal,
        r.prevenir, r.motif, r.motif_cloture, r.compte_rendu, r.date_compte_rendu, r.id_users_responsable, r.id_users, r.date_creation, r.date_modif,
        (SELECT s.id_rdv FROM r_rdv s WHERE s.id_rdv_precedent = r.id_rdv) AS id_rdv_suivant,
        (SELECT v.voie FROM r_reservation v WHERE v.id_rdv = r.id_rdv_origine) AS voie,
        c.prenom, c.nom, c.telephone, c.email, c.statut AS contact_statut, c.date_archivage AS contact_date_archivage,
        ur.identifiant AS responsable_identifiant, ur.prenom AS responsable_prenom, ur.nom AS responsable_nom,
        ua.identifiant AS auteur_identifiant, ua.prenom AS auteur_prenom, ua.nom AS auteur_nom,
        uc.identifiant AS redacteur_identifiant, uc.prenom AS redacteur_prenom, uc.nom AS redacteur_nom";

    const SQL_FROM = " FROM r_rdv r
        INNER JOIN d_contact c ON c.id_contact = r.id_contact
        LEFT JOIN u_users ur ON ur.id_users = r.id_users_responsable
        LEFT JOIN u_users ua ON ua.id_users = r.id_users
        LEFT JOIN u_users uc ON uc.id_users = r.id_users_compte_rendu";

    // LECTURE ########################################################

    public function charger($id_rdv)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT " . self::COLONNES . self::SQL_FROM . " WHERE r.id_rdv = ?", array((int) $id_rdv), 'i');
    }

    /**
     * Exige le droit demandé sur les rendez-vous, puis un rendez-vous dont le dossier est lisible.
     * Retourne array($user, $rdv) ; sinon 401 / 403 / 404 et exit.
     */
    public function exigerRdv($id_rdv, $niveau = 'L')
    {
        global $U, $Response;

        $user = $U->requireAccess('rendez_vous', $niveau);
        $rdv = (int) $id_rdv > 0 ? $this->charger($id_rdv) : null;
        if ($rdv === null) {
            $Response->notFound("Rendez-vous introuvable.");
        }
        if (!$U->can($user, Contact::groupeDe($rdv->contact_statut), 'L')) {
            $Response->forbidden("Vous n'avez pas accès au dossier de ce rendez-vous.");
        }

        return array($user, $rdv);
    }

    /**
     * Rendez-vous tel que servi au front. `passe` et `commence` sont calculés ici, à l'heure du serveur.
     * $avecTextes : droit famille ; sans lui, ni motif ni compte rendu (le profil de gestion lit l'agenda sans ses textes).
     */
    public function sortie($r, $avecTextes, $maintenant = null)
    {
        $maintenant = $maintenant ?? date('Y-m-d H:i:s');

        $out = array(
            'id_rdv' => (int) $r->id_rdv,
            'contact' => Suivi::dossier($r),
            'id_rdv_precedent' => $r->id_rdv_precedent === null ? null : (int) $r->id_rdv_precedent,
            'id_rdv_suivant' => $r->id_rdv_suivant === null ? null : (int) $r->id_rdv_suivant,
            'type' => $r->type,
            'statut' => $r->statut,
            'date_statut' => $r->date_statut,
            'date_debut' => $r->date_debut,
            'duree' => (int) $r->duree,
            'date_fin' => $r->date_fin,
            'canal' => $r->canal,
            // Le choix de la case « Prévenir le parent par e-mail », et s'il a une adresse où le prévenir
            'prevenir' => (int) $r->prevenir === 1,
            'parent_joignable' => $r->email !== null && $r->email !== '',
            // Pris en ligne par le parent : depuis la page publique ou son espace personnel ; sinon null
            'en_ligne' => $r->voie,
            'commence' => $r->date_debut !== null && $r->date_debut <= $maintenant,
            'passe' => $r->date_fin !== null && $r->date_fin < $maintenant,
            'a_compte_rendu' => $r->compte_rendu !== null,
            'responsable' => $r->id_users_responsable === null ? null : array(
                'id_users' => (int) $r->id_users_responsable,
                'nom' => Suivi::personne($r, 'responsable'),
            ),
            'auteur' => Suivi::personne($r, 'auteur'),
            'date_creation' => $r->date_creation,
            'date_modif' => $r->date_modif,
        );
        if ($avecTextes) {
            $out['motif'] = $r->motif;
            $out['motif_cloture'] = $r->motif_cloture;
            $out['compte_rendu'] = $r->compte_rendu;
            $out['date_compte_rendu'] = $r->date_compte_rendu;
            $out['redacteur'] = Suivi::personne($r, 'redacteur');
        }

        return $out;
    }

    /** Rendez-vous d'un dossier : les demandes sans créneau, puis du plus récent au plus ancien. Les créneaux remplacés n'y sont pas. */
    public function duDossier($id_contact, $avecTextes, $maintenant = null)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT " . self::COLONNES . self::SQL_FROM . " WHERE r.id_contact = ? AND r.statut <> 'reporte'
             ORDER BY (r.date_debut IS NULL) DESC, r.date_debut DESC, r.id_rdv DESC",
            array((int) $id_contact),
            'i'
        ) as $r) {
            $out[] = $this->sortie($r, $avecTextes, $maintenant);
        }

        return $out;
    }

    /** Chaîne des reports d'un rendez-vous, du premier créneau au dernier (lui compris). */
    public function chaine($rdv, $avecTextes)
    {
        $premier = $rdv;
        for ($i = 0; $i < 50 && $premier->id_rdv_precedent !== null; $i++) {
            $precedent = $this->charger($premier->id_rdv_precedent);
            if ($precedent === null) {
                break;
            }
            $premier = $precedent;
        }
        $out = array();
        $courant = $premier;
        for ($i = 0; $i < 50 && $courant !== null; $i++) {
            $out[] = $this->sortie($courant, $avecTextes);
            $courant = $courant->id_rdv_suivant === null ? null : $this->charger($courant->id_rdv_suivant);
        }

        return $out;
    }

    /**
     * Historique de rendez-vous (ceux d'une chaîne) : les faits du fil du dossier qui les concernent, du plus récent
     * au plus ancien, à la date où ils ont été notés. Codes seulement : aucun texte.
     */
    public function historique($ids)
    {
        global $Mysql;

        if (count($ids) === 0) {
            return array();
        }
        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT e.id_evenement, e.objet_id, e.details, e.origine, e.date_creation,
                    u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom
             FROM d_evenement e LEFT JOIN u_users u ON u.id_users = e.id_users
             WHERE e.objet_type = 'rdv' AND e.objet_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ")
             ORDER BY e.id_evenement DESC",
            array_map('intval', $ids)
        ) as $e) {
            $out[] = array(
                'id_evenement' => (int) $e->id_evenement,
                'id_rdv' => (int) $e->objet_id,
                'details' => $e->details === null ? null : json_decode($e->details, true),
                'origine' => $e->origine,
                'auteur' => Suivi::personne($e, 'auteur'),
                'date' => $e->date_creation,
            );
        }

        return $out;
    }

    // SAISIE #########################################################

    /** Champs descriptifs d'un rendez-vous. Le responsable est un utilisateur actif qui peut tenir l'agenda. */
    public function spec()
    {
        $profils = "'" . implode("', '", Suivi::profils('rendez_vous')) . "'";

        return array(
            'type' => array('type' => 'enum', 'valeurs' => self::TYPES, 'defaut' => 'decouverte'),
            'duree' => array('type' => 'int', 'min' => 5, 'max' => 600, 'defaut' => 60, 'libelle' => 'durée'),
            'canal' => array('type' => 'enum', 'valeurs' => self::CANAUX, 'defaut' => 'visio'),
            'motif' => array('type' => 'str', 'max' => 255),
            'id_users_responsable' => array('type' => 'fk', 'table' => 'u_users', 'col' => 'id_users', 'where' => "actif = 1 AND profil IN ($profils)", 'libelle' => 'responsable'),
        );
    }

    /**
     * Créneau saisi : `date` (AAAA-MM-JJ) et `heure` (HH:MM), à l'heure de Paris, ensemble ou pas du tout.
     * Retourne « AAAA-MM-JJ HH:MM:00 », ou null si aucun des deux n'est fourni ; 400 si $requis et absent.
     */
    public function lireCreneau($R, $requis = false)
    {
        global $S, $Response;

        $c = $S->lireChamps($R, array(
            'date' => array('type' => 'date', 'min' => date('Y-m-d', strtotime('-1 year')), 'max' => date('Y-m-d', strtotime('+2 years')), 'libelle' => 'date'),
            'heure' => array('type' => 'heure', 'libelle' => 'heure'),
        ), true);
        $date = $c['date'] ?? null;
        $heure = $c['heure'] ?? null;
        if ($date === null && $heure === null) {
            if ($requis) {
                $Response->validationError("Indiquez la date et l'heure du rendez-vous.");
            }

            return null;
        }
        if ($date === null || $heure === null) {
            $Response->validationError("La date et l'heure du rendez-vous vont ensemble.");
        }

        return "$date $heure:00";
    }

    /** Ce qu'il faut signaler sur un créneau, sans le refuser : il est passé, ou il chevauche un autre rendez-vous qui tient. */
    private function avertissements($debut, $duree, $id_exclu)
    {
        global $Mysql;

        $out = array();
        if ($debut < date('Y-m-d H:i:s')) {
            $out[] = "Ce créneau est déjà passé.";
        }
        $fin = date('Y-m-d H:i:s', strtotime($debut) + ((int) $duree) * 60);
        foreach ($Mysql->fetchAll(
            "SELECT r.date_debut, c.prenom, c.nom FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
             WHERE r.statut IN ('a_confirmer', 'confirme') AND r.date_debut < ? AND r.date_fin > ? AND r.id_rdv <> ?
             ORDER BY r.date_debut LIMIT 3",
            array($fin, $debut, (int) $id_exclu),
            'ssi'
        ) as $a) {
            $out[] = "Ce créneau chevauche un autre rendez-vous : " . trim((string) $a->prenom . ' ' . $a->nom) . ", " . Suivi::heure($a->date_debut) . ".";
        }

        return $out;
    }

    private function exigerDossierOuvert($id_contact)
    {
        global $Mysql, $Response;

        $etat = $Mysql->fetchOne("SELECT date_archivage FROM d_contact WHERE id_contact = ?", array((int) $id_contact), 'i');
        if ($etat->date_archivage !== null) {
            $Response->validationError("Ce dossier est classé sans suite : rouvrez-le avant de planifier un rendez-vous.");
        }
    }

    // ÉCRITURES ######################################################

    /** Trace un fait de rendez-vous : journal d'audit et fil du dossier (module rendez_vous). Codes seulement. */
    private function tracer($id_contact, $id_rdv, $action_audit, $fait, $details, $id_users, $date = null, $origine = 'utilisateur')
    {
        global $Contact;

        $Contact->tracer($id_contact, $id_users, $action_audit, array_merge(array('id_rdv' => (int) $id_rdv), $details), array(
            'type' => 'rdv', 'module' => 'rendez_vous', 'objet_type' => 'rdv', 'objet_id' => (int) $id_rdv,
            'details' => array_merge(array('action' => $fait), $details), 'date' => $date, 'origine' => $origine,
        ));
    }

    /** Origine d'un fait d'après les options d'une écriture : « parent » pour un geste fait en ligne, sinon l'utilisateur. */
    private static function origine($options)
    {
        return (isset($options['origine']) && $options['origine'] === 'parent') ? 'parent' : 'utilisateur';
    }

    /**
     * Verrou d'agenda : toute écriture qui fait tenir un créneau le prend AVANT d'ouvrir sa transaction et le rend
     * après l'avoir validée. Il met à la file les réservations en ligne (qui refusent un créneau pris) et les saisies
     * de l'outil (qui avertissent sans refuser). Ordre des verrous, partout : adresse e-mail, agenda, dossier.
     * Un script qui s'arrête le rend avec sa connexion.
     */
    public function prendreAgenda()
    {
        global $Mysql, $Response;

        $pris = $Mysql->fetchOne("SELECT GET_LOCK('navup_agenda', 10) AS pris");
        if ($pris === null || (int) $pris->pris !== 1) {
            $Response->validationError("L'agenda est occupé par une autre écriture : réessayez dans un instant.");
        }
    }

    public function rendreAgenda()
    {
        global $Mysql;

        $Mysql->fetchOne("SELECT RELEASE_LOCK('navup_agenda') AS rendu");
    }

    /**
     * Automatismes du dossier après une prise de rendez-vous, dans la même transaction (CDC §4).
     * - Demande sans créneau : un dossier « Prospect » ou « À relancer » passe « RDV demandé ».
     * - Créneau à venir fixé : un prospect passe « RDV planifié ».
     * Un client ne change jamais de statut par un rendez-vous, et rien ne fait reculer un statut.
     * Ils s'appliquent quel que soit le droit de l'auteur sur les dossiers : c'est le rendez-vous qui les déclenche.
     */
    private function automatismes($id_contact, $statut, $debut, $id_users)
    {
        global $Contact;

        $contact = $Contact->charger($id_contact);
        if (Contact::groupeDe($contact->statut) !== 'prospects') {
            return;
        }
        if ($statut === 'demande' && in_array($contact->statut, array('prospect', 'a_relancer'), true)) {
            $Contact->changerStatut($contact, 'rdv_demande', $id_users, 'automatique');
        } elseif (in_array($statut, self::PREVUS, true) && $debut > date('Y-m-d H:i:s')) {
            $Contact->changerStatut($contact, 'rdv_planifie', $id_users, 'automatique');
        }
    }

    /**
     * Ce que l'outil propose sans le faire : « à relancer » pour un prospect dont le rendez-vous vient d'être annulé
     * ou manqué et qui n'en a plus aucun, ni prévu ni demandé. Retourne le statut proposé, ou null.
     */
    public function proposition($id_contact)
    {
        global $Contact, $Mysql;

        $contact = $Contact->charger($id_contact);
        if (!in_array($contact->statut, array('rdv_demande', 'rdv_planifie'), true)) {
            return null;
        }
        $restants = (int) $Mysql->fetchOne(
            "SELECT COUNT(*) AS nb FROM r_rdv WHERE id_contact = ? AND (statut = 'demande' OR (statut IN ('a_confirmer', 'confirme') AND date_fin >= ?))",
            array((int) $id_contact, date('Y-m-d H:i:s')),
            'is'
        )->nb;

        return $restants === 0 ? 'a_relancer' : null;
    }

    /**
     * Enregistre un rendez-vous. $debut null : une demande sans créneau ; sinon $statut vaut a_confirmer ou confirme.
     * $data : champs de spec(). Retourne array(id_rdv, avertissements, deja).
     * Enveloppe de inscrire() : verrou d'agenda, verrou du dossier, transaction.
     */
    public function creer($contact, $data, $debut, $statut, $cle, $id_users, $options = array())
    {
        global $SQL, $Contact;

        $this->prendreAgenda();
        try {
            $Contact->verrouiller((int) $contact->id_contact);
            $res = $this->inscrire($contact, $data, $debut, $statut, $cle, $id_users, $options);
            $SQL->commit();
        } finally {
            $this->rendreAgenda();
        }

        $this->expedier((int) $contact->id_contact);

        return $res;
    }

    /**
     * Écriture d'un rendez-vous, sans transaction : l'appelant tient le verrou d'agenda, celui du dossier et la
     * transaction (la réservation en ligne y écrit aussi le dossier, le consentement et ce que le parent a déclaré).
     * $options : origine ('parent' pour une réservation en ligne), prevenir (case « Prévenir le parent par e-mail »).
     */
    public function inscrire($contact, $data, $debut, $statut, $cle, $id_users, $options = array())
    {
        global $Mysql, $S, $Tache;

        $idc = (int) $contact->id_contact;

        if ($cle !== null) {
            $deja = $Mysql->fetchOne("SELECT id_rdv FROM r_rdv WHERE cle_saisie = ?", array($cle), 's');
            if ($deja !== null) {
                return array('id_rdv' => (int) $deja->id_rdv, 'avertissements' => array(), 'deja' => true);
            }
        }
        $this->exigerDossierOuvert($idc);

        $statut = $debut === null ? 'demande' : $statut;
        $avertissements = $debut === null ? array() : $this->avertissements($debut, $data['duree'], 0);

        $idr = $S->inserer('r_rdv', array(
            'id_contact' => $idc,
            'type' => $data['type'],
            'statut' => $statut,
            'date_debut' => $debut,
            'duree' => $data['duree'],
            'canal' => $data['canal'],
            'prevenir' => empty($options['prevenir']) ? 0 : 1,
            'motif' => $data['motif'] ?? null,
            'id_users_responsable' => $data['id_users_responsable'] ?? $id_users,
            'cle_saisie' => $cle,
            'id_users' => $id_users,
        ));
        // Premier de sa chaîne : il en est l'origine
        $Mysql->execute("UPDATE r_rdv SET id_rdv_origine = id_rdv WHERE id_rdv = ?", array($idr), 'i');

        // Ce que le parent a déclaré en réservant : gardé tel quel, à côté du rendez-vous
        if (!empty($options['reservation'])) {
            $v = $options['reservation'];
            $S->inserer('r_reservation', array(
                'id_rdv' => $idr,
                'voie' => $v['voie'],
                'prenom' => $v['prenom'] ?? null,
                'nom' => $v['nom'] ?? null,
                'email' => $v['email'] ?? null,
                'telephone' => $v['telephone'] ?? null,
                'note' => $v['note'] ?? null,
            ));
        }

        $origine = self::origine($options);
        $details = array('statut' => $statut);
        if (!empty($options['reservation'])) {
            $details['via'] = $options['reservation']['voie'];
        }
        $this->tracer($idc, $idr, 'rdv_create', 'creation', $details, $id_users, null, $origine);
        $this->automatismes($idc, $statut, $debut, $id_users);
        $Tache->synchroniser($idc);

        if ($statut === 'confirme' && $debut > date('Y-m-d H:i:s') && !empty($options['prevenir'])) {
            $this->notifier($idr, 'rdv_confirmation', array(), $options, $id_users);
        }
        if ($origine === 'parent') {
            $this->aviser($idr, 'pris');
        }

        return array('id_rdv' => $idr, 'avertissements' => $avertissements, 'deja' => false);
    }

    /** Corrige les champs descriptifs d'un rendez-vous (type, durée, canal, motif, responsable). Retourne les champs modifiés. */
    public function modifier($rdv, $data, $id_users)
    {
        global $SQL, $S, $Contact, $Tache, $Response;

        $idc = (int) $rdv->id_contact;
        $Contact->verrouiller($idc);
        $r = $this->charger($rdv->id_rdv);
        if ($r->statut === 'reporte') {
            $Response->validationError("Ce rendez-vous a été déplacé : c'est son nouveau créneau qui se modifie.");
        }

        $modifies = $S->differences($r, $data);
        if (count($modifies) > 0) {
            $S->mettreAJour('r_rdv', 'id_rdv', (int) $r->id_rdv, $data);
            $this->tracer($idc, $r->id_rdv, 'rdv_update', 'modification', array('champs' => $modifies), $id_users);
            // La durée décide de l'heure de fin, donc du moment où le compte rendu est attendu
            $Tache->synchroniser($idc);

            // Le parent qui tient un créneau confirmé apprend que sa durée ou son canal a changé
            $prevenir = array_key_exists('prevenir', $data) ? (int) $data['prevenir'] === 1 : (int) $r->prevenir === 1;
            if ($prevenir && $r->statut === 'confirme' && $r->date_debut > date('Y-m-d H:i:s') && count(array_intersect($modifies, array('canal', 'duree'))) > 0) {
                $this->notifier((int) $r->id_rdv, 'rdv_modification', array(), array(), $id_users);
            }
        }
        $SQL->commit();
        $this->expedier($idc);

        return $modifies;
    }

    /**
     * Seul point d'écriture de r_rdv.statut, avec replanifier(). Sans effet si le statut ne change pas.
     * $options : debut (créneau d'une demande qu'on planifie), duree, motif_cloture (annulation, absence),
     * compte_rendu (avec « effectué »), prevenir (case « Prévenir le parent par e-mail » : absente, le choix gardé
     * par le rendez-vous vaut), origine. Un créneau fixé ne se modifie pas ici : il se déplace par replanifier().
     * Retourne les avertissements. Enveloppe de ecrireStatut().
     */
    public function changerStatut($rdv, $nouveau, $options, $id_users)
    {
        global $SQL, $Contact;

        $this->prendreAgenda();
        try {
            $Contact->verrouiller((int) $rdv->id_contact);
            $avertissements = $this->ecrireStatut($rdv, $nouveau, $options, $id_users);
            $SQL->commit();
        } finally {
            $this->rendreAgenda();
        }

        $this->expedier((int) $rdv->id_contact);

        return $avertissements;
    }

    /** Changement de statut, sans transaction : l'appelant tient les verrous (agenda, dossier) et la transaction. */
    public function ecrireStatut($rdv, $nouveau, $options, $id_users)
    {
        global $S, $Interaction, $Tache, $Response;

        $idc = (int) $rdv->id_contact;
        $r = $this->charger($rdv->id_rdv);
        $ancien = $r->statut;
        $maintenant = date('Y-m-d H:i:s');

        if ($ancien === $nouveau) {
            return array();
        }
        if (!in_array($nouveau, self::TRANSITIONS[$ancien], true)) {
            $Response->validationError("Un rendez-vous « " . self::LIBELLES[$ancien] . " » ne peut pas passer à « " . self::LIBELLES[$nouveau] . " ».");
        }

        $set = array('statut' => $nouveau, 'date_statut' => $maintenant);
        if (array_key_exists('prevenir', $options) && $options['prevenir'] !== null) {
            $set['prevenir'] = empty($options['prevenir']) ? 0 : 1;
        }
        $debut = $r->date_debut;
        $details = array('avant' => $ancien, 'apres' => $nouveau);
        $avertissements = array();
        $dateFait = null;

        if (in_array($nouveau, self::PREVUS, true)) {
            if ($debut === null) {
                if (empty($options['debut'])) {
                    $Response->validationError("Indiquez la date et l'heure du rendez-vous.");
                }
                $this->exigerDossierOuvert($idc);
                $debut = $options['debut'];
                $set['date_debut'] = $debut;
                if (isset($options['duree'])) {
                    $set['duree'] = (int) $options['duree'];
                }
                $avertissements = $this->avertissements($debut, $set['duree'] ?? (int) $r->duree, (int) $r->id_rdv);
                $fait = 'planification';
            } elseif (in_array($ancien, self::PREVUS, true)) {
                $fait = $nouveau === 'confirme' ? 'confirmation' : 'a_confirmer';
            } else {
                // Rétabli à son créneau : un autre rendez-vous a pu le prendre entre-temps
                $avertissements = $this->avertissements($debut, (int) $r->duree, (int) $r->id_rdv);
                $fait = 'retablissement';
            }
            $set['motif_cloture'] = null;
        } elseif ($nouveau === 'demande') {
            if ($debut !== null) {
                $Response->validationError("Ce rendez-vous avait un créneau : rétablissez-le à ce créneau, ou replanifiez-le.");
            }
            $set['motif_cloture'] = null;
            $fait = 'retablissement';
        } elseif ($nouveau === 'annule') {
            $set['motif_cloture'] = $options['motif_cloture'] ?? null;
            $fait = 'annulation';
        } else {
            // effectué, absent : des faits, qui ne se notent pas à l'avance et se lisent à la date du rendez-vous
            if ($debut === null || $debut > $maintenant) {
                $Response->validationError("Ce rendez-vous n'a pas encore commencé.");
            }
            $fait = $nouveau;
            $dateFait = $debut;
            $set['motif_cloture'] = $nouveau === 'absent' ? ($options['motif_cloture'] ?? null) : null;
            if ($nouveau === 'effectue' && isset($options['compte_rendu'])) {
                $set['compte_rendu'] = $options['compte_rendu'];
                $set['date_compte_rendu'] = $maintenant;
                $set['id_users_compte_rendu'] = $id_users;
                $details['compte_rendu'] = true;
            }
        }

        $S->mettreAJour('r_rdv', 'id_rdv', (int) $r->id_rdv, $set);
        $this->tracer($idc, $r->id_rdv, 'rdv_statut', $fait, $details, $id_users, $dateFait, self::origine($options));

        if (in_array($nouveau, self::PREVUS, true)) {
            $this->automatismes($idc, $nouveau, $debut, $id_users);
        }
        if ($ancien === 'effectue' || $nouveau === 'effectue') {
            $Interaction->recalculerDerniere($idc);
        }
        $Tache->synchroniser($idc);

        // Le parent n'est prévenu que d'un créneau confirmé, à venir : il se confirme, ou il s'annule après l'avoir été
        $parent = self::origine($options) === 'parent';
        $prevenir = isset($set['prevenir']) ? $set['prevenir'] === 1 : (int) $r->prevenir === 1;
        if ($prevenir && $debut !== null && $debut > $maintenant) {
            if ($nouveau === 'confirme') {
                $this->notifier((int) $r->id_rdv, 'rdv_confirmation', array(), $options, $id_users);
            } elseif ($nouveau === 'annule' && ($ancien === 'confirme' || $parent)) {
                $this->notifier((int) $r->id_rdv, 'rdv_annulation', array(), $options, $id_users);
            }
        }
        if ($parent && $nouveau === 'annule') {
            $this->aviser((int) $r->id_rdv, 'annule');
        }

        return $avertissements;
    }

    /**
     * Déplace un rendez-vous (CDC §11 : replanifier sans perdre l'historique) : un nouveau rendez-vous, chaîné à
     * l'ancien, prend le nouveau créneau. L'ancien garde le sien ; il devient « reporté » s'il tenait encore,
     * et reste « absent » ou « annulé » sinon. Un rendez-vous n'a qu'un successeur : un double envoi rend le premier.
     * Retourne array(id_rdv, avertissements, deja). Enveloppe de ecrireReport().
     */
    public function replanifier($rdv, $debut, $duree, $statut, $id_users, $options = array())
    {
        global $SQL, $Contact;

        $this->prendreAgenda();
        try {
            $Contact->verrouiller((int) $rdv->id_contact);
            $res = $this->ecrireReport($rdv, $debut, $duree, $statut, $id_users, $options);
            $SQL->commit();
        } finally {
            $this->rendreAgenda();
        }

        $this->expedier((int) $rdv->id_contact);

        return $res;
    }

    /**
     * Déplacement, sans transaction : l'appelant tient les verrous (agenda, dossier) et la transaction.
     * $options : origine, prevenir (absente : le choix de l'ancien créneau suit), responsable (celui qui est libre
     * au nouveau créneau, pour un déplacement fait en ligne).
     */
    public function ecrireReport($rdv, $debut, $duree, $statut, $id_users, $options = array())
    {
        global $Mysql, $S, $Tache, $Response;

        $idc = (int) $rdv->id_contact;
        $r = $this->charger($rdv->id_rdv);

        if ($r->id_rdv_suivant !== null) {
            return array('id_rdv' => (int) $r->id_rdv_suivant, 'avertissements' => array(), 'deja' => true);
        }
        if ($r->date_debut === null || !in_array($r->statut, array('a_confirmer', 'confirme', 'absent', 'annule'), true)) {
            $Response->validationError($r->statut === 'demande'
                ? "Cette demande n'a pas encore de créneau : fixez-le plutôt que de la déplacer."
                : "Seul un rendez-vous prévu, manqué ou annulé se replanifie.");
        }
        $this->exigerDossierOuvert($idc);

        $duree = $duree === null ? (int) $r->duree : (int) $duree;
        $avertissements = $this->avertissements($debut, $duree, (int) $r->id_rdv);

        $prevenir = (array_key_exists('prevenir', $options) && $options['prevenir'] !== null) ? (empty($options['prevenir']) ? 0 : 1) : (int) $r->prevenir;
        $responsable = array_key_exists('responsable', $options)
            ? ($options['responsable'] === null ? null : (int) $options['responsable'])
            : ($r->id_users_responsable === null ? null : (int) $r->id_users_responsable);

        $idn = $S->inserer('r_rdv', array(
            'id_contact' => $idc,
            'id_rdv_precedent' => (int) $r->id_rdv,
            'id_rdv_origine' => (int) ($r->id_rdv_origine ?? $r->id_rdv),
            'rang' => (int) $r->rang + 1,
            'type' => $r->type,
            'statut' => $statut,
            'date_debut' => $debut,
            'duree' => $duree,
            'canal' => $r->canal,
            'prevenir' => $prevenir,
            'motif' => $r->motif,
            'id_users_responsable' => $responsable,
            'id_users' => $id_users,
        ));
        if (in_array($r->statut, self::PREVUS, true)) {
            $Mysql->execute("UPDATE r_rdv SET statut = 'reporte', date_statut = NOW(), date_modif = NOW() WHERE id_rdv = ?", array((int) $r->id_rdv), 'i');
        }
        $this->tracer($idc, $idn, 'rdv_report', 'report', array('id_precedent' => (int) $r->id_rdv, 'statut' => $statut), $id_users, null, self::origine($options));
        $this->automatismes($idc, $statut, $debut, $id_users);
        $Tache->synchroniser($idc);

        // Le parent tenait un créneau confirmé : il apprend qu'il a bougé, même si le nouveau reste à confirmer.
        // Un rendez-vous replanifié après une annulation ou une absence s'annonce comme un nouveau créneau.
        $maintenant = date('Y-m-d H:i:s');
        $tenait = $r->statut === 'confirme' && $r->date_debut > $maintenant;
        if ($prevenir === 1 && $debut > $maintenant) {
            if ($statut === 'confirme') {
                $this->notifier($idn, $tenait ? 'rdv_modification' : 'rdv_confirmation', $tenait ? array('ancien_debut' => $r->date_debut) : array(), $options, $id_users);
            } elseif ($tenait) {
                $this->notifier($idn, 'rdv_modification', array('ancien_debut' => $r->date_debut, 'a_confirmer' => true), $options, $id_users);
            }
        }
        if (self::origine($options) === 'parent') {
            $this->aviser($idn, 'deplace', array('ancien_debut' => $r->date_debut));
        }

        return array('id_rdv' => $idn, 'avertissements' => $avertissements, 'deja' => false);
    }

    // PRÉVENIR ET LAISSER LA MAIN AU PARENT (étape 6b) ###############

    /** Ce que le parent a déclaré en réservant en ligne (r_reservation), ou null pour un rendez-vous saisi dans l'outil. */
    public function reservation($r)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT * FROM r_reservation WHERE id_rdv = ?", array((int) ($r->id_rdv_origine ?? $r->id_rdv)), 'i');
    }

    /** Lien de visio de la personne qui mène le rendez-vous, ou null. */
    public function lienVisio($r)
    {
        global $Mysql;

        if ($r->canal !== 'visio' || $r->id_users_responsable === null) {
            return null;
        }
        $a = $Mysql->fetchOne("SELECT lien_visio FROM u_agenda WHERE id_users = ?", array((int) $r->id_users_responsable), 'i');

        return ($a === null || $a->lien_visio === '') ? null : $a->lien_visio;
    }

    /**
     * Dépose l'e-mail au parent que cause un fait de rendez-vous, dans la transaction de ce fait : pas de message
     * sans fait, pas de fait sans message. Sans effet si l'endpoint n'a pas chargé les e-mails.
     * $plus : ancien_debut (déplacement), a_confirmer.
     */
    private function notifier($id_rdv, $modele, $plus, $options, $id_users)
    {
        global $Message, $Contact, $_APP_PARENTS_URL, $_RDV_MODIFIABLE_HEURES;

        if (!isset($Message)) {
            return;
        }
        $r = $this->charger($id_rdv);
        $reservation = $this->reservation($r);
        $appli = (isset($_APP_PARENTS_URL) && $_APP_PARENTS_URL !== '') ? $_APP_PARENTS_URL : null;
        $parent = self::origine($options) === 'parent';

        $Message->deposer($Contact->charger($r->id_contact), $modele, array_merge(array(
            'type' => $r->type,
            'date_debut' => $r->date_debut,
            'duree' => (int) $r->duree,
            'canal' => $r->canal,
            'visio' => $this->lienVisio($r),
            'gestion' => $appli !== null,
            'heures' => (int) $_RDV_MODIFIABLE_HEURES,
            'appli' => $appli,
            // Pris sur la page publique : l'adresse n'est pas prouvée, l'e-mail ne recopie rien de la saisie
            'inconnu' => $reservation !== null && $reservation->voie === 'public',
            'par_parent' => $parent,
        ), $plus), 'rdv:' . $modele . ':' . (int) $id_rdv . ':' . date('YmdHis'), array(
            'objet_type' => 'rdv', 'objet_id' => (int) $id_rdv,
            'origine' => ($parent || $id_users === null) ? 'automatique' : 'utilisateur', 'id_users' => $id_users,
        ));
    }

    /**
     * Prévient la personne qui mène le rendez-vous de ce qu'un parent vient de faire en ligne ($fait : pris, deplace,
     * annule) : un calendrier abonné ne se met à jour que quelques fois par jour. L'avis ne dit que ce que montre le
     * flux d'agenda : type, prénom et initiale, créneau, canal, lien vers la fiche.
     */
    private function aviser($id_rdv, $fait, $plus = array())
    {
        global $Message, $Mysql, $_URL_TOUR;

        if (!isset($Message)) {
            return;
        }
        $r = $this->charger($id_rdv);
        $u = $r->id_users_responsable === null ? null : $Mysql->fetchOne(
            "SELECT prenom, email FROM u_users WHERE id_users = ? AND actif = 1",
            array((int) $r->id_users_responsable),
            'i'
        );
        if ($u === null) {
            return;
        }
        $canaux = array('visio' => 'en visio', 'telephone' => 'par téléphone', 'presentiel' => 'en personne');
        $destinataire = (object) array('id_contact' => (int) $r->id_contact, 'prenom' => $u->prenom, 'email' => $u->email);

        $Message->deposer($destinataire, 'rdv_avis', array_merge(array(
            'fait' => $fait,
            'titre' => self::titre($r),
            'date_debut' => $r->date_debut,
            'duree' => (int) $r->duree,
            'canal_libelle' => $canaux[$r->canal],
            'fiche' => (isset($_URL_TOUR) && $_URL_TOUR !== '') ? $_URL_TOUR . 'rendez-vous/' . (int) $id_rdv : null,
            'a_relancer' => $fait === 'annule' && $this->proposition($r->id_contact) === 'a_relancer',
        ), $plus), 'rdv:rdv_avis:' . (int) $id_rdv . ':' . $fait . ':' . date('YmdHis'), array('objet_type' => 'rdv', 'objet_id' => (int) $id_rdv));
    }

    /** « Découverte · Sophie M. » : type, prénom et initiale du nom. C'est tout ce qui sort du dossier vers un calendrier ou un avis. */
    public static function titre($r)
    {
        $types = array('decouverte' => 'Découverte', 'suivi' => 'Accompagnement', 'bilan' => 'Bilan', 'autre' => 'Rendez-vous');
        $initiale = mb_strtoupper(mb_substr(trim((string) $r->nom), 0, 1));
        $qui = trim(trim((string) $r->prenom) . ($initiale !== '' ? ' ' . $initiale . '.' : ''));

        return $types[$r->type] . ($qui !== '' ? ' · ' . $qui : '');
    }

    /** Envoie ce qui vient d'être déposé pour un dossier, une fois l'écriture validée. Sans effet sans les e-mails. */
    public function expedier($id_contact)
    {
        global $Message;

        if (isset($Message)) {
            $Message->envoyerEnAttente((int) $id_contact);
        }
    }

    /**
     * Rappels (passe « rdv-rappels » de la tâche planifiée) : un e-mail avant chaque rendez-vous confirmé dont le
     * parent est prévenu. Pas de rappel pour un rendez-vous confirmé peu avant son heure : la confirmation en tient lieu.
     * Un seul rappel par rendez-vous (clé du message). Retourne le nombre de rappels déposés.
     */
    public function rappeler()
    {
        global $SQL, $Mysql, $Contact, $_RDV_RAPPEL_HEURES, $_RDV_RAPPEL_PRIS_AVANT_HEURES;

        $n = 0;
        foreach ($Mysql->fetchAll(
            "SELECT r.id_rdv, r.id_contact FROM r_rdv r
             WHERE r.statut = 'confirme' AND r.prevenir = 1 AND r.date_debut > NOW() AND r.date_debut <= NOW() + INTERVAL ? HOUR
               AND r.date_statut <= r.date_debut - INTERVAL ? HOUR
               AND NOT EXISTS (SELECT 1 FROM m_message m WHERE m.cle = CONCAT('rdv:rdv_rappel:', r.id_rdv))
             ORDER BY r.date_debut",
            array((int) $_RDV_RAPPEL_HEURES, (int) $_RDV_RAPPEL_PRIS_AVANT_HEURES),
            'ii'
        ) as $ligne) {
            $Contact->verrouiller((int) $ligne->id_contact);
            $r = $this->charger($ligne->id_rdv);
            if ($r !== null && $r->statut === 'confirme' && $this->deposerRappel($r)) {
                $n++;
            }
            $SQL->commit();
        }

        return $n;
    }

    private function deposerRappel($r)
    {
        global $Message, $Contact, $_APP_PARENTS_URL, $_RDV_MODIFIABLE_HEURES;

        $reservation = $this->reservation($r);
        $appli = (isset($_APP_PARENTS_URL) && $_APP_PARENTS_URL !== '') ? $_APP_PARENTS_URL : null;

        return $Message->deposer($Contact->charger($r->id_contact), 'rdv_rappel', array(
            'type' => $r->type, 'date_debut' => $r->date_debut, 'duree' => (int) $r->duree, 'canal' => $r->canal,
            'visio' => $this->lienVisio($r), 'gestion' => $appli !== null, 'heures' => (int) $_RDV_MODIFIABLE_HEURES,
            'inconnu' => $reservation !== null && $reservation->voie === 'public',
        ), 'rdv:rdv_rappel:' . (int) $r->id_rdv, array('objet_type' => 'rdv', 'objet_id' => (int) $r->id_rdv)) !== null;
    }

    /**
     * Crée un lien de gestion pour la chaîne d'un rendez-vous et rend son adresse ; null si l'appli des parents n'a pas
     * d'adresse. Seule l'empreinte du jeton est gardée : l'adresse ne se relit nulle part. Appelé à l'envoi d'un e-mail.
     */
    public function lienGestion($r)
    {
        global $Mysql, $_APP_PARENTS_URL;

        if (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
            return null;
        }
        $jeton = bin2hex(random_bytes(24));
        $Mysql->execute(
            "INSERT INTO r_lien (id_rdv, jeton) VALUES (?, ?)",
            array((int) ($r->id_rdv_origine ?? $r->id_rdv), hash('sha256', $jeton)),
            'is'
        );

        return $_APP_PARENTS_URL . 'rendez-vous#' . $jeton;
    }

    /**
     * Rendez-vous que désigne un jeton de gestion : le dernier créneau de sa chaîne (le lien suit le rendez-vous déplacé).
     * null si le jeton est inconnu ou révoqué.
     */
    public function parJeton($jeton)
    {
        global $Mysql;

        if (!is_string($jeton) || preg_match('/^[0-9a-f]{48}$/', $jeton) !== 1) {
            return null;
        }
        $lien = $Mysql->fetchOne("SELECT id_rdv FROM r_lien WHERE jeton = ? AND date_revocation IS NULL", array(hash('sha256', $jeton)), 's');
        if ($lien === null) {
            return null;
        }

        return $this->dernier((int) $lien->id_rdv);
    }

    /** Dernier créneau d'une chaîne, d'après son origine. */
    public function dernier($id_origine)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT " . self::COLONNES . self::SQL_FROM . " WHERE r.id_rdv_origine = ? ORDER BY r.rang DESC LIMIT 1",
            array((int) $id_origine),
            'i'
        );
    }

    /** Révoque les liens de gestion des rendez-vous d'un dossier : son adresse e-mail vient de changer. Sans transaction. */
    public function revoquerLiens($id_contact)
    {
        global $Mysql;

        return $Mysql->execute(
            "UPDATE r_lien l INNER JOIN r_rdv r ON r.id_rdv = l.id_rdv SET l.date_revocation = NOW()
             WHERE r.id_contact = ? AND l.date_revocation IS NULL",
            array((int) $id_contact),
            'i'
        );
    }

    /**
     * Ce que le parent peut encore faire lui-même sur un rendez-vous : array(annulable, deplacable).
     * Jusqu'au délai avant l'heure dite ; un déplacement demande aussi que le type se prenne en ligne et que le
     * nombre de déplacements ne soit pas atteint.
     */
    public function gestesParent($r)
    {
        global $_RDV_MODIFIABLE_HEURES, $_RDV_DEPLACEMENTS_MAX, $_RDV_PRISE;

        $tient = in_array($r->statut, self::PREVUS, true) && $r->date_debut !== null
            && $r->date_debut > date('Y-m-d H:i:s', time() + (int) $_RDV_MODIFIABLE_HEURES * 3600);

        return array(
            'annulable' => $tient,
            'deplacable' => $tient && isset($_RDV_PRISE[$r->type]) && (int) $r->rang < (int) $_RDV_DEPLACEMENTS_MAX,
        );
    }

    /**
     * Rendez-vous tel que le parent le voit (page de gestion, espace personnel) : ni note, ni nom d'utilisateur.
     * Un rendez-vous passé se dit « passé », qu'il ait eu lieu ou non : rien n'est mesuré du parent.
     */
    public function sortieParent($r)
    {
        $maintenant = date('Y-m-d H:i:s');
        if ($r->statut === 'annule') {
            $etat = 'annule';
        } elseif (($r->date_fin !== null && $r->date_fin < $maintenant) || in_array($r->statut, array('effectue', 'absent'), true)) {
            $etat = 'passe';
        } else {
            $etat = $r->statut === 'confirme' ? 'confirme' : 'a_confirmer';
        }
        $gestes = in_array($etat, array('confirme', 'a_confirmer'), true) ? $this->gestesParent($r) : array('annulable' => false, 'deplacable' => false);

        return array(
            'id_rdv' => (int) $r->id_rdv,
            'type' => $r->type,
            'etat' => $etat,
            'date_debut' => $r->date_debut,
            'duree' => (int) $r->duree,
            'canal' => $r->canal,
            'visio' => $etat === 'confirme' ? $this->lienVisio($r) : null,
            'annulable' => $gestes['annulable'],
            'deplacable' => $gestes['deplacable'],
        );
    }

    /** Écrit, corrige ou retire (texte null) le compte rendu d'un rendez-vous effectué. */
    public function ecrireCompteRendu($rdv, $texte, $id_users)
    {
        global $SQL, $Mysql, $Contact, $Tache, $Response;

        $idc = (int) $rdv->id_contact;
        $Contact->verrouiller($idc);
        $r = $this->charger($rdv->id_rdv);
        if ($r->statut !== 'effectue') {
            $Response->validationError("Le compte rendu s'écrit une fois le rendez-vous noté « effectué ».");
        }
        if ($r->compte_rendu !== $texte) {
            $etat = $texte === null ? 'retrait' : ($r->compte_rendu === null ? 'ajout' : 'correction');
            $Mysql->execute(
                "UPDATE r_rdv SET compte_rendu = ?, date_compte_rendu = ?, id_users_compte_rendu = ?, date_modif = NOW() WHERE id_rdv = ?",
                array($texte, $texte === null ? null : date('Y-m-d H:i:s'), $texte === null ? null : $id_users, (int) $r->id_rdv),
                'ssii'
            );
            $this->tracer($idc, $r->id_rdv, 'rdv_compte_rendu', 'compte_rendu', array('etat' => $etat), $id_users);
            $Tache->synchroniser($idc);
        }
        $SQL->commit();
    }
}

class Interaction
{
    const CANAUX = array('appel', 'email', 'message', 'rencontre');
    const SENS = array('sortant', 'entrant');
    const RESULTATS = array('abouti', 'sans_reponse', 'a_rappeler');

    // L'heure saisie peut devancer un peu celle du serveur (horloge du poste) : au-delà, l'échange est refusé
    const TOLERANCE_MINUTES = 10;

    // Échange qui a eu lieu : tout sauf un appel resté sans réponse ou à rappeler. Seul critère du « dernier échange ».
    const SQL_ABOUTI = "(i.canal <> 'appel' OR i.resultat = 'abouti')";

    const COLONNES = "i.id_interaction, i.id_contact, i.canal, i.sens, i.date_interaction, i.duree, i.resultat, i.date_rappel,
        i.motif, i.compte_rendu, i.id_tache, i.id_users, i.date_creation, i.date_modif,
        c.prenom, c.nom, c.telephone, c.statut AS contact_statut, c.date_archivage AS contact_date_archivage,
        u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom";

    const SQL_FROM = " FROM i_interaction i
        INNER JOIN d_contact c ON c.id_contact = i.id_contact
        LEFT JOIN u_users u ON u.id_users = i.id_users";

    // LECTURE ########################################################

    public function charger($id_interaction)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT " . self::COLONNES . self::SQL_FROM . " WHERE i.id_interaction = ?", array((int) $id_interaction), 'i');
    }

    /**
     * Exige le droit demandé sur les appels, puis un échange dont le dossier est lisible.
     * Retourne array($user, $echange) ; sinon 401 / 403 / 404 et exit.
     */
    public function exigerEchange($id_interaction, $niveau = 'L')
    {
        global $U, $Response;

        $user = $U->requireAccess('appels', $niveau);
        $echange = (int) $id_interaction > 0 ? $this->charger($id_interaction) : null;
        if ($echange === null) {
            $Response->notFound("Échange introuvable.");
        }
        if (!$U->can($user, Contact::groupeDe($echange->contact_statut), 'L')) {
            $Response->forbidden("Vous n'avez pas accès au dossier de cet échange.");
        }

        return array($user, $echange);
    }

    /** Échange tel que servi au front. $avecTextes : droit famille ; sans lui, ni motif ni compte rendu. */
    public function sortie($i, $avecTextes)
    {
        $out = array(
            'id_interaction' => (int) $i->id_interaction,
            'contact' => Suivi::dossier($i),
            'canal' => $i->canal,
            'sens' => $i->sens,
            'date_interaction' => $i->date_interaction,
            'duree' => $i->duree === null ? null : (int) $i->duree,
            'resultat' => $i->resultat,
            'date_rappel' => $i->date_rappel,
            'id_tache' => $i->id_tache === null ? null : (int) $i->id_tache,
            'id_users' => $i->id_users === null ? null : (int) $i->id_users,
            'auteur' => Suivi::personne($i, 'auteur'),
            'date_creation' => $i->date_creation,
            'date_modif' => $i->date_modif,
        );
        if ($avecTextes) {
            $out['motif'] = $i->motif;
            $out['compte_rendu'] = $i->compte_rendu;
        }

        return $out;
    }

    /** Derniers échanges d'un dossier, du plus récent au plus ancien. */
    public function duDossier($id_contact, $avecTextes, $limit = 10)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT " . self::COLONNES . self::SQL_FROM . " WHERE i.id_contact = ? ORDER BY i.date_interaction DESC, i.id_interaction DESC LIMIT ?",
            array((int) $id_contact, (int) $limit),
            'ii'
        ) as $i) {
            $out[] = $this->sortie($i, $avecTextes);
        }

        return $out;
    }

    /**
     * Dernier échange qui a eu lieu sur un dossier : le plus récent parmi les rendez-vous effectués et les échanges
     * aboutis (un appel sans réponse ou une absence ne comptent pas). Retourne array(nature, date) ou null ;
     * nature : rdv, appel, email, message, rencontre.
     */
    public function dernier($id_contact)
    {
        global $Mysql;

        $id = (int) $id_contact;
        $d = $Mysql->fetchOne(
            "SELECT x.nature, x.moment FROM (
                 SELECT i.canal AS nature, i.date_interaction AS moment FROM i_interaction i WHERE i.id_contact = ? AND " . self::SQL_ABOUTI . "
                 UNION ALL
                 SELECT 'rdv' AS nature, r.date_debut AS moment FROM r_rdv r WHERE r.id_contact = ? AND r.statut = 'effectue'
             ) x ORDER BY x.moment DESC LIMIT 1",
            array($id, $id),
            'ii'
        );

        return $d === null ? null : array('nature' => $d->nature, 'date' => $d->moment);
    }

    /**
     * Seul point d'écriture de d_contact.date_derniere_interaction (cache de dernier(), lu par les listes).
     * À appeler dans la transaction de chaque écriture d'un échange ou d'une issue de rendez-vous.
     */
    public function recalculerDerniere($id_contact)
    {
        global $Mysql;

        $d = $this->dernier($id_contact);
        $Mysql->execute(
            "UPDATE d_contact SET date_derniere_interaction = ? WHERE id_contact = ?",
            array($d === null ? null : $d['date'], (int) $id_contact),
            'si'
        );
    }

    // SAISIE #########################################################

    public function spec()
    {
        return array(
            'canal' => array('type' => 'enum', 'valeurs' => self::CANAUX, 'defaut' => 'appel'),
            'sens' => array('type' => 'enum', 'valeurs' => self::SENS, 'defaut' => 'sortant'),
            'date' => array('type' => 'date', 'requis' => true, 'min' => date('Y-m-d', strtotime('-2 years')), 'max' => date('Y-m-d'), 'libelle' => 'date'),
            'heure' => array('type' => 'heure', 'requis' => true, 'libelle' => 'heure'),
            'duree' => array('type' => 'int', 'min' => 1, 'max' => 600, 'libelle' => 'durée'),
            'resultat' => array('type' => 'enum', 'valeurs' => self::RESULTATS, 'libelle' => 'résultat'),
            'date_rappel' => array('type' => 'date', 'max' => date('Y-m-d', strtotime('+2 years')), 'libelle' => 'date de rappel'),
            'motif' => array('type' => 'str', 'max' => 255),
            'compte_rendu' => array('type' => 'text', 'max' => 5000, 'libelle' => 'compte rendu'),
            'id_tache' => array('type' => 'int', 'min' => 1, 'libelle' => 'tâche'),
        );
    }

    /**
     * Date et heure d'un échange, à la minute. Un échange est un fait : il ne se note pas à l'avance.
     * Une heure qui devance celle du serveur de moins de dix minutes est ramenée à maintenant.
     */
    private function moment($date, $heure)
    {
        global $Response;

        $t = strtotime("$date $heure:00");
        $maintenant = time();
        if ($t > $maintenant + self::TOLERANCE_MINUTES * 60) {
            $Response->validationError("Un échange se note une fois qu'il a eu lieu : l'heure indiquée est à venir.");
        }

        return date('Y-m-d H:i:00', min($t, $maintenant));
    }

    /**
     * Règles entre champs : le résultat est propre aux appels, la date de rappel au résultat « à rappeler ».
     * Retourne array(resultat, date_rappel) normalisés.
     */
    private function coherence($canal, $resultat, $date_rappel, $jour)
    {
        global $Response;

        if ($canal !== 'appel') {
            return array(null, null);
        }
        if ($resultat === null) {
            $Response->validationError("Indiquez le résultat de l'appel.");
        }
        if ($resultat !== 'a_rappeler') {
            return array($resultat, null);
        }
        if ($date_rappel === null) {
            $Response->validationError("Indiquez la date à laquelle rappeler.");
        }
        if ($date_rappel < $jour) {
            $Response->validationError("La date de rappel ne peut pas précéder l'appel.");
        }

        return array($resultat, $date_rappel);
    }

    // ÉCRITURES ######################################################

    /**
     * Note un échange sur un dossier. $data : champs de spec(). `id_tache` désigne l'appel prévu (tâche manuelle
     * du même dossier) que cet échange solde : elle est marquée traitée, sauf si l'appel est resté sans réponse.
     * Un appel « à rappeler » ouvre sa tâche de rappel par la synchronisation.
     * Retourne array(id_interaction, deja).
     */
    public function creer($contact, $data, $cle, $id_users)
    {
        global $SQL, $Mysql, $S, $Contact, $Tache, $Response;

        $idc = (int) $contact->id_contact;
        $moment = $this->moment($data['date'], $data['heure']);
        list($resultat, $rappel) = $this->coherence($data['canal'], $data['resultat'] ?? null, $data['date_rappel'] ?? null, substr($moment, 0, 10));

        $Contact->verrouiller($idc);

        if ($cle !== null) {
            $deja = $Mysql->fetchOne("SELECT id_interaction FROM i_interaction WHERE cle_saisie = ?", array($cle), 's');
            if ($deja !== null) {
                $SQL->commit();

                return array('id_interaction' => (int) $deja->id_interaction, 'deja' => true);
            }
        }

        $tache = null;
        if (isset($data['id_tache'])) {
            $tache = $Tache->charger($data['id_tache']);
            if ($tache === null || (int) $tache->id_contact !== $idc) {
                $Response->validationError("La tâche indiquée n'appartient pas à ce dossier.");
            }
        }
        // Une tâche automatique se ferme d'elle-même quand sa cause disparaît : seule une tâche manuelle se solde ici
        $solde = $tache !== null && $tache->alerte === null && $tache->date_cloture === null && $resultat !== 'sans_reponse';

        $idi = $S->inserer('i_interaction', array(
            'id_contact' => $idc,
            'canal' => $data['canal'],
            'sens' => $data['sens'],
            'date_interaction' => $moment,
            'duree' => $data['duree'] ?? null,
            'resultat' => $resultat,
            'date_rappel' => $rappel,
            'motif' => $data['motif'] ?? null,
            'compte_rendu' => $data['compte_rendu'] ?? null,
            'id_tache' => $solde ? (int) $tache->id_tache : null,
            'cle_saisie' => $cle,
            'id_users' => $id_users,
        ));
        // Le fait se lit au moment de l'échange. Noté dans la minute, il prend l'instant de la saisie :
        // il arrive en tête du fil, au-dessus de ce qui vient d'y être écrit.
        $codes = array('id_interaction' => $idi, 'canal' => $data['canal'], 'resultat' => $resultat);
        $Contact->tracer($idc, $id_users, 'echange_create', $codes, array(
            'type' => 'echange', 'module' => 'appels', 'objet_type' => 'interaction', 'objet_id' => $idi,
            'details' => array('action' => 'creation', 'canal' => $data['canal'], 'resultat' => $resultat),
            'date' => substr($moment, 0, 16) === date('Y-m-d H:i') ? null : $moment,
        ));
        if ($solde) {
            $Tache->marquerFaite($tache, $id_users);
        }

        $this->recalculerDerniere($idc);
        $Tache->synchroniser($idc);
        $SQL->commit();

        return array('id_interaction' => $idi, 'deja' => false);
    }

    /**
     * Corrige un échange : sens, date et heure, durée, résultat, date de rappel, motif, compte rendu.
     * Le canal ne se change pas. Le fait garde sa place dans le fil : sa date y suit celle de l'échange.
     * Retourne les champs modifiés.
     */
    public function modifier($echange, $data, $id_users)
    {
        global $SQL, $Mysql, $S, $Contact, $Tache;

        $idc = (int) $echange->id_contact;
        $Contact->verrouiller($idc);
        $i = $this->charger($echange->id_interaction);

        $nouveau = array();
        if (array_key_exists('date', $data) || array_key_exists('heure', $data)) {
            $nouveau['date_interaction'] = $this->moment($data['date'] ?? substr($i->date_interaction, 0, 10), $data['heure'] ?? substr($i->date_interaction, 11, 5));
        }
        foreach (array('sens', 'duree', 'motif', 'compte_rendu') as $champ) {
            if (array_key_exists($champ, $data) && !($champ === 'sens' && $data[$champ] === null)) {
                $nouveau[$champ] = $data[$champ];
            }
        }
        $resultat = array_key_exists('resultat', $data) ? $data['resultat'] : $i->resultat;
        $rappel = array_key_exists('date_rappel', $data) ? $data['date_rappel'] : $i->date_rappel;
        $jour = substr($nouveau['date_interaction'] ?? $i->date_interaction, 0, 10);
        list($nouveau['resultat'], $nouveau['date_rappel']) = $this->coherence($i->canal, $resultat, $rappel, $jour);

        $modifies = $S->differences($i, $nouveau);
        if (count($modifies) > 0) {
            $S->mettreAJour('i_interaction', 'id_interaction', (int) $i->id_interaction, $nouveau);
            if (isset($nouveau['date_interaction'])) {
                $Mysql->execute(
                    "UPDATE d_evenement SET date_evenement = ? WHERE objet_type = 'interaction' AND objet_id = ? AND JSON_VALUE(details, '$.action') = 'creation'",
                    array($nouveau['date_interaction'], (int) $i->id_interaction),
                    'si'
                );
            }
            $codes = array('id_interaction' => (int) $i->id_interaction, 'champs' => $modifies);
            $Contact->tracer($idc, $id_users, 'echange_update', $codes, array(
                'type' => 'echange', 'module' => 'appels', 'objet_type' => 'interaction', 'objet_id' => (int) $i->id_interaction,
                'details' => array('action' => 'modification', 'champs' => $modifies),
            ));
            $this->recalculerDerniere($idc);
            $Tache->synchroniser($idc);
        }
        $SQL->commit();

        return $modifies;
    }

    /**
     * Supprime un échange noté par erreur (un compte rendu saisi sur la mauvaise famille doit disparaître) :
     * la ligne et ses faits quittent le dossier ; le journal d'audit garde la trace de la suppression.
     */
    public function supprimer($echange, $id_users)
    {
        global $SQL, $Mysql, $Contact, $Tache;

        $idc = (int) $echange->id_contact;
        $idi = (int) $echange->id_interaction;
        $Contact->verrouiller($idc);

        $Mysql->execute("DELETE FROM d_evenement WHERE objet_type = 'interaction' AND objet_id = ?", array($idi), 'i');
        $Mysql->execute("DELETE FROM i_interaction WHERE id_interaction = ?", array($idi), 'i');
        $Contact->tracer($idc, $id_users, 'echange_delete', array('id_interaction' => $idi, 'canal' => $echange->canal));

        $this->recalculerDerniere($idc);
        $Tache->synchroniser($idc);
        $SQL->commit();
    }
}

class Tache
{
    const CATEGORIES = array('suivi', 'gestion');
    const NATURES = array('tache', 'appel');

    // Tâches automatiques (CDC §14) : catégorie, nature et objet qui les cause. Les délais deviennent réglables à l'étape 7.
    const ALERTES = array(
        'echeance_retard' => array('categorie' => 'gestion', 'nature' => 'tache', 'objet' => 'echeance'),
        'paiement_echoue' => array('categorie' => 'gestion', 'nature' => 'tache', 'objet' => 'paiement'),
        'rdv_a_planifier' => array('categorie' => 'suivi', 'nature' => 'tache', 'objet' => 'rdv'),
        'rdv_a_confirmer' => array('categorie' => 'suivi', 'nature' => 'tache', 'objet' => 'rdv'),
        'rdv_compte_rendu' => array('categorie' => 'suivi', 'nature' => 'tache', 'objet' => 'rdv'),
        'appel_a_rappeler' => array('categorie' => 'suivi', 'nature' => 'appel', 'objet' => 'interaction'),
        'programme_fin_proche' => array('categorie' => 'suivi', 'nature' => 'tache', 'objet' => 'compte'),
    );

    // Un rendez-vous à confirmer se rappelle deux jours avant
    const JOURS_AVANT_RDV = 2;

    // Une fin de programme reste à traiter pendant ces jours après la fin (bilan, clôture)
    const JOURS_APRES_PROGRAMME = 14;

    // Délai minimal entre deux synchronisations générales, en minutes
    const INTERVALLE_SYNCHRO = 5;

    // La tâche, l'identité de son dossier, et de l'objet qui la cause ce qu'il faut pour composer son libellé.
    const COLONNES = "t.id_tache, t.id_contact, t.categorie, t.nature, t.titre, t.alerte, t.objet_type, t.objet_id, t.date_echeance,
        t.nb_reports, t.id_users_assigne, t.date_cloture, t.cloture, t.id_users, t.date_creation, t.date_modif,
        c.prenom, c.nom, c.telephone, c.statut AS contact_statut, c.date_archivage AS contact_date_archivage,
        ua.identifiant AS assigne_identifiant, ua.prenom AS assigne_prenom, ua.nom AS assigne_nom,
        uc.identifiant AS auteur_identifiant, uc.prenom AS auteur_prenom, uc.nom AS auteur_nom,
        uf.identifiant AS cloture_identifiant, uf.prenom AS cloture_prenom, uf.nom AS cloture_nom,
        e.id_vente AS o_ech_id_vente, e.date_prevue AS o_ech_date_prevue, e.montant AS o_ech_montant, e.montant_paye AS o_ech_montant_paye,
        p.id_vente AS o_pai_id_vente, p.type AS o_pai_type, p.montant AS o_pai_montant, p.date_paiement AS o_pai_date,
        r.type AS o_rdv_type, r.statut AS o_rdv_statut, r.date_debut AS o_rdv_date_debut,
        i.date_interaction AS o_int_date,
        ac.date_fin AS o_cpt_date_fin";

    const SQL_FROM = " FROM t_tache t
        LEFT JOIN d_contact c ON c.id_contact = t.id_contact
        LEFT JOIN u_users ua ON ua.id_users = t.id_users_assigne
        LEFT JOIN u_users uc ON uc.id_users = t.id_users
        LEFT JOIN u_users uf ON uf.id_users = t.id_users_cloture
        LEFT JOIN v_echeance e ON t.objet_type = 'echeance' AND e.id_echeance = t.objet_id
        LEFT JOIN v_paiement p ON t.objet_type = 'paiement' AND p.id_paiement = t.objet_id
        LEFT JOIN r_rdv r ON t.objet_type = 'rdv' AND r.id_rdv = t.objet_id
        LEFT JOIN i_interaction i ON t.objet_type = 'interaction' AND i.id_interaction = t.objet_id
        LEFT JOIN a_compte ac ON t.objet_type = 'compte' AND ac.id_compte = t.objet_id";

    // LECTURE ########################################################

    public function charger($id_tache)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT " . self::COLONNES . self::SQL_FROM . " WHERE t.id_tache = ?", array((int) $id_tache), 'i');
    }

    /** Une tâche de suivi porte une note interne : elle se lit avec le droit famille. Une tâche de gestion, avec le droit sur les tâches. */
    public function lisible($categorie, $user, $niveau = 'L')
    {
        global $U;

        return $U->can($user, 'taches', $niveau) && ($categorie === 'gestion' || $U->can($user, 'famille', $niveau));
    }

    /**
     * Exige le droit demandé sur les tâches, puis une tâche que l'utilisateur peut lire en entier
     * (dossier lisible, droit famille pour une tâche de suivi). Retourne array($user, $tache) ; sinon 401 / 403 / 404 et exit.
     */
    public function exigerTache($id_tache, $niveau = 'C')
    {
        global $U, $Response;

        $user = $U->requireAccess('taches', $niveau);
        $tache = (int) $id_tache > 0 ? $this->charger($id_tache) : null;
        if ($tache === null) {
            $Response->notFound("Tâche introuvable.");
        }
        if ($tache->id_contact !== null && !$U->can($user, Contact::groupeDe($tache->contact_statut), 'L')) {
            $Response->forbidden("Vous n'avez pas accès au dossier de cette tâche.");
        }
        if (!$this->lisible($tache->categorie, $user, $niveau)) {
            $Response->forbidden("Cette tâche relève du suivi des familles : vous n'y avez pas accès.");
        }

        return array($user, $tache);
    }

    /**
     * Condition SQL limitant une liste aux tâches que l'utilisateur peut lire (alias `t` et `c`) :
     * catégorie selon le droit famille, dossier lisible ou tâche sans dossier. Retourne array(sql, params).
     */
    public function conditionLisibles($user)
    {
        global $U;

        $conds = array();
        $params = array();
        if (!$U->can($user, 'famille', 'L')) {
            $conds[] = "t.categorie = 'gestion'";
        }
        $statuts = array();
        foreach (Contact::GROUPES as $groupe => $liste) {
            if ($U->can($user, $groupe, 'L')) {
                $statuts = array_merge($statuts, $liste);
            }
        }
        if (count($statuts) === 0) {
            $conds[] = "t.id_contact IS NULL";
        } else {
            $conds[] = "(t.id_contact IS NULL OR c.statut IN (" . implode(', ', array_fill(0, count($statuts), '?')) . "))";
            $params = $statuts;
        }

        return array(implode(' AND ', $conds), $params);
    }

    /** Ce que la tâche automatique vise, lu dans sa table (null pour une tâche manuelle, ou si l'objet n'existe plus). */
    private function objet($t)
    {
        $reference = function ($id_vente) {
            return 'VE-' . str_pad((string) (int) $id_vente, 5, '0', STR_PAD_LEFT);
        };

        if ($t->objet_type === 'echeance' && $t->o_ech_id_vente !== null) {
            return array(
                'id_vente' => (int) $t->o_ech_id_vente,
                'reference' => $reference($t->o_ech_id_vente),
                'date_prevue' => $t->o_ech_date_prevue,
                'reste' => max(0, (int) $t->o_ech_montant - (int) $t->o_ech_montant_paye),
            );
        }
        if ($t->objet_type === 'paiement' && $t->o_pai_id_vente !== null) {
            return array(
                'id_vente' => (int) $t->o_pai_id_vente,
                'reference' => $reference($t->o_pai_id_vente),
                'type' => $t->o_pai_type,
                'montant' => (int) $t->o_pai_montant,
                'date_paiement' => $t->o_pai_date,
            );
        }
        if ($t->objet_type === 'rdv' && $t->o_rdv_type !== null) {
            return array('id_rdv' => (int) $t->objet_id, 'type' => $t->o_rdv_type, 'statut' => $t->o_rdv_statut, 'date_debut' => $t->o_rdv_date_debut);
        }
        if ($t->objet_type === 'interaction' && $t->o_int_date !== null) {
            return array('id_interaction' => (int) $t->objet_id, 'date_interaction' => $t->o_int_date);
        }
        if ($t->objet_type === 'compte' && $t->o_cpt_date_fin !== null) {
            return array('id_compte' => (int) $t->objet_id, 'date_fin' => $t->o_cpt_date_fin);
        }

        return null;
    }

    /**
     * Tâche telle que servie au front. `situation` (retard, aujourdhui, semaine, plus_tard) est calculée ici.
     * $complet = false : la tâche n'est pas lisible de l'utilisateur ; il n'en voit que l'échéance.
     */
    public function sortie($t, $complet, $aujourdhui)
    {
        $ouverte = $t->date_cloture === null;
        $situation = null;
        if ($ouverte) {
            if ($t->date_echeance < $aujourdhui) {
                $situation = 'retard';
            } elseif ($t->date_echeance === $aujourdhui) {
                $situation = 'aujourdhui';
            } else {
                $situation = $t->date_echeance <= Suivi::finSemaine($aujourdhui) ? 'semaine' : 'plus_tard';
            }
        }

        $out = array(
            'id_tache' => (int) $t->id_tache,
            'id_contact' => $t->id_contact === null ? null : (int) $t->id_contact,
            'categorie' => $t->categorie,
            'date_echeance' => $t->date_echeance,
            'situation' => $situation,
            'ouverte' => $ouverte,
            'lisible' => (bool) $complet,
        );
        if (!$complet) {
            return $out;
        }

        return array_merge($out, array(
            'contact' => Suivi::dossier($t),
            'nature' => $t->nature,
            'titre' => $t->titre,
            'alerte' => $t->alerte,
            'objet' => $this->objet($t),
            'nb_reports' => (int) $t->nb_reports,
            'assigne' => $t->id_users_assigne === null ? null : array(
                'id_users' => (int) $t->id_users_assigne,
                'nom' => Suivi::personne($t, 'assigne'),
            ),
            'cloture' => $t->cloture,
            'date_cloture' => $t->date_cloture,
            'auteur_cloture' => Suivi::personne($t, 'cloture'),
            'id_users' => $t->id_users === null ? null : (int) $t->id_users,
            'auteur' => Suivi::personne($t, 'auteur'),
            'date_creation' => $t->date_creation,
        ));
    }

    /** Tâches ouvertes d'un dossier, de la plus proche à la plus lointaine. D'une tâche qu'il ne peut pas lire, l'utilisateur voit la date. */
    public function duDossier($id_contact, $user)
    {
        global $Mysql;

        $aujourdhui = date('Y-m-d');
        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT " . self::COLONNES . self::SQL_FROM . " WHERE t.id_contact = ? AND t.date_cloture IS NULL ORDER BY t.date_echeance, t.id_tache",
            array((int) $id_contact),
            'i'
        ) as $t) {
            $out[] = $this->sortie($t, $this->lisible($t->categorie, $user), $aujourdhui);
        }

        return $out;
    }

    // SAISIE #########################################################

    /** Champs d'une tâche manuelle. La personne à qui elle est attribuée est un utilisateur actif. */
    public function spec()
    {
        return array(
            'titre' => array('type' => 'str', 'max' => 255, 'requis' => true, 'libelle' => 'intitulé'),
            'date_echeance' => array('type' => 'date', 'requis' => true, 'min' => date('Y-m-d', strtotime('-1 year')), 'max' => date('Y-m-d', strtotime('+2 years')), 'libelle' => 'échéance'),
            'nature' => array('type' => 'enum', 'valeurs' => self::NATURES, 'defaut' => 'tache'),
            'categorie' => array('type' => 'enum', 'valeurs' => self::CATEGORIES, 'libelle' => 'catégorie'),
            'id_users_assigne' => array('type' => 'fk', 'table' => 'u_users', 'col' => 'id_users', 'where' => 'actif = 1', 'libelle' => 'personne attribuée'),
        );
    }

    /** Refuse (400) d'attribuer une tâche à quelqu'un qui ne pourrait pas la lire. */
    public function exigerAttribuable($categorie, $id_users_assigne)
    {
        global $Mysql, $Response;

        if ($id_users_assigne === null) {
            return;
        }
        $profil = $Mysql->fetchOne("SELECT profil FROM u_users WHERE id_users = ? AND actif = 1", array((int) $id_users_assigne), 'i');
        $profils = $categorie === 'gestion' ? Suivi::profils('taches') : array_intersect(Suivi::profils('taches'), Suivi::profils('famille', 'L'));
        if ($profil === null || !in_array($profil->profil, $profils, true)) {
            $Response->validationError("Cette personne n'a pas accès à cette tâche : choisissez quelqu'un d'autre.");
        }
    }

    // ÉCRITURES ######################################################

    /** Ouvre la transaction d'une écriture sur une tâche : sous le verrou de son dossier quand elle en a un. */
    private function ouvrir($id_contact)
    {
        global $SQL, $Contact;

        if ($id_contact === null) {
            $SQL->begin_transaction();
        } else {
            $Contact->verrouiller($id_contact);
        }
    }

    /**
     * Trace une écriture sur une tâche : journal d'audit toujours ; fil du dossier ($fait) pour ce qui compte
     * (création, traitement, réouverture) quand la tâche a un dossier. Le fait d'une tâche de suivi se lit avec le
     * droit famille, celui d'une tâche de gestion avec le droit sur les tâches. Codes seulement : jamais l'intitulé.
     */
    private function tracer($t, $action_audit, $details, $fait, $id_users)
    {
        global $Contact, $U;

        $codes = array_merge(array('id_tache' => (int) $t->id_tache), $details);
        if ($t->id_contact === null) {
            $U->audit($id_users, $action_audit, $codes, 'tache', (int) $t->id_tache);

            return;
        }
        $Contact->tracer((int) $t->id_contact, $id_users, $action_audit, $codes, $fait === null ? null : array(
            'type' => 'tache', 'module' => $t->categorie === 'gestion' ? 'taches' : 'famille', 'objet_type' => 'tache', 'objet_id' => (int) $t->id_tache,
            'details' => array('action' => $fait),
        ));
    }

    /**
     * Crée une tâche manuelle, avec ou sans dossier. $data : champs de spec(), catégorie comprise.
     * Retourne array(id_tache, deja).
     */
    public function creer($contact, $data, $cle, $id_users)
    {
        global $SQL, $Mysql, $S, $Response;

        $idc = $contact === null ? null : (int) $contact->id_contact;
        $this->ouvrir($idc);

        if ($cle !== null) {
            $deja = $Mysql->fetchOne("SELECT id_tache FROM t_tache WHERE cle_saisie = ?", array($cle), 's');
            if ($deja !== null) {
                $SQL->commit();

                return array('id_tache' => (int) $deja->id_tache, 'deja' => true);
            }
        }
        if ($idc !== null && $Mysql->fetchOne("SELECT date_archivage FROM d_contact WHERE id_contact = ?", array($idc), 'i')->date_archivage !== null) {
            $Response->validationError("Ce dossier est classé sans suite : rouvrez-le avant d'y ajouter une tâche.");
        }

        $idt = $S->inserer('t_tache', array(
            'id_contact' => $idc,
            'categorie' => $data['categorie'],
            'nature' => $data['nature'],
            'titre' => $data['titre'],
            'date_echeance' => $data['date_echeance'],
            'id_users_assigne' => $data['id_users_assigne'] ?? $id_users,
            'cle_saisie' => $cle,
            'id_users' => $id_users,
        ));
        $this->tracer((object) array('id_tache' => $idt, 'id_contact' => $idc, 'categorie' => $data['categorie']), 'tache_create',
            array('categorie' => $data['categorie'], 'nature' => $data['nature']), 'creation', $id_users);
        $SQL->commit();

        return array('id_tache' => $idt, 'deja' => false);
    }

    /** Relit une tâche sous le verrou et refuse (400) si elle n'est plus ouverte, ou si elle est automatique quand $manuelle est exigé. */
    private function relireOuverte($tache, $manuelle, $verbe)
    {
        global $Response;

        $t = $this->charger($tache->id_tache);
        if ($manuelle && $t->alerte !== null) {
            $Response->validationError("Une tâche automatique ne se $verbe pas : elle suit ce qui la cause.");
        }
        if ($t->date_cloture !== null) {
            $Response->validationError("Cette tâche est fermée : elle ne se $verbe plus.");
        }

        return $t;
    }

    /** Corrige une tâche manuelle ouverte (intitulé, nature, catégorie). Retourne les champs modifiés. */
    public function modifier($tache, $data, $id_users)
    {
        global $SQL, $S;

        $this->ouvrir($tache->id_contact);
        $t = $this->relireOuverte($tache, true, 'modifie');
        $modifies = $S->differences($t, $data);
        if (count($modifies) > 0) {
            $S->mettreAJour('t_tache', 'id_tache', (int) $t->id_tache, $data);
            $this->tracer($t, 'tache_update', array('champs' => $modifies), null, $id_users);
        }
        $SQL->commit();

        return $modifies;
    }

    /** Supprime une tâche manuelle ouverte, créée par erreur : ses faits quittent le fil, le journal d'audit garde la trace. */
    public function supprimer($tache, $id_users)
    {
        global $SQL, $Mysql;

        $this->ouvrir($tache->id_contact);
        $t = $this->relireOuverte($tache, true, 'supprime');
        $Mysql->execute("DELETE FROM d_evenement WHERE objet_type = 'tache' AND objet_id = ?", array((int) $t->id_tache), 'i');
        $Mysql->execute("DELETE FROM t_tache WHERE id_tache = ?", array((int) $t->id_tache), 'i');
        $this->tracer($t, 'tache_delete', array(), null, $id_users);
        $SQL->commit();
    }

    /** Reporte une tâche ouverte : son échéance change sur la même ligne, le nombre de reports augmente. */
    public function reporter($tache, $date, $id_users)
    {
        global $SQL, $Mysql;

        $this->ouvrir($tache->id_contact);
        $t = $this->relireOuverte($tache, false, 'reporte');
        if ($t->date_echeance !== $date) {
            $Mysql->execute(
                "UPDATE t_tache SET date_echeance = ?, nb_reports = nb_reports + 1, date_modif = NOW() WHERE id_tache = ?",
                array($date, (int) $t->id_tache),
                'si'
            );
            $this->tracer($t, 'tache_report', array('nb_reports' => (int) $t->nb_reports + 1), null, $id_users);
        }
        $SQL->commit();
    }

    /** Attribue une tâche ouverte à quelqu'un (ou à personne : null). */
    public function attribuer($tache, $id_users_assigne, $id_users)
    {
        global $SQL, $Mysql;

        $this->ouvrir($tache->id_contact);
        $t = $this->relireOuverte($tache, false, 'attribue');
        $avant = $t->id_users_assigne === null ? null : (int) $t->id_users_assigne;
        if ($avant !== $id_users_assigne) {
            $Mysql->execute("UPDATE t_tache SET id_users_assigne = ?, date_modif = NOW() WHERE id_tache = ?", array($id_users_assigne, (int) $t->id_tache), 'ii');
            $this->tracer($t, 'tache_attribution', array('id_users_assigne' => $id_users_assigne), null, $id_users);
        }
        $SQL->commit();
    }

    /** Marque traitée une tâche ouverte. Sans transaction : l'appelant la tient, dossier verrouillé. */
    public function marquerFaite($t, $id_users)
    {
        global $Mysql;

        $n = $Mysql->execute(
            "UPDATE t_tache SET date_cloture = NOW(), cloture = 'faite', id_users_cloture = ?, date_modif = NOW() WHERE id_tache = ? AND date_cloture IS NULL",
            array($id_users, (int) $t->id_tache),
            'ii'
        );
        if ($n > 0) {
            $this->tracer($t, 'tache_cloture', array('alerte' => $t->alerte), 'cloture', $id_users);
        }
    }

    /**
     * Marque une tâche traitée ($traitee = true) ou la rouvre. Une tâche automatique traitée à la main ne revient pas,
     * même si sa cause persiste ; rouverte, elle se referme d'elle-même si sa cause a disparu entre-temps.
     * Une tâche que sa cause a fermée ne se rouvre pas à la main.
     */
    public function cloturer($tache, $traitee, $id_users)
    {
        global $SQL, $Mysql, $Response;

        $this->ouvrir($tache->id_contact);
        $t = $this->charger($tache->id_tache);

        if ($traitee) {
            $this->marquerFaite($t, $id_users);
        } elseif ($t->cloture === 'sans_objet') {
            $Response->validationError("Cette tâche s'est fermée d'elle-même : ce qui la causait a disparu.");
        } elseif ($t->cloture === 'faite') {
            $Mysql->execute(
                "UPDATE t_tache SET date_cloture = NULL, cloture = NULL, id_users_cloture = NULL, date_modif = NOW() WHERE id_tache = ?",
                array((int) $t->id_tache),
                'i'
            );
            $this->tracer($t, 'tache_reouverture', array('alerte' => $t->alerte), 'reouverture', $id_users);
            if ($t->alerte !== null && $t->id_contact !== null) {
                $this->synchroniser((int) $t->id_contact);
            }
        }
        $SQL->commit();
    }

    /**
     * Réponse d'une écriture sur une tâche : la tâche (null si elle vient d'être supprimée) et, quand elle a un dossier,
     * son bloc de suivi et le dossier lui-même (sa prochaine action a pu changer).
     */
    public function reponse($id_tache, $id_contact, $user)
    {
        global $Suivi;

        $t = $id_tache === null ? null : $this->charger($id_tache);
        $out = array('tache' => $t === null ? null : $this->sortie($t, $this->lisible($t->categorie, $user), date('Y-m-d')));
        if ($id_contact === null) {
            return array_merge($out, array('suivi' => null, 'contact' => null, 'avertissements' => array()));
        }

        return $Suivi->reponse($id_contact, $user, array(), $out);
    }

    // SYNCHRONISATION DES TÂCHES AUTOMATIQUES ########################

    /**
     * Causes actives d'une alerte : une ligne par objet (objet_id, id_contact, date_echeance, id_users à qui l'attribuer).
     * L'instant et le jour viennent de PHP. Un dossier classé sans suite n'a plus d'alerte de suivi ; ses alertes
     * de paiement restent. $id_contact : limiter à un dossier.
     */
    private function causes($alerte, $id_contact, $maintenant)
    {
        global $Mysql;

        $jour = substr($maintenant, 0, 10);
        $params = array();

        switch ($alerte) {
            case 'echeance_retard':
                // Échéance d'une vente en cours dont la date est passée, sans échec enregistré ; due le lendemain de sa date
                $sql = "SELECT e.id_echeance AS objet_id, v.id_contact, e.date_prevue + INTERVAL 1 DAY AS date_echeance, NULL AS id_users
                        FROM v_echeance e INNER JOIN v_vente v ON v.id_vente = e.id_vente
                        WHERE e.date_annulation IS NULL AND v.date_annulation IS NULL AND e.montant_paye < e.montant
                          AND e.date_dernier_echec IS NULL AND e.date_prevue < ?";
                $params[] = $jour;
                $dossier = 'v.id_contact';
                break;

            case 'paiement_echoue':
                // La dernière tentative sur la vente a échoué (cache posé par Vente::recalculer) : l'objet est cette tentative,
                // pour qu'un nouvel échec ouvre une nouvelle tâche même si la précédente a été traitée
                $sql = "SELECT (SELECT MAX(p.id_paiement) FROM v_paiement p
                                WHERE p.id_vente = e.id_vente AND p.date_annulation IS NULL AND p.type IN ('echec', 'impaye')) AS objet_id,
                               v.id_contact, e.date_dernier_echec AS date_echeance, NULL AS id_users
                        FROM v_echeance e INNER JOIN v_vente v ON v.id_vente = e.id_vente
                        WHERE e.date_annulation IS NULL AND v.date_annulation IS NULL AND e.date_dernier_echec IS NOT NULL";
                $dossier = 'v.id_contact';
                break;

            case 'rdv_a_planifier':
                // Demande restée sans créneau ; due le jour de la demande
                $sql = "SELECT r.id_rdv AS objet_id, r.id_contact, DATE(r.date_statut) AS date_echeance, r.id_users_responsable AS id_users
                        FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
                        WHERE r.statut = 'demande' AND c.date_archivage IS NULL";
                $dossier = 'r.id_contact';
                break;

            case 'rdv_a_confirmer':
                // Rendez-vous à venir, non confirmé ; dû deux jours avant, au plus tôt le jour où il a été pris
                $sql = "SELECT r.id_rdv AS objet_id, r.id_contact,
                               GREATEST(DATE(r.date_statut), DATE(r.date_debut) - INTERVAL " . self::JOURS_AVANT_RDV . " DAY) AS date_echeance,
                               r.id_users_responsable AS id_users
                        FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
                        WHERE r.statut = 'a_confirmer' AND r.date_fin >= ? AND c.date_archivage IS NULL";
                $params[] = $maintenant;
                $dossier = 'r.id_contact';
                break;

            case 'rdv_compte_rendu':
                // Rendez-vous passé sans être clôturé, ou effectué sans compte rendu ; dû le jour du rendez-vous
                $sql = "SELECT r.id_rdv AS objet_id, r.id_contact, DATE(r.date_debut) AS date_echeance, r.id_users_responsable AS id_users
                        FROM r_rdv r INNER JOIN d_contact c ON c.id_contact = r.id_contact
                        WHERE ((r.statut IN ('a_confirmer', 'confirme') AND r.date_fin < ?) OR (r.statut = 'effectue' AND r.compte_rendu IS NULL))
                          AND c.date_archivage IS NULL";
                $params[] = $maintenant;
                $dossier = 'r.id_contact';
                break;

            case 'appel_a_rappeler':
                // Appel « à rappeler » qu'aucun appel abouti (ni nouveau « à rappeler », qui prend le relais) n'a suivi
                $sql = "SELECT i.id_interaction AS objet_id, i.id_contact, i.date_rappel AS date_echeance, i.id_users
                        FROM i_interaction i INNER JOIN d_contact c ON c.id_contact = i.id_contact
                        WHERE i.canal = 'appel' AND i.resultat = 'a_rappeler' AND i.date_rappel IS NOT NULL AND c.date_archivage IS NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM i_interaction s
                              WHERE s.id_contact = i.id_contact AND s.canal = 'appel' AND s.resultat IN ('abouti', 'a_rappeler')
                                AND (s.date_interaction > i.date_interaction OR (s.date_interaction = i.date_interaction AND s.id_interaction > i.id_interaction))
                          )";
                $dossier = 'i.id_contact';
                break;

            case 'programme_fin_proche':
                // Programme qui s'achève bientôt, ou achevé depuis peu : bilan et clôture à prévoir ; due quelques jours avant la fin
                $proche = isset($GLOBALS['_PROGRAMME_FIN_PROCHE_JOURS']) ? (int) $GLOBALS['_PROGRAMME_FIN_PROCHE_JOURS'] : 7;
                $sql = "SELECT a.id_compte AS objet_id, a.id_contact, a.date_fin - INTERVAL $proche DAY AS date_echeance, NULL AS id_users
                        FROM a_compte a INNER JOIN d_contact c ON c.id_contact = a.id_contact
                        WHERE a.etat = 'actif' AND a.date_fin <= DATE_ADD(?, INTERVAL $proche DAY)
                          AND a.date_fin >= DATE_SUB(?, INTERVAL " . self::JOURS_APRES_PROGRAMME . " DAY) AND c.date_archivage IS NULL";
                $params[] = $jour;
                $params[] = $jour;
                $dossier = 'a.id_contact';
                break;

            default:
                throw new LogicException("Alerte inconnue : $alerte");
        }

        if ($id_contact !== null) {
            $sql .= " AND $dossier = ?";
            $params[] = (int) $id_contact;
        }

        $causes = array();
        foreach ($Mysql->fetchAll($sql, $params) as $c) {
            if ($c->objet_id !== null) {
                $causes[(int) $c->objet_id] = $c;
            }
        }

        return $causes;
    }

    /**
     * Seul point de création et de fermeture des tâches automatiques. Pour chaque alerte : ouvre la tâche d'une cause
     * apparue, ferme (« sans objet ») celle dont la cause a disparu, rouvre celle dont la cause revient, et ne touche
     * jamais une tâche qu'un utilisateur a traitée. L'échéance suit la cause tant que la tâche n'a pas été reportée.
     * Sans doublon : la clé unique (alerte, objet_id) absorbe deux exécutions simultanées. Silencieuse : ni audit,
     * ni fil. Ne dépend pas de l'utilisateur. N'écrit que s'il y a une différence.
     * $id_contact : limiter à un dossier (dans la transaction d'une écriture) ; null : tous les dossiers.
     * $simuler : ne rien écrire (contrôle de cohérence). Retourne les différences : array(array(operation, alerte, objet_id)).
     */
    public function synchroniser($id_contact = null, $maintenant = null, $simuler = false)
    {
        global $Mysql;

        $maintenant = $maintenant ?? date('Y-m-d H:i:s');
        $differences = array();

        foreach (self::ALERTES as $alerte => $def) {
            $causes = $this->causes($alerte, $id_contact, $maintenant);

            // Tâches de cette alerte encore ouvertes, ou dont l'objet a une cause active
            $sql = "SELECT id_tache, objet_id, date_echeance, nb_reports, date_cloture, cloture FROM t_tache WHERE alerte = ?";
            $params = array($alerte);
            if ($id_contact !== null) {
                $sql .= " AND id_contact = ?";
                $params[] = (int) $id_contact;
            } elseif (count($causes) > 0) {
                $sql .= " AND (date_cloture IS NULL OR objet_id IN (" . implode(', ', array_fill(0, count($causes), '?')) . "))";
                $params = array_merge($params, array_keys($causes));
            } else {
                $sql .= " AND date_cloture IS NULL";
            }
            $taches = array();
            foreach ($Mysql->fetchAll($sql, $params) as $t) {
                $taches[(int) $t->objet_id] = $t;
            }

            foreach ($causes as $objet_id => $c) {
                $t = $taches[$objet_id] ?? null;
                if ($t === null) {
                    $differences[] = array('ouvrir', $alerte, $objet_id);
                    if (!$simuler) {
                        $Mysql->execute(
                            "INSERT INTO t_tache (id_contact, categorie, nature, alerte, objet_type, objet_id, date_echeance, id_users_assigne)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id_tache = id_tache",
                            array((int) $c->id_contact, $def['categorie'], $def['nature'], $alerte, $def['objet'], $objet_id, $c->date_echeance, $c->id_users === null ? null : (int) $c->id_users),
                            'issssisi'
                        );
                    }
                } elseif ($t->cloture === 'sans_objet') {
                    $differences[] = array('rouvrir', $alerte, $objet_id);
                    if (!$simuler) {
                        $Mysql->execute(
                            "UPDATE t_tache SET date_cloture = NULL, cloture = NULL, id_users_cloture = NULL, date_echeance = ?, nb_reports = 0, date_modif = NOW()
                             WHERE id_tache = ? AND cloture = 'sans_objet'",
                            array($c->date_echeance, (int) $t->id_tache),
                            'si'
                        );
                    }
                } elseif ($t->date_cloture === null && (int) $t->nb_reports === 0 && $t->date_echeance !== $c->date_echeance) {
                    $differences[] = array('redater', $alerte, $objet_id);
                    if (!$simuler) {
                        $Mysql->execute("UPDATE t_tache SET date_echeance = ?, date_modif = NOW() WHERE id_tache = ?", array($c->date_echeance, (int) $t->id_tache), 'si');
                    }
                }
            }

            foreach ($taches as $objet_id => $t) {
                if ($t->date_cloture === null && !isset($causes[$objet_id])) {
                    $differences[] = array('fermer', $alerte, $objet_id);
                    if (!$simuler) {
                        $Mysql->execute(
                            "UPDATE t_tache SET date_cloture = ?, cloture = 'sans_objet', date_modif = NOW() WHERE id_tache = ? AND date_cloture IS NULL",
                            array($maintenant, (int) $t->id_tache),
                            'si'
                        );
                    }
                }
            }
        }

        return $differences;
    }

    /**
     * Synchronisation générale à la lecture (liste des tâches, listes de dossiers) : au plus toutes les cinq minutes.
     * La lecture qui réserve le créneau (UPDATE conditionnel) la lance ; les autres passent. Aucune tâche planifiée
     * n'est nécessaire sur le serveur ; script-cgi/synchroniser-taches.php permet d'en brancher une.
     */
    public function synchroniserSiBesoin()
    {
        global $Mysql;

        $maintenant = date('Y-m-d H:i:s');
        $limite = date('Y-m-d H:i:s', time() - self::INTERVALLE_SYNCHRO * 60);
        $reserve = $Mysql->execute(
            "UPDATE t_synchro SET date_synchro = ? WHERE id = 1 AND (date_synchro IS NULL OR date_synchro <= ?)",
            array($maintenant, $limite),
            'ss'
        );
        if ($reserve === 1) {
            $this->synchroniser(null, $maintenant);
        }
    }
}

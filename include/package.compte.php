<?php

//=======================================================================
// File:        package.compte.php
// Description: compte NavUp Academy d'un dossier (CDC §8, §15) : son accès à la formation, du début à la fin du
//              programme. Ouvert au premier encaissement par Vente::automatismes(), désactivé quand la vente est
//              défaite, réglable à la main (dates, désactivation). L'état du programme (pas commencé, en cours,
//              terminé) et la semaine en cours se déduisent des dates, à la lecture : rien de cela n'est stocké.
//              Ni mot de passe ni session ici : ils appartiennent à l'appli des parents (navup-parent-api, tables e_*),
//              qui lit le compte par la vue a_acces. Ce fichier décide de l'accès (dates, désactivation, révocation)
//              et crée seul les liens d'accès (a_jeton) ; l'appli les consomme.
//              Méthodes statiques, fichier inclus par package.contact.php : tout endpoint qui sert un dossier
//              sert aussi son programme, et toute écriture financière peut ouvrir un compte.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

include_once __DIR__ . "/package.formation.php";

class Compte
{
    // Vues de la liste des clients par état du programme, et chiffres du tableau de bord
    const VUES = array('en_cours', 'fin_proche', 'termine');

    // Colonnes du compte jointes à un dossier (alias `a`, voir Contact::SQL_FROM)
    const COLONNES_DOSSIER = "a.id_compte AS compte_id, a.id_formation AS compte_formation, a.etat AS compte_etat, a.date_debut AS compte_debut, a.date_fin AS compte_fin";

    private static $structures = array();

    public static function charger($id_contact)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT * FROM a_compte WHERE id_contact = ?", array((int) $id_contact), 'i');
    }

    /** Déroulé de la formation d'un compte, gardé le temps de la requête (une liste de dossiers le relit à chaque ligne). */
    private static function structure($id_formation)
    {
        $id = (int) $id_formation;
        if (!isset(self::$structures[$id])) {
            self::$structures[$id] = Formation::structure($id);
        }

        return self::$structures[$id];
    }

    /** Nombre de jours de $du à $au (AAAA-MM-JJ), négatif si $au précède $du. */
    private static function jours($du, $au)
    {
        return (int) round((strtotime($au . ' 12:00:00') - strtotime($du . ' 12:00:00')) / 86400);
    }

    /**
     * Programme d'un compte pour un jour donné : état, semaine en cours, bornes. Le jour vient de PHP.
     * - desactive : accès coupé ; pas_commence : le début est à venir ; termine : la fin est passée ;
     * - en_cours : la semaine est la dernière débloquée d'après le déroulé de la formation.
     * `fin_proche` : le programme en cours s'achève dans $_PROGRAMME_FIN_PROCHE_JOURS jours au plus.
     */
    public static function programme($etat, $id_formation, $debut, $fin, $aujourdhui = null)
    {
        global $_PROGRAMME_FIN_PROCHE_JOURS;

        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;
        $structure = self::structure($id_formation);
        $sur = count($structure['semaines']);
        $proche = isset($_PROGRAMME_FIN_PROCHE_JOURS) ? (int) $_PROGRAMME_FIN_PROCHE_JOURS : 7;

        $programme = array(
            'etat' => 'en_cours',
            'semaine' => null,
            'sur' => $sur,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'fin_proche' => false,
        );
        if ($etat !== 'actif') {
            $programme['etat'] = 'desactive';
        } elseif ($jour < $debut) {
            $programme['etat'] = 'pas_commence';
        } elseif ($jour > $fin) {
            $programme['etat'] = 'termine';
        } else {
            $ecoules = self::jours($debut, $jour);
            $semaine = 1;
            foreach ($structure['semaines'] as $numero => $decalage) {
                if ($decalage <= $ecoules) {
                    $semaine = max($semaine, $numero);
                }
            }
            $programme['semaine'] = $semaine;
            $programme['fin_proche'] = self::jours($jour, $fin) <= $proche;
        }

        return $programme;
    }

    /** Programme d'un dossier lu avec Contact::COLONNES, ou null s'il n'a pas de compte. */
    public static function programmeDuDossier($row)
    {
        if (!isset($row->compte_id) || $row->compte_id === null) {
            return null;
        }

        return self::programme($row->compte_etat, $row->compte_formation, $row->compte_debut, $row->compte_fin);
    }

    /** Compte tel que servi au front, avec son programme. */
    public static function sortie($compte)
    {
        return array(
            'id_compte' => (int) $compte->id_compte,
            'id_contact' => (int) $compte->id_contact,
            'etat' => $compte->etat,
            'date_debut' => $compte->date_debut,
            'date_fin' => $compte->date_fin,
            'date_fin_acces' => $compte->date_fin_acces,
            'date_activation' => $compte->date_activation,
            'date_desactivation' => $compte->date_desactivation,
            'desactivation_automatique' => $compte->etat === 'desactive' && $compte->origine_desactivation === 'automatique',
            'programme' => self::programme($compte->etat, $compte->id_formation, $compte->date_debut, $compte->date_fin),
            'acces' => self::acces($compte),
        );
    }

    /** Dernier jour d'accès d'un programme qui finit le $fin : $_ACCES_APRES_FIN_JOURS de plus. */
    private static function finAcces($fin)
    {
        global $_ACCES_APRES_FIN_JOURS;

        $jours = isset($_ACCES_APRES_FIN_JOURS) ? max(0, (int) $_ACCES_APRES_FIN_JOURS) : 30;

        return date('Y-m-d', strtotime($fin . ' 12:00:00') + $jours * 86400);
    }

    /**
     * Ce que l'on sait de l'espace personnel du parent (tables e_* de l'appli, lues ici, jamais écrites) :
     * dernier lien envoyé, mot de passe créé, dernière connexion, sujets terminés. Un mot de passe antérieur à la
     * révocation de l'accès ne compte plus.
     */
    public static function acces($compte)
    {
        global $Mysql, $_APP_PARENTS_URL;

        $idc = (int) $compte->id_compte;
        $e = $Mysql->fetchOne("SELECT date_mot_de_passe, date_derniere_connexion, annonce_semaine FROM e_acces WHERE id_compte = ?", array($idc), 'i');
        $valide = $e !== null && ($compte->date_revocation === null || $e->date_mot_de_passe > $compte->date_revocation);
        $lien = $Mysql->fetchOne("SELECT MAX(date_creation) AS le FROM a_jeton WHERE id_compte = ?", array($idc), 'i');
        $sujets = $Mysql->fetchOne(
            "SELECT COUNT(*) AS sur, COUNT(p.date_termine) AS termines
             FROM f_sujet s LEFT JOIN e_progression p ON p.id_sujet = s.id_sujet AND p.id_compte = ?
             WHERE s.id_formation = ? AND s.publie = 1",
            array($idc, (int) $compte->id_formation),
            'ii'
        );

        return array(
            'appli' => isset($_APP_PARENTS_URL) && $_APP_PARENTS_URL !== '',
            'lien_le' => $lien === null ? null : $lien->le,
            'mot_de_passe_le' => $valide ? $e->date_mot_de_passe : null,
            'derniere_connexion' => $valide ? $e->date_derniere_connexion : null,
            'revoque_le' => $compte->date_revocation,
            'annonce_semaine' => $e === null ? true : (int) $e->annonce_semaine === 1,
            'termines' => (int) $sujets->termines,
            'sur' => (int) $sujets->sur,
        );
    }

    /**
     * Consentements du dossier, tels que le parent les a déclarés : le dernier de chaque type, avec la version du texte.
     * Ce ne sont pas des données familiales : ils se lisent avec le dossier.
     */
    public static function consentements($id_contact)
    {
        global $Mysql;

        $derniers = array();
        foreach ($Mysql->fetchAll(
            "SELECT type, accorde, version, source, date_consentement FROM d_consentement WHERE id_contact = ? ORDER BY id_consentement DESC",
            array((int) $id_contact),
            'i'
        ) as $c) {
            if (!isset($derniers[$c->type])) {
                $derniers[$c->type] = array(
                    'type' => $c->type,
                    'accorde' => (int) $c->accorde === 1,
                    'version' => $c->version,
                    'source' => $c->source,
                    'date' => $c->date_consentement,
                );
            }
        }

        return array_values($derniers);
    }

    /**
     * Condition SQL d'une vue de programme sur la liste des clients (alias `c` sur d_contact), partagée avec le
     * tableau de bord : le chiffre est le total de la liste. Retourne array(sql, params), ou null si la vue est inconnue.
     */
    public static function conditionVue($vue, $aujourdhui = null)
    {
        global $_PROGRAMME_FIN_PROCHE_JOURS;

        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;
        $proche = isset($_PROGRAMME_FIN_PROCHE_JOURS) ? (int) $_PROGRAMME_FIN_PROCHE_JOURS : 7;
        $compte = "EXISTS (SELECT 1 FROM a_compte ap WHERE ap.id_contact = c.id_contact AND ap.etat = 'actif' AND ";

        switch ($vue) {
            case 'en_cours':
                return array($compte . "ap.date_debut <= ? AND ap.date_fin >= ?)", array($jour, $jour));
            case 'fin_proche':
                return array($compte . "ap.date_debut <= ? AND ap.date_fin >= ? AND ap.date_fin <= DATE_ADD(?, INTERVAL ? DAY))", array($jour, $jour, $jour, $proche));
            case 'termine':
                return array($compte . "ap.date_fin < ?)", array($jour));
        }

        return null;
    }

    private static function tracer($id_contact, $id_compte, $action, $id_users, $origine)
    {
        global $Contact;

        $Contact->tracer($id_contact, $id_users, 'compte_' . $action, array('id_compte' => (int) $id_compte), array(
            'type' => 'compte', 'module' => 'dossier', 'objet_type' => 'compte', 'objet_id' => (int) $id_compte,
            'details' => array('action' => $action), 'origine' => $origine,
        ));
    }

    /**
     * Ouvre le compte d'un dossier au premier encaissement d'une vente : le programme commence le jour de ce paiement
     * et dure ce que dure la formation de l'offre vendue. Un compte désactivé, ou dont le programme est fini,
     * repart pour un nouveau programme ; un programme en cours n'est pas touché.
     * Pose aussi la date d'inscription du dossier si elle est vide.
     * Sans transaction ni verrou : l'appelant (Vente::automatismes) les tient.
     * Retourne true si le dossier a, après l'appel, un compte actif.
     */
    public static function ouvrir($id_contact, $id_vente, $id_users, $origine = 'automatique')
    {
        global $Mysql;

        $idc = (int) $id_contact;
        $vente = $Mysql->fetchOne(
            "SELECT f.id_formation,
                    (SELECT MIN(p.date_paiement) FROM v_paiement p WHERE p.id_vente = v.id_vente AND p.type = 'encaissement' AND p.date_annulation IS NULL) AS premier
             FROM v_vente v LEFT JOIN f_formation f ON f.code_offre = v.code_offre WHERE v.id_vente = ?",
            array((int) $id_vente),
            'i'
        );
        $compte = self::charger($idc);
        if ($vente === null || $vente->id_formation === null || $vente->premier === null) {
            return $compte !== null && $compte->etat === 'actif';
        }

        $debut = $vente->premier;
        $fin = date('Y-m-d', strtotime($debut . ' 12:00:00') + (self::structure($vente->id_formation)['jours'] - 1) * 86400);
        $Mysql->execute("UPDATE d_contact SET date_inscription = ?, date_modif = NOW() WHERE id_contact = ? AND date_inscription IS NULL", array($debut, $idc), 'si');

        if ($compte === null) {
            $Mysql->execute(
                "INSERT INTO a_compte (id_contact, id_formation, etat, date_debut, date_fin, date_fin_acces, id_users) VALUES (?, ?, 'actif', ?, ?, ?, ?)",
                array($idc, (int) $vente->id_formation, $debut, $fin, self::finAcces($fin), $id_users === null ? null : (int) $id_users),
                'iisssi'
            );
            self::tracer($idc, $Mysql->lastId(), 'ouverture', $id_users, $origine);

            return true;
        }

        if ($compte->etat === 'actif' && $compte->date_fin >= date('Y-m-d')) {
            return true;
        }
        $Mysql->execute(
            "UPDATE a_compte SET etat = 'actif', id_formation = ?, date_debut = ?, date_fin = ?, date_fin_acces = ?, date_activation = NOW(),
                    date_desactivation = NULL, origine_desactivation = NULL, date_modif = NOW() WHERE id_compte = ?",
            array((int) $vente->id_formation, $debut, $fin, self::finAcces($fin), (int) $compte->id_compte),
            'isssi'
        );
        self::tracer($idc, $compte->id_compte, 'reouverture', $id_users, $origine);

        return true;
    }

    /**
     * Désactive le compte d'un dossier (vente annulée ou remboursée en totalité, premier paiement annulé, ou geste
     * d'un utilisateur). L'origine est gardée : seul un compte désactivé par un automatisme se réactive tout seul.
     * Sans transaction : l'appelant la tient. Retourne true si le compte a changé.
     */
    public static function desactiver($id_contact, $id_users, $origine = 'utilisateur')
    {
        global $Mysql;

        $compte = self::charger($id_contact);
        if ($compte === null || $compte->etat !== 'actif') {
            return false;
        }
        $Mysql->execute(
            "UPDATE a_compte SET etat = 'desactive', date_desactivation = NOW(), origine_desactivation = ?, date_modif = NOW() WHERE id_compte = ?",
            array($origine, (int) $compte->id_compte),
            'si'
        );
        self::tracer($id_contact, $compte->id_compte, 'desactivation', $id_users, $origine);

        return true;
    }

    /**
     * Réactive un compte désactivé, sans toucher à ses dates. $automatique : défait une désactivation automatique
     * (la vente revit après une erreur de saisie) ; un compte désactivé à la main ne se réactive qu'à la main.
     * Sans transaction : l'appelant la tient. Retourne true si le compte a changé.
     */
    public static function reactiver($id_contact, $id_users, $automatique = false)
    {
        global $Mysql;

        $compte = self::charger($id_contact);
        if ($compte === null || $compte->etat !== 'desactive' || ($automatique && $compte->origine_desactivation !== 'automatique')) {
            return false;
        }
        $Mysql->execute(
            "UPDATE a_compte SET etat = 'actif', date_activation = NOW(), date_desactivation = NULL, origine_desactivation = NULL, date_modif = NOW() WHERE id_compte = ?",
            array((int) $compte->id_compte),
            'i'
        );
        self::tracer($id_contact, $compte->id_compte, 'reactivation', $id_users, $automatique ? 'automatique' : 'utilisateur');

        return true;
    }

    /**
     * Change le début du programme, sa fin, ou la fin de l'accès (« l'administrateur peut exceptionnellement modifier
     * la date de début ou prolonger l'accès »). La fin du programme décide du statut du dossier et de l'alerte « fin
     * proche » ; la fin de l'accès, elle, ne prolonge que la consultation des contenus dans l'appli des parents.
     * Un programme prolongé au-delà d'aujourd'hui rend au dossier son statut « Client actif ».
     * Sans transaction : l'appelant la tient, dossier verrouillé.
     */
    public static function modifierDates($contact, $compte, $debut, $fin, $finAcces, $id_users)
    {
        global $Mysql, $Contact;

        if ($debut === $compte->date_debut && $fin === $compte->date_fin && $finAcces === $compte->date_fin_acces) {
            return;
        }
        $Mysql->execute(
            "UPDATE a_compte SET date_debut = ?, date_fin = ?, date_fin_acces = ?, date_modif = NOW() WHERE id_compte = ?",
            array($debut, $fin, $finAcces, (int) $compte->id_compte),
            'sssi'
        );
        self::tracer($contact->id_contact, $compte->id_compte, 'dates', $id_users, 'utilisateur');

        if ($contact->statut === 'programme_termine' && $compte->etat === 'actif' && $fin >= date('Y-m-d')) {
            $Contact->changerStatut($contact, 'client_actif', $id_users, 'utilisateur');
        }
    }

    // ACCÈS À L'ESPACE PERSONNEL ####################################

    /**
     * Crée un lien d'accès à l'espace personnel (création ou réinitialisation du mot de passe) et rend son adresse.
     * Seule l'empreinte du jeton est gardée ; le jeton voyage dans le fragment de l'adresse, que le navigateur
     * n'envoie à aucun serveur. Un nouveau lien révoque les précédents du compte : un seul lien vaut à la fois.
     * Retourne null si l'appli des parents n'a pas d'adresse ($_APP_PARENTS_URL).
     */
    public static function lienAcces($id_compte, $motif, $id_users = null)
    {
        global $Mysql, $U, $_APP_PARENTS_URL, $_LIEN_ACCES_CREATION_JOURS, $_LIEN_ACCES_REINIT_MINUTES;

        if (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
            return null;
        }
        $idc = (int) $id_compte;
        $minutes = $motif === 'reinitialisation'
            ? (isset($_LIEN_ACCES_REINIT_MINUTES) ? (int) $_LIEN_ACCES_REINIT_MINUTES : 60)
            : (isset($_LIEN_ACCES_CREATION_JOURS) ? (int) $_LIEN_ACCES_CREATION_JOURS : 7) * 1440;

        $Mysql->execute("UPDATE a_jeton SET date_revocation = NOW() WHERE id_compte = ? AND date_revocation IS NULL", array($idc), 'i');
        $jeton = $U->genToken(40);
        $Mysql->execute(
            "INSERT INTO a_jeton (id_compte, jeton, motif, date_expiration, id_users) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)",
            array($idc, hash('sha256', $jeton), $motif === 'reinitialisation' ? 'reinitialisation' : 'creation', $minutes, $id_users === null ? null : (int) $id_users),
            'issii'
        );

        return rtrim($_APP_PARENTS_URL, '/') . '/mot-de-passe#' . $jeton;
    }

    /** Le parent a-t-il un mot de passe utilisable (créé, et postérieur à la révocation de l'accès) ? */
    public static function aMotDePasse($compte)
    {
        global $Mysql;

        $e = $Mysql->fetchOne("SELECT date_mot_de_passe FROM e_acces WHERE id_compte = ?", array((int) $compte->id_compte), 'i');

        return $e !== null && ($compte->date_revocation === null || $e->date_mot_de_passe > $compte->date_revocation);
    }

    /**
     * Dépose l'e-mail qui porte un lien d'accès : « invitation » tant que le parent n'a pas de mot de passe,
     * « mot de passe oublié » ensuite. Le lien lui-même n'est créé qu'à l'envoi (Message::poserLiens).
     * La clé dédoublonne par quart d'heure : deux demandes rapprochées ne font qu'un message.
     * Sans transaction. Retourne l'identifiant du message, ou null (pas de compte, pas d'adresse, déjà déposé).
     */
    public static function inviter($contact, $id_users = null)
    {
        global $Message, $_APP_PARENTS_URL, $_LIEN_ACCES_CREATION_JOURS, $_LIEN_ACCES_REINIT_MINUTES;

        $compte = self::charger($contact->id_contact);
        if ($compte === null || !isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
            return null;
        }
        $modele = self::aMotDePasse($compte) ? 'mot_de_passe' : 'invitation';

        return $Message->deposer(
            $contact,
            $modele,
            array(
                'appli' => $_APP_PARENTS_URL,
                'jours' => isset($_LIEN_ACCES_CREATION_JOURS) ? (int) $_LIEN_ACCES_CREATION_JOURS : 7,
                'minutes' => isset($_LIEN_ACCES_REINIT_MINUTES) ? (int) $_LIEN_ACCES_REINIT_MINUTES : 60,
            ),
            'acces:' . (int) $compte->id_compte . ':' . (int) floor(time() / 900),
            array(
                'objet_type' => 'compte', 'objet_id' => (int) $compte->id_compte,
                'origine' => $id_users === null ? 'automatique' : 'utilisateur', 'id_users' => $id_users,
            )
        );
    }

    /**
     * Révoque l'accès à l'espace personnel : les sessions, le mot de passe et les liens d'avant ne valent plus rien
     * (l'appli des parents compare leurs dates à date_revocation). Le compte et ses dates ne changent pas : le parent
     * retrouve son programme et sa progression avec un nouveau lien. Sert quand l'e-mail du dossier change (une
     * invitation partie à la mauvaise adresse) et au geste « Réinitialiser l'accès ».
     * Sans transaction : l'appelant la tient. Retourne true si le dossier a un compte.
     */
    public static function revoquer($id_contact, $id_users, $origine = 'utilisateur')
    {
        global $Mysql;

        $compte = self::charger($id_contact);
        if ($compte === null) {
            return false;
        }
        $Mysql->execute("UPDATE a_compte SET date_revocation = NOW(), date_modif = NOW() WHERE id_compte = ?", array((int) $compte->id_compte), 'i');
        $Mysql->execute("UPDATE a_jeton SET date_revocation = NOW() WHERE id_compte = ? AND date_revocation IS NULL", array((int) $compte->id_compte), 'i');
        self::tracer($id_contact, $compte->id_compte, 'revocation', $id_users, $origine);

        return true;
    }

    /**
     * Passe « comptes » de la tâche planifiée. Deux rattrapages, chacun sous le verrou du dossier :
     * - un dossier qui a payé une vente non défaite et n'a pas de compte en reçoit un (ventes antérieures à l'étape 6) ;
     * - un dossier « Client actif » dont le programme est fini passe en « Programme terminé » (automatique).
     * Retourne array(ouverts, termines).
     */
    public static function synchroniser()
    {
        global $SQL, $Mysql, $Contact;

        $ouverts = 0;
        $termines = 0;
        $jour = date('Y-m-d');

        $sansCompte = $Mysql->fetchAll(
            "SELECT v.id_contact, MIN(v.id_vente) AS id_vente
             FROM v_vente v
             INNER JOIN f_formation f ON f.code_offre = v.code_offre
             LEFT JOIN a_compte a ON a.id_contact = v.id_contact
             WHERE a.id_compte IS NULL AND v.statut NOT IN ('annule', 'rembourse')
               AND EXISTS (SELECT 1 FROM v_paiement p WHERE p.id_vente = v.id_vente AND p.type = 'encaissement' AND p.date_annulation IS NULL)
             GROUP BY v.id_contact"
        );
        foreach ($sansCompte as $l) {
            $Contact->verrouiller($l->id_contact);
            if (self::charger($l->id_contact) === null && self::ouvrir($l->id_contact, $l->id_vente, null, 'automatique')) {
                $contact = $Contact->charger($l->id_contact);
                if ($contact->statut === 'client') {
                    $Contact->changerStatut($contact, self::programmeDuDossier($contact)['etat'] === 'termine' ? 'programme_termine' : 'client_actif', null, 'automatique');
                }
                $ouverts++;
            }
            $SQL->commit();
        }

        $finis = $Mysql->fetchAll(
            "SELECT c.id_contact FROM d_contact c INNER JOIN a_compte a ON a.id_contact = c.id_contact
             WHERE c.statut = 'client_actif' AND a.etat = 'actif' AND a.date_fin < ?",
            array($jour),
            's'
        );
        foreach ($finis as $l) {
            $Contact->verrouiller($l->id_contact);
            $contact = $Contact->charger($l->id_contact);
            $compte = self::charger($l->id_contact);
            if ($contact->statut === 'client_actif' && $compte !== null && $compte->etat === 'actif' && $compte->date_fin < $jour) {
                $Contact->changerStatut($contact, 'programme_termine', null, 'automatique');
                $termines++;
            }
            $SQL->commit();
        }

        return array($ouverts, $termines);
    }

    /**
     * Passe « semaines » de la tâche planifiée : écrit au parent quand une nouvelle semaine de son programme vient de
     * se débloquer (la première est annoncée par l'e-mail de bienvenue). Éteinte tant que l'appli des parents n'a pas
     * d'adresse ($_APP_PARENTS_URL) : il n'y aurait rien à ouvrir. Rien n'est programmé à l'avance : chaque passage
     * regarde ce qui est dû, la clé du message empêche un second envoi. Une semaine sans sujet publié ne s'annonce pas,
     * ni rien à un parent qui a décoché « me prévenir à chaque nouvelle semaine » dans son profil (e_acces).
     * Retourne le nombre de messages déposés, ou null si la passe est éteinte.
     */
    public static function annoncerSemaines()
    {
        global $Mysql, $Message, $_APP_PARENTS_URL;

        if (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
            return null;
        }
        $jour = date('Y-m-d');
        $deposes = 0;
        $comptes = $Mysql->fetchAll(
            "SELECT a.id_compte, a.id_formation, a.etat, a.date_debut, a.date_fin, c.id_contact, c.prenom, c.email
             FROM a_compte a INNER JOIN d_contact c ON c.id_contact = a.id_contact
             LEFT JOIN e_acces e ON e.id_compte = a.id_compte
             WHERE a.etat = 'actif' AND a.date_debut <= ? AND a.date_fin >= ? AND c.date_archivage IS NULL AND c.email IS NOT NULL
               AND COALESCE(e.annonce_semaine, 1) = 1",
            array($jour, $jour),
            'ss'
        );
        foreach ($comptes as $c) {
            $programme = self::programme($c->etat, $c->id_formation, $c->date_debut, $c->date_fin, $jour);
            $semaine = (int) $programme['semaine'];
            if ($semaine <= 1) {
                continue;
            }
            // Une semaine débloquée depuis plus de deux jours ne s'annonce plus (passe arrêtée un temps, appli branchée après coup)
            $decalage = self::structure($c->id_formation)['semaines'][$semaine];
            if (self::jours($c->date_debut, $jour) - $decalage > 2) {
                continue;
            }
            $titres = Formation::titresPublies($c->id_formation, $semaine);
            if (count($titres) === 0) {
                continue;
            }
            $id = $Message->deposer(
                $c,
                'semaine',
                array('semaine' => $semaine, 'sujets' => $titres, 'appli' => $_APP_PARENTS_URL),
                'semaine:' . (int) $c->id_compte . ':' . $c->date_debut . ':' . $semaine,
                array('objet_type' => 'compte', 'objet_id' => (int) $c->id_compte)
            );
            if ($id !== null) {
                $deposes++;
            }
        }

        return $deposes;
    }
}

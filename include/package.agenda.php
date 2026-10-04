<?php

//=======================================================================
// File:        package.agenda.php
// Description: rendez-vous pris en ligne (étape 6b) : disponibilités d'un utilisateur (plages hebdomadaires, absences,
//              lien de visio) et créneaux libres. Les créneaux ne se calculent QU'ICI : la page qui les affiche et la
//              réservation qui en prend un appellent la même fonction, sous le verrou d'agenda pour la seconde.
//              Les gestes du parent (réserver, déplacer, annuler en ligne) sont ici aussi : ils enchaînent les verrous
//              dans l'ordre fixé (adresse e-mail, agenda, dossier) et n'écrivent que par les méthodes de Rdv.
//              Requiert package.mysql.php ($Mysql), package.saisie.php ($S), package.user.php ($U, User::MATRICE),
//              package.contact.php ($Contact), package.suivi.php ($Rdv, Suivi) et require/param.php ($_RDV_PRISE…) ;
//              les gestes du parent, en plus, package.message.php ($Message). S'appelle par $Agenda.
//              Heure de Paris partout, comme r_rdv : un créneau est une date et une heure locales.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Agenda
{
    const PLAGES_PAR_JOUR_MAX = 4;
    const ABSENCES_MAX = 50;

    // RÉGLAGES #######################################################

    /** Réglages d'un type de rendez-vous qui se prend en ligne (require/param.php), ou null. */
    public static function reglages($type)
    {
        global $_RDV_PRISE;

        return (is_string($type) && isset($_RDV_PRISE[$type])) ? $_RDV_PRISE[$type] : null;
    }

    /**
     * La prise en ligne est-elle ouverte ? L'interrupteur général, puis, pour la page publique seulement, le plafond
     * de réservations par 24 heures : l'adresse d'un visiteur n'est pas prouvée, un parent inscrit est identifié.
     */
    public function ouverte($voie = 'public')
    {
        global $Mysql, $_RDV_PRISE_OUVERTE, $_RDV_PRISE_PLAFOND;

        if (empty($_RDV_PRISE_OUVERTE)) {
            return false;
        }
        if ($voie !== 'public') {
            return true;
        }

        return $this->reservationsDuJour() < (int) $_RDV_PRISE_PLAFOND;
    }

    /** Réservations faites depuis la page publique dans les dernières 24 heures. */
    public function reservationsDuJour()
    {
        global $Mysql;

        return (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM r_reservation WHERE voie = 'public' AND date_creation > NOW() - INTERVAL 1 DAY")->nb;
    }

    // DISPONIBILITÉS D'UN UTILISATEUR ################################

    /** Plages hebdomadaires d'un utilisateur : array(array(jour 1-7, debut « HH:MM », fin « HH:MM »)), dans l'ordre. */
    public function plages($id_users)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT jour, heure_debut, heure_fin FROM r_disponibilite WHERE id_users = ? ORDER BY jour, heure_debut",
            array((int) $id_users),
            'i'
        ) as $p) {
            $out[] = array('jour' => (int) $p->jour, 'debut' => substr($p->heure_debut, 0, 5), 'fin' => substr($p->heure_fin, 0, 5));
        }

        return $out;
    }

    /**
     * Lit les plages envoyées par le formulaire : tableau de {jour, debut, fin}. Une plage finit après son début,
     * deux plages d'un même jour ne se chevauchent pas. Retourne les plages triées ; 400 sinon.
     */
    public function lirePlages($liste)
    {
        global $Response;

        if (!is_array($liste) || count($liste) > 7 * self::PLAGES_PAR_JOUR_MAX) {
            $Response->validationError("Plages de disponibilité invalides.");
        }
        $jours = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');
        $out = array();
        foreach ($liste as $p) {
            $jour = (is_object($p) && isset($p->jour) && is_int($p->jour)) ? $p->jour : 0;
            $debut = (is_object($p) && isset($p->debut) && is_string($p->debut)) ? $p->debut : '';
            $fin = (is_object($p) && isset($p->fin) && is_string($p->fin)) ? $p->fin : '';
            if (!isset($jours[$jour]) || !self::heureValide($debut) || !self::heureValide($fin)) {
                $Response->validationError("Plages de disponibilité invalides : un jour, une heure de début et une heure de fin (HH:MM).");
            }
            if ($fin <= $debut) {
                $Response->validationError("Le " . $jours[$jour] . ", une plage finit après son début.");
            }
            $out[] = array('jour' => $jour, 'debut' => $debut, 'fin' => $fin);
        }
        usort($out, function ($a, $b) {
            return array($a['jour'], $a['debut']) <=> array($b['jour'], $b['debut']);
        });
        $parJour = array();
        foreach ($out as $i => $p) {
            $parJour[$p['jour']] = ($parJour[$p['jour']] ?? 0) + 1;
            if ($parJour[$p['jour']] > self::PLAGES_PAR_JOUR_MAX) {
                $Response->validationError("Le " . $jours[$p['jour']] . " : " . self::PLAGES_PAR_JOUR_MAX . " plages au plus.");
            }
            if ($i > 0 && $out[$i - 1]['jour'] === $p['jour'] && $p['debut'] < $out[$i - 1]['fin']) {
                $Response->validationError("Le " . $jours[$p['jour']] . ", deux plages se chevauchent.");
            }
        }

        return $out;
    }

    private static function heureValide($h)
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) === 1;
    }

    /** Remplace les plages d'un utilisateur. Sans transaction : l'appelant la tient. */
    public function ecrirePlages($id_users, $plages)
    {
        global $Mysql, $S;

        $Mysql->execute("DELETE FROM r_disponibilite WHERE id_users = ?", array((int) $id_users), 'i');
        foreach ($plages as $p) {
            $S->inserer('r_disponibilite', array(
                'id_users' => (int) $id_users,
                'jour' => (int) $p['jour'],
                'heure_debut' => $p['debut'] . ':00',
                'heure_fin' => $p['fin'] . ':00',
            ));
        }
    }

    /** Absences d'un utilisateur qui ne sont pas finies, dans l'ordre. */
    public function absences($id_users)
    {
        global $Mysql;

        $out = array();
        foreach ($Mysql->fetchAll(
            "SELECT id_indisponibilite, date_debut, date_fin FROM r_indisponibilite WHERE id_users = ? AND date_fin > ? ORDER BY date_debut",
            array((int) $id_users, date('Y-m-d H:i:s')),
            'is'
        ) as $a) {
            $out[] = array('id_indisponibilite' => (int) $a->id_indisponibilite, 'date_debut' => $a->date_debut, 'date_fin' => $a->date_fin);
        }

        return $out;
    }

    /** Lien de visio habituel d'un utilisateur, ou null. */
    public function lienVisio($id_users)
    {
        global $Mysql;

        if ($id_users === null) {
            return null;
        }
        $a = $Mysql->fetchOne("SELECT lien_visio FROM u_agenda WHERE id_users = ?", array((int) $id_users), 'i');

        return $a === null ? null : $a->lien_visio;
    }

    /** Enregistre (ou retire, null) le lien de visio d'un utilisateur. Sans transaction. */
    public function ecrireLienVisio($id_users, $lien)
    {
        global $Mysql;

        $Mysql->execute(
            "INSERT INTO u_agenda (id_users, lien_visio, date_modif) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE lien_visio = VALUES(lien_visio), date_modif = NOW()",
            array((int) $id_users, $lien),
            'is'
        );
    }

    /** Un lien de visio est une adresse https, sans espace ni retour à la ligne (elle part dans un e-mail). */
    public static function lienVisioValide($lien)
    {
        return is_string($lien) && strlen($lien) <= 255 && preg_match('/^https:\/\/[^\s<>"\']+$/', $lien) === 1
            && filter_var($lien, FILTER_VALIDATE_URL) !== false;
    }

    // CRÉNEAUX #######################################################

    /**
     * Utilisateurs qui reçoivent en ligne : actifs, d'un profil qui tient l'agenda, avec au moins une plage.
     * Relu à chaque calcul : un compte désactivé ne reçoit plus rien dans la minute.
     */
    private function receveurs()
    {
        global $Mysql;

        $profils = "'" . implode("', '", Suivi::profils('rendez_vous')) . "'";

        return $Mysql->fetchAll(
            "SELECT u.id_users, a.lien_visio FROM u_users u LEFT JOIN u_agenda a ON a.id_users = u.id_users
             WHERE u.actif = 1 AND u.profil IN ($profils) AND EXISTS (SELECT 1 FROM r_disponibilite d WHERE d.id_users = u.id_users)
             ORDER BY u.id_users"
        );
    }

    /**
     * Canaux proposés en ligne pour un type : ceux des réglages ; la visio seulement si chaque utilisateur qui reçoit
     * a un lien de visio (un créneau libre vaut alors pour tous les canaux proposés).
     */
    public function canaux($type)
    {
        $reglages = self::reglages($type);
        if ($reglages === null) {
            return array();
        }
        $receveurs = $this->receveurs();
        $visio = count($receveurs) > 0;
        foreach ($receveurs as $u) {
            if ($u->lien_visio === null || $u->lien_visio === '') {
                $visio = false;
            }
        }
        $out = array();
        foreach ($reglages['canaux'] as $canal) {
            if ($canal !== 'visio' || $visio) {
                $out[] = $canal;
            }
        }

        return $out;
    }

    /**
     * Créneaux libres d'un type, du plus proche au plus lointain :
     * array('AAAA-MM-JJ HH:MM:00' => array(id_users libres à ce créneau, dans l'ordre)).
     * Un créneau tient tout entier dans une plage de l'utilisateur, ne touche aucune de ses absences ni aucun
     * rendez-vous qui tient (à confirmer, confirmé) dont il est responsable ; un rendez-vous sans responsable bloque
     * tout le monde. $exclu : rendez-vous dont le créneau ne compte pas (celui qu'on déplace).
     * $jour (AAAA-MM-JJ) : ne calculer que ce jour-là. $duree : celle du rendez-vous qu'on déplace, si elle
     * n'est pas celle du réglage.
     */
    public function libres($type, $exclu = null, $jour = null, $duree = null)
    {
        global $Mysql;

        $reglages = self::reglages($type);
        if ($reglages === null) {
            return array();
        }
        $duree = $duree === null ? (int) $reglages['duree'] : (int) $duree;
        $pas = max(5, (int) $reglages['pas']);
        $paris = new DateTimeZone('Europe/Paris');
        $maintenant = new DateTimeImmutable('now', $paris);
        $premier = $maintenant->modify('+' . (int) $reglages['delai_heures'] . ' hours')->format('Y-m-d H:i:s');
        $dernierJour = $maintenant->modify('+' . (int) $reglages['horizon_jours'] . ' days')->format('Y-m-d');

        $du = substr($premier, 0, 10);
        $au = $dernierJour;
        if ($jour !== null) {
            if ($jour < $du || $jour > $au) {
                return array();
            }
            $du = $jour;
            $au = $jour;
        }

        $receveurs = $this->receveurs();
        if (count($receveurs) === 0) {
            return array();
        }

        // Ce qui occupe la période : rendez-vous qui tiennent, puis absences
        $occupe = array();   // id_users (0 : tout le monde) => array(array(debut, fin))
        foreach ($Mysql->fetchAll(
            "SELECT id_users_responsable, date_debut, date_fin FROM r_rdv
             WHERE statut IN ('a_confirmer', 'confirme') AND date_fin > ? AND date_debut < ? + INTERVAL 1 DAY AND id_rdv <> ?",
            array($du . ' 00:00:00', $au, (int) $exclu),
            'ssi'
        ) as $r) {
            $occupe[(int) $r->id_users_responsable][] = array($r->date_debut, $r->date_fin);
        }
        foreach ($Mysql->fetchAll(
            "SELECT id_users, date_debut, date_fin FROM r_indisponibilite WHERE date_fin > ? AND date_debut < ? + INTERVAL 1 DAY",
            array($du . ' 00:00:00', $au),
            'ss'
        ) as $a) {
            $occupe[(int) $a->id_users][] = array($a->date_debut, $a->date_fin);
        }

        $plages = array();   // id_users => jour => array(array(debut, fin))
        foreach ($Mysql->fetchAll("SELECT id_users, jour, heure_debut, heure_fin FROM r_disponibilite ORDER BY id_users, jour, heure_debut") as $p) {
            $plages[(int) $p->id_users][(int) $p->jour][] = array(substr($p->heure_debut, 0, 5), substr($p->heure_fin, 0, 5));
        }

        $libres = array();
        // Jour par jour, en dates du calendrier : jamais par ajout de 24 heures (les jours de changement d'heure en font 23 ou 25)
        for ($d = new DateTimeImmutable($du . ' 12:00:00', $paris); $d->format('Y-m-d') <= $au; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $n = (int) $d->format('N');
            foreach ($receveurs as $u) {
                $idu = (int) $u->id_users;
                foreach ($plages[$idu][$n] ?? array() as $plage) {
                    $finPlage = self::minutes($plage[1]);
                    for ($m = self::minutes($plage[0]); $m + $duree <= $finPlage; $m += $pas) {
                        $debut = sprintf('%s %02d:%02d:00', $date, intdiv($m, 60), $m % 60);
                        if ($debut < $premier || !self::heureExiste($debut, $paris)) {
                            continue;
                        }
                        $fin = sprintf('%s %02d:%02d:00', $date, intdiv($m + $duree, 60), ($m + $duree) % 60);
                        if (self::touche($occupe[$idu] ?? array(), $debut, $fin) || self::touche($occupe[0] ?? array(), $debut, $fin)) {
                            continue;
                        }
                        $libres[$debut][] = $idu;
                    }
                }
            }
        }
        ksort($libres);

        return $libres;
    }

    private static function minutes($heure)
    {
        return (int) substr($heure, 0, 2) * 60 + (int) substr($heure, 3, 2);
    }

    /** Une heure locale existe-t-elle ce jour-là ? (2 h 30 n'existe pas la nuit du passage à l'heure d'été.) */
    public static function heureExiste($moment, $fuseau)
    {
        return (new DateTimeImmutable($moment, $fuseau))->format('Y-m-d H:i:s') === $moment;
    }

    private static function touche($intervalles, $debut, $fin)
    {
        foreach ($intervalles as $i) {
            if ($debut < $i[1] && $fin > $i[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Créneaux tels que servis à une page : array(array(date, creneaux => array('HH:MM'))). Aucune identité :
     * la page ne sait pas qui reçoit.
     */
    public function jours($type, $exclu = null, $duree = null)
    {
        $jours = array();
        foreach (array_keys($this->libres($type, $exclu, null, $duree)) as $debut) {
            $jours[substr($debut, 0, 10)][] = substr($debut, 11, 5);
        }
        $out = array();
        foreach ($jours as $date => $creneaux) {
            $out[] = array('date' => $date, 'creneaux' => $creneaux);
        }

        return $out;
    }

    /**
     * Qui reçoit à ce créneau ? Le premier utilisateur libre, ou null si le créneau n'est pas (ou plus) proposé.
     * À appeler sous le verrou d'agenda : c'est le contrôle qui empêche la double réservation, et le seul moyen de
     * réserver. Une date forgée (hors plage, trop proche, trop lointaine) n'est pas un créneau : null.
     */
    public function attribuer($type, $debut, $exclu = null, $duree = null)
    {
        if (!is_string($debut) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $debut) !== 1) {
            return null;
        }
        $libres = $this->libres($type, $exclu, substr($debut, 0, 10), $duree);

        return isset($libres[$debut]) ? (int) $libres[$debut][0] : null;
    }

    /** Ce qu'une page reçoit pour proposer des créneaux d'un type. Fermé ou sans réglage : aucun créneau. */
    public function prise($type, $voie = 'public', $exclu = null, $duree = null)
    {
        $reglages = self::reglages($type);
        $ouvert = $reglages !== null && $this->ouverte($voie);
        $canaux = $ouvert ? $this->canaux($type) : array();
        $jours = ($ouvert && count($canaux) > 0) ? $this->jours($type, $exclu, $duree) : array();

        return array(
            'ouvert' => $ouvert && count($jours) > 0,
            'type' => $type,
            'duree' => $duree !== null ? (int) $duree : ($reglages === null ? null : (int) $reglages['duree']),
            'canaux' => $canaux,
            'fuseau' => 'Europe/Paris',
            'jours' => $jours,
        );
    }

    // FLUX D'AGENDA ##################################################

    /** État du flux d'agenda d'un utilisateur : actif ou non, depuis quand, dernière lecture par un calendrier. */
    public function etatFlux($id_users)
    {
        global $Mysql;

        $a = $Mysql->fetchOne("SELECT jeton, date_jeton, date_dernier_acces FROM u_agenda WHERE id_users = ?", array((int) $id_users), 'i');

        return array(
            'actif' => $a !== null && $a->jeton !== null,
            'date_jeton' => ($a !== null && $a->jeton !== null) ? $a->date_jeton : null,
            'date_dernier_acces' => ($a !== null && $a->jeton !== null) ? $a->date_dernier_acces : null,
        );
    }

    /**
     * Crée ou renouvelle le flux d'agenda d'un utilisateur et rend son adresse, qui n'est montrée qu'à ce moment :
     * seule l'empreinte du jeton est gardée. Renouveler coupe l'ancienne adresse. Sans transaction.
     */
    public function ouvrirFlux($id_users)
    {
        global $Mysql, $_PATH_API;

        $jeton = bin2hex(random_bytes(32));
        $Mysql->execute(
            "INSERT INTO u_agenda (id_users, jeton, date_jeton, date_dernier_acces, date_modif) VALUES (?, ?, NOW(), NULL, NOW())
             ON DUPLICATE KEY UPDATE jeton = VALUES(jeton), date_jeton = NOW(), date_dernier_acces = NULL, date_modif = NOW()",
            array((int) $id_users, hash('sha256', $jeton)),
            'is'
        );

        return $_PATH_API . 'v1/agenda/flux/?j=' . $jeton;
    }

    /** Coupe le flux d'agenda d'un utilisateur : son adresse ne répond plus. Sans transaction. */
    public function couperFlux($id_users)
    {
        global $Mysql;

        $Mysql->execute("UPDATE u_agenda SET jeton = NULL, date_jeton = NULL, date_dernier_acces = NULL, date_modif = NOW() WHERE id_users = ?", array((int) $id_users), 'i');
    }

    /**
     * Utilisateur que désigne le jeton d'un flux, ou null. Relu à chaque lecture : un compte désactivé, ou passé à un
     * profil sans droit sur les rendez-vous, ne sert plus rien.
     */
    public function utilisateurDuFlux($jeton)
    {
        global $Mysql;

        if (!is_string($jeton) || preg_match('/^[0-9a-f]{64}$/', $jeton) !== 1) {
            return null;
        }
        $profils = "'" . implode("', '", Suivi::profils('rendez_vous', 'L')) . "'";

        return $Mysql->fetchOne(
            "SELECT u.id_users, a.date_dernier_acces FROM u_agenda a INNER JOIN u_users u ON u.id_users = a.id_users
             WHERE a.jeton = ? AND u.actif = 1 AND u.profil IN ($profils)",
            array(hash('sha256', $jeton)),
            's'
        );
    }

    /**
     * Rendez-vous du flux d'un utilisateur : ceux qu'il mène, qui tiennent ou ont eu lieu (à confirmer, confirmé,
     * effectué, absent), de trente jours en arrière à six mois en avant. Un rendez-vous annulé ou déplacé n'y est
     * plus : il disparaît du calendrier à sa prochaine lecture.
     */
    public function rdvDuFlux($id_users)
    {
        global $Mysql;

        return $Mysql->fetchAll(
            "SELECT " . Rdv::COLONNES . Rdv::SQL_FROM . "
             WHERE r.id_users_responsable = ? AND r.statut IN ('a_confirmer', 'confirme', 'effectue', 'absent')
               AND r.date_debut >= NOW() - INTERVAL 30 DAY AND r.date_debut < NOW() + INTERVAL 180 DAY
             ORDER BY r.date_debut, r.id_rdv",
            array((int) $id_users),
            'i'
        );
    }

    // PARENT INSCRIT #################################################

    /**
     * Compte que désigne un billet délivré par l'API des parents (table e_billet, qu'elle seule écrit), ou null.
     * L'API des parents a vérifié la session et l'accès au moment de le délivrer ; ici on revérifie ce qui a pu changer
     * depuis : le billet n'a pas dépassé sa durée, la session qui l'a demandé est toujours ouverte, le compte n'est
     * ni désactivé ni révoqué. Retourne (id_compte, id_contact).
     */
    public function billet($brut)
    {
        global $Mysql, $_BILLET_MINUTES;

        if (!is_string($brut) || preg_match('/^[0-9a-f]{48}$/', $brut) !== 1) {
            return null;
        }

        return $Mysql->fetchOne(
            "SELECT c.id_compte, c.id_contact
             FROM e_billet b
             INNER JOIN e_session s ON s.id_session = b.id_session AND s.id_compte = b.id_compte
             INNER JOIN a_compte c ON c.id_compte = b.id_compte
             WHERE b.jeton = ? AND b.date_creation > NOW() - INTERVAL ? MINUTE
               AND s.date_expiration > NOW()
               AND c.etat = 'actif' AND (c.date_revocation IS NULL OR s.date_creation > c.date_revocation)",
            array(hash('sha256', $brut), (int) $_BILLET_MINUTES),
            'si'
        );
    }

    /**
     * Les rendez-vous d'un dossier tels que son parent les voit dans son espace : ceux qui tiennent, à venir, du plus
     * proche au plus lointain ; puis les passés et les annulés, du plus récent au plus ancien (vingt au plus).
     * Les créneaux remplacés et les demandes sans créneau n'y sont pas.
     */
    public function duParent($id_contact)
    {
        global $Mysql, $Rdv;

        $avenir = array();
        $avant = array();
        foreach ($Mysql->fetchAll(
            "SELECT " . Rdv::COLONNES . Rdv::SQL_FROM . " WHERE r.id_contact = ? AND r.date_debut IS NOT NULL AND r.statut NOT IN ('reporte', 'demande')
             ORDER BY r.date_debut DESC LIMIT 60",
            array((int) $id_contact),
            'i'
        ) as $r) {
            $vue = $Rdv->sortieParent($r);
            if (in_array($vue['etat'], array('confirme', 'a_confirmer'), true)) {
                array_unshift($avenir, $vue);
            } elseif (count($avant) < 20) {
                $avant[] = $vue;
            }
        }

        return array('avenir' => $avenir, 'avant' => $avant);
    }

    // GESTES DU PARENT ###############################################

    /**
     * Réserve un créneau en ligne. Ouvre et valide sa transaction, sous les verrous pris dans l'ordre fixé.
     * $qui : array('id_contact') pour un parent inscrit (son dossier est connu), sinon l'identité saisie sur la page
     * publique (prenom, nom, email, telephone?) : le dossier est retrouvé par son adresse ou créé en prospect, et un
     * dossier existant n'est jamais modifié d'ici. $telephone, $note : ce que le parent déclare, gardé tel quel.
     * Retourne array('etat', 'id_contact'?, 'id_rdv'?) ; etat vaut :
     * - reserve : le rendez-vous est confirmé (ou l'était déjà : clé de saisie rejouée) ;
     * - pris : ce créneau n'est pas, ou plus, proposé (évalué avant toute lecture de dossier) ;
     * - complet : le dossier a déjà autant de rendez-vous de ce type à venir qu'il est admis. Depuis la page publique,
     *   un e-mail le rappelle à l'adresse du dossier (un par jour) et la réponse à l'écran reste la même ;
     * - occupe : une autre demande est en cours pour cette adresse.
     */
    public function reserver($type, $voie, $qui, $debut, $canal, $telephone, $note, $cle)
    {
        global $SQL, $Mysql, $S, $U, $Contact, $Rdv, $Message, $_CONFIDENTIALITE_VERSION, $_RDV_MODIFIABLE_HEURES, $_APP_PARENTS_URL;

        $reglages = self::reglages($type);
        $public = !isset($qui['id_contact']);

        $verrou = null;
        if ($public) {
            // Même verrou que l'achat en ligne : deux demandes pour une même adresse ne créent pas deux dossiers
            $verrou = 'navup_commande_' . sha1($qui['email']);
            $pris = $Mysql->fetchOne("SELECT GET_LOCK(?, 10) AS pris", array($verrou), 's');
            if ($pris === null || (int) $pris->pris !== 1) {
                return array('etat' => 'occupe');
            }
        }
        $agenda = false;
        try {
            $Rdv->prendreAgenda();
            $agenda = true;

            $deja = $Mysql->fetchOne("SELECT id_rdv, id_contact FROM r_rdv WHERE cle_saisie = ?", array($cle), 's');
            if ($deja !== null) {
                return array('etat' => 'reserve', 'id_rdv' => (int) $deja->id_rdv, 'id_contact' => (int) $deja->id_contact);
            }

            $idu = $this->attribuer($type, $debut);
            if ($idu === null) {
                return array('etat' => 'pris');
            }

            $SQL->begin_transaction();
            if ($public) {
                $dossier = $Mysql->fetchOne("SELECT id_contact FROM d_contact WHERE email = ? FOR UPDATE", array($qui['email']), 's');
                if ($dossier === null) {
                    $champs = array('prenom' => $qui['prenom'], 'nom' => $qui['nom'], 'email' => $qui['email']);
                    if (!empty($qui['telephone'])) {
                        $champs['telephone'] = $qui['telephone'];
                    }
                    $idc = $Contact->creer($champs, 'prospect', null, 'automatique', array('canal' => 'rdv_en_ligne'));
                } else {
                    $idc = (int) $dossier->id_contact;
                }
            } else {
                $idc = (int) $qui['id_contact'];
                $Mysql->fetchOne("SELECT id_contact FROM d_contact WHERE id_contact = ? FOR UPDATE", array($idc), 'i');
            }
            $contact = $Contact->charger($idc);

            $existants = $Mysql->fetchAll(
                "SELECT id_rdv FROM r_rdv WHERE id_contact = ? AND type = ? AND statut IN ('a_confirmer', 'confirme') AND date_debut > ? ORDER BY date_debut",
                array($idc, $type, date('Y-m-d H:i:s')),
                'iss'
            );
            if (count($existants) >= (int) $reglages['max_a_venir']) {
                if ($public) {
                    $r = $Rdv->charger($existants[0]->id_rdv);
                    $Message->deposer($contact, 'rdv_deja', array(
                        'type' => $r->type, 'date_debut' => $r->date_debut,
                        'gestion' => isset($_APP_PARENTS_URL) && $_APP_PARENTS_URL !== '', 'heures' => (int) $_RDV_MODIFIABLE_HEURES,
                    ), 'rdv:rdv_deja:' . $idc . ':' . date('Y-m-d'), array('objet_type' => 'rdv', 'objet_id' => (int) $r->id_rdv));
                }
                $SQL->commit();

                return array('etat' => 'complet', 'id_contact' => $idc);
            }

            // Un dossier classé sans suite qui reprend rendez-vous est rouvert, comme à l'achat en ligne
            if ($contact->date_archivage !== null) {
                $Contact->archiver($contact, false, null, 'automatique');
                $contact = $Contact->charger($idc);
            }

            $res = $Rdv->inscrire($contact, array(
                'type' => $type, 'duree' => (int) $reglages['duree'], 'canal' => $canal, 'id_users_responsable' => $idu,
            ), $debut, 'confirme', $cle, null, array(
                'origine' => 'parent',
                'prevenir' => true,
                'reservation' => array(
                    'voie' => $voie,
                    'prenom' => $public ? $qui['prenom'] : null,
                    'nom' => $public ? $qui['nom'] : null,
                    'email' => $public ? $qui['email'] : null,
                    'telephone' => $telephone,
                    'note' => $note,
                ),
            ));
            if ($public) {
                $S->inserer('d_consentement', array(
                    'id_contact' => $idc, 'id_rdv' => $res['id_rdv'], 'type' => 'confidentialite', 'accorde' => 1,
                    'version' => $_CONFIDENTIALITE_VERSION, 'source' => 'formulaire',
                ));
            }
            $U->audit(null, 'rdv_en_ligne', array('id_rdv' => $res['id_rdv'], 'voie' => $voie), 'contact', $idc);
            $SQL->commit();

            return array('etat' => 'reserve', 'id_rdv' => $res['id_rdv'], 'id_contact' => $idc);
        } catch (Throwable $e) {
            $SQL->rollback();
            throw $e;
        } finally {
            if ($agenda) {
                $Rdv->rendreAgenda();
            }
            if ($verrou !== null) {
                $Mysql->fetchOne("SELECT RELEASE_LOCK(?) AS rendu", array($verrou), 's');
            }
        }
    }

    /**
     * Déplace en ligne un rendez-vous vers un créneau proposé. Le rendez-vous est relu sous les verrous : s'il n'est
     * plus déplaçable (trop proche, déjà déplacé trois fois, annulé entre-temps), rien n'est écrit.
     * Retourne array('etat' => deplace | pris | refuse, 'id_rdv'?).
     */
    public function deplacer($rdv, $debut)
    {
        global $SQL, $U, $Contact, $Rdv;

        $Rdv->prendreAgenda();
        try {
            $idu = $this->attribuer($rdv->type, $debut, (int) $rdv->id_rdv, (int) $rdv->duree);
            if ($idu === null) {
                return array('etat' => 'pris');
            }
            $idc = (int) $rdv->id_contact;
            $Contact->verrouiller($idc);
            $r = $Rdv->charger($rdv->id_rdv);
            if ($r->id_rdv_suivant !== null) {
                // Double envoi : le déplacement est déjà fait
                $SQL->commit();

                return array('etat' => 'deplace', 'id_rdv' => (int) $r->id_rdv_suivant);
            }
            if (!$Rdv->gestesParent($r)['deplacable']) {
                $SQL->rollback();

                return array('etat' => 'refuse');
            }
            $res = $Rdv->ecrireReport($r, $debut, null, 'confirme', null, array('origine' => 'parent', 'prevenir' => true, 'responsable' => $idu));
            $U->audit(null, 'rdv_en_ligne_report', array('id_rdv' => $res['id_rdv']), 'contact', $idc);
            $SQL->commit();

            return array('etat' => 'deplace', 'id_rdv' => $res['id_rdv']);
        } catch (Throwable $e) {
            $SQL->rollback();
            throw $e;
        } finally {
            $Rdv->rendreAgenda();
        }
    }

    /** Annule en ligne un rendez-vous, relu sous les verrous. Retourne array('etat' => annule | refuse). */
    public function annuler($rdv)
    {
        global $SQL, $U, $Contact, $Rdv;

        $Rdv->prendreAgenda();
        try {
            $idc = (int) $rdv->id_contact;
            $Contact->verrouiller($idc);
            $r = $Rdv->charger($rdv->id_rdv);
            if ($r->statut === 'annule') {
                $SQL->commit();

                return array('etat' => 'annule');
            }
            if (!$Rdv->gestesParent($r)['annulable']) {
                $SQL->rollback();

                return array('etat' => 'refuse');
            }
            $Rdv->ecrireStatut($r, 'annule', array('origine' => 'parent'), null);
            $U->audit(null, 'rdv_en_ligne_annulation', array('id_rdv' => (int) $r->id_rdv), 'contact', $idc);
            $SQL->commit();

            return array('etat' => 'annule');
        } catch (Throwable $e) {
            $SQL->rollback();
            throw $e;
        } finally {
            $Rdv->rendreAgenda();
        }
    }
}

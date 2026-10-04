<?php

//=======================================================================
// File:        package.message.php
// Description: e-mails aux parents (CDC §13) : modèles, file d'envoi (m_message), envoi, trace dans le fil du dossier.
//              Un message se dépose (dans la transaction de ce qui le cause), puis s'envoie : tout de suite si
//              l'appelant le demande, sinon par la passe « messages » de la tâche planifiée.
//              Requiert package.contact.php ($Contact), package.user.php ($U), package.mysql.php ($Mysql).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Message
{
    // Modèles : libellé (affiché dans l'outil) et module dont le droit de lecture ouvre le corps du message.
    // « dossier » : lisible par qui lit le dossier. Les textes sont provisoires, à faire valider par NavUp ;
    // l'écran de réglage des modèles est attendu à l'étape 7.
    const MODELES = array(
        'bienvenue' => array('libelle' => 'Bienvenue', 'module' => 'dossier'),
        'semaine' => array('libelle' => 'Nouvelle semaine', 'module' => 'dossier'),
        'lien_paiement' => array('libelle' => 'Lien de paiement', 'module' => 'paiements'),
        'prelevement_avis' => array('libelle' => 'Prélèvement à venir', 'module' => 'paiements'),
        'paiement_echoue' => array('libelle' => 'Paiement échoué', 'module' => 'paiements'),
        'commande_en_cours' => array('libelle' => 'Commande déjà en cours', 'module' => 'paiements'),
    );

    // Marque laissée dans le corps conservé à la place d'un lien personnel : le lien n'est composé qu'à l'envoi
    const MARQUE_LIEN = '{{lien_paiement}}';

    const MOIS = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');

    /** « 18 octobre 2026 », depuis une date AAAA-MM-JJ. */
    public static function jourEcrit($date)
    {
        $t = explode('-', substr((string) $date, 0, 10));
        if (count($t) !== 3) {
            return (string) $date;
        }
        $jour = (int) $t[2];

        return ($jour === 1 ? '1er' : $jour) . ' ' . self::MOIS[(int) $t[1]] . ' ' . $t[0];
    }

    // MODÈLES ########################################################

    /**
     * Compose un message : array(sujet, corps). $d : données du fait, préparées par l'appelant.
     * Texte brut, une idée par paragraphe. Aucune donnée saisie par un inconnu n'entre dans un sujet.
     */
    private function composer($modele, $contact, $d)
    {
        global $_LIEN_PAIEMENT_JOURS;

        $bonjour = ($contact->prenom !== null && $contact->prenom !== '') ? "Bonjour " . $contact->prenom . "," : "Bonjour,";
        $signature = "L'équipe NavUp\ncontact@navup.fr";
        $jours = isset($_LIEN_PAIEMENT_JOURS) ? (int) $_LIEN_PAIEMENT_JOURS : 30;
        $p = array();

        switch ($modele) {
            case 'bienvenue':
                $sujet = "Bienvenue chez NavUp";
                $p[] = "Votre inscription au programme NavUp est enregistrée : merci de votre confiance.";
                $p[] = "Votre programme commence le " . self::jourEcrit($d['date_debut']) . " et dure " . (int) $d['semaines'] . " semaines. Chaque semaine, de nouveaux contenus se débloquent.";
                $p[] = !empty($d['appli'])
                    ? "Votre espace personnel vous attend ici : " . $d['appli']
                    : "Nous revenons vers vous très vite pour vous ouvrir votre espace personnel.";
                if (!empty($d['echeances'])) {
                    $lignes = array();
                    foreach ($d['echeances'] as $e) {
                        $lignes[] = "- " . Vente::euros($e['montant']) . " le " . self::jourEcrit($e['date_prevue']);
                    }
                    $p[] = "Les prochaines échéances seront prélevées sur la carte que vous avez enregistrée :\n" . implode("\n", $lignes)
                        . "\nVous recevrez un e-mail quelques jours avant chacune.";
                }
                $p[] = "Une question ? Répondez simplement à cet e-mail.";
                break;

            case 'semaine':
                $sujet = "Semaine " . (int) $d['semaine'] . " : vos nouveaux contenus";
                $titres = array();
                foreach ($d['sujets'] as $titre) {
                    $titres[] = "- " . $titre;
                }
                $p[] = "La semaine " . (int) $d['semaine'] . " de votre programme NavUp est disponible" . (count($titres) > 0 ? " :\n" . implode("\n", $titres) : ".");
                $p[] = "Retrouvez-les dans votre espace personnel : " . $d['appli'];
                break;

            case 'lien_paiement':
                $sujet = "Votre paiement NavUp";
                $p[] = "Voici le lien pour régler " . Vente::euros($d['montant']) . " (" . $d['echeance'] . " de votre programme NavUp) :\n" . self::MARQUE_LIEN;
                $p[] = "Ce lien vous est personnel et reste valable $jours jours. Le paiement se fait sur la page sécurisée de notre prestataire, Stripe.";
                break;

            case 'prelevement_avis':
                $sujet = "Prélèvement prévu le " . self::jourEcrit($d['date_prevue']);
                $p[] = "Le " . self::jourEcrit($d['date_prevue']) . ", nous prélèverons " . Vente::euros($d['montant']) . " sur la carte que vous avez enregistrée, pour l'" . $d['echeance'] . " de votre programme NavUp.";
                $p[] = "Vous n'avez rien à faire. Si votre carte a changé ou si vous préférez régler autrement, répondez à cet e-mail avant cette date.";
                break;

            case 'paiement_echoue':
                $sujet = "Votre paiement n'a pas abouti";
                $p[] = "Le prélèvement de " . Vente::euros($d['montant']) . " pour l'" . $d['echeance'] . " de votre programme NavUp n'a pas abouti. Cela arrive : carte expirée, plafond atteint, vérification demandée par la banque.";
                $p[] = "Vous pouvez régler cette échéance ici :\n" . self::MARQUE_LIEN;
                $p[] = "Ce lien vous est personnel et reste valable $jours jours. Si vous préférez en parler avec nous, répondez à cet e-mail.";
                break;

            case 'commande_en_cours':
                // Déclenché par un inconnu qui a saisi cette adresse : ni prénom, ni rien de ce qu'il a saisi
                $bonjour = "Bonjour,";
                $sujet = "Votre commande NavUp";
                $p[] = "Une commande vient d'être commencée sur notre site avec cette adresse. Une commande y est déjà enregistrée : il n'est pas nécessaire de payer une seconde fois.";
                $p[] = "Si vous souhaitez changer de mode de règlement, ou si cette demande ne vient pas de vous, répondez à cet e-mail.";
                break;

            default:
                throw new LogicException("Modèle d'e-mail inconnu : " . $modele);
        }

        return array($sujet, $bonjour . "\n\n" . implode("\n\n", $p) . "\n\n" . $signature . "\n");
    }

    // DÉPÔT ##########################################################

    /**
     * Dépose un message dans la file. Sans transaction : l'appelant tient celle du fait qui cause le message,
     * le message n'existe donc que si le fait est écrit. La clé dédoublonne : un fait rejoué ne dépose rien.
     * $contact : ligne de dossier (id_contact, prenom, email). $options : objet_type, objet_id, origine, id_users.
     * Retourne l'identifiant du message, ou null (dossier sans adresse, ou message déjà déposé).
     */
    public function deposer($contact, $modele, $donnees, $cle, $options = array())
    {
        global $Mysql;

        if ($contact->email === null || filter_var($contact->email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        list($sujet, $corps) = $this->composer($modele, $contact, $donnees);

        $nb = $Mysql->execute(
            "INSERT IGNORE INTO m_message (id_contact, modele, module, destinataire, sujet, corps, objet_type, objet_id, cle, origine, id_users)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array(
                (int) $contact->id_contact,
                $modele,
                self::MODELES[$modele]['module'],
                $contact->email,
                mb_substr($sujet, 0, 200),
                $corps,
                $options['objet_type'] ?? null,
                isset($options['objet_id']) ? (int) $options['objet_id'] : null,
                mb_substr((string) $cle, 0, 100),
                $options['origine'] ?? 'automatique',
                isset($options['id_users']) ? (int) $options['id_users'] : null,
            ),
            'issssssissi'
        );

        return $nb === 1 ? $Mysql->lastId() : null;
    }

    // ENVOI ##########################################################

    /**
     * Transport : la seule méthode qui fait sortir un e-mail du serveur. Texte brut ; aucune donnée de dossier dans
     * un en-tête ; l'expéditeur vient de la configuration. En mode « essai », rien ne sort.
     * Retourne array(mode, erreur) ; erreur null si le message est parti (ou a été gardé en essai).
     */
    private function transporter($destinataire, $sujet, $corps)
    {
        global $_MAIL_MODE, $_MAIL_EXPEDITEUR_PARENTS;

        if (filter_var($destinataire, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $destinataire)) {
            return array(null, "Adresse du destinataire invalide.");
        }
        if (!isset($_MAIL_MODE) || $_MAIL_MODE !== 'reel') {
            return array('essai', null);
        }

        $de = isset($_MAIL_EXPEDITEUR_PARENTS) ? (string) $_MAIL_EXPEDITEUR_PARENTS : '';
        if (filter_var($de, FILTER_VALIDATE_EMAIL) === false) {
            return array(null, "Adresse de l'expéditeur absente de la configuration.");
        }

        $entetes = 'From: NavUp <' . $de . ">\r\n"
            . 'Reply-To: ' . $de . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";

        if (!Automate::mailPossible()) {
            return array(null, "Aucune messagerie n'est installée sur ce serveur.");
        }
        $ok = @mail($destinataire, mb_encode_mimeheader($sujet, 'UTF-8', 'B', "\r\n"), str_replace("\n", "\r\n", $corps), $entetes, '-f' . $de);

        return array('reel', $ok ? null : "Le serveur de messagerie a refusé le message.");
    }

    /**
     * Envoie un message de la file. Un verrou nommé empêche deux envois simultanés du même message ; le compteur
     * d'essais est augmenté avant l'envoi, pour qu'un message qui fait échouer le processus ne soit pas rejoué sans fin.
     * Au succès : état « envoyé » et fait « email » dans le fil du dossier, dans la même transaction.
     * Retourne true si le message est envoyé (ou l'était déjà).
     */
    public function envoyer($id_message)
    {
        global $SQL, $Mysql, $Contact, $_MAIL_ESSAIS_MAX;

        $id = (int) $id_message;
        $verrou = $Mysql->fetchOne("SELECT GET_LOCK(?, 0) AS pris", array('navup_message_' . $id), 's');
        if ($verrou === null || (int) $verrou->pris !== 1) {
            return false;
        }

        try {
            $m = $Mysql->fetchOne("SELECT * FROM m_message WHERE id_message = ?", array($id), 'i');
            if ($m === null || $m->etat === 'envoye') {
                return $m !== null;
            }
            $max = isset($_MAIL_ESSAIS_MAX) ? (int) $_MAIL_ESSAIS_MAX : 5;
            if (!in_array($m->etat, array('a_envoyer', 'erreur'), true) || (int) $m->essais >= $max) {
                return false;
            }
            $Mysql->execute("UPDATE m_message SET essais = essais + 1 WHERE id_message = ?", array($id), 'i');

            list($corps, $erreur) = $this->poserLiens($m);
            $mode = null;
            if ($erreur === null) {
                list($mode, $erreur) = $this->transporter($m->destinataire, $m->sujet, $corps);
            }

            if ($erreur !== null) {
                $Mysql->execute("UPDATE m_message SET etat = 'erreur', erreur = ? WHERE id_message = ?", array(mb_substr($erreur, 0, 255), $id), 'si');

                return false;
            }

            $SQL->begin_transaction();
            $Mysql->execute(
                "UPDATE m_message SET etat = 'envoye', mode = ?, erreur = NULL, date_envoi = NOW() WHERE id_message = ?",
                array($mode, $id),
                'si'
            );
            $codes = array('id_message' => $id, 'modele' => $m->modele);
            $Contact->tracer($m->id_contact, $m->id_users === null ? null : (int) $m->id_users, 'email_envoi', $codes, array(
                'type' => 'email', 'module' => $m->module, 'objet_type' => 'message', 'objet_id' => $id,
                'details' => array('modele' => $m->modele), 'origine' => $m->origine,
            ));
            $SQL->commit();

            return true;
        } finally {
            $Mysql->fetchOne("SELECT RELEASE_LOCK(?) AS rendu", array('navup_message_' . $id), 's');
        }
    }

    /**
     * Compose, au moment de l'envoi, le lien personnel d'un message : le corps gardé en base n'en porte que la marque.
     * Retourne array(corps, erreur).
     */
    private function poserLiens($m)
    {
        global $Stripe;

        if (strpos($m->corps, self::MARQUE_LIEN) === false) {
            return array($m->corps, null);
        }
        if ($m->objet_type !== 'vente' || $m->objet_id === null || !isset($Stripe)) {
            return array(null, "Lien de paiement impossible à composer.");
        }
        $lien = $Stripe->creerLien((int) $m->objet_id, null);
        if ($lien === null) {
            return array(null, "Plus rien à régler sur cette vente : le lien n'a pas été envoyé.");
        }

        return array(str_replace(self::MARQUE_LIEN, $lien, $m->corps), null);
    }

    /**
     * Envoie ce qui attend dans la file (passe « messages »), ou seulement les messages d'un dossier.
     * Un message en erreur est retenté tant que son compteur d'essais le permet.
     * Retourne array(envoyes, erreurs).
     */
    public function envoyerEnAttente($id_contact = null, $limite = 100)
    {
        global $Mysql, $_MAIL_ESSAIS_MAX;

        $max = isset($_MAIL_ESSAIS_MAX) ? (int) $_MAIL_ESSAIS_MAX : 5;
        $sql = "SELECT id_message FROM m_message WHERE etat IN ('a_envoyer', 'erreur') AND essais < ?";
        $params = array($max);
        if ($id_contact !== null) {
            $sql .= " AND id_contact = ?";
            $params[] = (int) $id_contact;
        }
        $params[] = (int) $limite;

        $envoyes = 0;
        $erreurs = 0;
        foreach ($Mysql->fetchAll($sql . " ORDER BY id_message LIMIT ?", $params) as $m) {
            if ($this->envoyer($m->id_message)) {
                $envoyes++;
            } else {
                $erreurs++;
            }
        }

        return array($envoyes, $erreurs);
    }

    /** Relance un message en erreur (geste de l'administrateur) : son compteur d'essais repart de zéro. */
    public function relancer($id_message, $id_users)
    {
        global $Mysql, $U;

        $id = (int) $id_message;
        $Mysql->execute("UPDATE m_message SET etat = 'a_envoyer', essais = 0, erreur = NULL WHERE id_message = ? AND etat = 'erreur'", array($id), 'i');
        $U->audit($id_users, 'email_relance', array('id_message' => $id), 'message', $id);

        return $this->envoyer($id);
    }

    // LECTURE ########################################################

    /** Message tel que servi au front. $avecCorps : le droit de lecture du module du modèle. */
    public function sortie($m, $avecCorps)
    {
        $ligne = array(
            'id_message' => (int) $m->id_message,
            'id_contact' => (int) $m->id_contact,
            'modele' => $m->modele,
            'libelle' => self::MODELES[$m->modele]['libelle'] ?? $m->modele,
            'module' => $m->module,
            'etat' => $m->etat,
            'mode' => $m->mode,
            'origine' => $m->origine,
            'date_envoi' => $m->date_envoi,
            'date_creation' => $m->date_creation,
            'lisible' => (bool) $avecCorps,
        );
        if ($avecCorps) {
            $ligne['destinataire'] = $m->destinataire;
            $ligne['sujet'] = $m->sujet;
            // La marque d'un lien personnel se lit en clair : le lien lui-même n'est gardé nulle part
            $ligne['corps'] = str_replace(self::MARQUE_LIEN, '(lien de paiement personnel)', $m->corps);
        }

        return $ligne;
    }

    /** E-mails d'un dossier, du plus récent au plus ancien. Le corps n'est servi qu'avec le droit du module du modèle. */
    public function duDossier($id_contact, $user)
    {
        global $Mysql, $U;

        $messages = array();
        foreach ($Mysql->fetchAll("SELECT * FROM m_message WHERE id_contact = ? ORDER BY id_message DESC LIMIT 100", array((int) $id_contact), 'i') as $m) {
            $messages[] = $this->sortie($m, $m->module === 'dossier' || $U->can($user, $m->module, 'L'));
        }

        return $messages;
    }
}

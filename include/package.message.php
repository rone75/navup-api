<?php

//=======================================================================
// File:        package.message.php
// Description: e-mails aux parents (CDC §13) : modèles, file d'envoi (m_message), envoi, trace dans le fil du dossier.
//              Un message se dépose (dans la transaction de ce qui le cause), puis s'envoie : tout de suite si
//              l'appelant le demande, sinon par la passe « messages » de la tâche planifiée.
//              Requiert package.contact.php ($Contact), package.user.php ($U), package.mysql.php ($Mysql).
//              Les e-mails de rendez-vous (étape 6b) demandent en plus package.suivi.php (Rdv : lien de gestion)
//              et package.ics.php (invitation jointe).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

require_once __DIR__ . '/package.modeles.php';

class Message
{
    // Modèles : libellé (affiché dans l'outil) et module dont le droit de lecture ouvre le corps du message.
    // « dossier » : lisible par qui lit le dossier. Les textes sont provisoires, à faire valider par NavUp ;
    // l'écran de réglage des modèles est attendu à l'étape 7.
    const MODELES = array(
        'bienvenue' => array('libelle' => 'Bienvenue', 'module' => 'dossier'),
        'semaine' => array('libelle' => 'Nouvelle semaine', 'module' => 'dossier'),
        'invitation' => array('libelle' => "Invitation à l'espace personnel", 'module' => 'dossier'),
        'mot_de_passe' => array('libelle' => 'Mot de passe oublié', 'module' => 'dossier'),
        'lien_paiement' => array('libelle' => 'Lien de paiement', 'module' => 'paiements'),
        'prelevement_avis' => array('libelle' => 'Prélèvement à venir', 'module' => 'paiements'),
        'paiement_echoue' => array('libelle' => 'Paiement échoué', 'module' => 'paiements'),
        'commande_en_cours' => array('libelle' => 'Commande déjà en cours', 'module' => 'paiements'),
        'rdv_confirmation' => array('libelle' => 'Rendez-vous confirmé', 'module' => 'rendez_vous'),
        'rdv_modification' => array('libelle' => 'Rendez-vous modifié', 'module' => 'rendez_vous'),
        'rdv_annulation' => array('libelle' => 'Rendez-vous annulé', 'module' => 'rendez_vous'),
        'rdv_rappel' => array('libelle' => 'Rappel de rendez-vous', 'module' => 'rendez_vous'),
        'rdv_deja' => array('libelle' => 'Rendez-vous déjà pris', 'module' => 'rendez_vous'),
        // Interne : avis au responsable quand un parent prend, déplace ou annule en ligne. Il ne s'écrit ni dans le fil
        // du dossier ni dans la liste de ses e-mails (le fait lui-même y est déjà) ; son destinataire est un utilisateur.
        'rdv_avis' => array('libelle' => 'Avis au responsable', 'module' => 'rendez_vous', 'interne' => true),
    );

    // Modèles d'un rendez-vous qui doit encore tenir au moment de l'envoi, et ceux qui portent l'invitation de calendrier
    const RDV_QUI_TIENT = array('rdv_confirmation', 'rdv_modification', 'rdv_rappel', 'rdv_deja');
    const RDV_AVEC_INVITATION = array('rdv_confirmation', 'rdv_modification');

    const RDV_TYPES = array(
        'decouverte' => 'rendez-vous découverte', 'suivi' => "rendez-vous d'accompagnement",
        'bilan' => 'rendez-vous de bilan', 'autre' => 'rendez-vous',
    );

    // Marque laissée dans le corps conservé à la place d'un lien personnel : le lien n'est composé qu'à l'envoi
    const MARQUE_LIEN = '{{lien_paiement}}';
    // Lien d'accès à l'espace personnel (création ou réinitialisation du mot de passe) : créé par Compte::lienAcces() à l'envoi
    const MARQUE_ACCES = '{{lien_acces}}';
    // Lien de gestion d'un rendez-vous (annuler, déplacer) : créé par Rdv::lienGestion() à l'envoi
    const MARQUE_RDV = '{{lien_rdv}}';

    const MOIS = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');
    const JOURS = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');

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

    /** « mardi 6 octobre 2026 à 14 h 30 », depuis une date et heure de Paris (AAAA-MM-JJ HH:MM:SS). */
    public static function momentEcrit($moment)
    {
        $date = substr((string) $moment, 0, 10);
        $minutes = substr((string) $moment, 14, 2);

        return self::JOURS[(int) (new DateTime($date . ' 12:00:00'))->format('N')] . ' ' . self::jourEcrit($date)
            . ' à ' . (int) substr((string) $moment, 11, 2) . ' h' . ($minutes === '00' ? '' : ' ' . $minutes);
    }

    /** Ce qu'un e-mail dit du déroulement d'un rendez-vous, selon son canal. Jamais un numéro : il a pu être saisi par un inconnu. */
    private static function deroulement($d)
    {
        if ($d['canal'] === 'visio') {
            return !empty($d['visio'])
                ? "Il se tient en visio. Pour nous rejoindre à l'heure dite :\n" . $d['visio']
                : "Il se tient en visio : nous vous envoyons le lien avant le rendez-vous.";
        }
        if ($d['canal'] === 'telephone') {
            return "Il se tient par téléphone : nous vous appelons au numéro que vous nous avez donné.";
        }

        return "Il se tient en personne : nous vous en précisons le lieu.";
    }

    /** Paragraphe du lien de gestion, ou la consigne de répondre quand l'appli des parents n'a pas d'adresse. */
    private static function gestion($d)
    {
        return !empty($d['gestion'])
            ? "Un empêchement ? Vous pouvez le déplacer ou l'annuler jusqu'à " . (int) $d['heures'] . " heures avant, ici :\n" . self::MARQUE_RDV
            : "Un empêchement ? Répondez à cet e-mail, nous trouverons un autre moment.";
    }

    // MODÈLES ########################################################

    /**
     * Compose un message : array(sujet, corps, version). Le texte est la version active du modèle (Modeles::actif :
     * celle que l'administrateur a enregistrée, sinon le gabarit d'origine), ses marques remplacées par les valeurs du
     * fait ($d, préparées par l'appelant). L'avis interne au responsable garde sa composition fixe.
     */
    private function composer($modele, $contact, $d)
    {
        if (!isset(Modeles::ORIGINES[$modele])) {
            list($sujet, $corps) = $this->composerOrigine($modele, $contact, $d);

            return array($sujet, $corps, 0);
        }
        $gabarit = Modeles::actif($modele);
        list($sujet, $corps) = Modeles::rendre($gabarit, Modeles::valeurs($modele, $contact, $d));

        return array($sujet, $corps, $gabarit['version']);
    }

    /**
     * Composition d'avant l'étape 7b, gardée telle quelle : elle compose l'avis interne au responsable, et sert de
     * référence à script-cgi/verifier-emails.php, qui prouve que chaque gabarit d'origine rend le même e-mail.
     * Texte brut, une idée par paragraphe. Aucune donnée saisie par un inconnu n'entre dans un sujet.
     */
    public function composerOrigine($modele, $contact, $d)
    {
        global $_LIEN_PAIEMENT_JOURS;

        // Rendez-vous pris sur la page publique : l'adresse n'est pas prouvée, rien de ce qui a été saisi n'est recopié
        $anonyme = !empty($d['inconnu']) || $modele === 'rdv_deja';
        $bonjour = (!$anonyme && $contact->prenom !== null && $contact->prenom !== '') ? "Bonjour " . $contact->prenom . "," : "Bonjour,";
        $signature = "L'équipe NavUp\ncontact@navup.fr";
        $jours = isset($_LIEN_PAIEMENT_JOURS) ? (int) $_LIEN_PAIEMENT_JOURS : 30;
        $p = array();

        switch ($modele) {
            case 'bienvenue':
                $sujet = "Bienvenue chez NavUp";
                $p[] = "Votre inscription au programme NavUp est enregistrée : merci de votre confiance.";
                $p[] = "Votre programme commence le " . self::jourEcrit($d['date_debut']) . " et dure " . (int) $d['semaines'] . " semaines. Chaque semaine, de nouveaux contenus se débloquent.";
                $p[] = !empty($d['appli'])
                    ? "Votre espace personnel est prêt. Pour y entrer, choisissez votre mot de passe :\n" . self::MARQUE_ACCES
                        . "\nCe lien vous est personnel et reste valable " . (int) ($d['jours'] ?? 7) . " jours. Ensuite, vous vous connecterez ici : " . $d['appli']
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

            case 'invitation':
                $sujet = "Votre espace personnel NavUp";
                $p[] = "Votre espace personnel NavUp est prêt : vous y retrouvez les audios et les fiches de votre programme, semaine après semaine.";
                $p[] = "Pour y entrer, choisissez votre mot de passe :\n" . self::MARQUE_ACCES;
                $p[] = "Ce lien vous est personnel et reste valable " . (int) $d['jours'] . " jours. Passé ce délai, demandez-en un nouveau depuis la page de connexion : " . $d['appli'];
                break;

            case 'mot_de_passe':
                $sujet = "Votre mot de passe NavUp";
                $p[] = "Voici le lien pour choisir un nouveau mot de passe :\n" . self::MARQUE_ACCES;
                $p[] = "Il reste valable " . (int) $d['minutes'] . " minutes et ne sert qu'une fois.";
                $p[] = "Si cette demande ne vient pas de vous, ignorez cet e-mail : votre mot de passe actuel ne change pas.";
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

            case 'rdv_confirmation':
                $sujet = "Votre rendez-vous NavUp du " . self::jourEcrit($d['date_debut']);
                $p[] = "Votre " . self::RDV_TYPES[$d['type']] . " est confirmé : " . self::momentEcrit($d['date_debut']) . " (heure de Paris), pour " . (int) $d['duree'] . " minutes environ.";
                $p[] = self::deroulement($d);
                $p[] = "L'invitation jointe (fichier .ics) l'ajoute à votre agenda.";
                $p[] = self::gestion($d);
                if (!empty($d['inconnu'])) {
                    $p[] = "Ce n'est pas vous qui avez pris ce rendez-vous ? Annulez-le par le même lien, ou répondez à cet e-mail.";
                }
                break;

            case 'rdv_modification':
                $sujet = "Votre rendez-vous NavUp est modifié";
                if (!empty($d['a_confirmer'])) {
                    $p[] = "Votre " . self::RDV_TYPES[$d['type']] . " du " . self::momentEcrit($d['ancien_debut']) . " ne tient plus à cette date.";
                    $p[] = "Nouveau créneau proposé : " . self::momentEcrit($d['date_debut']) . " (heure de Paris). Il reste à confirmer : nous revenons vers vous.";
                    $p[] = "Si vous aviez ajouté l'ancien rendez-vous à votre agenda, pensez à le retirer.";
                } else {
                    $p[] = !empty($d['ancien_debut'])
                        ? "Votre " . self::RDV_TYPES[$d['type']] . " du " . self::momentEcrit($d['ancien_debut']) . " est déplacé. Nouveau créneau : " . self::momentEcrit($d['date_debut']) . " (heure de Paris), pour " . (int) $d['duree'] . " minutes environ."
                        : "Votre " . self::RDV_TYPES[$d['type']] . " du " . self::momentEcrit($d['date_debut']) . " (heure de Paris) est modifié. Il dure " . (int) $d['duree'] . " minutes environ.";
                    $p[] = self::deroulement($d);
                    $p[] = "L'invitation jointe (fichier .ics) remplace la précédente dans votre agenda. Si l'ancien rendez-vous y reste, retirez-le.";
                    $p[] = self::gestion($d);
                }
                break;

            case 'rdv_annulation':
                $sujet = "Votre rendez-vous NavUp est annulé";
                $p[] = !empty($d['par_parent'])
                    ? "C'est noté : votre " . self::RDV_TYPES[$d['type']] . " du " . self::momentEcrit($d['date_debut']) . " est annulé."
                    : "Votre " . self::RDV_TYPES[$d['type']] . " du " . self::momentEcrit($d['date_debut']) . " est annulé.";
                $p[] = "Si vous l'aviez ajouté à votre agenda, pensez à le retirer.";
                $p[] = !empty($d['appli'])
                    ? "Pour en reprendre un quand vous le souhaitez : " . $d['appli']
                    : "Pour en reprendre un, répondez simplement à cet e-mail.";
                break;

            case 'rdv_rappel':
                $sujet = "Rappel : votre rendez-vous NavUp du " . self::jourEcrit($d['date_debut']);
                $p[] = "Votre " . self::RDV_TYPES[$d['type']] . " approche : " . self::momentEcrit($d['date_debut']) . " (heure de Paris).";
                $p[] = self::deroulement($d);
                $p[] = self::gestion($d);
                break;

            case 'rdv_deja':
                // Déclenché par un inconnu qui a saisi cette adresse : ni prénom, ni rien de ce qu'il a saisi
                $sujet = "Votre rendez-vous NavUp";
                $p[] = "Une demande de rendez-vous vient d'être faite sur notre site avec cette adresse. Vous avez déjà un " . self::RDV_TYPES[$d['type']] . " : " . self::momentEcrit($d['date_debut']) . " (heure de Paris). Le nouveau créneau n'a donc pas été réservé.";
                $p[] = self::gestion($d);
                $p[] = "Si cette demande ne vient pas de vous, ignorez cet e-mail : votre rendez-vous ne change pas.";
                break;

            case 'rdv_avis':
                // Au responsable : ce que montre aussi son flux d'agenda, rien de plus (ni nom complet, ni note)
                $faits = array('pris' => 'pris en ligne', 'deplace' => 'déplacé par le parent', 'annule' => 'annulé par le parent');
                $signature = "La Tour de contrôle NavUp";
                $sujet = "Rendez-vous " . $faits[$d['fait']];
                $p[] = $d['titre'] . "\n" . self::momentEcrit($d['date_debut']) . ", " . (int) $d['duree'] . " min, " . $d['canal_libelle']
                    . (!empty($d['ancien_debut']) ? "\nAuparavant : " . self::momentEcrit($d['ancien_debut']) : '');
                if (!empty($d['a_relancer'])) {
                    $p[] = "Ce dossier n'a plus de rendez-vous à venir : à relancer ?";
                }
                if (!empty($d['fiche'])) {
                    $p[] = "Ouvrir la fiche : " . $d['fiche'];
                }
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
        list($sujet, $corps, $version) = $this->composer($modele, $contact, $donnees);

        $nb = $Mysql->execute(
            "INSERT IGNORE INTO m_message (id_contact, modele, version_modele, module, destinataire, sujet, corps, objet_type, objet_id, cle, origine, id_users)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array(
                (int) $contact->id_contact,
                $modele,
                (int) $version,
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
            'isisssssissi'
        );

        return $nb === 1 ? $Mysql->lastId() : null;
    }

    // ENVOI ##########################################################

    /**
     * Assemble le contenu d'un e-mail : array(en-têtes de contenu, corps). Sans pièce jointe, du texte brut ; avec,
     * un message en plusieurs parties (le texte, puis chaque pièce encodée en base64). Fonction pure, en « \n » seul :
     * elle se contrôle sans rien envoyer (script-cgi/essai-rdv.php, verifier-agenda.php).
     * $pieces : array(array(nom, type, contenu)) ; le nom et le type viennent du code, jamais d'une saisie.
     */
    public static function assembler($corps, $pieces = array(), $frontiere = null)
    {
        if (count($pieces) === 0) {
            return array("MIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n", $corps);
        }
        $frontiere = $frontiere ?? 'navup-' . bin2hex(random_bytes(12));
        $parties = "--$frontiere\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n" . rtrim($corps, "\n") . "\n";
        foreach ($pieces as $piece) {
            $parties .= "--$frontiere\nContent-Type: " . $piece['type'] . '; name="' . $piece['nom'] . "\"\n"
                . "Content-Transfer-Encoding: base64\n"
                . 'Content-Disposition: attachment; filename="' . $piece['nom'] . "\"\n\n"
                . rtrim(chunk_split(base64_encode($piece['contenu']), 76, "\n"), "\n") . "\n";
        }

        return array("MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"$frontiere\"\n", $parties . "--$frontiere--\n");
    }

    /**
     * Transport : la seule méthode qui fait sortir un e-mail du serveur. Texte brut, avec ses pièces jointes s'il en a ;
     * aucune donnée de dossier dans un en-tête ; l'expéditeur vient de la configuration. En mode « essai », rien ne sort.
     * Retourne array(mode, erreur) ; erreur null si le message est parti (ou a été gardé en essai).
     */
    private function transporter($destinataire, $sujet, $corps, $pieces = array())
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

        list($contenu, $message) = self::assembler($corps, $pieces);
        $entetes = 'From: NavUp <' . $de . ">\r\n"
            . 'Reply-To: ' . $de . "\r\n"
            . str_replace("\n", "\r\n", $contenu);

        if (!Automate::mailPossible()) {
            return array(null, "Aucune messagerie n'est installée sur ce serveur.");
        }
        $ok = @mail($destinataire, mb_encode_mimeheader($sujet, 'UTF-8', 'B', "\r\n"), str_replace("\n", "\r\n", $message), $entetes, '-f' . $de);

        return array('reel', $ok ? null : "Le serveur de messagerie a refusé le message.");
    }

    /**
     * Ce message a-t-il encore lieu d'être envoyé ? Retourne la raison qui le rend sans objet, ou null.
     * - L'adresse du dossier a changé depuis le dépôt : le message ne part pas à l'ancienne (un lien personnel y serait livré).
     * - Le rendez-vous annoncé ne tient plus (déplacé, annulé, passé), ou celui qu'on disait annulé a été rétabli.
     * Un avis interne décrit un fait accompli : il part toujours.
     */
    private function perime($m)
    {
        global $Mysql;

        if (!empty(self::MODELES[$m->modele]['interne'])) {
            return null;
        }
        $dossier = $Mysql->fetchOne("SELECT email FROM d_contact WHERE id_contact = ?", array((int) $m->id_contact), 'i');
        if ($dossier === null || $dossier->email === null || strcasecmp($dossier->email, $m->destinataire) !== 0) {
            return "L'adresse du dossier a changé depuis ce message : il n'a pas été envoyé.";
        }

        if ($m->objet_type === 'rdv' && $m->objet_id !== null) {
            $r = $Mysql->fetchOne("SELECT statut, date_debut FROM r_rdv WHERE id_rdv = ?", array((int) $m->objet_id), 'i');
            if (in_array($m->modele, self::RDV_QUI_TIENT, true)
                && ($r === null || !in_array($r->statut, array('a_confirmer', 'confirme'), true) || $r->date_debut <= date('Y-m-d H:i:s'))) {
                return "Le rendez-vous ne tient plus : le message n'a pas été envoyé.";
            }
            if ($m->modele === 'rdv_annulation' && ($r === null || $r->statut !== 'annule')) {
                return "Le rendez-vous n'est plus annulé : le message n'a pas été envoyé.";
            }
        }

        return null;
    }

    /** Pièces jointes d'un message, composées à l'envoi : l'invitation de calendrier d'un rendez-vous confirmé. */
    private function pieces($m)
    {
        global $Rdv;

        if ($m->objet_type !== 'rdv' || !in_array($m->modele, self::RDV_AVEC_INVITATION, true) || !isset($Rdv) || !class_exists('Ics', false)) {
            return array();
        }
        $r = $Rdv->charger((int) $m->objet_id);
        if ($r === null || $r->statut !== 'confirme') {
            return array();
        }

        return array(array('nom' => Ics::NOM, 'type' => 'text/calendar; charset=UTF-8; method=PUBLISH', 'contenu' => Ics::invitation($r, $Rdv->lienVisio($r))));
    }

    /** Le message tel qu'il partirait (contrôles et essais) : array(sujet, en-têtes de contenu, corps). Compose les liens : à ne pas servir. */
    public function apercu($id_message)
    {
        global $Mysql;

        $m = $Mysql->fetchOne("SELECT * FROM m_message WHERE id_message = ?", array((int) $id_message), 'i');
        list($corps, $erreur) = $this->poserLiens($m);
        if ($erreur !== null) {
            throw new RuntimeException($erreur);
        }
        list($entetes, $message) = self::assembler($corps, $this->pieces($m));

        return array($m->sujet, $entetes, $message);
    }

    /**
     * Envoie un message de la file. Un verrou nommé empêche deux envois simultanés du même message ; le compteur
     * d'essais est augmenté avant l'envoi, pour qu'un message qui fait échouer le processus ne soit pas rejoué sans fin.
     * Au succès : état « envoyé » et fait « email » dans le fil du dossier, dans la même transaction.
     * Un message devenu sans objet (perime()) passe à l'état « annulé » au lieu de partir.
     * Retourne true si le message est envoyé (ou l'était déjà), false en cas d'échec, null s'il a été annulé.
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
            $raison = $this->perime($m);
            if ($raison !== null) {
                $Mysql->execute("UPDATE m_message SET etat = 'annule', erreur = ? WHERE id_message = ?", array(mb_substr($raison, 0, 255), $id), 'si');

                return null;
            }
            $Mysql->execute("UPDATE m_message SET essais = essais + 1 WHERE id_message = ?", array($id), 'i');

            list($corps, $erreur) = $this->poserLiens($m);
            $mode = null;
            if ($erreur === null) {
                list($mode, $erreur) = $this->transporter($m->destinataire, $m->sujet, $corps, $this->pieces($m));
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
            // Un avis interne ne s'écrit pas dans le fil du dossier : il n'est pas adressé au parent
            $Contact->tracer($m->id_contact, $m->id_users === null ? null : (int) $m->id_users, 'email_envoi', $codes, !empty(self::MODELES[$m->modele]['interne']) ? null : array(
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

        $corps = $m->corps;

        if (strpos($corps, self::MARQUE_LIEN) !== false) {
            if ($m->objet_type !== 'vente' || $m->objet_id === null || !isset($Stripe)) {
                return array(null, "Lien de paiement impossible à composer.");
            }
            $lien = $Stripe->creerLien((int) $m->objet_id, null);
            if ($lien === null) {
                return array(null, "Plus rien à régler sur cette vente : le lien n'a pas été envoyé.");
            }
            $corps = str_replace(self::MARQUE_LIEN, $lien, $corps);
        }

        if (strpos($corps, self::MARQUE_ACCES) !== false) {
            // Le compte est celui du dossier, quel que soit l'objet du message (une vente pour l'e-mail de bienvenue)
            $compte = Compte::charger($m->id_contact);
            $lien = $compte === null ? null : Compte::lienAcces(
                $compte->id_compte,
                $m->modele === 'mot_de_passe' ? 'reinitialisation' : 'creation',
                $m->id_users === null ? null : (int) $m->id_users
            );
            if ($lien === null) {
                return array(null, "Lien d'accès impossible à composer : le dossier n'a pas de compte, ou l'appli des parents n'a pas d'adresse.");
            }
            $corps = str_replace(self::MARQUE_ACCES, $lien, $corps);
        }

        if (strpos($corps, self::MARQUE_RDV) !== false) {
            global $Rdv;

            $r = ($m->objet_type === 'rdv' && $m->objet_id !== null && isset($Rdv)) ? $Rdv->charger((int) $m->objet_id) : null;
            $lien = $r === null ? null : $Rdv->lienGestion($r);
            if ($lien === null) {
                return array(null, "Lien de gestion du rendez-vous impossible à composer.");
            }
            $corps = str_replace(self::MARQUE_RDV, $lien, $corps);
        }

        return array($corps, null);
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
            $envoye = $this->envoyer($m->id_message);
            if ($envoye === true) {
                $envoyes++;
            } elseif ($envoye === false) {
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
            $ligne['corps'] = str_replace(
                array(self::MARQUE_LIEN, self::MARQUE_ACCES, self::MARQUE_RDV),
                array('(lien de paiement personnel)', "(lien d'accès personnel)", '(lien personnel de gestion du rendez-vous)'),
                $m->corps
            );
        }

        return $ligne;
    }

    /** Modèles internes (adressés à un utilisateur, pas au parent), pour une clause SQL. */
    public static function internes()
    {
        $out = array();
        foreach (self::MODELES as $code => $modele) {
            if (!empty($modele['interne'])) {
                $out[] = "'" . $code . "'";
            }
        }

        return implode(', ', $out);
    }

    /**
     * E-mails adressés au parent d'un dossier, du plus récent au plus ancien. Le corps n'est servi qu'avec le droit
     * du module du modèle. $ids_rdv : seulement ceux d'une chaîne de rendez-vous.
     */
    public function duDossier($id_contact, $user, $ids_rdv = null)
    {
        global $Mysql, $U;

        $sql = "SELECT * FROM m_message WHERE id_contact = ? AND modele NOT IN (" . self::internes() . ")";
        $params = array((int) $id_contact);
        if ($ids_rdv !== null) {
            if (count($ids_rdv) === 0) {
                return array();
            }
            $sql .= " AND objet_type = 'rdv' AND objet_id IN (" . implode(', ', array_fill(0, count($ids_rdv), '?')) . ")";
            $params = array_merge($params, array_map('intval', $ids_rdv));
        }

        $messages = array();
        foreach ($Mysql->fetchAll($sql . " ORDER BY id_message DESC LIMIT 100", $params) as $m) {
            $messages[] = $this->sortie($m, $m->module === 'dossier' || $U->can($user, $m->module, 'L'));
        }

        return $messages;
    }
}

<?php

//=======================================================================
// File:        package.modeles.php
// Description: modèles d'e-mails aux parents modifiables depuis l'outil (étape 7b ; CDC §13, §21).
//              Un modèle = un objet et un corps qui portent des marques {{…}} :
//                - variables : une valeur courte (une date, un montant, un nombre), libres d'usage ;
//                - blocs calculés : un ou plusieurs paragraphes que le code compose selon le cas (échéances, accès,
//                  déroulement d'un rendez-vous, lien pour le gérer…), obligatoires dans le corps ;
//                - liens personnels ({{lien_paiement}}, {{lien_acces}}, {{lien_rdv}}) : posés à l'envoi seulement
//                  (Message::poserLiens), obligatoires quand le modèle en porte un hors d'un bloc.
//              Un paragraphe qui ne contient plus rien une fois les marques remplacées disparaît (un bloc vide).
//              Les gabarits d'origine ci-dessous rendent, à l'octet près, les e-mails d'avant l'étape 7b
//              (script-cgi/verifier-emails.php le prouve). La version active d'un modèle est dans m_modele ;
//              sans elle, le gabarit d'origine (version 0) s'applique.
//              Requiert package.message.php (Message : dates, libellés, marques) et package.vente.php (Vente::euros).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Modeles
{
    const SIGNATURE = "L'équipe NavUp\ncontact@navup.fr";
    const OBJET_MAX = 200;
    const CORPS_MAX = 6000;

    // Modèles que l'outil ne modifie pas : l'avis interne au responsable n'est pas adressé au parent
    const FIGES = array('rdv_avis');

    /** Gabarits d'origine : le texte d'avant l'étape 7b, mot pour mot. */
    const ORIGINES = array(
        'bienvenue' => array(
            'objet' => "Bienvenue chez NavUp",
            'corps' => "{{bonjour}}\n\nVotre inscription au programme NavUp est enregistrée : merci de votre confiance.\n\nVotre programme commence le {{date_debut}} et dure {{semaines}} semaines. Chaque semaine, de nouveaux contenus se débloquent.\n\n{{acces}}\n\n{{echeances}}\n\nUne question ? Répondez simplement à cet e-mail.\n\n" . self::SIGNATURE,
        ),
        'semaine' => array(
            'objet' => "Semaine {{semaine}} : vos nouveaux contenus",
            'corps' => "{{bonjour}}\n\nLa semaine {{semaine}} de votre programme NavUp est disponible{{sujets}}\n\nRetrouvez-les dans votre espace personnel : {{appli}}\n\n" . self::SIGNATURE,
        ),
        'invitation' => array(
            'objet' => "Votre espace personnel NavUp",
            'corps' => "{{bonjour}}\n\nVotre espace personnel NavUp est prêt : vous y retrouvez les audios et les fiches de votre programme, semaine après semaine.\n\nPour y entrer, choisissez votre mot de passe :\n{{lien_acces}}\n\nCe lien vous est personnel et reste valable {{jours}} jours. Passé ce délai, demandez-en un nouveau depuis la page de connexion : {{appli}}\n\n" . self::SIGNATURE,
        ),
        'mot_de_passe' => array(
            'objet' => "Votre mot de passe NavUp",
            'corps' => "{{bonjour}}\n\nVoici le lien pour choisir un nouveau mot de passe :\n{{lien_acces}}\n\nIl reste valable {{minutes}} minutes et ne sert qu'une fois.\n\nSi cette demande ne vient pas de vous, ignorez cet e-mail : votre mot de passe actuel ne change pas.\n\n" . self::SIGNATURE,
        ),
        'lien_paiement' => array(
            'objet' => "Votre paiement NavUp",
            'corps' => "{{bonjour}}\n\nVoici le lien pour régler {{montant}} ({{echeance}} de votre programme NavUp) :\n{{lien_paiement}}\n\nCe lien vous est personnel et reste valable {{jours}} jours. Le paiement se fait sur la page sécurisée de notre prestataire, Stripe.\n\n" . self::SIGNATURE,
        ),
        'prelevement_avis' => array(
            'objet' => "Prélèvement prévu le {{date_prevue}}",
            'corps' => "{{bonjour}}\n\nLe {{date_prevue}}, nous prélèverons {{montant}} sur la carte que vous avez enregistrée, pour l'{{echeance}} de votre programme NavUp.\n\nVous n'avez rien à faire. Si votre carte a changé ou si vous préférez régler autrement, répondez à cet e-mail avant cette date.\n\n" . self::SIGNATURE,
        ),
        'paiement_echoue' => array(
            'objet' => "Votre paiement n'a pas abouti",
            'corps' => "{{bonjour}}\n\nLe prélèvement de {{montant}} pour l'{{echeance}} de votre programme NavUp n'a pas abouti. Cela arrive : carte expirée, plafond atteint, vérification demandée par la banque.\n\nVous pouvez régler cette échéance ici :\n{{lien_paiement}}\n\nCe lien vous est personnel et reste valable {{jours}} jours. Si vous préférez en parler avec nous, répondez à cet e-mail.\n\n" . self::SIGNATURE,
        ),
        'commande_en_cours' => array(
            'objet' => "Votre commande NavUp",
            'corps' => "{{bonjour}}\n\nUne commande vient d'être commencée sur notre site avec cette adresse. Une commande y est déjà enregistrée : il n'est pas nécessaire de payer une seconde fois.\n\nSi vous souhaitez changer de mode de règlement, ou si cette demande ne vient pas de vous, répondez à cet e-mail.\n\n" . self::SIGNATURE,
        ),
        'rdv_confirmation' => array(
            'objet' => "Votre rendez-vous NavUp du {{jour}}",
            'corps' => "{{bonjour}}\n\nVotre {{type_rdv}} est confirmé : {{moment}} (heure de Paris), pour {{duree}} minutes environ.\n\n{{deroulement}}\n\nL'invitation jointe (fichier .ics) l'ajoute à votre agenda.\n\n{{gestion}}\n\n{{pas_vous}}\n\n" . self::SIGNATURE,
        ),
        'rdv_modification' => array(
            'objet' => "Votre rendez-vous NavUp est modifié",
            'corps' => "{{bonjour}}\n\n{{changement}}\n\n{{deroulement}}\n\n{{agenda}}\n\n{{gestion}}\n\n" . self::SIGNATURE,
        ),
        'rdv_annulation' => array(
            'objet' => "Votre rendez-vous NavUp est annulé",
            'corps' => "{{bonjour}}\n\n{{annulation}}\n\nSi vous l'aviez ajouté à votre agenda, pensez à le retirer.\n\n{{reprendre}}\n\n" . self::SIGNATURE,
        ),
        'rdv_rappel' => array(
            'objet' => "Rappel : votre rendez-vous NavUp du {{jour}}",
            'corps' => "{{bonjour}}\n\nVotre {{type_rdv}} approche : {{moment}} (heure de Paris).\n\n{{deroulement}}\n\n{{gestion}}\n\n" . self::SIGNATURE,
        ),
        'rdv_deja' => array(
            'objet' => "Votre rendez-vous NavUp",
            'corps' => "{{bonjour}}\n\nUne demande de rendez-vous vient d'être faite sur notre site avec cette adresse. Vous avez déjà un {{type_rdv}} : {{moment}} (heure de Paris). Le nouveau créneau n'a donc pas été réservé.\n\n{{gestion}}\n\nSi cette demande ne vient pas de vous, ignorez cet e-mail : votre rendez-vous ne change pas.\n\n" . self::SIGNATURE,
        ),
    );

    /**
     * Marques de chaque modèle : code => array(sorte, description). sorte : variable, bloc, lien.
     * {{bonjour}} est commune : « Bonjour Camille, », ou « Bonjour, » quand l'adresse n'est pas prouvée.
     */
    public static function marques($code)
    {
        $commun = array('bonjour' => array('bloc', "La formule d'accueil : « Bonjour Camille, », ou « Bonjour, » quand on ne sait pas qui écrit"));
        $rdv = array(
            'type_rdv' => array('variable', 'Le type de rendez-vous : « rendez-vous découverte »…'),
            'jour' => array('variable', 'Le jour du rendez-vous : « 6 octobre 2026 »'),
            'moment' => array('variable', 'Le jour et l’heure : « mardi 6 octobre 2026 à 14 h 30 »'),
            'duree' => array('variable', 'La durée, en minutes'),
            'deroulement' => array('bloc', 'Comment il se tient : le lien de visio, ou « nous vous appelons »'),
            'gestion' => array('bloc', 'Le lien personnel pour le déplacer ou l’annuler, et jusqu’à quand'),
        );
        $m = array(
            'bienvenue' => array(
                'date_debut' => array('variable', 'Le jour où le programme commence'),
                'semaines' => array('variable', 'Le nombre de semaines du programme'),
                'acces' => array('bloc', 'Le lien personnel pour choisir son mot de passe, ou l’annonce de l’espace à venir'),
                'echeances' => array('bloc', 'Les prochains prélèvements, s’il y en a'),
            ),
            'semaine' => array(
                'semaine' => array('variable', 'Le numéro de la semaine'),
                'sujets' => array('bloc', 'Les titres des sujets de la semaine, en liste'),
                'appli' => array('variable', 'L’adresse de l’espace personnel'),
            ),
            'invitation' => array(
                'lien_acces' => array('lien', 'Le lien personnel pour choisir son mot de passe'),
                'jours' => array('variable', 'La durée de validité du lien, en jours'),
                'appli' => array('variable', 'L’adresse de l’espace personnel'),
            ),
            'mot_de_passe' => array(
                'lien_acces' => array('lien', 'Le lien personnel pour choisir un nouveau mot de passe'),
                'minutes' => array('variable', 'La durée de validité du lien, en minutes'),
            ),
            'lien_paiement' => array(
                'montant' => array('variable', 'Le montant à régler'),
                'echeance' => array('variable', 'L’échéance : « 2e échéance »…'),
                'lien_paiement' => array('lien', 'Le lien personnel de paiement'),
                'jours' => array('variable', 'La durée de validité du lien, en jours'),
            ),
            'prelevement_avis' => array(
                'date_prevue' => array('variable', 'Le jour du prélèvement'),
                'montant' => array('variable', 'Le montant prélevé'),
                'echeance' => array('variable', 'L’échéance : « 2e échéance »…'),
            ),
            'paiement_echoue' => array(
                'montant' => array('variable', 'Le montant qui n’a pas été prélevé'),
                'echeance' => array('variable', 'L’échéance : « 2e échéance »…'),
                'lien_paiement' => array('lien', 'Le lien personnel de paiement'),
                'jours' => array('variable', 'La durée de validité du lien, en jours'),
            ),
            'commande_en_cours' => array(),
            'rdv_confirmation' => $rdv + array('pas_vous' => array('bloc', '« Ce n’est pas vous ? », quand le rendez-vous a été pris sur la page publique')),
            'rdv_modification' => array(
                'changement' => array('bloc', 'Ce qui change : l’ancien et le nouveau créneau, ou la durée et la façon d’échanger'),
                'deroulement' => $rdv['deroulement'],
                'agenda' => array('bloc', 'Ce qu’il faut faire dans son agenda'),
                'gestion' => $rdv['gestion'],
            ),
            'rdv_annulation' => array(
                'annulation' => array('bloc', 'Le rendez-vous annulé, et par qui'),
                'reprendre' => array('bloc', 'Comment en reprendre un'),
            ),
            'rdv_rappel' => $rdv,
            'rdv_deja' => array('type_rdv' => $rdv['type_rdv'], 'moment' => $rdv['moment'], 'gestion' => $rdv['gestion']),
        );

        return $commun + ($m[$code] ?? array());
    }

    /** Les modèles modifiables, dans l'ordre de la liste. */
    public static function modifiables()
    {
        return array_keys(self::ORIGINES);
    }

    // VALEURS DES MARQUES ############################################

    private static function rdvType($d)
    {
        return Message::RDV_TYPES[$d['type']] ?? 'rendez-vous';
    }

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

    private static function gestion($d)
    {
        return !empty($d['gestion'])
            ? "Un empêchement ? Vous pouvez le déplacer ou l'annuler jusqu'à " . (int) $d['heures'] . " heures avant, ici :\n" . Message::MARQUE_RDV
            : "Un empêchement ? Répondez à cet e-mail, nous trouverons un autre moment.";
    }

    /**
     * Valeurs des marques d'un modèle pour un message : $contact (prénom), $d (données du fait, celles d'avant 7b).
     * Les liens personnels restent des marques : ils ne sont posés qu'à l'envoi.
     */
    public static function valeurs($code, $contact, $d)
    {
        global $_LIEN_PAIEMENT_JOURS;

        $anonyme = !empty($d['inconnu']) || in_array($code, array('rdv_deja', 'commande_en_cours'), true);
        $v = array('bonjour' => (!$anonyme && $contact->prenom !== null && $contact->prenom !== '') ? "Bonjour " . $contact->prenom . "," : "Bonjour,");
        $jours = isset($_LIEN_PAIEMENT_JOURS) ? (int) $_LIEN_PAIEMENT_JOURS : 30;

        switch ($code) {
            case 'bienvenue':
                $v['date_debut'] = Message::jourEcrit($d['date_debut']);
                $v['semaines'] = (string) (int) $d['semaines'];
                $v['acces'] = !empty($d['appli'])
                    ? "Votre espace personnel est prêt. Pour y entrer, choisissez votre mot de passe :\n" . Message::MARQUE_ACCES
                        . "\nCe lien vous est personnel et reste valable " . (int) ($d['jours'] ?? 7) . " jours. Ensuite, vous vous connecterez ici : " . $d['appli']
                    : "Nous revenons vers vous très vite pour vous ouvrir votre espace personnel.";
                $v['echeances'] = '';
                if (!empty($d['echeances'])) {
                    $lignes = array();
                    foreach ($d['echeances'] as $e) {
                        $lignes[] = "- " . Vente::euros($e['montant']) . " le " . Message::jourEcrit($e['date_prevue']);
                    }
                    $v['echeances'] = "Les prochaines échéances seront prélevées sur la carte que vous avez enregistrée :\n" . implode("\n", $lignes)
                        . "\nVous recevrez un e-mail quelques jours avant chacune.";
                }
                break;

            case 'semaine':
                $titres = array();
                foreach ($d['sujets'] as $titre) {
                    $titres[] = "- " . $titre;
                }
                $v['semaine'] = (string) (int) $d['semaine'];
                $v['sujets'] = count($titres) > 0 ? " :\n" . implode("\n", $titres) : ".";
                $v['appli'] = (string) $d['appli'];
                break;

            case 'invitation':
                $v['lien_acces'] = Message::MARQUE_ACCES;
                $v['jours'] = (string) (int) $d['jours'];
                $v['appli'] = (string) $d['appli'];
                break;

            case 'mot_de_passe':
                $v['lien_acces'] = Message::MARQUE_ACCES;
                $v['minutes'] = (string) (int) $d['minutes'];
                break;

            case 'lien_paiement':
            case 'paiement_echoue':
                $v['montant'] = Vente::euros($d['montant']);
                $v['echeance'] = (string) $d['echeance'];
                $v['lien_paiement'] = Message::MARQUE_LIEN;
                $v['jours'] = (string) $jours;
                break;

            case 'prelevement_avis':
                $v['date_prevue'] = Message::jourEcrit($d['date_prevue']);
                $v['montant'] = Vente::euros($d['montant']);
                $v['echeance'] = (string) $d['echeance'];
                break;

            case 'rdv_confirmation':
            case 'rdv_rappel':
            case 'rdv_deja':
                $v['type_rdv'] = self::rdvType($d);
                $v['jour'] = Message::jourEcrit($d['date_debut']);
                $v['moment'] = Message::momentEcrit($d['date_debut']);
                $v['duree'] = (string) (int) ($d['duree'] ?? 0);
                $v['deroulement'] = isset($d['canal']) ? self::deroulement($d) : '';
                $v['gestion'] = self::gestion($d);
                $v['pas_vous'] = !empty($d['inconnu']) ? "Ce n'est pas vous qui avez pris ce rendez-vous ? Annulez-le par le même lien, ou répondez à cet e-mail." : '';
                break;

            case 'rdv_modification':
                $type = self::rdvType($d);
                if (!empty($d['a_confirmer'])) {
                    $v['changement'] = "Votre " . $type . " du " . Message::momentEcrit($d['ancien_debut']) . " ne tient plus à cette date.\n\n"
                        . "Nouveau créneau proposé : " . Message::momentEcrit($d['date_debut']) . " (heure de Paris). Il reste à confirmer : nous revenons vers vous.\n\n"
                        . "Si vous aviez ajouté l'ancien rendez-vous à votre agenda, pensez à le retirer.";
                    $v['deroulement'] = '';
                    $v['agenda'] = '';
                    $v['gestion'] = '';
                } else {
                    $v['changement'] = !empty($d['ancien_debut'])
                        ? "Votre " . $type . " du " . Message::momentEcrit($d['ancien_debut']) . " est déplacé. Nouveau créneau : " . Message::momentEcrit($d['date_debut']) . " (heure de Paris), pour " . (int) $d['duree'] . " minutes environ."
                        : "Votre " . $type . " du " . Message::momentEcrit($d['date_debut']) . " (heure de Paris) est modifié. Il dure " . (int) $d['duree'] . " minutes environ.";
                    $v['deroulement'] = self::deroulement($d);
                    $v['agenda'] = "L'invitation jointe (fichier .ics) remplace la précédente dans votre agenda. Si l'ancien rendez-vous y reste, retirez-le.";
                    $v['gestion'] = self::gestion($d);
                }
                break;

            case 'rdv_annulation':
                $type = self::rdvType($d);
                $v['annulation'] = !empty($d['par_parent'])
                    ? "C'est noté : votre " . $type . " du " . Message::momentEcrit($d['date_debut']) . " est annulé."
                    : "Votre " . $type . " du " . Message::momentEcrit($d['date_debut']) . " est annulé.";
                $v['reprendre'] = !empty($d['appli'])
                    ? "Pour en reprendre un quand vous le souhaitez : " . $d['appli']
                    : "Pour en reprendre un, répondez simplement à cet e-mail.";
                break;
        }

        return $v;
    }

    // RENDU ##########################################################

    /** Remplace les marques d'un texte. Une marque sans valeur s'efface. */
    private static function remplacer($texte, $valeurs)
    {
        return preg_replace_callback('/\{\{([a-z_]+)\}\}/', function ($m) use ($valeurs) {
            return array_key_exists($m[1], $valeurs) ? (string) $valeurs[$m[1]] : '';
        }, $texte);
    }

    /**
     * Rend un gabarit : array(objet, corps). Les paragraphes (séparés par une ligne vide) qui ne contiennent plus
     * rien disparaissent ; le corps finit par un saut de ligne, comme avant.
     */
    public static function rendre($gabarit, $valeurs)
    {
        $objet = trim(preg_replace('/\s+/u', ' ', self::remplacer($gabarit['objet'], $valeurs)));
        $paragraphes = array();
        foreach (explode("\n\n", str_replace("\r\n", "\n", $gabarit['corps'])) as $p) {
            $r = self::remplacer($p, $valeurs);
            if (trim($r) !== '') {
                $paragraphes[] = $r;
            }
        }

        return array($objet, implode("\n\n", $paragraphes) . "\n");
    }

    /** Version active d'un modèle : array(version, objet, corps) ; version 0 : le gabarit d'origine. */
    public static function actif($code)
    {
        global $Mysql;

        $m = isset($Mysql) ? $Mysql->fetchOne("SELECT version, objet, corps FROM m_modele WHERE code = ? AND active = 1", array($code), 's') : null;
        if ($m === null) {
            return array('version' => 0, 'objet' => self::ORIGINES[$code]['objet'], 'corps' => self::ORIGINES[$code]['corps']);
        }

        return array('version' => (int) $m->version, 'objet' => $m->objet, 'corps' => $m->corps);
    }

    // CONTRÔLE D'UNE VERSION #########################################

    /**
     * Raisons de refuser un objet et un corps pour un modèle ; tableau vide s'ils conviennent.
     * Une marque inconnue, un bloc ou un lien absent, un objet qui porterait un bloc ou la formule d'accueil.
     */
    public static function controler($code, $objet, $corps)
    {
        $raisons = array();
        $marques = self::marques($code);
        if (trim($objet) === '' || mb_strlen($objet) > self::OBJET_MAX || preg_match('/[\r\n]/', $objet)) {
            $raisons[] = "L'objet tient sur une ligne, " . self::OBJET_MAX . " caractères au plus.";
        }
        if (trim($corps) === '' || mb_strlen($corps) > self::CORPS_MAX) {
            $raisons[] = "Le texte est obligatoire, " . self::CORPS_MAX . " caractères au plus.";
        }
        preg_match_all('/\{\{([^}]*)\}\}/', $objet . "\n" . $corps, $m);
        foreach (array_unique($m[1]) as $nom) {
            if (!isset($marques[$nom])) {
                $raisons[] = "La marque {{" . $nom . "}} n'existe pas pour ce modèle.";
            }
        }
        if (preg_match('/\{\{(?![a-z_]+\}\})|(?<!\})\}\}/', preg_replace('/\{\{[a-z_]+\}\}/', '', $objet . $corps))) {
            $raisons[] = "Une marque est mal écrite : elle s'écrit {{nom}}, en minuscules.";
        }
        preg_match_all('/\{\{([a-z_]+)\}\}/', $objet, $mo);
        foreach (array_unique($mo[1]) as $nom) {
            if (isset($marques[$nom]) && $marques[$nom][0] !== 'variable') {
                $raisons[] = "L'objet ne peut porter que des variables : {{" . $nom . "}} va dans le texte.";
            }
        }
        foreach ($marques as $nom => $def) {
            if ($def[0] !== 'variable' && strpos($corps, '{{' . $nom . '}}') === false) {
                $raisons[] = ($def[0] === 'lien' ? "Le lien personnel {{" : "Le bloc {{") . $nom . "}} doit rester dans le texte : " . lcfirst($def[1]) . ".";
            }
        }

        return $raisons;
    }

    // EXEMPLE FICTIF (APERÇU) #######################################

    /** Un parent et des données de fait fictifs, pour l'aperçu : jamais un vrai dossier. */
    public static function exemple($code)
    {
        $contact = (object) array('prenom' => 'Camille', 'email' => 'camille@exemple.fr');
        $appli = 'https://app.navup.fr/';
        $dans = function ($jours, $heure = null) {
            $d = date('Y-m-d', strtotime("+$jours days"));

            return $heure === null ? $d : "$d $heure:00";
        };
        $rdv = array('type' => 'decouverte', 'date_debut' => $dans(3, '14:30'), 'duree' => 30, 'canal' => 'visio', 'visio' => 'https://visio.exemple.fr/navup',
            'gestion' => true, 'heures' => 12, 'appli' => $appli, 'inconnu' => false, 'par_parent' => false);
        $d = array(
            'bienvenue' => array('date_debut' => $dans(0), 'semaines' => 12, 'appli' => $appli, 'jours' => 7,
                'echeances' => array(array('montant' => 9967, 'date_prevue' => $dans(30)), array('montant' => 9966, 'date_prevue' => $dans(60)))),
            'semaine' => array('semaine' => 3, 'sujets' => array('Poser un cadre sans crier', 'Le téléphone à table'), 'appli' => $appli),
            'invitation' => array('jours' => 7, 'appli' => $appli),
            'mot_de_passe' => array('minutes' => 60),
            'lien_paiement' => array('montant' => 9967, 'echeance' => '2e échéance'),
            'prelevement_avis' => array('montant' => 9967, 'echeance' => '2e échéance', 'date_prevue' => $dans(3)),
            'paiement_echoue' => array('montant' => 9967, 'echeance' => '2e échéance'),
            'commande_en_cours' => array(),
            'rdv_confirmation' => $rdv,
            'rdv_modification' => $rdv + array('ancien_debut' => $dans(2, '10:00')),
            'rdv_annulation' => $rdv,
            'rdv_rappel' => $rdv,
            'rdv_deja' => $rdv,
        );

        return array($contact, $d[$code] ?? array());
    }
}

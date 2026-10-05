<?php
// Configuration de l'API NavUp, versionnée et sans secret.
// Les identifiants et réglages propres à la machine sont dans require/secret.php (hors dépôt, modèle : secret.exemple.php).

// Tokens de session (table u_token)
$_TOKEN_TTL_HOURS = 12;        // expiration glissante : prolongée à chaque requête (au plus 1 fois/heure)
$_TOKEN_MAX_DAYS = 30;         // durée de vie absolue d'un token, quelle que soit l'activité
$_TOKEN_MAX_PAR_USER = 5;      // tokens simultanés conservés par utilisateur (les plus anciens sont purgés)

// Verrouillage de compte
$_LOGIN_MAX_TENTATIVES = 5;
$_LOGIN_LOCK_MINUTES = 15;

// Throttle par adresse IP sur le login (toutes tentatives échouées, y compris identifiant inconnu)
$_LOGIN_IP_MAX = 20;
$_LOGIN_IP_WINDOW_MINUTES = 15;

// Politique de mot de passe (le maximum de 72 octets est la limite de bcrypt, fixée dans User)
$_MDP_LONGUEUR_MIN = 12;

// Paiement en ligne (Stripe)
$_STRIPE_PRELEVEMENTS = 1;                 // coupe-circuit : 0 suspend tous les prélèvements automatiques
$_PRELEVEMENT_AVIS_JOURS = 3;              // un prélèvement part au plus tôt ce nombre de jours après son avis par e-mail
$_PRELEVEMENT_HEURES = array(8, 20);       // plage horaire des prélèvements (heure de Paris, fin exclue)
$_LIEN_PAIEMENT_JOURS = 30;                // durée de vie d'un lien de paiement envoyé au parent
$_SESSION_PAIEMENT_MINUTES = 60;           // durée de vie d'une session Stripe Checkout (30 minutes au moins, 24 heures au plus)
$_VENTE_EN_LIGNE_OFFRE = 'programme_navup'; // offre vendue par l'achat en ligne
$_VENTE_EN_LIGNE_FOIS = array(1, 3);       // modalités proposées : comptant, ou en trois mensualités
$_VENTE_EN_LIGNE_OUVERTE = 1;              // 0 : la page publique présente le programme sans vendre (« NavUp arrive bientôt »)

// Textes acceptés par le parent à l'achat : la version est enregistrée avec chaque consentement
$_CGV_VERSION = '2026-10';
$_CONFIDENTIALITE_VERSION = '2026-10';

// Limiteur par adresse IP des endpoints publics : array(nombre maximal, fenêtre en minutes)
$_LIMITE_COMMANDE = array(10, 15);
$_LIMITE_PAIEMENT = array(30, 15);

// Programme
$_PROGRAMME_FIN_PROCHE_JOURS = 7;          // alerte « fin de programme proche »

// Tâches automatiques (alertes). Valeurs par défaut : l'administrateur les règle dans l'outil (package.reglage.php)
$_ALERTES_ACTIVES = array(
    'echeance_retard' => true, 'paiement_echoue' => true, 'rdv_a_planifier' => true, 'rdv_a_confirmer' => true,
    'rdv_compte_rendu' => true, 'appel_a_rappeler' => true, 'programme_fin_proche' => true,
    'prospect_sans_suivi' => true, 'information_manquante' => true, 'acces_fin_proche' => true, 'programme_inactif' => true,
);
$_TACHE_JOURS_AVANT_RDV = 2;               // un rendez-vous à confirmer se rappelle ce nombre de jours avant
$_TACHE_JOURS_APRES_PROGRAMME = 14;        // une fin de programme reste à traiter ce nombre de jours après la fin
$_PROSPECT_SANS_SUIVI_JOURS = 14;          // prospect sans échange, rendez-vous ni note depuis ce nombre de jours
$_ACCES_FIN_PROCHE_JOURS = 7;              // l'accès d'un parent à son espace se ferme dans ce nombre de jours
$_PROGRAMME_INACTIF_JOURS = 21;            // client en programme qui n'a terminé aucun sujet depuis ce nombre de jours

// Appli des parents (navup-parent-api lit la base : ce qui suit s'écrit dans a_compte et a_jeton, elle n'a aucun réglage à recopier)
$_ACCES_APRES_FIN_JOURS = 30;              // les contenus restent consultables ce nombre de jours après la fin du programme
$_LIEN_ACCES_CREATION_JOURS = 7;           // lien d'invitation : création du mot de passe
$_LIEN_ACCES_REINIT_MINUTES = 60;          // lien « mot de passe oublié »
$_LIMITE_ACCES = array(10, 15);            // demandes de lien depuis une adresse IP : array(nombre maximal, fenêtre en minutes)
$_ACCES_MAX_PAR_JOUR = 3;                  // liens envoyés à un même compte à sa demande, par 24 heures

// Rendez-vous pris en ligne (page publique : découverte ; espace personnel : accompagnement). Heure de Paris.
$_RDV_PRISE_OUVERTE = 1;                   // 0 : aucun créneau n'est proposé, les pages renvoient à l'adresse de contact
$_RDV_PRISE = array(                       // par type : durée et pas en minutes, délai avant le premier créneau, horizon, rendez-vous à venir admis
    'decouverte' => array('duree' => 30, 'canaux' => array('visio', 'telephone'), 'pas' => 30, 'delai_heures' => 24, 'horizon_jours' => 30, 'max_a_venir' => 1),
    'suivi' => array('duree' => 45, 'canaux' => array('visio', 'telephone'), 'pas' => 30, 'delai_heures' => 24, 'horizon_jours' => 30, 'max_a_venir' => 1),
);
$_RDV_PRISE_PLAFOND = 20;                  // réservations en ligne par 24 heures ; au-delà la prise se ferme jusqu'au lendemain et l'alerte part
$_RDV_MODIFIABLE_HEURES = 12;              // le parent annule ou déplace en ligne jusqu'à ce délai avant le rendez-vous
$_RDV_DEPLACEMENTS_MAX = 3;                // déplacements en ligne d'un même rendez-vous
$_RDV_RAPPEL_HEURES = 24;                  // rappel par e-mail avant un rendez-vous confirmé
$_RDV_RAPPEL_PRIS_AVANT_HEURES = 36;       // pas de rappel pour un rendez-vous pris moins de ce délai avant son heure
$_LIMITE_RDV = array(8, 60);               // réservations et gestes depuis une adresse IP : array(nombre maximal, fenêtre en minutes)
$_LIMITE_CRENEAUX = array(120, 15);        // lectures des créneaux depuis une adresse IP
$_BILLET_MINUTES = 10;                     // durée d'un billet délivré par l'API des parents (rendez-vous de l'espace personnel)

// RGPD (étape 8) : durées de conservation, réglables dans l'outil (package.reglage.php). L'outil propose, un administrateur
// confirme chaque effacement (page RGPD) ; seuls le journal d'audit et les e-mails envoyés se purgent seuls (passe rgpd).
$_RGPD_PROSPECT_ANS = 3;                   // prospect sans vente : effacement complet ce nombre d'années après le dernier fait
$_RGPD_CLIENT_ANS = 3;                     // client : données familiales effacées ce nombre d'années après la fin de l'accès
$_RGPD_COMPTABLE_ANS = 10;                 // client : identité effacée ce nombre d'années après la dernière écriture comptable
$_RGPD_JOURNAL_MOIS = 12;                  // journal d'audit et e-mails envoyés : effacés au-delà de ce nombre de mois
$_DONNEES_BILLET_MINUTES = 5;              // billet de l'appli des parents pour « Télécharger mes données »
$_DOSSIER_SAUVEGARDES = '';                // dossier des sauvegardes de la nuit (script-cgi/sauvegarder.php) : à poser dans require/secret.php

// E-mails aux parents
$_MAIL_ESSAIS_MAX = 5;                    // au-delà, un envoi en erreur attend une relance manuelle

// Médias de la formation
$_MEDIA_MORCEAU_MAX = 4194304;             // octets par morceau téléversé (4 Mo : sous la limite PHP par défaut)
$_MEDIA_TAILLES_MAX = array('audio' => 314572800, 'fiche' => 31457280, 'annexe' => 31457280); // 300 Mo, 30 Mo, 30 Mo
$_FICHE_DPI = 180;                         // rendu des pages d'une fiche en images, pour l'appli des parents (A4 : 1489 px de large)
$_FICHE_PAGES_MAX = 12;                    // au-delà, la fiche n'est pas rendue en images : elle ne s'ouvre qu'en PDF

if (!is_file(__DIR__ . '/secret.php')) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Configuration manquante : copier require/secret.exemple.php en require/secret.php\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo ")]}',\n" . json_encode(array('success' => false, 'message' => "Une erreur interne s'est produite. Veuillez réessayer plus tard."));
    exit();
}

require __DIR__ . '/secret.php';

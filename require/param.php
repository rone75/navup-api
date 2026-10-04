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

// Textes acceptés par le parent à l'achat : la version est enregistrée avec chaque consentement
$_CGV_VERSION = '2026-10';
$_CONFIDENTIALITE_VERSION = '2026-10';

// Limiteur par adresse IP des endpoints publics : array(nombre maximal, fenêtre en minutes)
$_LIMITE_COMMANDE = array(10, 15);
$_LIMITE_PAIEMENT = array(30, 15);

// Programme
$_PROGRAMME_FIN_PROCHE_JOURS = 7;          // alerte « fin de programme proche »

// E-mails aux parents
$_MAIL_ESSAIS_MAX = 5;                     // au-delà, un envoi en erreur attend une relance manuelle

// Médias de la formation
$_MEDIA_MORCEAU_MAX = 4194304;             // octets par morceau téléversé (4 Mo : sous la limite PHP par défaut)
$_MEDIA_TAILLES_MAX = array('audio' => 314572800, 'fiche' => 31457280, 'annexe' => 31457280); // 300 Mo, 30 Mo, 30 Mo

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

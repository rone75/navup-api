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

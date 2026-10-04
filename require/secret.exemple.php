<?php
// Modèle de require/secret.php : secrets et réglages propres à la machine.
// Copier ce fichier en secret.php (ignoré par git), renseigner les valeurs, puis en production :
//   chmod 640 require/secret.php && chgrp apache require/secret.php

$_PROD = 0;                                   // 1 en production
$_PATH_API = "http://localhost/navup-api/";

// Base de données : utilisateur MariaDB dédié, limité à la base navup (jamais root)
$_DB = array(
    'hote' => 'localhost',
    'utilisateur' => 'navup',
    'mot_de_passe' => '',
    'base' => 'navup',
);

// Origines autorisées par CORS en production : hôtes exacts du front, servis en https (ex. 'tour.navup.fr').
// En développement ($_PROD = 0), les origines localhost sont acceptées d'office.
$_CORS_ORIGINES = array();

// Mails d'erreur SQL : destinataire et expéditeur
$_MAIL_ERREUR = "";
$_MAIL_EXPEDITEUR = "noreply@navup.fr";

// Stripe : clé secrète (sk_test_… en développement, sk_live_… en production) et secret de signature du webhook (whsec_…).
// L'API refuse une clé réelle en développement et une clé de test en production.
// En développement, sans webhook joignable : un secret local quelconque, par exemple
//   php -r 'echo "whsec_" . bin2hex(random_bytes(24)) . "\n";'
$_STRIPE_CLE_SECRETE = "";
$_STRIPE_SECRET_WEBHOOK = "";

// E-mails aux parents : 'essai' (rien ne sort du serveur, le message est gardé comme envoyé en essai) ou 'reel'
$_MAIL_MODE = "essai";
$_MAIL_EXPEDITEUR_PARENTS = "contact@navup.fr";

// Origines autorisées par CORS pour les pages publiques (site, appli des parents) : hôtes exacts, servis en https
$_CORS_ORIGINES_PUBLIQUES = array();

// Adresses : la Tour de contrôle ; la page qui accueille le parent au retour de Stripe (vide : page minimale servie par l'API) ;
// l'appli des parents (vide : les e-mails « nouvelle semaine » restent éteints)
$_URL_TOUR = "http://localhost:4200/";
$_URL_RETOUR_PAIEMENT = "";
$_APP_PARENTS_URL = "";

// Médias de la formation : dossier hors du web, accessible en écriture à php-fpm et à l'utilisateur de la tâche planifiée
//   sudo install -d -o <utilisateur> -g apache -m 2770 /var/www/navup-media
// ffmpeg et ffprobe convertissent un audio lourd en MP3 d'écoute (vide : pas de conversion)
$_DOSSIER_MEDIAS = "/var/www/navup-media";
$_FFMPEG = "/usr/bin/ffmpeg";
$_FFPROBE = "/usr/bin/ffprobe";
// pdftoppm et pdfinfo (paquet poppler-utils) rendent les pages d'une fiche PDF en images, pour l'appli des parents
// (vide : pas de rendu, la fiche ne s'y ouvre qu'en PDF)
$_PDFTOPPM = "/usr/bin/pdftoppm";
$_PDFINFO = "/usr/bin/pdfinfo";

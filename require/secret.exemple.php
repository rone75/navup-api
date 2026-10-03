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

#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/seed-admin.php
// Description: crée (ou réinitialise avec --force) le premier compte Admin.
// Usage:       php script-cgi/seed-admin.php --email=<email> --identifiant=<login> \
//                  [--password=<mdp>] [--nom=<nom>] [--prenom=<prenom>] [--force]
//              Sans --password : un mot de passe conforme (16 caractères) est généré et affiché.
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.data.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');

$opts = getopt('', array('email:', 'identifiant:', 'password::', 'nom::', 'prenom::', 'force', 'help'));

if (isset($opts['help']) || !isset($opts['email']) || !isset($opts['identifiant'])) {
    fwrite(STDERR, "Usage : php script-cgi/seed-admin.php --email=<email> --identifiant=<login> [--password=<mdp>] [--nom=<nom>] [--prenom=<prenom>] [--force]\n");
    exit(1);
}

$email = strtolower(trim($opts['email']));
$identifiant = trim($opts['identifiant']);
$nom = isset($opts['nom']) ? trim($opts['nom']) : 'Admin';
$prenom = isset($opts['prenom']) ? trim($opts['prenom']) : '';
$force = isset($opts['force']);

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Email invalide : $email\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $identifiant)) {
    fwrite(STDERR, "Identifiant invalide (3 à 50 caractères : lettres, chiffres, . _ -)\n");
    exit(1);
}

$U = new User();

if (isset($opts['password']) && $opts['password'] !== false && $opts['password'] !== '') {
    $password = $opts['password'];
    $erreur = $U->checkPasswordPolicy($password);
    if ($erreur !== null) {
        fwrite(STDERR, "$erreur\n");
        exit(1);
    }
    $genere = false;
} else {
    $password = $U->genererMotDePasse(16);
    $genere = true;
}

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$hash = password_hash($password, PASSWORD_BCRYPT);

$existant = $Mysql->fetchOne(
    "SELECT id_users, identifiant, email FROM u_users WHERE email = ? OR identifiant = ? LIMIT 1",
    array($email, $identifiant),
    'ss'
);

if ($existant !== null && !$force) {
    fwrite(STDERR, "Un compte existe déjà (id {$existant->id_users}, identifiant {$existant->identifiant}, email {$existant->email}). Utilisez --force pour réinitialiser son mot de passe.\n");
    exit(1);
}

if ($existant !== null) {
    $id_users = (int) $existant->id_users;
    $Mysql->execute(
        "UPDATE u_users SET mdp = ?, profil = 'admin', actif = 1, tentatives_echec = 0, date_verrouillage = NULL, date_modif = NOW() WHERE id_users = ?",
        array($hash, $id_users),
        'si'
    );
    $Mysql->execute("DELETE FROM u_token WHERE id_users = ?", array($id_users), 'i');
    $U->audit($id_users, 'seed_admin', array('mode' => 'reset'), 'user', $id_users);
    echo "Compte admin réinitialisé (id $id_users).\n";
} else {
    $Mysql->execute(
        "INSERT INTO u_users (identifiant, email, mdp, nom, prenom, profil, actif) VALUES (?, ?, ?, ?, ?, 'admin', 1)",
        array($identifiant, $email, $hash, $nom, $prenom),
        'sssss'
    );
    $id_users = $Mysql->lastId();
    $U->audit($id_users, 'seed_admin', array('mode' => 'create'), 'user', $id_users);
    echo "Compte admin créé (id $id_users).\n";
}

echo "  identifiant : $identifiant\n";
echo "  email       : $email\n";
echo "  mot de passe" . ($genere ? " (généré)" : "") . " : $password\n";

exit(0);

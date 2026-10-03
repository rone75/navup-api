<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// IDENTIFICATION ###############################
// POST {ident, pass} : ident = identifiant ou email ; pass = sel(18) + base64(mdp) + sel(9)

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    // Throttle par IP (429) avant toute lecture des identifiants
    $U->checkIpThrottle();

    $postdata = file_get_contents("php://input");
    $R = json_decode($postdata);

    if (isset($R->ident) && is_string($R->ident) && trim($R->ident) !== '') {
        $ident = substr($CD->clean_text($R->ident, array("API")), 0, 255);
    } else {
        $Response->validationError("Erreur paramètre IDENT manquant");
    }

    if (isset($R->pass) && is_string($R->pass) && $R->pass !== '') {
        $pass = $U->decodePassword($R->pass);
    } else {
        $Response->validationError("Erreur paramètre PASS manquant");
    }

    // Message unique quel que soit le motif (identifiant inconnu ou mot de passe faux)
    $msg_ko = "Identifiant ou mot de passe incorrect.";

    // Journal : une saisie qui a la forme d'un mot de passe valide (tapé par erreur dans le champ identifiant) n'est pas conservée
    $ident_journal = $U->checkPasswordPolicy($ident) === null ? '[masqué]' : mb_substr($ident, 0, 100);

    if ($pass === null) {
        $U->noteIpFailure();
        $U->audit(null, 'login_ko', array('ident' => $ident_journal, 'motif' => 'pass_illisible'));
        $Response->authError($msg_ko);
    }

    $C = $Mysql->fetchOne(
        "SELECT *, TIMESTAMPDIFF(SECOND, NOW(), date_verrouillage) AS verrou_reste
         FROM u_users WHERE identifiant = ? OR LOWER(email) = LOWER(?) LIMIT 1",
        array($ident, $ident),
        'ss'
    );

    if ($C === null) {
        // Temps de réponse constant : vérification d'un hash factice
        password_verify($pass, User::DUMMY_HASH);
        $U->noteIpFailure();
        $U->audit(null, 'login_ko', array('ident' => $ident_journal, 'motif' => 'inconnu'));
        $Response->authError($msg_ko);
    }

    $id_users = (int) $C->id_users;

    if ((int) $C->actif !== 1) {
        $U->noteIpFailure();
        $U->audit($id_users, 'login_ko', array('motif' => 'inactif'));
        $Response->forbidden("Compte désactivé. Contactez un administrateur.");
    }

    // Verrouillage après $_LOGIN_MAX_TENTATIVES échecs
    $verrou_reste = ($C->date_verrouillage !== null && $C->verrou_reste !== null) ? (int) $C->verrou_reste : 0;

    if ($verrou_reste > 0) {
        $minutes = (int) ceil($verrou_reste / 60);
        $U->noteIpFailure();
        $U->audit($id_users, 'login_ko', array('motif' => 'verrouille', 'reste_s' => $verrou_reste));
        $Response->forbidden("Compte verrouillé. Réessayez dans $minutes min.");
    }

    if ($C->date_verrouillage !== null) {
        // Verrou expiré : le compteur repart de zéro
        $Mysql->execute("UPDATE u_users SET tentatives_echec = 0, date_verrouillage = NULL WHERE id_users = ?", array($id_users), 'i');
        $C->tentatives_echec = 0;
    }

    if (!password_verify($pass, $C->mdp)) {

        $U->noteIpFailure();
        $tentatives = (int) $C->tentatives_echec + 1;

        if ($tentatives >= (int) $_LOGIN_MAX_TENTATIVES) {
            $Mysql->execute(
                "UPDATE u_users SET tentatives_echec = ?, date_verrouillage = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id_users = ?",
                array($tentatives, (int) $_LOGIN_LOCK_MINUTES, $id_users),
                'iii'
            );
            $U->audit($id_users, 'lock', array('tentatives' => $tentatives, 'minutes' => (int) $_LOGIN_LOCK_MINUTES));
            $Response->forbidden("Compte verrouillé pendant " . (int) $_LOGIN_LOCK_MINUTES . " min suite à $tentatives tentatives échouées.");
        }

        $Mysql->execute("UPDATE u_users SET tentatives_echec = ? WHERE id_users = ?", array($tentatives, $id_users), 'ii');
        $U->audit($id_users, 'login_ko', array('motif' => 'mdp', 'tentatives' => $tentatives));
        $Response->authError($msg_ko);
    }

    // LOGIN OK #######################################

    if (password_needs_rehash($C->mdp, PASSWORD_BCRYPT)) {
        $Mysql->execute("UPDATE u_users SET mdp = ? WHERE id_users = ?", array(password_hash($pass, PASSWORD_BCRYPT), $id_users), 'si');
    }

    $Mysql->execute(
        "UPDATE u_users SET tentatives_echec = 0, date_verrouillage = NULL, date_derniere_connexion = NOW() WHERE id_users = ?",
        array($id_users),
        'i'
    );

    $token = $U->creerToken($id_users);

    $U->clearIpFailures();
    $U->audit($id_users, 'login_ok');

    $C = $Mysql->fetchOne("SELECT * FROM u_users WHERE id_users = ?", array($id_users), 'i');

    $Response->success(array('token' => $token, 'user' => $U->publicUser($C)));
}

// Renvoi les informations de l'utilisateur connecté ################################

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireUser();

    $Response->success(array('user' => $U->publicUser($user)));
}

// Déconnexion : invalide le token courant ################################

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $U->requireUser();

    $Mysql->execute("DELETE FROM u_token WHERE token = ?", array($user->token), 's');
    $U->audit((int) $user->id_users, 'logout');

    $Response->success();
}

$Response->methodNotAllowed();

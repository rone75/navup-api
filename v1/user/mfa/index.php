<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.totp.php";
include "../../../require/param.php";

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

// Double authentification de l'utilisateur connecté (« Mon compte ») ################################
// Facultative (décision d'Erwan, étape 8). Les erreurs de code ou de mot de passe répondent 400 : elles ne déconnectent pas.

$user = $U->requireUser();
$id_users = (int) $user->id_users;

if (!Totp::disponible()) {
    $Response->serviceUnavailable("La double authentification n’est pas configurée sur ce serveur (clé manquante).");
}

function etat($id_users)
{
    global $Mysql;
    $u = $Mysql->fetchOne("SELECT totp_actif, totp_date FROM u_users WHERE id_users = ?", array($id_users), 'i');
    $actif = (int) $u->totp_actif === 1;
    return array(
        'actif' => $actif,
        'date' => $actif ? $u->totp_date : null,
        'secours_restants' => $actif ? Totp::secoursRestants($id_users) : 0,
    );
}

/** Mot de passe actuel (obfusqué comme au login) et code, exigés pour désactiver ou renouveler les codes de secours. */
function exigerPreuves($R, $user)
{
    global $U, $Response, $id_users;
    $pass = (isset($R->pass) && is_string($R->pass) && $R->pass !== '') ? $U->decodePassword($R->pass) : null;
    if ($pass === null || !password_verify($pass, $user->mdp)) {
        $U->audit($id_users, 'mfa_preuve_ko', array('motif' => 'mdp'), 'user', $id_users);
        $Response->validationError("Mot de passe incorrect.");
    }
    $code = (isset($R->code) && is_string($R->code)) ? mb_substr($R->code, 0, 20) : '';
    if ($code === '' || Totp::controler($user, $code) === null) {
        $U->audit($id_users, 'mfa_preuve_ko', array('motif' => 'code'), 'user', $id_users);
        $Response->validationError("Code incorrect.");
    }
}

// GET : état ################################
if ($_SERVER['REQUEST_METHOD'] === "GET") {
    $Response->success(etat($id_users));
}

// POST {action} ################################
//   commencer           → {secret, adresse} : un nouveau secret, pas encore actif
//   confirmer {code}    → {secours: [...]} : le code prouve que l'application est réglée ; codes de secours montrés une fois
//   secours {pass,code} → {secours: [...]} : dix nouveaux codes, les anciens ne valent plus
//   desactiver {pass,code}
if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $action = (isset($R->action) && is_string($R->action)) ? $R->action : '';
    $actif = (int) $user->totp_actif === 1;

    if ($action === 'commencer') {
        if ($actif) {
            $Response->validationError("La double authentification est déjà active.");
        }
        $cle = Totp::nouveauSecret();
        $Mysql->execute(
            "UPDATE u_users SET totp_secret = ?, totp_actif = 0, totp_date = NULL, totp_dernier_pas = NULL WHERE id_users = ?",
            array(Totp::chiffrer($cle), $id_users),
            'si'
        );
        $Response->success(array(
            'secret' => Totp::base32($cle),
            'adresse' => Totp::adresse($user->identifiant, $cle),
        ));
    }

    if ($action === 'confirmer') {
        if ($actif) {
            $Response->validationError("La double authentification est déjà active.");
        }
        $cle = $user->totp_secret !== null ? Totp::dechiffrer($user->totp_secret) : null;
        if ($cle === null) {
            $Response->validationError("Recommencez : la clé n’a pas été créée ou n’est plus lisible.");
        }
        $code = (isset($R->code) && is_string($R->code)) ? $R->code : '';
        $p = Totp::verifier($cle, $code);
        if ($p === null) {
            $Response->validationError("Code incorrect. Vérifiez l’heure de votre téléphone, puis entrez le code affiché maintenant.");
        }
        $Mysql->execute(
            "UPDATE u_users SET totp_actif = 1, totp_date = NOW(), totp_dernier_pas = ? WHERE id_users = ? AND totp_actif = 0",
            array($p, $id_users),
            'ii'
        );
        $secours = Totp::nouveauxSecours($id_users);
        $U->audit($id_users, 'mfa_activation', null, 'user', $id_users);
        $Response->success(array_merge(etat($id_users), array('secours' => $secours)));
    }

    if ($action === 'secours' || $action === 'desactiver') {
        if (!$actif) {
            $Response->validationError("La double authentification n’est pas active.");
        }
        exigerPreuves($R, $user);

        if ($action === 'secours') {
            $secours = Totp::nouveauxSecours($id_users);
            $U->audit($id_users, 'mfa_secours', null, 'user', $id_users);
            $Response->success(array_merge(etat($id_users), array('secours' => $secours)));
        }

        $Mysql->execute(
            "UPDATE u_users SET totp_secret = NULL, totp_actif = 0, totp_date = NULL, totp_dernier_pas = NULL WHERE id_users = ?",
            array($id_users),
            'i'
        );
        $Mysql->execute("DELETE FROM u_secours WHERE id_users = ?", array($id_users), 'i');
        $U->audit($id_users, 'mfa_desactivation', null, 'user', $id_users);
        $Response->success(etat($id_users));
    }

    $Response->validationError("Action inconnue.");
}

$Response->methodNotAllowed();

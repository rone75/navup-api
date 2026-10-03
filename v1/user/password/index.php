<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
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

// Changement de son propre mot de passe ################################
// PUT {old, new} : les deux obfusqués comme au login (sel18 + base64 + sel9)

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireUser();

    $R = json_decode(file_get_contents("php://input"));

    if (isset($R->old) && is_string($R->old) && $R->old !== '') {
        $old = $U->decodePassword($R->old);
    } else {
        $Response->validationError("Erreur paramètre OLD manquant");
    }

    if (isset($R->new) && is_string($R->new) && $R->new !== '') {
        $new = $U->decodePassword($R->new);
    } else {
        $Response->validationError("Erreur paramètre NEW manquant");
    }

    if ($old === null || $new === null) {
        $Response->validationError("Mot de passe illisible.");
    }

    // 400 (et non 401) : une erreur sur le mot de passe actuel ne doit pas déconnecter l'utilisateur
    if (!password_verify($old, $user->mdp)) {
        $U->audit((int) $user->id_users, 'password_change_ko', null, 'user', (int) $user->id_users);
        $Response->validationError("Mot de passe actuel incorrect.");
    }

    $erreur = $U->checkPasswordPolicy($new);
    if ($erreur !== null) {
        $Response->validationError($erreur);
    }

    if ($old === $new) {
        $Response->validationError("Le nouveau mot de passe doit être différent de l'actuel.");
    }

    $id_users = (int) $user->id_users;

    $Mysql->execute(
        "UPDATE u_users SET mdp = ?, date_modif = NOW() WHERE id_users = ?",
        array(password_hash($new, PASSWORD_BCRYPT), $id_users),
        'si'
    );

    // Les autres sessions sont fermées ; la session courante reste valide
    $Mysql->execute("DELETE FROM u_token WHERE id_users = ? AND token <> ?", array($id_users, $user->token), 'is');

    $U->audit($id_users, 'password_change', null, 'user', $id_users);

    $Response->success();
}

$Response->methodNotAllowed();

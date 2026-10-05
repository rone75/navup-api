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

$admin = $U->requireAccess('utilisateurs', 'C');
$id_admin = (int) $admin->id_users;

// Retrait de la double authentification d'un utilisateur (téléphone perdu, sans code de secours) ################################
// PUT {id_users} : secret et codes de secours effacés, sessions fermées ; l'utilisateur se connecte ensuite
// avec son seul mot de passe et peut la réactiver. Inscrit au journal.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));

    $id = (isset($R->id_users) && is_numeric($R->id_users)) ? (int) $R->id_users : 0;
    $cible = $id > 0 ? $U->findAdminUser($id) : null;
    if ($cible === null) {
        $Response->notFound("Utilisateur introuvable.");
    }
    if ($id === $id_admin) {
        $Response->validationError("Votre propre double authentification se retire depuis « Mon compte ».");
    }
    if ((int) $cible->totp_actif !== 1) {
        $Response->validationError("Cet utilisateur n’a pas de double authentification.");
    }

    $Mysql->execute(
        "UPDATE u_users SET totp_secret = NULL, totp_actif = 0, totp_date = NULL, totp_dernier_pas = NULL, date_modif = NOW() WHERE id_users = ?",
        array($id),
        'i'
    );
    $Mysql->execute("DELETE FROM u_secours WHERE id_users = ?", array($id), 'i');
    $Mysql->execute("DELETE FROM u_defi WHERE id_users = ?", array($id), 'i');
    $Mysql->execute("DELETE FROM u_token WHERE id_users = ?", array($id), 'i');

    $U->audit($id_admin, 'mfa_retrait', array('identifiant' => $cible->identifiant), 'user', $id);

    $Response->success(array('user' => $U->adminUser($U->findAdminUser($id))));
}

$Response->methodNotAllowed();

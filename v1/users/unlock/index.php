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

// Déverrouillage anticipé d'un compte (avant la fin des 15 min) ################################
// PUT {id_users}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));

    $id = (isset($R->id_users) && is_numeric($R->id_users)) ? (int) $R->id_users : 0;
    $cible = $id > 0 ? $U->findAdminUser($id) : null;
    if ($cible === null) {
        $Response->notFound("Utilisateur introuvable.");
    }

    $Mysql->execute("UPDATE u_users SET tentatives_echec = 0, date_verrouillage = NULL WHERE id_users = ?", array($id), 'i');

    $U->audit($id_admin, 'user_unlock', array('identifiant' => $cible->identifiant), 'user', $id);

    $Response->success(array('user' => $U->adminUser($U->findAdminUser($id))));
}

$Response->methodNotAllowed();

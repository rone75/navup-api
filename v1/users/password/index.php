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

// Réinitialisation du mot de passe par un administrateur ################################
// PUT {id_users, password?} - password obfusqué ; généré et renvoyé une seule fois si absent

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));

    $id = (isset($R->id_users) && is_numeric($R->id_users)) ? (int) $R->id_users : 0;
    $cible = $id > 0 ? $U->findAdminUser($id) : null;
    if ($cible === null) {
        $Response->notFound("Utilisateur introuvable.");
    }

    $password = $U->motDePasseFourni($R);
    $genere = false;
    if ($password === null) {
        $password = $U->genererMotDePasse(14);
        $genere = true;
    }

    $Mysql->execute(
        "UPDATE u_users SET mdp = ?, tentatives_echec = 0, date_verrouillage = NULL, date_modif = NOW() WHERE id_users = ?",
        array(password_hash($password, PASSWORD_BCRYPT), $id),
        'si'
    );

    // Sessions de la cible fermées (sauf la session courante si l'admin se réinitialise lui-même)
    if ($id === $id_admin) {
        $Mysql->execute("DELETE FROM u_token WHERE id_users = ? AND token <> ?", array($id, $admin->token), 'is');
    } else {
        $Mysql->execute("DELETE FROM u_token WHERE id_users = ?", array($id), 'i');
    }

    $U->audit($id_admin, 'password_reset', array('identifiant' => $cible->identifiant, 'mdp_genere' => $genere), 'user', $id);

    $Response->success(array(
        'user' => $U->adminUser($U->findAdminUser($id)),
        'password_genere' => $genere ? $password : null,
    ));
}

$Response->methodNotAllowed();

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

// Activation / désactivation (sans suppression) ################################
// PUT {id_users, actif: 0|1}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));

    $id = (isset($R->id_users) && is_numeric($R->id_users)) ? (int) $R->id_users : 0;
    if (!isset($R->actif) || !in_array((string) $R->actif, array('0', '1'), true)) {
        $Response->validationError("Erreur paramètre ACTIF manquant (0 ou 1)");
    }
    $actif = (int) $R->actif;

    $cible = $id > 0 ? $U->findAdminUser($id) : null;
    if ($cible === null) {
        $Response->notFound("Utilisateur introuvable.");
    }

    if ($id === $id_admin) {
        $Response->validationError("Vous ne pouvez pas modifier l'état de votre propre compte.");
    }

    if ($actif === 0 && $cible->profil === 'admin' && (int) $cible->actif === 1 && $U->compterAdminsActifs() <= 1) {
        $Response->validationError("Il doit rester au moins un administrateur actif.");
    }

    if ((int) $cible->actif !== $actif) {
        $Mysql->execute("UPDATE u_users SET actif = ?, date_modif = NOW() WHERE id_users = ?", array($actif, $id), 'ii');

        if ($actif === 0) {
            // Un compte désactivé perd immédiatement toutes ses sessions
            $Mysql->execute("DELETE FROM u_token WHERE id_users = ?", array($id), 'i');
        }

        $U->audit($id_admin, $actif === 1 ? 'user_enable' : 'user_disable', array('identifiant' => $cible->identifiant), 'user', $id);
    }

    $Response->success(array('user' => $U->adminUser($U->findAdminUser($id))));
}

$Response->methodNotAllowed();

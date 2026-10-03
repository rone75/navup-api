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

// Gestion des utilisateurs internes : réservée au profil admin (CDC §21, §22)
$admin = $U->requireAccess('utilisateurs', 'C');
$id_admin = (int) $admin->id_users;

// Liste / fiche ################################
// GET ?id=N | GET ?profil=&actif=&q=

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $row = $id > 0 ? $U->findAdminUser($id) : null;
        if ($row === null) {
            $Response->notFound("Utilisateur introuvable.");
        }
        $Response->success(array('user' => $U->adminUser($row)));
    }

    $where = array();
    $params = array();
    $types = '';

    if (isset($_GET['profil']) && in_array($_GET['profil'], User::PROFILS, true)) {
        $where[] = "u.profil = ?";
        $params[] = $_GET['profil'];
        $types .= 's';
    }
    if (isset($_GET['actif']) && ($_GET['actif'] === '0' || $_GET['actif'] === '1')) {
        $where[] = "u.actif = ?";
        $params[] = (int) $_GET['actif'];
        $types .= 'i';
    }
    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $q = '%' . mb_substr($CD->clean_text($_GET['q'], array('API')), 0, 100) . '%';
        $where[] = "(u.identifiant LIKE ? OR u.email LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
        array_push($params, $q, $q, $q, $q);
        $types .= 'ssss';
    }

    $sql = User::SQL_ADMIN_USER
        . (count($where) > 0 ? " WHERE " . implode(" AND ", $where) : "")
        . " ORDER BY u.nom, u.prenom, u.identifiant";

    $rows = $Mysql->fetchAll($sql, $params, $types);

    $users = array();
    foreach ($rows as $row) {
        $users[] = $U->adminUser($row);
    }

    $Response->success(array('users' => $users, 'total' => count($users)));
}

// Création ################################
// POST {identifiant, email, nom, prenom, profil, password?} - password obfusqué ; généré si absent

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));

    $data = $U->validerChamps($R, false);
    $U->verifierUnicite($data);

    $password = $U->motDePasseFourni($R);
    $genere = false;
    if ($password === null) {
        $password = $U->genererMotDePasse(14);
        $genere = true;
    }

    $Mysql->execute(
        "INSERT INTO u_users (identifiant, email, mdp, nom, prenom, profil, actif, id_users_createur)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
        array($data['identifiant'], $data['email'], password_hash($password, PASSWORD_BCRYPT), $data['nom'], $data['prenom'], $data['profil'], $id_admin),
        'ssssssi'
    );
    $id = $Mysql->lastId();

    $U->audit($id_admin, 'user_create', array('identifiant' => $data['identifiant'], 'profil' => $data['profil'], 'mdp_genere' => $genere), 'user', $id);

    $Response->success(array(
        'user' => $U->adminUser($U->findAdminUser($id)),
        'password_genere' => $genere ? $password : null,
    ), 201);
}

// Modification ################################
// PUT {id_users, identifiant?, email?, nom?, prenom?, profil?}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));

    $id = (isset($R->id_users) && is_numeric($R->id_users)) ? (int) $R->id_users : 0;
    $cible = $id > 0 ? $U->findAdminUser($id) : null;
    if ($cible === null) {
        $Response->notFound("Utilisateur introuvable.");
    }

    $data = $U->validerChamps($R, true);
    if (count($data) === 0) {
        $Response->validationError("Aucune modification transmise.");
    }
    $U->verifierUnicite($data, $id);

    // Toujours au moins un administrateur actif
    if (isset($data['profil']) && $data['profil'] !== 'admin'
        && $cible->profil === 'admin' && (int) $cible->actif === 1
        && $U->compterAdminsActifs() <= 1) {
        $Response->validationError("Il doit rester au moins un administrateur actif.");
    }

    $set = array();
    $params = array();
    $types = '';
    $modifies = array();
    foreach ($data as $champ => $valeur) {
        if ((string) $cible->$champ === (string) $valeur) {
            continue;
        }
        $set[] = "$champ = ?";
        $params[] = $valeur;
        $types .= 's';
        $modifies[$champ] = array('avant' => $cible->$champ, 'apres' => $valeur);
    }

    if (count($set) > 0) {
        $params[] = $id;
        $types .= 'i';
        $Mysql->execute("UPDATE u_users SET " . implode(", ", $set) . ", date_modif = NOW() WHERE id_users = ?", $params, $types);
        $U->audit($id_admin, 'user_update', array('identifiant' => $cible->identifiant, 'modifications' => $modifies), 'user', $id);
    }

    $Response->success(array('user' => $U->adminUser($U->findAdminUser($id))));
}

$Response->methodNotAllowed();

<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.vue.php";
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

// Vues enregistrées (CDC §16 ; étape 7b) ################################
// Propres à l'utilisateur connecté ; personne d'autre ne les voit. Il lui faut le droit de lire la liste.
// GET ?liste=prospects|clients|ventes|paiements|taches → {vues: [{id_vue, nom, reglages, ordre}]}
// POST {liste, nom, reglages} → 201 ; les réglages passent une liste blanche (package.vue.php) : jamais de recherche.
// PUT {id_vue, nom?, ordre?, reglages?} ; DELETE ?id=

/** Liste demandée, et le droit de la lire ; 400 / 403 sinon. */
function listeDemandee($user, $liste)
{
    global $U, $Response;

    $listes = Vue::listes();
    if (!is_string($liste) || !isset($listes[$liste])) {
        $Response->validationError("Liste inconnue.");
    }
    if (!$U->can($user, $listes[$liste]['module'], 'L')) {
        $Response->forbidden("Vous n'avez pas accès à cette liste.");
    }

    return $liste;
}

/** Vue de l'utilisateur connecté, ou 404 (celle d'un autre n'existe pas pour lui). */
function vueDe($user, $id)
{
    global $Mysql, $Response;

    $v = (is_int($id) && $id > 0) ? $Mysql->fetchOne("SELECT * FROM u_vue WHERE id_vue = ? AND id_users = ?", array($id, (int) $user->id_users), 'ii') : null;
    if ($v === null) {
        $Response->notFound("Vue introuvable.");
    }

    return $v;
}

function nom($R)
{
    global $Response;

    $nom = (isset($R['nom']) && is_string($R['nom'])) ? trim(preg_replace('/\s+/u', ' ', $R['nom'])) : '';
    if ($nom === '' || mb_strlen($nom) > 60) {
        $Response->validationError("Donnez un nom à la vue (60 caractères au plus).");
    }

    return $nom;
}

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireUser();
    $liste = listeDemandee($user, $_GET['liste'] ?? null);
    $Response->success(array('vues' => Vue::de($user->id_users, $liste)));
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireUser();
    $R = json_decode(file_get_contents("php://input"), true);
    $liste = listeDemandee($user, is_array($R) ? ($R['liste'] ?? null) : null);
    $nom = nom($R);
    list($reglages, $raison) = Vue::valider($liste, $R['reglages'] ?? null, $user);
    if ($raison !== null) {
        $Response->validationError($raison);
    }
    $nb = (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM u_vue WHERE id_users = ? AND liste = ?", array((int) $user->id_users, $liste), 'is')->n;
    if ($nb >= Vue::MAX_PAR_LISTE) {
        $Response->validationError("Vous avez déjà " . Vue::MAX_PAR_LISTE . " vues pour cette liste : retirez-en une avant d'en enregistrer une autre.");
    }
    if ($Mysql->fetchOne("SELECT 1 AS x FROM u_vue WHERE id_users = ? AND liste = ? AND nom = ?", array((int) $user->id_users, $liste, $nom), 'iss') !== null) {
        $Response->validationError("Une de vos vues porte déjà ce nom.");
    }
    $Mysql->execute(
        "INSERT INTO u_vue (id_users, liste, nom, filtres, ordre) VALUES (?, ?, ?, ?, ?)",
        array((int) $user->id_users, $liste, $nom, json_encode($reglages, JSON_UNESCAPED_UNICODE), $nb),
        'isssi'
    );
    $Response->success(array('id_vue' => $Mysql->lastId(), 'vues' => Vue::de($user->id_users, $liste)), 201);
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireUser();
    $R = json_decode(file_get_contents("php://input"), true);
    $v = vueDe($user, is_array($R) ? ($R['id_vue'] ?? null) : null);
    $liste = listeDemandee($user, $v->liste);
    $set = array();
    $params = array();
    if (array_key_exists('nom', $R)) {
        $nom = nom($R);
        if ($Mysql->fetchOne("SELECT 1 AS x FROM u_vue WHERE id_users = ? AND liste = ? AND nom = ? AND id_vue <> ?", array((int) $user->id_users, $liste, $nom, (int) $v->id_vue), 'issi') !== null) {
            $Response->validationError("Une de vos vues porte déjà ce nom.");
        }
        $set[] = 'nom = ?';
        $params[] = $nom;
    }
    if (array_key_exists('reglages', $R)) {
        list($reglages, $raison) = Vue::valider($liste, $R['reglages'], $user);
        if ($raison !== null) {
            $Response->validationError($raison);
        }
        $set[] = 'filtres = ?';
        $params[] = json_encode($reglages, JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('ordre', $R)) {
        if (!is_int($R['ordre']) || $R['ordre'] < 0 || $R['ordre'] > 100) {
            $Response->validationError("Erreur paramètre ORDRE");
        }
        $set[] = 'ordre = ?';
        $params[] = $R['ordre'];
    }
    if (count($set) === 0) {
        $Response->validationError("Rien à modifier.");
    }
    $params[] = (int) $v->id_vue;
    $Mysql->execute("UPDATE u_vue SET " . implode(', ', $set) . ", date_modif = NOW() WHERE id_vue = ?", $params);
    $Response->success(array('vues' => Vue::de($user->id_users, $liste)));
}

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $user = $U->requireUser();
    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $v = vueDe($user, $id);
    $Mysql->execute("DELETE FROM u_vue WHERE id_vue = ?", array((int) $v->id_vue), 'i');
    $Response->success(array('vues' => Vue::de($user->id_users, $v->liste)));
}

$Response->methodNotAllowed();

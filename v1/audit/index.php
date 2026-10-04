<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.xlsx.php";
include "../../include/package.export.php";
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

$admin = $U->requireAccess('utilisateurs', 'C');

// Journal d'audit (CDC §21, §22) ################################
// GET ?id_users=&cible_type=&cible_id=&action=&date_debut=YYYY-MM-DD&date_fin=YYYY-MM-DD&q=&page=&limit=
// id_users = auteur de l'action ; cible_type + cible_id = objet sur lequel elle porte

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $where = array();
    $params = array();
    $types = '';

    if (isset($_GET['id_users']) && is_numeric($_GET['id_users']) && (int) $_GET['id_users'] > 0) {
        $where[] = "a.id_users = ?";
        $params[] = (int) $_GET['id_users'];
        $types .= 'i';
    }
    if (isset($_GET['cible_type']) && is_string($_GET['cible_type']) && preg_match('/^[a-z_]{1,30}$/', $_GET['cible_type'])
        && isset($_GET['cible_id']) && is_numeric($_GET['cible_id']) && (int) $_GET['cible_id'] > 0) {
        $where[] = "a.cible_type = ? AND a.cible_id = ?";
        $params[] = $_GET['cible_type'];
        $params[] = (int) $_GET['cible_id'];
        $types .= 'si';
    }
    if (isset($_GET['action']) && is_string($_GET['action']) && preg_match('/^[a-z0-9_]{1,50}$/', $_GET['action'])) {
        $where[] = "a.action = ?";
        $params[] = $_GET['action'];
        $types .= 's';
    }
    foreach (array('date_debut' => 'a.date >= ?', 'date_fin' => 'a.date < DATE_ADD(?, INTERVAL 1 DAY)') as $cle => $cond) {
        if (isset($_GET[$cle]) && is_string($_GET[$cle]) && $_GET[$cle] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $_GET[$cle]);
            if ($d === false || $d->format('Y-m-d') !== $_GET[$cle]) {
                $Response->validationError("Date invalide (format AAAA-MM-JJ) : $cle");
            }
            $where[] = $cond;
            $params[] = $_GET[$cle];
            $types .= 's';
        }
    }
    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $q = '%' . mb_substr($CD->clean_text($_GET['q'], array('API')), 0, 100) . '%';
        $where[] = "(u.identifiant LIKE ? OR a.ip LIKE ? OR a.details LIKE ?)";
        array_push($params, $q, $q, $q);
        $types .= 'sss';
    }

    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int) $_GET['page']) : 1;
    $limit = (isset($_GET['limit']) && is_numeric($_GET['limit'])) ? min(200, max(1, (int) $_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;
    // &format=xlsx : tout le journal filtré, en classeur (administrateur seul : ce journal ne s'ouvre qu'à lui)
    if (Export::demande()) {
        Export::exiger($admin);
        list($page, $limit, $offset) = array(1, Export::MAX_LIGNES + 1, 0);
    }

    $from = " FROM u_audit a LEFT JOIN u_users u ON u.id_users = a.id_users"
        . (count($where) > 0 ? " WHERE " . implode(" AND ", $where) : "");

    $total = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb" . $from, $params, $types)->nb;

    $rows = $Mysql->fetchAll(
        "SELECT a.id_audit, a.id_users, a.action, a.cible_type, a.cible_id, a.details, a.ip, a.user_agent, a.date,
                u.identifiant, u.nom, u.prenom" . $from . " ORDER BY a.id_audit DESC LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset)),
        $types . 'ii'
    );

    $audit = array();
    foreach ($rows as $r) {
        $audit[] = array(
            'id_audit' => (int) $r->id_audit,
            'id_users' => $r->id_users === null ? null : (int) $r->id_users,
            'identifiant' => $r->identifiant,
            'nom' => $r->nom,
            'prenom' => $r->prenom,
            'action' => $r->action,
            'cible_type' => $r->cible_type,
            'cible_id' => $r->cible_id === null ? null : (int) $r->cible_id,
            'details' => $r->details === null ? null : json_decode($r->details, true),
            'ip' => $r->ip,
            'user_agent' => $r->user_agent,
            'date' => $r->date,
        );
    }

    $actions = array();
    foreach ($Mysql->fetchAll("SELECT DISTINCT action FROM u_audit ORDER BY action") as $r) {
        $actions[] = $r->action;
    }

    Export::siDemande('audit', $admin, $audit, $total);
    $Response->success(array(
        'audit' => $audit,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'actions' => $actions,
    ));
}

$Response->methodNotAllowed();

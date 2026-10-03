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

// Listes de référence (CDC §6, §7, §21) ################################
// GET : origines des contacts, catégories de problématiques, offres (prix en centimes) et moyens de paiement actifs.
// Le réglage de ces listes arrive à l'étape 7.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireUser();

    $Response->success(array(
        'origines' => $Mysql->fetchAll("SELECT code, libelle FROM p_origine WHERE actif = 1 ORDER BY ordre, libelle"),
        'categories' => $Mysql->fetchAll("SELECT code, libelle FROM p_categorie_problematique WHERE actif = 1 ORDER BY ordre, libelle"),
        'offres' => array_map(function ($o) {
            return array('code' => $o->code, 'libelle' => $o->libelle, 'prix' => (int) $o->prix);
        }, $Mysql->fetchAll("SELECT code, libelle, prix FROM p_offre WHERE actif = 1 ORDER BY ordre, libelle")),
        'moyens' => $Mysql->fetchAll("SELECT code, libelle FROM p_moyen_paiement WHERE actif = 1 ORDER BY ordre, libelle"),
    ));
}

$Response->methodNotAllowed();

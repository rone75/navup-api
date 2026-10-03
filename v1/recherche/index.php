<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Recherche globale (CDC §16) ################################
// GET ?q= : nom, prénom, e-mail, téléphone ou identifiant client ; 10 dossiers au plus, parmi ceux que l'utilisateur peut lire.
// Ne lit que d_contact : jamais un prénom d'enfant, une note ou une problématique.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireUser();

    $statuts = array();
    foreach (Contact::GROUPES as $groupe => $liste) {
        if ($U->can($user, $groupe, 'L')) {
            $statuts = array_merge($statuts, $liste);
        }
    }
    if (count($statuts) === 0) {
        $Response->forbidden("Vous n'avez pas accès à ce module.");
    }

    $cond = isset($_GET['q']) ? $Contact->conditionRecherche($_GET['q']) : null;
    if ($cond === null) {
        // Terme trop court : pas d'erreur, la recherche s'affine au fil de la frappe
        $Response->success(array('resultats' => array()));
    }

    $rows = $Mysql->fetchAll(
        "SELECT " . Contact::COLONNES . Contact::SQL_FROM
        . " WHERE " . $cond[0] . " AND c.statut IN (" . implode(', ', array_fill(0, count($statuts), '?')) . ")
          ORDER BY (c.date_archivage IS NOT NULL), c.nom, c.prenom, c.id_contact
          LIMIT 10",
        array_merge($cond[1], $statuts)
    );

    $resultats = array();
    foreach ($rows as $row) {
        $resultats[] = $Contact->sortie($row);
    }

    $Response->success(array('resultats' => $resultats));
}

$Response->methodNotAllowed();

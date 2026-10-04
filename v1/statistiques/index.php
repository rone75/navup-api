<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.vente.php";
include "../../include/package.pilotage.php";
include "../../include/package.xlsx.php";
include "../../include/package.export.php";
include "../../include/package.statistiques.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();
$Pilotage = new Pilotage();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Statistiques (CDC §17, étape 7a) ################################
// GET ?periode=jour|7j|mois|trimestre|annee|perso&du=&au= (le mois par défaut)
// → la période et la période précédente de même durée ; puis, un bloc par droit : nouveaux dossiers, conversion,
//   finances (ventes, panier moyen, modalités ; écritures, moyens de paiement), reste à encaisser, origines,
//   rendez-vous, problématiques (agrégées), programme, évolution sur 12 mois.
// Chaque nombre est le total d'une liste, calculé par la requête de cette liste (package.pilotage.php) ; les parts
// (longueurs de barres, pour cent) sont calculées ici, le front ne fait aucun calcul.
// &format=xlsx : les mêmes chiffres en classeur, une feuille par section (droit exports).
// Le rapport PDF de la même période : v1/statistiques/rapport/.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireAccess('statistiques', 'L');

    $aujourdhui = date('Y-m-d');
    $periode = $Pilotage->resoudrePeriode($aujourdhui);
    $stats = $Pilotage->statistiques($user, $periode, $aujourdhui);

    if (Export::demande()) {
        Export::exiger($user);
        $classeur = Statistiques::classeur($stats);
        Export::envoyer($classeur, 'statistiques-' . $periode['du'] . '-' . $periode['au'] . '.xlsx', 'statistiques', $user, $classeur->nbFeuilles());
    }

    $Response->success($stats);
}

$Response->methodNotAllowed();

<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.pilotage.php";
include "../../../include/package.xlsx.php";
include "../../../include/package.export.php";
include "../../../include/package.pdf.php";
include "../../../include/package.statistiques.php";
include "../../../require/param.php";

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

// Rapport de synthèse d'une période (CDC §18, étape 7a) ################################
// GET ?periode=…&du=&au= → application/pdf : les chiffres de v1/statistiques/ pour la même période, mêmes blocs
// selon les mêmes droits, dans le même ordre. Aucun nom de famille. Droits : statistiques et exports.
// Fabriqué à la demande, jamais gardé ; inscrit au journal d'audit comme un export.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireAccess('statistiques', 'L');
    Export::exiger($user);

    $aujourdhui = date('Y-m-d');
    $periode = $Pilotage->resoudrePeriode($aujourdhui);
    $stats = $Pilotage->statistiques($user, $periode, $aujourdhui);
    $auteur = trim((string) $user->prenom . ' ' . (string) $user->nom);
    $pdf = Statistiques::rapport($stats, $auteur !== '' ? $auteur : (string) $user->identifiant, __DIR__ . '/../../../assets/logo-navup.jpg');

    $U->audit((int) $user->id_users, 'export', array('liste' => 'rapport', 'lignes' => 0, 'filtres' => Export::filtres()));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="rapport-navup-' . $periode['du'] . '-' . $periode['au'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit();
}

$Response->methodNotAllowed();

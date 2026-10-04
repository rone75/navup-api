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

// Tableau de bord (CDC §3) ################################
// GET ?periode=jour|7j|mois|trimestre|annee|perso&du=&au= (le mois par défaut ; du et au pour « perso » seulement)
// À ce jour : `dossiers` (par statut), `a_encaisser`. Sur la période : `nouveaux`, `conversion`, `ventes`, `ecritures`.
// Puis `activite`, les derniers faits enregistrés. Chaque chiffre est le total d'une liste, calculé par la même requête.
// Tout utilisateur connecté ; un bloc par droit, absent de la réponse sans ce droit.
// Les tâches à faire et les rendez-vous à venir ne sont pas repris ici : v1/taches/ et v1/rendez-vous/ les servent.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireUser();

    $aujourdhui = date('Y-m-d');
    $periode = $Pilotage->resoudrePeriode($aujourdhui);

    $out = array('aujourdhui' => $aujourdhui, 'periode' => $periode);
    $blocs = array(
        'dossiers' => $Pilotage->dossiers($user),
        'a_encaisser' => $Pilotage->aEncaisser($user, $aujourdhui),
        'nouveaux' => $Pilotage->nouveaux($user, $periode),
        'conversion' => $Pilotage->conversion($user, $periode),
        'ventes' => $Pilotage->ventes($user, $periode),
        'ecritures' => $Pilotage->ecritures($user, $periode),
    );
    foreach ($blocs as $cle => $bloc) {
        if ($bloc !== null) {
            $out[$cle] = $bloc;
        }
    }
    $out['activite'] = $Contact->activite($user);

    $Response->success($out);
}

$Response->methodNotAllowed();

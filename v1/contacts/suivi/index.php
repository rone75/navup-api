<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Rdv = new Rdv();
$Interaction = new Interaction();
$Tache = new Tache();
$Suivi = new Suivi();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Suivi d'un dossier, pour la fiche 360° (CDC §5, §11, §12, §14) ################################
// GET ?id=N : synthèse (prochain rendez-vous, dernier échange, prochaine action), rendez-vous, derniers échanges,
// tâches ouvertes. Chaque partie n'est présente qu'avec son droit ; les textes internes, avec le droit famille.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    list($user) = $Contact->exigerDossier($id, 'L');

    // Les tâches automatiques du dossier sont remises à jour à son ouverture (n'écrit que s'il y a une différence)
    $Tache->synchroniser($id);

    $Response->success(array('suivi' => $Suivi->bloc($id, $user)));
}

$Response->methodNotAllowed();

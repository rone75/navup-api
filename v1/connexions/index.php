<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.vente.php";
include "../../include/package.suivi.php";
include "../../include/package.message.php";
include "../../include/package.automate.php";
include "../../include/package.stripe.php";
include "../../include/package.connexions.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();
$Rdv = new Rdv();
$Interaction = new Interaction();
$Tache = new Tache();
$Suivi = new Suivi();
$Message = new Message();
$Stripe = new PaiementStripe();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Supervision des connexions (CDC §23, §27) ################################
// GET : état de Stripe, des e-mails, des médias de la formation et de la tâche planifiée, avec ce qui est en erreur
// ou en attente. Réservé à l'administrateur (droit « parametres »). Aucun secret, aucun corps d'e-mail.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireAccess('parametres', 'L');

    $Response->success(array('connexions' => Connexions::etat()));
}

$Response->methodNotAllowed();

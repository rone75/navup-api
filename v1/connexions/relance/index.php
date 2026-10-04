<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.suivi.php";
include "../../../include/package.message.php";
include "../../../include/package.ics.php";
include "../../../include/package.agenda.php";
include "../../../include/package.automate.php";
include "../../../include/package.stripe.php";
include "../../../include/package.connexions.php";
include "../../../require/param.php";

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

// Relancer un signal Stripe resté en erreur ################################
// POST {id_evenement} : l'objet est relu chez Stripe et le fait constaté de nouveau. Sans risque de doublon :
// un fait déjà écrit n'est pas réécrit. Renvoie l'état des connexions.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('parametres', 'C');

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_evenement) && is_int($R->id_evenement)) ? $R->id_evenement : 0;
    $e = $id > 0 ? $Mysql->fetchOne("SELECT id_evenement, statut FROM s_evenement WHERE id_evenement = ?", array($id), 'i') : null;
    if ($e === null) {
        $Response->notFound("Signal introuvable.");
    }
    if (!in_array($e->statut, array('erreur', 'recu'), true)) {
        $Response->validationError("Ce signal est déjà traité.");
    }

    // Les classes métier lèvent leurs refus : le signal garde sa raison au lieu d'interrompre la requête
    $Sortie = $Response;
    $Response = new ReponseAutomate();
    $statut = $Stripe->relancer($id, (int) $user->id_users);
    $Response = $Sortie;

    $Response->success(array('statut' => $statut, 'connexions' => Connexions::etat()));
}

$Response->methodNotAllowed();

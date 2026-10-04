<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.message.php";
include "../../../include/package.ics.php";
include "../../../include/package.suivi.php";
include "../../../include/package.automate.php";
include "../../../include/package.stripe.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Message = new Message();
// Un e-mail de rendez-vous relancé recompose son lien de gestion et son invitation
$Rdv = new Rdv();
$Vente = new Vente();
$Stripe = new PaiementStripe();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Relancer un e-mail en erreur (CDC §27 : « les automatisations échouées sont détectables et relançables ») ########
// POST {id_message} : réservé à l'administrateur (onglet « Connexions »). Le message repart tout de suite ;
// un message déjà envoyé n'est pas renvoyé.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('parametres', 'C');

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_message) && is_int($R->id_message)) ? $R->id_message : 0;
    $m = $id > 0 ? $Mysql->fetchOne("SELECT id_message, etat FROM m_message WHERE id_message = ?", array($id), 'i') : null;
    if ($m === null) {
        $Response->notFound("Message introuvable.");
    }
    if ($m->etat !== 'erreur') {
        $Response->validationError("Seul un message en erreur se relance.");
    }

    $envoye = $Message->relancer($id, (int) $user->id_users);
    $m = $Mysql->fetchOne("SELECT * FROM m_message WHERE id_message = ?", array($id), 'i');

    $Response->success(array('envoye' => $envoye, 'message' => $Message->sortie($m, true)));
}

$Response->methodNotAllowed();

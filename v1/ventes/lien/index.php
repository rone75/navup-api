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

// Lien de paiement d'une vente (CDC §10) ################################
// POST {id_vente} : crée un lien de paiement → {lien}. L'adresse n'est donnée que cette fois : seule l'empreinte
//     de son jeton est gardée. Le parent y paie l'échéance due, sur la page de Stripe.
// POST {id_vente, envoyer: 1} : envoie le lien par e-mail au parent (modèle « lien de paiement ») → {envoye}.
//     Le lien de l'e-mail est composé au moment de l'envoi.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_vente) && is_int($R->id_vente)) ? $R->id_vente : 0;
    list($user, $vente) = $Vente->exigerVente($id, 'paiements', 'C');
    $envoyer = isset($R->envoyer) && $R->envoyer === 1;

    if (!$Stripe->configure()) {
        $Response->validationError("Le paiement en ligne n'est pas configuré sur ce serveur.");
    }
    $echeance = $vente->date_annulation !== null ? null : $Stripe->echeanceDue($id);
    if ($echeance === null) {
        $Response->validationError("Il n'y a plus rien à régler sur cette vente.");
    }

    if ($envoyer) {
        $contact = $Contact->charger($vente->id_contact);
        if ($contact->email === null) {
            $Response->validationError("Ce dossier n'a pas d'adresse e-mail : copiez le lien pour le transmettre autrement.");
        }
        $idm = $Message->deposer($contact, 'lien_paiement', array(
            'montant' => (int) $echeance->reste,
            'echeance' => PaiementStripe::libelleEcheance($echeance),
        ), 'lien_paiement:' . $id . ':' . bin2hex(random_bytes(6)), array(
            'objet_type' => 'vente', 'objet_id' => $id, 'origine' => 'utilisateur', 'id_users' => (int) $user->id_users,
        ));
        $envoye = $idm !== null && $Message->envoyer($idm);
        if (!$envoye) {
            $Response->validationError("L'e-mail n'a pas pu partir : il reste dans la file, à relancer depuis l'onglet « Connexions ».");
        }

        $Response->success(array('envoye' => true, 'messages' => $Message->duDossier($vente->id_contact, $user)), 201);
    }

    $lien = $Stripe->creerLien($id, (int) $user->id_users);
    $U->audit((int) $user->id_users, 'vente_lien', array('id_vente' => $id), 'contact', (int) $vente->id_contact);

    $Response->success(array('lien' => $lien), 201);
}

$Response->methodNotAllowed();

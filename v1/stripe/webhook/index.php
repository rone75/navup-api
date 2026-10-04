<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
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
// Filtre par adresse IP (défense en profondeur) ; l'authentification du webhook est sa signature, vérifiée plus bas
$H->cors_stripe('json');

Automate::demarrer(getcwd() . "/index.php");

// Webhook Stripe (CDC §10, §23) ################################
// POST : un événement signé par Stripe (en-tête Stripe-Signature). Sans signature valide : 400, rien n'est lu.
// L'événement n'est qu'un signal : il est consigné (s_evenement, une seule fois par identifiant), puis l'objet est
// relu chez Stripe et le fait constaté. 200 dès que le signal est consigné, même si le fait ne peut pas s'écrire :
// la raison se lit dans l'onglet « Connexions », où le signal se relance.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $corps = file_get_contents("php://input", false, null, 0, 1000001);
    $code = strlen($corps) > 1000000
        ? 400
        : $Stripe->recevoir($corps, isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '');

    http_response_code($code);
    echo json_encode(array('recu' => $code === 200));
    exit();
}

http_response_code(405);
echo json_encode(array('recu' => false));
exit();

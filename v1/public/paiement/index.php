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
$H->cors();

Automate::demarrer(getcwd() . "/index.php");

// Lien de paiement envoyé au parent (CDC §10) ################################
// Endpoint PUBLIC : GET ?j=<jeton> ouvre la page de paiement de l'échéance due et y redirige le navigateur (303).
// Le jeton (40 caractères, haché en base) ne donne accès qu'à payer : rien du dossier n'est affiché.
// Tout autre cas (jeton inconnu, rien à régler, Stripe indisponible) mène à la page de retour, avec son état.

$retour = function ($etat) use ($_PATH_API) {
    header('Location: ' . $_PATH_API . 'v1/public/retour/?etat=' . $etat, true, 303);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    Automate::limiter('paiement', (int) $_LIMITE_PAIEMENT[0], (int) $_LIMITE_PAIEMENT[1]);

    $idv = $Stripe->venteDuLien(isset($_GET['j']) ? $_GET['j'] : null);
    if ($idv === null) {
        $retour('invalide');
    }
    try {
        $url = $Stripe->sessionVente($idv);
    } catch (ErreurMetier $e) {
        $SQL->rollback();
        $retour('attente');
    } catch (Throwable $e) {
        $SQL->rollback();
        $retour('indisponible');
    }
    if ($url === null) {
        $retour('regle');
    }

    header('Location: ' . $url, true, 303);
    exit();
}

http_response_code(405);
exit();

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

// Retour de la page de paiement Stripe ################################
// Endpoint PUBLIC : GET ?etat=ok&session=cs_… (posé par Stripe), ou ?etat=annule|regle|invalide|attente|indisponible.
// Au retour d'un paiement, l'outil constate le fait tout de suite (le même traitement que le webhook : rejouable),
// puis affiche une page sans aucune donnée de dossier, ou redirige vers $_URL_RETOUR_PAIEMENT (la page de vente).

$MESSAGES = array(
    'ok' => array("Merci, votre paiement est bien reçu", "Un e-mail de confirmation vous attend dans votre boîte. À très vite."),
    'annule' => array("Le paiement n'a pas été effectué", "Rien n'a été débité. Vous pouvez reprendre quand vous le souhaitez."),
    'regle' => array("Tout est déjà réglé", "Il n'y a plus rien à payer avec ce lien. Merci."),
    'invalide' => array("Ce lien n'est plus valable", "Demandez-nous un nouveau lien : contact@navup.fr."),
    'attente' => array("Un paiement est déjà en cours", "Son résultat sera connu dans quelques minutes : il est inutile de payer une seconde fois."),
    'indisponible' => array("Le paiement en ligne est momentanément indisponible", "Réessayez dans quelques minutes. Si le problème dure : contact@navup.fr."),
);

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    Automate::limiter('paiement', (int) $_LIMITE_PAIEMENT[0], (int) $_LIMITE_PAIEMENT[1]);

    $etat = (isset($_GET['etat']) && is_string($_GET['etat']) && isset($MESSAGES[$_GET['etat']])) ? $_GET['etat'] : 'invalide';

    if ($etat === 'ok' && isset($_GET['session']) && is_string($_GET['session']) && preg_match('/^cs_[A-Za-z0-9_]{10,150}$/', $_GET['session'])) {
        // Seule une page de paiement ouverte par l'outil est relue chez Stripe
        if ($Mysql->fetchOne("SELECT 1 AS x FROM s_session WHERE stripe_session_id = ?", array($_GET['session']), 's') !== null) {
            try {
                $Stripe->traiter($Stripe->signaler('retour:' . $_GET['session'], 'checkout.session.retour', $_GET['session'], 'retour', date('Y-m-d H:i:s')));
            } catch (Throwable $e) {
                // Le webhook ou le rattrapage constateront le paiement : la page de retour ne doit jamais échouer
            }
        }
    }

    if (isset($_URL_RETOUR_PAIEMENT) && $_URL_RETOUR_PAIEMENT !== '') {
        header('Location: ' . $_URL_RETOUR_PAIEMENT . (strpos($_URL_RETOUR_PAIEMENT, '?') === false ? '?' : '&') . 'paiement=' . $etat, true, 303);
        exit();
    }

    list($titre, $texte) = $MESSAGES[$etat];
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>NavUp</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'font:17px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1c2330;background:#f6f4ef}'
        . 'main{max-width:32rem;padding:2rem}h1{font-size:1.6rem;line-height:1.25;margin:0 0 .75rem}p{margin:0}'
        . 'small{display:block;margin-top:2rem;color:#5b6472}</style></head><body><main>'
        . '<h1>' . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($texte, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<small>NavUp, l’accompagnement parental</small></main></body></html>';
    exit();
}

http_response_code(405);
exit();

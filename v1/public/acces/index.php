<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.message.php";
include "../../../include/package.automate.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

// Réponses au navigateur. Les classes métier, elles, lèvent leurs refus (ReponseAutomate) : rien ne s'arrête au milieu.
$Sortie = new Response();
Automate::demarrer(getcwd() . "/index.php");

// Demande d'un lien d'accès à l'espace personnel (« mot de passe oublié », « première connexion ») ####################
// Endpoint PUBLIC, appelé par l'appli des parents : POST {email} → {success: true}, toujours.
// La réponse ne dit jamais si l'adresse a un compte, et elle part AVANT tout envoi : son délai ne le dit pas non plus.
// Si l'adresse est celle d'un dossier qui a un compte, un e-mail part : « invitation » tant que le parent n'a pas de
// mot de passe, « mot de passe oublié » ensuite. Le lien est créé par Compte::lienAcces() au moment de l'envoi.
// Garde-fous : limiteur par adresse IP, un message par compte et par quart d'heure, $_ACCES_MAX_PAR_JOUR par 24 heures.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Automate::limiter('acces', (int) $_LIMITE_ACCES[0], (int) $_LIMITE_ACCES[1]);
    $R = Automate::lireCorps(2000);

    $email = (isset($R->email) && is_string($R->email)) ? mb_strtolower(trim($R->email), 'UTF-8') : '';
    if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $Sortie->validationError("Saisissez votre adresse e-mail.");
    }

    // La réponse d'abord, la même pour tous ; le travail ensuite, hors de la vue du demandeur
    http_response_code(200);
    echo ")]}',\n" . json_encode(array('success' => true));
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }

    try {
        $contact = $Mysql->fetchOne(
            "SELECT c.id_contact, c.prenom, c.email FROM d_contact c INNER JOIN a_compte a ON a.id_contact = c.id_contact WHERE c.email = ?",
            array($email),
            's'
        );
        if ($contact !== null && isset($_APP_PARENTS_URL) && $_APP_PARENTS_URL !== '') {
            $recents = (int) $Mysql->fetchOne(
                "SELECT COUNT(*) AS nb FROM m_message
                 WHERE id_contact = ? AND modele IN ('invitation', 'mot_de_passe') AND origine = 'automatique' AND date_creation > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                array((int) $contact->id_contact),
                'i'
            )->nb;
            if ($recents < (int) $_ACCES_MAX_PAR_JOUR && Compte::inviter($contact) !== null) {
                $Message->envoyerEnAttente((int) $contact->id_contact);
            }
        }
    } catch (Throwable $e) {
        // La réponse est partie : la passe « messages » de la tâche planifiée reprendra un envoi resté en file
        $SQL->rollback();
    }
    exit();
}

$Sortie->methodNotAllowed();

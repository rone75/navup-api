<?php

include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.agenda.php";
include "../../../include/package.ics.php";
include "../../../include/package.automate.php";
include "../../../require/param.php";

Automate::demarrer(getcwd() . "/index.php");

// Flux d'agenda d'un utilisateur (étape 6b ; CDC §11) ################################
// GET ?j=<jeton> → text/calendar : les rendez-vous qu'il mène, pour l'abonnement d'un calendrier (Google Agenda,
// calendrier d'un téléphone, Outlook). Un calendrier ne sait envoyer ni en-tête ni corps : le jeton est dans l'adresse.
// C'est le seul secret de cette adresse ; il est long (256 bits), propre à l'utilisateur, renouvelable et coupé depuis
// « Mon compte ». En production, le journal d'Apache ne garde pas la chaîne de requête de ce chemin (README).
// Le flux ne dit que le type, le prénom et l'initiale du nom, le canal et l'état : ni nom complet, ni téléphone, ni
// note. Il transite par les serveurs du calendrier, qui le relisent quelques fois par jour : ce n'est pas une alerte.
// Jeton inconnu, compte désactivé ou sans droit sur les rendez-vous : 404, sans détail. Seuls les échecs sont limités
// par adresse IP (les calendriers en ligne lisent depuis des adresses partagées).

if ($_SERVER['REQUEST_METHOD'] !== "GET" && $_SERVER['REQUEST_METHOD'] !== "HEAD") {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit();
}

$user = $Agenda->utilisateurDuFlux(isset($_GET['j']) ? $_GET['j'] : null);
if ($user === null) {
    Automate::limiter('flux-echec', 20, 15);
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Flux introuvable.\n");
}

$fiche = (isset($_URL_TOUR) && $_URL_TOUR !== '') ? $_URL_TOUR . 'rendez-vous/' : null;
$ics = Ics::flux($Agenda->rdvDuFlux((int) $user->id_users), $fiche);
$etag = '"' . sha1($ics) . '"';

// Dernière lecture, notée au plus une fois par heure : « Mon compte » dit si un calendrier est bien abonné
if ($user->date_dernier_acces === null || $user->date_dernier_acces < date('Y-m-d H:i:s', time() - 3600)) {
    $Mysql->execute("UPDATE u_agenda SET date_dernier_acces = NOW() WHERE id_users = ?", array((int) $user->id_users), 'i');
}

header('Cache-Control: private, max-age=900');
header('ETag: ' . $etag);
header('X-Robots-Tag: noindex');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit();
}
header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="navup.ics"');
header('Content-Length: ' . strlen($ics));
if ($_SERVER['REQUEST_METHOD'] === "GET") {
    echo $ics;
}

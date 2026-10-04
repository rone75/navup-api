<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.agenda.php";
include "../../../include/package.automate.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

$Sortie = new Response();
Automate::demarrer(getcwd() . "/index.php");

// Créneaux du rendez-vous découverte (étape 6b ; cahier de l'écosystème §5) ################################
// Endpoint PUBLIC, sans authentification : lu par la page publique de l'appli des parents.
// GET → {ouvert, type, duree, canaux: ["visio", "telephone"], fuseau: "Europe/Paris", jours: [{date, creneaux: ["09:00", …]}]}
// Heure de Paris. Aucune identité : la page ne sait ni qui reçoit ni combien de personnes reçoivent.
// Fermé (interrupteur, plafond du jour, aucune disponibilité) : ouvert = false, la page renvoie à l'adresse de contact.
// Les créneaux d'accompagnement d'un parent inscrit se lisent avec son billet : v1/public/rendez-vous/espace/.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    Automate::limiter('creneaux', (int) $_LIMITE_CRENEAUX[0], (int) $_LIMITE_CRENEAUX[1]);

    $Sortie->success($Agenda->prise('decouverte', 'public'));
}

$Sortie->methodNotAllowed();

<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Rdv = new Rdv();
$Interaction = new Interaction();
$Tache = new Tache();
$Suivi = new Suivi();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Statut d'un rendez-vous (CDC §11) ################################
// PUT {id_rdv, statut, date?, heure?, duree?, motif_cloture?, compte_rendu?}
// - a_confirmer, confirme : sur une demande, `date` et `heure` fixent le créneau (une fois pour toutes) ;
// - effectue, absent : une fois le rendez-vous commencé ; `compte_rendu` avec « effectué », `motif_cloture` avec « absent » ;
// - annule : `motif_cloture` facultatif. Un rendez-vous clôturé se rétablit (issue cochée par erreur).
// Motif et compte rendu sont des notes internes : droit famille. Après une annulation ou une absence,
// `proposition` vaut « a_relancer » si le prospect n'a plus aucun rendez-vous : l'outil propose, il ne le fait pas seul.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_rdv) && is_int($R->id_rdv)) ? $R->id_rdv : 0;
    list($user, $rdv) = $Rdv->exigerRdv($id, 'C');

    if (!isset($R->statut) || !is_string($R->statut) || !isset(Rdv::TRANSITIONS[$R->statut]) || $R->statut === 'reporte') {
        $Response->validationError("Statut de rendez-vous invalide.");
    }

    $champs = $S->lireChamps($R, array(
        'duree' => array('type' => 'int', 'min' => 5, 'max' => 600, 'libelle' => 'durée'),
        'motif_cloture' => array('type' => 'str', 'max' => 255, 'libelle' => 'motif'),
        'compte_rendu' => array('type' => 'text', 'max' => 5000, 'libelle' => 'compte rendu'),
    ), true);
    if ((isset($champs['motif_cloture']) || isset($champs['compte_rendu'])) && !$U->can($user, 'famille', 'C')) {
        $Response->forbidden("Le motif et le compte rendu sont des notes internes : vous n'avez pas le droit de les écrire.");
    }
    $champs['debut'] = $Rdv->lireCreneau($R);

    $avertissements = $Rdv->changerStatut($rdv, $R->statut, $champs, (int) $user->id_users);

    $idc = (int) $rdv->id_contact;
    $Response->success($Suivi->reponse($idc, $user, $avertissements, array(
        'id_rdv' => $id,
        'proposition' => in_array($R->statut, array('annule', 'absent'), true) ? $Rdv->proposition($idc) : null,
    )));
}

$Response->methodNotAllowed();

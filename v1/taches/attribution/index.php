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

// Attribuer une tâche (CDC §14) ################################
// PUT {id_tache, id_users_assigne} : un utilisateur actif qui peut lire la tâche, ou null (personne).

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_tache) && is_int($R->id_tache)) ? $R->id_tache : 0;
    list($user, $tache) = $Tache->exigerTache($id, 'C');

    if (!is_object($R) || !property_exists($R, 'id_users_assigne') || !($R->id_users_assigne === null || (is_int($R->id_users_assigne) && $R->id_users_assigne > 0))) {
        $Response->validationError("Erreur paramètre ID_USERS_ASSIGNE manquant (identifiant ou null)");
    }
    $Tache->exigerAttribuable($tache->categorie, $R->id_users_assigne);

    $Tache->attribuer($tache, $R->id_users_assigne, (int) $user->id_users);

    $Response->success($Tache->reponse($id, $tache->id_contact === null ? null : (int) $tache->id_contact, $user));
}

$Response->methodNotAllowed();

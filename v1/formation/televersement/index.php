<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include_once "../../../include/package.formation.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Formation = new Formation();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Téléversement d'un fichier de sujet, par morceaux ################################
// Un audio fourni par NavUp pèse jusqu'à 100 Mo : le navigateur l'envoie par morceaux de $_MEDIA_MORCEAU_MAX octets,
// sous les limites d'envoi de PHP. Trois temps :
// POST {id_sujet, role: audio|fiche|annexe, nom, taille} : ouvre le téléversement → {id_fichier, morceau}.
// PUT ?id=N&position=P (corps binaire) : ajoute un morceau ; P est le nombre d'octets déjà envoyés → {recu}.
//     Hors séquence : 400 avec `recu`, le navigateur reprend d'où le serveur en est.
// PUT ?id=N&fin=1 : clôt le téléversement (taille, type lu dans le fichier) → la formation entière.
// Un audio qui n'est pas un MP3 passe « à convertir » : la tâche planifiée produit le MP3 d'écoute.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('formation', 'C');

    $R = json_decode(file_get_contents("php://input"));
    $ids = (is_object($R) && isset($R->id_sujet) && is_int($R->id_sujet)) ? $R->id_sujet : 0;
    $sujet = $ids > 0 ? $Formation->chargerSujet($ids) : null;
    if ($sujet === null) {
        $Response->notFound("Sujet introuvable.");
    }
    $data = $S->lireChamps($R, array(
        'role' => array('type' => 'enum', 'valeurs' => Formation::ROLES, 'requis' => true, 'libelle' => 'type de fichier'),
        'nom' => array('type' => 'str', 'max' => 200, 'requis' => true, 'libelle' => 'nom du fichier'),
        'taille' => array('type' => 'int', 'min' => 1, 'max' => 999999999, 'requis' => true),
    ), false);

    // Le nom ne sert qu'à l'affichage : jamais de chemin
    $nom = basename(str_replace('\\', '/', $data['nom']));
    $id = $Formation->ouvrirTeleversement($sujet, $data['role'], $nom, $data['taille'], (int) $user->id_users);

    $Response->success(array('id_fichier' => $id, 'morceau' => (int) $_MEDIA_MORCEAU_MAX, 'recu' => 0), 201);
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('formation', 'C');

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $fichier = $id > 0 ? $Formation->chargerFichier($id) : null;
    if ($fichier === null) {
        $Response->notFound("Téléversement introuvable.");
    }

    if (isset($_GET['fin'])) {
        $Formation->cloreTeleversement($fichier, (int) $user->id_users);

        $Response->success(array('id_fichier' => $id, 'formation' => $Formation->sortie($Formation->charger($fichier->id_formation))));
    }

    if (!isset($_GET['position']) || !is_string($_GET['position']) || !ctype_digit($_GET['position']) || strlen($_GET['position']) > 10) {
        $Response->validationError("Erreur paramètre POSITION manquant");
    }
    $longueur = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($longueur <= 0 || $longueur > (int) $_MEDIA_MORCEAU_MAX) {
        $Response->validationError("Morceau de taille inattendue.", array('recu' => (int) $fichier->recu));
    }
    $donnees = file_get_contents("php://input", false, null, 0, (int) $_MEDIA_MORCEAU_MAX + 1);

    $recu = $Formation->recevoirMorceau($fichier, (int) $_GET['position'], $donnees === false ? '' : $donnees);

    $Response->success(array('recu' => $recu));
}

$Response->methodNotAllowed();

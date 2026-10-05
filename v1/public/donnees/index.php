<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.message.php";
include "../../../include/package.rgpd.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

$Response = new Response();
$U = new User();
$Rgpd = new Rgpd();

$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";
date_default_timezone_set('Europe/Paris');

// « Télécharger mes données » (RGPD, étape 8) ################################
// POST {billet} → {donnees} : ce que NavUp garde sur le parent (Rgpd::exporter), jamais une note interne.
// Sans session ici : le billet « donnees » vient de l'API des parents, qui l'a délivré après le mot de passe retapé
// (v1/profil/donnees/). Il vit quelques minutes et ne sert qu'une fois. Le fichier n'est gardé nulle part.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $compte = $Rgpd->billet(isset($R->billet) ? $R->billet : null);
    if ($compte === null) {
        $Response->authError("Votre demande n'est plus valable : recommencez depuis votre espace.");
    }

    $donnees = $Rgpd->exporter((int) $compte->id_contact);
    if ($donnees === null) {
        $Response->notFound("Aucune donnée.");
    }

    $U->audit(null, 'rgpd_export', null, 'contact', (int) $compte->id_contact);
    $Response->success(array('donnees' => $donnees));
}

$Response->methodNotAllowed();

<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.reglage.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Tache = new Tache();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Réglages (CDC §14, §21 ; étape 7b) ################################
// GET → {reglages: [{cle, groupe, libelle, aide?, type, min?, max?, unite?, valeur, defaut, modifie}]}
// PUT {reglages: {cle: valeur | null, …}} : enregistre (null : revient à la valeur par défaut, celle de param.php).
//     Tout ou rien : une valeur refusée n'enregistre aucune des autres. Chaque changement va au journal d'audit
//     (clé, avant, après). Un délai ou une alerte modifiés resynchronisent aussitôt les tâches automatiques.
// Administrateur seulement (droit parametres complet).

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireAccess('parametres', 'C');
    $Response->success(array('reglages' => Reglage::tous()));
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('parametres', 'C');
    $R = json_decode(file_get_contents("php://input"), true);
    if (!is_array($R) || !isset($R['reglages']) || !is_array($R['reglages']) || count($R['reglages']) === 0 || count($R['reglages']) > 80) {
        $Response->validationError("Erreur paramètre REGLAGES manquant");
    }

    $SQL->begin_transaction();
    $changes = 0;
    $taches = false;
    foreach ($R['reglages'] as $cle => $valeur) {
        list($avant, $apres) = Reglage::ecrire((string) $cle, $valeur, (int) $user->id_users);
        if ($avant !== $apres) {
            $changes++;
            $U->audit((int) $user->id_users, 'reglage_update', array('cle' => (string) $cle, 'avant' => $avant, 'apres' => $apres));
            $taches = $taches || strpos((string) $cle, 'ALERTES_ACTIVES.') === 0 || strpos((string) $cle, '_JOURS') !== false;
        }
    }
    $SQL->commit();

    // Les échéances et les alertes suivent leurs réglages tout de suite, pas à la prochaine lecture
    if ($taches) {
        $Tache->synchroniser();
    }

    $Response->success(array('reglages' => Reglage::tous(), 'modifies' => $changes));
}

$Response->methodNotAllowed();

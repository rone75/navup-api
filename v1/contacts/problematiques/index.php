<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Problématiques d'un dossier (CDC §7) : module famille.
// Description et objectif sont déclarés par le parent ; l'évolution se consigne en notes internes datées (v1/contacts/notes/).

// POST {id_contact, code_categorie, id_enfant?, intitule?, description?, objectif_parent?, priorite?, statut?, date_declaration?}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user) = $Contact->exigerFamille($id, 'C');
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, $Contact->specProblematique($id), false);
    if (empty($data['date_declaration'])) {
        $data['date_declaration'] = date('Y-m-d');
    }
    $data['priorite'] = $data['priorite'] ?? 'moyenne';
    $data['statut'] = $data['statut'] ?? 'ouverte';
    $data['id_contact'] = $id;
    $data['id_users'] = $id_users;

    $SQL->begin_transaction();
    $idp = $S->inserer('d_problematique', $data);
    $codes = array('action' => 'ajout', 'categorie' => $data['code_categorie'], 'priorite' => $data['priorite']);
    $Contact->tracer($id, $id_users, 'problematique_create', array_merge(array('id_problematique' => $idp), $codes), array(
        'type' => 'problematique', 'module' => 'famille', 'objet_type' => 'problematique', 'objet_id' => $idp, 'details' => $codes,
    ));
    $SQL->commit();

    $Response->success($Contact->famille($id), 201);
}

// PUT {id_problematique, …champs à modifier}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $idp = (is_object($R) && isset($R->id_problematique) && is_int($R->id_problematique)) ? $R->id_problematique : 0;
    $actuelle = $idp > 0 ? $Mysql->fetchOne("SELECT * FROM d_problematique WHERE id_problematique = ?", array($idp), 'i') : null;

    list($user) = $Contact->exigerFamille($actuelle === null ? 0 : (int) $actuelle->id_contact, 'C');
    $id = (int) $actuelle->id_contact;
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, $Contact->specProblematique($id), true);
    foreach (array('priorite', 'statut') as $champ) {
        if (array_key_exists($champ, $data) && $data[$champ] === null) {
            unset($data[$champ]);
        }
    }

    // Les changements de statut et de priorité gardent leurs codes avant / après : c'est l'historique demandé par le CDC §7
    $codes = array();
    foreach (array('statut', 'priorite') as $champ) {
        if (isset($data[$champ]) && $data[$champ] !== $actuelle->$champ) {
            $codes[$champ] = array('avant' => $actuelle->$champ, 'apres' => $data[$champ]);
        }
    }

    $modifies = $S->differences($actuelle, $data);
    if (count($modifies) > 0) {
        $data['id_users'] = $id_users;
        $SQL->begin_transaction();
        $S->mettreAJour('d_problematique', 'id_problematique', $idp, $data);
        $Contact->tracer(
            $id,
            $id_users,
            'problematique_update',
            array_merge(array('id_problematique' => $idp, 'champs' => $modifies), $codes),
            array('type' => 'problematique', 'module' => 'famille', 'objet_type' => 'problematique', 'objet_id' => $idp, 'details' => array_merge(array('action' => 'modification'), $codes))
        );
        $SQL->commit();
    }

    $Response->success($Contact->famille($id));
}

$Response->methodNotAllowed();

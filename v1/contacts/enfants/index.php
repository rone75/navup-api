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

// Enfants concernés (CDC §6) : module famille. Le strict nécessaire : prénom ou surnom, âge déclaré, niveau scolaire.

// POST {id_contact, prenom?, age?, niveau_scolaire?}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user) = $Contact->exigerFamille($id, 'C');
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, $Contact->specEnfant(), false);
    if (count(array_filter($data, function ($v) {
        return $v !== null;
    })) === 0) {
        $Response->validationError("Renseignez au moins le prénom, l'âge ou le niveau scolaire.");
    }
    if (isset($data['age'])) {
        $data['date_age'] = date('Y-m-d');
    }
    $data['id_contact'] = $id;
    $data['id_users'] = $id_users;

    $SQL->begin_transaction();
    $ide = $S->inserer('d_enfant', $data);
    $Contact->tracer($id, $id_users, 'enfant_create', array('id_enfant' => $ide), array(
        'type' => 'enfant', 'module' => 'famille', 'objet_type' => 'enfant', 'objet_id' => $ide, 'details' => array('action' => 'ajout'),
    ));
    $SQL->commit();

    $Response->success($Contact->famille($id), 201);
}

// PUT {id_enfant, prenom?, age?, niveau_scolaire?}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $ide = (is_object($R) && isset($R->id_enfant) && is_int($R->id_enfant)) ? $R->id_enfant : 0;
    $enfant = $ide > 0 ? $Mysql->fetchOne("SELECT * FROM d_enfant WHERE id_enfant = ?", array($ide), 'i') : null;

    // Le droit se vérifie sur le dossier de l'enfant ; sans enfant, sur un dossier inexistant (403 ou 404 selon le profil)
    list($user) = $Contact->exigerFamille($enfant === null ? 0 : (int) $enfant->id_contact, 'C');
    $id = (int) $enfant->id_contact;
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, $Contact->specEnfant(), true);
    $modifies = $S->differences($enfant, $data);
    if (count($modifies) > 0) {
        if (array_key_exists('age', $data)) {
            // L'âge vaut à la date où il est déclaré
            $data['date_age'] = $data['age'] === null ? null : date('Y-m-d');
        }
        $SQL->begin_transaction();
        $S->mettreAJour('d_enfant', 'id_enfant', $ide, $data);
        $Contact->tracer($id, $id_users, 'enfant_update', array('id_enfant' => $ide, 'champs' => $modifies));
        $SQL->commit();
    }

    $Response->success($Contact->famille($id));
}

// DELETE ?id=N

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $ide = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $enfant = $ide > 0 ? $Mysql->fetchOne("SELECT id_enfant, id_contact FROM d_enfant WHERE id_enfant = ?", array($ide), 'i') : null;

    list($user) = $Contact->exigerFamille($enfant === null ? 0 : (int) $enfant->id_contact, 'C');
    $id = (int) $enfant->id_contact;
    $id_users = (int) $user->id_users;

    $SQL->begin_transaction();
    $Mysql->execute("DELETE FROM d_enfant WHERE id_enfant = ?", array($ide), 'i');
    $Contact->tracer($id, $id_users, 'enfant_delete', array('id_enfant' => $ide), array(
        'type' => 'enfant', 'module' => 'famille', 'objet_type' => 'enfant', 'objet_id' => $ide, 'details' => array('action' => 'suppression'),
    ));
    $SQL->commit();

    $Response->success($Contact->famille($id));
}

$Response->methodNotAllowed();

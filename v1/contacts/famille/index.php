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

// Données familiales d'un dossier (CDC §5, §6, §7) : module famille ################################

// GET ?id=N : déclarations du parent, enfants, problématiques (avec leurs notes d'évolution), notes internes

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $Contact->exigerFamille($id, 'L');

    $Response->success($Contact->famille($id));
}

// Saisie de ce que le parent a déclaré sur sa situation ################################
// PUT {id_contact, situation_familiale?, motif?, objectifs?, disponibilites?}
// Une seule déclaration « saisie_navup » par dossier ; les réponses du formulaire public (étape 6) ne se modifient pas.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user) = $Contact->exigerFamille($id, 'C');
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, $Contact->specDeclaration(), true);

    $actuelle = $Mysql->fetchOne(
        "SELECT * FROM d_declaration WHERE id_contact = ? AND source = 'saisie_navup' ORDER BY id_declaration LIMIT 1",
        array($id),
        'i'
    );

    if ($actuelle === null) {
        $renseignes = array_keys(array_filter($data, function ($v) {
            return $v !== null;
        }));
        if (count($renseignes) > 0) {
            $SQL->begin_transaction();
            $idd = $S->inserer('d_declaration', array_merge($data, array(
                'id_contact' => $id,
                'source' => 'saisie_navup',
                'date_declaration' => date('Y-m-d'),
                'id_users' => $id_users,
            )));
            $Contact->tracer($id, $id_users, 'declaration_save', array('champs' => $renseignes), array(
                'type' => 'declaration', 'module' => 'famille', 'objet_type' => 'declaration', 'objet_id' => $idd, 'details' => array('champs' => $renseignes),
            ));
            $SQL->commit();
        }
    } else {
        $modifies = $S->differences($actuelle, $data);
        if (count($modifies) > 0) {
            $data['id_users'] = $id_users;
            $SQL->begin_transaction();
            $S->mettreAJour('d_declaration', 'id_declaration', (int) $actuelle->id_declaration, $data);
            $Contact->tracer($id, $id_users, 'declaration_save', array('champs' => $modifies), array(
                'type' => 'declaration', 'module' => 'famille', 'objet_type' => 'declaration', 'objet_id' => (int) $actuelle->id_declaration, 'details' => array('champs' => $modifies),
            ));
            $SQL->commit();
        }
    }

    $Response->success($Contact->famille($id));
}

$Response->methodNotAllowed();

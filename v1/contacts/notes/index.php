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

// Notes internes NavUp (CDC §5, §7) : module famille. Ajoutées, jamais modifiées ; supprimables par leur auteur ou un admin.

// POST {id_contact, texte, id_problematique?} : avec id_problematique, la note consigne l'évolution de cette problématique

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user) = $Contact->exigerFamille($id, 'C');
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, array(
        'texte' => array('type' => 'text', 'max' => 5000, 'requis' => true, 'libelle' => 'note'),
        'id_problematique' => array('type' => 'fk', 'table' => 'd_problematique', 'col' => 'id_problematique', 'where' => 'id_contact = ?', 'where_params' => array($id), 'libelle' => 'problématique'),
    ), false);
    $data['id_contact'] = $id;
    $data['id_users'] = $id_users;

    $SQL->begin_transaction();
    $idn = $S->inserer('d_note', $data);
    $ids = array('id_note' => $idn, 'id_problematique' => $data['id_problematique'] ?? null);
    $Contact->tracer($id, $id_users, 'note_create', $ids, array(
        'type' => 'note', 'module' => 'famille', 'objet_type' => 'note', 'objet_id' => $idn, 'details' => array('id_problematique' => $ids['id_problematique']),
    ));
    $SQL->commit();

    $Response->success($Contact->famille($id), 201);
}

// DELETE ?id=N

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $idn = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $note = $idn > 0 ? $Mysql->fetchOne("SELECT id_note, id_contact, id_users FROM d_note WHERE id_note = ?", array($idn), 'i') : null;

    list($user) = $Contact->exigerFamille($note === null ? 0 : (int) $note->id_contact, 'C');
    $id = (int) $note->id_contact;
    $id_users = (int) $user->id_users;

    if ($user->profil !== 'admin' && (int) $note->id_users !== $id_users) {
        $Response->forbidden("Seul l'auteur d'une note ou un administrateur peut la supprimer.");
    }

    $SQL->begin_transaction();
    $Mysql->execute("DELETE FROM d_note WHERE id_note = ?", array($idn), 'i');
    $Contact->tracer($id, $id_users, 'note_delete', array('id_note' => $idn));
    $SQL->commit();

    $Response->success($Contact->famille($id));
}

$Response->methodNotAllowed();

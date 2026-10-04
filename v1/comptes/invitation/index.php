<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.message.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Message = new Message();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Invitation à l'espace personnel d'un parent (cahier de l'écosystème §6, §14.3 : « renvoyer l'invitation ») ########
// Après un achat en ligne, le lien part tout seul dans l'e-mail de bienvenue. Ici : le geste de l'utilisateur, pour une
// vente saisie à la main, une invitation perdue ou expirée.
// POST {id_contact, envoyer: 1} : envoie au parent l'e-mail qui porte le lien (« invitation », ou « mot de passe
//     oublié » s'il a déjà un mot de passe) → {envoye, compte, …}. Le lien est composé au moment de l'envoi.
// POST {id_contact} : crée un lien → {lien, compte, …}. L'adresse n'est donnée que cette fois : seule l'empreinte de
//     son jeton est gardée. Pour le transmettre autrement que par e-mail.
// Un nouveau lien annule les précédents du compte.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list($user, $contact) = $Contact->exigerDossier($idc, 'C');
    $envoyer = isset($R->envoyer) && $R->envoyer === 1;

    $compte = Compte::charger($idc);
    if ($compte === null) {
        $Response->validationError("Ce dossier n'a pas de compte : il s'ouvre au premier paiement.");
    }
    if (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
        $Response->validationError("L'appli des parents n'a pas encore d'adresse sur ce serveur : il n'y a rien à ouvrir.");
    }
    if ($contact->email === null) {
        $Response->validationError("Ce dossier n'a pas d'adresse e-mail : c'est l'identifiant de l'espace personnel.");
    }

    $reponse = array();
    if ($envoyer) {
        $idm = Compte::inviter($contact, (int) $user->id_users);
        if ($idm === null) {
            $Response->validationError("Un e-mail vient déjà de partir pour ce dossier : patientez quelques minutes avant d'en renvoyer un.");
        }
        if (!$Message->envoyer($idm)) {
            $Response->validationError("L'e-mail n'a pas pu partir : il reste dans la file, à relancer depuis l'onglet « Connexions ».");
        }
        $reponse['envoye'] = true;
    } else {
        $SQL->begin_transaction();
        $reponse['lien'] = Compte::lienAcces($compte->id_compte, Compte::aMotDePasse($compte) ? 'reinitialisation' : 'creation', (int) $user->id_users);
        $Contact->tracer($idc, (int) $user->id_users, 'compte_lien', array('id_compte' => (int) $compte->id_compte), array(
            'type' => 'compte', 'module' => 'dossier', 'objet_type' => 'compte', 'objet_id' => (int) $compte->id_compte,
            'details' => array('action' => 'lien'), 'origine' => 'utilisateur',
        ));
        $SQL->commit();
    }

    $Response->success($reponse + array(
        'compte' => Compte::sortie(Compte::charger($idc)),
        'consentements' => Compte::consentements($idc),
        'contact' => $Contact->sortie($Contact->charger($idc)),
    ), 201);
}

$Response->methodNotAllowed();

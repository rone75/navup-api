<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.message.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Modèles d'e-mails aux parents (CDC §13, §21 ; étape 7b) ################################
// GET → {modeles: [{code, libelle, module, version, modifie_le, modifie_par}]}
// GET ?code= → {modele: {code, libelle, actif {version, objet, corps}, origine {objet, corps}, marques: [{nom, sorte, description}],
//               versions: [{version, objet, active, date, auteur}]}}
// POST {code, objet, corps} → {objet, corps, raisons} : l'aperçu sur un parent fictif ; `raisons` dit ce qui empêcherait
//      d'enregistrer. Aucun dossier n'est lu.
// PUT {code, objet, corps} → enregistre une nouvelle version, active aussitôt (refusée avec ses raisons sinon).
// PUT {code, version} → remet en service une version de l'historique ; version 0 : le texte d'origine.
// Un message déjà déposé garde le texte de sa version. Administrateur seulement ; chaque changement au journal d'audit.

function modeleDemande($code)
{
    global $Response;

    if (!is_string($code) || !isset(Modeles::ORIGINES[$code])) {
        $Response->notFound("Modèle introuvable.");
    }

    return $code;
}

function fiche($code)
{
    global $Mysql;

    $versions = array();
    foreach ($Mysql->fetchAll(
        "SELECT m.version, m.objet, m.active, m.date_creation, u.prenom, u.nom, u.identifiant
         FROM m_modele m LEFT JOIN u_users u ON u.id_users = m.id_users WHERE m.code = ? ORDER BY m.version DESC",
        array($code),
        's'
    ) as $v) {
        $auteur = trim((string) $v->prenom . ' ' . (string) $v->nom);
        $versions[] = array('version' => (int) $v->version, 'objet' => $v->objet, 'active' => (int) $v->active === 1, 'date' => $v->date_creation,
            'auteur' => $auteur !== '' ? $auteur : $v->identifiant);
    }
    $marques = array();
    foreach (Modeles::marques($code) as $nom => $def) {
        $marques[] = array('nom' => $nom, 'sorte' => $def[0], 'description' => $def[1]);
    }

    return array(
        'code' => $code,
        'libelle' => Message::MODELES[$code]['libelle'],
        'actif' => Modeles::actif($code),
        'origine' => Modeles::ORIGINES[$code],
        'marques' => $marques,
        'versions' => $versions,
    );
}

/** Aperçu d'un objet et d'un corps sur l'exemple fictif du modèle. */
function apercu($code, $objet, $corps)
{
    list($contact, $d) = Modeles::exemple($code);
    list($o, $c) = Modeles::rendre(array('objet' => $objet, 'corps' => $corps), Modeles::valeurs($code, $contact, $d));

    // Les liens personnels ne sont jamais composés pour un aperçu : ils se lisent en clair
    return array(
        'objet' => $o,
        'corps' => str_replace(
            array(Message::MARQUE_LIEN, Message::MARQUE_ACCES, Message::MARQUE_RDV),
            array('(lien de paiement personnel)', "(lien d'accès personnel)", '(lien personnel de gestion du rendez-vous)'),
            $c
        ),
    );
}

function lireTexte($R)
{
    global $Response;

    $objet = (is_object($R) && isset($R->objet) && is_string($R->objet)) ? trim($R->objet) : '';
    $corps = (is_object($R) && isset($R->corps) && is_string($R->corps)) ? rtrim(str_replace("\r\n", "\n", $R->corps)) : '';
    if (mb_strlen($objet) > Modeles::OBJET_MAX * 2 || mb_strlen($corps) > Modeles::CORPS_MAX * 2) {
        $Response->validationError("Texte trop long.");
    }

    return array($objet, $corps);
}

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireAccess('parametres', 'C');

    if (isset($_GET['code'])) {
        $Response->success(array('modele' => fiche(modeleDemande($_GET['code']))));
    }

    $out = array();
    foreach (Modeles::modifiables() as $code) {
        $actif = $Mysql->fetchOne(
            "SELECT m.version, m.date_creation, u.prenom, u.nom, u.identifiant FROM m_modele m LEFT JOIN u_users u ON u.id_users = m.id_users
             WHERE m.code = ? AND m.active = 1",
            array($code),
            's'
        );
        $out[] = array(
            'code' => $code,
            'libelle' => Message::MODELES[$code]['libelle'],
            'module' => Message::MODELES[$code]['module'],
            'version' => $actif === null ? 0 : (int) $actif->version,
            'modifie_le' => $actif === null ? null : $actif->date_creation,
            'modifie_par' => $actif === null ? null : (trim((string) $actif->prenom . ' ' . (string) $actif->nom) ?: $actif->identifiant),
        );
    }
    $Response->success(array('modeles' => $out));
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $U->requireAccess('parametres', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $code = modeleDemande(is_object($R) && isset($R->code) ? $R->code : null);
    list($objet, $corps) = lireTexte($R);

    $Response->success(apercu($code, $objet, $corps) + array('raisons' => Modeles::controler($code, $objet, $corps)));
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('parametres', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $code = modeleDemande(is_object($R) && isset($R->code) ? $R->code : null);

    $verrou = $Mysql->fetchOne("SELECT GET_LOCK(?, 5) AS pris", array('navup_modele_' . $code), 's');
    if ($verrou === null || (int) $verrou->pris !== 1) {
        $Response->validationError("Ce modèle est en cours d'enregistrement : réessayez dans un instant.");
    }

    // Remettre en service une version de l'historique, ou le texte d'origine (0)
    if (isset($R->version)) {
        if (!is_int($R->version) || $R->version < 0) {
            $Response->validationError("Erreur paramètre VERSION");
        }
        if ($R->version > 0 && $Mysql->fetchOne("SELECT 1 AS x FROM m_modele WHERE code = ? AND version = ?", array($code, $R->version), 'si') === null) {
            $Response->notFound("Version introuvable.");
        }
        $SQL->begin_transaction();
        $Mysql->execute("UPDATE m_modele SET active = 0 WHERE code = ?", array($code), 's');
        if ($R->version > 0) {
            $Mysql->execute("UPDATE m_modele SET active = 1 WHERE code = ? AND version = ?", array($code, $R->version), 'si');
        }
        $U->audit((int) $user->id_users, 'modele_email_retablir', array('code' => $code, 'version' => $R->version));
        $SQL->commit();
        $Mysql->fetchOne("SELECT RELEASE_LOCK(?) AS r", array('navup_modele_' . $code), 's');

        $Response->success(array('modele' => fiche($code)));
    }

    list($objet, $corps) = lireTexte($R);
    $raisons = Modeles::controler($code, $objet, $corps);
    if (count($raisons) > 0) {
        $Response->validationError(implode(' ', $raisons), array('raisons' => $raisons));
    }
    $actif = Modeles::actif($code);
    if ($actif['objet'] === $objet && rtrim($actif['corps']) === $corps) {
        $Response->validationError("Le texte n'a pas changé.");
    }

    $SQL->begin_transaction();
    $version = (int) $Mysql->fetchOne("SELECT COALESCE(MAX(version), 0) + 1 AS v FROM m_modele WHERE code = ?", array($code), 's')->v;
    $Mysql->execute("UPDATE m_modele SET active = 0 WHERE code = ?", array($code), 's');
    $Mysql->execute(
        "INSERT INTO m_modele (code, version, objet, corps, active, id_users) VALUES (?, ?, ?, ?, 1, ?)",
        array($code, $version, $objet, $corps, (int) $user->id_users),
        'sissi'
    );
    $U->audit((int) $user->id_users, 'modele_email_update', array('code' => $code, 'version' => $version));
    $SQL->commit();
    $Mysql->fetchOne("SELECT RELEASE_LOCK(?) AS r", array('navup_modele_' . $code), 's');

    $Response->success(array('modele' => fiche($code)));
}

$Response->methodNotAllowed();

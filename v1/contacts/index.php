<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.suivi.php";
include "../../require/param.php";

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

// Dossiers : prospects et clients (CDC §4, §5, §16). Identité et suivi uniquement : aucune donnée familiale ici.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    // Fiche ################################
    // GET ?id=N : identité, suivi et dernier événement lisible de la chronologie

    if (isset($_GET['id'])) {
        $id = (is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
        list($user, $contact) = $Contact->exigerDossier($id, 'L');

        list(, $derniers) = $Contact->chronologie($id, $Contact->modulesLisibles($user), 1, 0, $U->can($user, 'famille', 'L'));

        $Response->success(array(
            'contact' => $Contact->sortie($contact),
            'dernier_evenement' => count($derniers) > 0 ? $derniers[0] : null,
        ));
    }

    // Liste ################################
    // GET ?groupe=prospects|clients&q=&statut=a,b&origine=&programme=en_cours|fin_proche|termine&du=&au=&action=retard|prevue&categorie=&archive=1&sort=&dir=&page=&limit=
    // La prochaine action d'un dossier est l'échéance de sa tâche ouverte la plus proche : action=retard retient
    // les dossiers dont une tâche est due aujourd'hui ou en retard, action=prevue ceux qui ont une tâche ouverte.

    $user = $U->requireUser();

    $groupe = (isset($_GET['groupe']) && is_string($_GET['groupe']) && isset(Contact::GROUPES[$_GET['groupe']])) ? $_GET['groupe'] : null;
    if ($groupe === null) {
        $Response->validationError("Erreur paramètre GROUPE manquant (prospects ou clients)");
    }
    if (!$U->can($user, $groupe, 'L')) {
        $Response->forbidden("Vous n'avez pas accès à ce module.");
    }

    $where = array();
    $params = array();

    $statuts = Contact::GROUPES[$groupe];
    if (isset($_GET['statut']) && is_string($_GET['statut']) && $_GET['statut'] !== '') {
        $statuts = array_values(array_intersect(explode(',', $_GET['statut']), $statuts));
        if (count($statuts) === 0) {
            $Response->validationError("Statut inconnu pour cette rubrique.");
        }
    }
    $where[] = "c.statut IN (" . implode(', ', array_fill(0, count($statuts), '?')) . ")";
    $params = array_merge($params, $statuts);

    // Les dossiers classés sans suite ne sortent que sur demande
    $where[] = (isset($_GET['archive']) && $_GET['archive'] === '1') ? "c.date_archivage IS NOT NULL" : "c.date_archivage IS NULL";

    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $cond = $Contact->conditionRecherche($_GET['q']);
        if ($cond === null) {
            $Response->validationError("Saisissez entre 2 et 80 caractères pour la recherche.");
        }
        $where[] = $cond[0];
        $params = array_merge($params, $cond[1]);
    }

    if (isset($_GET['origine']) && is_string($_GET['origine']) && preg_match('/^[a-z0-9_]{1,30}$/', $_GET['origine'])) {
        $where[] = "c.code_origine = ?";
        $params[] = $_GET['origine'];
    }

    // Programme du compte NavUp Academy : en cours, fin proche, terminé. La condition est celle du chiffre du tableau de bord.
    if (isset($_GET['programme']) && is_string($_GET['programme']) && $_GET['programme'] !== '') {
        $cond = Compte::conditionVue($_GET['programme'], date('Y-m-d'));
        if ($cond === null) {
            $Response->validationError("Vue de programme inconnue.");
        }
        $where[] = $cond[0];
        $params = array_merge($params, $cond[1]);
    }

    // Période sur le premier contact (à défaut, la création du dossier)
    foreach (array('du' => '>=', 'au' => '<=') as $cle => $op) {
        if (isset($_GET[$cle]) && is_string($_GET[$cle]) && $_GET[$cle] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $_GET[$cle]);
            if ($d === false || $d->format('Y-m-d') !== $_GET[$cle]) {
                $Response->validationError("Date invalide (format AAAA-MM-JJ) : $cle");
            }
            $where[] = Contact::SQL_ARRIVEE . " $op ?";
            $params[] = $_GET[$cle];
        }
    }

    // Les tâches automatiques (échéance en retard, rendez-vous à confirmer…) sont remises à jour avant de filtrer,
    // au plus toutes les cinq minutes
    $Tache->synchroniserSiBesoin();

    // Le jour vient de PHP, comme partout ailleurs
    if (isset($_GET['action']) && $_GET['action'] === 'retard') {
        $where[] = "EXISTS (SELECT 1 FROM t_tache tr WHERE tr.id_contact = c.id_contact AND tr.date_cloture IS NULL AND tr.date_echeance <= ?)";
        $params[] = date('Y-m-d');
    } elseif (isset($_GET['action']) && $_GET['action'] === 'prevue') {
        $where[] = "EXISTS (SELECT 1 FROM t_tache tr WHERE tr.id_contact = c.id_contact AND tr.date_cloture IS NULL)";
    }

    // Filtrer par problématique révèle une donnée familiale : refus net sans le droit famille (jamais un filtre ignoré en silence)
    if (isset($_GET['categorie']) && is_string($_GET['categorie']) && $_GET['categorie'] !== '') {
        if (!$U->can($user, 'famille', 'L')) {
            $Response->forbidden("Vous n'avez pas accès aux données familiales.");
        }
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $_GET['categorie'])) {
            $Response->validationError("Catégorie invalide.");
        }
        $where[] = "EXISTS (SELECT 1 FROM d_problematique p WHERE p.id_contact = c.id_contact AND p.code_categorie = ? AND p.statut <> 'close')";
        $params[] = $_GET['categorie'];
    }

    $sqlWhere = " WHERE " . implode(" AND ", $where);
    $tri = $S->tri(
        array(
            'nom' => 'c.nom',
            'creation' => 'c.date_creation',
            'premier_contact' => array('c.date_premier_contact', true),
            'action' => array(Contact::SQL_PROCHAINE_ACTION, true),
            'statut' => 'c.date_statut',
        ),
        'creation',
        'c.prenom, c.id_contact'
    );
    list($page, $limit, $offset) = $S->pagination();

    $total = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM d_contact c" . $sqlWhere, $params)->nb;
    $rows = $Mysql->fetchAll(
        "SELECT " . Contact::COLONNES . Contact::SQL_FROM . $sqlWhere . " ORDER BY $tri LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset))
    );

    $contacts = array();
    foreach ($rows as $row) {
        $contacts[] = $Contact->sortie($row);
    }

    $Response->success(array('contacts' => $contacts, 'total' => $total, 'page' => $page, 'limit' => $limit));
}

// Création ################################
// POST {nom, prenom?, email?, telephone?, statut?, code_origine?, origine_precision?, date_premier_contact?, date_inscription?}
// Un e-mail déjà connu est refusé ; un téléphone déjà connu est signalé dans « doublons » sans bloquer.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireUser();
    $id_users = (int) $user->id_users;
    $R = json_decode(file_get_contents("php://input"));

    $statut = 'prospect';
    if (is_object($R) && isset($R->statut)) {
        if (!is_string($R->statut) || !in_array($R->statut, Contact::STATUTS, true)) {
            $Response->validationError("Statut invalide.");
        }
        $statut = $R->statut;
    }
    if (!$U->can($user, Contact::groupeDe($statut), 'C')) {
        $Response->forbidden("Vous n'avez pas les droits nécessaires pour créer ce dossier.");
    }

    $data = $S->lireChamps($R, $Contact->specContact(), false);

    if (empty($data['email']) && empty($data['telephone'])) {
        $Response->validationError("Renseignez au moins un e-mail ou un téléphone.");
    }
    if (!empty($data['email'])) {
        $S->verifierUnique('d_contact', 'email', $data['email'], "Un dossier existe déjà avec cette adresse e-mail.");
    }
    // Autres dossiers au même téléphone, parmi ceux que l'utilisateur peut lire
    $doublons = array();
    if (!empty($data['telephone'])) {
        $lisibles = array();
        foreach (Contact::GROUPES as $g => $liste) {
            if ($U->can($user, $g, 'L')) {
                $lisibles = array_merge($lisibles, $liste);
            }
        }
        foreach ($Mysql->fetchAll(
            "SELECT " . Contact::COLONNES . Contact::SQL_FROM . " WHERE c.telephone = ? AND c.statut IN (" . implode(', ', array_fill(0, count($lisibles), '?')) . ") ORDER BY c.id_contact LIMIT 5",
            array_merge(array($data['telephone']), $lisibles)
        ) as $row) {
            $doublons[] = $Contact->sortie($row);
        }
    }

    $SQL->begin_transaction();
    $id = $Contact->creer($data, $statut, $id_users);
    $SQL->commit();

    $Response->success(array(
        'contact' => $Contact->sortie($Contact->charger($id)),
        'doublons' => $doublons,
    ), 201);
}

// Modification de l'identité et du suivi ################################
// PUT {id_contact, …champs à modifier}. Le statut se change par v1/contacts/statut/.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;

    list($user, $contact) = $Contact->exigerDossier($id, 'C');
    $id_users = (int) $user->id_users;

    $data = $S->lireChamps($R, $Contact->specContact(), true);

    $email = array_key_exists('email', $data) ? $data['email'] : $contact->email;
    $telephone = array_key_exists('telephone', $data) ? $data['telephone'] : $contact->telephone;
    if (empty($email) && empty($telephone)) {
        $Response->validationError("Un dossier garde au moins un e-mail ou un téléphone.");
    }
    if (!empty($data['email']) && $data['email'] !== $contact->email) {
        $S->verifierUnique('d_contact', 'email', $data['email'], "Un dossier existe déjà avec cette adresse e-mail.", 'id_contact', $id);
    }

    $modifies = $S->differences($contact, $data);
    if (count($modifies) > 0) {
        $SQL->begin_transaction();
        $S->mettreAJour('d_contact', 'id_contact', $id, $data);
        $Contact->tracer($id, $id_users, 'contact_update', array('champs' => $modifies), array('type' => 'modification', 'details' => array('champs' => $modifies)));
        // L'adresse d'un dossier est l'identifiant de son espace personnel : si elle change, ce qui a été créé avec
        // l'ancienne (mot de passe, sessions, liens) ne doit plus ouvrir le programme. Une invitation se renvoie depuis la
        // fiche ; le parent peut aussi demander un lien depuis la page de connexion.
        if (in_array('email', $modifies, true)) {
            Compte::revoquer($id, $id_users, 'automatique');
            // Les liens de gestion de ses rendez-vous sont partis à l'ancienne adresse : ils ne valent plus
            (new Rdv())->revoquerLiens($id);
        }
        $SQL->commit();
    }

    $Response->success(array('contact' => $Contact->sortie($Contact->charger($id))));
}

$Response->methodNotAllowed();

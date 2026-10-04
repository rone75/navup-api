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
$Rdv = new Rdv();
$Interaction = new Interaction();
$Tache = new Tache();
$Suivi = new Suivi();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Tâches, rappels et alertes (CDC §14). Une tâche de suivi porte une note interne : elle ne se lit qu'avec le droit
// famille ; le profil de gestion ne voit que les tâches de gestion (paiements).

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    // Liste ################################
    // GET ?etat=ouvertes|traitees&nature=tache|appel&categorie=suivi|gestion&a=moi&id_contact=&du=&au=&q=&page=&limit=
    // ouvertes (défaut) : de la plus en retard à la plus lointaine ; traitees : de la plus récemment fermée à la plus ancienne.
    // du / au portent sur l'échéance. `compteurs` : tâches ouvertes de la même sélection (hors période), par situation.
    // `aujourdhui` et la situation de chaque tâche viennent du serveur.

    $user = $U->requireAccess('taches', 'L');

    // Les tâches automatiques sont remises à jour avant de lister, au plus toutes les cinq minutes
    $Tache->synchroniserSiBesoin();

    $aujourdhui = date('Y-m-d');
    $where = array();
    $params = array();

    $lisibles = $Tache->conditionLisibles($user);
    $where[] = $lisibles[0];
    $params = array_merge($params, $lisibles[1]);

    if (isset($_GET['nature']) && in_array($_GET['nature'], Tache::NATURES, true)) {
        $where[] = "t.nature = ?";
        $params[] = $_GET['nature'];
    }
    if (isset($_GET['categorie']) && in_array($_GET['categorie'], Tache::CATEGORIES, true)) {
        $where[] = "t.categorie = ?";
        $params[] = $_GET['categorie'];
    }
    if (isset($_GET['a']) && $_GET['a'] === 'moi') {
        $where[] = "t.id_users_assigne = ?";
        $params[] = (int) $user->id_users;
    }
    if (isset($_GET['id_contact']) && is_string($_GET['id_contact']) && ctype_digit($_GET['id_contact'])) {
        $where[] = "t.id_contact = ?";
        $params[] = (int) $_GET['id_contact'];
    }
    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $cond = $Contact->conditionRecherche($_GET['q']);
        if ($cond === null) {
            $Response->validationError("Saisissez entre 2 et 80 caractères pour la recherche.");
        }
        $where[] = $cond[0];
        $params = array_merge($params, $cond[1]);
    }

    // Compteurs : les tâches ouvertes de la sélection, quelles que soient la vue et la période
    $base = implode(" AND ", $where);
    $fin = Suivi::finSemaine($aujourdhui);
    $c = $Mysql->fetchOne(
        "SELECT COUNT(*) AS ouvertes,
                COALESCE(SUM(t.date_echeance < ?), 0) AS retard,
                COALESCE(SUM(t.date_echeance = ?), 0) AS aujourdhui,
                COALESCE(SUM(t.date_echeance > ? AND t.date_echeance <= ?), 0) AS semaine,
                COALESCE(SUM(t.date_echeance > ?), 0) AS plus_tard
         FROM t_tache t LEFT JOIN d_contact c ON c.id_contact = t.id_contact
         WHERE $base AND t.date_cloture IS NULL",
        array_merge(array($aujourdhui, $aujourdhui, $aujourdhui, $fin, $fin), $params)
    );

    $traitees = isset($_GET['etat']) && $_GET['etat'] === 'traitees';
    $where[] = $traitees ? "t.date_cloture IS NOT NULL" : "t.date_cloture IS NULL";
    foreach (array('du' => '>=', 'au' => '<=') as $cle => $op) {
        $d = $S->dateFiltre($cle);
        if ($d !== null) {
            $where[] = "t.date_echeance $op ?";
            $params[] = $d;
        }
    }

    $sqlWhere = " WHERE " . implode(" AND ", $where);
    $ordre = $traitees ? "t.date_cloture DESC, t.id_tache DESC" : "t.date_echeance, t.id_tache";
    list($page, $limit, $offset) = $S->pagination();

    $total = (int) $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM t_tache t LEFT JOIN d_contact c ON c.id_contact = t.id_contact" . $sqlWhere, $params)->nb;
    $taches = array();
    foreach ($Mysql->fetchAll(
        "SELECT " . Tache::COLONNES . Tache::SQL_FROM . $sqlWhere . " ORDER BY $ordre LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset))
    ) as $row) {
        $taches[] = $Tache->sortie($row, true, $aujourdhui);
    }

    $Response->success(array(
        'taches' => $taches,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'aujourdhui' => $aujourdhui,
        'compteurs' => array(
            'ouvertes' => (int) $c->ouvertes,
            'retard' => (int) $c->retard,
            'aujourdhui' => (int) $c->aujourdhui,
            'semaine' => (int) $c->semaine,
            'plus_tard' => (int) $c->plus_tard,
        ),
    ));
}

// Créer une tâche ################################
// POST {titre, date_echeance, id_contact?, nature?, categorie?, id_users_assigne?, cle_saisie?}
// Avec ou sans dossier. Catégorie « suivi » (défaut avec le droit famille) ou « gestion » ; sans le droit famille,
// une tâche est de gestion. Attribuée à son auteur si personne n'est indiqué.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('taches', 'C');
    $R = json_decode(file_get_contents("php://input"));

    $contact = null;
    if (is_object($R) && isset($R->id_contact)) {
        list(, $contact) = $Contact->exigerDossier(is_int($R->id_contact) ? $R->id_contact : 0, 'L');
    }

    $data = $S->lireChamps($R, $Tache->spec(), false);
    $famille = $U->can($user, 'famille', 'C');
    if (!isset($data['categorie'])) {
        $data['categorie'] = $famille ? 'suivi' : 'gestion';
    }
    if ($data['categorie'] === 'suivi' && !$famille) {
        $Response->forbidden("Sans accès aux données familiales, vous ne créez que des tâches de gestion.");
    }
    $Tache->exigerAttribuable($data['categorie'], $data['id_users_assigne'] ?? null);
    $cle = $S->lireCle($R);

    $res = $Tache->creer($contact, $data, $cle, (int) $user->id_users);

    $rep = $Tache->reponse($res['id_tache'], $contact === null ? null : (int) $contact->id_contact, $user);
    $rep['id_tache'] = $res['id_tache'];
    $Response->success($rep, $res['deja'] ? 200 : 201);
}

// Corriger une tâche manuelle ################################
// PUT {id_tache, titre?, nature?, categorie?}. L'échéance se change par v1/taches/report/, la personne par v1/taches/attribution/.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_tache) && is_int($R->id_tache)) ? $R->id_tache : 0;
    list($user, $tache) = $Tache->exigerTache($id, 'C');

    $spec = array_intersect_key($Tache->spec(), array_flip(array('titre', 'nature', 'categorie')));
    $data = $S->lireChamps($R, $spec, true);
    foreach ($data as $champ => $valeur) {
        if ($valeur === null) {
            $Response->validationError("Le champ « " . ($spec[$champ]['libelle'] ?? $champ) . " » est obligatoire.");
        }
    }
    if (isset($data['categorie']) && $data['categorie'] !== $tache->categorie) {
        if ($data['categorie'] === 'suivi' && !$U->can($user, 'famille', 'C')) {
            $Response->forbidden("Sans accès aux données familiales, vous ne créez que des tâches de gestion.");
        }
        $Tache->exigerAttribuable($data['categorie'], $tache->id_users_assigne === null ? null : (int) $tache->id_users_assigne);
    }

    $Tache->modifier($tache, $data, (int) $user->id_users);

    $Response->success($Tache->reponse($id, $tache->id_contact === null ? null : (int) $tache->id_contact, $user));
}

// Supprimer une tâche manuelle créée par erreur ################################
// DELETE ?id=N : tâche ouverte, par son auteur ou un administrateur. Une tâche faite se marque traitée, elle ne se supprime pas.

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    list($user, $tache) = $Tache->exigerTache($id, 'C');

    if ($user->profil !== 'admin' && (int) $tache->id_users !== (int) $user->id_users) {
        $Response->forbidden("Seul l'auteur d'une tâche ou un administrateur peut la supprimer.");
    }

    $Tache->supprimer($tache, (int) $user->id_users);

    $Response->success($Tache->reponse(null, $tache->id_contact === null ? null : (int) $tache->id_contact, $user));
}

$Response->methodNotAllowed();

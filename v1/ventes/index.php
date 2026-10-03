<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.vente.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Ventes (CDC §9). Montants en centimes. Du dossier, seule l'identité est servie : aucune donnée familiale ici.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    // Fiche ################################
    // GET ?id=N : la vente, son échéancier, ses écritures (droit paiements) et son historique

    if (isset($_GET['id'])) {
        $id = (is_string($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
        list($user, $vente) = $Vente->exigerVente($id, 'ventes', 'L');

        $out = $Vente->sortie($vente);
        $out['echeances'] = $Vente->echeances($id);
        if ($U->can($user, 'paiements', 'L')) {
            $out['paiements'] = $Vente->paiements($id);
        }
        $out['historique'] = $Vente->historique($id);

        $Response->success(array('vente' => $out));
    }

    // Ventes d'un dossier ################################
    // GET ?id_contact=N : bloc « ventes » de la fiche 360°

    if (isset($_GET['id_contact'])) {
        $idc = (is_string($_GET['id_contact']) && ctype_digit($_GET['id_contact'])) ? (int) $_GET['id_contact'] : 0;
        $user = $U->requireAccess('ventes', 'L');
        $Contact->exigerDossier($idc, 'L');

        $Response->success(array('ventes' => $Vente->blocDossier($idc, $user)));
    }

    // Liste ################################
    // GET ?q=&statut=a,b&en_cours=1&modalite=&moyen=&origine=&du=&au=&retard=1&sort=date|montant|nom|reste&dir=&page=&limit=
    // en_cours=1 : ventes non annulées auxquelles il reste quelque chose à encaisser.
    // `totaux` porte sur toute la sélection (pas seulement la page), avec les mêmes conditions que les lignes.

    $user = $U->requireAccess('ventes', 'L');

    $where = array();
    $params = array();

    $lisibles = $Vente->conditionDossiersLisibles($user);
    if ($lisibles === null) {
        $Response->forbidden("Vous n'avez pas accès aux dossiers.");
    }
    $where[] = $lisibles[0];
    $params = array_merge($params, $lisibles[1]);

    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $cond = $Vente->conditionRecherche($_GET['q']);
        if ($cond === null) {
            $Response->validationError("Saisissez entre 2 et 80 caractères pour la recherche.");
        }
        $where[] = $cond[0];
        $params = array_merge($params, $cond[1]);
    }

    if (isset($_GET['statut']) && is_string($_GET['statut']) && $_GET['statut'] !== '') {
        $statuts = array_values(array_intersect(explode(',', $_GET['statut']), Vente::STATUTS));
        if (count($statuts) === 0) {
            $Response->validationError("Statut de vente inconnu.");
        }
        $where[] = "v.statut IN (" . implode(', ', array_fill(0, count($statuts), '?')) . ")";
        $params = array_merge($params, $statuts);
    }

    if (isset($_GET['en_cours']) && $_GET['en_cours'] === '1') {
        $where[] = Vente::SQL_EN_COURS;
    }

    if (isset($_GET['modalite']) && in_array($_GET['modalite'], array('comptant', 'fractionne'), true)) {
        $where[] = "v.modalite = ?";
        $params[] = $_GET['modalite'];
    }

    foreach (array('moyen' => 'v.code_moyen', 'origine' => 'c.code_origine') as $cle => $col) {
        if (isset($_GET[$cle]) && is_string($_GET[$cle]) && preg_match('/^[a-z0-9_]{1,30}$/', $_GET[$cle])) {
            $where[] = "$col = ?";
            $params[] = $_GET[$cle];
        }
    }

    // Période sur la date de la vente
    foreach (array('du' => '>=', 'au' => '<=') as $cle => $op) {
        $d = $Vente->dateFiltre($cle);
        if ($d !== null) {
            $where[] = "v.date_vente $op ?";
            $params[] = $d;
        }
    }

    // Ventes en cours dont une échéance est en retard (le jour vient de PHP, comme partout ailleurs)
    if (isset($_GET['retard']) && $_GET['retard'] === '1') {
        $where[] = "v.date_annulation IS NULL AND EXISTS (SELECT 1 FROM v_echeance er WHERE er.id_vente = v.id_vente
            AND er.date_annulation IS NULL AND er.montant_paye < er.montant AND er.date_prevue < ?)";
        $params[] = date('Y-m-d');
    }

    $sqlWhere = " WHERE " . implode(" AND ", $where);
    $tri = $S->tri(
        array(
            'date' => 'v.date_vente',
            'montant' => 'v.montant',
            'nom' => 'c.nom',
            'reste' => '(' . Vente::SQL_RESTE . ')',
        ),
        'date',
        'v.id_vente DESC'
    );
    list($page, $limit, $offset) = $S->pagination();

    $t = $Mysql->fetchOne(
        "SELECT COUNT(*) AS nb,
                COALESCE(SUM(" . Vente::SQL_VENDU . "), 0) AS vendu,
                COALESCE(SUM(COALESCE(s.encaisse, 0)), 0) AS encaisse,
                COALESCE(SUM(COALESCE(s.rembourse, 0)), 0) AS rembourse,
                COALESCE(SUM(" . Vente::SQL_RESTE . "), 0) AS reste_du"
        . Vente::SQL_FROM . $sqlWhere,
        $params
    );
    $rows = $Mysql->fetchAll(
        "SELECT " . Vente::COLONNES . Vente::SQL_FROM . $sqlWhere . " ORDER BY $tri LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset))
    );

    $ventes = array();
    foreach ($rows as $row) {
        $ventes[] = $Vente->sortie($row);
    }

    $Response->success(array(
        'ventes' => $ventes,
        'total' => (int) $t->nb,
        'page' => $page,
        'limit' => $limit,
        'totaux' => array(
            'vendu' => (int) $t->vendu,
            'encaisse' => (int) $t->encaisse,
            'rembourse' => (int) $t->rembourse,
            'reste_du' => (int) $t->reste_du,
        ),
    ));
}

// Enregistrement d'une vente ################################
// POST {id_contact, code_offre, date_vente, remise?, motif_remise?, code_moyen?, commentaire?,
//       echeances: [{date_prevue, montant}], encaissement?: {date_paiement, code_moyen, reference?}, cle_saisie?}
// Le prix vient de l'offre ; la somme des échéances (1 à 4) doit être égale au total.
// `encaissement` encaisse tout de suite la première échéance. `cle_saisie` : un double envoi n'écrit qu'une fois.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('ventes', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $idc = (is_object($R) && isset($R->id_contact) && is_int($R->id_contact)) ? $R->id_contact : 0;
    list(, $contact) = $Contact->exigerDossier($idc, 'L');

    $data = $S->lireChamps($R, $Vente->specVente(), false);
    $echeances = $Vente->lireEcheances(isset($R->echeances) ? $R->echeances : null, 1, 4);

    $encaissement = null;
    if (isset($R->encaissement) && $R->encaissement !== null) {
        if (!$U->can($user, 'paiements', 'C')) {
            $Response->forbidden("Vous n'avez pas les droits nécessaires pour enregistrer un paiement.");
        }
        $encaissement = $S->lireChamps($R->encaissement, array(
            'date_paiement' => array('type' => 'date', 'requis' => true, 'max' => date('Y-m-d'), 'libelle' => 'date du paiement'),
            'code_moyen' => array('type' => 'fk', 'cast' => 'str', 'table' => 'p_moyen_paiement', 'col' => 'code', 'where' => 'actif = 1', 'requis' => true, 'libelle' => 'moyen de paiement'),
            'reference' => array('type' => 'str', 'max' => 100, 'defaut' => null, 'libelle' => 'référence'),
        ), false);
    }
    $cle = $Vente->lireCle($R);

    $res = $Vente->creer($contact, $data, $echeances, $encaissement, $cle, (int) $user->id_users);

    $Response->success(array(
        'id_vente' => $res['id_vente'],
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc), $U->can($user, 'famille', 'L')),
        'avertissements' => $res['avertissements'],
    ), $res['deja'] ? 200 : 201);
}

// Correction des champs descriptifs ################################
// PUT {id_vente, date_vente?, code_moyen?, commentaire?}. Le total et les échéances se révisent par v1/ventes/echeancier/.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_vente) && is_int($R->id_vente)) ? $R->id_vente : 0;
    list($user, $vente) = $Vente->exigerVente($id, 'ventes', 'C');

    $spec = array_intersect_key($Vente->specVente(), array_flip(array('date_vente', 'code_moyen', 'commentaire')));
    $data = $S->lireChamps($R, $spec, true);

    $Vente->modifier($vente, $data, (int) $user->id_users);

    $idc = (int) $vente->id_contact;
    $Response->success(array(
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc), $U->can($user, 'famille', 'L')),
        'avertissements' => array(),
    ));
}

$Response->methodNotAllowed();

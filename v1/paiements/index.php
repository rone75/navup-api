<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.xlsx.php";
include "../../include/package.export.php";
include "../../include/package.user.php";
include "../../include/package.saisie.php";
include "../../include/package.contact.php";
include "../../include/package.vente.php";
include "../../include/package.suivi.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();
$Tache = new Tache();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Paiements (CDC §10) : le grand livre des écritures. Montants en centimes.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    // Journal ################################
    // GET ?type=a,b&moyen=&du=&au=&q=&id_vente=&annulees=1&sort=date|montant|nom&dir=&page=&limit=
    // `totaux` : encaissé (encaissements moins impayés), remboursé, frais connus, net (encaissé moins frais),
    // sur les écritures valides de toute la sélection. Les écritures annulées ne sortent qu'avec annulees=1
    // et ne comptent jamais.

    $user = $U->requireAccess('paiements', 'L');

    $where = array();
    $params = array();

    $lisibles = $Vente->conditionDossiersLisibles($user);
    if ($lisibles === null) {
        $Response->forbidden("Vous n'avez pas accès aux dossiers.");
    }
    $where[] = $lisibles[0];
    $params = array_merge($params, $lisibles[1]);

    if (!(isset($_GET['annulees']) && $_GET['annulees'] === '1')) {
        $where[] = "p.date_annulation IS NULL";
    }

    if (isset($_GET['type']) && is_string($_GET['type']) && $_GET['type'] !== '') {
        $types = array_values(array_intersect(explode(',', $_GET['type']), Vente::TYPES));
        if (count($types) === 0) {
            $Response->validationError("Nature d'écriture inconnue.");
        }
        $where[] = "p.type IN (" . implode(', ', array_fill(0, count($types), '?')) . ")";
        $params = array_merge($params, $types);
    }

    if (isset($_GET['moyen']) && is_string($_GET['moyen']) && preg_match('/^[a-z0-9_]{1,30}$/', $_GET['moyen'])) {
        $where[] = "p.code_moyen = ?";
        $params[] = $_GET['moyen'];
    }

    if (isset($_GET['id_vente']) && is_string($_GET['id_vente']) && ctype_digit($_GET['id_vente'])) {
        $where[] = "p.id_vente = ?";
        $params[] = (int) $_GET['id_vente'];
    }

    // Période sur la date de valeur de l'écriture
    foreach (array('du' => '>=', 'au' => '<=') as $cle => $op) {
        $d = $Vente->dateFiltre($cle);
        if ($d !== null) {
            $where[] = "p.date_paiement $op ?";
            $params[] = $d;
        }
    }

    if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
        $cond = $Vente->conditionRecherche($_GET['q']);
        if ($cond === null) {
            $Response->validationError("Saisissez entre 2 et 80 caractères pour la recherche.");
        }
        $where[] = $cond[0];
        $params = array_merge($params, $cond[1]);
    }

    $sqlFrom = Vente::SQL_FROM_JOURNAL;
    $sqlWhere = " WHERE " . implode(" AND ", $where);
    $tri = $S->tri(
        array('date' => 'p.date_paiement', 'montant' => 'p.montant', 'nom' => 'c.nom'),
        'date',
        'p.id_paiement DESC'
    );
    list($page, $limit, $offset) = Export::pagination($user);

    $t = $Vente->totauxJournal(implode(" AND ", $where), $params);
    $rows = $Mysql->fetchAll(
        "SELECT p.*, m.libelle AS moyen, c.id_contact, c.prenom, c.nom, c.statut AS contact_statut,
                u.identifiant AS auteur_identifiant, u.prenom AS auteur_prenom, u.nom AS auteur_nom,
                EXISTS (SELECT 1 FROM v_paiement i WHERE i.id_paiement_origine = p.id_paiement AND i.type = 'impaye' AND i.date_annulation IS NULL) AS rejete"
        . $sqlFrom . $sqlWhere . " ORDER BY $tri LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset))
    );

    $paiements = array();
    foreach ($rows as $row) {
        $out = $Vente->paiementSortie($row);
        $out['vente'] = array('id_vente' => (int) $row->id_vente, 'reference' => Vente::reference($row->id_vente));
        $out['contact'] = array(
            'id_contact' => (int) $row->id_contact,
            'reference' => Contact::reference($row->id_contact),
            'prenom' => $row->prenom,
            'nom' => $row->nom,
            'groupe' => Contact::groupeDe($row->contact_statut),
        );
        $paiements[] = $out;
    }

    // &format=xlsx : le journal en classeur ; &modele=comptable : les écritures pour la comptabilité, avec leurs totaux
    // (à demander avec type=encaissement,remboursement,impaye et la période)
    Export::siDemande(isset($_GET['modele']) && $_GET['modele'] === 'comptable' ? 'comptable' : 'paiements', $user, $paiements, $t['nb'], $t);
    $Response->success(array(
        'paiements' => $paiements,
        'total' => $t['nb'],
        'page' => $page,
        'limit' => $limit,
        'totaux' => array(
            'encaisse' => $t['encaisse'],
            'rembourse' => $t['rembourse'],
            'frais' => $t['frais'],
            'net' => $t['net'],
        ),
    ));
}

// Enregistrement d'une écriture ################################
// POST {id_vente, type: encaissement|echec|impaye|remboursement, montant, date_paiement, code_moyen?, reference?, frais?,
//       motif?, commentaire?, id_paiement_origine?, cle_saisie?}
// encaissement, echec : le montant ne dépasse pas le reste dû. remboursement : motif obligatoire, plafonné à l'encaissé.
// impaye : `id_paiement_origine` désigne l'encaissement rejeté, dont il reprend le montant.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $R = json_decode(file_get_contents("php://input"));
    $idv = (is_object($R) && isset($R->id_vente) && is_int($R->id_vente)) ? $R->id_vente : 0;
    list($user, $vente) = $Vente->exigerVente($idv, 'paiements', 'C');

    $ligne = $S->lireChamps($R, $Vente->specPaiement(), false);
    if (in_array($ligne['type'], array('encaissement', 'remboursement'), true) && !isset($ligne['code_moyen'])) {
        $Response->validationError("Le champ « moyen de paiement » est obligatoire.");
    }
    $cle = $Vente->lireCle($R);

    $res = $Vente->ecrire($idv, $ligne, $cle, (int) $user->id_users);

    $idc = (int) $vente->id_contact;
    // Les tâches de relance du dossier suivent l'écriture (échéance en retard, paiement échoué)
    $Tache->synchroniser($idc);

    $Response->success(array(
        'id_paiement' => $res['id_paiement'],
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc)),
        'avertissements' => $res['avertissements'],
    ), $res['deja'] ? 200 : 201);
}

// Correction d'une écriture ################################
// PUT {id_paiement, code_moyen?, reference?, frais?, commentaire?}
// Montant, date et nature ne se modifient pas : on annule l'écriture (v1/paiements/annulation/) et on la ressaisit.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $idp = (is_object($R) && isset($R->id_paiement) && is_int($R->id_paiement)) ? $R->id_paiement : 0;
    $paiement = $idp > 0 ? $Mysql->fetchOne("SELECT * FROM v_paiement WHERE id_paiement = ?", array($idp), 'i') : null;
    list($user, $vente) = $Vente->exigerVente($paiement === null ? 0 : (int) $paiement->id_vente, 'paiements', 'C');

    if ($paiement->date_annulation !== null) {
        $Response->validationError("Cette écriture est annulée : elle ne se corrige plus.");
    }
    $spec = array_intersect_key($Vente->specPaiement(), array_flip(array('code_moyen', 'reference', 'frais', 'commentaire')));
    $data = $S->lireChamps($R, $spec, true);

    $Vente->corrigerEcriture($paiement, $data, (int) $user->id_users);

    $idc = (int) $vente->id_contact;
    $Response->success(array(
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc)),
        'avertissements' => array(),
    ));
}

$Response->methodNotAllowed();

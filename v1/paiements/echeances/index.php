<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../require/param.php";

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

// Échéances des ventes en cours (CDC §10) ################################
// GET ?etat=a_encaisser|retard|a_venir|soldees&du=&au=&q=&sort=date|montant|nom&dir=&page=&limit=
// a_encaisser (défaut) : échéances non soldées ; retard : date passée ; a_venir : date à venir ; soldees : payées.
// `totaux` : reste à encaisser de toute la sélection, dont la part en retard. Le retard se calcule au jour de la lecture.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $user = $U->requireAccess('paiements', 'L');
    $aujourdhui = date('Y-m-d');

    $where = array("e.date_annulation IS NULL");
    $params = array();

    $lisibles = $Vente->conditionDossiersLisibles($user);
    if ($lisibles === null) {
        $Response->forbidden("Vous n'avez pas accès aux dossiers.");
    }
    $where[] = $lisibles[0];
    $params = array_merge($params, $lisibles[1]);

    $etat = (isset($_GET['etat']) && in_array($_GET['etat'], array('a_encaisser', 'retard', 'a_venir', 'soldees'), true)) ? $_GET['etat'] : 'a_encaisser';
    if ($etat === 'soldees') {
        $where[] = "e.montant_paye >= e.montant";
    } else {
        // Ce qui reste à encaisser ne concerne que les ventes en cours
        $where[] = "e.montant_paye < e.montant AND v.date_annulation IS NULL";
        if ($etat === 'retard') {
            $where[] = "e.date_prevue < ?";
            $params[] = $aujourdhui;
        } elseif ($etat === 'a_venir') {
            $where[] = "e.date_prevue >= ?";
            $params[] = $aujourdhui;
        }
    }

    // Période sur la date prévue
    foreach (array('du' => '>=', 'au' => '<=') as $cle => $op) {
        $d = $Vente->dateFiltre($cle);
        if ($d !== null) {
            $where[] = "e.date_prevue $op ?";
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

    $sqlFrom = Vente::SQL_FROM_ECHEANCES;
    $sqlWhere = " WHERE " . implode(" AND ", $where);
    $tri = $S->tri(
        array('date' => 'e.date_prevue', 'montant' => 'e.montant', 'nom' => 'c.nom'),
        'date',
        'e.id_echeance'
    );
    list($page, $limit, $offset) = $S->pagination();

    $t = $Vente->totauxEcheances(implode(" AND ", $where), $params, $aujourdhui);
    $rows = $Mysql->fetchAll(
        "SELECT e.*, c.id_contact, c.prenom, c.nom, c.statut AS contact_statut,
                (SELECT COUNT(*) FROM v_echeance n WHERE n.id_vente = e.id_vente AND n.date_annulation IS NULL) AS nb_echeances"
        . $sqlFrom . $sqlWhere . " ORDER BY $tri LIMIT ? OFFSET ?",
        array_merge($params, array($limit, $offset))
    );

    $echeances = array();
    foreach ($rows as $row) {
        $out = $Vente->echeanceSortie($row, $aujourdhui);
        $out['nb_echeances'] = (int) $row->nb_echeances;
        $out['vente'] = array('id_vente' => (int) $row->id_vente, 'reference' => Vente::reference($row->id_vente));
        $out['contact'] = array(
            'id_contact' => (int) $row->id_contact,
            'reference' => Contact::reference($row->id_contact),
            'prenom' => $row->prenom,
            'nom' => $row->nom,
            'groupe' => Contact::groupeDe($row->contact_statut),
        );
        $echeances[] = $out;
    }

    $Response->success(array(
        'echeances' => $echeances,
        'total' => $t['nb'],
        'page' => $page,
        'limit' => $limit,
        'totaux' => array(
            'a_encaisser' => $t['a_encaisser'],
            'en_retard' => $t['en_retard'],
            'nb_retard' => $t['nb_retard'],
        ),
    ));
}

$Response->methodNotAllowed();

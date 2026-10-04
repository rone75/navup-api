<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
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

// Listes de référence (CDC §6, §7, §21 ; étape 7b) ################################
// GET → {origines, categories, moyens, offres} : toutes les valeurs, actives ou non, avec leur nombre d'utilisations.
// POST {liste, libelle} : ajoute une valeur (son code se forme sur le libellé et ne change plus jamais).
// PUT {liste, code, libelle?, actif?, ordre?} ; pour l'offre : {liste: "offres", code, libelle?, prix?}.
// Une valeur ne se supprime jamais : désactivée, elle disparaît des choix et reste lisible là où elle sert.
// Un prix changé vaut pour les ventes à venir ; une vente faite garde son montant. Administrateur seulement.

const LISTES = array(
    'origines' => array('table' => 'p_origine', 'usage' => "SELECT COUNT(*) AS n FROM d_contact WHERE code_origine = ?", 'libelle' => 'origine'),
    'categories' => array('table' => 'p_categorie_problematique', 'usage' => "SELECT COUNT(*) AS n FROM d_problematique WHERE code_categorie = ?", 'libelle' => 'catégorie'),
    'moyens' => array('table' => 'p_moyen_paiement', 'usage' => "SELECT (SELECT COUNT(*) FROM v_paiement WHERE code_moyen = ?) + (SELECT COUNT(*) FROM v_vente WHERE code_moyen = ?) AS n", 'libelle' => 'moyen de paiement'),
    'offres' => array('table' => 'p_offre', 'usage' => "SELECT COUNT(*) AS n FROM v_vente WHERE code_offre = ?", 'libelle' => 'offre'),
);

function lireListes()
{
    global $Mysql;

    $out = array();
    foreach (LISTES as $cle => $def) {
        $lignes = array();
        foreach ($Mysql->fetchAll("SELECT * FROM {$def['table']} ORDER BY ordre, libelle") as $r) {
            $params = substr_count($def['usage'], '?') === 2 ? array($r->code, $r->code) : array($r->code);
            $ligne = array('code' => $r->code, 'libelle' => $r->libelle, 'ordre' => (int) $r->ordre, 'actif' => (int) $r->actif === 1,
                'utilisations' => (int) $Mysql->fetchOne($def['usage'], $params)->n);
            if ($cle === 'offres') {
                $ligne['prix'] = (int) $r->prix;
            }
            $lignes[] = $ligne;
        }
        $out[$cle] = $lignes;
    }

    return $out;
}

function liste($R)
{
    global $Response;

    $cle = (is_object($R) && isset($R->liste) && is_string($R->liste)) ? $R->liste : '';
    if (!isset(LISTES[$cle])) {
        $Response->validationError("Liste inconnue.");
    }

    return $cle;
}

function libelle($R)
{
    global $Response;

    $l = (is_object($R) && isset($R->libelle) && is_string($R->libelle)) ? trim(preg_replace('/\s+/u', ' ', $R->libelle)) : '';
    if ($l === '' || mb_strlen($l) > 60) {
        $Response->validationError("Le libellé est obligatoire (60 caractères au plus).");
    }

    return $l;
}

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $U->requireAccess('parametres', 'C');
    $Response->success(array('listes' => lireListes()));
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $user = $U->requireAccess('parametres', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $cle = liste($R);
    if ($cle === 'offres') {
        $Response->validationError("Une nouvelle offre demande une évolution de l'outil (sa vente en ligne, son programme) : elle ne s'ajoute pas ici.");
    }
    $table = LISTES[$cle]['table'];
    $libelle = libelle($R);
    if ($Mysql->fetchOne("SELECT code FROM $table WHERE libelle = ?", array($libelle), 's') !== null) {
        $Response->validationError("Cette valeur existe déjà.");
    }
    // Code stable, formé sur le libellé : minuscules sans accents, chiffres, soulignés
    $base = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $libelle))), '_'), 0, 26);
    $base = $base === '' ? 'valeur' : $base;
    $code = $base;
    for ($i = 2; $Mysql->fetchOne("SELECT code FROM $table WHERE code = ?", array($code), 's') !== null; $i++) {
        $code = $base . '_' . $i;
    }
    $ordre = (int) $Mysql->fetchOne("SELECT COALESCE(MAX(ordre), 0) + 1 AS o FROM $table")->o;

    $SQL->begin_transaction();
    $Mysql->execute("INSERT INTO $table (code, libelle, ordre, actif) VALUES (?, ?, ?, 1)", array($code, $libelle, $ordre), 'ssi');
    $U->audit((int) $user->id_users, 'liste_create', array('liste' => $cle, 'code' => $code));
    $SQL->commit();

    $Response->success(array('code' => $code, 'listes' => lireListes()), 201);
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $user = $U->requireAccess('parametres', 'C');
    $R = json_decode(file_get_contents("php://input"));
    $cle = liste($R);
    $table = LISTES[$cle]['table'];
    $code = (isset($R->code) && is_string($R->code)) ? $R->code : '';
    $ligne = $Mysql->fetchOne("SELECT * FROM $table WHERE code = ?", array($code), 's');
    if ($ligne === null) {
        $Response->notFound("Valeur introuvable.");
    }

    $set = array();
    $details = array('liste' => $cle, 'code' => $code);
    if (isset($R->libelle)) {
        $set['libelle'] = libelle($R);
        if ($Mysql->fetchOne("SELECT code FROM $table WHERE libelle = ? AND code <> ?", array($set['libelle'], $code), 'ss') !== null) {
            $Response->validationError("Une autre valeur porte déjà ce libellé.");
        }
    }
    if (isset($R->actif)) {
        if (!is_bool($R->actif)) {
            $Response->validationError("Erreur paramètre ACTIF (true ou false)");
        }
        if ($cle === 'offres' && !$R->actif) {
            $Response->validationError("L'offre vendue ne se désactive pas ici : fermez plutôt la vente en ligne.");
        }
        $set['actif'] = $R->actif ? 1 : 0;
        $details['actif'] = $R->actif;
    }
    if (isset($R->ordre)) {
        if (!is_int($R->ordre) || $R->ordre < 0 || $R->ordre > 1000) {
            $Response->validationError("Erreur paramètre ORDRE");
        }
        $set['ordre'] = $R->ordre;
    }
    if (isset($R->prix)) {
        if ($cle !== 'offres' || !is_int($R->prix) || $R->prix < 100 || $R->prix > 10000000) {
            $Response->validationError("Le prix est un montant en centimes, entre 1 € et 100 000 €.");
        }
        $set['prix'] = $R->prix;
        $details['prix_avant'] = (int) $ligne->prix;
        $details['prix_apres'] = $R->prix;
    }
    if (count($set) === 0) {
        $Response->validationError("Rien à modifier.");
    }

    $SQL->begin_transaction();
    $cols = array();
    $params = array();
    foreach ($set as $col => $v) {
        $cols[] = "$col = ?";
        $params[] = $v;
    }
    $params[] = $code;
    $Mysql->execute("UPDATE $table SET " . implode(', ', $cols) . " WHERE code = ?", $params);
    $U->audit((int) $user->id_users, 'liste_update', $details);
    $SQL->commit();

    $Response->success(array('listes' => lireListes()));
}

$Response->methodNotAllowed();

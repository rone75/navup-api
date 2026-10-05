#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-production.php
// Description: avant l'ouverture (étape 8) : ce qui manque pour la production, en lecture seule. Chaque point répond
//              « OK » ou « À FAIRE » avec ce qu'il faut faire ; aucun secret n'est affiché, seulement sa présence.
//              Sur le poste de développement, la liste des « À FAIRE » est attendue : le script ne doit pas planter.
// Usage:       php script-cgi/verifier-production.php     (code de sortie 1 s'il reste quelque chose à faire)
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.totp.php";
include __DIR__ . "/../include/package.sauvegarde.php";
include __DIR__ . "/../include/package.connexions.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');

$Response = new Response();
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$afaire = 0;
function point($ok, $libelle, $sinon = '')
{
    global $afaire;
    echo ($ok ? "OK        " : "À FAIRE   ") . $libelle . ($ok || $sinon === '' ? '' : " — $sinon") . "\n";
    if (!$ok) {
        $afaire++;
    }
}
function titre($t)
{
    echo "\n$t\n";
}
function https($url)
{
    return is_string($url) && preg_match('#^https://[^/]+#', $url) === 1 && !preg_match('#//(localhost|127\.|\[::1\])#', $url);
}
function origines($liste)
{
    if (!is_array($liste) || count($liste) === 0) {
        return false;
    }
    foreach ($liste as $o) {
        if (!https($o)) {
            return false;
        }
    }
    return true;
}

titre('Configuration');
point(!empty($_PROD), '$_PROD vaut 1', 'require/secret.php');
point(https($_URL_TOUR ?? null), 'adresse de la Tour de contrôle en https', '$_URL_TOUR');
point(https($_APP_PARENTS_URL ?? null), "adresse de l'appli des parents en https", '$_APP_PARENTS_URL');
point(https($_URL_RETOUR_PAIEMENT ?? null), 'page de retour de paiement en https', '$_URL_RETOUR_PAIEMENT');
point(origines($_CORS_ORIGINES ?? null), 'origines CORS de la Tour : https, sans localhost', '$_CORS_ORIGINES');
point(origines($_CORS_ORIGINES_PUBLIQUES ?? null), 'origines CORS publiques : https, sans localhost', '$_CORS_ORIGINES_PUBLIQUES');
point(($_MAIL_MODE ?? '') === 'reel', 'e-mails aux parents réellement envoyés', '$_MAIL_MODE = "reel" (après SPF et DKIM)');
point(filter_var($_MAIL_EXPEDITEUR_PARENTS ?? '', FILTER_VALIDATE_EMAIL) !== false, 'expéditeur des e-mails aux parents', '$_MAIL_EXPEDITEUR_PARENTS');
point(filter_var($_MAIL_ERREUR ?? '', FILTER_VALIDATE_EMAIL) !== false, 'adresse qui reçoit les erreurs', '$_MAIL_ERREUR');

titre('Stripe');
$cle = (string) ($_STRIPE_CLE_SECRETE ?? '');
point(strpos($cle, 'sk_live_') === 0 || strpos($cle, 'rk_live_') === 0, 'clé Stripe du mode réel', $cle === '' ? 'absente' : 'clé de test : la remplacer par la clé live');
point(strpos((string) ($_STRIPE_SECRET_WEBHOOK ?? ''), 'whsec_') === 0, 'secret du webhook présent', '$_STRIPE_SECRET_WEBHOOK, celui du webhook de production');
$recent = $Mysql->fetchOne("SELECT MAX(date_creation) AS d FROM s_evenement WHERE canal = 'webhook'");
point($recent !== null && $recent->d !== null && strtotime($recent->d) > time() - 30 * 86400, 'webhook reçu depuis moins de 30 jours', 'aucun événement reçu par le webhook : vérifier son adresse chez Stripe');

titre('Secrets et clés');
$secret = __DIR__ . '/../require/secret.php';
$mode = fileperms($secret) & 0777;
point(($mode & 0007) === 0, 'require/secret.php illisible pour les autres utilisateurs', sprintf('droits %o : chmod 640', $mode));
point(Totp::disponible(), 'clé de la double authentification', '$_CLE_TOTP');
point(Sauvegarde::disponible(), 'clé des sauvegardes', '$_CLE_SAUVEGARDE (et sa copie hors du serveur)');
point(!is_dir(__DIR__ . '/../.git') || (fileperms(__DIR__ . '/../.git') & 0007) === 0, 'dépôt git non lisible par les autres', 'chmod o-rwx .git, ou déployer sans .git');

titre('Tâches et sauvegardes');
$p = Connexions::planifie();
point(!$p['retard'], 'tâche planifiée passée récemment', 'crontab de deploiement/crontab-navup');
$dossier = (string) ($_DOSSIER_SAUVEGARDES ?? '');
point($dossier !== '' && is_dir($dossier) && is_writable($dossier), 'dossier des sauvegardes', '$_DOSSIER_SAUVEGARDES, créé en 0700');
if ($dossier !== '' && is_dir($dossier)) {
    point(((fileperms($dossier) & 0777) & 0077) === 0, 'dossier des sauvegardes fermé aux autres', 'chmod 700');
}
point(!Connexions::sauvegardeEnRetard(), 'sauvegarde réussie depuis moins de 36 heures', 'script-cgi/sauvegarder.php (crontab)');
point(is_dir($_DOSSIER_MEDIAS ?? '') && is_writable($_DOSSIER_MEDIAS ?? ''), 'dossier des médias inscriptible', '$_DOSSIER_MEDIAS');

titre('Base de données');
$droits = array_map(function ($g) {
    return array_values((array) $g)[0];
}, $Mysql->fetchAll("SHOW GRANTS"));
$large = false;
foreach ($droits as $g) {
    if (preg_match('/ON \*\.\* /', $g) && !preg_match('/^GRANT USAGE/', $g)) {
        $large = true;
    }
    if (stripos($g, 'WITH GRANT OPTION') !== false) {
        $large = true;
    }
}
point(!$large, "l'utilisateur de l'API n'a de droits que sur ses bases", "droits globaux ou GRANT OPTION : le restreindre");
$base = $Mysql->fetchOne("SELECT DATABASE() AS b")->b;
point((int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'navup_restauration'")->n === 1, 'base de test des restaurations', 'navup_restauration (deploiement/README.md, § 2)');
point((int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'u_users' AND COLUMN_NAME = 'totp_actif'", array($base), 's')->n === 1, 'schéma à jour (étape 8)', 'sql/080_securite.sql');

titre('Comptes et données');
point((int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM u_users WHERE identifiant LIKE 'essai.%'")->n === 0, "aucun compte d'essai", 'script-cgi/purge-essais.php');
point((int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM d_contact WHERE email LIKE 'essai.%@navup.local'")->n === 0, "aucun dossier d'essai", 'script-cgi/purge-essais.php');
$admins = $Mysql->fetchOne("SELECT COUNT(*) AS n, SUM(totp_actif) AS mfa FROM u_users WHERE profil = 'admin' AND actif = 1");
point((int) $admins->n > 0, 'au moins un administrateur actif', 'script-cgi/seed-admin.php');
// Facultative (décision d'Erwan, étape 8) : une information, pas une condition
$sans = (int) $admins->n - (int) $admins->mfa;
echo ($sans === 0 ? "OK        " : "CONSEIL   ") . "double authentification des administrateurs"
    . ($sans === 0 ? "\n" : " — $sans sans : l'activer dans « Mon compte » est recommandé\n");

echo $afaire === 0 ? "\nPrêt pour la production.\n" : "\n$afaire point(s) à faire avant la production.\n";
exit($afaire === 0 ? 0 : 1);

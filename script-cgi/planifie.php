#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/planifie.php
// Description: tâche planifiée de la Tour de contrôle : tout ce qui doit arriver à une date sans attendre qu'un
//              utilisateur ouvre l'outil (prélèvements, e-mails, conversions audio, fins de programme, tâches du jour).
//              Chaque passe tourne dans son propre sous-processus : une erreur SQL termine le processus
//              (Mysql::Erreur), elle ne doit pas emporter les passes suivantes. Le dernier passage de chaque passe
//              est noté dans t_planifie, lu par l'onglet « Connexions ».
//              Un seul exemplaire à la fois (verrou nommé) ; chaque passe est rejouable sans effet.
//              Utilisable en production. À brancher sur cron :
//                */15 * * * * /usr/bin/php /var/www/navup-api/script-cgi/planifie.php > /dev/null
// Usage:       php script-cgi/planifie.php                 toutes les passes
//              php script-cgi/planifie.php --passe=taches  une seule passe (c'est ainsi que le script s'appelle lui-même)
//              php script-cgi/planifie.php --liste         les passes, dans l'ordre
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../include/package.user.php";
include __DIR__ . "/../include/package.saisie.php";
include __DIR__ . "/../include/package.contact.php";
include __DIR__ . "/../include/package.vente.php";
include __DIR__ . "/../include/package.suivi.php";
include __DIR__ . "/../include/package.message.php";
include_once __DIR__ . "/../include/package.formation.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../include/package.stripe.php";
include __DIR__ . "/../include/package.connexions.php";
include __DIR__ . "/../require/param.php";

/** « 1 audio converti », « 3 audios convertis » : le nombre et son nom, accordés. */
function nb($n, $singulier, $pluriel)
{
    return (int) $n . ' ' . ((int) $n > 1 ? $pluriel : $singulier);
}

// Les passes, dans l'ordre. Chacune rend son résumé : des nombres, jamais une donnée de dossier.
$PASSES = array(
    'stripe-rattrapage' => function () {
        global $Stripe;

        if (!$Stripe->configure()) {
            return "Stripe n'est pas configuré";
        }
        list($nouveaux, $traites, $resolus) = $Stripe->rattraper();

        return nb($nouveaux, 'signal nouveau', 'signaux nouveaux') . ', ' . nb($traites, 'traité', 'traités') . ', ' . nb($resolus, 'prélèvement résolu', 'prélèvements résolus');
    },

    'stripe-avis' => function () {
        global $Stripe;

        return $Stripe->configure() ? $Stripe->aviser() . " avis de prélèvement" : "Stripe n'est pas configuré";
    },

    'stripe-prelevements' => function () {
        global $Stripe;

        if (!$Stripe->configure()) {
            return "Stripe n'est pas configuré";
        }
        $lances = $Stripe->prelever();

        return $lances === null ? "fermée : hors de la plage horaire, ou prélèvements suspendus" : nb($lances, 'prélèvement lancé', 'prélèvements lancés');
    },

    'stripe-frais' => function () {
        global $Stripe;

        return $Stripe->configure() ? nb($Stripe->completerFrais(), 'écriture complétée de ses frais', 'écritures complétées de leurs frais') : "Stripe n'est pas configuré";
    },

    'comptes' => function () {
        list($ouverts, $termines) = Compte::synchroniser();

        return nb($ouverts, 'compte ouvert', 'comptes ouverts') . ', ' . nb($termines, 'programme terminé', 'programmes terminés');
    },

    'semaines' => function () {
        $deposes = Compte::annoncerSemaines();

        return $deposes === null ? "éteinte : l'appli des parents n'a pas d'adresse" : nb($deposes, 'annonce de nouvelle semaine', 'annonces de nouvelle semaine');
    },

    'medias' => function () {
        global $Formation, $Mysql;

        if ((int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM f_fichier WHERE etat = 'a_convertir'")->n === 0) {
            return "aucun audio à convertir";
        }
        list($convertis, $erreurs) = $Formation->convertir();

        return nb($convertis, 'audio converti', 'audios convertis') . ", $erreurs en erreur";
    },

    'messages' => function () {
        global $Message;

        list($envoyes, $erreurs) = $Message->envoyerEnAttente();

        return nb($envoyes, 'e-mail envoyé', 'e-mails envoyés') . ", $erreurs en erreur";
    },

    'taches' => function () {
        global $Tache, $Mysql;

        $maintenant = date('Y-m-d H:i:s');
        $differences = $Tache->synchroniser(null, $maintenant);
        $Mysql->execute("UPDATE t_synchro SET date_synchro = ? WHERE id = 1", array($maintenant), 's');
        $Mysql->execute("DELETE FROM u_limite_ip WHERE date_fin < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        // Ce que l'appli des parents laisse derrière elle : fenêtres de limiteur closes, sessions finies, liens d'accès périmés
        $Mysql->execute("DELETE FROM e_limite WHERE date_fin < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $Mysql->execute("DELETE FROM e_session WHERE date_expiration < NOW()");
        $Mysql->execute("DELETE FROM a_jeton WHERE date_expiration < DATE_SUB(NOW(), INTERVAL 30 DAY)");

        return nb(count($differences), 'tâche automatique ajustée', 'tâches automatiques ajustées');
    },

    'surveillance' => function () {
        return Connexions::surveiller();
    },
);

$options = getopt('', array('passe:', 'liste'));

if (isset($options['liste'])) {
    echo implode("\n", array_keys($PASSES)) . "\n";
    exit(0);
}

// Une passe, dans ce processus
if (isset($options['passe'])) {
    $nom = (string) $options['passe'];
    if (!isset($PASSES[$nom])) {
        fwrite(STDERR, "Passe inconnue : $nom\n");
        exit(2);
    }
    Automate::demarrer(__FILE__);
    try {
        echo $PASSES[$nom]() . "\n";
    } catch (Throwable $e) {
        // Le message d'une erreur inattendue peut citer une valeur : seule sa classe sort d'ici
        fwrite(STDERR, "Passe $nom interrompue : " . get_class($e) . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n");
        exit(1);
    }
    exit(0);
}

// Toutes les passes, chacune dans son sous-processus
date_default_timezone_set('Europe/Paris');
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$verrou = $Mysql->fetchOne("SELECT GET_LOCK('navup_planifie', 0) AS pris");
if ($verrou === null || (int) $verrou->pris !== 1) {
    fwrite(STDERR, "La tâche planifiée tourne déjà : ce lancement est abandonné.\n");
    exit(2);
}

$echecs = 0;
foreach (array_keys($PASSES) as $nom) {
    $Mysql->execute(
        "INSERT INTO t_planifie (passe, date_debut, date_fin, code, resume) VALUES (?, NOW(), NULL, 0, NULL)
         ON DUPLICATE KEY UPDATE date_debut = NOW(), date_fin = NULL, code = 0, resume = NULL",
        array($nom),
        's'
    );

    $sortie = array();
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__FILE__) . " --passe=" . escapeshellarg($nom) . " 2>&1", $sortie, $code);
    $resume = mb_substr(trim(implode(' ', $sortie)), 0, 255);
    if ($code !== 0) {
        $echecs++;
    }

    $Mysql->execute(
        "UPDATE t_planifie SET date_fin = NOW(), code = ?, resume = ? WHERE passe = ?",
        array(min(255, $code), $resume === '' ? null : $resume, $nom),
        'iss'
    );
    echo str_pad($nom, 22) . ($code === 0 ? "OK    " : "ÉCHEC ") . $resume . "\n";
}

$Mysql->fetchOne("SELECT RELEASE_LOCK('navup_planifie') AS rendu");

exit($echecs === 0 ? 0 : 1);

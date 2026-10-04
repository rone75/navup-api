#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-emails.php
// Description: lecture seule. Prouve que chaque gabarit d'origine des e-mails aux parents (package.modeles.php) rend,
//              à l'octet près, l'e-mail que composait le code avant l'étape 7b (Message::composerOrigine), sur toutes
//              les variantes de chaque modèle ; puis que chaque gabarit d'origine passe le contrôle d'une version, et
//              que la version active de chaque modèle (s'il y en a une) le passe aussi.
// Usage:       php script-cgi/verifier-emails.php            (code 1 au premier écart)
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

foreach (array('mysql', 'response', 'user', 'saisie', 'contact', 'vente', 'message', 'modeles', 'automate') as $p) {
    include_once __DIR__ . "/../include/package.$p.php";
}
include __DIR__ . "/../require/param.php";

Automate::demarrer(__FILE__);
$M = new Message();

$appli = 'https://app.navup.fr/';
$rdv = array('type' => 'suivi', 'date_debut' => '2026-10-27 09:30:00', 'duree' => 45, 'canal' => 'visio', 'visio' => 'https://visio.exemple.fr/x',
    'gestion' => true, 'heures' => 12, 'appli' => $appli, 'inconnu' => false, 'par_parent' => false);
$variantes = array(
    'bienvenue' => array(
        array('date_debut' => '2026-10-01', 'semaines' => 12, 'appli' => $appli, 'jours' => 7, 'echeances' => array(array('montant' => 9967, 'date_prevue' => '2026-11-01'))),
        array('date_debut' => '2026-10-01', 'semaines' => 12, 'appli' => '', 'echeances' => array()),
    ),
    'semaine' => array(array('semaine' => 2, 'sujets' => array('Un', 'Deux'), 'appli' => $appli), array('semaine' => 5, 'sujets' => array(), 'appli' => $appli)),
    'invitation' => array(array('jours' => 7, 'appli' => $appli)),
    'mot_de_passe' => array(array('minutes' => 60)),
    'lien_paiement' => array(array('montant' => 29900, 'echeance' => 'échéance unique')),
    'prelevement_avis' => array(array('montant' => 9966, 'echeance' => '3e échéance', 'date_prevue' => '2026-12-01')),
    'paiement_echoue' => array(array('montant' => 9966, 'echeance' => '2e échéance')),
    'commande_en_cours' => array(array()),
    'rdv_confirmation' => array($rdv, array('inconnu' => true, 'canal' => 'telephone') + $rdv, array('gestion' => false, 'visio' => null) + $rdv, array('canal' => 'presentiel') + $rdv),
    'rdv_modification' => array($rdv + array('ancien_debut' => '2026-10-20 10:00:00'), $rdv, $rdv + array('ancien_debut' => '2026-10-20 10:00:00', 'a_confirmer' => true)),
    'rdv_annulation' => array($rdv, array('par_parent' => true, 'appli' => '') + $rdv),
    'rdv_rappel' => array($rdv, array('inconnu' => true, 'gestion' => false) + $rdv),
    'rdv_deja' => array(array('type' => 'decouverte', 'date_debut' => '2026-10-27 09:00:00', 'gestion' => true, 'heures' => 12)),
);

$ecarts = array();
$n = 0;
foreach (Modeles::modifiables() as $code) {
    if (!isset($variantes[$code])) {
        $ecarts[] = "$code : aucune variante d'essai";
        continue;
    }
    foreach ($variantes[$code] as $i => $d) {
        foreach (array('Camille', null) as $prenom) {
            $contact = (object) array('prenom' => $prenom, 'email' => 'x@exemple.fr');
            $avant = $M->composerOrigine($code, $contact, $d);
            $apres = Modeles::rendre(Modeles::ORIGINES[$code], Modeles::valeurs($code, $contact, $d));
            $n++;
            if ($avant[0] !== $apres[0] || $avant[1] !== $apres[1]) {
                $ecarts[] = "$code, variante " . ($i + 1) . ($prenom === null ? ' sans prénom' : '') . " : le rendu diffère\n--- avant\n" . $avant[0] . "\n" . $avant[1] . "--- après\n" . $apres[0] . "\n" . $apres[1];
            }
        }
    }
    foreach (Modeles::controler($code, Modeles::ORIGINES[$code]['objet'], Modeles::ORIGINES[$code]['corps']) as $r) {
        $ecarts[] = "$code : le gabarit d'origine ne passe pas le contrôle : $r";
    }
    $actif = Modeles::actif($code);
    if ($actif['version'] > 0) {
        foreach (Modeles::controler($code, $actif['objet'], $actif['corps']) as $r) {
            $ecarts[] = "$code : la version active {$actif['version']} ne passe pas le contrôle : $r";
        }
    }
}

if (count($ecarts) > 0) {
    fwrite(STDERR, implode("\n", $ecarts) . "\n");
    echo count($ecarts) . " écart(s).\n";
    exit(1);
}
echo count(Modeles::modifiables()) . " modèles, $n rendus comparés au texte d'avant : aucun écart.\n";

<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.vente.php";
include "../../../include/package.suivi.php";
include "../../../include/package.message.php";
include "../../../include/package.automate.php";
include "../../../include/package.stripe.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

// Réponses au navigateur. Les classes métier, elles, lèvent leurs refus (ReponseAutomate) : rien ne s'arrête au milieu.
$Sortie = new Response();
Automate::demarrer(getcwd() . "/index.php");

// Achat en ligne (CDC §19, §20 ; cahier de l'écosystème §5, §13) ################################
// Endpoint PUBLIC, sans authentification : appelé par la page de vente (landing page de l'appli des parents).
// POST {prenom, nom, email, telephone?, fois: 1|3, cgv: 1, confidentialite: 1, communications?: 0|1, cle_saisie}
// → {suite: "paiement", url} : rediriger le parent vers la page de paiement Stripe ;
// → {suite: "email"} : rien à payer ici, le parent a reçu (ou va recevoir) un e-mail.
//
// Garde-fous : limiteur par adresse IP, corps JSON borné, champ leurre, clé de saisie, verrou par adresse e-mail.
// Le dossier est retrouvé par son e-mail ou créé en prospect ; un dossier existant n'est jamais modifié d'ici
// (l'identité saisie reste dans la commande). La vente n'est créée qu'au paiement : un panier abandonné ne compte
// ni dans les ventes ni dans le reste à encaisser.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Automate::limiter('commande', (int) $_LIMITE_COMMANDE[0], (int) $_LIMITE_COMMANDE[1]);
    $R = Automate::lireCorps();

    // Champ leurre, invisible du parent : un robot qui le remplit reçoit la réponse ordinaire, et rien n'est écrit
    if (isset($R->site) && $R->site !== '') {
        $Sortie->success(array('suite' => 'email'));
    }

    try {
        $data = $S->lireChamps($R, array(
            'prenom' => array('type' => 'str', 'max' => 100, 'requis' => true, 'libelle' => 'prénom'),
            'nom' => array('type' => 'str', 'max' => 100, 'requis' => true),
            'email' => array('type' => 'email', 'max' => 255, 'requis' => true, 'libelle' => 'e-mail'),
            'telephone' => array('type' => 'tel', 'libelle' => 'téléphone'),
            'fois' => array('type' => 'int', 'min' => 1, 'max' => 12, 'defaut' => 1, 'libelle' => 'nombre de paiements'),
            'cgv' => array('type' => 'bool', 'requis' => true, 'libelle' => 'conditions générales de vente'),
            'confidentialite' => array('type' => 'bool', 'requis' => true, 'libelle' => 'politique de confidentialité'),
            'communications' => array('type' => 'bool', 'defaut' => 0),
        ), false);
        $cle = $S->lireCle($R);
        if ($cle === null) {
            throw new ErreurMetier("Clé de saisie manquante.");
        }
        if (!in_array((int) $data['fois'], $_VENTE_EN_LIGNE_FOIS, true)) {
            throw new ErreurMetier("Ce nombre de paiements n'est pas proposé.");
        }
        if ((int) $data['cgv'] !== 1 || (int) $data['confidentialite'] !== 1) {
            throw new ErreurMetier("Pour commander, acceptez les conditions générales de vente et la politique de confidentialité.");
        }
        if (!$Stripe->configure()) {
            throw new ErreurMetier("Le paiement en ligne n'est pas ouvert pour le moment.");
        }
    } catch (ErreurMetier $e) {
        $Sortie->validationError($e->getMessage());
    }

    // Deux envois rapprochés pour la même adresse passent l'un après l'autre (le verrou survit aux transactions)
    $verrou = 'navup_commande_' . sha1($data['email']);
    $pris = $Mysql->fetchOne("SELECT GET_LOCK(?, 10) AS pris", array($verrou), 's');
    if ($pris === null || (int) $pris->pris !== 1) {
        $Sortie->validationError("Votre commande est déjà en cours d'enregistrement : patientez un instant.");
    }

    $reponse = null;
    $refus = null;
    $panne = false;
    try {
        $commande = $Mysql->fetchOne("SELECT * FROM s_commande WHERE cle_saisie = ?", array($cle), 's');

        if ($commande === null) {
            $offre = $Mysql->fetchOne("SELECT code, prix FROM p_offre WHERE code = ? AND actif = 1", array($_VENTE_EN_LIGNE_OFFRE), 's');
            if ($offre === null) {
                throw new ErreurMetier("Cette offre n'est pas en vente pour le moment.");
            }

            $SQL->begin_transaction();
            $dossier = $Mysql->fetchOne("SELECT id_contact FROM d_contact WHERE email = ? FOR UPDATE", array($data['email']), 's');
            if ($dossier === null) {
                $champs = array('prenom' => $data['prenom'], 'nom' => $data['nom'], 'email' => $data['email']);
                if (isset($data['telephone'])) {
                    $champs['telephone'] = $data['telephone'];
                }
                $idc = $Contact->creer($champs, 'prospect', null, 'automatique', array('canal' => 'achat_en_ligne'));
            } else {
                $idc = (int) $dossier->id_contact;
            }

            // Déjà une vente en cours, ou un programme ouvert : il n'y a rien à payer ici. La réponse ne dit rien du
            // dossier ; un e-mail part à son adresse (un par jour au plus).
            $enCours = (int) $Mysql->fetchOne(
                "SELECT COUNT(*) AS nb FROM v_vente v " . Vente::SQL_SOMMES . " WHERE v.id_contact = ? AND " . Vente::SQL_EN_COURS,
                array($idc),
                'i'
            )->nb;
            $compte = Compte::charger($idc);
            $programme = $compte === null ? null : Compte::sortie($compte)['programme']['etat'];
            if ($enCours > 0 || in_array($programme, array('en_cours', 'pas_commence'), true)) {
                $Message->deposer($Contact->charger($idc), 'commande_en_cours', array(), 'commande_en_cours:' . $idc . ':' . date('Y-m-d'));
                $SQL->commit();
                $Message->envoyerEnAttente($idc);
                $reponse = array('suite' => 'email');
            } else {
                $idco = $S->inserer('s_commande', array(
                    'cle_saisie' => $cle,
                    'id_contact' => $idc,
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                    'email' => $data['email'],
                    'telephone' => isset($data['telephone']) ? $data['telephone'] : null,
                    'code_offre' => $offre->code,
                    'nb_echeances' => (int) $data['fois'],
                    'montant' => (int) $offre->prix,
                ));
                foreach (array('cgv' => $_CGV_VERSION, 'confidentialite' => $_CONFIDENTIALITE_VERSION, 'communications' => $_CONFIDENTIALITE_VERSION) as $type => $version) {
                    $S->inserer('d_consentement', array(
                        'id_contact' => $idc,
                        'id_commande' => $idco,
                        'type' => $type,
                        'accorde' => (int) $data[$type],
                        'version' => $version,
                        'source' => 'formulaire',
                    ));
                }
                $U->audit(null, 'commande_create', array('id_commande' => $idco, 'fois' => (int) $data['fois']), 'contact', $idc);
                $SQL->commit();

                $Stripe->fermerCommandes($idc, $idco);
                $commande = $Mysql->fetchOne("SELECT * FROM s_commande WHERE id_commande = ?", array($idco), 'i');
            }
        }

        if ($reponse === null) {
            $reponse = $commande->etat === 'ouverte'
                ? array('suite' => 'paiement', 'url' => $Stripe->sessionCommande($commande))
                : array('suite' => 'email');
        }
    } catch (ErreurMetier $e) {
        $SQL->rollback();
        $refus = $e->getMessage();
    } catch (Throwable $e) {
        $SQL->rollback();
        $panne = true;
    }
    $Mysql->fetchOne("SELECT RELEASE_LOCK(?) AS rendu", array($verrou), 's');

    if ($panne) {
        $Sortie->serviceUnavailable("Le paiement en ligne est momentanément indisponible. Réessayez dans un instant.");
    }
    if ($refus !== null) {
        $Sortie->validationError($refus);
    }
    $Sortie->success($reponse, 201);
}

$Sortie->methodNotAllowed();

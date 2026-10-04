<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
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
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();
$S = new Saisie();
$Contact = new Contact();
$Vente = new Vente();
$Rdv = new Rdv();
$Interaction = new Interaction();
$Tache = new Tache();
$Suivi = new Suivi();
$Message = new Message();
$Stripe = new PaiementStripe();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// Prélèvements d'une vente dont la carte est enregistrée (CDC §10) ################################
// PUT {id_vente, action: "relancer"} : prélève tout de suite l'échéance due (après un échec, ou sans attendre l'avis).
// PUT {id_vente, action: "suspendre" | "reprendre"} : l'outil cesse, ou recommence, de prélever les échéances à leur date.
// Chaque écriture renvoie les ventes du dossier et le dossier lui-même, comme une écriture de paiement.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $R = json_decode(file_get_contents("php://input"));
    $id = (is_object($R) && isset($R->id_vente) && is_int($R->id_vente)) ? $R->id_vente : 0;
    list($user, $vente) = $Vente->exigerVente($id, 'paiements', 'C');
    $action = (isset($R->action) && is_string($R->action)) ? $R->action : '';
    if (!in_array($action, array('relancer', 'suspendre', 'reprendre'), true)) {
        $Response->validationError("Erreur paramètre ACTION manquant (relancer, suspendre ou reprendre)");
    }
    $idc = (int) $vente->id_contact;
    $issue = null;

    // Les classes métier lèvent leurs refus au lieu de terminer la requête : un prélèvement parti chez Stripe
    // doit toujours voir son issue consignée
    $Sortie = $Response;
    $Response = new ReponseAutomate();
    try {
        if ($action === 'relancer') {
            $issue = $Stripe->preleverVente($id, (int) $user->id_users, true);
        } else {
            $Vente->reglerPrelevement($id, $action === 'reprendre', (int) $user->id_users);
        }
    } catch (ErreurMetier $e) {
        $SQL->rollback();
        $Sortie->validationError($e->getMessage());
    } catch (ErreurStripe $e) {
        $SQL->rollback();
        $Sortie->validationError("Stripe n'a pas répondu : réessayez dans un instant.");
    }
    $Response = $Sortie;

    $avertissements = array();
    if ($issue === 'recu') {
        $avertissements[] = "L'issue du prélèvement n'est pas encore connue : elle s'affichera ici dans quelques minutes.";
    } elseif ($issue === 'erreur') {
        $avertissements[] = "Le prélèvement n'a pas pu être enregistré : voyez l'onglet « Connexions » des paramètres.";
    }

    $Response->success(array(
        'ventes' => $Vente->blocDossier($idc, $user),
        'contact' => $Contact->sortie($Contact->charger($idc)),
        'avertissements' => $avertissements,
    ));
}

$Response->methodNotAllowed();

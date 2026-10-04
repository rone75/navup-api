<?php

include "../../../../include/package.header.php";
include "../../../../include/package.mysql.php";
include "../../../../include/package.response.php";
include "../../../../include/package.user.php";
include "../../../../include/package.saisie.php";
include "../../../../include/package.contact.php";
include "../../../../include/package.suivi.php";
include "../../../../include/package.agenda.php";
include "../../../../include/package.message.php";
include "../../../../include/package.ics.php";
include "../../../../include/package.automate.php";
include "../../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

$Sortie = new Response();
Automate::demarrer(getcwd() . "/index.php");

// Gestion d'un rendez-vous par son lien (étape 6b) ################################
// Endpoint PUBLIC : le jeton du lien reçu par e-mail tient lieu d'identité. Il voyage dans le fragment de l'adresse de
// la page, puis dans le corps de ces requêtes : jamais dans une adresse, donc dans aucun journal.
// POST {jeton} → {rdv, prise} : le rendez-vous tel que le parent le voit ; `prise` (créneaux où le déplacer) s'il est déplaçable.
// POST {jeton, invitation: 1} → {nom, ics} : l'invitation de calendrier d'un rendez-vous confirmé.
// PUT  {jeton, geste: "deplacer", date, heure} → {rdv} ; créneau pris entre-temps : 400 avec `prise` à jour.
// PUT  {jeton, geste: "annuler"} → {rdv}
// Le lien suit le rendez-vous déplacé. Jeton inconnu ou révoqué (l'adresse du dossier a changé) : 404, sans détail.
// Annuler ou déplacer se fait jusqu'à $_RDV_MODIFIABLE_HEURES avant l'heure dite ; ensuite la page invite à écrire.

/** Rendez-vous que désigne le jeton du corps, ou 404. */
function rdvDuLien($R)
{
    global $Rdv, $Sortie;

    $rdv = $Rdv->parJeton(isset($R->jeton) ? $R->jeton : null);
    if ($rdv === null) {
        $Sortie->notFound("Ce lien n'est plus valable.");
    }

    return $rdv;
}

/** Ce que la page reçoit : le rendez-vous, et les créneaux où le déplacer tant qu'il est déplaçable. */
function vueDuLien($rdv)
{
    global $Rdv, $Agenda, $_RDV_MODIFIABLE_HEURES;

    $vue = $Rdv->sortieParent($rdv);

    return array(
        'rdv' => $vue,
        'prise' => $vue['deplacable'] ? $Agenda->prise($rdv->type, 'espace', (int) $rdv->id_rdv, (int) $rdv->duree) : null,
        'delai_heures' => (int) $_RDV_MODIFIABLE_HEURES,
    );
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Automate::limiter('rdv-lien', (int) $_LIMITE_CRENEAUX[0], (int) $_LIMITE_CRENEAUX[1]);
    $R = Automate::lireCorps();
    $rdv = rdvDuLien($R);

    if (isset($R->invitation)) {
        if ($rdv->statut !== 'confirme' || $rdv->date_debut <= date('Y-m-d H:i:s')) {
            $Sortie->validationError("Ce rendez-vous n'a pas d'invitation à télécharger.");
        }
        $Sortie->success(array('nom' => Ics::NOM, 'ics' => Ics::invitation($rdv, $Rdv->lienVisio($rdv))));
    }

    $Sortie->success(vueDuLien($rdv));
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    Automate::limiter('rdv', (int) $_LIMITE_RDV[0], (int) $_LIMITE_RDV[1]);
    $R = Automate::lireCorps();
    $rdv = rdvDuLien($R);
    $geste = (isset($R->geste) && is_string($R->geste)) ? $R->geste : '';

    $res = null;
    try {
        if ($geste === 'annuler') {
            $res = $Agenda->annuler($rdv);
        } elseif ($geste === 'deplacer') {
            try {
                $c = $S->lireChamps($R, array('date' => array('type' => 'date', 'requis' => true), 'heure' => array('type' => 'heure', 'requis' => true)), false);
            } catch (ErreurMetier $e) {
                $Sortie->validationError($e->getMessage());
            }
            $res = $Agenda->deplacer($rdv, $c['date'] . ' ' . $c['heure'] . ':00');
        } else {
            $Sortie->validationError("Geste inconnu.");
        }
    } catch (Throwable $e) {
        error_log("navup rdv en ligne (lien) : " . $e->getMessage());
        $Sortie->serviceUnavailable("Ce rendez-vous ne peut pas être modifié pour le moment. Réessayez dans un instant.");
    }

    if ($res['etat'] === 'refuse') {
        $Sortie->validationError("Ce rendez-vous ne peut plus être modifié en ligne. Écrivez-nous : nous trouverons une solution.", array('motif' => 'delai') + vueDuLien($Rdv->charger($rdv->id_rdv)));
    }
    if ($res['etat'] === 'pris') {
        $Sortie->validationError("Ce créneau vient d'être pris. Choisissez-en un autre.", array('motif' => 'creneau') + vueDuLien($rdv));
    }

    $Rdv->expedier((int) $rdv->id_contact);
    $Sortie->success(vueDuLien($Rdv->dernier((int) $rdv->id_rdv_origine)));
}

$Sortie->methodNotAllowed();

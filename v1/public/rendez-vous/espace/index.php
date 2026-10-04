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

// Rendez-vous d'un parent inscrit, depuis son espace personnel (étape 6b) ################################
// Sans session ici : le parent présente un billet que l'API des parents vient de lui délivrer (v1/rendez-vous/billet/,
// session vivante et accès ouvert exigés). Le billet voyage dans le corps des requêtes. Refusé (périmé, session fermée,
// compte désactivé) : 401, la page en redemande un.
// POST {billet} → {avenir: [rdv], avant: [rdv], prise, peut_prendre, telephone_connu, delai_heures}
//      prise : les créneaux d'un rendez-vous d'accompagnement ; peut_prendre : faux quand un rendez-vous d'accompagnement
//      est déjà à venir (un seul à la fois, réglable) ou qu'aucun créneau n'est proposé.
// POST {billet, invitation: id_rdv} → {nom, ics}
// POST {billet, creneaux: id_rdv} → {prise} : les créneaux où déplacer ce rendez-vous
// PUT  {billet, geste: "reserver", date, heure, canal, telephone?, note?, cle_saisie}
// PUT  {billet, geste: "deplacer", id_rdv, date, heure}
// PUT  {billet, geste: "annuler", id_rdv}
//      → la même vue que POST. Créneau pris entre-temps : 400 avec motif « creneau » et la vue à jour.
// Les rendez-vous sont ceux du dossier du compte, quel que soit leur type ; jamais une note, jamais un nom d'utilisateur.

const RDV_TYPE = 'suivi';

/** Compte du billet, ou 401. */
function compteDuBillet($R)
{
    global $Agenda, $Sortie;

    $compte = $Agenda->billet(isset($R->billet) ? $R->billet : null);
    if ($compte === null) {
        $Sortie->authError("Votre billet n'est plus valable.");
    }

    return $compte;
}

/** Rendez-vous du dossier du compte que désigne le corps, ou 404 : un parent n'agit que sur les siens. */
function rdvDuCompte($compte, $id)
{
    global $Rdv, $Sortie;

    $rdv = (is_int($id) && $id > 0) ? $Rdv->charger($id) : null;
    if ($rdv === null || (int) $rdv->id_contact !== (int) $compte->id_contact) {
        $Sortie->notFound("Rendez-vous introuvable.");
    }

    return $rdv;
}

function vueDeLEspace($compte)
{
    global $Agenda, $Contact, $_RDV_MODIFIABLE_HEURES;

    $rdv = $Agenda->duParent($compte->id_contact);
    $prise = $Agenda->prise(RDV_TYPE, 'espace');
    $aVenir = 0;
    foreach ($rdv['avenir'] as $r) {
        if ($r['type'] === RDV_TYPE) {
            $aVenir++;
        }
    }
    $contact = $Contact->charger($compte->id_contact);

    return array(
        'avenir' => $rdv['avenir'],
        'avant' => $rdv['avant'],
        'prise' => $prise,
        'peut_prendre' => $prise['ouvert'] && $aVenir < (int) Agenda::reglages(RDV_TYPE)['max_a_venir'],
        'telephone_connu' => $contact->telephone !== null && $contact->telephone !== '',
        'delai_heures' => (int) $_RDV_MODIFIABLE_HEURES,
    );
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Automate::limiter('rdv-espace', (int) $_LIMITE_CRENEAUX[0], (int) $_LIMITE_CRENEAUX[1]);
    $R = Automate::lireCorps();
    $compte = compteDuBillet($R);

    if (isset($R->invitation)) {
        $rdv = rdvDuCompte($compte, $R->invitation);
        if ($rdv->statut !== 'confirme' || $rdv->date_debut <= date('Y-m-d H:i:s')) {
            $Sortie->validationError("Ce rendez-vous n'a pas d'invitation à télécharger.");
        }
        $Sortie->success(array('nom' => Ics::NOM, 'ics' => Ics::invitation($rdv, $Rdv->lienVisio($rdv))));
    }

    if (isset($R->creneaux)) {
        $rdv = rdvDuCompte($compte, $R->creneaux);
        $Sortie->success(array('prise' => $Rdv->gestesParent($rdv)['deplacable'] ? $Agenda->prise($rdv->type, 'espace', (int) $rdv->id_rdv, (int) $rdv->duree) : null));
    }

    $Sortie->success(vueDeLEspace($compte));
}

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    Automate::limiter('rdv', (int) $_LIMITE_RDV[0], (int) $_LIMITE_RDV[1]);
    $R = Automate::lireCorps();
    $compte = compteDuBillet($R);
    $geste = (isset($R->geste) && is_string($R->geste)) ? $R->geste : '';
    $idc = (int) $compte->id_contact;

    $res = null;
    try {
        if ($geste === 'reserver') {
            try {
                $c = $S->lireChamps($R, array(
                    'date' => array('type' => 'date', 'requis' => true),
                    'heure' => array('type' => 'heure', 'requis' => true),
                    'canal' => array('type' => 'enum', 'valeurs' => Rdv::CANAUX, 'requis' => true, 'libelle' => 'façon de se parler'),
                    'telephone' => array('type' => 'tel', 'libelle' => 'téléphone'),
                    'note' => array('type' => 'text', 'max' => 500, 'libelle' => 'sujet à aborder'),
                ), false);
                $cle = $S->lireCle($R);
                if ($cle === null) {
                    throw new ErreurMetier("Clé de saisie manquante.");
                }
                $contact = $Contact->charger($idc);
                if ($c['canal'] === 'telephone' && empty($c['telephone']) && ($contact->telephone === null || $contact->telephone === '')) {
                    throw new ErreurMetier("Pour un rendez-vous par téléphone, indiquez le numéro où vous joindre.");
                }
            } catch (ErreurMetier $e) {
                $Sortie->validationError($e->getMessage());
            }
            if (!$Agenda->ouverte('espace') || !in_array($c['canal'], $Agenda->canaux(RDV_TYPE), true)) {
                $res = array('etat' => 'pris');
            } else {
                $res = $Agenda->reserver(RDV_TYPE, 'espace', array('id_contact' => $idc), $c['date'] . ' ' . $c['heure'] . ':00', $c['canal'], $c['telephone'] ?? null, $c['note'] ?? null, $cle);
            }
        } elseif ($geste === 'deplacer' || $geste === 'annuler') {
            $rdv = rdvDuCompte($compte, isset($R->id_rdv) ? $R->id_rdv : null);
            if ($geste === 'annuler') {
                $res = $Agenda->annuler($rdv);
            } else {
                try {
                    $c = $S->lireChamps($R, array('date' => array('type' => 'date', 'requis' => true), 'heure' => array('type' => 'heure', 'requis' => true)), false);
                } catch (ErreurMetier $e) {
                    $Sortie->validationError($e->getMessage());
                }
                $res = $Agenda->deplacer($rdv, $c['date'] . ' ' . $c['heure'] . ':00');
            }
        } else {
            $Sortie->validationError("Geste inconnu.");
        }
    } catch (Throwable $e) {
        error_log("navup rdv en ligne (espace) : " . $e->getMessage());
        $Sortie->serviceUnavailable("Vos rendez-vous ne peuvent pas être modifiés pour le moment. Réessayez dans un instant.");
    }

    if ($res['etat'] === 'pris') {
        $Sortie->validationError("Ce créneau vient d'être pris. Choisissez-en un autre.", array('motif' => 'creneau') + vueDeLEspace($compte));
    }
    if ($res['etat'] === 'complet') {
        $Sortie->validationError("Vous avez déjà un rendez-vous d'accompagnement à venir : déplacez-le plutôt que d'en prendre un second.", array('motif' => 'complet') + vueDeLEspace($compte));
    }
    if ($res['etat'] === 'refuse') {
        $Sortie->validationError("Ce rendez-vous ne peut plus être modifié en ligne. Écrivez-nous : nous trouverons une solution.", array('motif' => 'delai') + vueDeLEspace($compte));
    }

    $Rdv->expedier($idc);
    $Sortie->success(vueDeLEspace($compte), $geste === 'reserver' ? 201 : 200);
}

$Sortie->methodNotAllowed();

<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.saisie.php";
include "../../../include/package.contact.php";
include "../../../include/package.suivi.php";
include "../../../include/package.agenda.php";
include "../../../include/package.message.php";
include "../../../include/package.ics.php";
include "../../../include/package.automate.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json', isset($_CORS_ORIGINES_PUBLIQUES) ? $_CORS_ORIGINES_PUBLIQUES : array());

// Réponses au navigateur. Les classes métier, elles, lèvent leurs refus (ReponseAutomate) : rien ne s'arrête au milieu.
$Sortie = new Response();
Automate::demarrer(getcwd() . "/index.php");

// Rendez-vous découverte pris en ligne (étape 6b ; cahier de l'écosystème §5) ################################
// Endpoint PUBLIC, sans authentification : appelé par la page publique de l'appli des parents.
// POST {prenom, nom, email, telephone?, canal, date: AAAA-MM-JJ, heure: HH:MM, note?, confidentialite: 1, cle_saisie}
// → {suite: "email"} : un e-mail part à l'adresse saisie ; c'est lui qui confirme le rendez-vous.
//
// La réponse est la même que l'adresse soit connue ou non, qu'elle ait déjà un rendez-vous ou non : elle ne dit rien
// d'un dossier. Deux refus seulement : une saisie invalide, et un créneau qui n'est pas (ou plus) proposé, évalué
// avant toute lecture de dossier ; ce second refus rend les créneaux à jour (`prise`).
// Le créneau est choisi parmi ceux de v1/public/creneaux/ : durée et type viennent des réglages, jamais du corps.
// Garde-fous : limiteur par adresse IP, corps borné, champ leurre, clé de saisie, verrous (adresse, agenda, dossier),
// plafond quotidien (au-delà, la prise se ferme), e-mail sans rien de ce qui a été saisi.

const RDV_TYPE = 'decouverte';

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Automate::limiter('rdv', (int) $_LIMITE_RDV[0], (int) $_LIMITE_RDV[1]);
    $R = Automate::lireCorps();

    // Champ leurre, invisible du parent : un robot qui le remplit reçoit la réponse ordinaire, et rien n'est écrit
    if (isset($R->site) && $R->site !== '') {
        $Sortie->success(array('suite' => 'email'), 201);
    }

    try {
        $data = $S->lireChamps($R, array(
            'prenom' => array('type' => 'str', 'max' => 100, 'requis' => true, 'libelle' => 'prénom'),
            'nom' => array('type' => 'str', 'max' => 100, 'requis' => true),
            'email' => array('type' => 'email', 'max' => 255, 'requis' => true, 'libelle' => 'e-mail'),
            'telephone' => array('type' => 'tel', 'libelle' => 'téléphone'),
            'canal' => array('type' => 'enum', 'valeurs' => Rdv::CANAUX, 'requis' => true, 'libelle' => 'façon de se parler'),
            'date' => array('type' => 'date', 'requis' => true),
            'heure' => array('type' => 'heure', 'requis' => true),
            'note' => array('type' => 'text', 'max' => 500, 'libelle' => 'message'),
            'confidentialite' => array('type' => 'bool', 'requis' => true, 'libelle' => 'politique de confidentialité'),
        ), false);
        $cle = $S->lireCle($R);
        if ($cle === null) {
            throw new ErreurMetier("Clé de saisie manquante.");
        }
        if ((int) $data['confidentialite'] !== 1) {
            throw new ErreurMetier("Pour prendre rendez-vous, acceptez la politique de confidentialité.");
        }
        if ($data['canal'] === 'telephone' && empty($data['telephone'])) {
            throw new ErreurMetier("Pour un rendez-vous par téléphone, indiquez le numéro où vous joindre.");
        }
    } catch (ErreurMetier $e) {
        $Sortie->validationError($e->getMessage());
    }

    $debut = $data['date'] . ' ' . $data['heure'] . ':00';
    $res = null;
    try {
        // Fermé (interrupteur, plafond du jour) ou canal qui n'est pas proposé : pour la page, ce créneau n'existe pas
        if (!$Agenda->ouverte('public') || !in_array($data['canal'], $Agenda->canaux(RDV_TYPE), true)) {
            $res = array('etat' => 'pris');
        } else {
            $res = $Agenda->reserver(RDV_TYPE, 'public', array(
                'prenom' => $data['prenom'], 'nom' => $data['nom'], 'email' => $data['email'], 'telephone' => $data['telephone'] ?? null,
            ), $debut, $data['canal'], $data['telephone'] ?? null, $data['note'] ?? null, $cle);
        }
    } catch (Throwable $e) {
        // Aucun message interne ne sort d'ici : une règle métier inattendue se lit dans le journal du serveur
        error_log("navup rdv en ligne : " . $e->getMessage());
        $Sortie->serviceUnavailable("La prise de rendez-vous est momentanément indisponible. Réessayez dans un instant.");
    }

    if ($res['etat'] === 'occupe') {
        $Sortie->validationError("Votre demande est déjà en cours d'enregistrement : patientez un instant.");
    }
    if ($res['etat'] === 'pris') {
        $Sortie->validationError("Ce créneau vient d'être pris. Choisissez-en un autre.", array('motif' => 'creneau', 'prise' => $Agenda->prise(RDV_TYPE, 'public')));
    }

    // L'e-mail part après l'écriture : la confirmation et son invitation, ou le rappel du rendez-vous déjà pris
    if (isset($res['id_contact'])) {
        $Rdv->expedier($res['id_contact']);
    }
    $Sortie->success(array('suite' => 'email'), 201);
}

$Sortie->methodNotAllowed();

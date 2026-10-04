#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/essai-rdv.php
// Description: essai des rendez-vous en ligne (étape 6b), sans rien envoyer : un utilisateur d'essai qui reçoit
//              (essai.agenda, plages et lien de visio), un parent d'essai (essai.…@navup.local) qui réserve, déplace
//              et annule par les mêmes fonctions que les pages, et chaque fait contrôlé : rendez-vous, chaîne, fil du
//              dossier, e-mails déposés, message assemblé et invitation de calendrier, lien de gestion, rappel,
//              message périmé annulé, changement d'heure. purge-essais.php efface tout. Refusé en production.
// Usage:       php script-cgi/essai-rdv.php                      le scénario complet, contrôlé (code 1 au premier écart)
//              php script-cgi/essai-rdv.php --montrer            de plus, affiche l'e-mail de confirmation tel qu'il partirait
//              php script-cgi/essai-rdv.php --disponibilites=<identifiant> [--sans-visio]
//                                                                donne à un utilisateur des plages tous les jours (9 h-12 h, 14 h-18 h)
//                                                                et un lien de visio ; « essai.agenda » est créé s'il manque
//              php script-cgi/essai-rdv.php --reserver=<e-mail> [--type=decouverte|suivi] [--appli=http://127.0.0.1:4201/]
//                                                                réserve le premier créneau libre pour ce parent d'essai ;
//                                                                sortie : une ligne JSON {id_rdv, date_debut, lien}
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
include __DIR__ . "/../include/package.suivi.php";
include __DIR__ . "/../include/package.agenda.php";
include __DIR__ . "/../include/package.message.php";
include __DIR__ . "/../include/package.ics.php";
include __DIR__ . "/../include/package.automate.php";
include __DIR__ . "/../require/param.php";

if (!empty($_PROD)) {
    fwrite(STDERR, "Refusé en production.\n");
    exit(1);
}

$options = getopt('', array('montrer', 'disponibilites::', 'sans-visio', 'reserver::', 'type::', 'appli::'));
if (isset($options['appli']) && (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '')) {
    $_APP_PARENTS_URL = $options['appli'];
}

Automate::demarrer(__FILE__);

/** Plages d'essai : tous les jours, 9 h-12 h et 14 h-18 h. */
function donnerPlages($id_users, $visio)
{
    global $SQL, $Agenda;

    $plages = array();
    for ($jour = 1; $jour <= 7; $jour++) {
        $plages[] = array('jour' => $jour, 'debut' => '09:00', 'fin' => '12:00');
        $plages[] = array('jour' => $jour, 'debut' => '14:00', 'fin' => '18:00');
    }
    $SQL->begin_transaction();
    $Agenda->ecrirePlages($id_users, $plages);
    $Agenda->ecrireLienVisio($id_users, $visio ? 'https://visio.exemple.test/navup-essai' : null);
    $SQL->commit();
}

function parentEssai($email)
{
    if (!preg_match('/^essai\.[a-z0-9.-]+@navup\.local$/', $email)) {
        fwrite(STDERR, "L'e-mail d'un parent d'essai est en essai.…@navup.local (purge-essais.php l'efface).\n");
        exit(1);
    }

    return $email;
}

function cle()
{
    $h = bin2hex(random_bytes(14));

    // Marque des essais : purge-essais.php reconnaît les clés en e55a1e55-…
    return 'e55a1e55-' . substr($h, 0, 4) . '-' . substr($h, 4, 4) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 12);
}

/** L'utilisateur d'essai qui reçoit (essai.agenda, profil accompagnement) : créé s'il manque. purge-essais.php l'efface. */
function receveurEssai()
{
    global $Mysql;

    $u = $Mysql->fetchOne("SELECT id_users FROM u_users WHERE identifiant = 'essai.agenda'");
    if ($u !== null) {
        return (int) $u->id_users;
    }
    $Mysql->execute(
        "INSERT INTO u_users (identifiant, email, mdp, nom, prenom, profil, actif) VALUES ('essai.agenda', 'essai.agenda@navup.local', ?, 'Agenda', 'Essai', 'accompagnement', 1)",
        array(password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)),
        's'
    );

    return $Mysql->lastId();
}

// Plages pour un utilisateur (essai.agenda est créé s'il manque) ########################################
if (isset($options['disponibilites'])) {
    if ($options['disponibilites'] === 'essai.agenda') {
        receveurEssai();
    }
    $u = $Mysql->fetchOne("SELECT id_users FROM u_users WHERE identifiant = ? AND actif = 1", array($options['disponibilites']), 's');
    if ($u === null) {
        fwrite(STDERR, "Utilisateur introuvable.\n");
        exit(1);
    }
    donnerPlages((int) $u->id_users, !isset($options['sans-visio']));
    echo json_encode(array('id_users' => (int) $u->id_users, 'creneaux' => count($Agenda->libres('decouverte')))) . "\n";
    exit(0);
}

// Réservation du premier créneau libre ########################################
if (isset($options['reserver'])) {
    $email = parentEssai(strtolower($options['reserver']));
    $type = isset($options['type']) ? $options['type'] : 'decouverte';
    $libres = array_keys($Agenda->libres($type));
    if (count($libres) === 0) {
        fwrite(STDERR, "Aucun créneau libre : donnez des plages à un utilisateur (--disponibilites=<identifiant>).\n");
        exit(1);
    }
    $dossier = $Mysql->fetchOne("SELECT id_contact FROM d_contact WHERE email = ?", array($email), 's');
    $qui = ($type !== 'decouverte' && $dossier !== null)
        ? array('id_contact' => (int) $dossier->id_contact)
        : array('prenom' => 'Camille', 'nom' => 'Essai-Rdv', 'email' => $email, 'telephone' => null);
    $res = $Agenda->reserver($type, isset($qui['id_contact']) ? 'espace' : 'public', $qui, $libres[0], 'telephone', '0600000000', null, cle());
    if ($res['etat'] !== 'reserve') {
        fwrite(STDERR, "Réservation impossible : " . $res['etat'] . ".\n");
        exit(1);
    }
    $Rdv->expedier($res['id_contact']);
    $r = $Rdv->charger($res['id_rdv']);
    echo json_encode(array('id_rdv' => (int) $r->id_rdv, 'id_contact' => (int) $r->id_contact, 'date_debut' => $r->date_debut, 'lien' => $Rdv->lienGestion($r)), JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Scénario complet ########################################

$echecs = 0;
function controle($ok, $libelle, $detail = '')
{
    global $echecs;

    echo ($ok ? 'OK    ' : 'ÉCHEC ') . $libelle . ($detail !== '' ? ' : ' . $detail : '') . "\n";
    if (!$ok) {
        $echecs++;
    }
}
$un = function ($sql, $params = array()) use ($Mysql) {
    return $Mysql->fetchOne($sql, $params);
};

if (!isset($_APP_PARENTS_URL) || $_APP_PARENTS_URL === '') {
    $_APP_PARENTS_URL = 'http://127.0.0.1:4201/';
}

// Un utilisateur d'essai qui reçoit
$idu = receveurEssai();
donnerPlages($idu, true);

$email = 'essai.rdv.' . bin2hex(random_bytes(3)) . '@navup.local';
$qui = array('prenom' => 'Zoé-Saisie', 'nom' => 'Essai-Rdv', 'email' => $email, 'telephone' => '0611223344');
$carte = $Agenda->libres('decouverte');
$libres = array_keys($carte);
controle(count($libres) >= 3, "des créneaux sont proposés", count($libres) . " créneau(x)");
$premier = $libres[0];
$second = $libres[count($libres) - 1];
// D'autres utilisateurs peuvent recevoir aussi (ceux d'un parcours, ou de vrais) : un créneau se prend autant de fois qu'il a de personnes libres
$capacite = count($carte[$premier]);

// 1. Réservation
$cle = cle();
$res = $Agenda->reserver('decouverte', 'public', $qui, $premier, 'visio', $qui['telephone'], 'Notre fils ne décroche plus de son téléphone.', $cle);
controle($res['etat'] === 'reserve', "réservation du premier créneau", $premier);
$idc = (int) $res['id_contact'];
$idr = (int) $res['id_rdv'];
$Rdv->expedier($idc);
$r = $Rdv->charger($idr);
controle($r->statut === 'confirme' && (int) $r->prevenir === 1 && (int) $r->id_rdv_origine === $idr && (int) $r->rang === 0 && $r->id_users_responsable !== null,
    "rendez-vous confirmé, origine de sa chaîne, attribué, parent prévenu");
$fait = $un("SELECT origine, JSON_VALUE(details, '$.via') AS via FROM d_evenement WHERE objet_type = 'rdv' AND objet_id = ? AND JSON_VALUE(details, '$.action') = 'creation'", array($idr));
controle($fait !== null && $fait->origine === 'parent' && $fait->via === 'public', "fait « création » d'origine parent dans le fil");
$declare = $Rdv->reservation($r);
controle($declare !== null && $declare->voie === 'public' && $declare->note !== null && $declare->telephone === '0611223344', "ce que le parent a déclaré est gardé à part");
controle((int) $un("SELECT COUNT(*) AS n FROM d_consentement WHERE id_rdv = ? AND type = 'confidentialite' AND accorde = 1", array($idr))->n === 1, "consentement enregistré");
controle($Contact->charger($idc)->statut === 'rdv_planifie', "le prospect passe « RDV planifié »");
$conf = $un("SELECT * FROM m_message WHERE objet_type = 'rdv' AND objet_id = ? AND modele = 'rdv_confirmation'", array($idr));
$avis = $un("SELECT * FROM m_message WHERE objet_type = 'rdv' AND objet_id = ? AND modele = 'rdv_avis'", array($idr));
controle($conf !== null && $conf->etat === 'envoye' && $conf->destinataire === $email, "e-mail de confirmation parti à l'adresse saisie", $conf === null ? 'absent' : $conf->etat . ' (' . $conf->mode . ')');
$responsable = $un("SELECT email FROM u_users WHERE id_users = ?", array((int) $r->id_users_responsable));
controle($avis !== null && $avis->etat === 'envoye' && $avis->destinataire === $responsable->email, "avis parti au responsable");
controle($conf !== null && strpos($conf->corps, 'Zoé-Saisie') === false && strpos($conf->corps, '0611223344') === false && strpos($conf->corps, 'décroche') === false
    && strpos($conf->corps, 'rendez-vous#') === false && strpos($conf->corps, Message::MARQUE_RDV) !== false,
    "le corps gardé ne recopie rien de la saisie et ne porte que la marque du lien");
controle($avis !== null && strpos($avis->corps, 'Découverte · Zoé-Saisie E.') !== false && strpos($avis->corps, 'Essai-Rdv') === false && strpos($avis->corps, 'décroche') === false,
    "l'avis dit le type, le prénom et l'initiale, rien de plus");
controle((int) $un("SELECT COUNT(*) AS n FROM d_evenement WHERE id_contact = ? AND type = 'email'", array($idc))->n === 1, "l'avis interne n'est pas dans le fil du dossier");

// 2. Message assemblé et invitation
list($sujet, $entetes, $message) = $Message->apercu($conf->id_message);
$frontiere = preg_match('/boundary="([^"]+)"/', $entetes, $m) ? $m[1] : null;
$parties = $frontiere === null ? array() : explode("--$frontiere", $message);
controle($frontiere !== null && count($parties) === 4 && trim($parties[3]) === '--', "message en deux parties (texte, invitation)");
$ics = '';
if (count($parties) === 4) {
    list($entetesPiece, $base64) = explode("\n\n", ltrim($parties[2], "\n"), 2);
    $ics = base64_decode(str_replace("\n", '', $base64));
    controle(strpos($entetesPiece, 'text/calendar') !== false && strpos($entetesPiece, 'filename="' . Ics::NOM . '"') !== false, "la pièce est un calendrier nommé " . Ics::NOM);
    controle(preg_match('/rendez-vous#[0-9a-f]{48}/', $parties[1]) === 1, "le lien de gestion est posé à l'envoi");
}
$lignes = explode("\r\n", rtrim($ics, "\r\n"));
$longues = array_filter($lignes, function ($l) {
    return strlen($l) > 75;
});
controle(strpos($ics, "\r\n") !== false && count($longues) === 0 && $lignes[0] === 'BEGIN:VCALENDAR' && end($lignes) === 'END:VCALENDAR', "invitation en lignes de 75 octets au plus, CRLF");
controle(strpos($ics, 'UID:rdv-' . $idr . '@') !== false && strpos($ics, 'SEQUENCE:0') !== false && strpos($ics, 'DTSTART:' . Ics::utc($premier)) !== false
    && strpos($ics, 'METHOD:PUBLISH') !== false && strpos($ics, 'visio.exemple.test') !== false, "invitation : identifiant de la chaîne, rang 0, heure en UTC, lien de visio");
controle(strpos($ics, 'décroche') === false && strpos($ics, 'rendez-vous#') === false && strpos($ics, 'Zoé') === false, "ni note, ni lien de gestion, ni identité dans l'invitation");
if (isset($options['montrer'])) {
    echo "\n----- Sujet : $sujet\n$entetes\n$message\n----- Invitation décodée :\n$ics-----\n\n";
}

// 3. Rejeu, double réservation, créneau forgé, second rendez-vous
$rejeu = $Agenda->reserver('decouverte', 'public', $qui, $premier, 'visio', null, null, $cle);
controle($rejeu['etat'] === 'reserve' && (int) $rejeu['id_rdv'] === $idr && (int) $un("SELECT COUNT(*) AS n FROM r_rdv WHERE id_contact = ?", array($idc))->n === 1, "clé de saisie rejouée : un seul rendez-vous");
$autre = array('prenom' => 'Léa', 'nom' => 'Essai-Rdv', 'email' => 'essai.rdv.' . bin2hex(random_bytes(3)) . '@navup.local', 'telephone' => null);
$reste = count($Agenda->libres('decouverte')[$premier] ?? array());
controle($reste === $capacite - 1, "le créneau réservé compte une personne libre de moins", "$capacite, puis $reste");
// On épuise le créneau avec d'autres adresses : la demande qui suit la dernière place est refusée, sans créer de dossier
for ($i = 0; $i < $reste; $i++) {
    $Agenda->reserver('decouverte', 'public', array('prenom' => 'Léa', 'nom' => 'Essai-Rdv', 'email' => 'essai.rdv.' . bin2hex(random_bytes(3)) . '@navup.local', 'telephone' => null), $premier, 'visio', null, null, cle());
}
$double = $Agenda->reserver('decouverte', 'public', $autre, $premier, 'visio', null, null, cle());
controle($double['etat'] === 'pris' && !isset($Agenda->libres('decouverte')[$premier]), "un créneau complet n'est plus proposé, et se refuse", $double['etat']);
controle($un("SELECT id_contact FROM d_contact WHERE email = ?", array($autre['email'])) === null, "créneau refusé : aucun dossier créé");
$dimanche = date('Y-m-d', strtotime('next sunday +7 days')) . ' 03:00:00';
controle($Agenda->reserver('decouverte', 'public', $autre, $dimanche, 'visio', null, null, cle())['etat'] === 'pris', "créneau forgé (dimanche 3 h) refusé");
controle($Agenda->reserver('decouverte', 'public', $autre, date('Y-m-d H:i:s', time() + 3600), 'visio', null, null, cle())['etat'] === 'pris', "créneau trop proche refusé");
$complet = $Agenda->reserver('decouverte', 'public', $qui, $second, 'visio', null, null, cle());
$Rdv->expedier($idc);
$dejaMsg = $un("SELECT * FROM m_message WHERE id_contact = ? AND modele = 'rdv_deja'", array($idc));
controle($complet['etat'] === 'complet' && $dejaMsg !== null && strpos($dejaMsg->corps, 'Zoé') === false, "même adresse, second créneau : rien de réservé, un e-mail le rappelle");

// 4. Lien de gestion, déplacement
$lien = $Rdv->lienGestion($r);
$jeton = substr($lien, strpos($lien, '#') + 1);
controle($Rdv->parJeton($jeton) !== null && (int) $Rdv->parJeton($jeton)->id_rdv === $idr && $Rdv->parJeton(str_repeat('0', 48)) === null, "le jeton désigne le rendez-vous ; un faux, rien");
controle((int) $un("SELECT COUNT(*) AS n FROM r_lien WHERE jeton = ?", array($jeton))->n === 0, "seule l'empreinte du jeton est en base");
$dep = $Agenda->deplacer($Rdv->parJeton($jeton), $second);
$Rdv->expedier($idc);
$idn = (int) ($dep['id_rdv'] ?? 0);
$n = $idn > 0 ? $Rdv->charger($idn) : null;
controle($dep['etat'] === 'deplace' && $n !== null && $n->statut === 'confirme' && (int) $n->id_rdv_origine === $idr && (int) $n->rang === 1 && $n->date_debut === $second
    && $Rdv->charger($idr)->statut === 'reporte', "déplacement : nouveau créneau confirmé au rang 1, l'ancien « reporté »");
controle((int) $Rdv->parJeton($jeton)->id_rdv === $idn, "le lien suit le rendez-vous déplacé");
controle(isset($Agenda->libres('decouverte')[$premier]), "l'ancien créneau est de nouveau proposé");
$modif = $un("SELECT * FROM m_message WHERE objet_type = 'rdv' AND objet_id = ? AND modele = 'rdv_modification'", array($idn));
controle($modif !== null && $modif->etat === 'envoye', "e-mail de modification parti");
list(, , $messageModif) = $Message->apercu($modif->id_message);
$icsModif = preg_match('/\n\n([A-Za-z0-9+\/=\n]+)\n--/', substr($messageModif, strpos($messageModif, 'text/calendar')), $m) ? base64_decode(str_replace("\n", '', $m[1])) : '';
controle(strpos($icsModif, 'UID:rdv-' . $idr . '@') !== false && strpos($icsModif, 'SEQUENCE:1') !== false && strpos($icsModif, 'DTSTART:' . Ics::utc($second)) !== false,
    "invitation du nouveau créneau : même identifiant, rang 1");
controle((int) $un("SELECT COUNT(*) AS n FROM m_message WHERE id_contact = ? AND modele = 'rdv_avis'", array($idc))->n === 2, "second avis au responsable (déplacé)");

// 5. Annulation, message périmé
$Mysql->execute("UPDATE m_message SET etat = 'a_envoyer', essais = 0 WHERE id_message = ?", array((int) $modif->id_message));
$ann = $Agenda->annuler($Rdv->charger($idn));
controle($ann['etat'] === 'annule' && $Rdv->charger($idn)->statut === 'annule', "annulation par le parent");
$Rdv->expedier($idc);
controle($un("SELECT etat FROM m_message WHERE id_message = ?", array((int) $modif->id_message))->etat === 'annule', "un message dont le rendez-vous ne tient plus est annulé, pas envoyé");
$annMsg = $un("SELECT * FROM m_message WHERE objet_type = 'rdv' AND objet_id = ? AND modele = 'rdv_annulation'", array($idn));
controle($annMsg !== null && $annMsg->etat === 'envoye' && strpos($Message->apercu($annMsg->id_message)[1], 'multipart') === false, "e-mail d'annulation parti, sans pièce jointe");
$avisAnn = $un("SELECT corps FROM m_message WHERE objet_type = 'rdv' AND objet_id = ? AND modele = 'rdv_avis' AND cle LIKE '%:annule:%'", array($idn));
controle($avisAnn !== null && strpos($avisAnn->corps, 'à relancer') !== false, "l'avis d'annulation signale un prospect sans rendez-vous");
controle($Agenda->annuler($Rdv->charger($idn))['etat'] === 'annule' && (int) $un("SELECT COUNT(*) AS n FROM m_message WHERE objet_id = ? AND modele = 'rdv_annulation'", array($idn))->n === 1, "annulation rejouée : sans effet");

// 6. Délai : un rendez-vous trop proche ne se modifie plus en ligne ; rappel
$contact = $Contact->charger($idc);
$proche = $Rdv->creer($contact, array('type' => 'decouverte', 'duree' => 30, 'canal' => 'telephone', 'id_users_responsable' => $idu), date('Y-m-d H:i:00', time() + 2 * 3600), 'confirme', cle(), null, array('prevenir' => true));
$rp = $Rdv->charger($proche['id_rdv']);
controle($Agenda->annuler($rp)['etat'] === 'refuse' && $Agenda->deplacer($rp, $premier)['etat'] === 'refuse' && $Rdv->charger($rp->id_rdv)->statut === 'confirme', "à moins de $_RDV_MODIFIABLE_HEURES h, ni annulation ni déplacement en ligne");
$avant = $Rdv->rappeler();
$Mysql->execute("UPDATE r_rdv SET date_statut = date_debut - INTERVAL 48 HOUR WHERE id_rdv = ?", array((int) $rp->id_rdv));
$rappels = $Rdv->rappeler();
controle($avant === 0 && $rappels === 1 && $Rdv->rappeler() === 0, "rappel : aucun pour un rendez-vous pris peu avant, un seul sinon", "$avant, $rappels");

// 7. Adresse du dossier changée : liens révoqués, message en attente annulé
$rappel = $un("SELECT id_message FROM m_message WHERE cle = ?", array('rdv:rdv_rappel:' . (int) $rp->id_rdv));
$SQL->begin_transaction();
$Mysql->execute("UPDATE d_contact SET email = ? WHERE id_contact = ?", array('essai.rdv.change.' . bin2hex(random_bytes(3)) . '@navup.local', $idc));
$Rdv->revoquerLiens($idc);
$SQL->commit();
controle($Rdv->parJeton($jeton) === null, "adresse changée : le lien de gestion ne vaut plus");
controle($Message->envoyer($rappel->id_message) === null && $un("SELECT etat FROM m_message WHERE id_message = ?", array((int) $rappel->id_message))->etat === 'annule', "adresse changée : le message en attente n'est pas envoyé à l'ancienne");

// 8. Changement d'heure
controle(Ics::utc('2026-10-26 09:00:00') === '20261026T080000Z' && Ics::utc('2026-10-23 09:00:00') === '20261023T070000Z', "9 h de Paris : 8 h UTC en hiver, 7 h UTC en été");
$paris = new DateTimeZone('Europe/Paris');
controle(!Agenda::heureExiste('2027-03-28 02:30:00', $paris) && Agenda::heureExiste('2027-03-28 03:30:00', $paris), "2 h 30 n'existe pas la nuit du passage à l'heure d'été");
controle(strlen(Ics::plier('DESCRIPTION:' . str_repeat('é', 80))) > 0 && max(array_map('strlen', explode("\r\n", Ics::plier('DESCRIPTION:' . str_repeat('é', 80))))) <= 75
    && str_replace("\r\n ", '', Ics::plier('DESCRIPTION:' . str_repeat('é', 80))) === 'DESCRIPTION:' . str_repeat('é', 80), "pliage sans couper un caractère accentué");

echo $echecs === 0 ? "Rendez-vous en ligne : aucun écart.\n" : "$echecs écart(s).\n";
exit($echecs === 0 ? 0 : 1);

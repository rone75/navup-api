<?php

//=======================================================================
// File:        package.ics.php
// Description: calendriers au format iCalendar (RFC 5545), étape 6b : l'invitation jointe à l'e-mail d'un rendez-vous
//              confirmé, et le flux d'agenda auquel un utilisateur abonne son calendrier.
//              Fonctions pures : une ligne de r_rdv (Rdv::COLONNES) en entrée, du texte en sortie. Le titre d'un
//              rendez-vous dans le flux vient de Rdv::titre() (package.suivi.php).
//              Heures en UTC (suffixe Z) : aucun fuseau à déclarer, l'heure de Paris est convertie ici.
//              Jamais de note interne, de téléphone ni de lien de gestion dans un calendrier : il se synchronise
//              sur des serveurs tiers. L'invitation est un fichier à importer (METHOD:PUBLISH), pas une invitation
//              à laquelle on répond : accepter ou refuser dans un agenda ne dirait rien à NavUp.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Ics
{
    const NOM = 'rendez-vous-navup.ics';

    const TYPES_PARENT = array(
        'decouverte' => 'Rendez-vous découverte NavUp', 'suivi' => "Rendez-vous d'accompagnement NavUp",
        'bilan' => 'Rendez-vous de bilan NavUp', 'autre' => 'Rendez-vous NavUp',
    );
    const CANAUX = array('visio' => 'en visio', 'telephone' => 'par téléphone', 'presentiel' => 'en personne');

    /** Date et heure de Paris (AAAA-MM-JJ HH:MM:SS) → « AAAAMMJJTHHMMSSZ ». $minutes : décalage ajouté (durée). */
    public static function utc($moment, $minutes = 0)
    {
        $d = new DateTimeImmutable($moment, new DateTimeZone('Europe/Paris'));
        $d = $d->setTimezone(new DateTimeZone('UTC'));
        if ($minutes !== 0) {
            $d = $d->modify(($minutes > 0 ? '+' : '') . (int) $minutes . ' minutes');
        }

        return $d->format('Ymd\THis\Z');
    }

    /** Échappe un texte (RFC 5545 §3.3.11) : barre oblique inverse, point-virgule, virgule, retour à la ligne. */
    public static function texte($t)
    {
        $t = str_replace(array("\r\n", "\r"), "\n", (string) $t);

        return str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\;', '\,', '\n'), $t);
    }

    /** Plie une ligne à 75 octets (RFC 5545 §3.1), sans couper un caractère UTF-8. */
    public static function plier($ligne)
    {
        $out = '';
        $max = 75;
        while (strlen($ligne) > $max) {
            $coupe = $max;
            // Un octet de continuation UTF-8 (10xxxxxx) ne commence pas une ligne
            while ($coupe > 1 && (ord($ligne[$coupe]) & 0xC0) === 0x80) {
                $coupe--;
            }
            $out .= substr($ligne, 0, $coupe) . "\r\n ";
            $ligne = substr($ligne, $coupe);
            $max = 74;   // la ligne de suite commence par une espace, comptée dans les 75
        }

        return $out . $ligne;
    }

    private static function assembler($lignes)
    {
        return implode("\r\n", array_map(array('Ics', 'plier'), $lignes)) . "\r\n";
    }

    /** Domaine des identifiants d'événement : celui de l'expéditeur des e-mails, à défaut navup.fr. */
    private static function domaine()
    {
        global $_MAIL_EXPEDITEUR_PARENTS;

        $de = isset($_MAIL_EXPEDITEUR_PARENTS) ? (string) $_MAIL_EXPEDITEUR_PARENTS : '';
        $pos = strrpos($de, '@');

        return ($pos !== false && filter_var($de, FILTER_VALIDATE_EMAIL) !== false) ? substr($de, $pos + 1) : 'navup.fr';
    }

    /**
     * Identifiant d'un rendez-vous dans un calendrier : celui du premier créneau de sa chaîne. Un rendez-vous déplacé
     * garde donc son identifiant, et son rang (SEQUENCE) dit quelle version est la plus récente.
     */
    public static function uid($r)
    {
        return 'rdv-' . (int) ($r->id_rdv_origine ?? $r->id_rdv) . '@' . self::domaine();
    }

    /**
     * Invitation jointe à l'e-mail du parent : un événement, à importer dans son agenda.
     * $visio : lien de visio de la personne qui reçoit, ou null.
     */
    public static function invitation($r, $visio = null)
    {
        global $_MAIL_EXPEDITEUR_PARENTS;

        $lieu = $r->canal === 'visio' ? ($visio !== null && $visio !== '' ? $visio : 'En visio') : ($r->canal === 'telephone' ? 'Par téléphone' : 'En personne');
        $description = $r->canal === 'visio'
            ? ($visio !== null && $visio !== '' ? "En visio : " . $visio : "En visio : nous vous envoyons le lien avant le rendez-vous.")
            : ($r->canal === 'telephone' ? "Par téléphone : nous vous appelons." : "En personne.");
        $description .= "\nPour le déplacer ou l'annuler, utilisez le lien de l'e-mail de confirmation.";

        $lignes = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NavUp//Rendez-vous//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . self::uid($r),
            'SEQUENCE:' . (int) $r->rang,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . self::utc($r->date_debut),
            'DTEND:' . self::utc($r->date_debut, (int) $r->duree),
            'SUMMARY:' . self::texte(self::TYPES_PARENT[$r->type] ?? 'Rendez-vous NavUp'),
            'DESCRIPTION:' . self::texte($description),
            'LOCATION:' . self::texte($lieu),
            'STATUS:CONFIRMED',
        );
        $de = isset($_MAIL_EXPEDITEUR_PARENTS) ? (string) $_MAIL_EXPEDITEUR_PARENTS : '';
        if (filter_var($de, FILTER_VALIDATE_EMAIL) !== false) {
            $lignes[] = 'ORGANIZER;CN=NavUp:mailto:' . $de;
        }
        $lignes[] = 'END:VEVENT';
        $lignes[] = 'END:VCALENDAR';

        return self::assembler($lignes);
    }

    /**
     * Flux d'agenda d'un utilisateur : ses rendez-vous, un événement chacun. $fiche : adresse de la fiche d'un
     * rendez-vous dans l'outil, complétée de son numéro (ou null).
     */
    public static function flux($rdvs, $fiche = null)
    {
        $lignes = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NavUp//Agenda//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:NavUp',
            'X-WR-TIMEZONE:Europe/Paris',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        );
        $etats = array('a_confirmer' => 'à confirmer', 'confirme' => 'confirmé', 'effectue' => 'effectué', 'absent' => 'absent');
        foreach ($rdvs as $r) {
            $description = ucfirst(self::CANAUX[$r->canal] ?? '') . ', ' . ($etats[$r->statut] ?? $r->statut) . '.';
            if ($fiche !== null) {
                $description .= "\n" . $fiche . (int) $r->id_rdv;
            }
            $lignes[] = 'BEGIN:VEVENT';
            $lignes[] = 'UID:' . self::uid($r);
            $lignes[] = 'SEQUENCE:' . (int) $r->rang;
            $lignes[] = 'DTSTAMP:' . self::utc($r->date_statut);
            $lignes[] = 'DTSTART:' . self::utc($r->date_debut);
            $lignes[] = 'DTEND:' . self::utc($r->date_debut, (int) $r->duree);
            $lignes[] = 'SUMMARY:' . self::texte(Rdv::titre($r) . ($r->statut === 'a_confirmer' ? ' (à confirmer)' : ''));
            $lignes[] = 'DESCRIPTION:' . self::texte($description);
            $lignes[] = 'STATUS:' . ($r->statut === 'a_confirmer' ? 'TENTATIVE' : 'CONFIRMED');
            $lignes[] = 'TRANSP:OPAQUE';
            $lignes[] = 'END:VEVENT';
        }
        $lignes[] = 'END:VCALENDAR';

        return self::assembler($lignes);
    }
}

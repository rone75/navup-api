<?php

//=======================================================================
// File:        package.totp.php
// Description: double authentification (CDC §22 ; étape 8) : codes TOTP (RFC 6238, HMAC-SHA1, 6 chiffres, pas de 30 s),
//              secret chiffré en base (sodium, clé $_CLE_TOTP), codes de secours, défi de connexion.
//              Aucune librairie : base32 et HOTP sont écrits ici, vérifiés par script-cgi/verifier-totp.php.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Totp
{
    const PAS = 30;
    const CHIFFRES = 6;
    // Un pas de part et d'autre : l'horloge du téléphone peut avancer ou retarder de 30 s
    const FENETRE = 1;
    const NB_SECOURS = 10;
    const DEFI_MINUTES = 5;
    const DEFI_ESSAIS = 5;
    const EMETTEUR = 'NavUp';

    const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    // Codes de secours : sans caractères ambigus (0/O, 1/I/L)
    const ALPHABET_SECOURS = 'abcdefghjkmnpqrstuvwxyz23456789';

    // BASE32 (RFC 4648, sans remplissage) ##############################

    public static function base32($octets)
    {
        $bits = '';
        foreach (str_split($octets) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $morceau) {
            $out .= self::BASE32[bindec(str_pad($morceau, 5, '0'))];
        }
        return $out;
    }

    public static function base32Lire($texte)
    {
        $texte = strtoupper(preg_replace('/[\s=-]/', '', (string) $texte));
        $bits = '';
        foreach (str_split($texte) as $c) {
            $v = strpos(self::BASE32, $c);
            if ($v === false) {
                return null;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $out .= chr(bindec($octet));
            }
        }
        return $out;
    }

    // CODES ############################################################

    /** HOTP (RFC 4226) : le code du compteur donné. */
    public static function hotp($cle, $compteur, $chiffres = self::CHIFFRES, $algo = 'sha1')
    {
        $msg = pack('J', (int) $compteur);
        $h = hash_hmac($algo, $msg, $cle, true);
        $o = ord($h[strlen($h) - 1]) & 0x0f;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
    }

    public static function pas($t = null)
    {
        return intdiv($t === null ? time() : (int) $t, self::PAS);
    }

    /**
     * Le pas du code s'il est juste dans la fenêtre et postérieur au dernier accepté (anti-rejeu), sinon null.
     */
    public static function verifier($cle, $code, $dernier_pas = null, $t = null)
    {
        $code = preg_replace('/\s/', '', (string) $code);
        if (!preg_match('/^\d{' . self::CHIFFRES . '}$/', $code)) {
            return null;
        }
        $maintenant = self::pas($t);
        for ($d = -self::FENETRE; $d <= self::FENETRE; $d++) {
            $p = $maintenant + $d;
            if ($dernier_pas !== null && $p <= (int) $dernier_pas) {
                continue;
            }
            if (hash_equals(self::hotp($cle, $p), $code)) {
                return $p;
            }
        }
        return null;
    }

    public static function nouveauSecret()
    {
        return random_bytes(20);
    }

    /** Adresse lue par l'application d'authentification (QR code) : otpauth://totp/NavUp:identifiant?… */
    public static function adresse($identifiant, $cle)
    {
        $etiquette = rawurlencode(self::EMETTEUR) . ':' . rawurlencode($identifiant);
        return 'otpauth://totp/' . $etiquette . '?secret=' . self::base32($cle) . '&issuer=' . rawurlencode(self::EMETTEUR)
            . '&algorithm=SHA1&digits=' . self::CHIFFRES . '&period=' . self::PAS;
    }

    // CHIFFREMENT DU SECRET ############################################

    private static function cleChiffrement()
    {
        global $_CLE_TOTP;
        $cle = isset($_CLE_TOTP) && is_string($_CLE_TOTP) && preg_match('/^[0-9a-f]{64}$/i', $_CLE_TOTP) ? hex2bin($_CLE_TOTP) : null;
        if ($cle === null) {
            throw new RuntimeException('Clé $_CLE_TOTP absente ou invalide dans require/secret.php');
        }
        return $cle;
    }

    public static function disponible()
    {
        try {
            self::cleChiffrement();
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public static function chiffrer($secret)
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, self::cleChiffrement()));
    }

    public static function dechiffrer($blob)
    {
        $brut = base64_decode((string) $blob, true);
        if ($brut === false || strlen($brut) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($brut, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clair = sodium_crypto_secretbox_open(substr($brut, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::cleChiffrement());
        return $clair === false ? null : $clair;
    }

    // CODES DE SECOURS #################################################

    /** « abcd-efgh » : forme normale d'un code saisi (minuscules, sans espace ni tiret), ou null. */
    public static function secoursNormal($code)
    {
        $c = strtolower(preg_replace('/[\s-]/', '', (string) $code));
        return preg_match('/^[' . self::ALPHABET_SECOURS . ']{8}$/', $c) ? $c : null;
    }

    public static function empreinteSecours($code)
    {
        return hash_hmac('sha256', 'secours:' . $code, self::cleChiffrement());
    }

    /** Remplace les codes de secours d'un utilisateur ; rend les dix nouveaux, en clair, à montrer une fois. */
    public static function nouveauxSecours($id_users)
    {
        global $Mysql;
        $Mysql->execute("DELETE FROM u_secours WHERE id_users = ?", array((int) $id_users), 'i');
        $codes = array();
        $max = strlen(self::ALPHABET_SECOURS) - 1;
        while (count($codes) < self::NB_SECOURS) {
            $c = '';
            for ($i = 0; $i < 8; $i++) {
                $c .= self::ALPHABET_SECOURS[random_int(0, $max)];
            }
            if (isset($codes[$c])) {
                continue;
            }
            $codes[$c] = true;
            $Mysql->execute("INSERT INTO u_secours (id_users, empreinte) VALUES (?, ?)", array((int) $id_users, self::empreinteSecours($c)), 'is');
        }
        return array_map(function ($c) {
            return substr($c, 0, 4) . '-' . substr($c, 4);
        }, array_keys($codes));
    }

    public static function secoursRestants($id_users)
    {
        global $Mysql;
        $r = $Mysql->fetchOne("SELECT COUNT(*) AS n FROM u_secours WHERE id_users = ? AND date_utilisation IS NULL", array((int) $id_users), 'i');
        return (int) $r->n;
    }

    // VÉRIFICATION D'UN UTILISATEUR ####################################

    /**
     * Vérifie un code d'application ou un code de secours pour un utilisateur dont la double authentification est active.
     * Consomme le code (pas retenu, ou code de secours marqué utilisé). Rend 'totp', 'secours' ou null.
     */
    public static function controler($user, $code)
    {
        global $Mysql;
        $id = (int) $user->id_users;
        $code = trim((string) $code);

        if (preg_match('/^\d[\d\s]*$/', $code)) {
            $cle = self::dechiffrer($user->totp_secret);
            if ($cle === null) {
                return null;
            }
            $p = self::verifier($cle, $code, $user->totp_dernier_pas);
            if ($p === null) {
                return null;
            }
            // Pas plus récent que le dernier : condition dans la requête, contre deux envois simultanés du même code
            $n = $Mysql->execute(
                "UPDATE u_users SET totp_dernier_pas = ? WHERE id_users = ? AND (totp_dernier_pas IS NULL OR totp_dernier_pas < ?)",
                array($p, $id, $p),
                'iii'
            );
            return $n > 0 ? 'totp' : null;
        }

        $s = self::secoursNormal($code);
        if ($s === null) {
            return null;
        }
        $n = $Mysql->execute(
            "UPDATE u_secours SET date_utilisation = NOW() WHERE id_users = ? AND empreinte = ? AND date_utilisation IS NULL",
            array($id, self::empreinteSecours($s)),
            'is'
        );
        return $n > 0 ? 'secours' : null;
    }

    // DÉFI DE CONNEXION ################################################

    public static function creerDefi($id_users)
    {
        global $Mysql, $U;
        $Mysql->execute("DELETE FROM u_defi WHERE date_expiration <= NOW() OR id_users = ?", array((int) $id_users), 'i');
        $defi = $U->genToken(32);
        $Mysql->execute(
            "INSERT INTO u_defi (defi, id_users, date_expiration) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
            array(hash('sha256', $defi), (int) $id_users, self::DEFI_MINUTES),
            'sii'
        );
        return $defi;
    }

    /** La ligne du défi encore valable (avec id_users, essais), ou null. */
    public static function lireDefi($defi)
    {
        global $Mysql;
        if (!is_string($defi) || !preg_match('/^[A-Za-z0-9]{32}$/', $defi)) {
            return null;
        }
        return $Mysql->fetchOne(
            "SELECT * FROM u_defi WHERE defi = ? AND date_expiration > NOW()",
            array(hash('sha256', $defi)),
            's'
        );
    }

    public static function fermerDefi($ligne)
    {
        global $Mysql;
        $Mysql->execute("DELETE FROM u_defi WHERE defi = ?", array($ligne->defi), 's');
    }
}

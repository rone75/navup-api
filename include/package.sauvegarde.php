<?php

//=======================================================================
// File:        package.sauvegarde.php
// Description: sauvegardes (étape 8) : fabriquer, faire tourner, vérifier, ouvrir et recharger une archive.
//              Une sauvegarde = deux fichiers dans le dossier des sauvegardes :
//                navup-AAAAMMJJ-HHMMSS.tgz.enc   archive tar.gz (base.sql, medias.tar) chiffrée par openssl
//                                                (AES-256-CBC, PBKDF2 200 000 tours, clé $_CLE_SAUVEGARDE)
//                navup-AAAAMMJJ-HHMMSS.json      manifeste : date, taille, tables, médias, HMAC-SHA256 de l'archive
//              Le HMAC est vérifié avant tout déchiffrement : une archive altérée, ou d'une autre clé, est refusée.
//              Les secrets (mot de passe de la base, clé) ne passent jamais sur une ligne de commande :
//              fichiers temporaires en 0600, effacés à la fin.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Sauvegarde
{
    const PREFIXE = 'navup-';
    const GARDER_JOURS = 7;
    const GARDER_SEMAINES = 4;
    const ITERATIONS = 200000;

    private static function cle()
    {
        global $_CLE_SAUVEGARDE;
        if (!isset($_CLE_SAUVEGARDE) || !is_string($_CLE_SAUVEGARDE) || preg_match('/^[0-9a-f]{64}$/i', $_CLE_SAUVEGARDE) !== 1) {
            throw new RuntimeException('clé $_CLE_SAUVEGARDE absente ou invalide dans require/secret.php');
        }
        return $_CLE_SAUVEGARDE;
    }

    public static function disponible()
    {
        try {
            self::cle();
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public static function taille($octets)
    {
        $o = (float) $octets;
        if ($o >= 1073741824) {
            return number_format($o / 1073741824, 1, ',', ' ') . ' Go';
        }
        if ($o >= 1048576) {
            return number_format($o / 1048576, 1, ',', ' ') . ' Mo';
        }
        return number_format($o / 1024, 0, ',', ' ') . ' ko';
    }

    /** Dossier de travail privé (0700), effacé par nettoyer(). */
    private static function temporaire($parent)
    {
        $d = rtrim($parent, '/') . '/.travail-' . bin2hex(random_bytes(6));
        if (!mkdir($d, 0700)) {
            throw new RuntimeException("dossier de travail impossible dans $parent");
        }
        return $d;
    }

    private static function nettoyer($d)
    {
        if ($d === null || !is_dir($d)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($d);
    }

    private static function ecrirePrive($chemin, $contenu)
    {
        $f = fopen($chemin, 'x');
        if ($f === false) {
            throw new RuntimeException("fichier temporaire impossible : $chemin");
        }
        chmod($chemin, 0600);
        fwrite($f, $contenu);
        fclose($f);
    }

    /** Lance une commande ; l'erreur rendue ne cite que la commande, jamais un secret (ils sont dans des fichiers). */
    private static function lancer($commande, $quoi)
    {
        exec($commande . ' 2>&1', $sortie, $code);
        if ($code !== 0) {
            throw new RuntimeException("$quoi : " . mb_substr(implode(' ', $sortie), 0, 200));
        }
    }

    private static function fichierClient($travail, $base = null)
    {
        global $_DB;
        $cnf = $travail . '/client.cnf';
        $echapper = function ($v) {
            return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $v) . '"';
        };
        self::ecrirePrive($cnf, "[client]\nhost=" . $echapper($_DB['hote'] ?? 'localhost') . "\nuser=" . $echapper($_DB['utilisateur'] ?? '')
            . "\npassword=" . $echapper($_DB['mot_de_passe'] ?? '') . "\ndefault-character-set=utf8mb4\n");
        return $cnf;
    }

    private static function binaire($noms)
    {
        foreach ($noms as $n) {
            foreach (array('/usr/bin/', '/usr/local/bin/') as $d) {
                if (is_executable($d . $n)) {
                    return $d . $n;
                }
            }
        }
        throw new RuntimeException('introuvable : ' . implode(' ou ', $noms));
    }

    // FABRIQUER ######################################################

    /** Fait une sauvegarde dans $dossier ; rend son manifeste. */
    public static function faire($dossier, $avecMedias = true)
    {
        global $_DB, $_DOSSIER_MEDIAS, $Mysql;

        if ($dossier === '' || !is_dir($dossier) || !is_writable($dossier)) {
            throw new RuntimeException("dossier des sauvegardes absent ou non inscriptible : « $dossier » (\$_DOSSIER_SAUVEGARDES ou --dossier)");
        }
        $cle = self::cle();
        $nom = self::PREFIXE . date('Ymd-His');
        $travail = self::temporaire($dossier);
        $debut = microtime(true);

        try {
            $cnf = self::fichierClient($travail);
            self::ecrirePrive($travail . '/cle', $cle);

            // La base : une transaction cohérente, routines, déclencheurs et vues compris
            self::lancer(
                escapeshellarg(self::binaire(array('mariadb-dump', 'mysqldump'))) . ' --defaults-extra-file=' . escapeshellarg($cnf)
                . ' --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces --skip-dump-date '
                . escapeshellarg($_DB['base']) . ' > ' . escapeshellarg($travail . '/base.sql'),
                'export de la base'
            );
            $tables = (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->n;

            // Les médias de la formation
            $medias = 0;
            $listeTar = array('base.sql');
            if ($avecMedias && isset($_DOSSIER_MEDIAS) && is_dir($_DOSSIER_MEDIAS)) {
                self::lancer('tar -C ' . escapeshellarg($_DOSSIER_MEDIAS) . ' -cf ' . escapeshellarg($travail . '/medias.tar') . ' .', 'archive des médias');
                $medias = count(array_filter(scandir($_DOSSIER_MEDIAS), function ($f) use ($_DOSSIER_MEDIAS) {
                    return is_file($_DOSSIER_MEDIAS . '/' . $f);
                }));
                $listeTar[] = 'medias.tar';
            }

            // Une archive, chiffrée au fil de l'eau
            $chiffre = $travail . '/' . $nom . '.tgz.enc';
            self::lancer(
                'tar -C ' . escapeshellarg($travail) . ' -czf - ' . implode(' ', array_map('escapeshellarg', $listeTar))
                . ' | openssl enc -aes-256-cbc -salt -pbkdf2 -iter ' . self::ITERATIONS . ' -pass file:' . escapeshellarg($travail . '/cle')
                . ' -out ' . escapeshellarg($chiffre),
                'chiffrement'
            );

            $manifeste = array(
                'fichier' => $nom . '.tgz.enc',
                'date' => date('Y-m-d H:i:s'),
                'base' => $_DB['base'],
                'tables' => $tables,
                'medias' => $medias,
                'taille' => filesize($chiffre),
                'duree_s' => round(microtime(true) - $debut, 1),
                'chiffrement' => 'openssl aes-256-cbc pbkdf2 ' . self::ITERATIONS,
                'hmac_sha256' => hash_hmac_file('sha256', $chiffre, $cle),
            );
            rename($chiffre, rtrim($dossier, '/') . '/' . $nom . '.tgz.enc');
            file_put_contents(rtrim($dossier, '/') . '/' . $nom . '.json', json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

            return $manifeste;
        } finally {
            self::nettoyer($travail);
        }
    }

    /** Les manifestes du dossier, du plus récent au plus ancien. */
    public static function liste($dossier)
    {
        $out = array();
        foreach (glob(rtrim($dossier, '/') . '/' . self::PREFIXE . '*.json') ?: array() as $f) {
            $m = json_decode((string) file_get_contents($f), true);
            if (is_array($m) && isset($m['fichier'], $m['date'], $m['hmac_sha256'])) {
                $m['manifeste'] = $f;
                $out[] = $m;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        return $out;
    }

    /** Garde les 7 plus récentes et la plus récente de chacune des 4 dernières semaines ; rend le nombre retiré. */
    public static function rotation($dossier)
    {
        $garder = array();
        $semaines = array();
        foreach (self::liste($dossier) as $i => $m) {
            $semaine = date('o-W', strtotime($m['date']));
            if ($i < self::GARDER_JOURS) {
                $garder[$m['fichier']] = true;
            }
            if (!isset($semaines[$semaine]) && count($semaines) < self::GARDER_SEMAINES) {
                $semaines[$semaine] = true;
                $garder[$m['fichier']] = true;
            }
        }
        $retirees = 0;
        foreach (self::liste($dossier) as $m) {
            if (!isset($garder[$m['fichier']])) {
                @unlink(rtrim($dossier, '/') . '/' . $m['fichier']);
                @unlink($m['manifeste']);
                $retirees++;
            }
        }
        return $retirees;
    }

    // RELIRE #########################################################

    /** L'archive est-elle intacte (HMAC du manifeste, avec notre clé) ? */
    public static function intacte($dossier, $m)
    {
        $chemin = rtrim($dossier, '/') . '/' . basename($m['fichier']);
        return is_file($chemin) && hash_equals((string) $m['hmac_sha256'], hash_hmac_file('sha256', $chemin, self::cle()));
    }

    /**
     * Charge une sauvegarde dans une base de test (jamais la base en service). Rend ce qui a été chargé.
     * Les médias sont seulement relus (liste de l'archive) : ils ne se replacent qu'à la main, voir deploiement/.
     */
    public static function restaurer($dossier, $m, $vers)
    {
        global $_DB;

        if (preg_match('/^[a-z0-9_]{1,64}$/', $vers) !== 1) {
            throw new RuntimeException('nom de base invalide');
        }
        if ($vers === $_DB['base']) {
            throw new RuntimeException('refusé : ' . $vers . ' est la base en service');
        }
        if (!self::intacte($dossier, $m)) {
            throw new RuntimeException("archive altérée, ou chiffrée avec une autre clé : rien n'a été chargé");
        }

        $travail = self::temporaire(sys_get_temp_dir());
        try {
            self::ecrirePrive($travail . '/cle', self::cle());
            $cnf = self::fichierClient($travail);
            self::lancer(
                'openssl enc -d -aes-256-cbc -pbkdf2 -iter ' . self::ITERATIONS . ' -pass file:' . escapeshellarg($travail . '/cle')
                . ' -in ' . escapeshellarg(rtrim($dossier, '/') . '/' . basename($m['fichier'])) . ' | tar -C ' . escapeshellarg($travail) . ' -xzf -',
                'déchiffrement'
            );
            if (!is_file($travail . '/base.sql')) {
                throw new RuntimeException('archive sans base.sql');
            }

            // La base de test est vidée, puis rechargée
            $client = escapeshellarg(self::binaire(array('mariadb', 'mysql'))) . ' --defaults-extra-file=' . escapeshellarg($cnf);
            $vider = $travail . '/vider.sql';
            self::ecrirePrive($vider, "SET FOREIGN_KEY_CHECKS = 0;\n");
            $tables = shell_exec($client . ' -N -e ' . escapeshellarg("SELECT CONCAT('DROP ', IF(TABLE_TYPE = 'VIEW', 'VIEW', 'TABLE'), ' IF EXISTS `', TABLE_NAME, '`;') FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$vers'") . ' 2>/dev/null');
            file_put_contents($vider, (string) $tables . "SET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND);
            self::lancer($client . ' ' . escapeshellarg($vers) . ' < ' . escapeshellarg($vider), "accès à la base de test $vers");
            self::lancer($client . ' ' . escapeshellarg($vers) . ' < ' . escapeshellarg($travail . '/base.sql'), 'chargement de la base');

            $medias = 0;
            if (is_file($travail . '/medias.tar')) {
                exec('tar -tf ' . escapeshellarg($travail . '/medias.tar'), $liste, $code);
                if ($code !== 0) {
                    throw new RuntimeException('archive des médias illisible');
                }
                $medias = count(array_filter($liste, function ($l) {
                    return substr($l, -1) !== '/';
                }));
            }

            return array('medias' => $medias);
        } finally {
            self::nettoyer($travail);
        }
    }
}

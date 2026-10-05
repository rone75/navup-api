<?php

//=======================================================================
// File:        package.mysql.php
// Description: connexion MySQL (mysqli) + helpers de requêtes préparées
// Created:     2014-01-27 - refonte ManiCarton 2026-09-13 - NavUp 2026-10-03
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//
// Copyright (C) 2014 Erwan Corre
//========================================================================

class Mysql
{
    private $affectedRows = 0;
    private $insertId = 0;

    /**
     * Connexion à la base décrite par $_DB (require/secret.php, hors dépôt) : aucun identifiant dans le code.
     */
    public function OuvrirBase()
    {
        global $_DB;

        // MYSQLI_REPORT_OFF AVANT la connexion : depuis PHP 8.1 le mode par défaut
        // lève une mysqli_sql_exception (non catchée) si la connexion échoue.
        mysqli_report(MYSQLI_REPORT_OFF);

        $base = isset($_DB['base']) ? $_DB['base'] : '';

        // Contrôles d'une sauvegarde restaurée (script-cgi/restaurer.php) : en ligne de commande seulement, la variable
        // NAVUP_BASE désigne la base de test. Jamais pour une requête web.
        if (PHP_SAPI === 'cli' && is_string(getenv('NAVUP_BASE')) && preg_match('/^[a-z0-9_]{1,64}$/', getenv('NAVUP_BASE')) === 1) {
            $base = getenv('NAVUP_BASE');
        }

        $mysqli = @new mysqli(
            isset($_DB['hote']) ? $_DB['hote'] : 'localhost',
            isset($_DB['utilisateur']) ? $_DB['utilisateur'] : '',
            isset($_DB['mot_de_passe']) ? $_DB['mot_de_passe'] : '',
            $base
        );

        if ($mysqli->connect_errno) {
            $this->Erreur("CONNEXION " . $base, __FILE__, $mysqli->connect_error);
        }

        $mysqli->set_charset("utf8mb4");

        // NOW() et les dates par défaut suivent l'heure de Paris, comme date() côté PHP, quel que soit le fuseau du serveur.
        // Le décalage est calculé par PHP : les fuseaux nommés ne sont pas toujours chargés dans MySQL.
        $decalage = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('P');
        $mysqli->query("SET time_zone = '" . $decalage . "'");

        // Réglages modifiés dans l'outil (étape 7b) : ils remplacent, dans les variables globales, les valeurs par défaut
        // de require/param.php, que l'appelant a déjà incluses
        require_once __DIR__ . '/package.reglage.php';
        Reglage::appliquer($mysqli);

        return $mysqli;
    }

    /**
     * Exécute une requête préparée (marqueurs ?).
     * Retourne un mysqli_result pour un SELECT, true pour INSERT/UPDATE/DELETE.
     * Toute erreur SQL passe par Erreur() : mail + HTTP 500 + exit.
     *
     * @param string $sql    requête avec des ? comme marqueurs
     * @param array  $params valeurs liées, dans l'ordre des ?
     * @param string $types  types mysqli (i, d, s, b) ; déduits des valeurs PHP si vide
     * @return mysqli_result|bool
     */
    public function prepared($sql, $params = array(), $types = '')
    {
        global $SQL, $file_err;

        $file = isset($file_err) ? $file_err : __FILE__;

        $stmt = $SQL->prepare($sql);
        if ($stmt === false) {
            $this->Erreur($sql, $file, $SQL->error);
        }

        if (count($params) > 0) {
            if ($types === '') {
                $types = $this->typesFromParams($params);
            }
            // bind_param attend des références : tableau variable, booléens convertis en entiers
            $bind = array();
            foreach (array_values($params) as $p) {
                $bind[] = is_bool($p) ? (int) $p : $p;
            }
            $stmt->bind_param($types, ...$bind);
        }

        if (!$stmt->execute()) {
            // Les valeurs liées ne sont jamais jointes au diagnostic : elles peuvent contenir des données personnelles
            $this->Erreur($sql, $file, $stmt->error);
        }

        $result = $stmt->get_result();

        if ($result instanceof mysqli_result) {
            // Résultat bufferisé par mysqlnd : le statement peut être fermé tout de suite
            $stmt->close();
            return $result;
        }

        $this->affectedRows = $stmt->affected_rows;
        $this->insertId = $stmt->insert_id;
        $stmt->close();

        return true;
    }

    /**
     * Première ligne d'un SELECT préparé sous forme d'objet, ou null.
     */
    public function fetchOne($sql, $params = array(), $types = '')
    {
        $result = $this->prepared($sql, $params, $types);
        if (!($result instanceof mysqli_result)) {
            return null;
        }
        $row = $result->fetch_object();
        $result->free();

        return is_object($row) ? $row : null;
    }

    /**
     * Toutes les lignes d'un SELECT préparé (tableau d'objets, éventuellement vide).
     */
    public function fetchAll($sql, $params = array(), $types = '')
    {
        $result = $this->prepared($sql, $params, $types);
        if (!($result instanceof mysqli_result)) {
            return array();
        }
        $rows = array();
        while ($row = $result->fetch_object()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * INSERT / UPDATE / DELETE préparé. Retourne le nombre de lignes affectées.
     */
    public function execute($sql, $params = array(), $types = '')
    {
        $this->prepared($sql, $params, $types);

        return (int) $this->affectedRows;
    }

    public function lastId()
    {
        return (int) $this->insertId;
    }

    private function typesFromParams($params)
    {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p) || is_bool($p)) {
                $types .= 'i';
            } elseif (is_float($p)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    /**
     * Erreur SQL : mail de diagnostic, puis réponse générique HTTP 500 (préfixée )]}',) et exit.
     * $error : message mysqli (celui du statement pour une requête préparée).
     */
    public function Erreur($query, $file, $error = null)
    {
        global $SQL, $_MAIL_ERREUR, $_MAIL_EXPEDITEUR;

        $date = date("Y-m-d H:i:s");
        $ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "localhost";

        if ($error === null) {
            $error = (isset($SQL) && isset($SQL->error)) ? $SQL->error : '';
        }

        // Chemin seul : la chaîne de requête de l'URL peut porter des termes de recherche (noms, emails)
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : 'cli';

        if (php_sapi_name() !== 'cli' && isset($_MAIL_ERREUR) && $_MAIL_ERREUR !== '') {
            $from = (isset($_MAIL_EXPEDITEUR) && $_MAIL_EXPEDITEUR !== '') ? $_MAIL_EXPEDITEUR : "noreply@navup.fr";

            $headers = 'From: "NavUp" <' . $from . ">\r\n";
            $headers .= 'Reply-to: "NavUp" <' . $from . ">\r\n";
            $headers .= 'MIME-Version: 1.0' . "\r\n";
            $headers .= 'Content-Type: text/plain; charset="UTF-8"' . "\r\n";
            $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";

            @mail($_MAIL_ERREUR, "Erreur SQL NavUp : $file", "date : $date - IP : $ip - SQL : $query - $error - $uri", $headers);
        }

        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "Erreur SQL ($file) : $error\nSQL : " . (strlen($query) > 1500 ? substr($query, 0, 1500) . " … [" . strlen($query) . " car.]" : $query) . "\n");
            exit(1);
        }

        // Détails SQL masqués au client (sécurité) ; préfixe XSSI identique aux autres réponses
        http_response_code(500);
        header('Content-Type: application/json');
        echo ")]}',\n" . json_encode(array('success' => false, 'message' => "Une erreur interne s'est produite. Veuillez réessayer plus tard."));

        exit();
    }

    public function CloseBase($mysqli)
    {
        $mysqli->close();
    }
}

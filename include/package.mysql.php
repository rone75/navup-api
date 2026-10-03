<?php

//=======================================================================
// File:        package.mysql.php
// Description: connexion MySQL (mysqli) + helpers de requêtes préparées
// Created:     2014-01-27 - refonte ManiCarton 2026-09-13
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//
// Copyright (C) 2014 Erwan Corre
//========================================================================

class Mysql
{
    private $adresse = "localhost";
    private $user = "root";
    private $pass = "59XTVs48!";
    private $base = "navup";

    private $affectedRows = 0;
    private $insertId = 0;

    public function OuvrirBase()
    {
        // MYSQLI_REPORT_OFF AVANT la connexion : depuis PHP 8.1 le mode par défaut
        // lève une mysqli_sql_exception (non catchée) si la connexion échoue.
        mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = @new mysqli($this->adresse, $this->user, $this->pass, $this->base);

        if ($mysqli->connect_errno) {
            $this->Erreur("CONNEXION " . $this->base, __FILE__, $mysqli->connect_error);
        }

        $mysqli->set_charset("utf8mb4");

        return $mysqli;
    }

    /**
     * Connexion à une autre base du même serveur (ex. carton_sudev, source de l'import), mêmes identifiants.
     */
    public function OuvrirAutreBase($base)
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = @new mysqli($this->adresse, $this->user, $this->pass, $base);

        if ($mysqli->connect_errno) {
            $this->Erreur("CONNEXION " . $base, __FILE__, $mysqli->connect_error);
        }

        $mysqli->set_charset("utf8mb4");

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
            $this->Erreur($sql . " | params=" . json_encode($params, JSON_UNESCAPED_UNICODE), $file, $stmt->error);
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
        global $SQL, $_MAIL_ERREUR;

        $date = date("Y-m-d H:i:s");
        $ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "localhost";

        if ($error === null) {
            $error = (isset($SQL) && isset($SQL->error)) ? $SQL->error : '';
        }

        $dest = (isset($_MAIL_ERREUR) && $_MAIL_ERREUR !== '') ? $_MAIL_ERREUR : "erwan@anime-store.fr";
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'cli';

        $headers = 'From: "Manicarton" <noreply@manicarton.com>' . "\r\n";
        $headers .= 'Reply-to: "Manicarton" <noreply@manicarton.com' . ">\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Return-Path: <erreur@manicarton.com' . ">\r\n";
        $headers .= 'Content-Type: text/html; charset="UTF-8"' . "\r\n";
        $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";

        if (php_sapi_name() !== 'cli') {
            @mail($dest, "Erreur SQL Manicarton : $file", "date : $date - IP : $ip - SQL : $query - $error - $uri", $headers, "-ferreur@manicarton.com");
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

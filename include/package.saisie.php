<?php

//=======================================================================
// File:        package.saisie.php
// Description: lecture et validation des champs reçus en JSON selon une spécification,
//              pagination, tri, INSERT / UPDATE génériques, différences avant / après.
//              Repris de Referentiel (ManiCarton, package.referentiel.php) et durci pour NavUp :
//              pas de strip_tags sur le texte, longueurs toujours bornées, entiers et dates stricts.
// Created:     2026-10-03
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Saisie
{
    /**
     * Nom de table ou de colonne placé dans une requête. C'est la seule interpolation admise :
     * le nom vient toujours du code (clé d'une spécification), jamais de la requête HTTP, et il est contrôlé ici.
     */
    private function identifiant($nom)
    {
        if (!is_string($nom) || !preg_match('/^[a-z_][a-z0-9_]*$/', $nom)) {
            throw new LogicException("Identifiant SQL invalide.");
        }

        return $nom;
    }

    /**
     * Texte reçu : UTF-8 valide, caractères de contrôle retirés, espaces de bord supprimés.
     * $multiligne = false : une seule ligne, espaces multiples réduits.
     * Aucune balise n'est retirée (« enfant <10 ans » reste intact) : la valeur est liée par requête préparée
     * et échappée à l'affichage.
     */
    public function texte($v, $multiligne)
    {
        if (!mb_check_encoding($v, 'UTF-8')) {
            return null;
        }
        if ($multiligne) {
            $v = str_replace(array("\r\n", "\r"), "\n", $v);
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        } else {
            $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v);
            $v = preg_replace('/\s{2,}/u', ' ', $v);
        }

        return trim((string) $v);
    }

    /**
     * Téléphone au format international (E.164). Accepte les écritures usuelles :
     * « 06 12 34 56 78 », « 06.12.34.56.78 », « +33 6 12 34 56 78 », « +33 (0)6 12 34 56 78 », « 0033 6… ».
     * Un numéro national en 0… est lu comme français. Retourne null si le numéro n'est pas valide.
     */
    public function telephone($v)
    {
        if (!is_string($v)) {
            return null;
        }
        $v = str_replace('(0)', '', trim($v));
        $plus = strpos($v, '+') === 0;
        $chiffres = preg_replace('/\D/', '', $v);
        if ($chiffres === '') {
            return null;
        }
        if ($plus) {
            $tel = '+' . $chiffres;
        } elseif (strpos($chiffres, '00') === 0) {
            $tel = '+' . substr($chiffres, 2);
        } elseif (strlen($chiffres) === 10 && $chiffres[0] === '0') {
            $tel = '+33' . substr($chiffres, 1);
        } else {
            return null;
        }
        // +330612… : le zéro national répété après l'indicatif
        $tel = preg_replace('/^\+330(\d{9})$/', '+33$1', $tel);

        return preg_match('/^\+[1-9]\d{7,14}$/', $tel) ? $tel : null;
    }

    // VALIDATION GÉNÉRIQUE ###########################################

    /**
     * Lit et valide les champs d'un objet JSON selon une spécification.
     * Types : str (une ligne), text (multiligne), int, bool, enum, email, tel, date, heure (HH:MM), fk.
     * Clés d'une spécification : type, libelle, requis, max, min, valeurs (enum), defaut,
     * et pour fk : table, col, cast, where, where_params, where_lib.
     * $partiel = true : seuls les champs présents sont retournés (mise à jour).
     * Envoie validationError() (exit) au premier problème ; un champ vidé vaut null.
     */
    public function lireChamps($R, $specs, $partiel = false)
    {
        global $Response, $Mysql;

        $data = array();

        foreach ($specs as $champ => $spec) {
            $lib = $spec['libelle'] ?? str_replace('_', ' ', $champ);
            $present = is_object($R) && property_exists($R, $champ);

            if (!$present) {
                if (!$partiel && !empty($spec['requis'])) {
                    $Response->validationError("Le champ « $lib » est obligatoire.");
                }
                if (!$partiel && array_key_exists('defaut', $spec)) {
                    $data[$champ] = $spec['defaut'];
                }
                continue;
            }

            $v = $R->$champ;
            if ($v === null || $v === '') {
                if (!empty($spec['requis'])) {
                    $Response->validationError("Le champ « $lib » est obligatoire.");
                }
                $data[$champ] = null;
                continue;
            }

            switch ($spec['type']) {
                case 'str':
                case 'text':
                    if (!is_string($v)) {
                        $Response->validationError("Le champ « $lib » doit être un texte.");
                    }
                    $v = $this->texte($v, $spec['type'] === 'text');
                    if ($v === null) {
                        $Response->validationError("Le champ « $lib » contient des caractères illisibles.");
                    }
                    // Longueur toujours bornée : un texte trop long doit donner un message, pas une erreur SQL
                    $max = $spec['max'] ?? ($spec['type'] === 'str' ? 255 : 5000);
                    if ($v === '') {
                        if (!empty($spec['requis'])) {
                            $Response->validationError("Le champ « $lib » est obligatoire.");
                        }
                        $v = null;
                    } elseif (mb_strlen($v) > $max) {
                        $Response->validationError("Le champ « $lib » doit faire au plus $max caractères.");
                    }
                    break;

                case 'int':
                    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,9}$/', $v))) {
                        $Response->validationError("Le champ « $lib » doit être un nombre entier.");
                    }
                    $v = (int) $v;
                    if (isset($spec['min']) && $v < $spec['min']) {
                        $Response->validationError("Le champ « $lib » doit être supérieur ou égal à {$spec['min']}.");
                    }
                    if (isset($spec['max']) && $v > $spec['max']) {
                        $Response->validationError("Le champ « $lib » doit être inférieur ou égal à {$spec['max']}.");
                    }
                    break;

                case 'bool':
                    if (in_array($v, array(1, '1', true, 'true'), true)) {
                        $v = 1;
                    } elseif (in_array($v, array(0, '0', false, 'false'), true)) {
                        $v = 0;
                    } else {
                        $Response->validationError("Le champ « $lib » doit valoir 0 ou 1.");
                    }
                    break;

                case 'enum':
                    if (!is_string($v) || !in_array($v, $spec['valeurs'], true)) {
                        $Response->validationError("Le champ « $lib » doit valoir : " . implode(', ', $spec['valeurs']) . ".");
                    }
                    break;

                case 'email':
                    $v = is_string($v) ? strtolower(trim($v)) : '';
                    if ($v === '' || strlen($v) > ($spec['max'] ?? 255) || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                        $Response->validationError("Le champ « $lib » doit être une adresse e-mail valide.");
                    }
                    break;

                case 'tel':
                    $v = $this->telephone($v);
                    if ($v === null) {
                        $Response->validationError("Le champ « $lib » doit être un numéro valide, par exemple 06 12 34 56 78 ou +33 6 12 34 56 78 (indicatif obligatoire hors métropole).");
                    }
                    break;

                case 'date':
                    $ok = is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
                    if (!$ok) {
                        $Response->validationError("Le champ « $lib » doit être une date AAAA-MM-JJ.");
                    }
                    if (isset($spec['min']) && $v < $spec['min']) {
                        $Response->validationError("Le champ « $lib » ne peut pas être antérieur au {$spec['min']}.");
                    }
                    if (isset($spec['max']) && $v > $spec['max']) {
                        $Response->validationError("Le champ « $lib » ne peut pas être postérieur au {$spec['max']}.");
                    }
                    break;

                case 'heure':
                    if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                        $Response->validationError("Le champ « $lib » doit être une heure HH:MM.");
                    }
                    break;

                case 'fk':
                    $cast = $spec['cast'] ?? 'int';
                    if ($cast === 'int') {
                        if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,9}$/', $v))) {
                            $Response->validationError("Le champ « $lib » est invalide.");
                        }
                        $v = (int) $v;
                        if ($v <= 0) {
                            $Response->validationError("Le champ « $lib » est invalide.");
                        }
                    } else {
                        if (!is_string($v) || !preg_match('/^[a-z0-9_]{1,30}$/', $v)) {
                            $Response->validationError("Le champ « $lib » est invalide.");
                        }
                    }
                    // table, col et where viennent de la spécification (code), les valeurs sont liées
                    $sql = "SELECT 1 AS x FROM " . $this->identifiant($spec['table']) . " WHERE " . $this->identifiant($spec['col']) . " = ?"
                        . (isset($spec['where']) ? " AND {$spec['where']}" : "");
                    $params = array_merge(array($v), $spec['where_params'] ?? array());
                    if ($Mysql->fetchOne($sql, $params) === null) {
                        $Response->validationError("« $lib » : valeur inconnue" . (isset($spec['where_lib']) ? " ({$spec['where_lib']})" : "") . ".");
                    }
                    break;

                default:
                    throw new LogicException("Type de champ inconnu : " . $spec['type']);
            }

            $data[$champ] = $v;
        }

        return $data;
    }

    /** Clé de saisie d'un formulaire (UUID) : null si absente, 400 si mal formée. Un double envoi n'écrit qu'une fois. */
    public function lireCle($R)
    {
        global $Response;

        if (!is_object($R) || !isset($R->cle_saisie)) {
            return null;
        }
        if (!is_string($R->cle_saisie) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $R->cle_saisie)) {
            $Response->validationError("Clé de saisie invalide.");
        }

        return $R->cle_saisie;
    }

    /** Date AAAA-MM-JJ lue dans $_GET, ou null si absente ; 400 si elle est mal formée. */
    public function dateFiltre($cle)
    {
        global $Response;

        if (!isset($_GET[$cle]) || !is_string($_GET[$cle]) || $_GET[$cle] === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $_GET[$cle]);
        if ($d === false || $d->format('Y-m-d') !== $_GET[$cle]) {
            $Response->validationError("Date invalide (format AAAA-MM-JJ) : $cle");
        }

        return $_GET[$cle];
    }

    /** Refuse (400) si une valeur est déjà prise dans une colonne unique (hors la ligne exclue). */
    public function verifierUnique($table, $col, $valeur, $message, $pk = null, $pkVal = null)
    {
        global $Mysql, $Response;

        $sql = "SELECT 1 AS x FROM " . $this->identifiant($table) . " WHERE " . $this->identifiant($col) . " = ?"
            . ($pk !== null ? " AND " . $this->identifiant($pk) . " <> ?" : "") . " LIMIT 1";
        $params = array($valeur);
        if ($pk !== null) {
            $params[] = $pkVal;
        }
        if ($Mysql->fetchOne($sql, $params) !== null) {
            $Response->validationError($message);
        }
    }

    /** Pagination depuis $_GET : array(page, limit, offset). */
    public function pagination($defaut = 50, $max = 200)
    {
        $page = (isset($_GET['page']) && is_string($_GET['page']) && ctype_digit($_GET['page'])) ? max(1, (int) $_GET['page']) : 1;
        $limit = (isset($_GET['limit']) && is_string($_GET['limit']) && ctype_digit($_GET['limit'])) ? min($max, max(1, (int) $_GET['limit'])) : $defaut;

        return array($page, $limit, ($page - 1) * $limit);
    }

    /**
     * Tri depuis $_GET (sort, dir), limité à une liste blanche : "col ASC, départage".
     * Une colonne nullable se déclare sous la forme array('col', true) : les valeurs absentes passent en dernier.
     * $departage (clé primaire) rend l'ordre stable d'une page à l'autre.
     */
    public function tri($autorises, $defaut, $departage)
    {
        $cle = (isset($_GET['sort']) && is_string($_GET['sort']) && isset($autorises[$_GET['sort']])) ? $_GET['sort'] : $defaut;
        $dir = (isset($_GET['dir']) && is_string($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'DESC' : 'ASC';
        $col = $autorises[$cle];

        if (is_array($col)) {
            return "({$col[0]} IS NULL), {$col[0]} $dir, $departage";
        }

        return "$col $dir, $departage";
    }

    /** UPDATE générique : $data champ => valeur (les clés viennent d'une spécification). Sans champ, ne fait rien. */
    public function mettreAJour($table, $pk, $id, $data)
    {
        global $Mysql;

        if (count($data) === 0) {
            return 0;
        }
        $set = array();
        $params = array();
        foreach ($data as $champ => $valeur) {
            $set[] = $this->identifiant($champ) . " = ?";
            $params[] = $valeur;
        }
        $set[] = "date_modif = NOW()";
        $params[] = $id;

        return $Mysql->execute("UPDATE " . $this->identifiant($table) . " SET " . implode(', ', $set) . " WHERE " . $this->identifiant($pk) . " = ?", $params);
    }

    /** INSERT générique. Retourne l'id inséré. */
    public function inserer($table, $data)
    {
        global $Mysql;

        $cols = array_map(array($this, 'identifiant'), array_keys($data));
        $Mysql->execute(
            "INSERT INTO " . $this->identifiant($table) . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")",
            array_values($data)
        );

        return $Mysql->lastId();
    }

    /**
     * Retire de $data les champs inchangés et retourne la liste des champs réellement modifiés.
     * Seuls les noms sont retournés : ni le journal d'audit ni la chronologie ne recopient une valeur de dossier.
     */
    public function differences($actuel, &$data)
    {
        $modifies = array();
        foreach ($data as $champ => $valeur) {
            $avant = property_exists($actuel, $champ) ? $actuel->$champ : null;
            $a = $avant === null ? null : (string) $avant;
            $b = $valeur === null ? null : (string) $valeur;
            if ($a === $b) {
                unset($data[$champ]);
                continue;
            }
            $modifies[] = $champ;
        }

        return $modifies;
    }
}

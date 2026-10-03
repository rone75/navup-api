<?php

//=======================================================================
// File:    package.data.php
// Description:    class de Gestion de donnée (entier , string etc ... ) (Formulaire)
// Created:     2005-01-25
// Author:    Corre Erwan (corre_erwan@yahoo.fr)
//
// Copyright (C) 2005 Erwan Corre
//========================================================================

class ControleData
{

    // Jeton aléatoire cryptographique (random_int), alphabet sans caractères ambigus (0/O, 1/l/I)
    public function gen_token($len = 20)
    {
        $characts = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($characts) - 1;

        $token = '';

        for ($i = 0; $i < $len; $i++) {
            $token .= $characts[random_int(0, $max)];
        }

        return $token;
    }

    public function is_siret($siret)
    {
        if (strlen($siret) != 14) {
            return 1;
        }
        // le SIRET doit contenir 14 caractères
        if (!is_numeric($siret)) {
            return 2;
        }
        // le SIRET ne doit contenir que des chiffres

        // on prend chaque chiffre un par un
        // si son index (position dans la chaîne en commence à 0 au premier caractère) est pair
        // on double sa valeur et si cette dernière est supérieure à 9, on lui retranche 9
        // on ajoute cette valeur à la somme totale
        $sum = 0;
        for ($index = 0; $index < 14; $index++) {
            $number = (int) $siret[$index];
            if (($index % 2) == 0) {
                if (($number *= 2) > 9) {
                    $number -= 9;
                }
            }
            $sum += $number;
        }

        // le numéro est valide si la somme des chiffres est multiple de 10
        if (($sum % 10) != 0) {
            return 3;
        } else {
            return 0;
        }
    }

    public function is_siren($siren)
    {
        if (strlen($siren) != 9) {
            return 1;
        }
        // le SIREN doit contenir 9 caractères
        if (!is_numeric($siren)) {
            return 2;
        }
        // le SIREN ne doit contenir que des chiffres

        // on prend chaque chiffre un par un
        // si son index (position dans la chaîne en commence à 0 au premier caractère) est impair
        // on double sa valeur et si cette dernière est supérieure à 9, on lui retranche 9
        // on ajoute cette valeur à la somme totale
        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $number = (int) $siren[$index];
            if (($index % 2) != 0) {
                if (($number *= 2) > 9) {
                    $number -= 9;
                }
            }
            $sum += $number;
        }

        // le numéro est valide si la somme des chiffres est multiple de 10
        if (($sum % 10) != 0) {
            return 3;
        } else {
            return 0;
        }
    }

    public function nSIREN($siret)
    {
        return substr($siret, 0, 9);
    }

    public function zerofill($num, $zerofill = 5)
    {
        return str_pad($num, $zerofill, '0', STR_PAD_LEFT);
    }

    public function nTVA($siren, $countryCode)
    {
        $temp = intval(((12 + 3 * ($siren % 97)) % 97));
        $temp = $this->zerofill($temp . $siren, 11);

        return $countryCode . $temp;
    }

    public function is_num_tva($args = array())
    {

        if ('' != $args['vatnumber']) {

            $vat_number = str_replace(array(' ', '.', '-', ',', ', '), '', $args['vatnumber']);

            $countryCode = substr($vat_number, 0, 2);

            $vatNumber = substr($vat_number, 2);

            switch ($countryCode) {
                default:

                    $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                    return $error;

                    break;
                case "DE":
                    if (strlen($vatNumber) != 9) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "NL":
                    if (strlen($vatNumber) != 12) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "IT":
                    if (strlen($vatNumber) != 11) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "ES":
                    if (strlen($vatNumber) != 9) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "DK":
                    if (strlen($vatNumber) != 8) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "PT":
                    if (strlen($vatNumber) != 9) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "AT":
                    if (strlen($vatNumber) != 9) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "HU":
                    if (strlen($vatNumber) != 8) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "SE":
                    if (strlen($vatNumber) != 12) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "PL":
                    if (strlen($vatNumber) != 10) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "FI":
                    if (strlen($vatNumber) != 8) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "LT":
                    if (strlen($vatNumber) != 9 && strlen($vatNumber) != 12) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "LV":
                    if (strlen($vatNumber) != 11) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "EE":
                    if (strlen($vatNumber) != 9) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "SK":
                    if (strlen($vatNumber) != 10) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "CZ":
                    if (strlen($vatNumber) != 8 && strlen($vatNumber) != 9 && strlen($vatNumber) != 10) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "BE":
                    //BE0475910011
                    if (strlen($vatNumber) != 10) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "FR":
                    //FR02510057748
                    if (strlen($vatNumber) != 11) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
                case "LU":
                    // LU23279661
                    if (strlen($vatNumber) != 8) {
                        $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                        return $error;
                    }
                    break;
            }

            if (strlen($countryCode) != 2 || is_numeric(substr($countryCode, 0, 1)) || is_numeric(substr($countryCode, 1, 2))) {
                $error = array('result' => false, 'error' => 'Erreur de syntaxe');
                return $error;
            }

            if ($args['country'] != $countryCode) {
                $error = array('result' => false, 'error' => 'Erreur sur code pays');
                return $error;
            }

            $params = array('countryCode' => $countryCode, 'vatNumber' => $vatNumber);

            $params_soap = array("connection_timeout" => 1000);

            //Webservice
            try {

                //$url = "http://ec.europa.eu/taxation_customs/vies/checkVatTestService.wsdl";
                //$url = "https://ec.europa.eu/taxation_customs/vies/checkVatTestService.wsdl";
                $url = "http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl";
                //$url = "http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl";

                $client = new SoapClient($url, $params_soap);

                if ($client) {

                    $result = $client->checkVat($params);

                    if (!$result->valid) {
                        $error = array('result' => false, 'error' => 'Le numéro de TVA Intracommunautaire est invalide.');
                        return $error;
                    } else {
                        return array('result' => true);
                    }
                } else {
                    $error = array('result' => false, 'error' => 'Erreur timeout. Veuillez recommencer.');

                    return $error;
                }
            } catch (SoapFault $fault) {
                //$error = array('result' => false, 'error' => 'Erreur timeout. Veuillez recommencer.');
                // return $error;

                //print_r($fault);

                mail("erwan@anime-store.fr", "CHECK NUMERO DE TVA", "VERIFIER NUMERO DE TVA : " . $args['vatnumber']);

                return array('result' => true);
            }
        }

        return array('result' => false, 'error' => 'required');
    }

    public function clean_text($str, $options = array())
    {

        if (in_array('TOUT', $options)):
            $options = array('HTML', 'TRIM', 'ACCENT', 'PONCTUATION', 'TABULATION', 'ENTER', 'DOUBLE', 'MYSQL');
        endif;

        if (in_array('ADRESSE', $options)):
            $options = array('HTML', 'TRIM', 'UNICODE', 'ACCENT', 'TABULATION', 'ENTER', 'DOUBLE', 'MAJUSCULE', 'NO_QUOTE');
        endif;

        if (in_array('NOM', $options)):
            $options = array('HTML', 'TRIM', 'UNICODE', 'ACCENT', 'PONCTUATION', 'TABULATION', 'ENTER', 'DOUBLE', 'MAJUSCULE');
        endif;

        if (in_array('COMMENTAIRE', $options)):
            $options = array('INTERDIT', 'HTML', 'TRIM', 'TABULATION', 'DOUBLE', 'MYSQL');
        endif;

        if (in_array('PHONE', $options)):
            $options = array('HTML', 'TRIM', 'TABULATION', 'ACCENT', 'PONCTUATION', 'DOUBLE', 'MYSQL');
        endif;

        if (in_array('DESC', $options)):
            $options = array('TRIM', 'TABULATION', 'DOUBLE', 'MYSQL');
        endif;

        if (in_array('DESC_HTML', $options)):
            $options = array('TRIM', 'TABULATION', 'DOUBLE', 'MYSQL');
        endif;

        if (in_array('TITRE', $options)):
            $options = array('TRIM', 'TABULATION', 'DOUBLE', 'ENTER', 'MYSQL', 'HTML');
        endif;

        if (in_array('TITRE_FACTURE', $options)):
            $options = array('TRIM', 'TABULATION', 'DOUBLE', 'ENTER', 'HTML', 'ACCENT', 'MAJUSCULE');
        endif;

        if (in_array('SKU', $options)):
            $options = array('TRIM', 'TABULATION', 'DOUBLE', 'MYSQL', 'ENTER', 'ACCENT', 'PONCTUATION', 'MAJUSCULE');
        endif;

        if (in_array('ID', $options)):
            $options = array('HTML', 'TRIM', 'TABULATION', 'DOUBLE', 'MYSQL', 'INT');
        endif;

        if (in_array('FLOAT', $options)):
            $options = array('HTML', 'TRIM', 'TABULATION', 'DOUBLE', 'MYSQL', 'FLOAT');
        endif;

        if (in_array('EAN', $options)):
            $options = array('HTML', 'TRIM', 'TABULATION', 'DOUBLE', 'MYSQL', 'EAN');
        endif;

        if (in_array('DEFAULT', $options)):
            $options = array('HTML', 'TRIM', 'TABULATION', 'DOUBLE', 'MYSQL');
        endif;

        // Presets pour valeurs liées par requête préparée : jamais de 'MYSQL' (addslashes)
        if (in_array('API', $options)):
            $options = array('INTERDIT', 'HTML', 'TRIM', 'TABULATION', 'DOUBLE');
        endif;

        if (in_array('EMAIL', $options)):
            $options = array('INTERDIT', 'HTML', 'TRIM', 'TABULATION', 'DOUBLE', 'MINUSCULE');
        endif;

        if (in_array('ESPACE', $options)):
            $options = array('TRIM', 'TABULATION', 'DOUBLE');
        endif;

        foreach ($options as $option):
            switch ($option) {

                // Suppression des espaces vides en debut et fin de chaque ligne
                case 'TRIM':
                    $str = preg_replace("#^[\t\f\v ]+|[\t\f\v ]+$#m", '', $str);
                    break;

                // Remplacement des caractères accentués par leurs équivalents non accentués
                case 'ACCENT':

                    $str = htmlentities($str, ENT_NOQUOTES, 'utf-8');
                    $str = preg_replace('#&([A-za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
                    $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str); // pour les ligatures e.g. 'œ'
                    $str = html_entity_decode($str);

                    break;

                case "INTERDIT":

                    $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $str);

                    break;

                case "MYSQL":

                    $str = addslashes($str);

                    break;

                // Transforme tout le texte en minuscule
                case 'MINUSCULE':
                    $str = mb_strtolower($str, 'UTF-8');
                    break;

                // Transforme tout le texte en majuscule
                case 'MAJUSCULE':
                    $str = mb_strtoupper($str, 'UTF-8');
                    break;

                // Remplace toute la ponctuation par des espaces
                case 'PONCTUATION':
                    $str = preg_replace('#([[:punct:]])#', ' ', $str);
                    $exceptions = array("’");
                    $str = str_replace($exceptions, ' ', $str);
                    break;

                // Remplace les tabulations par des espaces
                case 'TABULATION':
                    $str = preg_replace("#\h#u", " ", $str);
                    break;

                // Remplace les espaces multiples par des espaces simples
                case 'DOUBLE':
                    $str = preg_replace('#[" "]{2,}#', ' ', $str);
                    break;

                // Remplace 1 entrée (\r\n) par 1 espace
                case 'ENTER':
                    $str = str_replace(array("\r", "\n"), ' ', $str);
                    break;

                // Supprime toutes les balises html
                case 'HTML':
                    $str = strip_tags($str);
                    break;

                // Filter tous ce qui n'est pas un chiffre

                case 'INT':
                    $str = preg_replace('@[^0-9]@', '', $str);
                    break;
                // Filter tous ce qui n'est pas un chiffre à fraction
                case 'FLOAT':
                    $str = str_replace(',', '.', $str);
                    $str = preg_replace('@[^0-9\.]@', '', $str);
                    break;
                // NUM TVA
                case "NUM_TVA":
                    $str = preg_replace('@[^0-9A-Z]@', '', $str);
                    break;
                case 'EAN':
                    $str = preg_replace('@[^0-9]@', '', $str);
                    if (strlen($str) == 12) {

                        $str = '0' . $str;
                    }

                    if (strlen($str) != 13) {
                        $str = '';
                    }

                    break;
                case "NO_QUOTE":

                    $str = str_replace('"', '', $str);

                    break;

                // Normalise les caractères Unicode exotiques (italiques mathématiques,
                // ligatures, lettres-symboles) vers leur équivalent ASCII/Latin,
                // puis retire emojis et pictogrammes (BMP et hors-BMP) pour ne
                // garder que des caractères imprimables sur étiquette postale.
                case 'UNICODE':
                    if (class_exists('Normalizer')) {
                        $str = Normalizer::normalize($str, Normalizer::FORM_KC);
                    }
                    // Tout ce qui est hors BMP (emojis 🎉, italiques 𝑠, etc.)
                    $str = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $str);
                    // Emojis, pictogrammes et symboles divers dans le BMP (☀ ★ ☎ ❤ ✓ …)
                    $str = preg_replace('/\p{Extended_Pictographic}|\p{So}/u', '', $str);
                    // Sélecteurs de variation et ZWJ (assembleurs d'emojis)
                    $str = preg_replace('/[\x{200D}\x{FE00}-\x{FE0F}]/u', '', $str);
                    break;
            }
        endforeach;

        return $str;
    }
}

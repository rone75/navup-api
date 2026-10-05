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

    // Nettoyage des textes reçus (preset API pour les recherches et les noms). Le reste de l'ancienne classe,
    // sans usage ici (TVA intracommunautaire, SIRET…), a été retiré à l'étape 8.
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

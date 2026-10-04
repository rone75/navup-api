<?php

//=======================================================================
// File:        package.xlsx.php
// Description: classeur Excel (.xlsx, Office Open XML) écrit à la main, sans librairie : un fichier zip
//              (ZipArchive) de quelques parties XML. Étape 7a : exports des listes et des statistiques.
//              Une feuille = des colonnes typées et des lignes de valeurs brutes :
//                texte     chaîne, toujours écrite comme texte (une valeur qui commence par « = » n'est jamais une formule)
//                entier    nombre entier
//                euros     montant en centimes (entier), écrit en euros au format « 1 234,56 € »
//                date      AAAA-MM-JJ, écrite comme une vraie date (jj/mm/aaaa)
//                moment    AAAA-MM-JJ HH:MM:SS, écrit comme une date et une heure
//                pourcent  nombre entier de pour cent, écrit 0,42 au format « 42 % »
//              Ligne d'en-tête en gras, figée, filtre automatique, largeurs de colonnes.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Xlsx
{
    // Styles (index dans cellXfs de styles.xml)
    const STYLE_TEXTE = 0;
    const STYLE_ENTETE = 1;
    const STYLE_EUROS = 2;
    const STYLE_DATE = 3;
    const STYLE_MOMENT = 4;
    const STYLE_POURCENT = 5;
    const STYLE_TITRE = 6;

    const TYPES = array('texte', 'entier', 'euros', 'date', 'moment', 'pourcent');

    private $feuilles = array();

    /**
     * Ajoute une feuille. $colonnes : array(array('titre' => …, 'type' => …, 'largeur' => caractères)).
     * $lignes : array(array(valeur, …)) dans l'ordre des colonnes ; null laisse la cellule vide.
     * $titre : une ligne au-dessus de l'en-tête (« Ventes du 1er au 31 octobre 2026 »), ou null.
     */
    public function feuille($nom, $colonnes, $lignes, $titre = null)
    {
        foreach ($colonnes as $c) {
            if (!in_array($c['type'], self::TYPES, true)) {
                throw new LogicException("Type de colonne inconnu : " . $c['type']);
            }
        }
        // Un nom de feuille Excel : 31 caractères, sans : \ / ? * [ ]
        $nom = mb_substr(str_replace(array(':', '\\', '/', '?', '*', '[', ']'), ' ', $nom), 0, 31);
        $this->feuilles[] = array('nom' => $nom, 'colonnes' => $colonnes, 'lignes' => $lignes, 'titre' => $titre);

        return $this;
    }

    public function nbFeuilles()
    {
        return count($this->feuilles);
    }

    /** Écrit le classeur dans un fichier temporaire et rend son chemin (à effacer par l'appelant). */
    public function ecrire()
    {
        if (count($this->feuilles) === 0) {
            $this->feuille('Vide', array(array('titre' => 'Aucune donnée', 'type' => 'texte', 'largeur' => 20)), array());
        }
        $chemin = tempnam(sys_get_temp_dir(), 'navup-xlsx-');
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Classeur impossible à écrire.");
        }
        $n = count($this->feuilles);

        $types = '';
        $feuillesXml = '';
        $relations = '';
        foreach ($this->feuilles as $i => $f) {
            $k = $i + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $feuillesXml .= '<sheet name="' . self::xml($f['nom']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $relations .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->feuilleXml($f));
        }
        $relations .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $entete = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $zip->addFromString('[Content_Types].xml', $entete
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . $types . '</Types>');
        $zip->addFromString('_rels/.rels', $entete
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/core.xml', $entete
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Tour de contrôle NavUp</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', $entete
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $feuillesXml . '</sheets>' . $this->nomsDefinis() . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $entete
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relations . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $entete . self::styles());

        if (!$zip->close()) {
            throw new RuntimeException("Classeur impossible à écrire.");
        }

        return $chemin;
    }

    // PARTIES ########################################################

    /** Le filtre automatique se déclare aussi dans le classeur (_xlnm._FilterDatabase), sans quoi Excel le perd. */
    private function nomsDefinis()
    {
        $noms = '';
        foreach ($this->feuilles as $i => $f) {
            $plage = $this->plageFiltre($f);
            if ($plage !== null) {
                $noms .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
                    . self::xml(str_replace("'", "''", $f['nom'])) . '\'!' . preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $plage) . '</definedName>';
            }
        }

        return $noms === '' ? '' : '<definedNames>' . $noms . '</definedNames>';
    }

    private function ligneEntete($f)
    {
        return $f['titre'] === null ? 1 : 3;
    }

    private function plageFiltre($f)
    {
        if (count($f['colonnes']) === 0) {
            return null;
        }
        $haut = $this->ligneEntete($f);

        return 'A' . $haut . ':' . self::colonne(count($f['colonnes']) - 1) . ($haut + max(1, count($f['lignes'])));
    }

    private function feuilleXml($f)
    {
        $haut = $this->ligneEntete($f);
        $nbCol = count($f['colonnes']);

        $cols = '';
        foreach ($f['colonnes'] as $i => $c) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int) ($c['largeur'] ?? 14) . '" customWidth="1"/>';
        }

        $lignes = '';
        if ($f['titre'] !== null) {
            $lignes .= '<row r="1">' . self::cellule('A1', $f['titre'], 'texte', self::STYLE_TITRE) . '</row>';
        }
        $entete = '';
        foreach ($f['colonnes'] as $i => $c) {
            $entete .= self::cellule(self::colonne($i) . $haut, $c['titre'], 'texte', self::STYLE_ENTETE);
        }
        $lignes .= '<row r="' . $haut . '">' . $entete . '</row>';

        foreach ($f['lignes'] as $j => $valeurs) {
            $r = $haut + 1 + $j;
            $cellules = '';
            for ($i = 0; $i < $nbCol; $i++) {
                $v = $valeurs[$i] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $cellules .= self::cellule(self::colonne($i) . $r, $v, $f['colonnes'][$i]['type']);
            }
            $lignes .= '<row r="' . $r . '">' . $cellules . '</row>';
        }

        $plage = $this->plageFiltre($f);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $haut . '" topLeftCell="A' . ($haut + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $lignes . '</sheetData>'
            . ($plage !== null ? '<autoFilter ref="' . $plage . '"/>' : '')
            . '</worksheet>';
    }

    /** Une cellule. Les textes sont des chaînes « en ligne » : jamais une formule, quoi qu'ils contiennent. */
    private static function cellule($ref, $v, $type, $style = null)
    {
        // Une valeur écrite en toutes lettres dans une colonne de nombres (« moins de 3 ») reste du texte
        if (in_array($type, array('entier', 'euros', 'pourcent'), true) && is_string($v) && !is_numeric($v)) {
            $type = 'texte';
        }
        switch ($type) {
            case 'entier':
                return '<c r="' . $ref . '"><v>' . (int) $v . '</v></c>';
            case 'euros':
                return '<c r="' . $ref . '" s="' . self::STYLE_EUROS . '"><v>' . self::nombre(((int) $v) / 100) . '</v></c>';
            case 'pourcent':
                return '<c r="' . $ref . '" s="' . self::STYLE_POURCENT . '"><v>' . self::nombre(((int) $v) / 100) . '</v></c>';
            case 'date':
            case 'moment':
                $serie = self::serie((string) $v);
                if ($serie !== null) {
                    return '<c r="' . $ref . '" s="' . ($type === 'date' ? self::STYLE_DATE : self::STYLE_MOMENT) . '"><v>' . self::nombre($serie) . '</v></c>';
                }
                // Date illisible : elle reste lisible comme texte
                return self::cellule($ref, (string) $v, 'texte');
            default:
                $s = $style === null ? '' : ' s="' . $style . '"';

                return '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . self::xml((string) $v) . '</t></is></c>';
        }
    }

    /** Numéro de série Excel d'une date (jours depuis le 30 décembre 1899), avec la fraction du jour pour une heure. */
    public static function serie($valeur)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $valeur, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $jours = (int) (new DateTimeImmutable('1899-12-30', $utc))->diff(new DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3], $utc))->days;
        $secondes = isset($m[4]) ? (int) $m[4] * 3600 + (int) $m[5] * 60 + (int) ($m[6] ?? 0) : 0;

        return $jours + $secondes / 86400;
    }

    private static function nombre($n)
    {
        $s = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Lettres d'une colonne : 0 → A, 25 → Z, 26 → AA. */
    public static function colonne($i)
    {
        $s = '';
        for ($i = (int) $i + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    /** Texte sûr pour du XML : caractères de contrôle interdits retirés, puis échappement. */
    private static function xml($s)
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function styles()
    {
        return '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/>'
            . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
            . '<numFmt numFmtId="166" formatCode="dd/mm/yyyy\ hh:mm"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="13"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF1A2340"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="9" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

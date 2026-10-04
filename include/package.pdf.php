<?php

//=======================================================================
// File:        package.pdf.php
// Description: document PDF 1.4 écrit à la main, sans librairie ni outil installé sur le serveur (étape 7a : rapport
//              de synthèse). Format A4, polices standard du PDF (Helvetica, Helvetica gras) en encodage WinAnsi : les
//              accents, « », €, l'espace insécable et le tiret long s'y écrivent ; un caractère hors de cet encodage
//              est remplacé par son équivalent le plus proche. Rien n'est incorporé, sauf une image JPEG (le logo).
//              Mise en page par curseur, de haut en bas : texte, paragraphe coupé à la largeur, filet, tableau avec
//              saut de page et répétition de l'en-tête, pied de page « page n sur N » posé à la fin.
//              Unités : points (1/72 de pouce), origine en haut à gauche pour l'appelant.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Pdf
{
    const LARGEUR = 595.28;
    const HAUTEUR = 841.89;
    const MARGE = 48;
    const PIED = 36;

    // Couleurs du système « Le semainier » (DESIGN.md de la Tour) : encre, encre seconde, bleu NavUp, filet
    const ENCRE = array(0.10, 0.137, 0.251);
    const SECONDE = array(0.36, 0.40, 0.49);
    const BLEU = array(0.18, 0.306, 0.824);
    const FILET = array(0.82, 0.85, 0.90);
    const FOND = array(0.91, 0.933, 0.973);

    // Largeurs (millièmes de corps) des caractères 32 à 255 en WinAnsi : celles d'Helvetica (relevées sur
    // Liberation Sans, qui a les mêmes métriques)
    const LARGEURS = array(278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,750,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,537,333,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,549,611,556,556,556,556,500,556,500);
    const LARGEURS_GRAS = array(278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,750,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,556,333,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,549,611,611,611,611,611,556,611,556);

    private $pages = array();
    private $courante = -1;
    private $y = 0;
    private $titre = '';
    private $image = null;
    private $pied = '';

    public function __construct($titre, $pied = '')
    {
        $this->titre = $titre;
        $this->pied = $pied;
        $this->nouvellePage();
    }

    // TEXTE ##########################################################

    /** Texte UTF-8 → octets WinAnsi (CP1252). Les espaces fines insécables deviennent des insécables ordinaires. */
    public static function winansi($s)
    {
        $s = str_replace(array("\u{202F}", "\u{2009}", "\u{2007}"), "\u{00A0}", (string) $s);
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);

        return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }

    /** Largeur d'un texte, en points. */
    public static function largeur($s, $corps, $gras = false)
    {
        $t = $gras ? self::LARGEURS_GRAS : self::LARGEURS;
        $w = 0;
        $o = self::winansi($s);
        for ($i = 0, $n = strlen($o); $i < $n; $i++) {
            $c = ord($o[$i]);
            $w += $c >= 32 ? $t[$c - 32] : 0;
        }

        return $w * $corps / 1000;
    }

    private static function chaine($octets)
    {
        return '(' . str_replace(array('\\', '(', ')', "\r"), array('\\\\', '\\(', '\\)', '\\r'), $octets) . ')';
    }

    private static function couleur($c, $fond = false)
    {
        return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $fond ? 'rg' : 'RG');
    }

    private function ecrire($op)
    {
        $this->pages[$this->courante] .= $op . "\n";
    }

    /** Écrit un texte à (x, y depuis le haut), sur sa ligne de base. $aligne : gauche, droite (x est alors le bord droit). */
    public function texte($x, $y, $s, $corps = 10, $gras = false, $couleur = self::ENCRE, $aligne = 'gauche')
    {
        if ($aligne === 'droite') {
            $x -= self::largeur($s, $corps, $gras);
        }
        $this->ecrire('BT /' . ($gras ? 'F2' : 'F1') . ' ' . $corps . ' Tf ' . self::couleur($couleur, true) . ' '
            . sprintf('%.2F %.2F', $x, self::HAUTEUR - $y) . ' Td ' . self::chaine(self::winansi($s)) . ' Tj ET');
    }

    /** Coupe un texte en lignes qui tiennent dans $largeur (aux espaces ; un mot trop long reste entier). */
    public static function couper($s, $largeur, $corps, $gras = false)
    {
        $lignes = array();
        foreach (preg_split('/\n/', (string) $s) as $para) {
            $ligne = '';
            foreach (preg_split('/ +/', $para) as $mot) {
                $essai = $ligne === '' ? $mot : $ligne . ' ' . $mot;
                if ($ligne !== '' && self::largeur($essai, $corps, $gras) > $largeur) {
                    $lignes[] = $ligne;
                    $ligne = $mot;
                } else {
                    $ligne = $essai;
                }
            }
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    // MISE EN PAGE ###################################################

    public function nouvellePage()
    {
        $this->pages[] = '';
        $this->courante = count($this->pages) - 1;
        $this->y = self::MARGE;
    }

    public function y()
    {
        return $this->y;
    }

    public function descendre($h)
    {
        $this->y += $h;
    }

    /** Passe à la page suivante si $h points ne tiennent plus au-dessus du pied. Retourne true si la page a changé. */
    public function place($h)
    {
        if ($this->y + $h > self::HAUTEUR - self::MARGE - self::PIED) {
            $this->nouvellePage();

            return true;
        }

        return false;
    }

    public function filet($x1, $y, $x2, $epaisseur = 0.6, $couleur = self::FILET)
    {
        $this->ecrire(sprintf('%.2F w %s %.2F %.2F m %.2F %.2F l S', $epaisseur, self::couleur($couleur), $x1, self::HAUTEUR - $y, $x2, self::HAUTEUR - $y));
    }

    public function rectangle($x, $y, $l, $h, $couleur)
    {
        $this->ecrire(sprintf('%s %.2F %.2F %.2F %.2F re f', self::couleur($couleur, true), $x, self::HAUTEUR - $y - $h, $l, $h));
    }

    /** Paragraphe au curseur, coupé à la largeur utile ; le curseur descend d'autant. */
    public function paragraphe($s, $corps = 10, $gras = false, $couleur = self::ENCRE, $interligne = 1.4)
    {
        $largeur = self::LARGEUR - 2 * self::MARGE;
        foreach (self::couper($s, $largeur, $corps, $gras) as $ligne) {
            $this->place($corps * $interligne);
            $this->y += $corps;
            $this->texte(self::MARGE, $this->y, $ligne, $corps, $gras, $couleur);
            $this->y += $corps * ($interligne - 1);
        }
    }

    /** Image JPEG (une seule par document : le logo), posée au curseur, en largeur $l. */
    public function image($chemin, $x, $l)
    {
        $info = @getimagesize($chemin);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            return;
        }
        $this->image = array('donnees' => file_get_contents($chemin), 'l' => $info[0], 'h' => $info[1], 'canaux' => $info['channels'] ?? 3);
        $h = $l * $info[1] / $info[0];
        $this->ecrire(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $l, $h, $x, self::HAUTEUR - $this->y - $h));
        $this->y += $h;
    }

    /**
     * Tableau au curseur. $colonnes : array(array(titre, largeur relative, aligne gauche|droite)).
     * $lignes : textes déjà mis en forme. Une ligne trop longue pour sa colonne est coupée sur plusieurs lignes.
     * L'en-tête se répète en haut de chaque nouvelle page.
     */
    public function tableau($colonnes, $lignes, $corps = 9.5)
    {
        $utile = self::LARGEUR - 2 * self::MARGE;
        $total = array_sum(array_column($colonnes, 1));
        $xs = array();
        $x = self::MARGE;
        foreach ($colonnes as $i => $c) {
            $l = $utile * $c[1] / $total;
            $xs[$i] = array($x, $l);
            $x += $l;
        }
        $pas = $corps * 1.45;
        $entete = function () use ($colonnes, $xs, $corps, $pas) {
            $this->rectangle(self::MARGE, $this->y, self::LARGEUR - 2 * self::MARGE, $pas + 4, self::FOND);
            $base = $this->y + $corps + 3;
            foreach ($colonnes as $i => $c) {
                $droite = ($c[2] ?? 'gauche') === 'droite';
                $this->texte($droite ? $xs[$i][0] + $xs[$i][1] - 4 : $xs[$i][0] + 4, $base, $c[0], $corps - 0.5, true, self::ENCRE, $droite ? 'droite' : 'gauche');
            }
            $this->y += $pas + 4;
            $this->filet(self::MARGE, $this->y, self::LARGEUR - self::MARGE, 0.9, self::ENCRE);
        };
        $this->place($pas * 3);
        $entete();
        foreach ($lignes as $ligne) {
            $coupees = array();
            $n = 1;
            foreach ($colonnes as $i => $c) {
                $coupees[$i] = self::couper((string) ($ligne[$i] ?? ''), $xs[$i][1] - 8, $corps);
                $n = max($n, count($coupees[$i]));
            }
            $h = $n * $pas + 3;
            if ($this->place($h)) {
                $entete();
            }
            foreach ($colonnes as $i => $c) {
                $droite = ($c[2] ?? 'gauche') === 'droite';
                foreach ($coupees[$i] as $k => $morceau) {
                    $this->texte($droite ? $xs[$i][0] + $xs[$i][1] - 4 : $xs[$i][0] + 4, $this->y + $corps + 2 + $k * $pas, $morceau, $corps, false, self::ENCRE, $droite ? 'droite' : 'gauche');
                }
            }
            $this->y += $h;
            $this->filet(self::MARGE, $this->y, self::LARGEUR - self::MARGE);
        }
    }

    // SORTIE #########################################################

    /** Texte en chaîne PDF Unicode (métadonnées) : UTF-16BE avec indicateur d'ordre des octets, en hexadécimal. */
    private static function unicode($s)
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding((string) $s, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /** Le document complet (octets). Le pied de page de chaque page est posé ici, quand le nombre de pages est connu. */
    public function sortie()
    {
        $n = count($this->pages);
        foreach ($this->pages as $i => $contenu) {
            $this->courante = $i;
            $bas = self::HAUTEUR - self::MARGE + 6;
            $this->filet(self::MARGE, $bas - 14, self::LARGEUR - self::MARGE);
            if ($this->pied !== '') {
                $this->texte(self::MARGE, $bas, $this->pied, 8, false, self::SECONDE);
            }
            $this->texte(self::LARGEUR - self::MARGE, $bas, 'Page ' . ($i + 1) . ' sur ' . $n, 8, false, self::SECONDE, 'droite');
        }

        $objets = array();
        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objets[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objets[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objets[5] = '<< /Title ' . self::unicode($this->titre) . ' /Producer ' . self::unicode('Tour de contrôle NavUp') . ' /CreationDate (D:' . date('YmdHis') . ') >>';
        $ressources = '/Font << /F1 3 0 R /F2 4 0 R >>';
        $suivant = 6;
        if ($this->image !== null) {
            $im = $this->image;
            $objets[6] = "<< /Type /XObject /Subtype /Image /Width {$im['l']} /Height {$im['h']} /ColorSpace /" . ($im['canaux'] === 1 ? 'DeviceGray' : 'DeviceRGB')
                . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($im['donnees']) . " >>\nstream\n" . $im['donnees'] . "\nendstream";
            $ressources .= ' /XObject << /Im1 6 0 R >>';
            $suivant = 7;
        }
        $kids = array();
        foreach ($this->pages as $contenu) {
            $flux = gzcompress($contenu, 6);
            $objets[$suivant] = '<< /Length ' . strlen($flux) . " /Filter /FlateDecode >>\nstream\n" . $flux . "\nendstream";
            $objets[$suivant + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::LARGEUR . ' ' . self::HAUTEUR . '] /Resources << ' . $ressources . ' >> /Contents ' . $suivant . ' 0 R >>';
            $kids[] = ($suivant + 1) . ' 0 R';
            $suivant += 2;
        }
        $objets[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objets);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $positions = array();
        foreach ($objets as $num => $corps) {
            $positions[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $corps . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objets));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $positions[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }
}

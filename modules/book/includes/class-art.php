<?php
/**
 * Illustrations du livre dessinées par le code (GD) : arbre de couverture, fleuron séparateur, cadre de page orné.
 * Tout est généré à la volée dans la couleur du livre, en PNG transparent, sans fichier d'image à installer.
 * Le dessin est déterministe (même graine) : un même livre donne toujours la même illustration.
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Art {
    private static function rgb($im, $hex, $alpha = 0) {
        $hex = ltrim((string) $hex, '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) $hex = '7a1f1f';
        return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    private static function canvas($w, $h) {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        return $im;
    }

    private static function png($im) {
        ob_start();
        imagepng($im, null, 7);
        $b = ob_get_clean();
        self::free($im);
        return $b;
    }

    /** Suite de nombres pseudo-aléatoires reproductible (indépendante de mt_srand global). */
    private static function rnd(&$seed) {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        return $seed / 0x7fffffff;
    }

    /** Segment épais qui s'effile : quadrilatère de largeur $w1 à $w2. */
    private static function stroke($im, $x1, $y1, $x2, $y2, $w1, $w2, $col) {
        $dx = $x2 - $x1; $dy = $y2 - $y1;
        $l = max(0.0001, sqrt($dx * $dx + $dy * $dy));
        $nx = -$dy / $l; $ny = $dx / $l;
        self::poly($im, [
            (int) round($x1 + $nx * $w1 / 2), (int) round($y1 + $ny * $w1 / 2),
            (int) round($x2 + $nx * $w2 / 2), (int) round($y2 + $ny * $w2 / 2),
            (int) round($x2 - $nx * $w2 / 2), (int) round($y2 - $ny * $w2 / 2),
            (int) round($x1 - $nx * $w1 / 2), (int) round($y1 - $ny * $w1 / 2),
        ], $col);
    }

    /** Feuille effilée, $len de long, de largeur $wr × longueur. */
    private static function leaf($im, $x, $y, $ang, $len, $col, $wr = 0.34) {
        $wd = $len * $wr;
        $ca = cos($ang); $sa = sin($ang);
        $pts = [];
        foreach ([[0, 0], [0.18, -0.55], [0.45, -1], [0.75, -0.7], [1, 0], [0.72, 0.62], [0.42, 0.95], [0.16, 0.5]] as $p) {
            $lx = $p[0] * $len; $ly = $p[1] * $wd;
            $pts[] = (int) round($x + $lx * $ca - $ly * $sa);
            $pts[] = (int) round($y + $lx * $sa + $ly * $ca);
        }
        self::poly($im, $pts, $col);
    }

    /** Une feuille semi-transparente, posée seulement dans la silhouette de la cime, dans l'une des trois teintes. */
    private static function drop($im, $x, $y, $ang, $depth, &$seed, $c) {
        if (!empty($c['bare'])) return;
        $dx = ($x - $c['cx']) / $c['rx']; $dy = ($y - $c['cy']) / $c['ry'];
        $out = $dx * $dx + $dy * $dy;
        if ($out > 1.0 && self::rnd($seed) < min(0.95, 0.5 + ($out - 1.0))) return;
        $t = self::rnd($seed);
        $col = $t < 0.4 ? $c['l1'] : ($t < 0.78 ? $c['l2'] : $c['l3']);
        self::leaf($im, $x, $y, $ang, ($c['ls'][0] + self::rnd($seed) * ($c['ls'][1] - $c['ls'][0])) * $c['S'], $col, $c['lw']);
    }

    /**
     * Branche courbe qui s'effile et se ramifie ; les rameaux fins portent des feuilles
     * (trois teintes semi-transparentes qui se superposent, comme une aquarelle).
     * Réglages de silhouette dans $c : droop (retombée), pull (cap préféré), lp (densité de feuilles), lenf (longueur relative).
     */
    private static function limb($im, $x, $y, $ang, $len, $th, $depth, &$seed, $c) {
        $S = $c['S'];
        $segs = 5;
        $bend = (self::rnd($seed) - 0.5) * 0.55;
        $thEnd = max(1.1 * $S, $th * 0.66);
        $px = $x; $py = $y; $a = $ang;
        $pts = array();
        imagefilledellipse($im, (int) round($x), (int) round($y), (int) max(1, round($th)), (int) max(1, round($th)), $c['wood']);   // départ arrondi
        for ($k = 1; $k <= $segs; $k++) {
            $a += $bend / $segs + (self::rnd($seed) - 0.5) * 0.16;
            if ($depth >= $c['droopFrom']) $a += $c['droop'] * cos($a) / $segs;   // retombée : plus la branche est horizontale, plus elle plie
            elseif ($depth >= 4) $a += 0.025;
            $nx = $px + cos($a) * $len / $segs; $ny = $py + sin($a) * $len / $segs;
            $w1 = $th + ($thEnd - $th) * (($k - 1) / $segs); $w2 = $th + ($thEnd - $th) * ($k / $segs);
            self::stroke($im, $px, $py, $nx, $ny, $w1, $w2, $c['wood']);
            imagefilledellipse($im, (int) round($nx), (int) round($ny), (int) max(1, round($w2)), (int) max(1, round($w2)), $c['wood']);
            $pts[] = array($nx, $ny, $a);
            $px = $nx; $py = $ny;
        }
        if (isset($c['med']) && $depth >= 3 && $depth <= 5) $c['med'][] = array($pts[2][0], $pts[2][1]);   // emplacements possibles de médaillons
        if ($depth >= $c['leafFrom']) {
            foreach ($pts as $p) {
                if (self::rnd($seed) < $c['lp']) {
                    $side = self::rnd($seed) > .5 ? 1 : -1;
                    self::drop($im, $p[0], $p[1], $p[2] + $side * (0.5 + self::rnd($seed) * 0.7), $depth, $seed, $c);
                }
            }
        }
        if ($depth >= $c['max']) {
            for ($k = 0; $k < $c['tips']; $k++) self::drop($im, $px, $py, $a + (self::rnd($seed) - 0.5) * 1.8, $depth, $seed, $c);
            return;
        }
        $n = ($depth < 2 || self::rnd($seed) > 0.7) ? 3 : 2;
        if ($depth < 1 && !empty($c['fork2'])) $n = 2;
        for ($i = 0; $i < $n; $i++) {
            $na = $a + ($i - ($n - 1) / 2) * (0.55 + self::rnd($seed) * 0.25) + (self::rnd($seed) - 0.5) * 0.25;
            $pull = $c['pull'] === null ? -M_PI / 2 : $c['pull'];
            $na += ($pull - $na) * ($depth < 3 ? $c['pullf'] : 0.04);
            if ($c['pull'] === null) $na += ($px < $c['cx'] ? -1 : 1) * ($depth < 4 ? 0.06 : 0.0);
            self::limb($im, $px, $py, $na, $len * ($c['lenf'] + self::rnd($seed) * 0.1) * ($depth === 0 && isset($c['lenf0']) ? $c['lenf0'] : 1), $thEnd, $depth + 1, $seed, $c);
        }
        if ($depth >= 1 && $depth < $c['max'] - 1 && self::rnd($seed) > 0.45) {   // rameau latéral à mi-longueur
            $side = self::rnd($seed) > .5 ? 1 : -1;
            self::limb($im, $pts[2][0], $pts[2][1], $pts[2][2] + $side * (0.7 + self::rnd($seed) * 0.4), $len * 0.55, $thEnd * 0.8, $depth + 2, $seed, $c);
        }
    }

    /** Racine : courbe arrondie, épaisse à la base, qui s'effile en ondulant sur le sol. */
    private static function root($im, $x, $y, $ang, $len, $th, &$seed, $col, $S) {
        $segs = 12; $a = $ang; $px = $x; $py = $y;
        imagefilledellipse($im, (int) round($x), (int) round($y), (int) round($th), (int) round($th), $col);
        $flat = cos($ang) >= 0 ? 0.12 : M_PI - 0.12;   // la racine s'aplatit peu à peu vers l'horizontale
        for ($k = 1; $k <= $segs; $k++) {
            $a += ($flat - $a) * 0.16 + (self::rnd($seed) - 0.5) * 0.16;
            $nx = $px + cos($a) * $len / $segs; $ny = $py + sin($a) * $len / $segs;
            $f1 = pow(1 - ($k - 1) / $segs, 0.6); $f2 = pow(1 - $k / $segs, 0.6);
            $w1 = max(2.4 * $S, $th * $f1); $w2 = max(2.4 * $S, $th * $f2);
            self::stroke($im, $px, $py, $nx, $ny, $w1, $w2, $col);
            imagefilledellipse($im, (int) round($nx), (int) round($ny), (int) round($w2), (int) round($w2), $col);   // bouts arrondis
            $px = $nx; $py = $ny;
        }
    }

    /** Racine souterraine : courbe souple qui s'effile, s'infléchit vers le bas et émet des radicelles de part et d'autre. */
    private static function rootbr($im, $x, $y, $ang, $len, $th, $depth, &$seed, $col, $S) {
        $segs = 16; $a = $ang; $px = $x; $py = $y; $curve = (self::rnd($seed) - 0.5) * 0.12; $side = self::rnd($seed) > 0.5 ? 1 : -1;
        imagefilledellipse($im, (int) round($x), (int) round($y), (int) max(1, round($th)), (int) max(1, round($th)), $col);
        for ($k = 1; $k <= $segs; $k++) {
            $curve = $curve * 0.82 + (self::rnd($seed) - 0.5) * 0.09;   // courbure qui persiste : pas d'angles brusques
            $a += $curve + (M_PI / 2 - $a) * 0.022;
            $nx = $px + cos($a) * $len / $segs; $ny = $py + sin($a) * $len / $segs;
            $f1 = pow(1 - ($k - 1) / $segs, 0.75); $f2 = pow(1 - $k / $segs, 0.75);
            $w1 = max(1.5 * $S, $th * $f1); $w2 = max(1.5 * $S, $th * $f2);
            self::stroke($im, $px, $py, $nx, $ny, $w1, $w2, $col);
            imagefilledellipse($im, (int) round($nx), (int) round($ny), (int) round($w2), (int) round($w2), $col);
            if ($depth < 2 && $k >= 4 && $k <= 13 && self::rnd($seed) < 0.26) {   // radicelle, en alternant les côtés
                $side = -$side;
                self::rootbr($im, $nx, $ny, $a + $side * (0.55 + self::rnd($seed) * 0.5), $len * (0.34 + self::rnd($seed) * 0.16) * (1 - $k / $segs * 0.4), $w2 * 0.8, $depth + 1, $seed, $col, $S);
            }
            $px = $nx; $py = $ny;
        }
    }

    /** Pied de tronc évasé : le tronc s'élargit en courbe creuse et se pose sur le sol, avec de petits renflements de racines. */
    private static function flare($im, $x, $y, $tw, $fw, $fh, $col, $S, $asym = 0.0, $lean = 0.0) {
        $n = 16; $L = array(); $R = array();
        for ($i = 0; $i <= $n; $i++) {
            $t = $i / $n; $e = pow($t, 2.3);
            $bump = 1 + 0.07 * sin($t * 9.5) * $t;   // renflements des racines
            $L[] = array($lean * (1 - $t) + $x - $tw / 2 - ($fw * (1 + $asym) - $tw / 2) * $e * $bump, $y - $fh + $fh * $t);
            $R[] = array($lean * (1 - $t) + $x + $tw / 2 + ($fw * (1 - $asym) - $tw / 2) * $e * (2 - $bump), $y - $fh + $fh * $t);
        }
        $pts = array();
        foreach ($L as $p) { $pts[] = (int) round($p[0]); $pts[] = (int) round($p[1]); }
        $pts[] = (int) round($L[$n][0] + 4 * $S); $pts[] = (int) round($y + 3 * $S);
        $pts[] = (int) round($R[$n][0] - 4 * $S); $pts[] = (int) round($y + 3 * $S);
        for ($i = $n; $i >= 0; $i--) { $pts[] = (int) round($R[$i][0]); $pts[] = (int) round($R[$i][1]); }
        self::poly($im, $pts, $col);
    }

    /** Lavis : taches douces et irrégulières (coussin de gaussiennes calculé en basse résolution puis agrandi sans crénelage). */
    private static function wash($im, $W, $H, $hex, array $blobs, $maxOpacity) {
        $f = 10; $sw = (int) ceil($W / $f); $sh = (int) ceil($H / $f);
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false); imagesavealpha($small, true);
        $hx = ltrim((string) $hex, '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $hx)) $hx = '7a1f1f';
        $r = hexdec(substr($hx, 0, 2)); $g = hexdec(substr($hx, 2, 2)); $b = hexdec(substr($hx, 4, 2));
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $px = $x * $f; $py = $y * $f; $cov = 0.0;
                foreach ($blobs as $bl) {
                    $dx = ($px - $bl[0]) / $bl[2]; $dy = ($py - $bl[1]) / $bl[3];
                    $d = $dx * $dx + $dy * $dy;
                    if ($d < 4) $cov += $bl[4] * exp(-$d * 1.6);
                }
                $ex = min($px / ($W * 0.12), ($W - $px) / ($W * 0.12), $py / ($H * 0.12), ($H - $py) / ($H * 0.12), 1.0);   // le lavis s'efface avant le bord de l'image
                $cov = min(1.0, $cov) * ($ex <= 0 ? 0 : $ex * $ex * (3 - 2 * $ex));
                imagesetpixel($small, $x, $y, imagecolorallocatealpha($small, $r, $g, $b, (int) round(127 - $cov * $maxOpacity * 127)));
            }
        }
        $big = imagescale($small, $W, $H, IMG_BICUBIC);   // agrandissement lissé
        if (!$big) {
            $big = imagecreatetruecolor($W, $H);
            imagealphablending($big, false); imagesavealpha($big, true);
            imagefill($big, 0, 0, imagecolorallocatealpha($big, 255, 255, 255, 127));
            imagecopyresampled($big, $small, 0, 0, 0, 0, $W, $H, $sw, $sh);
        }
        imagealphablending($big, false); imagesavealpha($big, true);
        self::free($small);
        imagealphablending($im, true);
        imagecopy($im, $big, 0, 0, 0, 0, $W, $H);
        self::free($big);
    }

    /** Valeurs du réglage « illustration de couverture » : none, ornament, puis un arbre (tree = chêne, olive, willow, roots). */
    /** Polygone plein : le paramètre « nombre de points » est obsolète depuis PHP 8.1 (avis « Deprecated » si l’hôte les affiche). */
    public static function poly($im, array $pts, $col) {
        if (PHP_VERSION_ID >= 80100) {
            return imagefilledpolygon($im, $pts, $col);
        }
        return imagefilledpolygon($im, $pts, (int) (count($pts) / 2), $col);
    }

    /** Libère une image GD : inutile (et obsolète, PHP 8.5) depuis PHP 8.0, où les images sont des objets. */
    public static function free($im) {
        if (PHP_VERSION_ID < 80000 && is_resource($im)) {
            imagedestroy($im);
        }
    }

    public static function cover_choices() {
        return array('none', 'ornament', 'tree', 'olive', 'willow', 'roots', 'color', 'page', 'meadow', 'farm', 'chene', 'peinture', 'chene2', 'chene3', 'chene4', 'nympheas', 'feuillage');
    }

    /** Pochettes pleine page (fond de la 1re page du PDF). */
    public static function page_covers() {
        return array('page', 'meadow', 'farm', 'chene', 'peinture', 'chene2', 'chene3', 'chene4');
    }

    /** Pochettes à image fournie (assets/img/cover-<nom>.jpg). */
    public static function image_covers() {
        return array('meadow', 'farm', 'chene', 'peinture', 'chene2', 'chene3', 'chene4');
    }

    /** Style d'arbre correspondant à une valeur du réglage (tree → oak). */
    public static function style_of($art) {
        if ($art === 'winter') $art = 'roots';   // ancien nom (2.8.2)
        if ($art === 'page') return 'olive';   // hors PDF : l'arbre penché, sans fond de page
        if ($art === 'meadow') return 'color';
        if (in_array($art, self::image_covers(), true)) return 'oak';
        return $art === 'tree' ? 'oak' : (in_array($art, self::tree_styles(), true) ? $art : 'oak');
    }

    /** Styles d'arbre proposés pour la couverture. */
    public static function tree_styles() {
        return array('oak', 'olive', 'willow', 'roots', 'color');
    }

    /**
     * Arbre à l'aquarelle, PNG transparent de $w × $h pixels, dans la couleur $hex.
     * $style : oak (chêne ample), olive (arbre penché aux branches légères), willow (saule pleureur),
     * roots (arbre feuillu dont les racines nues sont dessinées sous le sol, comme un arbre généalogique).
     */
    public static function tree($hex, $w = 900, $h = 760, $style = 'oak') {
        if (!in_array($style, self::tree_styles(), true)) $style = 'oak';
        if ($style === 'color') return self::tree_color($hex, $w, $h);
        $S = 2;
        $W = $w * $S; $H = $h * $S;
        $im = self::canvas($W, $H);
        $seed = array('oak' => 31337, 'olive' => 777, 'willow' => 4242, 'roots' => 1189)[$style];
        $c = array(
            'S' => $S, 'max' => 7, 'wood' => self::rgb($im, $hex, 0),
            'l1' => self::rgb($im, $hex, 108), 'l2' => self::rgb($im, $hex, 90), 'l3' => self::rgb($im, $hex, 64),
            'cx' => $W / 2, 'cy' => $H * 0.4, 'rx' => $W * 0.47, 'ry' => $H * 0.3,
            'droop' => 0, 'droopFrom' => 99, 'pull' => null, 'pullf' => 0.12, 'lp' => 0.42, 'leafFrom' => 3, 'tips' => 2,
            'lenf' => 0.7, 'ls' => array(10, 19), 'lw' => 0.34, 'bare' => false, 'fork2' => false,
        );
        $ground = $H * 0.88; $tx = $W / 2; $tang = -M_PI / 2 + 0.02; $tlen = $H * 0.17; $tth = 40 * $S; $tbase = 14 * $S;
        $wash = 0.24; $nb = 38; $roots = true; $mirror = false;
        if ($style === 'olive') {
            $c = array_merge($c, array('max' => 6, 'pull' => -0.3, 'pullf' => 0.16, 'droop' => 0.35, 'droopFrom' => 3, 'lp' => 0.3, 'leafFrom' => 2,
                'ls' => array(15, 24), 'lw' => 0.27, 'lenf' => 0.7, 'cx' => $W * 0.52, 'cy' => $H * 0.38, 'rx' => $W * 0.46, 'ry' => $H * 0.3, 'fork2' => true,
                'l1' => self::rgb($im, $hex, 96), 'l2' => self::rgb($im, $hex, 78), 'l3' => self::rgb($im, $hex, 52)));
            $tx = $W * 0.27; $tang = -M_PI / 2 + 0.33; $tlen = $H * 0.17; $tth = 42 * $S; $wash = 0.16; $nb = 14;
        } elseif ($style === 'willow') {
            $c = array_merge($c, array('max' => 6, 'droop' => 1.05, 'droopFrom' => 2, 'lp' => 0.75, 'leafFrom' => 2, 'tips' => 3,
                'ls' => array(14, 22), 'lw' => 0.2, 'lenf' => 0.74, 'pullf' => 0.2, 'cx' => $W * 0.47, 'cy' => $H * 0.52, 'rx' => $W * 0.47, 'ry' => $H * 0.4,
                'l1' => self::rgb($im, $hex, 104), 'l2' => self::rgb($im, $hex, 84), 'l3' => self::rgb($im, $hex, 58)));
            $tx = $W * 0.47; $tang = -M_PI / 2 + 0.06; $tlen = $H * 0.16; $tth = 40 * $S; $wash = 0.2; $nb = 26;
        } elseif ($style === 'roots') {
            $c = array_merge($c, array('max' => 6, 'lenf' => 0.72, 'lenf0' => 1.7, 'lp' => 0.5, 'cy' => $H * 0.3, 'rx' => $W * 0.44, 'ry' => $H * 0.27));
            $ground = $H * 0.6; $tlen = $H * 0.12; $tth = 36 * $S; $tbase = 0; $wash = 0.2; $nb = 26; $roots = false; $mirror = true;
        }
        // lavis de la cime et ombre au sol
        $blobs = array();
        for ($k = 0; $k < $nb; $k++) {
            $t = self::rnd($seed) * 2 * M_PI; $r = sqrt(self::rnd($seed));
            $blobs[] = array($c['cx'] + cos($t) * $r * $c['rx'] * 0.7, $c['cy'] + sin($t) * $r * $c['ry'] * 0.7, (60 + self::rnd($seed) * 70) * $S, (50 + self::rnd($seed) * 55) * $S, 0.3 + self::rnd($seed) * 0.4);
        }
        for ($k = 0; $k < 5; $k++) {
            $blobs[] = array($tx + (self::rnd($seed) - 0.5) * 220 * $S, $ground + 8 * $S, (90 + self::rnd($seed) * 70) * $S, (10 + self::rnd($seed) * 8) * $S, 0.7);
        }
        self::wash($im, $W, $H, $hex, $blobs, $wash);
        if ($roots) self::flare($im, $tx, $ground, $tth * 0.84, $tth * 1.6, 46 * $S, $c['wood'], $S, 0.1, $style === 'olive' ? 15 * $S : 1 * $S);   // pied de tronc évasé
        self::limb($im, $tx, $ground - $tbase, $tang, $tlen, $tth, 0, $seed, $c);
        if ($mirror) {   // racines : l'image du houppier, plus fine, sous la ligne de sol
            $rc = array_merge($c, array('bare' => true, 'max' => 4, 'cy' => $ground + $H * 0.2, 'lenf' => 0.7, 'lenf0' => 1.1, 'pull' => M_PI / 2, 'pullf' => 0.1, 'droopFrom' => 99));
            self::flare($im, $tx, $ground, $tth * 0.95, $tth * 1.7, 34 * $S, $c['wood'], $S, 0.06);   // collet évasé
            // maîtresses racines nées du collet : deux latérales qui plongent en s'écartant, une pivot, pas de symétrie parfaite
            $tmp = self::canvas($W, $H);
            foreach (array(array(-20, 1.0, 0.085, 20), array(2, 0.12, 0.105, 18), array(20, -0.85, 0.09, 19)) as $r) {
                self::rootbr($tmp, $tx + $r[0] * $S, $ground + 2 * $S, M_PI / 2 + $r[1], $H * $r[2] * 3.1, $r[3] * $S, 0, $seed, $c['wood'], $S);
            }
            imagealphablending($tmp, false);   // rien au-dessus du sol : les racines restent sous la ligne
            imagefilledrectangle($tmp, 0, 0, $W, (int) ($ground + 1 * $S), self::col($tmp, 0, 0, 0, 127));
            imagealphablending($tmp, true);
            imagecopy($im, $tmp, 0, 0, 0, 0, $W, $H);
            self::free($tmp);
        }
        // quelques feuilles qui tombent
        for ($k = 0; $k < ($style === 'roots' ? 4 : 7); $k++) {
            $x = $tx + (self::rnd($seed) - 0.3) * 520 * $S; $y = $H * ($style === 'roots' ? 0.45 + self::rnd($seed) * 0.12 : 0.6 + self::rnd($seed) * 0.26);
            self::leaf($im, $x, $y, self::rnd($seed) * 6.28, (9 + self::rnd($seed) * 6) * $S, $c['l2'], 0.34);
        }
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        self::free($im);
        return self::png($out);
    }

    private static function col($im, $r, $g, $b, $a = 0) {
        return imagecolorallocatealpha($im, $r, $g, $b, $a);
    }

    /** Hauteur de la crête du terre-plein en x relatif t (-1 à 1). */
    private static function mound($t, $y0, $H) {
        return $y0 + 0.07 * $H * $t * $t;
    }

    /**
     * Arbre en couleurs sur un îlot de terre en coupe (herbe, couches de terre, cailloux), feuillage vert,
     * tronc brun, racines dans la terre et médaillons vides (cadres ronds de la couleur du livre) posés sur les branches.
     * Image large (environ 1,6 × 1) : $w × $h pixels, fond transparent.
     */
    private static function tree_color($hex, $w, $h) {
        $S = 2;
        $w = max($w, 1000); $h = (int) round($w * 0.68);
        $W = $w * $S; $H = $h * $S;
        $im = self::canvas($W, $H);
        $seed = 5150;
        $cx = $W / 2; $hw = $W * 0.46; $y0 = $H * 0.66; $depth = $H * 0.28;
        $med = new ArrayObject();
        $c = array(
            'S' => $S, 'max' => 7, 'wood' => self::col($im, 92, 62, 40), 'med' => $med,
            'l1' => self::col($im, 108, 170, 66, 8), 'l2' => self::col($im, 70, 148, 62, 4), 'l3' => self::col($im, 40, 112, 52, 0),
            'cx' => $cx, 'cy' => $H * 0.3, 'rx' => $W * 0.45, 'ry' => $H * 0.27,
            'droop' => 0.1, 'droopFrom' => 4, 'pull' => null, 'pullf' => 0.12, 'lp' => 0.85, 'leafFrom' => 2, 'tips' => 4,
            'lenf' => 0.8, 'lenf0' => 1.4, 'ls' => array(16, 28), 'lw' => 0.4, 'bare' => false, 'fork2' => false,
        );
        // halo vert derrière le houppier
        $blobs = array();
        for ($k = 0; $k < 22; $k++) {
            $t = self::rnd($seed) * 2 * M_PI; $r = sqrt(self::rnd($seed));
            $blobs[] = array($cx + cos($t) * $r * $c['rx'] * 0.7, $c['cy'] + sin($t) * $r * $c['ry'] * 0.7, (70 + self::rnd($seed) * 70) * $S, (55 + self::rnd($seed) * 50) * $S, 0.4 + self::rnd($seed) * 0.4);
        }
        self::wash($im, $W, $H, '8cc152', $blobs, 0.2);
        // îlot de terre : contour (crête) puis fond arrondi
        $top = array(); $bot = array(); $N = 60;
        for ($i = 0; $i <= $N; $i++) {
            $t = -1 + 2 * $i / $N;
            $top[] = array($cx + $t * $hw, self::mound($t, $y0, $H));
            $bot[] = array($cx + $t * $hw, self::mound($t, $y0, $H) + $depth * sqrt(max(0, 1 - $t * $t)));
        }
        $poly = function ($upper, $lower, $off) {
            $pts = array(); $n = count($upper);
            for ($i = 0; $i < $n; $i++) { $y = min($upper[$i][1] + $off, $lower[$i][1]); $pts[] = (int) round($upper[$i][0]); $pts[] = (int) round($y); }
            for ($i = $n - 1; $i >= 0; $i--) { $pts[] = (int) round($lower[$i][0]); $pts[] = (int) round($lower[$i][1]); }
            return $pts;
        };
        $fill = function ($off, $col) use ($im, $top, $bot, $poly) { $p = $poly($top, $bot, $off); self::poly($im, $p, $col); };
        $fill(0, self::col($im, 126, 86, 56));
        $fill(0.1 * $H, self::col($im, 106, 71, 46));
        $fill(0.19 * $H, self::col($im, 84, 56, 38));
        for ($k = 0; $k < 40; $k++) {   // cailloux et graviers
            $t = (self::rnd($seed) - 0.5) * 1.8; $yt = self::mound($t, $y0, $H);
            $yb = $yt + $depth * sqrt(max(0, 1 - $t * $t));
            $y = $yt + (0.05 + self::rnd($seed) * 0.8) * ($yb - $yt);
            $d = (4 + self::rnd($seed) * 9) * $S;
            $g = 120 + (int) (self::rnd($seed) * 60);
            imagefilledellipse($im, (int) ($cx + $t * $hw), (int) $y, (int) $d, (int) ($d * 0.7), self::col($im, $g, (int) ($g * 0.82), (int) ($g * 0.66), 60));
        }
        // racines dans la terre, de part et d'autre, puis rognées au contour de l'îlot
        $rc = array_merge($c, array('bare' => true, 'max' => 5, 'lenf' => 0.76, 'lenf0' => 1.15, 'pull' => M_PI / 2, 'pullf' => 0.1, 'droopFrom' => 99, 'wood' => self::col($im, 62, 40, 26)));
        unset($rc['med']);
        $tmp = self::canvas($W, $H);   // les racines sont dessinées à part : ce qui dépasse au-dessus de l'herbe est effacé
        foreach (array(-1, 1) as $side) {
            foreach (array(0.4, 0.95) as $o) {
                self::limb($tmp, $cx + $side * 6 * $S, $y0 + 0.01 * $H, M_PI / 2 - $side * $o, $H * 0.085, (18 - $o * 4) * $S, 1, $seed, $rc);
            }
        }
        imagealphablending($tmp, false);
        $up = array();
        foreach ($top as $p) { $up[] = (int) round($p[0]); $up[] = (int) round($p[1] + 4 * $S); }
        array_push($up, (int) $W, 0, 0, 0);
        self::poly($tmp, $up, self::col($tmp, 0, 0, 0, 127));
        imagealphablending($tmp, true);
        imagecopy($im, $tmp, 0, 0, 0, 0, $W, $H);
        self::free($tmp);
        imagealphablending($im, false);
        $clear = self::col($im, 0, 0, 0, 127);
        $out = array();
        foreach ($bot as $p) { $out[] = (int) round($p[0]); $out[] = (int) round($p[1]); }
        array_push($out, (int) $W, (int) $H, 0, (int) $H);
        self::poly($im, $out, $clear);
        imagefilledrectangle($im, 0, (int) $y0, (int) ($cx - $hw), $H, $clear);
        imagefilledrectangle($im, (int) ($cx + $hw), (int) $y0, $W, $H, $clear);
        imagealphablending($im, true);
        // herbe (bande suivant la crête) et brins
        $g1 = self::col($im, 112, 160, 60); $g2 = self::col($im, 82, 132, 48);
        for ($i = 0; $i < $N; $i++) {
            self::poly($im, array((int) $top[$i][0], (int) $top[$i][1], (int) $top[$i + 1][0], (int) $top[$i + 1][1], (int) $top[$i + 1][0], (int) ($top[$i + 1][1] + 18 * $S), (int) $top[$i][0], (int) ($top[$i][1] + 18 * $S)), $g1);
            self::poly($im, array((int) $top[$i][0], (int) ($top[$i][1] + 14 * $S), (int) $top[$i + 1][0], (int) ($top[$i + 1][1] + 14 * $S), (int) $top[$i + 1][0], (int) ($top[$i + 1][1] + 24 * $S), (int) $top[$i][0], (int) ($top[$i][1] + 24 * $S)), $g2);
        }
        for ($k = 0; $k < 70; $k++) {
            $t = (self::rnd($seed) - 0.5) * 1.96; $x = $cx + $t * $hw; $y = self::mound($t, $y0, $H) + 2 * $S;
            $hgt = (8 + self::rnd($seed) * 12) * $S; $lean = (self::rnd($seed) - 0.5) * 8 * $S;
            self::poly($im, array((int) ($x - 2 * $S), (int) $y, (int) ($x + 2 * $S), (int) $y, (int) ($x + $lean), (int) ($y - $hgt)), $g1);
        }
        // pied du tronc : racines apparentes, puis tronc et houppier
        $wood = $c['wood'];
        self::flare($im, $cx, $y0 + 2 * $S, 52 * $S, 98 * $S, 34 * $S, $wood, $S, -0.08);
        self::limb($im, $cx, $y0 - 4 * $S, -M_PI / 2 + 0.02, $H * 0.085, 56 * $S, 0, $seed, $c);
        // médaillons : on garde ceux qui sont dans la cime, bien espacés
        $cands = $med->getArrayCopy();
        $pick = array();
        $minD = 74 * $S;
        for ($k = count($cands) - 1; $k > 0; $k--) { $j = (int) (self::rnd($seed) * ($k + 1)); $tmp = $cands[$k]; $cands[$k] = $cands[$j]; $cands[$j] = $tmp; }
        foreach ($cands as $p) {
            if ($p[1] > $y0 - 0.24 * $H || $p[1] < 0.08 * $H) continue;
            $ok = true;
            foreach ($pick as $q) { if (hypot($p[0] - $q[0], $p[1] - $q[1]) < $minD) { $ok = false; break; } }
            if ($ok) $pick[] = $p;
            if (count($pick) >= 9) break;
        }
        $ring = self::rgb($im, $hex); $cream = self::col($im, 250, 244, 226); $rim = self::col($im, 233, 222, 190);
        foreach ($pick as $p) {
            $d = 48 * $S;
            imagefilledellipse($im, (int) $p[0], (int) $p[1], (int) ($d + 8 * $S), (int) ($d + 8 * $S), self::col($im, 0, 0, 0, 100));   // ombre portée légère
            imagefilledellipse($im, (int) $p[0], (int) $p[1], (int) $d, (int) $d, $ring);
            imagefilledellipse($im, (int) $p[0], (int) $p[1], (int) ($d - 7 * $S), (int) ($d - 7 * $S), $rim);
            imagefilledellipse($im, (int) $p[0], (int) $p[1], (int) ($d - 11 * $S), (int) ($d - 11 * $S), $cream);
        }
        $outim = imagecreatetruecolor($w, $h);
        imagealphablending($outim, false); imagesavealpha($outim, true);
        imagefill($outim, 0, 0, imagecolorallocatealpha($outim, 255, 255, 255, 127));
        imagecopyresampled($outim, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        self::free($im);
        return self::png($outim);
    }

    /**
     * Pochette pleine page (A4, JPEG) : fond crème, arbre à l'aquarelle qui part du bord gauche et ses racines,
     * feuilles gris-bleu (teinte du livre atténuée), place libre dessous pour le titre. Posée en fond de la première page du PDF.
     */
    public static function cover_page($hex, $w = 1240, $h = 1754, $variant = 'page') {
        if (in_array($variant, self::image_covers(), true)) {   // illustrations fournies (assets/img), déjà au format A4
            $bin = @file_get_contents(TNGBOOK_DIR . 'assets/img/cover-' . $variant . '.jpg');
            if ($bin !== false && $bin !== '') return $bin;
        }
        $S = 2; $u = $w * $S / 1800;   // unité de dessin : l'arbre « olive » est dessiné pour 1800 px de large
        $W = $w * $S; $H = $h * $S;
        $im = self::canvas($W, $H);
        $hx = ltrim((string) $hex, '#'); if (!preg_match('/^[0-9a-f]{6}$/i', $hx)) $hx = '7a1f1f';
        $mix = function ($t) use ($hx) { $o = ''; foreach (array(0, 2, 4) as $i) { $o .= sprintf('%02x', (int) round(hexdec(substr($hx, $i, 2)) * $t + array(0x6f, 0x83, 0x93)[$i / 2] * (1 - $t))); } return $o; };
        $leaf = $mix(0.1);
        $seed = 20261116;
        $ground = $H * 0.72; $tx = $W * 0.075;
        $c = array(
            'S' => $u, 'max' => 7, 'wood' => self::col($im, 128, 116, 102),
            'l1' => self::rgb($im, $leaf, 70), 'l2' => self::rgb($im, $leaf, 52), 'l3' => self::rgb($im, $leaf, 30),
            'cx' => $W * 0.55, 'cy' => $H * 0.3, 'rx' => $W * 0.5, 'ry' => $H * 0.3,
            'droop' => 0.18, 'droopFrom' => 4, 'pull' => -0.75, 'pullf' => 0.2, 'lp' => 0.28, 'leafFrom' => 2, 'tips' => 2,
            'lenf' => 0.84, 'lenf0' => 2.9, 'ls' => array(34, 58), 'lw' => 0.3, 'bare' => false, 'fork2' => true,
        );
        // lavis léger derrière la ramure et le pied du tronc
        $blobs = array();
        for ($k = 0; $k < 40; $k++) {
            $blobs[] = array($W * (0.1 + self::rnd($seed) * 0.8), $H * (0.06 + self::rnd($seed) * 0.4), (90 + self::rnd($seed) * 110) * $u, (70 + self::rnd($seed) * 70) * $u, 0.3 + self::rnd($seed) * 0.4);
        }
        self::wash($im, $W, $H, $leaf, $blobs, 0.07);
        $blobs = array();
        for ($k = 0; $k < 12; $k++) $blobs[] = array($tx + (self::rnd($seed) - 0.3) * 260 * $u, $ground + (self::rnd($seed) - 0.3) * 200 * $u, (100 + self::rnd($seed) * 90) * $u, (60 + self::rnd($seed) * 60) * $u, 0.5 + self::rnd($seed) * 0.4);
        self::wash($im, $W, $H, 'a89880', $blobs, 0.2);
        // pied de tronc évasé, posé sur le sol (le tronc penche vers la droite)
        self::flare($im, $tx, $ground, 64 * $u * 0.84, 64 * $u * 1.7, 52 * $u, $c['wood'], $u, 0.15, 20 * $u);
        self::limb($im, $tx, $ground - 18 * $u, -M_PI / 2 + 0.38, 129 * $u, 64 * $u, 0, $seed, $c);
        for ($k = 0; $k < 9; $k++) {   // feuilles qui tombent vers le titre
            self::leaf($im, $W * (0.3 + self::rnd($seed) * 0.6), $H * (0.44 + self::rnd($seed) * 0.16), self::rnd($seed) * 6.28, (12 + self::rnd($seed) * 8) * $u, $c['l2'], 0.34);
        }
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 243, 240, 232));   // crème
        imagealphablending($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        self::free($im);
        ob_start(); imagejpeg($out, null, 88); $bin = (string) ob_get_clean();
        self::free($out);
        return $bin;
    }

    /** Mélange de deux couleurs [r,g,b] : $t = 0 → $a, 1 → $b. */
    private static function mixc(array $a, array $b, $t) {
        return array((int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t));
    }

    /** Palette « Monet » dérivée de la couleur du livre : pétales, feuillages, nymphéas et touches en camaïeu de cette couleur. */
    private static function monet_pal($hex) {
        $h = ltrim((string) $hex, '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $h)) $h = '7a1f1f';
        $b = array(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
        $w = array(255, 255, 255); $k = array(24, 20, 20);
        return array(
            'petal' => array(self::mixc($b, $w, 0.16), self::mixc($b, $w, 0.32), self::mixc($b, $k, 0.0), self::mixc($b, $w, 0.46)),
            'core'  => self::mixc($b, $k, 0.62),
            'stem'  => self::mixc($b, $w, 0.12),
            'leaf1' => self::mixc($b, $w, 0.30),
            'leaf2' => self::mixc($b, $w, 0.50),
            'pad'   => self::mixc($b, $w, 0.40),
            'pad2'  => self::mixc($b, $w, 0.58),
            'row'   => array(self::mixc($b, $w, 0.55), self::mixc($b, $w, 0.74), self::mixc($b, $w, 0.90)),
            'heart' => self::mixc($b, array(244, 210, 120), 0.62),
            'touch' => self::mixc($b, array(244, 210, 120), 0.5),
        );
    }

    /** Tache arrondie (ellipse inclinée) : pétale, galet de lumière, touche de pinceau. */
    private static function dab($im, $x, $y, $rx, $ry, $ang, $col) {
        $ca = cos($ang); $sa = sin($ang); $pts = array();
        for ($k = 0; $k < 22; $k++) {
            $t = $k / 22 * 2 * M_PI; $lx = cos($t) * $rx; $ly = sin($t) * $ry;
            $pts[] = (int) round($x + $lx * $ca - $ly * $sa); $pts[] = (int) round($y + $lx * $sa + $ly * $ca);
        }
        self::poly($im, $pts, $col);
    }

    /** Coquelicot : pétales ronds semi-transparents qui se superposent, cœur sombre et étamines. */
    private static function poppy($im, $x, $y, $r, $ang, &$seed, $pal = null) {
        $tones = $pal ? $pal['petal'] : array(array(214, 70, 52), array(226, 94, 60), array(196, 52, 46), array(232, 120, 76));
        $cc = $pal ? $pal['core'] : array(58, 44, 44);
        for ($k = 0; $k < 5; $k++) {
            $a = $ang + $k * 1.2566 + (self::rnd($seed) - 0.5) * 0.4; $t = $tones[$k % 4];
            self::dab($im, $x + cos($a) * $r * 0.46, $y + sin($a) * $r * 0.46, $r * 0.66, $r * 0.58, $a, imagecolorallocatealpha($im, $t[0], $t[1], $t[2], ($pal ? 14 : 36) + (int) (self::rnd($seed) * 24)));
        }
        imagefilledellipse($im, (int) $x, (int) $y, (int) ($r * 0.5), (int) ($r * 0.5), imagecolorallocatealpha($im, $cc[0], $cc[1], $cc[2], 4));
        for ($k = 0; $k < 9; $k++) {
            $a = $k * 0.698 + $ang; imagefilledellipse($im, (int) ($x + cos($a) * $r * 0.33), (int) ($y + sin($a) * $r * 0.33), (int) max(2, $r * 0.09), (int) max(2, $r * 0.09), imagecolorallocatealpha($im, (int) ($cc[0] * 0.7), (int) ($cc[1] * 0.7), (int) ($cc[2] * 0.7), 20));
        }
    }

    /** Nymphéa vu de côté : galette verte sur l'eau, pétales roses étagés, cœur doré, rides autour. */
    private static function lily($im, $x, $y, $r, $col, &$seed, $pal = null) {
        $pd = $pal ? $pal['pad'] : array(96, 142, 104); $pd2 = $pal ? $pal['pad2'] : array(130, 170, 120);
        $rw = $pal ? $pal['row'] : array(array(236, 170, 188), array(246, 206, 216), array(252, 236, 240));
        $ht = $pal ? $pal['heart'] : array(240, 196, 80);
        for ($k = 3; $k >= 1; $k--) {   // rides sur l'eau
            imagesetthickness($im, (int) max(1, $r * 0.04));
            imagearc($im, (int) $x, (int) ($y + $r * 0.14), (int) ($r * (1.5 + $k * 0.5)), (int) ($r * (0.3 + $k * 0.11)), 0, 360, $col);
        }
        imagesetthickness($im, 1);
        self::dab($im, $x, $y + $r * 0.12, $r * 1.15, $r * 0.28, 0, imagecolorallocatealpha($im, $pd[0], $pd[1], $pd[2], 30));
        self::dab($im, $x + $r * 0.5, $y + $r * 0.2, $r * 0.55, $r * 0.16, 0.05, imagecolorallocatealpha($im, $pd2[0], $pd2[1], $pd2[2], 50));
        $rows = array(array(7, 1.0, 120, $rw[0]), array(5, 0.8, 70, $rw[1]), array(3, 0.55, 20, $rw[2]));
        foreach ($rows as $row) {
            for ($k = 0; $k < $row[0]; $k++) {
                $a = -M_PI / 2 + ($k - ($row[0] - 1) / 2) * (0.36 + 0.04 * (7 - $row[0]) / 2) + (self::rnd($seed) - 0.5) * 0.08;
                self::leaf($im, $x, $y + $r * 0.05, $a, $r * $row[1] * (0.95 + 0.1 * self::rnd($seed)), imagecolorallocatealpha($im, $row[3][0], $row[3][1], $row[3][2], 28 + (int) (self::rnd($seed) * 20)), 0.3);
            }
        }
        imagefilledellipse($im, (int) $x, (int) ($y - $r * 0.05), (int) ($r * 0.22), (int) ($r * 0.14), imagecolorallocatealpha($im, $ht[0], $ht[1], $ht[2], 8));
    }

    /**
     * Fleuron « Monet » : nymphéa au centre, galettes et coquelicots de part et d'autre, roseaux, rides sur l'eau qui s'effilent
     * (dessiné par le plugin, touches translucides façon impressionniste ; les rides prennent la couleur du livre).
     */
    public static function garland($hex, $w = 1000, $h = 110) {
        $S = 3; $W = $w * $S; $H = $h * $S; $u = $S * $h / 110;
        $im = self::canvas($W, $H);
        $line = self::rgb($im, $hex, 40);
        $seed = 20261117; $cx = $W / 2; $cy = $H * 0.62; $pal = self::monet_pal($hex);
        foreach (array(-1, 1) as $s) {
            // rides : filets ondulés qui s'effilent vers le bord
            $x0 = $cx + $s * 340 * $u; $x1 = $cx + $s * ($W / 2 - 14 * $S);
            foreach (array(0, 13, -13) as $i => $dy) {
                $a = $x0 + $s * $i * 18 * $u; $px = $a; $py = $cy + $dy * $u;
                for ($k = 1; $k <= 20; $k++) {
                    $nx = $a + ($x1 - $a) * $k / 20; $ny = $cy + $dy * $u + sin($k * 0.9 + $i) * 2.2 * $u;
                    self::stroke($im, $px, $py, $nx, $ny, max(1, (5 - $i) * $S * (1 - $k / 20) + 0.8 * $S), max(1, (5 - $i) * $S * (1 - ($k + 1) / 20) + 0.8 * $S), $line);
                    $px = $nx; $py = $ny;
                }
            }
            // roseaux
            foreach (array(300, 318, 334) as $i => $d) {
                $bx = $cx + $s * $d * $u; $by = $cy + 6 * $u; $ang = -M_PI / 2 + $s * (0.18 + $i * 0.12);
                $len = (70 - $i * 14) * $u;
                self::stroke($im, $bx, $by, $bx + cos($ang) * $len, $by + sin($ang) * $len, 3.2 * $u, 1.4 * $u, self::col($im, $pal['stem'][0], $pal['stem'][1], $pal['stem'][2], 24));
                self::leaf($im, $bx + cos($ang) * $len * 0.45, $by + sin($ang) * $len * 0.45, $ang - $s * 0.9, 38 * $u, self::col($im, $pal['leaf1'][0], $pal['leaf1'][1], $pal['leaf1'][2], 40), 0.18);
            }
            // galettes et coquelicots
            self::lily($im, $cx + $s * 215 * $u, $cy + 6 * $u, 40 * $u, $line, $seed, $pal);
            self::poppy($im, $cx + $s * 268 * $u, $cy - 20 * $u, 24 * $u, $s * 0.5, $seed, $pal);
            self::dab($im, $cx + $s * 122 * $u, $cy + 14 * $u, 36 * $u, 9 * $u, 0.05, self::col($im, $pal['pad'][0], $pal['pad'][1], $pal['pad'][2], 36));
            self::poppy($im, $cx + $s * 140 * $u, $cy - 8 * $u, 17 * $u, $s * 1.0, $seed, $pal);
        }
        self::lily($im, $cx, $cy + 10 * $u, 62 * $u, $line, $seed, $pal);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        self::free($im);
        return self::png($out);
    }

    /** Coin fleuri (haut gauche) : tiges qui longent le cadre, feuilles et coquelicots ; à retourner pour les trois autres coins. */
    private static function floral_corner($T, $S, $hex) {
        $tile = self::canvas($T * $S, $T * $S);
        $u = $T / 34 * $S; $seed = 77123; $pal = self::monet_pal($hex);   // dessin conçu sur 34 unités, réduit à la taille $T
        $stem = self::col($tile, $pal['stem'][0], $pal['stem'][1], $pal['stem'][2], 12); $leaf1 = self::col($tile, $pal['leaf1'][0], $pal['leaf1'][1], $pal['leaf1'][2], 40); $leaf2 = self::col($tile, $pal['leaf2'][0], $pal['leaf2'][1], $pal['leaf2'][2], 52);
        foreach (array(0, 1) as $dir) {   // 0 : le long du bord haut, 1 : le long du bord gauche
            $px = 4 * $u; $py = 4 * $u; $a = $dir ? 1.2 : 0.37;
            for ($k = 1; $k <= 11; $k++) {
                $a += (self::rnd($seed) - 0.5) * 0.14 + ($dir ? (M_PI / 2 - 0.2 - $a) * 0.03 : (0.12 - $a) * 0.04);
                $L = ($dir ? 1.9 : 2.1) * $u; $nx = $px + cos($a) * $L; $ny = $py + sin($a) * $L;
                self::stroke($tile, $px, $py, $nx, $ny, 0.8 * $S, 0.5 * $S, $stem);
                if ($k % 2 == 0) {
                    $side = ($k % 4 == 0) ? 1 : -1;
                    self::leaf($tile, $nx, $ny, $a + $side * 0.95, (5.5 - $k * 0.18) * $u, $k % 4 == 0 ? $leaf1 : $leaf2, 0.34);
                }
                $px = $nx; $py = $ny;
            }
            self::poppy($tile, $px + ($dir ? 0.4 : 2.0) * $u, $py + ($dir ? 2.0 : 0.4) * $u, 3.0 * $u, $dir, $seed, $pal);
        }
        foreach (array(array(8.5, 8.5, 5.0, 0.3), array(14.5, 4.4, 3.3, 1.1), array(4.4, 14.5, 3.3, 2.0)) as $f) {
            self::poppy($tile, $f[0] * $u, $f[1] * $u, $f[2] * $u, $f[3], $seed, $pal);
        }
        for ($k = 0; $k < 14; $k++) {   // petites touches de couleur éparses
            $x = (3 + self::rnd($seed) * 24) * $u; $y = (3 + self::rnd($seed) * 24) * $u;
            if ($x > 9 * $u && $y > 9 * $u) continue;
            self::dab($tile, $x, $y, 0.7 * $u, 0.5 * $u, self::rnd($seed) * 3, self::col($tile, $pal['touch'][0], $pal['touch'][1], $pal['touch'][2], 40));
        }
        $small = self::canvas($T, $T);
        imagealphablending($small, false);
        imagecopyresampled($small, $tile, 0, 0, 0, 0, $T, $T, $T * $S, $T * $S);
        imagealphablending($small, true);
        self::free($tile);
        return $small;
    }

    /**
     * Fleuron « feuillage » : un petit arbre au centre et, de chaque côté, une branche ondulée garnie de feuilles qui rapetissent
     * vers le bord (camaïeu de la couleur du livre).
     */
    public static function leafgarland($hex, $w = 1000, $h = 110) {
        $S = 3; $W = $w * $S; $H = $h * $S; $u = $S * $h / 110;
        $im = self::canvas($W, $H);
        $pal = self::monet_pal($hex); $seed = 20261118;
        $cx = $W / 2; $cy = $H * 0.56;
        $stem = self::col($im, $pal['stem'][0], $pal['stem'][1], $pal['stem'][2], 14);
        $tones = array(self::col($im, $pal['leaf1'][0], $pal['leaf1'][1], $pal['leaf1'][2], 34), self::col($im, $pal['leaf2'][0], $pal['leaf2'][1], $pal['leaf2'][2], 42), self::col($im, $pal['petal'][0][0], $pal['petal'][0][1], $pal['petal'][0][2], 38));
        foreach (array(-1, 1) as $s) {
            $x0 = $cx + $s * 120 * $u; $x1 = $cx + $s * ($W / 2 - 12 * $S); $n = 26;
            $px = $x0; $py = $cy + sin(0) * 9 * $u;
            for ($k = 1; $k <= $n; $k++) {
                $t = $k / $n; $nx = $x0 + ($x1 - $x0) * $t; $ny = $cy + sin($t * M_PI * 2.2) * 9 * $u * (1 - 0.4 * $t);
                self::stroke($im, $px, $py, $nx, $ny, 3.2 * $u * (1 - $t) + 0.8 * $S, 3.2 * $u * (1 - ($k + 1) / $n) + 0.8 * $S, $stem);
                if ($k % 2 == 0 && $k < $n) {
                    $ang = atan2($ny - $py, $nx - $px); $len = (30 - 20 * $t) * $u;
                    foreach (array(-1, 1) as $side) {
                        self::leaf($im, $nx, $ny, $ang + $side * (0.85 + self::rnd($seed) * 0.25), $len * (0.9 + 0.2 * self::rnd($seed)), $tones[($k / 2 + ($side > 0 ? 1 : 0)) % 3], 0.34);
                    }
                }
                $px = $nx; $py = $ny;
            }
            self::leaf($im, $px, $py, atan2($s * 0.0, $s), 14 * $u, $tones[1], 0.3);
        }
        // petit arbre central : tronc, deux branches, houppier de feuilles
        $tb = $cy + 38 * $u;
        self::stroke($im, $cx, $tb, $cx, $cy - 4 * $u, 7 * $u, 3.4 * $u, $stem);
        self::stroke($im, $cx, $cy + 10 * $u, $cx - 22 * $u, $cy - 14 * $u, 3 * $u, 1.4 * $u, $stem);
        self::stroke($im, $cx, $cy + 6 * $u, $cx + 22 * $u, $cy - 16 * $u, 3 * $u, 1.4 * $u, $stem);
        foreach (array(array(0, -22, 30), array(-24, -10, 22), array(24, -12, 22)) as $c) {
            for ($k = 0; $k < 16; $k++) {
                $a = $k / 16 * 2 * M_PI + self::rnd($seed) * 0.3; $d = $c[2] * $u * (0.35 + 0.65 * self::rnd($seed));
                $lx = $cx + $c[0] * $u + cos($a) * $d * 0.9; $ly = $cy + $c[1] * $u + sin($a) * $d * 0.7;
                self::leaf($im, $lx, $ly, $a + 0.4, (9 + 7 * self::rnd($seed)) * $u, $tones[$k % 3], 0.4);
            }
        }
        $ground = self::col($im, $pal['stem'][0], $pal['stem'][1], $pal['stem'][2], 40);
        self::stroke($im, $cx - 30 * $u, $tb + 1 * $u, $cx + 30 * $u, $tb + 1 * $u, 1.6 * $u, 0.6 * $u, $ground);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        self::free($im);
        return self::png($out);
    }

    /** Coin « feuillage » (haut gauche) : deux brindilles feuillues qui longent le cadre et un bouquet de feuilles dans l'angle. */
    private static function leaf_corner($T, $S, $hex) {
        $tile = self::canvas($T * $S, $T * $S);
        $u = $T / 34 * $S; $seed = 9157; $pal = self::monet_pal($hex);
        $stem = self::col($tile, $pal['stem'][0], $pal['stem'][1], $pal['stem'][2], 10);
        $tones = array(self::col($tile, $pal['leaf1'][0], $pal['leaf1'][1], $pal['leaf1'][2], 22), self::col($tile, $pal['leaf2'][0], $pal['leaf2'][1], $pal['leaf2'][2], 34), self::col($tile, $pal['petal'][0][0], $pal['petal'][0][1], $pal['petal'][0][2], 28));
        foreach (array(0, 1) as $dir) {
            $px = 5 * $u; $py = 5 * $u; $a = $dir ? 1.22 : 0.35;
            for ($k = 1; $k <= 9; $k++) {
                $a += ($dir ? (M_PI / 2 - 0.15 - $a) : (0.1 - $a)) * 0.05 + (self::rnd($seed) - 0.5) * 0.12;
                $nx = $px + cos($a) * 2.9 * $u; $ny = $py + sin($a) * 2.9 * $u;
                self::stroke($tile, $px, $py, $nx, $ny, 0.9 * $S, 0.5 * $S, $stem);
                $len = (8 - $k * 0.55) * $u;
                foreach (array(-1, 1) as $side) {
                    if (($k + ($side > 0 ? 1 : 0)) % 2) self::leaf($tile, $nx, $ny, $a + $side * (0.8 + self::rnd($seed) * 0.25), $len, $tones[($k + $dir) % 3], 0.36);
                }
                $px = $nx; $py = $ny;
            }
            self::leaf($tile, $px, $py, $a, 5 * $u, $tones[1], 0.34);
        }
        foreach (array(0.45, 0.78, 1.12) as $i => $a) {   // petit bouquet dans l'angle
            self::leaf($tile, 4 * $u, 4 * $u, $a, (9.5 - ($i % 2) * 2.5) * $u, $tones[$i % 3], 0.34);
        }
        $small = self::canvas($T, $T);
        imagealphablending($small, false);
        imagecopyresampled($small, $tile, 0, 0, 0, 0, $T, $T, $T * $S, $T * $S);
        imagealphablending($small, true);
        self::free($tile);
        return $small;
    }

    /** Fleuron séparateur : filets qui s'effilent, losanges et points autour d'un motif central. */
    public static function flourish($hex, $w = 1000, $h = 70) {
        $S = 3;
        $W = $w * $S; $H = $h * $S;
        $im = self::canvas($W, $H);
        $col = self::rgb($im, $hex);
        $clear = imagecolorallocatealpha($im, 255, 255, 255, 127);
        $cx = $W / 2; $cy = $H / 2;
        $dia = function ($x, $y, $rw, $rh, $c) use ($im) {
            self::poly($im, [(int) ($x - $rw), (int) $y, (int) $x, (int) ($y - $rh), (int) ($x + $rw), (int) $y, (int) $x, (int) ($y + $rh)], $c);
        };
        foreach ([-1, 1] as $s) {
            // filet effilé du centre vers le bord
            $x0 = $cx + $s * 134 * $S; $x1 = $cx + $s * ($W / 2 - 16 * $S);
            self::stroke($im, $x0, $cy, $x1, $cy, 7 * $S, 1 * $S, $col);
            $dia($x1 + $s * 6 * $S, $cy, 6 * $S, 6 * $S, $col);
            // petits points et losange creux
            foreach ([48, 62] as $d) imagefilledellipse($im, (int) ($cx + $s * $d * $S), (int) $cy, 6 * $S, 6 * $S, $col);
            $dia($cx + $s * 105 * $S, $cy, 26 * $S, 14 * $S, $col);
            imagealphablending($im, false);
            $dia($cx + $s * 105 * $S, $cy, 20 * $S, 9 * $S, $clear);
            imagealphablending($im, true);
            $dia($cx + $s * 105 * $S, $cy, 6 * $S, 4 * $S, $col);
            // volute : deux arcs en S de part et d'autre du motif central
            imagesetthickness($im, (int) (3 * $S));
            $a = $s > 0 ? [180, 360] : [180, 360];
            imagearc($im, (int) ($cx + $s * 36 * $S), (int) ($cy - 11 * $S), (int) (24 * $S), (int) (22 * $S), 0, 270, $col);
            imagearc($im, (int) ($cx + $s * 36 * $S), (int) ($cy + 11 * $S), (int) (24 * $S), (int) (22 * $S), 90, 360, $col);
            imagesetthickness($im, 1);
        }
        $dia($cx, $cy, 17 * $S, 28 * $S, $col);
        imagealphablending($im, false);
        $dia($cx, $cy, 7 * $S, 14 * $S, $clear);
        imagealphablending($im, true);
        $dia($cx, $cy, 2.5 * $S, 5 * $S, $col);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        self::free($im);
        return self::png($out);
    }

    /**
     * Cadre de page : PNG transparent. $style : single | double | ornate. $box = false : page A4 entière (filet à 12 mm du bord) ;
     * $box = true : seulement le rectangle du cadre (pour la page imprimable).
     */
    public static function frame($style, $hex, $box = false) {
        $dpi = 200;
        $mm = function ($v) use ($dpi) { return (int) round($v / 25.4 * $dpi); };
        $pw = $box ? 186 : 210; $ph = $box ? 273 : 297;
        $w = $mm($pw); $h = $mm($ph);
        $im = self::canvas($w, $h);
        $col = self::rgb($im, $hex);
        $o = $box ? 0 : $mm(12);
        $x0 = $o; $y0 = $o; $x1 = $w - 1 - $o; $y1 = $h - 1 - $o;
        $rect = function ($off, $th, $cut) use ($im, $col, $x0, $y0, $x1, $y1) {
            for ($k = 0; $k < $th; $k++) {
                $a = $off + $k;
                imageline($im, $x0 + $a + $cut, $y0 + $a, $x1 - $a - $cut, $y0 + $a, $col);
                imageline($im, $x0 + $a + $cut, $y1 - $a, $x1 - $a - $cut, $y1 - $a, $col);
                imageline($im, $x0 + $a, $y0 + $a + $cut, $x0 + $a, $y1 - $a - $cut, $col);
                imageline($im, $x1 - $a, $y0 + $a + $cut, $x1 - $a, $y1 - $a - $cut, $col);
            }
        };
        if ($style === 'floral' || $style === 'leaves') {   // cadre fleuri / feuillage : filet fin et petits coins
            $rect(0, 3, 0);
            $T = $mm($style === 'leaves' ? 9 : 6);
            $small = $style === 'leaves' ? self::leaf_corner($T, 5, $hex) : self::floral_corner($T, 5, $hex);
            $put = function ($t, $dx, $dy) use ($im, $T) { imagecopy($im, $t, $dx, $dy, 0, 0, $T, $T); };
            $put($small, $x0, $y0);
            $f = imagecreatetruecolor($T, $T); imagealphablending($f, false); imagesavealpha($f, true);
            imagecopy($f, $small, 0, 0, 0, 0, $T, $T); imageflip($f, IMG_FLIP_HORIZONTAL);
            $put($f, $x1 - $T + 1, $y0);
            imageflip($f, IMG_FLIP_VERTICAL);
            $put($f, $x1 - $T + 1, $y1 - $T + 1);
            imageflip($f, IMG_FLIP_HORIZONTAL);
            $put($f, $x0, $y1 - $T + 1);
            self::free($f); self::free($small);
            return self::png($im);
        }
        if ($style !== 'ornate') {
            $rect(0, 5, 0);
            if ($style === 'double') $rect($mm(2.2), 2, 0);
            return self::png($im);
        }
        // cadre orné : double filet dont les angles sont des quarts de cercle creusés, avec un point à chaque angle
        $r1 = $mm(7); $off2 = $mm(2.4); $r2 = $r1 - $off2;
        $rect(0, 5, $r1); $rect($off2, 2, $r1);
        $T = $mm(14); $S = 5;
        $tile = self::canvas($T * $S, $T * $S);
        $tc = self::rgb($tile, $hex);
        $c0 = 0;
        // coin haut-gauche dans la tuile : le coin du cadre est à (0,0)
        imagesetthickness($tile, (int) (5 * $S));
        imagearc($tile, $c0, $c0, 2 * $r1 * $S, 2 * $r1 * $S, 0, 90, $tc);
        imagesetthickness($tile, (int) (2 * $S));
        imagearc($tile, (int) ($off2 * $S), (int) ($off2 * $S), 2 * $r2 * $S, 2 * $r2 * $S, 0, 90, $tc);
        imagesetthickness($tile, 1);
        imagefilledellipse($tile, (int) ($off2 * $S * 0.5), (int) ($off2 * $S * 0.5), (int) ($mm(2.2) * $S), (int) ($mm(2.2) * $S), $tc);
        $small = self::canvas($T, $T);
        imagealphablending($small, false);
        imagecopyresampled($small, $tile, 0, 0, 0, 0, $T, $T, $T * $S, $T * $S);
        imagealphablending($small, true);
        self::free($tile);
        $put = function ($t, $dx, $dy) use ($im, $T) { imagecopy($im, $t, $dx, $dy, 0, 0, $T, $T); };
        $put($small, $x0, $y0);
        $f = imagecreatetruecolor($T, $T); imagealphablending($f, false); imagesavealpha($f, true);
        imagecopy($f, $small, 0, 0, 0, 0, $T, $T); imageflip($f, IMG_FLIP_HORIZONTAL);
        $put($f, $x1 - $T + 1, $y0);
        imageflip($f, IMG_FLIP_VERTICAL);
        $put($f, $x1 - $T + 1, $y1 - $T + 1);
        imageflip($f, IMG_FLIP_HORIZONTAL);
        $put($f, $x0, $y1 - $T + 1);
        self::free($f); self::free($small);
        return self::png($im);
    }
}

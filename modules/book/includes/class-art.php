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
        imagedestroy($im);
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
        imagefilledpolygon($im, [
            (int) round($x1 + $nx * $w1 / 2), (int) round($y1 + $ny * $w1 / 2),
            (int) round($x2 + $nx * $w2 / 2), (int) round($y2 + $ny * $w2 / 2),
            (int) round($x2 - $nx * $w2 / 2), (int) round($y2 - $ny * $w2 / 2),
            (int) round($x1 - $nx * $w1 / 2), (int) round($y1 - $ny * $w1 / 2),
        ], 4, $col);
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
        imagefilledpolygon($im, $pts, 8, $col);
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
        imagefilledpolygon($im, $pts, count($pts) / 2, $col);
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
        imagedestroy($small);
        imagealphablending($im, true);
        imagecopy($im, $big, 0, 0, 0, 0, $W, $H);
        imagedestroy($big);
    }

    /** Valeurs du réglage « illustration de couverture » : none, ornament, puis un arbre (tree = chêne, olive, willow, roots). */
    public static function cover_choices() {
        return array('none', 'ornament', 'tree', 'olive', 'willow', 'roots', 'color', 'page', 'meadow', 'farm', 'chene', 'peinture', 'chene2', 'chene3', 'chene4');
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
            imagedestroy($tmp);
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
        imagedestroy($im);
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
        $fill = function ($off, $col) use ($im, $top, $bot, $poly) { $p = $poly($top, $bot, $off); imagefilledpolygon($im, $p, count($p) / 2, $col); };
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
        imagefilledpolygon($tmp, $up, count($up) / 2, self::col($tmp, 0, 0, 0, 127));
        imagealphablending($tmp, true);
        imagecopy($im, $tmp, 0, 0, 0, 0, $W, $H);
        imagedestroy($tmp);
        imagealphablending($im, false);
        $clear = self::col($im, 0, 0, 0, 127);
        $out = array();
        foreach ($bot as $p) { $out[] = (int) round($p[0]); $out[] = (int) round($p[1]); }
        array_push($out, (int) $W, (int) $H, 0, (int) $H);
        imagefilledpolygon($im, $out, count($out) / 2, $clear);
        imagefilledrectangle($im, 0, (int) $y0, (int) ($cx - $hw), $H, $clear);
        imagefilledrectangle($im, (int) ($cx + $hw), (int) $y0, $W, $H, $clear);
        imagealphablending($im, true);
        // herbe (bande suivant la crête) et brins
        $g1 = self::col($im, 112, 160, 60); $g2 = self::col($im, 82, 132, 48);
        for ($i = 0; $i < $N; $i++) {
            imagefilledpolygon($im, array((int) $top[$i][0], (int) $top[$i][1], (int) $top[$i + 1][0], (int) $top[$i + 1][1], (int) $top[$i + 1][0], (int) ($top[$i + 1][1] + 18 * $S), (int) $top[$i][0], (int) ($top[$i][1] + 18 * $S)), 4, $g1);
            imagefilledpolygon($im, array((int) $top[$i][0], (int) ($top[$i][1] + 14 * $S), (int) $top[$i + 1][0], (int) ($top[$i + 1][1] + 14 * $S), (int) $top[$i + 1][0], (int) ($top[$i + 1][1] + 24 * $S), (int) $top[$i][0], (int) ($top[$i][1] + 24 * $S)), 4, $g2);
        }
        for ($k = 0; $k < 70; $k++) {
            $t = (self::rnd($seed) - 0.5) * 1.96; $x = $cx + $t * $hw; $y = self::mound($t, $y0, $H) + 2 * $S;
            $hgt = (8 + self::rnd($seed) * 12) * $S; $lean = (self::rnd($seed) - 0.5) * 8 * $S;
            imagefilledpolygon($im, array((int) ($x - 2 * $S), (int) $y, (int) ($x + 2 * $S), (int) $y, (int) ($x + $lean), (int) ($y - $hgt)), 3, $g1);
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
        imagedestroy($im);
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
        imagedestroy($im);
        ob_start(); imagejpeg($out, null, 88); $bin = (string) ob_get_clean();
        imagedestroy($out);
        return $bin;
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
            imagefilledpolygon($im, [(int) ($x - $rw), (int) $y, (int) $x, (int) ($y - $rh), (int) ($x + $rw), (int) $y, (int) $x, (int) ($y + $rh)], 4, $c);
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
        imagedestroy($im);
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
        imagedestroy($tile);
        $put = function ($t, $dx, $dy) use ($im, $T) { imagecopy($im, $t, $dx, $dy, 0, 0, $T, $T); };
        $put($small, $x0, $y0);
        $f = imagecreatetruecolor($T, $T); imagealphablending($f, false); imagesavealpha($f, true);
        imagecopy($f, $small, 0, 0, 0, 0, $T, $T); imageflip($f, IMG_FLIP_HORIZONTAL);
        $put($f, $x1 - $T + 1, $y0);
        imageflip($f, IMG_FLIP_VERTICAL);
        $put($f, $x1 - $T + 1, $y1 - $T + 1);
        imageflip($f, IMG_FLIP_HORIZONTAL);
        $put($f, $x0, $y1 - $T + 1);
        imagedestroy($f); imagedestroy($small);
        return self::png($im);
    }
}

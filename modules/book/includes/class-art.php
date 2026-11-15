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

    /** Feuille effilée, $len de long. */
    private static function leaf($im, $x, $y, $ang, $len, $col) {
        $wd = $len * 0.34;
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
        $dx = ($x - $c['cx']) / $c['rx']; $dy = ($y - $c['cy']) / $c['ry'];
        $out = $dx * $dx + $dy * $dy;
        if ($out > 1.0 && self::rnd($seed) < min(0.95, 0.5 + ($out - 1.0))) return;
        $t = self::rnd($seed);
        $col = $t < 0.4 ? $c['l1'] : ($t < 0.78 ? $c['l2'] : $c['l3']);
        self::leaf($im, $x, $y, $ang, (10 + self::rnd($seed) * 9) * $c['S'], $col);
    }

    /**
     * Branche courbe qui s'effile et se ramifie ; les rameaux fins portent des feuilles
     * (trois teintes semi-transparentes qui se superposent, comme une aquarelle).
     */
    private static function limb($im, $x, $y, $ang, $len, $th, $depth, &$seed, $c) {
        $S = $c['S'];
        $segs = 5;
        $bend = (self::rnd($seed) - 0.5) * 0.55;
        $thEnd = max(1.1 * $S, $th * 0.66);
        $px = $x; $py = $y; $a = $ang;
        $pts = array();
        for ($k = 1; $k <= $segs; $k++) {
            $a += $bend / $segs + (self::rnd($seed) - 0.5) * 0.16;
            if ($depth >= 4) $a += 0.025;
            $nx = $px + cos($a) * $len / $segs; $ny = $py + sin($a) * $len / $segs;
            $w1 = $th + ($thEnd - $th) * (($k - 1) / $segs); $w2 = $th + ($thEnd - $th) * ($k / $segs);
            self::stroke($im, $px, $py, $nx, $ny, $w1, $w2, $c['wood']);
            imagefilledellipse($im, (int) round($nx), (int) round($ny), (int) max(1, round($w2)), (int) max(1, round($w2)), $c['wood']);
            $pts[] = array($nx, $ny, $a);
            $px = $nx; $py = $ny;
        }
        if ($depth >= 3) {
            foreach ($pts as $p) {
                if (self::rnd($seed) < 0.42) {
                    $side = self::rnd($seed) > .5 ? 1 : -1;
                    self::drop($im, $p[0], $p[1], $p[2] + $side * (0.5 + self::rnd($seed) * 0.7), $depth, $seed, $c);
                }
            }
        }
        if ($depth >= $c['max']) {
            for ($k = 0; $k < 2; $k++) self::drop($im, $px, $py, $a + (self::rnd($seed) - 0.5) * 1.8, $depth, $seed, $c);
            return;
        }
        $n = ($depth < 2 || self::rnd($seed) > 0.7) ? 3 : 2;
        for ($i = 0; $i < $n; $i++) {
            $na = $a + ($i - ($n - 1) / 2) * (0.55 + self::rnd($seed) * 0.25) + (self::rnd($seed) - 0.5) * 0.25;
            $na += (-M_PI / 2 - $na) * ($depth < 3 ? 0.12 : 0.04);
            $na += ($px < $c['cx'] ? -1 : 1) * ($depth < 4 ? 0.06 : 0.0);
            self::limb($im, $px, $py, $na, $len * (0.7 + self::rnd($seed) * 0.1), $thEnd, $depth + 1, $seed, $c);
        }
        if ($depth >= 1 && $depth < $c['max'] - 1 && self::rnd($seed) > 0.45) {   // rameau latéral à mi-longueur
            $side = self::rnd($seed) > .5 ? 1 : -1;
            self::limb($im, $pts[2][0], $pts[2][1], $pts[2][2] + $side * (0.7 + self::rnd($seed) * 0.4), $len * 0.55, $thEnd * 0.8, $depth + 2, $seed, $c);
        }
    }

    /** Racine : courbe épaisse à la base qui s'effile en rampant sur le sol. */
    private static function root($im, $x, $y, $ang, $len, $th, &$seed, $col, $S) {
        $segs = 9; $a = $ang; $px = $x; $py = $y;
        $bend = (self::rnd($seed) - 0.5) * 0.7;
        for ($k = 1; $k <= $segs; $k++) {
            $a += $bend / $segs + (self::rnd($seed) - 0.5) * 0.1;
            $nx = $px + cos($a) * $len / $segs; $ny = $py + sin($a) * $len / $segs * 0.5;
            $f1 = pow(1 - ($k - 1) / $segs, 0.85); $f2 = pow(1 - $k / $segs, 0.85);
            self::stroke($im, $px, $py, $nx, $ny, max(1.2 * $S, $th * $f1), max(1.2 * $S, $th * $f2), $col);
            imagefilledellipse($im, (int) round($nx), (int) round($ny), (int) max(1, round($th * $f2)), (int) max(1, round($th * $f2)), $col);
            $px = $nx; $py = $ny;
        }
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

    /** Arbre de vie à l'aquarelle (cime ample de feuilles, tronc, racines apparentes), PNG transparent de $w × $h pixels. */
    public static function tree($hex, $w = 900, $h = 760) {
        $S = 2;
        $W = $w * $S; $H = $h * $S;
        $im = self::canvas($W, $H);
        $seed = 20240611;
        $c = array(
            'S' => $S, 'max' => 7,
            'wood' => self::rgb($im, $hex, 0),
            'l1' => self::rgb($im, $hex, 108), 'l2' => self::rgb($im, $hex, 90), 'l3' => self::rgb($im, $hex, 64),
            'cx' => $W / 2, 'cy' => $H * 0.38, 'rx' => $W * 0.47, 'ry' => $H * 0.33,
        );
        $ground = $H * 0.88;
        // lavis de la cime et ombre au sol
        $blobs = array();
        for ($k = 0; $k < 38; $k++) {
            $t = self::rnd($seed) * 2 * M_PI; $r = sqrt(self::rnd($seed));
            $blobs[] = array($c['cx'] + cos($t) * $r * $c['rx'] * 0.7, $c['cy'] + sin($t) * $r * $c['ry'] * 0.7, (60 + self::rnd($seed) * 70) * $S, (50 + self::rnd($seed) * 55) * $S, 0.3 + self::rnd($seed) * 0.4);
        }
        for ($k = 0; $k < 5; $k++) {
            $blobs[] = array($c['cx'] + (self::rnd($seed) - 0.5) * 220 * $S, $ground + 8 * $S, (90 + self::rnd($seed) * 70) * $S, (10 + self::rnd($seed) * 8) * $S, 0.7);
        }
        self::wash($im, $W, $H, $hex, $blobs, 0.24);
        // racines apparentes de part et d'autre du tronc, puis évasement du pied
        foreach (array(-1, 1) as $side) {
            foreach (array(0.12, 0.42, 0.75) as $i => $o) {
                self::root($im, $c['cx'] + $side * (14 + $i * 5) * $S, $ground - 22 * $S + $i * 6 * $S, $side > 0 ? $o : M_PI - $o, (110 + self::rnd($seed) * 90 - $i * 25) * $S, (24 - $i * 4) * $S, $seed, $c['wood'], $S);
            }
        }
        $flare = array();
        foreach (array(-1, 1) as $side) {
            for ($k = 0; $k <= 12; $k++) {
                $t = $k / 12;                                  // 0 = sol, 1 = haut du pied
                $x = $c['cx'] + $side * (46 - 28 * pow($t, 0.55)) * $S;
                $y = $ground + 4 * $S - 84 * $S * $t;
                $flare[$side][] = array($x, $y);
            }
        }
        $poly = array();
        foreach ($flare[-1] as $p) { $poly[] = (int) round($p[0]); $poly[] = (int) round($p[1]); }
        foreach (array_reverse($flare[1]) as $p) { $poly[] = (int) round($p[0]); $poly[] = (int) round($p[1]); }
        imagefilledpolygon($im, $poly, count($poly) / 2, $c['wood']);
        // tronc et cime
        self::limb($im, $c['cx'], $ground - 40 * $S, -M_PI / 2 + 0.02, $H * 0.17, 34 * $S, 0, $seed, $c);
        // quelques feuilles qui tombent
        for ($k = 0; $k < 7; $k++) {
            $x = $c['cx'] + (self::rnd($seed) - 0.3) * 520 * $S; $y = $H * (0.6 + self::rnd($seed) * 0.26);
            self::leaf($im, $x, $y, self::rnd($seed) * 6.28, (9 + self::rnd($seed) * 6) * $S, $c['l2']);
        }
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        imagedestroy($im);
        return self::png($out);
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

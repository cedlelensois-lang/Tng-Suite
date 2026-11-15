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

    private static function leaf($im, $x, $y, $ang, $len, $col) {
        $wd = $len * 0.38;
        $ca = cos($ang); $sa = sin($ang);
        $pts = [];
        foreach ([[0, 0], [0.3, -1], [0.75, -0.8], [1, 0], [0.75, 0.8], [0.3, 1]] as $p) {
            $lx = $p[0] * $len; $ly = $p[1] * $wd;
            $pts[] = (int) round($x + $lx * $ca - $ly * $sa);
            $pts[] = (int) round($y + $lx * $sa + $ly * $ca);
        }
        imagefilledpolygon($im, $pts, 6, $col);
    }

    private static function branch($im, $x, $y, $ang, $len, $th, $depth, &$seed, $col, $leafCol, $S, $maxDepth, $up = true, $leaves = true) {
        $x2 = $x + cos($ang) * $len;
        $y2 = $y + sin($ang) * $len;
        $th2 = max(1.2 * $S, $th * 0.72);
        self::stroke($im, $x, $y, $x2, $y2, $th, $th2, $col);
        if ($depth >= $maxDepth) {
            if ($leaves) for ($k = 0; $k < 2; $k++) {
                self::leaf($im, $x2, $y2, $ang + (self::rnd($seed) - 0.5) * 2.2, (9 + self::rnd($seed) * 7) * $S, $leafCol);
            }
            return;
        }
        if ($leaves && $depth >= $maxDepth - 2) {   // petites feuilles le long des dernières branches
            self::leaf($im, ($x + $x2) / 2, ($y + $y2) / 2, $ang + (self::rnd($seed) > .5 ? 1 : -1) * (0.7 + self::rnd($seed) * 0.5), (8 + self::rnd($seed) * 6) * $S, $leafCol);
        }
        $n = ($depth < 2 || self::rnd($seed) > 0.78) ? 3 : 2;
        for ($i = 0; $i < $n; $i++) {
            $spread = ($i - ($n - 1) / 2) * (0.62 + self::rnd($seed) * 0.3) + (self::rnd($seed) - 0.5) * 0.25;
            $na = $ang + $spread;
            if ($up) $na += (-M_PI / 2 - $na) * 0.07;   // les branches se redressent : cime équilibrée
            self::branch($im, $x2, $y2, $na, $len * (0.72 + self::rnd($seed) * 0.08), $th2, $depth + 1, $seed, $col, $leafCol, $S, $maxDepth, $up, $leaves);
        }
    }

    /** Arbre de vie stylisé (cime ronde, racines), PNG transparent de $w × $h pixels. */
    public static function tree($hex, $w = 900, $h = 760) {
        $S = 2;
        $W = $w * $S; $H = $h * $S;
        $im = self::canvas($W, $H);
        $col = self::rgb($im, $hex);
        $leafCol = self::rgb($im, $hex, 30);
        $seed = 20240611;
        $cx = $W / 2; $ground = $H * 0.86;
        self::branch($im, $cx, $ground, -M_PI / 2, $H * 0.17, 26 * $S, 0, $seed, $col, $leafCol, $S, 8);
        // racines : quelques branches courtes vers le bas, sans feuilles
        for ($r = -2; $r <= 2; $r++) {
            $ang = M_PI / 2 + $r * 0.5 + (self::rnd($seed) - 0.5) * 0.2;
            self::branch($im, $cx + $r * 6 * $S, $ground - 4 * $S, $ang, $H * 0.055, 11 * $S, 5, $seed, $col, $leafCol, $S, 8, false, false);
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

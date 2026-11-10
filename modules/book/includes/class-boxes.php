<?php
/**
 * Arbres classiques du livre : cases reliées par des traits, racine à gauche, branches vers la droite.
 * Un nœud = ['name'=>, 'sub'=>, 'hl'=>bool, 'kids'=>[nœuds]].
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Boxes {
    const GX = 26, GY = 8, WMIN = 150, WMAX = 300;
    private static $H = 30;
    private static $W = 150;     // largeur des cases : s'adapte au nom le plus long (entre WMIN et WMAX)

    private static function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    /** Polices (Liberation Sans, licence SIL OFL) fournies avec le plugin : mesure du texte et dessin des arbres en image. */
    private static function font($style) {
        $f = TNGBOOK_DIR . 'assets/fonts/LiberationSans-' . $style . '.ttf';
        return file_exists($f) ? $f : null;
    }

    /** Largeur approximative (unités SVG = px) d'un texte ; $st : Regular, Bold ou Italic. */
    private static function textw($t, $px, $st) {
        $f = function_exists('imagettfbbox') ? self::font($st) : null;
        if ($f) {
            $b = @imagettfbbox($px * 0.75, 0, $f, $t);
            if ($b) return abs($b[2] - $b[0]);
        }
        return mb_strlen($t) * $px * ($st === 'Bold' ? 0.62 : 0.56);
    }

    /** Raccourcit un texte (…) pour qu'il tienne dans $max unités. */
    private static function fit($t, $px, $st, $max) {
        if (self::textw($t, $px, $st) <= $max) return $t;
        while (mb_strlen($t) > 1) {
            $t = rtrim(mb_substr($t, 0, -1));
            if (self::textw($t . '…', $px, $st) <= $max) return $t . '…';
        }
        return $t;
    }

    private static function spText($e) { return is_array($e) ? (string) ($e['t'] ?? '') : (string) $e; }
    private static function spMar($e) { return is_array($e) && !empty($e['m']); }

    /** Nombre maximal de lignes de conjoints dans une case de l'arbre (0 à 3). */
    private static function spLines(array $n) {
        $m = min(3, count((array) ($n['sp'] ?? [])));
        foreach ($n['kids'] ?? [] as $k) $m = max($m, self::spLines($k));
        return $m;
    }

    /** Largeur de case nécessaire au contenu le plus large de l'arbre. */
    private static function needW(array $n) {
        $w = 0;
        $un = !empty($n['union']);
        $w = max($w, 5 + 12 * ($un ? 1 : 0) + self::textw((string) $n['name'], 9.5, $un ? 'Italic' : 'Bold') + 6);
        if (!empty($n['sub'])) $w = max($w, 5 + self::textw((string) $n['sub'], 8.5, 'Regular') + 6);
        foreach (array_slice(array_values((array) ($n['sp'] ?? [])), 0, 3) as $e) $w = max($w, 17 + self::textw(self::spText($e), 8.5, 'Italic') + 6);
        foreach ($n['kids'] ?? [] as $k) $w = max($w, self::needW($k));
        return $w;
    }

    /** Place les nœuds : x selon la profondeur, y selon l'ordre des feuilles, parent centré sur ses enfants. */
    private static function place(array &$n, $depth, &$leaf, &$boxes, &$lines, &$maxD) {
        $maxD = max($maxD, $depth);
        $W = self::$W;
        $x = $depth * ($W + self::GX);
        if (empty($n['kids'])) {
            $y = $leaf * (self::$H + self::GY); $leaf++;
        } else {
            $ys = [];
            foreach ($n['kids'] as &$k) { $ys[] = self::place($k, $depth + 1, $leaf, $boxes, $lines, $maxD); }
            unset($k);
            $y = ($ys[0] + end($ys)) / 2;
            $xm = $x + $W + self::GX / 2;
            $lines[] = [$x + $W, $y + self::$H / 2, $xm, $y + self::$H / 2];
            foreach ($ys as $ky) {
                $lines[] = [$xm, $y + self::$H / 2, $xm, $ky + self::$H / 2];
                $lines[] = [$xm, $ky + self::$H / 2, $x + $W + self::GX, $ky + self::$H / 2];
            }
        }
        $boxes[] = [$x, $y, $n];
        return $y;
    }

    /** Éclaircit une couleur #rrggbb (t = 0 inchangée, 1 blanc). */
    private static function tint($hex, $t) {
        $h = ltrim($hex, '#');
        $c = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        return sprintf('#%02x%02x%02x', ...array_map(function ($v) use ($t) { return (int) round($v * (1 - $t) + 255 * $t); }, $c));
    }

    /**
     * Scène : dimensions, traits, cases et textes (communs au dessin SVG et à l'image PNG).
     * Textes : [x, y de la ligne de base, taille, style, couleur, texte, marqueur] ; marqueur '' | 'm' (mariage : deux anneaux) | 'u' (union sans mariage : croix).
     */
    private static function scene(array $root, array $colors) {
        $colors += ['tree' => '#8a6a6a', 'treehl' => '#7a2e2e', 'name' => '#222222', 'meta' => '#555555'];
        self::$W = (int) max(self::WMIN, min(self::WMAX, ceil(self::needW($root))));
        $W = self::$W;
        $leaf = 0; $boxes = []; $lines = []; $maxD = 0;
        self::$H = 30 + 11 * self::spLines($root);   // une ligne de plus par conjoint indiqué dans une case
        self::place($root, 0, $leaf, $boxes, $lines, $maxD);
        $w = ($maxD + 1) * $W + $maxD * self::GX + 4;
        $h = max(1, $leaf) * (self::$H + self::GY) - self::GY + 4;
        $items = [];
        foreach ($boxes as $b) {
            list($x, $y, $n) = $b;
            $un = !empty($n['union']);
            $mk = $un ? (!empty($n['m']) ? 'm' : 'u') : '';
            $off = $mk !== '' ? 12 : 0;
            $items[] = [$x, $y, $n, [
                [$x + 5 + $off, $y + 12, 9.5, $un ? 'Italic' : 'Bold', $colors['name'], self::fit((string) $n['name'], 9.5, $un ? 'Italic' : 'Bold', $W - 11 - $off), $mk ? [$x + 5, $y + 12, $mk] : null],
                [$x + 5, $y + 24, 8.5, 'Regular', $colors['meta'], self::fit((string) ($n['sub'] ?? ''), 8.5, 'Regular', $W - 11), null],
            ]];
            $sp = array_values((array) ($n['sp'] ?? []));
            foreach (array_slice($sp, 0, 3) as $i => $e) {
                $more = $i === 2 && count($sp) > 3 ? ' …' : '';
                $yy = $y + 35 + 11 * $i;
                $items[count($items) - 1][3][] = [$x + 17, $yy, 8.5, 'Italic', $colors['meta'], self::fit(self::spText($e) . $more, 8.5, 'Italic', $W - 23), [$x + 5, $yy, self::spMar($e) ? 'm' : 'u']];
            }
        }
        return [$w, $h, $lines, $items, $colors];
    }

    /** Marqueur vectoriel (indépendant des polices) : deux anneaux entrelacés = mariage, croix = union sans mariage. */
    private static function svgMark($m, $x, $y, $col) {
        if ($m === 'm') {
            return '<circle cx="' . ($x + 3) . '" cy="' . ($y - 3) . '" r="2.6" fill="none" stroke="' . $col . '" stroke-width="0.9"/><circle cx="' . ($x + 6.4) . '" cy="' . ($y - 3) . '" r="2.6" fill="none" stroke="' . $col . '" stroke-width="0.9"/>';
        }
        return '<path d="M' . ($x + 1.5) . ' ' . ($y - 5.5) . 'L' . ($x + 6.5) . ' ' . ($y - 0.5) . 'M' . ($x + 6.5) . ' ' . ($y - 5.5) . 'L' . ($x + 1.5) . ' ' . ($y - 0.5) . '" stroke="' . $col . '" stroke-width="1" fill="none"/>';
    }

    /** Arbre en image PNG (traits + cases + textes) pour les exports Word / OpenDocument, qui n'affichent pas le SVG. '' si GD / FreeType indisponible. */
    private static function png(array $root, $alt, array $colors, $maxWidthCm) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) return '';
        $fonts = ['Regular' => self::font('Regular'), 'Bold' => self::font('Bold'), 'Italic' => self::font('Italic')];
        if (!$fonts['Regular'] || !$fonts['Bold'] || !$fonts['Italic']) return '';
        list($w, $h, $lines, $items, $colors) = self::scene($root, $colors);
        $S = 3;
        while ($S > 1 && $w * $S * $h * $S > 9000000) $S--;
        if ($w * $S * $h * $S > 40000000) return '';
        $im = imagecreatetruecolor((int) ($w * $S), (int) ($h * $S));
        if (!$im) return '';
        $rgb = function ($hex) use ($im) {
            $x = ltrim((string) $hex, '#');
            if (strlen($x) !== 6) $x = '000000';
            return imagecolorallocate($im, hexdec(substr($x, 0, 2)), hexdec(substr($x, 2, 2)), hexdec(substr($x, 4, 2)));
        };
        imagefill($im, 0, 0, $rgb('#ffffff'));
        imageantialias($im, true);
        $px = function ($v) use ($S) { return (int) round(($v + 2) * $S); };
        $lc = $rgb($colors['tree']);
        imagesetthickness($im, max(1, (int) round($S * 0.8)));
        foreach ($lines as $l) imageline($im, $px($l[0]), $px($l[1]), $px($l[2]), $px($l[3]), $lc);
        foreach ($items as $it) {
            list($x, $y, $n, $txts) = $it;
            $hl = !empty($n['hl']); $un = !empty($n['union']);
            $edge = $rgb($hl ? $colors['treehl'] : $colors['tree']);
            imagefilledrectangle($im, $px($x), $px($y), $px($x + self::$W), $px($y + self::$H), $rgb($hl ? self::tint($colors['treehl'], 0.88) : '#ffffff'));
            if ($un) {
                imagesetstyle($im, array_merge(array_fill(0, 3 * $S, $edge), array_fill(0, 2 * $S, IMG_COLOR_TRANSPARENT)));
                $ec = IMG_COLOR_STYLED;
            } else $ec = $edge;
            $x1 = $px($x); $y1 = $px($y); $x2 = $px($x + self::$W); $y2 = $px($y + self::$H);
            imageline($im, $x1, $y1, $x2, $y1, $ec); imageline($im, $x2, $y1, $x2, $y2, $ec); imageline($im, $x2, $y2, $x1, $y2, $ec); imageline($im, $x1, $y2, $x1, $y1, $ec);
            foreach ($txts as $t) {
                list($tx, $ty, $size, $st, $col, $str, $mk) = $t;
                if ($str !== '') imagettftext($im, $size * $S * 0.75, 0, $px($tx), $px($ty), $rgb($col), $fonts[$st], $str);
                if ($mk) {
                    $mc = $rgb($col); list($mx, $my, $kind) = $mk;
                    imagesetthickness($im, max(1, (int) round($S * 0.9)));
                    if ($kind === 'm') {
                        imageellipse($im, $px($mx + 3), $px($my - 3), (int) (5.2 * $S), (int) (5.2 * $S), $mc);
                        imageellipse($im, $px($mx + 6.4), $px($my - 3), (int) (5.2 * $S), (int) (5.2 * $S), $mc);
                    } else {
                        imageline($im, $px($mx + 1.5), $px($my - 5.5), $px($mx + 6.5), $px($my - 0.5), $mc);
                        imageline($im, $px($mx + 6.5), $px($my - 5.5), $px($mx + 1.5), $px($my - 0.5), $mc);
                    }
                }
            }
        }
        ob_start(); imagepng($im, null, 6); $bin = ob_get_clean();
        if (PHP_VERSION_ID < 80000) imagedestroy($im);
        if (!$bin) return '';
        $cm = min($maxWidthCm, $w / 40); $hcm = $cm * $h / $w;
        if ($hcm > 23) { $cm = 23 * $w / $h; }
        return '<img class="tpng" data-cm="' . round($cm, 2) . '" src="data:image/png;base64,' . base64_encode($bin) . '" alt="' . self::e($alt) . '">';
    }

    public static function chart(array $root, $mode, $alt, array $colors = [], $maxWidthCm = 16) {
        if ($mode === 'doc') return self::png($root, $alt, $colors, $maxWidthCm);
        list($w, $h, $lines, $items, $colors) = self::scene($root, $colors);
        $s = '';
        foreach ($lines as $l) $s .= '<line x1="' . $l[0] . '" y1="' . $l[1] . '" x2="' . $l[2] . '" y2="' . $l[3] . '" stroke="' . $colors['tree'] . '" stroke-width="1"/>';
        foreach ($items as $it) {
            list($x, $y, $n, $txts) = $it;
            $hl = !empty($n['hl']);
            $s .= '<rect x="' . $x . '" y="' . $y . '" width="' . self::$W . '" height="' . self::$H . '" rx="3"' . (!empty($n['union']) ? ' stroke-dasharray="3 2"' : '') . ' fill="' . ($hl ? self::tint($colors['treehl'], 0.88) : '#ffffff') . '" stroke="' . ($hl ? $colors['treehl'] : self::tint($colors['tree'], 0.35)) . '" stroke-width="' . ($hl ? 1.4 : 1) . '"/>';
            foreach ($txts as $t) {
                list($tx, $ty, $size, $st, $col, $str, $mk) = $t;
                if ($str !== '') $s .= '<text x="' . $tx . '" y="' . $ty . '" font-family="Helvetica, Arial, sans-serif" font-size="' . $size . '"' . ($st === 'Bold' ? ' font-weight="bold"' : ($st === 'Italic' ? ' font-style="italic"' : '')) . ' fill="' . $col . '">' . self::e($str) . '</text>';
                if ($mk) $s .= self::svgMark($mk[2], $mk[0], $mk[1], $col);
            }
        }
        $cm = min($maxWidthCm, $w / 40); $hcm = $cm * $h / $w;
        $doc = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '">' . $s . '</svg>';
        if ($mode === 'pdf') {
            return '<img src="data:image/svg+xml;base64,' . base64_encode($doc) . '" style="width:' . round($cm, 2) . 'cm;height:' . round($hcm, 2) . 'cm" alt="' . self::e($alt) . '">';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 ' . $w . ' ' . $h . '" style="width:' . round($cm, 2) . 'cm;max-width:100%;height:auto" role="img" aria-label="' . self::e($alt) . '">' . $s . '</svg>';
    }
}

<?php
/** Graphiques SVG simples du livre (histogrammes, frise). Même habillage que les arbres : SVG en ligne, ou image base64 pour mPDF. */
if (!defined('ABSPATH')) exit;

class TNGBook_Charts {
    private static function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    private static function tint($hex, $t) {
        $h = ltrim($hex, '#');
        $c = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        return sprintf('#%02x%02x%02x', ...array_map(function ($v) use ($t) { return (int) round($v * (1 - $t) + 255 * $t); }, $c));
    }

    public static function wrap($inner, $w, $h, $mode, $alt, $cm = 16) {
        $hcm = $cm * $h / $w;
        $font = 'font-family="Helvetica, Arial, sans-serif"';
        if ($mode === 'pdf') {
            $doc = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '">' . $inner . '</svg>';
            return '<img src="data:image/svg+xml;base64,' . base64_encode($doc) . '" style="width:' . $cm . 'cm;height:' . round($hcm, 2) . 'cm" alt="' . self::e($alt) . '">';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" style="width:' . $cm . 'cm;max-width:100%;height:auto" role="img" aria-label="' . self::e($alt) . '" ' . $font . '>' . $inner . '</svg>';
    }

    /** Histogramme : $labels et $vals de même longueur. */
    public static function bars(array $labels, array $vals, array $c, $mode, $alt) {
        $W = 480; $H = 190; $x0 = 14; $y0 = 24; $yb = 160;
        $n = max(1, count($vals));
        $max = max(1, max($vals ?: [1]));
        $slot = ($W - 2 * $x0) / $n; $bw = $slot * 0.72;
        $s = '<line x1="' . $x0 . '" y1="' . $yb . '" x2="' . ($W - $x0) . '" y2="' . $yb . '" stroke="' . $c['tree'] . '" stroke-width="1"/>';
        foreach ($vals as $i => $v) {
            $h = ($yb - $y0) * $v / $max;
            $x = $x0 + $i * $slot + ($slot - $bw) / 2;
            if ($v > 0) $s .= '<rect x="' . round($x, 2) . '" y="' . round($yb - $h, 2) . '" width="' . round($bw, 2) . '" height="' . round($h, 2) . '" fill="' . $c['head'] . '" rx="1.5"/>';
            if ($v > 0) $s .= '<text x="' . round($x + $bw / 2, 2) . '" y="' . round($yb - $h - 3, 2) . '" font-size="8" text-anchor="middle" fill="' . $c['meta'] . '">' . self::e($v) . '</text>';
            $s .= '<text x="' . round($x + $bw / 2, 2) . '" y="' . ($yb + 12) . '" font-size="' . ($n > 16 ? 7 : 8.5) . '" text-anchor="middle" fill="' . $c['text'] . '">' . self::e($labels[$i] ?? '') . '</text>';
        }
        return self::wrap($s, $W, $H, $mode, $alt);
    }

    /** Courbes : $series = [['name'=>, 'vals'=>[valeur|null…], 'color'=>], …], une valeur par étiquette. */
    public static function lines(array $labels, array $series, array $c, $mode, $alt) {
        $W = 480; $H = 210; $x0 = 34; $x1 = 466; $y0 = 22; $yb = 168;
        $all = [];
        foreach ($series as $sr) foreach ($sr['vals'] as $v) if ($v !== null) $all[] = $v;
        if (!$all) return '';
        $lo = max(0, (int) floor(min($all) / 10) * 10 - 10); $hi = (int) ceil(max($all) / 10) * 10 + 10;
        $n = max(2, count($labels));
        $X = function ($i) use ($x0, $x1, $n) { return $x0 + ($x1 - $x0) * $i / ($n - 1); };
        $Y = function ($v) use ($y0, $yb, $lo, $hi) { return $yb - ($yb - $y0) * ($v - $lo) / max(1, $hi - $lo); };
        $s = '';
        for ($g = $lo; $g <= $hi; $g += 10) {
            $s .= '<line x1="' . $x0 . '" y1="' . round($Y($g), 1) . '" x2="' . $x1 . '" y2="' . round($Y($g), 1) . '" stroke="' . self::tint($c['tree'], 0.6) . '" stroke-width="0.6"/>'
                . '<text x="' . ($x0 - 4) . '" y="' . round($Y($g) + 3, 1) . '" font-size="8" text-anchor="end" fill="' . $c['meta'] . '">' . $g . '</text>';
        }
        foreach ($labels as $i => $l) $s .= '<text x="' . round($X($i), 1) . '" y="' . ($yb + 13) . '" font-size="8" text-anchor="middle" fill="' . $c['text'] . '">' . self::e($l) . '</text>';
        foreach ($series as $k => $sr) {
            $pts = [];
            foreach ($sr['vals'] as $i => $v) if ($v !== null) $pts[] = [round($X($i), 1), round($Y($v), 1), $v];
            if (count($pts) > 1) $s .= '<polyline fill="none" stroke="' . $sr['color'] . '" stroke-width="1.6" points="' . implode(' ', array_map(function ($p) { return $p[0] . ',' . $p[1]; }, $pts)) . '"/>';
            foreach ($pts as $p) $s .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="2.4" fill="' . $sr['color'] . '"/>'
                . '<text x="' . $p[0] . '" y="' . ($p[1] - 5 - ($k % 3) * 0) . '" font-size="7" text-anchor="middle" fill="' . $sr['color'] . '">' . self::e(rtrim(rtrim(number_format($p[2], 1, '.', ''), '0'), '.')) . '</text>';
        }
        $lx = $x0;
        foreach ($series as $sr) {
            $s .= '<rect x="' . $lx . '" y="190" width="9" height="4" fill="' . $sr['color'] . '"/><text x="' . ($lx + 13) . '" y="195" font-size="8.5" fill="' . $c['text'] . '">' . self::e($sr['name']) . '</text>';
            $lx += 20 + 5.2 * mb_strlen($sr['name']);
        }
        return self::wrap($s, $W, $H, $mode, $alt);
    }

    /** Pyramide : hommes à gauche, femmes à droite ; la tranche la plus jeune est en bas. */
    public static function pyramid(array $labels, array $men, array $women, array $c, $mode, $alt, $mLbl, $fLbl) {
        $W = 480; $rh = 14; $top = 22; $mid = 240; $half = 175; $gap = 17;   // $gap : colonne centrale des âges
        $n = count($labels);
        $max = max(1, max(array_merge($men, $women, [1])));
        $H = $top + $n * $rh + 22;
        $s = '<text x="' . ($mid - $gap) . '" y="12" font-size="9" text-anchor="end" fill="#1d4ed8">' . self::e($mLbl) . '</text>'
           . '<text x="' . ($mid + $gap) . '" y="12" font-size="9" text-anchor="start" fill="#c2417a">' . self::e($fLbl) . '</text>';
        for ($i = 0; $i < $n; $i++) {
            $y = $top + ($n - 1 - $i) * $rh;
            $wm = $half * $men[$i] / $max; $wf = $half * $women[$i] / $max;
            if ($men[$i] > 0) $s .= '<rect x="' . round($mid - $gap - $wm, 1) . '" y="' . ($y + 1) . '" width="' . round($wm, 1) . '" height="' . ($rh - 2) . '" fill="#1d4ed8" fill-opacity="0.8"/><text x="' . round($mid - $gap - $wm - 3, 1) . '" y="' . ($y + 10) . '" font-size="7.5" text-anchor="end" fill="' . $c['meta'] . '">' . $men[$i] . '</text>';
            if ($women[$i] > 0) $s .= '<rect x="' . ($mid + $gap) . '" y="' . ($y + 1) . '" width="' . round($wf, 1) . '" height="' . ($rh - 2) . '" fill="#f472b6" fill-opacity="0.9"/><text x="' . round($mid + $gap + $wf + 3, 1) . '" y="' . ($y + 10) . '" font-size="7.5" text-anchor="start" fill="' . $c['meta'] . '">' . $women[$i] . '</text>';
            $s .= '<text x="' . $mid . '" y="' . ($y + 10) . '" font-size="7" text-anchor="middle" fill="' . $c['text'] . '">' . self::e($labels[$i]) . '</text>';
        }
        $s .= '';
        return self::wrap($s, $W, $H, $mode, $alt);
    }

    /** Frise : $rows = [['label'=>, 'b'=>année, 'd'=>année|null], ...] triées. */
    public static function timeline(array $rows, array $c, $mode, $alt) {
        $lw = 156; $W = 500; $rh = 9.5; $top = 16;
        $ys = [];
        foreach ($rows as $r) { $ys[] = $r['b']; if ($r['d']) $ys[] = $r['d']; }
        $min = (int) floor(min($ys) / 50) * 50; $max = (int) ceil(max($ys) / 50) * 50;
        if ($max <= $min) $max = $min + 50;
        $pw = $W - $lw - 8;
        $X = function ($y) use ($lw, $pw, $min, $max) { return $lw + $pw * ($y - $min) / ($max - $min); };
        $H = (int) ($top + count($rows) * $rh + 6);
        $s = '';
        for ($y = $min; $y <= $max; $y += 50) {
            $s .= '<line x1="' . round($X($y), 1) . '" y1="' . ($top - 4) . '" x2="' . round($X($y), 1) . '" y2="' . ($H - 3) . '" stroke="' . self::tint($c['tree'], 0.55) . '" stroke-width="0.6"/>'
                . '<text x="' . round($X($y), 1) . '" y="9" font-size="7" text-anchor="middle" fill="' . $c['meta'] . '">' . $y . '</text>';
        }
        foreach ($rows as $i => $r) {
            $y = $top + $i * $rh;
            $lab = mb_strlen($r['label']) > 34 ? mb_substr($r['label'], 0, 33) . '…' : $r['label'];
            $s .= '<text x="' . ($lw - 4) . '" y="' . ($y + 6.2) . '" font-size="6.8" text-anchor="end" fill="' . $c['text'] . '">' . self::e($lab) . '</text>';
            if ($r['d'] && $r['d'] > $r['b']) {
                $s .= '<rect x="' . round($X($r['b']), 1) . '" y="' . ($y + 1.5) . '" width="' . round(max(1.5, $X($r['d']) - $X($r['b'])), 1) . '" height="5" fill="' . $c['head'] . '" rx="1.5"/>';
            } else {
                $s .= '<circle cx="' . round($X($r['b']), 1) . '" cy="' . ($y + 4) . '" r="2.4" fill="#ffffff" stroke="' . $c['head'] . '" stroke-width="1.2"/>';
            }
        }
        return self::wrap($s, $W, $H, $mode, $alt);
    }
}

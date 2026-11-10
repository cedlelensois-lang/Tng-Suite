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

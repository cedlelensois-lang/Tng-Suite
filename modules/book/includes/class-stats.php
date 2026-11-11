<?php
/**
 * Statistiques du livre, calculées uniquement à partir des données déjà chargées (TNGBook_Data).
 * Les personnes vivantes ou privées (masquées) sont exclues de tous les calculs détaillés.
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Stats {
    private $d;
    private $up;
    public $r = [];

    public static function compute(TNGBook_Data $d, $up) {
        $s = new self();
        $s->d = $d;
        $s->up = (int) $up;
        $s->run();
        return $s->r;
    }

    private static function ymd($s) {
        if (!$s || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $s, $m)) return null;
        $y = (int) $m[1];
        return $y > 0 ? [$y, (int) $m[2], (int) $m[3]] : null;
    }

    /** Âge révolu entre deux dates [a,m,j] (mois/jour ignorés s'ils sont inconnus), ou null si aberrant. */
    private static function age($b, $e) {
        if (!$b || !$e) return null;
        $a = $e[0] - $b[0];
        if ($b[1] && $e[1] && ($e[1] < $b[1] || ($e[1] === $b[1] && $b[2] && $e[2] && $e[2] < $b[2]))) $a--;
        return ($a >= 0 && $a <= 115) ? $a : null;
    }

    private static function median(array $v) {
        sort($v);
        $n = count($v);
        if (!$n) return null;
        return $n % 2 ? $v[($n - 1) >> 1] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    private static function summary(array $v) {
        return $v ? ['n' => count($v), 'mean' => array_sum($v) / count($v), 'median' => self::median($v)] : ['n' => 0, 'mean' => null, 'median' => null];
    }

    private static function century($y) { return (int) floor($y / 100) * 100; }

    private static function top(array $counts, $n) {
        arsort($counts);
        $out = [];
        foreach ($counts as $k => $c) { $out[] = [(string) $k, $c]; if (count($out) >= $n) break; }
        return $out;
    }

    private static function commune($place) {
        $p = trim(explode(',', (string) $place)[0]);
        return $p;
    }

    private function run() {
        $d = $this->d;
        $people = [];
        foreach ($d->people as $pid => $p) {
            if ($d->dropped($pid)) continue;
            $people[$pid] = $p;
        }
        $vis = [];                                  // personnes dont les détails sont utilisables
        foreach ($people as $pid => $p) if (!$d->hidden($pid)) $vis[$pid] = $p;

        // ---- vue d'ensemble
        $sx = ['M' => 0, 'F' => 0, 'U' => 0];
        foreach ($people as $p) { $k = strtoupper(trim((string) $p['sex'])); $sx[($k === 'M' || $k === 'F') ? $k : 'U']++; }
        $nf = 0;
        foreach ($d->fams as $f) {
            $a = $f['husband'] !== '' && isset($people[$f['husband']]);
            $b = $f['wife'] !== '' && isset($people[$f['wife']]);
            if ($a || $b) $nf++;
        }
        $maxA = 0;
        foreach ($d->sosa as $n => $pid) $maxA = max($maxA, strlen(decbin($n)));
        $maxD = $d->descGen ? max(array_keys($d->descGen)) : 0;
        $years = [];
        foreach ($vis as $p) {
            foreach (['birthdatetr', 'deathdatetr'] as $k) { $y = self::ymd($p[$k]); if ($y) $years[] = $y[0]; }
        }
        $this->r['overview'] = [
            'people' => count($people), 'M' => $sx['M'], 'F' => $sx['F'], 'U' => $sx['U'], 'fams' => $nf,
            'gen_asc' => $maxA, 'gen_desc' => $maxD, 'sources' => count($d->sources),
            'min' => $years ? min($years) : null, 'max' => $years ? max($years) : null,
        ];

        // ---- âges au décès, mortalité infantile
        $ageD = ['M' => [], 'F' => [], 'A' => []];
        $ageDc = [];                                // [siècle][sexe] => âges
        $inf = [];                                  // [siècle] => [n, <1, <5]
        $bands = array_fill(0, 11, 0);
        $bandsS = ['M' => array_fill(0, 11, 0), 'F' => array_fill(0, 11, 0)];   // pyramide : hommes / femmes par tranche de 10 ans
        $births = [];
        foreach ($vis as $pid => $p) {
            $b = self::ymd($p['birthdatetr']);
            if ($b) { $q = (int) floor($b[0] / 25) * 25; $births[$q] = ($births[$q] ?? 0) + 1; }
            $e = self::ymd($p['deathdatetr']);
            $a = self::age($b, $e);
            if ($a === null) continue;
            $k = strtoupper(trim((string) $p['sex']));
            $ageD['A'][] = $a;
            if ($k === 'M' || $k === 'F') { $ageD[$k][] = $a; $ageDc[self::century($e[0])][$k][] = $a; }   // espérance de vie : par siècle du décès
            $bands[min(10, (int) floor($a / 10))]++;
            if ($k === 'M' || $k === 'F') $bandsS[$k][min(10, (int) floor($a / 10))]++;
            $c = self::century($b[0]);
            if (!isset($inf[$c])) $inf[$c] = [0, 0, 0];
            $inf[$c][0]++;
            if ($a < 1) $inf[$c][1]++;
            if ($a < 5) $inf[$c][2]++;
        }
        ksort($ageDc); ksort($inf); ksort($births);
        $byC = [];
        foreach ($ageDc as $c => $bySex) {
            $row = ['c' => $c];
            foreach (['M', 'F'] as $k) $row[$k] = self::summary($bySex[$k] ?? []);
            $row['A'] = self::summary(array_merge($bySex['M'] ?? [], $bySex['F'] ?? []));
            if ($row['M']['n'] + $row['F']['n'] >= 5) $byC[] = $row;
        }
        $this->r['age_death'] = ['all' => self::summary($ageD['A']), 'M' => self::summary($ageD['M']), 'F' => self::summary($ageD['F']), 'by_century' => $byC, 'bands' => $bands, 'bands_sex' => $bandsS];
        $this->r['births'] = $births;
        $infRows = [];
        foreach ($inf as $c => $v) if ($v[0] >= 5) $infRows[] = ['c' => $c, 'n' => $v[0], 'u1' => $v[1], 'u5' => $v[2]];
        $this->r['infant'] = $infRows;

        // ---- âge au premier mariage, au premier enfant ; enfants par couple
        $marr = ['M' => [], 'F' => []];
        $first = ['M' => [], 'F' => []];
        foreach ($vis as $pid => $p) {
            $b = self::ymd($p['birthdatetr']);
            if (!$b) continue;
            $k = strtoupper(trim((string) $p['sex']));
            if ($k !== 'M' && $k !== 'F') continue;
            $mind = null;
            foreach ($d->famsOf[$pid] ?? [] as $fid) {
                $f = $d->fams[$fid] ?? null;
                if (!$f) continue;
                $m = self::ymd($f['marrdatetr']);
                if ($m && !isset($marr[$k][$pid])) { $a = self::age($b, $m); if ($a !== null && $a >= 10) $marr[$k][$pid] = $a; }
                foreach ($d->kidsOf[$fid] ?? [] as $kid) {
                    $kb = self::ymd($d->people[$kid]['birthdatetr'] ?? '');
                    if ($kb && ($mind === null || $kb < $mind)) $mind = $kb;
                }
            }
            if ($mind) { $a = self::age($b, $mind); if ($a !== null && $a >= 12) $first[$k][] = $a; }
        }
        $this->r['age_marr'] = ['M' => self::summary(array_values($marr['M'])), 'F' => self::summary(array_values($marr['F']))];
        $this->r['age_child'] = ['M' => self::summary($first['M']), 'F' => self::summary($first['F'])];

        $kids = [];                                 // fam => nombre d'enfants non retirés
        foreach ($d->kidsOf as $fid => $ks) {
            $n = 0;
            foreach ($ks as $k) if (isset($people[$k])) $n++;
            if ($n > 0 && isset($d->fams[$fid])) $kids[$fid] = $n;
        }
        $dist = array_fill(1, 10, 0);
        foreach ($kids as $n) $dist[min(10, $n)]++;
        arsort($kids);
        $big = [];
        foreach ($kids as $fid => $n) { $big[] = ['fam' => $fid, 'n' => $n, 'h' => $d->fams[$fid]['husband'], 'w' => $d->fams[$fid]['wife']]; if (count($big) >= 3) break; }
        $this->r['kids'] = ['dist' => $dist, 'couples' => count($kids), 'avg' => $kids ? array_sum($kids) / count($kids) : null, 'big' => $big];

        // ---- prénoms et noms
        $fn = ['M' => [], 'F' => []];
        $ln = [];
        $fnC = [];
        $lnC = [];
        // Personnes réellement présentes dans le livre : ascendants, descendants (et leurs conjoints). Les personnes
        // chargées seulement pour d'autres calculs (enfants d'ancêtres, etc.) ne comptent pas pour les noms.
        $feat = [];
        foreach ($d->sosa as $x) $feat[$x] = true;
        foreach (array_keys($d->desc) as $x) $feat[$x] = true;
        foreach (array_keys($d->listed) as $x) $feat[$x] = true;
        foreach (array_keys($d->desc) as $x) {
            foreach ($d->famsOf[$x] ?? [] as $fid) {
                $f = $d->fams[$fid] ?? null;
                if ($f) foreach (['husband', 'wife'] as $r) if (!empty($f[$r])) $feat[$f[$r]] = true;
            }
        }
        foreach ($vis as $pid => $p) {
            if (!isset($feat[$pid])) continue;
            $k = strtoupper(trim((string) $p['sex']));
            $b = self::ymd($p['birthdatetr']);
            $c = $b ? self::century($b[0]) : null;
            $first = preg_split('/\s+/u', trim((string) $p['firstname']))[0] ?? '';
            $first = trim($first, " \t\n\r\"'“”«»()?,.");
            if ($first !== '' && ($k === 'M' || $k === 'F')) {
                $first = mb_convert_case(mb_strtolower($first), MB_CASE_TITLE);
                $fn[$k][$first] = ($fn[$k][$first] ?? 0) + 1;
                if ($c !== null) $fnC[$c][$k][$first] = ($fnC[$c][$k][$first] ?? 0) + 1;
            }
            $last = mb_strtoupper(trim((string) $p['lastname']));
            if ($last !== '' && $last !== '?') {
                $ln[$last] = ($ln[$last] ?? 0) + 1;
                if ($c !== null) $lnC[$c][$last] = ($lnC[$c][$last] ?? 0) + 1;
            }
        }
        ksort($fnC); ksort($lnC);
        $cent = [];
        foreach ($lnC as $c => $v) {
            if (array_sum($v) < 8) continue;
            $cent[] = ['c' => $c, 'M' => self::top($fnC[$c]['M'] ?? [], 3), 'F' => self::top($fnC[$c]['F'] ?? [], 3), 'L' => self::top($v, 3)];
        }
        $this->r['names'] = ['M' => self::top($fn['M'], 10), 'F' => self::top($fn['F'], 10), 'L' => self::top($ln, 10), 'distinct' => count($ln), 'total' => array_sum($ln), 'by_century' => $cent];

        // ---- lieux
        $pb = []; $pm = []; $pd = [];
        $label = [];
        $add = function (array &$arr, $place) use (&$label) {
            $c = self::commune($place);
            if ($c === '') return;
            $key = mb_strtolower($c);
            $label[$key] = $label[$key] ?? $c;
            $arr[$key] = ($arr[$key] ?? 0) + 1;
        };
        foreach ($vis as $p) { $add($pb, $p['birthplace']); $add($pd, $p['deathplace']); }
        foreach ($d->fams as $f) {
            $ok = true;
            foreach (['husband', 'wife'] as $r) if ($f[$r] !== '' && (!isset($people[$f[$r]]) || $d->hidden($f[$r]))) $ok = false;
            if ($ok) $add($pm, $f['marrplace']);
        }
        $named = function ($arr) use ($label) {
            return array_map(function ($t) use ($label) { return [$label[$t[0]] ?? $t[0], $t[1]]; }, self::top($arr, 10));
        };
        $this->r['places'] = ['birth' => $named($pb), 'marr' => $named($pm), 'death' => $named($pd)];
        $mig = []; $cmp = 0; $diff = 0;
        foreach ($d->kidsOf as $fid => $ks) {
            $f = $d->fams[$fid] ?? null;
            if (!$f) continue;
            $from = '';
            foreach (['husband', 'wife'] as $r) {
                if ($from === '' && $f[$r] !== '' && isset($vis[$f[$r]])) $from = self::commune($vis[$f[$r]]['birthplace']);
            }
            if ($from === '') continue;
            foreach ($ks as $k) {
                if (!isset($vis[$k])) continue;
                $to = self::commune($vis[$k]['birthplace']);
                if ($to === '') continue;
                $cmp++;
                if (mb_strtolower($to) !== mb_strtolower($from)) { $diff++; $key = $from . "\t" . $to; $mig[$key] = ($mig[$key] ?? 0) + 1; }
            }
        }
        $mr = [];
        foreach (self::top($mig, 8) as $t) { list($a, $b2) = explode("\t", $t[0]); $mr[] = [$a, $b2, $t[1]]; }
        $this->r['migr'] = ['rows' => $mr, 'pct' => $cmp ? (int) round(100 * $diff / $cmp) : null];

        // ---- complétude de l'ascendance
        $G = max(1, min($this->up + 1, 15));
        $rows = []; $last = 0; $known = 0;
        for ($g = 1; $g <= $G; $g++) {
            $row = ['g' => $g, 'possible' => 1 << ($g - 1), 'known' => 0, 'implex' => 0, 'birth' => 0, 'death' => 0];
            for ($n = 1 << ($g - 1); $n < (1 << $g); $n++) {
                if (!isset($d->sosa[$n])) continue;
                $row['known']++;
                if (isset($d->collapse[$n])) { $row['implex']++; continue; }
                $known++;
                $p = $d->people[$d->sosa[$n]] ?? null;
                if ($p && !$d->hidden($d->sosa[$n])) {
                    if (self::ymd($p['birthdatetr'])) $row['birth']++;
                    if (self::ymd($p['deathdatetr'])) $row['death']++;
                }
            }
            if ($row['known'] > 0) $last = $g;
            $rows[] = $row;
        }
        $this->r['complete'] = ['rows' => array_slice($rows, 0, min($G, $last + 1)), 'distinct' => $known, 'possible' => (1 << $G) - 1];

        // ---- frise des ancêtres directs
        $tl = [];
        foreach ($d->sosa as $n => $pid) {
            if (isset($d->collapse[$n]) || !isset($vis[$pid])) continue;
            $b = self::ymd($vis[$pid]['birthdatetr']);
            if (!$b) continue;
            $e = self::ymd($vis[$pid]['deathdatetr']);
            $tl[] = ['n' => $n, 'pid' => $pid, 'b' => $b[0], 'd' => $e ? $e[0] : null];
        }
        usort($tl, function ($a, $b) { return $a['n'] <=> $b['n']; });
        $tl = array_slice($tl, 0, 60);
        usort($tl, function ($a, $b) { return $a['b'] <=> $b['b'] ?: $a['n'] <=> $b['n']; });
        $this->r['timeline'] = $tl;
    }
}

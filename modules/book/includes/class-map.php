<?php
/**
 * Carte des lieux de vie (noir et blanc) : un nombre de personnes par département (France), province (Espagne, Belgique)
 * ou pays. Fond de carte : contours GeoJSON déposés dans modules/book/data/ (regions-fr.geojson, regions-es.geojson,
 * regions-be.geojson) s'ils existent, sinon fond schématique très simplifié.
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Map {
    private $D;
    private $idx = [];
    private $cty = [];

    public static function norm($s) {
        $s = (string) $s;
        $s = function_exists('remove_accents') ? remove_accents($s) : (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    }

    /** Libellé de pays : majuscule initiale à chaque mot, sigles courts (USA, UK) conservés. */
    public static function nice($l) {
        $l = trim((string) $l);
        return mb_strlen($l) <= 3 ? mb_strtoupper($l) : mb_convert_case($l, MB_CASE_TITLE);
    }

    public static function make() { return new self(); }

    private function __construct() {
        $this->D = require TNGBOOK_DIR . 'includes/map-regions.php';
        foreach ($this->D['regions'] as $c => $list) {
            foreach ($list as $k => $r) {
                $this->idx[$c][self::norm($r[0])] = $k;
                foreach (array_slice($r, 3) as $al) $this->idx[$c][self::norm($al)] = $k;
            }
        }
        foreach ($this->D['world'] as $w) {
            $al = explode('|', $w[2]);
            foreach ($al as $n) $this->cty[$n] = $al[0];
        }
    }

    /* ---------- Calcul ---------- */

    /** Textes de lieux utiles (naissance, mariages, décès) d'une personne présente dans le livre. */
    public static function placesOf(TNGBook_Data $d, $pid) {
        $p = $d->people[$pid] ?? null;
        if (!$p) return [];
        $out = [];
        foreach (self::placesLabelled($d, $pid) as $x) $out[] = $x[1];
        return $out;
    }

    /** Mêmes lieux, avec leur origine : [[origine, texte], …] (origine : naissance, décès, mariage, résidence/événement). */
    public static function placesLabelled(TNGBook_Data $d, $pid) {
        $p = $d->people[$pid] ?? null;
        if (!$p) return [];
        $out = [];
        foreach (['birthplace' => 'naissance', 'deathplace' => 'décès'] as $c => $lab) if (trim((string) $p[$c]) !== '') $out[] = [$lab, trim($p[$c])];
        foreach ($d->famsOf[$pid] ?? [] as $fid) {
            $f = $d->fams[$fid] ?? null;
            if ($f && trim((string) ($f['marrplace'] ?? '')) !== '') $out[] = ['mariage ' . $fid, trim($f['marrplace'])];
        }
        foreach ($d->resi[$pid] ?? [] as $x) $out[] = ['résidence / événement', $x];
        return $out;
    }

    /** Liste de tous les lieux à chercher dans la table des lieux (pour le chargement des coordonnées). */
    public static function allPlaces(TNGBook_Data $d, array $pids) {
        $o = [];
        foreach ($pids as $pid) foreach (self::placesOf($d, $pid) as $x) $o[$x] = true;
        return array_keys($o);
    }

    public static function compute(TNGBook_Data $d, array $pids) {
        $m = new self();
        $res = ['maps' => [], 'other' => [], 'nodetail' => [], 'unlocated' => 0, 'people' => 0, 'approx' => 0];
        foreach ($pids as $pid) {
            if (!isset($d->people[$pid]) || $d->hidden($pid) || $d->dropped($pid)) continue;
            $seen = [];
            $any = false;
            foreach (self::placesOf($d, $pid) as $txt) {
                $r = $m->resolve($txt, $d->coords[$txt] ?? null);
                if (!$r) continue;
                $any = true;
                $key = $r['t'] . '|' . ($r['k'] ?? '') . '|' . ($r['l'] ?? '');
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                if ($r['t'] === 'R') {
                    $res['maps'][$r['c']][$r['k']] = ($res['maps'][$r['c']][$r['k']] ?? 0) + 1;
                    if (!empty($r['a'])) $res['approx']++;
                } elseif ($r['t'] === 'N') {
                    $res['nodetail'][$r['c']] = ($res['nodetail'][$r['c']] ?? 0) + 1;
                } else {
                    $nk = $r['k'];
                    if (!isset($res['other'][$nk])) $res['other'][$nk] = ['label' => self::nice($r['l']), 'n' => 0];
                    $res['other'][$nk]['n']++;
                }
            }
            $res['people']++;
            if (!$any) $res['unlocated']++;
        }
        foreach ($res['maps'] as &$mm) arsort($mm);
        unset($mm);
        uasort($res['other'], function ($a, $b) { return $b['n'] <=> $a['n']; });
        return $res;
    }

    /**
     * Rattache un texte de lieu à un territoire :
     *  ['t'=>'R','c'=>pays,'k'=>clé,'a'=>approximatif] région ; ['t'=>'N','c'=>pays] pays connu sans région ;
     *  ['t'=>'O','k'=>clé,'l'=>libellé] autre pays ; null si indéterminé.
     */
    public function resolve($text, $co = null) {
        $segs = array_values(array_filter(array_map('trim', explode(',', $text)), 'strlen'));
        if (!$segs) return null;
        $norm = array_map([__CLASS__, 'norm'], $segs);
        $hint = null; $other = null; $hintSeg = '';
        foreach ($norm as $i => $n) {
            foreach ($this->D['country'] as $c => $names) {
                if (in_array($n, $names, true)) { $hint = $c; $hintSeg = $segs[$i]; $norm[$i] = ''; }
            }
        }
        if (!$hint) {
            $last = end($norm);
            if ($last !== '' && isset($this->cty[$last]) && !isset($this->idx['FR'][$last]) && !isset($this->idx['ES'][$last])) {
                $other = [$this->cty[$last], end($segs)];
            }
        }
        if ($other) return ['t' => 'O', 'k' => $other[0], 'l' => $other[1], 'w' => 'pays écrit : « ' . $other[1] . ' »'];

        // « Province de Hainaut », « Département du Nord », « Provincia de Girona » : on retire le mot de structure
        foreach ($norm as $i => $n) {
            if ($n !== '' && preg_match('/^(province|provincia|provincie|prov|departement|department|dep|comarca)( de la| de l| du| des| del| de| d| van)? (.+)$/', $n, $mm)) $norm[$i] = $mm[3];
        }
        $order = $hint ? [$hint] : ['FR', 'BE', 'ES'];
        // 1. nom du département / de la province dans un des segments (de droite à gauche)
        foreach ($order as $c) {
            for ($i = count($norm) - 1; $i >= 0; $i--) {
                $n = $norm[$i];
                if ($n === '') continue;
                if (isset($this->idx[$c][$n])) return ['t' => 'R', 'c' => $c, 'k' => $this->idx[$c][$n], 'w' => 'nom de département/province trouvé : « ' . $segs[$i] . ' »' . ($hint ? ' + pays écrit « ' . $hintSeg . ' »' : '')];
                if ($c === 'FR' && preg_match('/^paris( \d+ ?(e|er|eme)?)?$/', $n)) return ['t' => 'R', 'c' => 'FR', 'k' => '75', 'w' => '« ' . $segs[$i] . ' » reconnu comme Paris'];
            }
        }
        // 2. code département entre parenthèses ou code postal
        if (!$hint || $hint === 'FR') {
            if (preg_match('/\((\d{2,3}|2[AB])\)/i', $text, $mm) && isset($this->D['regions']['FR'][strtoupper($mm[1])])) {
                return ['t' => 'R', 'c' => 'FR', 'k' => strtoupper($mm[1]), 'w' => 'code département « (' . $mm[1] . ') »'];
            }
            if (preg_match('/(?<!\d)(\d{5})(?!\d)/', $text, $mm)) {
                $k = self::frPostal($mm[1]);
                if ($k && isset($this->D['regions']['FR'][$k])) return ['t' => 'R', 'c' => 'FR', 'k' => $k, 'w' => 'code postal ' . $mm[1]];
            }
        }
        if ($hint === 'ES' && preg_match('/(?<!\d)(\d{2})\d{3}(?!\d)/', $text, $mm) && isset($this->D['regions']['ES']['ES' . $mm[1]])) {
            return ['t' => 'R', 'c' => 'ES', 'k' => 'ES' . $mm[1], 'w' => 'code postal espagnol ' . $mm[1]];
        }
        if ($hint === 'BE' && preg_match('/(?<!\d)([1-9]\d{3})(?!\d)/', $text, $mm)) {
            $k = self::bePostal((int) $mm[1]);
            if ($k && isset($this->D['regions']['BE'][$k])) return ['t' => 'R', 'c' => 'BE', 'k' => $k, 'w' => 'code postal belge ' . $mm[1]];
        }
        // 3. coordonnées connues de TNG : territoire dont le centre est le plus proche
        if ($co) {
            $k = $this->nearest($co[0], $co[1], $hint);
            if ($k) return ['t' => 'R', 'c' => $k[0], 'k' => $k[1], 'a' => true, 'w' => 'coordonnées TNG (' . round($co[0], 3) . ', ' . round($co[1], 3) . ') : territoire au centre le plus proche (APPROXIMATIF)' . ($hint ? ' + pays écrit « ' . $hintSeg . ' »' : '')];
        }
        return $hint ? ['t' => 'N', 'c' => $hint, 'w' => 'pays écrit « ' . $hintSeg . ' » mais aucun département/province/code postal/coordonnée exploitable'] : null;
    }

    /** Pays (FR, ES ou BE) déduit d'un lieu par son département, sa province ou son code postal, sinon null. */
    public function countryOf($text) {
        $r = $this->resolve($text, null);
        return $r && isset($r['c']) ? $r['c'] : null;
    }

    /** Province belge d'après le code postal à 4 chiffres (tranches officielles de bpost). */
    private static function bePostal($cp) {
        if ($cp >= 1000 && $cp <= 1299) return 'BE-BRU';
        if ($cp >= 1300 && $cp <= 1499) return 'BE-WBR';
        if ($cp >= 1500 && $cp <= 1999) return 'BE-VBR';
        if ($cp >= 2000 && $cp <= 2999) return 'BE-VAN';
        if ($cp >= 3000 && $cp <= 3499) return 'BE-VBR';
        if ($cp >= 3500 && $cp <= 3999) return 'BE-VLI';
        if ($cp >= 4000 && $cp <= 4999) return 'BE-WLG';
        if ($cp >= 5000 && $cp <= 5999) return 'BE-WNA';
        if ($cp >= 6000 && $cp <= 6599) return 'BE-WHT';
        if ($cp >= 6600 && $cp <= 6999) return 'BE-WLX';
        if ($cp >= 7000 && $cp <= 7999) return 'BE-WHT';
        if ($cp >= 8000 && $cp <= 8999) return 'BE-VWV';
        if ($cp >= 9000 && $cp <= 9999) return 'BE-VOV';
        return null;
    }

    private static function frPostal($cp) {
        $d2 = substr($cp, 0, 2);
        if ($d2 === '20') return (int) substr($cp, 0, 3) < 202 ? '2A' : '2B';
        if ((int) $d2 >= 97) return substr($cp, 0, 3);
        if ((int) $d2 === 0 || (int) $d2 > 95) return null;
        return $d2;
    }

    private function nearest($la, $lo, $hint) {
        $best = null; $bd = 1e9;
        foreach ($this->D['bbox'] as $c => $b) {
            if ($hint && $hint !== $c) continue;
            if ($la < $b[0] || $la > $b[1] || $lo < $b[2] || $lo > $b[3]) continue;
            foreach ($this->D['regions'][$c] as $k => $r) {
                $dx = ($lo - $r[2]) * cos(deg2rad($la)); $dy = $la - $r[1];
                $dd = $dx * $dx + $dy * $dy;
                if ($dd < $bd) { $bd = $dd; $best = [$c, $k]; }
            }
        }
        return $best;
    }

    /* ---------- Dessin ---------- */

    public function data() { return $this->D; }

    public static function names() {
        $m = new self();
        $o = [];
        foreach ($m->D['regions'] as $c => $l) foreach ($l as $k => $r) $o[$c][$k] = $r[0];
        return $o;
    }

    /** GeoJSON utilisateur → [clé => [anneaux [[lon,lat],…]]], ou null. */
    private function geo($c) {
        $f = TNGBOOK_DIR . 'data/regions-' . preg_replace('/[^a-z]/', '', strtolower((string) $c)) . '.geojson';
        if (!is_readable($f)) return null;
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j) || empty($j['features'])) return null;
        $out = [];
        foreach ($j['features'] as $ft) {
            $pr = $ft['properties'] ?? [];
            $k = null;
            foreach (['code', 'CODE', 'code_insee', 'INSEE_DEP', 'CODE_DEPT', 'dep', 'id'] as $key) {
                if ($c === 'FR' && isset($pr[$key])) {
                    $v = strtoupper(trim((string) $pr[$key]));
                    if (strlen($v) === 1) $v = '0' . $v;
                    if (isset($this->D['regions']['FR'][$v])) { $k = $v; break; }
                }
            }
            if (!$k) {
                foreach (['nom', 'name', 'NAME', 'NAME_2', 'NAME_LATN', 'NOM', 'NOM_DEP', 'prov', 'provincia', 'name_fr', 'NAMEUNIT'] as $key) {
                    if (isset($pr[$key]) && isset($this->idx[$c][self::norm($pr[$key])])) { $k = $this->idx[$c][self::norm($pr[$key])]; break; }
                }
            }
            if (!$k) continue;
            $g = $ft['geometry'] ?? null;
            if (!$g) continue;
            $polys = $g['type'] === 'Polygon' ? [$g['coordinates']] : ($g['type'] === 'MultiPolygon' ? $g['coordinates'] : []);
            foreach ($polys as $poly) foreach ($poly as $ring) $out[$k][] = $ring;
        }
        return $out ?: null;
    }

    private static $shade = ['#ffffff', '#ebebeb', '#cfcfcf', '#adadad', '#8a8a8a', '#666666'];

    /** Carte d'un pays : $counts [clé => nombre]. Retourne le SVG intérieur et ses dimensions. */
    public function svg($c, array $counts, $mode, $alt) {
        $D = $this->D; $reg = $D['regions'][$c]; $b = $D['bbox'][$c];
        $geo = $this->geo($c);
        $W = 480; $mg = 10;
        $la0 = $b[0]; $la1 = $b[1]; $lo0 = $b[2]; $lo1 = $b[3];
        $cs = cos(deg2rad(($la0 + $la1) / 2));
        $sx = ($W - 2 * $mg) / (($lo1 - $lo0) * $cs);
        $sy = $sx;
        $H = (int) round(($la1 - $la0) * $sy + 2 * $mg);
        if ($H > 520) { $sy = $sx = $sx * 520 / $H; $H = 520; $W = (int) round(($lo1 - $lo0) * $cs * $sx + 2 * $mg); }
        $px = function ($lo) use ($lo0, $cs, $sx, $mg) { return $mg + ($lo - $lo0) * $cs * $sx; };
        $py = function ($la) use ($la1, $sy, $mg) { return $mg + ($la1 - $la) * $sy; };
        $path = function (array $ring) use ($px, $py) {
            $d = '';
            foreach ($ring as $i => $pt) $d .= ($i ? 'L' : 'M') . round($px($pt[0]), 1) . ' ' . round($py($pt[1]), 1);
            return $d . 'Z';
        };
        $max = max(1, max($counts ?: [1]));
        $step = function ($n) use ($max) { return $n > 0 ? max(1, (int) ceil(5 * $n / $max)) : 0; };
        $s = '<rect x="0.5" y="0.5" width="' . ($W - 1) . '" height="' . ($H - 1) . '" fill="#fff" stroke="#000" stroke-width="1"/>';
        if ($geo) {
            foreach ($geo as $k => $rings) {
                $d = '';
                foreach ($rings as $ring) {
                    $mid = $ring[(int) (count($ring) / 2)] ?? null;
                    if (!$mid || $mid[1] < $la0 || $mid[1] > $la1 || $mid[0] < $lo0 || $mid[0] > $lo1) continue;
                    $d .= $path($ring);
                }
                if ($d === '') continue;
                $s .= '<path d="' . $d . '" fill="' . self::$shade[$step($counts[$k] ?? 0)] . '" stroke="#000" stroke-width="0.5" stroke-linejoin="round"/>';
            }
        } else {
            // fond schématique : graticule léger et contours très simplifiés
            for ($la = ceil($la0 / 2) * 2; $la <= $la1; $la += 2) $s .= '<line x1="0" x2="' . $W . '" y1="' . round($py($la), 1) . '" y2="' . round($py($la), 1) . '" stroke="#bbb" stroke-width="0.4" stroke-dasharray="2 3"/>';
            for ($lo = ceil($lo0 / 2) * 2; $lo <= $lo1; $lo += 2) $s .= '<line y1="0" y2="' . $H . '" x1="' . round($px($lo), 1) . '" x2="' . round($px($lo), 1) . '" stroke="#bbb" stroke-width="0.4" stroke-dasharray="2 3"/>';
            foreach ($D['outline'][$c] as $ring) $s .= '<path d="' . $path($ring) . '" fill="#f1f1f1" stroke="#000" stroke-width="0.9" stroke-linejoin="round"/>';
        }
        // pastilles numérotées, légèrement écartées les unes des autres
        $pts = [];
        foreach ($counts as $k => $n) {
            if (!isset($reg[$k])) continue;
            $r = $reg[$k];
            if ($r[1] < $la0 || $r[1] > $la1 || $r[2] < $lo0 || $r[2] > $lo1) continue;
            $x = $px($r[2]); $y = $py($r[1]);
            $pts[] = ['k' => $k, 'n' => $n, 'x0' => $x, 'y0' => $y, 'x' => $x, 'y' => $y, 'r' => 5.2 + 2.6 * strlen((string) $n)];
        }
        $s .= $this->badges($pts, $W, $H, !$geo, $reg);
        return TNGBook_Charts::wrap($s, $W, $H, $mode, $alt, $c === 'BE' ? 12 : 14);
    }

    /** Carte schématique du monde : $others [clé => ['label'=>, 'n'=>]] ; contours de continents très simplifiés. */
    public function svgWorld(array $others, $mode, $alt) {
        $pos = [];
        foreach ($this->D['world'] as $w) $pos[explode('|', $w[2])[0]] = [$w[0], $w[1]];
        $items = [];
        foreach ($others as $k => $o) if (isset($pos[$k])) $items[$k] = $o;
        if (!$items) return '';
        $la0 = 90; $la1 = -90; $lo0 = 180; $lo1 = -180;
        foreach ($items as $k => $o) {
            $la0 = min($la0, $pos[$k][0]); $la1 = max($la1, $pos[$k][0]); $lo0 = min($lo0, $pos[$k][1]); $lo1 = max($lo1, $pos[$k][1]);
        }
        $la0 -= 12; $la1 += 12; $lo0 -= 20; $lo1 += 20;
        if ($lo1 - $lo0 < 70) { $m = (70 - ($lo1 - $lo0)) / 2; $lo0 -= $m; $lo1 += $m; }
        if ($la1 - $la0 < 35) { $m = (35 - ($la1 - $la0)) / 2; $la0 -= $m; $la1 += $m; }
        $la0 = max($la0, -60); $la1 = min($la1, 84); $lo0 = max($lo0, -180); $lo1 = min($lo1, 180);
        $W = 640; $mg = 6;
        $sx = ($W - 2 * $mg) / ($lo1 - $lo0);
        $H = (int) round(($la1 - $la0) * $sx + 2 * $mg);
        $px = function ($lo) use ($lo0, $sx, $mg) { return $mg + ($lo - $lo0) * $sx; };
        $py = function ($la) use ($la1, $sx, $mg) { return $mg + ($la1 - $la) * $sx; };
        $s = '<rect x="0.5" y="0.5" width="' . ($W - 1) . '" height="' . ($H - 1) . '" fill="#fff" stroke="#000" stroke-width="1"/>';
        foreach ($this->D['worldoutline'] as $id => $ring) {
            $d = '';
            foreach ($ring as $i => $pt) $d .= ($i ? 'L' : 'M') . round($px($pt[0]), 1) . ' ' . round($py($pt[1]), 1);
            $sea = in_array($id, ['bs', 'cs'], true);
            $s .= '<path d="' . $d . 'Z" fill="' . ($sea ? '#fff' : '#f1f1f1') . '" stroke="#000" stroke-width="' . ($sea ? '0.5' : '0.7') . '" stroke-linejoin="round"/>';
        }
        $pts = [];
        foreach ($items as $k => $o) {
            $x = $px($pos[$k][1]); $y = $py($pos[$k][0]);
            $pts[] = ['k' => $k, 'n' => $o['n'], 'name' => $o['label'], 'x0' => $x, 'y0' => $y, 'x' => $x, 'y' => $y, 'r' => 5.2 + 2.6 * strlen((string) $o['n'])];
        }
        $s .= $this->badges($pts, $W, $H, true, []);
        return TNGBook_Charts::wrap($s, $W, $H, $mode, $alt, 16);
    }

    private function badges(array $pts, $W, $H, $names, array $reg) {
        $s = '';
        for ($it = 0; $it < 80; $it++) {
            $moved = false;
            foreach ($pts as $i => &$a) {
                foreach ($pts as $j => &$bb) {
                    if ($j <= $i) continue;
                    $dx = $bb['x'] - $a['x']; $dy = $bb['y'] - $a['y'];
                    $dist = sqrt($dx * $dx + $dy * $dy);
                    $min = $a['r'] + $bb['r'] + 1.5;
                    if ($dist < $min) {
                        if ($dist < 0.01) { $dx = 1; $dy = 0.3; $dist = 1.04; }
                        $push = ($min - $dist) / 2;
                        $ux = $dx / $dist; $uy = $dy / $dist;
                        $a['x'] -= $ux * $push; $a['y'] -= $uy * $push;
                        $bb['x'] += $ux * $push; $bb['y'] += $uy * $push;
                        $moved = true;
                    }
                }
                unset($bb);
            }
            unset($a);
            if (!$moved) break;
        }
        $lab = '';
        foreach ($pts as $p) {
            $x = max($p['r'] + 2, min($W - $p['r'] - 2, $p['x'])); $y = max($p['r'] + 2, min($H - $p['r'] - 2, $p['y']));
            if (hypot($x - $p['x0'], $y - $p['y0']) > 3) {
                $s .= '<line x1="' . round($p['x0'], 1) . '" y1="' . round($p['y0'], 1) . '" x2="' . round($x, 1) . '" y2="' . round($y, 1) . '" stroke="#000" stroke-width="0.6"/>'
                    . '<circle cx="' . round($p['x0'], 1) . '" cy="' . round($p['y0'], 1) . '" r="1.6" fill="#000"/>';
            }
            $lab .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="' . round($p['r'], 1) . '" fill="#fff" stroke="#000" stroke-width="0.9"/>'
                . '<text x="' . round($x, 1) . '" y="' . round($y + 3.2, 1) . '" text-anchor="middle" font-size="9" font-weight="bold" fill="#000">' . (int) $p['n'] . '</text>';
            if ($names) {
                $lab .= '<text x="' . round($x, 1) . '" y="' . round($y + $p['r'] + 6.5, 1) . '" text-anchor="middle" font-size="5.6" fill="#222" stroke="#fff" stroke-width="1.6" paint-order="stroke">' . htmlspecialchars($p['name'] ?? $reg[$p['k']][0], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text>';
            }
        }
        return $s . $lab;
    }

    public function hasGeo($c) { return $this->geo($c) !== null; }
}

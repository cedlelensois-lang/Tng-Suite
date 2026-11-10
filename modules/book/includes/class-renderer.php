<?php
if (!defined('ABSPATH')) exit;

/**
 * Produit le livre en HTML à partir des données TNG, par gabarits de phrases
 * (accords de genre, dates partielles, langue au choix). Aucune IA.
 */
class TNGBook_Renderer {
    private $d, $L, $lang, $o, $mode;
    use TNGBook_StatsView;

    private $Ls = [], $tpl = [], $names = [], $places = [], $srcNum = [], $srcOrder = [], $toc = [], $av = [], $dbg = [];

    /** $mode : 'html' (images par URL) ou 'pdf' (images intégrées en base64) */
    public function __construct($d, $lang, array $o, $mode) {
        $all = require TNGBOOK_DIR . 'includes/lang.php';
        $this->lang = isset($all[$lang]) ? $lang : 'fr';
        $this->o = $o;
        $this->L = $all[$this->lang];
        $this->Ls = $this->L;                                   // formes courtes (conjoints, tableaux)
        if (!empty($this->o['verbose']) && !empty($this->L['vb'])) {   // option « phrases détaillées »
            foreach (['born', 'bapt', 'died', 'buried', 'cremated', 'resided', 'resides'] as $k) $this->L[$k] = $this->L['vb'][$k];
        }
        $this->d = $d;
        $this->o = $o;
        $this->mode = $mode;
        $t = self::templates();
        $id = (string) ($o['template'] ?? 'classic');
        $this->tpl = $t[isset($t[$id]) ? $id : 'classic'];
    }

    /**
     * Modèles de mise en page. « classic » est le modèle d'origine (aucune modification de son rendu).
     * font : police CSS ; docfont : police Word/ODT ; cols : texte sur 2 colonnes ; banner : bandeau de génération ;
     * narr : phrases enrichies (âge des parents, autres informations, couples) ; center : titres centrés ; sz : corps (pt).
     */
    public static function templates() {
        return [
            'classic' => ['docfont' => 'Georgia'],
            'recit'   => ['font' => '"EB Garamond", Garamond, "Times New Roman", "DejaVu Serif", serif', 'docfont' => 'Garamond', 'cols' => 1, 'banner' => 1, 'narr' => 1, 'sz' => 11],
            'compact' => ['font' => 'Cambria, Georgia, "DejaVu Serif", serif', 'docfont' => 'Cambria', 'cols' => 1, 'sz' => 9.5],
            'elegant' => ['font' => '"Palatino Linotype", Palatino, "Book Antiqua", "DejaVu Serif", serif', 'docfont' => 'Palatino Linotype', 'center' => 1, 'sz' => 11.5],
            'modern'  => ['font' => 'Calibri, Arial, "DejaVu Sans", sans-serif', 'docfont' => 'Calibri', 'banner' => 1, 'solid' => 1, 'sz' => 10.5],
        ];
    }

    /** Teinte claire d'une couleur (mélange avec du blanc), pour les bandeaux. */
    public static function tint($hex, $ratio = 0.85) {
        $hex = ltrim((string) $hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return '#eee6d4';
        $o = '#';
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2));
            $o .= str_pad(dechex((int) round($v + (255 - $v) * $ratio)), 2, '0', STR_PAD_LEFT);
        }
        return $o;
    }

    /** Caractéristiques du modèle pour l'export Word/ODT. */
    public function docSpec() {
        $c = $this->colors();
        return $this->tpl + ['band' => self::tint($c['head'], 0.82)];
    }

    /* ---------- Petits utilitaires ---------- */

    private function e($s) { return esc_html((string) $s); }

    private static function cap($s) {
        return $s === '' ? $s : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function pd($s) {
        if (!$s || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $s, $m)) return null;
        $y = (int) $m[1];
        return $y > 0 ? [$y, (int) $m[2], (int) $m[3]] : null;
    }

    private function sx($s) {
        $s = strtoupper(trim((string) $s));
        return ($s === 'M' || $s === 'F') ? $s : 'U';
    }

    private function link($ref) {
        return '<a class="ref" href="#' . esc_attr($ref) . '">' . $this->e($ref) . '</a>';
    }

    private function refAny($pid, $prefer) {
        $a = $this->d->sosaOf[$pid] ?? null;
        $dd = $this->d->desc[$pid]['num'] ?? null;
        if ($prefer === 'A') {
            if ($a) return 'A' . $a;
            if ($dd) return 'D' . $dd;
        } else {
            if ($dd) return 'D' . $dd;
            if ($a) return 'A' . $a;
        }
        return null;
    }

    /* ---------- Noms, dates, lieux ---------- */

    private function plain($pid) {
        $p = $this->d->people[$pid] ?? null;
        if (!$p) return $this->L['unknown'];
        $first = trim($p['firstname']);
        if (strpos($first, '"') !== false) {   // surnom écrit entre guillemets droits dans le prénom : guillemets de la langue du livre
            $q = $this->L['qt'] ?? ['“', '”'];
            $sp = $this->lang === 'fr' ? "\xC2\xA0" : '';
            $first = preg_replace('/"\s*([^"]+?)\s*"/u', $q[0] . $sp . '$1' . $sp . $q[1], $first);
        }
        $nick = $this->nick($p);
        if ($nick !== '') $first = trim($first . ' (' . $nick . ')');
        $last = mb_strtoupper(trim(trim($p['lnprefix']) . ' ' . trim($p['lastname'])));
        $n = trim($first . ' ' . $last);
        return $n !== '' ? $n : $this->L['unknown'];
    }

    /** Surnom TNG, sans guillemets ni parenthèses d'origine. */
    private function nick(array $p) {
        $n = trim((string) ($p['nickname'] ?? ''), " \t\n\r\"'“”«»()");
        if ($n === '') return '';
        // Un « surnom » qui n'est qu'un des prénoms (ou le prénom entier) n'est pas un surnom : on l'ignore.
        $norm = function ($x) { return preg_replace('/[^a-z0-9]+/', ' ', strtolower(remove_accents($x))); };
        $nn = trim($norm($n));
        $ff = trim($norm((string) $p['firstname']));
        if ($nn === '' || $nn === $ff || in_array($nn, preg_split('/\s+/', $ff), true)) return '';
        return $n;
    }

    private function years($pid) {
        $p = $this->d->people[$pid] ?? null;
        if (!$p || $this->d->hidden($pid)) return '';
        $b = self::pd($p['birthdatetr']);
        $dd = self::pd($p['deathdatetr']);
        if (!$b && !$dd) return '';
        return '(' . ($b ? $b[0] : '') . '–' . ($dd ? $dd[0] : '') . ')';
    }

    /**
     * Date d'un événement : une date GEDCOM simple est mise en forme ; tout le reste (période « 1939-1945 »,
     * texte libre…) est conservé tel quel, sans réduction à une année.
     */
    private function evDate($tr, $txt) {
        $raw = trim((string) $txt);
        if (preg_match('/^y$/i', $raw)) return '';   // « Y » TNG = fait établi sans date
        if ($raw === '') return $this->dt($tr, $txt);
        $simple = '/^(?:(?:ABT|ABOUT|CIRCA|CA|EST|CAL|BEF|BEFORE|AFT|AFTER)\s+)?(?:\d{1,2}\s+)?(?:[A-Za-z]{3,9}\.?\s+)?\d{3,4}$/i';
        if (preg_match($simple, $raw)) {
            $s = $this->dt($tr, $raw);
            if ($s !== '') return $s;
        }
        return $raw;
    }

    /** Notes d'événements (texte conservé tel quel, balises simples autorisées). */
    private function evNotes($id, array $keys) {
        if (empty($this->o['notes'])) return '';
        $allowed = ['br' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => []];
        $h = '';
        foreach ($keys as $k) {
            foreach ($this->d->evnotes[$id][strtoupper((string) $k)] ?? [] as $t) {
                $t = wp_kses($t, $allowed);
                if (stripos($t, '<p') === false && stripos($t, '<br') === false) $t = nl2br(trim($t));
                $h .= '<div class="evnote">' . $t . '</div>';
            }
        }
        return $h;
    }

    /** Notes d'événement d'une famille : rien si l'un des conjoints est masqué. */
    private function famEvNotes($fid, array $keys) {
        $f = $this->d->fams[$fid] ?? null;
        if (!$f) return '';
        foreach (['husband', 'wife'] as $r) {
            if ($f[$r] !== '' && isset($this->d->people[$f[$r]]) && $this->d->hidden($f[$r])) return '';
        }
        return $this->evNotes($fid, $keys);
    }

    /** Phrase de date localisée (texte brut), avec qualificatifs vers/avant/après. */
    private function dt($tr, $txt) {
        $p = self::pd($tr);
        if (!$p) return '';
        list($y, $m, $d) = $p;
        $L = $this->L;
        $q = '';
        if (preg_match('/^\s*(ABT|ABOUT|CIRCA|CA|EST|CAL|BEF|BEFORE|AFT|AFTER)\b/i', (string) $txt, $mm)) {
            $k = strtoupper($mm[1]);
            $q = in_array($k, ['BEF', 'BEFORE'], true) ? 'bef' : (in_array($k, ['AFT', 'AFTER'], true) ? 'aft' : 'abt');
        }
        $mon = $m >= 1 && $m <= 12;
        $vars = [
            '{d}' => ($d && $this->lang === 'fr' && $d === 1) ? '1er' : (string) $d,
            '{m}' => $mon ? $L['months'][$m - 1] : '',
            '{md}' => $mon ? $L['months_de'][$m - 1] : '',
            '{y}' => (string) $y,
        ];
        $k = ($mon && $d) ? 'full' : ($mon ? 'my' : 'y');
        if ($q) return $L['q'][$q] . ' ' . strtr($L['b_' . $k], $vars);
        return strtr($L['d_' . $k], $vars);
    }

    private function placeShort($place) {
        $n = (int) $this->o['place_parts'];
        $cty = !empty($this->o['country']); $reg = !empty($this->o['regions']);
        if (!$cty && !$reg) {
            if ($n <= 0) return trim($place);
            $parts = array_values(array_filter(array_map('trim', explode(',', $place)), function ($x) { return !preg_match('/^\d{4,5}$/', $x); }));   // un code postal seul n'est pas un niveau de lieu
            return implode(', ', array_slice($parts, 0, $n));
        }
        $a = TNGBook_Country::analyse($place);
        $rest = $cty ? $a['rest'] : array_values(array_filter(array_map('trim', explode(',', (string) $place)), 'strlen'));
        $rest = array_values(array_filter($rest, function ($x) { return !preg_match('/^\d{4,5}$/', $x); }));
        if ($n > 0) $rest = array_slice($rest, 0, $n);
        if ($reg) {   // régions, États, provinces dans la langue du livre
            foreach ($rest as $i => $seg) {
                $t = TNGBook_Country::region($seg, $this->lang, $a['code']);
                if ($t !== null) $rest[$i] = $t;
            }
        }
        $txt = implode(', ', $rest);
        if (!$cty) return $txt !== '' ? $txt : trim($place);
        $name = $a['code'] ? TNGBook_Country::name($a['code'], $this->lang) : '';
        if ($name === '') return $txt !== '' ? $txt : trim($place);
        if (!$rest) return $name;                                   // le lieu est un pays seul
        if (mb_stripos($txt, $name) !== false) return $txt;         // déjà écrit : pas de doublon
        return $txt . ' (' . $name . ')';
    }

    /** « à Lieu » (HTML) et enregistrement à l'index des lieux. */
    private function pl($place, $ref) {
        $place = trim((string) $place);
        if ($place === '') return '';
        $s = $this->placeShort($place);
        $this->places[$s][$ref] = true;
        return $this->e($this->L['in'] . ' ' . $s);
    }

    private function addName($pid, $ref) {
        $p = $this->d->people[$pid] ?? null;
        if (!$p || $this->d->hidden($pid)) return;
        $last = trim(trim($p['lnprefix']) . ' ' . trim($p['lastname']));
        $first = trim($p['firstname']);
        $nick = $this->nick($p);
        $key = remove_accents(mb_strtolower($last . ' ' . $first));
        if (!isset($this->names[$key])) {
            $this->names[$key] = ['disp' => trim(mb_strtoupper($last) . ' ' . $first . ($nick !== '' ? ' (' . $nick . ')' : '')), 'refs' => []];
        }
        $this->names[$key]['refs'][$ref] = true;
    }

    /* ---------- Parents ---------- */

    private function of(array $it) {
        $L = $this->L;
        if ($L['elide'] && preg_match('/^[aeiouyhàâäéèêëîïôöùûüáíóúòÀÂÉÈÍÓÚ]/iu', $it[0])) return 'd’' . $it[1];
        return $L['of'] . ' ' . $it[1];
    }

    /** Âge d'un parent à la naissance de son enfant (« âgé de 32 ans »), vide si les dates ne le permettent pas. */
    private function ageAt($par, $kid) {
        $a = $this->d->people[$par] ?? null;
        $b = $this->d->people[$kid] ?? null;
        if (!$a || !$b) return '';
        $pb = self::pd($a['birthdatetr']);
        $cb = self::pd($b['birthdatetr']);
        if (!$pb || !$cb) return '';
        $sx = $this->sx($a['sex']);
        if ($sx === 'U') $sx = 'M';
        $full = $pb[1] && $pb[2] && $cb[1] && $cb[2];
        $n = $cb[0] - $pb[0];
        if ($full && ($cb[1] < $pb[1] || ($cb[1] === $pb[1] && $cb[2] < $pb[2]))) $n--;
        if ($n < 11 || $n > 90) return '';
        $L = $this->L;
        return str_replace(['{n}', '{y}'], [(string) $n, $L['tpl_ys'][$n === 1 ? 0 : 1]], $L[$full ? 'tpl_aged' : 'tpl_aged_abt'][$sx]);
    }

    /** Âge (en années) d'une personne à une date de mariage : [n, exact] ou null. */
    private function ageOn($pid, array $m) {
        $pp = $this->d->people[$pid] ?? null;
        if (!$pp) return null;
        $b = self::pd($pp['birthdatetr']);
        if (!$b) return null;
        $full = $b[1] && $b[2] && $m[1] && $m[2];
        $n = $m[0] - $b[0];
        if ($full && ($m[1] < $b[1] || ($m[1] === $b[1] && $m[2] < $b[2]))) $n--;
        if ($n < 10 || $n > 90) return null;
        return [$n, $full];
    }

    /** « (il avait 27 ans et elle 24 ans) » : âges des deux époux au mariage, si les dates de naissance le permettent. */
    /** Âges aux noces : ['self' => « à l'âge de 26 ans », 'sp' => « âgée de 26 ans lors de leur union », 'both' => « tous deux âgés de … »] ; clés absentes si l'âge est inconnu. */
    private function weddingParts($pid, $sp, $sex, $f) {
        $d = $this->d;
        $m = self::pd($f['marrdatetr'] ?? '');
        if (!$m || !isset($this->L['wed_self'])) return [];
        $L = $this->L;
        $spSex = $sp !== '' && isset($d->people[$sp]) ? $this->sx($d->people[$sp]['sex']) : 'U';
        $ys = function ($n) use ($L) { return $L['tpl_ys'][$n === 1 ? 0 : 1]; };
        $ok = function ($x) use ($d) { return $x !== '' && isset($d->people[$x]) && !$d->hidden($x) && !$d->dropped($x); };
        $a = $ok($pid) ? $this->ageOn($pid, $m) : null;
        $b = $ok($sp) ? $this->ageOn($sp, $m) : null;
        $o = [];
        if ($a && $b && $a[0] === $b[0] && $a[1] && $b[1]) {
            $o['both'] = str_replace(['{n}', '{y}'], [(string) $a[0], $ys($a[0])], $L['wed_both'][($sex === 'F' && $spSex === 'F') ? 'F' : 'M']);
            return $o;
        }
        if ($a) $o['self'] = str_replace(['{n}', '{y}'], [(string) $a[0], $ys($a[0])], $L[$a[1] ? 'wed_self' : 'wed_self_abt']);
        if ($b) $o['sp'] = str_replace(['{n}', '{y}'], [(string) $b[0], $ys($b[0])], $L[$b[1] ? 'wed_sp' : 'wed_sp_abt'][$spSex]);
        return $o;
    }

    private function weddingAges($pid, $sp, $sex, $f) {
        $d = $this->d;
        $m = self::pd($f['marrdatetr'] ?? '');
        if (!$m) return '';
        $L = $this->L;
        $cl = [];
        $spSex = $sp !== '' && isset($d->people[$sp]) ? $this->sx($d->people[$sp]['sex']) : 'U';
        $same = $sex !== 'U' && $sex === $spSex;   // union de même sexe : « il avait 30 ans et Auguste 32 ans » (évite « il … et il … »)
        foreach ([[$pid, $sex], [$sp, $spSex]] as $i => $x) {
            if ($x[0] === '' || !isset($d->people[$x[0]]) || $d->hidden($x[0]) || $d->dropped($x[0])) return '';
            $a = $this->ageOn($x[0], $m);
            if (!$a) return '';
            $tpl = ($same && $i === 1) ? $L[$a[1] ? 'wed_nm' : 'wed_nm_abt'] : $L[$a[1] ? 'wed_age' : 'wed_abt'][$x[1]];
            $cl[] = str_replace(['{n}', '{y}', '{name}'], [(string) $a[0], $L['tpl_ys'][$a[0] === 1 ? 0 : 1], trim((string) $d->people[$x[0]]['firstname']) !== '' ? trim((string) $d->people[$x[0]]['firstname']) : $this->plain($x[0])], $tpl);
        }
        return ' (' . $this->e($cl[0] . ' ' . $L['and'] . ' ' . $cl[1]) . ')';
    }

    /** Frères et sœurs (même famille d'origine) : « Frères et sœurs : Marie (1850–1920) (D12), … ». */
    private function siblingsPara($pid, $prefer) {
        if (empty($this->o['sibs'])) return '';
        $d = $this->d;
        $fid = $d->parentFam[$pid] ?? null;
        if (!$fid || empty($d->kidsOf[$fid])) return '';
        $li = [];
        foreach ($d->kidsOf[$fid] as $k) {
            if ($k === $pid || !isset($d->people[$k]) || $d->dropped($k)) continue;
            if ($d->hidden($k)) { $li[] = $this->e($this->L['living']); continue; }
            $t = $this->e($this->plain($k));
            $yr = $this->years($k);
            if ($yr) $t .= ' ' . $this->e($yr);
            $rr = $this->refAny($k, $prefer);
            if ($rr) $t .= ' (' . $this->link($rr) . ')';
            $li[] = $t;
        }
        if (!$li) return '';
        $L = $this->L;
        return '<p class="sibs">' . $this->e($L['sibs'][count($li) > 1 ? 1 : 0] . $L['colon']) . implode(', ', $li) . '.</p>';
    }

    /** Durée d'une union (« 41 ans et 5 mois ») jusqu'au premier décès ; vide si les dates complètes manquent ou en cas de divorce. */
    private function unionLength($fid) {
        $d = $this->d;
        $f = $d->fams[$fid] ?? null;
        if (!$f || trim((string) ($f['divdate'] ?? '')) !== '') return '';
        $m = self::pd($f['marrdatetr'] ?? '');
        if (!$m || !$m[1] || !$m[2]) return '';
        $best = null;
        foreach (['husband', 'wife'] as $r) {
            $x = $f[$r];
            if ($x === '' || !isset($d->people[$x])) continue;
            $e = self::pd($d->people[$x]['deathdatetr']);
            if ($e && $e[1] && $e[2] && ($best === null || $e < $best)) $best = $e;
        }
        if (!$best) return '';
        $t = ($best[0] - $m[0]) * 12 + ($best[1] - $m[1]) - ($best[2] < $m[2] ? 1 : 0);
        if ($t < 1) return '';
        $L = $this->L;
        $y = intdiv($t, 12);
        $mo = $t % 12;
        $parts = [];
        if ($y > 0) $parts[] = $y . ' ' . $L['tpl_ys'][$y === 1 ? 0 : 1];
        if ($mo > 0) $parts[] = $mo . ' ' . $L['tpl_mo'][$mo === 1 ? 0 : 1];
        return implode(' ' . $L['and'] . ' ', $parts);
    }

    /** Prénoms des deux membres d'un couple (époux d'abord), pour « X et Y ont 2 enfants ». */
    private function coupleNames($fid) {
        $d = $this->d;
        $f = $d->fams[$fid] ?? null;
        $nm = [];
        if (!$f) return $nm;
        foreach (['husband', 'wife'] as $r) {
            $x = $f[$r];
            if ($x === '' || !isset($d->people[$x]) || $d->dropped($x)) continue;
            if ($d->hidden($x)) { $nm[] = $this->e($this->L['living']); continue; }
            $fn = trim((string) $d->people[$x]['firstname']);
            $nm[] = '<strong>' . $this->e($fn !== '' ? $fn : $this->plain($x)) . '</strong>';
        }
        return $nm;
    }

    /** Modèle « récit » : « X et Y ont N enfants : » suivi d'une puce par enfant (naissance, décès, lien vers la fiche). */
    private function narrKids($fid, $ref, $prefer) {
        $d = $this->d;
        $L = $this->L;
        $kids = [];
        foreach ($d->kidsOf[$fid] ?? [] as $k) {
            if (isset($d->people[$k]) && !$d->dropped($k)) $kids[] = $k;
        }
        if (!$kids) return '';
        $nm = $this->coupleNames($fid);
        $n = count($kids);
        $lead = $nm
            ? implode(' ' . $L['tpl_and'] . ' ', $nm) . ' ' . $L['tpl_have'][count($nm) > 1 ? 1 : 0] . ' ' . $n . ' ' . $L['tpl_child'][$n === 1 ? 0 : 1]
            : $L['children'];
        $li = [];
        foreach ($kids as $k) {
            if ($d->hidden($k)) { $li[] = '<li>' . $this->e($L['living']) . '</li>'; continue; }
            $kref = null;
            if ($prefer === 'D') {
                $num = $d->childNum[$fid . '|' . $k] ?? null;
                if ($num && isset($d->desc[$k]) && $d->desc[$k]['num'] === $num) $kref = 'D' . $num;
            } elseif (isset($d->sosaOf[$k])) {
                $kref = 'A' . $d->sosaOf[$k];
            }
            $plain = $this->plain($k);
            $name = $kref ? '<a class="ref nm" href="#' . esc_attr($kref) . '">' . $this->e($plain) . '</a>' : $this->e($plain);
            $bio = $this->bioShort($k, $ref);
            $li[] = '<li>' . $name . ($bio !== '' ? ', ' . $bio : '') . '.</li>';
        }
        return '<p class="kidshead">' . $lead . $this->e(preg_replace('/^ /u', "\u{00A0}", rtrim($L['colon']))) . '</p><ul class="bul kids">' . implode('', $li) . '</ul>';
    }

    /** Modèle « récit » : couple d'ancêtres (« X et Y se sont mariés … Leur union a duré … ») puis leurs enfants. */
    private function coupleBlock($fid, $ref) {
        $d = $this->d;
        $L = $this->L;
        $f = $d->fams[$fid] ?? null;
        if (!$f) return '';
        $html = '';
        $nm = $this->coupleNames($fid);
        $married = trim((string) ($f['marrdate'] ?? '')) !== '';
        if ($married && count($nm) === 2) {
            $md = $this->dt($f['marrdatetr'], $f['marrdate']);
            $mp = $this->pl($f['marrplace'], $ref);
            $s = str_replace(['{a}', '{b}'], [$nm[0], $nm[1]], $L['tpl_wed']) . ($md !== '' ? ' ' . $this->e($md) : '') . ($mp !== '' ? ' ' . $mp : '') . '.';
            $len = $this->unionLength($fid);
            if ($len !== '') $s .= ' ' . $this->e(str_replace('{d}', $len, $L['tpl_lasted']));
            $html .= '<p class="couple">' . $s . '</p>';
        }
        return $html . $this->famEvNotes($fid, ['MARR']) . $this->famNotes($fid) . $this->narrKids($fid, $ref, 'A');
    }

    private function parentsPhrase($pid, $sex, $prefer) {
        $fid = $this->d->parentFam[$pid] ?? null;
        if (!$fid || !isset($this->d->fams[$fid])) return '';
        $f = $this->d->fams[$fid];
        $items = [];
        foreach (['husband', 'wife'] as $r) {
            $x = $f[$r];
            if ($x === '' || !isset($this->d->people[$x]) || $this->d->hidden($x)) continue;
            $plain = $this->plain($x);
            $ref = $this->refAny($x, $prefer);
            if (!empty($this->tpl['narr'])) {
                // modèle « récit » : le nom est le lien, suivi des années et de l'âge du parent à la naissance
                $h = $ref ? '<a class="ref nm" href="#' . esc_attr($ref) . '">' . $this->e($plain) . '</a>' : $this->e($plain);
                $yr = $this->years($x);
                if ($yr !== '') $h .= ' ' . $this->e($yr);
                $ag = $this->ageAt($x, $pid);
                if ($ag !== '') $h .= ', ' . $this->e($ag);
                $items[] = [$plain, $h];
                continue;
            }
            $items[] = [$plain, $this->e($plain) . ($ref ? ' (' . $this->link($ref) . ')' : '')];
        }
        if (!$items) return '';
        $L = $this->L;
        $out = $L['son'][$sex] . ' ' . $this->of($items[0]);
        if (isset($items[1])) {
            $and = $L['and'];
            if ($this->lang === 'es' && preg_match('/^h?i/iu', $items[1][0])) $and = 'e';
            $out .= (!empty($this->tpl['narr']) ? ',' : '') . ' ' . $and . ' ' . ($L['repeat_of'] ? $this->of($items[1]) : $items[1][1]);
        }
        return $out;
    }

    /* ---------- Photos ---------- */

    private function upload() {
        $up = wp_upload_dir();
        $dir = $up['basedir'] . '/tngbook-cache';
        wp_mkdir_p($dir . '/img');
        if (!file_exists($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');   // pas de listing du dossier
        return [$dir, $up['baseurl'] . '/tngbook-cache'];
    }

    private function asset($file, $url, $mime) {
        if ($this->mode === 'pdf' || $this->mode === 'doc') return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file));
        return $url;
    }

    private function dbg($msg) {
        if (!empty($this->o['debug']) && count($this->dbg) < 60) $this->dbg[] = $msg;
    }

    /** Retrouve le fichier image sur le disque, le réduit et renvoie son URL (ou data URI en PDF). */
    private function cachedPhoto(array $m, $who) {
        $o = $this->o;
        if (preg_match('#^https?://#i', (string) $m['path'])) {
            $this->dbg($who . ' : photo distante, utilisée telle quelle (HTML uniquement).');
            return ($this->mode === 'pdf' || $this->mode === 'doc') ? '' : (string) $m['path'];
        }
        if ($o['tng_path'] === '') {
            $this->dbg($who . ' : « Chemin serveur de TNG » non renseigné dans les réglages.');
            return '';
        }
        $root = TNGBook::resolve_tng_path($o['tng_path']);
        if ($root === '') {
            $this->dbg($who . ' : le dossier TNG « ' . $o['tng_path'] . ' » est introuvable pour PHP (racine web : ' . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . ' ; WordPress : ' . ABSPATH . '). Saisissez l’URL de TNG ou son chemin absolu.');
            return '';
        }
        $clean = function ($s) { return trim(str_replace('\\', '/', (string) $s), '/'); };
        $pf = $clean($o['photo_folder']);
        $mt = $clean($this->d->photoDir);
        $dirs = !empty($m['usecollfolder']) && $m['usecollfolder'] !== '0' ? [$pf, $mt, ''] : ['', $pf, $mt];
        $dirs = array_values(array_unique($dirs));

        $rels = [];
        foreach (['path', 'thumbpath'] as $k) {
            $rel = $clean($m[$k] ?? '');
            if ($rel === '') continue;
            $rels[] = $rel;
            $dec = rawurldecode($rel);
            if ($dec !== $rel) $rels[] = $dec;
        }
        $tried = [];
        $file = '';
        foreach ($rels as $rel) {
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
            foreach ($dirs as $dir) {
                $cand = $root . ($dir !== '' ? '/' . $dir : '') . '/' . $rel;
                $tried[] = $cand;
                $real = realpath($cand);
                if ($real && strpos(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', (string) realpath($root)), '/') . '/') === 0 && is_file($real)) { $file = $real; break 2; }
            }
        }
        if ($file === '') {
            $this->dbg($who . ' : fichier introuvable. Chemins essayés : ' . implode(' | ', array_slice($tried, 0, 6)));
            return '';
        }

        list($dir, $url) = $this->upload();
        $base = md5($file . filemtime($file));
        $dest = $dir . '/img/' . $base . '.jpg';
        $name = $base . '.jpg';
        $mime = 'image/jpeg';
        if (!file_exists($dest)) {
            $done = false;
            $ed = wp_get_image_editor($file);
            if (!is_wp_error($ed)) {
                $ed->resize(360, 480, false);
                $ed->set_quality(82);
                $res = $ed->save($dest, 'image/jpeg');
                $done = !is_wp_error($res);
            }
            if (!$done) {                         // GD/Imagick indisponible : copie brute
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $name = $base . '.' . $ext;
                $dest = $dir . '/img/' . $name;
                $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));
                if (!file_exists($dest) && !@copy($file, $dest)) {
                    $this->dbg($who . ' : fichier trouvé (' . $file . ') mais copie dans uploads impossible (droits d’écriture ?).');
                    return '';
                }
            }
        }
        $this->dbg($who . ' : photo trouvée → ' . $file);
        return $this->asset($dest, $url . '/img/' . $name, $mime);
    }

    /** Silhouette générique homme / femme / inconnu (PNG généré avec GD, sinon SVG). */
    private function avatar($s) {
        if (isset($this->av[$s])) return $this->av[$s];
        $f = TNGBOOK_DIR . 'assets/img/' . ($s === 'M' ? 'male' : ($s === 'F' ? 'female' : 'unknown')) . '.jpg';
        if (file_exists($f)) {
            return $this->av[$s] = $this->asset($f, TNGBOOK_URL . 'assets/img/' . basename($f), 'image/jpeg');
        }
        list($dir, $url) = $this->upload();
        $dest = $dir . '/avatar-' . $s . '.png';
        $pal = [
            'M' => [[219, 231, 243], [138, 164, 191], null],
            'F' => [[246, 221, 230], [194, 138, 163], [168, 112, 138]],
            'U' => [[232, 232, 232], [170, 170, 170], null],
        ][$s];
        if (!file_exists($dest) && function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(240, 300);
            $bg = imagecolorallocate($im, $pal[0][0], $pal[0][1], $pal[0][2]);
            $fg = imagecolorallocate($im, $pal[1][0], $pal[1][1], $pal[1][2]);
            imagefill($im, 0, 0, $bg);
            if ($pal[2]) {
                $hair = imagecolorallocate($im, $pal[2][0], $pal[2][1], $pal[2][2]);
                imagefilledellipse($im, 120, 125, 150, 175, $hair);
            }
            imagefilledellipse($im, 120, 118, 100, 115, $fg);
            imagefilledellipse($im, 120, 330, 220, 250, $fg);
            imagepng($im, $dest);
            if (PHP_VERSION_ID < 80000) imagedestroy($im);   // sans effet depuis PHP 8.0, obsolète en 8.5
        }
        if (file_exists($dest)) {
            $this->av[$s] = $this->asset($dest, $url . '/avatar-' . $s . '.png', 'image/png');
        } else {
            $svg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 300"><rect width="240" height="300" fill="rgb(%s)"/><circle cx="120" cy="118" r="52" fill="rgb(%s)"/><ellipse cx="120" cy="330" rx="110" ry="125" fill="rgb(%s)"/></svg>',
                implode(',', $pal[0]), implode(',', $pal[1]), implode(',', $pal[1])
            );
            $this->av[$s] = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
        return $this->av[$s];
    }

    private function photoTag($pid, $sex, $hidden) {
        $src = '';
        $who = $this->plain($pid) . ' (' . $pid . ')';
        if ($this->o['photos']) {
            if ($hidden) {
                $this->dbg($who . ' : photo ignorée car personne vivante/privée en mode « anonymiser ».');
            } elseif (empty($this->d->photos[$pid])) {
                $this->dbg($who . ' : aucun lien média de type image trouvé dans TNG pour cette personne.');
            } else {
                $src = $this->cachedPhoto($this->d->photos[$pid], $who);
            }
        }
        if ($src === '' && $this->o['generic']) $src = $this->avatar($sex);
        return $src === '' ? '' : '<img class="ph" src="' . esc_attr($src) . '" alt="">';
    }

    /* ---------- Fiche d'une personne ---------- */

    private function sourceNums($pid) {
        if (!$this->o['sources'] || empty($this->d->cites[$pid])) return '';
        $nums = [];
        foreach ($this->d->cites[$pid] as $sid) {
            if (!isset($this->d->sources[$sid])) continue;
            if (!isset($this->srcNum[$sid])) {
                $this->srcNum[$sid] = count($this->srcNum) + 1;
                $this->srcOrder[] = $sid;
            }
            $nums[] = $this->srcNum[$sid];
        }
        if (!$nums) return '';
        sort($nums);
        return '<p class="src">' . $this->e($this->L['sources'] . $this->L['colon']) . implode(', ', array_map(function ($n) { return '[' . $n . ']'; }, $nums)) . '</p>';
    }

    private static function lc1($s) {
        return $s === '' ? $s : mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Résidences, du plus ancien au plus récent (option « Lieux de résidence »), en une phrase : « Après avoir résidé successivement à A (Espagne), B…, elle s'est établie à Z (France), où elle réside actuellement. » */
    private function resiSentence($pid, $sex, $ref) {
        if (empty($this->o['resi'])) return '';
        $list = $this->d->resiEv[$pid] ?? [];
        if (!$list) return '';
        $L = $this->L;
        $seen = []; $items = [];
        foreach ($list as $r) {
            $s = $this->placeShort($r[0]);
            $k = TNGBook_Map::norm($s);
            if ($s === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $this->places[$s][$ref] = true;
            // séparer « Ville, Dép. » du pays entre parenthèses, que l'on n'écrit que lorsqu'il change
            $base = $s; $cty = '';
            if (!empty($this->o['country'])) {
                $a = TNGBook_Country::analyse($r[0]);
                $nm = $a['code'] ? TNGBook_Country::name($a['code'], $this->lang) : '';
                if ($nm !== '' && substr($s, -strlen($nm) - 3) === ' (' . $nm . ')') { $base = substr($s, 0, -strlen($nm) - 3); $cty = $nm; }
            }
            $items[] = [$base, $cty];
        }
        if (!$items) return '';
        $n = count($items);
        $prev = null; $txt = [];
        foreach ($items as $i => $it) {
            $show = $it[1] !== '' && ($i === 0 || $i === $n - 1 || $it[1] !== $prev);
            $txt[] = $this->e($it[0]) . ($show ? ' (' . $this->e($it[1]) . ')' : '');
            if ($it[1] !== '') $prev = $it[1];
        }
        $now = !empty($this->d->people[$pid]['living']) && (int) $this->d->people[$pid]['living'] === 1;   // personne vivante : présent
        if ($n === 1) return $this->e($L[$now ? 'resides' : 'resided'][$sex]) . ' ' . $txt[0] . '.';
        $last = array_pop($txt);
        $sep = strpos(implode('', $txt), ',') !== false ? '; ' : ', ';
        $and = ' ' . $L['and'] . ' ';
        $head = count($txt) > 1 ? implode($sep, array_slice($txt, 0, -1)) . $and . end($txt) : $txt[0];
        $rs = $L['res_seq'];
        $tpl = $now ? $rs['now'][$sex] : (!empty($this->o['verbose']) ? $rs['past'][$sex] : $rs['past_s']);
        $tpl = str_replace('{succ}', count($txt) > 1 ? $rs['succ'] : '', $tpl);
        return str_replace(['{list}', '{last}'], [$head, $last], $tpl);
    }

    /** « Baptisé le … à … » (option « Baptême »), sinon chaîne vide. */
    private function baptBit($p, $sex, $ref, $short = false) {
        if (empty($this->o['bapt'])) return '';
        // TNG range le baptême « chrétien » dans la naissance alternative (type CHR / BAPM / CHRA) ; la date de baptême propre (champ SDJ) sert de repli.
        $d = $pl = '';
        if (in_array(strtoupper(trim((string) ($p['altbirthtype'] ?? ''))), ['CHR', 'BAPM', 'CHRA'], true)) {
            $d = $this->dt($p['altbirthdatetr'] ?? '', $p['altbirthdate'] ?? '');
            $pl = $this->pl($p['altbirthplace'] ?? '', $ref);
        }
        if ($d === '' && $pl === '') {
            $d = $this->dt($p['baptdatetr'] ?? '', $p['baptdate'] ?? '');
            $pl = $this->pl($p['baptplace'] ?? '', $ref);
        }
        if ($d === '' && $pl === '') return '';
        return $this->e(($short ? $this->Ls : $this->L)['bapt'][$sex] . ($d !== '' ? ' ' . $d : '')) . ($pl !== '' ? ' ' . $pl : '');
    }

    /** « Inhumé le … à … » ou « Incinéré le … » (option « Sépulture »), sinon chaîne vide. */
    private function burialBit($p, $sex, $ref, $short = false) {
        if (empty($this->o['burial'])) return '';
        $cr = !empty($p['burialtype']) && (int) $p['burialtype'] === 1;
        $d = $this->dt($p['burialdatetr'] ?? '', $p['burialdate'] ?? '');
        $pl = $this->pl($p['burialplace'] ?? '', $ref);
        if ($d === '' && $pl === '' && !$cr) return '';
        return $this->e(($short ? $this->Ls : $this->L)[$cr ? 'cremated' : 'buried'][$sex] . ($d !== '' ? ' ' . $d : '')) . ($pl !== '' ? ' ' . $pl : '');
    }

    /** « né le 12 mars 1855 à Bergues, décédé le … » (dates complètes), pour un conjoint. */
    private function bioShort($pid, $ref, $and = false) {
        $p = $this->d->people[$pid] ?? null;
        if (!$p) return '';
        $L = $this->Ls;
        $sex = $this->sx($p['sex']);
        $bits = [];
        $bd = $this->dt($p['birthdatetr'], $p['birthdate']);
        $bp = $this->pl($p['birthplace'], $ref);
        if ($bd !== '' || $bp !== '') $bits[] = $this->e(self::lc1($L['born'][$sex] . ($bd !== '' ? ' ' . $bd : ''))) . ($bp !== '' ? ' ' . $bp : '');
        $bb = $this->baptBit($p, $sex, $ref, true);
        if ($bb !== '') $bits[] = self::lc1($bb);
        $dd = $this->dt($p['deathdatetr'], $p['deathdate']);
        $dp = $this->pl($p['deathplace'], $ref);
        if ($dd !== '' || $dp !== '') $bits[] = $this->e(self::lc1($L['died'][$sex] . ($dd !== '' ? ' ' . $dd : ''))) . ($dp !== '' ? ' ' . $dp : '');
        $ub = $this->burialBit($p, $sex, $ref, true);
        if ($ub !== '') $bits[] = self::lc1($ub);
        if ($and && count($bits) > 1) {   // « née le …, baptisée …, et décédée le … »
            $last = array_pop($bits);
            return implode(', ', $bits) . ' ' . $this->e($this->L['and']) . ' ' . $last;
        }
        return implode(', ', $bits);
    }

    /** Notes TNG d'une personne ou d'une famille. */
    private function notesHtml($id) {
        if (empty($this->o['notes']) || empty($this->d->notes[$id])) return '';
        $allowed = ['br' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => []];
        $h = '<div class="notes"><p class="nh">' . $this->e($this->L['notes']) . '</p>';
        foreach ($this->d->notes[$id] as $t) {
            $t = wp_kses($t, $allowed);
            if (stripos($t, '<p') === false && stripos($t, '<br') === false) $t = nl2br(trim($t));
            $h .= '<div class="note">' . $t . '</div>';
        }
        return $h . '</div>';
    }

    private function famNotes($fid) {
        $f = $this->d->fams[$fid] ?? null;
        if (!$f) return '';
        foreach (['husband', 'wife'] as $r) {
            if ($f[$r] !== '' && isset($this->d->people[$f[$r]]) && $this->d->hidden($f[$r])) return '';
        }
        return $this->notesHtml($fid);
    }

    /**
     * Avant un remariage : comment l'union précédente s'est terminée (divorce, ou décès du conjoint),
     * avec la date précise quand elle est connue. Rien si la fin de l'union n'est pas documentée.
     */
    private function prevUnionClause($pid, $fid, $sex) {
        $d = $this->d;
        $L = $this->L;
        $list = $d->famsOf[$pid] ?? [];
        $i = array_search($fid, $list, true);
        if ($i === false || $i < 1) return '';
        $prev = $d->fams[$list[$i - 1]] ?? null;
        $cur = $d->fams[$fid] ?? null;
        if (!$prev || !$cur) return '';
        $sp = ($prev['husband'] === $pid) ? $prev['wife'] : $prev['husband'];
        if ($sp === '' || !isset($d->people[$sp]) || $d->dropped($sp)) return '';
        $hidden = $d->hidden($sp);
        $name = $hidden ? $this->e($L['living']) : '<strong>' . $this->e($this->plain($sp)) . '</strong>';

        // Union précédente mariée seulement si marrdate est renseignée : sinon « séparé », jamais « divorcé ».
        $prevMarried = trim((string) ($prev['marrdate'] ?? '')) !== '';

        // Fin de l'union : date précise si elle existe (« Y » seul = fin sans date).
        $div = trim((string) ($prev['divdate'] ?? ''));
        if ($div !== '') {
            $dd = strtoupper($div) === 'Y' ? '' : $this->dt($prev['divdatetr'] ?? '', $div);
            $word = $prevMarried ? $L['divorced'][$sex] : $L['separated'][$sex];
            return $this->e($word) . ' ' . $name . ($dd !== '' ? ' ' . $this->e($dd) : '');   // « Séparée de X en septembre 2013 » : la date suffit, sans répéter le verbe
        }

        // Décès du conjoint précédent (jamais pour une personne masquée).
        if ($hidden) return '';
        $sp_p = $d->people[$sp];
        $dd = $this->dt($sp_p['deathdatetr'], $sp_p['deathdate']);
        if ($dd === '') return '';
        $de = self::pd($sp_p['deathdatetr']);
        $mr = self::pd($cur['marrdatetr'] ?? '');
        if ($de && $mr && $de > $mr) return '';        // décès postérieur au remariage : incohérent, on n'affirme rien
        if (!$prevMarried) {                           // couple non marié : « Après le décès de X (le … ) », pas « veuf »
            return $this->e($L['after_death']) . ' ' . $name . ' (' . $this->e($dd) . ')';
        }
        $spSex = $this->sx($sp_p['sex']);
        $diedWord = function_exists('mb_strtolower') ? mb_strtolower($this->Ls['died'][$spSex], 'UTF-8') : strtolower($L['died'][$spSex]);
        return $this->e($L['widowed'][$sex]) . ' ' . $name . ' (' . $this->e($diedWord . ' ' . $dd) . ')';
    }

    /** Petite photo du conjoint, quand il n'a pas sa propre fiche dans le livre (conjoints des descendants, par exemple). */
    private function spPhoto($pid, $fid) {
        if (empty($this->o['photos']) || empty($this->o['spphotos'])) return '';
        $d = $this->d;
        $f = $d->fams[$fid] ?? null;
        if (!$f) return '';
        $sp = ($f['husband'] === $pid) ? $f['wife'] : $f['husband'];
        if ($sp === '' || !isset($d->people[$sp]) || $d->dropped($sp) || $d->hidden($sp)) return '';
        if (isset($d->sosaOf[$sp]) || isset($d->desc[$sp]) || empty($d->photos[$sp])) return '';
        $src = $this->cachedPhoto($d->photos[$sp], $this->plain($sp) . ' (' . $sp . ')');
        return $src === '' ? '' : '<img class="ph sp" src="' . esc_attr($src) . '" alt="">';
    }

    private function unionSentence($pid, $fid, $sex, $ref, $prefer) {
        $d = $this->d;
        $L = $this->L;
        $f = $d->fams[$fid] ?? null;
        if (!$f) return '';
        $sp = ($f['husband'] === $pid) ? $f['wife'] : $f['husband'];
        $spHtml = '';
        $spHead = ''; $spBio = ''; $spBioAnd = ''; $spPar = '';
        if ($sp !== '' && isset($d->people[$sp]) && !$d->dropped($sp)) {
            if ($d->hidden($sp)) {
                $spHtml = $this->e($L['living']);
                $spHead = $spHtml;
            } else {
                $plain = $this->plain($sp);
                $rr = $this->refAny($sp, $prefer);
                $spHead = '<strong>' . $this->e($plain) . '</strong>' . ($rr ? ' (' . $this->link($rr) . ')' : '');
                $spHtml = $spHead;
                $spBio = $this->bioShort($sp, $ref);
                $spBioAnd = $this->bioShort($sp, $ref, true);
                if ($spBio !== '') $spHtml .= ' (' . $spBio . ')';
                if ($prefer === 'D') {
                    $spPar = (string) $this->parentsPhrase($sp, $this->sx($d->people[$sp]['sex']), 'D');
                    if ($spPar !== '') $spHtml .= ', ' . $spPar;
                }
                $this->addName($sp, $ref);
            }
        }
        $md = $this->dt($f['marrdatetr'], $f['marrdate']);
        $mp = $this->pl($f['marrplace'], $ref);
        if ($spHtml === '' && $md === '' && $mp === '') return '';
        // Règle : marrdate renseignée (ni vide ni NULL) = mariage ; sinon le couple est « en couple » (jamais « épousa »).
        $married = trim((string) ($f['marrdate'] ?? '')) !== '';
        if ($married || !isset($L['partnered'])) {
            $verb = $L['married'][$sex];
        } else {
            $verb = $spHtml !== '' ? $L['partnered'][$sex] : $L['partnered0'][$sex];
        }
        $wa = ($married && !empty($this->o['marrage'])) ? $this->weddingAges($pid, $sp, $sex, $f) : '';
        if ($married && $spHtml !== '' && ($md !== '' || $mp !== '')) {
            // Date et lieu du mariage avant le conjoint : « Il épousa, le 8 décembre 1939 à Barcelone, Narcisa … »
            $prep = (string) ($L['married_prep'] ?? '');
            $v = $verb;
            if ($prep !== '' && substr($v, -strlen($prep) - 1) === ' ' . $prep) $v = substr($v, 0, -strlen($prep) - 1);
            // Âges : « à l'âge de 26 ans » avant la date ; « âgée de 26 ans lors de leur union » (ou « tous deux âgés … ») après le conjoint.
            $wp = !empty($this->o['marrage']) ? $this->weddingParts($pid, $sp, $sex, $f) : [];
            $selfAge = isset($wp['self']) ? ', ' . $this->e($wp['self']) : '';
            $tail = [];
            $ag = $wp['both'] ?? ($wp['sp'] ?? '');
            if ($ag !== '') $tail[] = $this->e($ag);
            if ($spPar !== '') $tail[] = $spPar;
            if ($spBioAnd !== '') $tail[] = $spBioAnd;
            return $this->e($v) . $selfAge . ',' . ($md !== '' ? ' ' . $this->e($md) : '') . ($mp !== '' ? ' ' . $mp : '') . ',' . ($prep !== '' ? ' ' . $this->e($prep) : '') . ' ' . $spHead . ($tail ? ', ' . implode(', ', $tail) : '') . '.';
        }
        return $this->e($verb) . ($spHtml !== '' ? ' ' . $spHtml : '') . ($md !== '' ? ' ' . $this->e($md) : '') . ($mp !== '' ? ' ' . $mp : '') . $wa . '.';
    }

    /** Fin de la dernière union (divorce ou séparation) : « Le couple se sépara en septembre 2013. » ; « Y » = fin sans date. Les unions antérieures sont rappelées dans la phrase de l'union suivante. */
    private function endUnion($pid, $fid) {
        $d = $this->d; $L = $this->L;
        $f = $d->fams[$fid] ?? null;
        if (!$f) return '';
        $div = trim((string) ($f['divdate'] ?? ''));
        if ($div === '') return '';
        $list = $d->famsOf[$pid] ?? [];
        if ($list && end($list) !== $fid) return '';
        $dd = strtoupper($div) === 'Y' ? '' : $this->dt($f['divdatetr'] ?? '', $div);
        $married = trim((string) ($f['marrdate'] ?? '')) !== '';
        return $this->e(str_replace('{d}', $dd !== '' ? ' ' . $dd : '', $L[$married ? 'union_div' : 'union_sep']));
    }

    private function childrenList($fid) {
        $d = $this->d;
        $L = $this->L;
        $li = [];
        foreach ($d->kidsOf[$fid] ?? [] as $kid) {
            $num = $d->childNum[$fid . '|' . $kid] ?? null;
            if (!$num || !isset($d->people[$kid])) continue;
            $ref = 'D' . $num;
            $hasFiche = isset($d->desc[$kid]) && $d->desc[$kid]['num'] === $num;
            $label = $hasFiche ? $this->link($ref) : '<span class="ref">' . $this->e($ref) . '</span>';
            if ($d->hidden($kid)) {
                $txt = $this->e($L['living']);
            } else {
                $txt = $this->e($this->plain($kid));
                $yr = $this->years($kid);
                if ($yr) $txt .= ' ' . $this->e($yr);
            }
            if (isset($d->dupDesc[$num])) $txt .= ' → ' . $this->e($L['see']) . ' ' . $this->link('D' . $d->dupDesc[$num]);
            $li[] = '<li>' . $label . ' ' . $txt . '</li>';
        }
        if (!$li) return '';
        return '<p class="kidshead">' . $this->e($L['children'] . $L['colon']) . '</p><ul class="kids">' . implode('', $li) . '</ul>';
    }

    /** $unions : liste d'identifiants de familles à décrire. */
    private function fiche($pid, $ref, $prefer, array $unions) {
        $d = $this->d;
        $L = $this->L;
        $p = $d->people[$pid] ?? null;
        if (!$p) return '';
        $sex = $this->sx($p['sex']);

        if ($d->hidden($pid)) {
            return '<div class="fiche anon" id="' . esc_attr($ref) . '">' . $this->photoTag($pid, $sex, true)
                . '<h4><span class="num">' . $this->e($ref) . '</span> ' . $this->e($L['living']) . '</h4></div>';
        }

        $narr = !empty($this->tpl['narr']);
        $this->addName($pid, $ref);
        $yr = $this->years($pid);
        $symbol = $sex === 'M' ? '♂' : ($sex === 'F' ? '♀' : '');
        $hasNotes = !empty($this->o['notes']) && !empty($d->notes[$pid]);
        $html = '<div class="fiche' . ($hasNotes ? ' hasnotes' : '') . '" id="' . esc_attr($ref) . '">' . $this->photoTag($pid, $sex, false);
        if ($narr) {
            $html .= '<h4><span class="num">' . $this->e($ref) . ' -</span> <span class="nm">' . $this->e($this->plain($pid)) . '</span>.</h4>';
        } else {
            $html .= '<h4><span class="num">' . $this->e($ref) . '</span> ' . $this->e($this->plain($pid))
                . ($symbol ? ' <span class="sx">' . $symbol . '</span>' : '') . ($yr ? ' <span class="yrs">' . $this->e($yr) . '</span>' : '') . '</h4>';
        }

        /* Mini-arbre d'ascendance (parents et grands-parents) en tête de la fiche d'un ancêtre */
        if (!empty($this->o['mini']) && $ref[0] === 'A') $html .= $this->miniTree((int) substr($ref, 1));

        /* Naissance et parents */
        $bits = [];
        $bd = $this->dt($p['birthdatetr'], $p['birthdate']);
        $bp = $this->pl($p['birthplace'], $ref);
        if ($bd !== '' || $bp !== '') $bits[] = $this->e($L['born'][$sex] . ($bd !== '' ? ' ' . $bd : '')) . ($bp !== '' ? ' ' . $bp : '');
        $par = $this->parentsPhrase($pid, $sex, $prefer);
        if ($narr) {
            // récit : une phrase pour la naissance, une pour la filiation
            if ($bits) $html .= '<p>' . self::cap($bits[0]) . '.</p>';
            $html .= $this->evNotes($pid, ['BIRT']);
            $bb = $this->baptBit($p, $sex, $ref);
            if ($bb !== '') $html .= '<p>' . $bb . '.</p>';
        } else {
            if ($par !== '') $bits[] = $par;
            if ($bits) {
                $first = implode(', ', $bits);
                $html .= '<p>' . (strpos($first, '<') === 0 ? $first : self::cap($first)) . '.</p>';
            }
            $html .= $this->evNotes($pid, ['BIRT']);
            $bb = $this->baptBit($p, $sex, $ref);
            if ($bb !== '') $html .= '<p>' . $bb . '.</p>';
        }

        /* Décès et inhumation (calculés ici : le modèle « récit » les place juste après la naissance) */
        $dd = $this->dt($p['deathdatetr'], $p['deathdate']);
        $dp = $this->pl($p['deathplace'], $ref);
        $out = [];
        if ($dd !== '' || $dp !== '') {
            $s = $this->e($L['died'][$sex] . ($dd !== '' ? ' ' . $dd : '')) . ($dp !== '' ? ' ' . $dp : '');
            $b = self::pd($p['birthdatetr']);
            $de = self::pd($p['deathdatetr']);
            if ($b && $de && $b[1] && $b[2] && $de[1] && $de[2]) {
                $age = $de[0] - $b[0];
                if ($de[1] < $b[1] || ($de[1] === $b[1] && $de[2] < $b[2])) $age--;
                if ($age >= 0 && $age <= 120) {
                    $s .= !empty($this->o['verbose']) && !empty($L['vb'])
                        ? $this->e(str_replace('{n}', (string) $age, $L['vb']['aged']))
                        : ' (' . $this->e(str_replace('{n}', (string) $age, $L['aged'])) . ')';
                }
            }
            $out[] = $s . '.';
        }
        /* Mort pour la France : drapeau tricolore, mention et conflit (guerre, bataille), à la suite du décès */
        $mpfHtml = '';
        if (!empty($this->o['mpf']) && isset($d->mpf[$pid]) && !$d->hidden($pid)) {
            $w = $d->mpf[$pid]['war'];
            $mpfHtml = '<p class="mpf"><span class="fl"><i></i><i></i><i></i></span> <strong>' . $this->e($L['mpf']) . '</strong>' . ($w !== '' ? $this->e(' — ' . $w) : '') . ($d->mpf[$pid]['det'] !== '' ? $this->e(' (' . $d->mpf[$pid]['det'] . ')') : '') . '.</p>';
        }
        $ub = $this->burialBit($p, $sex, $ref);
        if ($ub !== '') $out[] = $ub . '.';
        $deathHtml = ($out ? '<p>' . implode(' ', $out) . '</p>' : '') . $this->evNotes($pid, ['DEAT', 'BURI']);
        if ($narr) {
            $html .= $deathHtml . $mpfHtml;
            if ($par !== '') $html .= '<p>' . (strpos($par, '<') === 0 ? $par : self::cap($par)) . '.</p>';
        }

        /* Profession */
        $other = [];
        if (!empty($d->occ[$pid])) {
            $occ = (!empty($this->o['verbose']) && !empty($L['vb']))
                ? $this->e(str_replace('{o}', implode(', ', $d->occ[$pid]), $L['vb'][(int) ($p['living'] ?? 0) === 1 ? 'occ_now' : 'occ'][$sex])) . '.'
                : $this->e($L['occupation'] . $L['colon'] . implode(', ', $d->occ[$pid])) . '.';
            if ($narr) $other[] = $occ; else $html .= '<p>' . $occ . '</p>';
        }

        /* Lieux de résidence, du plus ancien au plus récent */
        $resi = $this->resiSentence($pid, $sex, $ref);
        if ($resi !== '') { if ($narr) $other[] = $resi; else $html .= '<p>' . $resi . '</p>'; }

        /* Informations militaires */
        if (!empty($this->o['military']) && !empty($d->mil[$pid])) {
            foreach ($d->mil[$pid] as $ev) {
                $s = $this->e($L['military'] . ($ev['txt'] !== '' ? $L['colon'] . $ev['txt'] : ''));
                $md = $this->evDate($ev['dt'], $ev['d']);
                $mp = $this->pl($ev['pl'], $ref);
                if ($md !== '') $s .= ', ' . $this->e($md);
                if ($mp !== '') $s .= ' ' . $mp;
                $html .= '<p>' . $s . '.</p>' . $this->evNotes($pid, [$ev['id']]);
            }
        }

        $sib = $this->siblingsPara($pid, $prefer);
        if ($sib !== '' && !$narr) $html .= $sib;

        /* Récit : « Les autres informations connues pour X sont : » (numéro, profession) */
        if ($narr) {
            $num = substr($ref, 1);
            array_unshift($other, $this->e(str_replace('{n}', $num, ($ref[0] === 'A' ? $L['tpl_sosa'] : $L['tpl_abo'])[$sex])));
            $fn = trim((string) $p['firstname']);
            $html .= '<p class="oth">' . $this->e(preg_replace('/ ([:;?!])$/u', "\u{00A0}$1", str_replace('{name}', $fn !== '' ? $fn : $this->plain($pid), $L['tpl_other']))) . '</p>'
                . '<ul class="bul">' . implode('', array_map(function ($x) { return '<li>' . $x . '</li>'; }, $other)) . '</ul>';
        }

        if ($sib !== '' && $narr) $html .= $sib;

        /* Unions (et enfants pour la descendance) */
        if ($narr && $prefer === 'A') {
            // ancêtres : le couple est décrit une seule fois, dans la fiche de l'épouse (ou de l'époux seul)
            if ($ref === 'A1') $unions = $d->famsOf[$pid] ?? [];
            foreach ($unions as $fid) {
                $f = $d->fams[$fid] ?? null;
                if (!$f) continue;
                if ($ref === 'A1') {
                    $s = $this->unionSentence($pid, $fid, $sex, $ref, $prefer);
                    $eu = $this->endUnion($pid, $fid);
                    if ($eu !== '') $s = ($s !== '' ? $s . ' ' : '') . $eu;
                    if ($s !== '') $html .= '<p>' . $this->spPhoto($pid, $fid) . $s . '</p>';
                    $html .= $this->famEvNotes($fid, ['MARR']) . $this->famNotes($fid) . $this->narrKids($fid, $ref, 'A');
                    continue;
                }
                $spouse = ($f['husband'] === $pid) ? $f['wife'] : $f['husband'];
                $spouseHere = $spouse !== '' && isset($d->sosaOf[$spouse]) && !$d->dropped($spouse);
                if ($sex === 'F' || !$spouseHere) $html .= $this->coupleBlock($fid, $ref);
            }
        } else {
            foreach ($unions as $fid) {
                $s = $this->unionSentence($pid, $fid, $sex, $ref, $prefer);
                $pre = $this->prevUnionClause($pid, $fid, $sex);   // « Veuf de … (décédée le …), il épousa … »
                if ($pre !== '') {
                    if ($s !== '' && $s[0] !== '<') {
                        $s = $pre . ', ' . (function_exists('mb_strtolower') ? mb_strtolower(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8') : lcfirst($s));
                    } else {
                        $s = $pre . ($s !== '' ? ', ' . $s : '.');
                    }
                }
                $eu = $this->endUnion($pid, $fid);
                if ($eu !== '') $s = ($s !== '' ? $s . ' ' : '') . $eu;
                if ($s !== '') $html .= '<p>' . $this->spPhoto($pid, $fid) . $s . '</p>';
                $html .= $this->famEvNotes($fid, ['MARR']);
                $html .= $this->famNotes($fid);
                if ($prefer === 'D') $html .= $narr ? $this->narrKids($fid, $ref, 'D') : $this->childrenList($fid);
            }
        }

        if (!$narr) $html .= $deathHtml . $mpfHtml;

        $html .= $this->notesHtml($pid);
        $html .= $this->sourceNums($pid);
        return $html . '</div>';
    }

    /* ---------- Parties du livre ---------- */

    private function genLabel($kind, $g) {
        $names = $this->L[$kind === 'A' ? 'gen_asc' : 'gen_desc'];
        return $this->L['generation'] . ' ' . $g . (isset($names[$g - 1]) ? ' — ' . $names[$g - 1] : '');
    }

    /** Nom (ou « Personne vivante ») et années d'une personne, pour les roues. */
    private function treeInfo() {
        return function ($pid) {
            $hid = $this->d->hidden($pid);
            return [$hid ? $this->L['living'] : $this->plain($pid), $hid ? '' : $this->years($pid)];
        };
    }

    /**
     * Roue visuelle. $hl = 0 : roue complète (ouverture de la partie) ; $hl = g : mini-roue cumulée jusqu'à la
     * génération g, mise en évidence et numérotée. Vide si l'option est coupée ou si la roue serait illisible.
     */
    /** Une union à décrire dans un arbre : [fid, libellé du conjoint (années éventuelles)]. Liste vide si personne masquée. */
    private function unionList($pid, $withYears = false) {
        $d = $this->d;
        $o = [];
        if ($d->hidden($pid)) return $o;
        foreach ($d->famsOf[$pid] ?? [] as $fid) {
            $f = $d->fams[$fid] ?? null;
            if (!$f) continue;
            $sp = ($f['husband'] === $pid) ? $f['wife'] : $f['husband'];
            if ($sp !== '' && (!isset($d->people[$sp]) || $d->dropped($sp))) $sp = '';
            if ($sp === '' && empty($d->kidsOf[$fid])) continue;
            $m = trim((string) ($f['marrdate'] ?? '')) !== '';   // mariage = date de mariage renseignée
            if ($sp === '') { $o[] = [$fid, '?', $m]; continue; }
            if ($d->hidden($sp)) { $o[] = [$fid, $this->L['living'], $m]; continue; }
            $y = $withYears ? $this->years($sp) : '';
            $o[] = [$fid, $this->plain($sp) . ($y !== '' ? ' ' . $y : ''), $m];
        }
        return $o;
    }

    /** Conjoints d'une personne, un libellé par union (arbres de descendance). */
    private function spouseList($pid, $withYears = false) {
        return array_map(function ($u) { return $u[1]; }, $this->unionList($pid, $withYears));
    }

    /** Conjoints avec leur nature d'union : [['t' => libellé, 'm' => mariage ?], …]. */
    private function spouseMarks($pid, $withYears = false) {
        return array_map(function ($u) { return ['t' => $u[1], 'm' => !empty($u[2])]; }, $this->unionList($pid, $withYears));
    }

    /** Marqueur texte d'une union : ⚭ (mariage) ou × (union sans mariage). */
    private function mk($m) {
        return $m ? '<span class="mk">⚭</span>' : '×';
    }

    /**
     * Plusieurs unions : regroupe les enfants par union, pour qu'on voie de quel couple chacun est issu.
     * Retourne null s'il y a moins de deux unions (les enfants se rattachent alors directement à la personne),
     * sinon une liste de ['label'=>, 'kids'=>[pids]] ; les enfants sans union connue vont dans un groupe sans libellé.
     */
    private function unionGroups($pid, array $kidPids, $withYears = false) {
        $us = $this->unionList($pid, $withYears);
        if (count($us) < 2) return null;
        $g = []; $left = $kidPids;
        foreach ($us as $u) {
            $mine = [];
            foreach ($kidPids as $c) if (($this->d->parentFam[$c] ?? null) === $u[0]) $mine[] = $c;
            $left = array_diff($left, $mine);
            $g[] = ['label' => $u[1], 'm' => !empty($u[2]), 'kids' => $mine];
        }
        if ($left) $g[] = ['label' => '', 'kids' => array_values($left)];
        return $g;
    }

    /** Une ligne de l'arbre : Nom (code), années ; les sous-niveaux sont imbriqués. */
    private function outlineNode($pid, $ref, array $kids, $extra = '', $groups = null) {
        $hid = $this->d->hidden($pid);
        $yrs = $hid ? '' : $this->years($pid);
        $sp = '';
        if ($ref[0] === 'D' && $groups === null) {
            $parts = [];
            foreach ($this->spouseMarks($pid, true) as $e) $parts[] = $this->mk($e['m']) . ' ' . $this->e($e['t']);
            $sp = implode(', ', $parts);
        }
        $h = '<li><span class="on">' . $this->e($hid ? $this->L['living'] : $this->plain($pid)) . '</span> (' . $this->link($ref) . ')'
            . ($yrs !== '' ? ' <span class="yrs">' . $this->e($yrs) . '</span>' : '')
            . ($sp !== '' ? ' <span class="spn">' . $sp . '</span>' : '') . $extra;
        if ($groups !== null) {
            $h .= '<ul>';
            foreach ($groups as $g) {
                if ($g['label'] === '') { $h .= implode('', $g['html']); continue; }
                $h .= '<li class="un"><span class="spn">' . $this->mk(!empty($g['m'])) . ' ' . $this->e($g['label']) . '</span>' . ($g['html'] ? '<ul>' . implode('', $g['html']) . '</ul>' : '') . '</li>';
            }
            $h .= '</ul>';
        } elseif ($kids) {
            $h .= '<ul>' . implode('', $kids) . '</ul>';
        }
        return $h . '</li>';
    }

    /**
     * Arbre d'ouverture d'une partie : ascendance (père puis mère, 5 générations au plus) ou descendance
     * (400 personnes au plus), chaque ligne « Nom (code) » renvoyant à la fiche.
     */
    private function outline($kind) {
        $d = $this->d;
        $shown = 0; $trunc = false; $html = '';
        if ($kind === 'A') {
            if (count($d->sosa) < 2) return '';
            $maxRing = 5;
            $build = function ($n, $ring) use (&$build, $d, $maxRing, &$trunc) {
                $kids = [];
                foreach ([2 * $n, 2 * $n + 1] as $c) {
                    if (!isset($d->sosa[$c])) continue;
                    if ($ring + 1 > $maxRing) { $trunc = true; continue; }
                    $kids[] = $build($c, $ring + 1);
                }
                $extra = isset($d->collapse[$n]) ? ' → ' . $this->e($this->L['see']) . ' ' . $this->link('A' . $d->collapse[$n]) : '';
                return $this->outlineNode($d->sosa[$n], 'A' . $n, $d->collapse[$n] ?? null ? [] : $kids, $extra);
            };
            $html = $build(1, 0);
            $shown = $maxRing + 1;
            $title = $this->L['tree_asc'];
        } else {
            $by = []; $cnt = 0; $maxGen = 1;
            ksort($d->descGen);
            foreach ($d->descGen as $g => $list) {
                if ($g > 1 && $cnt + count($list) > 400) { $trunc = true; break; }
                $cnt += count($list); $maxGen = $g;
                foreach ($list as $pid) {
                    $num = $d->desc[$pid]['num'];
                    $by[$g > 1 ? substr($num, 0, (int) strrpos($num, '.')) : ''][] = $pid;
                }
            }
            if ($maxGen < 2) return '';
            $build = function ($pid) use (&$build, $by, $d) {
                $num = $d->desc[$pid]['num'];
                $cs = $by[$num] ?? [];
                $grp = $this->unionGroups($pid, $cs, true);
                if ($grp !== null) {
                    foreach ($grp as &$g) $g['html'] = array_map($build, $g['kids']);
                    unset($g);
                    return $this->outlineNode($pid, 'D' . $num, [], '', $grp);
                }
                return $this->outlineNode($pid, 'D' . $num, array_map($build, $cs));
            };
            $html = $build(array_key_first($d->desc));
            $shown = $maxGen;
            $title = $this->L['tree_desc'];
        }
        $cap = $title . ($trunc ? ' ' . str_replace('{n}', (string) $shown, $this->L['tree_trunc']) : '');
        return '<div class="tree outline"><ul class="otree">' . $html . '</ul><p class="cap">' . $this->e($cap) . '</p></div>';
    }

    /** Même arbre en liste imbriquée (export Word / OpenDocument, où le SVG n'est pas utilisable). */
    private function boxList(array $n) {
        $h = !empty($n['union'])
            ? '<li><span class="spn">' . $this->mk(!empty($n['m'])) . ' ' . $this->e($n['name']) . '</span>'
            : '<li><span class="on">' . $this->e($n['name']) . '</span> ' . $this->e($n['sub']) . (!empty($n['sp']) ? ' ' . implode('; ', array_map(function ($e) { return '<span class="spn">' . $this->mk(is_array($e) && !empty($e['m'])) . ' ' . $this->e(is_array($e) ? $e['t'] : $e) . '</span>'; }, (array) $n['sp'])) : '');
        if ($n['kids']) $h .= '<ul>' . implode('', array_map([$this, 'boxList'], $n['kids'])) . '</ul>';
        return $h . '</li>';
    }

    /** Nœud de case : nom, « (code) années ». */
    /** Petit arbre en cases (la personne puis ses parents et grands-parents connus) ; '' s'il n'y a pas de parents dans le livre. */
    private function miniTree($n) {
        $d = $this->d;
        if ($n < 2 || !isset($d->sosa[$n]) || isset($d->collapse[$n])) return '';
        if (!isset($d->sosa[2 * $n]) && !isset($d->sosa[2 * $n + 1])) return '';
        $build = function ($m, $lvl) use (&$build, $d) {
            $kids = [];
            if ($lvl < 2 && !isset($d->collapse[$m])) {
                foreach ([2 * $m, 2 * $m + 1] as $c) if (isset($d->sosa[$c])) $kids[] = $build($c, $lvl + 1);
            }
            return $this->boxNode($d->sosa[$m], 'A' . $m, $kids, $lvl === 0);
        };
        $c = $build($n, 0);
        $fig = TNGBook_Boxes::chart($c, $this->mode, $this->L['tree_asc'], $this->colors(), 13);
        if ($this->mode === 'doc' && $fig === '') return '<div class="tfig"><ul class="otree">' . $this->boxList($c) . '</ul></div>';
        return '<div class="tree boxes mini"><div class="tfig">' . $fig . '</div></div>';
    }

    private function boxNode($pid, $ref, array $kids, $hl) {
        $hid = $this->d->hidden($pid);
        $yrs = $hid ? '' : $this->years($pid);
        return ['name' => $hid ? $this->L['living'] : $this->plain($pid), 'sub' => '(' . $ref . ')' . ($yrs !== '' ? ' ' . $yrs : ''), 'sp' => $ref[0] === 'D' ? $this->spouseMarks($pid) : [], 'hl' => $hl, 'kids' => $kids];
    }

    /**
     * Arbre d'ouverture : liste indentée « Nom (code) ». Pour une génération $hl >= 2 : arbres classiques en cases
     * (racine à gauche) couvrant les trois dernières générations, la génération $hl étant en évidence.
     */
    private function tree($kind, $hl = 0) {
        if (empty($this->o['trees'])) return '';
        if (!$hl) return $this->outline($kind);
        $d = $this->d;
        $r = max(1, $hl - 2); $depth = $hl - $r;
        $charts = [];
        if ($kind === 'A') {
            $build = function ($n, $lvl) use (&$build, $d, $depth) {
                $kids = [];
                if ($lvl < $depth && !isset($d->collapse[$n])) {
                    foreach ([2 * $n, 2 * $n + 1] as $c) if (isset($d->sosa[$c])) $kids[] = $build($c, $lvl + 1);
                }
                return $this->boxNode($d->sosa[$n], 'A' . $n, $kids, $lvl === $depth);
            };
            for ($n = 1 << ($r - 1); $n < (1 << $r); $n++) {
                if (!isset($d->sosa[$n]) || isset($d->collapse[$n])) continue;
                if (!isset($d->sosa[2 * $n]) && !isset($d->sosa[2 * $n + 1])) continue;
                $charts[] = $build($n, 0);
            }
        } else {
            $by = [];
            foreach ($d->descGen as $g => $list) foreach ($list as $pid) {
                $num = $d->desc[$pid]['num'];
                $by[$g > 1 ? substr($num, 0, (int) strrpos($num, '.')) : ''][] = $pid;
            }
            $build = function ($pid, $lvl) use (&$build, $by, $d, $depth) {
                $num = $d->desc[$pid]['num'];
                $kids = [];
                $multi = false;
                if ($lvl < $depth) {
                    $cs = $by[$num] ?? [];
                    $grp = $this->unionGroups($pid, $cs);
                    if ($grp === null) {
                        foreach ($cs as $c) $kids[] = $build($c, $lvl + 1);
                    } else {
                        $multi = true;   // une case « union » par conjoint, les enfants s'y rattachent
                        foreach ($grp as $g) {
                            $kk = [];
                            foreach ($g['kids'] as $c) $kk[] = $build($c, $lvl + 1);
                            if ($g['label'] === '') { foreach ($kk as $x) $kids[] = $x; continue; }
                            $kids[] = ['name' => $g['label'], 'sub' => '', 'sp' => [], 'union' => true, 'm' => !empty($g['m']), 'hl' => false, 'kids' => $kk];
                        }
                    }
                }
                $n = $this->boxNode($pid, 'D' . $num, $kids, $lvl === $depth);
                if ($multi) $n['sp'] = [];
                return $n;
            };
            foreach ($d->descGen[$r] ?? [] as $pid) {
                if (empty($by[$d->desc[$pid]['num']])) continue;
                $charts[] = $build($pid, 0);
            }
        }
        if (!$charts) return '';
        $more = count($charts) > 16;
        $title = $kind === 'A' ? $this->L['tree_asc'] : $this->L['tree_desc'];
        $h = '<div class="tree boxes">';
        foreach (array_slice($charts, 0, 16) as $c) {
            $fig = TNGBook_Boxes::chart($c, $this->mode, $title, $this->colors());
            $h .= ($this->mode === 'doc' && $fig === '')       // image impossible (GD/FreeType absents) : liste indentée
                ? '<div class="tfig"><ul class="otree">' . $this->boxList($c) . '</ul></div>'
                : '<div class="tfig">' . $fig . '</div>';
        }
        if ($more) $h .= '<p class="cap">' . $this->e(str_replace('{n}', '16', $this->L['tree_more'])) . '</p>';
        return $h . '</div>';
    }

    private function part($id, $title, $level = 1) {
        $this->toc[] = [$id, $title, $level];
        return '<h2 class="part-title" id="' . esc_attr($id) . '">' . $this->e($title) . '</h2>';
    }

    /** Page de présentation du patronyme : texte libre de l'administrateur (profil du livre), avec tableaux et images. */
    private function surnamePart() {
        $o = $this->o; $L = $this->L;
        $title = trim((string) ($o['surname_title'] ?? ''));
        if ($title === '') {
            $p = $this->d->people[$o['root']] ?? [];
            $last = trim(trim((string) ($p['lnprefix'] ?? '')) . ' ' . trim((string) ($p['lastname'] ?? '')));
            $title = str_replace('{name}', mb_strtoupper($last), $L['surname_default']);
        }
        return '<div class="part notice surn">' . $this->part('p-surn', $title) . $this->surnameBody((string) $o['surname']) . '</div>';
    }

    /** HTML du texte : images rendues autonomes (données intégrées) et sorties des paragraphes pour que Word / OpenDocument les reprennent. */
    private function surnameBody($html) {
        $html = TNGBook::surname_kses(wpautop($html));
        $html = preg_replace(['#<h[12](\s|>)#i', '#</h[12]>#i'], ['<h3$1', '</h3>'], $html);   // les titres de la page restent sous le titre de la partie
        if (stripos($html, '<img') === false || !class_exists('DOMDocument')) return $html;
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="surn-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $dom->getElementById('surn-root');
        if (!$root) return $html;
        $imgs = [];
        foreach ($dom->getElementsByTagName('img') as $im) $imgs[] = $im;
        foreach ($imgs as $im) {
            $src = $this->surnameImage($im->getAttribute('src'));
            if (!$src) { $im->parentNode->removeChild($im); continue; }
            $px = (float) $im->getAttribute('width');
            $cm = min(15.0, max(1.0, ($px > 0 ? $px : $src[1]) * 0.02646));
            $new = $dom->createElement('img');
            $new->setAttribute('class', 'tpng');
            $new->setAttribute('data-cm', (string) round($cm, 2));
            $new->setAttribute('style', 'display:block;margin:.6em auto;max-width:100%;height:auto');
            $new->setAttribute('src', $src[0]);
            $new->setAttribute('alt', $im->getAttribute('alt'));
            // l'image passe après le bloc qui la contient (Word / OpenDocument n'acceptent pas les images dans un paragraphe) ; dans une cellule de tableau elle est retirée.
            $par = $im->parentNode;
            $inCell = false;
            for ($x = $par; $x && $x !== $root; $x = $x->parentNode) if (in_array(strtolower($x->nodeName), ['td', 'th'], true)) $inCell = true;
            $im->parentNode->removeChild($im);
            if ($inCell) continue;
            $top = $par;
            while ($top && $top->parentNode && $top->parentNode !== $root) $top = $top->parentNode;
            if (!$top || $top === $root) $root->appendChild($new); else $top->parentNode->insertBefore($new, $top->nextSibling);
        }
        $out = '';
        foreach ($root->childNodes as $n) $out .= $dom->saveHTML($n);
        return $out;
    }

    /** Image du texte → [ « data:… », largeur en pixels ] (JPEG, PNG, GIF ; WebP converti en PNG si GD le permet), ou null. */
    private function surnameImage($url) {
        $url = trim((string) $url);
        if ($url === '') return null;
        $bin = null;
        if (strpos($url, 'data:') === 0) {
            if (preg_match('#^data:image/[a-z+]+;base64,(.+)$#s', $url, $m)) $bin = base64_decode($m[1], true);
        } else {
            $up = wp_upload_dir();
            $base = trailingslashit($up['baseurl']);
            $clean = preg_replace('#-\d+x\d+(\.[a-z]+)$#i', '$1', $url);   // la taille d'origine pour une meilleure qualité d'impression
            foreach ([$url, $clean] as $u) {
                $alt = ($clean !== $url && $u === $clean);
                if (strpos($u, $base) === 0) {
                    $path = realpath(trailingslashit($up['basedir']) . ltrim(rawurldecode(substr($u, strlen($base))), '/'));
                    $root = realpath($up['basedir']);
                    if ($path && $root && strpos($path, $root . DIRECTORY_SEPARATOR) === 0 && is_file($path) && filesize($path) < 8 * MB_IN_BYTES) { $bin = file_get_contents($path); break; }
                } elseif (!$alt && preg_match('#^https?://#i', $u)) {
                    $r = wp_safe_remote_get($u, ['timeout' => 10, 'limit_response_size' => 8 * MB_IN_BYTES]);
                    if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) { $bin = wp_remote_retrieve_body($r); break; }
                }
            }
        }
        if (!$bin) return null;
        $sz = @getimagesizefromstring($bin);
        if (!$sz) return null;
        $mime = $sz['mime'];
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $g = @imagecreatefromstring($bin);
            if (!$g) return null;
            ob_start(); imagepng($g); $bin = ob_get_clean(); $mime = 'image/png';
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) return null;
        return ['data:' . $mime . ';base64,' . base64_encode($bin), (int) $sz[0]];
    }

    private function ascendance() {
        $d = $this->d;
        $by = [];
        foreach ($d->sosa as $n => $pid) $by[strlen(decbin($n))][$n] = $pid;
        ksort($by);
        $h = '<div class="part">' . $this->part('p-asc', $this->L['ascendance']) . $this->tree('A');
        $cols = !empty($this->tpl['cols']);
        foreach ($by as $g => $list) {
            ksort($list);
            $id = 'Ag' . $g;
            $this->toc[] = [$id, $this->genLabel('A', $g), 2];
            $gh = '<h3 class="gen" id="' . $id . '">' . $this->e($this->genLabel('A', $g)) . '</h3>';
            $gt = $g >= 2 ? $this->tree('A', $g) : '';
            if ($cols && $gt !== '') { $h .= $gh . $gt . '<div class="cols">'; }
            elseif ($cols) { $h .= '<div class="cols">' . $gh; }
            else { $h .= $gh . $gt; }
            foreach ($list as $n => $pid) {
                $ref = 'A' . $n;
                if (isset($d->collapse[$n])) {
                    $this->addName($pid, $ref);
                    $h .= '<div class="fiche stub" id="' . $ref . '"><h4><span class="num">' . $ref . '</span> ' . $this->e($this->plain($pid))
                        . '</h4><p>→ ' . $this->e($this->L['see']) . ' ' . $this->link('A' . $d->collapse[$n]) . '</p></div>';
                    continue;
                }
                $unions = [];
                if ($n > 1) {
                    $child = $d->sosa[$n >> 1] ?? null;
                    $fid = $child ? ($d->parentFam[$child] ?? null) : null;
                    if ($fid) $unions[] = $fid;
                }
                if (!empty($this->o['allunions'])) {           // autres mariages / unions de l'ancêtre, dans l'ordre chronologique
                    $all = $d->famsOf[$pid] ?? [];
                    foreach ($unions as $u) if (!in_array($u, $all, true)) $all[] = $u;
                    if ($all) $unions = $all;
                }
                $h .= $this->fiche($pid, $ref, 'A', $unions);
            }
            if ($cols) $h .= '</div>';
        }
        return $h . '</div>';
    }

    /** Cousinages et implexes : ancêtres présents plusieurs fois et unions entre apparentés (vide s'il n'y en a pas). */
    private function cousinsPart() {
        $d = $this->d; $L = $this->L;
        if (empty($d->collapse)) return '';
        $pos = [];
        foreach ($d->collapse as $n => $m) {
            $pid = $d->sosa[$m] ?? null;
            if (!$pid || !isset($d->people[$pid]) || $d->hidden($pid)) continue;
            if (!isset($pos[$pid])) $pos[$pid] = [(int) $m];
            $pos[$pid][] = (int) $n;
        }
        if (!$pos) return '';
        // Coefficient de consanguinité (Wright) par la parenté récursive, sur les générations chargées uniquement.
        $parOf = function ($p) use ($d) {
            $fid = $d->parentFam[$p] ?? null; $f = $fid ? ($d->fams[$fid] ?? null) : null;
            $a = ($f && !empty($f['husband']) && isset($d->sosaOf[$f['husband']])) ? $f['husband'] : null;
            $b = ($f && !empty($f['wife']) && isset($d->sosaOf[$f['wife']])) ? $f['wife'] : null;
            return [$a, $b];
        };
        $hm = []; $Fm = []; $km = []; $calls = 0; $over = false;
        $H = function ($p) use (&$H, &$hm, $parOf) {
            if (isset($hm[$p])) return $hm[$p];
            $hm[$p] = 0;                                              // garde anti-boucle
            list($a, $b) = $parOf($p);
            $x = 0;
            if ($a !== null) $x = max($x, 1 + $H($a));
            if ($b !== null) $x = max($x, 1 + $H($b));
            return $hm[$p] = $x;
        };
        $K = null; $F = null;
        $K = function ($a, $b) use (&$K, &$F, &$km, &$calls, &$over, $H, $parOf) {
            if ($a === null || $b === null || $over) return 0.0;
            if ($a === $b) return (1 + $F($a)) / 2;
            $ha = $H($a); $hb = $H($b);
            if ($ha < $hb) { $t = $a; $a = $b; $b = $t; }
            $key = $a < $b ? $a . '|' . $b : $b . '|' . $a;
            if (isset($km[$key])) return $km[$key];
            if (++$calls > 400000) { $over = true; return 0.0; }
            list($fa, $ma) = $parOf($a);
            if ($fa === null && $ma === null) return $km[$key] = 0.0;
            return $km[$key] = ($K($fa, $b) + $K($ma, $b)) / 2;
        };
        $F = function ($p) use (&$K, &$Fm, $parOf) {
            if (isset($Fm[$p])) return $Fm[$p];
            list($a, $b) = $parOf($p);
            return $Fm[$p] = ($a !== null && $b !== null) ? $K($a, $b) : 0.0;
        };
        $pf = function ($f) {
            if ($f <= 0) return '0 %';
            $pc = $f * 100;
            $dec = (int) max(0, min(6, 2 - floor(log10($pc))));
            $t = $this->nf($pc, $dec) . ' %';
            $inv = (int) round(1 / $f);
            if ($inv > 1 && abs($f * $inv - 1) < 0.01) $t .= ' (1/' . $inv . ')';
            return $t;
        };
        $bl = function ($n) { return strlen(decbin($n)); };
        $lnk = function ($n) { return '<a class="ref" href="#A' . $n . '">' . $n . '</a>'; };
        $ref = function ($n) use ($d, $lnk) {
            $pid = $d->sosa[$n] ?? null;
            return ($pid ? '<strong>' . $this->e($this->plain($pid)) . '</strong> ' : '') . '(' . $lnk($n) . ')';
        };
        // implexes
        $rows = [];
        uasort($pos, function ($a, $b) { return $a[0] <=> $b[0]; });
        foreach ($pos as $pid => $ns) {
            sort($ns);
            $rows[] = ['<strong>' . $this->e($this->plain($pid)) . '</strong>', implode(', ', array_map($lnk, $ns)), (string) count($ns)];
        }
        // couples dont les deux membres descendent d'un même ancêtre
        $couples = [];
        foreach ($pos as $pid => $ns) {
            $cnt = count($ns);
            for ($i = 0; $i < $cnt; $i++) for ($j = $i + 1; $j < $cnt; $j++) {
                $a = $ns[$i]; $b = $ns[$j];
                $ba = decbin($a); $bb = decbin($b);
                $k = 0; $max = min(strlen($ba), strlen($bb));
                while ($k < $max && $ba[$k] === $bb[$k]) $k++;
                if ($k < 1 || $k >= $max) continue;                 // l'un est l'ancêtre de l'autre dans l'arbre : incohérent, ignoré
                $c = bindec(substr($ba, 0, $k));
                if ($ba[$k] === '1') { $t = $a; $a = $b; $b = $t; $ba = decbin($a); $bb = decbin($b); }
                $ga = $bl($a) - $bl(2 * $c); $gb = $bl($b) - $bl(2 * $c + 1);
                $couples[$c]['anc'][$pid] = [$a, $b];
                if (!isset($couples[$c]['best']) || $ga + $gb < array_sum($couples[$c]['best'])) $couples[$c]['best'] = [$ga, $gb];
            }
        }
        $h = '<div class="part">' . $this->part('p-cous', $L['cx_title']);
        $gmax = 0; foreach ($d->sosa as $n => $x) $gmax = max($gmax, $bl($n));
        $h .= '<p>' . $this->e(str_replace(['{name}', '{g}', '{k}'], [$this->plain($this->o['root']), (string) $gmax, (string) count($pos)], $L['cx_intro'])) . '</p>';
        $root = $d->sosa[1] ?? null;
        $fr = $root !== null ? $F($root) : 0.0;
        $h .= '<p><strong>' . $this->e(str_replace(['{name}', '{f}'], [$this->plain($root), $over ? $L['cx_fover'] : $pf($fr)], $L['cx_f'])) . '</strong></p>';
        $h .= $this->stSection('cx-impl', $L['cx_impl']) . $this->table([$L['cx_anc'], $L['cx_nums'], $L['cx_occ']], $rows, 'llr');
        if ($couples) {
            ksort($couples);
            $rows = [];
            foreach ($couples as $c => $info) {
                $x = $d->sosa[2 * $c] ?? null; $y = $d->sosa[2 * $c + 1] ?? null;
                if (!$x || !$y || $d->hidden($x) || $d->hidden($y)) continue;
                list($ga, $gb) = $info['best'];
                if ($ga === 0 || $gb === 0) $rel = $L['cx_dir'];
                elseif ($ga === $gb && $ga === 2) $rel = $L['cx_rel2'];
                elseif ($ga === $gb && $ga === 3) $rel = $L['cx_rel3'];
                else $rel = str_replace(['{a}', '{b}'], [(string) $ga, (string) $gb], $L['cx_relx']);
                $an = [];
                foreach ($info['anc'] as $pid => $ab) $an[] = '<strong>' . $this->e($this->plain($pid)) . '</strong> (' . $lnk($ab[0]) . ' ' . $this->e($L['cx_and']) . ' ' . $lnk($ab[1]) . ')';
                $kid = $d->sosa[$c] ?? null;
                $rows[] = [$ref(2 * $c) . ' ' . $this->e($L['cx_and']) . ' ' . $ref(2 * $c + 1), $this->e($rel), implode('<br>', $an), $over ? '–' : $this->e($pf($K($x, $y)))];
            }
            if ($rows) $h .= $this->stSection('cx-cous', $L['cx_cous']) . $this->table([$L['cx_couple'], $L['cx_rel'], $L['cx_common'], $L['cx_fcol']], $rows, 'lllr');
        }
        return $h . '<p class="src">' . $this->e($L['cx_note'] . ' ' . $L['cx_fnote']) . '</p></div>';
    }

    private function descendance() {
        $d = $this->d;
        $h = '<div class="part">' . $this->part('p-desc', $this->L['descendance']) . $this->tree('D');
        ksort($d->descGen);
        foreach ($d->descGen as $g => $list) {
            $id = 'Dg' . $g;
            $this->toc[] = [$id, $this->genLabel('D', $g), 2];
            $gh = '<h3 class="gen" id="' . $id . '">' . $this->e($this->genLabel('D', $g)) . '</h3>';
            $gt = $g >= 2 ? $this->tree('D', $g) : '';
            $cols = !empty($this->tpl['cols']);
            if ($cols && $gt !== '') { $h .= $gh . $gt . '<div class="cols">'; }
            elseif ($cols) { $h .= '<div class="cols">' . $gh; }
            else { $h .= $gh . $gt; }
            foreach ($list as $pid) {
                $h .= $this->fiche($pid, 'D' . $d->desc[$pid]['num'], 'D', $d->famsOf[$pid] ?? []);
            }
            if ($cols) $h .= '</div>';
        }
        return $h . '</div>';
    }

    /* ---------- Liste des descendants (tableau, en fin de livre) ---------- */

    /** Date courte (« 5.10.1917 » selon la langue) d'un événement ; texte libre conservé tel quel. */
    private function dShort($tr, $txt) {
        $p = self::pd($tr);
        if (!$p) { $t = trim((string) $txt); return preg_match('/^(y|n)$/i', $t) ? '' : $t; }   // « Y » = marqueur TNG sans date
        list($y, $m, $dd) = $p;
        $L = $this->L;
        $mon = $m >= 1 && $m <= 12;
        $q = '';
        if (preg_match('/^\s*(ABT|ABOUT|CIRCA|CA|EST|CAL|BEF|BEFORE|AFT|AFTER)\b/i', (string) $txt, $mm)) {
            $k = strtoupper($mm[1]);
            $q = in_array($k, ['BEF', 'BEFORE'], true) ? 'bef' : (in_array($k, ['AFT', 'AFTER'], true) ? 'aft' : 'abt');
        }
        $vars = ['{d}' => ($dd && $this->lang === 'fr' && $dd === 1) ? '1er' : (string) $dd, '{m}' => $mon ? $L['months'][$m - 1] : '', '{md}' => $mon ? $L['months_de'][$m - 1] : '', '{y}' => (string) $y];
        $k = ($mon && $dd) ? 'full' : ($mon ? 'my' : 'y');
        return ($q ? $L['q'][$q] . ' ' : '') . strtr($L['b_' . $k], $vars);
    }

    /** Âge au décès en années entières (vide si dates incomplètes). */
    private function ageYears($b, $dth) {
        $b = self::pd($b); $dth = self::pd($dth);
        if (!$b || !$dth) return '';
        $n = $dth[0] - $b[0];
        if ($b[1] && $dth[1] && ($dth[1] < $b[1] || ($dth[1] === $b[1] && $b[2] && $dth[2] && $dth[2] < $b[2]))) $n--;
        return $n >= 0 && $n < 125 ? (string) $n : '';
    }

    /** Date + lieu sur deux lignes (cellule de tableau). */
    private function dpCell($date, $place) {
        $h = $this->e($date);
        $pl = $this->placeShort((string) $place);
        if ($pl !== '') $h .= ($h !== '' ? '<br>' : '') . $this->e($pl);
        return $h;
    }

    /** Naissance (à défaut baptême) et décès (à défaut sépulture) d'une personne : [date naiss., lieu naiss., date décès, lieu décès]. */
    private function listDates($p) {
        $L = $this->L;
        // Naissance, à défaut baptême (« bapt. … ») ; décès, à défaut inhumation (« inhum. … »)
        $bd = trim((string) $p['birthdate']) !== '' ? $this->dShort($p['birthdatetr'], $p['birthdate']) : '';
        $bp = trim((string) $p['birthplace']);
        if ($bd === '' && trim((string) ($p['baptdate'] ?? '')) !== '') {
            $bd = $L['dl_bapt'] . ' ' . $this->dShort($p['baptdatetr'] ?? '', $p['baptdate']);
            if ($bp === '') $bp = trim((string) ($p['baptplace'] ?? ''));
        }
        $dd = trim((string) $p['deathdate']) !== '' ? $this->dShort($p['deathdatetr'], $p['deathdate']) : '';
        if ($dd === '' && preg_match('/^y$/i', trim((string) $p['deathdate']))) $dd = '†';   // décédé, date inconnue
        $dp = trim((string) $p['deathplace']);
        if (($dd === '' || $dd === '†') && trim((string) ($p['burialdate'] ?? '')) !== '' && !preg_match('/^y$/i', trim((string) $p['burialdate']))) {
            $dd = $L[!empty($p['burialtype']) && (int) $p['burialtype'] === 1 ? 'dl_cremated' : 'dl_buried'] . ' ' . $this->dShort($p['burialdatetr'] ?? '', $p['burialdate']);
            if ($dp === '') $dp = trim((string) ($p['burialplace'] ?? ''));
        }
        return [$bd, $bp, $dd, $dp];
    }

    private function descListPart() {
        $d = $this->d;
        $L = $this->L;
        $entries = [];
        foreach ($d->desc as $pid => $info) $entries[] = ['pid' => $pid, 'num' => $info['num'], 'gen' => $info['gen'], 'dup' => null];
        foreach ($d->dupDesc as $num => $orig) {
            if (isset($d->dupPid[$num])) $entries[] = ['pid' => $d->dupPid[$num], 'num' => $num, 'gen' => substr_count($num, '.') + 1, 'dup' => $orig];
        }
        if (count($entries) < 2) return '';
        usort($entries, function ($a, $b) {
            if ($a['gen'] !== $b['gen']) return $a['gen'] - $b['gen'];
            $x = array_map('intval', explode('.', $a['num'])); $y = array_map('intval', explode('.', $b['num']));
            foreach ($x as $i => $v) { if (!isset($y[$i])) return 1; if ($v !== $y[$i]) return $v - $y[$i]; }
            return count($x) - count($y);
        });
        $rows = []; $lastGen = 0;
        foreach ($entries as $en) {
            $pid = $en['pid'];
            $p = $d->people[$pid] ?? null;
            if (!$p || $d->hidden($pid)) continue;            // vivants/privés masqués : absents de la liste
            if ($en['gen'] !== $lastGen) { $rows[] = ['gen' => $this->genLabel('D', $en['gen'])]; $lastGen = $en['gen']; }
            $ref = $en['dup'] ? 'D' . $en['dup'] : 'D' . $en['num'];
            $numCell = '<a class="ref" href="#' . esc_attr($ref) . '">' . $this->e($en['num']) . '</a>';
            $nameCell = '<strong>' . $this->e($this->plain($pid)) . '</strong>';
            if ($en['dup']) {
                $nameCell .= '<br><em>' . $this->e(str_replace('{n}', $en['dup'], $L['dl_dup'])) . '</em>';
                $rows[] = [$numCell, $nameCell, '', '', '', ''];
                continue;
            }
            list($bd, $bp, $dd, $dp) = $this->listDates($p);
            $sp = [];
            foreach ($d->famsOf[$pid] ?? [] as $fid) {
                $f = $d->fams[$fid] ?? null;
                if (!$f) continue;
                $o = $f['husband'] === $pid ? $f['wife'] : $f['husband'];
                $line = '';
                if ($o && isset($d->people[$o]) && !$d->hidden($o)) $line = $this->e($this->plain($o));
                $md = $f['marrdate'] !== '' ? $this->dShort($f['marrdatetr'], $f['marrdate']) : '';
                $mp = $this->placeShort((string) $f['marrplace']);
                if ($line === '' && $md === '' && $mp === '') continue;
                $un = trim($md . ($mp !== '' ? ($md !== '' ? ', ' : '') . $mp : ''));
                if ($un !== '') $line .= ($line !== '' ? '<br>' : '') . '<span class="un">' . $this->e($L['dl_union'] . ' ' . $un) . '</span>';
                $sp[] = $line;
            }
            $rows[] = [$numCell, $nameCell, $this->dpCell($bd, $bp), implode('<br>', $sp), $this->dpCell($dd, $dp), $this->e($this->ageYears($p['birthdatetr'] ?: ($p['baptdatetr'] ?? ''), $p['deathdatetr'] ?: ($p['burialdatetr'] ?? '')))];
        }
        if (count($rows) < 1) return '';
        return $this->listTable('p-dlist', 'dl_title', 'dl_intro', 'dl_num', $rows);
    }

    private function listTable($id, $titleKey, $introKey, $numKey, array $rows) {
        $L = $this->L;
        $root = $this->plain($this->o['root']);
        $h = '<div class="part dlist">' . $this->part($id, $L[$titleKey]) . '<p class="src">' . $this->e(str_replace('{name}', $root, $L[$introKey])) . '</p>';
        $h .= '<table class="st"><tr>';
        foreach ([$numKey, 'dl_name', 'dl_birth', 'dl_spouse', 'dl_death', 'dl_age'] as $i => $k) $h .= '<th' . ($i === 5 ? ' class="r"' : '') . '>' . $this->e($L[$k]) . '</th>';
        $h .= '</tr>';
        foreach ($rows as $r) {
            if (isset($r['gen'])) { $h .= '<tr class="genrow"><td colspan="6" class="gh">' . $this->e($r['gen']) . '</td></tr>'; continue; }
            $h .= '<tr>';
            foreach ($r as $i => $c) $h .= '<td' . ($i === 5 ? ' class="r"' : '') . '>' . $c . '</td>';
            $h .= '</tr>';
        }
        return $h . '</table></div>';
    }

    /** Liste des ascendants : un tableau par numéro Sosa (naissance, conjoint de la lignée, décès, âge). */
    private function ascListPart() {
        $d = $this->d;
        $L = $this->L;
        if (count($d->sosa) < 2) return '';
        $nums = array_keys($d->sosa);
        sort($nums, SORT_NUMERIC);
        $rows = []; $lastGen = 0;
        foreach ($nums as $n) {
            $pid = $d->sosa[$n];
            $p = $d->people[$pid] ?? null;
            if (!$p || $d->hidden($pid) || $d->dropped($pid)) continue;
            $gen = (int) floor(log($n, 2)) + 1;
            if ($gen !== $lastGen) { $rows[] = ['gen' => $this->genLabel('A', $gen)]; $lastGen = $gen; }
            $numCell = '<a class="ref" href="#A' . $n . '">' . $n . '</a>';
            $nameCell = '<strong>' . $this->e($this->plain($pid)) . '</strong>';
            if (isset($d->collapse[$n])) {
                $nameCell .= '<br><em>' . $this->e(str_replace('{n}', (string) $d->collapse[$n], $L['al_dup'])) . '</em>';
                $rows[] = [$numCell, $nameCell, '', '', '', ''];
                continue;
            }
            list($bd, $bp, $dd, $dp) = $this->listDates($p);
            // conjoint : l'autre membre du couple de la lignée (parents de l'enfant numéroté n/2) ; pour la personne de départ, toutes ses unions
            $fids = [];
            if ($n > 1) {
                $child = $d->sosa[$n >> 1] ?? null;
                $fid = $child ? ($d->parentFam[$child] ?? null) : null;
                if ($fid) $fids[] = $fid;
            } else {
                $fids = $d->famsOf[$pid] ?? [];
            }
            $sp = [];
            foreach ($fids as $fid) {
                $f = $d->fams[$fid] ?? null;
                if (!$f) continue;
                $o = $f['husband'] === $pid ? $f['wife'] : $f['husband'];
                $line = '';
                if ($o && isset($d->people[$o]) && !$d->hidden($o)) $line = $this->e($this->plain($o));
                $md = $f['marrdate'] !== '' ? $this->dShort($f['marrdatetr'], $f['marrdate']) : '';
                $mp = $this->placeShort((string) $f['marrplace']);
                if ($line === '' && $md === '' && $mp === '') continue;
                $un = trim($md . ($mp !== '' ? ($md !== '' ? ', ' : '') . $mp : ''));
                if ($un !== '') $line .= ($line !== '' ? '<br>' : '') . '<span class="un">' . $this->e($L['dl_union'] . ' ' . $un) . '</span>';
                $sp[] = $line;
            }
            $rows[] = [$numCell, $nameCell, $this->dpCell($bd, $bp), implode('<br>', $sp), $this->dpCell($dd, $dp), $this->e($this->ageYears($p['birthdatetr'] ?: ($p['baptdatetr'] ?? ''), $p['deathdatetr'] ?: ($p['burialdatetr'] ?? '')))];
        }
        if (count($rows) < 1) return '';
        return $this->listTable('p-alist', 'al_title', 'al_intro', 'al_num', $rows);
    }

    private function refLinks(array $refs) {
        $keys = array_keys($refs);
        usort($keys, function ($a, $b) { return strnatcmp($a, $b); });
        return implode(', ', array_map([$this, 'link'], $keys));
    }

    private function indexNames() {
        if (!$this->names) return '';
        uksort($this->names, 'strcmp');
        $h = '<div class="part">' . $this->part('p-idx-names', $this->L['index_names']) . '<div class="idx">';
        $cur = '';
        foreach ($this->names as $key => $n) {
            $letter = mb_strtoupper(mb_substr(remove_accents($n['disp']), 0, 1));
            if ($letter !== $cur) {
                $cur = $letter;
                $h .= '<h5>' . $this->e($letter) . '</h5>';
            }
            $h .= '<p>' . $this->e($n['disp']) . ' — ' . $this->refLinks($n['refs']) . '</p>';
        }
        return $h . '</div></div>';
    }

    private function indexPlaces() {
        if (!$this->places) return '';
        uksort($this->places, function ($a, $b) { return strcmp(remove_accents(mb_strtolower($a)), remove_accents(mb_strtolower($b))); });
        $h = '<div class="part">' . $this->part('p-idx-places', $this->L['index_places']) . '<div class="idx">';
        foreach ($this->places as $place => $refs) {
            $h .= '<p>' . $this->e($place) . ' — ' . $this->refLinks($refs) . '</p>';
        }
        return $h . '</div></div>';
    }

    private function sourcesPart() {
        if (!$this->srcOrder) return '';
        $h = '<div class="part">' . $this->part('p-src', $this->L['sources']) . '<ol class="srcs">';
        foreach ($this->srcOrder as $i => $sid) {
            $h .= '<li value="' . ($i + 1) . '">[' . ($i + 1) . '] ' . $this->e($this->d->sources[$sid]) . '</li>';
        }
        return $h . '</ol></div>';
    }

    /** Couleurs du livre (réglables) : text, title, head, name, meta. */
    public static function defaultColors() {
        return ['text' => '#222222', 'title' => '#222222', 'head' => '#7a1f1f', 'name' => '#222222', 'meta' => '#555555', 'tree' => '#8a6a6a', 'treehl' => '#7a2e2e'];
    }

    private function colors() {
        $c = self::defaultColors();
        foreach ((array) ($this->o['colors'] ?? []) as $k => $v) {
            if (isset($c[$k]) && is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $c[$k] = strtolower($v);
        }
        return $c;
    }

    private function css() {
        $c = $this->colors();
        return str_replace(['%text%', '%title%', '%head%', '%name%', '%meta%', '%tree%'], [$c['text'], $c['title'], $c['head'], $c['name'], $c['meta'], $c['tree']], $this->cssRaw() . $this->tplCss());
    }

    /** Feuille de style propre au modèle (vide pour le modèle d'origine). */
    private function tplCss() {
        $t = $this->tpl;
        if (empty($t['font'])) return '';
        $c = $this->colors();
        $band = self::tint($c['head'], 0.82);
        $css = "\nbody { font-family: {$t['font']}; font-size: {$t['sz']}pt; }\n";
        $id = (string) ($this->o['template'] ?? '');
        if ($id === 'recit') {
            $css .= <<<CSS
h2.part-title { text-align: center; border-bottom: 0; font-size: 20pt; letter-spacing: .04em; }
h3.gen { background: $band; text-align: center; color: %title%; font-size: 12.5pt; margin: 1.6em 0 .7em; padding: .3em .4em; border-top: 2px solid %head%; border-bottom: 1px solid %head%; letter-spacing: .14em; text-transform: uppercase; }
.cols { column-count: 2; column-gap: 1.1cm; column-rule: 1px solid $band; }
.cols h3.gen { margin-top: .8em; break-inside: avoid; }
.fiche { overflow: visible; page-break-inside: auto; break-inside: avoid; margin: 0 0 1em; }
.fiche img.ph { width: 2.3cm; margin: 0 0 .2cm .3cm; }
.fiche h4 { font-size: 11.5pt; margin: 0 0 .25em; }
.fiche h4 .nm { color: %head%; font-weight: bold; }
.fiche p { text-align: left; margin: .2em 0; }
a.nm { color: %head%; }
p.oth, p.kidshead { font-style: italic; margin: .7em 0 .1em !important; }
ul.bul { margin: .1em 0 .3em 0; padding-left: 1.1em; list-style: disc; }
ul.bul li { margin: .1em 0; }
CSS;
        } elseif ($id === 'compact') {
            $css .= <<<CSS
body { line-height: 1.3; }
h2.part-title { font-size: 17pt; margin-bottom: .4em; }
h3.gen { font-size: 11.5pt; letter-spacing: .1em; margin: 1.3em 0 .5em; padding: .2em 0; border-top: 2px solid %head%; border-bottom: 1px solid %head%; }
.cols { column-count: 2; column-gap: .8cm; }
.cols h3.gen { break-inside: avoid; }
.fiche { margin: 0 0 .7em; overflow: visible; break-inside: avoid; }
.fiche img.ph { width: 1.8cm; margin: 0 0 .15cm .3cm; }
.fiche h4 { font-size: 10.5pt; }
.fiche p { margin: .15em 0; text-align: left; }
CSS;
        } elseif ($id === 'elegant') {
            $css .= <<<CSS
body { line-height: 1.55; }
h2.part-title { text-align: center; border-bottom: 0; font-size: 22pt; letter-spacing: .12em; font-variant: small-caps; margin-bottom: .9em; }
h3.gen { text-align: center; font-size: 13pt; letter-spacing: .08em; border-top: 2px solid %head%; border-bottom: 1px solid %head%; padding: .3em 0; margin: 2em 0 .9em; text-transform: uppercase; letter-spacing: .14em; }
.fiche { margin: 0 0 1.4em; }
.fiche img.ph { float: left; margin: 0 .6cm .3cm 0; width: 3cm; }
.fiche h4 { font-size: 12.5pt; font-variant: small-caps; letter-spacing: .03em; border-bottom: 1px solid #d8cfcf; padding-bottom: .1em; }
CSS;
        } elseif ($id === 'modern') {
            $css .= <<<CSS
h2.part-title { border-bottom: 3px solid %head%; }
h3.gen { background: %head%; color: #ffffff; font-size: 12pt; padding: .3em .6em; margin: 1.8em 0 .7em; text-align: center; text-transform: uppercase; letter-spacing: .14em; }
.fiche { border: 1px solid #ddd; border-left: 4px solid %head%; padding: .3cm .4cm; margin: 0 0 .5cm; overflow: hidden; }
.fiche img.ph { width: 2.6cm; }
.fiche h4 { font-size: 12pt; }
.fiche p { text-align: left; }
.num { color: %head%; }
CSS;
        }
        return $css;
    }

    private function cssRaw() {
        return <<<'CSS'
@page { size: A4; margin: 2cm 2cm 2.2cm 2cm; @bottom-center { content: counter(page); font-size: 9pt; color: #666; } }
body { font-family: Georgia, "DejaVu Serif", serif; font-size: 11pt; line-height: 1.45; color: %text%; margin: 0; }
a { color: inherit; text-decoration: none; }
a.ref { color: %head%; }
.cover { text-align: center; padding-top: 6cm; page-break-after: always; }
.cover h1 { font-size: 30pt; margin: 0 0 .6em; line-height: 1.2; color: %title%; }
.cover .author { font-size: 14pt; margin-top: 2em; }
.cover .gen-date { margin-top: 5cm; font-size: 10pt; color: %meta%; }
.part { page-break-before: always; }
h2.part-title { font-size: 22pt; margin: 0 0 .6em; padding-bottom: .2em; border-bottom: 2px solid %title%; color: %title%; }
h3.gen { font-size: 13pt; text-align: center; text-transform: uppercase; letter-spacing: .14em; margin: 2em 0 .8em; padding: .3em 0; color: %head%; border-top: 2px solid %head%; border-bottom: 1px solid %head%; page-break-after: avoid; }
.fiche { overflow: hidden; margin: 0 0 1.2em; page-break-inside: avoid; }
.fiche img.ph { float: right; width: 3.2cm; height: auto; margin: 0 0 .3cm .5cm; border: 1px solid #bbb; }
.fiche h4 { margin: 0 0 .3em; font-size: 12.5pt; color: %name%; }
.fiche p { margin: .25em 0; text-align: justify; }
.fiche img.ph.sp { float: left; width: 1.5cm; margin: .1cm .25cm .1cm 0; border: 1px solid #bbb; }
.num { font-weight: bold; color: %head%; margin-right: .3em; }
.yrs { font-weight: normal; color: %meta%; font-size: 10pt; }
.sx { color: %meta%; }
.fiche.hasnotes { page-break-inside: auto; }
.notes { margin: .5em 0 .3em; font-size: 10pt; }
.notes .nh { font-weight: bold; margin: 0 !important; }
.note { margin: .2em 0 .4em; text-align: justify; }
.evnote { margin: .1em 0 .5em 1.2em; font-size: 10pt; font-style: italic; text-align: justify; }
.tree { text-align: center; margin: 0 0 1.2em; page-break-inside: avoid; }
.tree.outline { text-align: left; page-break-inside: auto; margin-bottom: 1.4em; }
.otree, .otree ul { list-style: none; margin: 0; padding: 0; }
.otree ul { margin-left: .45em; padding-left: 1em; border-left: 1px solid %tree%; }
.otree li { margin: .18em 0; font-size: 10pt; }
.otree .on { font-weight: bold; }
p.mpf { margin: .5em 0; padding: .25em .6em; border-left: 3px solid #1f3a8a; background: #f4f6fb; }
p.mpf .fl { display: inline-block; width: 1.3em; height: .85em; vertical-align: -.1em; font-size: 100%; line-height: 0; }
p.mpf .fl i { display: inline-block; width: .43em; height: .85em; }
p.mpf .fl i:nth-child(1) { background: #1f3a8a; }
p.mpf .fl i:nth-child(2) { background: #ffffff; border-top: 1px solid #bbb; border-bottom: 1px solid #bbb; box-sizing: border-box; }
p.mpf .fl i:nth-child(3) { background: #c8102e; }
.otree .spn { font-style: italic; color: %meta%; }
.otree .mk { font-family: 'DejaVu Sans', 'Segoe UI Symbol', sans-serif; font-style: normal; }
.otree li.un { margin-top: .3em; }
.tree svg, .tree img { max-width: 100%; height: auto; }
.tree.boxes { text-align: left; page-break-inside: auto; }
.tree.boxes .tfig { margin: 0 0 .6em; page-break-inside: avoid; }
.stats table.st { border-collapse: collapse; width: 100%; font-size: 9.5pt; margin: .3em 0 .8em; }
.stats table.st th { text-align: left; color: %head%; border-bottom: 1.5px solid %tree%; padding: 2px 5px; }
.stats table.st td { padding: 2px 5px; border-bottom: 1px solid #e6dede; vertical-align: top; }
.stats table.st .r { text-align: right; }
.dlist table.st { border-collapse: collapse; width: 100%; font-size: 8pt; line-height: 1.3; margin: .3em 0; }
.dlist table.st th { text-align: left; color: %head%; border-bottom: 1.5px solid %tree%; padding: 2px 4px; }
.dlist table.st td { padding: 3px 4px; border-bottom: 1px solid #e6dede; vertical-align: top; }
.dlist table.st tr { page-break-inside: avoid; }
.dlist table.st .r { text-align: right; }
.dlist table.st td.gh { font-weight: bold; color: %head%; background: #f3eded; border-bottom: 1.5px solid %tree%; padding: 4px; font-size: 9pt; }
.dlist table.st .un { color: %meta%; font-size: 7.5pt; }
.dlist table.st tr.genrow { page-break-after: avoid; }
.stats .chart { text-align: center; margin: .2em 0 .8em; }
.cover-stats { margin-top: 1.2cm; font-size: 11pt; color: %meta%; }
.tree .cap { font-size: 9pt; color: %meta%; font-style: italic; text-align: center; margin: .3em 0 0; }
.kidshead { margin-bottom: 0 !important; font-style: italic; }
ul.kids { margin: .1em 0 .4em 1.2em; padding: 0; list-style: none; }
.src { font-size: 9pt; color: %meta%; }
.anon h4 { font-weight: normal; color: #666; }
.toc p { margin: .2em 0; } .toc .l2 { margin-left: 1.5em; font-size: 10pt; }
.notice p { text-align: justify; }
.surn table { border-collapse: collapse; margin: .6em 0; max-width: 100%; }
.surn th, .surn td { border: 1px solid #999; padding: .25em .5em; font-size: 10pt; vertical-align: top; }
.surn th { background: #eee; }
.surn blockquote { margin: .6em 1.5em; font-style: italic; }
.idx { column-count: 2; column-gap: 1cm; font-size: 10pt; }
.idx p { margin: .15em 0; break-inside: avoid; }
.idx h5 { margin: .8em 0 .2em; font-size: 12pt; color: %head%; break-after: avoid; }
ol.srcs { list-style: none; padding: 0; font-size: 10pt; }
.noprint { background: #f0f4f8; border: 1px solid #c7d2de; padding: 10px 14px; margin: 0 0 16px; font-family: sans-serif; font-size: 13px; }
.noprint button { padding: 6px 14px; cursor: pointer; }
@media print { .noprint { display: none; } }
CSS;
    }

    /* ---------- Assemblage ---------- */

    public function render() {
        $L = $this->L;
        $o = $this->o;
        $d = $this->d;

        $root = $o['root'];
        $rootName = $this->plain($root);
        $title = $o['title'] !== '' ? $o['title'] : str_replace('{name}', $rootName, $L['title_default']);
        $today = $this->dt(gmdate('Y-m-d'), '');

        $front = '<div class="cover"><h1>' . $this->e($title) . '</h1>';
        if ($o['author'] !== '') $front .= '<div class="author">' . $this->e($o['author']) . '</div>';
        $front .= $this->coverStats();
        $front .= '<div class="gen-date">' . $this->e(str_replace('{date}', $today, $L['generated'])) . '</div></div>';

        if ($o['preface'] !== '') {
            $front .= '<div class="part">' . $this->part('p-pref', $L['preface']) . '<div class="notice">'
                . wpautop(esc_html($o['preface'])) . '</div></div>';
        }

        $notice = '<div class="part notice">' . $this->part('p-howto', $L['notice']);
        if ($o['up'] > 0) $notice .= '<p>' . $this->e($L['howto_asc']) . '</p>';
        if ($o['down'] > 0) $notice .= '<p>' . $this->e($L['howto_desc']) . '</p>';
        $notice .= '</div>';

        // Les parties sont produites dans l'ordre technique (les index et les sources se nourrissent des fiches), puis assemblées dans l'ordre choisi.
        $parts = []; $tocs = []; $tocPre = $this->toc;
        $run = function ($k, $fn) use (&$parts, &$tocs) { $n = count($this->toc); $parts[$k] = $fn(); $tocs[$k] = array_slice($this->toc, $n); };
        if (trim((string) ($o['surname'] ?? '')) !== '') $run('surn', function () { return $this->surnamePart(); });
        if ($o['up'] > 0) $run('asc', function () { return $this->ascendance(); });
        if ($o['down'] > 0) $run('desc', function () { return $this->descendance(); });
        if (!empty($o['stats'])) $run('stats', function () { return $this->statsPart(); });
        if (!empty($o['cous']) && $o['up'] > 0) $run('cous', function () { return $this->cousinsPart(); });
        if (!empty($o['map'])) $run('map', function () { return $this->mapPart(); });
        if ($o['index']) { $run('idxn', function () { return $this->indexNames(); }); $run('idxp', function () { return $this->indexPlaces(); }); }
        $run('src', function () { return $this->sourcesPart(); });
        if (!empty($o['asclist']) && $o['up'] > 0) $run('alist', function () { return $this->ascListPart(); });
        if (!empty($o['desclist']) && $o['down'] > 0) $run('dlist', function () { return $this->descListPart(); });
        $order = TNGBook::clean_order($o['order'] ?? null);
        $body = ''; $this->toc = $tocPre;
        foreach ($order as $k) {
            if (!isset($parts[$k])) continue;
            $body .= $parts[$k];
            foreach ($tocs[$k] as $t) $this->toc[] = $t;
        }

        $toc = '<div class="part toc"><h2 class="part-title">' . $this->e($L['toc']) . '</h2>';
        foreach ($this->toc as $t) {
            $toc .= '<p class="l' . (int) $t[2] . '"><a href="#' . esc_attr($t[0]) . '">' . $this->e($t[1]) . '</a></p>';
        }
        $toc .= '</div>';

        $bar = '';
        if ($this->mode === 'html') {
            $bar = '<div class="noprint"><button type="button" onclick="window.print()">' . $this->e($L['print']) . '</button> <span>' . $this->e($L['print_hint']) . '</span></div>';
        }

        if ($this->mode === 'html' && ($this->o['living'] ?? '') === 'include') {
            $bar .= '<div class="noprint" style="border-left:4px solid #b32d2e;background:#fcf0f1;padding:8px 12px;margin:8px 0"><strong>⚠️ RGPD</strong> : ce livre contient des données de personnes vivantes ou privées. Ne le diffusez pas sans leur consentement.</div>';
        }

        if (!empty($this->o['debug']) && $this->dbg) {
            $bar .= '<div class="noprint"><strong>Diagnostic photos</strong><br>' . implode('<br>', array_map([$this, 'e'], $this->dbg)) . '</div>';
        }

        return '<!DOCTYPE html><html lang="' . esc_attr($this->lang) . '"><head><meta charset="utf-8"><title>' . $this->e($title)
            . '</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>' . $this->css() . '</style></head><body>'
            . $bar . $front . $notice . $toc . $body . '</body></html>';
    }
}

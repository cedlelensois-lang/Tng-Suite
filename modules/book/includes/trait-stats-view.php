<?php
/** Chapitre « En chiffres » : mise en page des statistiques (utilisé par TNGBook_Renderer). */
if (!defined('ABSPATH')) exit;

trait TNGBook_StatsView {
    private $statsCache = null;

    private function stats() {
        if ($this->statsCache === null) {
            $all = require TNGBOOK_DIR . 'includes/lang-stats.php';
            $this->L += $all[$this->lang] ?? $all['fr'];
            $this->statsCache = TNGBook_Stats::compute($this->d, (int) $this->o['up']);
        }
        return $this->statsCache;
    }

    private function nf($x, $dec = 1) {
        $en = $this->lang === 'en';
        $s = number_format((float) $x, $dec, $en ? '.' : ',', $en ? ',' : ' ');
        return $dec > 0 ? rtrim(rtrim($s, '0'), $en ? '.' : ',') : $s;
    }

    private function sm(array $s) { return $s['n'] ? $this->nf($s['mean']) . ' (' . $s['n'] . ')' : '–'; }

    /** $al : une lettre par colonne, « l » (gauche) ou « r » (droite, pour les nombres). */
    private function table(array $head, array $rows, $al = '') {
        $al = str_pad($al, count($head), 'r');
        $h = '<table class="st"><tr>';
        foreach ($head as $i => $c) $h .= '<th' . ($al[$i] === 'r' ? ' class="r"' : '') . '>' . $this->e($c) . '</th>';
        $h .= '</tr>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r as $i => $c) $h .= '<td' . ($al[$i] === 'r' ? ' class="r"' : '') . '>' . $c . '</td>';
            $h .= '</tr>';
        }
        return $h . '</table>';
    }

    /** Histogramme : dessin SVG, ou tableau à barres de texte dans les exports Word / OpenDocument. */
    private function histo($title, array $labels, array $vals) {
        $h = '<p class="nh">' . $this->e($title) . '</p>';
        if ($this->mode === 'doc') {
            $max = max(1, max($vals ?: [1]));
            $rows = [];
            foreach ($vals as $i => $v) $rows[] = [$this->e($labels[$i] ?? ''), (string) $v, str_repeat('█', (int) round(12 * $v / $max))];
            return $h . $this->table([$this->L['st_name'], $this->L['st_count'], ''], $rows, 'lrl');
        }
        return $h . '<div class="chart">' . TNGBook_Charts::bars($labels, $vals, $this->colors(), $this->mode, $title) . '</div>';
    }

    private function stSection($id, $title) {
        $this->toc[] = [$id, $title, 2];
        return '<h3 class="gen" id="' . esc_attr($id) . '">' . $this->e($title) . '</h3>';
    }

    private function topCell(array $t) { return $this->e($t[0]) . ' (' . $t[1] . ')'; }

    /** Ligne de chiffres clés de la page de titre. */
    private function coverStats() {
        if (empty($this->o['stats'])) return '';
        $s = $this->stats()['overview'];
        $p = [str_replace('{n}', $this->nf($s['people'], 0), $this->L['st_cover_people'])];
        if ($s['fams']) $p[] = str_replace('{n}', $this->nf($s['fams'], 0), $this->L['st_cover_fams']);
        if ($s['sources']) $p[] = str_replace('{n}', $this->nf($s['sources'], 0), $this->L['st_cover_src']);
        if ($s['min'] !== null) $p[] = $s['min'] . '–' . $s['max'];
        return '<div class="cover-stats">' . $this->e(implode(' · ', $p)) . '</div>';
    }

    private function statsPart() {
        $st = $this->stats(); $L = $this->L; $o = $st['overview'];
        $h = '<div class="part stats">' . $this->part('p-stats', $L['st_title']) . '<p class="src">' . $this->e($L['st_intro']) . '</p>';

        // vue d'ensemble
        $h .= $this->stSection('st-over', $L['st_overview']);
        $rows = [[$this->e($L['st_people']), $this->nf($o['people'], 0) . ' (' . $this->nf($o['M'], 0) . ' ' . $this->e($L['st_men']) . ', ' . $this->nf($o['F'], 0) . ' ' . $this->e($L['st_women']) . ($o['U'] ? ', ' . $this->nf($o['U'], 0) . ' ' . $this->e($L['st_unk']) : '') . ')'],
                 [$this->e($L['st_fams']), $this->nf($o['fams'], 0)]];
        if ($o['gen_asc'] > 1) $rows[] = [$this->e($L['st_gen_asc']), (string) $o['gen_asc']];
        if ($o['gen_desc'] > 1) $rows[] = [$this->e($L['st_gen_desc']), (string) $o['gen_desc']];
        if ($o['min'] !== null) $rows[] = [$this->e($L['st_period']), $o['min'] . ' – ' . $o['max']];
        if ($o['sources']) $rows[] = [$this->e($L['st_sources']), $this->nf($o['sources'], 0)];
        $h .= $this->table(['', ''], $rows, 'lr');

        // complétude de l'ascendance
        $cp = $st['complete'];
        if ($cp['rows'] && (int) $this->o['up'] > 0) {
            $h .= $this->stSection('st-comp', $L['st_complete']);
            $rows = [];
            foreach ($cp['rows'] as $r) {
                $rows[] = [(string) $r['g'], $this->nf($r['possible'], 0), $this->nf($r['known'], 0), $this->nf(100 * $r['known'] / $r['possible'], 0) . ' %', $r['implex'] ?: '–', $r['birth'] ?: '–', $r['death'] ?: '–'];
            }
            $h .= $this->table([$L['st_gen'], $L['st_possible'], $L['st_known'], $L['st_pct'], $L['st_implex'], $L['st_birth_known'], $L['st_death_known']], $rows, 'lrrrrrr')
                . '<p class="src">' . $this->e(str_replace(['{k}', '{m}'], [$this->nf($cp['distinct'], 0), $this->nf($cp['possible'], 0)], $L['st_complete_note'])) . '</p>';
        }

        // démographie
        $ad = $st['age_death'];
        $h .= $this->stSection('st-demo', $L['st_demo']);
        if ($ad['all']['n']) {
            $rows = [[$this->e($L['st_all']), $this->sm($ad['M']), $this->sm($ad['F']), $this->sm($ad['all'])]];
            foreach ($ad['by_century'] as $r) $rows[] = [$r['c'] . '–' . ($r['c'] + 99), $this->sm($r['M']), $this->sm($r['F']), $this->sm($r['A'])];
            $h .= '<p class="nh">' . $this->e($L['st_life']) . ' — ' . $this->e($L['st_mean']) . ' (' . $this->e($L['st_n']) . ')</p>'
                . $this->table([$L['st_century_d'], ucfirst($L['st_men']), ucfirst($L['st_women']), $L['st_all']], $rows, 'lrrr')
                . '<p class="src">' . $this->e($L['st_median']) . ' : ' . $this->nf($ad['all']['median']) . ' (' . $this->e($L['st_all']) . ')</p>';
            if ($this->mode !== 'doc' && count($ad['by_century']) > 1) {   // courbes d'espérance de vie (le tableau ci-dessus les donne pour Word / OpenDocument)
                $lab = []; $vm = []; $vf = []; $va = [];
                foreach ($ad['by_century'] as $r) {
                    $lab[] = (string) $r['c'];
                    $vm[] = $r['M']['n'] >= 3 ? round($r['M']['mean'], 1) : null;
                    $vf[] = $r['F']['n'] >= 3 ? round($r['F']['mean'], 1) : null;
                    $va[] = $r['A']['n'] ? round($r['A']['mean'], 1) : null;
                }
                $h .= '<div class="chart">' . TNGBook_Charts::lines($lab, [
                    ['name' => ucfirst($L['st_men']), 'vals' => $vm, 'color' => '#1d4ed8'],
                    ['name' => ucfirst($L['st_women']), 'vals' => $vf, 'color' => '#f472b6'],
                    ['name' => $L['st_all'], 'vals' => $va, 'color' => '#555555'],
                ], $this->colors(), $this->mode, $L['st_life']) . '</div>';
            }
            $bs = $ad['bands_sex'];
            $bl = ['0–9', '10–19', '20–29', '30–39', '40–49', '50–59', '60–69', '70–79', '80–89', '90–99', '100+'];
            if (array_sum($bs['M']) + array_sum($bs['F']) > 0) {
                $h .= '<p class="nh">' . $this->e($L['st_pyr']) . '</p>';
                if ($this->mode === 'doc') {
                    $rows = []; for ($i = count($bl) - 1; $i >= 0; $i--) $rows[] = [$bl[$i], (string) $bs['M'][$i], (string) $bs['F'][$i]];
                    $h .= $this->table([$L['st_age'], ucfirst($L['st_men']), ucfirst($L['st_women'])], $rows, 'lrr');
                } else {
                    $h .= '<div class="chart">' . TNGBook_Charts::pyramid($bl, $bs['M'], $bs['F'], $this->colors(), $this->mode, $L['st_pyr'], ucfirst($L['st_men']), ucfirst($L['st_women'])) . '</div>';
                }
            }
            $h .= $this->histo($L['st_h_death'], ['0–9', '10–19', '20–29', '30–39', '40–49', '50–59', '60–69', '70–79', '80–89', '90–99', '100+'], $ad['bands']);
        }
        $am = $st['age_marr']; $ac = $st['age_child'];
        if ($am['M']['n'] + $am['F']['n'] + $ac['M']['n'] + $ac['F']['n'] > 0) {
            $h .= '<p class="nh">' . $this->e($L['st_mean']) . ' (' . $this->e($L['st_n']) . ')</p>' . $this->table(['', ucfirst($L['st_men']), ucfirst($L['st_women'])], [
                [$this->e($L['st_age_marr']), $this->sm($am['M']), $this->sm($am['F'])],
                [$this->e($L['st_age_child']), $this->sm($ac['M']), $this->sm($ac['F'])],
            ], 'lrr');
        }
        $k = $st['kids'];
        if ($k['couples']) {
            $h .= '<p>' . $this->e($L['st_kids_avg']) . ' : <strong>' . $this->nf($k['avg']) . '</strong> (' . $this->nf($k['couples'], 0) . ')</p>';
            $h .= $this->histo($L['st_h_kids'], ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10+'], array_values($k['dist']));
            $h .= '<p class="nh">' . $this->e($L['st_biggest']) . '</p>';
            foreach ($k['big'] as $b) {
                $names = trim(($b['h'] !== '' ? $this->plain($b['h']) : '') . ($b['h'] !== '' && $b['w'] !== '' ? ' & ' : '') . ($b['w'] !== '' ? $this->plain($b['w']) : ''));
                $h .= '<p>' . $this->e($names) . ' — ' . $this->e(str_replace('{n}', $b['n'], $L['st_children_n'])) . '</p>';
            }
        }
        if (count($st['births']) > 1) {
            $labels = array_map('strval', array_keys($st['births']));
            $h .= $this->histo($L['st_h_births'], $labels, array_values($st['births']));
        }

        // mortalité infantile
        if ($st['infant']) {
            $h .= $this->stSection('st-inf', $L['st_infant']);
            $rows = [];
            foreach ($st['infant'] as $r) {
                $rows[] = [$r['c'] . '–' . ($r['c'] + 99), (string) $r['n'], $r['u1'] . ' (' . $this->nf(100 * $r['u1'] / $r['n'], 1) . ' %)', $r['u5'] . ' (' . $this->nf(100 * $r['u5'] / $r['n'], 1) . ' %)'];
            }
            $h .= $this->table([$L['st_century'], $L['st_n'], $L['st_before1'], $L['st_before5']], $rows, 'lrrr') . '<p class="src">' . $this->e($L['st_infant_note']) . '</p>';
        }

        // prénoms et noms
        $nm = $st['names'];
        $wf = !empty($this->o['stats_first']);   // options : prénoms / noms de famille les plus fréquents
        $wl = !empty($this->o['stats_last']);
        if ((($wf && ($nm['M'] || $nm['F'])) || ($wl && $nm['L']))) {
            $h .= $this->stSection('st-names', $wf && $wl ? $L['st_names'] : ($wf ? $L['st_names_first'] : $L['st_names_last']));
            if ($wf) {
                $rows = [];
                for ($i = 0; $i < 10; $i++) {
                    if (!isset($nm['M'][$i]) && !isset($nm['F'][$i])) break;
                    $rows[] = [(string) ($i + 1), isset($nm['M'][$i]) ? $this->topCell($nm['M'][$i]) : '', isset($nm['F'][$i]) ? $this->topCell($nm['F'][$i]) : ''];
                }
                $h .= '<p class="nh">' . $this->e($L['st_first_m']) . ' / ' . $this->e($L['st_first_f']) . '</p>' . $this->table(['#', ucfirst($L['st_men']), ucfirst($L['st_women'])], $rows, 'lll');
            }
            if ($wl) {
                $rows = [];
                foreach ($nm['L'] as $i => $t) $rows[] = [$this->e($t[0]), (string) $t[1], $nm['total'] ? $this->nf(100 * $t[1] / $nm['total'], 1) . ' %' : ''];
                $h .= '<p class="nh">' . $this->e($L['st_surnames']) . ' (' . $this->nf($nm['distinct'], 0) . ' ' . $this->e($L['st_distinct']) . ')</p>' . $this->table([$L['st_surname'], $L['st_count'], '%'], $rows, 'lrr');
            }
            if ($nm['by_century']) {
                $cell = function ($list) { return implode(', ', array_map(function ($t) { return $this->topCell($t); }, $list)); };
                $head = [$L['st_century']]; $al = 'l';
                if ($wf) { $head[] = ucfirst($L['st_men']); $head[] = ucfirst($L['st_women']); $al .= 'll'; }
                if ($wl) { $head[] = $L['st_surnames']; $al .= 'l'; }
                $rows = [];
                foreach ($nm['by_century'] as $r) {
                    $row = [$r['c'] . '–' . ($r['c'] + 99)];
                    if ($wf) { $row[] = $cell($r['M']); $row[] = $cell($r['F']); }
                    if ($wl) $row[] = $cell($r['L']);
                    $rows[] = $row;
                }
                $h .= '<p class="nh">' . $this->e($L['st_by_century']) . '</p>' . $this->table($head, $rows, $al);
            }
        }

        // géographie
        $pl = $st['places'];
        if ($pl['birth'] || $pl['marr'] || $pl['death']) {
            $h .= $this->stSection('st-geo', $L['st_geo']);
            $rows = [];
            for ($i = 0; $i < 10; $i++) {
                if (!isset($pl['birth'][$i]) && !isset($pl['marr'][$i]) && !isset($pl['death'][$i])) break;
                $rows[] = [isset($pl['birth'][$i]) ? $this->topCell($pl['birth'][$i]) : '', isset($pl['marr'][$i]) ? $this->topCell($pl['marr'][$i]) : '', isset($pl['death'][$i]) ? $this->topCell($pl['death'][$i]) : ''];
            }
            $h .= $this->table([$L['st_births_in'], $L['st_marr_in'], $L['st_deaths_in']], $rows, 'lll');
            if ($st['migr']['rows']) {
                $rows = [];
                foreach ($st['migr']['rows'] as $r) $rows[] = [$this->e($r[0]), $this->e($r[1]), (string) $r[2]];
                $h .= '<p class="nh">' . $this->e($L['st_migr']) . '</p>' . $this->table([$L['st_from'], $L['st_to'], $L['st_count']], $rows, 'llr')
                    . '<p class="src">' . $this->e(str_replace('{p}', (string) $st['migr']['pct'], $L['st_migr_note'])) . '</p>';
            }
        }

        // frise des ancêtres directs
        if (count($st['timeline']) >= 3 && $this->mode !== 'doc') {
            $h .= $this->stSection('st-time', $L['st_timeline']);
            $rows = [];
            foreach ($st['timeline'] as $t) {
                $rows[] = ['label' => $this->plain($t['pid']) . ' (A' . $t['n'] . ')', 'b' => $t['b'], 'd' => $t['d']];
            }
            $h .= '<div class="chart">' . TNGBook_Charts::timeline($rows, $this->colors(), $this->mode, $L['st_timeline']) . '</div>';
        }
        return $h . '</div>';
    }
    /** Carte des lieux de vie (option « map »). */
    /** Mode débogage de la carte : pour chaque personne, chaque lieu pris en compte et la raison de son classement. */
    private function mapDebug(array $pids) {
        $d = $this->d;
        $mp = TNGBook_Map::make();
        $rows = [];
        $stat = ['pers' => 0, 'noplace' => 0, 'unres' => 0];
        $only = [];
        $kind = ['R' => 'région', 'N' => 'pays seul', 'O' => 'autre pays'];
        foreach ($pids as $pid) {
            if (!isset($d->people[$pid]) || $d->hidden($pid) || $d->dropped($pid)) continue;
            $stat['pers']++;
            $ref = isset($d->sosaOf[$pid]) ? 'A' . $d->sosaOf[$pid] : (isset($d->desc[$pid]) ? 'D' . $d->desc[$pid]['num'] : '—');
            $labs = TNGBook_Map::placesLabelled($d, $pid);
            if (!$labs) { $stat['noplace']++; $rows[] = [$this->e($ref), $this->e($this->plain($pid)), '—', '<em>aucun lieu renseigné</em>', '<em>non localisée</em>']; continue; }
            $any = false;
            foreach ($labs as $i => $lb) {
                $co = $d->coords[$lb[1]] ?? null;
                $r = $mp->resolve($lb[1], $co);
                if ($r) $any = true;
                if ($r && $r['t'] === 'N') $only[] = [$this->e($ref), $this->e($this->plain($pid)), $this->e($lb[0]), $this->e($lb[1]), $this->e($r['w'] ?? '')];
                if (!$r) $res = '<em>non reconnu</em>';
                else {
                    $nm = $r['t'] === 'R' ? (TNGBook_Map::names()[$r['c']][$r['k']] ?? $r['k']) . ' (' . $r['c'] . ')' : ($r['t'] === 'N' ? TNGBook_Country::name($r['c'], $this->lang) : $r['l']);
                    $res = '<strong>' . $this->e($kind[$r['t']] . ' : ' . $nm) . '</strong><br>' . $this->e($r['w'] ?? '');
                }
                $rows[] = [$i === 0 ? $this->e($ref) : '', $i === 0 ? $this->e($this->plain($pid)) : '', $this->e($lb[0]), $this->e($lb[1]) . ($co ? '<br><span class="un">TNG : ' . $this->e(round($co[0], 3) . ', ' . round($co[1], 3)) . '</span>' : ''), $res];
            }
            if (!$any) $stat['unres']++;
        }
        $h = $this->stSection('map-debug', 'Débogage de la carte');
        $h .= '<p class="src">' . $this->e($stat['pers'] . ' personne(s) examinée(s) ; ' . $stat['noplace'] . ' sans aucun lieu renseigné ; ' . $stat['unres'] . ' avec des lieux mais aucun reconnu. « Pays seul » = un pays est écrit dans le lieu mais ni département, ni province, ni code postal, ni coordonnées TNG ne permettent d’aller plus loin.') . '</p>';
        if ($only) $h .= '<h4>Lieux classés « pays seul »</h4>' . $this->table(['Réf.', 'Personne', 'Origine du lieu', 'Lieu (texte TNG)', 'Raison'], $only, 'lllll');
        return $h . '<h4>Détail de toutes les personnes</h4>' . $this->table(['Réf.', 'Personne', 'Origine du lieu', 'Lieu (texte TNG)', 'Classement et raison'], $rows, 'llllll');
    }

    private function regionName($name, $c) {
        return !empty($this->o['regions']) ? (TNGBook_Country::region($name, $this->lang, $c) ?? $name) : $name;
    }

    private function mapPart() {
        $all = require TNGBOOK_DIR . 'includes/lang-map.php';
        $this->L += $all[$this->lang] ?? $all['fr'];
        $L = $this->L;
        $d = $this->d;
        $pids = $d->featured();
        $mp = TNGBook_Map::make();
        $r = TNGBook_Map::compute($d, $pids);
        foreach ($r['other'] as $k => $o) $r['other'][$k]['label'] = TNGBook_Country::label($k, $o['label'], $this->lang);
        $names = TNGBook_Map::names();
        $h = '<div class="part stats">' . $this->part('p-map', $L['map_title']) . '<p class="src">' . $this->e($L['map_intro']) . '</p>';
        if (!$r['maps'] && !$r['other']) return $h . '<p>' . $this->e($L['map_none']) . '</p></div>';
        $hasAny = false;
        foreach (['FR', 'ES', 'BE'] as $c) {
            if (empty($r['maps'][$c])) continue;
            $cnt = $r['maps'][$c];
            $h .= $this->stSection('map-' . strtolower($c), $L['map_' . $c]);
            if ($this->mode !== 'doc') {
                $h .= '<div class="chart">' . $mp->svg($c, $cnt, $this->mode, $L['map_' . $c]) . '</div>'
                    . '<p class="src">' . $this->e($mp->hasGeo($c) ? $L['map_shade'] : $L['map_schema']) . '</p>';
            }
            $rows = []; $i = 0; $rest = 0; $nrest = 0;
            foreach ($cnt as $k => $n) {
                if ($i++ < 20) $rows[] = [$this->e($this->regionName($names[$c][$k] ?? $k, $c)), (string) $n];
                else { $rest += $n; $nrest++; }
            }
            if ($nrest) $rows[] = [$this->e(str_replace('{n}', (string) $nrest, $L['map_rest'])), (string) $rest];
            $h .= $this->table([$c === 'FR' ? $L['map_col_FR'] : $L['map_col_prov'], $L['map_n']], $rows, 'lr');
            $hasAny = true;
        }
        if ($r['other']) {
            $h .= $this->stSection('map-other', $L['map_other']);
            if ($this->mode !== 'doc') {
                $wm = $mp->svgWorld($r['other'], $this->mode, $L['map_other']);
                if ($wm !== '') $h .= '<div class="chart">' . $wm . '</div><p class="src">' . $this->e($L['map_schema']) . '</p>';
            }
            $rows = []; $i = 0;
            foreach ($r['other'] as $o) {
                if ($i++ >= 20) break;
                $rows[] = [$this->e($o['label']), (string) $o['n']];
            }
            $h .= $this->table([$L['map_col_country'], $L['map_n']], $rows, 'lr');
        }
        $dbgHtml = !empty($this->o['debug']) ? $this->mapDebug($pids) : '';
        $notes = [];
        if ($r['nodetail']) {
            $p = [];
            foreach ($r['nodetail'] as $c => $n) $p[] = $this->e(TNGBook_Country::name($c, $this->lang) . ' ' . $n);
            $notes[] = $this->e($L['map_nodetail']) . ' ' . implode(', ', $p) . '.';
        }
        if ($r['unlocated']) $notes[] = $this->e(str_replace('{n}', (string) $r['unlocated'], $L['map_unloc']));
        if ($r['approx']) $notes[] = $this->e(str_replace('{n}', (string) $r['approx'], $L['map_approx']));
        if ($notes) $h .= '<p class="src">' . implode(' ', $notes) . '</p>';
        return $h . $dbgHtml . '</div>';
    }
}

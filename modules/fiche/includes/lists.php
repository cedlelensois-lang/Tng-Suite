<?php
/**
 * Module « Listes de documents » : données brutes (le formatage est fait côté navigateur).
 * Actions AJAX : tngfg_asc (ascendants + Sosa), tngfg_desc (descendants + d'Aboville), tngfg_fiche (fiche individuelle).
 */
if (!defined('ABSPATH')) exit;

define('TNGFG_MAX_ROWS', 5000);

/* ------------------------------------------------------------------ */
/* Briques communes                                                    */
/* ------------------------------------------------------------------ */
function tngfg_in($db, $ids) {
    return implode(',', array_fill(0, count($ids), '%s'));
}
function tngfg_fail($db) {
    if (!empty($db->last_error)) wp_send_json_error(['msg' => 'Erreur de lecture TNG : vérifiez le préfixe des tables et la version de TNG.']);
}

/** Personnes par identifiants -> [personID => ligne brute] */
function tngfg_people_by_ids($db, $ids, $tree) {
    $ids = array_values(array_unique(array_filter($ids, 'strlen')));
    if (!$ids) return [];
    $P = tngfg_t('people');
    $sql = "SELECT personID, firstname, nickname, lastname, lnprefix, sex, birthdate, birthplace, baptdate, baptplace,
                   deathdate, deathplace, burialdate, burialplace, living, private
              FROM $P WHERE gedcom = %s AND personID IN (" . tngfg_in($db, $ids) . ')';
    $rows = $db->get_results($db->prepare($sql, array_merge([$tree], $ids)), ARRAY_A);
    tngfg_fail($db);
    $out = [];
    foreach ((array) $rows as $r) $out[$r['personID']] = $r;
    return $out;
}

/** Ligne brute -> tableau public (masqué si personne vivante/privée non autorisée) */
function tngfg_pout($id, $r) {
    if (!$r) return ['id' => (string) $id, 'sex' => '', 'first' => '', 'last' => '', 'missing' => true];
    if (!tngfg_private_ok($r)) return ['id' => (string) $id, 'sex' => $r['sex'], 'first' => '', 'last' => '', 'priv' => true];
    return [
        'id'    => (string) $id,
        'sex'   => $r['sex'],
        'first' => trim($r['firstname']),
        'nick'  => tngfg_nick($r),
        'last'  => trim($r['lnprefix'] . ' ' . $r['lastname']),
        'birth' => $r['birthdate'],  'birthp' => $r['birthplace'],
        'bapt'  => $r['baptdate'],   'baptp'  => $r['baptplace'],
        'death' => $r['deathdate'],  'deathp' => $r['deathplace'],
        'burial' => $r['burialdate'], 'burialp' => $r['burialplace'],
    ];
}
/** Vrai si la personne existe mais est vivante/privée et que l'option d'affichage est désactivée. */
function tngfg_hidden($people, $id) {
    return $id !== '' && $id !== null && isset($people[$id]) && !tngfg_private_ok($people[$id]);
}
function tngfg_pmap($people, $id) {
    return $id ? tngfg_pout($id, isset($people[$id]) ? $people[$id] : null) : null;
}

/** Famille dans laquelle chaque personne est ENFANT -> [personID => famille] */
function tngfg_parent_families($db, $ids, $tree) {
    $ids = array_values(array_unique(array_filter($ids, 'strlen')));
    if (!$ids) return [];
    $F = tngfg_t('families'); $C = tngfg_t('children');
    $sql = "SELECT c.personID AS cid, f.familyID, f.husband, f.wife, f.marrdate, f.marrplace
              FROM $C c JOIN $F f ON f.familyID = c.familyID AND f.gedcom = c.gedcom
             WHERE c.gedcom = %s AND c.personID IN (" . tngfg_in($db, $ids) . ')';
    $rows = $db->get_results($db->prepare($sql, array_merge([$tree], $ids)), ARRAY_A);
    tngfg_fail($db);
    $out = [];
    foreach ((array) $rows as $r) if (!isset($out[$r['cid']])) $out[$r['cid']] = $r;
    return $out;
}

/**
 * Unions de chaque personne -> [personID => [ [fid, spouse, marrdate, marrplace, kids[]] ... ]]
 * (triées par date de mariage, enfants dans l'ordre TNG)
 */
function tngfg_unions($db, $ids, $tree) {
    $ids = array_values(array_unique(array_filter($ids, 'strlen')));
    if (!$ids) return [];
    $F = tngfg_t('families'); $C = tngfg_t('children');
    $in = tngfg_in($db, $ids);
    $sql = "SELECT familyID, husband, wife, marrdate, marrplace FROM $F
             WHERE gedcom = %s AND (husband IN ($in) OR wife IN ($in)) ORDER BY marrdatetr, familyID";
    $fams = $db->get_results($db->prepare($sql, array_merge([$tree], $ids, $ids)), ARRAY_A);
    tngfg_fail($db);
    $fams = (array) $fams;
    $kids = [];
    if ($fams) {
        $fids = array_map(function ($f) { return $f['familyID']; }, $fams);
        $sql = "SELECT familyID, personID FROM $C WHERE gedcom = %s AND familyID IN (" . tngfg_in($db, $fids) . ') ORDER BY ordernum';
        foreach ((array) $db->get_results($db->prepare($sql, array_merge([$tree], $fids)), ARRAY_A) as $k) {
            $kids[$k['familyID']][] = $k['personID'];
        }
    }
    $out = [];
    foreach ($fams as $f) {
        $u = ['fid' => $f['familyID'], 'marrdate' => $f['marrdate'], 'marrplace' => $f['marrplace'],
              'kids' => isset($kids[$f['familyID']]) ? $kids[$f['familyID']] : []];
        foreach ([['husband', 'wife'], ['wife', 'husband']] as $pair) {
            $me = $f[$pair[0]];
            if ($me !== '' && in_array($me, $ids, true)) {
                $u2 = $u; $u2['spouse'] = $f[$pair[1]];
                $out[$me][] = $u2;
            }
        }
    }
    return $out;
}

function tngfg_req() {
    $id   = sanitize_text_field(wp_unslash(isset($_POST['id']) ? $_POST['id'] : ''));
    $tree = sanitize_text_field(wp_unslash(isset($_POST['tree']) ? $_POST['tree'] : ''));
    if ($id === '' || $tree === '') wp_send_json_error(['msg' => 'Personne non sélectionnée.']);
    return [$id, $tree];
}

/** Cherche $target dans l'ascendance de $root -> ['n' => sosa, 'g' => génération] ou null */
function tngfg_sosa_find($db, $root, $target, $tree, $maxGen = 25) {
    $level = [1 => $root];
    for ($g = 1; $g <= $maxGen && $level; $g++) {
        foreach ($level as $s => $id) if ($id === $target) return ['n' => $s, 'g' => $g];
        $fams = tngfg_parent_families($db, array_values($level), $tree);
        $next = [];
        foreach ($level as $s => $id) {
            if (!isset($fams[$id])) continue;
            if ($fams[$id]['husband'] !== '') $next[2 * $s]     = $fams[$id]['husband'];
            if ($fams[$id]['wife'] !== '')    $next[2 * $s + 1] = $fams[$id]['wife'];
        }
        if (count($next) > 4096) return null;
        $level = $next;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Ascendants (numérotation Sosa-Stradonitz)                           */
/* ------------------------------------------------------------------ */
add_action('wp_ajax_tngfg_asc', 'tngfg_ajax_asc');
add_action('wp_ajax_nopriv_tngfg_asc', 'tngfg_ajax_asc');
function tngfg_ajax_asc() {
    tngfg_guard();
    $db = tngfg_db();
    list($pid, $tree) = tngfg_req();
    $gens = max(1, min(15, (int) (isset($_POST['gens']) ? $_POST['gens'] : 6)));
    $me0 = tngfg_people_by_ids($db, [$pid], $tree);
    if (!$me0) wp_send_json_error(['msg' => 'Personne introuvable.']);
    if (!tngfg_private_ok($me0[$pid])) wp_send_json_error(['msg' => 'Personne vivante ou privée : activez l’option dans les réglages.']);

    // Conjoint de la personne de départ : sa première union
    $u0   = tngfg_unions($db, [$pid], $tree);
    $first = isset($u0[$pid][0]) ? $u0[$pid][0] : null;
    $level = [1 => ['id' => $pid, 'spouse' => $first ? $first['spouse'] : '', 'marr' => null]];

    $rows = []; $truncated = false;
    $seenAnc = [];   // implexe : ancêtre déjà rencontré -> n° Sosa de sa première apparition
    for ($g = 1; $g <= $gens && $level; $g++) {
        $ids = []; foreach ($level as $v) { $ids[] = $v['id']; if ($v['spouse'] !== '') $ids[] = $v['spouse']; }
        $people = tngfg_people_by_ids($db, $ids, $tree);
        $fams   = tngfg_parent_families($db, array_map(function ($v) { return $v['id']; }, $level), $tree);
        $next = [];
        foreach ($level as $s => $v) {
            if (count($rows) >= TNGFG_MAX_ROWS) { $truncated = true; break; }
            // Personnes vivantes/privées masquées : ni ligne, ni conjoint (la numérotation Sosa reste celle de la généalogie).
            if (!tngfg_hidden($people, $v['id'])) {
                $spHidden = tngfg_hidden($people, $v['spouse']);
                $row = [
                    'sosa' => $s, 'gen' => $g,
                    'p'    => tngfg_pmap($people, $v['id']),
                    'sp'   => $spHidden ? null : tngfg_pmap($people, $v['spouse']),
                    'marr' => $spHidden ? null : $v['marr'],
                ];
                if (isset($seenAnc[$v['id']])) $row['dup'] = $seenAnc[$v['id']];   // implexe : renvoi à la 1re apparition
                else $seenAnc[$v['id']] = $s;
                $rows[] = $row;
                if (isset($row['dup'])) continue;   // ses ascendants figurent déjà sous le premier numéro
            }
            if (isset($fams[$v['id']])) {
                $f = $fams[$v['id']];
                $m = ['date' => $f['marrdate'], 'place' => $f['marrplace']];
                if ($f['husband'] !== '') $next[2 * $s]     = ['id' => $f['husband'], 'spouse' => $f['wife'],    'marr' => $m];
                if ($f['wife'] !== '')    $next[2 * $s + 1] = ['id' => $f['wife'],    'spouse' => $f['husband'], 'marr' => $m];
            }
        }
        if ($truncated) break;
        $level = $next;
    }
    wp_send_json_success(['rows' => $rows, 'gens' => $gens, 'truncated' => $truncated]);
}

/* ------------------------------------------------------------------ */
/* Descendants (numérotation d'Aboville)                               */
/* ------------------------------------------------------------------ */
add_action('wp_ajax_tngfg_desc', 'tngfg_ajax_desc');
add_action('wp_ajax_nopriv_tngfg_desc', 'tngfg_ajax_desc');
function tngfg_ajax_desc() {
    tngfg_guard();
    $db = tngfg_db();
    list($pid, $tree) = tngfg_req();
    $gens = max(1, min(12, (int) (isset($_POST['gens']) ? $_POST['gens'] : 4)));
    $me0 = tngfg_people_by_ids($db, [$pid], $tree);
    if (!$me0) wp_send_json_error(['msg' => 'Personne introuvable.']);
    if (!tngfg_private_ok($me0[$pid])) wp_send_json_error(['msg' => 'Personne vivante ou privée : activez l’option dans les réglages.']);

    $level = [['id' => $pid, 'num' => '1']];
    $seen  = [$pid => '1'];   // implexe : personne déjà rencontrée -> son n° d'Aboville
    $rows  = []; $truncated = false;
    for ($g = 1; $g <= $gens && $level; $g++) {
        $ids    = array_map(function ($v) { return $v['id']; }, $level);
        $unions = tngfg_unions($db, $ids, $tree);
        $need   = $ids;
        foreach ($unions as $us) foreach ($us as $u) { if ($u['spouse'] !== '') $need[] = $u['spouse']; foreach ($u['kids'] as $kid) $need[] = $kid; }
        $people = tngfg_people_by_ids($db, $need, $tree);
        $next = [];
        foreach ($level as $v) {
            if (count($rows) >= TNGFG_MAX_ROWS) { $truncated = true; break; }
            if (isset($v['dup'])) {   // implexe : déjà décrit sous un autre numéro, on renvoie sans le redévelopper
                $rows[] = ['num' => $v['num'], 'gen' => $g, 'p' => tngfg_pmap($people, $v['id']), 'unions' => [], 'dup' => $v['dup']];
                continue;
            }
            $uOut = []; $k = 0;
            foreach (isset($unions[$v['id']]) ? $unions[$v['id']] : [] as $u) {
                $spHidden = tngfg_hidden($people, $u['spouse']);
                if ($spHidden) $uOut[] = ['sp' => null, 'marr' => ['date' => '', 'place' => '']];
                else $uOut[] = ['sp' => tngfg_pmap($people, $u['spouse']), 'marr' => ['date' => $u['marrdate'], 'place' => $u['marrplace']]];
                $ui = count($uOut) - 1; $refs = [];
                foreach ($u['kids'] as $kid) {
                    // Enfant vivant/privé masqué : il (et sa branche) disparaît, et le compteur ne le compte pas.
                    if (tngfg_hidden($people, $kid)) continue;
                    // Couple dont les deux conjoints descendent de la souche : les enfants sont déjà numérotés
                    // sous l'autre parent. On n'ajoute pas de n° ; on renvoie vers la ligne de cet autre parent.
                    if (isset($seen[$kid])) { $refs[preg_replace('/\.\d+$/', '', $seen[$kid])] = true; continue; }
                    $k++;
                    $num = $v['num'] . '.' . $k;
                    $seen[$kid] = $num;
                    $next[] = ['id' => $kid, 'num' => $num];
                }
                if ($refs) $uOut[$ui]['kidsRef'] = implode(', ', array_keys($refs));
            }
            $rows[] = ['num' => $v['num'], 'gen' => $g, 'p' => tngfg_pmap($people, $v['id']), 'unions' => $uOut];
        }
        if ($truncated) break;
        $level = $next;
    }
    wp_send_json_success(['rows' => $rows, 'gens' => $gens, 'truncated' => $truncated]);
}

/* ------------------------------------------------------------------ */
/* Fiche individuelle                                                  */
/* ------------------------------------------------------------------ */
add_action('wp_ajax_tngfg_fiche', 'tngfg_ajax_fiche');
add_action('wp_ajax_nopriv_tngfg_fiche', 'tngfg_ajax_fiche');
function tngfg_ajax_fiche() {
    tngfg_guard();
    $db = tngfg_db();
    list($pid, $tree) = tngfg_req();
    $E = tngfg_t('events'); $T = tngfg_t('eventtypes');

    $me = tngfg_people_by_ids($db, [$pid], $tree);
    if (!$me) wp_send_json_error(['msg' => 'Personne introuvable.']);
    if (!tngfg_private_ok($me[$pid])) wp_send_json_error(['msg' => 'Personne vivante ou privée : activez l’option dans les réglages.']);

    // Personne de référence Sosa (formulaire, sinon réglage)
    $rootId = sanitize_text_field(wp_unslash(isset($_POST['root']) ? $_POST['root'] : ''));
    if ($rootId === '') $rootId = tngfg_opt('sosa_root');
    $sosa = null; $rootName = '';
    if ($rootId !== '') {
        $rp = tngfg_people_by_ids($db, [$rootId], $tree);
        if ($rp) {
            $rootName = trim($rp[$rootId]['firstname'] . ' ' . $rp[$rootId]['lastname']);
            $sosa = tngfg_sosa_find($db, $rootId, $pid, $tree);
        }
    }

    // Événements personnels
    $ev = $db->get_results($db->prepare(
        "SELECT t.tag, t.description, e.info, e.eventdate, e.eventplace FROM $E e JOIN $T t ON t.eventtypeID = e.eventtypeID
          WHERE e.persfamID = %s AND e.gedcom = %s", $pid, $tree), ARRAY_A);
    tngfg_fail($db);
    $events = [];
    foreach ((array) $ev as $e) {
        $events[] = ['tag' => $e['tag'], 'desc' => $e['description'], 'info' => $e['info'], 'date' => $e['eventdate'], 'place' => $e['eventplace']];
    }

    // Parents + fratrie
    $parents = null;
    $pf = tngfg_parent_families($db, [$pid], $tree);
    if (isset($pf[$pid])) {
        $f = $pf[$pid];
        $C = tngfg_t('children');
        $sibIds = $db->get_col($db->prepare("SELECT personID FROM $C WHERE familyID = %s AND gedcom = %s ORDER BY ordernum", $f['familyID'], $tree));
        $sibUn  = tngfg_unions($db, $sibIds, $tree);
        $need   = array_merge([$f['husband'], $f['wife']], (array) $sibIds);
        $allKids = [];
        foreach ($sibUn as $us) foreach ($us as $u) { if ($u['spouse'] !== '') $need[] = $u['spouse']; foreach ($u['kids'] as $k) $allKids[] = $k; }
        $people = tngfg_people_by_ids($db, array_merge($need, $allKids), $tree);
        $sibs = [];
        foreach ((array) $sibIds as $i => $sid) {
            $first = isset($sibUn[$sid][0]) ? $sibUn[$sid][0] : null;
            $kidIds = [];
            foreach (isset($sibUn[$sid]) ? $sibUn[$sid] : [] as $u) $kidIds = array_merge($kidIds, $u['kids']);
            if (tngfg_hidden($people, $sid)) continue;   // frère/sœur vivant(e) masqué(e) : le rang ne compte que les personnes affichées
            $sibs[] = [
                'rank' => count($sibs) + 1, 'self' => $sid === $pid,
                'p'    => tngfg_pmap($people, $sid),
                'sp'   => ($first && !tngfg_hidden($people, $first['spouse'])) ? tngfg_pmap($people, $first['spouse']) : null,
                'kids' => array_values(array_map(function ($k) use ($people) { return tngfg_pmap($people, $k); },
                    array_filter($kidIds, function ($k) use ($people) { return !tngfg_hidden($people, $k); }))),
            ];
        }
        $parents = [
            'marr'   => ['date' => $f['marrdate'], 'place' => $f['marrplace']],
            'father' => tngfg_pmap($people, $f['husband']),
            'mother' => tngfg_pmap($people, $f['wife']),
            'sibs'   => $sibs,
        ];
    }

    // Unions et enfants
    $un = tngfg_unions($db, [$pid], $tree);
    $unions = [];
    if (!empty($un[$pid])) {
        $need = []; $kidIds = [];
        foreach ($un[$pid] as $u) { if ($u['spouse'] !== '') $need[] = $u['spouse']; foreach ($u['kids'] as $k) { $need[] = $k; $kidIds[] = $k; } }
        $kidUn = tngfg_unions($db, $kidIds, $tree);
        $gk = [];
        foreach ($kidUn as $us) foreach ($us as $u) foreach ($u['kids'] as $k) { $gk[] = $k; $need[] = $k; }
        $people = tngfg_people_by_ids($db, $need, $tree);
        foreach ($un[$pid] as $u) {
            $kids = [];
            foreach ($u['kids'] as $k) {
                if (tngfg_hidden($people, $k)) continue;
                $gkids = [];
                foreach (isset($kidUn[$k]) ? $kidUn[$k] : [] as $ku) foreach ($ku['kids'] as $x) { if (!tngfg_hidden($people, $x)) $gkids[] = tngfg_pmap($people, $x); }
                $kids[] = ['p' => tngfg_pmap($people, $k), 'kids' => $gkids];
            }
            $spHidden = tngfg_hidden($people, $u['spouse']);
            $unions[] = ['sp' => $spHidden ? null : tngfg_pmap($people, $u['spouse']), 'marr' => $spHidden ? ['date' => '', 'place' => ''] : ['date' => $u['marrdate'], 'place' => $u['marrplace']], 'kids' => $kids];
        }
    }

    wp_send_json_success([
        'p' => tngfg_pout($pid, $me[$pid]), 'sosa' => $sosa, 'rootName' => $rootName,
        'events' => $events, 'parents' => $parents, 'unions' => $unions,
    ]);
}

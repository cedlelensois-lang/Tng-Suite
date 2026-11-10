<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------ */
/* Dates / lieux                                                       */
/* ------------------------------------------------------------------ */
function tngfg_year($d) {
    return preg_match('/(\d{4})/', (string) $d, $m) ? (int) $m[1] : 0;
}
/** Langue des documents : valeur demandée par le navigateur (liste fermée), sinon réglage du module. */
function tngfg_lang() {
    $ok = ['fr', 'en', 'ca', 'es'];
    $l  = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
    return in_array($l, $ok, true) ? $l : tngfg_default_lang();
}
/** Date TNG (GEDCOM ou texte) -> texte dans la langue des documents. */
function tngfg_date_fr($d, $lang = null) {
    $d = trim((string) $d);
    if ($d === '') return '';
    $lang = $lang ?: tngfg_lang();
    $M = [
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'ca' => ['gener', 'febrer', 'març', 'abril', 'maig', 'juny', 'juliol', 'agost', 'setembre', 'octubre', 'novembre', 'desembre'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    ];
    $Q = [
        'fr' => ['abt' => 'vers ', 'bef' => 'avant ', 'aft' => 'après '],
        'en' => ['abt' => 'about ', 'bef' => 'before ', 'aft' => 'after '],
        'ca' => ['abt' => 'cap al ', 'bef' => 'abans de ', 'aft' => 'després de '],
        'es' => ['abt' => 'hacia ', 'bef' => 'antes de ', 'aft' => 'después de '],
    ];
    $idx = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    $pre = '';
    if (preg_match('/^(ABT|ABOUT|CAL|EST|CIRCA|C)\.?\s+(.*)$/i', $d, $m))   { $pre = $Q[$lang]['abt']; $d = $m[2]; }
    elseif (preg_match('/^BEF\.?\s+(.*)$/i', $d, $m))                        { $pre = $Q[$lang]['bef']; $d = $m[1]; }
    elseif (preg_match('/^AFT\.?\s+(.*)$/i', $d, $m))                        { $pre = $Q[$lang]['aft']; $d = $m[1]; }
    if (preg_match('/^(?:(\d{1,2})\s+)?([A-Za-z]{3})[A-Za-z]*\.?\s+(\d{4})$/', $d, $m) && isset($idx[strtoupper($m[2])])) {
        $mo = $idx[strtoupper($m[2])];
        $j  = $m[1] !== '' ? (int) $m[1] : 0;
        $mn = $M[$lang][$mo - 1];
        if ($lang === 'fr') {
            $out = ($j ? ($j === 1 ? '1er ' : $j . ' ') : '') . $mn . ' ' . $m[3];
        } elseif ($lang === 'en') {
            $out = ($j ? $j . ' ' : '') . $mn . ' ' . $m[3];
        } elseif ($lang === 'ca') {
            $out = ($j ? $j . (in_array($mo, [4, 8, 10], true) ? ' d’' : ' de ') : '') . $mn . ' de ' . $m[3];
        } else {
            $out = ($j ? $j . ' de ' : '') . $mn . ' de ' . $m[3];
        }
        return $pre . $out;
    }
    return $pre . $d;
}
function tngfg_marr_word() {
    $w = ['fr' => 'mariage', 'en' => 'marriage', 'ca' => 'matrimoni', 'es' => 'matrimonio'];
    return $w[tngfg_lang()];
}
function tngfg_place($p) {
    $parts = array_values(array_filter(array_map('trim', explode(',', (string) $p))));
    return implode(', ', array_slice($parts, 0, 2));
}

/* ------------------------------------------------------------------ */
/* Recherche (autocomplétion)                                          */
/* ------------------------------------------------------------------ */
add_action('wp_ajax_tngfg_search', 'tngfg_ajax_search');
add_action('wp_ajax_nopriv_tngfg_search', 'tngfg_ajax_search');
function tngfg_ajax_search() {
    tngfg_guard();
    $db   = tngfg_db();
    $term = trim(sanitize_text_field(wp_unslash(isset($_POST['term']) ? $_POST['term'] : '')));
    if (mb_strlen($term) < 2) wp_send_json_success([]);
    $words = array_slice(preg_split('/\s+/', $term), 0, 4);
    $where = [];
    foreach ($words as $w) {
        $like = '%' . $db->esc_like($w) . '%';
        $where[] = $db->prepare('(p.firstname LIKE %s OR p.lastname LIKE %s)', $like, $like);
    }
    $sql = 'AND ' . implode(' AND ', $where);
    if (!tngfg_opt('allow_living')) $sql .= ' AND p.living = 0 AND p.private = 0';
    if (tngfg_opt('tree')) $sql .= $db->prepare(' AND p.gedcom = %s', tngfg_opt('tree'));
    $P = tngfg_t('people'); $F = tngfg_t('families');
    $rows = $db->get_results(
        "SELECT p.personID, p.gedcom, p.firstname, p.nickname, p.lastname, p.lnprefix, p.sex, p.birthdate, p.deathdate,
                (SELECT COUNT(*) FROM $F f WHERE f.gedcom = p.gedcom AND (f.husband = p.personID OR f.wife = p.personID)) AS nf
           FROM $P p WHERE 1=1 $sql ORDER BY p.lastname, p.firstname LIMIT 12", ARRAY_A);
    $out = [];
    foreach ((array) $rows as $r) {
        $out[] = [
            'id'    => $r['personID'],
            'tree'  => $r['gedcom'],
            'name'  => trim(tngfg_first_nick($r) . ' ' . trim($r['lnprefix'] . ' ' . $r['lastname'])),
            'by'    => tngfg_year($r['birthdate']) ?: '',
            'dy'    => tngfg_year($r['deathdate']) ?: '',
            'union' => (int) $r['nf'],
        ];
    }
    wp_send_json_success($out);
}

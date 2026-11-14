<?php
/**
 * TNG Suite – module « Individu au hasard » (ex-plugin TNG Random Individual 2.0.0).
 * Shortcode : [tng_random_individual name="" tree=""]. Les vivants / privés sont toujours exclus.
 * Connexion, préfixe, URL de TNG et arbre par défaut : réglages communs.
 */

if (!defined('ABSPATH')) exit;

define('TRI_VERSION', TNGSUITE_VERSION);

/* -------------------------------------------------------------------------
 * Réglages : valeurs par défaut + accès
 * ---------------------------------------------------------------------- */

function tri_defaults() {
    return [
        'tri_photo_dir'     => 'photos',
        'tri_cache_minutes' => 720, // 12 h ; 0 = pas de cache
        'tri_show_photo'    => 1,
        'tri_avatar'        => 'portrait', // silhouette sans photo : portrait (cercle sur fond clair) ou round (rond, sans fond)
        'tri_show_name'     => 1,
        'tri_show_dates'    => 1,
        'tri_cache_version' => 1,
    ];
}

function tri_opt($name) {
    // Valeurs communes (TNG Suite > Général)
    switch ($name) {
        case 'tri_db_tree':      return tngs_gedcom();
        case 'tri_tng_url':      return tngs_site_url();
        case 'tri_table_prefix': return tngs_prefix();
    }
    $d = tri_defaults();
    return get_option($name, isset($d[$name]) ? $d[$name] : '');
}

/* Sanitizers (utilisés par l'admin et par le front) */

function tri_sanitize_dir($v) {
    $v     = str_replace('\\', '/', sanitize_text_field((string) $v));
    $parts = array_filter(explode('/', $v), function ($s) {
        return $s !== '' && $s !== '.' && $s !== '..';
    });
    return implode('/', $parts);
}

function tri_sanitize_prefix($v) {
    $v = preg_replace('/[^A-Za-z0-9_]/', '', (string) $v);
    return $v === '' ? 'tng_' : $v;
}


/* Page d'admin : chargée uniquement dans l'administration */
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'admin-page.php';
}

/* -------------------------------------------------------------------------
 * CSS : enregistré partout, chargé seulement si le shortcode est utilisé
 * ---------------------------------------------------------------------- */

function tri_register_styles() {
    wp_register_style('tri-style', plugin_dir_url(__FILE__) . 'css/style.css', [], TRI_VERSION);
}
add_action('wp_enqueue_scripts', 'tri_register_styles');

/* -------------------------------------------------------------------------
 * Connexion BDD TNG (instance unique partagée)
 *
 * - Connexion : mode choisi dans TNG Suite > Général (wp-config.php, saisie dans le plugin,
 *   ou base WordPress). Compte MySQL en SELECT seul conseillé.
 * - Sinon : on utilise la base WordPress ($wpdb) (tables TNG dans la même base).
 * ---------------------------------------------------------------------- */

function tri_has_dedicated_db() {
    return tngs_conn_dedicated();
}

function tri_get_db() {
    return tngs_db();
}

function tri_tables() {
    $p = tri_sanitize_prefix(tri_opt('tri_table_prefix'));
    return [
        'people'     => $p . 'people',
        'media'      => $p . 'media',
        'medialinks' => $p . 'medialinks',
    ];
}

function tri_has_column($db, $table, $column) {
    $key = 'tri_col_' . md5($table . '|' . $column);
    $c   = get_transient($key);
    if ($c !== false) return $c === 'yes';

    $found = (bool) $db->get_var(
        $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $db->esc_like($column))
    );
    set_transient($key, $found ? 'yes' : 'no', DAY_IN_SECONDS);
    return $found;
}

/* -------------------------------------------------------------------------
 * Requêtes
 * ---------------------------------------------------------------------- */

/**
 * Liste (mise en cache) des individus éligibles, au format "arbre<TAB>personID".
 * Remplace ORDER BY RAND() : le tirage se fait en PHP.
 */
function tri_get_ids($db, $t, $tree, $name) {
    $minutes = (int) tri_opt('tri_cache_minutes');
    $hide    = tngs_hide_living('random');
    $key     = 'tri_ids_' . md5(implode('|', [tri_opt('tri_cache_version'), $t['people'], $tree, $name, $hide ? 1 : 0]));

    if ($minutes > 0) {
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
    }

    $where = ['1 = 1'];
    $args  = [];

    if ($hide) {   // vivants / privés exclus (réglage TNG Suite > Général > Modules)
        $where[] = 'living = 0';
        if (tri_has_column($db, $t['people'], 'private')) {
            $where[] = '(private = 0 OR private IS NULL)';
        }
    }
    if ($tree !== '') {
        $where[] = 'gedcom = %s';
        $args[]  = $tree;
    }
    if ($name !== '') {
        $where[] = 'lastname = %s';
        $args[]  = $name;
    }

    $sql = "SELECT CONCAT(gedcom, CHAR(9), personID) FROM `{$t['people']}` WHERE " . implode(' AND ', $where);
    if ($args) {
        $sql = $db->prepare($sql, $args);
    }

    $ids = $db->get_col($sql);
    if ($db->last_error) {
        error_log('[TNG Random Individual] ' . $db->last_error);
        return null;
    }

    if ($ids && $minutes > 0) {
        set_transient($key, $ids, $minutes * MINUTE_IN_SECONDS);
    }
    return $ids;
}

/** Individu + vignette en une seule requête. */
function tri_get_person($db, $t, $tree, $personID) {
    $sql = "SELECT p.personID, p.gedcom, p.firstname, p.lastname, p.birthdate, p.deathdate, p.sex, m.thumbpath
            FROM `{$t['people']}` p
            LEFT JOIN `{$t['medialinks']}` ml
                   ON ml.personID = p.personID AND ml.gedcom = p.gedcom AND ml.defphoto = 1
            LEFT JOIN `{$t['media']}` m
                   ON m.mediaID = ml.mediaID AND m.mediatypeID = 'photos'
            WHERE p.personID = %s AND p.gedcom = %s
            ORDER BY (m.thumbpath IS NULL) ASC
            LIMIT 1";

    $row = $db->get_row($db->prepare($sql, $personID, $tree), ARRAY_A);
    if ($db->last_error) {
        error_log('[TNG Random Individual] ' . $db->last_error);
        return null;
    }
    return $row;
}

/* -------------------------------------------------------------------------
 * Affichage
 * ---------------------------------------------------------------------- */

function tri_error($public, $detail = '') {
    if ($detail !== '') {
        error_log('[TNG Random Individual] ' . $detail);
    }
    $msg = $public;
    if ($detail !== '' && current_user_can('manage_options')) {
        $msg .= ' (' . $detail . ')';
    }
    return '<p class="tri-error">' . esc_html($msg) . '</p>';
}

function tri_year($date) {
    return preg_match('/\d{4}/', (string) $date, $m) ? $m[0] : '';
}

function tri_render($p) {
    $base = trailingslashit(tri_opt('tri_tng_url'));
    $dir  = tri_sanitize_dir(tri_opt('tri_photo_dir'));
    $name = trim($p['firstname'] . ' ' . $p['lastname']);

    $url = add_query_arg(
        ['personID' => $p['personID'], 'tree' => $p['gedcom']],
        $base . 'getperson.php'
    );

    $thumb = trim((string) $p['thumbpath']);
    if ($thumb !== '') {
        $src = preg_match('#^https?://#i', $thumb)
            ? $thumb
            : $base . ($dir !== '' ? $dir . '/' : '') . ltrim($thumb, '/');
    } else {
        $sx  = strtoupper((string) $p['sex']);
        $img = ($sx === 'M' ? 'male' : ($sx === 'F' ? 'female' : 'unknown'));
        $src = plugin_dir_url(__FILE__) . 'img/' . $img . ('round' === tri_opt('tri_avatar') ? '-round.png' : '.jpg');
    }

    $out = '<div class="tri-wrapper">';

    if ((int) tri_opt('tri_show_photo')) {
        $out .= sprintf(
            '<a href="%1$s" target="_blank" rel="noopener"><img class="tri-photo" src="%2$s" alt="%3$s" loading="lazy"></a>',
            esc_url($url),
            esc_url($src),
            esc_attr($name)
        );
    }

    if ((int) tri_opt('tri_show_name')) {
        $out .= sprintf(
            '<div class="tri-name"><a href="%1$s" target="_blank" rel="noopener">%2$s</a></div>',
            esc_url($url),
            esc_html($name)
        );
    }

    if ((int) tri_opt('tri_show_dates')) {
        $birth = tri_year($p['birthdate']);
        $death = tri_year($p['deathdate']);
        if ($birth !== '' || $death !== '') {
            $out .= '<div class="tri-dates">'
                . esc_html(($birth !== '' ? $birth : '?') . ' - ' . ($death !== '' ? $death : '?'))
                . '</div>';
        }
    }

    return $out . '</div>';
}

/* -------------------------------------------------------------------------
 * Shortcode : [tng_random_individual name="..." tree="..."]
 * ---------------------------------------------------------------------- */

function tri_random_individual_shortcode($atts) {
    $atts = shortcode_atts(['name' => '', 'tree' => ''], $atts, 'tng_random_individual');

    $name = sanitize_text_field($atts['name']);
    $tree = sanitize_text_field($atts['tree']);
    if ($tree === '') {
        $tree = sanitize_text_field(tri_opt('tri_db_tree'));
    }

    if (tngs_site_url() === '') {
        return tri_error(__('Contenu momentanément indisponible.', 'tng-suite'), 'URL de TNG non configurée (TNG Suite > Général)');
    }

    $db = tri_get_db();
    if (!$db) {
        return tri_error(__('Contenu momentanément indisponible.', 'tng-suite'), 'Connexion TNG impossible');
    }

    $t   = tri_tables();
    $ids = tri_get_ids($db, $t, $tree, $name);
    if ($ids === null) {
        return tri_error(__('Contenu momentanément indisponible.', 'tng-suite'), 'Erreur SQL (voir le journal PHP)');
    }
    if (!$ids) {
        return tri_error(__('Aucun individu trouvé.', 'tng-suite'));
    }

    $pick = explode("\t", $ids[array_rand($ids)], 2);
    if (count($pick) !== 2) {
        return tri_error(__('Aucun individu trouvé.', 'tng-suite'));
    }

    $p = tri_get_person($db, $t, $pick[0], $pick[1]);
    if (!$p) {
        return tri_error(__('Aucun individu trouvé.', 'tng-suite'));
    }

    wp_enqueue_style('tri-style');
    return tri_render($p);
}
add_shortcode('tng_random_individual', 'tri_random_individual_shortcode');

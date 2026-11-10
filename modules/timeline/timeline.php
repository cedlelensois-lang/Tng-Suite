<?php
/**
 * TNG Suite – module « Frise chronologique » (ex-plugin TNG Family Timeline 3.1.0).
 * Shortcode : [tng_timeline]. Connexion, préfixe, URL et confidentialité : réglages communs.
 *
 * Fichiers requis dans modules/timeline/assets/ :
 *   vis-timeline-graph2d.min.js  /  vis-timeline-graph2d.min.css  (vis-timeline 7.7.3)
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité : pas d'accès direct.
}

define('TNGFT_VERSION', TNGSUITE_VERSION);
define('TNGFT_VIS_VERSION', '8.5.4'); // Version de vis-timeline fournie dans assets/
define('TNGFT_DIR', plugin_dir_path(__FILE__));
define('TNGFT_URL', plugin_dir_url(__FILE__));

/* ==========================================================================
 * 1. RÉGLAGES
 * ========================================================================== */

add_action('admin_menu', 'tngft_add_admin_menu');
add_action('admin_init', 'tngft_settings_init');

function tngft_valid_prefix($prefix) {
    return is_string($prefix) && preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix) === 1;
}

/**
 * Lecture centralisée et sécurisée des réglages (valeurs validées à la lecture
 * également, par défense en profondeur).
 */
function tngft_get_settings() {
    $o = get_option('tng_timeline_settings', array());
    if (!is_array($o)) {
        $o = array();
    }

    return array(
        // Valeurs communes (TNG Suite > Général).
        'tng_url'           => untrailingslashit(tngs_site_url()),
        'db_prefix'         => tngs_prefix(),
        'respect_privacy'   => tngs_hide_living('timeline') ? 1 : 0,
        // Valeurs propres au module.
        'historical_events' => isset($o['historical_events']) ? (string) $o['historical_events'] : '',
        'theme'             => (isset($o['theme']) && is_string($o['theme']) && isset(tngft_theme_choices()[$o['theme']])) ? $o['theme'] : 'classic',
        'color_scheme'      => (isset($o['color_scheme']) && is_string($o['color_scheme']) && isset(tngft_scheme_choices()[$o['color_scheme']])) ? $o['color_scheme'] : 'auto',
    );
}

function tngft_theme_choices() {
    return array(
        'classic' => __('Classique (apparence d\'origine)', 'tng-suite'),
        'modern'  => __('Moderne (carte arrondie, ombres douces, accents colorés)', 'tng-suite'),
    );
}

function tngft_scheme_choices() {
    return array(
        'auto'  => __('Automatique (suit le réglage clair/sombre du visiteur)', 'tng-suite'),
        'light' => __('Toujours clair', 'tng-suite'),
        'dark'  => __('Toujours sombre', 'tng-suite'),
    );
}

function tngft_add_admin_menu() {
    tngs_add_subpage(
        __('Configuration TNG Timeline', 'tng-suite'),
        __('Frise chronologique', 'tng-suite'),
        'tng_family_timeline',
        'tngft_options_page'
    );
}

function tngft_settings_init() {
    register_setting('tngTimelinePlugin', 'tng_timeline_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'tngft_sanitize_settings',
    ));

    add_settings_section(
        'tng_timeline_appearance_section',
        __('Apparence', 'tng-suite'),
        '__return_false',
        'tngTimelinePlugin'
    );
    add_settings_field('theme', __('Thème visuel', 'tng-suite'), 'tngft_render_field', 'tngTimelinePlugin', 'tng_timeline_appearance_section', array('field' => 'theme'));
    add_settings_field('color_scheme', __('Mode de couleurs', 'tng-suite'), 'tngft_render_field', 'tngTimelinePlugin', 'tng_timeline_appearance_section', array('field' => 'color_scheme'));

    add_settings_section(
        'tng_timeline_history_section',
        __('Événements historiques contextuels', 'tng-suite'),
        '__return_false',
        'tngTimelinePlugin'
    );

    add_settings_field(
        'historical_events',
        __('Liste des événements (format : AAAA-MM-JJ|Titre)', 'tng-suite'),
        'tngft_render_history_field',
        'tngTimelinePlugin',
        'tng_timeline_history_section'
    );
}

/**
 * Validation / nettoyage de tout ce qui est enregistré.
 */
function tngft_sanitize_settings($in) {
    $in = is_array($in) ? $in : array();

    return array(
        'theme'             => (isset($in['theme']) && is_string($in['theme']) && isset(tngft_theme_choices()[$in['theme']])) ? $in['theme'] : 'classic',
        'color_scheme'      => (isset($in['color_scheme']) && is_string($in['color_scheme']) && isset(tngft_scheme_choices()[$in['color_scheme']])) ? $in['color_scheme'] : 'auto',
        'historical_events' => tngft_clean_history(isset($in['historical_events']) ? $in['historical_events'] : ''),
    );
}

function tngft_render_field($args) {
    $s    = tngft_get_settings();
    $key  = $args['field'];
    $name = 'tng_timeline_settings[' . $key . ']';

    if ($key === 'respect_privacy') {
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            esc_attr($name),
            checked(1, $s['respect_privacy'], false),
            esc_html__('Masquer les personnes vivantes ou privées (recommandé)', 'tng-suite')
        );
        return;
    }

    if ($key === 'theme' || $key === 'color_scheme') {
        $choices = ($key === 'theme') ? tngft_theme_choices() : tngft_scheme_choices();
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($choices as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($s[$key], $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        if ($key === 'color_scheme') {
            echo '<p class="description">' . esc_html__('S\'applique uniquement au thème moderne.', 'tng-suite') . '</p>';
        }
        return;
    }

    printf(
        '<input type="text" name="%s" value="%s" class="regular-text">',
        esc_attr($name),
        esc_attr($s[$key])
    );
}

function tngft_render_history_field() {
    $s     = tngft_get_settings();
    $value = $s['historical_events'];
    if ($value === '') {
        $value = "1789-07-14|Prise de la Bastille\n1914-08-03|Début de la 1ère Guerre Mondiale\n1939-09-03|Début de la 2nde Guerre Mondiale";
    }
    printf(
        '<textarea name="tng_timeline_settings[historical_events]" rows="5" cols="50" class="large-text">%s</textarea>',
        esc_textarea($value)
    );
}

function tngft_general_section_callback() {
    echo '<p>' . wp_kses(
        __('La connexion, le préfixe, l\'URL de TNG et la confidentialité se règlent dans <strong>TNG Suite > Général</strong>.', 'tng-suite'),
        array('strong' => array(), 'code' => array())
    ) . '</p>';
}

function tngft_options_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // État de la configuration.
    $missing = tngs_conn_ready() ? array() : array(tngs_db_error_message());
    $db_ok = false;
    if (!$missing) {
        $db    = tngft_get_db();
        $db_ok = $db && $db->get_var('SELECT 1') !== null;
    }
    ?>
    <?php tngs_admin_header('Frise chronologique', array('module' => 'timeline')); ?>

        <?php if (isset($_GET['tngft_flushed'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Cache vidé.', 'tng-suite'); ?></p></div>
        <?php endif; ?>

        <h2><?php echo esc_html__('État', 'tng-suite'); ?></h2>
        <ul>
            <li>
                <?php echo tngft_assets_available() ? '✅' : '❌'; ?>
                <?php
                echo esc_html(tngft_assets_available()
                    ? __('Fichiers vis-timeline présents dans /assets/', 'tng-suite')
                    : __('Fichiers vis-timeline manquants dans /assets/ (modules/timeline/assets/)', 'tng-suite'));
                ?>
            </li>
        </ul>

        <form action="options.php" method="post">
            <?php
            settings_fields('tngTimelinePlugin');
            do_settings_sections('tngTimelinePlugin');
            submit_button();
            ?>
        </form>

        <hr>
        <h2><?php echo esc_html__('Cache', 'tng-suite'); ?></h2>
        <p><?php echo esc_html__('Les résultats sont mis en cache (6 h par défaut). Videz le cache après une modification dans TNG.', 'tng-suite'); ?></p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="tngft_flush_cache">
            <?php
            wp_nonce_field('tngft_flush_cache');
            submit_button(__('Vider le cache', 'tng-suite'), 'secondary');
            ?>
        </form>
    </div>
    <?php
}

/* ==========================================================================
 * 2. CACHE
 * ========================================================================== */

function tngft_cols_key($prefix) {
    $cred = tngs_conn_credentials();
    return 'tngft_cols_' . md5(tngs_conn_mode() . (is_array($cred) ? $cred['name'] : '') . $prefix);
}

function tngft_flush_cache() {
    update_option('tngft_cache_ver', (string) microtime(true));
    $s = tngft_get_settings();
    delete_transient(tngft_cols_key($s['db_prefix']));
}
add_action('tngs_flush_cache', 'tngft_flush_cache');
add_action('update_option_tng_timeline_settings', 'tngft_flush_cache');
add_action('add_option_tng_timeline_settings', 'tngft_flush_cache');

add_action('admin_post_tngft_flush_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Accès refusé.', 'tng-suite'), '', array('response' => 403));
    }
    check_admin_referer('tngft_flush_cache');
    tngft_flush_cache();
    wp_safe_redirect(add_query_arg(
        array('page' => 'tng_family_timeline', 'tngft_flushed' => '1'),
        admin_url('admin.php')
    ));
    exit;
});

/* ==========================================================================
 * 3. CONNEXION BDD (base TNG externe)
 * ========================================================================== */

/**
 * Retourne une instance wpdb connectée à la base TNG, ou false.
 * La connexion n'est ouverte qu'une fois par requête (et seulement si besoin).
 */
function tngft_get_db() {
    return tngs_db();
}

/**
 * Exécute une requête SELECT. Retourne un tableau (éventuellement vide) ou false en cas d'erreur SQL.
 */
function tngft_fetch($db, $sql, $params = array()) {
    $query = $params ? $db->prepare($sql, $params) : $sql;
    $rows  = $db->get_results($query, ARRAY_A);

    if ($db->last_error) {
        error_log('TNG Timeline : erreur SQL sur la base TNG.');
        return false;
    }
    return $rows ? $rows : array();
}

/**
 * Détecte (une fois par jour) la présence des colonnes living / private dans TNG.
 * Retourne false en cas d'erreur SQL (le plugin refuse alors d'afficher quoi que ce soit).
 */
function tngft_privacy_columns($db, $pfx) {
    $key    = tngft_cols_key($pfx);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $cols = array('people' => array(), 'families' => array());
    foreach (array_keys($cols) as $table) {
        // $pfx est validé par tngft_valid_prefix() ; le reste est constant.
        $found = $db->get_col("SHOW COLUMNS FROM `{$pfx}{$table}` WHERE Field IN ('living','private')");
        if ($db->last_error) {
            return false;
        }
        $cols[$table] = array_values(array_intersect(array('living', 'private'), (array) $found));
    }

    set_transient($key, $cols, DAY_IN_SECONDS);
    return $cols;
}

/**
 * Fragment SQL « AND … » excluant les enregistrements vivants / privés.
 * $nullable = true pour un LEFT JOIN (l'absence de ligne reste autorisée).
 */
function tngft_privacy_where($alias, $table, $cols, $enabled, $nullable = false) {
    if (!$enabled || empty($cols[$table])) {
        return '';
    }
    $conds = array();
    foreach ($cols[$table] as $col) { // noms de colonnes issus d'une liste blanche
        $conds[] = "COALESCE({$alias}.`{$col}`, 0) = 0";
    }
    $sql = implode(' AND ', $conds);
    if ($nullable) {
        $sql = "({$alias}.personID IS NULL OR ({$sql}))";
    }
    return ' AND ' . $sql;
}

/* ==========================================================================
 * 4. OUTILS (dates, liens, événements)
 * ========================================================================== */

function tngft_format_approx_date($date_str) {
    if ( preg_match( '/^\s*y\s*$/i', (string) $date_str ) ) {
        return '†';   // « Y » TNG : décédé, date inconnue
    }
    $map = array(
        '/\b(?:ABT|ABOUT)\b/i' => tngs_t('Vers'),
        '/\bBEF\b/i'           => tngs_t('Avant'),
        '/\bAFT\b/i'           => tngs_t('Après'),
        '/\bEST\b/i'           => tngs_t('Est.'),
    );
    return trim(preg_replace(array_keys($map), array_values($map), (string) $date_str));
}

/**
 * Position sur la frise : année de la date brute si présente, sinon date normalisée TNG.
 */
function tngft_date_position($date_real, $date_raw) {
    if (!empty($date_raw) && preg_match('/(\d{4})/', $date_raw, $m) && (int) $m[1] > 0) {
        return $m[1] . '-01-01';
    }
    if (!empty($date_real) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date_real, $m) && (int) $m[1] > 0) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], max(1, (int) $m[2]), max(1, (int) $m[3]));
    }
    return false;
}

function tngft_bounds($person) {
    $birth = tngft_date_position($person['birthdatetr'], $person['birthdate']);
    $death = tngft_date_position($person['deathdatetr'], $person['deathdate']);
    $start = null;
    $end   = null;

    if ($birth) {
        $start = sprintf('%04d-01-01', max(1, (int) substr($birth, 0, 4) - 10));
    }
    if ($death) {
        $end = sprintf('%04d-12-31', (int) substr($death, 0, 4) + 10);
    } elseif ($birth) {
        $end = sprintf('%04d-12-31', (int) substr($birth, 0, 4) + 110);
    }
    return array($start, $end);
}

function tngft_link($base, $file, $params) {
    if ($base === '') {
        return '';
    }
    // add_query_arg() n'encode pas les valeurs : on le fait explicitement.
    return add_query_arg(array_map('rawurlencode', $params), trailingslashit($base) . $file);
}

function tngft_name($first, $last) {
    $first = trim((string) $first);
    return trim(($first !== '' ? $first : '?') . ' ' . trim((string) $last));
}

/**
 * Fabrique un événement vis-timeline. Tout ce qui vient de la base est échappé ici.
 */
function tngft_event($id, $group, $class, $icon, $label, $start, $title, $url = '') {
    $inner = esc_html($icon . ' ' . $label);

    if ($url !== '') {
        $content = '<a class="tng-timeline-link" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';
    } else {
        $content = '<span class="tng-timeline-link">' . $inner . '</span>';
    }

    return array(
        'id'        => $id,
        'group'     => $group,
        'content'   => $content,
        'start'     => $start,
        'className' => $class,
        'title'     => esc_html($title),
    );
}

function tngft_family_event($f, $tng_url) {
    $pos = tngft_date_position($f['marrdatetr'], $f['marrdate']);
    if (!$pos) {
        return null;
    }
    $couple = tngft_name($f['h_first'], $f['h_last']) . ' & ' . tngft_name($f['w_first'], $f['w_last']);
    $m_date = $f['marrdate'] ? tngft_format_approx_date($f['marrdate']) : '?';
    $link   = tngft_link($tng_url, 'familygroup.php', array('familyID' => $f['familyID'], 'tree' => $f['gedcom']));

    return tngft_event('m_' . $f['familyID'], 'top', 'marriage', '💍', $couple, $pos, "Mariage : {$couple} ({$m_date})", $link);
}

function tngft_people_events($p, $tng_url) {
    $events = array();
    $name   = trim($p['firstname'] . ' ' . $p['lastname']);
    $b_date = $p['birthdate'] ? tngft_format_approx_date($p['birthdate']) : '?';
    $d_date = $p['deathdate'] ? tngft_format_approx_date($p['deathdate']) : 'inconnu';
    $span   = "{$name} (Nais: {$b_date} ✝ Décès: {$d_date})";
    $link   = tngft_link($tng_url, 'getperson.php', array('personID' => $p['personID'], 'tree' => $p['gedcom']));

    $birth = tngft_date_position($p['birthdatetr'], $p['birthdate']);
    if ($birth) {
        $events[] = tngft_event('b_' . $p['personID'], 'bottom', 'birth', '👶', $name, $birth, $span, $link);
    }
    $death = tngft_date_position($p['deathdatetr'], $p['deathdate']);
    if ($death) {
        $events[] = tngft_event('d_' . $p['personID'], 'bottom', 'death', '⚰️', $name, $death, $span, $link);
    }
    return $events;
}

/* --- Événements historiques saisis dans les réglages --------------------- */

function tngft_parse_history_line($line) {
    if (strpos($line, '|') === false) {
        return false;
    }
    list($date, $title) = array_map('trim', explode('|', $line, 2));

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return false;
    }
    $title = sanitize_text_field($title);
    if ($title === '') {
        return false;
    }
    return array($date, mb_substr($title, 0, 200));
}

function tngft_clean_history($text) {
    $clean = array();
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $parsed = tngft_parse_history_line($line);
        if ($parsed) {
            $clean[] = $parsed[0] . '|' . $parsed[1];
        }
        if (count($clean) >= 200) {
            break;
        }
    }
    return implode("\n", $clean);
}

function tngft_history_events($text, $start, $end) {
    $events = array();
    $i      = 1;

    foreach (preg_split('/\R/', (string) $text) as $line) {
        $parsed = tngft_parse_history_line($line);
        if (!$parsed) {
            continue;
        }
        list($date, $title) = $parsed;

        if ($start && $date < $start) continue;
        if ($end && $date > $end) continue;

        $d = explode('-', $date);
        $events[] = tngft_event(
            'hist_' . $i++, 'top', 'history', '🏛️', $title, $date,
            tngs_t('Événement :') . ' ' . $title . ' (' . $d[2] . '/' . $d[1] . '/' . $d[0] . ')'
        );
    }
    return $events;
}

/* ==========================================================================
 * 5. REQUÊTES TNG
 * ========================================================================== */

function tngft_people_select($pfx) {
    return "SELECT p.personID, p.firstname, p.lastname, p.birthdate, p.birthdatetr, p.deathdate, p.deathdatetr, p.gedcom
            FROM `{$pfx}people` p";
}

function tngft_family_select($pfx) {
    return "SELECT f.familyID, f.marrdatetr, f.marrdate, f.gedcom, f.husband, f.wife,
                   h.firstname AS h_first, h.lastname AS h_last,
                   w.firstname AS w_first, w.lastname AS w_last
            FROM `{$pfx}families` f
            LEFT JOIN `{$pfx}people` h ON f.husband = h.personID AND f.gedcom = h.gedcom
            LEFT JOIN `{$pfx}people` w ON f.wife = w.personID AND f.gedcom = w.gedcom";
}

/**
 * Construit les données de la frise. Retourne :
 *   array('events' => [...], 'start' => ?string, 'end' => ?string)
 * ou false en cas d'erreur technique (non mis en cache).
 */
function tngft_build_timeline($args) {
    $empty = array('events' => array(), 'start' => null, 'end' => null);

    $db = tngft_get_db();
    if (!$db) {
        return false;
    }

    $cfg  = tngft_get_settings();
    $pfx  = $cfg['db_prefix'];
    $priv = (bool) $cfg['respect_privacy'];
    $cols = tngft_privacy_columns($db, $pfx);
    if ($cols === false) {
        return false;
    }

    $fam_privacy = tngft_privacy_where('f', 'families', $cols, $priv)
                 . tngft_privacy_where('h', 'people', $cols, $priv, true)
                 . tngft_privacy_where('w', 'people', $cols, $priv, true);

    $events = array();
    $start  = null;
    $end    = null;
    $people = array();

    /* ---- Mode « famille » ------------------------------------------------ */
    if ($args['family'] !== '') {
        $sql    = tngft_family_select($pfx) . ' WHERE f.familyID = %s';
        $params = array($args['family']);
        if ($args['tree'] !== '') {
            $sql    .= ' AND f.gedcom = %s';
            $params[] = $args['tree'];
        }
        $families = tngft_fetch($db, $sql . $fam_privacy . ' LIMIT 1', $params);
        if ($families === false) {
            return false;
        }
        if (!$families) {
            return $empty;
        }

        $f      = $families[0];
        $gedcom = $f['gedcom'];

        $ev = tngft_family_event($f, $cfg['tng_url']);
        if ($ev) {
            $events[] = $ev;
        }

        $ids = array_values(array_filter(array($f['husband'], $f['wife'])));
        if ($ids) {
            $ph  = implode(',', array_fill(0, count($ids), '%s'));
            $sql = tngft_people_select($pfx)
                 . " WHERE p.gedcom = %s AND p.personID IN ($ph)"
                 . tngft_privacy_where('p', 'people', $cols, $priv);
            $people = tngft_fetch($db, $sql, array_merge(array($gedcom), $ids));
            if ($people === false) {
                return false;
            }
        }
    }
    /* ---- Mode « personne » ---------------------------------------------- */
    else {
        $sql    = tngft_people_select($pfx) . ' WHERE p.personID = %s';
        $params = array($args['person']);
        if ($args['tree'] !== '') {
            $sql    .= ' AND p.gedcom = %s';
            $params[] = $args['tree'];
        }
        $rows = tngft_fetch($db, $sql . tngft_privacy_where('p', 'people', $cols, $priv) . ' LIMIT 1', $params);
        if ($rows === false) {
            return false;
        }
        if (!$rows) {
            return $empty;
        }

        $person = $rows[0];
        $people = array($person);
        $gedcom = $person['gedcom'];
        list($start, $end) = tngft_bounds($person);

        // Deux requêtes simples plutôt qu'un « OR » qui empêche l'usage des index.
        $seen = array();
        foreach (array('husband', 'wife') as $role) { // noms de colonnes en dur
            $families = tngft_fetch(
                $db,
                tngft_family_select($pfx) . " WHERE f.gedcom = %s AND f.{$role} = %s" . $fam_privacy,
                array($gedcom, $person['personID'])
            );
            if ($families === false) {
                return false;
            }
            foreach ($families as $f) {
                if (isset($seen[$f['familyID']])) {
                    continue;
                }
                $seen[$f['familyID']] = true;
                $ev = tngft_family_event($f, $cfg['tng_url']);
                if ($ev) {
                    $events[] = $ev;
                }
            }
        }
    }

    foreach ($people as $p) {
        $events = array_merge($events, tngft_people_events($p, $cfg['tng_url']));
    }

    if ($cfg['historical_events'] !== '') {
        $events = array_merge($events, tngft_history_events($cfg['historical_events'], $start, $end));
    }

    return array('events' => $events, 'start' => $start, 'end' => $end);
}

/**
 * Version avec cache (transient). Les erreurs techniques ne sont jamais mises en cache.
 */
function tngft_get_timeline($args) {
    $ver = get_option('tngft_cache_ver', '1');
    $key = 'tngft_' . md5(wp_json_encode(array($args, $ver, TNGFT_VERSION)));

    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $result = tngft_build_timeline($args);
    if ($result === false) {
        return false;
    }

    $ttl = empty($result['events'])
        ? 15 * MINUTE_IN_SECONDS
        : (int) apply_filters('tngft_cache_ttl', 6 * HOUR_IN_SECONDS);

    set_transient($key, $result, $ttl);
    return $result;
}

/* ==========================================================================
 * 6. ASSETS (vis-timeline hébergé localement, chargé uniquement si nécessaire)
 * ========================================================================== */

function tngft_assets_available() {
    static $ok = null;
    if ($ok === null) {
        $ok = file_exists(TNGFT_DIR . 'assets/vis-timeline-graph2d.min.js')
           && file_exists(TNGFT_DIR . 'assets/vis-timeline-graph2d.min.css');
    }
    return $ok;
}

/* --- Thèmes visuels ------------------------------------------------------ */

function tngft_css_classic() {
    return '
        .tng-timeline-container { width: 100%; margin: 20px 0; font-family: sans-serif; }
        .vis-item { cursor: pointer; border-radius: 4px; font-size: 13px; padding: 0 !important; }
        .vis-item .vis-item-content { padding: 0 !important; width: 100%; height: 100%; }
        .tng-timeline-link { display: inline-block; width: 100%; height: 100%; padding: 4px 6px; text-decoration: none !important; color: inherit !important; }

        .vis-item.birth { background-color: #e3f2fd; border-color: #2196f3; color: #0d47a1; font-weight: bold; }
        .vis-item.marriage { background-color: #e8f5e9; border-color: #4caf50; color: #1b5e20; }
        .vis-item.death { background-color: #ffebee; border-color: #f44336; color: #b71c1c; }
        .vis-item.history { background-color: #f5f5f5; border-color: #9e9e9e; color: #616161; font-size: 11px; }

        .vis-labelset { display: none !important; }
        .vis-panel.vis-center { border-left: none !important; }
        .vis-timeline { border: none !important; }
        .vis-panel.vis-bottom, .vis-panel.vis-top { border-top: none !important; border-bottom: none !important; }
    ';
}

/**
 * Thème moderne : tout est piloté par des variables CSS (--tng-*), faciles à surcharger
 * depuis votre thème, par exemple : .tng-theme-modern { --tng-birth-ac: #8b5cf6; }
 */
function tngft_css_modern() {
    $light = '--tng-surface:#ffffff;--tng-border:#e5e7eb;--tng-text:#111827;--tng-muted:#6b7280;--tng-grid:#eef0f3;'
           . '--tng-birth-bg:#eff6ff;--tng-birth-fg:#1e40af;--tng-birth-ac:#3b82f6;'
           . '--tng-marriage-bg:#ecfdf5;--tng-marriage-fg:#065f46;--tng-marriage-ac:#10b981;'
           . '--tng-death-bg:#fff1f2;--tng-death-fg:#9f1239;--tng-death-ac:#f43f5e;'
           . '--tng-history-bg:#f8fafc;--tng-history-fg:#475569;--tng-history-ac:#94a3b8;';

    $dark  = '--tng-surface:#111827;--tng-border:#243041;--tng-text:#e5e7eb;--tng-muted:#9ca3af;--tng-grid:#1f2937;'
           . '--tng-birth-bg:rgba(59,130,246,.18);--tng-birth-fg:#bfdbfe;--tng-birth-ac:#60a5fa;'
           . '--tng-marriage-bg:rgba(16,185,129,.18);--tng-marriage-fg:#a7f3d0;--tng-marriage-ac:#34d399;'
           . '--tng-death-bg:rgba(244,63,94,.18);--tng-death-fg:#fecdd3;--tng-death-ac:#fb7185;'
           . '--tng-history-bg:rgba(148,163,184,.14);--tng-history-fg:#cbd5e1;--tng-history-ac:#94a3b8;';

    $css  = '.tng-theme-modern{' . $light . '}';
    $css .= '.tng-theme-modern.tng-scheme-dark{' . $dark . '}';
    $css .= '@media (prefers-color-scheme: dark){.tng-theme-modern.tng-scheme-auto{' . $dark . '}}';

    $css .= <<<'CSS'
.tng-theme-modern{width:100%;margin:20px 0;padding:10px 8px 6px;box-sizing:border-box;background:var(--tng-surface);color:var(--tng-text);border:1px solid var(--tng-border);border-radius:16px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.06);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;}
.tng-theme-modern .vis-timeline{border:none!important;background:transparent;font-family:inherit;}
.tng-theme-modern .vis-panel.vis-center,.tng-theme-modern .vis-panel.vis-left,.tng-theme-modern .vis-panel.vis-right,.tng-theme-modern .vis-panel.vis-top,.tng-theme-modern .vis-panel.vis-bottom{border-color:transparent!important;}
.tng-theme-modern .vis-labelset{display:none!important;}
.tng-theme-modern .vis-current-time{display:none;}
.tng-theme-modern .vis-time-axis .vis-text{color:var(--tng-muted);font-size:12px;font-weight:500;}
.tng-theme-modern .vis-time-axis .vis-grid.vis-minor{border-color:var(--tng-grid);}
.tng-theme-modern .vis-time-axis .vis-grid.vis-major{border-color:var(--tng-border);}
.tng-theme-modern .vis-item .vis-item-content{padding:0!important;width:100%;height:100%;}
.tng-theme-modern .vis-item.vis-box{border:none;border-radius:10px;padding:0!important;font-size:13px;cursor:pointer;transition:box-shadow .15s ease;}
.tng-theme-modern .vis-item.vis-box.vis-selected{outline:2px solid var(--tng-muted);}
.tng-theme-modern .vis-item.vis-box.birth{font-weight:600;}
.tng-theme-modern .vis-item.vis-box.history{font-size:11px;}
.tng-theme-modern .tng-timeline-link{display:inline-block;width:100%;height:100%;padding:5px 10px;box-sizing:border-box;text-decoration:none!important;color:inherit!important;}
.tng-theme-modern .vis-tooltip{background:var(--tng-surface);color:var(--tng-text);border:1px solid var(--tng-border);border-radius:10px;box-shadow:0 8px 24px rgba(16,24,40,.18);padding:8px 12px;font-size:12px;font-family:inherit;}
CSS;

    // Couleurs par type d'événement (boîte, trait de liaison et point).
    foreach (array('birth', 'marriage', 'death', 'history') as $t) {
        $css .= ".tng-theme-modern .vis-item.vis-box.{$t}{background:var(--tng-{$t}-bg);color:var(--tng-{$t}-fg);box-shadow:inset 3px 0 0 var(--tng-{$t}-ac),0 1px 2px rgba(16,24,40,.08);}";
        $css .= ".tng-theme-modern .vis-item.vis-box.{$t}:hover{box-shadow:inset 3px 0 0 var(--tng-{$t}-ac),0 4px 14px rgba(16,24,40,.18);}";
        $css .= ".tng-theme-modern .vis-item.vis-line.{$t}{border-color:var(--tng-{$t}-ac);}";
        $css .= ".tng-theme-modern .vis-item.vis-dot.{$t}{border-color:var(--tng-{$t}-ac);background:var(--tng-{$t}-ac);}";
    }

    return $css;
}

function tngft_register_assets() {
    if (!tngft_assets_available()) {
        return;
    }

    // Simple enregistrement : rien n'est chargé tant que le shortcode n'est pas utilisé.
    wp_register_style('tngft-vis', TNGFT_URL . 'assets/vis-timeline-graph2d.min.css', array(), TNGFT_VIS_VERSION);
    wp_register_script('tngft-vis', TNGFT_URL . 'assets/vis-timeline-graph2d.min.js', array(), TNGFT_VIS_VERSION, true);

    $cfg = tngft_get_settings();
    wp_add_inline_style('tngft-vis', $cfg['theme'] === 'modern' ? tngft_css_modern() : tngft_css_classic());

    // Un seul script d'initialisation pour toutes les frises de la page ;
    // les données sont lues dans un attribut data-* (aucun JSON injecté dans du JS).
    $init = <<<'JS'
(function () {
    function init() {
        if (typeof vis === 'undefined') { return; }
        document.querySelectorAll('.tng-timeline-canvas').forEach(function (el) {
            var cfg;
            try { cfg = JSON.parse(el.getAttribute('data-config')); } catch (e) { return; }
            new vis.Timeline(el, new vis.DataSet(cfg.items), new vis.DataSet(cfg.groups), cfg.options);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
    wp_add_inline_script('tngft-vis', $init);
}
add_action('wp_enqueue_scripts', 'tngft_register_assets');

/* ==========================================================================
 * 7. SHORTCODE  [tng_timeline person="I123" tree="arbre1"]  /  family="F45"
 * ========================================================================== */

/**
 * Message d'erreur technique : détaillé pour les administrateurs, générique (ou vide) pour les visiteurs.
 */
function tngft_notice($admin_msg, $public_msg = '') {
    if (current_user_can('manage_options')) {
        return '<p class="tng-timeline-error" style="color:#b32d2e;">' . esc_html($admin_msg) . '</p>';
    }
    return $public_msg !== '' ? '<p>' . esc_html($public_msg) . '</p>' : '';
}

function tngft_shortcode($atts) {
    $args = shortcode_atts(array('tree' => '', 'person' => '', 'family' => ''), $atts, 'tng_timeline');
    $args = array_map('sanitize_text_field', $args);

    if ($args['person'] === '' && $args['family'] === '') {
        return tngft_notice(
            'TNG Timeline : indiquez person="…" ou family="…" dans le shortcode.',
            'Aucun événement trouvé.'
        );
    }

    if (!tngft_assets_available()) {
        return tngft_notice('TNG Timeline : fichiers vis-timeline manquants dans le dossier /assets/ du plugin.');
    }

    $data = tngft_get_timeline($args);
    if ($data === false) {
        return tngft_notice(
            'TNG Timeline : erreur de connexion ou de requête sur la base TNG. Vérifiez la connexion dans TNG Suite > Général.',
            'La chronologie est momentanément indisponible.'
        );
    }
    if (empty($data['events'])) {
        return '<p>' . esc_html__('Aucun événement trouvé.', 'tng-suite') . '</p>';
    }

    $options = array(
        'zoomMin'     => 1000 * 60 * 60 * 24 * 30 * 12,
        'orientation' => array('axis' => 'top', 'item' => 'top'),
        'maxHeight'   => '500px',
        'zoomable'    => false,
        'stack'       => true,
    );
    if (!empty($data['start'])) {
        $options['start'] = $data['start'];
    }
    if (!empty($data['end'])) {
        $options['end'] = $data['end'];
    }

    $json = wp_json_encode(array(
        'items'   => $data['events'],
        'groups'  => array(array('id' => 'top', 'content' => ''), array('id' => 'bottom', 'content' => '')),
        'options' => $options,
    ));
    if (!$json) {
        return tngft_notice('TNG Timeline : échec d\'encodage JSON (vérifiez le jeu de caractères de la connexion dans TNG Suite > Général).');
    }

    wp_enqueue_style('tngft-vis');
    wp_enqueue_script('tngft-vis');

    $cfg     = tngft_get_settings();
    $classes = 'tng-timeline-container tng-theme-' . $cfg['theme'] . ' tng-scheme-' . $cfg['color_scheme'];

    return '<div class="' . esc_attr($classes) . '"><div id="' . esc_attr(wp_unique_id('tngft-timeline-')) . '" class="tng-timeline-canvas" data-config="' . esc_attr($json) . '"></div></div>';
}
add_shortcode('tng_timeline', 'tngft_shortcode');

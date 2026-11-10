<?php
/**
 * TNG Suite – module « Heatmap des lieux » (ex-plugin TNG Ancestors Heatmap 1.21).
 * Shortcode : [tng_heatmap]. Connexion, préfixe et confidentialité : réglages communs.
 */

if (!defined('ABSPATH')) exit; // Sécurité

define('TNG_HEATMAP_VERSION', TNGSUITE_VERSION);
define('TNG_HEATMAP_DIR', plugin_dir_path(__FILE__));
define('TNG_HEATMAP_URL', plugin_dir_url(__FILE__));

const TNG_HEATMAP_CACHE_KEY = 'tng_heatmap_data_v2';
const TNG_HEATMAP_ERROR_KEY = 'tng_heatmap_recent_error';
const TNG_HEATMAP_LOCK_KEY  = 'tng_heatmap_build_lock';
const TNG_HEATMAP_TYPES     = ['birth', 'baptism', 'marriage', 'death', 'burial'];

/* ==========================================================================
 * 0. CONNEXION À LA BASE TNG
 * ========================================================================== */

/**
 * Connexion à la base TNG (partagée avec tous les modules) ou WP_Error.
 */
function tng_heatmap_get_db() {
    $db = tngs_db();
    if (!$db) {
        return new WP_Error('tng_connect', tngs_db_error_message());
    }
    return $db;
}

function tng_heatmap_table_prefix() {
    return tngs_prefix();
}

/* ==========================================================================
 * 1. ADMIN : MENU, RÉGLAGES, CACHE
 * ========================================================================== */

add_action('admin_menu', 'tng_heatmap_add_menu');
function tng_heatmap_add_menu() {
    tngs_add_subpage('Configuration TNG Heatmap', 'Heatmap des lieux', 'tng-heatmap-settings', 'tng_heatmap_settings_page');
}

function tng_heatmap_sanitize_bool($v) {
    return empty($v) ? 0 : 1;
}

function tng_heatmap_color_sanitizer($default) {
    return function ($v) use ($default) {
        $c = sanitize_hex_color(is_string($v) ? trim($v) : '');
        return $c ? $c : $default;
    };
}

function tng_heatmap_sanitize_provider($v) {
    $allowed = ['carto_light', 'osm', 'carto_dark', 'esri_ocean'];
    return in_array($v, $allowed, true) ? $v : 'carto_light';
}

function tng_heatmap_sanitize_opacity($v) {
    $f = (float) str_replace(',', '.', (string) $v);
    return max(0.1, min(1.0, $f));
}

function tng_heatmap_sanitize_threshold($v) {
    return max(1, absint($v));
}

function tng_heatmap_sanitize_url($v) {
    return esc_url_raw(trim((string) $v), ['http', 'https']);
}

add_action('admin_init', 'tng_heatmap_settings_init');
function tng_heatmap_settings_init() {
    $g = 'tng_heatmap_group';

    register_setting($g, 'tng_search_url', ['sanitize_callback' => 'tng_heatmap_sanitize_url']);
    register_setting($g, 'tng_map_provider', ['sanitize_callback' => 'tng_heatmap_sanitize_provider']);

    foreach (['tng_show_number', 'tng_use_solid_circles', 'tng_enable_century_filter', 'tng_enable_surname_filter',
              'tng_enable_place_filter', 'tng_enable_distinct_colors'] as $opt) {
        register_setting($g, $opt, ['sanitize_callback' => 'tng_heatmap_sanitize_bool']);
    }

    register_setting($g, 'tng_color_low',  ['sanitize_callback' => tng_heatmap_color_sanitizer('#0000ff')]);
    register_setting($g, 'tng_color_med',  ['sanitize_callback' => tng_heatmap_color_sanitizer('#ffff00')]);
    register_setting($g, 'tng_color_high', ['sanitize_callback' => tng_heatmap_color_sanitizer('#ff0000')]);
    register_setting($g, 'tng_solid_circle_color', ['sanitize_callback' => tng_heatmap_color_sanitizer('#3388ff')]);

    register_setting($g, 'tng_threshold_low', ['sanitize_callback' => 'tng_heatmap_sanitize_threshold']);
    register_setting($g, 'tng_threshold_med', ['sanitize_callback' => 'tng_heatmap_sanitize_threshold']);
    register_setting($g, 'tng_solid_circle_opacity', ['sanitize_callback' => 'tng_heatmap_sanitize_opacity']);

    // Auto-réparation de la planification du rafraîchissement du cache
    if (!wp_next_scheduled('tng_heatmap_cron_refresh')) {
        wp_schedule_event(time() + 300, 'twicedaily', 'tng_heatmap_cron_refresh');
    }
}

// Changer l'option "inclure les vivants/privés" invalide le cache (les données changent).

add_action('admin_enqueue_scripts', 'tng_heatmap_admin_scripts');
function tng_heatmap_admin_scripts($hook) {
    if (strpos((string) $hook, 'tng-heatmap-settings') === false) return;
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_add_inline_script('wp-color-picker', 'jQuery(function($){ $(".tng-color-picker").wpColorPicker(); });');
}

// Bouton « Purger et régénérer le cache »
add_action('admin_post_tng_heatmap_purge', 'tng_heatmap_handle_purge');
function tng_heatmap_handle_purge() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', '', ['response' => 403]);
    }
    check_admin_referer('tng_heatmap_purge');
    tng_heatmap_clear_cache();
    $result = tng_heatmap_get_data(true);
    wp_safe_redirect(add_query_arg(
        ['page' => 'tng-heatmap-settings', 'tng_purged' => is_wp_error($result) ? 'err' : 'ok'],
        admin_url('admin.php')
    ));
    exit;
}

function tng_heatmap_settings_row_checkbox($name, $default, $label, $desc = '') {
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
    echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(1, (int) get_option($name, $default), false) . ' />';
    if ($desc !== '') echo ' <span class="description">' . esc_html($desc) . '</span>';
    echo '</td></tr>';
}

function tng_heatmap_settings_page() {
    if (!current_user_can('manage_options')) return;

    $current_provider = get_option('tng_map_provider', 'carto_light');
    $cached           = get_transient(TNG_HEATMAP_CACHE_KEY);
    $last_error       = get_option('tng_heatmap_last_error', '');
    ?>
    <?php tngs_admin_header('Heatmap des lieux', array('module' => 'heatmap')); ?>

        <?php if (isset($_GET['tng_purged'])): // phpcs:ignore WordPress.Security.NonceVerification ?>
            <?php if (sanitize_key(wp_unslash($_GET['tng_purged'])) === 'ok'): // phpcs:ignore WordPress.Security.NonceVerification ?>
                <div class="notice notice-success is-dismissible"><p>Cache purgé et régénéré.</p></div>
            <?php else: ?>
                <div class="notice notice-error is-dismissible"><p>Le cache a été purgé mais la régénération a échoué (voir « Dernière erreur » ci-dessous).</p></div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="tngs-box">
            <p>
                Recommandation : utilisez un utilisateur MySQL en <strong>lecture seule</strong> (<code>SELECT</code> sur les tables people, families et places uniquement).
            </p>
        </div>

        <h2>Cache des données</h2>
        <table class="form-table">
            <tr>
                <th scope="row">État</th>
                <td>
                    <?php if (is_array($cached)): ?>
                        Généré le <strong><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $cached['g'])); ?></strong>
                        — <?php echo esc_html(number_format_i18n(count($cached['p']))); ?> lieux,
                        <?php echo esc_html(number_format_i18n(count($cached['r']))); ?> lignes agrégées.
                    <?php else: ?>
                        Aucun cache pour le moment (il sera généré à la prochaine visite ou par la tâche planifiée).
                    <?php endif; ?>
                    <p class="description">Régénération automatique deux fois par jour.</p>
                    <?php if ($last_error): ?>
                        <p style="color:#b32d2e;"><strong>Dernière erreur :</strong> <?php echo esc_html($last_error); ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                        <input type="hidden" name="action" value="tng_heatmap_purge" />
                        <?php wp_nonce_field('tng_heatmap_purge'); ?>
                        <?php submit_button('Purger et régénérer le cache', 'secondary', 'submit', false); ?>
                    </form>
                </td>
            </tr>
        </table>

        <form method="post" action="options.php">
            <?php settings_fields('tng_heatmap_group'); ?>

            <h2>Paramètres généraux</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">URL recherche TNG</th>
                    <td>
                        <input type="url" name="tng_search_url" class="large-text" value="<?php echo esc_attr(get_option('tng_search_url')); ?>" />
                        <p class="description">
                            ⚠️ L’URL doit indiquer où est située la page placesearch.php<br>
                            Exemple : <code>https://genealogie.fr/tng/placesearch.php</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Personnes vivantes / privées</th>
                    <td>
                        <strong><?php echo tngs_hide_living('heatmap') ? 'Masquées' : '⚠️ Affichées (RGPD)'; ?></strong>
                        <p class="description">Réglage commun à tous les modules : <a href="<?php echo esc_url(admin_url('admin.php?page=tng-suite')); ?>">TNG Suite &gt; Général &gt; Modules</a>.</p>
                    </td>
                </tr>
            </table>

            <h2>Fonctionnalités avancées (modules optionnels)</h2>
            <table class="form-table">
                <?php
                tng_heatmap_settings_row_checkbox('tng_enable_century_filter', 1, 'Activer le filtre par siècle', 'Affiche une barre de sélection temporelle sous la carte.');
                tng_heatmap_settings_row_checkbox('tng_enable_surname_filter', 1, 'Activer le filtre par nom de famille', 'Permet de filtrer la carte sur un patronyme précis.');
                tng_heatmap_settings_row_checkbox('tng_enable_place_filter', 1, 'Activer la recherche par ville', 'Permet de centrer et zoomer rapidement sur une ville de votre arbre.');
                tng_heatmap_settings_row_checkbox('tng_enable_distinct_colors', 1, 'Couleurs distinctes par événement', 'Attribue une couleur fixe à chaque type d’événement.');
                ?>
            </table>

            <h2>Options d'affichage & intensité</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Fond de carte par défaut</th>
                    <td>
                        <select name="tng_map_provider">
                            <option value="carto_light" <?php selected($current_provider, 'carto_light'); ?>>CartoDB Positron</option>
                            <option value="osm" <?php selected($current_provider, 'osm'); ?>>OpenStreetMap</option>
                            <option value="carto_dark" <?php selected($current_provider, 'carto_dark'); ?>>CartoDB Dark Matter</option>
                            <option value="esri_ocean" <?php selected($current_provider, 'esri_ocean'); ?>>Esri World Ocean</option>
                        </select>
                    </td>
                </tr>
                <?php tng_heatmap_settings_row_checkbox('tng_show_number', 0, 'Afficher les chiffres au centre'); ?>
                <tr><th scope="row">Seuil faible max</th><td><input type="number" min="1" name="tng_threshold_low" value="<?php echo esc_attr(get_option('tng_threshold_low', '10')); ?>" /></td></tr>
                <tr><th scope="row">Seuil moyen max</th><td><input type="number" min="1" name="tng_threshold_med" value="<?php echo esc_attr(get_option('tng_threshold_med', '50')); ?>" /></td></tr>
            </table>

            <h2>Style par défaut des ronds (si couleur distincte désactivée)</h2>
            <table class="form-table">
                <?php tng_heatmap_settings_row_checkbox('tng_use_solid_circles', 0, 'Ronds opaques / visibles'); ?>
                <tr><th scope="row">Couleur par défaut</th><td><input type="text" name="tng_solid_circle_color" class="tng-color-picker" value="<?php echo esc_attr(get_option('tng_solid_circle_color', '#3388ff')); ?>" /></td></tr>
                <tr><th scope="row">Opacité de remplissage</th><td><input type="number" name="tng_solid_circle_opacity" step="0.1" min="0.1" max="1" value="<?php echo esc_attr(get_option('tng_solid_circle_opacity', '0.4')); ?>" /></td></tr>
            </table>

            <h2>Couleurs de la chaleur (heatmap)</h2>
            <table class="form-table">
                <tr><th scope="row">Intensité faible</th><td><input type="text" name="tng_color_low" class="tng-color-picker" value="<?php echo esc_attr(get_option('tng_color_low', '#0000ff')); ?>" /></td></tr>
                <tr><th scope="row">Intensité moyenne</th><td><input type="text" name="tng_color_med" class="tng-color-picker" value="<?php echo esc_attr(get_option('tng_color_med', '#ffff00')); ?>" /></td></tr>
                <tr><th scope="row">Intensité forte</th><td><input type="text" name="tng_color_high" class="tng-color-picker" value="<?php echo esc_attr(get_option('tng_color_high', '#ff0000')); ?>" /></td></tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/* ==========================================================================
 * 2. EXTRACTION DES DONNÉES (SQL) + CACHE
 * ========================================================================== */

/**
 * Expression SQL de l'année d'un événement.
 * Utilise la colonne normalisée *datetr de TNG si elle existe, sinon extrait l'année du texte
 * (REGEXP_SUBSTR : MySQL 8+ / MariaDB 10.0.5+).
 */
function tng_heatmap_year_expr($alias, $date_col, $tr_col, array $cols) {
    if (in_array($tr_col, $cols, true)) {
        return "NULLIF(CAST(SUBSTRING({$alias}.`{$tr_col}`, 1, 4) AS UNSIGNED), 0)";
    }
    return "IF({$alias}.`{$date_col}` REGEXP '[0-9]{4}', CAST(REGEXP_SUBSTR({$alias}.`{$date_col}`, '[0-9]{4}') AS UNSIGNED), NULL)";
}

/**
 * Construit la requête agrégée. Fonction pure (testable sans base).
 * Les lignes sont regroupées par lieu / type d'événement / siècle / patronyme.
 */
function tng_heatmap_build_sql($prefix, array $people_cols, array $family_cols, $include_private) {
    $t_people = "`{$prefix}people`";
    $t_fam    = "`{$prefix}families`";
    $t_places = "`{$prefix}places`";

    $priv_p = $include_private ? '' : ' AND COALESCE(t.living, 0) = 0 AND COALESCE(t.private, 0) = 0';
    $priv_f = $include_private ? '' : ' AND COALESCE(f.living, 0) = 0 AND COALESCE(f.private, 0) = 0';
    $priv_s = $include_private ? '' : ' AND COALESCE(s.living, 0) = 0 AND COALESCE(s.private, 0) = 0';

    $parts = [];

    $people_events = [
        ['birth',   'birthplace',    'birthdate',    'birthdatetr'],
        ['baptism', 'altbirthplace', 'altbirthdate', 'altbirthdatetr'],
        ['death',   'deathplace',    'deathdate',    'deathdatetr'],
        ['burial',  'burialplace',   'burialdate',   'burialdatetr'],
    ];
    foreach ($people_events as $ev) {
        list($type, $place_col, $date_col, $tr_col) = $ev;
        $year = tng_heatmap_year_expr('t', $date_col, $tr_col, $people_cols);
        $parts[] = "SELECT t.gedcom AS gedcom, t.`{$place_col}` AS place, '{$type}' AS ev_type, {$year} AS ev_year, UPPER(t.lastname) AS ln "
                 . "FROM {$t_people} t WHERE t.`{$place_col}` <> ''{$priv_p}";
    }

    $year_f = tng_heatmap_year_expr('f', 'marrdate', 'marrdatetr', $family_cols);
    foreach (['husband', 'wife'] as $role) {
        $parts[] = "SELECT f.gedcom AS gedcom, f.marrplace AS place, 'marriage' AS ev_type, {$year_f} AS ev_year, UPPER(s.lastname) AS ln "
                 . "FROM {$t_fam} f JOIN {$t_people} s ON s.personID = f.`{$role}` AND s.gedcom = f.gedcom "
                 . "WHERE f.marrplace <> '' AND s.lastname <> ''{$priv_f}{$priv_s}";
    }

    return "SELECT p.place, p.latitude, p.longitude, x.ev_type, x.century, x.ln, COUNT(*) AS intensite
FROM (
    SELECT e.gedcom, e.place, e.ev_type, e.ln,
        CASE WHEN e.ev_year IS NULL THEN NULL
             WHEN e.ev_year < 1600 THEN 16
             WHEN e.ev_year >= 1900 THEN 20
             ELSE FLOOR(e.ev_year / 100) + 1 END AS century
    FROM (" . implode(' UNION ALL ', $parts) . ") e
) x
JOIN {$t_places} p ON p.place = x.place AND p.gedcom = x.gedcom
WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND p.latitude <> '' AND p.longitude <> ''
GROUP BY p.place, p.latitude, p.longitude, x.ev_type, x.century, x.ln";
}

/**
 * Transforme les lignes SQL en structure compacte (dictionnaires de lieux et de patronymes).
 * Fonction pure (testable sans base).
 *
 * p : [[nom, lat, lng], ...]   s : [patronyme, ...]
 * r : [[idxLieu, idxPatronyme(-1 si vide), idxType, siècle(0 si inconnu), nombre], ...]
 */
function tng_heatmap_rows_to_data(array $rows) {
    $type_idx = array_flip(TNG_HEATMAP_TYPES);
    $places = [];
    $p_index = [];
    $surnames = [];
    $s_index = [];
    $out = [];

    foreach ($rows as $row) {
        if (!isset($type_idx[$row['ev_type']])) continue;
        if (!is_numeric($row['latitude']) || !is_numeric($row['longitude'])) continue;

        $lat = round((float) $row['latitude'], 5);
        $lng = round((float) $row['longitude'], 5);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) continue;

        $name = trim(wp_check_invalid_utf8((string) $row['place'], true));
        if ($name === '') continue;

        $pk = $name . "\0" . $lat . "\0" . $lng;
        if (!isset($p_index[$pk])) {
            $p_index[$pk] = count($places);
            $places[] = [$name, $lat, $lng];
        }

        $si = -1;
        $ln = trim(wp_check_invalid_utf8((string) $row['ln'], true));
        if ($ln !== '') {
            if (!isset($s_index[$ln])) {
                $s_index[$ln] = count($surnames);
                $surnames[] = $ln;
            }
            $si = $s_index[$ln];
        }

        $out[] = [$p_index[$pk], $si, $type_idx[$row['ev_type']], (int) $row['century'], (int) $row['intensite']];
    }

    return [
        'v' => 2,
        'g' => time(),
        't' => TNG_HEATMAP_TYPES,
        'p' => $places,
        's' => $surnames,
        'r' => $out,
    ];
}

/**
 * Interroge la base TNG et retourne la structure compacte, ou un WP_Error.
 */
function tng_heatmap_build_data() {
    $db = tng_heatmap_get_db();
    if (is_wp_error($db)) return $db;

    $prefix = tng_heatmap_table_prefix();
    if ($prefix === false) {
        return new WP_Error('tng_prefix', 'Préfixe de tables invalide (voir TNG Suite > Général).');
    }

    $include_private = !tngs_hide_living('heatmap');

    $people_cols = $db->get_col("SHOW COLUMNS FROM `{$prefix}people`");
    $family_cols = $db->get_col("SHOW COLUMNS FROM `{$prefix}families`");
    if (empty($people_cols) || empty($family_cols)) {
        return new WP_Error('tng_schema', 'Tables TNG introuvables (vérifiez la base et le préfixe dans TNG Suite > Général).');
    }

    // Échec "fermé" : sans les colonnes living/private on ne publie rien plutôt que d'exposer des vivants.
    $needed_p = $include_private ? ['gedcom'] : ['gedcom', 'living', 'private'];
    foreach ($needed_p as $c) {
        if (!in_array($c, $people_cols, true) || !in_array($c, $family_cols, true)) {
            return new WP_Error('tng_schema', "Colonne TNG attendue absente : {$c}.");
        }
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    $sql  = tng_heatmap_build_sql($prefix, $people_cols, $family_cols, $include_private);
    $rows = $db->get_results($sql, ARRAY_A);

    if ($db->last_error) {
        error_log('[tng-heatmap] Erreur SQL : ' . $db->last_error);
        return new WP_Error('tng_sql', 'Erreur SQL lors de la lecture de la base TNG (voir le journal PHP).');
    }

    return tng_heatmap_rows_to_data(is_array($rows) ? $rows : []);
}

function tng_heatmap_clear_cache() {
    delete_transient(TNG_HEATMAP_CACHE_KEY);
    delete_transient(TNG_HEATMAP_ERROR_KEY);
    delete_transient(TNG_HEATMAP_LOCK_KEY);
}

/**
 * Données avec cache (transient 24 h, rafraîchi par cron deux fois par jour).
 * $force = true : ignore cache, verrou et erreur récente.
 */
function tng_heatmap_get_data($force = false) {
    if (!$force) {
        $cached = get_transient(TNG_HEATMAP_CACHE_KEY);
        if (is_array($cached)) return $cached;

        $recent_error = get_transient(TNG_HEATMAP_ERROR_KEY);
        if ($recent_error) return new WP_Error('tng_recent_error', (string) $recent_error);

        if (get_transient(TNG_HEATMAP_LOCK_KEY)) {
            return new WP_Error('tng_building', 'Génération des données en cours.');
        }
    }

    set_transient(TNG_HEATMAP_LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS);
    $result = tng_heatmap_build_data();
    delete_transient(TNG_HEATMAP_LOCK_KEY);

    if (is_wp_error($result)) {
        $msg = $result->get_error_message();
        set_transient(TNG_HEATMAP_ERROR_KEY, $msg, 5 * MINUTE_IN_SECONDS); // évite de marteler la base en cas de panne
        update_option('tng_heatmap_last_error', $msg, false);
        return $result;
    }

    set_transient(TNG_HEATMAP_CACHE_KEY, $result, DAY_IN_SECONDS);
    delete_transient(TNG_HEATMAP_ERROR_KEY);
    update_option('tng_heatmap_last_error', '', false);
    return $result;
}

// Rafraîchissement planifié : les visiteurs ne paient jamais la requête.
add_action('tng_heatmap_cron_refresh', 'tng_heatmap_cron_refresh');
function tng_heatmap_cron_refresh() {
    tng_heatmap_get_data(true);
}

// Vidage du cache lors d'un « Vider tous les caches » ou d'un changement de réglage commun.
add_action('tngs_flush_cache', 'tng_heatmap_clear_cache');

/* ==========================================================================
 * 3. API REST (données publiques, mises en cache)
 * ========================================================================== */

add_action('rest_api_init', function () {
    register_rest_route('tng-heatmap/v1', '/data', [
        'methods'             => 'GET',
        'callback'            => 'tng_heatmap_rest_data',
        'permission_callback' => '__return_true', // mêmes données que la page publique du shortcode
    ]);
});

function tng_heatmap_rest_data() {
    $data = tng_heatmap_get_data();
    if (is_wp_error($data)) {
        // Message générique côté public ; le détail est dans l'admin et le journal PHP.
        return new WP_Error('tng_heatmap_unavailable', 'Données indisponibles.', ['status' => 503]);
    }
    $response = new WP_REST_Response($data);
    $response->header('Cache-Control', 'public, max-age=3600');
    return $response;
}

/* ==========================================================================
 * 4. ASSETS (Leaflet en enqueue, local si présent, sinon CDN avec SRI)
 * ========================================================================== */

function tng_heatmap_vendor_src($local_rel, $cdn_url) {
    if (file_exists(TNG_HEATMAP_DIR . $local_rel)) {
        return [TNG_HEATMAP_URL . $local_rel, false];
    }
    return [$cdn_url, true];
}

function tng_heatmap_register_assets() {
    if (wp_script_is('tng-heatmap', 'registered')) return;

    list($css_src, $css_cdn)   = tng_heatmap_vendor_src('assets/vendor/leaflet.css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
    list($js_src, $js_cdn)     = tng_heatmap_vendor_src('assets/vendor/leaflet.js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
    list($heat_src, $heat_cdn) = tng_heatmap_vendor_src('assets/vendor/leaflet-heat.js', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet.heat/0.2.0/leaflet-heat.js');

    wp_register_style('tng-leaflet', $css_src, [], $css_cdn ? '1.9.4' : TNG_HEATMAP_VERSION);
    wp_register_script('tng-leaflet', $js_src, [], $js_cdn ? '1.9.4' : TNG_HEATMAP_VERSION, true);
    wp_register_script('tng-leaflet-heat', $heat_src, ['tng-leaflet'], $heat_cdn ? '0.2.0' : TNG_HEATMAP_VERSION, true);

    wp_register_style('tng-heatmap', TNG_HEATMAP_URL . 'assets/heatmap.css', ['tng-leaflet'], TNG_HEATMAP_VERSION);
    wp_register_script('tng-heatmap', TNG_HEATMAP_URL . 'assets/heatmap.js', ['tng-leaflet', 'tng-leaflet-heat'], TNG_HEATMAP_VERSION, true);
    tngs_js_i18n('tng-heatmap');
}

// Intégrité (SRI) uniquement pour les fichiers servis par le CDN.
add_filter('script_loader_tag', 'tng_heatmap_sri_script', 10, 3);
function tng_heatmap_sri_script($tag, $handle, $src) {
    $sri = [
        'tng-leaflet' => ['https://unpkg.com/', 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo='],
    ];
    if (isset($sri[$handle]) && strpos($src, $sri[$handle][0]) === 0 && strpos($tag, 'integrity=') === false) {
        $tag = preg_replace('/<script (?=[^>]*\bsrc=)/', '<script integrity="' . esc_attr($sri[$handle][1]) . '" crossorigin="" ', $tag, 1);
    }
    return $tag;
}

add_filter('style_loader_tag', 'tng_heatmap_sri_style', 10, 4);
function tng_heatmap_sri_style($tag, $handle, $href, $media) {
    if ($handle === 'tng-leaflet' && strpos($href, 'https://unpkg.com/') === 0 && strpos($tag, 'integrity=') === false) {
        $tag = preg_replace('/<link /', '<link integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" ', $tag, 1);
    }
    return $tag;
}

/* ==========================================================================
 * 5. SHORTCODE
 * ========================================================================== */

add_shortcode('tng_heatmap', 'tng_heatmap_shortcode');
function tng_heatmap_shortcode() {
    static $instance = 0;
    static $config_printed = false;
    $instance++;

    // Pas de rendu dans les requêtes REST (aperçus de l'éditeur, indexation SEO) : on évite des chargements inutiles.
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return '';
    }

    tng_heatmap_register_assets();
    wp_enqueue_style('tng-heatmap');
    wp_enqueue_script('tng-heatmap');

    if (!$config_printed) {
        $config_printed = true;
        $provider = tng_heatmap_sanitize_provider(get_option('tng_map_provider', 'carto_light'));
        $cfg = [
            'restUrl'       => esc_url_raw(rest_url('tng-heatmap/v1/data')),
            'searchUrl'     => esc_url_raw((string) (get_option('tng_search_url') ?: (tngs_site_url() !== '' ? tngs_site_url() . 'placesearch.php' : '')), ['http', 'https']),
            'provider'      => $provider,
            'showNumber'    => (int) get_option('tng_show_number', 0),
            'useSolid'      => (int) get_option('tng_use_solid_circles', 0),
            'solidColor'    => tng_heatmap_color_sanitizer('#3388ff')(get_option('tng_solid_circle_color', '#3388ff')),
            'solidOpacity'  => tng_heatmap_sanitize_opacity(get_option('tng_solid_circle_opacity', 0.4)),
            'colorLow'      => tng_heatmap_color_sanitizer('#0000ff')(get_option('tng_color_low', '#0000ff')),
            'colorMed'      => tng_heatmap_color_sanitizer('#ffff00')(get_option('tng_color_med', '#ffff00')),
            'colorHigh'     => tng_heatmap_color_sanitizer('#ff0000')(get_option('tng_color_high', '#ff0000')),
            'thresholdLow'  => tng_heatmap_sanitize_threshold(get_option('tng_threshold_low', 10)),
            'thresholdMed'  => tng_heatmap_sanitize_threshold(get_option('tng_threshold_med', 50)),
            'distinctColors'=> (int) get_option('tng_enable_distinct_colors', 1),
        ];
        wp_add_inline_script(
            'tng-heatmap',
            'window.TNG_HEATMAP_CONFIG = ' . wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
            'before'
        );
    }

    $mod_century = (int) get_option('tng_enable_century_filter', 1) === 1;
    $mod_surname = (int) get_option('tng_enable_surname_filter', 1) === 1;
    $mod_place   = (int) get_option('tng_enable_place_filter', 1) === 1;

    $uid = 'tng-hm-' . $instance;

    ob_start();
    ?>
    <div class="tng-hm" id="<?php echo esc_attr($uid); ?>">
        <div class="tng-hm-filters">
            <div class="tng-hm-field">
                <label for="<?php echo esc_attr($uid); ?>-ev">Événement :</label>
                <select id="<?php echo esc_attr($uid); ?>-ev" class="tng-hm-ev">
                    <option value="all">Tous les événements</option>
                    <option value="birth">Naissances uniquement</option>
                    <option value="baptism">Baptêmes uniquement</option>
                    <option value="marriage">Mariages uniquement</option>
                    <option value="death">Décès uniquement</option>
                    <option value="burial">Sépultures uniquement</option>
                </select>
            </div>

            <?php if ($mod_surname): ?>
            <div class="tng-hm-field">
                <label for="<?php echo esc_attr($uid); ?>-sn">Nom de famille :</label>
                <input type="text" id="<?php echo esc_attr($uid); ?>-sn" class="tng-hm-surname" list="<?php echo esc_attr($uid); ?>-sn-list" placeholder="Ex : MARTIN, DUPONT..." autocomplete="off" />
                <datalist id="<?php echo esc_attr($uid); ?>-sn-list"></datalist>
                <button type="button" class="tng-hm-clear tng-hm-clear-surname" aria-label="Effacer le nom de famille">X</button>
            </div>
            <?php endif; ?>

            <?php if ($mod_place): ?>
            <div class="tng-hm-field tng-hm-field-right">
                <label for="<?php echo esc_attr($uid); ?>-pl">Aller à la ville :</label>
                <input type="text" id="<?php echo esc_attr($uid); ?>-pl" class="tng-hm-place" list="<?php echo esc_attr($uid); ?>-pl-list" placeholder="Rechercher un lieu..." autocomplete="off" />
                <datalist id="<?php echo esc_attr($uid); ?>-pl-list"></datalist>
                <button type="button" class="tng-hm-clear tng-hm-clear-place" aria-label="Effacer la ville">X</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="tng-hm-map-wrap">
            <div class="tng-hm-map"></div>
            <div class="tng-hm-status" role="status">Chargement de la carte…</div>
        </div>

        <?php if ($mod_century): ?>
        <div class="tng-hm-century">
            <span class="tng-hm-century-title">Période historique :</span>
            <div class="tng-hm-century-options">
                <label><input type="radio" name="<?php echo esc_attr($uid); ?>-century" value="all" checked /> Toutes époques</label>
                <label><input type="radio" name="<?php echo esc_attr($uid); ?>-century" value="16" /> Avant 1600 (Ancien Régime)</label>
                <label><input type="radio" name="<?php echo esc_attr($uid); ?>-century" value="17" /> XVIIe siècle (1600-1699)</label>
                <label><input type="radio" name="<?php echo esc_attr($uid); ?>-century" value="18" /> XVIIIe siècle (1700-1799)</label>
                <label><input type="radio" name="<?php echo esc_attr($uid); ?>-century" value="19" /> XIXe siècle (1800-1899)</label>
                <label><input type="radio" name="<?php echo esc_attr($uid); ?>-century" value="20" /> XXe &amp; XXIe (1900+)</label>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

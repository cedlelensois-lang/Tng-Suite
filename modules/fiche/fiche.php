<?php
/**
 * TNG Suite – module « Fiche & listes de documents » (ex-plugin TNG Fiche Généalogique 1.1.0).
 * Shortcode : [tng_listes] (liste des ascendants Sosa, liste des descendants d'Aboville, fiche individuelle :
 * PDF / Excel / Word).
 * Connexion TNG, préfixe, URL, arbre et « vivants / privés » : réglages communs de TNG Suite.
 * Champs affichés dans les documents : TNG Suite > Fiche & documents (modifiables sur la page si l'option est cochée).
 */
if (!defined('ABSPATH')) exit;

define('TNGFG_VER', TNGSUITE_VERSION);
define('TNGFG_DIR', plugin_dir_path(__FILE__));
define('TNGFG_URL', plugin_dir_url(__FILE__));

require_once TNGFG_DIR . 'includes/data.php';
require_once TNGFG_DIR . 'includes/lists.php';

/* ------------------------------------------------------------------ */
/* Réglages propres au module                                          */
/* ------------------------------------------------------------------ */
function tngfg_defaults() {
    return [
        'sosa_root'  => '',          // personID de la personne de référence (Sosa 1) pour les fiches
        'login'      => 0,           // réserver aux utilisateurs connectés
        'front'      => 1,           // les visiteurs peuvent cocher / décocher les champs sur la page
        'lang'       => 'auto',      // langue des documents : fr, en (britannique), ca, es ou auto (langue choisie dans TNG Suite > Général)
        'lang_front' => 1,           // les visiteurs peuvent choisir la langue des documents sur la page
        'fields'     => [],
    ];
}

/** Champs des documents : groupe => [ libellé, [ clé => libellé ] ]. Tout est coché par défaut. */
function tngfg_field_groups() {
    $cols = function ($first) {
        return [
            'num' => $first, 'name' => 'Nom (toujours affiché)', 'birth' => 'Date de naissance / baptême', 'birthp' => 'Lieu de naissance / baptême',
            'spouse' => 'Conjoint', 'union' => 'Date d’union', 'unionp' => 'Lieu d’union', 'death' => 'Date de décès / inhumation',
            'deathp' => 'Lieu de décès / inhumation', 'age' => 'Âge au décès',
        ];
    };
    return [
        'asc'    => ['Liste des ascendants (colonnes)', $cols('N° Sosa')],
        'desc'   => ['Liste des descendants (colonnes)', $cols('N° d’Aboville')],
        'fiche'  => ['Fiche individuelle (rubriques)', [
            'sosa' => 'N° Sosa et génération (si une personne de référence est définie)', 'job' => 'Profession', 'sign' => 'Mention « sait / ne sait pas signer »',
            'rubr' => 'Rubriques personnelles (événements personnalisés)', 'parents' => 'Parents', 'pages' => 'Âge des parents à la naissance',
            'legit' => 'Mention « légitime »', 'rank' => 'Rang dans la fratrie', 'sibs' => 'Frères et sœurs',
            'sibkids' => 'Conjoint et enfants des frères et sœurs', 'unions' => 'Conjoints et enfants', 'marr' => 'Détail du mariage (date et lieu)',
            'grand' => 'Petits-enfants (nombre et prénoms)', 'chrono' => 'Chronologie', 'ages' => 'Âges dans la chronologie',
            'kin' => 'Événements des proches dans la chronologie (conjoints, enfants, parents)',
        ]],
    ];
}

/** Champs réglés : groupe => [ clé => 0|1 ] (seuls les champs décochés valent 0). */
function tngfg_fields() {
    $o = tngfg_opt('fields');
    $o = is_array($o) ? $o : [];
    $out = [];
    foreach (tngfg_field_groups() as $g => $def) {
        $out[$g] = [];
        foreach ($def[1] as $k => $lab) {
            $out[$g][$k] = ($k !== 'name' && isset($o[$g][$k]) && (int) $o[$g][$k] === 0) ? 0 : 1;
        }
    }
    return $out;
}

/** Libellés envoyés au navigateur (cases « champs affichés » de la page). */
function tngfg_labels($group = null) {
    $g = tngfg_field_groups();
    if ($group !== null) return $g[$group][1];
    return ['asc' => $g['asc'][1], 'desc' => $g['desc'][1], 'fiche' => $g['fiche'][1]];
}

/** Langue par défaut des documents (fr, en, ca, es). « auto » suit la langue du site. */
function tngfg_default_lang() {
    $o = wp_parse_args(get_option('tngfg_options', []), tngfg_defaults());
    $l = (string) $o['lang'];
    if ($l === 'auto') $l = tngs_lang();   // fr ou en, selon TNG Suite > Général > Langue / Language
    return in_array($l, ['fr', 'en', 'ca', 'es'], true) ? $l : 'fr';
}

/** Lecture d'un réglage. Connexion, préfixe, URL, arbre et vivants viennent des réglages communs de la suite. */
function tngfg_opt($key = null) {
    $o = wp_parse_args(get_option('tngfg_options', []), tngfg_defaults());
    $o['prefix']       = tngs_prefix();
    $o['tng_url']      = tngs_site_url();
    $o['tree']         = tngs_gedcom();
    $o['allow_living'] = tngs_hide_living('fiche') ? 0 : 1;
    return $key === null ? $o : (isset($o[$key]) ? $o[$key] : '');
}

/* ------------------------------------------------------------------ */
/* Accès base TNG (connexion commune de TNG Suite)                     */
/* ------------------------------------------------------------------ */
function tngfg_db() {
    $db = tngs_db();
    if (!$db) {
        if (function_exists('wp_send_json_error') && wp_doing_ajax()) wp_send_json_error(['msg' => 'Base TNG indisponible : vérifiez la connexion dans TNG Suite > Général.']);
        return null;
    }
    return $db;
}
function tngfg_t($name) {
    return '`' . preg_replace('/[^A-Za-z0-9_]/', '', tngfg_opt('prefix') . $name) . '`';
}
/** Surnom TNG nettoyé (sans guillemets/parenthèses), '' s'il est vide ou identique au prénom. */
function tngfg_nick($r) {
    $n = trim((string) (isset($r['nickname']) ? $r['nickname'] : ''), " \t\n\r\"'“”«»()");
    $f = trim((string) (isset($r['firstname']) ? $r['firstname'] : ''));
    if ($n === '') return '';
    // Un « surnom » qui n'est qu'un des prénoms (ou le prénom entier) n'est pas un surnom : on l'ignore.
    $norm = function ($x) { return preg_replace('/[^a-z0-9]+/', ' ', strtolower(remove_accents($x))); };
    $nn = trim($norm($n));
    if ($nn === '' || $nn === trim($norm($f))) return '';
    if (in_array($nn, preg_split('/\s+/', trim($norm($f))), true)) return '';
    return $n;
}
/** « Prénoms (Surnom) » */
function tngfg_first_nick($r) {
    $f = trim((string) $r['firstname']);
    $n = tngfg_nick($r);
    return $n !== '' ? trim($f . ' (' . $n . ')') : $f;
}
function tngfg_guard() {
    check_ajax_referer('tngfg', 'nonce');
    if (tngfg_opt('login') && !is_user_logged_in()) {
        wp_send_json_error(['msg' => 'Connexion requise.'], 403);
    }
}
function tngfg_private_ok($row) {
    if (tngfg_opt('allow_living')) return true;
    return empty($row['living']) && empty($row['private']);
}

/* ------------------------------------------------------------------ */
/* Page de réglages                                                    */
/* ------------------------------------------------------------------ */
add_action('admin_menu', function () {
    tngs_add_subpage('Fiche généalogique et listes de documents', 'Fiche : réglages', 'tngfg', 'tngfg_settings_page');
});
add_action('admin_init', function () {
    register_setting('tngfg', 'tngfg_options', ['sanitize_callback' => 'tngfg_sanitize']);
});

function tngfg_sanitize($in) {
    $in  = is_array($in) ? $in : [];
    $o = [];
    $o['sosa_root'] = isset($in['sosa_root']) ? sanitize_text_field($in['sosa_root']) : '';
    $o['sosa_root'] = preg_replace('/[^\w\-.]/u', '', $o['sosa_root']);
    $o['login']   = empty($in['login']) ? 0 : 1;
    $o['front']   = empty($in['front']) ? 0 : 1;
    $o['lang_front'] = empty($in['lang_front']) ? 0 : 1;
    $o['lang']    = isset($in['lang']) && in_array($in['lang'], ['fr', 'en', 'ca', 'es', 'auto'], true) ? $in['lang'] : 'auto';
    // champs : une case décochée vaut 0
    $fields = [];
    foreach (tngfg_field_groups() as $g => $def) {
        foreach ($def[1] as $k => $lab) {
            $fields[$g][$k] = ($k === 'name' || !empty($in['fields'][$g][$k])) ? 1 : 0;
        }
    }
    $o['fields'] = $fields;
    return $o;
}

function tngfg_settings_page() {
    if (!current_user_can('manage_options')) return;
    $o  = wp_parse_args(get_option('tngfg_options', []), tngfg_defaults());
    $fl = tngfg_fields();
    $text = function ($k, $label, $help = '', $type = 'text') use ($o) {
        $n = 'tngfg_options[' . $k . ']';
        echo '<tr><th scope="row"><label for="tngfg_' . esc_attr($k) . '">' . esc_html(tngs_t($label)) . '</label></th><td>';
        if ($type === 'checkbox') {
            echo '<input type="checkbox" id="tngfg_' . esc_attr($k) . '" name="' . esc_attr($n) . '" value="1" ' . checked(1, $o[$k], false) . '>';
        } elseif ($type === 'lang') {
            echo '<select id="tngfg_lang" name="' . esc_attr($n) . '">';
            foreach (['fr' => 'Français', 'en' => 'English (UK)', 'ca' => 'Català', 'es' => 'Español', 'auto' => tngs_t('Langue du site')] as $v => $l) {
                echo '<option value="' . esc_attr($v) . '" ' . selected($o[$k], $v, false) . '>' . esc_html($l) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="text" class="regular-text" id="tngfg_' . esc_attr($k) . '" name="' . esc_attr($n) . '" value="' . esc_attr($o[$k]) . '">';
        }
        if ($help) echo '<p class="description">' . wp_kses_post(tngs_t($help)) . '</p>';
        echo '</td></tr>';
    };
    ?>
    <?php tngs_admin_header('Fiche & documents', array('module' => 'fiche')); ?>
      <p><?php echo esc_html(tngs_t('Insérez')); ?> <code>[tng_listes]</code> (<?php echo esc_html(tngs_t('listes d’ascendants / descendants et fiche individuelle')); ?>) <?php echo esc_html(tngs_t('dans une page ou un article.')); ?>
         <?php echo esc_html(tngs_t('La connexion TNG, le préfixe des tables, l’URL de TNG, l’arbre et la protection des personnes vivantes se règlent dans TNG Suite > Général.')); ?></p>
      <form method="post" action="options.php">
        <?php settings_fields('tngfg'); ?>
        <h2><?php echo esc_html(tngs_t('Champs affichés dans les documents')); ?></h2>
        <p class="description"><?php echo esc_html(tngs_t('Décochez ce que vous ne voulez pas voir. Pour les listes, les colonnes décochées disparaissent (PDF et Excel) ; pour la fiche individuelle, les rubriques décochées sont omises.')); ?></p>
        <table class="form-table">
          <?php foreach (tngfg_field_groups() as $g => $def) : ?>
            <tr><th scope="row"><?php echo esc_html(tngs_t($def[0])); ?></th><td>
              <?php foreach ($def[1] as $k => $lab) : $locked = ($k === 'name'); ?>
                <label style="display:inline-block;min-width:330px;margin:0 16px 4px 0"><input type="checkbox" name="tngfg_options[fields][<?php echo esc_attr($g); ?>][<?php echo esc_attr($k); ?>]" value="1" <?php checked(1, $fl[$g][$k]); echo $locked ? ' disabled checked' : ''; ?>> <?php echo esc_html(tngs_t($lab)); ?></label>
              <?php endforeach; ?>
            </td></tr>
          <?php endforeach; ?>
          <?php $text('front', 'Choix sur la page', 'Affiche aux visiteurs une case à cocher par champ, pré-réglée avec les choix ci-dessus (ils ne modifient que leur propre document).', 'checkbox'); ?>
        </table>
        <h2><?php echo esc_html(tngs_t('Langue des documents')); ?></h2>
        <table class="form-table"><?php
            $text('lang', 'Langue par défaut', 'Langue des textes des listes et de la fiche individuelle (dates, phrases, titres) : français, anglais (britannique), catalan ou espagnol.', 'lang');
            $text('lang_front', 'Choix de la langue sur la page', 'Affiche aux visiteurs une liste pour choisir la langue du document.', 'checkbox');
        ?></table>
        <h2><?php echo esc_html(tngs_t('Personne de référence')); ?></h2>
        <table class="form-table"><?php
            $text('sosa_root', 'Personne de référence (Sosa 1)', 'Identifiant TNG de la personne de départ de la numérotation Sosa (ex. <code>I1</code>) pour les fiches individuelles ; modifiable à chaque fiche.');
        ?></table>
        <h2><?php echo esc_html(tngs_t('Accès')); ?></h2>
        <table class="form-table"><?php $text('login', 'Réserver aux utilisateurs connectés', '', 'checkbox'); ?></table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Scripts : site et administration                                    */
/* ------------------------------------------------------------------ */
function tngfg_register_assets() {
    wp_register_style('tngfg', TNGFG_URL . 'assets/tngfg.css', [], TNGFG_VER);
    wp_register_style('tngfg-admin', TNGFG_URL . 'assets/tngfg-admin.css', ['tngfg'], TNGFG_VER);
    wp_register_script('tngfg-lang', TNGFG_URL . 'assets/tngfg-lang.js', [], TNGFG_VER, true);
    wp_register_script('tngfg-lists', TNGFG_URL . 'assets/tngfg-lists.js', ['tngfg-lang'], TNGFG_VER, true);
}
add_action('wp_enqueue_scripts', 'tngfg_register_assets');
add_action('admin_enqueue_scripts', 'tngfg_register_assets');

/** Charge l'outil « listes et fiche individuelle » et ses données. */
function tngfg_enqueue_lists($admin = false) {
    wp_enqueue_style('tngfg');
    if ($admin) wp_enqueue_style('tngfg-admin');
    wp_enqueue_script('tngfg-lists');
    tngs_js_i18n('tngfg-lists');
    static $done = false;
    if ($done) return;
    $f = tngfg_fields();
    wp_localize_script('tngfg-lists', 'TNGFG_L', [
        'ajax'   => admin_url('admin-ajax.php'),
        'nonce'  => wp_create_nonce('tngfg'),
        'site'   => get_bloginfo('name'),
        'root'   => tngfg_opt('sosa_root'),
        'fields' => ['asc' => $f['asc'], 'desc' => $f['desc'], 'fiche' => $f['fiche']],
        'front'  => ($admin || tngfg_opt('front')) ? 1 : 0,
        'labels' => tngfg_labels(),
        'lang'   => tngfg_default_lang(),
        'langFront' => ($admin || tngfg_opt('lang_front')) ? 1 : 0,
        'langStore' => $admin ? 0 : 1,   // dans l'administration, la langue suit TNG Suite > Général (pas la dernière langue choisie dans le navigateur)
    ]);
    $done = true;
}

/* ------------------------------------------------------------------ */
/* Génération dans l'administration                                    */
/* ------------------------------------------------------------------ */
add_action('admin_menu', function () {
    tngs_add_subpage('Générer les documents (fiche, listes)', 'Fiche : générer des documents', 'tngfg-generate', 'tngfg_generate_page');
});

function tngfg_generate_page() {
    if (!current_user_can('manage_options')) return;
    tngfg_enqueue_lists(true);
    ?>
    <?php tngs_admin_header('Générer des documents', array('module' => 'fiche')); ?>
      <p class="tngs-box"><?php echo esc_html(tngs_t('Créez ici, sans passer par une page du site, les listes d’ascendants et de descendants et la fiche individuelle (PDF, Excel, Word). Les champs affichés et la langue par défaut se règlent dans la section « Réglages » de cette page.')); ?></p>
      <div style="max-width:1100px;margin-top:1em">
        <div class="tngl"></div>
      </div>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Shortcodes                                                          */
/* ------------------------------------------------------------------ */
/* [tng_fiche] (ancienne affiche généalogique, supprimée) : affiche désormais l'outil de listes et fiche individuelle. */
add_shortcode('tng_fiche', function () {
    return do_shortcode('[tng_listes]');
});

add_shortcode('tng_listes', function () {
    if (tngfg_opt('login') && !is_user_logged_in()) {
        return '<p class="tngfg-note">' . esc_html(tngs_t('Vous devez être connecté pour utiliser cet outil.')) . '</p>';
    }
    tngfg_enqueue_lists();
    return '<div class="tngl"></div>';
});

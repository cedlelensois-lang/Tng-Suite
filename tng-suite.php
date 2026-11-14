<?php
/**
 * Plugin Name: TNG Suite
 * Description: Suite unifiée pour TNG : arbres et descendance, bouton vers la fiche, frise chronologique, heatmap des lieux, galerie de médias, individu au hasard, nuage de noms et anniversaires, statistiques et graphiques, éventail (roue de parenté), implexe et consanguinité, livre généalogique, fiche et listes de documents. Un seul réglage de connexion (wp-config.php ou saisie dans le plugin), de préfixe et d'URL, et un réglage « vivants / privés » par module, en français ou en anglais.
 * Version: 2.2.1
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author: Cédric Malvache
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/cedlelensois-lang/tng-suite
 * Text Domain: tng-suite
 * Domain Path: /languages
 *
 * Connexion à la base TNG (TNG Suite > Général), au choix :
 *   - constantes de wp-config.php : DB_HOSTTNG, DB_NAMETNG, DB_USERTNG, DB_PASSWORDTNG
 *     (optionnel : DB_CHARSETTNG, DB_COLLATETNG) ;
 *   - identifiants saisis dans le plugin (mot de passe chiffré) ;
 *   - tables TNG dans la base de WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TNGSUITE_VERSION', '2.2.1' );
define( 'TNGSUITE_FILE', __FILE__ );
define( 'TNGSUITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNGSUITE_URL', plugin_dir_url( __FILE__ ) );

require_once TNGSUITE_DIR . 'includes/core.php';
require_once TNGSUITE_DIR . 'includes/i18n.php';
require_once TNGSUITE_DIR . 'includes/updater.php';

if ( is_admin() ) {
    require_once TNGSUITE_DIR . 'includes/admin.php';
}

/**
 * Chargement des modules une fois tous les plugins chargés.
 * Si l'ancien plugin autonome d'un module est encore actif, ce module n'est pas
 * chargé (sinon : « Cannot redeclare function » et site en erreur fatale).
 */
function tngs_boot() {
    global $tngs_legacy;
    $tngs_legacy = array();

    tngs_maybe_migrate();
    tngs_migrate_late();

    // slug => [ fichier, test de présence de l'ancien plugin, libellé de l'ancien plugin ]
    $modules = array(
        'tree'     => array( 'modules/tree/tree.php',         'function:tng_tree_shortcode',              'TNG Gutenberg Tree & Descendants' ),
        'link'     => array( 'modules/link/link.php',         'function:tng_link_shortcode',              'TNG Individual Link Button' ),
        'timeline' => array( 'modules/timeline/timeline.php', 'function:tngft_shortcode',                 'TNG Family Timeline' ),
        'heatmap'  => array( 'modules/heatmap/heatmap.php',   'function:tng_heatmap_shortcode',           'TNG Ancestors Heatmap' ),
        'gallery'  => array( 'modules/gallery/gallery.php',   'class:TNG_Media_Gallery',                  'TNG Media Gallery' ),
        'random'   => array( 'modules/random/random.php',     'function:tri_random_individual_shortcode', 'TNG Random Individual' ),
        'extra'    => array( 'modules/extra/extra.php',       'class:TNG_Extra_Shortcodes',               'TNG Extra Shortcodes' ),
        'fan'      => array( 'modules/fan/fan.php',           'class:TNGFan_Front',                       'TNG Fan' ),
        'stats'    => array( 'modules/stats/stats.php',       'class:TNGS_Settings',                      'TNG Stats' ),
        'implexe'  => array( 'modules/implexe/implexe.php',   'class:TNGI_Admin',                         'TNG Implexe & Ancêtres' ),
        'book'     => array( 'modules/book/book.php',         'class:TNGBook',                            'TNG Livre Généalogique' ),
        'fiche'    => array( 'modules/fiche/fiche.php',       'function:tngfg_defaults',                  'TNG Fiche Généalogique' ),
    );

    foreach ( $modules as $slug => $m ) {
        list( $type, $name ) = explode( ':', $m[1] );
        $legacy_loaded = ( 'class' === $type ) ? class_exists( $name, false ) : function_exists( $name );
        if ( $legacy_loaded ) {
            $tngs_legacy[] = $m[2];
            continue;
        }
        if ( ! tngs_module_enabled( $slug ) ) {
            continue;
        }
        require_once TNGSUITE_DIR . $m[0];
    }
}
add_action( 'plugins_loaded', 'tngs_boot' );

register_activation_hook( __FILE__, function () {
    tngs_maybe_migrate();
    if ( ! wp_next_scheduled( 'tng_heatmap_cron_refresh' ) ) {
        wp_schedule_event( time() + 300, 'twicedaily', 'tng_heatmap_cron_refresh' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'tng_heatmap_cron_refresh' );
    wp_clear_scheduled_hook( 'tngbook_auto_tick' );
} );

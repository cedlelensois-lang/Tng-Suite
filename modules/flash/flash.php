<?php
/**
 * TNG Suite – module « Liste flash » (Documents).
 * Liste des patronymes de l'arbre TNG avec, pour chacun : le nombre de personnes, la date minimale et la date maximale
 * (naissances, mariages, décès) et les lieux avec leur intervalle de dates. Les variantes d'orthographe sont regroupées
 * mais affichées. Page publique : shortcode [tng_flash] (recherche, filtres, tri, export CSV / TXT). Administration : réglages et aperçu.
 * Lecture seule sur TNG.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TNGFLASH_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNGFLASH_URL', plugin_dir_url( __FILE__ ) );

require_once TNGFLASH_DIR . 'includes/class-flash-data.php';
require_once TNGFLASH_DIR . 'includes/class-flash-front.php';

TNGFlash_Front::init();

if ( is_admin() ) {
	require_once TNGFLASH_DIR . 'includes/class-flash-admin.php';
	TNGFlash_Admin::init();
}

add_action( 'tngs_flush_cache', array( 'TNGFlash_Data', 'flush_cache' ) );

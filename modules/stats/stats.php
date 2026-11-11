<?php
/**
 * TNG Suite – module « Statistiques & graphiques » (ex-plugin TNG Stats 1.0.0).
 * Shortcodes : [tng_stat stat="…"] et un alias par statistique ([tng_stat_genre], [tng_stat_vivants],
 * [tng_stat_centenaires], [tng_stat_age_union], [tng_stat_distance], [tng_stat_demi_siecle], [tng_stat_esperance], [tng_stat_pyramide],
 * [tng_stat_mois], [tng_stat_villes], [tng_stat_metiers]).
 * Titres, libellés, légendes et palettes de chaque graphique : TNG Suite > Stats : graphiques.
 * Connexion, préfixe, arbre par défaut et vivants/privés : réglages communs.
 *
 * Chart.js est chargé depuis cdnjs.cloudflare.com sur les pages qui affichent un graphique
 * (l'adresse IP du visiteur est donc transmise à ce CDN).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TNGSTATS_VERSION', TNGSUITE_VERSION );
define( 'TNGSTATS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNGSTATS_URL', plugin_dir_url( __FILE__ ) );

require_once TNGSTATS_DIR . 'includes/class-tngstats-settings.php';
require_once TNGSTATS_DIR . 'includes/class-tngstats-queries.php';
require_once TNGSTATS_DIR . 'includes/class-tngstats-shortcodes.php';

TNGStats_Settings::init();
TNGStats_Shortcodes::init();

<?php
/**
 * TNG Suite – module « Implexe & consanguinité » (ex-plugin TNG Implexe & Ancêtres 1.0.0).
 * Shortcodes : [tng_implexe tree="" person="" generations="" network_generations=""] et
 * [tng_consanguinite tree="" per_page=""]. Pages d'analyse : TNG Suite > Implexe.
 * Connexion, préfixe, arbre par défaut et masquage des vivants : réglages communs.
 *
 * Les scripts Chart.js et vis-network sont chargés depuis cdnjs.cloudflare.com (uniquement sur les
 * pages qui utilisent ces shortcodes) : l'adresse IP du visiteur est donc transmise à ce CDN.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TNGI_VERSION', TNGSUITE_VERSION );
define( 'TNGI_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNGI_URL', plugin_dir_url( __FILE__ ) );

require_once TNGI_DIR . 'includes/class-tngi-admin.php';
require_once TNGI_DIR . 'includes/class-tngi-engine.php';
require_once TNGI_DIR . 'includes/class-tngi-inbreeding.php';
require_once TNGI_DIR . 'includes/class-tngi-front.php';

TNGI_Admin::init();
TNGI_Front::init();

// « Vider tous les caches » (TNG Suite) : les analyses mises en cache sont recalculées.
add_action( 'tngs_flush_cache', array( 'TNGI_Admin', 'flush_cache' ) );

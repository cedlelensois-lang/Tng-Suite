<?php
/**
 * TNG Suite – module « Éventail / roue de parenté ».
 * Shortcode : [tng_fan person="I1" mode="ancestors|descendants|mixed" asc="5" desc="3" shape="full|three|half"
 *             color="generation|branch|sex" palette="rainbow" style="tint|solid"].
 * Graphique SVG sans bibliothèque externe : ascendance, descendance ou roue mixte, générations réglables de
 * chaque côté, palette paramétrable. Connexion, préfixe, arbre, URL de TNG et vivants/privés : réglages communs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TNGFAN_VERSION', TNGSUITE_VERSION );
define( 'TNGFAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNGFAN_URL', plugin_dir_url( __FILE__ ) );

require_once TNGFAN_DIR . 'includes/class-fan-data.php';
require_once TNGFAN_DIR . 'includes/class-fan-admin.php';
require_once TNGFAN_DIR . 'includes/class-fan-front.php';

TNGFan_Admin::init();
TNGFan_Front::init();

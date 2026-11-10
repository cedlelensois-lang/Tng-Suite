<?php
/**
 * TNG Suite – module « Nuage de noms & anniversaires » (ex-plugin TNG Extra Shortcodes 2.0.0).
 * Shortcodes : [tng_name_cloud] [tng_births_today] [tng_marriages_today] [tng_deaths_today].
 * Les personnes vivantes / privées sont toujours exclues. Connexion, préfixe et URL : réglages communs.
 */

if (!defined('ABSPATH')) exit;

define('TNG_EXSC_VERSION', TNGSUITE_VERSION);
define('TNG_EXSC_FILE', __FILE__);
define('TNG_EXSC_PATH', plugin_dir_path(__FILE__));
define('TNG_EXSC_URL', plugin_dir_url(__FILE__));

require_once TNG_EXSC_PATH . 'includes/class-tng-db.php';
require_once TNG_EXSC_PATH . 'includes/class-tng-shortcodes.php';

add_action('init', function () {
    (new TNG_Extra_Shortcodes())->register();
});

// « Vider tous les caches » (TNG Suite) et changement des réglages communs.
add_action('tngs_flush_cache', array('TNG_Extra_DB', 'flush_cache'));

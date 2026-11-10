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

// Page d'aide (ce module n'a pas de réglages propres).
add_action('admin_menu', function () {
    tngs_add_subpage('Nuage de noms & anniversaires : utilisation', 'Aide', 'tng-extra-help', function () {
        tngs_render_shortcode_help(
            'Nuage de noms & anniversaires',
            'extra',
            array(
                array('code' => '[tng_name_cloud limit="50"]', 'desc' => 'Nuage des noms de famille les plus fréquents : plus un nom est fréquent, plus il est grand.', 'atts' => array(array('limit', 'Nombre de noms affichés (1 à 200).', '50'))),
                array('code' => '[tng_births_today]', 'desc' => 'Les naissances survenues le même jour et le même mois, toutes années confondues.', 'atts' => array()),
                array('code' => '[tng_marriages_today]', 'desc' => 'Les mariages célébrés le même jour et le même mois.', 'atts' => array()),
                array('code' => '[tng_deaths_today]', 'desc' => 'Les décès survenus le même jour et le même mois.', 'atts' => array()),
            ),
            array('Les listes d’anniversaires sont mises en cache pour la journée.')
        );
    });
});

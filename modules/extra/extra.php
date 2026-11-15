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
    tngs_add_subpage('Nuage de noms & anniversaires : utilisation', 'Nuage de noms & anniversaires', 'tng-extra-help', function () {
        tngs_render_shortcode_help(
            'Nuage de noms & anniversaires',
            'extra',
            array(
                array('code' => '[tng_name_cloud limit="50"]', 'desc' => 'Nuage des noms de famille les plus fréquents : plus un nom est fréquent, plus il est grand.', 'atts' => array(array('limit', 'Nombre de noms affichés (1 à 200).', '50'))),
                array('code' => '[tng_births_today]', 'desc' => 'Les naissances survenues le même jour et le même mois, toutes années confondues.', 'atts' => array()),
                array('code' => '[tng_marriages_today]', 'desc' => 'Les mariages célébrés le même jour et le même mois.', 'atts' => array()),
                array('code' => '[tng_deaths_today]', 'desc' => 'Les décès survenus le même jour et le même mois.', 'atts' => array()),
                array('code' => '[tng_recent_updates limit="10"]', 'desc' => 'Les derniers individus mis à jour dans TNG (date de dernière modification), le plus récent en premier.', 'atts' => array(
                    array('limit', 'Nombre d’individus affichés (1 à 100).', '10'),
                    array('tree', 'Identifiant de l’arbre TNG (vide = arbre des réglages communs, sinon tous).', ''),
                    array('date', 'yes ou no : afficher la date de mise à jour.', 'yes'),
                    array('date_format', 'Format de la date (codes PHP, par exemple d/m/Y ou j F Y). Vide : jj/mm/aaaa en français, mm/dd/yyyy en anglais.', ''),
                    array('link', 'yes ou no : lier chaque nom à sa fiche TNG.', 'yes'),
                    array('title', 'Titre affiché au-dessus de la liste (vide = aucun).', ''),
                )),
            ),
            array('Les listes d’anniversaires sont mises en cache pour la journée ; la liste des dernières mises à jour, pour une heure.')
        );
    });
});

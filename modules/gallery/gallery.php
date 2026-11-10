<?php
/**
 * TNG Suite – module « Galerie de médias » (ex-plugin TNG Media Gallery 1.3.0).
 * Shortcode : [tng_media_gallery limit="30" personid="I123" surname="Dupont"].
 * Connexion, préfixe, URL de TNG et confidentialité : réglages communs.
 */
if (!defined('ABSPATH')) exit;

define('TNG_MG_VERSION', TNGSUITE_VERSION);
define('TNG_MG_PATH', plugin_dir_path(__FILE__));
define('TNG_MG_URL', plugin_dir_url(__FILE__));

require_once TNG_MG_PATH . 'includes/class-tng-media-gallery.php';

if (is_admin()) {
    require_once TNG_MG_PATH . 'admin/settings-register.php';
    require_once TNG_MG_PATH . 'admin/settings-page.php';
    add_action('admin_menu', 'tng_mg_add_admin_menu');
}

function tng_mg_add_admin_menu() {
    tngs_add_subpage('Réglages TNG Media Gallery', 'Galerie de médias', 'tng-media-gallery', 'tng_mg_render_settings_page');
}

/** Aucune connexion ici : la base TNG n'est contactée que lorsqu'un shortcode s'affiche. */
function tng_mg_init() {
    $gallery = new TNG_Media_Gallery();
    $gallery->register();
}
tng_mg_init();

// « Vider tous les caches » (TNG Suite) et changement des réglages communs.
add_action('tngs_flush_cache', function () {
    update_option('tng_mg_cache_ver', time(), false);
});

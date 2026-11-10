<?php

if (!defined('ABSPATH')) exit;

function tng_mg_register_settings() {

    register_setting('tng_mg_options', 'tng_mg_cache_minutes', [
        'type'              => 'integer',
        'sanitize_callback' => 'tng_mg_sanitize_cache_minutes',
        'default'           => 15,
    ]);

    register_setting('tng_mg_options', 'tng_mg_thumb_height', [
        'type'              => 'integer',
        'sanitize_callback' => 'tng_mg_sanitize_thumb_size',
        'default'           => 100,
    ]);

    register_setting('tng_mg_options', 'tng_mg_thumb_width', [
        'type'              => 'integer',
        'sanitize_callback' => 'tng_mg_sanitize_thumb_size',
        'default'           => 80,
    ]);

    register_setting('tng_mg_options', 'tng_mg_display_mode', [
        'type'              => 'string',
        'sanitize_callback' => 'tng_mg_sanitize_display_mode',
        'default'           => 'grid',
    ]);
}
add_action('admin_init', 'tng_mg_register_settings');

/* ---------- Callbacks de sanitization ---------- */



function tng_mg_sanitize_checkbox($value) {
    return empty($value) ? 0 : 1;
}

function tng_mg_sanitize_cache_minutes($value) {
    return max(0, min(1440, absint($value)));
}

function tng_mg_sanitize_thumb_size($value) {
    return max(20, min(600, absint($value)));
}

function tng_mg_sanitize_display_mode($value) {
    $value = sanitize_key($value);
    return in_array($value, TNG_Media_Gallery::MODES, true) ? $value : 'grid';
}

/* ---------- Vidage du cache (changement de version de clé) ---------- */

function tng_mg_flush_cache() {

    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', '', ['response' => 403]);
    }

    check_admin_referer('tng_mg_flush_cache');

    // Fonctionne aussi avec un cache d'objet persistant (Redis, Memcached…) :
    // les anciennes entrées ne sont simplement plus jamais lues.
    update_option('tng_mg_cache_ver', time(), false);

    wp_safe_redirect(add_query_arg('tng_mg_flushed', '1', admin_url('admin.php?page=tng-media-gallery')));
    exit;
}
add_action('admin_post_tng_mg_flush_cache', 'tng_mg_flush_cache');

<?php
/**
 * Désinstallation de TNG Suite : supprime uniquement les réglages, caches et tâches planifiées
 * créés par la suite. Les anciens plugins autonomes et vos données TNG ne sont jamais touchés.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$options = array(
    // Cœur
    'tngs_settings', 'tngs_cache_ver', 'tngs_migration_notes',
    // Bouton vers la fiche
    'tng_emoji', 'tng_button_text', 'tng_color_bg', 'tng_color_text', 'tng_color_hover', 'tng_color_text_hover', 'tngs_migrated_link',
    // Timeline
    'tng_timeline_settings', 'tngft_cache_ver',
    // Heatmap
    'tng_search_url', 'tng_map_provider', 'tng_show_number', 'tng_color_low', 'tng_color_med', 'tng_color_high',
    'tng_threshold_low', 'tng_threshold_med', 'tng_use_solid_circles', 'tng_solid_circle_color', 'tng_solid_circle_opacity',
    'tng_enable_century_filter', 'tng_enable_surname_filter', 'tng_enable_place_filter', 'tng_enable_distinct_colors',
    'tng_include_private', 'tng_heatmap_last_error',
    // Galerie
    'tng_mg_cache_minutes', 'tng_mg_thumb_height', 'tng_mg_thumb_width', 'tng_mg_display_mode', 'tng_mg_cache_ver',
    // Random
    'tri_photo_dir', 'tri_cache_minutes', 'tri_show_photo', 'tri_show_name', 'tri_show_dates', 'tri_cache_version',
    // Extra
    'tng_exsc_gen',
    // Éventail
    'tngfan_options',
    // Statistiques
    'tngstats_options',
    // Implexe
    'tngi_options', 'tngi_inb_meta', 'tngi_db_version',
    // Livre généalogique
    'tngbook_options',
    'tngbook_profiles',
    'tngbook_profile_active',
    // Migration
    'tngs_migrated',
);
foreach ( $options as $option ) {
    delete_option( $option );
}

foreach ( array( 'tngs_db_down', 'tng_heatmap_data_v2', 'tng_heatmap_recent_error', 'tng_heatmap_build_lock' ) as $transient ) {
    delete_transient( $transient );
}

wp_clear_scheduled_hook( 'tng_heatmap_cron_refresh' );

// Transients dont le nom est un préfixe + empreinte (on ne touche qu'aux nôtres).
global $wpdb;
$like_prefixes = array( 'tng_mg_', 'tri_ids_', 'tri_col_', 'tngft_', 'tng_exsc_', 'tngi_', 'tngstats_', 'tngfan_' );
foreach ( $like_prefixes as $p ) {
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_' . $p ) . '%',
        $wpdb->esc_like( '_transient_timeout_' . $p ) . '%'
    ) );
}
// Cache des arbres : « tng_ » + 32 caractères hexadécimaux.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name REGEXP %s", '^_transient(_timeout)?_tng_[0-9a-f]{32}$' ) );

// Implexe : table des coefficients de consanguinité (créée dans la base WordPress par le module).
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'tngi_inbreeding' ) . '`' );

// Livre généalogique : cache temporaire des PDF.
$upload = wp_upload_dir();
$cache  = $upload['basedir'] . '/tngbook-cache';
if ( is_dir( $cache ) ) {
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cache, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $it as $f ) {
        $f->isDir() ? @rmdir( $f->getRealPath() ) : wp_delete_file( $f->getRealPath() );
    }
    @rmdir( $cache );
}

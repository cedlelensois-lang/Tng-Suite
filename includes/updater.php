<?php
/**
 * Mises à jour automatiques depuis les « Releases » d'un dépôt GitHub (Plugin Update Checker, licence MIT).
 * Dépôt par défaut : celui du plugin. Pour un fork ou un dépôt privé, définissez TNGSUITE_GITHUB_REPO dans wp-config.php
 * (ex. define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/votre-compte/tng-suite/' );), ou filtre « tngsuite_github_repo ».
 * Désactiver : define( 'TNGSUITE_GITHUB_REPO', '' );
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TNGSUITE_GITHUB_REPO' ) ) {
    define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/cedlelensois-lang/tng-suite/' );
}

add_action( 'plugins_loaded', function () {
    $repo = (string) apply_filters( 'tngsuite_github_repo', TNGSUITE_GITHUB_REPO );
    $lib  = TNGSUITE_DIR . 'includes/plugin-update-checker/load-v5p6.php';
    if ( '' === $repo || ! is_readable( $lib ) ) {
        return;
    }
    require_once $lib;
    if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        return;
    }
    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( $repo, TNGSUITE_FILE, 'tng-suite' );
    $checker->getVcsApi()->enableReleaseAssets();   // utilise le zip joint à chaque Release (tng-suite.zip)
} );

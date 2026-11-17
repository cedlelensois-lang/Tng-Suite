<?php
/**
 * TNG Suite – module « Cohérence des données » (Analyses).
 * Contrôle en lecture seule d'un arbre TNG : dates, sexe, âges, structure des familles, doublons possibles, orthographe des lieux.
 * Réservé aux administrateurs (aucun shortcode, aucun affichage public). Lancement manuel, par lots (AJAX), résultats conservés
 * dans la base WordPress (tables tngs_coh_res et tngs_coh_key), exportables en CSV. La base TNG n'est jamais modifiée.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TNGCOH_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNGCOH_URL', plugin_dir_url( __FILE__ ) );

require_once TNGCOH_DIR . 'includes/class-coh-rules.php';
require_once TNGCOH_DIR . 'includes/class-coh-store.php';
require_once TNGCOH_DIR . 'includes/class-coh-engine.php';

if ( is_admin() ) {
	require_once TNGCOH_DIR . 'includes/class-coh-admin.php';
	TNGCoh_Admin::init();
}

<?php
/**
 * Liste flash : page d'administration (réglages, aperçu, exports, aide du shortcode).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGFlash_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_tngflash_flush', array( __CLASS__, 'post_flush' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register() {
		register_setting( 'tngflash_group', TNGFlash_Data::OPT, array(
			'type'              => 'array',
			'sanitize_callback' => array( 'TNGFlash_Data', 'clean' ),
		) );
	}

	public static function menu() {
		tngs_add_subpage( 'Liste flash des patronymes', 'Liste flash', 'tng-flash', array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'tng-' ) ) {
			return;
		}
		wp_register_style( 'tngflash', TNGFLASH_URL . 'assets/flash.css', array(), TNGSUITE_VERSION );
		wp_enqueue_style( 'tngflash' );
	}

	public static function post_flush() {
		tngs_admin_guard( 'tngflash_flush' );
		TNGFlash_Data::flush_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => 'tng-flash', 'tngflash_flushed' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page() {
		$o = TNGFlash_Data::options();
		tngs_admin_header( 'Liste flash des patronymes', array( 'module' => 'flash' ) );
		$n = TNGFlash_Data::OPT;
		if ( ! empty( $_GET['tngflash_flushed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline"><p>' . esc_html( tngs_t( 'Calcul relancé : la liste sera recalculée à la prochaine visite.' ) ) . '</p></div>';
		}
		echo '<p>' . esc_html( tngs_t( 'La liste flash donne, pour chaque patronyme de l’arbre, le nombre de personnes, la date minimale et la date maximale (naissances, mariages et décès) et les lieux avec leur intervalle de dates. Les variantes d’orthographe sont regroupées mais affichées. Elle sert à échanger avec d’autres généalogistes pour repérer des branches communes.' ) ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'tngflash_group' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html( tngs_t( 'Niveau des lieux' ) ) . '</th><td><select name="' . esc_attr( $n ) . '[level]">';
		foreach ( TNGFlash_Data::levels() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $o['level'], $k, false ) . '>' . esc_html( tngs_t( $label ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html( tngs_t( 'Les lieux TNG sont du texte libre : on suppose « Commune, Département, Région, Pays » (les codes postaux sont ignorés).' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html( tngs_t( 'Patronymes minimum' ) ) . '</th><td><input type="number" min="1" max="50" name="' . esc_attr( $n ) . '[min]" value="' . (int) $o['min'] . '" class="small-text"> '
			. esc_html( tngs_t( 'personnes : les patronymes portés par moins de personnes sont masqués (1 = tout afficher).' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html( tngs_t( 'Variantes' ) ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $n ) . '[variants]" value="1"' . checked( 1, (int) $o['variants'], false ) . '> '
			. esc_html( tngs_t( 'Regrouper les variantes d’orthographe (Lecoq, Lecocq…) ; elles restent affichées entre parenthèses.' ) ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html( tngs_t( 'Lieux par patronyme' ) ) . '</th><td><input type="number" min="1" max="60" name="' . esc_attr( $n ) . '[places_max]" value="' . (int) $o['places_max'] . '" class="small-text"> '
			. esc_html( tngs_t( 'lieux les plus fréquents conservés pour chaque patronyme.' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html( tngs_t( 'Export public' ) ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $n ) . '[export]" value="1"' . checked( 1, (int) $o['export'], false ) . '> '
			. esc_html( tngs_t( 'Proposer les exports CSV, TXT et « Copier pour un forum » aux visiteurs.' ) ) . '</label></td></tr>';
		echo '</table>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html( tngs_t( 'Utilisation' ) ) . '</h2>';
		echo '<p><code>[tng_flash]</code> ' . esc_html( tngs_t( 'dans une page ou un article. Attributs facultatifs :' ) ) . '</p><ul class="ul-disc">'
			. '<li><code>level="commune|departement|region|pays"</code>, <code>min="2"</code>, <code>variants="1|0"</code>, <code>tree="monarbre"</code></li>'
			. '<li><code>search="1|0"</code> (' . esc_html( tngs_t( 'recherche et filtres' ) ) . '), <code>living="hide|show"</code> (' . esc_html( tngs_t( 'masque ou affiche les personnes vivantes ou privées sur cette page ; sans cet attribut, le réglage du module s’applique ; rgpd="0" équivaut à living="show"' ) ) . '), <code>export="1|0"</code>, <code>limit="50"</code> (' . esc_html( tngs_t( 'lignes affichées par page' ) ) . '), <code>title="…"</code></li></ul>';

		echo '<h2>' . esc_html( tngs_t( 'Aperçu' ) ) . '</h2>';
		$show = ! TNGFlash_Data::hide_for( true );
		$p    = TNGFlash_Data::params( array( 'hide' => TNGFlash_Data::hide_for( true ) ) );
		echo '<p class="description">' . esc_html( tngs_t( $show ? 'Aperçu réservé aux administrateurs : les personnes vivantes ou privées sont incluses. Les visiteurs voient la liste selon le réglage « Vivants / privés » du module (ou l’attribut rgpd du shortcode).' : 'Le module est réglé sur « Toujours masquer » (TNG Suite > Général) : les personnes vivantes ou privées sont exclues, même ici.' ) ) . '</p>';
		try {
			$rows = TNGFlash_Data::get( $p );
			echo '<p>' . esc_html( count( $rows ) ) . ' ' . esc_html( tngs_t( 'patronymes' ) ) . ' – ';
			$q = array( 'action' => 'tngflash_export', 'level' => $p['level'], 'min' => $p['min'], 'variants' => $p['variants'], 'tree' => $p['tree'] );
			if ( $show ) {
				$q['lv'] = 'show';
				$q['ls'] = TNGFlash_Data::living_sig();
			}
			echo '<a href="' . esc_url( add_query_arg( array_merge( $q, array( 'format' => 'csv' ) ), admin_url( 'admin-post.php' ) ) ) . '">CSV</a> · '
				. '<a href="' . esc_url( add_query_arg( array_merge( $q, array( 'format' => 'txt' ) ), admin_url( 'admin-post.php' ) ) ) . '">TXT</a> · '
				. '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tngflash_flush' ), 'tngflash_flush' ) ) . '">' . esc_html( tngs_t( 'Recalculer' ) ) . '</a></p>';
			echo wp_kses_post( TNGFlash_Front::table( $rows, 25 ) );
			if ( count( $rows ) > 25 ) {
				echo '<p class="description">' . esc_html( tngs_t( 'Seuls les 25 premiers patronymes sont montrés ici.' ) ) . '</p>';
			}
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
		echo '</div>';
	}
}

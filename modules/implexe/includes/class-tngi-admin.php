<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Réglages propres au module + accès à la base TNG (fournie par le cœur de TNG Suite).
 */
class TNGI_Admin {

	const OPT = 'tngi_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/** Réglages propres au module (connexion, préfixe, arbre et vie privée : voir TNG Suite > Général). */
	public static function defaults() {
		return array(
			'default_person' => '',
			'max_gen'        => 25,
			'net_gen'        => 6,
			'access'         => 'logged', // all | logged | admin.
			'cache_minutes'  => 60,
		);
	}

	public static function get( $key ) {
		switch ( $key ) {
			case 'prefix':
				return tngs_prefix();
			case 'default_tree':
				return tngs_gedcom();
			case 'mask_living':
				return tngs_hide_living( 'implexe' ) ? 1 : 0;
		}
		$o = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	public static function clean_prefix( $p ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $p );
	}

	/**
	 * Retourne l'objet wpdb pointant vers la base TNG.
	 *
	 * @return wpdb|WP_Error
	 */
	public static function db() {
		$db = tngs_db();
		if ( $db ) {
			return $db;
		}
		// Le détail de configuration n'est montré qu'aux administrateurs.
		return new WP_Error(
			'tngi_conn',
			current_user_can( 'manage_options' ) ? tngs_db_error_message() : 'Connexion à la base TNG impossible.'
		);
	}

	public static function flush_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_tngi_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_tngi_' ) . '%'
			)
		);
	}

	public static function register() {
		register_setting(
			'tngi_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$out['default_person'] = sanitize_text_field( isset( $in['default_person'] ) ? $in['default_person'] : '' );
		$out['max_gen']        = max( 2, min( 40, (int) ( isset( $in['max_gen'] ) ? $in['max_gen'] : 25 ) ) );
		$out['net_gen']        = max( 2, min( 10, $out['max_gen'], (int) ( isset( $in['net_gen'] ) ? $in['net_gen'] : 6 ) ) );
		$out['access']         = ( isset( $in['access'] ) && in_array( $in['access'], array( 'all', 'logged', 'admin' ), true ) ) ? $in['access'] : 'logged';
		$out['cache_minutes']  = max( 0, min( 1440, (int) ( isset( $in['cache_minutes'] ) ? $in['cache_minutes'] : 60 ) ) );

		self::flush_cache();
		return $out;
	}

	public static function menu() {
		tngs_add_subpage( 'Implexe – Analyse', 'Implexe : analyse', 'tng-implexe', array( __CLASS__, 'page_analysis' ) );
		tngs_add_subpage( 'Implexe – Consanguinité', 'Implexe : consanguinité', 'tng-implexe-inbreeding', array( __CLASS__, 'page_inbreeding' ) );
		tngs_add_subpage( 'Implexe – Réglages', 'Implexe : réglages', 'tng-implexe-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function page_analysis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		tngs_admin_header( 'Implexe : analyse', array( 'module' => 'implexe' ) );
		// Dans l'administration, l'accès est déjà limité aux administrateurs.
		echo TNGI_Front::render( array(), true ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	public static function page_inbreeding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		tngs_admin_header( 'Implexe : consanguinité', array( 'module' => 'implexe' ) );
		echo TNGI_Front::render_inbreeding( array(), true ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		$n = self::OPT;
		$db = tngs_db();
		?>
		<?php tngs_admin_header( 'Implexe : réglages', array( 'module' => 'implexe' ) ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'tngi_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tngi_person">Personne de départ par défaut</label></th>
						<td><input id="tngi_person" class="regular-text" type="text" name="<?php echo esc_attr( $n ); ?>[default_person]" value="<?php echo esc_attr( $o['default_person'] ); ?>"> <span class="description">ex. I1</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="tngi_maxgen">Générations maximum</label></th>
						<td><input id="tngi_maxgen" type="number" min="2" max="40" name="<?php echo esc_attr( $n ); ?>[max_gen]" value="<?php echo esc_attr( $o['max_gen'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tngi_netgen">Générations du réseau</label></th>
						<td><input id="tngi_netgen" type="number" min="2" max="10" name="<?php echo esc_attr( $n ); ?>[net_gen]" value="<?php echo esc_attr( $o['net_gen'] ); ?>"> <span class="description">Profondeur affichée dans le graphe d'ascendance.</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="tngi_access">Qui peut voir les résultats (shortcode)</label></th>
						<td>
							<select id="tngi_access" name="<?php echo esc_attr( $n ); ?>[access]">
								<option value="admin" <?php selected( $o['access'], 'admin' ); ?>>Administrateurs</option>
								<option value="logged" <?php selected( $o['access'], 'logged' ); ?>>Utilisateurs connectés</option>
								<option value="all" <?php selected( $o['access'], 'all' ); ?>>Tout le monde</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tngi_cache">Cache (minutes)</label></th>
						<td><input id="tngi_cache" type="number" min="0" max="1440" name="<?php echo esc_attr( $n ); ?>[cache_minutes]" value="<?php echo esc_attr( $o['cache_minutes'] ); ?>"> <span class="description">0 = pas de cache.</span></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Utilisation</h2>
			<p>Insérez le shortcode <code>[tng_implexe]</code> dans une page, avec en option :
				<code>[tng_implexe tree="monarbre" person="I1" generations="20" network_generations="6"]</code>.<br>
				Classement de consanguinité : <code>[tng_consanguinite tree="monarbre" per_page="50"]</code> (le calcul se lance depuis la page <em>TNG Suite &gt; Implexe : consanguinité</em>).</p>
		</div>
		<?php
	}
}

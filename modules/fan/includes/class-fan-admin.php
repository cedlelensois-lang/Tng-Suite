<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Réglages du module Éventail : valeurs par défaut du graphique, palette, accès, cache.
 * Connexion, préfixe, arbre par défaut, URL de TNG et vivants/privés : réglages communs de TNG Suite.
 */
class TNGFan_Admin {

	const OPT = 'tngfan_options';

	/** Générations d'ascendance maximum (12 = 8 191 ancêtres) et de descendance. */
	const ASC_LIMIT  = 12;
	const DESC_LIMIT = 10;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'tngs_flush_cache', array( __CLASS__, 'flush_cache' ) );
		add_action( 'wp_ajax_tngfan_gen_save', array( __CLASS__, 'ajax_gen_save' ) );
	}

	public static function defaults() {
		return array(
			'default_person' => '',
			'mode'           => 'ancestors',   // ancestors | descendants | mixed
			'asc'            => 5,
			'desc'           => 3,
			'desc_max'       => 8,
			'shape'          => 'full',        // full | three | half
			'color'          => 'generation',  // generation | branch | sex
			'style'          => 'tint',        // tint (teinte légère) | solid (plein)
			'palette'        => 'rainbow',     // identifiant de palette ou « custom »
			'custom_colors'  => array(),
			'dates'          => 'years',       // years | full | none
			'places'         => 'none',        // none | commune | full
			'labels'         => 'symbols',     // symbols (° †) | letters (N : D : M :)
			'firstnames'     => 'usual',       // usual (premier prénom) | all
			'show_empty'     => 0,
			'spouses'        => 1,             // afficher les conjoints
			'zoomdetail'     => 1,             // le texte s'enrichit au zoom
			'height'         => 720,
			'cache_minutes'  => 30,
			'access'         => 'all',         // all | logged | admin
		);
	}

	public static function get( $key = null ) {
		$o = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		return null === $key ? $o : ( isset( $o[ $key ] ) ? $o[ $key ] : null );
	}

	/** Palettes : identifiant => [ libellé, couleurs ]. */
	public static function presets() {
		return array(
			'rainbow'    => array( 'Arc-en-ciel', array( '#19d9e8', '#3a4bff', '#d63bf0', '#ff2d8a', '#ff8c1a', '#b7e61e', '#22d46a' ) ),
			'classic'    => array( 'Classique', array( '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac' ) ),
			'pastel'     => array( 'Pastel', array( '#a6cee3', '#b2df8a', '#fb9a99', '#fdbf6f', '#cab2d6', '#ffff99', '#8dd3c7', '#fccde5', '#d9d9d9', '#bc80bd' ) ),
			'vivid'      => array( 'Vive', array( '#e41a1c', '#377eb8', '#4daf4a', '#984ea3', '#ff7f00', '#ffd92f', '#a65628', '#f781bf', '#17becf', '#999999' ) ),
			'colorblind' => array( 'Daltonien (Okabe-Ito)', array( '#0072b2', '#e69f00', '#009e73', '#d55e00', '#cc79a7', '#56b4e9', '#f0e442', '#000000' ) ),
			'blues'      => array( 'Camaïeu de bleus', array( '#0b3c5d', '#1d6fa5', '#328cc1', '#5aa9d6', '#8cc5e8', '#b9dcf2', '#d9ecf9', '#eef6fc' ) ),
			'earth'      => array( 'Terre', array( '#7f5539', '#b08968', '#ddb892', '#9c6644', '#606c38', '#a3b18a', '#588157', '#e6ccb2' ) ),
			'greys'      => array( 'Gris', array( '#111827', '#374151', '#6b7280', '#9ca3af', '#d1d5db' ) ),
		);
	}

	public static function clean_colors( $list, $max ) {
		$out = array();
		foreach ( (array) $list as $c ) {
			$c = sanitize_hex_color( trim( (string) $c ) );
			if ( $c ) {
				$out[] = $c;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/** Couleurs d'une palette (identifiant « custom » = palette personnalisée) ; null si inconnue. */
	public static function palette_colors( $id ) {
		if ( 'custom' === $id ) {
			$c = self::clean_colors( self::get( 'custom_colors' ), 10 );
			return $c ? $c : null;
		}
		$p = self::presets();
		return isset( $p[ $id ] ) ? $p[ $id ][1] : null;
	}

	public static function flush_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_tngfan_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_tngfan_' ) . '%'
			)
		);
	}

	public static function register() {
		register_setting(
			'tngfan_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/** Nettoyage idempotent (WordPress peut repasser une valeur déjà nettoyée). */
	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		$d  = self::defaults();
		$o  = array();

		$o['default_person'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $in['default_person'] ) ? (string) $in['default_person'] : '' );
		$choice              = function ( $key, array $allowed ) use ( $in, $d ) {
			$v = isset( $in[ $key ] ) ? (string) $in[ $key ] : $d[ $key ];
			return in_array( $v, $allowed, true ) ? $v : $d[ $key ];
		};
		$o['mode']       = $choice( 'mode', array( 'ancestors', 'descendants', 'mixed' ) );
		$o['shape']      = $choice( 'shape', array( 'full', 'three', 'half' ) );
		$o['color']      = $choice( 'color', array( 'generation', 'branch', 'sex' ) );
		$o['style']      = $choice( 'style', array( 'tint', 'solid' ) );
		$o['dates']      = $choice( 'dates', array( 'years', 'full', 'none' ) );
		$o['places']     = $choice( 'places', array( 'none', 'commune', 'full' ) );
		$o['firstnames'] = $choice( 'firstnames', array( 'usual', 'all' ) );
		$o['labels']     = $choice( 'labels', array( 'symbols', 'letters' ) );
		$o['access']     = $choice( 'access', array( 'all', 'logged', 'admin' ) );

		$presets      = self::presets();
		$pal          = isset( $in['palette'] ) ? (string) $in['palette'] : $d['palette'];
		$o['palette'] = ( isset( $presets[ $pal ] ) || 'custom' === $pal ) ? $pal : $d['palette'];

		$o['custom_colors'] = self::clean_colors( isset( $in['custom_colors'] ) ? $in['custom_colors'] : array(), 10 );
		$o['desc_max']      = max( 1, min( self::DESC_LIMIT, (int) ( isset( $in['desc_max'] ) ? $in['desc_max'] : $d['desc_max'] ) ) );
		$o['asc']           = max( 1, min( self::ASC_LIMIT, (int) ( isset( $in['asc'] ) ? $in['asc'] : $d['asc'] ) ) );
		$o['desc']          = max( 1, min( $o['desc_max'], (int) ( isset( $in['desc'] ) ? $in['desc'] : $d['desc'] ) ) );
		$o['show_empty']    = empty( $in['show_empty'] ) ? 0 : 1;
		$o['spouses']       = empty( $in['spouses'] ) ? 0 : 1;
		$o['zoomdetail']    = empty( $in['zoomdetail'] ) ? 0 : 1;
		$o['height']        = max( 360, min( 1600, (int) ( isset( $in['height'] ) ? $in['height'] : $d['height'] ) ) );
		$o['cache_minutes'] = max( 0, min( 1440, (int) ( isset( $in['cache_minutes'] ) ? $in['cache_minutes'] : $d['cache_minutes'] ) ) );

		self::flush_cache();
		return $o;
	}

	public static function menu() {
		tngs_add_subpage( 'Éventail : générateur', 'Éventail : générer une roue', 'tng-fan-gen', array( __CLASS__, 'generator_page' ) );
		tngs_add_subpage( 'Éventail : réglages', 'Éventail / roue', 'tng-fan', array( __CLASS__, 'page' ) );
	}

	public static function admin_assets( $hook ) {
		$hook = (string) $hook;
		if ( false !== strpos( $hook, 'tng-fan-gen' ) ) {
			TNGFan_Front::register_assets();
			wp_enqueue_style( 'tngfan' );
			wp_enqueue_script( 'tngfan-app' );
			TNGFan_Front::boot_script();
			return;
		}
		if ( false === strpos( $hook, 'tng-fan' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', "jQuery(function($){ $('.tngfan-color').wpColorPicker(); });" );
	}

	private static function swatches( array $colors ) {
		$h = '';
		foreach ( $colors as $c ) {
			$h .= '<span style="display:inline-block;width:16px;height:16px;margin-right:2px;border:1px solid #c3c4c7;background:' . esc_attr( $c ) . '"></span>';
		}
		return $h;
	}

	private static function select( $name, $value, array $choices ) {
		echo '<select name="' . esc_attr( self::OPT . '[' . $name . ']' ) . '">';
		foreach ( $choices as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = self::get();
		$n       = self::OPT;
		$presets = self::presets();
		$custom  = array_pad( self::clean_colors( $o['custom_colors'], 10 ), 10, '' );
		$db      = tngs_db();
		?>
		<?php tngs_admin_header( 'Éventail / roue de parenté', array( 'module' => 'fan' ) ); ?>

			<p class="tngs-box">Insérez le shortcode <code>[tng_fan person="I1"]</code> dans une page. Options facultatives :
				<code>mode="ancestors|descendants|mixed"</code>, <code>asc="6"</code>, <code>desc="4"</code>, <code>shape="full|three|half"</code>,
				<code>color="generation|branch|sex"</code>, <code>palette="rainbow"</code>, <code>colors="#ff0000,#00ff00"</code>, <code>style="tint|solid"</code>,
				<code>spouses="1|0"</code> (conjoints), <code>living="default|hide|show"</code> (personnes vivantes : voir l'avertissement du générateur), <code>zoomdetail="1|0"</code> (texte enrichi au zoom), <code>pdf="1|0"</code> (bouton d'export PDF),
				<code>height="720"</code>, <code>controls="0"</code> (masquer le panneau d'options), <code>tree="…"</code>.
				Vous pouvez aussi <a href="<?php echo esc_url( admin_url( 'admin.php?page=tng-fan-gen' ) ); ?>">générer la roue directement depuis l'administration</a>.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'tngfan_group' ); ?>

				<h2>Graphique par défaut</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="tngfan_person">Personne de départ par défaut</label></th>
						<td><input id="tngfan_person" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[default_person]" value="<?php echo esc_attr( $o['default_person'] ); ?>"> <span class="description">ex. I1</span></td></tr>
					<tr><th scope="row">Type</th>
						<td><?php self::select( 'mode', $o['mode'], array( 'ancestors' => 'Roue d\'ascendance', 'descendants' => 'Roue de descendance', 'mixed' => 'Roue mixte' ) ); ?></td></tr>
					<tr><th scope="row">Générations</th>
						<td>
							<label>Ascendance <input type="number" min="1" max="12" name="<?php echo esc_attr( $n ); ?>[asc]" value="<?php echo esc_attr( $o['asc'] ); ?>" style="width:70px"></label>
							<label style="margin-left:14px">Descendance <input type="number" min="1" max="10" name="<?php echo esc_attr( $n ); ?>[desc]" value="<?php echo esc_attr( $o['desc'] ); ?>" style="width:70px"></label>
							<p class="description">Ascendance : jusqu'à 12 générations (8 191 ancêtres). Maximum de descendance proposé aux visiteurs :
								<label><input type="number" min="1" max="10" name="<?php echo esc_attr( $n ); ?>[desc_max]" value="<?php echo esc_attr( $o['desc_max'] ); ?>" style="width:60px"></label>
								La descendance est limitée à 3 000 personnes.</p></td></tr>
					<tr><th scope="row">Forme</th>
						<td><?php self::select( 'shape', $o['shape'], array( 'full' => 'Cercle complet', 'three' => 'Trois quarts de cercle', 'half' => 'Demi-cercle' ) ); ?></td></tr>
					<tr><th scope="row">Hauteur (px)</th>
						<td><input type="number" min="360" max="1600" name="<?php echo esc_attr( $n ); ?>[height]" value="<?php echo esc_attr( $o['height'] ); ?>" style="width:90px"></td></tr>
				</table>

				<h2>Couleurs</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Coloration par</th>
						<td><?php self::select( 'color', $o['color'], array( 'generation' => 'Génération', 'branch' => 'Branche', 'sex' => 'Sexe' ) ); ?></td></tr>
					<tr><th scope="row">Style</th>
						<td><?php self::select( 'style', $o['style'], array( 'tint' => 'Teinte légère et contour coloré', 'solid' => 'Couleur pleine' ) ); ?></td></tr>
					<tr><th scope="row">Palette</th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[palette]">
								<?php foreach ( $presets as $id => $p ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $o['palette'], $id ); ?>><?php echo esc_html( $p[0] ); ?></option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $o['palette'], 'custom' ); ?>>Ma palette personnalisée (ci-dessous)</option>
							</select>
							<table class="widefat striped" style="max-width:560px;margin-top:10px"><tbody>
								<?php foreach ( $presets as $id => $p ) : ?>
									<tr><td style="width:200px"><?php echo esc_html( $p[0] ); ?></td><td><?php echo self::swatches( $p[1] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
								<?php endforeach; ?>
							</tbody></table>
						</td></tr>
					<tr><th scope="row">Ma palette personnalisée</th>
						<td>
							<?php foreach ( $custom as $c ) : ?>
								<input type="text" class="tngfan-color" name="<?php echo esc_attr( $n ); ?>[custom_colors][]" value="<?php echo esc_attr( $c ); ?>">
							<?php endforeach; ?>
							<p class="description">Jusqu'à 10 couleurs, utilisées dans l'ordre (génération 1, génération 2… ou branche 1, branche 2…). Laissez vides les couleurs inutiles.</p>
						</td></tr>
				</table>

				<h2>Contenu</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Dates</th>
						<td><?php self::select( 'dates', $o['dates'], array( 'years' => 'Années seulement', 'full' => 'Dates complètes', 'none' => 'Aucune' ) ); ?></td></tr>
					<tr><th scope="row">Lieux</th>
						<td><?php self::select( 'places', $o['places'], array( 'none' => 'Aucun', 'commune' => 'Commune seule', 'full' => 'Lieu complet' ) ); ?></td></tr>
					<tr><th scope="row">Prénoms</th>
						<td><?php self::select( 'firstnames', $o['firstnames'], array( 'usual' => 'Prénom usuel (le premier)', 'all' => 'Tous les prénoms' ) ); ?></td></tr>
					<tr><th>Style des textes</th>
						<td><?php self::select( 'labels', $o['labels'], array( 'symbols' => '° naissance, † décès, x mariage', 'letters' => 'N : naissance, D : décès, M : mariage (style Heredis)' ) ); ?></td></tr>
					<tr><th scope="row">Conjoints</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[spouses]" value="1" <?php checked( 1, (int) $o['spouses'] ); ?>> Afficher les conjoints (anneau extérieur de chaque descendant, et au centre pour la personne de départ)</label></td></tr>
					<tr><th scope="row">Zoom</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[zoomdetail]" value="1" <?php checked( 1, (int) $o['zoomdetail'] ); ?>> Enrichir le texte au zoom (années, puis communes, puis dates et lieux complets)</label></td></tr>
					<tr><th scope="row">Ancêtres inconnus</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_empty]" value="1" <?php checked( 1, (int) $o['show_empty'] ); ?>> Afficher les emplacements vides</label></td></tr>
				</table>

				<h2>Accès et cache</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Qui peut voir le graphique</th>
						<td><?php self::select( 'access', $o['access'], array( 'all' => 'Tout le monde', 'logged' => 'Utilisateurs connectés', 'admin' => 'Administrateurs' ) ); ?></td></tr>
					<tr><th scope="row"><label for="tngfan_cache">Cache (minutes)</label></th>
						<td><input id="tngfan_cache" type="number" min="0" max="1440" name="<?php echo esc_attr( $n ); ?>[cache_minutes]" value="<?php echo esc_attr( $o['cache_minutes'] ); ?>" style="width:90px"> <span class="description">0 = pas de cache.</span></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	const GEN_OPT = 'tngfan_gen_saved';

	/** Configuration du générateur enregistrée (valeurs déjà validées), ou tableau vide. */
	public static function gen_saved() {
		$v = get_option( self::GEN_OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	/** AJAX : enregistre la configuration utilisée lors d'une génération. */
	public static function ajax_gen_save() {
		check_ajax_referer( 'tngfan_gen_save', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$in  = json_decode( wp_unslash( isset( $_POST['cfg'] ) ? (string) $_POST['cfg'] : '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$in  = is_array( $in ) ? $in : array();
		$pick = function ( $k, array $allowed, $def ) use ( $in ) {
			return ( isset( $in[ $k ] ) && in_array( (string) $in[ $k ], $allowed, true ) ) ? (string) $in[ $k ] : $def;
		};
		$pal = array_merge( array_keys( self::presets() ), array( 'custom' ) );
		$out = array(
			'person'     => isset( $in['person'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['person'] ) : '',
			'mode'       => $pick( 'mode', array( 'ancestors', 'descendants', 'mixed' ), 'ancestors' ),
			'shape'      => $pick( 'shape', array( 'full', 'three', 'half' ), 'full' ),
			'color'      => $pick( 'color', array( 'generation', 'branch', 'sex' ), 'generation' ),
			'style'      => $pick( 'style', array( 'tint', 'solid' ), 'tint' ),
			'palette'    => $pick( 'palette', $pal, 'rainbow' ),
			'dates'      => $pick( 'dates', array( 'years', 'full', 'none' ), 'years' ),
			'places'     => $pick( 'places', array( 'none', 'commune', 'full' ), 'none' ),
			'labels'     => $pick( 'labels', array( 'default', 'symbols', 'letters' ), 'default' ),
			'living'     => $pick( 'living', array( 'default', 'hide', 'show' ), 'default' ),
			'asc'        => max( 1, min( self::ASC_LIMIT, (int) ( isset( $in['asc'] ) ? $in['asc'] : 1 ) ) ),
			'desc'       => max( 1, min( self::DESC_LIMIT, (int) ( isset( $in['desc'] ) ? $in['desc'] : 1 ) ) ),
			'height'     => max( 360, min( 1600, (int) ( isset( $in['height'] ) ? $in['height'] : 720 ) ) ),
			'spouses'    => empty( $in['spouses'] ) ? 0 : 1,
			'zoomdetail' => empty( $in['zoomdetail'] ) ? 0 : 1,
			'pdf'        => empty( $in['pdf'] ) ? 0 : 1,
		);
		update_option( self::GEN_OPT, $out, false );
		wp_send_json_success();
	}

	/** Générateur : construit une roue depuis l'administration, aperçu en direct et shortcode à copier. */
	public static function generator_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = self::get();
		$db      = tngs_db();
		$presets = self::presets();
		$atts    = TNGFan_Front::default_atts();
		$atts['person'] = $o['default_person'];
		$base    = TNGFan_Front::config( $atts );
		$base['living'] = 'default';
		$base['lsig']   = '';
		$boot    = array(
			'cfg'     => $base,
			'sigShow' => TNGFan_Front::living_sig(),
			'defs'    => array(
				'mode' => $base['mode'], 'asc' => $base['asc'], 'desc' => $base['desc'], 'shape' => $base['shape'], 'color' => $base['color'],
				'style' => $base['style'], 'palette' => $base['palette'], 'dates' => $base['dates'], 'places' => $base['places'],
				'spouses' => $base['spouses'], 'zoomdetail' => $base['zoomDetail'], 'height' => (int) $o['height'],
			),
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tngfan_gen_save' ),
		);
		// dernière configuration utilisée (enregistrée à chaque génération)
		$sv   = self::gen_saved();
		$person_val = ! empty( $sv['person'] ) ? $sv['person'] : $o['default_person'];
		$pdf_val    = isset( $sv['pdf'] ) ? (int) $sv['pdf'] : 1;
		$living_val = isset( $sv['living'] ) ? $sv['living'] : 'default';
		$height_val = isset( $sv['height'] ) ? (int) $sv['height'] : (int) $o['height'];
		$labels_val = isset( $sv['labels'] ) ? $sv['labels'] : 'default';
		foreach ( array( 'mode', 'shape', 'color', 'style', 'palette', 'dates', 'places', 'asc', 'desc' ) as $k ) {
			if ( isset( $sv[ $k ] ) ) {
				$base[ $k ] = $sv[ $k ];
			}
		}
		if ( isset( $sv['spouses'] ) ) {
			$base['spouses'] = (int) $sv['spouses'];
		}
		if ( isset( $sv['zoomdetail'] ) ) {
			$base['zoomDetail'] = (int) $sv['zoomdetail'];
		}
		$sel = function ( $id, $choices, $value ) {
			echo '<select id="' . esc_attr( $id ) . '">';
			foreach ( $choices as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		};
		?>
		<?php tngs_admin_header( 'Éventail : générer une roue', array( 'module' => 'fan' ) ); ?>
			<p class="tngs-box">Choisissez une personne et les options, cliquez sur « Générer la roue » : l'aperçu est interactif (zoom, déplacement, export PDF).
				Copiez ensuite le shortcode dans une page. Les réglages par défaut se trouvent dans <a href="<?php echo esc_url( admin_url( 'admin.php?page=tng-fan' ) ); ?>">Éventail / roue</a>.
			</p>

			<form id="tngfan-gen" class="tngfan-genform" onsubmit="return false">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="tg-person">Personne de départ</label></th>
						<td>
							<input id="tg-person" type="text" class="regular-text" value="<?php echo esc_attr( $person_val ); ?>" placeholder="ex. I1">
							<input id="tg-q" type="search" class="regular-text" placeholder="…ou rechercher par nom" autocomplete="off">
							<div id="tg-res" class="tngfan-genres"></div>
						</td></tr>
					<tr><th scope="row">Type</th>
						<td><?php $sel( 'tg-mode', array( 'ancestors' => 'Roue d\'ascendance', 'descendants' => 'Roue de descendance', 'mixed' => 'Roue mixte' ), $base['mode'] ); ?>
							<?php $sel( 'tg-shape', array( 'full' => 'Cercle complet', 'three' => 'Trois quarts de cercle', 'half' => 'Demi-cercle' ), $base['shape'] ); ?></td></tr>
					<tr><th scope="row">Générations</th>
						<td><label>Ascendance <input id="tg-asc" type="number" min="1" max="12" value="<?php echo esc_attr( $base['asc'] ); ?>" style="width:70px"></label>
							<label style="margin-left:14px">Descendance <input id="tg-desc" type="number" min="1" max="<?php echo (int) $o['desc_max']; ?>" value="<?php echo esc_attr( $base['desc'] ); ?>" style="width:70px"></label></td></tr>
					<tr><th scope="row">Couleurs</th>
						<td><?php $sel( 'tg-color', array( 'generation' => 'Par génération', 'branch' => 'Par branche', 'sex' => 'Par sexe' ), $base['color'] ); ?>
							<?php $sel( 'tg-style', array( 'tint' => 'Teinte légère', 'solid' => 'Couleur pleine' ), $base['style'] ); ?>
							<select id="tg-palette"><?php foreach ( $presets as $id => $p ) : ?><option value="<?php echo esc_attr( $id ); ?>" <?php selected( $base['palette'], $id ); ?>><?php echo esc_html( $p[0] ); ?></option><?php endforeach; ?>
								<?php if ( self::palette_colors( 'custom' ) ) : ?><option value="custom" <?php selected( $base['palette'], 'custom' ); ?>>Ma palette personnalisée</option><?php endif; ?></select></td></tr>
					<tr><th scope="row">Contenu</th>
						<td>Dates <?php $sel( 'tg-dates', array( 'years' => 'Années', 'full' => 'Dates complètes', 'none' => 'Aucune' ), $base['dates'] ); ?>
							Lieux <?php $sel( 'tg-places', array( 'none' => 'Aucun', 'commune' => 'Commune', 'full' => 'Lieu complet' ), $base['places'] ); ?>
							Style <?php $sel( 'tg-labels', array( 'default' => 'Selon le réglage du module', 'symbols' => '° † x', 'letters' => 'N : D : M :' ), $labels_val ); ?><br>
							<label><input id="tg-spouses" type="checkbox" <?php checked( 1, (int) $base['spouses'] ); ?>> Afficher les conjoints</label>
							<label style="margin-left:14px"><input id="tg-zoomdetail" type="checkbox" <?php checked( 1, (int) $base['zoomDetail'] ); ?>> Enrichir le texte au zoom</label>
							<label style="margin-left:14px"><input id="tg-pdf" type="checkbox" <?php checked( 1, $pdf_val ); ?>> Bouton d'export PDF</label></td></tr>
					<tr><th scope="row">Personnes vivantes</th>
						<td><?php $sel( 'tg-living', array( 'default' => tngs_hide_living( 'fan' ) ? 'Selon le réglage du module (masquées)' : 'Selon le réglage du module (affichées)', 'hide' => 'Toujours masquer sur cette roue', 'show' => 'Afficher sur cette roue (⚠️ RGPD)' ), $living_val ); ?>
							<p id="tg-lvwarn" class="description" style="color:#b32d2e" hidden>⚠️ Attention RGPD : les noms, dates et lieux des personnes vivantes ou privées seront visibles de tous les visiteurs de la page qui contiendra ce shortcode.
								Ce choix est ignoré si le module est réglé sur « Toujours masquer » dans TNG Suite &gt; Général, ou si l'auteur de la page n'est pas éditeur ou administrateur.</p></td></tr>
					<tr><th scope="row"><label for="tg-height">Hauteur (px)</label></th>
						<td><input id="tg-height" type="number" min="360" max="1600" value="<?php echo esc_attr( $height_val ); ?>" style="width:90px"></td></tr>
				</table>
				<p><button type="button" id="tg-go" class="button button-primary button-hero">Générer la roue</button></p>
				<div id="tg-sc-wrap" hidden>
					<h2>Shortcode à copier</h2>
					<p><input id="tg-sc" type="text" class="large-text code" readonly onclick="this.select()"> <button type="button" id="tg-copy" class="button">Copier</button> <span id="tg-copied" hidden>Copié !</span></p>
				</div>
			</form>
			<div id="tg-msg" class="notice notice-warning inline" hidden><p></p></div>
			<div id="tg-stage"></div>
		</div>
		<script>
		window.TNGFAN_GEN = <?php echo wp_json_encode( $boot ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;
		(function () {
			var G = window.TNGFAN_GEN, $ = function (id) { return document.getElementById(id); };
			function val(id) { var e = $(id); return e.type === 'checkbox' ? (e.checked ? 1 : 0) : e.value; }
			$('tg-living').addEventListener('change', function () { $('tg-lvwarn').hidden = this.value !== 'show'; });
			var timer = null;
			$('tg-q').addEventListener('input', function () {
				var v = this.value.trim(), box = $('tg-res'), R = window.TNGFAN_REST || {};
				clearTimeout(timer);
				if (v.length < 2) { box.innerHTML = ''; return; }
				timer = setTimeout(function () {
					var lv = val('tg-living'), url = R.url + '/search?q=' + encodeURIComponent(v) + (lv === 'show' ? '&lv=show&ls=' + encodeURIComponent(G.sigShow) : (lv === 'hide' ? '&lv=hide' : ''));
					fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': R.nonce || '' } }).then(function (r) { return r.json(); }).then(function (list) {
						box.innerHTML = '';
						if (!Array.isArray(list) || !list.length) { box.textContent = tngsT('Aucun résultat.'); return; }
						list.forEach(function (p) {
							var b = document.createElement('button');
							b.type = 'button'; b.className = 'button button-small';
							b.textContent = p.id + ' – ' + p.name + (p.b || p.d ? ' (' + (p.b || '') + ' – ' + (p.d || '') + ')' : '');
							b.onclick = function () { $('tg-person').value = p.id; box.innerHTML = ''; $('tg-q').value = ''; };
							box.appendChild(b);
						});
					});
				}, 250);
			});
			function shortcode(c) {
				var d = G.defs, parts = ['person="' + c.root + '"'];
				[['mode', c.mode], ['asc', c.asc], ['desc', c.desc], ['shape', c.shape], ['color', c.color], ['style', c.style], ['palette', c.palette], ['dates', c.dates], ['places', c.places]].forEach(function (kv) {
					parts.push(kv[0] + '="' + kv[1] + '"');
				});
				if (val('tg-labels') !== 'default') { parts.push('labels="' + val('tg-labels') + '"'); }
				parts.push('spouses="' + c.spouses + '"');
				parts.push('living="' + c.living + '"');
				if (!c.zoomDetail) { parts.push('zoomdetail="0"'); }
				if (!c.pdf) { parts.push('pdf="0"'); }
				if (+val('tg-height') !== d.height) { parts.push('height="' + (+val('tg-height')) + '"'); }
				return '[tng_fan ' + parts.join(' ') + ']';
			}
			$('tg-go').addEventListener('click', function () {
				var person = $('tg-person').value.replace(/[^A-Za-z0-9_\-]/g, '');
				var m = $('tg-msg');
				if (!person) { m.hidden = false; m.firstElementChild.textContent = tngsT('Indiquez la personne de départ.'); return; }
				m.hidden = true;
				var c = JSON.parse(JSON.stringify(G.cfg)), lv = val('tg-living');
				c.root = person; c.mode = val('tg-mode'); c.shape = val('tg-shape'); c.color = val('tg-color'); c.style = val('tg-style');
				c.asc = Math.max(1, Math.min(12, +val('tg-asc') || 1)); c.desc = Math.max(1, Math.min(c.descMax, +val('tg-desc') || 1));
				c.palette = val('tg-palette'); c.colors = (c.presets[c.palette] || c.presets.rainbow)[1].slice();
				c.dates = val('tg-dates'); c.places = val('tg-places'); c.spouses = val('tg-spouses'); c.zoomDetail = val('tg-zoomdetail'); c.pdf = val('tg-pdf');
				if (val('tg-labels') !== 'default') { c.labels = val('tg-labels'); }
				c.living = lv; c.lsig = lv === 'show' ? G.sigShow : '';
				var h = Math.max(360, Math.min(1600, +val('tg-height') || 720));
				var stage = $('tg-stage');
				stage.innerHTML = '';
				var box = document.createElement('div');
				box.className = 'tngfan';
				box.style.height = h + 'px';
				box.setAttribute('data-config', JSON.stringify(c));
				stage.appendChild(box);
				box.setAttribute('data-ready', '1');
				if (window.TNGFanMount) { window.TNGFanMount(box); }
				$('tg-sc').value = shortcode(c);
				// mémorise la configuration pour la prochaine visite
				try {
					var fd = new FormData();
					fd.append('action', 'tngfan_gen_save'); fd.append('nonce', G.nonce);
					fd.append('cfg', JSON.stringify({ person: person, mode: c.mode, shape: c.shape, color: c.color, style: c.style, palette: c.palette, dates: c.dates, places: c.places,
						labels: val('tg-labels'), living: lv, asc: c.asc, desc: c.desc, height: h, spouses: c.spouses, zoomdetail: c.zoomDetail, pdf: c.pdf }));
					fetch(G.ajax, { method: 'POST', credentials: 'same-origin', body: fd });
				} catch (e) {}
				$('tg-sc-wrap').hidden = false;
				box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			});
			$('tg-copy').addEventListener('click', function () {
				var f = $('tg-sc'); f.select();
				try { document.execCommand('copy'); } catch (e) {}
				if (navigator.clipboard) { navigator.clipboard.writeText(f.value).catch(function () {}); }
				$('tg-copied').hidden = false; setTimeout(function () { $('tg-copied').hidden = true; }, 1800);
			});
		})();
		</script>
		<style>.tngfan-genres{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px}#tg-stage{margin-top:14px;max-width:1200px}</style>
		<?php
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shortcode [tng_fan], API REST des données et chargement des fichiers du graphique. */
class TNGFan_Front {

	public static function init() {
		add_shortcode( 'tng_fan', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'tng_roue', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'tngfan', TNGFAN_URL . 'assets/fan.css', array(), TNGFAN_VERSION );
		wp_register_script( 'tngfan-app', TNGFAN_URL . 'assets/fan.js', array(), TNGFAN_VERSION, true );
		tngs_js_i18n( 'tngfan-app' );
	}

	/** Accès aux données selon le réglage du module. */
	public static function can_view() {
		$a = TNGFan_Admin::get( 'access' );
		if ( 'admin' === $a ) {
			return current_user_can( 'manage_options' );
		}
		if ( 'logged' === $a ) {
			return is_user_logged_in();
		}
		return true;
	}

	/* ------------------------------------------------------------------ Vivants / privés */

	/** Signature qui autorise l'affichage des vivants pour les requêtes d'un graphique dont le shortcode le demande. */
	public static function living_sig() {
		return wp_hash( 'tngfan-living-show' );
	}

	/**
	 * Décision pour un shortcode : « default » (réglage du module), « hide » ou « show ».
	 * « show » n'est accepté que si l'auteur de la page peut publier pour les autres (éditeur et plus) et si
	 * le module n'est pas réglé sur « toujours masquer » dans TNG Suite > Général.
	 */
	public static function living_for_shortcode( $requested ) {
		$requested = strtolower( trim( (string) $requested ) );
		if ( 'hide' === $requested ) {
			return 'hide';
		}
		if ( 'show' !== $requested ) {
			return 'default';
		}
		if ( 'hide' === tngs_privacy_mode( 'fan' ) ) {
			return 'hide';
		}
		$post   = get_post();
		$author = $post ? (int) $post->post_author : 0;
		$ok     = $author ? user_can( $author, 'edit_others_posts' ) : current_user_can( 'edit_others_posts' );
		return $ok ? 'show' : 'default';
	}

	/** Masquer les vivants pour une requête REST ? */
	public static function hide_for_request( $lv, $ls ) {
		if ( 'hide' === tngs_privacy_mode( 'fan' ) || 'hide' === $lv ) {
			return true;
		}
		if ( 'show' === $lv && is_string( $ls ) && hash_equals( self::living_sig(), $ls ) ) {
			return false;
		}
		return tngs_hide_living( 'fan' );
	}

	/* ------------------------------------------------------------------ Configuration du graphique */

	/** Valeurs par défaut des attributs du shortcode. */
	public static function default_atts() {
		$o = TNGFan_Admin::get();
		return array(
			'person'     => $o['default_person'],
			'tree'       => '',
			'mode'       => $o['mode'],
			'asc'        => $o['asc'],
			'desc'       => $o['desc'],
			'shape'      => $o['shape'],
			'color'      => $o['color'],
			'style'      => $o['style'],
			'palette'    => $o['palette'],
			'colors'     => '',
			'dates'      => $o['dates'],
			'places'     => $o['places'],
			'spouses'    => $o['spouses'],
			'living'     => 'default',
			'zoomdetail' => $o['zoomdetail'],
			'labels'     => '',
			'pdf'        => '1',
			'height'     => $o['height'],
			'controls'   => '1',
			'class'      => '',
		);
	}

	/** Configuration JSON transmise au script (utilisée par le shortcode et par le générateur d'administration). */
	public static function config( array $a ) {
		$o       = TNGFan_Admin::get();
		$pick    = function ( $v, array $allowed, $def ) {
			return in_array( (string) $v, $allowed, true ) ? (string) $v : $def;
		};
		$flag    = function ( $v ) {
			return in_array( strtolower( (string) $v ), array( '0', 'no', 'non', 'false', 'off', '' ), true ) ? 0 : 1;
		};
		$presets = TNGFan_Admin::presets();

		// Palette : couleurs données dans le shortcode > palette choisie > palette par défaut.
		$colors = TNGFan_Admin::clean_colors( '' !== $a['colors'] ? explode( ',', (string) $a['colors'] ) : array(), 12 );
		$pal_id = in_array( $a['palette'], array_merge( array_keys( $presets ), array( 'custom' ) ), true ) ? $a['palette'] : 'rainbow';
		if ( ! $colors ) {
			$colors = TNGFan_Admin::palette_colors( $pal_id );
			if ( ! $colors ) {
				$colors = $presets['rainbow'][1];
			}
		}
		$list = array();
		foreach ( $presets as $id => $p ) {
			$list[ $id ] = array( tngs_t( $p[0] ), $p[1] );
		}
		$custom = TNGFan_Admin::palette_colors( 'custom' );
		if ( $custom ) {
			$list['custom'] = array( tngs_t( 'Ma palette personnalisée' ), $custom );
		}

		$living = in_array( $a['living'], array( 'hide', 'show' ), true ) ? $a['living'] : 'default';

		return array(
			'root'       => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $a['person'] ),
			'tree'       => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $a['tree'] ),
			'mode'       => $pick( $a['mode'], array( 'ancestors', 'descendants', 'mixed' ), 'ancestors' ),
			'asc'        => max( 1, min( TNGFan_Admin::ASC_LIMIT, (int) $a['asc'] ) ),
			'desc'       => max( 1, min( (int) $o['desc_max'], (int) $a['desc'] ) ),
			'ascMax'     => TNGFan_Admin::ASC_LIMIT,
			'descMax'    => (int) $o['desc_max'],
			'shape'      => $pick( $a['shape'], array( 'full', 'three', 'half' ), 'full' ),
			'color'      => $pick( $a['color'], array( 'generation', 'branch', 'sex' ), 'generation' ),
			'style'      => $pick( $a['style'], array( 'tint', 'solid' ), 'tint' ),
			'palette'    => $pal_id,
			'colors'     => $colors,
			'presets'    => $list,
			'dates'      => $pick( $a['dates'], array( 'years', 'full', 'none' ), 'years' ),
			'places'     => $pick( $a['places'], array( 'none', 'commune', 'full' ), 'none' ),
			'firstnames' => $o['firstnames'],
			'labels'     => $pick( $a['labels'], array( 'symbols', 'letters' ), isset( $o['labels'] ) ? $o['labels'] : 'symbols' ),
			'showEmpty'  => (int) $o['show_empty'],
			'spouses'    => $flag( $a['spouses'] ),
			'zoomDetail' => $flag( $a['zoomdetail'] ),
			'pdf'        => $flag( $a['pdf'] ),
			'living'     => $living,
			'lsig'       => 'show' === $living ? self::living_sig() : '',
			'siteUrl'    => tngs_site_url(),
			'controls'   => $flag( $a['controls'] ),
		);
	}

	/** Données de départ du script (une seule fois par page). */
	public static function boot_script() {
		static $boot = false;
		if ( $boot ) {
			return;
		}
		$boot = true;
		wp_add_inline_script(
			'tngfan-app',
			'window.TNGFAN_REST = ' . wp_json_encode( array( 'url' => esc_url_raw( rest_url( 'tngfan/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ) . ';',
			'before'
		);
	}

	/* ------------------------------------------------------------------ Shortcode */

	public static function shortcode( $atts ) {
		$a = shortcode_atts( self::default_atts(), $atts, 'tng_fan' );

		if ( ! self::can_view() ) {
			return '<p class="tngfan-msg">' . esc_html__( 'Ce graphique est réservé aux utilisateurs autorisés.', 'tng-suite' ) . '</p>';
		}
		$person = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $a['person'] );
		if ( '' === $person ) {
			return current_user_can( 'manage_options' )
				? '<p class="tngfan-msg">' . esc_html__( 'Éventail : indiquez la personne de départ, par exemple [tng_fan person="I1"].', 'tng-suite' ) . '</p>'
				: '';
		}
		$a['living'] = self::living_for_shortcode( $a['living'] );
		$cfg         = self::config( $a );

		wp_enqueue_style( 'tngfan' );
		wp_enqueue_script( 'tngfan-app' );
		self::boot_script();

		return sprintf(
			'<div class="tngfan %s" data-config="%s" style="height:%dpx"><noscript>%s</noscript></div>',
			esc_attr( sanitize_html_class( $a['class'] ) ),
			esc_attr( wp_json_encode( $cfg ) ),
			max( 360, min( 1600, (int) $a['height'] ) ),
			esc_html__( 'Ce graphique nécessite JavaScript.', 'tng-suite' )
		);
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		register_rest_route(
			'tngfan/v1',
			'/data',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_data' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);
		register_rest_route(
			'tngfan/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);
	}

	private static function clean_id( $v ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
	}

	private static function fail( $msg, $status = 400 ) {
		return new WP_Error( 'tngfan', $msg, array( 'status' => $status ) );
	}

	public static function rest_data( WP_REST_Request $req ) {
		$o      = TNGFan_Admin::get();
		$person = self::clean_id( $req->get_param( 'person' ) );
		if ( '' === $person ) {
			return self::fail( tngs_t( 'Identifiant manquant.' ) );
		}
		$asc     = max( 0, min( TNGFan_Admin::ASC_LIMIT, (int) $req->get_param( 'asc' ) ) );
		$desc    = max( 0, min( (int) $o['desc_max'], (int) $req->get_param( 'desc' ) ) );
		$spouses = (bool) (int) $req->get_param( 'spouses' );
		$db   = tngs_db();
		if ( ! $db ) {
			return self::fail( current_user_can( 'manage_options' ) ? tngs_db_error_message() : tngs_t( 'Connexion à la base TNG impossible.' ), 503 );
		}
		$prefix = tngs_prefix();
		$tree   = TNGFan_Data::resolve_tree( $db, $prefix, self::clean_id( $req->get_param( 'tree' ) ), $person );
		$hide   = self::hide_for_request( (string) $req->get_param( 'lv' ), (string) $req->get_param( 'ls' ) );

		$mins = (int) $o['cache_minutes'];
		$key  = 'tngfan_' . md5( wp_json_encode( array( $person, $tree, $asc, $desc, $spouses, $hide, $prefix, 'u2', tngs_cache_ver() ) ) );
		if ( $mins > 0 ) {
			$hit = get_transient( $key );
			if ( false !== $hit ) {
				return rest_ensure_response( $hit );
			}
		}
		try {
			$data = ( new TNGFan_Data( $db, $prefix, $tree, $hide ) )->build( $person, $asc, $desc, $spouses );
		} catch ( Exception $e ) {
			return self::fail( current_user_can( 'manage_options' ) ? $e->getMessage() : tngs_t( 'Erreur de lecture de la base TNG.' ), 500 );
		}
		if ( null === $data ) {
			return self::fail( tngs_t( 'Personne introuvable.' ), 404 );
		}
		$data['tree'] = $tree;
		$data['root'] = $person;
		if ( $mins > 0 ) {
			set_transient( $key, $data, $mins * MINUTE_IN_SECONDS );
		}
		return rest_ensure_response( $data );
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q = trim( sanitize_text_field( (string) $req->get_param( 'q' ) ) );
		if ( strlen( $q ) < 2 ) {
			return rest_ensure_response( array() );
		}
		$db = tngs_db();
		if ( ! $db ) {
			return self::fail( tngs_t( 'Connexion à la base TNG impossible.' ), 503 );
		}
		$prefix = tngs_prefix();
		$tree   = TNGFan_Data::resolve_tree( $db, $prefix, self::clean_id( $req->get_param( 'tree' ) ), '' );
		try {
			return rest_ensure_response( ( new TNGFan_Data( $db, $prefix, $tree, self::hide_for_request( (string) $req->get_param( 'lv' ), (string) $req->get_param( 'ls' ) ) ) )->search( $q ) );
		} catch ( Exception $e ) {
			return self::fail( tngs_t( 'Erreur de lecture de la base TNG.' ), 500 );
		}
	}
}

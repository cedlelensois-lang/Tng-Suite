<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [tng_implexe], routes REST et chargement des scripts.
 */
class TNGI_Front {

	const NS = 'tng-implexe/v1';

	public static function init() {
		add_shortcode( 'tng_implexe', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'tng_consanguinite', array( __CLASS__, 'shortcode_inbreeding' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes_inbreeding' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/* ------------------------------------------------------------------ Accès */

	public static function can_view() {
		switch ( TNGI_Admin::get( 'access' ) ) {
			case 'all':
				return true;
			case 'admin':
				return current_user_can( 'manage_options' );
			default:
				return is_user_logged_in();
		}
	}

	/* ------------------------------------------------------------------ Assets & shortcode */

	public static function register_assets() {
		wp_register_style( 'tngi', TNGI_URL . 'assets/tngi.css', array(), TNGI_VERSION );
		$chart = tngs_register_chartjs();
		wp_register_script( 'tngi-vis', tngs_lib_url( 'vis-network.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/vis-network/9.1.9/standalone/umd/vis-network.min.js' ), array(), '9.1.9', true );
		wp_register_script( 'tngi-app', TNGI_URL . 'assets/tngi.js', array( $chart, 'tngi-vis' ), TNGI_VERSION, true );
		tngs_js_i18n( 'tngi-app' );
	}

	private static function enqueue_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		// Au cas où le shortcode est rendu avant wp_enqueue_scripts (ex. aperçu, builders).
		if ( ! wp_script_is( 'tngi-app', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'tngi' );
		wp_enqueue_script( 'tngi-app' );
		wp_add_inline_script(
			'tngi-app',
			'window.TNGI = ' . wp_json_encode(
				array(
					'rest'  => esc_url_raw( rest_url( self::NS . '/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	public static function shortcode( $atts ) {
		return self::render( $atts, false );
	}

	/**
	 * @param array $atts   Attributs du shortcode.
	 * @param bool  $bypass Ignorer le contrôle d'accès (page d'administration déjà protégée).
	 */
	public static function render( $atts, $bypass = false ) {
		if ( ! $bypass && ! self::can_view() ) {
			return '<p class="tngi-notice">Cette analyse généalogique est réservée aux utilisateurs autorisés.</p>';
		}
		$a = shortcode_atts(
			array(
				'tree'                => '',
				'person'              => '',
				'generations'         => '',
				'network_generations' => '',
			),
			$atts,
			'tng_implexe'
		);

		$tree   = '' !== $a['tree'] ? $a['tree'] : TNGI_Admin::get( 'default_tree' );
		$person = '' !== $a['person'] ? $a['person'] : TNGI_Admin::get( 'default_person' );
		$cap    = (int) TNGI_Admin::get( 'max_gen' );
		$gens   = '' !== $a['generations'] ? max( 2, min( $cap, (int) $a['generations'] ) ) : $cap;
		$net    = '' !== $a['network_generations'] ? max( 2, min( $gens, 10, (int) $a['network_generations'] ) ) : min( $gens, (int) TNGI_Admin::get( 'net_gen' ) );

		self::enqueue_assets();

		return sprintf(
			'<div class="tngi-app" data-tree="%s" data-person="%s" data-gens="%d" data-maxgens="%d" data-net="%d"></div>',
			esc_attr( $tree ),
			esc_attr( $person ),
			$gens,
			$cap,
			$net
		);
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		$perm = array( __CLASS__, 'can_view' );

		register_rest_route(
			self::NS,
			'/trees',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_trees' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'permission_callback' => $perm,
				'args'                => array(
					'tree' => array( 'type' => 'string', 'required' => true ),
					'q'    => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/analyze',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_analyze' ),
				'permission_callback' => $perm,
				'args'                => array(
					'tree'        => array( 'type' => 'string', 'required' => true ),
					'person'      => array( 'type' => 'string', 'required' => true ),
					'generations' => array( 'type' => 'integer', 'default' => 25 ),
					'net_gen'     => array( 'type' => 'integer', 'default' => 6 ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ Consanguinité */

	public static function shortcode_inbreeding( $atts ) {
		return self::render_inbreeding( $atts, false );
	}

	public static function render_inbreeding( $atts, $bypass = false ) {
		if ( ! $bypass && ! self::can_view() ) {
			return '<p class="tngi-notice">Ce classement généalogique est réservé aux utilisateurs autorisés.</p>';
		}
		$a    = shortcode_atts( array( 'tree' => '', 'per_page' => 50 ), $atts, 'tng_consanguinite' );
		$tree = '' !== $a['tree'] ? $a['tree'] : TNGI_Admin::get( 'default_tree' );
		self::enqueue_assets();
		return sprintf(
			'<div class="tngi-app tngi-inb" data-tree="%s" data-per="%d" data-admin="%d"></div>',
			esc_attr( $tree ),
			max( 10, min( 100, (int) $a['per_page'] ) ),
			current_user_can( 'manage_options' ) ? 1 : 0
		);
	}

	public static function routes_inbreeding() {
		register_rest_route(
			self::NS,
			'/inbreeding/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_inb_step' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'tree'   => array( 'type' => 'string', 'required' => true ),
					'offset' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/inbreeding/rank',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_inb_rank' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'tree'     => array( 'type' => 'string', 'required' => true ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50 ),
					'min'      => array( 'type' => 'number', 'default' => 0 ),
				),
			)
		);
	}

	/** Traite un lot de calcul (administrateurs uniquement). */
	public static function rest_inb_step( WP_REST_Request $req ) {
		global $wpdb;
		$tree   = sanitize_text_field( (string) $req->get_param( 'tree' ) );
		$offset = max( 0, (int) $req->get_param( 'offset' ) );
		if ( '' === $tree ) {
			return new WP_Error( 'tngi_params', 'Arbre requis.', array( 'status' => 400 ) );
		}
		$db = TNGI_Admin::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		TNGI_Inbreeding::ensure_table();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$calc = new TNGI_Inbreeding( $db, TNGI_Admin::clean_prefix( TNGI_Admin::get( 'prefix' ) ), $tree );
		try {
			$res = $calc->step( $offset );
		} catch ( Exception $e ) {
			return self::fail( $e );
		}

		$table = TNGI_Inbreeding::table();
		$key   = md5( $tree );
		$meta  = get_option( 'tngi_inb_meta', array() );
		if ( 0 === $offset ) {
			$wpdb->delete( $table, array( 'tree' => $tree ), array( '%s' ) );
			$meta[ $key ] = array();
		}
		$m = wp_parse_args( isset( $meta[ $key ] ) ? $meta[ $key ] : array(), array( 'tree' => $tree, 'inbred' => 0, 'sum_f' => 0.0, 'max_f' => 0.0 ) );
		foreach ( $res['rows'] as $r ) {
			$wpdb->replace(
				$table,
				array( 'tree' => $tree, 'person_id' => $r['person_id'], 'f' => $r['f'], 'depth' => $r['depth'], 'common' => $r['common'] ),
				array( '%s', '%s', '%f', '%d', '%d' )
			);
			$m['inbred'] += 1;
			$m['sum_f']  += $r['f'];
			$m['max_f']   = max( $m['max_f'], $r['f'] );
		}
		$m['total']    = $res['total'];
		$m['complete'] = $res['done'];
		if ( $res['done'] ) {
			$m['date'] = current_time( 'mysql' );
		}
		$meta[ $key ] = $m;
		update_option( 'tngi_inb_meta', $meta, false );

		return rest_ensure_response(
			array(
				'next'   => $res['next'],
				'total'  => $res['total'],
				'done'   => $res['done'],
				'inbred' => $m['inbred'],
			)
		);
	}

	/** Classement des individus par coefficient de consanguinité décroissant. */
	public static function rest_inb_rank( WP_REST_Request $req ) {
		global $wpdb;
		$tree = sanitize_text_field( (string) $req->get_param( 'tree' ) );
		if ( '' === $tree ) {
			return new WP_Error( 'tngi_params', 'Arbre requis.', array( 'status' => 400 ) );
		}
		TNGI_Inbreeding::ensure_table();
		$page = max( 1, (int) $req->get_param( 'page' ) );
		$per  = max( 10, min( 100, (int) $req->get_param( 'per_page' ) ) );
		$thr  = max( 0.0, (float) $req->get_param( 'min' ) / 100 - 1e-12 );
		$tbl  = TNGI_Inbreeding::table();

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE tree = %s AND f >= %f", $tree, $thr ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT person_id, f, depth, common FROM {$tbl} WHERE tree = %s AND f >= %f ORDER BY f DESC, depth DESC, person_id ASC LIMIT %d OFFSET %d",
				$tree,
				$thr,
				$per,
				( $page - 1 ) * $per
			),
			ARRAY_A
		);
		$rows = $rows ? $rows : array();

		// Répartition par classes de F (indépendante du filtre).
		$edges = array( 0.25, 0.125, 0.0625, 0.03125, 0.015625, 0.0078125 );
		$sel   = array();
		foreach ( $edges as $i => $e ) {
			$lo    = number_format( $e - 1e-12, 12, '.', '' );
			$sel[] = 0 === $i
				? "SUM(f >= {$lo}) AS b{$i}"
				: "SUM(f >= {$lo} AND f < " . number_format( $edges[ $i - 1 ] - 1e-12, 12, '.', '' ) . ") AS b{$i}";
		}
		$sel[] = 'SUM(f < ' . number_format( end( $edges ) - 1e-12, 12, '.', '' ) . ') AS b' . count( $edges );
		$dist  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ', ', $sel ) . " FROM {$tbl} WHERE tree = %s", $tree ), ARRAY_A );
		$distribution = array();
		for ( $i = 0; $i <= count( $edges ); $i++ ) {
			$distribution[] = $dist && isset( $dist[ 'b' . $i ] ) ? (int) $dist[ 'b' . $i ] : 0;
		}

		$items = array();
		if ( $rows ) {
			$eng = self::engine( $tree );
			if ( is_wp_error( $eng ) ) {
				return $eng;
			}
			try {
				$desc = $eng->describe( wp_list_pluck( $rows, 'person_id' ) );
			} catch ( Exception $e ) {
				return self::fail( $e );
			}
			foreach ( $rows as $i => $r ) {
				$id   = (string) $r['person_id'];
				$d    = isset( $desc[ $id ] ) ? $desc[ $id ] : array( 'person' => array( 'id' => $id, 'name' => '[' . $id . ']', 'years' => '' ), 'father' => null, 'mother' => null );
				$items[] = array(
					'rank'   => ( $page - 1 ) * $per + $i + 1,
					'person' => $d['person'],
					'father' => $d['father'],
					'mother' => $d['mother'],
					'f'      => (float) $r['f'],
					'depth'  => (int) $r['depth'],
					'common' => (int) $r['common'],
				);
			}
		}

		$meta = get_option( 'tngi_inb_meta', array() );
		$m    = isset( $meta[ md5( $tree ) ] ) ? $meta[ md5( $tree ) ] : null;

		return rest_ensure_response(
			array(
				'items'        => $items,
				'total'        => $total,
				'page'         => $page,
				'pages'        => max( 1, (int) ceil( $total / $per ) ),
				'distribution' => $distribution,
				'meta'         => $m,
			)
		);
	}

	private static function engine( $tree ) {
		$db = TNGI_Admin::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		return new TNGI_Engine(
			$db,
			TNGI_Admin::clean_prefix( TNGI_Admin::get( 'prefix' ) ),
			$tree,
			(bool) TNGI_Admin::get( 'mask_living' )
		);
	}

	private static function fail( Exception $e, $status = 500 ) {
		$msg = current_user_can( 'manage_options' ) ? $e->getMessage() : 'Erreur lors de la lecture de la base généalogique.';
		return new WP_Error( 'tngi_error', $msg, array( 'status' => $status ) );
	}

	public static function rest_trees() {
		$eng = self::engine( '' );
		if ( is_wp_error( $eng ) ) {
			return $eng;
		}
		try {
			return rest_ensure_response( $eng->trees() );
		} catch ( Exception $e ) {
			return self::fail( $e );
		}
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q = trim( sanitize_text_field( (string) $req->get_param( 'q' ) ) );
		if ( mb_strlen( $q ) < 2 ) {
			return rest_ensure_response( array() );
		}
		$eng = self::engine( sanitize_text_field( (string) $req->get_param( 'tree' ) ) );
		if ( is_wp_error( $eng ) ) {
			return $eng;
		}
		try {
			return rest_ensure_response( $eng->search( $q ) );
		} catch ( Exception $e ) {
			return self::fail( $e );
		}
	}

	public static function rest_analyze( WP_REST_Request $req ) {
		$tree   = sanitize_text_field( (string) $req->get_param( 'tree' ) );
		$person = sanitize_text_field( (string) $req->get_param( 'person' ) );
		$cap    = (int) TNGI_Admin::get( 'max_gen' );
		$gens   = max( 1, min( $cap, (int) $req->get_param( 'generations' ) ) );
		$net    = max( 1, min( $gens, 10, (int) $req->get_param( 'net_gen' ) ) );

		if ( '' === $tree || '' === $person ) {
			return new WP_Error( 'tngi_params', 'Arbre et personne requis.', array( 'status' => 400 ) );
		}

		$mins = (int) TNGI_Admin::get( 'cache_minutes' );
		$key  = 'tngi_' . md5(
			wp_json_encode(
				array( $tree, $person, $gens, $net, (int) TNGI_Admin::get( 'mask_living' ), TNGI_Admin::get( 'prefix' ), tngs_cache_ver() )
			)
		);
		if ( $mins > 0 ) {
			$hit = get_transient( $key );
			if ( false !== $hit ) {
				return rest_ensure_response( $hit );
			}
		}

		$eng = self::engine( $tree );
		if ( is_wp_error( $eng ) ) {
			return $eng;
		}
		try {
			$data = $eng->analyze( $person, $gens, $net );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'tngi_notfound', $e->getMessage(), array( 'status' => 404 ) );
		} catch ( Exception $e ) {
			return self::fail( $e );
		}

		if ( $mins > 0 ) {
			set_transient( $key, $data, $mins * MINUTE_IN_SECONDS );
		}
		return rest_ensure_response( $data );
	}
}

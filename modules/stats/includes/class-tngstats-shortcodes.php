<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Déclaration des statistiques, shortcodes et rendu.
 */
class TNGStats_Shortcodes {

	public static function init() {
		add_shortcode( 'tng_stat', array( __CLASS__, 'generic' ) );
		foreach ( self::stats() as $key => $def ) {
			add_shortcode(
				$def['alias'],
				function ( $atts ) use ( $key ) {
					return self::render( $key, $atts );
				}
			);
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Catalogue des statistiques : shortcode, types de graphique autorisés, attributs.
	 */
	public static function stats() {
		$all_pie = array( 'pie', 'doughnut', 'polarArea', 'bar', 'hbar', 'table' );
		return array(
			'centenarians'  => array(
				'alias'   => 'tng_stat_centenaires',
				'title'   => 'Les 10 plus longues vies',
				'types'   => array( 'table', 'hbar', 'bar' ),
				'default' => 'table',
				'atts'    => array( 'limit' => '10', 'min_age' => '100', 'precision' => 'day', 'person' => '' ),
				'help'    => 'Ancêtres décédés à 100 ans ou plus. precision = day (dates complètes) ou year. person = ID pour se limiter aux ancêtres de cette personne.',
			),
			'gender'        => array(
				'alias'   => 'tng_stat_genre',
				'title'   => 'Répartition des individus par genre',
				'types'   => $all_pie,
				'default' => 'doughnut',
				'atts'    => array(),
				'help'    => 'Hommes, femmes, inconnus (vivants inclus).',
			),
			'living'        => array(
				'alias'   => 'tng_stat_vivants',
				'title'   => 'Répartition des décès et des vivants de la base',
				'types'   => $all_pie,
				'default' => 'polarArea',
				'atts'    => array(),
				'help'    => 'Nombre de personnes décédées et vivantes de l\'arbre (totaux uniquement). Renommez les libellés (ex. Oui / Non) dans Stats : graphiques.',
			),
			'marriage_age'  => array(
				'alias'   => 'tng_stat_age_union',
				'title'   => 'Moyenne d\'âge à la première union (H/F)',
				'types'   => array( 'line', 'area', 'bar', 'table' ),
				'default' => 'line',
				'atts'    => array( 'min_n' => '3' ),
				'help'    => 'Par siècle du mariage. min_n = nombre minimal de cas par siècle et par sexe.',
			),
			'life_expectancy' => array(
				'alias'   => 'tng_stat_esperance',
				'title'   => 'Espérance de vie moyenne par siècle',
				'types'   => array( 'line', 'area', 'bar', 'table' ),
				'default' => 'line',
				'atts'    => array( 'basis' => 'birth', 'min_age' => '0', 'min_n' => '5' ),
				'help'    => 'Âge moyen au décès (naissance et décès connus), par siècle de naissance (basis = birth) ou de décès (basis = death). min_age = âge minimal au décès pris en compte (0 = tous ; 5 ou 20 pour écarter la mortalité infantile). min_n = nombre minimal de personnes par siècle.',
			),
			'pyramid'       => array(
				'alias'   => 'tng_stat_pyramide',
				'title'   => 'Pyramide des âges',
				'types'   => array( 'pyramid', 'bar', 'table' ),
				'default' => 'pyramid',
				'atts'    => array( 'step' => '10', 'year' => '', 'max_age' => '100' ),
				'help'    => 'Sans year : répartition des âges au décès, hommes à gauche, femmes à droite. Avec year="1850" : âges des personnes vivantes cette année-là (naissance connue, décès connu postérieur). step = taille des tranches (5 ou 10), max_age = dernière tranche.',
			),
			'distance'      => array(
				'alias'   => 'tng_stat_distance',
				'title'   => 'Distance moyenne, en km, entre lieu de naissance et décès',
				'types'   => array( 'line', 'area', 'bar', 'hbar', 'table' ),
				'default' => 'line',
				'atts'    => array( 'from' => '1650', 'to' => '2024', 'step' => '75', 'basis' => 'death' ),
				'help'    => 'Nécessite des lieux géolocalisés dans TNG. basis = death ou birth (année servant à former les périodes).',
			),
			'half_century'  => array(
				'alias'   => 'tng_stat_demi_siecle',
				'title'   => 'Répartition des naissances et décès par demi-siècle',
				'types'   => array( 'bar', 'line', 'area', 'table' ),
				'default' => 'bar',
				'atts'    => array( 'from' => '1200', 'to' => '2000', 'step' => '50' ),
				'help'    => 'step = taille de la période en années (25, 50, 100…).',
			),
			'months'        => array(
				'alias'   => 'tng_stat_mois',
				'title'   => 'Naissances et décès par mois',
				'types'   => array( 'area', 'line', 'bar', 'radar', 'table' ),
				'default' => 'area',
				'atts'    => array(),
				'help'    => '',
			),
			'places'        => array(
				'alias'   => 'tng_stat_villes',
				'title'   => 'Principales villes',
				'types'   => array( 'hbar', 'bar', 'pie', 'doughnut', 'polarArea', 'table' ),
				'default' => 'hbar',
				'atts'    => array( 'limit' => '20', 'level' => '1', 'source' => 'birth,baptism,marriage,death,burial', 'others' => '1' ),
				'help'    => 'source = événements comptés, séparés par des virgules : birth (naissances), baptism (baptêmes), marriage (mariages), death (décès), burial (sépultures), ou all (les cinq, par défaut). level = rang du segment du lieu (1 = premier, avant la première virgule). others = 1 pour ajouter « Autres ».',
			),
			'occupations'   => array(
				'alias'   => 'tng_stat_metiers',
				'title'   => 'Principaux métiers exercés par les individus en %',
				'types'   => array( 'pie', 'doughnut', 'polarArea', 'bar', 'hbar', 'table' ),
				'default' => 'pie',
				'atts'    => array( 'limit' => '15', 'others' => '0' ),
				'help'    => 'Catégories modifiables dans Réglages > TNG Stats. others = 1 pour afficher les professions non classées.',
			),
		);
	}

	/** Libellés d'origine de chaque graphique (aide affichée dans Stats : graphiques). */
	public static function label_hints() {
		return array(
			'centenarians' => "Âge au décès",
			'gender'       => "Hommes\nFemmes\nInconnus\nIndividus",
			'living'       => "Décédés\nVivants\nIndividus",
			'marriage_age' => "Hommes\nFemmes",
			'life_expectancy' => "Hommes\nFemmes\nEnsemble",
			'pyramid'      => "Hommes\nFemmes",
			'distance'     => "Distance moyenne (km)",
			'half_century' => "Naissances\nDécès",
			'months'       => "Naissances\nDécès",
			'places'       => "Total",
			'occupations'  => "Part (%)",
		);
	}

	/* ------------------------------------------------------------------ Assets */

	public static function register_assets() {
		wp_register_style( 'tngstats', TNGSTATS_URL . 'assets/tngstats.css', array(), TNGSTATS_VERSION );
		$chart = tngs_register_chartjs();
		wp_register_script( 'tngstats-app', TNGSTATS_URL . 'assets/tngstats.js', array( $chart ), TNGSTATS_VERSION, true );
		tngs_js_i18n( 'tngstats-app' );
	}

	private static function enqueue() {
		if ( ! wp_script_is( 'tngstats-app', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'tngstats' );
		wp_enqueue_script( 'tngstats-app' );
	}

	/* ------------------------------------------------------------------ Rendu */

	public static function generic( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		$key  = isset( $atts['stat'] ) ? sanitize_key( $atts['stat'] ) : '';
		$all  = self::stats();
		if ( ! isset( $all[ $key ] ) ) {
			return current_user_can( 'manage_options' )
				? '<p class="tngs-error">' . esc_html( '[tng_stat] : ' . tngs_t( 'attribut stat invalide. Valeurs possibles :' ) . ' ' . implode( ', ', array_keys( $all ) ) . '.' ) . '</p>'
				: '';
		}
		unset( $atts['stat'] );
		return self::render( $key, $atts );
	}

	public static function render( $key, $atts ) {
		$all = self::stats();
		$def = $all[ $key ];
		$a   = shortcode_atts(
			array_merge( array( 'tree' => '', 'type' => '', 'height' => '380', 'title' => '', 'class' => '' ), $def['atts'] ),
			$atts,
			$def['alias']
		);
		$cust = TNGStats_Settings::chart( $key );

		// Type de graphique : attribut du shortcode > réglage du graphique > type par défaut.
		$type = $def['default'];
		foreach ( array( $cust['type'], $a['type'] ) as $wanted ) {
			foreach ( $def['types'] as $t ) {
				if ( '' !== trim( (string) $wanted ) && strtolower( $t ) === strtolower( trim( (string) $wanted ) ) ) {
					$type = $t;
				}
			}
		}

		$db = TNGStats_Settings::db();
		if ( is_wp_error( $db ) ) {
			return self::error( $db->get_error_message() );
		}
		$prefix = TNGStats_Settings::prefix();
		$mask   = (bool) tngs_hide_living( 'stats' );

		try {
			$tree = '' !== $a['tree'] ? $a['tree'] : TNGStats_Settings::default_tree();
			if ( '' === $tree ) {
				$tree = ( new TNGStats_Queries( $db, $prefix, '', $mask ) )->first_tree();
			}
			if ( '' === $tree ) {
				return self::error( 'Aucun arbre TNG trouvé.' );
			}

			$params = array_diff_key( $a, array( 'type' => 1, 'height' => 1, 'title' => 1, 'class' => 1 ) );
			$mins   = (int) TNGStats_Settings::get( 'cache_minutes' );
			$ckey   = 'tngstats_' . md5( wp_json_encode( array( $key, $params, $tree, $prefix, $mask, tngs_cache_ver(), tngs_lang(), TNGStats_Settings::get( 'occupations' ), TNGStats_Settings::get( 'occ_tags' ) ) ) );
			$data   = $mins > 0 ? get_transient( $ckey ) : false;
			if ( false === $data ) {
				$q    = new TNGStats_Queries( $db, $prefix, $tree, $mask );
				$data = self::compute( $q, $key, $a );
				if ( $mins > 0 ) {
					set_transient( $ckey, $data, $mins * MINUTE_IN_SECONDS );
				}
			}
		} catch ( Exception $e ) {
			error_log( '[TNG Suite / Stats] ' . $e->getMessage() );
			return self::error( current_user_can( 'manage_options' ) ? $e->getMessage() : '' );
		}

		// Textes : libellés personnalisés d'abord (sur les libellés d'origine), puis traduction du reste.
		$data = self::apply_labels( $data, TNGStats_Settings::label_map( $cust['labels'] ) );
		foreach ( array( 'xTitle', 'yTitle', 'note', 'first', 'unit' ) as $tk ) {
			if ( isset( $data[ $tk ] ) && is_string( $data[ $tk ] ) ) {
				$data[ $tk ] = tngs_t( $data[ $tk ] );
			}
		}
		if ( '' !== $cust['xTitle'] ) {
			$data['xTitle'] = $cust['xTitle'];
		}
		if ( '' !== $cust['yTitle'] ) {
			$data['yTitle'] = $cust['yTitle'];
		}
		if ( '' !== $cust['note'] ) {
			$data['note'] = 'none' === strtolower( $cust['note'] ) ? '' : $cust['note'];
		}
		$pal = TNGStats_Settings::palette_for( $key );
		if ( $pal ) {
			$data = self::apply_palette( $data, $pal, $type );
		}
		$data['legend'] = array( 'mode' => $cust['legend'], 'pos' => $cust['legend_pos'] );

		self::enqueue();

		$title  = '' !== $a['title'] ? $a['title'] : ( '' !== $cust['title'] ? $cust['title'] : tngs_t( $def['title'] ) );
		$config = array_merge( $data, array( 'stat' => $key, 'type' => $type ) );

		return sprintf(
			'<figure class="tngs %s" data-config="%s" data-height="%d">%s<div class="tngs-body"></div>%s</figure>',
			esc_attr( sanitize_html_class( $a['class'] ) ),
			esc_attr( wp_json_encode( $config ) ),
			max( 120, min( 1200, (int) $a['height'] ) ),
			'none' === strtolower( $title ) ? '' : '<figcaption class="tngs-title">' . esc_html( $title ) . '</figcaption>',
			! empty( $data['note'] ) ? '<p class="tngs-note">' . esc_html( $data['note'] ) . '</p>' : ''
		);
	}

	/** Remplace les libellés (catégories, séries, en-têtes et première colonne du tableau). */
	private static function apply_labels( array $data, array $map ) {
		$m = function ( $s ) use ( $map ) {
			if ( is_scalar( $s ) && isset( $map[ (string) $s ] ) ) {
				return $map[ (string) $s ]; // libellé choisi par l'utilisateur : jamais traduit.
			}
			// La règle peut aussi être écrite avec le libellé affiché dans la langue courante (ex. « Deceased = Yes »).
			if ( is_string( $s ) ) {
				$shown = tngs_t( $s );
				if ( $shown !== $s && isset( $map[ $shown ] ) ) {
					return $map[ $shown ];
				}
			}
			return is_string( $s ) ? tngs_t( $s ) : $s;
		};
		if ( isset( $data['labels'] ) ) {
			$data['labels'] = array_map( $m, $data['labels'] );
		}
		if ( isset( $data['datasets'] ) ) {
			foreach ( $data['datasets'] as $i => $ds ) {
				if ( isset( $ds['label'] ) ) {
					$data['datasets'][ $i ]['label'] = $m( $ds['label'] );
				}
			}
		}
		if ( isset( $data['table']['head'] ) ) {
			$data['table']['head'] = array_map( $m, $data['table']['head'] );
		}
		if ( isset( $data['table']['body'] ) ) {
			foreach ( $data['table']['body'] as $i => $row ) {
				if ( isset( $row[0] ) && is_string( $row[0] ) ) {
					$data['table']['body'][ $i ][0] = $m( $row[0] );
				}
			}
		}
		return $data;
	}

	/** Applique une palette : une couleur par série, et une par part pour les graphiques à parts. */
	private static function apply_palette( array $data, array $pal, $type ) {
		$n = count( $pal );
		if ( ! $n || empty( $data['datasets'] ) ) {
			return $data;
		}
		foreach ( $data['datasets'] as $i => $ds ) {
			$data['datasets'][ $i ]['color'] = $pal[ $i % $n ];
		}
		if ( in_array( $type, array( 'pie', 'doughnut', 'polarArea' ), true ) || ! empty( $data['colors'] ) ) {
			$cols = array();
			foreach ( array_keys( array_values( $data['labels'] ) ) as $k ) {
				$cols[] = $pal[ $k % $n ];
			}
			$data['colors'] = $cols;
		}
		return $data;
	}

	private static function compute( TNGStats_Queries $q, $key, array $a ) {
		switch ( $key ) {
			case 'centenarians':
				return $q->centenarians( $a['limit'], $a['min_age'], 'year' === strtolower( $a['precision'] ) ? 'year' : 'day', $a['person'] );
			case 'gender':
				return $q->gender();
			case 'living':
				return $q->living();
			case 'marriage_age':
				return $q->marriage_age( $a['min_n'] );
			case 'life_expectancy':
				return $q->life_expectancy( $a['basis'], $a['min_age'], $a['min_n'] );
			case 'pyramid':
				return $q->pyramid( $a['step'], $a['year'], $a['max_age'] );
			case 'distance':
				return $q->distance( $a['from'], $a['to'], $a['step'], 'birth' === strtolower( $a['basis'] ) ? 'birth' : 'death' );
			case 'half_century':
				return $q->half_century( $a['from'], $a['to'], $a['step'] );
			case 'months':
				return $q->months();
			case 'places':
				return $q->places( $a['limit'], $a['level'], $a['source'], (bool) (int) $a['others'] );
			case 'occupations':
				return $q->occupations( $a['limit'], (bool) (int) $a['others'] );
		}
		throw new InvalidArgumentException( 'Statistique inconnue.' );
	}

	private static function error( $message ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="tngs-error">' . esc_html( 'TNG Stats : ' . tngs_t( $message ) ) . '</p>';
		}
		return '<p class="tngs-error">Statistique momentanément indisponible.</p>';
	}
}

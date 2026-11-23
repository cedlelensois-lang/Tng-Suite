<?php
/**
 * Liste flash : shortcode public [tng_flash], rendu du tableau et exports CSV / TXT.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGFlash_Front {

	public static function init() {
		add_shortcode( 'tng_flash', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_post_tngflash_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_nopriv_tngflash_export', array( __CLASS__, 'export' ) );
	}

	public static function register_assets() {
		wp_register_style( 'tngflash', TNGFLASH_URL . 'assets/flash.css', array(), TNGSUITE_VERSION );
		wp_register_script( 'tngflash', TNGFLASH_URL . 'assets/flash.js', array(), TNGSUITE_VERSION, true );
	}

	/** Attributs du shortcode et de l'export, nettoyés. */
	public static function atts( $in ) {
		$o  = TNGFlash_Data::options();
		$in = shortcode_atts(
			array(
				'level'    => $o['level'],
				'min'      => $o['min'],
				'variants' => $o['variants'],
				'tree'     => '',
				'search'   => '1',
				'export'   => $o['export'],
				'limit'    => '50',
				'title'    => '',
			),
			(array) $in,
			'tng_flash'
		);
		$flag = function ( $v ) {
			return in_array( strtolower( (string) $v ), array( '0', 'no', 'non', 'false', 'off', '' ), true ) ? 0 : 1;
		};
		$in['variants'] = $flag( $in['variants'] );
		$in['search']   = $flag( $in['search'] );
		$in['export']   = $flag( $in['export'] );
		$in['limit']    = max( 10, min( 500, (int) $in['limit'] ) );
		$in['title']    = sanitize_text_field( (string) $in['title'] );
		return $in;
	}

	/** Paramètres de calcul d'après les attributs. */
	public static function calc( array $a ) {
		return TNGFlash_Data::params( array(
			'level'    => $a['level'],
			'min'      => $a['min'],
			'variants' => $a['variants'],
			'tree'     => $a['tree'],
		) );
	}

	private static function norm( $s ) {
		return mb_strtolower( remove_accents( (string) $s ) );
	}

	/** Texte d'un lieu avec ses dates : « Nord 1698-1850 ». */
	private static function place_text( array $p ) {
		$r = TNGFlash_Data::range( $p[2], $p[3] );
		return $p[0] . ( '' !== $r ? ' ' . str_replace( ' – ', '-', $r ) : '' );
	}

	/** Lieux affichés d'une ligne (les plus fréquents) et nombre de lieux non affichés. */
	public static function shown( array $r ) {
		$max = (int) TNGFlash_Data::options()['places_max'];
		return array( array_slice( $r['places'], 0, $max ), max( 0, count( $r['places'] ) - $max ) + (int) $r['more'] );
	}

	/** Tableau HTML de la liste (public et aperçu d'administration). */
	public static function table( array $rows, $limit = 0 ) {
		$ev  = array( 'B' => 'naissances', 'M' => 'mariages', 'D' => 'décès' );
		$out = '<table class="tngflash-table"><thead><tr>'
			. '<th data-sort="name" aria-sort="ascending" scope="col">' . esc_html( tngs_t( 'Patronyme' ) ) . '</th>'
			. '<th data-sort="n" scope="col">' . esc_html( tngs_t( 'Personnes' ) ) . '</th>'
			. '<th data-sort="min" scope="col">' . esc_html( tngs_t( 'Dates' ) ) . '</th>'
			. '<th scope="col">' . esc_html( tngs_t( 'Lieux' ) ) . '</th></tr></thead><tbody>';
		$i = 0;
		foreach ( $rows as $r ) {
			if ( $limit && ++$i > $limit ) {
				break;
			}
			$search = self::norm( $r['name'] . ' ' . implode( ' ', $r['variants'] ) );
			$pls    = array();
			foreach ( $r['places'] as $p ) {
				$pls[] = array( self::norm( $p[0] ), (int) $p[2], (int) $p[3] );
			}
			list( $shown, $more ) = self::shown( $r );
			$out .= '<tr data-name="' . esc_attr( $search ) . '" data-n="' . (int) $r['n'] . '" data-min="' . (int) $r['min'] . '" data-max="' . (int) $r['max']
				. '" data-pls="' . esc_attr( wp_json_encode( $pls ) ) . '">';
			$out .= '<th scope="row" class="tngflash-name"><strong>' . esc_html( $r['name'] ) . '</strong>';
			if ( $r['variants'] ) {
				$out .= ' <span class="tngflash-var">(' . esc_html( implode( ', ', $r['variants'] ) ) . ')</span>';
			}
			$out .= '</th><td class="tngflash-n">' . (int) $r['n'] . '</td>';
			$out .= '<td class="tngflash-dates">' . esc_html( TNGFlash_Data::range( $r['min'], $r['max'] ) ) . '</td><td class="tngflash-places">';
			foreach ( $shown as $p ) {
				$tip = array();
				foreach ( $ev as $k => $label ) {
					if ( $p[4][ $k ] ) {
						$tip[] = $p[4][ $k ] . ' ' . tngs_t( $label );
					}
				}
				$out .= '<span class="tngflash-pl" data-nm="' . esc_attr( self::norm( $p[0] ) ) . '" data-a="' . (int) $p[2] . '" data-b="' . (int) $p[3] . '" title="' . esc_attr( implode( ', ', $tip ) ) . '">' . esc_html( self::place_text( $p ) ) . ' <small>(' . (int) $p[1] . ')</small></span> ';
			}
			if ( $more > 0 ) {
				$out .= '<span class="tngflash-more">+' . (int) $more . '</span>';
			}
			$out .= '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	public static function shortcode( $atts ) {
		$a = self::atts( $atts );
		try {
			$rows = TNGFlash_Data::get( self::calc( $a ) );
		} catch ( Throwable $e ) {
			return current_user_can( 'manage_options' ) ? '<p class="tngflash-error">' . esc_html( tngs_t( 'Liste flash indisponible :' ) . ' ' . $e->getMessage() ) . '</p>' : '';
		}
		wp_enqueue_style( 'tngflash' );
		wp_enqueue_script( 'tngflash' );
		wp_localize_script( 'tngflash', 'TNGFLASH_T', array(
			'shown'   => tngs_t( 'patronymes affichés' ),
			'more'    => tngs_t( 'Afficher plus' ),
			'copied'  => tngs_t( 'Copié dans le presse-papiers.' ),
			'copyfail' => tngs_t( 'Copie impossible : sélectionnez le texte à la main.' ),
			'none'    => tngs_t( 'Aucun patronyme ne correspond.' ),
		) );

		$o = '<div class="tngflash" data-limit="' . (int) $a['limit'] . '">';
		if ( '' !== $a['title'] ) {
			$o .= '<h3 class="tngflash-title">' . esc_html( $a['title'] ) . '</h3>';
		}
		if ( $a['search'] ) {
			$o .= '<div class="tngflash-tools">'
				. '<label>' . esc_html( tngs_t( 'Patronyme' ) ) . ' <input type="search" class="tngflash-q" autocomplete="off"></label> '
				. '<label>' . esc_html( tngs_t( 'Lieu' ) ) . ' <input type="search" class="tngflash-pl-q" autocomplete="off"></label> '
				. '<label>' . esc_html( tngs_t( 'Période' ) ) . ' <input type="number" class="tngflash-from" min="0" max="2999" placeholder="1500"> – <input type="number" class="tngflash-to" min="0" max="2999" placeholder="1900"></label>'
				. '</div>';
		}
		$o .= '<p class="tngflash-count" aria-live="polite"></p>' . self::table( $rows );
		$o .= '<p class="tngflash-foot"><button type="button" class="tngflash-more-btn" hidden>' . esc_html( tngs_t( 'Afficher plus' ) ) . '</button></p>';
		if ( $a['export'] ) {
			$q = array(
				'action'   => 'tngflash_export',
				'level'    => $a['level'],
				'min'      => (int) $a['min'],
				'variants' => (int) $a['variants'],
				'tree'     => $a['tree'],
			);
			$o .= '<p class="tngflash-export">' . esc_html( tngs_t( 'Exporter :' ) )
				. ' <a href="' . esc_url( add_query_arg( array_merge( $q, array( 'format' => 'csv' ) ), admin_url( 'admin-post.php' ) ) ) . '">CSV</a> · '
				. '<a href="' . esc_url( add_query_arg( array_merge( $q, array( 'format' => 'txt' ) ), admin_url( 'admin-post.php' ) ) ) . '">TXT</a> · '
				. '<button type="button" class="tngflash-copy">' . esc_html( tngs_t( 'Copier pour un forum' ) ) . '</button></p>';
		}
		return $o . '</div>';
	}

	/* ------------------------------------------------------------ exports */

	/** Protège un champ CSV contre les formules de tableur. */
	private static function cell( $s ) {
		$s = (string) $s;
		return ( '' !== $s && false !== strpos( '=+-@', $s[0] ) ) ? "'" . $s : $s;
	}

	/** Ligne de texte : « NOM ; min ; max ; lieu (a-b), lieu (a-b) ». */
	public static function txt_line( array $r ) {
		$pl = array();
		list( $shown, $more ) = self::shown( $r );
		foreach ( $shown as $p ) {
			$pl[] = self::place_text( $p );
		}
		$name = $r['name'] . ( $r['variants'] ? ' (' . implode( ', ', $r['variants'] ) . ')' : '' );
		return $name . ' ; ' . ( $r['min'] ? $r['min'] : '' ) . ' ; ' . ( $r['max'] ? $r['max'] : '' ) . ' ; ' . implode( ', ', $pl ) . ( $more > 0 ? ' …' : '' );
	}

	public static function export() {
		$opt    = TNGFlash_Data::options();
		$a      = self::atts( array(
			'level'    => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : $opt['level'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- export public en lecture seule.
			'min'      => isset( $_GET['min'] ) ? (int) $_GET['min'] : $opt['min'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'variants' => isset( $_GET['variants'] ) ? (string) (int) $_GET['variants'] : (string) $opt['variants'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'tree'     => isset( $_GET['tree'] ) ? sanitize_text_field( wp_unslash( $_GET['tree'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) );
		$format = ( isset( $_GET['format'] ) && 'txt' === $_GET['format'] ) ? 'txt' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $opt['export'] && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( tngs_t( 'Export désactivé.' ) ), '', array( 'response' => 403 ) );
		}
		try {
			$rows = TNGFlash_Data::get( self::calc( $a ) );
		} catch ( Throwable $e ) {
			wp_die( esc_html( tngs_t( 'Liste flash indisponible.' ) ), '', array( 'response' => 503 ) );
		}
		nocache_headers();
		$fn = 'liste-flash-' . gmdate( 'Y-m-d' ) . '.' . $format;
		if ( 'txt' === $format ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $fn . '"' );
			echo "Liste flash – patronyme ; date min ; date max ; lieux (dates, nombre d'événements)\n";
			foreach ( $rows as $r ) {
				echo self::txt_line( $r ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- texte brut.
			}
			exit;
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fn . '"' );
		$h = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $h, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $h, array( 'Patronyme', 'Variantes', 'Personnes', 'Date min', 'Date max', 'Lieux' ), ';', '"', '' );
		foreach ( $rows as $r ) {
			$pl = array();
			list( $shown ) = self::shown( $r );
			foreach ( $shown as $p ) {
				$pl[] = self::place_text( $p ) . ' [' . (int) $p[1] . ']';
			}
			fputcsv( $h, array( self::cell( $r['name'] ), self::cell( implode( ', ', $r['variants'] ) ), (int) $r['n'], $r['min'] ? $r['min'] : '', $r['max'] ? $r['max'] : '', self::cell( implode( ' | ', $pl ) ) ), ';', '"', '' );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

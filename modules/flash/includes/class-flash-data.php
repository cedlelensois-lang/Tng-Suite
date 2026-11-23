<?php
/**
 * Liste flash : calcul des patronymes, dates et lieux (lecture seule sur les tables TNG).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGFlash_Data {

	const OPT = 'tngflash_options';

	/** Réglages du module. */
	public static function defaults() {
		return array(
			'level'      => 'departement', // commune | departement | region | pays
			'min'        => 2,             // patronymes ayant au moins N personnes
			'variants'   => 1,             // regrouper les variantes d'orthographe
			'places_max' => 12,            // lieux conservés par patronyme (les plus fréquents)
			'export'     => 1,             // boutons d'export dans la page publique
		);
	}

	public static function levels() {
		return array(
			'commune'     => 'Commune (1er élément du lieu)',
			'departement' => 'Département (2e élément du lieu)',
			'region'      => 'Région (3e élément du lieu)',
			'pays'        => 'Pays (dernier élément du lieu)',
		);
	}

	public static function clean( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = $d;
		$lv  = isset( $in['level'] ) ? (string) $in['level'] : $d['level'];
		$out['level']      = isset( self::levels()[ $lv ] ) ? $lv : $d['level'];
		$out['min']        = max( 1, min( 50, (int) ( isset( $in['min'] ) ? $in['min'] : $d['min'] ) ) );
		$out['variants']   = empty( $in['variants'] ) ? 0 : 1;
		$out['places_max'] = max( 1, min( 60, (int) ( isset( $in['places_max'] ) ? $in['places_max'] : $d['places_max'] ) ) );
		$out['export']     = empty( $in['export'] ) ? 0 : 1;
		return $out;
	}

	public static function options() {
		return self::clean( array_merge( self::defaults(), (array) get_option( self::OPT, array() ) ) );
	}

	public static function flush_cache() {
		update_option( 'tngflash_gen', (int) get_option( 'tngflash_gen', 1 ) + 1, false );
	}

	/**
	 * Clé de regroupement des variantes d'un patronyme : sans accents ni ponctuation, sons voisins fusionnés
	 * (ph/f, th/t, ck/cq/qu/c/k, y/i, d/t finaux), lettres doubles réduites, pluriels et consonnes muettes finales retirés.
	 */
	public static function name_key( $name ) {
		$s = strtolower( remove_accents( (string) $name ) );
		$s = preg_replace( '/[^a-z]+/', '', $s );
		if ( '' === $s ) {
			return '';
		}
		$s = str_replace( array( 'ph', 'th', 'ck', 'cq', 'qu', 'w' ), array( 'f', 't', 'k', 'k', 'k', 'v' ), $s );
		$s = str_replace( 'q', 'k', $s );
		$s = preg_replace( '/c(?![eiy])/', 'k', $s );
		$s = str_replace( 'y', 'i', $s );
		$s = preg_replace( '/(.)\1+/', '$1', $s );
		$s = preg_replace( '/d$/', 't', $s );
		$s = preg_replace( '/[stxz]+$/', '', $s );
		return '' === $s ? strtolower( remove_accents( (string) $name ) ) : $s;
	}

	/** Élément de lieu retenu pour le niveau demandé (codes postaux ignorés). */
	public static function place_part( $place, $level ) {
		$parts = array();
		foreach ( explode( ',', (string) $place ) as $p ) {
			$p = trim( $p );
			if ( '' !== $p && ! preg_match( '/^\d{4,5}$/', $p ) ) {
				$parts[] = $p;
			}
		}
		if ( ! $parts ) {
			return '';
		}
		$n = count( $parts );
		switch ( $level ) {
			case 'commune':
				return $parts[0];
			case 'region':
				return $parts[ min( 2, $n - 1 ) ];
			case 'pays':
				return $parts[ $n - 1 ];
			default:
				return $parts[ min( 1, $n - 1 ) ];
		}
	}

	private static function year_sql( $col ) {
		return 'CAST(SUBSTRING(' . $col . ',1,4) AS UNSIGNED)';
	}

	/** Exécute une requête ; tableau de lignes, ou lève une exception. */
	private static function rows( $db, $sql, array $args = array() ) {
		$q    = $args ? $db->prepare( $sql, $args ) : $sql;
		$rows = $db->get_results( $q, ARRAY_A );
		if ( null === $rows || '' !== (string) $db->last_error ) {
			throw new RuntimeException( 'Requête de la liste flash impossible.' );
		}
		return $rows;
	}

	/**
	 * Liste flash : tableau de lignes [ name, variants[], n, min, max, places[ [label, n, a, b, ev[B,M,D]] ], more ] triées par nom.
	 * Lève une RuntimeException si la base TNG est inaccessible ou inattendue.
	 *
	 * @param array $o level, min, variants, places_max, tree, hide.
	 */
	public static function build( array $o ) {
		$db = tngs_db();
		if ( ! $db ) {
			throw new RuntimeException( tngs_db_error_message() );
		}
		$pre   = tngs_prefix();
		$tp    = '`' . $pre . 'people`';
		$tf    = '`' . $pre . 'families`';
		$tree  = isset( $o['tree'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $o['tree'] ) : '';
		$hide  = ! empty( $o['hide'] );
		$level = $o['level'];

		$pw = ' WHERE p.lastname IS NOT NULL AND p.lastname <> \'\'';
		$fw = '';
		$ar = array();
		if ( '' !== $tree ) {
			$pw .= ' AND p.gedcom = %s';
			$fw .= ' AND f.gedcom = %s';
			$ar[] = $tree;
		}
		$pv = $hide ? ' AND p.living = 0 AND p.private = 0' : '';
		$fv = $hide ? ' AND f.living = 0 AND f.private = 0' : '';

		// Personnes par patronyme exact.
		$persons = self::rows( $db, "SELECT p.lastname AS ln, COUNT(*) AS n FROM $tp p" . $pw . $pv . ' GROUP BY p.lastname', $ar );

		// Événements : (patronyme, lieu) → nombre, année min, année max.
		$ev  = array();
		$agg = function ( $ycol, $pcol, $kind, $from, $where, $args, $lncol ) use ( $db, &$ev ) {
			$y    = self::year_sql( $ycol );
			$sql  = "SELECT $lncol AS ln, $pcol AS pl, COUNT(*) AS n, MIN(CASE WHEN $y > 0 THEN $y END) AS a, MAX($y) AS b FROM $from"
				. $where . " AND ($y > 0 OR ($pcol IS NOT NULL AND $pcol <> '')) GROUP BY $lncol, $pcol";
			foreach ( self::rows( $db, $sql, $args ) as $r ) {
				$ev[] = array( $r['ln'], (string) $r['pl'], $kind, (int) $r['n'], (int) $r['a'], (int) $r['b'] );
			}
		};
		$agg( 'p.birthdatetr', 'p.birthplace', 'B', "$tp p", $pw . $pv, $ar, 'p.lastname' );
		$agg( 'p.deathdatetr', 'p.deathplace', 'D', "$tp p", $pw . $pv, $ar, 'p.lastname' );
		foreach ( array( 'husband', 'wife' ) as $role ) {
			$agg( 'f.marrdatetr', 'f.marrplace', 'M', "$tf f JOIN $tp p ON p.personID = f.$role AND p.gedcom = f.gedcom", $pw . $pv . $fw . $fv, array_merge( $ar, $ar ), 'p.lastname' );
		}

		$groups = array();
		$gkey   = function ( $ln ) use ( $o ) {
			$ln = trim( (string) $ln );
			return ! empty( $o['variants'] ) ? self::name_key( $ln ) : mb_strtolower( $ln );
		};
		foreach ( $persons as $r ) {
			$ln = trim( (string) $r['ln'] );
			$k  = $gkey( $ln );
			if ( '' === $k ) {
				continue;
			}
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = array( 'names' => array(), 'n' => 0, 'min' => 0, 'max' => 0, 'pl' => array() );
			}
			$groups[ $k ]['names'][ $ln ] = ( isset( $groups[ $k ]['names'][ $ln ] ) ? $groups[ $k ]['names'][ $ln ] : 0 ) + (int) $r['n'];
			$groups[ $k ]['n']           += (int) $r['n'];
		}
		foreach ( $ev as $e ) {
			$k = $gkey( $e[0] );
			if ( ! isset( $groups[ $k ] ) ) {
				continue;
			}
			$g = &$groups[ $k ];
			if ( $e[4] > 0 ) {
				$g['min'] = ( 0 === $g['min'] ) ? $e[4] : min( $g['min'], $e[4] );
			}
			$g['max'] = max( $g['max'], $e[5] );
			$place    = self::place_part( $e[1], $level );
			if ( '' !== $place ) {
				$pk = mb_strtolower( remove_accents( $place ) );
				if ( ! isset( $g['pl'][ $pk ] ) ) {
					$g['pl'][ $pk ] = array( $place, 0, 0, 0, array( 'B' => 0, 'M' => 0, 'D' => 0 ) );
				}
				$p = &$g['pl'][ $pk ];
				$p[1]            += $e[3];
				$p[4][ $e[2] ]   += $e[3];
				if ( $e[4] > 0 ) {
					$p[2] = ( 0 === $p[2] ) ? $e[4] : min( $p[2], $e[4] );
				}
				$p[3] = max( $p[3], $e[5] );
				unset( $p );
			}
			unset( $g );
		}

		$min = max( 1, (int) $o['min'] );
		$max = max( 1, (int) $o['places_max'] );
		$out = array();
		foreach ( $groups as $g ) {
			if ( $g['n'] < $min ) {
				continue;
			}
			arsort( $g['names'] );
			$names = array_keys( $g['names'] );
			$label = array_shift( $names );
			uasort( $g['pl'], function ( $a, $b ) {
				return $b[1] <=> $a[1] ?: strcmp( $a[0], $b[0] );
			} );
			$pl   = array_values( $g['pl'] );
			$more = max( 0, count( $pl ) - $max );
			$out[] = array(
				'name'     => $label,
				'variants' => array_values( $names ),
				'n'        => (int) $g['n'],
				'min'      => (int) $g['min'],
				'max'      => (int) $g['max'],
				'places'   => array_slice( $pl, 0, $max ),
				'more'     => $more,
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( remove_accents( mb_strtolower( $a['name'] ) ), remove_accents( mb_strtolower( $b['name'] ) ) );
		} );
		return $out;
	}

	/** Liste en cache (une erreur n'est jamais mise en cache). */
	public static function get( array $o ) {
		$hours = max( 0, (int) tngs_setting( 'cache_hours' ) );
		$key   = 'tngflash_' . md5( wp_json_encode( array( $o, (int) get_option( 'tngflash_gen', 1 ), tngs_prefix(), tngs_cache_ver(), 'v1' ) ) );
		if ( $hours > 0 ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$rows = self::build( $o );
		if ( $hours > 0 ) {
			set_transient( $key, $rows, $hours * HOUR_IN_SECONDS );
		}
		return $rows;
	}

	/** Paramètres de calcul à partir des réglages et des attributs (déjà nettoyés). */
	public static function params( array $a = array() ) {
		$o   = self::options();
		$in  = array_merge( $o, array_intersect_key( $a, $o ) );
		$p   = self::clean( $in );
		$p['tree'] = isset( $a['tree'] ) && '' !== $a['tree'] ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $a['tree'] ) : tngs_gedcom();
		$p['hide'] = tngs_hide_living( 'flash' ) ? 1 : 0;
		return $p;
	}

	/** « 1698 – 1923 » ; vide si aucune date. */
	public static function range( $a, $b ) {
		if ( $a && $b && $a !== $b ) {
			return $a . ' – ' . $b;
		}
		return $a ? (string) $a : ( $b ? (string) $b : '' );
	}
}

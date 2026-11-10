<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coefficient de consanguinité F (Wright) de tous les individus d'un arbre TNG.
 *
 * Méthode : F(x) = coefficient de parenté φ(père, mère), avec la récurrence classique
 *   φ(a, a) = (1 + F(a)) / 2
 *   φ(a, b) = (φ(père(a), b) + φ(mère(a), b)) / 2   où a est le plus « jeune » des deux
 * Le résultat est exact (il tient compte de la consanguinité des ancêtres communs) et mémoïsé.
 * Les individus dont les parents n'ont aucun ancêtre commun sont écartés très vite (F = 0).
 *
 * Les résultats (F > 0 uniquement) sont stockés dans la base WordPress, jamais dans la base TNG.
 */
class TNGI_Inbreeding {

	const BUDGET     = 12.0;   // secondes par lot.
	const BATCH_MAX  = 4000;   // individus par lot au maximum.
	const MEMO_LIMIT = 1500000;
	const DB_VERSION = '1';

	private $db;
	private $tree;
	private $t   = array();
	private $par = array(); // personne => [père, mère] ('' si inconnu).
	private $d   = array(); // personne => profondeur (plus longue chaîne vers un fondateur).
	private $km  = array(); // mémo des coefficients de parenté.
	private $fm  = array(); // mémo des coefficients de consanguinité.

	public function __construct( $db, $prefix, $tree ) {
		$this->db   = $db;
		$this->tree = (string) $tree;
		foreach ( array( 'families', 'children' ) as $n ) {
			$this->t[ $n ] = '`' . $prefix . $n . '`';
		}
	}

	/* ------------------------------------------------------------------ Table de résultats */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tngi_inbreeding';
	}

	public static function ensure_table() {
		if ( get_option( 'tngi_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			  tree varchar(40) NOT NULL,
			  person_id varchar(40) NOT NULL,
			  f double NOT NULL,
			  depth smallint(5) unsigned NOT NULL DEFAULT 0,
			  common int(10) unsigned NOT NULL DEFAULT 0,
			  PRIMARY KEY  (tree,person_id),
			  KEY tree_f (tree,f)
			) {$charset};"
		);
		update_option( 'tngi_db_version', self::DB_VERSION );
	}

	/* ------------------------------------------------------------------ Calcul par lots */

	/**
	 * Traite un lot d'individus à partir de $offset.
	 *
	 * @return array rows, next, total, done
	 * @throws RuntimeException En cas d'erreur SQL.
	 */
	public function step( $offset ) {
		$start = microtime( true );
		$this->load();

		$ids = array();
		foreach ( $this->par as $x => $pp ) {
			$x = (string) $x;
			if ( '' !== $pp[0] && '' !== $pp[1] && $pp[0] !== $pp[1] && isset( $this->d[ $x ] ) ) {
				$ids[] = $x;
			}
		}
		sort( $ids, SORT_STRING );

		$total = count( $ids );
		$i     = max( 0, (int) $offset );
		$n     = 0;
		$rows  = array();

		while ( $i < $total && $n < self::BATCH_MAX && ( microtime( true ) - $start ) < self::BUDGET ) {
			$x = $ids[ $i ];
			$i++;
			$n++;

			if ( count( $this->km ) > self::MEMO_LIMIT ) {
				$this->km = array();
				$this->fm = array();
			}

			$f = $this->par[ $x ][0];
			$m = $this->par[ $x ][1];
			$a = $this->closed( $f );
			if ( ! $this->intersects( $a, $m ) ) {
				continue; // aucun ancêtre commun : F = 0.
			}
			$value = $this->f( $x );
			if ( $value > 1e-12 ) {
				$rows[] = array(
					'person_id' => $x,
					'f'         => $value,
					'depth'     => $this->d[ $x ],
					'common'    => count( array_intersect_key( $a, $this->closed( $m ) ) ),
				);
			}
		}

		return array(
			'rows'  => $rows,
			'next'  => $i,
			'total' => $total,
			'done'  => $i >= $total,
		);
	}

	/* ------------------------------------------------------------------ Chargement du pedigree */

	private function is_birth( $rel ) {
		$rel = strtolower( trim( (string) $rel ) );
		return '' === $rel || in_array( $rel, array( 'birth', 'natural', 'biological', 'naissance' ), true );
	}

	private function load() {
		$this->par = array();
		$this->d   = array();
		$this->km  = array();
		$this->fm  = array();

		$best   = array();
		$offset = 0;
		$chunk  = 20000;
		$prev   = $this->db->suppress_errors( true );
		try {
			do {
				$sql  = $this->db->prepare(
					"SELECT c.personID, c.frel, c.mrel, f.husband, f.wife
					 FROM {$this->t['children']} c
					 INNER JOIN {$this->t['families']} f ON f.gedcom = c.gedcom AND f.familyID = c.familyID
					 WHERE c.gedcom = %s ORDER BY c.personID, c.familyID LIMIT %d OFFSET %d",
					$this->tree,
					$chunk,
					$offset
				);
				$rows = $this->db->get_results( $sql, ARRAY_N );
				if ( $this->db->last_error ) {
					throw new RuntimeException( $this->db->last_error );
				}
				$rows = $rows ? $rows : array();
				foreach ( $rows as $r ) {
					$h = (string) $r[3];
					$w = (string) $r[4];
					if ( '' === $h && '' === $w ) {
						continue;
					}
					$score = ( $this->is_birth( $r[1] ) && $this->is_birth( $r[2] ) ) ? 0 : 1;
					$pid   = (string) $r[0];
					if ( ! isset( $best[ $pid ] ) || $score < $best[ $pid ][2] ) {
						$best[ $pid ] = array( $h, $w, $score );
					}
				}
				$count   = count( $rows );
				$offset += $chunk;
				unset( $rows );
				$this->db->flush();
			} while ( $count === $chunk );
		} finally {
			$this->db->suppress_errors( $prev );
		}

		// Profondeur de chaque personne (ordre topologique ; les cycles de données sont ignorés).
		$kids  = array();
		$indeg = array();
		$nodes = array();
		foreach ( $best as $pid => $b ) {
			$pid                = (string) $pid;
			$this->par[ $pid ]  = array( $b[0], $b[1] );
			$nodes[ $pid ]      = true;
			foreach ( array( $b[0], $b[1] ) as $q ) {
				if ( '' !== $q ) {
					$kids[ $q ][]    = $pid;
					$nodes[ $q ]     = true;
					$indeg[ $pid ]   = ( isset( $indeg[ $pid ] ) ? $indeg[ $pid ] : 0 ) + 1;
				}
			}
		}
		unset( $best );

		$dd    = array();
		$queue = array();
		foreach ( array_keys( $nodes ) as $p ) {
			$p = (string) $p;
			if ( empty( $indeg[ $p ] ) ) {
				$this->d[ $p ] = 0;
				$queue[]       = $p;
			}
		}
		for ( $qi = 0; $qi < count( $queue ); $qi++ ) {
			$p = $queue[ $qi ];
			if ( empty( $kids[ $p ] ) ) {
				continue;
			}
			foreach ( $kids[ $p ] as $c ) {
				$cand      = $this->d[ $p ] + 1;
				$dd[ $c ]  = isset( $dd[ $c ] ) ? max( $dd[ $c ], $cand ) : $cand;
				$indeg[ $c ]--;
				if ( 0 === $indeg[ $c ] ) {
					$this->d[ $c ] = $dd[ $c ];
					$queue[]       = (string) $c;
				}
			}
		}
	}

	/* ------------------------------------------------------------------ Ancêtres communs */

	/** Ensemble des ancêtres de $x, $x inclus. */
	private function closed( $x ) {
		$seen  = array( $x => true );
		$stack = array( $x );
		while ( $stack ) {
			$p = array_pop( $stack );
			if ( ! isset( $this->par[ $p ] ) ) {
				continue;
			}
			foreach ( $this->par[ $p ] as $q ) {
				if ( '' !== $q && ! isset( $seen[ $q ] ) ) {
					$seen[ $q ] = true;
					$stack[]    = $q;
				}
			}
		}
		return $seen;
	}

	/** Vrai si $m ou l'un de ses ancêtres appartient à l'ensemble $a. */
	private function intersects( array $a, $m ) {
		if ( isset( $a[ $m ] ) ) {
			return true;
		}
		$seen  = array( $m => true );
		$stack = array( $m );
		while ( $stack ) {
			$p = array_pop( $stack );
			if ( ! isset( $this->par[ $p ] ) ) {
				continue;
			}
			foreach ( $this->par[ $p ] as $q ) {
				if ( '' === $q || isset( $seen[ $q ] ) ) {
					continue;
				}
				if ( isset( $a[ $q ] ) ) {
					return true;
				}
				$seen[ $q ] = true;
				$stack[]    = $q;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ Coefficients */

	/** Coefficient de consanguinité de $x. */
	private function f( $x ) {
		if ( isset( $this->fm[ $x ] ) ) {
			return $this->fm[ $x ];
		}
		if ( ! isset( $this->par[ $x ] ) ) {
			return $this->fm[ $x ] = 0.0;
		}
		return $this->fm[ $x ] = $this->kin( $this->par[ $x ][0], $this->par[ $x ][1] );
	}

	/** Coefficient de parenté φ(a, b). */
	private function kin( $a, $b ) {
		if ( '' === $a || '' === $b || ! isset( $this->d[ $a ], $this->d[ $b ] ) ) {
			return 0.0;
		}
		if ( $a === $b ) {
			return ( 1 + $this->f( $a ) ) / 2;
		}
		$key = strcmp( $a, $b ) < 0 ? $a . "\t" . $b : $b . "\t" . $a;
		if ( isset( $this->km[ $key ] ) ) {
			return $this->km[ $key ];
		}
		// On développe le plus profond : l'autre ne peut pas être son descendant.
		if ( $this->d[ $a ] < $this->d[ $b ] ) {
			$tmp = $a;
			$a   = $b;
			$b   = $tmp;
		}
		if ( ! isset( $this->par[ $a ] ) ) {
			$v = 0.0;
		} else {
			$v = 0.5 * ( $this->kin( $this->par[ $a ][0], $b ) + $this->kin( $this->par[ $a ][1], $b ) );
		}
		return $this->km[ $key ] = $v;
	}
}

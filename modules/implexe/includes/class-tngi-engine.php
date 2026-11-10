<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculs d'ascendance à partir des tables TNG (people, families, children, trees).
 *
 * Principe : on remonte l'ascendance génération par génération en ne conservant, pour chaque
 * génération, la table « personne => nombre de chemins depuis le probant ». Cela évite
 * d'énumérer les 2^n cases théoriques : seules les personnes distinctes sont manipulées.
 */
class TNGI_Engine {

	const TOP_LIMIT     = 100;
	const FOUNDER_LIMIT = 100;
	const NETWORK_NODES = 600;

	private $db;
	private $tree;
	private $mask;
	private $t = array();

	/**
	 * @param wpdb   $db     Connexion à la base TNG.
	 * @param string $prefix Préfixe des tables (déjà assaini).
	 * @param string $tree   Identifiant de l'arbre (colonne gedcom).
	 * @param bool   $mask   Masquer les personnes vivantes / privées.
	 */
	public function __construct( $db, $prefix, $tree, $mask = true ) {
		$this->db   = $db;
		$this->tree = (string) $tree;
		$this->mask = (bool) $mask;
		foreach ( array( 'people', 'families', 'children', 'trees' ) as $n ) {
			$this->t[ $n ] = '`' . $prefix . $n . '`';
		}
	}

	/* ------------------------------------------------------------------ API publique */

	public function trees() {
		return $this->run(
			function () {
				$rows = $this->rows( "SELECT gedcom, treename FROM {$this->t['trees']} ORDER BY treename", array() );
				$out  = array();
				foreach ( $rows as $r ) {
					$out[] = array(
						'id'   => (string) $r['gedcom'],
						'name' => (string) ( '' !== (string) $r['treename'] ? $r['treename'] : $r['gedcom'] ),
					);
				}
				return $out;
			}
		);
	}

	public function search( $q ) {
		return $this->run(
			function () use ( $q ) {
				$like = '%' . $this->db->esc_like( $q ) . '%';
				$rows = $this->rows(
					"SELECT personID, firstname, lastname, birthdate, deathdate, living, private
					 FROM {$this->t['people']}
					 WHERE gedcom = %s AND ( lastname LIKE %s OR CONCAT_WS(' ', firstname, lastname) LIKE %s )
					 ORDER BY lastname, firstname LIMIT 60",
					array( $this->tree, $like, $like )
				);
				$out  = array();
				foreach ( $rows as $r ) {
					$i = $this->format( $r );
					if ( $i['masked'] ) {
						continue;
					}
					$out[] = array(
						'id'    => (string) $r['personID'],
						'name'  => $i['name'],
						'years' => $i['years'],
					);
					if ( count( $out ) >= 15 ) {
						break;
					}
				}
				return $out;
			}
		);
	}

	/**
	 * @throws InvalidArgumentException Si le probant n'existe pas.
	 * @throws RuntimeException         En cas d'erreur SQL.
	 */
	public function analyze( $root, $max_gen, $net_gen ) {
		return $this->run(
			function () use ( $root, $max_gen, $net_gen ) {
				return $this->do_analyze( (string) $root, (int) $max_gen, (int) $net_gen );
			}
		);
	}

	/**
	 * Identité et parents d'une liste de personnes (pour le classement de consanguinité).
	 *
	 * @return array id => [ person, father, mother ]
	 */
	public function describe( array $ids ) {
		return $this->run(
			function () use ( $ids ) {
				$ids    = array_values( array_unique( array_map( 'strval', $ids ) ) );
				$fam_of = $ids ? $this->load_parent_families( $ids ) : array();
				$fams   = $fam_of ? $this->load_families( array_values( array_unique( array_values( $fam_of ) ) ) ) : array();
				$all    = $ids;
				foreach ( $ids as $p ) {
					if ( isset( $fam_of[ $p ] ) && isset( $fams[ $fam_of[ $p ] ] ) ) {
						$all[] = $fams[ $fam_of[ $p ] ]['h'];
						$all[] = $fams[ $fam_of[ $p ] ]['w'];
					}
				}
				$info = $this->people_info( array_values( array_filter( $all, 'strlen' ) ) );
				$out  = array();
				foreach ( $ids as $p ) {
					$row = array( 'person' => $this->person_ref( $p, $info ), 'father' => null, 'mother' => null );
					if ( isset( $fam_of[ $p ] ) && isset( $fams[ $fam_of[ $p ] ] ) ) {
						$f = $fams[ $fam_of[ $p ] ];
						if ( '' !== $f['h'] ) {
							$row['father'] = $this->person_ref( $f['h'], $info );
						}
						if ( '' !== $f['w'] ) {
							$row['mother'] = $this->person_ref( $f['w'], $info );
						}
					}
					$out[ $p ] = $row;
				}
				return $out;
			}
		);
	}

	/* ------------------------------------------------------------------ Calcul */

	private function do_analyze( $root, $max_gen, $net_gen ) {
		$max_gen = max( 1, min( 40, $max_gen ) );
		$net_gen = max( 1, min( $max_gen, $net_gen ) );

		$root_info = $this->people_info( array( $root ) );
		if ( ! isset( $root_info[ $root ] ) ) {
			throw new InvalidArgumentException( 'Personne introuvable dans cet arbre.' );
		}

		$cur          = array( $root => 1 ); // personne => nombre de chemins vers le probant.
		$fam_of       = array();             // personne => famille dont elle est l'enfant (ou null).
		$fams         = array();             // famille => [h, w].
		$occ          = array();             // personne => occurrences totales (gén. >= 1).
		$contrib      = array();             // personne => part théorique de patrimoine (somme m / 2^g).
		$min_gen      = array( $root => 0 ); // personne => première génération d'apparition.
		$fam_count    = array();
		$fam_contrib  = array();
		$fam_gen      = array();
		$gens         = array();
		$cum_slots    = 0;

		for ( $g = 0; $g <= $max_gen; $g++ ) {
			// 1. Charger les familles d'origine des personnes de la génération courante.
			$need = array();
			foreach ( array_keys( $cur ) as $p ) {
				if ( ! array_key_exists( $p, $fam_of ) ) {
					$need[] = $p;
				}
			}
			if ( $need ) {
				$found = $this->load_parent_families( $need );
				$need_f = array();
				foreach ( $need as $p ) {
					$fam_of[ $p ] = isset( $found[ $p ] ) ? $found[ $p ] : null;
					if ( null !== $fam_of[ $p ] && ! isset( $fams[ $fam_of[ $p ] ] ) ) {
						$need_f[ $fam_of[ $p ] ] = true;
					}
				}
				if ( $need_f ) {
					$fams += $this->load_families( array_keys( $need_f ) );
				}
				// Lien enfant orphelin (famille absente ou sans parent) : on l'ignore.
				foreach ( $need as $p ) {
					if ( null !== $fam_of[ $p ] && ! isset( $fams[ $fam_of[ $p ] ] ) ) {
						$fam_of[ $p ] = null;
					}
				}
			}

			if ( $g === $max_gen ) {
				break;
			}

			// 2. Construire la génération suivante.
			$next = array();
			foreach ( $cur as $p => $m ) {
				$f = $fam_of[ $p ];
				if ( null === $f ) {
					continue;
				}
				$fam_count[ $f ]   = ( isset( $fam_count[ $f ] ) ? $fam_count[ $f ] : 0 ) + $m;
				$fam_contrib[ $f ] = ( isset( $fam_contrib[ $f ] ) ? $fam_contrib[ $f ] : 0 ) + $m / pow( 2, $g );
				if ( ! isset( $fam_gen[ $f ] ) || $g + 1 < $fam_gen[ $f ] ) {
					$fam_gen[ $f ] = $g + 1;
				}
				foreach ( array( $fams[ $f ]['h'], $fams[ $f ]['w'] ) as $par ) {
					if ( '' === $par ) {
						continue;
					}
					$next[ $par ] = ( isset( $next[ $par ] ) ? $next[ $par ] : 0 ) + $m;
				}
			}
			if ( ! $next ) {
				break;
			}

			$g1       = $g + 1;
			$slots    = array_sum( $next );
			$distinct = count( $next );
			foreach ( $next as $p => $m ) {
				$occ[ $p ]     = ( isset( $occ[ $p ] ) ? $occ[ $p ] : 0 ) + $m;
				$contrib[ $p ] = ( isset( $contrib[ $p ] ) ? $contrib[ $p ] : 0 ) + $m / pow( 2, $g1 );
				if ( ! isset( $min_gen[ $p ] ) ) {
					$min_gen[ $p ] = $g1;
				}
			}
			$cum_slots   += $slots;
			$cum_distinct = count( $min_gen ) - 1; // sans le probant.
			$theoretical  = pow( 2, $g1 );

			$gens[] = array(
				'gen'          => $g1,
				'theoretical'  => $theoretical,
				'slots'        => $slots,
				'distinct'     => $distinct,
				'completeness' => round( 100 * $slots / $theoretical, 2 ),
				'implexe'      => round( 100 * ( $slots - $distinct ) / $slots, 2 ),
				'cum_slots'    => $cum_slots,
				'cum_distinct' => $cum_distinct,
				'cum_implexe'  => round( 100 * ( $cum_slots - $cum_distinct ) / $cum_slots, 2 ),
			);
			$cur = $next;
		}

		/* ---- Top 100 des ancêtres les plus présents ---- */
		$ids = array_keys( $occ );
		usort(
			$ids,
			function ( $a, $b ) use ( $occ, $contrib, $min_gen ) {
				if ( $occ[ $a ] !== $occ[ $b ] ) {
					return $occ[ $b ] <=> $occ[ $a ];
				}
				if ( $contrib[ $a ] != $contrib[ $b ] ) {
					return $contrib[ $b ] <=> $contrib[ $a ];
				}
				return $min_gen[ $a ] <=> $min_gen[ $b ];
			}
		);
		$top_ids = array_slice( $ids, 0, self::TOP_LIMIT );

		$implexe_ancestors = 0;
		foreach ( $occ as $c ) {
			if ( $c > 1 ) {
				$implexe_ancestors++;
			}
		}

		/* ---- Couples fondateurs : couples d'ancêtres dont aucun des deux n'a de parents connus ---- */
		$founders = array();
		foreach ( $fam_count as $f => $cnt ) {
			$h = $fams[ $f ]['h'];
			$w = $fams[ $f ]['w'];
			if ( '' === $h || '' === $w ) {
				continue;
			}
			$h_parents = isset( $fam_of[ $h ] ) ? $fam_of[ $h ] : null;
			$w_parents = isset( $fam_of[ $w ] ) ? $fam_of[ $w ] : null;
			if ( null !== $h_parents || null !== $w_parents ) {
				continue;
			}
			$founders[] = $f;
		}
		usort(
			$founders,
			function ( $a, $b ) use ( $fam_count, $fam_gen ) {
				if ( $fam_count[ $a ] !== $fam_count[ $b ] ) {
					return $fam_count[ $b ] <=> $fam_count[ $a ];
				}
				return $fam_gen[ $a ] <=> $fam_gen[ $b ];
			}
		);
		$founders_total = count( $founders );
		$founder_fams   = array_slice( $founders, 0, self::FOUNDER_LIMIT );

		/* ---- Réseau ---- */
		$cand = array();
		foreach ( $min_gen as $p => $gg ) {
			if ( $gg <= $net_gen ) {
				$cand[] = $p;
			}
		}
		$net_total = count( $cand );
		usort(
			$cand,
			function ( $a, $b ) use ( $min_gen, $occ ) {
				$c = $min_gen[ $a ] <=> $min_gen[ $b ];
				if ( 0 !== $c ) {
					return $c;
				}
				return ( isset( $occ[ $b ] ) ? $occ[ $b ] : 0 ) <=> ( isset( $occ[ $a ] ) ? $occ[ $a ] : 0 );
			}
		);
		$cand   = array_slice( $cand, 0, self::NETWORK_NODES );
		$in_net = array_flip( $cand );
		$edges  = array();
		$unions = array();
		foreach ( $cand as $p ) {
			$f = isset( $fam_of[ $p ] ) ? $fam_of[ $p ] : null;
			if ( null === $f ) {
				continue;
			}
			$h   = $fams[ $f ]['h'];
			$w   = $fams[ $f ]['w'];
			$hin = ( '' !== $h && isset( $in_net[ $h ] ) );
			$win = ( '' !== $w && isset( $in_net[ $w ] ) );
			if ( $hin ) {
				$edges[] = array( 'from' => (string) $p, 'to' => (string) $h, 'type' => 'child' );
			}
			if ( $win ) {
				$edges[] = array( 'from' => (string) $p, 'to' => (string) $w, 'type' => 'child' );
			}
			if ( $hin && $win ) {
				$unions[ $f ] = array( (string) $h, (string) $w );
			}
		}
		foreach ( $unions as $u ) {
			$edges[] = array( 'from' => $u[0], 'to' => $u[1], 'type' => 'union' );
		}

		/* ---- Libellés ---- */
		$need_names = array_merge( array( $root ), $top_ids, $cand );
		foreach ( $founder_fams as $f ) {
			$need_names[] = $fams[ $f ]['h'];
			$need_names[] = $fams[ $f ]['w'];
		}
		$info = $this->people_info( $need_names );

		$top = array();
		foreach ( $top_ids as $i => $p ) {
			$top[] = array_merge(
				$this->person_ref( $p, $info ),
				array(
					'rank'      => $i + 1,
					'occ'       => $occ[ $p ],
					'contrib'   => round( 100 * $contrib[ $p ], 3 ),
					'first_gen' => $min_gen[ $p ],
				)
			);
		}

		$founder_out = array();
		foreach ( $founder_fams as $f ) {
			$founder_out[] = array(
				'family'  => (string) $f,
				'husband' => $this->person_ref( $fams[ $f ]['h'], $info ),
				'wife'    => $this->person_ref( $fams[ $f ]['w'], $info ),
				'gen'     => $fam_gen[ $f ],
				'occ'     => $fam_count[ $f ],
				'contrib' => round( 100 * $fam_contrib[ $f ], 3 ),
			);
		}

		$nodes = array();
		foreach ( $cand as $p ) {
			$ref     = $this->person_ref( $p, $info );
			$nodes[] = array(
				'id'       => $ref['id'],
				'name'     => $ref['name'],
				'years'    => $ref['years'],
				'gen'      => $min_gen[ $p ],
				'occ'      => isset( $occ[ $p ] ) ? $occ[ $p ] : 1,
				'founder'  => ( null === ( isset( $fam_of[ $p ] ) ? $fam_of[ $p ] : null ) ),
				'implexe'  => ( isset( $occ[ $p ] ) && $occ[ $p ] > 1 ),
			);
		}

		$last = $gens ? $gens[ count( $gens ) - 1 ] : null;

		return array(
			'root'        => $this->person_ref( $root, $info ),
			'params'      => array(
				'tree'        => $this->tree,
				'generations' => $max_gen,
				'net_gen'     => $net_gen,
			),
			'summary'     => array(
				'depth'             => count( $gens ),
				'cum_slots'         => $last ? $last['cum_slots'] : 0,
				'cum_distinct'      => $last ? $last['cum_distinct'] : 0,
				'cum_implexe'       => $last ? $last['cum_implexe'] : 0,
				'implexe_ancestors' => $implexe_ancestors,
				'founders_total'    => $founders_total,
			),
			'generations' => $gens,
			'top'         => $top,
			'founders'    => $founder_out,
			'network'     => array(
				'nodes'     => $nodes,
				'edges'     => $edges,
				'truncated' => $net_total > count( $cand ),
				'total'     => $net_total,
			),
		);
	}

	/* ------------------------------------------------------------------ Accès base */

	private function run( $fn ) {
		$prev = $this->db->suppress_errors( true );
		try {
			return $fn();
		} finally {
			$this->db->suppress_errors( $prev );
		}
	}

	private function rows( $sql, array $args ) {
		$query = $args ? $this->db->prepare( $sql, $args ) : $sql;
		$res   = $this->db->get_results( $query, ARRAY_A );
		if ( $this->db->last_error ) {
			throw new RuntimeException( $this->db->last_error );
		}
		return $res ? $res : array();
	}

	private function placeholders( array $chunk ) {
		return implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
	}

	private function is_birth( $rel ) {
		$rel = strtolower( trim( (string) $rel ) );
		return '' === $rel || in_array( $rel, array( 'birth', 'natural', 'biological', 'naissance' ), true );
	}

	/**
	 * personne => famille d'origine (lien biologique prioritaire, adoption/accueil en repli).
	 */
	private function load_parent_families( array $ids ) {
		$best = array();
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$chunk = array_map( 'strval', $chunk );
			$rows  = $this->rows(
				"SELECT personID, familyID, frel, mrel FROM {$this->t['children']}
				 WHERE gedcom = %s AND personID IN (" . $this->placeholders( $chunk ) . ')',
				array_merge( array( $this->tree ), $chunk )
			);
			foreach ( $rows as $r ) {
				$score = ( $this->is_birth( $r['frel'] ) && $this->is_birth( $r['mrel'] ) ) ? 0 : 1;
				$pid   = $r['personID'];
				if ( ! isset( $best[ $pid ] ) || $score < $best[ $pid ][1] ) {
					$best[ $pid ] = array( (string) $r['familyID'], $score );
				}
			}
		}
		$out = array();
		foreach ( $best as $pid => $b ) {
			$out[ $pid ] = $b[0];
		}
		return $out;
	}

	/**
	 * famille => ['h' => mari, 'w' => épouse] (familles sans aucun parent ignorées).
	 */
	private function load_families( array $ids ) {
		$out = array();
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$chunk = array_map( 'strval', $chunk );
			$rows  = $this->rows(
				"SELECT familyID, husband, wife FROM {$this->t['families']}
				 WHERE gedcom = %s AND familyID IN (" . $this->placeholders( $chunk ) . ')',
				array_merge( array( $this->tree ), $chunk )
			);
			foreach ( $rows as $r ) {
				$h = (string) $r['husband'];
				$w = (string) $r['wife'];
				if ( '' === $h && '' === $w ) {
					continue;
				}
				$out[ $r['familyID'] ] = array( 'h' => $h, 'w' => $w );
			}
		}
		return $out;
	}

	/**
	 * personne => ['name', 'years', 'masked'].
	 */
	private function people_info( array $ids ) {
		$ids  = array_values( array_unique( array_map( 'strval', $ids ) ) );
		$info = array();
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$args = array_merge( array( $this->tree ), $chunk );
			$ph   = $this->placeholders( $chunk );
			try {
				$rows = $this->rows(
					"SELECT personID, firstname, lastname, lnprefix, birthdate, deathdate, living, private
					 FROM {$this->t['people']} WHERE gedcom = %s AND personID IN ($ph)",
					$args
				);
			} catch ( RuntimeException $e ) {
				// Anciennes versions de TNG sans colonne lnprefix.
				$rows = $this->rows(
					"SELECT personID, firstname, lastname, birthdate, deathdate, living, private
					 FROM {$this->t['people']} WHERE gedcom = %s AND personID IN ($ph)",
					$args
				);
			}
			foreach ( $rows as $r ) {
				$info[ $r['personID'] ] = $this->format( $r );
			}
		}
		return $info;
	}

	private function format( array $r ) {
		if ( $this->mask && ( ! empty( $r['living'] ) || ! empty( $r['private'] ) ) ) {
			return array( 'name' => 'Personne vivante ou privée', 'years' => '', 'masked' => true );
		}
		$ln = trim( ( isset( $r['lnprefix'] ) ? $r['lnprefix'] : '' ) . ' ' . ( isset( $r['lastname'] ) ? $r['lastname'] : '' ) );
		$ln = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $ln, 'UTF-8' ) : strtoupper( $ln );
		$name = trim( ( isset( $r['firstname'] ) ? $r['firstname'] : '' ) . ' ' . $ln );
		if ( '' === $name ) {
			$name = '(sans nom)';
		}
		$b = preg_match( '/\d{4}/', (string) ( isset( $r['birthdate'] ) ? $r['birthdate'] : '' ), $mb ) ? $mb[0] : '';
		$d = preg_match( '/\d{4}/', (string) ( isset( $r['deathdate'] ) ? $r['deathdate'] : '' ), $md ) ? $md[0] : '';
		$years = ( '' !== $b || '' !== $d ) ? $b . '–' . $d : '';
		return array( 'name' => $name, 'years' => $years, 'masked' => false );
	}

	private function person_ref( $id, array $info ) {
		$id = (string) $id;
		if ( isset( $info[ $id ] ) ) {
			return array(
				'id'    => $id,
				'name'  => $info[ $id ]['name'],
				'years' => $info[ $id ]['years'],
			);
		}
		return array( 'id' => $id, 'name' => '[' . $id . '] introuvable', 'years' => '' );
	}
}

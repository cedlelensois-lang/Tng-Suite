<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requêtes statistiques sur les tables TNG.
 *
 * Chaque méthode publique renvoie la même structure :
 *   labels, datasets[ {label, data, color} ], colors (par point, optionnel), unit, xTitle, yTitle,
 *   table {head, body}, note, total.
 *
 * Les personnes vivantes sont incluses : seuls des agrégats sont publiés (hors liste des centenaires,
 * qui ne concerne que des personnes décédées).
 */
class TNGStats_Queries {

	private $db;
	private $tree;
	private $mask;
	private $t = array();
	private $has_prefix = null;

	public function __construct( $db, $prefix, $tree, $mask_private = true ) {
		$this->db   = $db;
		$this->tree = (string) $tree;
		$this->mask = (bool) $mask_private;
		foreach ( array( 'people', 'families', 'children', 'events', 'eventtypes', 'places', 'trees' ) as $n ) {
			$this->t[ $n ] = '`' . $prefix . $n . '`';
		}
	}

	/* ------------------------------------------------------------------ Infrastructure */

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

	private function ph( array $list ) {
		return implode( ',', array_fill( 0, count( $list ), '%s' ) );
	}

	private function pack( array $labels, array $datasets, array $opt = array() ) {
		if ( isset( $opt['table'] ) ) {
			$table = $opt['table'];
		} else {
			$head = array( isset( $opt['first'] ) ? $opt['first'] : '' );
			foreach ( $datasets as $d ) {
				$head[] = $d['label'];
			}
			$body = array();
			foreach ( array_values( $labels ) as $i => $l ) {
				$row = array( $l );
				foreach ( $datasets as $d ) {
					$row[] = isset( $d['data'][ $i ] ) ? $d['data'][ $i ] : null;
				}
				$body[] = $row;
			}
			$table = array( 'head' => $head, 'body' => $body );
		}
		return array(
			'labels'   => array_values( $labels ),
			'datasets' => $datasets,
			'colors'   => isset( $opt['colors'] ) ? $opt['colors'] : null,
			'unit'     => isset( $opt['unit'] ) ? $opt['unit'] : '',
			'xTitle'   => isset( $opt['xTitle'] ) ? $opt['xTitle'] : '',
			'yTitle'   => isset( $opt['yTitle'] ) ? $opt['yTitle'] : '',
			'table'    => $table,
			'note'     => isset( $opt['note'] ) ? $opt['note'] : '',
			'total'    => isset( $opt['total'] ) ? $opt['total'] : null,
		);
	}

	private function roman( $n ) {
		$map = array( 'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1 );
		$r   = '';
		foreach ( $map as $s => $v ) {
			while ( $n >= $v ) {
				$r .= $s;
				$n -= $v;
			}
		}
		return $r . 'e';
	}

	private function name( array $r ) {
		if ( $this->mask && ! empty( $r['private'] ) ) {
			return 'Personne privée';
		}
		$ln = trim( ( isset( $r['lnprefix'] ) ? $r['lnprefix'] : '' ) . ' ' . ( isset( $r['lastname'] ) ? $r['lastname'] : '' ) );
		$ln = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $ln, 'UTF-8' ) : strtoupper( $ln );
		$n  = trim( ( isset( $r['firstname'] ) ? $r['firstname'] : '' ) . ' ' . $ln );
		return '' !== $n ? $n : '(sans nom)';
	}

	private function lnprefix_col() {
		if ( null === $this->has_prefix ) {
			$r                = $this->db->get_results( "SHOW COLUMNS FROM {$this->t['people']} LIKE 'lnprefix'" );
			$this->has_prefix = ! empty( $r );
		}
		return $this->has_prefix ? 'lnprefix' : "'' AS lnprefix";
	}

	/* ------------------------------------------------------------------ Arbres */

	public function trees() {
		return $this->run(
			function () {
				$out = array();
				foreach ( $this->rows( "SELECT gedcom, treename FROM {$this->t['trees']} ORDER BY treename", array() ) as $r ) {
					$out[] = array( 'id' => (string) $r['gedcom'], 'name' => '' !== (string) $r['treename'] ? (string) $r['treename'] : (string) $r['gedcom'] );
				}
				return $out;
			}
		);
	}

	public function first_tree() {
		$t = $this->trees();
		return $t ? $t[0]['id'] : '';
	}

	/* ------------------------------------------------------------------ 1. Genre */

	public function gender() {
		return $this->run(
			function () {
				$rows = $this->rows( "SELECT sex, COUNT(*) AS n FROM {$this->t['people']} WHERE gedcom = %s GROUP BY sex", array( $this->tree ) );
				$c    = array( 'M' => 0, 'F' => 0, 'U' => 0 );
				foreach ( $rows as $r ) {
					$s = strtoupper( trim( (string) $r['sex'] ) );
					$k = ( 'M' === $s || 'F' === $s ) ? $s : 'U';
					$c[ $k ] += (int) $r['n'];
				}
				$labels = array( 'Hommes', 'Femmes', 'Inconnus' );
				$data   = array( $c['M'], $c['F'], $c['U'] );
				$total  = array_sum( $data );
				$body   = array();
				foreach ( $labels as $i => $l ) {
					$body[] = array( $l, $data[ $i ], $total ? number_format( 100 * $data[ $i ] / $total, 1, ',', ' ' ) . ' %' : '—' );
				}
				return $this->pack(
					$labels,
					array( array( 'label' => 'Individus', 'data' => $data, 'color' => '#3b82f6' ) ),
					array(
						'colors' => array( '#3b82f6', '#ec4899', '#9ca3af' ),
						'total'  => $total,
						'table'  => array( 'head' => array( 'Genre', 'Individus', 'Part' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ Vivants / décédés */

	/**
	 * Répartition décédés / vivants de l'arbre (drapeau « living » de TNG). Seuls des totaux sont publiés.
	 */
	public function living() {
		return $this->run(
			function () {
				$rows  = $this->rows( "SELECT living, COUNT(*) AS n FROM {$this->t['people']} WHERE gedcom = %s GROUP BY living", array( $this->tree ) );
				$dead  = 0;
				$alive = 0;
				foreach ( $rows as $r ) {
					if ( 1 === (int) $r['living'] ) {
						$alive += (int) $r['n'];
					} else {
						$dead += (int) $r['n'];
					}
				}
				$labels = array( 'Décédés', 'Vivants' );
				$data   = array( $dead, $alive );
				$total  = array_sum( $data );
				$body   = array();
				foreach ( $labels as $i => $l ) {
					$body[] = array( $l, $data[ $i ], $total ? number_format( 100 * $data[ $i ] / $total, 1, ',', ' ' ) . ' %' : '—' );
				}
				return $this->pack(
					$labels,
					array( array( 'label' => 'Individus', 'data' => $data, 'color' => '#6b6569' ) ),
					array(
						'colors' => array( '#6b6569', '#bde4f2' ),
						'total'  => $total,
						'table'  => array( 'head' => array( 'Statut', 'Individus', 'Part' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 2. Centenaires */

	private function parents_of( array $ids ) {
		$best = array();
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$rows = $this->rows(
				"SELECT personID, familyID, frel, mrel FROM {$this->t['children']} WHERE gedcom = %s AND personID IN (" . $this->ph( $chunk ) . ')',
				array_merge( array( $this->tree ), $chunk )
			);
			foreach ( $rows as $r ) {
				$bio = ( '' === trim( (string) $r['frel'] ) || 'birth' === strtolower( trim( (string) $r['frel'] ) ) )
					&& ( '' === trim( (string) $r['mrel'] ) || 'birth' === strtolower( trim( (string) $r['mrel'] ) ) );
				$pid = (string) $r['personID'];
				if ( ! isset( $best[ $pid ] ) || ( $bio && ! $best[ $pid ][1] ) ) {
					$best[ $pid ] = array( (string) $r['familyID'], $bio );
				}
			}
		}
		$fam_ids = array();
		foreach ( $best as $b ) {
			$fam_ids[ $b[0] ] = true;
		}
		$fams = array();
		foreach ( array_chunk( array_map( 'strval', array_keys( $fam_ids ) ), 400 ) as $chunk ) {
			$rows = $this->rows(
				"SELECT familyID, husband, wife FROM {$this->t['families']} WHERE gedcom = %s AND familyID IN (" . $this->ph( $chunk ) . ')',
				array_merge( array( $this->tree ), $chunk )
			);
			foreach ( $rows as $r ) {
				$fams[ (string) $r['familyID'] ] = array( (string) $r['husband'], (string) $r['wife'] );
			}
		}
		$out = array();
		foreach ( $best as $pid => $b ) {
			$pids = array();
			if ( isset( $fams[ $b[0] ] ) ) {
				foreach ( $fams[ $b[0] ] as $q ) {
					if ( '' !== $q ) {
						$pids[] = $q;
					}
				}
			}
			$out[ (string) $pid ] = $pids;
		}
		return $out;
	}

	/** Ascendants d'une personne (distincts, probant exclu). */
	private function ancestors( $root, $max_gen = 40 ) {
		$root = (string) $root;
		$seen = array( $root => true );
		$cur  = array( $root );
		for ( $g = 0; $g < $max_gen && $cur; $g++ ) {
			$next = array();
			foreach ( $this->parents_of( $cur ) as $parents ) {
				foreach ( $parents as $q ) {
					if ( ! isset( $seen[ $q ] ) ) {
						$seen[ $q ] = true;
						$next[]     = (string) $q;
					}
				}
			}
			$cur = $next;
		}
		unset( $seen[ $root ] );
		return array_map( 'strval', array_keys( $seen ) );
	}

	public function centenarians( $limit, $min_age, $precision, $person ) {
		return $this->run(
			function () use ( $limit, $min_age, $precision, $person ) {
				$limit   = max( 1, min( 50, (int) $limit ) );
				$min_age = max( 1, (int) $min_age );
				$exact   = '(MONTH(birthdatetr) > 0 AND DAY(birthdatetr) > 0 AND MONTH(deathdatetr) > 0 AND DAY(deathdatetr) > 0)';
				$age     = "(YEAR(deathdatetr) - YEAR(birthdatetr) - (CASE WHEN {$exact} AND (MONTH(deathdatetr) * 100 + DAY(deathdatetr)) < (MONTH(birthdatetr) * 100 + DAY(birthdatetr)) THEN 1 ELSE 0 END))";
				// Dates approximatives (ABT, BEF, AFT, EST, CAL, BET, FROM, TO, « vers », « avant », « après »…) exclues.
				$rx      = "(^|[^a-zA-Z])(abt|bef|aft|est|cal|bet|from|to|int|vers|avant|apr.s|environ|entre)([^a-zA-Z]|$)";
				$pref    = $this->lnprefix_col();
				$only    = 'day' === $precision ? ' AND is_exact = 1' : '';

				$base = "SELECT personID, firstname, lastname, {$pref}, birthdate, deathdate, private,
					{$age} AS age, IF({$exact}, 1, 0) AS is_exact
					FROM {$this->t['people']}
					WHERE gedcom = %s AND living = 0 AND YEAR(birthdatetr) > 0 AND YEAR(deathdatetr) > 0
					AND birthdate NOT REGEXP '{$rx}' AND deathdate NOT REGEXP '{$rx}'";

				$found = array();
				$fetch = function ( $extra_sql, array $extra_args ) use ( $base, $min_age, $only, $limit ) {
					return $this->rows(
						"SELECT * FROM ({$base}{$extra_sql}) t WHERE age BETWEEN %d AND 125{$only} ORDER BY age DESC, is_exact DESC, personID LIMIT %d",
						array_merge( array( $this->tree ), $extra_args, array( $min_age, $limit ) )
					);
				};

				if ( '' !== trim( (string) $person ) ) {
					$ids = $this->ancestors( trim( (string) $person ) );
					foreach ( array_chunk( $ids, 500 ) as $chunk ) {
						$found = array_merge( $found, $fetch( ' AND personID IN (' . $this->ph( $chunk ) . ')', $chunk ) );
					}
					usort(
						$found,
						function ( $a, $b ) {
							return ( (int) $b['age'] <=> (int) $a['age'] ) ?: ( (int) $b['is_exact'] <=> (int) $a['is_exact'] );
						}
					);
					$found = array_slice( $found, 0, $limit );
				} else {
					$found = $fetch( '', array() );
				}

				$labels = array();
				$ages   = array();
				$body   = array();
				foreach ( $found as $i => $r ) {
					$nm       = $this->name( $r );
					$labels[] = $nm;
					$ages[]   = (int) $r['age'];
					$body[]   = array( $i + 1, $nm, (string) $r['birthdate'], (string) $r['deathdate'], ( $r['is_exact'] ? '' : '≈ ' ) . (int) $r['age'] . ' ans' );
				}
				return $this->pack(
					$labels,
					array( array( 'label' => 'Âge au décès', 'data' => $ages, 'color' => '#7c3aed' ) ),
					array(
						'unit'   => 'ans',
						'yTitle' => 'Âge au décès (ans)',
						'note'   => 'day' === $precision
							? 'Seules les personnes décédées dont les dates de naissance et de décès sont complètes (jour, mois, année) et non approximatives sont retenues.'
							: 'Les âges « ≈ » sont calculés à l\'année près (dates incomplètes).',
						'table'  => array( 'head' => array( '#', 'Nom', 'Naissance', 'Décès', 'Âge' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 3. Âge moyen à la première union */

	public function marriage_age( $min_n ) {
		return $this->run(
			function () use ( $min_n ) {
				$min_n = max( 1, (int) $min_n );
				$f     = $this->t['families'];
				$sql   = "SELECT CEIL(YEAR(fu.m) / 100) AS century, p.sex AS sex, AVG(YEAR(fu.m) - YEAR(p.birthdatetr)) AS avg_age, COUNT(*) AS n
					FROM (
						SELECT pid, MIN(m) AS m FROM (
							SELECT husband AS pid, marrdatetr AS m FROM {$f} WHERE gedcom = %s AND husband <> '' AND YEAR(marrdatetr) > 0
							UNION ALL
							SELECT wife AS pid, marrdatetr AS m FROM {$f} WHERE gedcom = %s AND wife <> '' AND YEAR(marrdatetr) > 0
						) x GROUP BY pid
					) fu
					INNER JOIN {$this->t['people']} p ON p.gedcom = %s AND p.personID = fu.pid
					WHERE YEAR(p.birthdatetr) > 0 AND (YEAR(fu.m) - YEAR(p.birthdatetr)) BETWEEN 12 AND 80 AND p.sex IN ('M', 'F')
					GROUP BY century, p.sex ORDER BY century";
				$rows  = $this->rows( $sql, array( $this->tree, $this->tree, $this->tree ) );

				$by = array();
				foreach ( $rows as $r ) {
					if ( (int) $r['n'] >= $min_n ) {
						$by[ (int) $r['century'] ][ strtoupper( $r['sex'] ) ] = array( round( (float) $r['avg_age'], 1 ), (int) $r['n'] );
					}
				}
				ksort( $by );
				$labels = array();
				$men    = array();
				$women  = array();
				$body   = array();
				foreach ( $by as $c => $v ) {
					$labels[] = $this->roman( $c ) . ' ' . tngs_t( 'siècle' );
					$men[]    = isset( $v['M'] ) ? $v['M'][0] : null;
					$women[]  = isset( $v['F'] ) ? $v['F'][0] : null;
					$body[]   = array(
						$this->roman( $c ) . ' ' . tngs_t( 'siècle' ),
						isset( $v['M'] ) ? $v['M'][0] : null,
						isset( $v['M'] ) ? $v['M'][1] : null,
						isset( $v['F'] ) ? $v['F'][0] : null,
						isset( $v['F'] ) ? $v['F'][1] : null,
					);
				}
				return $this->pack(
					$labels,
					array(
						array( 'label' => 'Hommes', 'data' => $men, 'color' => '#1d4ed8' ),
						array( 'label' => 'Femmes', 'data' => $women, 'color' => '#f472b6' ),
					),
					array(
						'unit'   => 'ans',
						'xTitle' => 'Siècle du mariage',
						'yTitle' => 'Âge moyen (ans)',
						'note'   => tngs_t( 'Âge calculé à l\'année près, à la première union connue. Les siècles avec moins de' ) . ' ' . $min_n . ' ' . tngs_t( 'cas par sexe sont ignorés.' ),
						'table'  => array( 'head' => array( 'Siècle', 'Hommes (âge)', 'Hommes (n)', 'Femmes (âge)', 'Femmes (n)' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ Espérance de vie et pyramide des âges */

	/** Espérance de vie : âge moyen au décès par siècle (naissance et décès connus, à l'année près). */
	public function life_expectancy( $basis, $min_age, $min_n ) {
		return $this->run(
			function () use ( $basis, $min_age, $min_n ) {
				$by_death = 'death' === strtolower( (string) $basis );
				$min_age  = max( 0, min( 100, (int) $min_age ) );
				$min_n    = max( 1, (int) $min_n );
				$col      = $by_death ? 'deathdatetr' : 'birthdatetr';
				$sql      = "SELECT CEIL(YEAR({$col}) / 100) AS century, sex, AVG(YEAR(deathdatetr) - YEAR(birthdatetr)) AS avg_age, COUNT(*) AS n
					FROM {$this->t['people']}
					WHERE gedcom = %s AND YEAR(birthdatetr) > 0 AND YEAR(deathdatetr) > 0
					AND (YEAR(deathdatetr) - YEAR(birthdatetr)) BETWEEN %d AND 110 AND sex IN ('M', 'F')
					GROUP BY century, sex ORDER BY century";
				$rows     = $this->rows( $sql, array( $this->tree, $min_age ) );
				$by       = array();
				foreach ( $rows as $r ) {
					$by[ (int) $r['century'] ][ strtoupper( $r['sex'] ) ] = array( (float) $r['avg_age'], (int) $r['n'] );
				}
				ksort( $by );
				$labels = array();
				$men    = array();
				$women  = array();
				$all    = array();
				$body   = array();
				foreach ( $by as $c => $v ) {
					$nm = isset( $v['M'] ) ? $v['M'][1] : 0;
					$nf = isset( $v['F'] ) ? $v['F'][1] : 0;
					if ( $nm + $nf < $min_n ) {
						continue;
					}
					$m   = $nm >= $min_n ? round( $v['M'][0], 1 ) : null;
					$f   = $nf >= $min_n ? round( $v['F'][0], 1 ) : null;
					$tot = round( ( ( $nm ? $v['M'][0] * $nm : 0 ) + ( $nf ? $v['F'][0] * $nf : 0 ) ) / ( $nm + $nf ), 1 );
					$lab = $this->roman( $c ) . ' ' . tngs_t( 'siècle' );
					$labels[] = $lab;
					$men[]    = $m;
					$women[]  = $f;
					$all[]    = $tot;
					$body[]   = array( $lab, $m, $nm, $f, $nf, $tot, $nm + $nf );
				}
				return $this->pack(
					$labels,
					array(
						array( 'label' => 'Hommes', 'data' => $men, 'color' => '#1d4ed8' ),
						array( 'label' => 'Femmes', 'data' => $women, 'color' => '#f472b6' ),
						array( 'label' => 'Ensemble', 'data' => $all, 'color' => '#444444' ),
					),
					array(
						'unit'   => 'ans',
						'xTitle' => $by_death ? 'Siècle du décès' : 'Siècle de naissance',
						'yTitle' => 'Âge moyen au décès (ans)',
						'note'   => tngs_t( 'Âge au décès à l\'année près, personnes dont la naissance et le décès sont connus' ) . ( $min_age > 0 ? ' (' . tngs_t( 'décédés à' ) . ' ' . $min_age . ' ' . tngs_t( 'ans ou plus' ) . ')' : '' ) . '. ' . tngs_t( 'Les siècles avec moins de' ) . ' ' . $min_n . ' ' . tngs_t( 'cas sont ignorés.' ),
						'table'  => array( 'head' => array( 'Siècle', 'Hommes (âge)', 'Hommes (n)', 'Femmes (âge)', 'Femmes (n)', 'Ensemble (âge)', 'Ensemble (n)' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/** Pyramide des âges : âges au décès, ou âges des personnes vivantes une année donnée. Hommes et femmes par tranche. */
	public function pyramid( $step, $year, $max_age ) {
		return $this->run(
			function () use ( $step, $year, $max_age ) {
				$step    = in_array( (int) $step, array( 1, 2, 5, 10, 20 ), true ) ? (int) $step : 10;
				$max_age = max( $step, min( 120, (int) $max_age ) );
				$year    = (int) $year;
				if ( $year > 0 ) {
					$sql  = "SELECT sex, ( %d - YEAR(birthdatetr) ) AS a, COUNT(*) AS n FROM {$this->t['people']}
						WHERE gedcom = %s AND YEAR(birthdatetr) > 0 AND YEAR(birthdatetr) <= %d AND YEAR(deathdatetr) > %d AND sex IN ('M', 'F')
						GROUP BY sex, a";
					$rows = $this->rows( $sql, array( $year, $this->tree, $year, $year ) );
				} else {
					$sql  = "SELECT sex, ( YEAR(deathdatetr) - YEAR(birthdatetr) ) AS a, COUNT(*) AS n FROM {$this->t['people']}
						WHERE gedcom = %s AND YEAR(birthdatetr) > 0 AND YEAR(deathdatetr) > 0 AND (YEAR(deathdatetr) - YEAR(birthdatetr)) BETWEEN 0 AND 120 AND sex IN ('M', 'F')
						GROUP BY sex, a";
					$rows = $this->rows( $sql, array( $this->tree ) );
				}
				$bands = (int) ceil( ( $max_age + 1 ) / $step );
				$m     = array_fill( 0, $bands, 0 );
				$f     = array_fill( 0, $bands, 0 );
				foreach ( $rows as $r ) {
					$a = (int) $r['a'];
					if ( $a < 0 || $a > 120 ) {
						continue;
					}
					$i = min( $bands - 1, (int) floor( $a / $step ) );   // la dernière tranche regroupe les âges plus élevés
					if ( 'M' === strtoupper( $r['sex'] ) ) {
						$m[ $i ] += (int) $r['n'];
					} else {
						$f[ $i ] += (int) $r['n'];
					}
				}
				$labels = array();
				$body   = array();
				for ( $i = 0; $i < $bands; $i++ ) {
					$lo = $i * $step;
					$lab = ( $i === $bands - 1 ) ? $lo . '+' : ( 1 === $step ? (string) $lo : $lo . '-' . ( $lo + $step - 1 ) );
					$labels[] = $lab;
					$body[]   = array( $lab, $m[ $i ], $f[ $i ] );
				}
				return $this->pack(
					$labels,
					array(
						array( 'label' => 'Hommes', 'data' => $m, 'color' => '#1d4ed8' ),
						array( 'label' => 'Femmes', 'data' => $f, 'color' => '#f472b6' ),
					),
					array(
						'unit'   => $year > 0 ? 'personnes' : 'décès',
						'xTitle' => $year > 0 ? tngs_t( 'Personnes vivantes en' ) . ' ' . $year : 'Nombre de décès',
						'first'  => 'Âge',
						'note'   => $year > 0 ? tngs_t( 'Personnes dont la naissance et le décès sont connus, vivantes l\'année indiquée (âge atteint cette année-là).' ) : tngs_t( 'Âge au décès à l\'année près, personnes dont la naissance et le décès sont connus.' ),
						'table'  => array( 'head' => array( 'Âge', 'Hommes', 'Femmes' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 4. Distance naissance → décès */

	public function distance( $from, $to, $step, $basis ) {
		return $this->run(
			function () use ( $from, $to, $step, $basis ) {
				$from = max( 1000, (int) $from );
				$to   = max( $from + 1, (int) $to );
				$step = max( 5, min( 200, (int) $step ) );
				$n    = (int) ceil( ( $to - $from + 1 ) / $step );
				$ycol = 'birth' === $basis ? 'p.birthdatetr' : 'p.deathdatetr';

				$bl   = 'CAST(b.latitude AS DECIMAL(10,6))';
				$bo   = 'CAST(b.longitude AS DECIMAL(10,6))';
				$dl   = 'CAST(d.latitude AS DECIMAL(10,6))';
				$do   = 'CAST(d.longitude AS DECIMAL(10,6))';
				$dist = "6371 * 2 * ASIN(LEAST(1, SQRT(POW(SIN(RADIANS({$dl} - {$bl}) / 2), 2) + COS(RADIANS({$bl})) * COS(RADIANS({$dl})) * POW(SIN(RADIANS({$do} - {$bo}) / 2), 2))))";

				$sql  = "SELECT bucket, AVG(dist) AS avg_km, COUNT(*) AS n FROM (
						SELECT LEAST(%d, FLOOR((YEAR({$ycol}) - %d) / %d)) AS bucket, {$dist} AS dist
						FROM {$this->t['people']} p
						INNER JOIN {$this->t['places']} b ON b.gedcom = p.gedcom AND b.place = p.birthplace
						INNER JOIN {$this->t['places']} d ON d.gedcom = p.gedcom AND d.place = p.deathplace
						WHERE p.gedcom = %s AND p.birthplace <> '' AND p.deathplace <> '' AND YEAR({$ycol}) >= %d
						AND b.latitude <> '' AND b.longitude <> '' AND d.latitude <> '' AND d.longitude <> ''
					) t GROUP BY bucket ORDER BY bucket";
				$rows = $this->rows( $sql, array( $n - 1, $from, $step, $this->tree, $from ) );

				$avg = array();
				$cnt = array();
				foreach ( $rows as $r ) {
					$avg[ (int) $r['bucket'] ] = round( (float) $r['avg_km'], 1 );
					$cnt[ (int) $r['bucket'] ] = (int) $r['n'];
				}
				$labels = array();
				$data   = array();
				$body   = array();
				for ( $i = 0; $i < $n; $i++ ) {
					$a        = $from + $i * $step;
					$b        = min( $to, $a + $step - 1 );
					$labels[] = $a . '-' . $b;
					$data[]   = isset( $avg[ $i ] ) ? $avg[ $i ] : null;
					$body[]   = array( $a . '-' . $b, isset( $avg[ $i ] ) ? $avg[ $i ] : null, isset( $cnt[ $i ] ) ? $cnt[ $i ] : 0 );
				}
				return $this->pack(
					$labels,
					array( array( 'label' => 'Distance moyenne (km)', 'data' => $data, 'color' => '#e6a817' ) ),
					array(
						'unit'   => 'km',
						'yTitle' => 'km',
						'note'   => tngs_t( 'Distance à vol d\'oiseau entre lieu de naissance et lieu de décès, pour les lieux géolocalisés dans TNG, par période de' ) . ' ' . tngs_t( 'birth' === $basis ? 'naissance' : 'décès' ) . '.',
						'table'  => array( 'head' => array( 'Période', 'Distance moyenne (km)', 'Individus' ), 'body' => $body ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 5. Naissances et décès par demi-siècle */

	private function bucket_counts( $col, $step ) {
		$rows = $this->rows(
			"SELECT FLOOR(YEAR({$col}) / %d) * %d AS y, COUNT(*) AS n FROM {$this->t['people']}
			 WHERE gedcom = %s AND YEAR({$col}) BETWEEN 1000 AND 2200 GROUP BY y",
			array( $step, $step, $this->tree )
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['y'] ] = (int) $r['n'];
		}
		return $out;
	}

	public function half_century( $from, $to, $step ) {
		return $this->run(
			function () use ( $from, $to, $step ) {
				$step   = max( 5, min( 100, (int) $step ) );
				$from   = (int) ( floor( max( 1000, (int) $from ) / $step ) * $step );
				$to     = max( $from, (int) $to );
				$births = $this->bucket_counts( 'birthdatetr', $step );
				$deaths = $this->bucket_counts( 'deathdatetr', $step );
				$labels = array();
				$b      = array();
				$d      = array();
				for ( $y = $from; $y <= $to; $y += $step ) {
					$labels[] = (string) $y;
					$b[]      = isset( $births[ $y ] ) ? $births[ $y ] : 0;
					$d[]      = isset( $deaths[ $y ] ) ? $deaths[ $y ] : 0;
				}
				return $this->pack(
					$labels,
					array(
						array( 'label' => 'Naissances', 'data' => $b, 'color' => '#33ccff' ),
						array( 'label' => 'Décès', 'data' => $d, 'color' => '#333333' ),
					),
					array(
						'xTitle' => 'Date',
						'first'  => 'Période (début)',
						'note'   => 'Personnes avec une année connue, vivants inclus pour les naissances.',
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 6. Naissances et décès par mois */

	private function month_counts( $col ) {
		$rows = $this->rows(
			"SELECT MONTH({$col}) AS m, COUNT(*) AS n FROM {$this->t['people']}
			 WHERE gedcom = %s AND YEAR({$col}) > 0 AND MONTH({$col}) BETWEEN 1 AND 12 GROUP BY m",
			array( $this->tree )
		);
		$out = array_fill( 1, 12, 0 );
		foreach ( $rows as $r ) {
			$out[ (int) $r['m'] ] = (int) $r['n'];
		}
		return array_values( $out );
	}

	public function months() {
		return $this->run(
			function () {
				return $this->pack(
					array( 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre' ),
					array(
						array( 'label' => 'Naissances', 'data' => $this->month_counts( 'birthdatetr' ), 'color' => '#33ccff' ),
						array( 'label' => 'Décès', 'data' => $this->month_counts( 'deathdatetr' ), 'color' => '#111827' ),
					),
					array(
						'xTitle' => 'Mois',
						'yTitle' => 'Nombre',
						'first'  => 'Mois',
						'note'   => 'Seules les dates comportant un mois sont comptées.',
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 7. Principales villes */

	public function places( $limit, $level, $sources, $others ) {
		return $this->run(
			function () use ( $limit, $level, $sources, $others ) {
				$limit  = max( 3, min( 50, (int) $limit ) );
				$level  = max( 1, min( 6, (int) $level ) );
				// Événements comptés : naissance, baptême, mariage, décès, sépulture (« all » = les cinq).
				$alias  = array(
					'baptism' => 'baptism', 'baptisme' => 'baptism', 'bapteme' => 'baptism', 'christening' => 'baptism', 'bapt' => 'baptism',
					'burial'  => 'burial', 'sepulture' => 'burial', 'inhumation' => 'burial',
					'birth'   => 'birth', 'naissance' => 'birth', 'death' => 'death', 'deces' => 'death', 'marriage' => 'marriage', 'mariage' => 'marriage',
				);
				$source = array();
				foreach ( explode( ',', strtolower( (string) $sources ) ) as $s ) {
					$s = trim( $s );
					if ( 'all' === $s || 'tous' === $s ) {
						$source = array( 'birth', 'baptism', 'marriage', 'death', 'burial' );
						break;
					}
					if ( isset( $alias[ $s ] ) ) {
						$source[] = $alias[ $s ];
					}
				}
				$source = array_values( array_unique( $source ) );
				$sqls   = array();
				foreach ( array(
					'birth'    => array( 'people', 'birthplace' ),
					'baptism'  => array( 'people', 'altbirthplace' ), // baptême / christening dans TNG.
					'marriage' => array( 'families', 'marrplace' ),
					'death'    => array( 'people', 'deathplace' ),
					'burial'   => array( 'people', 'burialplace' ),
				) as $ev => $def ) {
					if ( in_array( $ev, $source, true ) ) {
						$sqls[] = "SELECT {$def[1]} AS place, COUNT(*) AS n FROM {$this->t[ $def[0] ]} WHERE gedcom = %s AND {$def[1]} <> '' GROUP BY {$def[1]}";
					}
				}
				if ( ! $sqls ) {
					return $this->pack( array(), array() );
				}

				$agg   = array(); // clé minuscule => [ libellé, total ].
				$total = 0;
				foreach ( $sqls as $sql ) {
					foreach ( $this->rows( $sql, array( $this->tree ) ) as $r ) {
						$parts = explode( ',', (string) $r['place'] );
						$name  = isset( $parts[ $level - 1 ] ) ? trim( $parts[ $level - 1 ] ) : '';
						if ( '' === $name ) {
							continue;
						}
						$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
						if ( ! isset( $agg[ $k ] ) ) {
							$agg[ $k ] = array( $name, 0 );
						}
						$agg[ $k ][1] += (int) $r['n'];
						$total        += (int) $r['n'];
					}
				}
				uasort(
					$agg,
					function ( $a, $b ) {
						return $b[1] <=> $a[1];
					}
				);
				$top    = array_slice( array_values( $agg ), 0, $limit );
				$shown  = 0;
				$labels = array();
				$data   = array();
				foreach ( $top as $t ) {
					$labels[] = $t[0];
					$data[]   = $t[1];
					$shown   += $t[1];
				}
				if ( $others && $total > $shown ) {
					$labels[] = 'Autres';
					$data[]   = $total - $shown;
				}
				return $this->pack(
					$labels,
					array( array( 'label' => 'Total', 'data' => $data, 'color' => '#86efac' ) ),
					array(
						'xTitle' => 'Total',
						'first'  => 'Lieu',
						'total'  => $total,
						'note'   => tngs_t( 'Événements comptés' ) . ' (' . implode( ', ', array_map( function ( $s ) {
							$names = array( 'birth' => 'naissances', 'baptism' => 'baptêmes', 'marriage' => 'mariages', 'death' => 'décès', 'burial' => 'sépultures' );
							return tngs_t( $names[ $s ] );
						}, $source ) ) . '), ' . tngs_t( 'regroupés par commune.' ),
					)
				);
			}
		);
	}

	/* ------------------------------------------------------------------ 8. Métiers */

	private function occupation_rows() {
		$tags = array_values( array_filter( array_map( 'trim', explode( ',', (string) TNGStats_Settings::get( 'occ_tags' ) ) ) ) );
		if ( ! $tags ) {
			$tags = array( 'OCCU' );
		}
		return $this->rows(
			"SELECT e.info AS occ, COUNT(DISTINCT e.persfamID) AS n
			 FROM {$this->t['events']} e
			 INNER JOIN {$this->t['eventtypes']} t ON t.eventtypeID = e.eventtypeID
			 WHERE e.gedcom = %s AND t.type = 'I' AND t.tag IN (" . $this->ph( $tags ) . ") AND e.info <> ''
			 GROUP BY e.info",
			array_merge( array( $this->tree ), $tags )
		);
	}

	/** Intitulés les plus fréquents ne correspondant à aucune catégorie. */
	public function unclassified( $limit = 40 ) {
		return $this->run(
			function () use ( $limit ) {
				$cats = TNGStats_Settings::categories();
				$out  = array();
				foreach ( $this->occupation_rows() as $r ) {
					$txt = TNGStats_Settings::norm( $r['occ'] );
					$hit = false;
					foreach ( $cats as $c ) {
						if ( preg_match( $c[1], $txt ) ) {
							$hit = true;
							break;
						}
					}
					if ( ! $hit ) {
						$out[] = array( (string) $r['occ'], (int) $r['n'] );
					}
				}
				usort(
					$out,
					function ( $a, $b ) {
						return $b[1] <=> $a[1];
					}
				);
				return array_slice( $out, 0, $limit );
			}
		);
	}

	public function occupations( $limit, $others ) {
		return $this->run(
			function () use ( $limit, $others ) {
				$limit  = max( 3, min( 40, (int) $limit ) );
				$cats   = TNGStats_Settings::categories();
				$counts = array();
				$other  = 0;
				foreach ( $this->occupation_rows() as $r ) {
					$txt = TNGStats_Settings::norm( $r['occ'] );
					$hit = false;
					foreach ( $cats as $c ) {
						if ( preg_match( $c[1], $txt ) ) {
							$counts[ $c[0] ] = ( isset( $counts[ $c[0] ] ) ? $counts[ $c[0] ] : 0 ) + (int) $r['n'];
							$hit             = true;
							break;
						}
					}
					if ( ! $hit ) {
						$other += (int) $r['n'];
					}
				}
				arsort( $counts );
				$matched = array_sum( $counts );
				$total   = $matched + ( $others ? $other : 0 );
				$counts  = array_slice( $counts, 0, $limit, true );
				if ( $others && $other > 0 ) {
					$counts['Autres (non classés)'] = $other;
				}
				$labels = array_keys( $counts );
				$data   = array();
				$body   = array();
				foreach ( $counts as $l => $n ) {
					$p      = $total ? round( 100 * $n / $total, 1 ) : 0;
					$data[] = $p;
					$body[] = array( $l, number_format( $p, 1, ',', ' ' ) . ' %', $n );
				}
				return $this->pack(
					$labels,
					array( array( 'label' => 'Part (%)', 'data' => $data, 'color' => '#4e79a7' ) ),
					array(
						'unit'   => '%',
						'yTitle' => '%',
						'total'  => $total,
						'note'   => tngs_t( 'Mentions de professions classées par catégorie' ) . ( $others ? '' : ' (' . number_format_i18n( $other ) . ' ' . tngs_t( 'mentions non classées exclues' ) . ')' ) . '.',
						'table'  => array( 'head' => array( 'Catégorie', 'Part', 'Mentions' ), 'body' => $body ),
					)
				);
			}
		);
	}
}

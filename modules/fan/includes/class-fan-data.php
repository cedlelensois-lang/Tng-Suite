<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lecture des données TNG pour l'éventail : ascendance (numérotation de Sosa par générations),
 * descendance (par générations, avec l'index du parent) et fiches abrégées des personnes.
 */
class TNGFan_Data {

	/** Ascendance : 12 générations complètes = 8 191 ancêtres. Descendance : plafond séparé. */
	const ANC_CAP  = 8400;
	const DESC_CAP = 3000;

	private $db;
	private $p;
	private $tree;
	private $hide;
	private $noParentOrder = false;
	private $hasPrefix = null;
	public $truncated = false;
	public $truncatedAnc  = false;
	public $truncatedDesc = false;

	public function __construct( $db, $prefix, $tree, $hide ) {
		$this->db   = $db;
		$this->p    = $prefix;
		$this->tree = $tree;
		$this->hide = (bool) $hide;
	}

	private function t( $name ) {
		return '`' . $this->p . $name . '`';
	}

	/** @return array[] lignes associatives */
	private function rows( $sql, array $args ) {
		$q    = $args ? $this->db->prepare( $sql, $args ) : $sql;
		$rows = $this->db->get_results( $q, ARRAY_A );
		if ( null === $rows || '' !== $this->db->last_error ) {
			error_log( '[TNG Suite / Fan] ' . $this->db->last_error );
			throw new Exception( tngs_t( 'Erreur de lecture de la base TNG.' ) );
		}
		return $rows;
	}

	/** Liste de marqueurs %s pour une clause IN (les identifiants sont passés à prepare()). */
	private function in( array $ids ) {
		return implode( ',', array_fill( 0, max( 1, count( $ids ) ), '%s' ) );
	}

	/** Identifiants (chaînes) pour accompagner in(). */
	private function idv( array $ids ) {
		$ids = array_map( 'strval', array_values( $ids ) );
		return $ids ? $ids : array( '' );
	}

	/** Arbre utilisé : celui demandé, sinon celui qui contient la personne, sinon le premier. */
	public static function resolve_tree( $db, $prefix, $tree, $person ) {
		if ( '' !== $tree ) {
			return $tree;
		}
		$t = $db->get_var( $db->prepare( "SELECT gedcom FROM `{$prefix}people` WHERE personID = %s LIMIT 1", $person ) );
		if ( $t ) {
			return (string) $t;
		}
		$t = $db->get_var( "SELECT gedcom FROM `{$prefix}trees` ORDER BY treename LIMIT 1" );
		return $t ? (string) $t : '';
	}

	/** Père et mère de chaque enfant : [ enfant => [ père|null, mère|null ] ]. */
	private function parents( array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		$out  = array();
		$base = "SELECT c.personID AS child, f.husband, f.wife FROM {$this->t('children')} c INNER JOIN {$this->t('families')} f ON f.familyID = c.familyID AND f.gedcom = c.gedcom WHERE c.gedcom = %s AND c.personID IN (" . $this->in( $ids ) . ')';
		try {
			if ( $this->noParentOrder ) {
				throw new Exception( 'sans parentorder' );
			}
			$rows = $this->rows( $base . ' ORDER BY c.personID, c.parentorder', array_merge( array( $this->tree ), $this->idv( $ids ) ) );
		} catch ( Exception $e ) {
			$this->noParentOrder = true;
			$rows                = $this->rows( $base . ' ORDER BY c.personID', array_merge( array( $this->tree ), $this->idv( $ids ) ) );
		}
		foreach ( $rows as $r ) {
			if ( ! isset( $out[ $r['child'] ] ) ) {      // la première famille (naturelle) l'emporte
				$out[ $r['child'] ] = array(
					'' !== (string) $r['husband'] ? (string) $r['husband'] : null,
					'' !== (string) $r['wife'] ? (string) $r['wife'] : null,
				);
			}
		}
		return $out;
	}

	/** Enfants de chaque parent, dans l'ordre des unions puis des naissances : [ parent => [ [enfant, famille] ] ]. */
	private function children( array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		$in   = $this->in( $ids );
		$idv  = $this->idv( $ids );
		$rows = $this->rows(
			"SELECT f.familyID, f.husband, f.wife, c.personID AS child FROM {$this->t('families')} f INNER JOIN {$this->t('children')} c ON c.familyID = f.familyID AND c.gedcom = f.gedcom WHERE f.gedcom = %s AND (f.husband IN ($in) OR f.wife IN ($in)) ORDER BY f.marrdatetr, f.familyID, c.ordernum",
			array_merge( array( $this->tree ), $idv, $idv )
		);
		$out  = array();
		$seen = array();
		foreach ( $rows as $r ) {
			foreach ( array( $r['husband'], $r['wife'] ) as $par ) {
				$par = (string) $par;
				if ( '' !== $par && in_array( $par, $ids, true ) && ! isset( $seen[ $par . '|' . $r['child'] ] ) ) {
					$seen[ $par . '|' . $r['child'] ] = true;
					$out[ $par ][]                    = array( (string) $r['child'], (string) $r['familyID'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Unions de chaque personne, dans le même ordre que children() :
	 * [ personne => [ [ famille, conjoint ou '', nombre d'enfants ] ] ].
	 */
	private function unions( array $ids ) {
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 400 ) as $chunk ) {
			$in   = $this->in( $chunk );
			$rows = $this->rows(
				"SELECT f.familyID, f.husband, f.wife, f.marrdate, f.marrdatetr, (SELECT COUNT(*) FROM {$this->t('children')} c WHERE c.familyID = f.familyID AND c.gedcom = f.gedcom) AS nch FROM {$this->t('families')} f WHERE f.gedcom = %s AND (f.husband IN ($in) OR f.wife IN ($in)) ORDER BY f.marrdatetr, f.familyID",
				array_merge( array( $this->tree ), $this->idv( $chunk ), $this->idv( $chunk ) )
			);
			foreach ( $rows as $r ) {
				list( $my, $mf ) = self::dates( isset( $r['marrdatetr'] ) ? $r['marrdatetr'] : '', isset( $r['marrdate'] ) ? $r['marrdate'] : '' );
				$h = (string) $r['husband'];
				$w = (string) $r['wife'];
				foreach ( array( array( $h, $w ), array( $w, $h ) ) as $pair ) {
					if ( '' !== $pair[0] && in_array( $pair[0], $chunk, true ) ) {
						$out[ $pair[0] ][] = array( (string) $r['familyID'], $pair[1], (int) $r['nch'], $my, $mf );
					}
				}
			}
		}
		return $out;
	}

	private function has_prefix() {
		if ( null === $this->hasPrefix ) {
			$r               = $this->db->get_results( "SHOW COLUMNS FROM {$this->t('people')} LIKE 'lnprefix'" );
			$this->hasPrefix = ! empty( $r );
		}
		return $this->hasPrefix;
	}

	/** Date abrégée : année, date numérique complète et texte d'origine. */
	private static function dates( $tr, $raw ) {
		$tr  = (string) $tr;
		$raw = trim( (string) $raw );
		$y   = '';
		$f   = '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $tr, $m ) && '0000' !== $m[1] ) {
			$y = $m[1];
			$f = ( '00' !== $m[3] && '00' !== $m[2] ) ? $m[3] . '.' . $m[2] . '.' . $m[1] : ( '00' !== $m[2] ? $m[2] . '.' . $m[1] : $m[1] );
		} elseif ( preg_match( '/\b(\d{4})\b/', $raw, $m ) ) {
			$y = $m[1];
			$f = $raw;
		}
		return array( $y, $f );
	}

	/** Fiches abrégées : [ id => [ ln, fn, s, by, bf, bp, dy, df, dp, h ] ]. Vivants/privés masqués si demandé. */
	public function persons( array $ids ) {
		$out = array();
		$ids = array_values( array_unique( $ids ) );
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$pre  = $this->has_prefix() ? ', lnprefix' : '';
			$rows = $this->rows(
				"SELECT personID, firstname, lastname{$pre}, sex, birthdate, birthdatetr, birthplace, deathdate, deathdatetr, deathplace, living, private FROM {$this->t('people')} WHERE gedcom = %s AND personID IN (" . $this->in( $chunk ) . ')',
				array_merge( array( $this->tree ), $this->idv( $chunk ) )
			);
			foreach ( $rows as $r ) {
				$hidden = $this->hide && ( 1 === (int) $r['living'] || 1 === (int) $r['private'] );
				if ( $hidden ) {
					$out[ $r['personID'] ] = array( 'h' => 1 );
					continue;
				}
				list( $by, $bf ) = self::dates( $r['birthdatetr'], $r['birthdate'] );
				list( $dy, $df ) = self::dates( $r['deathdatetr'], $r['deathdate'] );
				$ln              = trim( ( isset( $r['lnprefix'] ) ? $r['lnprefix'] . ' ' : '' ) . $r['lastname'] );
				$sex             = strtoupper( (string) $r['sex'] );
				$out[ $r['personID'] ] = array(
					'ln' => $ln,
					'fn' => trim( (string) $r['firstname'] ),
					's'  => ( 'M' === $sex || 'F' === $sex ) ? $sex : '',
					'by' => $by,
					'bf' => $bf,
					'bp' => trim( (string) $r['birthplace'] ),
					'dy' => $dy,
					'df' => $df,
					'dp' => trim( (string) $r['deathplace'] ),
					'h'  => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * @param bool $spouses Joindre les unions (conjoints) des personnes de la descendance et du centre.
	 * @return array|null [ anc => génération => [ids|null], desc => génération => [ [id, p, f] ], unions, persons, truncated ]
	 */
	public function build( $root, $asc, $desc, $spouses = false ) {
		$count = 1;
		$anc   = array( array( $root ) );
		for ( $g = 1; $g <= $asc; $g++ ) {
			$prev = array();
			foreach ( end( $anc ) as $id ) {
				if ( null !== $id ) {
					$prev[] = $id;
				}
			}
			$par = $this->parents( array_values( array_unique( $prev ) ) );
			$row = array();
			$any = false;
			foreach ( end( $anc ) as $id ) {
				$f     = ( null !== $id && isset( $par[ $id ] ) ) ? $par[ $id ][0] : null;
				$m     = ( null !== $id && isset( $par[ $id ] ) ) ? $par[ $id ][1] : null;
				$row[] = $f;
				$row[] = $m;
				$any   = $any || null !== $f || null !== $m;
			}
			if ( ! $any ) {
				break;
			}
			$count += count( array_filter( $row ) );
			if ( $count > self::ANC_CAP ) {
				$this->truncated    = true;
				$this->truncatedAnc = true;
				break;
			}
			$anc[] = $row;
		}

		$count = 1;
		$gens  = array( array( array( 'id' => $root, 'p' => -1, 'f' => '' ) ) );
		for ( $d = 1; $d <= $desc; $d++ ) {
			$last = end( $gens );
			$ids  = array();
			foreach ( $last as $n ) {
				$ids[] = $n['id'];
			}
			$ch  = $this->children( array_values( array_unique( $ids ) ) );
			$row = array();
			foreach ( $last as $idx => $n ) {
				foreach ( isset( $ch[ $n['id'] ] ) ? $ch[ $n['id'] ] : array() as $c ) {
					$row[] = array( 'id' => $c[0], 'p' => $idx, 'f' => $c[1] );
				}
			}
			if ( ! $row ) {
				break;
			}
			$count += count( $row );
			if ( $count > self::DESC_CAP ) {
				$this->truncated     = true;
				$this->truncatedDesc = true;
				break;
			}
			$gens[] = $row;
		}

		$all = array();
		foreach ( $anc as $row ) {
			foreach ( $row as $id ) {
				if ( null !== $id ) {
					$all[] = $id;
				}
			}
		}
		$descIds = array();
		foreach ( $gens as $row ) {
			foreach ( $row as $n ) {
				$all[]     = $n['id'];
				$descIds[] = $n['id'];
			}
		}
		$unions = array();
		if ( $spouses ) {
			$unions = $this->unions( $descIds );
			foreach ( $unions as $list ) {
				foreach ( $list as $u ) {
					if ( '' !== $u[1] ) {
						$all[] = $u[1];
					}
				}
			}
		}
		$persons = $this->persons( $all );
		if ( ! isset( $persons[ $root ] ) ) {
			return null;
		}
		return array(
			'anc'       => $anc,
			'desc'      => $gens,
			'unions'    => (object) $unions,
			'persons'   => $persons,
			'truncated' => $this->truncated,
			'truncAnc'  => $this->truncatedAnc,
			'truncDesc' => $this->truncatedDesc,
		);
	}

	/** Recherche par nom (vivants/privés exclus quand ils sont masqués). */
	public function search( $q ) {
		$like = '%' . $this->db->esc_like( $q ) . '%';
		$priv = $this->hide ? ' AND living = 0 AND private = 0' : '';
		$rows = $this->rows(
			"SELECT personID, firstname, lastname, birthdatetr, deathdatetr FROM {$this->t('people')} WHERE gedcom = %s AND (CONCAT(lastname, ' ', firstname) LIKE %s OR CONCAT(firstname, ' ', lastname) LIKE %s OR personID = %s){$priv} ORDER BY lastname, firstname LIMIT 15",
			array( $this->tree, $like, $like, $q )
		);
		$out = array();
		foreach ( $rows as $r ) {
			list( $by ) = self::dates( $r['birthdatetr'], '' );
			list( $dy ) = self::dates( $r['deathdatetr'], '' );
			$out[]      = array( 'id' => $r['personID'], 'name' => trim( $r['lastname'] . ' ' . $r['firstname'] ), 'b' => $by, 'd' => $dy );
		}
		return $out;
	}
}

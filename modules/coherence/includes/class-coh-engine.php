<?php
/**
 * Cohérence des données : moteur d'analyse, par lots (un lot = une requête AJAX de quelques secondes).
 * La base TNG n'est lue que par des SELECT ; les résultats sont écrits dans la base WordPress.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGCoh_Engine {

	const BLOCK_CAP = 250;   // taille maximale d'un bloc de comparaison de doublons.
	const SUB_CAP   = 400;

	private $db;
	private $t = array();
	private $tree;
	private $cfg;
	private $st;
	private $buf = array();
	private $ign = array();
	private $today;
	private $first;
	private $dupcut = 0;

	/* ------------------------------------------------------------------ API */

	/** Liste des arbres de la base TNG : [ id => nom ]. */
	public static function trees() {
		$db = tngs_db();
		if ( ! $db ) {
			return array();
		}
		$p    = tngs_prefix();
		$prev = $db->suppress_errors( true );
		$rows = $db->get_results( "SELECT gedcom, treename FROM `{$p}trees` ORDER BY treename", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$db->suppress_errors( $prev );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$id = (string) $r['gedcom'];
			if ( preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $id ) ) {
				$out[ $id ] = '' !== (string) $r['treename'] ? (string) $r['treename'] : $id;
			}
		}
		return $out;
	}

	public static function labels() {
		return array(
			'people'   => 'Personnes : dates, sexe, noms, liens',
			'families' => 'Familles : mariages, parents et enfants',
			'loops'    => 'Recherche des boucles d’ascendance',
			'struct'   => 'Enfants de plusieurs familles',
			'struct2'  => 'Couples en double',
			'dupes'    => 'Recherche des doublons',
			'places'   => 'Orthographe des lieux',
			'done'     => 'Analyse terminée',
		);
	}

	public static function state() {
		$s = get_option( TNGCoh_Store::RUN, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Démarre une analyse : efface les résultats précédents de l'arbre. */
	public static function start( $tree ) {
		$db = tngs_db();
		if ( ! $db ) {
			return new WP_Error( 'tngcoh_db', tngs_t( 'Connexion à la base TNG impossible.' ) );
		}
		$trees = self::trees();
		if ( ! isset( $trees[ $tree ] ) ) {
			return new WP_Error( 'tngcoh_tree', tngs_t( 'Arbre inconnu.' ) );
		}
		TNGCoh_Store::install();
		TNGCoh_Store::clear( $tree );
		$p    = tngs_prefix();
		$prev = $db->suppress_errors( true );
		$mp   = (int) $db->get_var( $db->prepare( "SELECT MAX(ID) FROM `{$p}people` WHERE gedcom = %s", $tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mf   = (int) $db->get_var( $db->prepare( "SELECT MAX(ID) FROM `{$p}families` WHERE gedcom = %s", $tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$np   = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM `{$p}people` WHERE gedcom = %s", $tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nf   = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM `{$p}families` WHERE gedcom = %s", $tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$err  = $db->last_error;
		$db->suppress_errors( $prev );
		if ( '' !== $err ) {
			return new WP_Error( 'tngcoh_sql', current_user_can( 'manage_options' ) ? $err : 'SQL' );
		}
		$st = array(
			'token'   => wp_generate_password( 20, false ),
			'tree'    => $tree,
			'status'  => 'running',
			'phase'   => 'people',
			'cur'     => 0,
			'max'     => array( 'people' => $mp, 'fam' => $mf ),
			'n'       => array( 'people' => $np, 'fam' => $nf ),
			'blocks'  => -1,
			'boff'    => 0,
			'skipped' => 0,
			'counts'  => array(),
			'cut'     => array(),
			'started' => time(),
			'updated' => time(),
			'err'     => '',
			'cfg'     => TNGCoh_Rules::options(),
		);
		update_option( TNGCoh_Store::RUN, $st, false );
		return self::publish( $st );
	}

	public static function cancel( $token ) {
		$st = self::state();
		if ( $st && hash_equals( (string) $st['token'], (string) $token ) && 'running' === $st['status'] ) {
			$st['status']  = 'canceled';
			$st['updated'] = time();
			update_option( TNGCoh_Store::RUN, $st, false );
			delete_transient( 'tngcoh_lock' );
			self::finish_summary( $st, false );
		}
		return self::publish( $st );
	}

	/** Exécute un lot (quelques secondes) et renvoie l'état à afficher. */
	public static function step( $token ) {
		$st = self::state();
		if ( ! $st || ! hash_equals( (string) $st['token'], (string) $token ) ) {
			return new WP_Error( 'tngcoh_token', tngs_t( 'Analyse inconnue ou remplacée.' ) );
		}
		if ( 'running' !== $st['status'] ) {
			return self::publish( $st );
		}
		if ( get_transient( 'tngcoh_lock' ) ) {   // un autre lot est en cours (onglet en double, requête lente).
			$st['busy'] = true;
			return self::publish( $st );
		}
		set_transient( 'tngcoh_lock', 1, 90 );
		try {
			$e  = new self( $st );
			$st = $e->run();
		} catch ( Exception $ex ) {
			$st['status'] = 'error';
			$st['err']    = $ex->getMessage();
			update_option( TNGCoh_Store::RUN, $st, false );
		}
		delete_transient( 'tngcoh_lock' );
		return self::publish( $st );
	}

	/** Réponse JSON : avancement en pourcentage, phase, anomalies trouvées. */
	public static function publish( array $st ) {
		$lab   = self::labels();
		$phase = isset( $st['phase'] ) ? $st['phase'] : 'people';
		$w     = array(
			'people' => array( 0, 38 ), 'families' => array( 38, 22 ), 'loops' => array( 60, 8 ), 'struct' => array( 68, 2 ),
			'struct2' => array( 70, 1 ), 'dupes' => array( 71, 22 ), 'places' => array( 93, 7 ),
		);
		$pct = 100;
		if ( 'done' !== $phase && isset( $w[ $phase ] ) && ! empty( $st['status'] ) ) {
			$f   = 0;
			$max = isset( $st['max'] ) ? $st['max'] : array( 'people' => 0, 'fam' => 0 );
			if ( 'people' === $phase && $max['people'] > 0 ) {
				$f = $st['cur'] / $max['people'];
			} elseif ( ( 'families' === $phase || 'loops' === $phase ) && $max['fam'] > 0 ) {
				$f = $st['cur'] / $max['fam'];
			} elseif ( 'dupes' === $phase && $st['blocks'] > 0 ) {
				$f = $st['boff'] / $st['blocks'];
			}
			$pct = (int) floor( $w[ $phase ][0] + $w[ $phase ][1] * min( 1, max( 0, $f ) ) );
		}
		$found = 0;
		foreach ( (array) ( isset( $st['counts'] ) ? $st['counts'] : array() ) as $n ) {
			$found += (int) $n;
		}
		return array(
			'status' => isset( $st['status'] ) ? $st['status'] : 'none',
			'token'  => isset( $st['token'] ) ? $st['token'] : '',
			'tree'   => isset( $st['tree'] ) ? $st['tree'] : '',
			'phase'  => $phase,
			'label'  => tngs_t( isset( $lab[ $phase ] ) ? $lab[ $phase ] : '' ),
			'pct'    => min( 100, $pct ),
			'found'  => $found,
			'busy'   => ! empty( $st['busy'] ),
			'err'    => ( isset( $st['err'] ) && '' !== $st['err'] && current_user_can( 'manage_options' ) ) ? (string) $st['err'] : '',
		);
	}

	/* ------------------------------------------------------------------ lot */

	private function __construct( array $st ) {
		$this->db   = tngs_db();
		if ( ! $this->db ) {
			throw new RuntimeException( tngs_db_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message interne, jamais affiché tel quel (réservé aux administrateurs).
		}
		$p                  = tngs_prefix();
		$this->t            = array(
			'people'   => '`' . $p . 'people`',
			'families' => '`' . $p . 'families`',
			'children' => '`' . $p . 'children`',
		);
		$this->st           = $st;
		$this->tree         = (string) $st['tree'];
		$this->cfg          = $st['cfg'];
		$this->ign          = TNGCoh_Store::ignored();
		$this->today        = TNGCoh_Rules::days( (int) gmdate( 'Y' ), (int) gmdate( 'n' ), (int) gmdate( 'j' ) );
		$this->first        = TNGCoh_Rules::firstnames();
	}

	private function run() {
		$ini    = (int) ini_get( 'max_execution_time' );
		$budget = $ini > 0 ? max( 1.0, min( 3.0, $ini * 0.3 ) ) : 3.0;
		$until  = microtime( true ) + $budget;
		$prev   = $this->db->suppress_errors( true );
		try {
			do {
				$ph = $this->st['phase'];
				if ( 'people' === $ph ) {
					$this->phase_people();
				} elseif ( 'families' === $ph ) {
					$this->phase_families();
				} elseif ( 'loops' === $ph ) {
					$this->phase_loops();
				} elseif ( 'struct' === $ph ) {
					$this->phase_struct();
				} elseif ( 'struct2' === $ph ) {
					$this->phase_struct2();
				} elseif ( 'dupes' === $ph ) {
					$this->phase_dupes();
				} elseif ( 'places' === $ph ) {
					$this->phase_places();
				} else {
					break;
				}
				$this->flush();
			} while ( microtime( true ) < $until && 'done' !== $this->st['phase'] );
		} finally {
			$this->db->suppress_errors( $prev );
			$this->flush();
		}
		if ( 'done' === $this->st['phase'] ) {
			$this->st['status'] = 'done';
			self::finish_summary( $this->st, true );
		}
		$this->st['updated'] = time();
		update_option( TNGCoh_Store::RUN, $this->st, false );
		return $this->st;
	}

	/** Résumé de la dernière analyse d'un arbre (et suppression des clés de travail). */
	private static function finish_summary( array $st, $complete ) {
		global $wpdb;
		$tree = (string) $st['tree'];
		$wpdb->delete( TNGCoh_Store::table( 'key' ), array( 'tree' => $tree ), array( '%s' ) );
		$last          = TNGCoh_Store::last();
		$last[ $tree ] = array(
			'ts'       => time(),
			'dur'      => max( 0, time() - (int) $st['started'] ),
			'complete' => $complete ? 1 : 0,
			'people'   => (int) $st['n']['people'],
			'fam'      => (int) $st['n']['fam'],
			'skipped'  => (int) $st['skipped'],
			'cut'      => array_keys( (array) $st['cut'] ),
			'cfg'      => $st['cfg'],
		);
		update_option( TNGCoh_Store::LAST, $last, false );
	}

	private function next_phase() {
		$order = array( 'people', 'families', 'loops', 'struct', 'struct2', 'dupes', 'places', 'done' );
		$i     = array_search( $this->st['phase'], $order, true );
		$n     = $order[ min( count( $order ) - 1, (int) $i + 1 ) ];
		if ( 'loops' === $n && empty( $this->cfg['loops'] ) ) {
			$n = 'struct';
		}
		$this->st['phase'] = $n;
		$this->st['cur']   = 0;
	}

	/* ------------------------------------------------------------------ requêtes */

	private function rows( $sql, array $args ) {
		$q = $args ? $this->db->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$r = $this->db->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $this->db->last_error ) {
			throw new RuntimeException( $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message interne, affiché seulement aux administrateurs.
		}
		return $r ? $r : array();
	}

	/** Requête avec liste « IN » : « {IN} » est remplacé par autant de %s que d'identifiants (donnés après $before). */
	private function rows_in( $sql, array $before, array $ids, $times = 1 ) {
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args = $before;
			for ( $i = 0; $i < $times; $i++ ) {
				$args = array_merge( $args, $chunk );
			}
			foreach ( $this->rows( str_replace( '{IN}', $ph, $sql ), $args ) as $r ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	private function tol() {
		return (int) $this->cfg['tol'];
	}

	private function range( $tr, $txt ) {
		return TNGCoh_Rules::range( $tr, $txt, $this->tol() );
	}

	/* ------------------------------------------------------------------ anomalies */

	/**
	 * Enregistre une anomalie (en mémoire, écrite à la fin du lot).
	 *
	 * @param string   $code  Code du contrôle.
	 * @param string   $pid   Personne principale.
	 * @param string   $fid   Famille.
	 * @param string   $pid2  Seconde personne.
	 * @param array    $p     Valeurs du message.
	 * @param int      $score Score (doublons).
	 * @param int|null $sev   Gravité (sinon celle du contrôle).
	 * @param string   $x     Précision pour la signature (ex. champ de date).
	 */
	private function add( $code, $pid, $fid, $pid2, array $p, $score = 0, $sev = null, $x = '' ) {
		$rules = TNGCoh_Rules::rules();
		if ( ! isset( $rules[ $code ] ) ) {
			return;
		}
		$n = isset( $this->st['counts'][ $code ] ) ? (int) $this->st['counts'][ $code ] : 0;
		if ( $n >= (int) $this->cfg['max_rule'] ) {
			$this->st['cut'][ $code ] = 1;
			return;
		}
		$this->st['counts'][ $code ] = $n + 1;
		$sig                         = TNGCoh_Store::sig( $this->tree, $code, $pid . '~' . $x, $fid, $pid2 );
		$this->buf[]                 = array(
			'tree'  => $this->tree,
			'sig'   => $sig,
			'code'  => $code,
			'cat'   => $rules[ $code ][0],
			'sev'   => null === $sev ? $rules[ $code ][1] : (int) $sev,
			'pid'   => (string) $pid,
			'fid'   => (string) $fid,
			'pid2'  => (string) $pid2,
			'score' => (int) $score,
			'ign'   => isset( $this->ign[ $sig ] ) ? 1 : 0,
			'txt'   => wp_json_encode( $p, JSON_UNESCAPED_UNICODE ),
		);
	}

	private function flush() {
		if ( $this->buf ) {
			TNGCoh_Store::insert( $this->buf );
			$this->buf = array();
		}
	}

	/* ------------------------------------------------------------------ phase : personnes */

	private function phase_people() {
		$t    = $this->t;
		$rows = $this->rows(
			"SELECT ID, personID, lastname, firstname, sex, living, birthdate, birthdatetr, deathdate, deathdatetr, baptdate, baptdatetr, burialdate, burialdatetr, birthplace
			 FROM {$t['people']} WHERE gedcom = %s AND ID > %d ORDER BY ID LIMIT 400", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree, (int) $this->st['cur'] )
		);
		if ( ! $rows ) {
			$this->next_phase();
			return;
		}
		$ids  = array();
		foreach ( $rows as $r ) {
			$ids[] = (string) $r['personID'];
		}
		$par = array();
		foreach ( $this->rows_in(
			"SELECT c.personID, f.husband, f.wife FROM {$t['children']} c INNER JOIN {$t['families']} f ON f.gedcom = c.gedcom AND f.familyID = c.familyID
			 WHERE c.gedcom = %s AND c.personID IN ({IN}) ORDER BY c.ID", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree ),
			$ids
		) as $x ) {
			if ( ! isset( $par[ $x['personID'] ] ) ) {
				$par[ $x['personID'] ] = array( (string) $x['husband'], (string) $x['wife'] );
			}
		}
		$sp = array();
		foreach ( $this->rows_in(
			"SELECT husband, wife FROM {$t['families']} WHERE gedcom = %s AND (husband IN ({IN}) OR wife IN ({IN}))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree ),
			$ids,
			2
		) as $x ) {
			$sp[ $x['husband'] ] = 1;
			$sp[ $x['wife'] ]    = 1;
		}
		$keys = array();
		foreach ( $rows as $r ) {
			$this->st['cur'] = (int) $r['ID'];
			$pid             = (string) $r['personID'];
			$this->check_person( $r, isset( $par[ $pid ] ), isset( $sp[ $pid ] ) );
			$k = $this->dup_key( $r, isset( $par[ $pid ] ) ? $par[ $pid ] : array( '', '' ) );
			if ( $k ) {
				$keys[] = $k;
			}
		}
		$this->insert_keys( $keys );
	}

	private function check_person( array $r, $is_child, $is_spouse ) {
		$pid  = (string) $r['personID'];
		$name = TNGCoh_Rules::name( $r['lastname'], $r['firstname'] );
		$c    = $this->cfg;
		$def  = array(
			'birth'  => array( 'birthdate', 'birthdatetr' ),
			'bapt'   => array( 'baptdate', 'baptdatetr' ),
			'death'  => array( 'deathdate', 'deathdatetr' ),
			'burial' => array( 'burialdate', 'burialdatetr' ),
		);
		$rg   = array();
		$show = array();
		foreach ( $def as $f => $cols ) {
			$txt = trim( (string) $r[ $cols[0] ] );
			$tr  = (string) $r[ $cols[1] ];
			if ( '' !== $txt ) {
				$ck = TNGCoh_Rules::check_text( $txt );
				if ( 'impossible' === $ck ) {
					$this->add( 'D01', $pid, '', '', array( 'name' => $name, 'txt' => $txt, 'field' => $f ), 0, null, $f );
				} elseif ( 'unknown' === $ck ) {
					$this->add( 'D02', $pid, '', '', array( 'name' => $name, 'txt' => $txt, 'field' => $f ), 0, null, $f );
				}
			}
			$rg[ $f ]   = $this->range( $tr, $txt );
			$show[ $f ] = TNGCoh_Rules::show( $tr, $txt );
			if ( $rg[ $f ] && $rg[ $f ][0] > $this->today ) {
				$this->add( 'D03', $pid, '', '', array( 'name' => $name, 'txt' => $show[ $f ], 'field' => $f ), 0, null, $f );
			}
		}
		$b = $rg['birth'];
		$d = $rg['death'];
		if ( $b && $d && $d[1] < $b[0] ) {
			$this->add( 'D04', $pid, '', '', array( 'name' => $name, 'd1' => $show['birth'], 'd2' => $show['death'] ) );
		}
		if ( $b && $rg['bapt'] && $rg['bapt'][1] < $b[0] ) {
			$this->add( 'D05', $pid, '', '', array( 'name' => $name, 'd1' => $show['birth'], 'd2' => $show['bapt'] ) );
		}
		if ( $d && $rg['burial'] && $rg['burial'][1] < $d[0] ) {
			$this->add( 'D06', $pid, '', '', array( 'name' => $name, 'd1' => $show['death'], 'd2' => $show['burial'] ) );
		}
		if ( $b && $d && ( $d[0] - $b[1] ) / 365.25 > $c['age_max'] ) {
			$this->add( 'D07', $pid, '', '', array( 'name' => $name, 'age' => (int) round( ( ( $d[0] + $d[1] ) / 2 - ( $b[0] + $b[1] ) / 2 ) / 365.25 ), 'max' => $c['age_max'] ) );
		}
		if ( ! empty( $r['living'] ) ) {
			if ( $b && ( $this->today - $b[1] ) / 365.25 > $c['living_max'] ) {
				$this->add( 'D08', $pid, '', '', array( 'name' => $name, 'age' => (int) floor( ( $this->today - $b[1] ) / 365.25 ), 'max' => $c['living_max'] ) );
			}
			if ( $d ) {
				$this->add( 'D09', $pid, '', '', array( 'name' => $name, 'd1' => $show['death'] ) );
			}
		}
		// Noms.
		$ln = (string) $r['lastname'];
		$fn = (string) $r['firstname'];
		if ( '' === trim( $ln ) && '' === trim( $fn ) ) {
			$this->add( 'T13', $pid, '', '', array( 'name' => $name ) );
		}
		if ( '' !== trim( $ln ) && preg_match( '/\p{L}/u', $ln ) && mb_strtolower( $ln ) === $ln ) {
			$this->add( 'T10', $pid, '', '', array( 'name' => $name, 'txt' => $ln ) );
		}
		foreach ( array( $ln, $fn ) as $part ) {
			if ( '' !== $part && ( trim( $part ) !== $part || preg_match( '/\s{2,}/u', $part ) ) ) {
				$this->add( 'T11', $pid, '', '', array( 'name' => $name, 'txt' => $part ) );
				break;
			}
		}
		foreach ( array( $ln, $fn ) as $part ) {
			if ( '' !== $part && preg_match( '/[?\d*]/u', $part ) ) {
				$this->add( 'T12', $pid, '', '', array( 'name' => $name, 'txt' => $part ) );
				break;
			}
		}
		// Prénom habituellement porté par l'autre sexe (simple indice).
		$sex = strtoupper( (string) $r['sex'] );
		if ( ( 'M' === $sex || 'F' === $sex ) && '' !== trim( $fn ) ) {
			$tok = explode( ' ', TNGCoh_Rules::norm( $fn ) );
			$tok = $tok[0];
			if ( 'F' === $sex && isset( $this->first['m'][ $tok ] ) && ! isset( $this->first['f'][ $tok ] ) ) {
				$this->add( 'S05', $pid, '', '', array( 'name' => $name, 'txt' => trim( $fn ), 'typ' => 'm', 'sex' => 'F' ) );
			} elseif ( 'M' === $sex && isset( $this->first['f'][ $tok ] ) && ! isset( $this->first['m'][ $tok ] ) ) {
				$this->add( 'S05', $pid, '', '', array( 'name' => $name, 'txt' => trim( $fn ), 'typ' => 'f', 'sex' => 'M' ) );
			}
		}
		if ( ! $is_child && ! $is_spouse ) {
			$this->add( 'T01', $pid, '', '', array( 'name' => $name ) );
		}
	}

	/** Ligne de la table de clés (blocage des doublons) ou null si la personne n'est pas comparable. */
	private function dup_key( array $r, array $par ) {
		$ln = TNGCoh_Rules::norm( $r['lastname'] );
		$fn = TNGCoh_Rules::norm( $r['firstname'] );
		if ( '' === $ln || '' === $fn ) {
			return null;
		}
		$ft  = explode( ' ', $fn );
		$ft  = $ft[0];
		$lk  = substr( TNGCoh_Rules::phon( $ln ), 0, 5 ) . '|' . substr( TNGCoh_Rules::phon( $ft ), 0, 3 );
		$b   = TNGCoh_Rules::parse_tr( (string) $r['birthdatetr'] );
		$d   = TNGCoh_Rules::parse_tr( (string) $r['deathdatetr'] );
		$bd  = ( $b && $b[1] > 0 && $b[2] > 0 ) ? $b[0] * 10000 + $b[1] * 100 + $b[2] : 0;
		$sex = strtoupper( (string) $r['sex'] );
		return array(
			'pid' => (string) $r['personID'],
			'lk'  => substr( $lk, 0, 24 ),
			'ln'  => substr( $ln, 0, 60 ),
			'fn'  => substr( $fn, 0, 60 ),
			'byr' => $b ? $b[0] : 0,
			'dyr' => $d ? $d[0] : 0,
			'bd'  => $bd,
			'sex' => ( 'M' === $sex || 'F' === $sex ) ? $sex : '',
			'bp'  => substr( TNGCoh_Rules::place_key( $r['birthplace'] ), 0, 60 ),
			'fa'  => substr( (string) $par[0], 0, 40 ),
			'mo'  => substr( (string) $par[1], 0, 40 ),
		);
	}

	private function insert_keys( array $keys ) {
		if ( ! $keys ) {
			return;
		}
		$tab  = TNGCoh_Store::table( 'key' );
		$vals = array();
		foreach ( $keys as $k ) {
			$vals[] = $GLOBALS['wpdb']->prepare( '(%s,%s,%s,%s,%s,%d,%d,%d,%s,%s,%s,%s)', $this->tree, $k['pid'], $k['lk'], $k['ln'], $k['fn'], $k['byr'], $k['dyr'], $k['bd'], $k['sex'], $k['bp'], $k['fa'], $k['mo'] );
		}
		foreach ( array_chunk( $vals, 200 ) as $chunk ) {
			$GLOBALS['wpdb']->query( "INSERT IGNORE INTO {$tab} (tree,pid,lk,ln,fn,byr,dyr,bd,sex,bp,fa,mo) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/* ------------------------------------------------------------------ phase : familles */

	private static function natural( $rel ) {
		return in_array( strtolower( trim( (string) $rel ) ), array( '', 'birth', 'natural', 'biological' ), true );
	}

	private function phase_families() {
		$t    = $this->t;
		$fams = $this->rows(
			"SELECT ID, familyID, husband, wife, marrdate, marrdatetr, divdate, divdatetr FROM {$t['families']}
			 WHERE gedcom = %s AND ID > %d ORDER BY ID LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree, (int) $this->st['cur'] )
		);
		if ( ! $fams ) {
			$this->next_phase();
			return;
		}
		$fids = array();
		foreach ( $fams as $f ) {
			$fids[] = (string) $f['familyID'];
		}
		$kids = array();
		foreach ( $this->rows_in(
			"SELECT familyID, personID, frel, mrel FROM {$t['children']} WHERE gedcom = %s AND familyID IN ({IN}) ORDER BY ordernum, ID", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree ),
			$fids
		) as $k ) {
			$kids[ $k['familyID'] ][] = $k;
		}
		$need = array();
		foreach ( $fams as $f ) {
			$need[] = (string) $f['husband'];
			$need[] = (string) $f['wife'];
			foreach ( isset( $kids[ $f['familyID'] ] ) ? $kids[ $f['familyID'] ] : array() as $k ) {
				$need[] = (string) $k['personID'];
			}
		}
		$need = array_filter( $need, 'strlen' );
		$P    = array();
		foreach ( $this->rows_in(
			"SELECT personID, lastname, firstname, sex, birthdate, birthdatetr, deathdate, deathdatetr FROM {$t['people']} WHERE gedcom = %s AND personID IN ({IN})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree ),
			$need
		) as $p ) {
			$P[ $p['personID'] ] = array(
				'name' => TNGCoh_Rules::name( $p['lastname'], $p['firstname'] ),
				'nm'   => TNGCoh_Rules::norm( $p['firstname'] . ' ' . $p['lastname'] ),
				'sex'  => strtoupper( (string) $p['sex'] ),
				'b'    => $this->range( $p['birthdatetr'], $p['birthdate'] ),
				'd'    => $this->range( $p['deathdatetr'], $p['deathdate'] ),
				'bs'   => TNGCoh_Rules::show( $p['birthdatetr'], $p['birthdate'] ),
				'ds'   => TNGCoh_Rules::show( $p['deathdatetr'], $p['deathdate'] ),
				'by'   => ( TNGCoh_Rules::parse_tr( (string) $p['birthdatetr'] ) ?: array( 0 ) )[0],
			);
		}
		foreach ( $fams as $f ) {
			$this->st['cur'] = (int) $f['ID'];
			$this->check_family( $f, isset( $kids[ $f['familyID'] ] ) ? $kids[ $f['familyID'] ] : array(), $P );
		}
	}

	private function check_family( array $f, array $kids, array $P ) {
		$c   = $this->cfg;
		$fid = (string) $f['familyID'];
		$h   = (string) $f['husband'];
		$w   = (string) $f['wife'];
		$nm  = function ( $id ) use ( $P ) {
			return isset( $P[ $id ] ) ? $P[ $id ]['name'] : '?';
		};
		// Références à des personnes absentes.
		foreach ( array_merge( array( $h, $w ), array_map( function ( $k ) {
			return (string) $k['personID'];
		}, $kids ) ) as $id ) {
			if ( '' !== $id && ! isset( $P[ $id ] ) ) {
				$this->add( 'T14', '', $fid, $id, array( 'pid2' => $id ) );
			}
		}
		if ( '' !== $h && $h === $w ) {
			$this->add( 'T03', $h, $fid, '', array( 'name' => $nm( $h ) ) );
		}
		if ( '' === $h && '' === $w ) {
			if ( $kids ) {
				$this->add( 'T04', '', $fid, '', array( 'n' => count( $kids ) ) );
			} else {
				$this->add( 'T05', '', $fid, '', array() );
			}
		}
		// Enfants : doublons, parent = enfant.
		$seen = array();
		foreach ( $kids as $k ) {
			$id = (string) $k['personID'];
			if ( isset( $seen[ $id ] ) ) {
				$this->add( 'T08', $id, $fid, '', array( 'name' => $nm( $id ) ) );
			}
			$seen[ $id ] = 1;
			if ( '' !== $id && ( $id === $h || $id === $w ) ) {
				$this->add( 'T07', $id, $fid, '', array( 'name' => $nm( $id ) ) );
			}
		}
		// Sexe des parents.
		$hs = isset( $P[ $h ] ) ? $P[ $h ]['sex'] : '';
		$ws = isset( $P[ $w ] ) ? $P[ $w ]['sex'] : '';
		if ( ( 'M' === $hs || 'F' === $hs ) && $hs === $ws && $h !== $w ) {
			$this->add( 'S04', $h, $fid, $w, array( 'name' => $nm( $h ), 'name2' => $nm( $w ) ) );
		} elseif ( $h !== $w ) {
			if ( 'F' === $hs ) {
				$this->add( 'S01', $h, $fid, '', array( 'name' => $nm( $h ) ) );
			}
			if ( 'M' === $ws ) {
				$this->add( 'S02', $w, $fid, '', array( 'name' => $nm( $w ) ) );
			}
		}
		if ( $kids ) {
			foreach ( array( $h => $hs, $w => $ws ) as $id => $sx ) {
				if ( '' !== (string) $id && isset( $P[ $id ] ) && 'M' !== $sx && 'F' !== $sx ) {
					$this->add( 'S03', $id, $fid, '', array( 'name' => $nm( $id ) ) );
				}
			}
		}
		// Dates du mariage et du divorce.
		$mtxt = trim( (string) $f['marrdate'] );
		$dtxt = trim( (string) $f['divdate'] );
		foreach ( array( 'marr' => $mtxt, 'div' => $dtxt ) as $fld => $txt ) {
			if ( '' === $txt ) {
				continue;
			}
			$ck = TNGCoh_Rules::check_text( $txt );
			if ( 'impossible' === $ck ) {
				$this->add( 'D01', '', $fid, '', array( 'txt' => $txt, 'field' => $fld ), 0, null, $fld );
			} elseif ( 'unknown' === $ck ) {
				$this->add( 'D02', '', $fid, '', array( 'txt' => $txt, 'field' => $fld ), 0, null, $fld );
			}
		}
		$m  = $this->range( $f['marrdatetr'], $mtxt );
		$dv = $this->range( $f['divdatetr'], $dtxt );
		$ms = TNGCoh_Rules::show( $f['marrdatetr'], $mtxt );
		$ds = TNGCoh_Rules::show( $f['divdatetr'], $dtxt );
		if ( $m && $m[0] > $this->today ) {
			$this->add( 'D03', '', $fid, '', array( 'txt' => $ms, 'field' => 'marr' ), 0, null, 'marr' );
		}
		if ( $m && $dv && $dv[1] < $m[0] ) {
			$this->add( 'D18', '', $fid, '', array( 'd1' => $ms, 'd2' => $ds ) );
		}
		if ( $m ) {
			foreach ( array( $h, $w ) as $s ) {
				if ( '' === $s || ! isset( $P[ $s ] ) ) {
					continue;
				}
				$sp = $P[ $s ];
				if ( $sp['b'] && $m[1] < $sp['b'][0] ) {
					$this->add( 'D10', $s, $fid, '', array( 'name2' => $sp['name'], 'd1' => $sp['bs'], 'd2' => $ms ) );
				} elseif ( $sp['b'] && ( $m[1] - $sp['b'][0] ) / 365.25 < $c['marr_min'] ) {
					$this->add( 'D12', $s, $fid, '', array( 'name2' => $sp['name'], 'age' => max( 0, (int) round( ( ( $m[0] + $m[1] ) / 2 - ( $sp['b'][0] + $sp['b'][1] ) / 2 ) / 365.25 ) ), 'min' => $c['marr_min'] ) );
				}
				if ( $sp['d'] && $m[0] > $sp['d'][1] ) {
					$this->add( 'D11', $s, $fid, '', array( 'name2' => $sp['name'], 'd1' => $sp['ds'], 'd2' => $ms ) );
				}
			}
		}
		// Enfants et parents.
		$byname = array();
		foreach ( $kids as $k ) {
			$id = (string) $k['personID'];
			if ( ! isset( $P[ $id ] ) ) {
				continue;
			}
			$ch = $P[ $id ];
			$key = TNGCoh_Rules::norm( $ch['nm'] );
			if ( '' !== $key ) {
				$byname[ $key ][ $id ] = $ch;
			}
			if ( ! $ch['b'] ) {
				continue;
			}
			foreach ( array( 'f' => array( $h, $k['frel'] ), 'm' => array( $w, $k['mrel'] ) ) as $role => $pr ) {
				$pid = (string) $pr[0];
				if ( '' === $pid || ! isset( $P[ $pid ] ) || ! self::natural( $pr[1] ) || $pid === $id ) {
					continue;
				}
				$par = $P[ $pid ];
				$a   = array( 'name' => $ch['name'], 'name2' => $par['name'] );
				if ( $par['b'] ) {
					if ( $ch['b'][1] < $par['b'][0] ) {
						$this->add( 'D13', $id, $fid, $pid, $a + array( 'd1' => $par['bs'], 'd2' => $ch['bs'] ) );
					} else {
						$min = ( $ch['b'][0] - $par['b'][1] ) / 365.25;
						$max = ( $ch['b'][1] - $par['b'][0] ) / 365.25;
						$mid = (int) round( ( $min + $max ) / 2 );
						if ( 'm' === $role ) {
							if ( $max < $c['mother_min'] || $min > $c['mother_max'] ) {
								$this->add( 'D16', $id, $fid, $pid, $a + array( 'age' => $mid, 'min' => $c['mother_min'], 'max' => $c['mother_max'] ), 0, ( $max < 9 || $min > 60 ) ? TNGCoh_Rules::ERR : TNGCoh_Rules::WARN );
							}
						} elseif ( $max < $c['father_min'] || $min > $c['father_max'] ) {
							$this->add( 'D17', $id, $fid, $pid, $a + array( 'age' => $mid, 'min' => $c['father_min'], 'max' => $c['father_max'] ) );
						}
					}
				}
				if ( $par['d'] ) {
					if ( 'm' === $role && $ch['b'][0] > $par['d'][1] ) {
						$this->add( 'D14', $id, $fid, $pid, $a + array( 'd1' => $par['ds'], 'd2' => $ch['bs'] ) );
					} elseif ( 'f' === $role && $ch['b'][0] > $par['d'][1] + (int) round( $c['posthumous'] * 30.44 ) ) {
						$this->add( 'D15', $id, $fid, $pid, $a + array( 'd1' => $par['ds'], 'd2' => $ch['bs'], 'months' => $c['posthumous'] ) );
					}
				}
			}
		}
		// Le même enfant saisi deux fois dans la famille.
		foreach ( $byname as $grp ) {
			if ( count( $grp ) < 2 ) {
				continue;
			}
			$ids = array_keys( $grp );
			sort( $ids, SORT_STRING );
			for ( $i = 0; $i < count( $ids ); $i++ ) {
				for ( $j = $i + 1; $j < count( $ids ); $j++ ) {
					$x = $grp[ $ids[ $i ] ];
					$y = $grp[ $ids[ $j ] ];
					if ( $x['sex'] !== $y['sex'] && in_array( $x['sex'], array( 'M', 'F' ), true ) && in_array( $y['sex'], array( 'M', 'F' ), true ) ) {
						continue;
					}
					$dy = ( $x['by'] && $y['by'] ) ? abs( $x['by'] - $y['by'] ) : 0;
					if ( $dy > 1 ) {
						continue;   // jumeaux ou enfants de même prénom (décès d'un aîné) : naissances distinctes.
					}
					$this->add( 'P02', $ids[ $i ], $fid, $ids[ $j ], array( 'name' => $x['name'], 'name2' => $y['name'], 'why' => 'n1,fa' . ( ( $x['by'] && $x['by'] === $y['by'] ) ? ',b2' : '' ) ), 80 );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ phase : boucles d'ascendance */

	private function phase_loops() {
		$t    = $this->t;
		$fams = $this->rows(
			"SELECT f.ID, f.husband, f.wife FROM {$t['families']} f
			 WHERE f.gedcom = %s AND f.ID > %d AND EXISTS (SELECT 1 FROM {$t['children']} c WHERE c.gedcom = f.gedcom AND c.familyID = f.familyID)
			 ORDER BY f.ID LIMIT 120", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree, (int) $this->st['cur'] )
		);
		if ( ! $fams ) {
			$this->next_phase();
			return;
		}
		$cand = array();
		foreach ( $fams as $f ) {
			$this->st['cur'] = (int) $f['ID'];
			foreach ( array( (string) $f['husband'], (string) $f['wife'] ) as $x ) {
				if ( '' !== $x ) {
					$cand[ $x ] = 1;
				}
			}
		}
		$cand     = array_keys( $cand );
		$frontier = array();
		$seen     = array();
		foreach ( $cand as $p ) {
			$frontier[ $p ] = array( $p );
			$seen[ $p ]     = array();
		}
		$found = array();
		for ( $depth = 0; $depth < (int) $this->cfg['loop_depth'] && $frontier; $depth++ ) {
			$all = array();
			foreach ( $frontier as $list ) {
				foreach ( $list as $x ) {
					$all[ $x ] = 1;
				}
			}
			$parents = array();
			foreach ( $this->rows_in(
				"SELECT c.personID, f.husband, f.wife FROM {$t['children']} c INNER JOIN {$t['families']} f ON f.gedcom = c.gedcom AND f.familyID = c.familyID
				 WHERE c.gedcom = %s AND c.personID IN ({IN})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array( $this->tree ),
				array_keys( $all )
			) as $r ) {
				foreach ( array( (string) $r['husband'], (string) $r['wife'] ) as $x ) {
					if ( '' !== $x ) {
						$parents[ $r['personID'] ][ $x ] = 1;
					}
				}
			}
			$next = array();
			foreach ( $frontier as $origin => $list ) {
				$n = array();
				foreach ( $list as $x ) {
					foreach ( isset( $parents[ $x ] ) ? array_keys( $parents[ $x ] ) : array() as $par ) {
						$par = (string) $par;
						if ( $par === (string) $origin ) {
							$found[ $origin ] = 1;
							continue 3;
						}
						if ( ! isset( $seen[ $origin ][ $par ] ) ) {
							$seen[ $origin ][ $par ] = 1;
							$n[]                     = $par;
						}
					}
				}
				if ( $n ) {
					$next[ $origin ] = $n;
				}
			}
			$frontier = $next;
		}
		if ( $found ) {
			foreach ( $this->names( array_keys( $found ) ) as $id => $nm ) {
				$this->add( 'T02', (string) $id, '', '', array( 'name' => $nm ) );
			}
		}
	}

	/** Noms de personnes : [ personID => « NOM Prénom » ]. */
	private function names( array $ids ) {
		$out = array();
		foreach ( $this->rows_in( "SELECT personID, lastname, firstname FROM {$this->t['people']} WHERE gedcom = %s AND personID IN ({IN})", array( $this->tree ), array_map( 'strval', $ids ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $r['personID'] ] = TNGCoh_Rules::name( $r['lastname'], $r['firstname'] );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ phases : structure */

	/** Enfants naturels de plusieurs familles. */
	private function phase_struct() {
		$t   = $this->t;
		$rows = $this->rows(
			"SELECT c.personID, GROUP_CONCAT(c.familyID ORDER BY c.familyID SEPARATOR ', ') fams, COUNT(*) n FROM {$t['children']} c
			 WHERE c.gedcom = %s AND LOWER(COALESCE(NULLIF(c.frel, ''), 'birth')) IN ('birth', 'natural', 'biological') AND LOWER(COALESCE(NULLIF(c.mrel, ''), 'birth')) IN ('birth', 'natural', 'biological')
			 GROUP BY c.personID HAVING n > 1 ORDER BY c.personID LIMIT 1000 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree, (int) $this->st['cur'] )
		);
		if ( ! $rows ) {
			$this->next_phase();
			return;
		}
		$names = $this->names( array_column( $rows, 'personID' ) );
		foreach ( $rows as $r ) {
			$this->add( 'T06', (string) $r['personID'], '', '', array( 'name' => isset( $names[ $r['personID'] ] ) ? $names[ $r['personID'] ] : '?', 'list' => mb_substr( (string) $r['fams'], 0, 120 ) ) );
		}
		$this->st['cur'] += 1000;
		if ( count( $rows ) < 1000 ) {
			$this->next_phase();
		}
	}

	/** Couples présents dans plusieurs familles. */
	private function phase_struct2() {
		$t    = $this->t;
		$rows = $this->rows(
			"SELECT husband, wife, COUNT(*) n, GROUP_CONCAT(familyID ORDER BY familyID SEPARATOR ', ') fams FROM {$t['families']}
			 WHERE gedcom = %s AND husband <> '' AND wife <> '' GROUP BY husband, wife HAVING n > 1 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $this->tree, (int) $this->cfg['max_rule'] )
		);
		$ids   = array();
		foreach ( $rows as $r ) {
			$ids[] = (string) $r['husband'];
			$ids[] = (string) $r['wife'];
		}
		$names = $this->names( $ids );
		foreach ( $rows as $r ) {
			$this->add(
				'T09',
				(string) $r['husband'],
				'',
				(string) $r['wife'],
				array(
					'name'  => isset( $names[ $r['husband'] ] ) ? $names[ $r['husband'] ] : '?',
					'name2' => isset( $names[ $r['wife'] ] ) ? $names[ $r['wife'] ] : '?',
					'list'  => mb_substr( (string) $r['fams'], 0, 120 ),
				)
			);
		}
		$this->next_phase();
	}

	/* ------------------------------------------------------------------ phase : doublons */

	private function phase_dupes() {
		global $wpdb;
		$kt = TNGCoh_Store::table( 'key' );
		if ( (int) $this->st['blocks'] < 0 ) {
			$this->st['blocks'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT lk FROM {$kt} WHERE tree = %s GROUP BY lk HAVING COUNT(*) > 1) x", $this->tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->st['boff']   = 0;
		}
		$blocks = $wpdb->get_col( $wpdb->prepare( "SELECT lk FROM {$kt} WHERE tree = %s GROUP BY lk HAVING COUNT(*) > 1 ORDER BY lk LIMIT 120 OFFSET %d", $this->tree, (int) $this->st['boff'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $blocks ) {
			$this->next_phase();
			return;
		}
		$ph   = implode( ',', array_fill( 0, count( $blocks ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$kt} WHERE tree = %s AND lk IN ({$ph}) ORDER BY lk, pid", array_merge( array( $this->tree ), $blocks ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by   = array();
		foreach ( (array) $rows as $r ) {
			$by[ $r['lk'] ][] = $r;
		}
		$pairs = array();
		foreach ( $by as $members ) {
			if ( count( $members ) > self::BLOCK_CAP ) {
				// Bloc trop grand (« Jean Martin ») : on ne compare que les noms strictement identiques.
				$sub = array();
				foreach ( $members as $m ) {
					$sub[ $m['ln'] . '|' . $m['fn'] ][] = $m;
				}
				foreach ( $sub as $g ) {
					if ( count( $g ) > self::SUB_CAP ) {
						$this->st['skipped']++;
						continue;
					}
					$this->compare( $g, $pairs, true );
				}
			} else {
				$this->compare( $members, $pairs, false );
			}
		}
		if ( $pairs ) {
			$ids = array();
			foreach ( $pairs as $p ) {
				$ids[ $p[0]['pid'] ] = 1;
				$ids[ $p[1]['pid'] ] = 1;
			}
			$names = $this->names( array_keys( $ids ) );
			foreach ( $pairs as $p ) {
				list( $a, $b, $score, $why ) = $p;
				$na = ( isset( $names[ $a['pid'] ] ) ? $names[ $a['pid'] ] : '?' ) . ( $a['byr'] ? ' (' . $a['byr'] . ')' : '' );
				$nb = ( isset( $names[ $b['pid'] ] ) ? $names[ $b['pid'] ] : '?' ) . ( $b['byr'] ? ' (' . $b['byr'] . ')' : '' );
				$this->add( 'P01', $a['pid'], '', $b['pid'], array( 'name' => $na, 'name2' => $nb, 'pid2' => $b['pid'], 'why' => implode( ',', $why ) ), $score, $score >= 85 ? TNGCoh_Rules::WARN : TNGCoh_Rules::INFO );
			}
		}
		$this->st['boff'] += count( $blocks );
		if ( count( $blocks ) < 120 ) {
			$this->next_phase();
		}
	}

	/** Compare toutes les paires d'un bloc ; ajoute à $pairs celles dont le score atteint le seuil. */
	private function compare( array $m, array &$pairs, $strict ) {
		$n   = count( $m );
		$min = (int) $this->cfg['dup_min'];
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$r = $this->score( $m[ $i ], $m[ $j ] );
				if ( $r && $r[0] >= $min ) {
					$pairs[] = ( strcmp( $m[ $i ]['pid'], $m[ $j ]['pid'] ) <= 0 ) ? array( $m[ $i ], $m[ $j ], $r[0], $r[1] ) : array( $m[ $j ], $m[ $i ], $r[0], $r[1] );
				}
			}
		}
	}

	/** Score de ressemblance (0 à 100) de deux personnes + raisons, ou null si elles ne peuvent pas être la même. */
	private function score( array $a, array $b ) {
		if ( $a['sex'] && $b['sex'] && $a['sex'] !== $b['sex'] ) {
			return null;
		}
		$s   = 0;
		$why = array();
		// Nom de famille.
		if ( $a['ln'] === $b['ln'] ) {
			$s    += 30;
			$why[] = 'n1';
		} elseif ( TNGCoh_Rules::phon( $a['ln'] ) === TNGCoh_Rules::phon( $b['ln'] ) ) {
			$s    += 22;
			$why[] = 'n2';
		} else {
			$l = levenshtein( $a['ln'], $b['ln'] );
			if ( $l <= 1 ) {
				$s += 18;
			} elseif ( $l <= 2 ) {
				$s += 10;
			} else {
				return null;
			}
			$why[] = 'n2';
		}
		// Prénom (le premier).
		$fa = explode( ' ', $a['fn'] );
		$fb = explode( ' ', $b['fn'] );
		if ( $fa[0] === $fb[0] ) {
			$s += 25;
		} elseif ( strlen( $fa[0] ) >= 3 && strlen( $fb[0] ) >= 3 && ( 0 === strpos( $fa[0], $fb[0] ) || 0 === strpos( $fb[0], $fa[0] ) ) ) {
			$s += 15;
		} elseif ( TNGCoh_Rules::phon( $fa[0] ) === TNGCoh_Rules::phon( $fb[0] ) ) {
			$s += 15;
		} elseif ( levenshtein( $fa[0], $fb[0] ) <= 1 ) {
			$s += 12;
		} elseif ( $fa[0][0] === $fb[0][0] && ( strlen( $fa[0] ) <= 2 || strlen( $fb[0] ) <= 2 ) ) {
			$s += 6;
		}
		if ( $a['fn'] === $b['fn'] && count( $fa ) > 1 ) {
			$s += 3;
		}
		// Naissance.
		if ( $a['bd'] && $b['bd'] && $a['bd'] === $b['bd'] ) {
			$s    += 25;
			$why[] = 'b1';
		} elseif ( $a['byr'] && $b['byr'] ) {
			$d = abs( $a['byr'] - $b['byr'] );
			if ( 0 === $d ) {
				$s    += 18;
				$why[] = 'b2';
			} elseif ( $d <= 1 ) {
				$s    += 10;
				$why[] = 'b3';
			} elseif ( $d <= 3 ) {
				$s    += 3;
			} elseif ( $d > 5 ) {
				$s -= 30;
			}
		}
		// Décès.
		if ( $a['dyr'] && $b['dyr'] ) {
			$d = abs( $a['dyr'] - $b['dyr'] );
			if ( 0 === $d ) {
				$s    += 8;
				$why[] = 'd1';
			} elseif ( $d <= 1 ) {
				$s += 4;
			} elseif ( $d > 5 ) {
				$s -= 20;
			}
		}
		if ( '' !== $a['bp'] && $a['bp'] === $b['bp'] ) {
			$s    += 7;
			$why[] = 'bp';
		}
		// Parents.
		$pf = '' !== $a['fa'] && $a['fa'] === $b['fa'];
		$pm = '' !== $a['mo'] && $a['mo'] === $b['mo'];
		if ( $pf && $pm ) {
			$s    += 16;
			$why[] = 'pa';
		} elseif ( $pf ) {
			$s    += 8;
			$why[] = 'pf';
		} elseif ( $pm ) {
			$s    += 8;
			$why[] = 'pm';
		} elseif ( '' !== $a['fa'] && '' !== $b['fa'] ) {
			$s -= 8;
		}
		return array( max( 0, min( 100, $s ) ), $why );
	}

	/* ------------------------------------------------------------------ phase : lieux */

	private function phase_places() {
		$t     = $this->t;
		$count = array();
		foreach ( array(
			array( 'people', 'birthplace' ),
			array( 'people', 'deathplace' ),
			array( 'people', 'burialplace' ),
			array( 'families', 'marrplace' ),
		) as $tc ) {
			$rows = $this->rows( "SELECT MIN({$tc[1]}) p, COUNT(*) n FROM {$t[$tc[0]]} WHERE gedcom = %s AND {$tc[1]} <> '' GROUP BY BINARY {$tc[1]}", array( $this->tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				$p = trim( (string) $r['p'] );
				if ( '' !== $p ) {
					$count[ $p ] = ( isset( $count[ $p ] ) ? $count[ $p ] : 0 ) + (int) $r['n'];
				}
			}
		}
		// Regroupement par clé de comparaison, puis par fautes de frappe (distance 1) à clé voisine.
		$groups = array();
		foreach ( $count as $p => $n ) {
			$k = TNGCoh_Rules::place_key( $p );
			if ( '' !== $k ) {
				$groups[ $k ][] = $p;
			}
		}
		$parent = array();
		foreach ( array_keys( $groups ) as $k ) {
			$parent[ $k ] = $k;
		}
		$find = function ( $x ) use ( &$parent ) {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ];
				$x            = $parent[ $x ];
			}
			return $x;
		};
		$buckets = array();
		foreach ( array_keys( $groups ) as $k ) {
			if ( strlen( $k ) >= 7 ) {
				$buckets[ substr( $k, 0, 3 ) . '|' . substr( $k, -3 ) ][] = $k;
			}
		}
		foreach ( $buckets as $list ) {
			$c = count( $list );
			if ( $c < 2 || $c > 300 ) {
				continue;
			}
			for ( $i = 0; $i < $c; $i++ ) {
				for ( $j = $i + 1; $j < $c; $j++ ) {
					if ( abs( strlen( $list[ $i ] ) - strlen( $list[ $j ] ) ) <= 1 && levenshtein( $list[ $i ], $list[ $j ] ) <= 1 ) {
						$parent[ $find( $list[ $j ] ) ] = $find( $list[ $i ] );
					}
				}
			}
		}
		$clusters = array();
		foreach ( $groups as $k => $spell ) {
			$clusters[ $find( $k ) ] = array_merge( isset( $clusters[ $find( $k ) ] ) ? $clusters[ $find( $k ) ] : array(), $spell );
		}
		$out = array();
		foreach ( $clusters as $root => $spell ) {
			if ( count( $spell ) < 2 ) {
				continue;
			}
			usort( $spell, function ( $x, $y ) use ( $count ) {
				return $count[ $y ] <=> $count[ $x ];
			} );
			$tot   = 0;
			$parts = array();
			foreach ( $spell as $s ) {
				$tot    += $count[ $s ];
				$parts[] = '« ' . $s . ' » (' . $count[ $s ] . ')';
			}
			$out[] = array( $tot, $root, mb_substr( implode( ' ; ', array_slice( $parts, 0, 6 ) ) . ( count( $parts ) > 6 ? ' …' : '' ), 0, 600 ) );
		}
		usort( $out, function ( $x, $y ) {
			return $y[0] <=> $x[0];
		} );
		foreach ( array_slice( $out, 0, 1000 ) as $o ) {
			$this->add( 'L01', 'plc-' . substr( md5( $o[1] ), 0, 12 ), '', '', array( 'list' => $o[2] ) );
		}
		$this->next_phase();
	}
}

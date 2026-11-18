<?php
/**
 * Cohérence des données : stockage des résultats dans la base WordPress (jamais dans la base TNG, qui reste en lecture seule),
 * anomalies ignorées, résumé de la dernière analyse.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGCoh_Store {

	const DBVER   = '1';
	const IGNORED = 'tngcoh_ignored';
	const LAST    = 'tngcoh_last';
	const RUN     = 'tngcoh_run';
	const MAXIGN  = 20000;

	public static function table( $n ) {
		global $wpdb;
		return $wpdb->prefix . 'tngs_coh_' . $n;
	}

	/** Crée (ou met à jour) les tables de résultats. */
	public static function install() {
		global $wpdb;
		if ( get_option( 'tngcoh_dbver' ) === self::DBVER ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs  = $wpdb->get_charset_collate();
		$res = self::table( 'res' );
		$key = self::table( 'key' );
		dbDelta(
			"CREATE TABLE {$res} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tree varchar(40) NOT NULL DEFAULT '',
			sig char(32) NOT NULL DEFAULT '',
			code varchar(4) NOT NULL DEFAULT '',
			cat varchar(8) NOT NULL DEFAULT '',
			sev tinyint(1) NOT NULL DEFAULT 0,
			pid varchar(40) NOT NULL DEFAULT '',
			fid varchar(40) NOT NULL DEFAULT '',
			pid2 varchar(40) NOT NULL DEFAULT '',
			score smallint(5) unsigned NOT NULL DEFAULT 0,
			ign tinyint(1) NOT NULL DEFAULT 0,
			txt text NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sig (sig),
			KEY tree_sev (tree,ign,sev),
			KEY tree_cat (tree,cat)
			) {$cs};"
		);
		dbDelta(
			"CREATE TABLE {$key} (
			tree varchar(40) NOT NULL DEFAULT '',
			pid varchar(40) NOT NULL DEFAULT '',
			lk varchar(24) NOT NULL DEFAULT '',
			ln varchar(60) NOT NULL DEFAULT '',
			fn varchar(60) NOT NULL DEFAULT '',
			byr smallint(6) NOT NULL DEFAULT 0,
			dyr smallint(6) NOT NULL DEFAULT 0,
			bd int(11) NOT NULL DEFAULT 0,
			sex char(1) NOT NULL DEFAULT '',
			bp varchar(60) NOT NULL DEFAULT '',
			fa varchar(40) NOT NULL DEFAULT '',
			mo varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (tree,pid),
			KEY lk (tree,lk)
			) {$cs};"
		);
		update_option( 'tngcoh_dbver', self::DBVER, false );
	}

	/** Efface les résultats (et les clés de travail) d'un arbre. */
	public static function clear( $tree ) {
		global $wpdb;
		self::install();
		$wpdb->delete( self::table( 'res' ), array( 'tree' => $tree ), array( '%s' ) );
		$wpdb->delete( self::table( 'key' ), array( 'tree' => $tree ), array( '%s' ) );
		$last = get_option( self::LAST, array() );
		if ( is_array( $last ) && isset( $last[ $tree ] ) ) {
			unset( $last[ $tree ] );
			update_option( self::LAST, $last, false );
		}
	}

	/**
	 * Efface les résultats des seules parties relancées (les autres parties gardent leurs résultats et leur date d'analyse).
	 * Les dates (D01 à D03) viennent des personnes (famille vide) ou des familles (famille renseignée).
	 */
	public static function clear_parts( $tree, array $parts ) {
		global $wpdb;
		self::install();
		$res   = self::table( 'res' );
		$codes = array();
		foreach ( TNGCoh_Rules::part_codes() as $part => $list ) {
			if ( in_array( $part, $parts, true ) ) {
				$codes = array_merge( $codes, $list );
			}
		}
		if ( $codes ) {
			$ph = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$res} WHERE tree = %s AND code IN ({$ph})", array_merge( array( $tree ), $codes ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- table du plugin, marqueurs générés ci-dessus.
		}
		foreach ( array( 'people' => "fid = ''", 'families' => "fid <> ''" ) as $p => $cond ) {
			if ( in_array( $p, $parts, true ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$res} WHERE tree = %s AND code IN ('D01','D02','D03') AND {$cond}", $tree ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table du plugin, condition fixe.
			}
		}
		if ( in_array( 'people', $parts, true ) ) {
			$wpdb->delete( self::table( 'key' ), array( 'tree' => $tree ), array( '%s' ) );
		}
		$last = get_option( self::LAST, array() );
		if ( is_array( $last ) && isset( $last[ $tree ] ) ) {
			$prev = isset( $last[ $tree ]['parts'] ) && is_array( $last[ $tree ]['parts'] ) ? $last[ $tree ]['parts'] : array_fill_keys( array_keys( TNGCoh_Rules::parts() ), (int) $last[ $tree ]['ts'] );
			foreach ( $parts as $p ) {
				unset( $prev[ $p ] );
			}
			$last[ $tree ]['parts'] = $prev;
			update_option( self::LAST, $last, false );
		}
	}

	public static function sig( $tree, $code, $a, $b, $c ) {
		return md5( $tree . '|' . $code . '|' . $a . '|' . $b . '|' . $c );
	}

	public static function ignored() {
		$i = get_option( self::IGNORED, array() );
		return is_array( $i ) ? $i : array();
	}

	/** Insère des anomalies (INSERT IGNORE : la signature est unique). Lignes : tableaux associatifs. */
	public static function insert( array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}
		$t    = self::table( 'res' );
		$vals = array();
		foreach ( $rows as $r ) {
			$vals[] = $wpdb->prepare(
				'(%s,%s,%s,%s,%d,%s,%s,%s,%d,%d,%s)',
				$r['tree'],
				$r['sig'],
				$r['code'],
				$r['cat'],
				$r['sev'],
				$r['pid'],
				$r['fid'],
				$r['pid2'],
				$r['score'],
				$r['ign'],
				$r['txt']
			);
		}
		foreach ( array_chunk( $vals, 200 ) as $chunk ) {
			$wpdb->query( "INSERT IGNORE INTO {$t} (tree,sig,code,cat,sev,pid,fid,pid2,score,ign,txt) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- valeurs déjà passées par $wpdb->prepare() ci-dessus.
		}
	}

	/** Conditions SQL communes (arbre, ignorées, type, gravité, recherche). */
	private static function where( $tree, array $f, $ign ) {
		global $wpdb;
		$w = array( $wpdb->prepare( 'tree = %s', $tree ), $wpdb->prepare( 'ign = %d', $ign ? 1 : 0 ) );
		if ( ! empty( $f['cat'] ) && isset( TNGCoh_Rules::cats()[ $f['cat'] ] ) ) {
			$w[] = $wpdb->prepare( 'cat = %s', $f['cat'] );
		}
		if ( ! empty( $f['code'] ) && isset( TNGCoh_Rules::rules()[ $f['code'] ] ) ) {
			$w[] = $wpdb->prepare( 'code = %s', $f['code'] );
		}
		if ( isset( $f['sev'] ) && '' !== $f['sev'] && in_array( (int) $f['sev'], array( 0, 1, 2 ), true ) ) {
			$w[] = $wpdb->prepare( 'sev = %d', (int) $f['sev'] );
		}
		if ( ! empty( $f['q'] ) ) {
			$like = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$w[]  = $wpdb->prepare( '(pid LIKE %s OR fid LIKE %s OR pid2 LIKE %s OR txt LIKE %s)', $like, $like, $like, $like );
		}
		return implode( ' AND ', $w );
	}

	public static function count( $tree, array $f, $ign = false ) {
		global $wpdb;
		$t = self::table( 'res' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE " . self::where( $tree, $f, $ign ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- conditions construites par where() avec $wpdb->prepare().
	}

	/** Page de résultats triée : tri parmi sev, score, cat, code, pid (liste blanche). */
	public static function query( $tree, array $f, $orderby, $order, $offset, $limit, $ign = false ) {
		global $wpdb;
		$t   = self::table( 'res' );
		$dir = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		switch ( $orderby ) {
			case 'score':
				$ob = "score {$dir}, sev DESC, id ASC";
				break;
			case 'cat':
				$ob = "cat {$dir}, sev DESC, id ASC";
				break;
			case 'code':
				$ob = "code {$dir}, id ASC";
				break;
			case 'pid':
				$ob = "pid {$dir}, id ASC";
				break;
			default:
				$ob = "sev {$dir}, score DESC, id ASC";
		}
		$sql = "SELECT * FROM {$t} WHERE " . self::where( $tree, $f, $ign ) . " ORDER BY {$ob}";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $rows ? $rows : array();
	}

	/** Effectifs par gravité (hors anomalies ignorées) : [ 2 => n, 1 => n, 0 => n ]. */
	public static function counts( $tree ) {
		global $wpdb;
		$t   = self::table( 'res' );
		$out = array( 2 => 0, 1 => 0, 0 => 0 );
		$r   = $wpdb->get_results( $wpdb->prepare( "SELECT sev, COUNT(*) n FROM {$t} WHERE tree = %s AND ign = 0 GROUP BY sev", $tree ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $r as $x ) {
			$out[ (int) $x['sev'] ] = (int) $x['n'];
		}
		return $out;
	}

	/** Effectifs par type (hors anomalies ignorées) : [ catégorie => n ]. */
	public static function cat_counts( $tree ) {
		global $wpdb;
		$t   = self::table( 'res' );
		$out = array();
		$r   = $wpdb->get_results( $wpdb->prepare( "SELECT cat, COUNT(*) n FROM {$t} WHERE tree = %s AND ign = 0 GROUP BY cat", $tree ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table du plugin.
		foreach ( (array) $r as $x ) {
			$out[ (string) $x['cat'] ] = (int) $x['n'];
		}
		return $out;
	}

	/** Effectifs par contrôle (hors anomalies ignorées) : [ code => n ]. */
	public static function code_counts( $tree ) {
		global $wpdb;
		$t   = self::table( 'res' );
		$out = array();
		$r   = $wpdb->get_results( $wpdb->prepare( "SELECT code, COUNT(*) n FROM {$t} WHERE tree = %s AND ign = 0 GROUP BY code", $tree ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $r as $x ) {
			$out[ (string) $x['code'] ] = (int) $x['n'];
		}
		return $out;
	}

	public static function by_sig( $sig ) {
		global $wpdb;
		$t = self::table( 'res' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE sig = %s", $sig ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ? $r : null;
	}

	/** Ignore une anomalie (la ligne et la liste durable des anomalies ignorées). */
	public static function ignore( $sig ) {
		global $wpdb;
		$row = self::by_sig( $sig );
		if ( ! $row ) {
			return false;
		}
		$wpdb->update( self::table( 'res' ), array( 'ign' => 1 ), array( 'sig' => $sig ), array( '%d' ), array( '%s' ) );
		$list = self::ignored();
		if ( count( $list ) < self::MAXIGN ) {
			$p            = json_decode( (string) $row['txt'], true );
			$list[ $sig ] = array(
				't' => (string) $row['tree'],
				'c' => (string) $row['code'],
				'm' => mb_substr( TNGCoh_Rules::describe( $row['code'], is_array( $p ) ? $p : array() ), 0, 300 ),
				'p' => (string) $row['pid'] . ( '' !== $row['fid'] ? ' / ' . $row['fid'] : '' ),
				's' => time(),
			);
			update_option( self::IGNORED, $list, false );
		}
		return true;
	}

	/** Remet une anomalie ignorée dans la liste (sig vide = toutes). */
	public static function restore( $sig ) {
		global $wpdb;
		$list = self::ignored();
		$res  = self::table( 'res' );
		if ( '' === $sig ) {
			$wpdb->query( "UPDATE {$res} SET ign = 0 WHERE ign = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			delete_option( self::IGNORED );
			return;
		}
		$wpdb->update( $res, array( 'ign' => 0 ), array( 'sig' => $sig ), array( '%d' ), array( '%s' ) );
		unset( $list[ $sig ] );
		update_option( self::IGNORED, $list, false );
	}

	public static function last() {
		$l = get_option( self::LAST, array() );
		return is_array( $l ) ? $l : array();
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::table( 'res' ) ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::table( 'key' ) ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

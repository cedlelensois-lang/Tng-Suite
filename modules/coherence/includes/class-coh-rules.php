<?php
/**
 * Cohérence des données : catalogue des contrôles, réglages (seuils) et outils de dates et de noms.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGCoh_Rules {

	const OPT = 'tngcoh_options';

	const ERR  = 2;
	const WARN = 1;
	const INFO = 0;

	/** Seuils réglables (valeurs par défaut). */
	public static function defaults() {
		return array(
			'mother_min'    => 12,   // âge minimal d'une mère à la naissance d'un enfant.
			'mother_max'    => 50,   // âge maximal.
			'father_min'    => 13,
			'father_max'    => 75,
			'marr_min'      => 15,   // âge minimal au mariage.
			'age_max'       => 105,  // âge maximal au décès.
			'living_max'    => 110,  // personne « vivante » née il y a plus de N ans.
			'posthumous'    => 9,    // enfant né plus de N mois après le décès du père.
			'tol'           => 2,    // tolérance (années) pour les dates approximatives (vers, avant, après…).
			'dup_min'       => 70,   // score minimal (0 à 100) pour signaler un doublon possible.
			'loops'         => 1,    // recherche des boucles d'ascendance.
			'loop_depth'    => 40,
			'max_rule'      => 5000, // nombre maximal d'anomalies conservées par contrôle.
		);
	}

	/** Bornes (min, max) de chaque seuil. */
	private static function bounds() {
		return array(
			'mother_min' => array( 8, 25 ), 'mother_max' => array( 35, 70 ),
			'father_min' => array( 8, 30 ), 'father_max' => array( 45, 100 ),
			'marr_min'   => array( 8, 30 ), 'age_max' => array( 80, 130 ),
			'living_max' => array( 80, 150 ), 'posthumous' => array( 9, 24 ),
			'tol'        => array( 0, 10 ), 'dup_min' => array( 30, 100 ),
			'loop_depth' => array( 5, 100 ), 'max_rule' => array( 100, 50000 ),
		);
	}

	public static function options() {
		$o = get_option( self::OPT, array() );
		return self::clean( is_array( $o ) ? $o : array() );
	}

	/** Réglages assainis : entiers bornés, valeurs par défaut pour toute valeur manquante. */
	public static function clean( array $in ) {
		$d = self::defaults();
		$b = self::bounds();
		$o = array();
		foreach ( $d as $k => $dv ) {
			if ( isset( $b[ $k ] ) ) {
				$v       = isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? (int) $in[ $k ] : $dv;
				$o[ $k ] = max( $b[ $k ][0], min( $b[ $k ][1], $v ) );
			}
		}
		$o['loops'] = array_key_exists( 'loops', $in ) ? ( empty( $in['loops'] ) ? 0 : 1 ) : 1;   // le formulaire envoie toujours 0 ou 1.
		return $o;
	}

	/** Contrôles : code => [ type, gravité, libellé du modèle de message (« {x} » = valeurs insérées) ]. */
	public static function rules() {
		return array(
			// Dates.
			'D01' => array( 'date', self::ERR, 'Date impossible : « {txt} » ({field})' ),
			'D02' => array( 'date', self::INFO, 'Date non reconnue : « {txt} » ({field})' ),
			'D03' => array( 'date', self::ERR, 'Date dans le futur : {txt} ({field})' ),
			'D04' => array( 'date', self::ERR, 'Décès ({d2}) avant la naissance ({d1})' ),
			'D05' => array( 'date', self::ERR, 'Baptême ({d2}) avant la naissance ({d1})' ),
			'D06' => array( 'date', self::ERR, 'Sépulture ({d2}) avant le décès ({d1})' ),
			'D07' => array( 'date', self::WARN, 'Âge au décès de {age} ans, au-delà de {max} ans' ),
			'D08' => array( 'date', self::WARN, 'Marquée « vivante » alors qu’elle est née il y a {age} ans (limite : {max} ans)' ),
			'D09' => array( 'date', self::WARN, 'Marquée « vivante » alors qu’un décès est renseigné ({d1})' ),
			'D10' => array( 'date', self::ERR, 'Mariage ({d2}) avant la naissance de {name2} ({d1})' ),
			'D11' => array( 'date', self::ERR, 'Mariage ({d2}) après le décès de {name2} ({d1})' ),
			'D12' => array( 'date', self::WARN, '{name2} se marie à {age} ans (âge minimal attendu : {min} ans)' ),
			'D13' => array( 'date', self::ERR, '{name} est né(e) ({d2}) avant la naissance de son parent {name2} ({d1})' ),
			'D14' => array( 'date', self::ERR, '{name} est né(e) ({d2}) après le décès de sa mère {name2} ({d1})' ),
			'D15' => array( 'date', self::ERR, '{name} est né(e) ({d2}) plus de {months} mois après le décès de son père {name2} ({d1})' ),
			'D16' => array( 'date', self::WARN, 'La mère {name2} avait {age} ans à la naissance de {name} (âge attendu : de {min} à {max} ans)' ),
			'D17' => array( 'date', self::WARN, 'Le père {name2} avait {age} ans à la naissance de {name} (âge attendu : de {min} à {max} ans)' ),
			'D18' => array( 'date', self::ERR, 'Divorce ({d2}) avant le mariage ({d1})' ),
			// Sexe.
			'S01' => array( 'sex', self::WARN, '{name} est indiqué comme père (époux) mais est de sexe féminin' ),
			'S02' => array( 'sex', self::WARN, '{name} est indiquée comme mère (épouse) mais est de sexe masculin' ),
			'S03' => array( 'sex', self::INFO, 'Le sexe de {name} est inconnu alors qu’il ou elle a des enfants' ),
			'S04' => array( 'sex', self::INFO, 'Couple de même sexe : {name} et {name2}' ),
			'S05' => array( 'sex', self::INFO, 'Le prénom « {txt} » est généralement {typ}, mais le sexe renseigné est {sex}' ),
			// Doublons.
			'P01' => array( 'dup', self::WARN, 'Doublon possible avec {name2} ({pid2}) : {why}' ),
			'P02' => array( 'dup', self::WARN, 'Dans cette famille, les enfants {name} et {name2} semblent être la même personne : {why}' ),
			// Structure.
			'T01' => array( 'struct', self::INFO, 'Personne sans aucun lien : ni parents, ni conjoint, ni enfant' ),
			'T02' => array( 'struct', self::ERR, 'Boucle d’ascendance : {name} est son propre ancêtre' ),
			'T03' => array( 'struct', self::ERR, '{name} est son propre conjoint' ),
			'T04' => array( 'struct', self::WARN, 'Famille sans époux ni épouse, mais avec {n} enfant(s)' ),
			'T05' => array( 'struct', self::INFO, 'Famille vide : ni époux, ni épouse, ni enfant' ),
			'T06' => array( 'struct', self::WARN, '{name} est enfant naturel de plusieurs familles : {list}' ),
			'T07' => array( 'struct', self::ERR, '{name} est à la fois parent et enfant de cette famille' ),
			'T08' => array( 'struct', self::ERR, '{name} est enregistré(e) deux fois comme enfant de cette famille' ),
			'T09' => array( 'struct', self::WARN, 'Le couple {name} et {name2} apparaît dans plusieurs familles : {list}' ),
			'T10' => array( 'struct', self::INFO, 'Nom de famille écrit en minuscules : « {txt} »' ),
			'T11' => array( 'struct', self::INFO, 'Espaces superflus dans le nom : « {txt} »' ),
			'T12' => array( 'struct', self::INFO, 'Chiffre dans le nom : « {txt} »' ),
			'T13' => array( 'struct', self::INFO, 'Personne sans nom ni prénom' ),
			'T14' => array( 'struct', self::WARN, 'Référence à une personne qui n’existe pas dans la base : {pid2}' ),
			'T15' => array( 'struct', self::INFO, 'Point d’interrogation dans le nom (lecture incertaine) : « {txt} »' ),
			// Lieux.
			'L01' => array( 'place', self::INFO, 'Lieu écrit de plusieurs façons : {list}' ),
		);
	}

	/** Intitulé court de chaque contrôle (liste de filtre et colonne « Anomalie »). */
	public static function titles() {
		return array(
			'D01' => 'Date impossible',
			'D02' => 'Date non reconnue',
			'D03' => 'Date dans le futur',
			'D04' => 'Décès avant la naissance',
			'D05' => 'Baptême avant la naissance',
			'D06' => 'Sépulture avant le décès',
			'D07' => 'Âge au décès excessif',
			'D08' => 'Personne « vivante » trop âgée',
			'D09' => 'Personne « vivante » avec un décès',
			'D10' => 'Mariage avant la naissance d’un époux',
			'D11' => 'Mariage après le décès d’un époux',
			'D12' => 'Âge trop jeune au mariage',
			'D13' => 'Enfant né avant son parent',
			'D14' => 'Enfant né après le décès de sa mère',
			'D15' => 'Enfant né longtemps après le décès du père',
			'D16' => 'Âge de la mère à la naissance',
			'D17' => 'Âge du père à la naissance',
			'D18' => 'Divorce avant le mariage',
			'S01' => 'Père de sexe féminin',
			'S02' => 'Mère de sexe masculin',
			'S03' => 'Sexe inconnu avec enfants',
			'S04' => 'Couple de même sexe',
			'S05' => 'Prénom du sexe opposé',
			'P01' => 'Doublon possible',
			'P02' => 'Enfants en double dans une famille',
			'T01' => 'Personne sans lien',
			'T02' => 'Boucle d’ascendance',
			'T03' => 'Personne son propre conjoint',
			'T04' => 'Famille sans époux ni épouse',
			'T05' => 'Famille vide',
			'T06' => 'Enfant de plusieurs familles',
			'T07' => 'Parent et enfant de la même famille',
			'T08' => 'Enfant enregistré deux fois',
			'T09' => 'Couple dans plusieurs familles',
			'T10' => 'Nom de famille en minuscules',
			'T11' => 'Espaces superflus dans le nom',
			'T12' => 'Chiffre dans le nom',
			'T13' => 'Personne sans nom ni prénom',
			'T14' => 'Personne référencée absente de la base',
			'T15' => 'Point d’interrogation dans le nom',
			'L01' => 'Lieu écrit de plusieurs façons',
		);
	}

	public static function cats() {
		return array(
			'date'   => 'Dates',
			'sex'    => 'Sexe',
			'dup'    => 'Doublons',
			'struct' => 'Structure',
			'place'  => 'Lieux',
		);
	}

	public static function sev_labels() {
		return array(
			self::ERR  => 'Erreur',
			self::WARN => 'Avertissement',
			self::INFO => 'Information',
		);
	}

	private static function fields() {
		return array(
			'birth'  => 'naissance',
			'bapt'   => 'baptême',
			'death'  => 'décès',
			'burial' => 'sépulture',
			'marr'   => 'mariage',
			'div'    => 'divorce',
		);
	}

	private static function why_labels() {
		return array(
			'n1' => 'noms identiques',
			'n2' => 'noms proches',
			'b1' => 'même date de naissance',
			'b2' => 'même année de naissance',
			'b3' => 'naissances proches',
			'd1' => 'même année de décès',
			'bp' => 'même lieu de naissance',
			'pa' => 'mêmes parents',
			'pf' => 'même père',
			'pm' => 'même mère',
			'sx' => 'même sexe',
			'fa' => 'même famille',
		);
	}

	/** Message d'une anomalie (texte brut, à échapper à l'affichage) : modèle traduit + valeurs enregistrées. */
	public static function describe( $code, array $p ) {
		$rules = self::rules();
		if ( ! isset( $rules[ $code ] ) ) {
			return $code;
		}
		$t = tngs_t( $rules[ $code ][2] );
		if ( isset( $p['field'] ) ) {
			$f          = self::fields();
			$p['field'] = isset( $f[ $p['field'] ] ) ? tngs_t( $f[ $p['field'] ] ) : (string) $p['field'];
		}
		if ( isset( $p['why'] ) ) {
			$w   = self::why_labels();
			$out = array();
			foreach ( explode( ',', (string) $p['why'] ) as $k ) {
				if ( isset( $w[ $k ] ) ) {
					$out[] = tngs_t( $w[ $k ] );
				}
			}
			$p['why'] = implode( ', ', $out );
		}
		if ( isset( $p['typ'] ) ) {
			$p['typ'] = tngs_t( 'm' === $p['typ'] ? 'masculin' : 'féminin' );
		}
		if ( isset( $p['sex'] ) ) {
			$p['sex'] = tngs_t( 'M' === $p['sex'] ? 'masculin' : ( 'F' === $p['sex'] ? 'féminin' : 'inconnu' ) );
		}
		$rep = array();
		foreach ( $p as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$rep[ '{' . $k . '}' ] = (string) $v;
			}
		}
		return strtr( $t, $rep );
	}

	/* ---------------------------------------------------------------- dates */

	/** Nombre de jours depuis le 1er janvier 1970 (calendrier grégorien proleptique). */
	public static function days( $y, $m, $d ) {
		$y  -= $m <= 2 ? 1 : 0;
		$era = (int) floor( $y / 400 );
		$yoe = $y - $era * 400;
		$doy = (int) floor( ( 153 * ( $m + ( $m > 2 ? -3 : 9 ) ) + 2 ) / 5 ) + $d - 1;
		$doe = $yoe * 365 + (int) floor( $yoe / 4 ) - (int) floor( $yoe / 100 ) + $doy;
		return $era * 146097 + $doe - 719468;
	}

	/** Dernier jour du mois (année bissextile « souple » avant 1900 : le calendrier julien reste possible). */
	public static function month_len( $m, $y ) {
		if ( 2 === $m ) {
			$leap = $y < 1900 ? 0 === $y % 4 : ( 0 === $y % 4 && ( 0 !== $y % 100 || 0 === $y % 400 ) );
			return $leap ? 29 : 28;
		}
		return in_array( $m, array( 4, 6, 9, 11 ), true ) ? 30 : 31;
	}

	/** « AAAA-MM-JJ » (colonnes *datetr de TNG) => [ a, m, j ] (0 = inconnu), ou null. */
	public static function parse_tr( $tr ) {
		if ( ! is_string( $tr ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $tr, $m ) || 0 === (int) $m[1] ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	/** Date avec qualificatif (vers, avant, après, entre…) : l'incertitude est élargie par la tolérance. */
	public static function qualified( $txt ) {
		return (bool) preg_match( '/\b(ABT|ABOUT|EST|CAL|BEF|AFT|BET|FROM|TO|INT|VERS|ENV|AVANT|APRES|APRÈS|ENTRE|CA|CIRCA)\b/iu', (string) $txt );
	}

	/** Intervalle [ premier jour possible, dernier jour possible ] d'une date, ou null si elle est inconnue. */
	public static function range( $tr, $txt, $tol ) {
		$a = self::parse_tr( $tr );
		if ( ! $a ) {
			return null;
		}
		list( $y, $m, $d ) = $a;
		if ( $m < 1 || $m > 12 ) {
			$lo = self::days( $y, 1, 1 );
			$hi = self::days( $y, 12, 31 );
		} elseif ( $d < 1 || $d > self::month_len( $m, $y ) ) {
			$lo = self::days( $y, $m, 1 );
			$hi = self::days( $y, $m, self::month_len( $m, $y ) );
		} else {
			$lo = self::days( $y, $m, $d );
			$hi = $lo;
		}
		if ( self::qualified( $txt ) ) {
			$lo -= (int) round( $tol * 365.25 );
			$hi += (int) round( $tol * 365.25 );
		}
		return array( $lo, $hi );
	}

	private static function months() {
		return array(
			'jan' => 1, 'janv' => 1, 'january' => 1, 'janvier' => 1, 'ene' => 1, 'enero' => 1,
			'feb' => 2, 'fev' => 2, 'fevr' => 2, 'fevrier' => 2, 'february' => 2, 'febrero' => 2,
			'mar' => 3, 'mars' => 3, 'march' => 3, 'marzo' => 3,
			'apr' => 4, 'avr' => 4, 'avril' => 4, 'april' => 4, 'abr' => 4, 'abril' => 4,
			'may' => 5, 'mai' => 5, 'mayo' => 5,
			'jun' => 6, 'juin' => 6, 'june' => 6, 'junio' => 6,
			'jul' => 7, 'juil' => 7, 'juillet' => 7, 'july' => 7, 'julio' => 7,
			'aug' => 8, 'aout' => 8, 'august' => 8, 'ago' => 8, 'agosto' => 8,
			'sep' => 9, 'sept' => 9, 'septembre' => 9, 'september' => 9, 'septiembre' => 9,
			'oct' => 10, 'octobre' => 10, 'october' => 10, 'octubre' => 10,
			'nov' => 11, 'novembre' => 11, 'november' => 11, 'noviembre' => 11,
			'dec' => 12, 'decembre' => 12, 'december' => 12, 'dic' => 12, 'diciembre' => 12,
		);
	}

	/**
	 * Contrôle d'une date saisie en texte : « ok », « impossible » (31 FEB 1850) ou « unknown » (aucune année reconnaissable).
	 */
	public static function check_text( $txt ) {
		$t = trim( (string) $txt );
		if ( '' === $t ) {
			return 'ok';
		}
		$mon = self::months();
		if ( preg_match_all( '/(?<!\d)(\d{1,2})[\s\/\.\-]+([^\s\/\.\-\d]+|\d{1,2})[\s\/\.\-]+(\d{3,4})(?!\d)/u', $t, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$d = (int) $m[1];
				$y = (int) $m[3];
				if ( ctype_digit( $m[2] ) ) {
					$mo = (int) $m[2];
				} else {
					$key = preg_replace( '/[^a-z]/', '', strtolower( remove_accents( $m[2] ) ) );
					if ( ! isset( $mon[ $key ] ) ) {
						continue;
					}
					$mo = $mon[ $key ];
				}
				if ( $mo < 1 || $mo > 12 || $d < 1 || $d > self::month_len( $mo, $y ) ) {
					return 'impossible';
				}
			}
			return 'ok';
		}
		return preg_match( '/\d{3,4}/', $t ) ? 'ok' : 'unknown';
	}

	/** Date à afficher : le texte saisi, sinon la date normalisée. */
	public static function show( $tr, $txt ) {
		$t = trim( (string) $txt );
		if ( '' === $t ) {
			$a = self::parse_tr( $tr );
			if ( ! $a ) {
				return '';
			}
			$t = sprintf( '%04d', $a[0] ) . ( $a[1] ? '-' . sprintf( '%02d', $a[1] ) . ( $a[2] ? '-' . sprintf( '%02d', $a[2] ) : '' ) : '' );
		}
		return mb_strlen( $t ) > 40 ? mb_substr( $t, 0, 40 ) . '…' : $t;
	}

	/* ---------------------------------------------------------------- noms */

	/** « NOM Prénom » (texte brut). */
	public static function name( $ln, $fn ) {
		$ln = trim( (string) $ln );
		$fn = trim( (string) $fn );
		$s  = trim( ( '' !== $ln ? mb_strtoupper( $ln ) : '' ) . ' ' . $fn );
		return '' === $s ? '?' : $s;
	}

	/**
	 * Convention généalogique, pas une erreur : nom ou prénom inconnu (« ? », « ??? », « … », « - ») ou conjecturé entre crochets (« [Marie] »).
	 */
	public static function convention( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return false;
		}
		return (bool) preg_match( '/^[\s?*._\-…\[\]()]+$/u', $s ) || (bool) preg_match( '/^\[[^\[\]]*\]$/u', $s );
	}

	/** Minuscules, sans accents, lettres et chiffres séparés par une seule espace. */
	public static function norm( $s ) {
		$s = strtolower( remove_accents( mb_strtolower( (string) $s ) ) );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( (string) $s );
	}

	/** Clé phonétique approchée, pour le français : les écritures qui se prononcent pareil donnent la même clé. */
	public static function phon( $s ) {
		$s = self::norm( $s );
		if ( '' === $s ) {
			return '';
		}
		$s = str_replace( ' ', '', $s );
		$r = array(
			'/ph/' => 'f', '/th/' => 't', '/ch/' => 'S', '/sch/' => 'S', '/sh/' => 'S', '/h/' => '',
			'/q[u]?/' => 'k', '/k/' => 'k', '/c(?=[eiy])/' => 's', '/c/' => 'k', '/g(?=[eiy])/' => 'j', '/gu/' => 'g',
			'/eau|au/' => 'o', '/ou/' => 'u', '/ai|ei|ay/' => 'e', '/oi|oy/' => 'wa',
			'/(an|am|en|em)(?=[^aeiouy]|$)/' => 'a', '/(on|om)(?=[^aeiouy]|$)/' => 'o', '/(ain|ein|in|im|un|yn)(?=[^aeiouy]|$)/' => 'i',
			'/y/' => 'i', '/w/' => 'v', '/z/' => 's', '/x/' => 'ks',
			'/(.)\1+/' => '$1',
			'/(?<=.)[stdxzp]+$/' => '',
		);
		foreach ( $r as $re => $to ) {
			$s = preg_replace( $re, $to, $s );
		}
		$s = preg_replace( '/(?<=.)[aeiou]+$/', '', (string) $s );
		return strtolower( (string) $s );
	}

	/** Prénoms usuels masculins / féminins (sans ambiguïté) : [ m => [ prénom => 1 ], f => [ … ] ]. */
	public static function firstnames() {
		static $set = null;
		if ( null === $set ) {
			$d   = include TNGCOH_DIR . 'data/firstnames.php';
			$set = array(
				'm' => array_fill_keys( $d['m'], 1 ),
				'f' => array_fill_keys( $d['f'], 1 ),
			);
		}
		return $set;
	}

	/** Clé de comparaison d'un lieu : « Saint-Denis (Rhône) » et « St Denis, Rhone » donnent la même clé. */
	public static function place_key( $s ) {
		$n = self::norm( $s );
		if ( '' === $n ) {
			return '';
		}
		$w   = explode( ' ', $n );
		$out = array();
		$stop = array( 'de', 'du', 'des', 'la', 'le', 'les', 'sur', 'sous', 'en', 'd', 'l', 'et', 'france' );
		foreach ( $w as $x ) {
			if ( 'saint' === $x ) {
				$x = 'st';
			} elseif ( 'sainte' === $x ) {
				$x = 'ste';
			}
			if ( in_array( $x, $stop, true ) ) {
				continue;
			}
			$out[] = $x;
		}
		return implode( ' ', $out );
	}
}

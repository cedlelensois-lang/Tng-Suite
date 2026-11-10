<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Réglages du module Statistiques : métiers, cache, personnalisation de chaque graphique
 * (titre, libellés, axes, légende, palette). La connexion à la base, le préfixe, l'arbre par
 * défaut et les vivants/privés viennent des réglages communs de TNG Suite.
 */
class TNGStats_Settings {

	const OPT = 'tngstats_options';

	public static function init() {
		self::maybe_import_legacy();
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'tngs_flush_cache', array( __CLASS__, 'flush_cache' ) );
	}

	/** Reprend les réglages propres au module de l'ancien plugin « TNG Stats » (option tngs_options). */
	private static function maybe_import_legacy() {
		if ( false !== get_option( self::OPT, false ) ) {
			return;
		}
		$old = get_option( 'tngs_options', null );
		if ( ! is_array( $old ) ) {
			return;
		}
		$new = array();
		foreach ( array( 'cache_minutes', 'occ_tags', 'occupations' ) as $k ) {
			if ( isset( $old[ $k ] ) ) {
				$new[ $k ] = $old[ $k ];
			}
		}
		add_option( self::OPT, $new );
	}

	/* ------------------------------------------------------------------ Valeurs */

	public static function default_occupations() {
		return <<<'TXT'
# Une catégorie par ligne :  Libellé affiché = mot-clé, mot-clé, ...
# Les mots-clés sont cherchés au début d'un mot, sans tenir compte des accents ni des majuscules.
# La première catégorie qui correspond l'emporte : mettez les plus précises en premier.
Journalier, Ouvrier agricole = journalier, manouvrier, ouvrier agricole, valet de ferme, valet de charrue, brassier, domestique de ferme
Agriculteur, Ingénieur/Conseiller agricole = cultivateur, cultivatrice, laboureur, agriculteur, agricultrice, fermier, fermiere, exploitant agricole, metayer, vigneron, ingenieur agricole, conseiller agricole, jardinier, maraicher, horticulteur
Marchand, Épicier, Commerçant, Grossiste = marchand, epicier, epiciere, commercant, commercante, negociant, grossiste, boutiquier, colporteur, quincaillier, mercier
Ouvrier, Mineur, Chauffeur... = ouvrier, ouvriere, mineur, chauffeur, manoeuvre, mecanicien, forgeron, macon, couvreur
Brodeuse, Confectionneuse, Fileuse, Tisserand... = brodeuse, confectionneuse, fileuse, tisserand, tisserande, tisseur, dentelliere, couturiere, lingere, tailleur, cordier
Employé de bureau = employe, employee, commis, secretaire, comptable, clerc, dactylo, fonctionnaire
Cabaretier/ière = cabaretier, cabaretiere, aubergiste, cafetier, cafetiere, debitant, hotelier, brasseur
Abbé, Curé, Prêtre, Religieux/se, Vicaire = abbe, cure, pretre, vicaire, religieux, religieuse, soeur, chanoine, moine, diacre
Boulanger, Meunier, Pâtissier = boulanger, boulangere, meunier, meuniere, patissier, boucher, charcutier
Médecin, Docteur, Infirmier(ère) = medecin, docteur, infirmier, infirmiere, chirurgien, sage-femme, sage femme, pharmacien, officier de sante
Maire, Adjoint, Échevin = maire, adjoint, echevin, bourgmestre, conseiller municipal, garde champetre
Domestique, Servant, Valet = domestique, servante, servant, valet, bonne, serviteur, femme de chambre
Cadres, Professions intellectuelles, Artistes = ingenieur, avocat, notaire, architecte, artiste, peintre, musicien, cadre, directeur, banquier, journaliste, ecrivain, huissier, greffier
Professeur, Instituteur(trice) = professeur, instituteur, institutrice, maitre d'ecole, maitresse d'ecole, enseignant, regent
Menuisier, Charpentier = menuisier, charpentier, ebeniste, charron, sabotier, tonnelier
TXT;
	}

	public static function defaults() {
		return array(
			'cache_minutes' => 60,
			'occ_tags'      => 'OCCU',
			'occupations'   => self::default_occupations(),
			'palette'       => 'native', // palette de tous les graphiques (identifiant de preset ou « custom »).
			'custom_colors' => array(),  // palette personnalisée globale.
			'charts'        => array(),  // personnalisation par graphique.
		);
	}

	public static function get( $key ) {
		$o = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	/* --- Connexion et réglages communs (cœur de TNG Suite) --- */

	public static function prefix() {
		return tngs_prefix();
	}

	public static function default_tree() {
		return tngs_gedcom();
	}

	/** @return wpdb|WP_Error */
	public static function db() {
		$db = tngs_db();
		if ( $db ) {
			return $db;
		}
		return new WP_Error(
			'tngstats_conn',
			current_user_can( 'manage_options' ) ? tngs_db_error_message() : 'Connexion à la base TNG impossible.'
		);
	}

	/* ------------------------------------------------------------------ Palettes */

	/** Palettes proposées : identifiant => [ libellé, couleurs ]. « native » = couleurs d'origine de chaque graphique. */
	public static function presets() {
		return array(
			'native'      => array( 'Couleurs d\'origine', array() ),
			'classic'     => array( 'Classique', array( '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac' ) ),
			'pastel'      => array( 'Pastel', array( '#a6cee3', '#b2df8a', '#fb9a99', '#fdbf6f', '#cab2d6', '#ffff99', '#8dd3c7', '#fccde5', '#d9d9d9', '#bc80bd' ) ),
			'vivid'       => array( 'Vive', array( '#e41a1c', '#377eb8', '#4daf4a', '#984ea3', '#ff7f00', '#ffd92f', '#a65628', '#f781bf', '#17becf', '#999999' ) ),
			'colorblind'  => array( 'Daltonien (Okabe-Ito)', array( '#0072b2', '#e69f00', '#009e73', '#d55e00', '#cc79a7', '#56b4e9', '#f0e442', '#000000' ) ),
			'blues'       => array( 'Camaïeu de bleus', array( '#0b3c5d', '#1d6fa5', '#328cc1', '#5aa9d6', '#8cc5e8', '#b9dcf2', '#d9ecf9', '#eef6fc' ) ),
			'earth'       => array( 'Terre', array( '#7f5539', '#b08968', '#ddb892', '#9c6644', '#606c38', '#a3b18a', '#588157', '#e6ccb2' ) ),
			'greys'       => array( 'Gris', array( '#111827', '#374151', '#6b7280', '#9ca3af', '#d1d5db' ) ),
		);
	}

	/** Couleurs valides (#rrggbb ou #rgb) d'une liste. */
	private static function clean_colors( $list, $max ) {
		$out = array();
		foreach ( (array) $list as $c ) {
			$c = sanitize_hex_color( trim( (string) $c ) );
			if ( $c ) {
				$out[] = $c;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Palette effective d'un graphique, ou null pour garder ses couleurs d'origine.
	 * Ordre : couleurs propres au graphique > palette choisie pour ce graphique > palette globale.
	 */
	public static function palette_for( $key ) {
		$c = self::chart( $key );
		if ( $c['colors'] ) {
			return $c['colors'];
		}
		$id = 'global' === $c['palette'] ? (string) self::get( 'palette' ) : $c['palette'];
		if ( 'custom' === $id ) {
			$list = self::clean_colors( self::get( 'custom_colors' ), 10 );
			return $list ? $list : null;
		}
		$p = self::presets();
		return ( isset( $p[ $id ] ) && $p[ $id ][1] ) ? $p[ $id ][1] : null;
	}

	/* ------------------------------------------------------------------ Personnalisation d'un graphique */

	public static function chart_defaults() {
		return array(
			'title'      => '',
			'type'       => '',
			'labels'     => '',
			'xTitle'     => '',
			'yTitle'     => '',
			'note'       => '',
			'legend'     => 'auto',
			'legend_pos' => 'auto',
			'palette'    => 'global',
			'colors'     => array(),
		);
	}

	public static function chart( $key ) {
		$all = self::get( 'charts' );
		$c   = ( is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : array();
		return array_merge( self::chart_defaults(), $c );
	}

	/** « Ancien = Nouveau » (une paire par ligne) => tableau de remplacement. */
	public static function label_map( $text ) {
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$pos = strpos( $line, '=' );
			if ( false === $pos ) {
				continue;
			}
			$from = trim( substr( $line, 0, $pos ) );
			$to   = trim( substr( $line, $pos + 1 ) );
			if ( '' !== $from && '' !== $to ) {
				$map[ $from ] = $to;
			}
		}
		return $map;
	}

	/* ------------------------------------------------------------------ Métiers */

	public static function norm( $s ) {
		$s = remove_accents( (string) $s );
		$s = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/** @return array de [ libellé, regex ] */
	public static function categories() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'occupations' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$pos = strpos( $line, '=' );
			if ( false === $pos ) {
				continue;
			}
			$label = trim( substr( $line, 0, $pos ) );
			$kws   = array();
			foreach ( explode( ',', substr( $line, $pos + 1 ) ) as $k ) {
				$k = self::norm( trim( $k ) );
				if ( '' !== $k ) {
					$kws[] = preg_quote( $k, '/' );
				}
			}
			if ( '' !== $label && $kws ) {
				$out[] = array( $label, '/\b(?:' . implode( '|', $kws ) . ')/u' );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Enregistrement */

	public static function register() {
		register_setting(
			'tngstats_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function flush_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_tngstats_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_tngstats_' ) . '%'
			)
		);
	}

	/**
	 * Chaque page du module envoie sa section (_section) : on ne modifie que ce qu'elle contient.
	 */
	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		$sec = isset( $in['_section'] ) ? (string) $in['_section'] : '';

		// WordPress peut repasser une valeur déjà nettoyée (sans « _section ») dans cette fonction : on la conserve.
		if ( '' === $sec ) {
			return array_merge( $out, array_intersect_key( $in, $out ) );
		}

		if ( 'general' === $sec ) {
			$out['cache_minutes'] = max( 0, min( 1440, (int) ( isset( $in['cache_minutes'] ) ? $in['cache_minutes'] : 60 ) ) );
			$tags                 = preg_replace( '/[^A-Za-z0-9_,]/', '', isset( $in['occ_tags'] ) ? (string) $in['occ_tags'] : 'OCCU' );
			$out['occ_tags']      = '' !== trim( $tags, ',' ) ? $tags : 'OCCU';
			$occ                  = isset( $in['occupations'] ) ? sanitize_textarea_field( $in['occupations'] ) : '';
			$out['occupations']   = '' !== trim( $occ ) ? $occ : self::default_occupations();
			self::flush_cache();
		}

		if ( 'charts' === $sec ) {
			$presets = self::presets();
			$pal     = isset( $in['palette'] ) ? (string) $in['palette'] : 'native';
			$out['palette']       = ( isset( $presets[ $pal ] ) || 'custom' === $pal ) ? $pal : 'native';
			$out['custom_colors'] = self::clean_colors( isset( $in['custom_colors'] ) ? $in['custom_colors'] : array(), 10 );

			$charts = array();
			foreach ( TNGStats_Shortcodes::stats() as $key => $def ) {
				$ci = isset( $in['charts'][ $key ] ) && is_array( $in['charts'][ $key ] ) ? $in['charts'][ $key ] : array();
				$c  = self::chart_defaults();

				$c['title'] = sanitize_text_field( isset( $ci['title'] ) ? $ci['title'] : '' );
				$type       = isset( $ci['type'] ) ? (string) $ci['type'] : '';
				$c['type']  = in_array( $type, $def['types'], true ) ? $type : '';

				// Remplacements de libellés : 80 lignes maximum, uniquement les lignes « a = b ».
				$lines = array();
				foreach ( preg_split( '/\r\n|\r|\n/', isset( $ci['labels'] ) ? sanitize_textarea_field( $ci['labels'] ) : '' ) as $l ) {
					if ( false !== strpos( $l, '=' ) ) {
						$lines[] = trim( $l );
					}
					if ( count( $lines ) >= 80 ) {
						break;
					}
				}
				$c['labels'] = implode( "\n", $lines );

				$c['xTitle'] = sanitize_text_field( isset( $ci['xTitle'] ) ? $ci['xTitle'] : '' );
				$c['yTitle'] = sanitize_text_field( isset( $ci['yTitle'] ) ? $ci['yTitle'] : '' );
				$c['note']   = sanitize_text_field( isset( $ci['note'] ) ? $ci['note'] : '' );

				$leg          = isset( $ci['legend'] ) ? (string) $ci['legend'] : 'auto';
				$c['legend']  = in_array( $leg, array( 'auto', 'show', 'hide' ), true ) ? $leg : 'auto';
				$lp           = isset( $ci['legend_pos'] ) ? (string) $ci['legend_pos'] : 'auto';
				$c['legend_pos'] = in_array( $lp, array( 'auto', 'top', 'right', 'bottom', 'left' ), true ) ? $lp : 'auto';

				$pc           = isset( $ci['palette'] ) ? (string) $ci['palette'] : 'global';
				$c['palette'] = ( 'global' === $pc || 'custom' === $pc || isset( $presets[ $pc ] ) ) ? $pc : 'global';
				$c['colors']  = self::clean_colors( isset( $ci['colors'] ) ? $ci['colors'] : array(), 8 );

				$charts[ $key ] = $c;
			}
			$out['charts'] = $charts;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ Administration */

	public static function menu() {
		tngs_add_subpage( 'Statistiques', 'Stats : réglages', 'tng-stats', array( __CLASS__, 'page' ) );
		tngs_add_subpage( 'Statistiques : graphiques', 'Stats : graphiques', 'tng-stats-charts', array( __CLASS__, 'page_charts' ) );
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'tng-stats-charts' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', "jQuery(function($){ $('.tngstats-color').wpColorPicker(); });" );
	}

	/** Pastilles de couleur (aperçu d'une palette). */
	private static function swatches( array $colors ) {
		$h = '';
		foreach ( $colors as $c ) {
			$h .= '<span style="display:inline-block;width:16px;height:16px;margin-right:2px;border:1px solid #c3c4c7;background:' . esc_attr( $c ) . '"></span>';
		}
		return $h;
	}

	private static function privacy_line() {
		return '<p>Vivants / privés : <strong>' . ( tngs_hide_living( 'stats' ) ? 'masqués' : '⚠️ affichés (RGPD)' ) . '</strong> — réglage dans <a href="'
			. esc_url( tngs_page_url( 'tng-suite' ) ) . '">TNG Suite &gt; Général &gt; Modules</a>. Seuls des totaux sont publiés ; « masqués » cache le nom des personnes privées dans la liste des centenaires.</p>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o   = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		$n   = self::OPT;
		$msg = '';
		$ok  = false;

		$db = self::db();
		if ( is_wp_error( $db ) ) {
			$msg = $db->get_error_message();
		} else {
			$q = new TNGStats_Queries( $db, self::prefix(), '', true );
			try {
				$trees = $q->trees();
				$ok    = true;
				$msg   = $trees
					? tngs_t( 'Connexion OK. Arbres détectés :' ) . ' ' . implode( ', ', array_map( function ( $t ) {
						return $t['id'] . ( $t['name'] !== $t['id'] ? ' (' . $t['name'] . ')' : '' );
					}, $trees ) ) . '.'
					: 'Connexion OK, mais aucun arbre trouvé dans la table trees.';
			} catch ( Exception $e ) {
				$msg = 'Lecture impossible (voir TNG Suite > Général).';
				error_log( '[TNG Suite / Stats] ' . $e->getMessage() );
			}
		}
		?>
		<?php tngs_admin_header( 'Statistiques', array( 'module' => 'stats', 'status' => array( $ok, $msg ) ) ); ?>
			<p>Titres, libellés, légendes et couleurs de chaque graphique : <a href="<?php echo esc_url( tngs_page_url( 'tng-stats-charts' ) ); ?>">Stats : graphiques</a>.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'tngstats_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[_section]" value="general">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="tngstats_cache">Cache (minutes)</label></th>
						<td><input id="tngstats_cache" type="number" min="0" max="1440" name="<?php echo esc_attr( $n ); ?>[cache_minutes]" value="<?php echo esc_attr( $o['cache_minutes'] ); ?>"> <span class="description">0 = pas de cache. Vidé à chaque enregistrement.</span></td></tr>
					<tr><th scope="row"><label for="tngstats_tags">Types d'événement « profession »</label></th>
						<td><input id="tngstats_tags" class="regular-text" type="text" name="<?php echo esc_attr( $n ); ?>[occ_tags]" value="<?php echo esc_attr( $o['occ_tags'] ); ?>"> <span class="description">Balises GEDCOM séparées par des virgules (par défaut <code>OCCU</code>).</span></td></tr>
					<tr><th scope="row"><label for="tngstats_occ">Catégories de métiers</label></th>
						<td>
							<textarea id="tngstats_occ" name="<?php echo esc_attr( $n ); ?>[occupations]" rows="16" class="large-text code"><?php echo esc_textarea( $o['occupations'] ); ?></textarea>
							<p class="description">Format : <code>Libellé = mot-clé, mot-clé</code>. Vider le champ restaure les catégories par défaut.</p>
						</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php self::doc(); ?>

			<h2>Professions non classées</h2>
			<?php
			if ( $ok && isset( $_GET['unclassified'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$tree = self::default_tree();
				$q2   = new TNGStats_Queries( $db, self::prefix(), $tree, true );
				try {
					if ( '' === $tree ) {
						$tree = $q2->first_tree();
						$q2   = new TNGStats_Queries( $db, self::prefix(), $tree, true );
					}
					$rows = $q2->unclassified( 40 );
					if ( $rows ) {
						echo '<p>' . esc_html( tngs_t( 'Les 40 intitulés les plus fréquents qui ne correspondent à aucune catégorie' ) ) . ' (' . esc_html( tngs_t( 'arbre' ) ) . ' « ' . esc_html( $tree ) . ' ») :</p><table class="widefat striped" style="max-width:600px"><thead><tr><th>Intitulé</th><th>Mentions</th></tr></thead><tbody>';
						foreach ( $rows as $r ) {
							echo '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . esc_html( number_format_i18n( $r[1] ) ) . '</td></tr>';
						}
						echo '</tbody></table>';
					} else {
						echo '<p>Tous les intitulés sont classés (ou aucune profession trouvée).</p>';
					}
				} catch ( Exception $e ) {
					error_log( '[TNG Suite / Stats] ' . $e->getMessage() );
					echo '<p>Lecture impossible (voir le journal PHP).</p>';
				}
			} else {
				echo '<p><a class="button" href="' . esc_url( add_query_arg( 'unclassified', 1 ) ) . '">Afficher les professions non classées</a> <span class="description">Pratique pour compléter les catégories ci-dessus.</span></p>';
			}
			?>
		</div>
		<?php
	}

	/** Page « Stats : graphiques » : éditeur de chaque graphique. */
	public static function page_charts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		$n       = self::OPT;
		$presets = self::presets();
		$hints   = TNGStats_Shortcodes::label_hints();
		$custom  = array_pad( self::clean_colors( $o['custom_colors'], 10 ), 10, '' );
		?>
		<?php tngs_admin_header( 'Statistiques : graphiques', array( 'module' => 'stats', 'db' => false ) ); ?>
			<p style="max-width:780px">Pour chaque graphique : titre, libellés de la légende, titres d'axes, position de la légende et couleurs. Les réglages s'appliquent immédiatement (sans vider le cache). Les attributs du shortcode (<code>title</code>, <code>type</code>) restent prioritaires.</p>

			<h2>Palettes disponibles</h2>
			<table class="widefat striped" style="max-width:640px"><tbody>
				<?php foreach ( $presets as $id => $p ) : ?>
					<tr><td style="width:220px"><?php echo esc_html( $p[0] ); ?></td><td><?php echo $p[1] ? self::swatches( $p[1] ) : '<span class="description">couleurs choisies par le plugin pour chaque graphique</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>

			<form method="post" action="options.php">
				<?php settings_fields( 'tngstats_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[_section]" value="charts">

				<h2>Palette de tous les graphiques</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tngstats_palette">Palette par défaut</label></th>
						<td>
							<select id="tngstats_palette" name="<?php echo esc_attr( $n ); ?>[palette]">
								<?php foreach ( $presets as $id => $p ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $o['palette'], $id ); ?>><?php echo esc_html( $p[0] ); ?></option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $o['palette'], 'custom' ); ?>>Ma palette personnalisée (ci-dessous)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Ma palette personnalisée</th>
						<td>
							<?php foreach ( $custom as $i => $c ) : ?>
								<input type="text" class="tngstats-color" name="<?php echo esc_attr( $n ); ?>[custom_colors][]" value="<?php echo esc_attr( $c ); ?>">
							<?php endforeach; ?>
							<p class="description">Jusqu'à 10 couleurs, utilisées dans l'ordre (série 1, série 2… ou part 1, part 2…). Laissez vide les couleurs inutiles.</p>
						</td>
					</tr>
				</table>

				<h2>Graphiques</h2>
				<?php foreach ( TNGStats_Shortcodes::stats() as $key => $def ) :
					$c   = self::chart( $key );
					$nm  = $n . '[charts][' . $key . ']';
					$col = array_pad( $c['colors'], 6, '' );
					?>
					<details style="background:#fff;border:1px solid #c3c4c7;padding:8px 14px;margin:0 0 10px;max-width:900px">
						<summary style="cursor:pointer;font-weight:600"><?php echo esc_html( $def['title'] ); ?> <code style="font-weight:400">[<?php echo esc_html( $def['alias'] ); ?>]</code></summary>
						<table class="form-table" role="presentation">
							<tr><th scope="row">Titre</th>
								<td><input type="text" class="large-text" name="<?php echo esc_attr( $nm ); ?>[title]" value="<?php echo esc_attr( $c['title'] ); ?>" placeholder="<?php echo esc_attr( $def['title'] ); ?>">
									<p class="description">Vide = titre d'origine. Saisissez <code>none</code> pour ne pas afficher de titre.</p></td></tr>
							<tr><th scope="row">Type de graphique</th>
								<td><select name="<?php echo esc_attr( $nm ); ?>[type]">
									<option value=""><?php echo esc_html( tngs_t( 'Par défaut' ) . ' (' . tngs_t( (string) $def['default'] ) . ')' ); ?></option>
									<?php foreach ( $def['types'] as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $c['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
									<?php endforeach; ?>
								</select></td></tr>
							<tr><th scope="row">Libellés (légende, tableau)</th>
								<td><textarea name="<?php echo esc_attr( $nm ); ?>[labels]" rows="4" class="large-text code" placeholder="<?php echo esc_attr( ! empty( $hints[ $key ] ) ? "Ancien = Nouveau\n" . $hints[ $key ] : 'Ancien = Nouveau' ); ?>"><?php echo esc_textarea( $c['labels'] ); ?></textarea>
									<p class="description">Une règle par ligne : <code>Ancien libellé = Nouveau libellé</code>.
										<?php if ( ! empty( $hints[ $key ] ) ) : ?>Libellés d'origine : <em><?php echo esc_html( implode( ' ; ', array_map( 'tngs_t', explode( "\n", $hints[ $key ] ) ) ) ); ?></em>.<?php endif; ?></p></td></tr>
							<tr><th scope="row">Titres des axes</th>
								<td>
									<input type="text" name="<?php echo esc_attr( $nm ); ?>[xTitle]" value="<?php echo esc_attr( $c['xTitle'] ); ?>" placeholder="Axe horizontal">
									<input type="text" name="<?php echo esc_attr( $nm ); ?>[yTitle]" value="<?php echo esc_attr( $c['yTitle'] ); ?>" placeholder="Axe vertical">
									<p class="description">Vide = titres d'origine (sans effet sur les camemberts et tableaux).</p>
								</td></tr>
							<tr><th scope="row">Légende</th>
								<td>
									<select name="<?php echo esc_attr( $nm ); ?>[legend]">
										<option value="auto" <?php selected( $c['legend'], 'auto' ); ?>>Automatique</option>
										<option value="show" <?php selected( $c['legend'], 'show' ); ?>>Toujours afficher</option>
										<option value="hide" <?php selected( $c['legend'], 'hide' ); ?>>Masquer</option>
									</select>
									<select name="<?php echo esc_attr( $nm ); ?>[legend_pos]">
										<option value="auto" <?php selected( $c['legend_pos'], 'auto' ); ?>>Position automatique</option>
										<option value="top" <?php selected( $c['legend_pos'], 'top' ); ?>>En haut</option>
										<option value="right" <?php selected( $c['legend_pos'], 'right' ); ?>>À droite</option>
										<option value="bottom" <?php selected( $c['legend_pos'], 'bottom' ); ?>>En bas</option>
										<option value="left" <?php selected( $c['legend_pos'], 'left' ); ?>>À gauche</option>
									</select>
								</td></tr>
							<tr><th scope="row">Note sous le graphique</th>
								<td><input type="text" class="large-text" name="<?php echo esc_attr( $nm ); ?>[note]" value="<?php echo esc_attr( $c['note'] ); ?>">
									<p class="description">Vide = note d'origine (s'il y en a une). <code>none</code> pour la masquer.</p></td></tr>
							<tr><th scope="row">Couleurs</th>
								<td>
									<select name="<?php echo esc_attr( $nm ); ?>[palette]">
										<option value="global" <?php selected( $c['palette'], 'global' ); ?>>Comme la palette de tous les graphiques</option>
										<?php foreach ( $presets as $id => $p ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $c['palette'], $id ); ?>><?php echo esc_html( $p[0] ); ?></option>
										<?php endforeach; ?>
										<option value="custom" <?php selected( $c['palette'], 'custom' ); ?>>Ma palette personnalisée</option>
									</select>
									<p class="description" style="margin-top:8px">Ou couleurs propres à ce graphique (prioritaires, dans l'ordre) :</p>
									<?php foreach ( $col as $cc ) : ?>
										<input type="text" class="tngstats-color" name="<?php echo esc_attr( $nm ); ?>[colors][]" value="<?php echo esc_attr( $cc ); ?>">
									<?php endforeach; ?>
								</td></tr>
						</table>
					</details>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function doc() {
		echo '<h2>Shortcodes</h2>';
		echo '<p>Chaque statistique s\'insère avec son propre shortcode, ou avec <code>[tng_stat stat="…"]</code>. Attributs communs : <code>tree</code>, <code>type</code>, <code>height</code> (px, 380 par défaut), <code>title</code> (texte, ou <code>none</code> pour le masquer), <code>class</code>.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Statistique</th><th>Shortcode</th><th>Types de graphique (<code>type</code>)</th><th>Attributs spécifiques</th></tr></thead><tbody>';
		foreach ( TNGStats_Shortcodes::stats() as $key => $d ) {
			$atts = array();
			foreach ( $d['atts'] as $k => $v ) {
				$atts[] = '<code>' . esc_html( $k ) . '="' . esc_html( $v ) . '"</code>';
			}
			echo '<tr><td>' . esc_html( $d['title'] ) . '<br><small><code>stat="' . esc_html( $key ) . '"</code></small></td>';
			echo '<td><code>[' . esc_html( $d['alias'] ) . ']</code></td>';
			echo '<td>' . esc_html( implode( ', ', $d['types'] ) ) . '<br><small>' . esc_html( tngs_t( 'défaut :' ) ) . ' ' . esc_html( $d['default'] ) . '</small></td>';
			echo '<td>' . ( $atts ? implode( ' ', $atts ) : '—' ) . '<br><small>' . esc_html( $d['help'] ) . '</small></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>Exemple : <code>[tng_stat_genre type="pie" height="300"]</code></p>';
	}
}

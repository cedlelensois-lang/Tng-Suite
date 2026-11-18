<?php
/**
 * Cohérence des données : pages d'administration (réservées aux administrateurs), lancement par lots (AJAX), résultats, export CSV.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNGCoh_Admin {

	const PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_tngcoh_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_tngcoh_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_tngcoh_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'admin_post_tngcoh_ignore', array( __CLASS__, 'post_ignore' ) );
		add_action( 'admin_post_tngcoh_restore', array( __CLASS__, 'post_restore' ) );
		add_action( 'admin_post_tngcoh_clear', array( __CLASS__, 'post_clear' ) );
		add_action( 'admin_post_tngcoh_csv', array( __CLASS__, 'post_csv' ) );
	}

	public static function register() {
		register_setting(
			'tngcoh_group',
			TNGCoh_Rules::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		return TNGCoh_Rules::clean( is_array( $in ) ? $in : array() );
	}

	public static function menu() {
		tngs_add_subpage( 'Cohérence des données', 'Cohérence : contrôle des données', 'tng-coherence', array( __CLASS__, 'page_main' ) );
		tngs_add_subpage( 'Cohérence : seuils et anomalies ignorées', 'Cohérence : seuils et anomalies ignorées', 'tng-coherence-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $page || ( 'analyse' !== tngs_merged_module( $page ) && 0 !== strpos( $page, 'tng-coherence' ) ) ) {
			return;
		}
		wp_enqueue_style( 'tngcoh', TNGCOH_URL . 'assets/coh.css', array(), TNGSUITE_VERSION );
		wp_enqueue_script( 'tngcoh', TNGCOH_URL . 'assets/coh.js', array(), TNGSUITE_VERSION, true );
		wp_add_inline_script(
			'tngcoh',
			'window.TNGCOH = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'tngcoh' ),
					'msg'   => array(
						'starting' => tngs_t( 'Démarrage…' ),
						'done'     => tngs_t( 'Analyse terminée.' ),
						'canceled' => tngs_t( 'Analyse annulée : les résultats sont partiels.' ),
						'error'    => tngs_t( 'Erreur pendant l’analyse :' ),
						'network'  => tngs_t( 'Connexion interrompue. Vous pouvez reprendre l’analyse.' ),
						'found'    => tngs_t( 'anomalie(s) trouvée(s)' ),
						'nopart'   => tngs_t( 'Cochez au moins une partie à analyser.' ),
						'sel'      => tngs_t( 'Parties à analyser' ),
						'of'       => tngs_t( 'sur' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/* ---------------------------------------------------------------- outils */

	private static function ok_tree( $t ) {
		$t = (string) $t;
		return preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $t ) ? $t : '';
	}

	/** Filtres de la liste (adresse de la page ou de l'export) : arbre, type, gravité, recherche, tri, page. */
	private static function filters( array $trees ) {
		$g    = $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- simple lecture de filtres ; l'export vérifie son jeton avant.
		$tree = isset( $g['tngtree'] ) ? self::ok_tree( wp_unslash( $g['tngtree'] ) ) : '';
		if ( '' === $tree || ! isset( $trees[ $tree ] ) ) {
			$def  = tngs_gedcom();
			$last = TNGCoh_Store::last();
			if ( '' !== $def && isset( $trees[ $def ] ) ) {
				$tree = $def;
			} elseif ( $last ) {
				$k    = array_keys( $last );
				$tree = in_array( $k[0], array_keys( $trees ), true ) ? $k[0] : '';
			}
			if ( '' === $tree && $trees ) {
				$k    = array_keys( $trees );
				$tree = (string) $k[0];
			}
		}
		$cat = isset( $g['cat'] ) ? sanitize_key( wp_unslash( $g['cat'] ) ) : '';
		$sev = isset( $g['sev'] ) ? sanitize_text_field( wp_unslash( $g['sev'] ) ) : '';
		$cod = isset( $g['code'] ) ? strtoupper( sanitize_key( wp_unslash( $g['code'] ) ) ) : '';
		$ord = isset( $g['orderby'] ) ? sanitize_key( wp_unslash( $g['orderby'] ) ) : 'sev';
		return array(
			'tree'    => $tree,
			'cat'     => isset( TNGCoh_Rules::cats()[ $cat ] ) ? $cat : '',
			'sev'     => in_array( $sev, array( '0', '1', '2' ), true ) ? $sev : '',
			'code'    => isset( TNGCoh_Rules::rules()[ $cod ] ) ? $cod : '',
			'q'       => isset( $g['q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $g['q'] ) ), 0, 80 ) : '',
			'orderby' => in_array( $ord, array( 'sev', 'score', 'cat', 'code', 'pid' ), true ) ? $ord : 'sev',
			'order'   => ( isset( $g['order'] ) && 'asc' === sanitize_key( wp_unslash( $g['order'] ) ) ) ? 'asc' : 'desc',
			'paged'   => isset( $g['paged'] ) ? max( 1, (int) $g['paged'] ) : 1,
		);
	}

	private static function here() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'tng-coherence'; // phpcs:ignore WordPress.Security.NonceVerification
		return array( admin_url( 'admin.php' ), $page );
	}

	private static function url( array $f, array $over = array() ) {
		list( $base, $page ) = self::here();
		$a                   = array( 'page' => $page, 'tngtree' => $f['tree'] );
		foreach ( array( 'cat', 'code', 'sev', 'q' ) as $k ) {
			if ( '' !== $f[ $k ] ) {
				$a[ $k ] = $f[ $k ];
			}
		}
		if ( 'sev' !== $f['orderby'] || 'desc' !== $f['order'] ) {
			$a['orderby'] = $f['orderby'];
			$a['order']   = $f['order'];
		}
		return add_query_arg( array_merge( $a, $over ), $base ) . '#tng-coherence';
	}

	private static function link( $kind, $id, $tree ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return '';
		}
		$base = tngs_site_url();
		if ( '' === $base || 0 === strpos( $id, 'plc-' ) ) {
			return '<code>' . esc_html( $id ) . '</code>';
		}
		$u = 'F' === $kind
			? add_query_arg( array( 'familyID' => $id, 'tree' => $tree ), $base . 'admin_editfamily.php' )
			: add_query_arg( array( 'personID' => $id, 'tree' => $tree ), $base . 'admin_editperson.php' );
		// Page d'édition de TNG (connexion à l'administration de TNG requise), pour corriger tout de suite l'anomalie.
		return '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( tngs_t( 'Ouvrir la fiche en édition dans TNG' ) ) . '"><code>' . esc_html( $id ) . '</code></a>';
	}

	private static function message( array $row ) {
		$p = json_decode( (string) $row['txt'], true );
		return TNGCoh_Rules::describe( (string) $row['code'], is_array( $p ) ? $p : array() );
	}

	private static function back( $extra = array() ) {
		$to = wp_get_referer();
		if ( ! $to ) {
			$to = admin_url( 'admin.php?page=tng-coherence' );
		}
		wp_safe_redirect( add_query_arg( $extra, $to ) . '#tng-coherence' );
		exit;
	}

	/* ---------------------------------------------------------------- page principale */

	public static function page_main() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		tngs_admin_header( 'Cohérence des données', array( 'module' => '' ) );
		$trees = TNGCoh_Engine::trees();
		$f     = self::filters( $trees );
		$last  = TNGCoh_Store::last();
		$run   = TNGCoh_Engine::state();
		$tree  = $f['tree'];

		echo '<h2>' . esc_html( tngs_t( 'Cohérence des données' ) ) . '</h2>';
		echo '<p>' . esc_html( tngs_t( 'Contrôle en lecture seule de votre arbre TNG : dates impossibles ou incohérentes, sexe, âges, structure des familles et doublons possibles. Rien n’est modifié dans TNG : chaque anomalie renvoie vers la fiche pour que vous la corrigiez vous-même. L’analyse se lance à la main et peut durer plusieurs minutes sur un grand arbre ; ses résultats sont conservés jusqu’à la prochaine analyse.' ) ) . '</p>';
		echo '<p class="description">' . esc_html( tngs_t( 'Page réservée aux administrateurs. Les résultats contiennent des noms de personnes, y compris vivantes ; ils sont stockés dans la base WordPress (jamais dans la base TNG), sans shortcode ni affichage public, et sont effacés par « Effacer les résultats » ou à la désinstallation du plugin.' ) ) . '</p>';

		if ( ! $trees ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( tngs_t( 'Aucun arbre trouvé : vérifiez la connexion à la base TNG (TNG Suite > Général).' ) ) . '</p></div></div>';
			return;
		}

		list( $base, $page ) = self::here();
		echo '<form method="get" action="' . esc_url( $base ) . '#tng-coherence" class="tngcoh-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		echo '<label>' . esc_html( tngs_t( 'Arbre' ) ) . ' <select name="tngtree" id="tngcoh-tree">';
		foreach ( $trees as $id => $nm ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $tree, $id, false ) . '>' . esc_html( $nm . ( $nm !== $id ? ' (' . $id . ')' : '' ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html( tngs_t( 'Type' ) ) . ' <select name="cat"><option value="">' . esc_html( tngs_t( 'Tous' ) ) . '</option>';
		foreach ( TNGCoh_Rules::cats() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['cat'], $k, false ) . '>' . esc_html( tngs_t( $l ) ) . '</option>';
		}
		echo '</select></label> ';
		$cc = TNGCoh_Store::code_counts( $tree );
		echo '<label>' . esc_html( tngs_t( 'Anomalie' ) ) . ' <select name="code"><option value="">' . esc_html( tngs_t( 'Toutes' ) ) . '</option>';
		$ti   = TNGCoh_Rules::titles();
		$cats = TNGCoh_Rules::cats();
		foreach ( $cats as $ck => $cl ) {
			$o = '';
			foreach ( TNGCoh_Rules::rules() as $code => $rule ) {
				if ( $rule[0] === $ck && ! empty( $cc[ $code ] ) ) {
					$o .= '<option value="' . esc_attr( $code ) . '"' . selected( $f['code'], $code, false ) . '>' . esc_html( tngs_t( $ti[ $code ] ) . ' (' . $cc[ $code ] . ')' ) . '</option>';
				}
			}
			if ( '' !== $o ) {
				echo '<optgroup label="' . esc_attr( tngs_t( $cl ) ) . '">' . $o . '</optgroup>'; // phpcs:ignore WordPress.Security.EscapeOutput -- options échappées ci-dessus.
			}
		}
		echo '</select></label> ';
		echo '<label>' . esc_html( tngs_t( 'Gravité' ) ) . ' <select name="sev"><option value="">' . esc_html( tngs_t( 'Toutes' ) ) . '</option>';
		foreach ( TNGCoh_Rules::sev_labels() as $k => $l ) {
			echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( $f['sev'], (string) $k, false ) . '>' . esc_html( tngs_t( $l ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html( tngs_t( 'Recherche' ) ) . ' <input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="' . esc_attr( tngs_t( 'nom, identifiant, texte' ) ) . '"></label> ';
		echo '<button type="submit" class="button">' . esc_html( tngs_t( 'Afficher' ) ) . '</button>';
		echo '</form>';

		// Lancement.
		$running = ( ! empty( $run['status'] ) && 'running' === $run['status'] );
		$has     = isset( $last[ $tree ] );
		echo '<div class="tngcoh-run" id="tngcoh-run"' . ( $running ? ' data-resume="' . esc_attr( (string) $run['token'] ) . '" data-resume-tree="' . esc_attr( (string) $run['tree'] ) . '"' : '' ) . '>';
		$sel   = TNGCoh_Rules::parts_selected();
		$all   = TNGCoh_Rules::parts();
		echo '<details class="tngcoh-parts" id="tngcoh-parts"' . ( count( $sel ) < count( $all ) ? ' open' : '' ) . '><summary>' . esc_html( tngs_t( 'Parties à analyser' ) ) . ' (<span id="tngcoh-nsel">' . (int) count( $sel ) . '</span> ' . esc_html( tngs_t( 'sur' ) ) . ' ' . (int) count( $all ) . ')</summary><p>';
		foreach ( $all as $pk => $pl ) {
			echo '<label class="tngcoh-part-l"><input type="checkbox" class="tngcoh-part" value="' . esc_attr( $pk ) . '"' . ( in_array( $pk, $sel, true ) ? ' checked' : '' ) . '> ' . esc_html( tngs_t( $pl ) ) . '</label><br>';
		}
		echo '</p><p><button type="button" class="button-link" id="tngcoh-all">' . esc_html( tngs_t( 'Tout cocher' ) ) . '</button> · <button type="button" class="button-link" id="tngcoh-none">' . esc_html( tngs_t( 'Tout décocher' ) ) . '</button></p><p class="description">'
			. esc_html( tngs_t( 'Seules les parties cochées sont relancées : les résultats des autres parties sont conservés. Les doublons possibles ont besoin de la partie « Personnes ».' ) ) . '</p></details>';
		echo '<button type="button" class="button button-primary" id="tngcoh-go">' . esc_html( tngs_t( $has ? 'Relancer l’analyse' : 'Lancer l’analyse' ) ) . '</button> ';
		echo '<button type="button" class="button" id="tngcoh-cancel" hidden>' . esc_html( tngs_t( 'Annuler' ) ) . '</button>';
		echo '<div class="tngcoh-bar" id="tngcoh-bar" hidden><div class="tngcoh-fill" id="tngcoh-fill"></div></div>';
		echo '<p class="tngcoh-status" id="tngcoh-status" aria-live="polite">';
		if ( $running ) {
			echo esc_html( tngs_t( 'Une analyse est en cours ou a été interrompue :' ) ) . ' <button type="button" class="button button-small" id="tngcoh-resume">' . esc_html( tngs_t( 'Reprendre' ) ) . '</button>';
		}
		echo '</p></div>';

		if ( ! $has ) {
			echo '<p>' . esc_html( tngs_t( 'Aucun résultat pour cet arbre : lancez l’analyse.' ) ) . '</p></div>';
			return;
		}

		$l   = $last[ $tree ];
		$cnt = TNGCoh_Store::counts( $tree );
		echo '<p class="tngcoh-sum">';
		foreach ( TNGCoh_Rules::sev_labels() as $k => $lab ) {
			echo '<span class="tngcoh-sev tngcoh-sev-' . (int) $k . '">' . esc_html( tngs_t( $lab ) ) . ' : ' . (int) $cnt[ $k ] . '</span> ';
		}
		echo '</p><p class="description">' . esc_html( tngs_t( 'Dernière analyse :' ) ) . ' ' . esc_html( wp_date( 'd/m/Y H:i', (int) $l['ts'] ) ) . ' – '
			. esc_html( number_format_i18n( (int) $l['people'] ) ) . ' ' . esc_html( tngs_t( 'personnes' ) ) . ', ' . esc_html( number_format_i18n( (int) $l['fam'] ) ) . ' ' . esc_html( tngs_t( 'familles' ) )
			. ' – ' . esc_html( tngs_t( 'durée' ) ) . ' ' . (int) $l['dur'] . ' s</p>';
		if ( ! empty( $l['parts'] ) && is_array( $l['parts'] ) ) {
			$bits = array();
			foreach ( TNGCoh_Rules::parts() as $pk => $pl ) {
				$bits[] = esc_html( tngs_t( $pl ) ) . ' : ' . ( isset( $l['parts'][ $pk ] ) ? esc_html( wp_date( 'd/m/Y H:i', (int) $l['parts'][ $pk ] ) ) : '<em>' . esc_html( tngs_t( 'non analysée' ) ) . '</em>' );
			}
			echo '<details class="tngcoh-partsinfo"><summary>' . esc_html( tngs_t( 'Parties analysées' ) ) . '</summary><ul><li>' . implode( '</li><li>', $bits ) . '</li></ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- éléments échappés ci-dessus.
		}
		if ( ! empty( $l['fail'] ) && is_array( $l['fail'] ) ) {
			$fl = array( 'events' => 'Événements', 'cit' => 'Citations de sources', 'src' => 'Sources jamais citées', 'med' => 'Liens vers les médias', 'cov' => 'Part des personnes sans source' );
			foreach ( $l['fail'] as $fk => $fm ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( tngs_t( 'Cette partie n’a pas pu être analysée (tables ou colonnes TNG inattendues) :' ) ) . ' <strong>' . esc_html( tngs_t( isset( $fl[ $fk ] ) ? $fl[ $fk ] : (string) $fk ) ) . '</strong> – <code>' . esc_html( (string) $fm ) . '</code></p></div>';
			}
		}
		if ( empty( $l['complete'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( tngs_t( 'Analyse incomplète (annulée) : les résultats sont partiels.' ) ) . '</p></div>';
		}
		if ( ! empty( $l['cut'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( tngs_t( 'Certains contrôles ont atteint le nombre maximal d’anomalies conservées (réglable dans « Seuils et anomalies ignorées ») :' ) ) . ' ' . esc_html( implode( ', ', array_map( 'sanitize_key', (array) $l['cut'] ) ) ) . '.</p></div>';
		}
		if ( ! empty( $l['skipped'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( (string) (int) $l['skipped'] ) . ' ' . esc_html( tngs_t( 'groupes de personnes aux noms très répandus n’ont pas été comparés pour les doublons.' ) ) . '</p></div>';
		}

		$total = TNGCoh_Store::count( $tree, $f );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$cur   = min( $f['paged'], $pages );
		$rows  = TNGCoh_Store::query( $tree, $f, $f['orderby'], $f['order'], ( $cur - 1 ) * self::PER_PAGE, self::PER_PAGE );

		echo '<p>' . esc_html( number_format_i18n( $total ) ) . ' ' . esc_html( tngs_t( 'anomalie(s) affichée(s)' ) ) . ' ';
		$csv = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'tngcoh_csv', 'tngtree' => $tree ), array_filter( array( 'cat' => $f['cat'], 'code' => $f['code'], 'sev' => $f['sev'], 'q' => $f['q'] ), 'strlen' ) ), admin_url( 'admin-post.php' ) ), 'tngcoh_csv' );
		echo '<a class="button button-small" href="' . esc_url( $csv ) . '">' . esc_html( tngs_t( 'Exporter en CSV' ) ) . '</a> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tngcoh-inline" onsubmit="return confirm(\'' . esc_js( tngs_t( 'Effacer les résultats de cet arbre ?' ) ) . '\');">';
		wp_nonce_field( 'tngcoh_clear' );
		echo '<input type="hidden" name="action" value="tngcoh_clear"><input type="hidden" name="tngtree" value="' . esc_attr( $tree ) . '">';
		echo '<button type="submit" class="button button-small">' . esc_html( tngs_t( 'Effacer les résultats' ) ) . '</button></form></p>';

		if ( ! $rows ) {
			echo '<p>' . esc_html( tngs_t( 'Aucune anomalie pour ces filtres.' ) ) . '</p></div>';
			return;
		}

		$th = function ( $key, $label ) use ( $f ) {
			$dir = ( $f['orderby'] === $key && 'desc' === $f['order'] ) ? 'asc' : 'desc';
			$mk  = $f['orderby'] === $key ? ( 'desc' === $f['order'] ? ' ▾' : ' ▴' ) : '';
			return '<a href="' . esc_url( self::url( $f, array( 'orderby' => $key, 'order' => $dir ) ) ) . '">' . esc_html( tngs_t( $label ) ) . $mk . '</a>';
		};
		echo '<table class="widefat striped tngcoh-table"><thead><tr><th>' . $th( 'sev', 'Gravité' ) . '</th><th>' . $th( 'cat', 'Type' ) . '</th><th>' . $th( 'code', 'Anomalie' ) . '</th><th>' . esc_html( tngs_t( 'Détail' ) ) . '</th><th>' . $th( 'pid', 'Fiches' ) . '</th><th>' . $th( 'score', 'Score' ) . '</th><th></th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$sev  = TNGCoh_Rules::sev_labels();
		$cats   = TNGCoh_Rules::cats();
		$titles = TNGCoh_Rules::titles();
		foreach ( $rows as $r ) {
			echo '<tr><td><span class="tngcoh-sev tngcoh-sev-' . (int) $r['sev'] . '">' . esc_html( tngs_t( $sev[ (int) $r['sev'] ] ) ) . '</span></td>';
			echo '<td>' . esc_html( tngs_t( isset( $cats[ $r['cat'] ] ) ? $cats[ $r['cat'] ] : $r['cat'] ) ) . '</td>';
			echo '<td>' . esc_html( isset( $titles[ $r['code'] ] ) ? tngs_t( $titles[ $r['code'] ] ) : $r['code'] ) . '</td>';
			echo '<td>' . esc_html( self::message( $r ) ) . '</td>';
			$links = array_filter( array( self::link( 'P', $r['pid'], $tree ), self::link( 'F', $r['fid'], $tree ), self::link( 'P', $r['pid2'], $tree ) ), 'strlen' );
			echo '<td>' . implode( ' ', $links ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- liens construits par self::link() (échappés).
			echo '<td>' . ( 'dup' === $r['cat'] ? '<strong>' . (int) $r['score'] . '</strong>' : '–' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tngcoh-inline">';
			wp_nonce_field( 'tngcoh_ignore' );
			echo '<input type="hidden" name="action" value="tngcoh_ignore"><input type="hidden" name="sig" value="' . esc_attr( $r['sig'] ) . '">';
			echo '<button type="submit" class="button button-small">' . esc_html( tngs_t( 'Ignorer cette anomalie' ) ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		$pl = paginate_links(
			array(
				'base'         => add_query_arg( 'paged', '%#%', self::url( $f ) ),
				'format'       => '',
				'current'      => $cur,
				'total'        => $pages,
				'add_fragment' => '',
			)
		);
		if ( $pl ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $pl ) . '</div></div>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------- page des réglages */

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		tngs_admin_header( 'Cohérence : seuils et anomalies ignorées', array( 'module' => '' ) );
		$o = TNGCoh_Rules::options();
		$n = TNGCoh_Rules::OPT;
		echo '<h2>' . esc_html( tngs_t( 'Cohérence : seuils et anomalies ignorées' ) ) . '</h2>';
		echo '<p>' . esc_html( tngs_t( 'Valeurs au-delà desquelles une anomalie est signalée. Elles s’appliquent à la prochaine analyse ; relancez-la pour mettre les résultats à jour.' ) ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'tngcoh_group' );
		echo '<table class="form-table" role="presentation">';
		$f = array(
			array( 'mother_min', 'Âge minimal d’une mère à la naissance d’un enfant (ans)' ),
			array( 'mother_max', 'Âge maximal d’une mère à la naissance d’un enfant (ans)' ),
			array( 'father_min', 'Âge minimal d’un père à la naissance d’un enfant (ans)' ),
			array( 'father_max', 'Âge maximal d’un père à la naissance d’un enfant (ans)' ),
			array( 'marr_min', 'Âge minimal au mariage (ans)' ),
			array( 'age_max', 'Âge maximal au décès (ans)' ),
			array( 'living_max', 'Personne marquée « vivante » née il y a plus de (ans)' ),
			array( 'posthumous', 'Enfant né plus de (mois) après le décès de son père' ),
			array( 'tol', 'Tolérance sur les dates approximatives : « vers », « avant », « après »… (ans)' ),
			array( 'dup_min', 'Score minimal pour signaler un doublon possible (0 à 100)' ),
			array( 'loop_depth', 'Profondeur de la recherche des boucles d’ascendance (générations)' ),
			array( 'priv_years', 'Personne sans décès connu, non marquée « vivante » ni « privée », née il y a moins de (ans) : signalée avant un export' ),
			array( 'patro_sim', 'Patronyme de l’enfant et de son père : similarité minimale pour les juger identiques, en % (0 = contrôle désactivé)' ),
			array( 'max_rule', 'Nombre maximal d’anomalies conservées par contrôle' ),
		);
		$b = array( 'mother_min' => array( 8, 25 ), 'mother_max' => array( 35, 70 ), 'father_min' => array( 8, 30 ), 'father_max' => array( 45, 100 ), 'marr_min' => array( 8, 30 ), 'age_max' => array( 80, 130 ), 'living_max' => array( 80, 150 ), 'posthumous' => array( 9, 24 ), 'tol' => array( 0, 10 ), 'dup_min' => array( 30, 100 ), 'loop_depth' => array( 5, 100 ), 'max_rule' => array( 100, 50000 ), 'patro_sim' => array( 0, 100 ), 'priv_years' => array( 50, 130 ) );
		$d = TNGCoh_Rules::defaults();
		foreach ( $f as $row ) {
			$k = $row[0];
			echo '<tr><th scope="row"><label for="tngcoh_' . esc_attr( $k ) . '">' . esc_html( tngs_t( $row[1] ) ) . '</label></th><td><input id="tngcoh_' . esc_attr( $k ) . '" type="number" class="small-text" min="' . (int) $b[ $k ][0] . '" max="' . (int) $b[ $k ][1] . '" name="' . esc_attr( $n . '[' . $k . ']' ) . '" value="' . esc_attr( (string) $o[ $k ] ) . '"> <span class="description">' . esc_html( tngs_t( 'par défaut' ) ) . ' ' . (int) $d[ $k ] . '</span></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form>';

		echo '<h3>' . esc_html( tngs_t( 'Anomalies ignorées' ) ) . '</h3>';
		$ign = TNGCoh_Store::ignored();
		if ( ! $ign ) {
			echo '<p>' . esc_html( tngs_t( 'Aucune anomalie ignorée.' ) ) . '</p></div>';
			return;
		}
		echo '<p class="description">' . esc_html( tngs_t( 'Une anomalie ignorée reste ignorée aux analyses suivantes (même contrôle, mêmes personnes ou famille) ; « Restaurer » la remet dans la liste.' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tngcoh-inline">';
		wp_nonce_field( 'tngcoh_restore' );
		echo '<input type="hidden" name="action" value="tngcoh_restore"><input type="hidden" name="all" value="1"><button type="submit" class="button">' . esc_html( tngs_t( 'Tout restaurer' ) ) . '</button></form>';
		echo '<table class="widefat striped tngcoh-table"><thead><tr><th>' . esc_html( tngs_t( 'Arbre' ) ) . '</th><th>' . esc_html( tngs_t( 'Anomalie' ) ) . '</th><th>' . esc_html( tngs_t( 'Fiches' ) ) . '</th><th>' . esc_html( tngs_t( 'Ignorée le' ) ) . '</th><th></th></tr></thead><tbody>';
		$shown = 0;
		foreach ( array_reverse( $ign, true ) as $sig => $i ) {
			if ( ++$shown > 500 ) {
				break;
			}
			echo '<tr><td>' . esc_html( (string) $i['t'] ) . '</td><td>' . esc_html( (string) $i['m'] ) . '</td><td><code>' . esc_html( (string) $i['p'] ) . '</code></td><td>' . esc_html( wp_date( 'd/m/Y', (int) $i['s'] ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tngcoh-inline">';
			wp_nonce_field( 'tngcoh_restore' );
			echo '<input type="hidden" name="action" value="tngcoh_restore"><input type="hidden" name="sig" value="' . esc_attr( (string) $sig ) . '"><button type="submit" class="button button-small">' . esc_html( tngs_t( 'Restaurer' ) ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $ign ) > 500 ) {
			echo '<p class="description">' . esc_html( tngs_t( 'Les 500 plus récentes sont affichées.' ) ) . '</p>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------- actions */
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- jeton et droits vérifiés par tngs_admin_guard() (check_admin_referer + manage_options) / ajax_guard() (check_ajax_referer + manage_options).

	public static function post_ignore() {
		tngs_admin_guard( 'tngcoh_ignore' );
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		if ( preg_match( '/^[a-f0-9]{32}$/', $sig ) ) {
			TNGCoh_Store::ignore( $sig );
		}
		self::back();
	}

	public static function post_restore() {
		tngs_admin_guard( 'tngcoh_restore' );
		if ( ! empty( $_POST['all'] ) ) {
			TNGCoh_Store::restore( '' );
		} else {
			$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
			if ( preg_match( '/^[a-f0-9]{32}$/', $sig ) ) {
				TNGCoh_Store::restore( $sig );
			}
		}
		self::back();
	}

	public static function post_clear() {
		tngs_admin_guard( 'tngcoh_clear' );
		$tree = isset( $_POST['tngtree'] ) ? self::ok_tree( sanitize_text_field( wp_unslash( $_POST['tngtree'] ) ) ) : '';
		if ( '' !== $tree ) {
			TNGCoh_Store::clear( $tree );
		}
		self::back();
	}

	/** Cellule CSV : un texte qui commencerait par = + - @ serait interprété comme une formule par le tableur. */
	private static function cell( $s ) {
		$s = (string) $s;
		return ( '' !== $s && preg_match( '/^[=+\-@\t\r]/', $s ) ) ? "'" . $s : $s;
	}

	public static function post_csv() {
		tngs_admin_guard( 'tngcoh_csv' );
		$trees = TNGCoh_Engine::trees();
		$f     = self::filters( $trees );
		if ( '' === $f['tree'] ) {
			wp_die( esc_html( tngs_t( 'Arbre inconnu.' ) ), '', array( 'response' => 400 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="coherence-' . sanitize_file_name( $f['tree'] ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( tngs_t( 'Gravité' ), tngs_t( 'Type' ), tngs_t( 'Anomalie' ), tngs_t( 'Détail' ), tngs_t( 'Personne' ), tngs_t( 'Famille' ), tngs_t( 'Seconde personne' ), tngs_t( 'Score' ), tngs_t( 'Arbre' ) ), ';' );
		$sev  = TNGCoh_Rules::sev_labels();
		$cats   = TNGCoh_Rules::cats();
		$titles = TNGCoh_Rules::titles();
		$off    = 0;
		do {
			$rows = TNGCoh_Store::query( $f['tree'], $f, $f['orderby'], $f['order'], $off, 2000 );
			foreach ( $rows as $r ) {
				fputcsv(
					$out,
					array(
						self::cell( tngs_t( $sev[ (int) $r['sev'] ] ) ),
						self::cell( tngs_t( isset( $cats[ $r['cat'] ] ) ? $cats[ $r['cat'] ] : $r['cat'] ) ),
						self::cell( isset( $titles[ $r['code'] ] ) ? tngs_t( $titles[ $r['code'] ] ) : $r['code'] ),
						self::cell( self::message( $r ) ),
						self::cell( $r['pid'] ),
						self::cell( $r['fid'] ),
						self::cell( $r['pid2'] ),
						'dup' === $r['cat'] ? (int) $r['score'] : '',
						self::cell( $r['tree'] ),
					),
					';'
				);
			}
			$off += 2000;
		} while ( count( $rows ) === 2000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ---------------------------------------------------------------- AJAX */

	private static function ajax_guard() {
		check_ajax_referer( 'tngcoh', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	private static function reply( $r ) {
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_start() {
		self::ajax_guard();
		$tree = isset( $_POST['tree'] ) ? self::ok_tree( sanitize_text_field( wp_unslash( $_POST['tree'] ) ) ) : '';
		$parts = isset( $_POST['parts'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_POST['parts'] ) ) ), 'strlen' ) : array();
		$parts = TNGCoh_Rules::parts_clean( $parts );
		if ( ! $parts ) {
			wp_send_json_error( array( 'message' => tngs_t( 'Aucune partie sélectionnée.' ) ) );
		}
		self::reply( TNGCoh_Engine::start( $tree, $parts ) );
	}

	public static function ajax_step() {
		self::ajax_guard();
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		self::reply( TNGCoh_Engine::step( $token ) );
	}

	public static function ajax_cancel() {
		self::ajax_guard();
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		self::reply( TNGCoh_Engine::cancel( $token ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

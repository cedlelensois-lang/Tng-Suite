<?php
/**
 * TNG Suite – module « Arbres & descendance » (ex-plugin TNG Gutenberg Tree & Descendants 7.0.0).
 * Shortcodes : arbre_tng, enfants_tng, patronyme_tng, liste_enfants_tng, stats_descendants_tng, lien_fiche_tng.
 * Connexion, préfixe, URL, arbre et confidentialité : réglages communs (TNG Suite > Général).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TNG_TREE_VERSION', TNGSUITE_VERSION );

// ------------------------------------------------------------------
// 1. CONNEXION CENTRALISÉE À LA BDD TNG DISTANTE - SINGLETON SÛR
// ------------------------------------------------------------------

/**
 * Retourne une instance unique de wpdb connectée à la base TNG, ou false.
 *
 * - Une connexion en échec n'est JAMAIS mémorisée comme valide.
 * - Après un échec, on évite de retenter pendant 60 s (transient) pour ne pas
 *   faire attendre chaque visiteur sur un serveur distant indisponible.
 *
 * @param bool $force Ignorer le « disjoncteur » (utilisé par la page de réglages).
 */
function tng_get_db( $force = false ) {
    return tngs_db( $force );
}

// ------------------------------------------------------------------
// 2. RÉGLAGES (lus de façon défensive)
// ------------------------------------------------------------------

function tng_prefix() {
    return tngs_prefix();
}

function tng_table( $name ) {
    return '`' . tng_prefix() . $name . '`';
}

function tng_gedcom() {
    return tngs_gedcom();
}

function tng_hide_private() {
    return tngs_hide_living( 'tree' );
}

function tng_site_url() {
    return tngs_site_url();
}

function tng_person_url( $id ) {
    $url = tng_site_url();
    if ( '' === $url || '' === (string) $id ) {
        return '';
    }
    $url .= 'getperson.php?personID=' . rawurlencode( $id );
    $gedcom = tng_gedcom();
    if ( '' !== $gedcom ) {
        $url .= '&tree=' . rawurlencode( $gedcom );
    }
    return $url;
}

/** Nettoie un identifiant TNG fourni en attribut de shortcode (I12, F34...). */
function tng_clean_id( $value ) {
    return preg_replace( '/[^A-Za-z0-9_\-\.]/', '', sanitize_text_field( (string) $value ) );
}

function tng_clean_ids( $ids ) {
    $out = array();
    foreach ( (array) $ids as $id ) {
        $id = (string) $id;
        if ( '' !== $id ) {
            $out[ $id ] = $id;
        }
    }
    return array_values( $out );
}

// ------------------------------------------------------------------
// 3. OUTILS SQL (requêtes préparées, IN() sûrs, filtre d'arbre)
// ------------------------------------------------------------------

function tng_in_placeholders( array $values ) {
    return implode( ',', array_fill( 0, count( $values ), '%s' ) );
}

/** Ajoute « AND colonne = %s » si un arbre (gedcom) est configuré. */
function tng_scope( &$sql, array &$args, $column = 'gedcom' ) {
    $gedcom = tng_gedcom();
    if ( '' !== $gedcom ) {
        $sql   .= " AND {$column} = %s";
        $args[] = $gedcom;
    }
}

/** Exécute une requête préparée, loggue les erreurs, renvoie toujours un tableau. */
function tng_run( $db, $sql, array $args = array() ) {
    $query = empty( $args ) ? $sql : $db->prepare( $sql, $args );
    if ( ! $query ) {
        return array();
    }
    $rows = $db->get_results( $query );
    if ( ! empty( $db->last_error ) ) {
        error_log( '[TNG] Erreur SQL : ' . $db->last_error );
        return array();
    }
    return is_array( $rows ) ? $rows : array();
}

// ------------------------------------------------------------------
// 4. CACHE (transients) + MESSAGES
// ------------------------------------------------------------------

/** Message visible uniquement par les rôles pouvant éditer (jamais par les visiteurs). */
function tng_notice( $message ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return '';
    }
    return '<p class="tng-notice">[TNG] ' . esc_html( $message ) . '</p>';
}

/**
 * Rend un shortcode avec cache. Le callback renvoie du HTML (string) ou un WP_Error.
 * Seul le HTML est mis en cache, jamais les erreurs.
 */
function tng_render_cached( $kind, array $key_parts, callable $callback ) {
    $ttl = tngs_cache_hours() * HOUR_IN_SECONDS;
    $key = 'tng_' . md5( wp_json_encode( array(
        $kind,
        $key_parts,
        tng_prefix(),
        tng_gedcom(),
        tng_hide_private(),
        tng_site_url(),
        tngs_cache_ver(),
    ) ) );

    if ( $ttl > 0 ) {
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return $cached;
        }
    }

    $result = $callback();
    if ( is_wp_error( $result ) ) {
        return tng_notice( $result->get_error_message() );
    }

    if ( $ttl > 0 ) {
        set_transient( $key, $result, $ttl );
    }
    return $result;
}

// ------------------------------------------------------------------
// 5. CHARGEMENT DES PERSONNES PAR LOTS (fini les requêtes N+1)
// ------------------------------------------------------------------

/** Lignes brutes des personnes, indexées par personID. 1 requête par lot de 400. */
function tng_fetch_people_rows( $db, array $ids ) {
    $out = array();
    foreach ( array_chunk( tng_clean_ids( $ids ), 400 ) as $chunk ) {
        $sql  = 'SELECT personID, firstname, lastname, sex, famc, birthdate, deathdate, living, private FROM '
              . tng_table( 'people' ) . ' WHERE personID IN (' . tng_in_placeholders( $chunk ) . ')';
        $args = $chunk;
        tng_scope( $sql, $args );
        foreach ( tng_run( $db, $sql, $args ) as $row ) {
            $out[ (string) $row->personID ] = $row;
        }
    }
    return $out;
}

/** Chemins des photos par défaut, indexés par personID. 1 requête par lot de 400. */
function tng_fetch_photos( $db, array $ids ) {
    $out = array();
    foreach ( array_chunk( tng_clean_ids( $ids ), 400 ) as $chunk ) {
        $sql  = 'SELECT l.personID, m.thumbpath, m.path, m.mediatypeID FROM ' . tng_table( 'media' ) . ' m '
              . 'INNER JOIN ' . tng_table( 'medialinks' ) . " l ON m.mediaID = l.mediaID "
              . "WHERE l.defphoto = '1' AND l.personID IN (" . tng_in_placeholders( $chunk ) . ')';
        $args = $chunk;
        tng_scope( $sql, $args, 'l.gedcom' );
        foreach ( tng_run( $db, $sql, $args ) as $r ) {
            $pid = (string) $r->personID;
            if ( isset( $out[ $pid ] ) ) {
                continue;
            }
            $chosen = ! empty( $r->thumbpath ) ? $r->thumbpath : $r->path;
            if ( empty( $chosen ) ) {
                continue;
            }
            if ( false === strpos( $chosen, '/' ) && 'photos' === $r->mediatypeID ) {
                $chosen = 'photos/' . $chosen;
            }
            $out[ $pid ] = $chosen;
        }
    }
    return $out;
}

/** Familles par identifiant, indexées par familyID. */
function tng_fetch_families_by_id( $db, array $ids ) {
    $out = array();
    foreach ( array_chunk( tng_clean_ids( $ids ), 400 ) as $chunk ) {
        $sql  = 'SELECT familyID, husband, wife FROM ' . tng_table( 'families' )
              . ' WHERE familyID IN (' . tng_in_placeholders( $chunk ) . ')';
        $args = $chunk;
        tng_scope( $sql, $args );
        foreach ( tng_run( $db, $sql, $args ) as $row ) {
            $out[ (string) $row->familyID ] = $row;
        }
    }
    return $out;
}

/**
 * Transforme une ligne TNG en « vue » prête à afficher.
 * Les personnes vivantes/privées sont masquées si l'option est active (RGPD).
 */
function tng_make_view( $row, array $photos = array() ) {
    $id     = (string) $row->personID;
    $masked = tng_hide_private() && ( ! empty( $row->living ) || ! empty( $row->private ) );

    if ( $masked ) {
        $short = 'Personne protégée';
        $name  = $short;
        $photo = '';
    } else {
        $short = trim( $row->firstname . ' ' . tng_upper( (string) $row->lastname ) );
        $name  = $short . tng_get_clean_years( $row->birthdate, $row->deathdate );
        $photo = isset( $photos[ $id ] ) ? $photos[ $id ] : '';
    }

    return array(
        'id'         => $id,
        'name'       => $name,
        'short_name' => $short,
        'sex'        => $row->sex,
        'gender'     => ( 'M' === $row->sex ) ? 'male' : 'female',
        'photo'      => $photo,
        'famc'       => (string) $row->famc,
        'masked'     => $masked,
        'linkable'   => ! $masked && ! tng_is_placeholder_name( $name ),
    );
}

/** Charge personnes (+ photos) en 2 requêtes au total. Renvoie id => vue. */
function tng_load_people( $db, array $ids, $with_photos = true ) {
    $rows = tng_fetch_people_rows( $db, $ids );
    if ( ! $rows ) {
        return array();
    }
    $photos = $with_photos ? tng_fetch_photos( $db, array_keys( $rows ) ) : array();
    $views  = array();
    foreach ( $rows as $id => $row ) {
        $views[ (string) $id ] = tng_make_view( $row, $photos );
    }
    return $views;
}

// ------------------------------------------------------------------
// 6. FONCTIONS D'AFFICHAGE MUTUALISÉES
// ------------------------------------------------------------------

function tng_upper( $str ) {
    return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $str, 'UTF-8' ) : strtoupper( $str );
}

/** Noms « fictifs » pour lesquels on ne génère pas de lien (comportement d'origine conservé). */
function tng_is_placeholder_name( $name ) {
    return false !== strpos( $name, 'inconnu' ) || false !== strpos( $name, 'Pat.' ) || false !== strpos( $name, 'Mat.' );
}

/** Traduit les dates GEDCOM anglaises (ex : 23 MAY 1860) en français. */
function tng_translate_date( $date_str ) {
    if ( null === $date_str || '' === $date_str ) {
        return '';
    }
    $map = tngs_is_en()
        ? array(
            'JAN' => 'Jan', 'FEB' => 'Feb', 'MAR' => 'Mar', 'APR' => 'Apr', 'MAY' => 'May', 'JUN' => 'Jun',
            'JUL' => 'Jul', 'AUG' => 'Aug', 'SEP' => 'Sep', 'OCT' => 'Oct', 'NOV' => 'Nov', 'DEC' => 'Dec',
            'ABT' => 'about', 'EST' => 'estimated', 'AFT' => 'after', 'BEF' => 'before',
            'BET' => 'between', 'AND' => 'and', 'CAL' => 'calculated',
        )
        : array(
            'JAN' => 'jan', 'FEB' => 'fév', 'MAR' => 'mar', 'APR' => 'avr', 'MAY' => 'mai', 'JUN' => 'juin',
            'JUL' => 'juil', 'AUG' => 'aoû', 'SEP' => 'sep', 'OCT' => 'oct', 'NOV' => 'nov', 'DEC' => 'déc',
            'ABT' => 'vers', 'EST' => 'estimé', 'AFT' => 'après', 'BEF' => 'avant',
            'BET' => 'entre', 'AND' => 'et', 'CAL' => 'calculé',
        );
    return preg_replace_callback(
        '/\b(' . implode( '|', array_keys( $map ) ) . ')\b/i',
        function ( $m ) use ( $map ) {
            return $map[ strtoupper( $m[1] ) ];
        },
        (string) $date_str
    );
}

/** Extrait la dernière année à 4 chiffres de chaque date. */
function tng_get_clean_years( $birth, $death ) {
    $extract = function ( $date ) {
        if ( empty( $date ) ) {
            return '';
        }
        return preg_match_all( '/\b(\d{4})\b/', (string) $date, $m ) ? end( $m[1] ) : '';
    };
    $b_year = $extract( $birth );
    $d_year = $extract( $death );

    if ( '' === $b_year && '' === $d_year ) {
        return '';
    }
    return ' (' . ( $b_year ? $b_year : '?' ) . ' - ' . ( $d_year ? $d_year : '?' ) . ')';
}

/** Boîte individu : vignette ou icône SVG + nom, avec lien vers la fiche TNG si possible. */
function tng_render_person_box( $id, $fullname, $gender_class, $is_root = false, $photo_path = '', $linkable = true ) {
    $tng_url = tng_site_url();
    $class   = ( $is_root ? 'tng-box root ' : 'tng-box ' ) . $gender_class;

    if ( '' !== (string) $photo_path && '' !== $tng_url ) {
        $avatar_html = sprintf(
            '<img class="tng-avatar-img" src="%s" alt="" loading="lazy" decoding="async" />',
            esc_url( $tng_url . ltrim( $photo_path, '/' ) )
        );
    } elseif ( 'male' === $gender_class ) {
        $avatar_html = '<svg class="tng-avatar-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
    } else {
        $avatar_html = '<svg class="tng-avatar-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-3.2 0-6 1.41-6 3.5V20h12v-2.5c0-2.09-2.8-3.5-6-3.5z"/></svg>';
    }

    $inner = '<div class="tng-box-inner">' . $avatar_html . '<div class="tng-box-text">' . esc_html( $fullname ) . '</div></div>';

    if ( $linkable && '' !== (string) $id && '' !== $tng_url ) {
        return sprintf(
            '<a href="%s" class="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url( tng_person_url( $id ) ),
            esc_attr( $class ),
            $inner
        );
    }
    return sprintf( '<div class="%s">%s</div>', esc_attr( $class ), $inner );
}

/** Raccourci : boîte à partir d'une vue. */
function tng_render_view_box( array $view, $is_root = false ) {
    return tng_render_person_box( $view['id'], $view['name'], $view['gender'], $is_root, $view['photo'], $view['linkable'] );
}

// ------------------------------------------------------------------
// 7. STYLES (chargés uniquement quand un shortcode est présent)
// ------------------------------------------------------------------

function tng_get_css() {
    return <<<'CSS'
/* Base des Boîtes */
.tng-box {
    display: flex;
    align-items: center;
    text-decoration: none;
    background: #fff;
    border: 1px solid #ccc;
    padding: 10px 15px;
    border-radius: 6px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.06);
    width: 240px;
    min-width: 240px;
    height: 65px;
    box-sizing: border-box;
    font-size: 13px;
    position: relative;
    z-index: 2;
}
.tng-box, .tng-box:visited, .tng-box:hover, .tng-box:active { color: #333 !important; }
a.tng-box:hover { background: #fcfcfc; border-color: #666; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
.tng-box.male { border-left: 5px solid #1466b8; background: #f4f8ff; }
.tng-box.female { border-left: 5px solid #e0245e; background: #fff4f7; }
.tng-box.root { font-weight: bold; border-left-width: 7px; }

.tng-box-inner { display: flex; align-items: center; gap: 12px; width: 100%; height: 100%; }
.tng-box-text { display: flex; flex-direction: column; justify-content: center; text-align: left; line-height: 1.3; width: 100%; overflow: hidden; }

.tng-avatar-icon { width: 22px; height: 22px; flex-shrink: 0; }
.tng-avatar-img { width: 32px; height: 32px; object-fit: cover; border-radius: 50%; border: 1px solid #ddd; flex-shrink: 0; }
.tng-box.male .tng-avatar-icon { fill: #1466b8; }
.tng-box.female .tng-avatar-icon { fill: #e0245e; }

.tng-notice { font-family: sans-serif; font-size: 13px; color: #8a1f1f; background: #fdf0f0; border: 1px solid #e3b5b5; padding: 8px 12px; border-radius: 4px; }

/* Lignes de connexion de l'arbre d'ascendance ([arbre_tng]) */
.tng-tree-wrapper { width: 100%; overflow-x: auto; padding: 20px 10px; background: #fff; margin: 20px 0; }
.tng-tree-node-group { display: flex; align-items: center; position: relative; }
.tng-tree-parents { display: flex; flex-direction: column; justify-content: center; gap: 20px; padding-left: 40px; position: relative; }
.tng-tree-node-group::after { content: ""; position: absolute; right: -20px; top: 50%; width: 20px; height: 2px; background-color: #bbb; z-index: 1; }
.tng-tree-node-group:only-child::after,
.tng-tree-parents:empty + .tng-tree-node-group::after { display: none; }
.tng-tree-parents::before { content: ""; position: absolute; left: 20px; top: 25%; bottom: 25%; width: 2px; background-color: #bbb; z-index: 1; }
.tng-tree-parents .tng-tree-node-group::before { content: ""; position: absolute; left: -20px; top: 50%; width: 20px; height: 2px; background-color: #bbb; z-index: 1; }

/* Descendance ([enfants_tng]) */
.tng-desc-wrapper { font-family: sans-serif; max-width: 100%; margin: 20px 0; }
.tng-union-block { display: flex; flex-direction: row; align-items: center; gap: 30px; margin-bottom: 30px; background: #fff; }
.tng-column-couple { display: flex; flex-direction: column; gap: 12px; }
.tng-column-children { display: flex; flex-direction: column; gap: 12px; flex-grow: 1; padding-left: 20px; border-left: 2px dashed #ccc; }
.tng-role-label { font-size: 11px; text-transform: uppercase; color: #777; margin-bottom: 4px; font-weight: bold; text-align: left; }
.tng-union-date { font-size: 11px; color: #555; background: #f2f2f2; padding: 3px 8px; border-radius: 4px; display: inline-block; margin-top: 5px; font-weight: 500; }
.tng-no-child { font-style: italic; color: #888; font-size: 13px; }

/* Lignée patronymique ([patronyme_tng]) */
.tng-patronymic-container { font-family: sans-serif; display: flex; flex-direction: column-reverse; gap: 0px; margin: 20px 0; max-width: 600px; padding-left: 10px; }
.tng-patronymic-row { display: flex; flex-direction: row; align-items: center; gap: 20px; padding: 12px 0; position: relative; }
.tng-patronymic-row:not(:first-child)::after { content: ''; position: absolute; bottom: -13px; left: 120px; width: 2px; height: 26px; background: #ccc; }

/* Liste d'enfants ([liste_enfants_tng]) */
.tng-children-list { margin: 15px 0 15px 20px; padding: 0; list-style-type: disc; font-family: sans-serif; }
.tng-children-list li { margin-bottom: 6px; font-size: 14px; color: #333; }
.tng-children-list li a { color: #0066cc; text-decoration: none; }
.tng-children-list li a:hover { text-decoration: underline; }

/* Statistiques de descendance ([stats_descendants_tng]) */
.tng-stats-table { font-family: sans-serif; border-collapse: collapse; width: 100%; max-width: 350px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); font-size: 14px; }
.tng-stats-table th { background-color: #b33939; color: #ffffff; text-align: left; padding: 10px 12px; font-weight: bold; font-size: 12px; letter-spacing: 0.5px; text-transform: uppercase; }
.tng-stats-table td { padding: 9px 12px; border-bottom: 1px solid #eef0f2; color: #333; }
.tng-stats-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
.tng-stats-table tbody tr:hover { background-color: #f1f3f5; }
.tng-stats-table tfoot td { background-color: #eaedf0; padding: 10px 12px; border-top: 2px solid #ced4da; color: #000; }

/* Bouton Fiche ([lien_fiche_tng]) */
.tng-btn-link-container { margin: 15px 0; }
.tng-btn-profile-link { display: inline-flex; align-items: center; gap: 8px; background-color: rgba(179, 57, 57, 0.05); color: #b33939 !important; border: 1px solid #b33939; padding: 10px 20px; border-radius: 4px; font-family: sans-serif; font-size: 14px; font-weight: 500; text-decoration: none !important; transition: all 0.25s ease-in-out; }
.tng-btn-profile-link:hover { background-color: #b33939; color: #ffffff !important; box-shadow: 0 4px 8px rgba(179, 57, 57, 0.2); }
.tng-btn-profile-link .tng-btn-emoji { font-size: 16px; }
.tng-btn-profile-link.is-disabled { border-color: #ccc; background-color: #eee; color: #777 !important; cursor: default; }
CSS;
}

function tng_register_assets() {
    wp_register_style( 'tng-tree', false, array(), TNG_TREE_VERSION );
    wp_add_inline_style( 'tng-tree', tng_get_css() );
}
add_action( 'wp_enqueue_scripts', 'tng_register_assets' );

/** À appeler au début de chaque shortcode : le CSS n'est chargé que si nécessaire. */
function tng_enqueue_assets() {
    wp_enqueue_style( 'tng-tree' );
}

// ------------------------------------------------------------------
// 8. ARBRE D'ASCENDANCE ([arbre_tng])  -  1 requête par génération
// ------------------------------------------------------------------

function tng_build_ancestor_node( $id, $gen, $max_gen, array $views, array $families ) {
    if ( ! isset( $views[ $id ] ) ) {
        return null;
    }
    $node = $views[ $id ] + array( 'father' => null, 'mother' => null );

    if ( $gen < $max_gen && '' !== $node['famc'] && isset( $families[ $node['famc'] ] ) ) {
        $family = $families[ $node['famc'] ];
        if ( ! empty( $family->husband ) ) {
            $node['father'] = tng_build_ancestor_node( (string) $family->husband, $gen + 1, $max_gen, $views, $families );
        }
        if ( ! empty( $family->wife ) ) {
            $node['mother'] = tng_build_ancestor_node( (string) $family->wife, $gen + 1, $max_gen, $views, $families );
        }
    }
    return $node;
}

function tng_get_ancestors_tree( $db, $root_id, $max_gen ) {
    $rows     = array();
    $families = array();
    $level    = array( $root_id );

    for ( $gen = 1; $gen <= $max_gen && $level; $gen++ ) {
        $rows += tng_fetch_people_rows( $db, array_diff( $level, array_keys( $rows ) ) );

        if ( 1 === $gen && ! isset( $rows[ $root_id ] ) ) {
            return null;
        }
        if ( $gen >= $max_gen ) {
            break;
        }

        $famc = array();
        foreach ( $level as $pid ) {
            if ( isset( $rows[ $pid ] ) && ! empty( $rows[ $pid ]->famc ) && ! isset( $families[ $rows[ $pid ]->famc ] ) ) {
                $famc[] = $rows[ $pid ]->famc;
            }
        }
        $families += tng_fetch_families_by_id( $db, $famc );

        $next = array();
        foreach ( $level as $pid ) {
            if ( ! isset( $rows[ $pid ] ) || empty( $rows[ $pid ]->famc ) || ! isset( $families[ $rows[ $pid ]->famc ] ) ) {
                continue;
            }
            $family = $families[ $rows[ $pid ]->famc ];
            if ( ! empty( $family->husband ) ) {
                $next[] = $family->husband;
            }
            if ( ! empty( $family->wife ) ) {
                $next[] = $family->wife;
            }
        }
        $level = tng_clean_ids( $next );
    }

    $photos = tng_fetch_photos( $db, array_keys( $rows ) );
    $views  = array();
    foreach ( $rows as $id => $row ) {
        $views[ (string) $id ] = tng_make_view( $row, $photos );
    }

    return tng_build_ancestor_node( $root_id, 1, $max_gen, $views, $families );
}

function tng_render_ancestor_node_html( $node, $is_root = false ) {
    if ( ! $node ) {
        return '';
    }
    $html  = '<div class="tng-tree-node-group">';
    $html .= tng_render_view_box( $node, $is_root );

    if ( $node['father'] || $node['mother'] ) {
        $html .= '<div class="tng-tree-parents">';
        if ( $node['father'] ) {
            $html .= tng_render_ancestor_node_html( $node['father'], false );
        }
        if ( $node['mother'] ) {
            $html .= tng_render_ancestor_node_html( $node['mother'], false );
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

function tng_tree_shortcode( $atts ) {
    tng_enqueue_assets();
    $pairs     = shortcode_atts( array( 'id' => 'I1', 'generations' => '3' ), $atts, 'arbre_tng' );
    $person_id = tng_clean_id( $pairs['id'] );
    $limit     = max( 1, (int) apply_filters( 'tng_max_ancestor_generations', 5 ) );
    $max_gen   = max( 1, min( $limit, (int) $pairs['generations'] ) );

    if ( '' === $person_id ) {
        return tng_notice( 'Identifiant manquant.' );
    }

    return tng_render_cached( 'arbre', array( $person_id, $max_gen ), function () use ( $person_id, $max_gen ) {
        $db = tng_get_db();
        if ( ! $db ) {
            return new WP_Error( 'tng_db', 'Connexion à la base TNG impossible.' );
        }
        $tree = tng_get_ancestors_tree( $db, $person_id, $max_gen );
        if ( ! $tree ) {
            return new WP_Error( 'tng_nf', 'Individu introuvable.' );
        }
        return '<div class="tng-tree-wrapper"><div style="display:flex;align-items:center;font-family:sans-serif;">'
            . tng_render_ancestor_node_html( $tree, true )
            . '</div></div>';
    } );
}
add_shortcode( 'arbre_tng', 'tng_tree_shortcode' );

// ------------------------------------------------------------------
// 9. DESCENDANTS AVEC VIGNETTES ([enfants_tng])  -  5 requêtes au total
// ------------------------------------------------------------------

function tng_descendants_shortcode( $atts ) {
    tng_enqueue_assets();
    $pairs     = shortcode_atts( array( 'id' => 'I1' ), $atts, 'enfants_tng' );
    $person_id = tng_clean_id( $pairs['id'] );

    if ( '' === $person_id ) {
        return tng_notice( 'Identifiant manquant.' );
    }

    return tng_render_cached( 'enfants', array( $person_id ), function () use ( $person_id ) {
        $db = tng_get_db();
        if ( ! $db ) {
            return new WP_Error( 'tng_db', 'Connexion à la base TNG impossible.' );
        }

        $me_map = tng_load_people( $db, array( $person_id ) );
        if ( ! isset( $me_map[ $person_id ] ) ) {
            return new WP_Error( 'tng_nf', 'Individu introuvable.' );
        }
        $me = $me_map[ $person_id ];

        $is_male      = ( 'M' === $me['sex'] );
        $my_field     = $is_male ? 'husband' : 'wife';      // valeurs en liste blanche
        $spouse_field = $is_male ? 'wife' : 'husband';

        $sql  = "SELECT familyID, marrdate, {$spouse_field} AS spouse_id FROM " . tng_table( 'families' ) . " WHERE {$my_field} = %s";
        $args = array( $person_id );
        tng_scope( $sql, $args );
        $families = tng_run( $db, $sql, $args );

        $children_by_family = array();
        $related_ids        = array();

        if ( $families ) {
            $family_ids = array();
            foreach ( $families as $family ) {
                $family_ids[] = $family->familyID;
                if ( ! empty( $family->spouse_id ) ) {
                    $related_ids[] = $family->spouse_id;
                }
            }
            foreach ( array_chunk( tng_clean_ids( $family_ids ), 400 ) as $chunk ) {
                $sql  = 'SELECT familyID, personID FROM ' . tng_table( 'children' )
                      . " WHERE personID != '' AND familyID IN (" . tng_in_placeholders( $chunk ) . ')';
                $args = $chunk;
                tng_scope( $sql, $args );
                $sql .= ' ORDER BY familyID ASC, ordernum ASC';
                foreach ( tng_run( $db, $sql, $args ) as $c ) {
                    $children_by_family[ (string) $c->familyID ][] = (string) $c->personID;
                    $related_ids[]                                 = $c->personID;
                }
            }
        }

        $related = tng_load_people( $db, $related_ids );

        ob_start();
        ?>
        <div class="tng-desc-wrapper">
            <?php if ( ! empty( $families ) ) : ?>
                <?php foreach ( $families as $family ) :
                    $spouse = ( ! empty( $family->spouse_id ) && isset( $related[ $family->spouse_id ] ) ) ? $related[ $family->spouse_id ] : null;
                    $child_ids = isset( $children_by_family[ (string) $family->familyID ] ) ? $children_by_family[ (string) $family->familyID ] : array();
                    $show_date = ! empty( $family->marrdate ) && ! $me['masked'] && ( ! $spouse || ! $spouse['masked'] );
                    ?>
                    <div class="tng-union-block">
                        <div class="tng-column-couple">
                            <div>
                                <div class="tng-role-label">Sujet principal :</div>
                                <?php echo tng_render_view_box( $me ); ?>
                            </div>
                            <div>
                                <div class="tng-role-label">Conjoint(e) :</div>
                                <?php
                                if ( $spouse ) {
                                    echo tng_render_view_box( $spouse );
                                } else {
                                    echo tng_render_person_box( '', 'Conjoint inconnu', $is_male ? 'female' : 'male', false, '', false );
                                }
                                ?>
                            </div>
                            <?php if ( $show_date ) : ?>
                                <div>
                                    <div class="tng-union-date">💍 Mariage : <?php echo esc_html( tng_translate_date( $family->marrdate ) ); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="tng-column-children">
                            <div class="tng-role-label">Enfant(s) de cette union :</div>
                            <?php
                            $printed = 0;
                            foreach ( $child_ids as $child_id ) {
                                if ( isset( $related[ $child_id ] ) ) {
                                    echo tng_render_view_box( $related[ $child_id ] );
                                    $printed++;
                                }
                            }
                            if ( 0 === $printed ) :
                                ?>
                                <div class="tng-no-child">Aucun enfant enregistré pour cette union.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <?php echo tng_render_view_box( $me ); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    } );
}
add_shortcode( 'enfants_tng', 'tng_descendants_shortcode' );

// ------------------------------------------------------------------
// 10. LIGNÉE PATRONYMIQUE ([patronyme_tng])  -  1 requête / génération + 3
// ------------------------------------------------------------------

function tng_patronymic_shortcode( $atts ) {
    tng_enqueue_assets();
    $pairs     = shortcode_atts( array( 'id' => 'I1', 'generations' => '5' ), $atts, 'patronyme_tng' );
    $person_id = tng_clean_id( $pairs['id'] );
    $limit     = max( 1, (int) apply_filters( 'tng_max_patronymic_generations', 30 ) );
    $max_gen   = max( 1, min( $limit, (int) $pairs['generations'] ) );

    if ( '' === $person_id ) {
        return tng_notice( 'Identifiant manquant.' );
    }

    return tng_render_cached( 'patronyme', array( $person_id, $max_gen ), function () use ( $person_id, $max_gen ) {
        $db = tng_get_db();
        if ( ! $db ) {
            return new WP_Error( 'tng_db', 'Connexion à la base TNG impossible.' );
        }

        // 1) Remontée de la lignée : une personne + son père par requête (JOIN).
        $chain   = array();
        $seen    = array();
        $current = $person_id;

        for ( $i = 0; $i < $max_gen && '' !== $current && ! isset( $seen[ $current ] ); $i++ ) {
            $seen[ $current ] = true;

            $sql  = 'SELECT p.personID, p.firstname, p.lastname, p.sex, p.famc, p.birthdate, p.deathdate, p.living, p.private, f.husband AS father_id '
                  . 'FROM ' . tng_table( 'people' ) . ' p LEFT JOIN ' . tng_table( 'families' ) . ' f ON f.familyID = p.famc AND f.gedcom = p.gedcom '
                  . 'WHERE p.personID = %s';
            $args = array( $current );
            tng_scope( $sql, $args, 'p.gedcom' );
            $sql .= ' LIMIT 1';

            $found = tng_run( $db, $sql, $args );
            if ( ! $found ) {
                break;
            }
            $chain[] = $found[0];
            $current = (string) $found[0]->father_id;
        }

        if ( ! $chain ) {
            return new WP_Error( 'tng_nf', 'Individu introuvable.' );
        }

        // 2) Tous les conjoints en une seule requête.
        $chain_ids = array();
        foreach ( $chain as $row ) {
            $chain_ids[] = (string) $row->personID;
        }
        $chain_ids = tng_clean_ids( $chain_ids );

        $ph   = tng_in_placeholders( $chain_ids );
        $sql  = 'SELECT familyID, husband, wife FROM ' . tng_table( 'families' ) . " WHERE (husband IN ($ph) OR wife IN ($ph))";
        $args = array_merge( $chain_ids, $chain_ids );
        tng_scope( $sql, $args );
        $sql .= ' ORDER BY familyID ASC';
        $spouse_families = tng_run( $db, $sql, $args );

        $spouse_of = array();
        foreach ( $chain as $row ) {
            $id    = (string) $row->personID;
            $mine  = ( 'M' === $row->sex ) ? 'husband' : 'wife';
            $other = ( 'M' === $row->sex ) ? 'wife' : 'husband';
            foreach ( $spouse_families as $family ) {
                if ( (string) $family->$mine === $id && ! empty( $family->$other ) ) {
                    $spouse_of[ $id ] = (string) $family->$other;
                    break;
                }
            }
        }

        // 3) Personnes liées + photos : 2 requêtes.
        $spouse_rows = tng_fetch_people_rows( $db, array_values( $spouse_of ) );
        $photos      = tng_fetch_photos( $db, array_merge( $chain_ids, array_keys( $spouse_rows ) ) );

        $generation_rows = array();
        foreach ( $chain as $i => $row ) {
            $id       = (string) $row->personID;
            $man_view = tng_make_view( $row, $photos );

            $spouse_html = '';
            if ( isset( $spouse_of[ $id ], $spouse_rows[ $spouse_of[ $id ] ] ) ) {
                $spouse_html = tng_render_view_box( tng_make_view( $spouse_rows[ $spouse_of[ $id ] ], $photos ) );
            }
            $generation_rows[] = array( 'man' => tng_render_view_box( $man_view, 0 === $i ), 'spouse' => $spouse_html );
        }

        ob_start();
        ?>
        <div class="tng-patronymic-container">
            <?php foreach ( $generation_rows as $row ) : ?>
                <div class="tng-patronymic-row">
                    <?php echo $row['man']; ?>
                    <?php if ( ! empty( $row['spouse'] ) ) { echo $row['spouse']; } ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    } );
}
add_shortcode( 'patronyme_tng', 'tng_patronymic_shortcode' );

// ------------------------------------------------------------------
// 11. LISTE D'ENFANTS D'UNE FAMILLE ([liste_enfants_tng])
// ------------------------------------------------------------------

function tng_children_list_shortcode( $atts ) {
    tng_enqueue_assets();
    $pairs     = shortcode_atts( array( 'id' => '' ), $atts, 'liste_enfants_tng' );
    $family_id = tng_clean_id( $pairs['id'] );

    if ( '' === $family_id ) {
        return tng_notice( 'Aucun identifiant de famille fourni.' );
    }

    return tng_render_cached( 'liste_enfants', array( $family_id ), function () use ( $family_id ) {
        $db = tng_get_db();
        if ( ! $db ) {
            return new WP_Error( 'tng_db', 'Connexion à la base TNG impossible.' );
        }

        $sql  = 'SELECT personID FROM ' . tng_table( 'children' ) . ' WHERE familyID = %s';
        $args = array( $family_id );
        tng_scope( $sql, $args );
        $sql .= ' ORDER BY ordernum ASC';

        $children_ids = array();
        foreach ( tng_run( $db, $sql, $args ) as $row ) {
            $children_ids[] = (string) $row->personID;
        }

        if ( ! $children_ids ) {
            return '<p class="tng-no-child">Aucun enfant trouvé pour cette famille.</p>';
        }

        $people = tng_load_people( $db, $children_ids, false );

        ob_start();
        ?>
        <ul class="tng-children-list">
            <?php foreach ( $children_ids as $pid ) :
                if ( ! isset( $people[ $pid ] ) ) {
                    continue;
                }
                $child = $people[ $pid ];
                $url   = $child['masked'] ? '' : tng_person_url( $pid );
                ?>
                <li>
                    <?php if ( '' !== $url ) : ?>
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $child['name'] ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $child['name'] ); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    } );
}
add_shortcode( 'liste_enfants_tng', 'tng_children_list_shortcode' );

// ------------------------------------------------------------------
// 12. STATISTIQUES DE DESCENDANCE ([stats_descendants_tng])
//     Parcours par génération, anti-boucle, profondeur limitée, IN() découpés.
// ------------------------------------------------------------------

function tng_get_descendants_stats( $db, $person_id ) {
    $stats     = array();
    $visited   = array( $person_id => true );
    $current   = array( $person_id );
    $gen       = 2;
    $max_depth = max( 1, (int) apply_filters( 'tng_max_descendant_generations', 15 ) );

    while ( $current && $gen <= $max_depth + 1 ) {
        // Familles où l'un des parents courants est époux/épouse.
        $family_ids = array();
        foreach ( array_chunk( $current, 300 ) as $chunk ) {
            $ph   = tng_in_placeholders( $chunk );
            $sql  = 'SELECT familyID FROM ' . tng_table( 'families' ) . " WHERE (husband IN ($ph) OR wife IN ($ph))";
            $args = array_merge( $chunk, $chunk );
            tng_scope( $sql, $args );
            foreach ( tng_run( $db, $sql, $args ) as $row ) {
                $family_ids[ (string) $row->familyID ] = (string) $row->familyID;
            }
        }
        if ( ! $family_ids ) {
            break;
        }

        // Enfants de ces familles, sans jamais recompter une personne déjà vue.
        $next = array();
        foreach ( array_chunk( array_values( $family_ids ), 300 ) as $chunk ) {
            $sql  = 'SELECT DISTINCT personID FROM ' . tng_table( 'children' )
                  . " WHERE personID != '' AND familyID IN (" . tng_in_placeholders( $chunk ) . ')';
            $args = $chunk;
            tng_scope( $sql, $args );
            foreach ( tng_run( $db, $sql, $args ) as $row ) {
                $pid = (string) $row->personID;
                if ( ! isset( $visited[ $pid ] ) ) {
                    $visited[ $pid ] = true;
                    $next[]          = $pid;
                }
            }
        }
        if ( ! $next ) {
            break;
        }

        $stats[ $gen ] = count( $next );
        $current       = $next;
        $gen++;
    }
    return $stats;
}

function tng_descendants_stats_shortcode( $atts ) {
    tng_enqueue_assets();
    $pairs     = shortcode_atts( array( 'id' => 'I1' ), $atts, 'stats_descendants_tng' );
    $person_id = tng_clean_id( $pairs['id'] );

    if ( '' === $person_id ) {
        return tng_notice( 'Identifiant manquant.' );
    }

    return tng_render_cached( 'stats', array( $person_id ), function () use ( $person_id ) {
        $db = tng_get_db();
        if ( ! $db ) {
            return new WP_Error( 'tng_db', 'Connexion à la base TNG impossible.' );
        }

        $stats = tng_get_descendants_stats( $db, $person_id );
        if ( empty( $stats ) ) {
            return '<p class="tng-no-child" style="font-family: sans-serif;">Aucun descendant enregistré pour cet individu.</p>';
        }

        $total_global = array_sum( $stats );

        ob_start();
        ?>
        <table class="tng-stats-table">
            <thead>
                <tr>
                    <th>GÉN.</th>
                    <th>PARENTÉ</th>
                    <th style="text-align: right;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $stats as $gen => $total ) :
                    if ( 2 === $gen ) {
                        $label = 'Enfants';
                    } elseif ( 3 === $gen ) {
                        $label = 'Petits-enfants';
                    } elseif ( 4 === $gen ) {
                        $label = 'Arrière-petits-enfants';
                    } else {
                        $label = ( $gen - 3 ) . ' x ' . tngs_t( 'Arrière-petits-enfants' );
                    }
                    ?>
                    <tr>
                        <td><?php echo (int) $gen; ?></td>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td style="text-align: right; font-weight: 500;"><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" style="font-weight: bold;">Total</td>
                    <td style="text-align: right; font-weight: bold;"><?php echo esc_html( number_format_i18n( $total_global ) ); ?></td>
                </tr>
            </tfoot>
        </table>
        <?php
        return ob_get_clean();
    } );
}
add_shortcode( 'stats_descendants_tng', 'tng_descendants_stats_shortcode' );

// ------------------------------------------------------------------
// 13. BOUTON LIEN VERS LA FICHE ([lien_fiche_tng])
// ------------------------------------------------------------------

function tng_person_profile_link_shortcode( $atts ) {
    tng_enqueue_assets();
    $pairs     = shortcode_atts( array( 'id' => 'I1' ), $atts, 'lien_fiche_tng' );
    $person_id = tng_clean_id( $pairs['id'] );

    if ( '' === $person_id ) {
        return tng_notice( 'Identifiant manquant.' );
    }

    return tng_render_cached( 'fiche', array( $person_id ), function () use ( $person_id ) {
        $db = tng_get_db();
        if ( ! $db ) {
            return new WP_Error( 'tng_db', 'Connexion à la base TNG impossible.' );
        }

        $people = tng_load_people( $db, array( $person_id ), false );
        if ( ! isset( $people[ $person_id ] ) ) {
            return new WP_Error( 'tng_nf', 'Individu introuvable.' );
        }
        $person = $people[ $person_id ];

        // Personne vivante/privée : aucun nom n'est divulgué.
        if ( $person['masked'] ) {
            return '';
        }

        $button_text = tngs_t( 'Accéder à la fiche généalogique de ' ) . $person['short_name'];
        $url         = tng_person_url( $person_id );

        ob_start();
        ?>
        <div class="tng-btn-link-container">
            <?php if ( '' !== $url ) : ?>
                <a href="<?php echo esc_url( $url ); ?>" class="tng-btn-profile-link" target="_blank" rel="noopener noreferrer">
                    <span class="tng-btn-emoji">🔍</span> <?php echo esc_html( $button_text ); ?>
                </a>
            <?php else : ?>
                <span class="tng-btn-profile-link is-disabled">
                    <span class="tng-btn-emoji">🔍</span> <?php echo esc_html( $button_text ); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    } );
}
add_shortcode( 'lien_fiche_tng', 'tng_person_profile_link_shortcode' );


/* Page d'aide (ce module n'a pas de réglages propres) */
add_action( 'admin_menu', function () {
    tngs_add_subpage( 'Arbres & descendance : utilisation', 'Arbres & descendance', 'tng-tree-help', function () {
        $id = array( 'id', 'Identifiant TNG de la personne (visible dans l’adresse de sa fiche TNG : personID=I12).', 'I1' );
        tngs_render_shortcode_help(
            'Arbres & descendance',
            'tree',
            array(
                array( 'code' => '[arbre_tng id="I1" generations="3"]', 'desc' => 'Arbre d’ascendance : la personne, ses parents, grands-parents, etc.', 'atts' => array( $id, array( 'generations', 'Nombre de générations d’ancêtres affichées (1 à 5).', '3' ) ) ),
                array( 'code' => '[enfants_tng id="I1"]', 'desc' => 'Descendants d’une personne, présentés avec leurs vignettes (photo ou silhouette).', 'atts' => array( $id ) ),
                array( 'code' => '[patronyme_tng id="I1" generations="5"]', 'desc' => 'Lignée patronymique : la transmission du nom de famille de père en fils.', 'atts' => array( $id, array( 'generations', 'Nombre de générations suivies.', '5' ) ) ),
                array( 'code' => '[liste_enfants_tng id="F12"]', 'desc' => 'Liste des enfants d’une famille (couple).', 'atts' => array( array( 'id', 'Identifiant TNG de la famille (F12…), obligatoire.', '—' ) ) ),
                array( 'code' => '[stats_descendants_tng id="I1"]', 'desc' => 'Statistiques de descendance : nombre de descendants à chaque génération.', 'atts' => array( $id ) ),
                array( 'code' => '[lien_fiche_tng id="I1"]', 'desc' => 'Bouton qui renvoie vers la fiche TNG de la personne.', 'atts' => array( $id ) ),
            ),
            array(
                'Les résultats sont mis en cache ; le bouton « Vider tous les caches » de TNG Suite > Général > Maintenance les renouvelle après une mise à jour de TNG.',
            )
        );
    } );
} );

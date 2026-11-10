<?php
/**
 * TNG Suite – langue de l'extension (français / anglais).
 *
 * Les textes sources sont en français. Quand la langue est l'anglais, ils sont traduits à la volée :
 *  - pages d'administration et notices : tngs_translate_html() sur la sortie de chaque page ;
 *  - shortcodes : filtre do_shortcode_tag (uniquement les textes EXACTEMENT présents dans le dictionnaire,
 *    jamais les noms, notes ou autres données issues de TNG) ;
 *  - textes construits dynamiquement : tngs_t() ;
 *  - JavaScript : dictionnaire window.TNGS_I18N.
 * En français, rien n'est modifié (aucun coût).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Langue effective : 'fr' ou 'en'. Réglage « auto » = langue de WordPress (français sinon anglais). */
function tngs_lang() {
    $pref = (string) tngs_setting( 'lang' );
    if ( 'fr' === $pref || 'en' === $pref ) {
        return $pref;
    }
    $loc = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    return 0 === strpos( (string) $loc, 'fr' ) ? 'fr' : 'en';
}

function tngs_is_en() {
    return 'en' === tngs_lang();
}

/** Dictionnaire FR => EN (clés normalisées). */
function tngs_dict() {
    static $d = null;
    if ( null === $d ) {
        $d = require TNGSUITE_DIR . 'includes/i18n-en.php';
        $d = is_array( $d ) ? $d : array();
    }
    return $d;
}

/** Dictionnaire des textes JavaScript (clés = chaîne exacte du JavaScript). */
function tngs_js_dict() {
    static $d = null;
    if ( null === $d ) {
        $d = require TNGSUITE_DIR . 'includes/i18n-js-en.php';
        $d = is_array( $d ) ? $d : array();
    }
    return $d;
}

/** Normalise un texte : entités décodées, espaces réduits, bords retirés. */
function tngs_norm( $s ) {
    $s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $s = preg_replace( '/\s+/u', ' ', $s );
    return trim( (string) $s );
}

/** Traduit un texte (anglais uniquement) ; renvoie le texte d'origine s'il n'est pas dans le dictionnaire. */
function tngs_t( $text ) {
    if ( ! tngs_is_en() ) {
        return $text;
    }
    $d = tngs_dict();
    if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/su', (string) $text, $m ) || '' === $m[2] ) {
        return $text;
    }
    $k = tngs_norm( $m[2] );
    return isset( $d[ $k ] ) ? $m[1] . $d[ $k ] . $m[3] : $text;
}

/** Traduit les attributs d'une balise (placeholder, title, alt, aria-label, bouton submit). */
function tngs_translate_tag( $tag, array $dict ) {
    return preg_replace_callback(
        '/\b(placeholder|title|alt|aria-label|value)="([^"]*)"/i',
        function ( $m ) use ( $dict, $tag ) {
            if ( 'value' === strtolower( $m[1] ) && ! preg_match( '/\btype="(?:submit|button)"/i', $tag ) ) {
                return $m[0];
            }
            $k = tngs_norm( $m[2] );
            return isset( $dict[ $k ] ) ? $m[1] . '="' . esc_attr( $dict[ $k ] ) . '"' : $m[0];
        },
        $tag
    );
}

/**
 * Traduit un fragment HTML : textes (nœuds entiers) et attributs listés ci-dessus.
 * Ne touche ni aux scripts, styles, textarea, pre, ni aux commentaires.
 */
function tngs_translate_html( $html ) {
    if ( '' === $html || ! tngs_is_en() ) {
        return $html;
    }
    $dict  = tngs_dict();
    $parts = preg_split(
        '/(<!--.*?-->|<(script|style|textarea|pre)\b[^>]*>.*?<\/\2>|<[^>]+>)/is',
        $html,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if ( false === $parts ) {
        return $html;
    }
    $out = '';
    $n   = count( $parts );
    for ( $i = 0; $i < $n; $i++ ) {
        $p = $parts[ $i ];
        if ( '' === $p ) {
            continue;
        }
        if ( '<' === $p[0] ) {
            if ( 0 === strpos( $p, '<!--' ) ) {
                $out .= $p; // commentaire.
            } elseif ( preg_match( '/^<(script|style|textarea|pre)\b/i', $p ) ) {
                $out .= $p; // bloc protégé : conservé tel quel.
                $i++;       // le groupe capturé qui suit (nom de l'élément) est ignoré.
            } else {
                $out .= tngs_translate_tag( $p, $dict );
            }
            continue;
        }
        if ( preg_match( '/^(\s*)(.*?)(\s*)$/su', $p, $m ) && '' !== $m[2] ) {
            $k    = tngs_norm( $m[2] );
            $out .= isset( $dict[ $k ] ) ? $m[1] . esc_html( $dict[ $k ] ) . $m[3] : $p;
        } else {
            $out .= $p;
        }
    }
    return $out;
}

/** Traduit la sortie d'une page d'administration (tampon de sortie). */
function tngs_admin_page( $callback ) {
    return function () use ( $callback ) {
        ob_start();
        settings_errors();
        call_user_func( $callback );
        echo tngs_translate_html( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput
    };
}

/** Shortcodes de la suite dont la sortie est traduite. */
function tngs_shortcode_tags() {
    return array(
        'arbre_tng', 'enfants_tng', 'patronyme_tng', 'liste_enfants_tng', 'stats_descendants_tng', 'lien_fiche_tng',
        'tng_link', 'tng_timeline', 'tng_heatmap', 'tng_media_gallery', 'tng_random_individual',
        'tng_name_cloud', 'tng_births_today', 'tng_marriages_today', 'tng_deaths_today',
        'tng_implexe', 'tng_consanguinite', 'tng_stat', 'tng_book', 'tng_fan', 'tng_roue',
    );
}

add_filter(
    'do_shortcode_tag',
    function ( $output, $tag ) {
        if ( ! is_string( $output ) || '' === $output || ! tngs_is_en() ) {
            return $output;
        }
        if ( in_array( $tag, tngs_shortcode_tags(), true ) || 0 === strpos( $tag, 'tng_stat_' ) ) {
            return tngs_translate_html( $output );
        }
        return $output;
    },
    10,
    2
);

/** Charge le dictionnaire JavaScript (anglais) avant le script $handle. */
function tngs_js_i18n( $handle ) {
    if ( ! tngs_is_en() ) {
        return;
    }
    wp_add_inline_script(
        $handle,
        'window.TNGS_I18N = Object.assign(window.TNGS_I18N || {}, ' . wp_json_encode( tngs_js_dict(), JSON_HEX_TAG | JSON_HEX_AMP ) . ');',
        'before'
    );
}

/** Traduit récursivement les textes d'un tableau (correspondance EXACTE d'une chaîne entière). */
function tngs_t_deep( $v ) {
    if ( ! tngs_is_en() ) {
        return $v;
    }
    if ( is_string( $v ) ) {
        return tngs_t( $v );
    }
    if ( is_array( $v ) ) {
        foreach ( $v as $k => $x ) {
            $v[ $k ] = tngs_t_deep( $x );
        }
    }
    return $v;
}

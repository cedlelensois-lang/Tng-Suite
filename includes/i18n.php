<?php
/**
 * TNG Suite – langue de l'extension (français / anglais).
 *
 * Les textes sources sont en français ; ils sont traduits à la volée par gettext (domaine « tng-suite », languages/*.mo) :
 *  - pages d'administration et notices : tngs_translate_html() sur la sortie de chaque page ;
 *  - shortcodes : filtre do_shortcode_tag (uniquement les textes EXACTEMENT présents dans le dictionnaire,
 *    jamais les noms, notes ou autres données issues de TNG) ;
 *  - textes construits dynamiquement : tngs_t() ;
 *  - JavaScript : window.TNGS_I18N (textes du catalogue, contexte « js »).
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

/**
 * Catalogue gettext (domaine « tng-suite ») : les identifiants de message sont les textes français (normalisés).
 * Langue : fichiers languages/tng-suite-{locale}.mo du plugin, ou wp-content/languages/plugins/ (Loco Translate, traduction.wordpress.org…).
 * Réglage « Anglais » ou langue de WordPress sans catalogue : tng-suite-en_US.mo.
 */
function tngs_catalog_locale() {
    $pref = (string) tngs_setting( 'lang' );
    if ( 'fr' === $pref ) {
        return '';
    }
    if ( 'en' !== $pref ) {
        $loc = (string) ( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() );
        if ( 0 === strpos( $loc, 'fr' ) ) {
            return '';
        }
        if ( '' !== $loc && tngs_catalog_file( $loc ) ) {
            return $loc;
        }
    }
    return 'en_US';
}

/** Chemin du fichier .mo d'une langue, ou ''. */
function tngs_catalog_file( $locale ) {
    $locale = preg_replace( '/[^A-Za-z_]/', '', (string) $locale );
    foreach ( array( TNGSUITE_DIR . 'languages/', WP_LANG_DIR . '/plugins/' ) as $dir ) {
        if ( file_exists( $dir . 'tng-suite-' . $locale . '.mo' ) ) {
            return $dir . 'tng-suite-' . $locale . '.mo';
        }
    }
    return '';
}

/** Charge (une fois) le catalogue de la langue effective ; false s'il n'y en a pas. */
function tngs_catalog_load() {
    static $ok = null;
    if ( null === $ok ) {
        $file = tngs_is_en() ? tngs_catalog_file( tngs_catalog_locale() ) : '';
        $ok   = false;
        if ( '' !== $file ) {
            unload_textdomain( 'tng-suite' );
            $ok = load_textdomain( 'tng-suite', $file );
        }
    }
    return $ok;
}

/** Traduction d'un texte normalisé (gettext) ; null s'il n'y en a pas. */
function tngs_lookup( $key ) {
    if ( ! tngs_catalog_load() ) {
        return null;
    }
    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain
    $t = translate( $key, 'tng-suite' );
    return ( $t !== $key && '' !== $t ) ? $t : null;
}

/** Dictionnaire des textes JavaScript (clés = chaîne exacte du JavaScript ; contexte gettext « js »). */
function tngs_js_dict() {
    static $d = null;
    if ( null === $d ) {
        $d = array();
        if ( tngs_catalog_load() ) {
            $keys = require TNGSUITE_DIR . 'includes/i18n-js-keys.php';
            foreach ( (array) $keys as $k ) {
                // phpcs:ignore WordPress.WP.I18n
                $t = translate_with_gettext_context( $k, 'js', 'tng-suite' );
                if ( $t !== $k ) {
                    $d[ $k ] = $t;
                }
            }
        }
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
    if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/su', (string) $text, $m ) || '' === $m[2] ) {
        return $text;
    }
    $t = tngs_lookup( tngs_norm( $m[2] ) );
    return null !== $t ? $m[1] . $t . $m[3] : $text;
}

/** Traduit les attributs d'une balise (placeholder, title, alt, aria-label, bouton submit). */
function tngs_translate_tag( $tag ) {
    return preg_replace_callback(
        '/\b(placeholder|title|alt|aria-label|value)="([^"]*)"/i',
        function ( $m ) use ( $tag ) {
            if ( 'value' === strtolower( $m[1] ) && ! preg_match( '/\btype="(?:submit|button)"/i', $tag ) ) {
                return $m[0];
            }
            $t = tngs_lookup( tngs_norm( $m[2] ) );
            return null !== $t ? $m[1] . '="' . esc_attr( $t ) . '"' : $m[0];
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
                $out .= tngs_translate_tag( $p );
            }
            continue;
        }
        if ( preg_match( '/^(\s*)(.*?)(\s*)$/su', $p, $m ) && '' !== $m[2] ) {
            $t    = tngs_lookup( tngs_norm( $m[2] ) );
            $out .= null !== $t ? $m[1] . esc_html( $t ) . $m[3] : $p;
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

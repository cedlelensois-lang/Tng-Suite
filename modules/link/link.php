<?php
/**
 * TNG Suite – module « Bouton vers la fiche » (ex-plugin TNG Individual Link Button 1.9).
 * Shortcode : [tng_link id="I001" tree="" text=""].
 *
 * Simple lien vers getperson.php, sans requête à la base TNG (TNG applique lui-même ses règles de
 * confidentialité sur la fiche). Pour un bouton qui lit le nom en base et masque les vivants, utilisez
 * [lien_fiche_tng] du module « Arbres & descendance ».
 * URL de TNG et arbre par défaut : réglages communs (TNG Suite > Général).
 * Apparence (emoji, texte, couleurs) : TNG Suite > Bouton vers la fiche.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TNG_LINK_VERSION', TNGSUITE_VERSION );

/* ------------------------------------------------------------------
 * Réglages propres au module
 * ------------------------------------------------------------------ */

function tng_link_defaults() {
    return array(
        'tng_emoji'       => '🔍',
        'tng_button_text' => "Voir la fiche de l'individu",
        'tng_color_bg'    => '#e0e0e0',
        'tng_color_text'  => '#000000',
        'tng_color_hover' => '#cccccc',
        'tng_color_text_hover' => '', // vide = même couleur que le texte normal
    );
}

function tng_link_opt( $name ) {
    $d = tng_link_defaults();
    return get_option( $name, isset( $d[ $name ] ) ? $d[ $name ] : '' );
}

function tng_link_color( $name ) {
    $d = tng_link_defaults();
    $c = sanitize_hex_color( (string) tng_link_opt( $name ) );
    return $c ? $c : $d[ $name ];
}

/** Couleur du texte au survol : celle choisie, sinon celle du texte normal. */
function tng_link_hover_text_color() {
    $c = sanitize_hex_color( trim( (string) tng_link_opt( 'tng_color_text_hover' ) ) );
    return $c ? $c : tng_link_color( 'tng_color_text' );
}

function tng_link_sanitize_color_cb( $name ) {
    return function ( $v ) use ( $name ) {
        $d = tng_link_defaults();
        $c = sanitize_hex_color( trim( (string) $v ) );
        return $c ? $c : $d[ $name ];
    };
}

add_action( 'admin_init', 'tng_link_register_settings' );
function tng_link_register_settings() {
    $d = tng_link_defaults();
    $g = 'tng_link_options';

    register_setting( $g, 'tng_emoji', array(
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) {
            return mb_substr( sanitize_text_field( (string) $v ), 0, 8 );
        },
        'default'           => $d['tng_emoji'],
    ) );
    register_setting( $g, 'tng_button_text', array(
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) {
            $v = sanitize_text_field( (string) $v );
            return '' === $v ? tng_link_defaults()['tng_button_text'] : $v;
        },
        'default'           => $d['tng_button_text'],
    ) );
    register_setting( $g, 'tng_color_text_hover', array(
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) {
            $c = sanitize_hex_color( trim( (string) $v ) );
            return $c ? $c : '';
        },
        'default'           => '',
    ) );
    foreach ( array( 'tng_color_bg', 'tng_color_text', 'tng_color_hover' ) as $opt ) {
        register_setting( $g, $opt, array(
            'type'              => 'string',
            'sanitize_callback' => tng_link_sanitize_color_cb( $opt ),
            'default'           => $d[ $opt ],
        ) );
    }
}

/* ------------------------------------------------------------------
 * Administration
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', 'tng_link_menu' );
function tng_link_menu() {
    tngs_add_subpage( 'Réglages TNG Link', 'Bouton vers la fiche', 'tng-link', 'tng_link_settings_page' );
}

add_action( 'admin_enqueue_scripts', 'tng_link_admin_scripts' );
function tng_link_admin_scripts( $hook ) {
    if ( false === strpos( (string) $hook, 'tng-link' ) ) {
        return;
    }
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
    wp_add_inline_script(
        'wp-color-picker',
        "jQuery(function($){ $('.color-field').wpColorPicker({ palettes: ['#0073aa', '#d54e21', '#46b450', '#f1c40f', '#333333', '#e0e0e0'] }); });"
    );
}

function tng_link_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <?php tngs_admin_header( 'Bouton vers la fiche', array( 'module' => 'link', 'db' => false ) ); ?>

        <div class="tngs-box">
            <p>
                Options facultatives : <code>tree="monarbre"</code> (sinon l'arbre par défaut), <code>text="Voir sa fiche"</code> (sinon le texte ci-dessous).<br>
                L'URL de TNG et l'arbre par défaut se règlent dans <strong>TNG Suite &gt; Général</strong>
                (actuellement : <code><?php echo esc_html( tngs_site_url() !== '' ? tngs_site_url() : 'non configurée' ); ?></code>,
                arbre <code><?php echo esc_html( tngs_gedcom() !== '' ? tngs_gedcom() : 'aucun' ); ?></code>).
            </p>
        </div>

        <h2>Aperçu</h2>
        <p><?php echo tng_link_render( 'I1', '', '', true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'tng_link_options' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tng_emoji">Emoji</label></th>
                    <td>
                        <input type="text" id="tng_emoji" name="tng_emoji" value="<?php echo esc_attr( tng_link_opt( 'tng_emoji' ) ); ?>" class="small-text">
                        <p class="description">Laisser vide pour ne pas afficher d'emoji.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_button_text">Texte du bouton</label></th>
                    <td><input type="text" id="tng_button_text" name="tng_button_text" value="<?php echo esc_attr( tng_link_opt( 'tng_button_text' ) ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_color_bg">Couleur de fond</label></th>
                    <td><input type="text" id="tng_color_bg" name="tng_color_bg" value="<?php echo esc_attr( tng_link_color( 'tng_color_bg' ) ); ?>" class="color-field"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_color_text">Couleur du texte / de l'icône</label></th>
                    <td><input type="text" id="tng_color_text" name="tng_color_text" value="<?php echo esc_attr( tng_link_color( 'tng_color_text' ) ); ?>" class="color-field"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_color_hover">Couleur au survol</label></th>
                    <td><input type="text" id="tng_color_hover" name="tng_color_hover" value="<?php echo esc_attr( tng_link_color( 'tng_color_hover' ) ); ?>" class="color-field"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_color_text_hover">Couleur du texte au survol</label></th>
                    <td>
                        <input type="text" id="tng_color_text_hover" name="tng_color_text_hover" value="<?php echo esc_attr( sanitize_hex_color( (string) tng_link_opt( 'tng_color_text_hover' ) ) ); ?>" class="color-field">
                        <p class="description">Vide = même couleur que le texte normal.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 * Rendu
 * ------------------------------------------------------------------ */

/** Feuille de style : ajoutée une seule fois, chargée uniquement si le shortcode est utilisé. */
function tng_link_enqueue_style() {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    wp_register_style( 'tng-link', false, array(), TNG_LINK_VERSION );
    wp_enqueue_style( 'tng-link' );
    wp_add_inline_style(
        'tng-link',
        '.tng-link-btn{display:inline-block;padding:10px 15px;border-radius:5px;text-decoration:none;transition:background-color .2s}'
        . '.tng-link-btn:hover,.tng-link-btn:focus{background-color:var(--tngl-hover)!important;color:var(--tngl-hover-fg)!important}'
    );
}

/**
 * Construit le bouton. Le survol passe par une variable CSS (plus de JavaScript en ligne).
 *
 * @param string $id      Identifiant TNG de l'individu (ex. I001).
 * @param string $tree    Arbre (sinon celui des réglages communs).
 * @param string $text    Texte (sinon celui des réglages).
 * @param bool   $preview Aperçu en administration : pas de feuille de style publique.
 */
function tng_link_render( $id, $tree = '', $text = '', $preview = false ) {
    $id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
    if ( '' === $id ) {
        return '';
    }

    $base = tngs_site_url();
    if ( '' === $base ) {
        return current_user_can( 'manage_options' )
            ? '<p><em>TNG Link : URL de TNG non configurée (TNG Suite &gt; Général).</em></p>'
            : '';
    }

    $tree = '' !== $tree ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $tree ) : tngs_gedcom();

    $args = array( 'personID' => $id );
    if ( '' !== $tree ) {
        $args['tree'] = $tree;
    }
    $url = add_query_arg( $args, $base . 'getperson.php' );

    $label = '' !== $text ? $text : (string) tng_link_opt( 'tng_button_text' );
    $emoji = (string) tng_link_opt( 'tng_emoji' );

    $bg    = tng_link_color( 'tng_color_bg' );
    $fg    = tng_link_color( 'tng_color_text' );
    $hover = tng_link_color( 'tng_color_hover' );
    $hfg   = tng_link_hover_text_color();

    $style = sprintf( 'background-color:%s;color:%s;--tngl-hover:%s;--tngl-hover-fg:%s;', $bg, $fg, $hover, $hfg );

    if ( $preview ) {
        // L'aperçu admin n'a pas le CSS public : on inclut l'essentiel en ligne.
        $style .= 'padding:10px 15px;text-decoration:none;border-radius:5px;display:inline-block;';
    } else {
        tng_link_enqueue_style();
    }

    return sprintf(
        '<a class="tng-link-btn" href="%s" target="_blank" rel="noopener noreferrer" style="%s">%s</a>',
        esc_url( $url ),
        esc_attr( $style ),
        esc_html( trim( $emoji . ' ' . $label ) )
    );
}

add_shortcode( 'tng_link', 'tng_link_shortcode' );
function tng_link_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'id' => '', 'tree' => '', 'text' => '' ), $atts, 'tng_link' );
    if ( '' === trim( (string) $atts['id'] ) ) {
        return '';
    }
    return tng_link_render( $atts['id'], sanitize_text_field( $atts['tree'] ), sanitize_text_field( $atts['text'] ) );
}

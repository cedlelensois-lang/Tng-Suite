<?php
/**
 * TNG Suite – cœur commun : réglages partagés, connexion unique à la base TNG
 * (wp-config.php, saisie dans le plugin ou base WordPress), confidentialité, cache,
 * migration des anciens plugins.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * 1. MODES DE CONNEXION
 *    config : constantes DB_HOSTTNG / DB_NAMETNG / DB_USERTNG / DB_PASSWORDTNG dans wp-config.php
 *    plugin : identifiants saisis dans TNG Suite > Général (mot de passe chiffré)
 *    wp     : tables TNG dans la base de données de WordPress
 * ------------------------------------------------------------------ */

/** wpdb dont bail() ne fait ni wp_die() ni affichage : l'échec est géré proprement. */
class TNGS_DB extends wpdb {
    public function bail( $message, $error_code = '500' ) {
        $this->error = new WP_Error( $error_code, $message );
        return false;
    }
}

function tngs_has_constants() {
    foreach ( array( 'DB_HOSTTNG', 'DB_NAMETNG', 'DB_USERTNG', 'DB_PASSWORDTNG' ) as $c ) {
        if ( ! defined( $c ) ) {
            return false;
        }
    }
    return true;
}

function tngs_conn_mode() {
    $m = (string) tngs_setting( 'conn_mode' );
    return in_array( $m, array( 'config', 'plugin', 'wp' ), true ) ? $m : 'config';
}

/** true si la base TNG est distincte de celle de WordPress. */
function tngs_conn_dedicated() {
    return 'wp' !== tngs_conn_mode();
}

/* --- Chiffrement du mot de passe saisi dans le plugin (libsodium, fourni par PHP ou par WordPress) --- */

function tngs_crypto_key() {
    return hash( 'sha256', wp_salt( 'auth' ) . '|tng-suite-conn', true );
}

/** @return string|false 'enc1:…' ; '' si vide ; false si le chiffrement est indisponible. */
function tngs_encrypt( $plain ) {
    $plain = (string) $plain;
    if ( '' === $plain ) {
        return '';
    }
    if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
        return false;
    }
    try {
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        return 'enc1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, tngs_crypto_key() ) );
    } catch ( Exception $e ) {
        return false;
    }
}

/** Retourne le mot de passe en clair, ou '' (vide / illisible : clés de sécurité modifiées). */
function tngs_decrypt( $stored ) {
    $stored = (string) $stored;
    if ( 0 !== strpos( $stored, 'enc1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
        return '';
    }
    $raw = base64_decode( substr( $stored, 5 ), true );
    if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
        return '';
    }
    $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, tngs_crypto_key() );
    return false === $plain ? '' : $plain;
}

/** true si un mot de passe est enregistré mais ne peut plus être déchiffré. */
function tngs_conn_pass_unreadable() {
    $c = tngs_setting( 'conn' );
    return is_array( $c ) && ! empty( $c['pass'] ) && '' === tngs_decrypt( $c['pass'] );
}

/**
 * Identifiants de la base TNG selon le mode, ou false si incomplets.
 *
 * @return array|false [host, name, user, pass, charset, collate]
 */
function tngs_conn_credentials() {
    $mode = tngs_conn_mode();

    if ( 'config' === $mode ) {
        if ( ! tngs_has_constants() ) {
            return false;
        }
        return array(
            'host'    => DB_HOSTTNG,
            'name'    => DB_NAMETNG,
            'user'    => DB_USERTNG,
            'pass'    => DB_PASSWORDTNG,
            'charset' => ( defined( 'DB_CHARSETTNG' ) && preg_match( '/^[A-Za-z0-9_]+$/', (string) DB_CHARSETTNG ) ) ? DB_CHARSETTNG : '',
            'collate' => ( defined( 'DB_COLLATETNG' ) && preg_match( '/^[A-Za-z0-9_]+$/', (string) DB_COLLATETNG ) ) ? DB_COLLATETNG : '',
        );
    }

    if ( 'plugin' === $mode ) {
        $c = tngs_setting( 'conn' );
        $c = is_array( $c ) ? $c : array();
        if ( empty( $c['host'] ) || empty( $c['name'] ) || empty( $c['user'] ) ) {
            return false;
        }
        return array(
            'host'    => $c['host'],
            'name'    => $c['name'],
            'user'    => $c['user'],
            'pass'    => tngs_decrypt( isset( $c['pass'] ) ? $c['pass'] : '' ),
            'charset' => ( ! empty( $c['charset'] ) && preg_match( '/^[A-Za-z0-9_]+$/', $c['charset'] ) ) ? $c['charset'] : '',
            'collate' => '',
        );
    }

    return false;
}

/** Les réglages de connexion sont-ils complets pour le mode choisi ? */
function tngs_conn_ready() {
    return 'wp' === tngs_conn_mode() || (bool) tngs_conn_credentials();
}

/**
 * Connexion wpdb unique vers la base TNG, ou false.
 * Après un échec : pas de nouvelle tentative pendant 60 s (sauf $force).
 */
function tngs_db( $force = false ) {
    static $db = null, $failed = false;

    if ( $db ) {
        return $db;
    }
    if ( ! $force && ( $failed || get_transient( 'tngs_db_down' ) ) ) {
        return false;
    }

    if ( 'wp' === tngs_conn_mode() ) {
        global $wpdb;
        $db = $wpdb;
        return $db;
    }

    $cred = tngs_conn_credentials();
    if ( ! $cred ) {
        $failed = true;
        return false;
    }

    $candidate = new TNGS_DB( $cred['user'], $cred['pass'], $cred['name'], $cred['host'] );
    if ( empty( $candidate->ready ) || ! empty( $candidate->error ) ) {
        error_log( '[TNG Suite] Connexion à la base TNG impossible.' );
        set_transient( 'tngs_db_down', 1, 60 );
        $failed = true;
        return false;
    }

    $candidate->hide_errors();
    $candidate->suppress_errors( true );

    if ( '' !== $cred['charset'] ) {
        $candidate->set_charset( $candidate->dbh, $cred['charset'], $cred['collate'] );
    }

    delete_transient( 'tngs_db_down' );
    $db = $candidate;
    return $db;
}

/** Message lisible (jamais de détail MySQL brut) pour les administrateurs. */
function tngs_db_error_message() {
    switch ( tngs_conn_mode() ) {
        case 'config':
            return tngs_has_constants()
                ? 'Connexion à la base TNG impossible (vérifiez les constantes DB_*TNG de wp-config.php).'
                : 'Constantes DB_HOSTTNG, DB_NAMETNG, DB_USERTNG, DB_PASSWORDTNG absentes de wp-config.php (voir TNG Suite > Général).';
        case 'plugin':
            return tngs_conn_pass_unreadable()
                ? 'Le mot de passe enregistré est illisible (clés de sécurité modifiées) : ressaisissez-le dans TNG Suite > Général.'
                : 'Connexion à la base TNG impossible (vérifiez les identifiants dans TNG Suite > Général).';
    }
    return 'Base de données WordPress inaccessible.';
}

/* ------------------------------------------------------------------
 * 2. RÉGLAGES PARTAGÉS
 * ------------------------------------------------------------------ */

/**
 * Modules : slug => [ libellé, shortcodes, ce que contrôle « vivants / privés » (null = sans objet) ].
 */
function tngs_modules() {
    return array(
        'tree'     => array( 'Arbres & descendance', '[arbre_tng] [enfants_tng] [patronyme_tng] [liste_enfants_tng] [stats_descendants_tng] [lien_fiche_tng]', 'Noms, dates et liens des personnes vivantes ou privées dans les arbres et les listes.' ),
        'link'     => array( 'Bouton vers la fiche', '[tng_link id="I1"]', null ),
        'timeline' => array( 'Frise chronologique', '[tng_timeline]', 'Événements des personnes vivantes ou privées dans la frise.' ),
        'heatmap'  => array( 'Heatmap des lieux', '[tng_heatmap]', 'Lieux et dates des personnes vivantes ou privées dans la carte.' ),
        'fan'      => array( 'Éventail / roue de parenté', '[tng_fan person="I1"] [tng_roue]', 'Noms, dates et lieux des personnes vivantes ou privées dans le graphique. Le paramètre living="show" du shortcode permet de les afficher sur une roue précise, sauf si « Toujours masquer » est choisi ici.' ),
        'stats'    => array( 'Statistiques & graphiques', '[tng_stat_genre] [tng_stat_vivants] [tng_stat_demi_siecle] [tng_stat_mois] … (TNG Suite > Stats & analyses > Stats : graphiques)', 'Seuls des totaux sont publiés : « Masquer » cache le nom des personnes privées dans la liste des centenaires.' ),
        'gallery'  => array( 'Galerie de médias', '[tng_media_gallery]', 'Médias liés à des personnes vivantes ou privées.' ),
        'random'   => array( 'Individu au hasard', '[tng_random_individual]', 'Tirage au sort parmi les personnes vivantes ou privées.' ),
        'extra'    => array( 'Nuage de noms & anniversaires', '[tng_name_cloud] [tng_births_today] [tng_marriages_today] [tng_deaths_today] [tng_recent_updates]', 'Noms de famille et anniversaires du jour des personnes vivantes ou privées.' ),
        'implexe'  => array( 'Implexe & consanguinité', '[tng_implexe] [tng_consanguinite]', 'Noms des personnes vivantes ou privées dans les graphes et les listes.' ),
        'flash'    => array( 'Liste flash des patronymes', '[tng_flash]', 'Dates et lieux des personnes vivantes ou privées dans les lignes de la liste (nombre de personnes, dates minimale et maximale, lieux).' ),
        'coherence' => array( 'Cohérence des données', '(aucun shortcode : page d’administration réservée aux administrateurs)', null ),
        'book'     => array( 'Livre généalogique', '[tng_book] (et génération depuis l\'administration)', 'Personnes vivantes ou privées dans le livre (noms, dates, photos).' ),
        'fiche'    => array( 'Fiche & listes de documents', '[tng_listes]', 'Noms et dates des personnes vivantes ou privées dans les listes et les fiches individuelles.' ),
    );
}

/**
 * Modules fusionnés dans un autre pour l'utilisateur : alias => module principal.
 * Les clés d'origine (link, random) restent valables partout (shortcodes, réglages enregistrés, dossiers) ;
 * seuls l'activation, la confidentialité et l'affichage des réglages suivent le module principal.
 */
function tngs_module_aliases() {
    return array( 'link' => 'tree', 'random' => 'extra' );
}

/** Module principal d'un module (lui-même s'il n'est pas fusionné). */
function tngs_module_root( $slug ) {
    $a = tngs_module_aliases();
    return isset( $a[ $slug ] ) ? $a[ $slug ] : $slug;
}

/** Modules tels que l’utilisateur les voit : 11 lignes (link et random sont regroupés avec tree et extra). */
function tngs_modules_ui() {
    $all   = tngs_modules();
    $label = array(
        'tree'  => 'Arbres, descendance & bouton vers la fiche',
        'extra' => 'Nuage de noms, anniversaires & individu au hasard',
    );
    $out = array();
    foreach ( $all as $slug => $info ) {
        if ( tngs_module_root( $slug ) !== $slug ) {
            continue;
        }
        foreach ( tngs_module_aliases() as $al => $root ) {
            if ( $root === $slug && isset( $all[ $al ] ) ) {
                $info[1] .= ' ' . $all[ $al ][1];
            }
        }
        if ( isset( $label[ $slug ] ) ) {
            $info[0] = $label[ $slug ];
        }
        $out[ $slug ] = $info;
    }
    return $out;
}

/**
 * Familles du menu d'administration : clé => [ libellé, [ modules ] ].
 * Un onglet par famille ; chaque page de ses modules y devient une section.
 */
function tngs_families() {
    return array(
        'trees'   => array( 'Arbres & visualisations', array( 'tree', 'link', 'timeline', 'fan' ) ),
        'analyse' => array( 'Stats & analyses', array( 'stats', 'heatmap', 'implexe', 'coherence' ) ),
        'docs'    => array( 'Documents', array( 'book', 'fiche', 'flash' ) ),
        'media'   => array( 'Médias & widgets', array( 'gallery', 'extra', 'random' ) ),
    );
}

function tngs_conn_defaults() {
    return array( 'host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '', 'charset' => '' );
}

function tngs_defaults() {
    return array(
        'lang'        => 'auto',   // auto (langue de WordPress) | fr | en
        'conn_mode'   => 'config',
        'conn'        => tngs_conn_defaults(),
        'site_url'    => '',
        'db_prefix'   => 'tng_',
        'gedcom'      => '',
        'hide_living' => 1,
        'cache_hours' => 12,
        'modules'     => array_fill_keys( array_keys( tngs_modules() ), 1 ),
        // Vivants / privés par module : default (= réglage commun) | hide | show.
        // « Individu au hasard » et les anniversaires étaient toujours masqués : ils le restent par défaut.
        'privacy'     => array_merge(
            array_fill_keys( array_keys( tngs_modules() ), 'default' ),
            array( 'random' => 'hide', 'extra' => 'hide' )
        ),
    );
}

function tngs_valid_prefix( $p ) {
    return is_string( $p ) && 1 === preg_match( '/^[A-Za-z0-9_]{1,32}$/', $p );
}

function tngs_sanitize_settings( $in ) {
    $d   = tngs_defaults();
    $in  = is_array( $in ) ? $in : array();
    $old = get_option( 'tngs_settings', array() );
    $old = is_array( $old ) ? $old : array();
    $out = $d;

    $url = isset( $in['site_url'] ) ? trim( (string) $in['site_url'] ) : '';
    $out['site_url'] = '' === $url ? '' : esc_url_raw( $url );

    $prefix = isset( $in['db_prefix'] ) ? trim( (string) $in['db_prefix'] ) : 'tng_';
    if ( ! tngs_valid_prefix( $prefix ) ) {
        add_settings_error( 'tngs_settings', 'tngs_bad_prefix', 'Préfixe invalide (lettres, chiffres, _ ; 32 max). « tng_ » a été utilisé.', 'error' );
        $prefix = 'tng_';
    }
    $out['db_prefix'] = $prefix;

    $out['gedcom']      = isset( $in['gedcom'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['gedcom'] ) : '';
    $out['hide_living'] = empty( $in['hide_living'] ) ? 0 : 1;
    $out['cache_hours'] = isset( $in['cache_hours'] ) ? min( 720, absint( $in['cache_hours'] ) ) : 12;

    foreach ( array_keys( $d['modules'] ) as $m ) {
        $r                    = tngs_module_root( $m );
        $out['modules'][ $m ] = empty( $in['modules'][ $r ] ) ? 0 : 1; // link suit tree, random suit extra.
    }

    $lang        = isset( $in['lang'] ) ? (string) $in['lang'] : 'auto';
    $out['lang'] = in_array( $lang, array( 'auto', 'fr', 'en' ), true ) ? $lang : 'auto';

    foreach ( $d['privacy'] as $m => $def ) {
        $r                  = tngs_module_root( $m );
        $v                  = isset( $in['privacy'][ $r ] ) ? (string) $in['privacy'][ $r ] : $def;
        $out['privacy'][ $m ] = in_array( $v, array( 'default', 'hide', 'show' ), true ) ? $v : $def;
    }

    // --- Connexion ---
    $mode             = isset( $in['conn_mode'] ) ? (string) $in['conn_mode'] : 'config';
    $out['conn_mode'] = in_array( $mode, array( 'config', 'plugin', 'wp' ), true ) ? $mode : 'config';

    $ci   = isset( $in['conn'] ) && is_array( $in['conn'] ) ? $in['conn'] : array();
    $oc   = isset( $old['conn'] ) && is_array( $old['conn'] ) ? $old['conn'] : array();
    $conn = tngs_conn_defaults();

    $host = isset( $ci['host'] ) ? trim( (string) $ci['host'] ) : '';
    if ( '' === $host ) {
        $conn['host'] = 'localhost';
    } elseif ( preg_match( '/^[A-Za-z0-9.\-_:\/\[\]]{1,255}$/', $host ) ) {
        $conn['host'] = $host;
    } else {
        add_settings_error( 'tngs_settings', 'tngs_bad_host', 'Hôte MySQL invalide.', 'error' );
        $conn['host'] = 'localhost';
    }

    $name = isset( $ci['name'] ) ? trim( (string) $ci['name'] ) : '';
    if ( '' !== $name && ! preg_match( '/^[A-Za-z0-9_$\-]{1,64}$/', $name ) ) {
        add_settings_error( 'tngs_settings', 'tngs_bad_dbname', 'Nom de base invalide.', 'error' );
        $name = '';
    }
    $conn['name']    = $name;
    $conn['user']    = mb_substr( sanitize_text_field( isset( $ci['user'] ) ? (string) $ci['user'] : '' ), 0, 80 );
    $charset         = isset( $ci['charset'] ) ? trim( (string) $ci['charset'] ) : '';
    $conn['charset'] = preg_match( '/^[A-Za-z0-9_]{1,32}$/', $charset ) ? $charset : '';

    // Mot de passe : vide = conserver ; case « effacer » ; jamais conservé hors du mode « plugin ».
    $conn['pass'] = isset( $oc['pass'] ) ? (string) $oc['pass'] : '';
    if ( ! empty( $ci['clear_pass'] ) ) {
        $conn['pass'] = '';
    } elseif ( isset( $ci['pass'] ) && '' !== (string) $ci['pass'] ) {
        if ( 0 === strpos( (string) $ci['pass'], 'enc1:' ) && '' !== tngs_decrypt( (string) $ci['pass'] ) ) {
            $conn['pass'] = (string) $ci['pass']; // déjà chiffré (seconde passe de WordPress)
            $enc          = $conn['pass'];
        } else {
            $enc = tngs_encrypt( (string) $ci['pass'] );
        }
        if ( false === $enc ) {
            add_settings_error( 'tngs_settings', 'tngs_no_crypto', 'Chiffrement indisponible sur ce serveur : mot de passe non enregistré. Utilisez wp-config.php.', 'error' );
        } else {
            $conn['pass'] = $enc;
        }
    }
    if ( 'plugin' !== $out['conn_mode'] ) {
        $conn['pass'] = '';
    } elseif ( '' === $conn['name'] || '' === $conn['user'] ) {
        add_settings_error( 'tngs_settings', 'tngs_conn_incomplete', 'Mode « saisie dans le plugin » : renseignez au minimum la base et l\'utilisateur.', 'warning' );
    }
    $out['conn'] = $conn;

    return $out;
}

function tngs_settings() {
    $o = get_option( 'tngs_settings', array() );
    $o = is_array( $o ) ? $o : array();
    $d = tngs_defaults();
    $s = array_merge( $d, $o );

    $s['modules'] = array_merge( $d['modules'], isset( $o['modules'] ) && is_array( $o['modules'] ) ? $o['modules'] : array() );
    $s['conn']    = array_merge( $d['conn'], isset( $o['conn'] ) && is_array( $o['conn'] ) ? $o['conn'] : array() );
    $s['privacy'] = array_merge( $d['privacy'], isset( $o['privacy'] ) && is_array( $o['privacy'] ) ? $o['privacy'] : array() );

    // Compatibilité 1.0 / 1.1 : ancienne case « tables dans la base WordPress ».
    if ( ! isset( $o['conn_mode'] ) ) {
        $s['conn_mode'] = ! empty( $o['use_wp_db'] ) ? 'wp' : 'config';
    }
    return $s;
}

function tngs_setting( $key ) {
    $s = tngs_settings();
    return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

function tngs_module_enabled( $slug ) {
    $s = tngs_settings();
    return ! empty( $s['modules'][ tngs_module_root( $slug ) ] );
}

function tngs_prefix() {
    $p = (string) tngs_setting( 'db_prefix' );
    return tngs_valid_prefix( $p ) ? $p : 'tng_';
}

function tngs_gedcom() {
    return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) tngs_setting( 'gedcom' ) );
}

/** Réglage « vivants / privés » d'un module : default | hide | show. */
function tngs_privacy_mode( $slug ) {
    $p    = tngs_setting( 'privacy' );
    $slug = tngs_module_root( $slug );
    $m    = ( is_array( $p ) && isset( $p[ $slug ] ) ) ? (string) $p[ $slug ] : 'default';
    return in_array( $m, array( 'hide', 'show' ), true ) ? $m : 'default';
}

/**
 * true = masquer les personnes vivantes / privées (défaut, RGPD).
 *
 * @param string $slug Module (tree, timeline…). Vide = réglage commun seul.
 */
function tngs_hide_living( $slug = '' ) {
    if ( '' !== $slug ) {
        $m = tngs_privacy_mode( $slug );
        if ( 'hide' === $m ) {
            return true;
        }
        if ( 'show' === $m ) {
            return false;
        }
    }
    return (bool) tngs_setting( 'hide_living' );
}

/** Libellés des modules actifs qui affichent des personnes vivantes ou privées. */
function tngs_living_exposed_modules() {
    $out = array();
    foreach ( tngs_modules_ui() as $slug => $info ) {
        if ( $info[2] && tngs_module_enabled( $slug ) && ! tngs_hide_living( $slug ) ) {
            $out[] = $info[0];
        }
    }
    return $out;
}

/** URL de TNG avec slash final, ou ''. */
function tngs_site_url() {
    $u = (string) tngs_setting( 'site_url' );
    return '' === $u ? '' : trailingslashit( esc_url_raw( $u ) );
}

function tngs_cache_hours() {
    return max( 0, (int) tngs_setting( 'cache_hours' ) );
}

/** Version de cache globale : change à chaque « Vider les caches » ou modification des réglages. */
function tngs_cache_ver() {
    return (string) get_option( 'tngs_cache_ver', '1' );
}

/** Vide tous les caches de tous les modules. */
function tngs_flush_all() {
    update_option( 'tngs_cache_ver', (string) microtime( true ), false );
    delete_transient( 'tngs_db_down' );
    do_action( 'tngs_flush_cache' );
}

// Toute modification des réglages partagés invalide les caches.
add_action( 'update_option_tngs_settings', 'tngs_flush_all' );
add_action( 'add_option_tngs_settings', 'tngs_flush_all' );

/* ------------------------------------------------------------------
 * 3. MIGRATION DEPUIS LES ANCIENS PLUGINS AUTONOMES
 * ------------------------------------------------------------------ */

/** Plugins reconnus, dans l'ordre de priorité des valeurs. */
function tngs_legacy_labels() {
    return array( 'Tree', 'Extra', 'Galerie', 'Random', 'Timeline', 'Heatmap', 'Link', 'Book', 'Implexe', 'Stats' );
}

/**
 * Lit les réglages des anciens plugins (sans rien modifier).
 *
 * @param string[] $labels Plugins à lire.
 * @return array urls, prefixes, geds, privacy (bool = masquer), conns, wp (signaux « base WordPress »).
 */
function tngs_legacy_collect( array $labels ) {
    $want = array_flip( $labels );
    $src  = array( 'urls' => array(), 'prefixes' => array(), 'geds' => array(), 'privacy' => array(), 'conns' => array(), 'wp' => array() );
    $str  = function ( $bucket, $label, $v ) use ( &$src ) {
        if ( is_string( $v ) && '' !== trim( $v ) ) {
            $src[ $bucket ][ $label ] = trim( $v );
        }
    };
    $has = function ( $opt ) {
        return false !== get_option( $opt, false );
    };

    if ( isset( $want['Tree'] ) ) {
        $str( 'urls', 'Tree', get_option( 'tng_site_url', '' ) );
        $str( 'prefixes', 'Tree', get_option( 'tng_db_prefix', '' ) );
        $str( 'geds', 'Tree', get_option( 'tng_gedcom', '' ) );
        if ( $has( 'tng_hide_living' ) ) {
            $src['privacy']['Tree'] = '1' === (string) get_option( 'tng_hide_living' );
        }
    }
    if ( isset( $want['Extra'] ) ) {
        $str( 'urls', 'Extra', get_option( 'tng_base_url', '' ) );
    }
    if ( isset( $want['Galerie'] ) ) {
        $str( 'urls', 'Galerie', get_option( 'tng_mg_base_url', '' ) );
        $str( 'prefixes', 'Galerie', get_option( 'tng_mg_db_prefix', '' ) );
        if ( $has( 'tng_mg_hide_private' ) ) {
            $src['privacy']['Galerie'] = 1 === (int) get_option( 'tng_mg_hide_private' );
        }
    }
    if ( isset( $want['Random'] ) ) {
        $str( 'urls', 'Random', get_option( 'tri_tng_url', '' ) );
        $str( 'prefixes', 'Random', get_option( 'tri_table_prefix', '' ) );
        $str( 'geds', 'Random', get_option( 'tri_db_tree', '' ) );
        if ( $has( 'tri_table_prefix' ) ) {
            $src['wp']['Random'] = true;
        }
    }
    if ( isset( $want['Timeline'] ) ) {
        $tl = get_option( 'tng_timeline_settings', array() );
        $tl = is_array( $tl ) ? $tl : array();
        $str( 'urls', 'Timeline', isset( $tl['tng_url'] ) ? $tl['tng_url'] : '' );
        $str( 'prefixes', 'Timeline', isset( $tl['db_prefix'] ) ? $tl['db_prefix'] : '' );
        if ( isset( $tl['respect_privacy'] ) ) {
            $src['privacy']['Timeline'] = (bool) $tl['respect_privacy'];
        }
    }
    if ( isset( $want['Heatmap'] ) ) {
        $str( 'prefixes', 'Heatmap', defined( 'DB_PREFIXTNG' ) ? DB_PREFIXTNG : '' );
        if ( $has( 'tng_include_private' ) ) {
            $src['privacy']['Heatmap'] = 1 !== (int) get_option( 'tng_include_private' );
        }
    }
    if ( isset( $want['Link'] ) ) {
        $str( 'urls', 'Link', get_option( 'tng_url', '' ) );
        $str( 'geds', 'Link', get_option( 'tng_tree', '' ) );
    }
    if ( isset( $want['Book'] ) ) {
        $bk = get_option( 'tngbook_options', null );
        if ( is_array( $bk ) ) {
            $str( 'prefixes', 'Book', isset( $bk['prefix'] ) ? $bk['prefix'] : '' );
            $path = isset( $bk['tng_path'] ) ? (string) $bk['tng_path'] : '';
            if ( preg_match( '#^https?://#i', $path ) ) {
                $str( 'urls', 'Book', $path );
            }
            if ( isset( $bk['living'] ) ) {
                $src['privacy']['Book'] = 'include' !== $bk['living'];
            }
            $name = defined( 'TNGBOOK_DB_NAME' ) ? TNGBOOK_DB_NAME : ( isset( $bk['db_name'] ) ? $bk['db_name'] : '' );
            if ( '' !== (string) $name ) {
                $src['conns']['Book'] = array(
                    'host' => defined( 'TNGBOOK_DB_HOST' ) ? TNGBOOK_DB_HOST : ( ! empty( $bk['db_host'] ) ? $bk['db_host'] : DB_HOST ),
                    'name' => (string) $name,
                    'user' => defined( 'TNGBOOK_DB_USER' ) ? TNGBOOK_DB_USER : ( isset( $bk['db_user'] ) ? $bk['db_user'] : '' ),
                    'pass' => defined( 'TNGBOOK_DB_PASS' ) ? TNGBOOK_DB_PASS : ( isset( $bk['db_pass'] ) ? $bk['db_pass'] : '' ),
                );
            } else {
                $src['wp']['Book'] = true;
            }
        }
    }
    if ( isset( $want['Implexe'] ) ) {
        $im = get_option( 'tngi_options', null );
        if ( is_array( $im ) ) {
            $str( 'prefixes', 'Implexe', isset( $im['prefix'] ) ? $im['prefix'] : '' );
            $str( 'geds', 'Implexe', isset( $im['default_tree'] ) ? $im['default_tree'] : '' );
            if ( isset( $im['mask_living'] ) ) {
                $src['privacy']['Implexe'] = (bool) $im['mask_living'];
            }
            if ( isset( $im['db_mode'] ) && 'external' === $im['db_mode'] && ! empty( $im['db_name'] ) ) {
                $src['conns']['Implexe'] = array(
                    'host' => ! empty( $im['db_host'] ) ? $im['db_host'] : 'localhost',
                    'name' => (string) $im['db_name'],
                    'user' => isset( $im['db_user'] ) ? (string) $im['db_user'] : '',
                    'pass' => isset( $im['db_pass'] ) ? (string) $im['db_pass'] : '',
                );
            } else {
                $src['wp']['Implexe'] = true;
            }
        }
    }
    if ( isset( $want['Stats'] ) ) {
        $st = get_option( 'tngs_options', null );
        if ( is_array( $st ) ) {
            $str( 'prefixes', 'Stats', isset( $st['prefix'] ) ? $st['prefix'] : '' );
            $str( 'geds', 'Stats', isset( $st['default_tree'] ) ? $st['default_tree'] : '' );
            if ( isset( $st['mask_private'] ) ) {
                $src['privacy']['Stats'] = (bool) $st['mask_private'];
            }
            $mode = isset( $st['db_mode'] ) ? $st['db_mode'] : 'wp';
            if ( 'external' === $mode && ! empty( $st['db_name'] ) ) {
                $src['conns']['Stats'] = array(
                    'host' => ! empty( $st['db_host'] ) ? $st['db_host'] : 'localhost',
                    'name' => (string) $st['db_name'],
                    'user' => isset( $st['db_user'] ) ? (string) $st['db_user'] : '',
                    'pass' => isset( $st['db_pass'] ) ? (string) $st['db_pass'] : '',
                );
            } elseif ( 'wp' === $mode ) {
                $src['wp']['Stats'] = true;
            }
        }
    }
    return $src;
}

/**
 * Applique les valeurs lues dans $src à $s.
 *
 * @param array $s     Réglages courants.
 * @param array $src   Résultat de tngs_legacy_collect().
 * @param bool  $fresh true = première migration (écrit tout) ; false = import ultérieur (ne remplace que les valeurs par défaut).
 * @return array [ réglages, notes ]
 */
function tngs_legacy_apply( array $s, array $src, $fresh ) {
    $notes = array();

    foreach ( array( 'site_url' => 'urls', 'db_prefix' => 'prefixes', 'gedcom' => 'geds' ) as $key => $bucket ) {
        $found = $src[ $bucket ];
        if ( ! $found ) {
            continue;
        }
        $cur        = (string) $s[ $key ];
        $is_default = ( 'db_prefix' === $key ) ? ( 'tng_' === $cur ) : ( '' === $cur );
        $pick       = reset( $found );

        if ( $fresh || $is_default ) {
            $s[ $key ] = $pick;
            $cur       = $pick;
        }
        $distinct = array_unique( array_map( 'untrailingslashit', array_merge( $found, array( $cur ) ) ) );
        if ( count( $distinct ) > 1 ) {
            $parts = array();
            foreach ( $found as $label => $v ) {
                $parts[] = $label . ' = ' . $v;
            }
            $notes[] = tngs_t( 'Valeurs différentes trouvées pour' ) . ' "' . $key . '" (' . implode( ' ; ', $parts ) . '). ' . tngs_t( 'Retenu :' ) . ' ' . $cur . '.';
        }
    }

    // Confidentialité par module : on ne reprend que les cas où l'ancien plugin AFFICHAIT les vivants.
    $slugs = array( 'Tree' => 'tree', 'Galerie' => 'gallery', 'Timeline' => 'timeline', 'Heatmap' => 'heatmap', 'Book' => 'book', 'Implexe' => 'implexe', 'Stats' => 'stats' );
    foreach ( $src['privacy'] as $label => $hide ) {
        if ( false === $hide && isset( $slugs[ $label ] ) && 'default' === $s['privacy'][ $slugs[ $label ] ] ) {
            $s['privacy'][ $slugs[ $label ] ] = 'show';
            $notes[] = tngs_t( 'L\'ancien plugin' ) . ' "' . $label . '" ' . tngs_t( 'affichait les personnes vivantes : le module garde ce comportement (⚠️ RGPD, réglable dans Général > Modules).' );
        }
    }

    // Connexion : uniquement si rien ne fonctionne encore (ni constantes DB_*TNG, ni mode déjà choisi).
    if ( ! tngs_has_constants() && 'config' === $s['conn_mode'] ) {
        if ( $src['conns'] ) {
            $label = key( $src['conns'] );
            $c     = reset( $src['conns'] );
            $enc   = tngs_encrypt( $c['pass'] );
            if ( false !== $enc ) {
                $s['conn_mode'] = 'plugin';
                $s['conn']      = array_merge( tngs_conn_defaults(), array(
                    'host' => $c['host'] ? $c['host'] : 'localhost',
                    'name' => $c['name'],
                    'user' => $c['user'],
                    'pass' => $enc,
                ) );
                $sigs = array();
                foreach ( $src['conns'] as $x ) {
                    $sigs[ $x['host'] . '/' . $x['name'] . '/' . $x['user'] ] = true;
                }
                if ( count( $sigs ) > 1 ) {
                    $notes[] = tngs_t( 'Plusieurs connexions différentes trouvées' ) . ' (' . implode( ' ; ', array_keys( $src['conns'] ) ) . '). ' . tngs_t( 'Reprise de celle de' ) . ' "' . $label . '" : ' . tngs_t( 'vérifiez-la.' );
                }
            }
        } elseif ( $src['wp'] ) {
            $s['conn_mode'] = 'wp';
        }
    }

    return array( $s, $notes );
}

/** Marque des plugins comme importés. */
function tngs_legacy_mark_done( array $labels ) {
    $done = get_option( 'tngs_migrated', array() );
    $done = is_array( $done ) ? $done : array();
    update_option( 'tngs_migrated', array_values( array_unique( array_merge( $done, $labels ) ) ), false );
}

function tngs_maybe_migrate() {
    if ( false !== get_option( 'tngs_settings', false ) ) {
        return;
    }
    $labels = tngs_legacy_labels();
    $src    = tngs_legacy_collect( $labels );
    list( $s, $notes ) = tngs_legacy_apply( tngs_defaults(), $src, true );

    $s['db_prefix'] = tngs_valid_prefix( $s['db_prefix'] ) ? $s['db_prefix'] : 'tng_';
    if ( false !== get_option( 'tng_cache_hours', false ) ) {
        $s['cache_hours'] = min( 720, absint( get_option( 'tng_cache_hours' ) ) );
    }

    add_option( 'tngs_settings', $s );
    tngs_legacy_mark_done( $labels );
    if ( $notes ) {
        update_option( 'tngs_migration_notes', $notes, false );
    }
}

/**
 * Import ponctuel des plugins ajoutés après la première installation de la suite
 * (Link : 1.1 ; Livre et Implexe : 1.2 ; Stats : 1.3). N'écrase jamais un réglage déjà personnalisé.
 */
function tngs_migrate_late() {
    if ( false === get_option( 'tngs_settings', false ) ) {
        return;
    }
    $done = get_option( 'tngs_migrated', array() );
    $done = is_array( $done ) ? $done : array();
    // Installations 1.1 : le drapeau « Link » était stocké à part.
    if ( get_option( 'tngs_migrated_link' ) ) {
        $done[] = 'Link';
    }
    $pending = array_values( array_diff( array( 'Link', 'Book', 'Implexe', 'Stats' ), $done ) );
    if ( ! $pending ) {
        return;
    }

    $src = tngs_legacy_collect( $pending );
    list( $s, $notes ) = tngs_legacy_apply( tngs_settings(), $src, false );
    $s['db_prefix'] = tngs_valid_prefix( $s['db_prefix'] ) ? $s['db_prefix'] : 'tng_';

    update_option( 'tngs_settings', $s );
    tngs_legacy_mark_done( $pending );

    if ( $notes ) {
        $old = get_option( 'tngs_migration_notes', array() );
        update_option( 'tngs_migration_notes', array_merge( is_array( $old ) ? $old : array(), $notes ), false );
    }
}

/** Anciennes options qui contiennent encore un mot de passe MySQL en clair. */
function tngs_legacy_secret_options() {
    $found = array();
    foreach ( array( 'tngbook_options', 'tngi_options', 'tngs_options' ) as $opt ) {
        $o = get_option( $opt, null );
        if ( is_array( $o ) && ! empty( $o['db_pass'] ) ) {
            $found[] = $opt;
        }
    }
    return $found;
}

function tngs_legacy_purge_secrets() {
    foreach ( tngs_legacy_secret_options() as $opt ) {
        $o            = get_option( $opt );
        $o['db_pass'] = '';
        update_option( $opt, $o );
    }
}

/* ------------------------------------------------------------------
 * 4. DIAGNOSTIC
 * ------------------------------------------------------------------ */

function tngs_diagnostic() {
    $rows = array();
    $mode = tngs_conn_mode();

    if ( 'config' === $mode ) {
        $rows[] = array( 'Mode : constantes de wp-config.php', tngs_has_constants() ? 'DB_*TNG présentes' : 'DB_*TNG absentes', tngs_has_constants() );
    } elseif ( 'plugin' === $mode ) {
        $ok     = (bool) tngs_conn_credentials() && ! tngs_conn_pass_unreadable();
        $rows[] = array( 'Mode : identifiants saisis dans le plugin', $ok ? 'renseignés' : ( tngs_conn_pass_unreadable() ? 'mot de passe illisible' : 'incomplets' ), $ok );
    } else {
        $rows[] = array( 'Mode : base de données WordPress', 'tables TNG attendues dans la base WordPress', true );
    }

    $db     = tngs_db( true );
    $rows[] = array( 'Connexion à la base TNG', $db ? 'OK' : 'échec', (bool) $db );
    if ( $db ) {
        foreach ( array( 'people', 'families', 'media', 'medialinks', 'places' ) as $t ) {
            $table  = tngs_prefix() . $t;
            $exists = (bool) $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $table ) ) );
            $rows[] = array( tngs_t( 'Table' ) . ' ' . $table, $exists ? 'trouvée' : 'introuvable', $exists );
        }
    }
    return $rows;
}

/* ------------------------------------------------------------------
 * 5. BIBLIOTHÈQUES TIERCES : copie locale (dossier libs/) d'abord, CDN sinon
 * ------------------------------------------------------------------ */

/** URL du fichier de libs/ s'il est présent dans le plugin, sinon $cdn. */
function tngs_lib_url( $file, $cdn ) {
    return file_exists( TNGSUITE_DIR . 'libs/' . $file ) ? TNGSUITE_URL . 'libs/' . $file : $cdn;
}

/** Enregistre Chart.js une seule fois pour toute la suite (Statistiques, Implexe). */
function tngs_register_chartjs() {
    if ( ! wp_script_is( 'tngs-chartjs', 'registered' ) ) {
        wp_register_script(
            'tngs-chartjs',
            tngs_lib_url( 'chart.umd.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js' ),
            array(),
            '4.4.1',
            true
        );
    }
    return 'tngs-chartjs';
}

/* ------------------------------------------------------------------
 * Politique de confidentialité (Réglages > Confidentialité > « Texte de politique suggéré »)
 * ------------------------------------------------------------------ */
add_action( 'admin_init', function () {
    if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
        return;
    }
    $fr = 'fr' === ( function_exists( 'tngs_lang' ) ? tngs_lang() : 'fr' );
    if ( $fr ) {
        $txt = '<h2>TNG Suite</h2>'
            . '<p class="privacy-policy-tutorial">Ce texte est une suggestion : adaptez-le à votre site.</p>'
            . '<p><strong>Ce que le plugin affiche.</strong> TNG Suite lit la base de données de votre site TNG (généalogie) et affiche des personnes, des dates, des lieux, des photos et des statistiques. Par défaut, les personnes vivantes et les personnes marquées « privées » sont masquées ; vous restez responsable des choix faits dans Général > Modules et dans chaque module.</p>'
            . '<p><strong>Données des visiteurs.</strong> Le plugin n\'enregistre aucune donnée personnelle sur vos visiteurs (pas de cookie, pas de statistiques de visite ; seul le choix de langue du panneau « fiche » est mémorisé dans le navigateur du visiteur, via localStorage) et n\'envoie aucune donnée à l\'auteur du plugin.</p>'
            . '<p><strong>Services tiers.</strong> Pour afficher les graphiques, les réseaux et les cartes, le navigateur du visiteur peut charger des bibliothèques depuis un CDN public (cdnjs.cloudflare.com, unpkg.com, cdn.jsdelivr.net), sauf si elles ont été copiées dans le dossier libs/ du plugin. La carte de chaleur charge des tuiles de carte auprès du fournisseur choisi dans ses réglages (Esri, OpenStreetMap, OpenTopoMap…) : ce fournisseur reçoit l\'adresse IP du visiteur.</p>'
            . '<p><strong>Mises à jour.</strong> Le site vérifie périodiquement les nouvelles versions du plugin auprès de GitHub (api.github.com) ; aucune donnée sur vos visiteurs n\'est transmise.</p>'
            . '<p><strong>Livre généalogique.</strong> Les PDF, DOCX et ODT générés contiennent des données de votre base TNG ; les fichiers du livre automatique sont enregistrés dans un dossier protégé de votre site.</p>'
            . '<p><strong>Cohérence des données.</strong> Les résultats du contrôle (noms, identifiants et dates des personnes concernées par une anomalie, y compris vivantes) sont conservés dans deux tables de la base WordPress, visibles des seuls administrateurs. La liste des anomalies que vous avez choisi d’ignorer (message, identifiants) est conservée dans les options du site. Les résultats sont effacés par le bouton « Effacer les résultats » (la liste des anomalies ignorées par « Tout restaurer ») et tout est supprimé à la désinstallation du plugin.</p>';
    } else {
        $txt = '<h2>TNG Suite</h2>'
            . '<p class="privacy-policy-tutorial">This text is a suggestion: adapt it to your site.</p>'
            . '<p><strong>What the plugin displays.</strong> TNG Suite reads the database of your TNG (genealogy) site and displays people, dates, places, photos and statistics. By default living people and people flagged “private” are hidden; you remain responsible for the choices made in General > Modules and in each module.</p>'
            . '<p><strong>Visitor data.</strong> The plugin stores no personal data about your visitors (no cookie, no visit statistics; only the language choice of the “fiche” panel is remembered in the visitor\'s browser, via localStorage) and sends no data to the plugin author.</p>'
            . '<p><strong>Third-party services.</strong> To display charts, networks and maps, the visitor\'s browser may load libraries from a public CDN (cdnjs.cloudflare.com, unpkg.com, cdn.jsdelivr.net), unless they have been copied into the plugin\'s libs/ folder. The heatmap loads map tiles from the provider chosen in its settings (Esri, OpenStreetMap, OpenTopoMap…): that provider receives the visitor\'s IP address.</p>'
            . '<p><strong>Updates.</strong> The site periodically checks GitHub (api.github.com) for new plugin versions; no data about your visitors is sent.</p>'
            . '<p><strong>Genealogy book.</strong> Generated PDF, DOCX and ODT files contain data from your TNG database; files from the automatic book are saved in a protected folder of your site.</p>'
            . '<p><strong>Data consistency.</strong> The check results (names, identifiers and dates of people concerned by an anomaly, including living people) are kept in two tables of the WordPress database, visible to administrators only. The list of anomalies you chose to ignore (message, identifiers) is kept in the site options. The results are erased by the “Clear results” button (the ignored list by “Restore all”), and everything is deleted when the plugin is uninstalled.</p>';
    }
    wp_add_privacy_policy_content( 'TNG Suite', wp_kses_post( wpautop( $txt, false ) ) );
} );

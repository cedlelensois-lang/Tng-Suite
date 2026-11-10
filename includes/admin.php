<?php
/**
 * TNG Suite – menu unique et page « Général » (réglages partagés par tous les modules).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'tngs_admin_menu', 9 );
add_action( 'admin_menu', 'tngs_sort_submenu', 999 );
add_action( 'admin_init', 'tngs_register_settings' );
add_action( 'admin_post_tngs_flush', 'tngs_handle_flush' );
add_action( 'admin_post_tngs_dismiss_notes', 'tngs_handle_dismiss_notes' );
add_action( 'admin_post_tngs_purge_legacy', 'tngs_handle_purge_legacy' );
add_action( 'admin_notices', 'tngs_legacy_notice' );
add_filter( 'plugin_action_links_' . plugin_basename( TNGSUITE_FILE ), 'tngs_action_links' );

function tngs_admin_menu() {
    $cb = tngs_admin_page( 'tngs_render_general_page' );
    add_menu_page( 'TNG Suite', 'TNG Suite', 'manage_options', 'tng-suite', $cb, 'dashicons-networking', 80 );
    add_submenu_page( 'tng-suite', tngs_t( 'TNG Suite – Général' ), tngs_t( 'Général' ), 'manage_options', 'tng-suite', $cb );
}

/**
 * Ajoute une page de réglages de module sous « TNG Suite ».
 * Les pages hors « Réglages » n'affichent pas seules les messages d'enregistrement : on les affiche ici.
 */
function tngs_add_subpage( $page_title, $menu_title, $slug, $callback ) {
    global $tngs_tabs;
    if ( ! is_array( $tngs_tabs ) ) {
        $tngs_tabs = array();
    }
    $tngs_tabs[ $slug ] = tngs_t( $menu_title );
    add_submenu_page(
        'tng-suite',
        tngs_t( $page_title ),
        tngs_t( $menu_title ),
        'manage_options',
        $slug,
        tngs_admin_page( $callback )
    );
}

/** Module auquel appartient chaque page d'administration (même ordre que tngs_modules()). */
function tngs_page_modules() {
    return array(
        'tng-link'                => 'link',
        'tng_family_timeline'     => 'timeline',
        'tng-heatmap-settings'    => 'heatmap',
        'tng-fan'                 => 'fan',
        'tng-fan-gen'             => 'fan',
        'tng-stats'               => 'stats',
        'tng-stats-charts'        => 'stats',
        'tng-media-gallery'       => 'gallery',
        'tng_random'              => 'random',
        'tng-implexe-settings'    => 'implexe',
        'tng-implexe'             => 'implexe',
        'tng-implexe-inbreeding'  => 'implexe',
        'tngbook'                 => 'book',
        'tngfg'                   => 'fiche',
        'tngfg-generate'          => 'fiche',
    );
}

/** Pages d'un module : [ slug => libellé ], dans l'ordre d'enregistrement. */
function tngs_module_pages( $module ) {
    global $tngs_tabs;
    $out = array();
    foreach ( tngs_page_modules() as $slug => $m ) {
        if ( $m === $module && is_array( $tngs_tabs ) && isset( $tngs_tabs[ $slug ] ) ) {
            $out[ $slug ] = $tngs_tabs[ $slug ];
        }
    }
    return $out;
}

/** Remet les sous-menus dans l'ordre : Général, puis les modules dans l'ordre du tableau « Modules ». */
function tngs_sort_submenu() {
    global $submenu;
    if ( empty( $submenu['tng-suite'] ) ) {
        return;
    }
    $rank = array( 'tng-suite' => 0 );
    $i    = 1;
    foreach ( array_keys( tngs_modules() ) as $mod ) {
        foreach ( tngs_page_modules() as $slug => $m ) {
            if ( $m === $mod ) {
                $rank[ $slug ] = $i++;
            }
        }
    }
    usort(
        $submenu['tng-suite'],
        function ( $a, $b ) use ( $rank ) {
            $ra = isset( $rank[ $a[2] ] ) ? $rank[ $a[2] ] : 999;
            $rb = isset( $rank[ $b[2] ] ) ? $rank[ $b[2] ] : 999;
            return $ra <=> $rb;
        }
    );
}

function tngs_action_links( $links ) {
    array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=tng-suite' ) ) . '">' . esc_html( tngs_t( 'Réglages' ) ) . '</a>' );
    return $links;
}

function tngs_register_settings() {
    register_setting( 'tngs_group', 'tngs_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'tngs_sanitize_settings',
    ) );
}

function tngs_admin_guard( $nonce_action ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( $nonce_action );
}

function tngs_handle_flush() {
    tngs_admin_guard( 'tngs_flush' );
    tngs_flush_all();
    wp_safe_redirect( add_query_arg( array( 'page' => 'tng-suite', 'tngs_flushed' => 1 ), admin_url( 'admin.php' ) ) );
    exit;
}

function tngs_handle_dismiss_notes() {
    tngs_admin_guard( 'tngs_dismiss_notes' );
    delete_option( 'tngs_migration_notes' );
    wp_safe_redirect( admin_url( 'admin.php?page=tng-suite' ) );
    exit;
}

function tngs_handle_purge_legacy() {
    tngs_admin_guard( 'tngs_purge_legacy' );
    tngs_legacy_purge_secrets();
    wp_safe_redirect( add_query_arg( array( 'page' => 'tng-suite', 'tngs_purged' => 1 ), admin_url( 'admin.php' ) ) );
    exit;
}

/** Anciens plugins encore actifs : leur module de la suite n'est pas chargé (évite les doublons fatals). */
function tngs_legacy_notice() {
    global $tngs_legacy;
    if ( empty( $tngs_legacy ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo tngs_translate_html( '<div class="notice notice-warning"><p><strong>TNG Suite :</strong> ces anciens plugins sont encore actifs, leur module n\'est donc pas chargé par la suite. Désactivez-les (puis supprimez-les) : <em>'
        . esc_html( implode( ', ', $tngs_legacy ) ) . '</em>.</p></div>' ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Bloc « Connexion à la base TNG » du formulaire. */
function tngs_render_connection_fields( array $s ) {
    $mode    = tngs_conn_mode();
    $conn    = $s['conn'];
    $missing = array();
    foreach ( array( 'DB_HOSTTNG', 'DB_NAMETNG', 'DB_USERTNG', 'DB_PASSWORDTNG' ) as $c ) {
        if ( ! defined( $c ) ) {
            $missing[] = $c;
        }
    }
    $has_pass   = ! empty( $conn['pass'] );
    $unreadable = tngs_conn_pass_unreadable();
    ?>
    <h2>Connexion à la base TNG</h2>
    <p class="description" style="max-width:760px">Choisissez comment le plugin se connecte à la base de données de TNG. Ce choix est commun à tous les modules.</p>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">Mode de connexion</th>
            <td>
                <fieldset id="tngs-conn-modes">
                    <label>
                        <input type="radio" name="tngs_settings[conn_mode]" value="config" <?php checked( $mode, 'config' ); ?>>
                        <strong>Via le fichier wp-config.php</strong> (recommandé : rien n'est stocké dans la base)
                    </label>
                    <div class="tngs-mode-box" data-mode="config" style="margin:8px 0 18px 24px">
                        <?php if ( $missing ) : ?>
                            <p style="color:#b32d2e">❌ Constantes absentes : <code><?php echo esc_html( implode( ', ', $missing ) ); ?></code></p>
                            <p>Ajoutez ces lignes dans <code>wp-config.php</code>, avant la ligne « <em>That's all, stop editing!</em> » :</p>
                            <pre style="background:#f6f7f7;padding:10px;border:1px solid #dcdcde;max-width:520px;overflow:auto">define( 'DB_HOSTTNG',     'localhost' );
define( 'DB_NAMETNG',     'nom_de_la_base_tng' );
define( 'DB_USERTNG',     'utilisateur_lecture_seule' );
define( 'DB_PASSWORDTNG', 'mot_de_passe' );</pre>
                            <p class="description">Pas à l'aise avec ce fichier ? Choisissez plutôt « Saisir les identifiants ici ».</p>
                        <?php else : ?>
                            <p style="color:#1a7f37">✅ Les quatre constantes <code>DB_*TNG</code> sont définies dans <code>wp-config.php</code>.</p>
                        <?php endif; ?>
                    </div>

                    <label>
                        <input type="radio" name="tngs_settings[conn_mode]" value="plugin" <?php checked( $mode, 'plugin' ); ?>>
                        <strong>Saisir les identifiants ici</strong> (sans modifier wp-config.php)
                    </label>
                    <div class="tngs-mode-box" data-mode="plugin" style="margin:8px 0 18px 24px">
                        <table class="form-table" role="presentation" style="margin-top:0">
                            <tr>
                                <th scope="row"><label for="tngs_conn_host">Hôte MySQL</label></th>
                                <td><input type="text" id="tngs_conn_host" name="tngs_settings[conn][host]" value="<?php echo esc_attr( $conn['host'] ); ?>" class="regular-text" autocomplete="off">
                                    <p class="description"><code>localhost</code> dans la plupart des cas, ou <code>hote:port</code>.</p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tngs_conn_name">Nom de la base TNG</label></th>
                                <td><input type="text" id="tngs_conn_name" name="tngs_settings[conn][name]" value="<?php echo esc_attr( $conn['name'] ); ?>" class="regular-text" autocomplete="off"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tngs_conn_user">Utilisateur MySQL</label></th>
                                <td><input type="text" id="tngs_conn_user" name="tngs_settings[conn][user]" value="<?php echo esc_attr( $conn['user'] ); ?>" class="regular-text" autocomplete="off"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tngs_conn_pass">Mot de passe MySQL</label></th>
                                <td>
                                    <input type="password" id="tngs_conn_pass" name="tngs_settings[conn][pass]" value="" class="regular-text" autocomplete="new-password"
                                        placeholder="<?php echo $has_pass ? esc_attr( '•••••••• (enregistré)' ) : ''; ?>">
                                    <?php if ( $unreadable ) : ?>
                                        <p style="color:#b32d2e">⚠️ Le mot de passe enregistré est illisible (les clés de sécurité de WordPress ont changé) : ressaisissez-le.</p>
                                    <?php elseif ( $has_pass ) : ?>
                                        <p class="description">Laisser vide pour conserver le mot de passe enregistré.
                                            <label><input type="checkbox" name="tngs_settings[conn][clear_pass]" value="1"> Effacer le mot de passe enregistré</label></p>
                                    <?php endif; ?>
                                    <p class="description">Il n'est jamais réaffiché. Il est chiffré avec les clés de sécurité de WordPress avant d'être enregistré.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tngs_conn_charset">Jeu de caractères</label></th>
                                <td><input type="text" id="tngs_conn_charset" name="tngs_settings[conn][charset]" value="<?php echo esc_attr( $conn['charset'] ); ?>" class="small-text" placeholder="utf8mb4" autocomplete="off">
                                    <span class="description">Facultatif (avancé). Laisser vide en cas de doute.</span></td>
                            </tr>
                        </table>
                        <p class="description" style="max-width:640px">
                            ⚠️ Cette méthode garde le mot de passe (chiffré) dans la base de WordPress : elle est un peu moins sûre que wp-config.php.
                            Dans tous les cas, créez un compte MySQL en <strong>lecture seule</strong> (droit SELECT uniquement) pour TNG Suite.
                        </p>
                    </div>

                    <label>
                        <input type="radio" name="tngs_settings[conn_mode]" value="wp" <?php checked( $mode, 'wp' ); ?>>
                        <strong>Mes tables TNG sont dans la base de WordPress</strong>
                    </label>
                    <div class="tngs-mode-box" data-mode="wp" style="margin:8px 0 6px 24px">
                        <p class="description">Aucun identifiant à saisir : les tables <code><?php echo esc_html( tngs_prefix() ); ?>people</code>, <code><?php echo esc_html( tngs_prefix() ); ?>families</code>… sont lues dans la même base que WordPress.</p>
                    </div>
                </fieldset>
            </td>
        </tr>
    </table>
    <script>
    (function () {
        var radios = document.querySelectorAll('#tngs-conn-modes input[type=radio]');
        var boxes = document.querySelectorAll('#tngs-conn-modes .tngs-mode-box');
        function sync() {
            var cur = 'config';
            radios.forEach(function (r) { if (r.checked) { cur = r.value; } });
            boxes.forEach(function (b) { b.style.display = (b.getAttribute('data-mode') === cur) ? '' : 'none'; });
        }
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    })();
    </script>
    <?php
}

function tngs_render_general_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    global $tngs_legacy;

    $s       = tngs_settings();
    $notes   = get_option( 'tngs_migration_notes', array() );
    $secrets = tngs_legacy_secret_options();
    ?>
    <div class="wrap tngs-wrap">
        <h1><span class="tngs-brand">TNG Suite</span> – Général</h1>
        <?php tngs_admin_tabs(); ?>

        <?php $exposed = tngs_living_exposed_modules(); ?>
        <?php if ( $exposed ) : ?>
            <div class="notice notice-warning inline"><p><strong>⚠️ Attention, RGPD :</strong> les personnes vivantes ou privées sont actuellement <strong>affichées</strong> par : <?php echo esc_html( implode( ', ', $exposed ) ); ?>. Vérifiez que vous avez leur consentement ou que ces pages sont à accès restreint.</p></div>
        <?php endif; ?>

        <?php if ( isset( $_GET['tngs_flushed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="notice notice-success is-dismissible"><p>Tous les caches ont été vidés.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['tngs_purged'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="notice notice-success is-dismissible"><p>Les mots de passe ont été effacés des réglages des anciens plugins.</p></div>
        <?php endif; ?>

        <?php if ( ! empty( $tngs_legacy ) ) : ?>
            <div class="notice notice-warning inline"><p>Anciens plugins encore actifs : <strong><?php echo esc_html( implode( ', ', $tngs_legacy ) ); ?></strong>. Désactivez-les pour activer les modules correspondants.</p></div>
        <?php endif; ?>

        <?php if ( is_array( $notes ) && $notes ) : ?>
            <div class="notice notice-info inline">
                <p><strong>Migration depuis vos anciens plugins :</strong></p>
                <ul style="list-style:disc;margin-left:1.5em">
                    <?php foreach ( $notes as $n ) : ?>
                        <li><?php echo esc_html( $n ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p>Vérifiez les valeurs ci-dessous.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="tngs_dismiss_notes">
                    <?php wp_nonce_field( 'tngs_dismiss_notes' ); ?>
                    <?php submit_button( 'Compris', 'secondary small', 'submit', false ); ?>
                </form>
            </div>
        <?php endif; ?>

        <?php if ( $secrets ) : ?>
            <div class="notice notice-warning inline">
                <p><strong>Sécurité :</strong> un ancien plugin (<?php echo '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $secrets ) ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>) conserve encore un mot de passe MySQL <strong>en clair</strong> dans la base. Une fois TNG Suite vérifié et les anciens plugins désactivés, effacez-le.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="<?php echo esc_attr( 'return confirm(' . wp_json_encode( tngs_t( 'Les anciens plugins concernés ne pourront plus se connecter à la base TNG. Continuer ?' ) ) . ');' ); ?>">
                    <input type="hidden" name="action" value="tngs_purge_legacy">
                    <?php wp_nonce_field( 'tngs_purge_legacy' ); ?>
                    <?php submit_button( 'Effacer les mots de passe des anciens plugins', 'secondary small', 'submit', false ); ?>
                </form>
            </div>
        <?php endif; ?>

        <nav class="tngs-sections" aria-label="Sections">
            <?php foreach ( array( 'overview' => 'Tableau de bord', 'connexion' => 'Connexion', 'commun' => 'Réglages communs', 'modules' => 'Modules', 'maintenance' => 'Maintenance' ) as $k => $lbl ) : ?>
                <a href="#<?php echo esc_attr( $k ); ?>" class="tngs-sec" data-panel="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $lbl ); ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="tngs-panel" data-panel="overview">
        <h2>Diagnostic</h2>
        <table class="widefat striped" style="max-width:700px"><tbody>
            <?php foreach ( tngs_diagnostic() as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row[0] ); ?></td>
                    <td style="color:<?php echo $row[2] ? '#1a7f37' : '#b32d2e'; ?>"><?php echo esc_html( ( $row[2] ? '✅ ' : '❌ ' ) . tngs_t( $row[1] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <p class="description">Le diagnostic est refait à chaque affichage de cette page : enregistrez vos réglages pour tester une nouvelle connexion.</p>

        <h2>Vos modules</h2>
        <table class="widefat striped tngs-overview"><thead><tr><th>Module</th><th>État</th><th>Vivants / privés</th><th>Réglages</th></tr></thead><tbody>
        <?php foreach ( tngs_modules() as $slug => $info ) :
            $on    = ! empty( $s['modules'][ $slug ] );
            $pages = $on ? tngs_module_pages( $slug ) : array();
            ?>
            <tr>
                <td><strong><?php echo esc_html( $info[0] ); ?></strong></td>
                <td style="color:<?php echo $on ? '#1a7f37' : '#757575'; ?>"><?php echo $on ? '✅ Activé' : '⏸ Désactivé'; ?></td>
                <td><?php echo $info[2] ? ( tngs_hide_living( $slug ) ? 'Masquées' : '⚠️ Affichées (RGPD)' ) : '—'; ?></td>
                <td>
                    <?php
                    $links = array();
                    foreach ( $pages as $pg => $label ) {
                        $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $pg ) ) . '">' . esc_html( $label ) . '</a>';
                    }
                    echo $links ? implode( ' · ', $links ) : '<span class="description">Pas de page propre : réglages dans le shortcode.</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'tngs_group' ); ?>

            <div class="tngs-panel" data-panel="connexion">
            <?php tngs_render_connection_fields( $s ); ?>
            </div>

            <div class="tngs-panel" data-panel="commun">
            <h2>Réglages communs à tous les modules</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tngs_lang">Langue / Language</label></th>
                    <td>
                        <select id="tngs_lang" name="tngs_settings[lang]">
                            <option value="auto" <?php selected( $s['lang'], 'auto' ); ?>>Automatique (langue de WordPress)</option>
                            <option value="fr" <?php selected( $s['lang'], 'fr' ); ?>>Français</option>
                            <option value="en" <?php selected( $s['lang'], 'en' ); ?>>English</option>
                        </select>
                        <p class="description">Textes de l'administration et des modules affichés aux visiteurs (graphiques, boutons, messages). Les noms, notes et lieux issus de TNG ne sont jamais traduits. Le livre généalogique a sa propre langue.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tngs_site_url">URL de votre site TNG</label></th>
                    <td>
                        <input type="text" id="tngs_site_url" name="tngs_settings[site_url]" value="<?php echo esc_attr( $s['site_url'] ); ?>" class="regular-text" placeholder="https://mon-site.com/tng/">
                        <p class="description">Dossier où se trouve <code>getperson.php</code>. Une URL relative (<code>/tng/</code>) fonctionne aussi.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tngs_db_prefix">Préfixe des tables TNG</label></th>
                    <td>
                        <input type="text" id="tngs_db_prefix" name="tngs_settings[db_prefix]" value="<?php echo esc_attr( $s['db_prefix'] ); ?>" class="small-text">
                        <p class="description">Lettres, chiffres et « _ » uniquement (par défaut <code>tng_</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tngs_gedcom">Identifiant de l'arbre TNG</label></th>
                    <td>
                        <input type="text" id="tngs_gedcom" name="tngs_settings[gedcom]" value="<?php echo esc_attr( $s['gedcom'] ); ?>" class="regular-text">
                        <p class="description">Colonne <code>gedcom</code>. Laisser vide pour ne pas filtrer (tous les arbres).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Vivants / privés (par défaut)</th>
                    <td>
                        <label>
                            <input type="checkbox" id="tngs_hide_living" name="tngs_settings[hide_living]" value="1" <?php checked( 1, (int) $s['hide_living'] ); ?>>
                            Masquer les personnes vivantes ou privées (recommandé, RGPD)
                        </label>
                        <p class="description">Valeur utilisée par les modules réglés sur « Comme le réglage commun ». Chaque module peut être réglé séparément dans le tableau « Modules » ci-dessous.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tngs_cache_hours">Cache des arbres (heures)</label></th>
                    <td>
                        <input type="number" id="tngs_cache_hours" name="tngs_settings[cache_hours]" min="0" max="720" value="<?php echo esc_attr( (int) $s['cache_hours'] ); ?>" class="small-text">
                        <p class="description">0 = pas de cache. Les autres modules ont leur propre durée dans leur page.</p>
                    </td>
                </tr>
            </table>
            </div>

            <div class="tngs-panel" data-panel="modules">
            <h2>Modules et confidentialité des vivants</h2>
            <p class="description" style="max-width:760px">Activez les modules voulus et choisissez, <strong>pour chacun</strong>, ce qui est affiché au sujet des personnes vivantes ou privées.</p>
            <table class="widefat striped" id="tngs-modules" style="max-width:980px">
                <thead><tr><th>Module</th><th style="width:90px">Activé</th><th>Vivants / privés</th></tr></thead>
                <tbody>
                <?php foreach ( tngs_modules() as $slug => $info ) :
                    $mode      = tngs_privacy_mode( $slug );
                    $eff_show  = ! tngs_hide_living( $slug );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $info[0] ); ?></strong><br>
                            <code style="font-size:11px"><?php echo esc_html( $info[1] ); ?></code>
                        </td>
                        <td>
                            <label><input type="checkbox" name="tngs_settings[modules][<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( 1, (int) $s['modules'][ $slug ] ); ?>> Oui</label>
                        </td>
                        <td>
                            <?php if ( $info[2] ) : ?>
                                <select class="tngs-priv" name="tngs_settings[privacy][<?php echo esc_attr( $slug ); ?>]" data-initial-show="<?php echo $eff_show ? '1' : '0'; ?>">
                                    <option value="default" <?php selected( $mode, 'default' ); ?>>Comme le réglage commun</option>
                                    <option value="hide" <?php selected( $mode, 'hide' ); ?>>Masquer</option>
                                    <option value="show" <?php selected( $mode, 'show' ); ?>>Afficher (⚠️ RGPD)</option>
                                </select>
                                <span class="tngs-priv-warn" style="color:#b32d2e;display:none">⚠️ RGPD</span>
                                <br><span class="description"><?php echo esc_html( $info[2] ); ?></span>
                            <?php else : ?>
                                <span class="description">Sans objet : simple lien, c'est TNG qui protège la fiche.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Un module désactivé n'est pas chargé du tout : ses shortcodes n'affichent plus rien, ses réglages sont conservés.</p>
            <script>
            (function () {
                var glob = document.getElementById('tngs_hide_living');
                var sels = document.querySelectorAll('#tngs-modules .tngs-priv');
                function effShow(sel) {
                    if (sel.value === 'show') { return true; }
                    if (sel.value === 'hide') { return false; }
                    return !glob.checked;
                }
                function sync() {
                    sels.forEach(function (sel) {
                        var w = sel.parentNode.querySelector('.tngs-priv-warn');
                        w.style.display = effShow(sel) ? '' : 'none';
                    });
                }
                sels.forEach(function (sel) { sel.addEventListener('change', sync); });
                glob.addEventListener('change', sync);
                sync();
                var form = document.getElementById('tngs-modules').closest('form');
                form.addEventListener('submit', function (e) {
                    var newly = [];
                    sels.forEach(function (sel) {
                        if (effShow(sel) && sel.getAttribute('data-initial-show') !== '1') {
                            newly.push(sel.closest('tr').querySelector('strong').textContent);
                        }
                    });
                    if (newly.length && !window.confirm(<?php echo wp_json_encode( tngs_t( '⚠️ RGPD : vous allez afficher publiquement des personnes vivantes ou privées pour :' ) ); ?> + ' ' + newly.join(', ') + '.\n\n' + <?php echo wp_json_encode( tngs_t( 'Assurez-vous d\'avoir leur consentement ou de restreindre l\'accès à ces pages. Continuer ?' ) ); ?>)) {
                        e.preventDefault();
                    }
                });
            })();
            </script>

            </div>

            <div class="tngs-submit"><?php submit_button( 'Enregistrer les réglages' ); ?></div>
        </form>

        <div class="tngs-panel" data-panel="maintenance">
        <h2>Caches</h2>
        <p>Vide les caches de tous les modules (utile après une modification dans TNG).</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tngs_flush">
            <?php wp_nonce_field( 'tngs_flush' ); ?>
            <?php submit_button( 'Vider tous les caches', 'secondary', 'submit', false ); ?>
        </form>
        </div>
        <script>
        (function () {
            var wrap = document.querySelector('.tngs-wrap');
            var panels = wrap.querySelectorAll('.tngs-panel');
            var links = wrap.querySelectorAll('.tngs-sec');
            var submit = wrap.querySelector('.tngs-submit');
            var forms = ['connexion', 'commun', 'modules'];
            function show(name) {
                if (!wrap.querySelector('.tngs-panel[data-panel="' + name + '"]')) { name = 'overview'; }
                panels.forEach(function (p) { p.style.display = p.getAttribute('data-panel') === name ? '' : 'none'; });
                links.forEach(function (a) { a.classList.toggle('tngs-sec-active', a.getAttribute('data-panel') === name); });
                if (submit) { submit.style.display = forms.indexOf(name) >= 0 ? '' : 'none'; }
            }
            links.forEach(function (a) {
                a.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + a.getAttribute('data-panel')); show(a.getAttribute('data-panel')); });
            });
            wrap.classList.add('tngs-js');
            show((location.hash || '#overview').slice(1));
        })();
        </script>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Présentation commune des pages de réglages des modules
 * ---------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', 'tngs_admin_assets' );

function tngs_admin_assets( $hook ) {
    if ( false !== strpos( (string) $hook, 'tng-suite' ) ) {
        wp_enqueue_style( 'tngs-admin', TNGSUITE_URL . 'assets/admin.css', array(), TNGSUITE_VERSION );
    }
}

/**
 * En-tête commun : titre « TNG Suite – … », état de la connexion TNG (avec rappel du réglage vivants / privés),
 * encadré des shortcodes du module. Ouvre <div class="wrap tngs-wrap"> : la page ferme ce <div>.
 *
 * Arguments : module (clé de tngs_modules()), db (afficher l'état de la connexion), status (array( ok, message )
 * pour remplacer l'état standard), shortcodes (liste de shortcodes ; sinon ceux du module), intro (HTML de confiance).
 */
function tngs_admin_header( $title, $args = array() ) {
    $a = wp_parse_args( $args, array( 'module' => '', 'db' => true, 'status' => null, 'shortcodes' => null, 'intro' => '' ) );

    echo '<div class="wrap tngs-wrap"><h1><span class="tngs-brand">TNG Suite</span> – ' . esc_html( tngs_t( $title ) ) . '</h1>';
    tngs_admin_tabs();

    if ( $a['db'] ) {
        if ( is_array( $a['status'] ) ) {
            $ok  = (bool) $a['status'][0];
            $msg = (string) $a['status'][1];
        } else {
            $ok  = (bool) tngs_db();
            $msg = $ok ? 'Connexion à la base TNG : OK.' : tngs_db_error_message();
        }
        echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' inline tngs-status"><p><strong>' . esc_html( tngs_t( $msg ) ) . '</strong> ';
        if ( '' !== $a['module'] ) {
            echo 'La connexion, le préfixe, l\'URL de TNG, l\'arbre par défaut et les personnes vivantes/privées (<strong>'
                . ( tngs_hide_living( $a['module'] ) ? 'masquées' : '⚠️ affichées (RGPD)' ) . '</strong>) se règlent dans ';
        } else {
            echo 'La connexion, le préfixe, l\'URL de TNG et l\'arbre par défaut se règlent dans ';
        }
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tng-suite' ) ) . '">TNG Suite &gt; Général</a>.</p></div>';
    }

    $sc = $a['shortcodes'];
    if ( null === $sc && '' !== $a['module'] ) {
        $mods = tngs_modules();
        $sc   = array();
        if ( isset( $mods[ $a['module'] ] ) ) {
            preg_match_all( '/\[[a-z_]+[^\]]*\]/i', (string) $mods[ $a['module'] ][1], $m );
            $sc = $m[0];
        }
    }
    if ( $sc ) {
        $codes = array();
        foreach ( (array) $sc as $c ) {
            $codes[] = '<code>' . esc_html( $c ) . '</code>';
        }
        echo '<p class="tngs-shortcodes"><strong>Shortcodes :</strong> ' . implode( ' ', $codes ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    if ( '' !== $a['intro'] ) {
        echo '<div class="tngs-box">' . $a['intro'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}


/**
 * Barre d'onglets commune à deux niveaux : 1re ligne = Général + un onglet par module qui a des pages d'administration ;
 * 2e ligne (si le module en a plusieurs) = ses pages.
 */
function tngs_admin_tabs() {
    $current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'tng-suite'; // phpcs:ignore WordPress.Security.NonceVerification
    $map     = tngs_page_modules();
    $cur_mod = isset( $map[ $current ] ) ? $map[ $current ] : '';
    $mods    = tngs_modules();
    echo '<nav class="nav-tab-wrapper tngs-tabs" aria-label="TNG Suite">';
    printf(
        '<a class="nav-tab%s" href="%s"%s>%s</a>',
        'tng-suite' === $current ? ' nav-tab-active' : '',
        esc_url( admin_url( 'admin.php?page=tng-suite' ) ),
        'tng-suite' === $current ? ' aria-current="page"' : '',
        esc_html( tngs_t( 'Général' ) )
    );
    foreach ( $mods as $slug => $info ) {
        $pages = tngs_module_pages( $slug );
        if ( ! $pages ) {
            continue;
        }
        $first = (string) key( $pages );
        printf(
            '<a class="nav-tab%s" href="%s"%s>%s</a>',
            $slug === $cur_mod ? ' nav-tab-active' : '',
            esc_url( admin_url( 'admin.php?page=' . $first ) ),
            $slug === $cur_mod ? ' aria-current="page"' : '',
            esc_html( tngs_t( $info[0] ) )
        );
    }
    echo '</nav>';
    $sub = $cur_mod ? tngs_module_pages( $cur_mod ) : array();
    if ( count( $sub ) > 1 ) {
        echo '<ul class="subsubsub tngs-subtabs">';
        $n = 0;
        foreach ( $sub as $slug => $label ) {
            printf(
                '%s<li><a href="%s"%s>%s</a></li>',
                $n++ ? ' | ' : '',
                esc_url( admin_url( 'admin.php?page=' . $slug ) ),
                $slug === $current ? ' class="current" aria-current="page"' : '',
                esc_html( $label )
            );
        }
        echo '</ul><br class="clear">';
    }
}

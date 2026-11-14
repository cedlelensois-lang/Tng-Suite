<?php

if (!defined('ABSPATH')) exit;

/* ------------------------------ Réglages ------------------------------- */

function tri_register_settings() {
    $g = 'tri_settings_group';
    $d = tri_defaults();

    register_setting($g, 'tri_photo_dir', [
        'type' => 'string', 'sanitize_callback' => function ($v) {
            $v = tri_sanitize_dir($v);
            return $v === '' ? 'photos' : $v;
        }, 'default' => $d['tri_photo_dir'],
    ]);
    register_setting($g, 'tri_cache_minutes', [
        'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => $d['tri_cache_minutes'],
    ]);

    register_setting($g, 'tri_avatar', [
        'type' => 'string', 'sanitize_callback' => function ($v) { return 'round' === $v ? 'round' : 'portrait'; }, 'default' => $d['tri_avatar'],
    ]);

    foreach (['tri_show_photo', 'tri_show_name', 'tri_show_dates'] as $cb) {
        register_setting($g, $cb, [
            'type' => 'integer',
            'sanitize_callback' => function ($v) { return empty($v) ? 0 : 1; },
            'default' => 1,
        ]);
    }
}
add_action('admin_init', 'tri_register_settings');

/* Invalidation du cache quand un réglage qui change la sélection est modifié */
function tri_bump_cache() {
    update_option('tri_cache_version', (int) tri_opt('tri_cache_version') + 1, false);
}
add_action('update_option_tri_cache_minutes', 'tri_bump_cache');
add_action('tngs_flush_cache', 'tri_bump_cache'); // réglages communs ou « Vider tous les caches »

/* Bouton « Vider le cache » */
function tri_handle_flush_cache() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Accès refusé.', 'tng-suite'), '', ['response' => 403]);
    }
    check_admin_referer('tri_flush_cache');
    tri_bump_cache();
    wp_safe_redirect(add_query_arg(['page' => 'tng_random', 'tri_flushed' => 1], admin_url('admin.php')));
    exit;
}
add_action('admin_post_tri_flush_cache', 'tri_handle_flush_cache');

/* ------------------------------ Menu ----------------------------------- */

function tri_settings_page() {
    tngs_add_subpage('TNG Random Réglages', 'Individu au hasard', 'tng_random', 'tri_settings_page_html');
}
add_action('admin_menu', 'tri_settings_page');

/* ------------------------------ Diagnostic ----------------------------- */

function tri_diagnostic_rows() {
    $rows = [];
    $rows[] = [
        __('Mode de connexion', 'tng-suite'),
        tri_has_dedicated_db()
            ? __('Connexion dédiée (voir TNG Suite > Général)', 'tng-suite')
            : __('Base WordPress', 'tng-suite'),
        true,
    ];

    $db = tri_get_db();
    $rows[] = [
        __('État de la connexion', 'tng-suite'),
        $db ? 'OK' : __('Échec (voir le journal PHP)', 'tng-suite'),
        (bool) $db,
    ];

    if ($db) {
        foreach (tri_tables() as $table) {
            $exists = (bool) $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
            $rows[] = [
                tngs_t('Table') . ' ' . $table,
                $exists ? __('trouvée', 'tng-suite') : __('introuvable', 'tng-suite'),
                $exists,
            ];
        }
    }
    return $rows;
}

function tri_settings_page_html() {
    if (!current_user_can('manage_options')) return;

    $flush_url = wp_nonce_url(admin_url('admin-post.php?action=tri_flush_cache'), 'tri_flush_cache');
?>
<?php tngs_admin_header('Individu au hasard', array('module' => 'random')); ?>

    <?php if (!empty($_GET['tri_flushed'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Cache vidé.', 'tng-suite'); ?></p></div>
    <?php endif; ?>

    <div class="tngs-box">
        <code>[tng_random_individual]</code> — individu aléatoire dans la base<br>
        <code>[tng_random_individual name="monnom"]</code> — individu aléatoire avec ce patronyme<br>
        <code>[tng_random_individual tree="montree"]</code> — individu aléatoire dans cet arbre (sinon l'arbre des réglages)
    </div>

    <h2><?php echo esc_html__('Diagnostic', 'tng-suite'); ?></h2>
    <table class="widefat striped" style="max-width:700px">
        <tbody>
        <?php foreach (tri_diagnostic_rows() as $row) : ?>
            <tr>
                <td><?php echo esc_html($row[0]); ?></td>
                <td style="color:<?php echo $row[2] ? '#1a7f37' : '#b32d2e'; ?>"><?php echo esc_html($row[1]); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" action="options.php">
        <?php settings_fields('tri_settings_group'); ?>

        <h2>TNG</h2>
        <p class="description">L'arbre par défaut, l'URL de TNG et le préfixe des tables se règlent dans <strong>TNG Suite &gt; Général</strong>.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="tri_photo_dir">Répertoire des photos TNG</label></th>
                <td>
                    <input type="text" id="tri_photo_dir" name="tri_photo_dir" value="<?php echo esc_attr(tri_opt('tri_photo_dir')); ?>">
                    <p class="description">Exemple : photos (par défaut)</p>
                </td>
            </tr>
            <tr>
                <th><label for="tri_cache_minutes">Durée du cache (minutes)</label></th>
                <td>
                    <input type="number" min="0" id="tri_cache_minutes" name="tri_cache_minutes" value="<?php echo esc_attr((int) tri_opt('tri_cache_minutes')); ?>">
                    <a href="<?php echo esc_url($flush_url); ?>" class="button">Vider le cache</a>
                    <p class="description">Cache de la liste des individus éligibles (le tirage reste aléatoire). 0 = désactivé.</p>
                </td>
            </tr>
        </table>

        <h2>Affichage</h2>
        <table class="form-table" role="presentation">
            <tr><th>Afficher photo</th><td><input type="checkbox" name="tri_show_photo" value="1" <?php checked(tri_opt('tri_show_photo'), 1); ?>></td></tr>
            <tr>
                <th><label for="tri_avatar">Silhouette sans photo</label></th>
                <td>
                    <select id="tri_avatar" name="tri_avatar">
                        <option value="portrait" <?php selected(tri_opt('tri_avatar'), 'portrait'); ?>>Portrait (cercle sur fond clair)</option>
                        <option value="round" <?php selected(tri_opt('tri_avatar'), 'round'); ?>>Rond (sans fond)</option>
                    </select>
                    <p class="description">Image affichée quand l'individu n'a pas de photo dans TNG.</p>
                </td>
            </tr>
            <tr><th>Afficher nom/prénom</th><td><input type="checkbox" name="tri_show_name" value="1" <?php checked(tri_opt('tri_show_name'), 1); ?>></td></tr>
            <tr><th>Afficher dates</th><td><input type="checkbox" name="tri_show_dates" value="1" <?php checked(tri_opt('tri_show_dates'), 1); ?>></td></tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
<?php
}

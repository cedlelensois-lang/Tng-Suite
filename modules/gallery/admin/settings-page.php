<?php

if (!defined('ABSPATH')) exit;

function tng_mg_render_settings_page() {

    if (!current_user_can('manage_options')) return;

    $missing = tngs_conn_ready() ? [] : [tngs_db_error_message()];

    $mode = get_option('tng_mg_display_mode', 'grid');
    ?>
    <?php tngs_admin_header('Galerie de médias', array('module' => 'gallery')); ?>

        <?php if (isset($_GET['tng_mg_flushed'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Cache vidé.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('tng_mg_options'); ?>

            <h2>Cache</h2>
            <p class="description">La connexion, le préfixe, l'URL de TNG et la confidentialité se règlent dans <strong>TNG Suite &gt; Général</strong>.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tng_mg_cache_minutes">Cache (minutes)</label></th>
                    <td>
                        <input type="number" id="tng_mg_cache_minutes" name="tng_mg_cache_minutes"
                               min="0" max="1440"
                               value="<?php echo esc_attr(get_option('tng_mg_cache_minutes', 15)); ?>">
                        <p class="description">Durée de conservation des résultats. 0 = désactivé. Évite d'interroger la base distante à chaque visite.</p>
                    </td>
                </tr>
            </table>

            <h2>Affichage</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tng_mg_display_mode">Mode d’affichage</label></th>
                    <td>
                        <select id="tng_mg_display_mode" name="tng_mg_display_mode">
                            <option value="grid"    <?php selected($mode, 'grid'); ?>>Grille standard</option>
                            <option value="masonry" <?php selected($mode, 'masonry'); ?>>Masonry (Pinterest)</option>
                            <option value="elegant" <?php selected($mode, 'elegant'); ?>>Galerie élégante</option>
                            <option value="modern"  <?php selected($mode, 'modern'); ?>>Cartes modernes (légende au survol)</option>
                        </select>
                        <p class="description">« Cartes modernes » affiche les images en plein cadre (les tailles de vignettes ci-dessous sont ignorées dans ce mode).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_mg_thumb_height">Hauteur max. des vignettes (px)</label></th>
                    <td><input type="number" id="tng_mg_thumb_height" name="tng_mg_thumb_height" min="20" max="600"
                               value="<?php echo esc_attr(get_option('tng_mg_thumb_height', 100)); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tng_mg_thumb_width">Largeur max. des vignettes (px)</label></th>
                    <td><input type="number" id="tng_mg_thumb_width" name="tng_mg_thumb_width" min="20" max="600"
                               value="<?php echo esc_attr(get_option('tng_mg_thumb_width', 80)); ?>"></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>
        <h2>Maintenance</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tng_mg_flush_cache">
            <?php wp_nonce_field('tng_mg_flush_cache'); ?>
            <p>Un nouveau média ajouté dans TNG apparaît au plus tard à l'expiration du cache. Pour l'afficher tout de suite :</p>
            <?php submit_button('Vider le cache', 'secondary', 'submit', false); ?>
        </form>

        <p style="margin-top:2em">Shortcode : <code>[tng_media_gallery limit="30" personid="I123" surname="Dupont"]</code></p>
    </div>
    <?php
}

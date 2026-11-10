<?php

if (!defined('ABSPATH')) exit;

class TNG_Media_Gallery {

    const MODES = ['grid', 'masonry', 'elegant', 'modern'];

    /** @var wpdb|false|null null = pas encore tenté, false = échec */
    protected $db = null;

    /** Dernier message d'erreur (affiché uniquement aux administrateurs) */
    protected $error = '';

    protected $assets_done = false;

    public function register() {
        add_shortcode('tng_media_gallery', [$this, 'shortcode_gallery']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    /* ------------------------------------------------------------------
     * Connexion à la base TNG (paresseuse)
     * ------------------------------------------------------------------ */

    /**
     * Connexion fournie par le cœur de TNG Suite (TNG Suite > Général).
     * Jeu de caractères : réglable dans TNG Suite > Général (mode « saisie ») ou via DB_CHARSETTNG.
     *
     * @return wpdb|null
     */
    protected function get_db() {
        $db = tngs_db();
        if (!$db) {
            $this->error = tngs_db_error_message();
            return null;
        }
        return $db;
    }

    protected function get_prefix() {
        return tngs_prefix();
    }

    /* ------------------------------------------------------------------
     * Assets (chargés uniquement quand un shortcode est affiché)
     * ------------------------------------------------------------------ */

    public function register_assets() {

        wp_register_style(
            'tng-media-gallery',
            TNG_MG_URL . 'assets/css/tng-media-gallery.css',
            [],
            TNG_MG_VERSION
        );

        // Si GLightbox est déposé dans assets/vendor/glightbox/, on l'utilise
        // en local (RGPD, pas de dépendance à un CDN). Sinon : CDN avec version figée.
        $local_dir = TNG_MG_PATH . 'assets/vendor/glightbox/';

        if (file_exists($local_dir . 'glightbox.min.js') && file_exists($local_dir . 'glightbox.min.css')) {
            $css = TNG_MG_URL . 'assets/vendor/glightbox/glightbox.min.css';
            $js  = TNG_MG_URL . 'assets/vendor/glightbox/glightbox.min.js';
        } else {
            $css = 'https://cdn.jsdelivr.net/npm/glightbox@3.3.0/dist/css/glightbox.min.css';
            $js  = 'https://cdn.jsdelivr.net/npm/glightbox@3.3.0/dist/js/glightbox.min.js';
        }

        wp_register_style('glightbox', $css, [], '3.3.0');
        wp_register_script('glightbox', $js, [], '3.3.0', true);
    }

    protected function enqueue_assets() {

        if ($this->assets_done) {
            return;
        }
        $this->assets_done = true;

        if (!wp_style_is('tng-media-gallery', 'registered')) {
            $this->register_assets();
        }

        wp_enqueue_style('tng-media-gallery');
        wp_enqueue_style('glightbox');
        wp_enqueue_script('glightbox');

        wp_add_inline_script(
            'glightbox',
            'document.addEventListener("DOMContentLoaded",function(){if(window.GLightbox){GLightbox({selector:".tng-lightbox"});}});'
        );
    }

    /* ------------------------------------------------------------------
     * Shortcode
     * ------------------------------------------------------------------ */

    public function shortcode_gallery($atts) {

        // (array) : [tng_media_gallery] sans attribut fournit '' (TypeError en PHP 8 sinon)
        $atts = shortcode_atts([
            'limit'    => 30,
            'personid' => '',
            'surname'  => '',
        ], (array) $atts, 'tng_media_gallery');

        $media = $this->get_media($atts);

        if ($media === null) {
            // Détail technique uniquement pour les administrateurs
            if (current_user_can('manage_options')) {
                return '<p class="tng-media-notice"><strong>TNG Media Gallery :</strong> '
                    . esc_html($this->error) . '</p>';
            }
            return '';
        }

        if (empty($media)) {
            return '<p>Aucun média trouvé.</p>';
        }

        $mode = get_option('tng_mg_display_mode', 'grid');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'grid';
        }

        $this->enqueue_assets();

        return $this->render_gallery($media, $mode);
    }

    /* ------------------------------------------------------------------
     * Récupération des médias (cache + requête préparée)
     * ------------------------------------------------------------------ */

    /**
     * @return array|null null en cas d'erreur (connexion ou requête)
     */
    protected function get_media(array $atts) {

        $limit    = max(1, min(100, (int) $atts['limit']));
        $personID = sanitize_text_field($atts['personid']);
        $surname  = sanitize_text_field($atts['surname']);

        $prefix       = $this->get_prefix();
        $hide_private = tngs_hide_living('gallery');
        $ttl          = max(0, (int) get_option('tng_mg_cache_minutes', 15)) * MINUTE_IN_SECONDS;

        $cache_key = 'tng_mg_' . md5(wp_json_encode([
            $limit, $personID, $surname, $prefix, $hide_private,
            (int) get_option('tng_mg_cache_ver', 1),
        ]));

        // Cache hit : aucune connexion à la base distante
        if ($ttl > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $db = $this->get_db();
        if (!$db) {
            return null;
        }

        $t_media  = "`{$prefix}media`";
        $t_links  = "`{$prefix}medialinks`";
        $t_people = "`{$prefix}people`";

        // TNG : personID n'est unique que par arbre (gedcom) ; linktype 'I' = individu.
        $sql = "
            SELECT DISTINCT
                m.mediaID,
                m.mediatypeID,
                m.path,
                m.thumbpath,
                m.description,
                LEFT(m.notes, 400) AS notes,
                m.changedate,
                p.personID,
                p.lastname,
                p.firstname
            FROM {$t_media} m
            INNER JOIN {$t_links} l  ON l.mediaID = m.mediaID AND l.linktype = 'I'
            INNER JOIN {$t_people} p ON p.personID = l.personID AND p.gedcom = l.gedcom
            WHERE 1=1
        ";

        $params = [];

        if ($hide_private) {
            // Respecte la confidentialité TNG (personnes vivantes / privées / médias masqués)
            $sql .= " AND p.living = 0 AND p.private = 0 AND l.dontshow = 0 ";
        }

        if ($personID !== '') {
            $sql    .= " AND p.personID = %s ";
            $params[] = $personID;
        }

        if ($surname !== '') {
            $sql    .= " AND p.lastname = %s ";
            $params[] = $surname;
        }

        $sql    .= " ORDER BY m.changedate DESC, m.mediaID DESC LIMIT %d ";
        $params[] = $limit;

        $rows = $db->get_results($db->prepare($sql, $params), ARRAY_A);

        if ($db->last_error) {
            $this->error = tngs_t('Erreur de requête TNG :') . ' ' . $db->last_error . ' '
                . tngs_t('(vérifiez le préfixe dans TNG Suite > Général, ou le réglage « Vivants / privés » du module si vos colonnes diffèrent).');
            error_log('TNG Media Gallery : ' . $db->last_error);
            return null;
        }

        $rows = is_array($rows) ? $rows : [];

        if ($ttl > 0) {
            set_transient($cache_key, $rows, $ttl);
        }

        return $rows;
    }

    /* ------------------------------------------------------------------
     * Rendu
     * ------------------------------------------------------------------ */

    protected function render_gallery(array $media, $mode) {

        $base_url     = untrailingslashit(tngs_site_url());
        $thumb_height = (int) get_option('tng_mg_thumb_height', 100);
        $thumb_width  = (int) get_option('tng_mg_thumb_width', 80);

        $classes = [
            'grid'    => 'tng-media-gallery-grid',
            'masonry' => 'tng-media-gallery-masonry',
            'elegant' => 'tng-media-gallery-elegant',
            'modern'  => 'tng-media-gallery-modern',
        ];

        $style = '';
        if ($mode === 'elegant') {
            $style = ' style="--tng-min-width:' . (int) ($thumb_width + 180) . 'px"';
        }

        $html  = '<div class="tng-media-gallery ' . esc_attr($classes[$mode]) . '"' . $style . '>';
        $index = 0;

        foreach ($media as $item) {
            $html .= $this->render_media_item($item, $mode, $base_url, $thumb_width, $thumb_height, $index++);
        }

        return $html . '</div>';
    }

    /**
     * Construit l'URL d'un fichier TNG (gère aussi les chemins absolus http/https).
     */
    protected function build_url($base_url, $collection, $path) {

        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return esc_url($path);
        }

        if ($base_url === '') {
            return '';
        }

        $parts = array_filter(
            [$base_url, trim((string) $collection, '/'), ltrim($path, '/')],
            'strlen'
        );

        return esc_url(implode('/', $parts));
    }

    protected function render_media_item(array $item, $mode, $base_url, $thumb_width, $thumb_height, $index) {

        $collection = trim((string) $item['mediatypeID']);

        $img_src = $this->build_url($base_url, $collection, $item['path']);
        if ($img_src === '') {
            return '';
        }

        $thumb_src = $this->build_url($base_url, $collection, $item['thumbpath']);
        if ($thumb_src === '') {
            $thumb_src = $img_src;
        }

        // TNG stocke parfois du HTML dans notes : on nettoie et on tronque.
        $raw_title = !empty($item['description']) ? $item['description'] : $item['notes'];
        $title     = wp_trim_words(wp_strip_all_tags((string) $raw_title), 20, '…');
        $name      = trim((string) $item['firstname'] . ' ' . (string) $item['lastname']);
        $alt       = $title !== '' ? $title : $name;

        $link = '<a href="' . $img_src . '" class="tng-lightbox" data-gallery="tng-gallery"'
            . ' data-title="' . esc_attr($name) . '"'
            . ' data-description="' . esc_attr($title) . '">';

        /* ----- Mode moderne : cartes avec légende en surimpression ----- */
        if ($mode === 'modern') {

            // Image complète : les vignettes TNG (≈80×100) seraient floues une fois étirées.
            $html  = '<div class="tng-media-item tng-card" style="--i:' . (int) min($index, 12) . '">';
            $html .= $link;
            $html .= '<img src="' . $img_src . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async">';

            if ($name !== '' || $title !== '') {
                $html .= '<span class="tng-card-caption">';
                if ($name !== '')  $html .= '<span class="tng-card-person">' . esc_html($name) . '</span>';
                if ($title !== '') $html .= '<span class="tng-card-title">' . esc_html($title) . '</span>';
                $html .= '</span>';
            }

            return $html . '</a></div>';
        }

        /* ----- Modes classiques : grille / masonry / élégante ----- */
        $html  = '<div class="tng-media-item">';
        $html .= $link;
        $html .= '<img src="' . $thumb_src . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async"'
            . ' style="max-height:' . (int) $thumb_height . 'px; max-width:' . (int) $thumb_width . 'px;">';
        $html .= '</a>';
        $html .= '<div class="tng-media-meta">';
        if ($title !== '') $html .= '<div class="tng-media-title">' . esc_html($title) . '</div>';
        if ($name !== '')  $html .= '<div class="tng-media-person">' . esc_html($name) . '</div>';
        $html .= '</div></div>';

        return $html;
    }
}

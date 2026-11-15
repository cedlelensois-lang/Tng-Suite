<?php
if (!defined('ABSPATH')) exit;

class TNG_Extra_Shortcodes {

    /** Nombre d'éléments visibles avant le bouton « Afficher tout ». */
    const VISIBLE = 10;

    public function register() {

        // Assets enregistrés ici, chargés uniquement quand ils sont nécessaires.
        wp_register_style('tng-suite', TNG_EXSC_URL . 'assets/css/tng-shortcodes.css', [], TNG_EXSC_VERSION);
        wp_register_script('tng-suite', TNG_EXSC_URL . 'assets/js/tng-shortcodes.js', [], TNG_EXSC_VERSION, true);

        add_shortcode('tng_name_cloud', [$this, 'shortcode_name_cloud']);
        add_shortcode('tng_births_today', [$this, 'shortcode_births_today']);
        add_shortcode('tng_marriages_today', [$this, 'shortcode_marriages_today']);
        add_shortcode('tng_deaths_today', [$this, 'shortcode_deaths_today']);
        add_shortcode('tng_recent_updates', [$this, 'shortcode_recent_updates']);
    }

    /* ------------------------------------------------------------------
     * Outils : cache, URL, erreurs
     * ---------------------------------------------------------------- */

    /**
     * Retourne les données en cache, ou les calcule via $callback.
     * Une erreur (null) n'est jamais mise en cache.
     *
     * @return array|null
     */
    protected function cached($suffix, $ttl, $callback) {
        $key = 'tng_exsc_' . (int) get_option('tng_exsc_gen', 1) . '_' . $suffix;

        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = call_user_func($callback);
        if (is_array($rows)) {
            set_transient($key, $rows, $ttl);
        }
        return $rows;
    }

    /**
     * URL d'une page TNG, ou chaîne vide si l'URL de base n'est pas configurée.
     */
    protected function tng_url($page, array $args = []) {
        $base = untrailingslashit(tngs_site_url());
        if ($base === '') {
            return '';
        }
        $args = array_filter($args, function ($value) {
            return $value !== '' && $value !== null;
        });
        return add_query_arg($args, $base . '/' . $page);
    }

    /**
     * Message d'erreur visible uniquement des administrateurs.
     */
    protected function error_html() {
        if (!current_user_can('manage_options')) {
            return '';
        }
        return '<p class="tng-error">TNG Extra Shortcodes : ' . esc_html(TNG_Extra_DB::message()) . '</p>';
    }

    /* ------------------------------------------------------------------
     * Nuage de noms
     * ---------------------------------------------------------------- */

    public function shortcode_name_cloud($atts) {

        $atts  = shortcode_atts(['limit' => 50], $atts, 'tng_name_cloud');
        $limit = min(200, max(1, absint($atts['limit'])));

        $rows = $this->cached('cloud_' . $limit, DAY_IN_SECONDS, function () use ($limit) {

            $db = TNG_Extra_DB::get();
            if (!$db) {
                return null;
            }

            $priv = tngs_hide_living('extra') ? ' AND Living = 0 AND private = 0' : '';
            $sql  = $db->prepare(
                "SELECT lastname, COUNT(*) AS cnt
                 FROM " . TNG_Extra_DB::table('people') . "
                 WHERE lastname <> ''" . $priv . "
                 GROUP BY lastname
                 ORDER BY cnt DESC, lastname ASC
                 LIMIT %d",
                $limit
            );
            return TNG_Extra_DB::results($sql);
        });

        if ($rows === null) {
            return $this->error_html();
        }
        if (empty($rows)) {
            return '<p>' . esc_html__('Aucun nom disponible.', 'tng-suite') . '</p>';
        }

        // Mélange à chaque affichage (les données, elles, sont en cache).
        shuffle($rows);

        $counts = array_map('intval', array_column($rows, 'cnt'));
        $max    = max($counts);
        $min    = min($counts);

        $out = '<div class="tng-name-cloud">';

        foreach ($rows as $row) {
            $ratio = ((int) $row->cnt - $min) / max(1, $max - $min);
            $size  = number_format(0.8 + 1.2 * $ratio, 2, '.', '');
            $style = ' style="font-size:' . esc_attr($size) . 'em"';
            $label = esc_html($row->lastname);

            $url = $this->tng_url('search.php', ['mylastname' => $row->lastname]);

            if ($url !== '') {
                $out .= '<a href="' . esc_url($url) . '"' . $style . '>' . $label . '</a> ';
            } else {
                $out .= '<span' . $style . '>' . $label . '</span> ';
            }
        }

        return $out . '</div>';
    }

    /* ------------------------------------------------------------------
     * Requêtes des anniversaires (cache : une entrée par jour)
     * ---------------------------------------------------------------- */

    /**
     * Naissances ou décès du jour. Seules les personnes décédées et non privées sont retournées.
     */
    protected function get_people_events($field) {

        $field = ($field === 'birthdatetr') ? 'birthdatetr' : 'deathdatetr'; // liste blanche

        return $this->cached($field . '_' . wp_date('md'), DAY_IN_SECONDS, function () use ($field) {

            $db = TNG_Extra_DB::get();
            if (!$db) {
                return null;
            }

            // Date calculée côté PHP avec le fuseau horaire de WordPress (et non celui de MySQL).
            $priv = tngs_hide_living('extra') ? ' AND Living = 0 AND private = 0' : '';
            $sql  = $db->prepare(
                "SELECT personID, gedcom, firstname, lastname, YEAR(`$field`) AS yr
                 FROM " . TNG_Extra_DB::table('people') . "
                 WHERE 1 = 1" . $priv . "
                   AND MONTH(`$field`) = %d
                   AND DAYOFMONTH(`$field`) = %d
                 ORDER BY `$field`, lastname, firstname",
                (int) wp_date('n'),
                (int) wp_date('j')
            );
            return TNG_Extra_DB::results($sql);
        });
    }

    /**
     * Mariages du jour. Famille et conjoints doivent être non vivants et non privés.
     */
    protected function get_marriage_events() {

        return $this->cached('marriages_' . wp_date('md'), DAY_IN_SECONDS, function () {

            $db = TNG_Extra_DB::get();
            if (!$db) {
                return null;
            }

            $people   = TNG_Extra_DB::table('people');
            $families = TNG_Extra_DB::table('families');

            $priv = tngs_hide_living('extra')
                ? ' AND f.living = 0 AND f.private = 0 AND (h.personID IS NULL OR (h.Living = 0 AND h.private = 0)) AND (w.personID IS NULL OR (w.Living = 0 AND w.private = 0))'
                : '';
            $sql  = $db->prepare(
                "SELECT f.familyID, f.gedcom, YEAR(f.marrdatetr) AS yr,
                        h.firstname AS husbandFirst, h.lastname AS husbandLast,
                        w.firstname AS wifeFirst,    w.lastname AS wifeLast
                 FROM $families f
                 LEFT JOIN $people h ON h.personID = f.husband AND h.gedcom = f.gedcom
                 LEFT JOIN $people w ON w.personID = f.wife    AND w.gedcom = f.gedcom
                 WHERE 1 = 1" . $priv . "
                   AND MONTH(f.marrdatetr) = %d
                   AND DAYOFMONTH(f.marrdatetr) = %d
                 ORDER BY f.marrdatetr",
                (int) wp_date('n'),
                (int) wp_date('j')
            );
            return TNG_Extra_DB::results($sql);
        });
    }

    /* ------------------------------------------------------------------
     * Shortcodes d'anniversaires
     * ---------------------------------------------------------------- */

    public function shortcode_births_today() {
        return $this->render_people(
            $this->get_people_events('birthdatetr'),
            __('Naissances du jour', 'tng-suite'),
            __('Aucune naissance aujourd’hui.', 'tng-suite')
        );
    }

    public function shortcode_deaths_today() {
        return $this->render_people(
            $this->get_people_events('deathdatetr'),
            __('Décès du jour', 'tng-suite'),
            __('Aucun décès aujourd’hui.', 'tng-suite')
        );
    }

    public function shortcode_marriages_today() {
        return $this->render_families(
            $this->get_marriage_events(),
            __('Mariages du jour', 'tng-suite'),
            __('Aucun mariage aujourd’hui.', 'tng-suite')
        );
    }

    /* ------------------------------------------------------------------
     * Derniers individus mis à jour
     * ---------------------------------------------------------------- */

    /** La colonne « préfixe du nom » (de, van…) n'existe que dans les versions récentes de TNG. */
    protected function has_lnprefix() {
        static $has = null;
        if ($has === null) {
            $db  = TNG_Extra_DB::get();
            $res = $db ? $db->get_results('SHOW COLUMNS FROM ' . TNG_Extra_DB::table('people') . " LIKE 'lnprefix'") : null;
            $has = !empty($res);
        }
        return $has;
    }

    /**
     * [tng_recent_updates limit="10" tree="" date="yes" date_format="" link="yes" title=""]
     * Les personnes les plus récemment modifiées dans TNG (date de dernière modification), la plus récente d'abord.
     * Les personnes vivantes ou privées sont exclues, sauf réglage contraire de ce module.
     */
    public function shortcode_recent_updates($atts) {

        $atts = shortcode_atts([
            'limit'       => 10,
            'tree'        => '',
            'date'        => 'yes',
            'date_format' => '',
            'link'        => 'yes',
            'title'       => '',
        ], $atts, 'tng_recent_updates');

        $limit     = min(100, max(1, absint($atts['limit'])));
        $tree      = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $atts['tree']);
        $tree      = $tree !== '' ? $tree : tngs_gedcom();
        $show_date = !in_array(strtolower(trim((string) $atts['date'])), ['no', 'non', '0', 'false'], true);
        $link      = !in_array(strtolower(trim((string) $atts['link'])), ['no', 'non', '0', 'false'], true);
        $hide      = tngs_hide_living('extra');

        // Mise en cache courte : une modification dans TNG apparaît en moins d'une heure.
        $rows = $this->cached('recent_' . $limit . '_' . md5($tree . '|' . (int) $hide), HOUR_IN_SECONDS, function () use ($limit, $tree, $hide) {

            $db = TNG_Extra_DB::get();
            if (!$db) {
                return null;
            }

            $prefix = $this->has_lnprefix() ? 'lnprefix' : "'' AS lnprefix";
            $where  = "changedate IS NOT NULL AND changedate > '1900-01-01'";
            $args   = [];
            if ($hide) {
                $where .= ' AND Living = 0 AND private = 0';
            }
            if ($tree !== '') {
                $where .= ' AND gedcom = %s';
                $args[] = $tree;
            }
            $args[] = $limit;

            $sql = $db->prepare(
                "SELECT personID, gedcom, firstname, $prefix, lastname, changedate
                 FROM " . TNG_Extra_DB::table('people') . "
                 WHERE $where
                 ORDER BY changedate DESC, personID DESC
                 LIMIT %d",
                $args
            );
            return TNG_Extra_DB::results($sql);
        });

        if ($rows === null) {
            return $this->error_html();
        }
        if (empty($rows)) {
            return '<p>' . esc_html(tngs_t('Aucune mise à jour récente.')) . '</p>';
        }

        $format = trim((string) $atts['date_format']);
        if ($format === '') {
            $format = (function_exists('tngs_lang') && tngs_lang() === 'en') ? 'm/d/Y' : 'd/m/Y';
        }

        wp_enqueue_style('tng-suite');

        $out = '<div class="tng-recent">';
        if (trim((string) $atts['title']) !== '') {
            $out .= '<h3>' . esc_html($atts['title']) . '</h3>';
        }
        $out .= '<ul>';
        foreach ($rows as $r) {
            $last  = mb_strtoupper(trim(trim((string) $r->lnprefix) . ' ' . trim((string) $r->lastname)));
            $name  = trim($last . ' ' . trim((string) $r->firstname));
            $label = esc_html($name !== '' ? $name : $r->personID);
            $url   = $link ? $this->tng_url('getperson.php', ['personID' => $r->personID, 'tree' => $r->gedcom]) : '';
            $text  = $url !== '' ? '<a href="' . esc_url($url) . '">' . $label . '</a>' : $label;

            $date = '';
            if ($show_date) {
                $ts = strtotime($r->changedate . ' UTC');   // date TNG en heure locale : on la formate telle quelle, sans décalage
                if ($ts) {
                    $date = '<span class="tng-recent-date">(' . esc_html(wp_date($format, $ts, new DateTimeZone('UTC'))) . ')</span>';
                }
            }
            $out .= '<li><span class="tng-recent-name">' . $text . '</span>' . $date . '</li>';
        }
        return $out . '</ul></div>';
    }

    /* ------------------------------------------------------------------
     * Affichage
     * ---------------------------------------------------------------- */

    protected function render_people($rows, $title, $empty) {

        if ($rows === null) {
            return $this->error_html();
        }

        $items = [];
        foreach ($rows as $r) {
            $name    = trim($r->firstname . ' ' . $r->lastname);
            $items[] = [
                'label' => $name !== '' ? $name : $r->personID,
                'url'   => $this->tng_url('getperson.php', ['personID' => $r->personID, 'tree' => $r->gedcom]),
                'yr'    => (int) $r->yr,
            ];
        }

        return $this->render_list($items, $title, $empty);
    }

    protected function render_families($rows, $title, $empty) {

        if ($rows === null) {
            return $this->error_html();
        }

        $items = [];
        foreach ($rows as $r) {
            $names = [];

            $husband = trim($r->husbandFirst . ' ' . $r->husbandLast);
            $wife    = trim($r->wifeFirst . ' ' . $r->wifeLast);
            if ($husband !== '') $names[] = $husband;
            if ($wife !== '')    $names[] = $wife;

            $label = implode(' & ', $names);
            if ($label === '') {
                $label = sprintf(tngs_t('Famille %s'), $r->familyID);
            }

            $items[] = [
                'label' => $label,
                'url'   => $this->tng_url('familygroup.php', ['familyID' => $r->familyID, 'tree' => $r->gedcom]),
                'yr'    => (int) $r->yr,
            ];
        }

        return $this->render_list($items, $title, $empty);
    }

    /**
     * Liste générique : $items = [ ['label' => ..., 'url' => ..., 'yr' => ...], ... ]
     */
    protected function render_list(array $items, $title, $empty) {

        if (empty($items)) {
            return '<p>' . esc_html($empty) . '</p>';
        }

        $uid = wp_unique_id('tng-events-');

        $out  = '<div id="' . esc_attr($uid) . '" class="tng-events">';
        $out .= '<h3>' . esc_html($title) . '</h3>';
        $out .= '<ul>';

        foreach ($items as $i => $item) {
            $class = ($i >= self::VISIBLE) ? ' class="tng-event-extra tng-hidden"' : '';
            $label = esc_html($item['label']);
            $text  = ($item['url'] !== '')
                ? '<a href="' . esc_url($item['url']) . '">' . $label . '</a>'
                : $label;
            $year  = ($item['yr'] > 0) ? ' (' . esc_html($item['yr']) . ')' : '';

            $out .= '<li' . $class . '>' . $text . $year . '</li>';
        }

        $out .= '</ul>';

        if (count($items) > self::VISIBLE) {
            // CSS et JS ne sont chargés que lorsqu'une liste dépasse la limite.
            wp_enqueue_style('tng-suite');
            wp_enqueue_script('tng-suite');

            $out .= '<button type="button" class="tng-events-toggle" aria-expanded="false"'
                  . ' aria-controls="' . esc_attr($uid) . '"'
                  . ' data-target="' . esc_attr($uid) . '"'
                  . ' data-more="' . esc_attr__('Afficher tout', 'tng-suite') . '"'
                  . ' data-less="' . esc_attr__('Réduire la liste', 'tng-suite') . '">'
                  . esc_html__('Afficher tout', 'tng-suite')
                  . '</button>';
        }

        return $out . '</div>';
    }
}

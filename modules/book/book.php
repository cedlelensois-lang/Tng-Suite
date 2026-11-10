<?php
/**
 * TNG Suite – module « Livre généalogique » (ex-plugin TNG Livre Généalogique 1.1.0).
 * Génère le livre (ascendance Sosa + descendance d'Aboville) d'une personne TNG : depuis l'administration
 * (TNG Suite > Livre généalogique) ou via le shortcode [tng_book] (utilisateurs connectés).
 * Connexion, préfixe des tables, URL de TNG, arbre par défaut et confidentialité : réglages communs.
 */

if (!defined('ABSPATH')) exit;

define('TNGBOOK_VER', TNGSUITE_VERSION);
define('TNGBOOK_DIR', plugin_dir_path(__FILE__));
define('TNGBOOK_URL', plugin_dir_url(__FILE__));

require_once TNGBOOK_DIR . 'includes/class-data.php';
require_once TNGBOOK_DIR . 'includes/class-boxes.php';
require_once TNGBOOK_DIR . 'includes/class-doc.php';
require_once TNGBOOK_DIR . 'includes/class-charts.php';
require_once TNGBOOK_DIR . 'includes/class-stats.php';
require_once TNGBOOK_DIR . 'includes/class-map.php';
require_once TNGBOOK_DIR . 'includes/class-country.php';
require_once TNGBOOK_DIR . 'includes/trait-stats-view.php';
require_once TNGBOOK_DIR . 'includes/class-renderer.php';

final class TNGBook {
    const OPT = 'tngbook_options';
    const MAX_PEOPLE = 4000;   // valeur de départ du réglage « nombre maximum de personnes » (0 = illimité)

    public static function langs() {
        return ['fr' => 'Français', 'en' => 'English (UK)', 'es' => 'Español', 'ca' => 'Català'];
    }

    /** Parties du livre, dans l'ordre par défaut : clé => libellé. */
    public static function partLabels() {
        return [
            'surn' => 'Page sur le patronyme', 'asc' => 'Ascendance', 'desc' => 'Descendance', 'stats' => 'En chiffres', 'cous' => 'Cousinages et implexes', 'map' => 'Carte des lieux de vie',
            'idxn' => 'Index des noms', 'idxp' => 'Index des lieux', 'src' => 'Sources',
            'alist' => 'Liste des ascendants', 'dlist' => 'Liste des descendants',
        ];
    }

    /** Ordre des parties : clés valides sans doublon, les parties absentes étant ajoutées à leur place par défaut. */
    public static function clean_order($v) {
        $def = array_keys(self::partLabels());
        $out = [];
        foreach ((array) $v as $k) if (is_string($k) && in_array($k, $def, true) && !in_array($k, $out, true)) $out[] = $k;
        if ($out && !in_array('surn', $out, true)) array_unshift($out, 'surn');   // profils d'avant la page patronyme : elle s'ouvre en tête
        foreach ($def as $k) if (!in_array($k, $out, true)) $out[] = $k;
        return $out;
    }

    /** Texte enrichi de la page patronyme : mise en forme, listes, tableaux, liens et images ; rien d'exécutable. */
    public static function surname_kses($html) {
        $a  = ['class' => true, 'style' => true, 'align' => true];
        $h  = ['style' => true, 'align' => true];
        $tags = [
            'p' => $a, 'div' => $a, 'span' => $a, 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'del' => [], 'ins' => [], 'strike' => [],
            'sup' => [], 'sub' => [], 'small' => [], 'mark' => [], 'blockquote' => $a, 'figure' => $a, 'figcaption' => $a,
            'h1' => $h, 'h2' => $h, 'h3' => $h, 'h4' => $h, 'h5' => $h, 'h6' => $h, 'ul' => [], 'ol' => [], 'li' => $h, 'a' => ['href' => true, 'title' => true],
            'table' => $a + ['border' => true, 'width' => true], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'caption' => [], 'colgroup' => [], 'col' => ['width' => true],
            'tr' => $h, 'th' => $h + ['colspan' => true, 'rowspan' => true, 'width' => true], 'td' => $h + ['colspan' => true, 'rowspan' => true, 'width' => true],
            'img' => ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true],
        ];
        $out = wp_kses((string) $html, $tags, ['http', 'https', 'mailto', 'data']);   // « data » : images collées depuis le presse-papiers
        return preg_replace('#(href\s*=\s*["\'])\s*data:#i', '$1#blocked:', $out);   // jamais de lien data:
    }

    public static function defaults() {
        return [
            // Photos et accès (connexion, préfixe : réglages communs de TNG Suite)
            'tng_path' => '', 'photo_folder' => 'photos',
            'front_access' => 'none', 'front_living' => 'anonymize',
            // Paramètres du livre (tous conservés d'une fois sur l'autre)
            'personID' => '', 'tree' => '', 'lang' => tngs_lang(),
            'gen_up' => 6, 'gen_down' => 4, 'max_people' => self::MAX_PEOPLE, 'living' => 'anonymize', 'place_parts' => 2,
            'photos' => 1, 'spphotos' => 1, 'generic' => 1, 'sources' => 1, 'index' => 1, 'notes' => 0, 'military' => 0, 'mpf' => 1, 'mini' => 0, 'trees' => 1, 'stats' => 1, 'stats_first' => 1, 'stats_last' => 1, 'desclist' => 1, 'map' => 0, 'bapt' => 0, 'burial' => 1, 'verbose' => 0, 'country' => 0, 'regions' => 0, 'asclist' => 0, 'resi' => 0, 'sibs' => 0, 'marrage' => 0, 'cous' => 1, 'allunions' => 1, 'order' => ['asc', 'desc', 'stats', 'map', 'idxn', 'idxp', 'src', 'alist', 'dlist'],
            'title' => '', 'author' => '', 'preface' => '', 'surname' => '', 'surname_title' => '', 'format' => 'html', 'template' => 'classic',
            'colors' => TNGBook_Renderer::defaultColors(),
        ];
    }

    public static function opts() {
        $o = wp_parse_args(get_option(self::OPT, []), self::defaults());
        $o['prefix'] = tngs_prefix();                       // réglage commun
        // Vivants/privés : réglage du module (TNG Suite > Général > Modules). « Afficher » = inclus en entier.
        if (!tngs_hide_living('book')) $o['living'] = 'include';
        elseif ($o['living'] === 'include') $o['living'] = 'anonymize';
        return $o;
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_post_tngbook_generate', [__CLASS__, 'generate']);
        add_action('admin_post_tngbook_profile_save', [__CLASS__, 'profile_save']);
        add_action('admin_post_tngbook_profile_apply', [__CLASS__, 'profile_apply']);
        add_shortcode('tng_book', [__CLASS__, 'shortcode']);
    }

    /* ---------- Profils de livre enregistrés ---------- */

    const PROFILES = 'tngbook_profiles';
    const MAX_PROFILES = 50;
    const ACTIVE = 'tngbook_profile_active';   // dernier profil chargé ou enregistré

    /** Profils enregistrés : nom => paramètres du livre (sans la connexion TNG ni les réglages du formulaire public). */
    public static function profiles() {
        $p = get_option(self::PROFILES, []);
        return is_array($p) ? $p : [];
    }

    private static function profile_back($msg) {
        wp_safe_redirect(add_query_arg(['page' => 'tngbook', 'tngbook_msg' => $msg], admin_url('admin.php')));
        exit;
    }

    private static function profile_name($raw) {
        $n = sanitize_text_field((string) $raw);
        return function_exists('mb_substr') ? mb_substr(trim($n), 0, 60, 'UTF-8') : substr(trim($n), 0, 60);
    }

    /** Enregistre les paramètres affichés dans le formulaire sous un nom (remplace un profil du même nom). */
    public static function profile_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        if (!isset($_POST['tngbook_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tngbook_nonce'])), 'tngbook_generate')) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
        $name = self::profile_name(wp_unslash($_POST['tngbook_profile_name'] ?? ''));
        if (!empty($_POST['tngbook_profile_update'])) {          // « Mettre à jour le profil actif »
            $act = (string) get_option(self::ACTIVE, '');
            if ($act !== '' && isset(self::profiles()[$act])) $name = $act;
        }
        $in = (isset($_POST[self::OPT]) && is_array($_POST[self::OPT])) ? wp_unslash($_POST[self::OPT]) : [];
        $o = self::sanitize($in);
        update_option(self::OPT, $o);                       // le formulaire est aussi enregistré
        if ($name === '') self::profile_back('noname');
        $all = self::profiles();
        if (!isset($all[$name]) && count($all) >= self::MAX_PROFILES) self::profile_back('full');
        $prof = self::sanitize_gen($o, self::defaults());
        foreach (['tng_path', 'photo_folder', 'front_access', 'front_living', 'prefix'] as $k) unset($prof[$k]);
        $all[$name] = $prof;
        update_option(self::PROFILES, $all, false);
        update_option(self::ACTIVE, $name, false);
        self::profile_back('saved');
    }

    /** Charge ou supprime un profil. */
    public static function profile_apply() {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        if (!isset($_POST['tngbook_pnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tngbook_pnonce'])), 'tngbook_profile')) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
        $name = self::profile_name(wp_unslash($_POST['tngbook_profile'] ?? ''));
        $all = self::profiles();
        if ($name === '' || !isset($all[$name])) self::profile_back('missing');
        if (($_POST['op'] ?? '') === 'delete') {
            unset($all[$name]);
            update_option(self::PROFILES, $all, false);
            if (get_option(self::ACTIVE, '') === $name) update_option(self::ACTIVE, '', false);
            self::profile_back('deleted');
        }
        $cur = self::opts();
        $new = self::sanitize_gen(array_merge((array) $all[$name]), $cur);   // repasse par le nettoyage des paramètres
        update_option(self::OPT, $new);
        update_option(self::ACTIVE, $name, false);
        self::profile_back('loaded');
    }

    /** Nom du profil actif (chargé ou enregistré en dernier), s'il existe encore. */
    private static function active_name() {
        $act = (string) get_option(self::ACTIVE, '');
        return ($act !== '' && isset(self::profiles()[$act])) ? $act : '';
    }

    private static function profile_panel() {
        $msgs = ['saved' => 'Profil enregistré.', 'loaded' => 'Profil chargé : les paramètres ci-dessous sont ceux du profil.', 'deleted' => 'Profil supprimé.',
            'noname' => 'Donnez un nom au profil (les paramètres ont tout de même été enregistrés).', 'missing' => 'Profil introuvable.', 'full' => 'Nombre maximal de profils atteint (' . self::MAX_PROFILES . ').'];
        $m = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';
        if (isset($msgs[$m])) echo '<div class="notice ' . (in_array($m, ['saved', 'loaded', 'deleted'], true) ? 'notice-success' : 'notice-warning') . ' is-dismissible"><p>' . esc_html(tngs_t($msgs[$m])) . '</p></div>';
        $all = self::profiles();
        $act = (string) get_option(self::ACTIVE, '');
        if (!isset($all[$act])) $act = '';
        echo '<h2>' . esc_html(tngs_t('Profils de livre enregistrés')) . '</h2>';
        if ($act !== '') echo '<p>' . esc_html(tngs_t('Profil actif :')) . ' <strong>' . esc_html($act) . '</strong> — ' . esc_html(tngs_t('modifiez les réglages ci-dessous puis utilisez « Mettre à jour le profil » pour l’enregistrer.')) . '</p>';
        if (!$all) {
            echo '<p class="description">' . esc_html(tngs_t('Aucun profil. Réglez le livre ci-dessous, donnez un nom à côté du bouton « Enregistrer comme profil », puis retrouvez ici vos réglages (personne, générations, options, modèle, couleurs, préface…).')) . '</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px">'
            . '<input type="hidden" name="action" value="tngbook_profile_apply">';
        wp_nonce_field('tngbook_profile', 'tngbook_pnonce');
        echo '<select name="tngbook_profile">';
        ksort($all, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($all as $n => $x) {
            $who = trim((string) ($x['personID'] ?? ''));
            echo '<option value="' . esc_attr($n) . '"' . selected($n, $act, false) . '>' . esc_html($n . ($who !== '' ? ' (' . $who . ')' : '')) . '</option>';
        }
        echo '</select> <button type="submit" class="button" name="op" value="load">' . esc_html(tngs_t('Charger')) . '</button> '
            . '<button type="submit" class="button" name="op" value="delete" onclick="return confirm(\'' . esc_js(tngs_t('Supprimer ce profil ?')) . '\')">' . esc_html(tngs_t('Supprimer')) . '</button></form>';
    }

    /* ---------- Connexion TNG (lecture seule, fournie par le cœur de TNG Suite) ---------- */

    public static function db() {
        $db = tngs_db();
        return $db ? $db : new WP_Error('tngbook_db', tngs_db_error_message());
    }

    /**
     * Retrouve le dossier TNG sur le disque : chemin absolu, chemin relatif à la racine web, ou URL de TNG.
     */
    public static function resolve_tng_path($in) {
        $in = trim((string) $in);
        if ($in === '') $in = tngs_site_url();   // par défaut : URL de TNG des réglages communs
        if ($in === '') return '';
        $cands = [];
        if (preg_match('#^https?://#i', $in)) {
            $rel = (string) parse_url($in, PHP_URL_PATH);
        } else {
            $rel = $in;
            $cands[] = $in;
        }
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel !== '') {
            $dr = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : '';
            if ($dr !== '') $cands[] = $dr . '/' . $rel;
            $dir = untrailingslashit(ABSPATH);
            for ($i = 0; $i < 6 && $dir !== '' && $dir !== '/' && $dir !== '.'; $i++) {
                $cands[] = $dir . '/' . $rel;
                $up = dirname($dir);
                if ($up === $dir) break;
                $dir = $up;
            }
        }
        foreach ($cands as $c) {
            $r = @realpath($c);
            if ($r && is_dir($r)) return $r;
        }
        return '';
    }

    private static function mpdf_available() {
        $auto = TNGBOOK_DIR . 'vendor/autoload.php';
        if (file_exists($auto)) require_once $auto;
        return class_exists('\Mpdf\Mpdf');
    }

    /* ---------- Réglages : tout est sauvegardé ensemble ---------- */

    public static function menu() {
        tngs_add_subpage('Livre généalogique TNG', 'Livre généalogique', 'tngbook', [__CLASS__, 'page']);
    }

    public static function register() {
        register_setting('tngbook_group', self::OPT, ['sanitize_callback' => [__CLASS__, 'sanitize']]);
    }

    /** Paramètres du livre uniquement (utilisé aussi pour le formulaire public). */
    public static function sanitize_gen($in, array $base) {
        $in = (array) $in;
        $out = $base;
        $out['personID'] = preg_replace('/[^\w\-.]/u', '', (string) ($in['personID'] ?? ''));
        $out['tree'] = sanitize_text_field($in['tree'] ?? '');
        $out['lang'] = isset(self::langs()[$in['lang'] ?? '']) ? $in['lang'] : 'fr';
        $out['gen_up'] = max(0, (int) ($in['gen_up'] ?? 6));
        $out['gen_down'] = max(0, (int) ($in['gen_down'] ?? 4));
        $out['living'] = in_array($in['living'] ?? '', ['exclude', 'anonymize'], true) ? $in['living'] : 'anonymize';
        if (!tngs_hide_living('book')) $out['living'] = 'include';
        $out['place_parts'] = max(0, min(5, (int) ($in['place_parts'] ?? 2)));
        foreach (['photos', 'spphotos', 'generic', 'sources', 'index', 'notes', 'military', 'mpf', 'mini', 'trees', 'stats', 'stats_first', 'stats_last', 'desclist', 'map', 'bapt', 'burial', 'verbose', 'country', 'regions', 'asclist', 'resi', 'sibs', 'marrage', 'cous', 'allunions'] as $k) {
            $out[$k] = empty($in[$k]) ? 0 : 1;
        }
        $out['order'] = self::clean_order($in['order'] ?? null);
        $out['title'] = sanitize_text_field($in['title'] ?? '');
        $out['author'] = sanitize_text_field($in['author'] ?? '');
        $out['preface'] = sanitize_textarea_field($in['preface'] ?? '');
        if (current_user_can('manage_options')) {   // le texte de la page patronyme est rédigé par l'administrateur (jamais par les visiteurs)
            $out['surname'] = array_key_exists('surname', $in) ? self::surname_kses($in['surname']) : (string) ($base['surname'] ?? '');
            $out['surname_title'] = array_key_exists('surname_title', $in) ? sanitize_text_field($in['surname_title']) : (string) ($base['surname_title'] ?? '');
        }
        $tp = (string) ($in['template'] ?? 'classic');
        $out['template'] = isset(TNGBook_Renderer::templates()[$tp]) ? $tp : 'classic';
        $f = $in['format'] ?? '';
        $out['format'] = in_array($f, ['pdf', 'docx', 'odt'], true) ? $f : 'html';
        // couleurs des textes (sélecteurs de couleur) ; case « par défaut » = retour aux couleurs d'origine
        $def = TNGBook_Renderer::defaultColors();
        $cols = $def;
        if (empty($in['colors_reset']) && !empty($in['colors']) && is_array($in['colors'])) {
            foreach ($def as $k => $dv) {
                $v = isset($in['colors'][$k]) ? sanitize_hex_color((string) $in['colors'][$k]) : '';
                if ($v && strlen($v) === 7) $cols[$k] = strtolower($v);
            }
        }
        $out['colors'] = $cols;
        return $out;
    }

    public static function sanitize($in) {
        $in = (array) $in;
        $old = self::opts();
        $out = self::sanitize_gen($in, $old);
        foreach (['tng_path', 'photo_folder'] as $k) {
            $out[$k] = sanitize_text_field($in[$k] ?? '');
        }
        $out['photo_folder'] = $out['photo_folder'] !== '' ? $out['photo_folder'] : 'photos';
        $out['max_people'] = max(0, (int) ($in['max_people'] ?? self::MAX_PEOPLE));   // réglage administrateur (jamais modifiable par les visiteurs)
        $out['front_access'] = ($in['front_access'] ?? '') === 'logged_in' ? 'logged_in' : 'none';
        $out['front_living'] = ($in['front_living'] ?? '') === 'exclude' ? 'exclude' : 'anonymize';
        // Anciennes clés de connexion (ex-plugin autonome) : on ne les conserve pas.
        foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'prefix'] as $k) unset($out[$k]);
        return $out;
    }

    /* ---------- Composants de formulaire ---------- */

    private static function field($label, $key, $o, $type = 'text', $desc = '', $attrs = '') {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input id="%1$s" name="%3$s" type="%4$s" value="%5$s" class="regular-text" %6$s>%7$s</td></tr>',
            esc_attr('tngbook_' . $key), esc_html($label), esc_attr(self::OPT . '[' . $key . ']'), esc_attr($type),
            esc_attr($type === 'password' ? '' : $o[$key]), $attrs,
            $desc ? '<p class="description">' . wp_kses_post($desc) . '</p>' : ''
        );
    }

    private static function select($label, $key, array $choices, $current, $desc = '') {
        $id = 'tngbook_' . $key;
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td><select id="' . esc_attr($id) . '" name="' . esc_attr(self::OPT . '[' . $key . ']') . '">';
        foreach ($choices as $v => $l) {
            echo '<option value="' . esc_attr($v) . '"' . selected((string) $current, (string) $v, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select>' . ($desc ? '<p class="description">' . wp_kses_post($desc) . '</p>' : '') . '</td></tr>';
    }

    private static function chk($key, $label, $o) {
        return '<label><input type="checkbox" name="' . esc_attr(self::OPT . '[' . $key . ']') . '" value="1"' . checked(!empty($o[$key]), true, false) . '> ' . $label . '</label><br>';
    }

    /** Lignes du formulaire « Livre à générer » (admin et formulaire public). */
    private static function gen_fields($o, $admin) {
        self::field('Identifiant de la personne', 'personID', $o, 'text', 'Ex. <code>I1239</code> (dans l’URL TNG : <code>getperson.php?personID=I1239</code>).', 'placeholder="I1"');
        self::field('Arbre (gedcom)', 'tree', $o, 'text', 'Vide = arbre par défaut de TNG Suite > Général, sinon détection automatique.');
        self::select('Langue du livre', 'lang', self::langs(), $o['lang']);
        self::field('Générations d’ascendants', 'gen_up', $o, 'number', '0 = pas d’ascendance. Aucune limite : 6 par défaut ; chaque génération double le nombre de places, le livre s’arrête de lui-même quand il n’y a plus d’ancêtres connus.', 'min="0"');
        self::field('Générations de descendants', 'gen_down', $o, 'number', '0 = pas de descendance. Aucune limite : 4 par défaut.', 'min="0"');
        if ($admin) self::field('Nombre maximum de personnes', 'max_people', $o, 'number', 'Garde-fou contre les livres trop lourds (délai dépassé, mémoire). 4000 par défaut ; <strong>0 = illimité</strong>. Si la génération échoue ou s’arrête, baissez cette valeur ou le nombre de générations.', 'min="0"');
        if ($admin) {
            if (tngs_hide_living('book')) {
                self::select('Personnes vivantes/privées', 'living', [
                    'anonymize' => 'Anonymiser (« Personne vivante », sans date ni photo)',
                    'exclude' => 'Exclure (retirées du livre)',
                ], $o['living'], 'Pour les afficher en entier : TNG Suite > Général > Modules > Livre généalogique (⚠️ RGPD).');
            } else {
                echo '<tr><th>Personnes vivantes/privées</th><td><div style="border-left:4px solid #b32d2e;background:#fcf0f1;padding:10px 12px;max-width:640px">'
                    . '<strong>⚠️ Attention, RGPD</strong> : les personnes vivantes ou privées sont incluses <strong>en entier</strong> (noms, dates, lieux, photos, notes). '
                    . 'Ne diffusez pas ce livre sans leur consentement et réservez-le à un usage familial privé.<br>'
                    . 'Pour les masquer : <a href="' . esc_url(admin_url('admin.php?page=tng-suite')) . '">TNG Suite &gt; Général &gt; Modules</a>.'
                    . '</div></td></tr>';
            }
        }
        self::select('Niveaux de lieux affichés', 'place_parts', ['1' => 'Commune seule', '2' => 'Commune, département', '3' => '3 niveaux', '0' => 'Lieu complet'], $o['place_parts']);
        echo '<tr><th>Contenu</th><td>'
            . self::chk('photos', 'Photos TNG (uniquement celles au statut « Photo par défaut »)', $o)
            . self::chk('spphotos', 'Photo du conjoint (petite vignette à côté de l’union, quand le conjoint n’a pas sa propre fiche)', $o)
            . self::chk('generic', 'Silhouettes génériques (homme / femme) sinon', $o)
            . self::chk('bapt', 'Baptême (date et lieu, quand ils sont renseignés dans TNG)', $o)
            . self::chk('burial', 'Sépulture (inhumation, ou incinération le cas échéant : date et lieu quand ils sont renseignés)', $o)
            . self::chk('verbose', 'Phrases détaillées (« Il est né le … à …, fils de … » ; « Il est décédé le …, à l’âge de … ans » ; « Il a été inhumé … ») au lieu des mentions courtes « Né le … »', $o)
            . self::chk('country', 'Pays entre parenthèses après le lieu (« Lyon, Rhône (France) »), traduit dans la langue du livre et déduit du département ou de l’État quand il n’est pas écrit', $o)
            . self::chk('regions', 'Départements, provinces et États traduits dans la langue du livre (Bouches-du-Rhône / Bocas del Ródano, Bavière / Bavaria / Baviera, Nouvelle-Galles du Sud / New South Wales…), y compris dans les cartes', $o)
            . self::chk('notes', 'Notes (générales de chaque personne et de chaque union, et notes des événements : naissance, mariage, décès…)', $o)
            . self::chk('cous', 'Cousinages et implexes (partie ajoutée seulement si un même ancêtre apparaît plusieurs fois : liste des implexes et des unions entre apparentés)', $o)
            . self::chk('allunions', 'Autres mariages et unions des ancêtres (en plus de l’union dont descend la lignée)', $o)
            . self::chk('sibs', 'Frères et sœurs sur les fiches (liste des autres enfants de la famille d’origine, avec lien vers leur fiche si elle existe)', $o)
            . self::chk('marrage', 'Âge des époux au mariage (« il avait 27 ans et elle 24 ans », si les dates de naissance sont connues)', $o)
            . self::chk('resi', 'Lieux de résidence (« A résidé à Comines (Belgique), Nieppe (France) », du plus ancien au plus récent, d’après les événements Résidence de TNG)', $o)
            . self::chk('mpf', 'Mention « Mort pour la France » (cause de décès) avec la guerre ou la bataille tirée des événements militaires', $o)
            . self::chk('military', 'Informations militaires / événements de guerre (dates et notes conservées telles quelles)', $o)
            . self::chk('sources', 'Sources', $o)
            . self::chk('index', 'Index des noms et des lieux', $o)
            . self::chk('mini', 'Mini-arbre d’ascendance (parents et grands-parents) en tête de la fiche de chaque ancêtre', $o)
            . self::chk('trees', 'Arbres visuels : un arbre (Nom (code)) en ouverture de l’ascendance et de la descendance, et des arbres classiques en cases (génération en évidence) à chaque génération', $o)
            . self::chk('asclist', 'Liste des ascendants (tableau récapitulatif : n° Sosa, naissance, conjoint, décès, âge), placée avant la liste des descendants', $o)
            . self::chk('desclist', 'Liste des descendants (tableau récapitulatif : n° d’Aboville, naissance, conjoint, décès, âge) à la toute fin du document', $o)
            . self::chk('stats', 'Chapitre « En chiffres » (statistiques, histogrammes, complétude de l’ascendance, frise) et chiffres clés sur la page de titre', $o)
            . self::chk('stats_first', '— dans « En chiffres » : prénoms les plus fréquents', $o)
            . self::chk('stats_last', '— dans « En chiffres » : noms de famille les plus fréquents', $o)
            . self::chk('map', 'Carte des lieux de vie en noir et blanc (nombre de personnes par département ou province ; fond de carte précis si des fichiers GeoJSON sont déposés dans le dossier data du module, voir le guide)', $o)
            . ($admin ? '<label><input type="checkbox" name="' . esc_attr(self::OPT) . '[debug]" value="1"> Mode diagnostic (en haut du livre : pourquoi une photo n’est pas trouvée ; à la fin du chapitre « Carte des lieux de vie » : chaque lieu pris en compte, son origine et la raison de son classement)</label>' : '')
            . '</td></tr>';
        if ($admin) {
            $ord = self::clean_order($o['order'] ?? null);
            $lab = self::partLabels();
            echo '<tr><th>Ordre des parties du livre</th><td><ul id="tngbook-order" style="margin:0;max-width:340px">';
            foreach ($ord as $k) {
                echo '<li style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #c3c4c7;padding:4px 8px;margin:0 0 3px"><input type="hidden" name="' . esc_attr(self::OPT) . '[order][]" value="' . esc_attr($k) . '"><span style="flex:1">' . esc_html($lab[$k]) . '</span>'
                    . '<button type="button" class="button button-small" data-d="-1" title="Monter">↑</button><button type="button" class="button button-small" data-d="1" title="Descendre">↓</button></li>';
            }
            echo '</ul><p class="description">Les parties désactivées plus haut (statistiques, carte, index, listes…) sont simplement ignorées. Les index sont toujours complets, quelle que soit leur place.</p>'
                . '<script>(function(){var u=document.getElementById("tngbook-order");if(!u)return;u.addEventListener("click",function(e){var b=e.target.closest("button[data-d]");if(!b)return;var li=b.closest("li"),d=+b.getAttribute("data-d");if(d<0&&li.previousElementSibling)u.insertBefore(li,li.previousElementSibling);else if(d>0&&li.nextElementSibling)u.insertBefore(li.nextElementSibling,li);});})();</script></td></tr>';
        }
        $cl = isset($o['colors']) && is_array($o['colors']) ? $o['colors'] : TNGBook_Renderer::defaultColors();
        $cl = array_merge(TNGBook_Renderer::defaultColors(), $cl);
        $lab = ['title' => 'Titres des parties', 'head' => 'Générations, codes et liens', 'name' => 'Noms des personnes', 'text' => 'Texte courant', 'meta' => 'Années, sources, légendes', 'tree' => 'Arbres : traits et cases', 'treehl' => 'Arbres : génération en évidence'];
        echo '<tr><th>Couleurs des textes</th><td>';
        foreach ($lab as $k => $l) {
            echo '<label style="display:inline-block;margin:0 16px 6px 0"><input type="color" name="' . esc_attr(self::OPT . '[colors][' . $k . ']') . '" value="' . esc_attr($cl[$k]) . '" style="vertical-align:middle"> ' . esc_html($l) . '</label>';
        }
        $pal = [
            'Bordeaux classique' => ['#222222', '#222222', '#7a1f1f', '#222222', '#555555', '#8a6a6a', '#7a2e2e'],
            'Bleu marine' => ['#1f2430', '#14284b', '#1d4e89', '#14284b', '#5b6678', '#6f8db3', '#1d4e89'],
            'Forêt' => ['#1f2a24', '#1e3a2b', '#2f6b4f', '#1e3a2b', '#5a6b60', '#7fa38f', '#2f6b4f'],
            'Terre de Sienne' => ['#2b221b', '#4a2c17', '#9a4a1f', '#4a2c17', '#6b5b4e', '#b08a6a', '#9a4a1f'],
            'Ardoise et cuivre' => ['#23272b', '#2f3a44', '#b0602a', '#2f3a44', '#5f6a73', '#8794a0', '#b0602a'],
            'Prune' => ['#261f26', '#4a1d4a', '#7b2d7b', '#4a1d4a', '#6b5c6b', '#a98aa9', '#7b2d7b'],
            'Bleu canard' => ['#1d2a2c', '#0f4c5c', '#0f6b7a', '#0f4c5c', '#566b6e', '#6fa3ab', '#b5481b'],
            'Vieux papier' => ['#3b2f25', '#5c4033', '#8c5a3c', '#3b2f25', '#7a6a5a', '#a89078', '#8c5a3c'],
            'Sépia & Tradition Chaleureuse' => ['#222222', '#2c221e', '#8c4a32', '#3d2e28', '#6b605a', '#9e938b', '#c27e58'],
            'Forêt Ancienne' => ['#1a1a1a', '#1b2e23', '#2e5a44', '#16231c', '#54625a', '#85948c', '#5b8c73'],
            'Bleu Nuit & Or' => ['#1c1c1c', '#101820', '#1e3d59', '#0d1b2a', '#5c6b73', '#7b9ea8', '#b08d57'],
            'Héritage & parchemin' => ['#333333', '#4b2e1f', '#8a6a44', '#2f1b12', '#786c5a', '#bbae9a', '#a76a3a'],
            'Ardoise & vieux rose' => ['#2e2e2e', '#2f4858', '#51728c', '#1f2933', '#6e7b87', '#bac6cf', '#a04b65'],
            'Vert sauge & cuivre' => ['#2d2d2d', '#33463a', '#58715f', '#223028', '#6d776f', '#b7c1b9', '#b07a44'],
            'Sobre (noir et gris)' => ['#000000', '#000000', '#333333', '#000000', '#555555', '#777777', '#000000'],
        ];
        $ord = ['text', 'title', 'head', 'name', 'meta', 'tree', 'treehl'];
        echo '<br><span style="display:block;margin:6px 0 4px"><strong>' . esc_html(tngs_t('Palettes harmonisées :')) . '</strong></span><span id="tngbook-pal">';
        foreach ($pal as $nm => $v) {
            echo '<button type="button" class="button" data-pal="' . esc_attr(wp_json_encode(array_combine($ord, $v))) . '" style="margin:0 6px 6px 0"><span style="display:inline-flex;vertical-align:middle;margin-right:6px">';
            foreach ([2, 3, 5, 6] as $i) echo '<i style="display:inline-block;width:12px;height:12px;background:' . esc_attr($v[$i]) . ';border:1px solid #0003"></i>';
            echo '</span>' . esc_html(tngs_t($nm)) . '</button>';
        }
        echo '</span><script>(function(){var b=document.getElementById("tngbook-pal");if(!b)return;b.addEventListener("click",function(e){var t=e.target.closest("button[data-pal]");if(!t)return;var p=JSON.parse(t.getAttribute("data-pal"));for(var k in p){var i=document.querySelector("input[type=color][name$=\"[colors]["+k+"]\"]");if(i)i.value=p[k];}var r=document.querySelector("input[name$=\"[colors_reset]\"]");if(r)r.checked=false;});})();</script>';
        echo '<br><label><input type="checkbox" name="' . esc_attr(self::OPT) . '[colors_reset]" value="1"> Revenir aux couleurs par défaut</label>'
            . '<p class="description">S’appliquent à la page imprimable, au PDF, au fichier Word (.docx) et au fichier OpenDocument (.odt).</p></td></tr>';
        self::field('Titre', 'title', $o, 'text', 'Vide = titre automatique dans la langue choisie.');
        self::field('Auteur', 'author', $o);
        echo '<tr><th scope="row"><label for="tngbook_preface">Préface</label></th><td><textarea id="tngbook_preface" name="' . esc_attr(self::OPT) . '[preface]" rows="4" class="large-text">' . esc_textarea($o['preface']) . '</textarea></td></tr>';
        if ($admin) {
            echo '<tr><th scope="row"><label for="tngbook_surname_title">Page sur le patronyme</label></th><td>';
            echo '<p><input id="tngbook_surname_title" type="text" class="regular-text" name="' . esc_attr(self::OPT) . '[surname_title]" value="' . esc_attr($o['surname_title'] ?? '') . '" placeholder="Titre de la page (vide = « Le patronyme NOM »)"></p>';
            wp_editor((string) ($o['surname'] ?? ''), 'tngbook_surname', [
                'textarea_name' => self::OPT . '[surname]', 'textarea_rows' => 12, 'media_buttons' => true, 'drag_drop_upload' => true,
                'teeny' => false, 'quicktags' => true,
                'tinymce' => ['paste_data_images' => true, 'paste_as_text' => false, 'paste_webkit_styles' => 'font-weight font-style text-decoration text-align color background-color', 'paste_remove_styles_if_webkit' => false, 'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,alignleft,aligncenter,alignright,removeformat,undo,redo', 'toolbar2' => '', 'block_formats' => 'Paragraphe=p;Titre 1=h2;Titre 2=h3;Titre 3=h4'],
            ]);
            echo '<p class="description">Texte de présentation du patronyme (origine, signification, répartition…) : mise en forme, listes, tableaux (mode « Texte ») et images (bouton Ajouter un média). Vide = pas de page. La page fait partie du livre : elle se place dans « Ordre des parties » et s’enregistre dans le profil du livre. Les images (JPEG, PNG, GIF) sont intégrées au PDF, à Word et à OpenDocument.</p></td></tr>';
        }
        self::select('Modèle de mise en page', 'template', [
            'classic' => 'Classique (modèle d’origine) : une colonne, texte justifié, photo à droite',
            'recit' => 'Récit en 2 colonnes : bandeaux de génération, âge des parents, « autres informations », couples et enfants en puces',
            'compact' => 'Compact : 2 colonnes, petit corps, pour limiter le nombre de pages',
            'elegant' => 'Élégant : titres centrés et filets, photo à gauche, grande marge de lecture',
            'modern' => 'Moderne : police sans empattement, bandeaux pleins, fiches en cartes',
        ], $o['template'], 'Le modèle s’applique à la page imprimable, au PDF, au Word et à l’OpenDocument ; les couleurs ci-dessus restent réglables. Les colonnes sont natives dans Word et LibreOffice.');
        $fmt = ['html' => 'Page imprimable (Imprimer → Enregistrer en PDF)'];
        if (self::mpdf_available()) $fmt['pdf'] = 'PDF (téléchargement direct)';
        if (TNGBook_Doc::available()) { $fmt['docx'] = 'Word (.docx, téléchargement)'; $fmt['odt'] = 'OpenDocument (.odt, téléchargement)'; }
        self::select('Format', 'format', $fmt, $o['format']);
    }

    /* ---------- Page d'administration (un seul formulaire) ---------- */

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $o = self::opts();
        $db = self::db();
        if (is_wp_error($db)) {
            $status = array(false, $db->get_error_message());
        } else {
            $cnt    = $db->get_var('SELECT COUNT(*) FROM `' . esc_sql($o['prefix']) . 'people`');
            $status = $cnt === null
                ? array(false, tngs_t('Table') . ' ' . $o['prefix'] . 'people ' . tngs_t('introuvable (préfixe ou base à vérifier).'))
                : array(true, tngs_t('Connexion TNG OK :') . ' ' . (int) $cnt . ' ' . tngs_t('personnes.'));
        }
        tngs_admin_header('Livre généalogique', array('module' => 'book', 'status' => $status));

        self::profile_panel();
        echo '<form method="post" action="options.php">';
        settings_fields('tngbook_group');
        wp_nonce_field('tngbook_generate', 'tngbook_nonce');

        echo '<h2>Livre à générer</h2><table class="form-table">';
        self::gen_fields($o, true);
        echo '</table>';

        echo '<h2>Photos</h2><p class="description">La connexion à la base, le préfixe des tables et l\'URL de TNG se règlent dans <strong>TNG Suite &gt; Général</strong>.</p><table class="form-table">';

        $res = self::resolve_tng_path($o['tng_path']);
        $dr = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '?';
        if ($o['tng_path'] === '' && tngs_site_url() === '') {
            $st = '';
        } elseif ($res !== '') {
            $has = is_dir($res . '/' . trim($o['photo_folder'], '/\\'));
            $st = '<br><strong style="color:#1a7f37">' . esc_html(tngs_t('Résolu :')) . ' ' . esc_html($res) . ' ' . ($has ? esc_html(tngs_t('(dossier photos trouvé)')) : '— ' . esc_html(tngs_t('mais le dossier')) . ' « ' . esc_html($o['photo_folder']) . ' » ' . esc_html(tngs_t('est introuvable'))) . '</strong>';
        } else {
            $st = '<br><strong style="color:#b32d2e">' . esc_html(tngs_t('Introuvable par PHP. Racine web :')) . ' ' . esc_html($dr) . ' — WordPress : ' . esc_html(ABSPATH) . '</strong>';
        }
        self::field('Dossier de TNG sur le disque', 'tng_path', $o, 'text', 'Facultatif. Vide = déduit de l’<strong>URL de TNG</strong> (TNG Suite > Général). Vous pouvez aussi indiquer un chemin absolu (<code>/home/…/tng</code>). Si le dossier est introuvable, des silhouettes génériques sont utilisées.' . $st);
        self::field('Dossier des photos', 'photo_folder', $o, 'text', 'Relatif au dossier de TNG (<code>photos</code> par défaut).');
        echo '</table>';

        echo '<h2>Formulaire public <code>[tng_book]</code></h2><table class="form-table">';
        self::select('Accès', 'front_access', ['none' => 'Désactivé', 'logged_in' => 'Utilisateurs WordPress connectés'], $o['front_access']);
        self::select('Vivants/privés pour le formulaire public', 'front_living', ['anonymize' => 'Anonymiser', 'exclude' => 'Exclure'], $o['front_living'], 'Les visiteurs ne peuvent jamais inclure les personnes vivantes en entier.');
        echo '</table>';

        echo '<p class="submit"><button type="submit" class="button button-secondary">Enregistrer les paramètres</button> '
            . '<span style="margin-left:12px"><input type="text" name="tngbook_profile_name" maxlength="60" value="' . esc_attr(self::active_name()) . '" placeholder="' . esc_attr(tngs_t('Nom du profil')) . '"> '
            . '<button type="submit" class="button" name="action" value="tngbook_profile_save" formaction="' . esc_url(admin_url('admin-post.php')) . '">' . esc_html(tngs_t('Enregistrer comme profil')) . '</button>'
            . (self::active_name() !== '' ? ' <button type="submit" class="button button-primary" name="action" value="tngbook_profile_save" formaction="' . esc_url(admin_url('admin-post.php')) . '" onclick="this.form.insertAdjacentHTML(\'beforeend\',\'<input type=hidden name=tngbook_profile_update value=1>\')">' . esc_html(tngs_t('Mettre à jour le profil')) . '</button>' : '')
            . '</span> '
            . '<button type="submit" class="button button-primary" name="action" value="tngbook_generate" formaction="' . esc_url(admin_url('admin-post.php')) . '" formtarget="_blank">Enregistrer et générer le livre</button></p>';
        echo '</form>';

        echo '<p><strong>PDF direct :</strong> ' . (self::mpdf_available()
            ? 'disponible (mPDF détecté).'
            : 'non disponible. Le livre s’ouvre en page imprimable (Imprimer → Enregistrer en PDF). Pour un PDF direct, installez mPDF dans le dossier du plugin : <code>composer require mpdf/mpdf</code>.')
            . '</p></div>';
    }

    /* ---------- Formulaire public ---------- */

    public static function shortcode() {
        $o = self::opts();
        if ($o['front_access'] !== 'logged_in') return '';
        if (!is_user_logged_in()) return '<p>Connectez-vous pour générer un livre généalogique.</p>';
        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank" class="tngbook-form">';
        echo '<input type="hidden" name="action" value="tngbook_generate">';
        wp_nonce_field('tngbook_generate', 'tngbook_nonce');
        echo '<table class="form-table">';
        self::gen_fields($o, false);
        echo '</table><p><button type="submit" class="button button-primary">Générer le livre</button></p></form>';
        return ob_get_clean();
    }

    /* ---------- Génération ---------- */

    public static function generate() {
        $stored = self::opts();
        $admin = current_user_can('manage_options');
        if (!isset($_POST['tngbook_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tngbook_nonce'])), 'tngbook_generate')) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
        if (!$admin && !($stored['front_access'] === 'logged_in' && is_user_logged_in())) wp_die(esc_html(tngs_t('Accès refusé.')), 403);

        $in = (isset($_POST[self::OPT]) && is_array($_POST[self::OPT])) ? wp_unslash($_POST[self::OPT]) : [];
        if ($admin) {
            $o = self::sanitize($in);                 // tous les paramètres du formulaire sont conservés
            update_option(self::OPT, $o);
            $o['prefix'] = tngs_prefix();
            $debug = !empty($in['debug']);
        } else {
            $o = self::sanitize_gen($in, $stored);   // les visiteurs ne modifient jamais la connexion TNG
            $o['living'] = $stored['front_living'];
            $debug = false;
        }

        $pid = $o['personID'];
        if ($pid === '') wp_die(esc_html(tngs_t('Identifiant de personne manquant.')));
        $lang = $o['lang'];
        $up = (int) $o['gen_up'];
        $down = (int) $o['gen_down'];
        if ($up + $down === 0) wp_die(esc_html(tngs_t('Choisissez au moins une génération d’ascendants ou de descendants.')));
        $format = 'html';
        if ($o['format'] === 'pdf' && self::mpdf_available()) $format = 'pdf';
        elseif (in_array($o['format'], ['docx', 'odt'], true) && TNGBook_Doc::available()) $format = $o['format'];

        @set_time_limit(300);
        wp_raise_memory_limit('admin');

        $db = self::db();
        if (is_wp_error($db)) wp_die(esc_html($db->get_error_message()));

        try {
            $tree = $o['tree'] !== '' ? $o['tree'] : tngs_gedcom();
            if ($tree === '') {
                $found = TNGBook_Data::trees($db, $o['prefix'], $pid);
                if (count($found) === 1) $tree = $found[0];
                elseif (!$found) throw new Exception(tngs_t('Identifiant') . ' "' . $pid . '" ' . tngs_t('introuvable.'));
                else throw new Exception(tngs_t('Cet identifiant existe dans plusieurs arbres') . ' (' . implode(', ', $found) . ') : ' . tngs_t('précisez l’arbre.'));
            }

            $data = new TNGBook_Data($db, $o['prefix'], $tree, $o['living']);
            if ($up > 0) $data->buildAscendants($pid, $up); else $data->loadPeople([$pid]);
            if ($down > 0) $data->buildDescendants($pid, $down);
            $maxP = isset($o['max_people']) ? (int) $o['max_people'] : self::MAX_PEOPLE;
            if ($maxP > 0 && count($data->sosa) + count($data->desc) > $maxP) {
                throw new Exception(tngs_t('Le livre dépasse') . ' ' . $maxP . ' ' . tngs_t('personnes : réduisez le nombre de générations, ou augmentez le nombre maximum de personnes dans les réglages.'));
            }
            if (!isset($data->people[$pid])) throw new Exception(tngs_t('Personne introuvable.'));

            $opts = [
                'root' => $pid, 'up' => $up, 'down' => $down,
                'photos' => (bool) $o['photos'], 'spphotos' => (bool) $o['spphotos'], 'generic' => (bool) $o['generic'],
                'sources' => (bool) $o['sources'], 'index' => (bool) $o['index'],
                'notes' => (bool) $o['notes'], 'military' => (bool) $o['military'], 'mpf' => !empty($o['mpf']), 'mini' => !empty($o['mini']), 'trees' => !empty($o['trees']), 'stats' => !empty($o['stats']), 'stats_first' => !empty($o['stats_first']), 'stats_last' => !empty($o['stats_last']), 'desclist' => !empty($o['desclist']), 'map' => !empty($o['map']), 'bapt' => !empty($o['bapt']), 'burial' => !empty($o['burial']), 'verbose' => !empty($o['verbose']), 'country' => !empty($o['country']), 'regions' => !empty($o['regions']), 'asclist' => !empty($o['asclist']), 'resi' => !empty($o['resi']), 'sibs' => !empty($o['sibs']), 'cous' => !empty($o['cous']), 'allunions' => !empty($o['allunions']), 'marrage' => !empty($o['marrage']),
                'debug' => $debug, 'place_parts' => (int) $o['place_parts'],
                'title' => $o['title'], 'author' => $o['author'], 'preface' => $o['preface'], 'surname' => (string) ($o['surname'] ?? ''), 'surname_title' => (string) ($o['surname_title'] ?? ''), 'colors' => $o['colors'] ?? [],
                'template' => $o['template'] ?? 'classic', 'order' => self::clean_order($o['order'] ?? null),
                'tng_path' => $o['tng_path'], 'photo_folder' => $o['photo_folder'],
            ];
            $narr = !empty(TNGBook_Renderer::templates()[$opts['template']]['narr']);
            $data->finalize($opts['photos'], $opts['sources'], $opts['notes'], $opts['military'], $narr, !empty($opts['sibs']), !empty($opts['allunions']), !empty($opts['mpf']));
            if ($opts['stats']) $data->loadStatsData();
            if (!empty($opts['resi'])) $data->loadResidences($data->featured());
            if (!empty($opts['map'])) { $fp = $data->featured(); $data->loadMapData($fp, TNGBook_Map::allPlaces($data, $fp)); }
            $isDoc = in_array($format, ['docx', 'odt'], true);
            $rend = new TNGBook_Renderer($data, $lang, $opts, $isDoc ? 'doc' : $format);
            $html = $rend->render();
            if ($isDoc) {
                $file = TNGBook_Doc::convert($html, array_merge(TNGBook_Renderer::defaultColors(), (array) $opts['colors']), [
                    'title' => $opts['title'] !== '' ? $opts['title'] : 'Livre généalogique', 'author' => $opts['author'], 'lang' => $lang,
                    'tpl' => $rend->docSpec(),
                ], $format);
            }
        } catch (Exception $e) {
            wp_die(esc_html($e->getMessage()), esc_html(tngs_t('Erreur')), ['back_link' => true]);
        }

        nocache_headers();
        if (in_array($format, ['docx', 'odt'], true)) {
            header('Content-Type: ' . ($format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.oasis.opendocument.text'));
            header('Content-Disposition: attachment; filename="livre-genealogique-' . preg_replace('/[^\w\-.]/', '_', $pid) . '.' . $format . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            @unlink($file);
            exit;
        }
        if ($format === 'pdf') {
            $up_dir = wp_upload_dir();
            $tmp = $up_dir['basedir'] . '/tngbook-cache/mpdf';
            wp_mkdir_p($tmp);
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp, 'default_font' => 'dejavuserif',
                'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 20, 'margin_bottom' => 22,
            ]);
            $mpdf->SetFooter('{PAGENO}');
            $mpdf->WriteHTML($html);
            $mpdf->Output('livre-genealogique-' . $pid . '.pdf', 'D');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}

TNGBook::init();

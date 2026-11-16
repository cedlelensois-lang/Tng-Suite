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
require_once TNGBOOK_DIR . 'includes/class-history.php';
require_once TNGBOOK_DIR . 'includes/class-art.php';
require_once TNGBOOK_DIR . 'includes/trait-stats-view.php';
require_once TNGBOOK_DIR . 'includes/class-renderer.php';
require_once TNGBOOK_DIR . 'includes/class-auto.php';
require_once TNGBOOK_DIR . 'includes/class-bg.php';

final class TNGBook {
    const OPT = 'tngbook_options';
    public static $progress = null;      // rappel (phase, avancement 0..1) appelé pendant la génération en arrière-plan ; peut lever une exception pour l'interrompre
    public static $unlimited = false;    // génération en arrière-plan : pas de limite de temps imposée par build()
    const MAX_PEOPLE = 4000;   // valeur de départ du réglage « nombre maximum de personnes » (0 = illimité)

    /** Signale l'avancement de la génération (sans effet hors génération en arrière-plan). */
    public static function beat($phase, $pct = null, $detail = '') {
        if (self::$progress) call_user_func(self::$progress, $phase, $pct, $detail);
    }

    public static function langs() {
        return ['fr' => 'Français', 'en' => 'English (UK)', 'es' => 'Español', 'ca' => 'Català'];
    }

    /** Parties du livre, dans l'ordre par défaut : clé => libellé. */
    public static function partLabels() {
        return [
            'surn' => 'Page sur le patronyme', 'asc' => 'Ascendance', 'desc' => 'Descendance', 'stats' => 'En chiffres', 'chrono' => 'Chronologie familiale', 'cous' => 'Cousinages et implexes', 'map' => 'Carte des lieux de vie',
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
        foreach ($def as $k) {
            if (in_array($k, $out, true)) continue;
            $at = $k === 'chrono' ? array_search('stats', $out, true) : false;   // la chronologie s'ajoute après « En chiffres » dans les anciens ordres
            if ($at !== false) array_splice($out, $at + 1, 0, [$k]); else $out[] = $k;
        }
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
            'img' => ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true, 'align' => true],
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
            'gen_up' => 6, 'gen_down' => 4, 'max_people' => self::MAX_PEOPLE, 'living' => 'anonymize', 'place_parts' => 1,
            // réglages d'un nouveau livre (2.12.0) : contenu complet, modèle Élégant, PDF, contexte historique
            'photos' => 1, 'spphotos' => 1, 'spfiche' => 1, 'generic' => 1, 'avatar' => 'portrait', 'sources' => 0, 'index' => 1, 'notes' => 1, 'military' => 1, 'mpf' => 1, 'mini' => 1, 'imgq' => 0, 'trees' => 1, 'stats' => 1, 'stats_first' => 1, 'stats_last' => 1, 'desclist' => 1, 'chrono' => 1, 'kids' => 1, 'histfiche' => 1, 'map' => 1, 'bapt' => 1, 'burial' => 1, 'verbose' => 1, 'country' => 1, 'regions' => 1, 'asclist' => 1, 'resi' => 1, 'sibs' => 0, 'marrage' => 1, 'cous' => 1, 'allunions' => 1, 'order' => ['asc', 'desc', 'stats', 'chrono', 'map', 'idxn', 'idxp', 'src', 'alist', 'dlist'],
            'title' => '', 'author' => '', 'preface' => '', 'surname' => '', 'surname_title' => '', 'format' => self::mpdf_available() ? 'pdf' : 'html', 'template' => 'elegant', 'frame' => 'single', 'cover_art' => 'color', 'history' => 1, 'history_lists' => [tngs_lang() === 'fr' ? 'tpl-FR' : 'tpl-GB'], 'history_default' => '', 'history_scope' => 'all',
            'colors' => TNGBook_Renderer::defaultColors(),
        ];
    }

    /** Réglages enregistrés + valeurs par défaut ; « spfiche » (apparu en 2.9.0) reste décoché pour des réglages déjà enregistrés. */
    private static function saved_opts() {
        $saved = get_option(self::OPT, []);
        if (is_array($saved) && $saved && !array_key_exists('spfiche', $saved)) $saved['spfiche'] = 0;
        if (is_array($saved) && $saved && !array_key_exists('history_lists', $saved)) $saved = array_merge($saved, TNGBook_History::legacy_selection($saved));   // avant la 2.10.0 : pays par défaut + événements personnalisés
        return wp_parse_args($saved, self::defaults());
    }

    /** « Nouveau livre » : les réglages par défaut du plugin, en gardant seulement les réglages communs (dossier TNG, accès, langue, vivants/privés). */
    private static function new_book_opts(array $cur) {
        $o = self::defaults();
        foreach (['tng_path', 'photo_folder', 'front_access', 'front_living', 'max_people', 'living', 'lang', 'prefix'] as $k) if (array_key_exists($k, $cur)) $o[$k] = $cur[$k];
        return $o;
    }

    public static function opts() {
        $o = self::saved_opts();
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
        add_action('admin_post_tngbook_settings_save', [__CLASS__, 'settings_save']);
        TNGBook_History::init();
        add_shortcode('tng_book', [__CLASS__, 'shortcode']);
    }

    /* ---------- Profils de livre enregistrés ---------- */

    const PROFILES = 'tngbook_profiles';
    const MAX_PROFILES = 50;
    private static $trusted = false;               // vrai uniquement pendant profile_options() (génération automatique)
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
        TNGBook_Auto::save_profile_job($name);
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
            TNGBook_Auto::forget($name);
            if (get_option(self::ACTIVE, '') === $name) update_option(self::ACTIVE, '', false);
            self::profile_back('deleted');
        }
        $cur = self::opts();
        $p = (array) $all[$name];
        if (!array_key_exists('surname', $p)) { $p['surname'] = ''; $p['surname_title'] = ''; }   // profil d'avant la page patronyme : pas de texte hérité du profil précédent
        $new = self::sanitize_gen($p, $cur);   // repasse par le nettoyage des paramètres
        update_option(self::OPT, $new);
        update_option(self::ACTIVE, $name, false);
        self::profile_back('loaded');
    }

    /** Nom du profil actif (chargé ou enregistré en dernier), s'il existe encore. */
    public static function active_name() {
        $act = (string) get_option(self::ACTIVE, '');
        return ($act !== '' && isset(self::profiles()[$act])) ? $act : '';
    }

    private static function profile_panel($form_shown = true) {
        $msgs = ['saved' => 'Profil enregistré.', 'loaded' => 'Profil chargé : les paramètres ci-dessous sont ceux du profil.', 'deleted' => 'Profil supprimé.',
            'noname' => 'Donnez un nom au profil (les paramètres ont tout de même été enregistrés).', 'missing' => 'Profil introuvable.', 'full' => 'Nombre maximal de profils atteint (' . self::MAX_PROFILES . ').'];
        $m = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';
        if (isset($msgs[$m])) echo '<div class="notice ' . (in_array($m, ['saved', 'loaded', 'deleted'], true) ? 'notice-success' : 'notice-warning') . ' is-dismissible"><p>' . esc_html(tngs_t($msgs[$m])) . '</p></div>';
        $all = self::profiles();
        $act = (string) get_option(self::ACTIVE, '');
        if (!isset($all[$act])) $act = '';
        echo '<h2>' . esc_html(tngs_t('Profils de livre enregistrés')) . '</h2>';
        if ($act !== '' && $form_shown) echo '<p>' . esc_html(tngs_t('Profil actif :')) . ' <strong>' . esc_html($act) . '</strong> — ' . esc_html(tngs_t('modifiez les réglages ci-dessous puis utilisez « Mettre à jour le profil » pour l’enregistrer.')) . '</p>';
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
        if (!$form_shown) echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg(['page' => 'tngbook', 'new' => 1], admin_url('admin.php'))) . '">' . esc_html(tngs_t('Nouveau livre')) . '</a> <span class="description">' . esc_html(tngs_t('ou chargez un profil ci-dessus pour afficher ses réglages.')) . '</span></p>';
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
            $rel = (string) wp_parse_url($in, PHP_URL_PATH);
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

    public static function mpdf_available() {
        $auto = TNGBOOK_DIR . 'vendor/autoload.php';
        if (file_exists($auto)) require_once $auto;
        return class_exists('\Mpdf\Mpdf');
    }

    /* ---------- Réglages : tout est sauvegardé ensemble ---------- */

    public static function menu() {
        tngs_add_subpage('Livre généalogique TNG', 'Livre : générer un livre', 'tngbook', [__CLASS__, 'page']);
        tngs_add_subpage('Livre : réglages', 'Livre : réglages', 'tngbook-settings', [__CLASS__, 'page_settings']);
        tngs_add_subpage('Livre : vue d’ensemble des profils', 'Livre : vue d’ensemble des profils', 'tngbook-overview', [__CLASS__, 'page_overview']);
    }

    public static function register() {
        register_setting('tngbook_group', self::OPT, ['sanitize_callback' => [__CLASS__, 'sanitize']]);
    }

    /**
     * Le formulaire regroupe plusieurs réglages en un seul choix (photos, baptême/sépulture, chronologie, phrases détaillées, lieux,
     * militaire, arbres, listes) : ils sont ici traduits vers les réglages détaillés que le livre utilise (et que conservent les profils).
     * Un tableau sans ces choix (profil enregistré, anciens réglages) est laissé tel quel.
     */
    private static function expand_fields(array $in) {
        if (!isset($in['photos_mode'])) return $in;
        $pm = (string) $in['photos_mode'];
        $in['photos'] = $in['spphotos'] = $pm === 'none' ? 0 : 1;
        $in['generic'] = $pm === 'full' ? 1 : 0;
        $bm = (string) ($in['bapt_mode'] ?? 'burial');
        $in['bapt'] = $bm === 'both' ? 1 : 0;
        $in['burial'] = $bm === 'none' ? 0 : 1;
        $cm = (string) ($in['chrono_mode'] ?? 'none');
        $in['chrono'] = $cm === 'none' ? 0 : 1;
        $in['history'] = $cm === 'history' ? 1 : 0;
        $in['verbose'] = $in['marrage'] = $in['resi'] = empty($in['verbose_all']) ? 0 : 1;
        $in['country'] = $in['regions'] = empty($in['places_tr']) ? 0 : 1;
        $in['military'] = $in['mpf'] = empty($in['military_all']) ? 0 : 1;
        $in['mini'] = $in['trees'] = empty($in['trees_all']) ? 0 : 1;
        $in['asclist'] = $in['desclist'] = empty($in['lists']) ? 0 : 1;
        $in['stats_first'] = $in['stats_last'] = empty($in['stats']) ? 0 : 1;
        return $in;
    }

    /** Paramètres du livre uniquement (utilisé aussi pour le formulaire public). */
    public static function sanitize_gen($in, array $base) {
        $in = self::expand_fields((array) $in);
        $out = $base;
        $out['personID'] = preg_replace('/[^\w\-.]/u', '', (string) ($in['personID'] ?? ''));
        $out['tree'] = sanitize_text_field($in['tree'] ?? '');
        $out['lang'] = isset(self::langs()[$in['lang'] ?? '']) ? $in['lang'] : 'fr';
        $out['gen_up'] = max(0, (int) ($in['gen_up'] ?? 6));
        $out['gen_down'] = max(0, (int) ($in['gen_down'] ?? 4));
        $out['living'] = in_array($in['living'] ?? '', ['exclude', 'anonymize'], true) ? $in['living'] : 'anonymize';
        if (!tngs_hide_living('book')) $out['living'] = 'include';
        $out['place_parts'] = max(0, min(5, (int) ($in['place_parts'] ?? 2)));
        $out['avatar'] = (($in['avatar'] ?? '') === 'round') ? 'round' : 'portrait';
        foreach (['photos', 'spphotos', 'spfiche', 'generic', 'sources', 'index', 'notes', 'military', 'mpf', 'mini', 'imgq', 'trees', 'stats', 'stats_first', 'stats_last', 'desclist', 'kids', 'histfiche', 'chrono', 'history', 'map', 'bapt', 'burial', 'verbose', 'country', 'regions', 'asclist', 'resi', 'sibs', 'marrage', 'cous', 'allunions'] as $k) {
            $out[$k] = empty($in[$k]) ? 0 : 1;
        }
        $out['order'] = self::clean_order($in['order'] ?? null);
        if (!empty($out['history'])) $out['chrono'] = 1;   // le contexte historique s'affiche dans la chronologie : elle est donc générée
        $out['title'] = sanitize_text_field($in['title'] ?? '');
        $out['author'] = sanitize_text_field($in['author'] ?? '');
        $out['preface'] = sanitize_textarea_field($in['preface'] ?? '');
        if (self::$trusted || current_user_can('manage_options')) {   // le texte de la page patronyme est rédigé par l'administrateur (jamais par les visiteurs)
            $out['surname'] = array_key_exists('surname', $in) ? self::surname_kses($in['surname']) : (string) ($base['surname'] ?? '');
            $out['surname_title'] = array_key_exists('surname_title', $in) ? sanitize_text_field($in['surname_title']) : (string) ($base['surname_title'] ?? '');
        }
        $tp = (string) ($in['template'] ?? 'classic');
        $out['template'] = isset(TNGBook_Renderer::templates()[$tp]) ? $tp : 'classic';
        if (array_key_exists('history_lists', $in)) $sel = TNGBook_History::sanitize_selection($in);
        elseif (array_key_exists('history_country', $in) || array_key_exists('history_custom', $in)) $sel = TNGBook_History::legacy_selection($in);   // profil enregistré avant la 2.10.0
        else $sel = ['history_lists' => (array) ($base['history_lists'] ?? []), 'history_default' => (string) ($base['history_default'] ?? ''), 'history_scope' => (string) ($base['history_scope'] ?? 'all')];
        $out['history_lists'] = $sel['history_lists'];
        $out['history_default'] = $sel['history_default'];
        $out['history_scope'] = $sel['history_scope'];
        unset($out['history_country'], $out['history_custom']);
        $fr = (string) ($in['frame'] ?? 'none');
        $out['frame'] = in_array($fr, ['single', 'double', 'ornate', 'floral'], true) ? $fr : 'none';
        $ca = (string) ($in['cover_art'] ?? 'tree');
        if ($ca === 'winter') $ca = 'roots';   // ancien nom (2.8.2)
        $out['cover_art'] = in_array($ca, TNGBook_Art::cover_choices(), true) ? $ca : 'tree';
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
        // Photos et formulaire public se règlent dans l'onglet « Réglages » : absents du formulaire du livre, ils gardent leur valeur.
        foreach (['tng_path', 'photo_folder'] as $k) {
            $out[$k] = array_key_exists($k, $in) ? sanitize_text_field($in[$k]) : (string) ($old[$k] ?? '');
        }
        $out['photo_folder'] = $out['photo_folder'] !== '' ? $out['photo_folder'] : 'photos';
        $out['max_people'] = max(0, (int) ($in['max_people'] ?? ($old['max_people'] ?? self::MAX_PEOPLE)));   // réglage administrateur (jamais modifiable par les visiteurs)
        $out['front_access'] = array_key_exists('front_access', $in) ? (($in['front_access'] ?? '') === 'logged_in' ? 'logged_in' : 'none') : (string) ($old['front_access'] ?? 'none');
        $out['front_living'] = array_key_exists('front_living', $in) ? (($in['front_living'] ?? '') === 'exclude' ? 'exclude' : 'anonymize') : (string) ($old['front_living'] ?? 'anonymize');
        // Anciennes clés de connexion (ex-plugin autonome) : on ne les conserve pas.
        foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'prefix'] as $k) unset($out[$k]);
        return $out;
    }

    /* ---------- Composants de formulaire ---------- */

    private static function field($label, $key, $o, $type = 'text', $desc = '', $attrs = '', $name = null) {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input id="%1$s" name="%3$s" type="%4$s" value="%5$s" class="regular-text" %6$s>%7$s</td></tr>',
            esc_attr('tngbook_' . $key), esc_html($label), esc_attr(($name ?: self::OPT) . '[' . $key . ']'), esc_attr($type),
            esc_attr($type === 'password' ? '' : $o[$key]), $attrs,
            $desc ? '<p class="description">' . wp_kses_post($desc) . '</p>' : ''
        );
    }

    private static function select($label, $key, array $choices, $current, $desc = '', $name = null) {
        $id = 'tngbook_' . $key;
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td><select id="' . esc_attr($id) . '" name="' . esc_attr(($name ?: self::OPT) . '[' . $key . ']') . '">';
        foreach ($choices as $v => $l) {
            echo '<option value="' . esc_attr($v) . '"' . selected((string) $current, (string) $v, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select>' . ($desc ? '<p class="description">' . wp_kses_post($desc) . '</p>' : '') . '</td></tr>';
    }

    /** Liste déroulante dans une cellule de formulaire (plusieurs réglages regroupés dans une même ligne). */
    private static function isel($key, $label, array $choices, $current) {
        $h = '<p style="margin:0 0 6px"><label><strong>' . esc_html($label) . '</strong> : <select name="' . esc_attr(self::OPT . '[' . $key . ']') . '">';
        foreach ($choices as $val => $l) $h .= '<option value="' . esc_attr($val) . '"' . selected((string) $current, (string) $val, false) . '>' . esc_html($l) . '</option>';
        return $h . '</select></label></p>';
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
        self::select('Style des silhouettes génériques', 'avatar', ['portrait' => 'Portrait (cercle sur fond clair)', 'round' => 'Rond (sans fond)'], $o['avatar'] ?? 'portrait', 'Utilisé quand « Silhouettes génériques » est coché, pour les personnes sans photo.');
        $v = function ($k) use ($o) { return !empty($o[$k]); };
        $pm = !$v('photos') ? 'none' : ($v('generic') ? 'full' : 'photos');
        $bm = $v('bapt') ? 'both' : ($v('burial') ? 'burial' : 'none');
        $cm = $v('history') ? 'history' : ($v('chrono') ? 'chrono' : 'none');
        echo '<tr><th>Contenu des fiches</th><td>'
            . self::isel('photos_mode', 'Photos', ['none' => 'Aucune', 'photos' => 'Photos TNG (statut « Photo par défaut »), avec photo du conjoint', 'full' => 'Photos TNG, et silhouettes génériques (homme / femme) à défaut de photo'], $pm)
            . self::isel('bapt_mode', 'Baptême et sépulture', ['none' => 'Aucun', 'burial' => 'Sépulture seule (inhumation ou incinération : date et lieu)', 'both' => 'Baptême et sépulture (dates et lieux renseignés dans TNG)'], $bm)
            . self::chk('verbose_all', 'Phrases détaillées (« Il est né le … à …, fils de … ; il est décédé le …, à l’âge de … ans ») avec l’âge des époux au mariage et les lieux de résidence', ['verbose_all' => $v('verbose')])
            . self::chk('places_tr', 'Lieux : pays entre parenthèses après le lieu (« Lyon, Rhône (France) ») et départements, provinces et États traduits dans la langue du livre', ['places_tr' => $v('country') || $v('regions')])
            . self::chk('military_all', 'Informations militaires : événements de guerre et mention « Mort pour la France »', ['military_all' => $v('military') || $v('mpf')])
            . self::chk('notes', 'Notes (générales de chaque personne et de chaque union, et notes des événements : naissance, mariage, décès…)', $o)
            . self::chk('sibs', 'Frères et sœurs sur les fiches (liste des autres enfants de la famille d’origine, avec lien vers leur fiche si elle existe)', $o)
            . self::chk('allunions', 'Autres mariages et unions des ancêtres (en plus de l’union dont descend la lignée)', $o)
            . self::chk('spfiche', 'Dans la descendance, une fiche détaillée pour chaque conjoint (numéro du couple suivi de « c », par exemple D12c), à la suite de celle du descendant ; la phrase d’union se limite alors à son nom, avec un lien. Dans l’ascendance : vignette de la photo du conjoint et lien vers sa fiche', $o)
            . self::chk('kids', 'Enfants des couples dans l’ascendance : « X et Y ont N enfants : » suivi de la liste (modèles Classique, Élégant et Moderne ; le modèle Récit les présente déjà)', $o)
            . self::chk('histfiche', 'Contexte historique dans les fiches : une ligne « Contexte historique : la Première Guerre mondiale et la Seconde Guerre mondiale » qui énumère, d’après les listes cochées, les périodes (guerres, régimes…) où tombe une date de la fiche ou que la personne a traversées de son vivant', $o)
            . '</td></tr>';
        echo '<tr><th>Parties du livre</th><td>'
            . self::isel('chrono_mode', 'Chronologie', ['none' => 'Aucune', 'chrono' => 'Chronologie familiale (naissances, mariages et décès par date et par décennie)', 'history' => 'Chronologie familiale avec contexte historique (guerres, révolutions… du pays de naissance)'], $cm)
            . self::chk('stats', 'Chapitre « En chiffres » (statistiques, prénoms et noms les plus fréquents, histogrammes, complétude de l’ascendance, frise) et chiffres clés sur la page de titre', $o)
            . self::chk('trees_all', 'Arbres : mini-arbre d’ascendance en tête de chaque fiche d’ancêtre, arbres visuels en ouverture et à chaque génération', ['trees_all' => $v('mini') || $v('trees')])
            . self::chk('lists', 'Listes récapitulatives des ascendants et des descendants (tableaux : naissance, conjoint, décès, âge)', ['lists' => $v('asclist') || $v('desclist')])
            . self::chk('cous', 'Cousinages et implexes (partie ajoutée seulement si un même ancêtre apparaît plusieurs fois : liste des implexes et des unions entre apparentés)', $o)
            . self::chk('sources', 'Sources', $o)
            . self::chk('index', 'Index des noms et des lieux', $o)
            . self::chk('map', 'Carte des lieux de vie en noir et blanc (nombre de personnes par département ou province ; fond de carte précis si des fichiers GeoJSON sont déposés dans le dossier data du module, voir le guide)', $o)
            . '</td></tr>';
        if ($admin) {
            echo '<tr><th>Options avancées</th><td><details><summary>Afficher</summary><label><input type="checkbox" name="' . esc_attr(self::OPT) . '[debug]" value="1"> Mode diagnostic (en haut du livre : pourquoi une photo n’est pas trouvée ; à la fin du chapitre « Carte des lieux de vie » : chaque lieu pris en compte, son origine et la raison de son classement)</label></details></td></tr>';
        }
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
            'Chêne et prairie' => ['#252a1f', '#2d4a22', '#4f7a2f', '#2d4a22', '#62695a', '#8fae6b', '#6b8e3a'],
            'Écorce et ciel d’été' => ['#2b241d', '#4a3524', '#6e4b2d', '#4a3524', '#6b6258', '#7fa3c9', '#2f6aa3'],
            'Ciel de campagne' => ['#1f2733', '#2a4a73', '#3b6ea5', '#2a4a73', '#5d6b7d', '#8fb2d6', '#c97a3c'],
            'Terre et racines' => ['#2c231b', '#5a3c22', '#8a5a32', '#5a3c22', '#6f6354', '#c0a07a', '#8a5a32'],
            'Ferme en pierre et tuiles' => ['#2d2822', '#6b3b25', '#b0562e', '#6b3b25', '#756a5c', '#c9b08a', '#b0562e'],
            'Peinture impressionniste' => ['#2a2a30', '#3d4f7a', '#5a73a8', '#3d4f7a', '#6a6e78', '#a9b88a', '#c0603b'],
            'Coquelicot et feuillage' => ['#232620', '#3c5a2a', '#a8402a', '#3c5a2a', '#666a5c', '#98b070', '#a8402a'],
            'Prairie fleurie' => ['#232a25', '#3a5a43', '#4f7a5a', '#3a5a43', '#65705f', '#a7bf9a', '#d17a2a'],
            'Pastel de la campagne' => ['#2b2a30', '#4d4a6e', '#7a6aa6', '#4d4a6e', '#6c6a78', '#9cc5c0', '#c98a7a'],
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
        self::select('Cadre autour des pages', 'frame', [
            'none' => 'Aucun',
            'single' => 'Filet simple',
            'double' => 'Double filet',
            'ornate' => 'Cadre orné (angles creusés, PDF et page imprimable ; double filet dans Word et OpenDocument)',
            'floral' => 'Cadre fleuri, style Monet (coquelicots aux quatre coins, PDF et page imprimable ; double filet dans Word et OpenDocument)',
        ], $o['frame'] ?? 'none', 'Un liseré autour de chaque page, pour une impression en livre : couleur du livre (« en-têtes et liens »), pas de cadre sur la couverture.');
        self::select('Illustration de couverture', 'cover_art', [
            'none' => 'Aucune (deux filets autour du titre)',
            'ornament' => 'Fleurons au-dessus et au-dessous du titre',
            'nympheas' => 'Nymphéas et coquelicots au-dessus et au-dessous du titre (style Monet)',
            'tree' => 'Arbre : chêne ample, puis titre et fleuron',
            'olive' => 'Arbre : penché, aux branches légères',
            'willow' => 'Arbre : saule pleureur',
            'roots' => 'Arbre : feuillu, ses racines dessinées dessous',
            'color' => 'Arbre en couleurs : feuillage vert, tronc brun, racines dans la terre et médaillons',
            'page' => 'Pochette pleine page (PDF) : arbre à l’aquarelle sur fond crème, titre dessous',
            'meadow' => 'Pochette pleine page (PDF) : arbre aux étiquettes vierges et oiseaux, prairie fleurie',
            'farm' => 'Pochette pleine page (PDF) : grand tronc, feuillage pastel et campagne',
            'chene' => 'Pochette pleine page (PDF) : chêne et ses racines, campagne, texte sur fond blanc',
            'chene2' => 'Pochette pleine page (PDF) : chêne ensoleillé, prairie, texte sur fond blanc',
            'chene3' => 'Pochette pleine page (PDF) : chêne vert, ciel bleu et ferme, texte sur fond blanc',
            'chene4' => 'Pochette pleine page (PDF) : chêne aux couleurs éclatantes, texte sur fond blanc',
            'peinture' => 'Pochette pleine page (PDF) : peinture impressionniste, chêne et coquelicots, texte sur fond blanc',
        ], $o['cover_art'] ?? 'tree', 'Les arbres sont dessinés par le plugin dans la couleur du livre (« en-têtes et liens »). Les pochettes illustrées (oiseaux, campagne, chênes, peinture) sont des illustrations fournies, qui gardent leurs couleurs.');
        echo TNGBook_History::form_row(self::OPT, $o, $admin);
        $fmt = ['html' => 'Page imprimable (Imprimer → Enregistrer en PDF)'];
        if (self::mpdf_available()) $fmt['pdf'] = 'PDF (téléchargement direct)';
        if (TNGBook_Doc::available()) { $fmt['docx'] = 'Word (.docx, téléchargement)'; $fmt['odt'] = 'OpenDocument (.odt, téléchargement)'; }
        self::select('Format', 'format', $fmt, $o['format']);
        echo '<tr><th>Poids du fichier</th><td>' . self::chk('imgq', 'Compresser les images (PDF direct et Word / OpenDocument : photos réduites, images du texte à 150 ppp) : fichier plus léger, qualité un peu moindre', $o) . '</td></tr>';
    }

    /* ---------- Page d'administration (un seul formulaire) ---------- */

    /** Onglet « Générer un livre ». Avec des profils enregistrés, la page n'affiche d'abord que le choix du profil (ou « Nouveau livre »). */
    public static function page() {
        if (!current_user_can('manage_options')) return;
        $o = self::opts();
        if (isset($_GET['new'])) $o = self::new_book_opts($o);   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        tngs_admin_header('Livre généalogique', array('module' => 'book'));
        TNGBook_Bg::panel();

        $m = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $new = isset($_GET['new']);                                                              // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $show = !self::profiles() || $new || isset($_GET['settings-updated']) || in_array($m, ['loaded', 'saved', 'noname', 'full', 'bg_queued', 'bg_busy', 'bg_noid', 'bg_nogen'], true);   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $act = $new ? '' : self::active_name();
        self::profile_panel($show);
        if (!$show) {
            echo '</div>';
            return;
        }
        echo '<form method="post" action="options.php">';
        settings_fields('tngbook_group');
        wp_nonce_field('tngbook_generate', 'tngbook_nonce');

        echo '<h2>Livre à générer</h2><table class="form-table">';
        self::gen_fields($o, true);
        echo '</table>';

        TNGBook_Auto::profile_fields($act);

        echo '<p class="submit"><button type="submit" class="button button-secondary">Enregistrer les paramètres</button> '
            . '<span style="margin-left:12px"><input type="text" name="tngbook_profile_name" maxlength="60" value="' . esc_attr($act) . '" placeholder="' . esc_attr(tngs_t('Nom du profil')) . '"> '
            . '<button type="submit" class="button" name="action" value="tngbook_profile_save" formaction="' . esc_url(admin_url('admin-post.php')) . '">' . esc_html(tngs_t('Enregistrer comme profil')) . '</button>'
            . ($act !== '' ? ' <button type="submit" class="button button-primary" name="action" value="tngbook_profile_save" formaction="' . esc_url(admin_url('admin-post.php')) . '" onclick="this.form.insertAdjacentHTML(\'beforeend\',\'<input type=hidden name=tngbook_profile_update value=1>\')">' . esc_html(tngs_t('Mettre à jour le profil actif')) . '</button>' : '')
            . '</span> '
            . '<button type="submit" class="button button-primary" name="action" value="tngbook_generate" formaction="' . esc_url(admin_url('admin-post.php')) . '" formtarget="_blank">Enregistrer et générer le livre</button> '
            . '<button type="submit" class="button" name="action" value="tngbook_bg_start" formaction="' . esc_url(admin_url('admin-post.php')) . '">' . esc_html(tngs_t('Générer en arrière-plan')) . '</button></p>'
            . '<p class="description" style="max-width:900px">' . esc_html(tngs_t('Pour un gros livre : « Générer en arrière-plan » prépare le livre sur le serveur sans faire attendre cette page (utile quand l’hébergeur coupe les traitements trop longs). Vous suivez l’avancement ici et téléchargez le fichier quand il est prêt.')) . '</p>';
        echo '</form></div>';
    }

    /** Onglet « Réglages » : photos, formulaire public, PDF direct et réglages communs de la génération automatique. */
    public static function page_settings() {
        if (!current_user_can('manage_options')) return;
        $o = self::opts();
        tngs_admin_header('Livre : réglages', array('module' => 'book'));
        $m = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($m === 'cfg_saved') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(tngs_t('Réglages enregistrés.')) . '</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tngbook_settings_save">';
        wp_nonce_field('tngbook_settings', 'tngbook_snonce');
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
        self::field('Dossier de TNG sur le disque', 'tng_path', $o, 'text', 'Facultatif. Vide = déduit de l’<strong>URL de TNG</strong> (TNG Suite > Général). Vous pouvez aussi indiquer un chemin absolu (<code>/home/…/tng</code>). Si le dossier est introuvable, des silhouettes génériques sont utilisées.' . $st, '', 'tngbook_cfg');
        self::field('Dossier des photos', 'photo_folder', $o, 'text', 'Relatif au dossier de TNG (<code>photos</code> par défaut).', '', 'tngbook_cfg');
        echo '</table>';

        echo '<h2>Formulaire public <code>[tng_book]</code></h2><table class="form-table">';
        self::select('Accès', 'front_access', ['none' => 'Désactivé', 'logged_in' => 'Utilisateurs WordPress connectés'], $o['front_access'], '', 'tngbook_cfg');
        self::select('Vivants/privés pour le formulaire public', 'front_living', ['anonymize' => 'Anonymiser', 'exclude' => 'Exclure'], $o['front_living'], 'Les visiteurs ne peuvent jamais inclure les personnes vivantes en entier.', 'tngbook_cfg');
        echo '</table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(tngs_t('Enregistrer les réglages')) . '</button></p></form>';

        if (self::mpdf_available()) {
            $pdf = tngs_t('disponible (mPDF détecté).');
            if (!extension_loaded('mbstring')) $pdf .= ' ' . tngs_t('Attention : l’extension PHP mbstring est absente, le PDF risque d’échouer.');
        } elseif (file_exists(TNGBOOK_DIR . 'vendor/autoload.php')) {
            $pdf = tngs_t('mPDF est présent mais ne se charge pas (version de PHP trop ancienne ou extension manquante) : voir le journal d’erreurs PHP.');
        } else {
            $pdf = tngs_t('non disponible : ce plugin ne contient pas mPDF. Installez le fichier tng-suite.zip de la Release GitHub (section « Assets », et non « Source code (zip) »). En attendant, le livre s’ouvre en page imprimable (Imprimer → Enregistrer en PDF).')
                . ' ' . tngs_t('Installation depuis le code source : lancez « composer install --no-dev » dans le dossier modules/book du plugin.');
        }
        echo '<p><strong>' . esc_html(tngs_t('PDF direct :')) . '</strong> ' . esc_html($pdf) . '</p>';
        TNGBook_History::admin_form();
        TNGBook_Auto::settings_form();
        echo '</div>';
    }

    /** Onglet « Vue d'ensemble des profils » : planification et dernière génération de chaque profil. */
    public static function page_overview() {
        if (!current_user_can('manage_options')) return;
        tngs_admin_header('Livre : vue d’ensemble des profils', array('module' => 'book'));
        TNGBook_Auto::overview();
        echo '</div>';
    }

    /** Enregistre les réglages de l'onglet « Réglages » (sans toucher aux paramètres du livre). */
    public static function settings_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        if (!isset($_POST['tngbook_snonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tngbook_snonce'])), 'tngbook_settings')) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
        $in = (isset($_POST['tngbook_cfg']) && is_array($_POST['tngbook_cfg'])) ? wp_unslash($_POST['tngbook_cfg']) : [];
        $cur = self::saved_opts();
        $cur['tng_path'] = sanitize_text_field($in['tng_path'] ?? '');
        $ph = sanitize_text_field($in['photo_folder'] ?? '');
        $cur['photo_folder'] = $ph !== '' ? $ph : 'photos';
        $cur['front_access'] = ($in['front_access'] ?? '') === 'logged_in' ? 'logged_in' : 'none';
        $cur['front_living'] = ($in['front_living'] ?? '') === 'exclude' ? 'exclude' : 'anonymize';
        foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'prefix'] as $k) unset($cur[$k]);
        update_option(self::OPT, $cur);
        wp_safe_redirect(add_query_arg(['page' => 'tngbook', 'tngbook_msg' => 'cfg_saved'], admin_url('admin.php')) . '#tngbook-settings');
        exit;
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

        try {
            $b = self::build($o, $debug);
        } catch (Exception $e) {
            wp_die(esc_html($e->getMessage()), esc_html(tngs_t('Erreur')), ['back_link' => true]);
        }
        $format = $b['format'];
        $pid = $o['personID'];

        nocache_headers();
        if (in_array($format, ['docx', 'odt'], true)) {
            header('Content-Type: ' . ($format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.oasis.opendocument.text'));
            header('Content-Disposition: attachment; filename="livre-genealogique-' . preg_replace('/[^\w\-.]/', '_', $pid) . '.' . $format . '"');
            header('Content-Length: ' . filesize($b['doc']));
            readfile($b['doc']);
            wp_delete_file($b['doc']);
            exit;
        }
        if ($format === 'pdf') {
            try {
                self::pdf_output($b['html'], 'livre-genealogique-' . $pid . '.pdf', 'D');
            } catch (Throwable $e) {   // plutôt qu'une « erreur critique » : le motif réel s'affiche
                wp_die(esc_html(tngs_t('La création du PDF a échoué :') . ' ' . $e->getMessage()), esc_html(tngs_t('Erreur')), ['back_link' => true]);
            }
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $b['html'];
        exit;
    }

    /**
     * Construit le livre à partir de paramètres déjà nettoyés. Sans effet de bord HTTP : sert au téléchargement et à la génération automatique.
     * $strict : si le format demandé (PDF, Word…) n'est pas disponible, lève une exception au lieu de replier sur le HTML.
     * Retourne ['format' => html|pdf|docx|odt, 'html' => …, 'doc' => chemin du fichier Word/ODT temporaire ou ''].
     */
    public static function build(array $o, $debug = false, $strict = false) {
        $pid = $o['personID'];
        if ($pid === '') throw new Exception(tngs_t('Identifiant de personne manquant.'));
        $lang = $o['lang'];
        $up = (int) $o['gen_up'];
        $down = (int) $o['gen_down'];
        if ($up + $down === 0) throw new Exception(tngs_t('Choisissez au moins une génération d’ascendants ou de descendants.'));
        $format = 'html';
        if ($o['format'] === 'pdf' && self::mpdf_available()) $format = 'pdf';
        elseif (in_array($o['format'], ['docx', 'odt'], true) && TNGBook_Doc::available()) $format = $o['format'];
        if ($strict && $format !== $o['format']) throw new Exception(tngs_t('Le format demandé n’est pas disponible sur ce serveur :') . ' ' . $o['format']);

        if (!self::$unlimited) @set_time_limit(300);
        wp_raise_memory_limit('admin');

        $db = self::db();
        if (is_wp_error($db)) throw new Exception($db->get_error_message());

        $tree = $o['tree'] !== '' ? $o['tree'] : tngs_gedcom();
        if ($tree === '') {
            $found = TNGBook_Data::trees($db, $o['prefix'], $pid);
            if (count($found) === 1) $tree = $found[0];
            elseif (!$found) throw new Exception(tngs_t('Identifiant') . ' "' . $pid . '" ' . tngs_t('introuvable.'));
            else throw new Exception(tngs_t('Cet identifiant existe dans plusieurs arbres') . ' (' . implode(', ', $found) . ') : ' . tngs_t('précisez l’arbre.'));
        }

        self::beat('data');
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
            'photos' => (bool) $o['photos'], 'spphotos' => (bool) $o['spphotos'], 'spfiche' => !empty($o['spfiche']), 'generic' => (bool) $o['generic'], 'avatar' => ($o['avatar'] ?? '') === 'round' ? 'round' : 'portrait',
            'sources' => (bool) $o['sources'], 'index' => (bool) $o['index'],
            'notes' => (bool) $o['notes'], 'military' => (bool) $o['military'], 'mpf' => !empty($o['mpf']), 'mini' => !empty($o['mini']), 'imgq' => !empty($o['imgq']), 'trees' => !empty($o['trees']), 'stats' => !empty($o['stats']), 'stats_first' => !empty($o['stats_first']), 'stats_last' => !empty($o['stats_last']), 'desclist' => !empty($o['desclist']), 'chrono' => !empty($o['chrono']), 'map' => !empty($o['map']), 'bapt' => !empty($o['bapt']), 'burial' => !empty($o['burial']), 'verbose' => !empty($o['verbose']), 'country' => !empty($o['country']), 'regions' => !empty($o['regions']), 'asclist' => !empty($o['asclist']), 'resi' => !empty($o['resi']), 'sibs' => !empty($o['sibs']), 'cous' => !empty($o['cous']), 'allunions' => !empty($o['allunions']), 'marrage' => !empty($o['marrage']),
            'debug' => $debug, 'place_parts' => (int) $o['place_parts'],
            'title' => $o['title'], 'author' => $o['author'], 'preface' => $o['preface'], 'surname' => (string) ($o['surname'] ?? ''), 'surname_title' => (string) ($o['surname_title'] ?? ''), 'colors' => $o['colors'] ?? [],
            'template' => $o['template'] ?? 'classic', 'frame' => in_array($o['frame'] ?? '', ['single', 'double', 'ornate', 'floral'], true) ? $o['frame'] : 'none', 'cover_art' => in_array($o['cover_art'] ?? 'tree', TNGBook_Art::cover_choices(), true) ? $o['cover_art'] : 'tree', 'kids' => !empty($o['kids']), 'histfiche' => !empty($o['histfiche']), 'history' => !empty($o['history']), 'history_lists' => (array) ($o['history_lists'] ?? []), 'history_default' => (string) ($o['history_default'] ?? ''), 'history_scope' => (string) ($o['history_scope'] ?? 'all'), 'order' => self::clean_order($o['order'] ?? null),
            'tng_path' => $o['tng_path'], 'photo_folder' => $o['photo_folder'],
        ];
        $narr = !empty(TNGBook_Renderer::templates()[$opts['template']]['narr']);
        $data->finalize($opts['photos'], $opts['sources'], $opts['notes'], $opts['military'], $narr || !empty($opts['kids']), !empty($opts['sibs']), !empty($opts['allunions']), !empty($opts['mpf']));
        if ($opts['stats']) $data->loadStatsData();
        if (!empty($opts['resi'])) $data->loadResidences($data->featured());
        if (!empty($opts['map'])) { $fp = $data->featured(); $data->loadMapData($fp, TNGBook_Map::allPlaces($data, $fp)); }
        self::beat('render');
        $isDoc = in_array($format, ['docx', 'odt'], true);
        $rend = new TNGBook_Renderer($data, $lang, $opts, $isDoc ? 'doc' : $format);
        $html = $rend->render();
        if ($isDoc) {
            self::beat('convert');
            $file = TNGBook_Doc::convert($html, array_merge(TNGBook_Renderer::defaultColors(), (array) $opts['colors']), [
                'title' => $opts['title'] !== '' ? $opts['title'] : 'Livre généalogique', 'author' => $opts['author'], 'lang' => $lang,
                'tpl' => $rend->docSpec(),
                'frame' => $opts['frame'] !== 'none' ? ['style' => in_array($opts['frame'], ['ornate', 'floral'], true) ? 'double' : $opts['frame'], 'color' => array_merge(TNGBook_Renderer::defaultColors(), (array) $opts['colors'])['head']] : null,
            ], $format);
        }

        return ['format' => $format, 'html' => $html, 'doc' => $isDoc ? $file : ''];
    }

    /**
     * mPDF affiche un carré vide à la place de certaines lettres accentuées (ç, è, ë, à…) dans un texte en petites capitales
     * (modèle « Élégant »). Pour le PDF, les petites capitales sont donc imitées : initiale telle quelle, reste en majuscules réduites.
     */
    public static function pdf_smallcaps($html) {
        if (strpos($html, 'font-variant: small-caps') === false) return $html;
        $html = str_replace('font-variant: small-caps;', '', $html);
        $sc = function ($txt) {
            $parts = preg_split('/(&#?\w+;)/u', $txt, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $i => $t) {
                if ($i % 2 === 1 || $t === '') continue;
                $parts[$i] = preg_replace_callback('/\p{L}[\p{L}\p{M}\x{2019}\x{27}-]*/u', function ($m) {
                    $w = $m[0];
                    if (!preg_match('/\p{Ll}/u', $w)) return $w;   // déjà en majuscules (patronyme)
                    if (preg_match('/^\p{Lu}/u', $w)) return mb_substr($w, 0, 1) . '<span class="sc">' . mb_substr($w, 1) . '</span>';
                    return '<span class="sc">' . $w . '</span>';
                }, $t);
            }
            return implode('', $parts);
        };
        $inner = function ($m) use ($sc) {
            $segs = preg_split('/(<[^>]*>)/', $m[2], -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($segs as $i => $t) if ($i % 2 === 0) $segs[$i] = $sc($t);
            return $m[1] . implode('', $segs) . $m[3];
        };
        $html = preg_replace_callback('#(<h4\b[^>]*>)(.*?)(</h4>)#s', $inner, $html);
        $html = preg_replace_callback('#(<h2 class="part-title"[^>]*>)(.*?)(</h2>)#s', $inner, $html);
        return str_replace('</style>', '.sc { font-size: 80%; text-transform: uppercase; }</style>', $html);
    }

    /**
     * Cadre autour des pages (PDF) : mPDF ne sait pas tracer de bordure de page ; le cadre est donc une image PNG transparente de la taille
     * de la page, posée en fond de toutes les pages sauf la première (couverture). Le repère <meta name="tng-frame"> du HTML est retiré.
     */
    public static function pdf_frame(\Mpdf\Mpdf $mpdf, $html) {
        if (!preg_match('/<meta name="tng-frame" content="(single|double|ornate|floral) (#[0-9a-f]{6})">/', $html, $m)) return $html;
        $html = str_replace($m[0], '', $html);
        if (!function_exists('imagecreatetruecolor')) return $html;
        $png = TNGBook_Art::frame($m[1], $m[2]);
        $mpdf->imageVars['tngframe'] = $png;
        // une règle @page fait perdre à mPDF son pied de page : la numérotation est donc redéclarée par ces règles (à l'intérieur du cadre)
        $ft = 'odd-footer-name: html_tngpf; even-footer-name: html_tngpf; margin-footer: 14mm;';
        $html = str_replace('</style>', '@page { background: url(var:tngframe) no-repeat 0 0; background-image-resize: 6; ' . $ft . ' } @page :first { background: none; ' . $ft . ' }</style>', $html);
        return preg_replace('/<body[^>]*>/', '$0<htmlpagefooter name="tngpf"><div style="text-align:center;font-size:9pt;color:#666666">{PAGENO}</div></htmlpagefooter>', $html, 1);
    }

    /** Pochette pleine page : image JPEG posée en fond de la première page seulement (repère <meta name="tng-cover">, retiré avant mPDF). */
    public static function pdf_cover(\Mpdf\Mpdf $mpdf, $html) {
        if (!preg_match('/<meta name="tng-cover" content="(#[0-9a-f]{6})(?: (meadow|farm|chene[234]?|peinture))?">/', $html, $m)) return $html;
        $html = str_replace($m[0], '', $html);
        if (!function_exists('imagecreatetruecolor')) return $html;
        $mpdf->imageVars['tngcover'] = TNGBook_Art::cover_page($m[1], 1240, 1754, $m[2] ?? 'page');
        $first = 'background: url(var:tngcover) no-repeat 0 0; background-image-resize: 6; odd-footer-name: html_tngnf; even-footer-name: html_tngnf; margin-footer: 14mm;';   // pas de numéro sur la pochette
        $nf = '<htmlpagefooter name="tngnf"><div></div></htmlpagefooter>';
        if (strpos($html, 'html_tngpf') !== false) {   // cadre déjà déclaré : sa règle de première page (sans fond) est remplacée
            $html = preg_replace('/@page :first \{[^}]*\}/', '@page :first { ' . $first . ' }', $html, 1);
        } else {   // une règle @page fait perdre le pied de page de toutes les pages : la numérotation est redéclarée
            $html = str_replace('</style>', '@page { odd-footer-name: html_tngpf; even-footer-name: html_tngpf; margin-footer: 14mm; } @page :first { ' . $first . ' }</style>', $html);
            $nf .= '<htmlpagefooter name="tngpf"><div style="text-align:center;font-size:9pt;color:#666666">{PAGENO}</div></htmlpagefooter>';
        }
        return preg_replace('/<body[^>]*>/', '$0' . $nf, $html, 1);
    }

    /** Nouveau document mPDF (réglages communs au PDF direct et à la génération en arrière-plan). */
    private static function pdf_make() {
        $up_dir = wp_upload_dir();
        $tmp = $up_dir['basedir'] . '/tngbook-cache/mpdf';
        wp_mkdir_p($tmp);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp, 'default_font' => 'dejavuserif',
            'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 20, 'margin_bottom' => 22,
        ]);
        $mpdf->SetCompression(true);
        $mpdf->SetFooter('{PAGENO}');
        return $mpdf;
    }

    /** Écrit le PDF (mPDF) : 'D' = téléchargement, 'F' = fichier $name. */
    public static function pdf_output(&$html, $name, $dest) {   // $html passé par référence : la copie de l'appelant est libérée dès que les images sont extraites
        self::pdf_prepare();
        $mpdf = self::pdf_make();
        self::beat('images');
        $html = self::pdf_images($mpdf, self::pdf_cover($mpdf, self::pdf_frame($mpdf, self::pdf_smallcaps((string) $html))));
        self::pdf_write($mpdf, $html);
        $html = '';
        $mpdf->imageVars = array_intersect_key($mpdf->imageVars, ['tngframe' => 1, 'tngcover' => 1]);   // les images sont désormais dans le PDF : mémoire libérée avant l'écriture du fichier
        self::beat('output');
        $mpdf->Output($name, $dest);
    }

    /**
     * Génération en arrière-plan par reprises : prépare le document et le découpe en morceaux. Retourne [mPDF, morceaux] ;
     * l'appelant écrit les morceaux au fil de plusieurs processus courts (le document mPDF se sérialise entre deux reprises).
     */
    public static function pdf_stage_init(&$html) {
        $mpdf = self::pdf_make();
        self::beat('images');
        $html = self::pdf_images($mpdf, self::pdf_cover($mpdf, self::pdf_frame($mpdf, self::pdf_smallcaps((string) $html))));
        $pieces = self::pdf_pieces($mpdf, $html);
        $html = '';
        if ($pieces === null) throw new Exception(tngs_t('Le livre n’a pas pu être découpé pour le PDF.'));
        return [$mpdf, $pieces];
    }

    /**
     * Prépare un PDF volumineux : le serveur reçoit plus de mémoire et de temps quand il l'autorise, et un manque de ressources
     * affiche un message explicite (au lieu d'une page « Erreur 500 » de l'hébergeur).
     */
    public static function pdf_prepare() {
        @ini_set('pcre.jit', '0');   // évite un plantage silencieux de PHP (JIT PCRE) sur de très gros documents ; sans effet mesurable sur la vitesse
        if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
        $mem = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get('memory_limit')) : 0;
        if ($mem > 0 && $mem < 512 * MB_IN_BYTES) @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) @set_time_limit(0);
        @ignore_user_abort(true);
        $GLOBALS['tngbook_reserve'] = str_repeat('x', 262144);   // mémoire de secours pour afficher le message en cas d'épuisement
        if (function_exists('wp_doing_cron') && wp_doing_cron()) return;   // génération automatique : l'erreur est enregistrée par la planification, pas affichée
        register_shutdown_function(function () {
            $e = error_get_last();
            if (!$e || !in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
            unset($GLOBALS['tngbook_reserve']);
            $mem = stripos($e['message'], 'memory') !== false;
            $why = $mem ? tngs_t('Mémoire insuffisante sur le serveur') : (stripos($e['message'], 'execution time') !== false ? tngs_t('Temps d’exécution dépassé sur le serveur') : tngs_t('Erreur du serveur'));
            if (!headers_sent()) { status_header(500); header('Content-Type: text/html; charset=utf-8'); }
            echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;max-width:640px;margin:3em auto;line-height:1.5"><h2>' . esc_html(tngs_t('Le PDF n’a pas pu être généré')) . '</h2><p>' . esc_html($why) . '. '
                . esc_html(tngs_t('Le livre est trop lourd pour ce serveur. Cochez « Compresser les images » (photos réduites : fichier bien plus léger), essayez « Générer en arrière-plan », réduisez le nombre de générations ou de photos, ou choisissez le format Word / OpenDocument.')) . '</p></body>';
        });
    }

    /**
     * Les images intégrées au HTML (data:) sont confiées à mPDF une seule fois chacune, sous forme de variables (src="var:…") :
     * le HTML envoyé à mPDF ne pèse plus que quelques centaines de Ko et la mémoire utilisée est nettement plus faible.
     */
    private static function pdf_images(\Mpdf\Mpdf $mpdf, $html) {
        $out = '';
        $pos = 0;
        while (($i = strpos($html, 'src="data:image/', $pos)) !== false) {
            $b = strpos($html, ';base64,', $i);
            $q = strpos($html, '"', $i + 5);
            if ($b === false || $q === false || $b > $q) {
                $out .= substr($html, $pos, $i + 5 - $pos);
                $pos = $i + 5;
                continue;
            }
            $data = substr($html, $b + 8, $q - $b - 8);
            $key = 'i' . md5($data);
            if (!isset($mpdf->imageVars[$key])) {
                $bin = base64_decode($data, true);
                if ($bin === false || $bin === '') {   // image illisible : laissée telle quelle
                    $out .= substr($html, $pos, $q - $pos);
                    $pos = $q;
                    continue;
                }
                $mpdf->imageVars[$key] = $bin;
            }
            $out .= substr($html, $pos, $i + 5 - $pos) . 'var:' . $key;
            $pos = $q;
        }
        return $out . substr($html, $pos);
    }

    /**
     * Envoie le livre à mPDF par morceaux : mPDF refuse tout bloc supérieur à pcre.backtrack_limit (1 Mo par défaut), ce que dépasse un livre un peu fourni.
     * Les coupures se font entre deux balises <div> et, dans les longs tableaux de liste, entre deux lignes (le tableau est refermé puis rouvert).
     */
    private static function pdf_write(\Mpdf\Mpdf $mpdf, $html) {
        $pieces = self::pdf_pieces($mpdf, $html);
        if ($pieces === null) {   // pas de document complet : envoi tel quel
            @ini_set('pcre.backtrack_limit', (string) max((int) ini_get('pcre.backtrack_limit'), 2 * strlen($html)));
            $mpdf->WriteHTML($html);
            return;
        }
        foreach ($pieces as $n => $_) self::pdf_feed($mpdf, $pieces, $n);
    }

    /** Écrit le morceau $n du livre dans mPDF (journal d'avancement compris) ; retourne la durée en secondes. */
    public static function pdf_feed(\Mpdf\Mpdf $mpdf, array $pieces, $n) {
        $count = count($pieces);
        $piece = $pieces[$n];
        $need = 2 * strlen($piece);
        if ($need > (int) ini_get('pcre.backtrack_limit')) @ini_set('pcre.backtrack_limit', (string) $need);
        $txt = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(substr($piece, 0, 4000))));
        self::beat('write', $n / $count, sprintf('%d/%d · %d Ko%s · « %s »%s', $n + 1, $count, (int) round(strlen($piece) / 1024), strncasecmp($piece, '<table', 6) === 0 ? ' (' . tngs_t('suite de tableau') . ')' : '', mb_substr($txt, 0, 70), self::$took));
        $t0 = microtime(true);
        $mpdf->WriteHTML($piece, \Mpdf\HTMLParserMode::HTML_BODY, $n === 0, $n === $count - 1);
        $d = microtime(true) - $t0;
        self::$took = sprintf(' · %s %.1f s', tngs_t('précédent :'), $d);
        return $d;
    }

    private static $took = '';

    /**
     * Prépare le corps du livre pour mPDF : feuilles de style et titre confiés à $mpdf, puis liste des morceaux à écrire
     * (null si le HTML n'est pas un document complet).
     */
    private static function pdf_pieces(\Mpdf\Mpdf $mpdf, $html) {
        $b0 = stripos($html, '<body');
        $b1 = $b0 === false ? false : strpos($html, '>', $b0);
        $b2 = strripos($html, '</body>');
        if ($b0 === false || $b1 === false || $b2 === false || $b2 < $b1) return null;
        $head = substr($html, 0, $b0);
        if (preg_match('#<title>(.*?)</title>#is', $head, $t)) $mpdf->SetTitle(html_entity_decode(wp_strip_all_tags($t[1]), ENT_QUOTES, 'UTF-8'));
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $head, $css)) {
            foreach ($css[1] as $c) $mpdf->WriteHTML($c, \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $body = substr($html, $b1 + 1, $b2 - $b1 - 1);
        /* Découpage : coupures entre deux <div> (100 Ko au plus entre deux coupures) et, dans un long tableau « st »
           (listes, chronologie), entre deux lignes (80 Ko au plus) : le tableau est refermé puis rouvert avec sa ligne d'en-tête,
           ce qui garde des colonnes alignées. Un morceau géant (plusieurs centaines de Ko) est ce qui épuise mPDF. */
        $max = 100000;
        $tmax = 80000;
        $cuts = [];   // chaque coupure : [position, fermeture ajoutée au morceau qui finit, ouverture ajoutée au suivant]
        $last = 0;
        $tab = 0;
        $st = false; $tstart = 0; $topen = ''; $hdr = ''; $hdrFrom = -1; $prevGen = false;
        if (preg_match_all('#<(/?)(table|div|tr)\b[^>]*>#i', $body, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $k => $hit) {
                $tag = strtolower($m[2][$k][0]);
                $close = $m[1][$k][0] === '/';
                $pos = $hit[1];
                if ($tag === 'table') {
                    $tab += $close ? -1 : 1;
                    if (!$close && $tab === 1) { $st = (bool) preg_match('#\bclass\s*=\s*["\']st["\']#i', $hit[0]); $topen = $hit[0]; $tstart = $pos; $hdr = ''; $hdrFrom = -1; $prevGen = false; }
                    if ($close && $tab === 0) $st = false;
                    continue;
                }
                if ($tag === 'tr') {
                    if ($tab !== 1 || !$st) continue;
                    if ($close) {
                        if ($hdrFrom >= 0 && $hdr === '') $hdr = substr($body, $hdrFrom, $pos + 5 - $hdrFrom);   // première ligne = en-tête
                        continue;
                    }
                    if ($hdrFrom < 0) { $hdrFrom = $pos; continue; }
                    if ($hdr !== '' && !$prevGen && $pos - max($last, $tstart) > $tmax) {
                        $cuts[] = [$pos, '</table>', $topen . $hdr];
                        $last = $pos;
                    }
                    $prevGen = stripos($hit[0], 'genrow') !== false;
                    continue;
                }
                if (!$close && $tab === 0 && $pos - $last > $max) { $cuts[] = [$pos, '', '']; $last = $pos; }
            }
        }
        $cuts[] = [strlen($body), '', ''];
        $pieces = [];
        $from = 0;
        $pre = '';
        foreach ($cuts as $c) {
            $pieces[] = $pre . substr($body, $from, $c[0] - $from) . $c[1];
            $from = $c[0];
            $pre = $c[2];
        }
        return $pieces;
    }

    /**
     * Paramètres complets d'un profil enregistré, prêts pour build() (génération automatique, sans utilisateur connecté).
     * Les profils ne sont enregistrés que par un administrateur : on les traite donc comme tels (texte du patronyme conservé).
     */
    public static function profile_options($name) {
        $all = self::profiles();
        if (!isset($all[$name])) return null;
        self::$trusted = true;
        try {
            $o = self::sanitize_gen((array) $all[$name], self::opts());
        } finally {
            self::$trusted = false;
        }
        $o['prefix'] = tngs_prefix();
        return $o;
    }
}

TNGBook::init();
TNGBook_Auto::init();
TNGBook_Bg::init();

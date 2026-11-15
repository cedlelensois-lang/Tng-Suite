<?php
/**
 * Livre : génération en arrière-plan.
 *
 * Un gros livre peut dépasser le délai que l'hébergeur accorde à une page (erreur 500 après une longue attente). Ici, la page se contente
 * de mettre le travail en file : une tâche WP-Cron unique construit le livre sur le serveur, sans que personne n'attende, et le fichier
 * est déposé dans le dossier de la génération automatique (protégé). L'écran affiche l'état, l'avancement et le lien de téléchargement.
 * Un seul travail à la fois ; il peut être annulé ; une interruption brutale (limite de l'hébergeur) est détectée et expliquée.
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Bg {
    const JOB   = 'tngbook_bg_job';
    const HOOK  = 'tngbook_bg_run';
    const STALE = 300;   // secondes sans signe de vie avant de considérer le travail comme interrompu
    const WAIT  = 900;   // idem entre deux reprises d'un PDF (le travail attend que WordPress relance ses tâches planifiées)
    const BUDGET = 12;   // PDF : durée de travail (s) d'une reprise avant de mettre le document en pause ; beaucoup d'hébergeurs coupent un processus long

    private static $handover = false;

    private static $last_beat = 0;
    private static $last_phase = '';

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('admin_post_tngbook_bg_start', [__CLASS__, 'start']);
        add_action('admin_post_tngbook_bg_clear', [__CLASS__, 'clear']);
        add_action('wp_ajax_tngbook_bg_status', [__CLASS__, 'ajax_status']);
    }

    /* ---------- Données ---------- */

    public static function job() {
        $j = get_option(self::JOB, null);
        return (is_array($j) && !empty($j['id'])) ? $j : null;
    }

    private static function save(array $j) {
        update_option(self::JOB, $j, false);
    }

    /** Travail en file ou en cours (et pas abandonné). */
    public static function active(array $j) {
        if ($j['state'] === 'queued') return true;
        return in_array($j['state'], ['running', 'cancel'], true) && !self::stale($j);
    }

    public static function stale(array $j) {
        return in_array($j['state'], ['running', 'cancel'], true) && (time() - (int) $j['beat']) > (!empty($j['wait']) ? self::WAIT : self::STALE);
    }

    /** Phases : libellé, début et étendue (en %) dans la barre d'avancement. */
    private static function phases() {
        return [
            'data'    => ['Lecture des personnes dans la base TNG', 2, 13],
            'render'  => ['Mise en page du livre', 15, 30],
            'convert' => ['Création du fichier Word / OpenDocument', 45, 50],
            'images'  => ['Préparation des images', 45, 5],
            'write'   => ['Création des pages du PDF', 50, 45],
            'output'  => ['Compression et assemblage du PDF', 95, 3],
            'save'    => ['Écriture du fichier', 96, 3],
        ];
    }

    /* ---------- Mise en file ---------- */

    private static function check_nonce($profile) {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        $ok = false;
        if ($profile !== '') {
            $ok = isset($_POST['_tngauto']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tngauto'])), 'tngbook_auto');
        } else {
            $ok = isset($_POST['tngbook_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tngbook_nonce'])), 'tngbook_generate');
        }
        if (!$ok) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
    }

    private static function back($msg) {
        wp_safe_redirect(add_query_arg(['page' => 'tngbook', 'tngbook_msg' => $msg], admin_url('admin.php')));
        exit;
    }

    /** Bouton « Générer en arrière-plan » (formulaire du livre) ou « En arrière-plan » d'un profil (vue d'ensemble). */
    public static function start() {
        $profile = isset($_POST['bg_profile']) ? sanitize_text_field(wp_unslash($_POST['bg_profile'])) : '';
        self::check_nonce($profile);

        $cur = self::job();
        if ($cur && self::active($cur)) self::back('bg_busy');
        if ($cur && self::stale($cur)) delete_transient(TNGBook_Auto::LOCK);   // le travail précédent est mort : son verrou ne doit pas bloquer le suivant

        if ($profile !== '') {
            $profiles = TNGBook::profiles();
            if (!isset($profiles[$profile])) self::back('missing');
            $label = $profile;
            $format = (string) ($profiles[$profile]['format'] ?? 'html');
            $opts = [];
        } else {
            $in = (isset($_POST[TNGBook::OPT]) && is_array($_POST[TNGBook::OPT])) ? wp_unslash($_POST[TNGBook::OPT]) : [];
            $o = TNGBook::sanitize($in);
            update_option(TNGBook::OPT, $o);
            $o['prefix'] = tngs_prefix();
            if ($o['personID'] === '') self::back('bg_noid');
            if ((int) $o['gen_up'] + (int) $o['gen_down'] === 0) self::back('bg_nogen');
            $label = $o['personID'];
            $format = $o['format'];
            $opts = $o;
        }

        foreach ((array) glob(TNGBook_Auto::dir(true) . '/work-*', GLOB_ONLYDIR) as $d) {   // dossiers de travail abandonnés (travail interrompu, effacé)
            if (filemtime($d) < time() - DAY_IN_SECONDS) { foreach ((array) glob($d . '/*') as $f) if (is_file($f)) wp_delete_file($f); @rmdir($d); }
        }
        $id = strtolower(wp_generate_password(12, false, false));
        self::save([
            'id' => $id, 'state' => 'queued', 'profile' => $profile, 'label' => $label, 'format' => $format,
            'queued' => time(), 'started' => 0, 'beat' => 0, 'phase' => '', 'pct' => 0, 'finished' => 0,
            'msg' => '', 'file' => '', 'size' => 0, 'peak' => 0, 'opts' => $opts,
        ]);
        wp_schedule_single_event(time(), self::HOOK, [$id]);
        self::spawn();
        self::back('bg_queued');
    }

    /** Lance WP-Cron tout de suite (sinon la tâche attend la prochaine visite du site). */
    private static function spawn() {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) return;
        if (function_exists('spawn_cron')) spawn_cron();
    }

    /** Annule le travail en cours ou en file, ou efface un résultat / une interruption. */
    public static function clear() {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        if (!isset($_POST['_tngbg']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tngbg'])), 'tngbook_bg')) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
        $j = self::job();
        if ($j) {
            wp_clear_scheduled_hook(self::HOOK, [$j['id']]);
            if ($j['state'] === 'running' && !self::stale($j) && empty($j['wait'])) {
                $j['state'] = 'cancel';   // le travail s'arrête à son prochain point de contrôle
                self::save($j);
            } else {
                if (self::stale($j) || !empty($j['wait'])) delete_transient(TNGBook_Auto::LOCK);
                self::cleanup($j['id']);
                delete_option(self::JOB);
            }
        }
        self::back('bg_cleared');
    }

    /* ---------- Exécution (WP-Cron) ---------- */

    public static function run($id) {
        $j = self::job();
        if (!$j || $j['id'] !== $id) return;
        $cont = $j['state'] === 'running' && !empty($j['stage']);   // reprise d'un PDF mis en pause
        if ($j['state'] !== 'queued' && !$cont) return;
        if ($cont && !empty($j['busy']) && time() - (int) $j['busy'] < 120) return;   // doublon de la tâche planifiée
        $t0 = microtime(true);
        if (!$cont) {
            if (get_transient(TNGBook_Auto::LOCK)) {   // une génération automatique est en cours : on réessaie dans deux minutes
                wp_schedule_single_event(time() + 120, self::HOOK, [$id]);
                return;
            }
            set_transient(TNGBook_Auto::LOCK, 1, 2 * HOUR_IN_SECONDS);
            $j['state'] = 'running';
            $j['started'] = time();
            $j['phase'] = 'data';
        }
        $j['beat'] = time();
        $j['busy'] = time();
        $j['wait'] = 0;
        $j['runs'] = (int) ($j['runs'] ?? 0) + 1;
        self::save($j);

        @ignore_user_abort(true);
        TNGBook::$unlimited = true;
        TNGBook::pdf_prepare();   // mémoire et temps relevés quand le serveur l'autorise
        $j['limits'] = 'max_execution_time=' . ini_get('max_execution_time') . ' s · memory_limit=' . ini_get('memory_limit') . ' · PHP ' . PHP_VERSION . ' · ' . PHP_SAPI;
        self::save($j);
        TNGBook::$progress = [__CLASS__, 'beat'];
        self::$handover = false;
        register_shutdown_function([__CLASS__, 'shutdown'], $id);

        try {
            $res = $cont ? self::pdf_resume($j, $t0) : self::build($j, $t0);
        } catch (Throwable $e) {
            $res = ['fail', $e->getMessage()];
        }
        TNGBook::$progress = null;

        if ($res[0] === 'more') {   // PDF mis en pause : la suite sera écrite par une nouvelle tâche, dans un autre processus
            $j = self::job();
            if (!$j || $j['id'] !== $id) return;
            self::$handover = true;
            $j['busy'] = 0;
            $j['wait'] = time();
            $j['beat'] = time();
            $j['peak'] = max((int) $j['peak'], memory_get_peak_usage(true));
            self::save($j);
            wp_schedule_single_event(time(), self::HOOK, [$id]);
            delete_transient('doing_cron');   // le verrou de WP-Cron est encore pris par ce processus : on le libère pour que la suite démarre tout de suite
            self::spawn();
            return;
        }

        delete_transient(TNGBook_Auto::LOCK);
        $j = self::job();
        self::cleanup($id);
        if (!$j || $j['id'] !== $id) return;   // effacé entre-temps
        if ($j['state'] === 'cancel' && $res[0] === 'fail') { delete_option(self::JOB); return; }   // annulé par l'administrateur : rien à signaler
        $j['finished'] = time();
        $j['peak'] = max((int) $j['peak'], memory_get_peak_usage(true));
        $j['opts'] = [];
        $j['stage'] = '';
        $j['busy'] = 0;
        $j['state'] = $res[0];
        $j['msg'] = (string) $res[1];
        if ($res[0] === 'ok') { $j['file'] = (string) $res[2]; $j['size'] = (int) $res[3]; $j['pct'] = 100; }
        self::save($j);
        if ($j['profile'] !== '') TNGBook_Auto::record($j['profile'], $res);
        TNGBook_Auto::notify($j['label'], $res);
    }

    private static function build(array $j, $t0) {
        if ($j['profile'] !== '') {
            $o = TNGBook::profile_options($j['profile']);
            if ($o === null) throw new Exception(tngs_t('Profil introuvable.'));
            $base = TNGBook_Auto::base($j['profile']);
        } else {
            $o = $j['opts'];
            $base = TNGBook_Auto::base('livre-' . $o['personID']);
        }
        $b = TNGBook::build($o, false, true);
        $sig = $j['profile'] !== '' ? TNGBook_Auto::signature($o) : '';
        if ($b['format'] === 'pdf') return self::pdf_begin($j, $b, $base, $sig, $t0);
        [$file, $size] = TNGBook_Auto::store($b, $base);
        return ['ok', tngs_t('Livre généré.'), $file, $size, $sig];
    }

    /* ---------- PDF par reprises ---------- */

    /** Dossier de travail d'un PDF en cours (dans le dossier protégé de la génération automatique). */
    private static function workdir($id) {
        return TNGBook_Auto::dir(true) . '/work-' . preg_replace('/[^a-z0-9]/', '', $id);
    }

    private static function cleanup($id) {
        $d = self::workdir($id);
        if (!is_dir($d)) return;
        foreach ((array) glob($d . '/*') as $f) { if (is_file($f)) wp_delete_file($f); }
        @rmdir($d);
    }

    private static function patch(array $new) {
        wp_cache_delete(self::JOB, 'options');
        $j = self::job();
        if ($j) self::save(array_merge($j, $new));
    }

    /** Première reprise : le livre est prêt en HTML ; le document PDF est préparé puis commencé. */
    private static function pdf_begin(array $j, array $b, $base, $sig, $t0) {
        $w = self::workdir($j['id']);
        if (!wp_mkdir_p($w)) throw new Exception(tngs_t('Écriture impossible dans le dossier de destination.'));
        [$mpdf, $pieces] = TNGBook::pdf_stage_init($b['html']);
        $b = null;
        $file = TNGBook_Auto::new_name($base, 'pdf');
        if (file_put_contents($w . '/pieces.ser', serialize($pieces)) === false) throw new Exception(tngs_t('Écriture impossible dans le dossier de destination.'));
        self::patch(['stage' => 'pdf', 'total' => count($pieces), 'pos' => 0, 'pfile' => $file, 'pbase' => $base, 'psig' => $sig]);
        return self::pdf_work($j['id'], $mpdf, $pieces, 0, 'pdf', $t0);
    }

    /** Reprise suivante : le document est rechargé tel qu'il avait été mis en pause. */
    private static function pdf_resume(array $j, $t0) {
        if (!TNGBook::mpdf_available()) throw new Exception(tngs_t('Le format demandé n’est pas disponible sur ce serveur :') . ' PDF');   // la bibliothèque doit être chargée avant de relire le document
        $w = self::workdir($j['id']);
        $st = is_file($w . '/state.ser') ? @unserialize((string) file_get_contents($w . '/state.ser')) : false;
        $pieces = is_file($w . '/pieces.ser') ? @unserialize((string) file_get_contents($w . '/pieces.ser')) : false;
        if (!is_array($st) || !isset($st['mpdf']) || !is_array($pieces)) throw new Exception(tngs_t('Impossible de reprendre le PDF : l’état enregistré est illisible.'));
        return self::pdf_work($j['id'], $st['mpdf'], $pieces, (int) $st['pos'], (string) $st['stage'], $t0);
    }

    /** Écrit des morceaux tant que la durée d'une reprise n'est pas atteinte, puis met en pause (« more ») ou termine le PDF. */
    private static function pdf_work($id, \Mpdf\Mpdf $mpdf, array $pieces, $pos, $stage, $t0) {
        $total = count($pieces);
        if ($stage === 'pdf') {
            $budget = defined('TNGBOOK_BG_BUDGET') ? (float) TNGBOOK_BG_BUDGET : self::BUDGET;
            while ($pos < $total) {   // au moins un morceau par reprise
                TNGBook::pdf_feed($mpdf, $pieces, $pos);
                $pos++;
                self::patch(['pos' => $pos]);
                if (microtime(true) - $t0 >= $budget) break;
            }
            if ($pos < $total || (microtime(true) - $t0) > 5) {   // la sortie du fichier a droit à une reprise à elle quand celle-ci a déjà beaucoup travaillé
                self::pdf_pause($id, $mpdf, $pos, $pos < $total ? 'pdf' : 'out');
                return ['more'];
            }
        }
        $j = self::job();
        $file = (string) $j['pfile'];
        $dest = TNGBook_Auto::dir(true) . '/' . $file;
        $mpdf->imageVars = array_intersect_key($mpdf->imageVars, ['tngframe' => 1]);
        TNGBook::beat('output');
        $mpdf->Output($dest, 'F');
        TNGBook::beat('save');
        [$f, $size] = TNGBook_Auto::finish($file, (string) $j['pbase']);
        return ['ok', tngs_t('Livre généré.'), $f, $size, (string) $j['psig']];
    }

    private static function pdf_pause($id, \Mpdf\Mpdf $mpdf, $pos, $stage) {
        $w = self::workdir($id);
        $tmp = $w . '/state.tmp';
        if (file_put_contents($tmp, serialize(['mpdf' => $mpdf, 'pos' => $pos, 'stage' => $stage])) === false || !@rename($tmp, $w . '/state.ser')) {
            throw new Exception(tngs_t('Écriture impossible dans le dossier de destination.'));
        }
        self::patch(['stage' => $stage]);
    }

    /** Point de contrôle : enregistre l'avancement (au plus toutes les 3 s) et s'interrompt si le travail a été annulé. */
    public static function beat($phase, $pct = null, $detail = '') {
        $now = time();
        if ($detail === '' && $phase === self::$last_phase && $now - self::$last_beat < 3) return;   // un point précis (morceau du PDF) est toujours consigné
        self::$last_phase = $phase;
        self::$last_beat = $now;
        wp_cache_delete(self::JOB, 'options');
        $j = self::job();
        if (!$j || $j['state'] === 'cancel') throw new Exception(tngs_t('Génération annulée.'));
        $ph = self::phases()[$phase] ?? null;
        $j['phase'] = $phase;
        $j['beat'] = $now;
        if ($ph) $j['pct'] = (int) round($ph[1] + $ph[2] * max(0, min(1, (float) $pct)));
        $j['peak'] = memory_get_peak_usage(true);
        $j['mem'] = memory_get_usage(true);
        if ($detail !== '') $j['detail'] = $detail;
        self::save($j);
    }

    /** Fin brutale du processus (mémoire, temps, erreur fatale) : l'état est consigné avec le motif. */
    public static function shutdown($id) {
        if (self::$handover) return;   // pause volontaire d'un PDF : la suite est déjà planifiée
        $j = self::job();
        if (!$j || $j['id'] !== $id || !in_array($j['state'], ['running', 'cancel'], true)) return;
        unset($GLOBALS['tngbook_reserve']);
        if ($j['state'] === 'cancel') { delete_option(self::JOB); delete_transient(TNGBook_Auto::LOCK); self::cleanup($id); return; }
        $e = error_get_last();
        $fatal = $e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true);
        if ($fatal && stripos($e['message'], 'memory') !== false) $why = tngs_t('Mémoire insuffisante sur le serveur');
        elseif ($fatal && stripos($e['message'], 'execution time') !== false) $why = tngs_t('Temps d’exécution dépassé sur le serveur');
        elseif ($fatal) $why = tngs_t('Erreur du serveur') . ' : ' . $e['message'];
        else $why = tngs_t('Le traitement s’est arrêté sans message');
        $j['state'] = 'fail';
        $j['msg'] = $why;
        $j['finished'] = time();
        $j['peak'] = memory_get_peak_usage(true);
        $j['opts'] = [];
        self::save($j);
        delete_transient(TNGBook_Auto::LOCK);
        TNGBook_Auto::notify($j['label'], ['fail', $why]);
    }

    /* ---------- Affichage ---------- */

    private static function mins($s) {
        $s = max(0, (int) $s);
        return $s >= 60 ? floor($s / 60) . ' min ' . sprintf('%02d', $s % 60) . ' s' : $s . ' s';
    }

    private static function btn($label, $class = 'button') {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:6px 0 0">'
            . '<input type="hidden" name="action" value="tngbook_bg_clear"><input type="hidden" name="_tngbg" value="' . esc_attr(wp_create_nonce('tngbook_bg')) . '">'
            . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    /** Contenu du panneau d'état (rafraîchi par AJAX tant que le travail est actif). */
    public static function status_html(array $j) {
        $fmt = strtoupper((string) $j['format']);
        $head = '<strong>' . esc_html($j['label']) . '</strong> <span class="description">(' . esc_html($fmt) . ')</span> — ';
        $now = time();
        if ($j['state'] === 'queued') {
            $h = '<p>' . $head . '<span style="color:#996800">●</span> ' . esc_html(tngs_t('En attente de démarrage…')) . ' <span class="description">' . esc_html(self::mins($now - (int) $j['queued'])) . '</span></p>';
            $h .= '<p class="description">' . esc_html(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
                ? tngs_t('Le planificateur interne de WordPress est désactivé sur ce site : le travail démarrera au prochain passage de la tâche cron du serveur (wp-cron.php).')
                : tngs_t('Le travail démarre dès que WordPress lance ses tâches planifiées (quelques secondes, ou à la prochaine visite du site).')) . '</p>';
            if (($now - (int) $j['queued']) > 120 && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
                $h .= '<p class="description" style="color:#b32d2e">' . esc_html(tngs_t('Toujours en attente ? Les tâches planifiées semblent ne pas s’exécuter : l’hébergeur peut bloquer les appels du site vers lui-même. Programmez une tâche cron du serveur qui appelle wp-cron.php, ou demandez à l’hébergeur.')) . '</p>';
            }
            return $h . self::btn(tngs_t('Annuler'));
        }
        if ($j['state'] === 'cancel' && !self::stale($j)) {
            return '<p>' . $head . '<span style="color:#996800">●</span> ' . esc_html(tngs_t('Annulation en cours…')) . '</p>';
        }
        if (self::stale($j)) {
            $ph = self::phases()[$j['phase']][0] ?? '';
            $h = '<p>' . $head . '<span style="color:#b32d2e">●</span> <strong>' . esc_html(tngs_t('Génération interrompue')) . '</strong></p>';
            $h .= '<p>' . esc_html(tngs_t('Aucun signe d’activité depuis')) . ' ' . esc_html(self::mins($now - (int) $j['beat'])) . '. ' . esc_html(tngs_t('Le traitement s’est arrêté sans pouvoir se signaler : limite de l’hébergeur (temps, mémoire, processus) ou plantage de PHP.'));
            if ($ph !== '') $h .= ' ' . esc_html(tngs_t('Dernière étape atteinte :')) . ' <em>' . esc_html(tngs_t($ph)) . '</em>.';
            $h .= '</p>';
            if ((int) $j['started'] > 0 && (int) $j['beat'] >= (int) $j['started']) {
                $h .= '<p class="description">' . esc_html(tngs_t('Durée de travail avant l’arrêt :')) . ' <strong>' . esc_html(self::mins((int) $j['beat'] - (int) $j['started'])) . '</strong>'
                    . (!empty($j['limits']) ? ' · ' . esc_html(tngs_t('Réglages du serveur :')) . ' ' . esc_html($j['limits']) : '')
                    . ' · ' . esc_html(tngs_t('Mémoire maximale utilisée :')) . ' ' . esc_html(size_format((int) $j['peak'])) . '</p>';
            }
            if (!empty($j['detail'])) {
                $h .= '<p class="description">' . esc_html(tngs_t('Dernier morceau du PDF lancé :')) . ' ' . esc_html($j['detail'])
                    . (!empty($j['mem']) ? ' · ' . esc_html(tngs_t('mémoire en cours :')) . ' ' . esc_html(size_format((int) $j['mem'])) : '') . '</p>';
            }
            $h .= '<p class="description">' . esc_html(tngs_t('Pistes : cochez « Compresser les images », réduisez le nombre de générations ou de photos, choisissez le format Word / OpenDocument, ou demandez à l’hébergeur d’allonger la limite de temps des tâches planifiées (ou programmez une tâche cron du serveur qui appelle wp-cron.php).')) . '</p>';
            return $h . self::btn(tngs_t('Effacer ce message'));
        }
        if ($j['state'] === 'running') {
            $ph = self::phases()[$j['phase']][0] ?? '';
            $pct = max(1, min(99, (int) $j['pct']));
            $h = '<p>' . $head . '<span style="color:#2271b1">●</span> ' . esc_html(tngs_t('En cours')) . ' — ' . esc_html(tngs_t($ph)) . ' <span class="description">(' . (int) $pct . ' %, ' . esc_html(self::mins($now - (int) $j['started'])) . ')</span></p>';
            $h .= '<div style="height:10px;max-width:520px;background:#dcdcde;border-radius:5px;overflow:hidden"><div style="height:100%;width:' . (int) $pct . '%;background:#2271b1"></div></div>';
            if (!empty($j['detail'])) $h .= '<p class="description">' . esc_html(tngs_t('Morceau du PDF en cours :')) . ' ' . esc_html($j['detail']) . '</p>';
            if (!empty($j['total']) && !empty($j['runs'])) $h .= '<p class="description">' . esc_html(tngs_t('Le PDF est écrit par étapes courtes (chaque étape reprend là où la précédente s’est arrêtée) : étape')) . ' ' . (int) $j['runs'] . (!empty($j['wait']) ? ' — ' . esc_html(tngs_t('en attente de la suivante (WordPress relance ses tâches planifiées)')) : '') . '.</p>';
            $age = $now - (int) $j['beat'];
            $h .= '<p class="description">' . esc_html(tngs_t('Vous pouvez quitter cette page : le livre se prépare sur le serveur.'))
                . ($age >= 60 ? ' ' . esc_html(tngs_t('Dernier signe d’activité il y a')) . ' ' . esc_html(self::mins($age)) . '.' : '') . '</p>';
            return $h . self::btn(tngs_t('Annuler'));
        }
        $dur = $j['finished'] && $j['started'] ? ' ' . esc_html(tngs_t('en')) . ' ' . esc_html(self::mins((int) $j['finished'] - (int) $j['started'])) : '';
        if ($j['state'] === 'ok') {
            $url = wp_nonce_url(add_query_arg(['action' => 'tngbook_auto_dl', 'f' => $j['file']], admin_url('admin-post.php')), 'tngbook_auto', '_tngauto');
            $h = '<p>' . $head . '<span style="color:#1a7f37">●</span> <strong>' . esc_html(tngs_t('Livre prêt')) . '</strong> <span class="description">(' . esc_html(wp_date('d/m/Y H:i', (int) $j['finished'])) . $dur . ')</span></p>';
            $h .= '<p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html(tngs_t('Télécharger')) . ' ' . esc_html($j['file']) . '</a> <span class="description">' . esc_html(size_format((int) $j['size'])) . '</span></p>';
            $h .= '<p class="description">' . esc_html(tngs_t('Le fichier est conservé dans le dossier de la génération automatique (onglet « Réglages »).')) . '</p>';
            return $h . self::btn(tngs_t('Effacer ce message'));
        }
        $h = '<p>' . $head . '<span style="color:#b32d2e">●</span> <strong>' . esc_html(tngs_t('Échec de la génération')) . '</strong> <span class="description">(' . esc_html(wp_date('d/m/Y H:i', (int) $j['finished'])) . $dur . ')</span></p>';
        $h .= '<p>' . esc_html($j['msg']) . '</p>';
        if (!empty($j['phase']) && isset(self::phases()[$j['phase']])) $h .= '<p class="description">' . esc_html(tngs_t('Dernière étape atteinte :')) . ' ' . esc_html(tngs_t(self::phases()[$j['phase']][0])) . ($j['peak'] ? ' · ' . esc_html(tngs_t('mémoire maximale utilisée :')) . ' ' . esc_html(size_format((int) $j['peak'])) : '') . '</p>';
        return $h . self::btn(tngs_t('Effacer ce message'));
    }

    public static function ajax_status() {
        if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
        check_ajax_referer('tngbook_bg', '_n');
        $j = self::job();
        if (!$j) wp_send_json_success(['html' => '', 'active' => false]);
        if ($j['state'] === 'queued' || (!empty($j['wait']) && time() - (int) $j['wait'] > 8)) self::spawn();
        wp_send_json_success(['html' => self::status_html($j), 'active' => self::active($j)]);
    }

    private static function notices() {
        $notes = [
            'bg_queued' => ['success', 'La génération est lancée en arrière-plan : suivez son avancement ci-dessous.'],
            'bg_busy' => ['warning', 'Une génération en arrière-plan est déjà en cours : attendez qu’elle se termine ou annulez-la.'],
            'bg_noid' => ['warning', 'Indiquez l’identifiant de la personne avant de lancer la génération.'],
            'bg_nogen' => ['warning', 'Choisissez au moins une génération d’ascendants ou de descendants.'],
            'missing' => ['warning', 'Profil introuvable.'],
            'bg_cleared' => ['info', 'Génération en arrière-plan annulée ou effacée.'],
        ];
        $m = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($notes[$m])) echo '<div class="notice notice-' . esc_attr($notes[$m][0]) . ' is-dismissible"><p>' . esc_html(tngs_t($notes[$m][1])) . '</p></div>';
    }

    /** Panneau d'état en haut de l'onglet « Générer un livre » (visible seulement quand un travail existe). */
    public static function panel() {
        self::notices();
        $j = self::job();
        if (!$j) return;
        $n = wp_create_nonce('tngbook_bg');
        echo '<div id="tngbook-bg" style="max-width:1100px;margin:14px 0;padding:6px 16px 10px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1">'
            . '<h2 style="margin:.6em 0 .2em">' . esc_html(tngs_t('Génération en arrière-plan')) . '</h2><div class="tngbook-bg-body">' . self::status_html($j) . '</div></div>';
        if (self::active($j)) {
            echo '<script>(function(){var box=document.querySelector("#tngbook-bg .tngbook-bg-body");if(!box||!window.fetch)return;var fails=0;function tick(){'
                . 'fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"action=tngbook_bg_status&_n=' . esc_js($n) . '"})'
                . '.then(function(r){return r.json();}).then(function(d){fails=0;if(d&&d.success){if(d.data.html)box.innerHTML=d.data.html;if(d.data.active)setTimeout(tick,4000);}}).catch(function(){if(++fails<5)setTimeout(tick,8000);});}'
                . 'setTimeout(tick,4000);})();</script>';
        }
    }
}

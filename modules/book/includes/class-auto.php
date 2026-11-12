<?php
/**
 * Livre : génération automatique planifiée par profil (bêta).
 *
 * Chaque profil enregistré peut être généré chaque jour, semaine ou mois, dans un dossier du serveur, avec rotation des anciens fichiers.
 * La planification repose sur WP-Cron : un contrôle toutes les heures lance les tâches arrivées à échéance, une à la fois.
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Auto {
    const OPT   = 'tngbook_auto';        // réglages communs : dossier, nombre de fichiers conservés, e-mail…
    const JOBS  = 'tngbook_auto_jobs';   // nom du profil => tâche
    const KEY   = 'tngbook_auto_key';    // suffixe aléatoire du dossier par défaut
    const HOOK  = 'tngbook_auto_tick';
    const LOCK  = 'tngbook_auto_lock';
    const EXTS  = ['pdf', 'docx', 'odt', 'html'];

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'tick']);
        add_action('admin_init', [__CLASS__, 'ensure_schedule']);
        add_action('admin_post_tngbook_auto_save', [__CLASS__, 'save']);
        add_action('admin_post_tngbook_auto_now', [__CLASS__, 'now']);
        add_action('admin_post_tngbook_auto_dl', [__CLASS__, 'download']);
    }

    /* ---------- Données ---------- */

    public static function settings() {
        $s = wp_parse_args(get_option(self::OPT, []), ['dir' => '', 'keep' => 4, 'only_changed' => 1, 'mail' => '', 'mail_on' => 'fail']);
        $s['keep'] = max(1, min(30, (int) $s['keep']));
        $s['mail_on'] = in_array($s['mail_on'], ['never', 'fail', 'always'], true) ? $s['mail_on'] : 'fail';
        return $s;
    }

    public static function jobs() {
        $j = get_option(self::JOBS, []);
        return is_array($j) ? $j : [];
    }

    private static function job_defaults() {
        return ['on' => 0, 'freq' => 'weekly', 'dow' => 1, 'dom' => 1, 'hour' => 3, 'next' => 0, 'last' => 0, 'status' => '', 'msg' => '', 'file' => '', 'size' => 0, 'sig' => ''];
    }

    /** Retire la tâche d'un profil supprimé. */
    public static function forget($name) {
        $j = self::jobs();
        if (isset($j[$name])) {
            unset($j[$name]);
            update_option(self::JOBS, $j, false);
            self::ensure_schedule();
        }
    }

    /* ---------- Planification ---------- */

    /** Prochaine exécution (horodatage) après $from, à l'heure du site. */
    public static function next_run(array $j, $from = null) {
        $tz = wp_timezone();
        $now = (new DateTimeImmutable('@' . (int) ($from ?: time())))->setTimezone($tz);
        $h = max(0, min(23, (int) $j['hour']));
        $freq = in_array($j['freq'], ['daily', 'weekly', 'monthly'], true) ? $j['freq'] : 'weekly';
        $c = $now->setTime($h, 0);
        if ($freq === 'weekly') {
            $diff = (((int) $j['dow']) - (int) $c->format('w') + 7) % 7;
            $c = $c->modify('+' . $diff . ' days')->setTime($h, 0);
        } elseif ($freq === 'monthly') {
            $c = $c->setDate((int) $c->format('Y'), (int) $c->format('n'), max(1, min(28, (int) $j['dom'])))->setTime($h, 0);
        }
        if ($c <= $now) {
            if ($freq === 'daily') $c = $c->modify('+1 day');
            elseif ($freq === 'weekly') $c = $c->modify('+7 days');
            else $c = $c->setDate((int) $c->format('Y'), (int) $c->format('n') + 1, max(1, min(28, (int) $j['dom'])));
            $c = $c->setTime($h, 0);
        }
        return $c->getTimestamp();
    }

    /** Programme (ou retire) le contrôle horaire selon qu'au moins une tâche est active. */
    public static function ensure_schedule() {
        $any = false;
        foreach (self::jobs() as $j) if (!empty($j['on'])) { $any = true; break; }
        $next = wp_next_scheduled(self::HOOK);
        if ($any && !$next) wp_schedule_event(time() + 60, 'hourly', self::HOOK);
        elseif (!$any && $next) wp_clear_scheduled_hook(self::HOOK);
    }

    /** Appelé par WP-Cron : lance, l'une après l'autre, les tâches échues. */
    public static function tick() {
        $jobs = self::jobs();
        $profiles = TNGBook::profiles();
        $now = time();
        $due = [];
        $dirty = false;
        foreach ($jobs as $name => $j) {
            if (!isset($profiles[$name])) { unset($jobs[$name]); $dirty = true; continue; }
            if (!empty($j['on']) && !empty($j['next']) && (int) $j['next'] <= $now) $due[] = $name;
        }
        if ($dirty) update_option(self::JOBS, $jobs, false);
        foreach ($due as $name) self::run($name, false);
    }

    /* ---------- Exécution ---------- */

    /** Génère le livre d'un profil. Retourne [statut ok|fail|skip, message]. */
    public static function run($name, $manual) {
        if (get_transient(self::LOCK)) return ['skip', tngs_t('Une génération est déjà en cours.')];
        set_transient(self::LOCK, 1, 30 * MINUTE_IN_SECONDS);
        try {
            $res = self::do_run($name, $manual);
        } catch (Throwable $e) {
            $res = ['fail', $e->getMessage()];
        }
        delete_transient(self::LOCK);
        $jobs = self::jobs();
        $j = array_merge(self::job_defaults(), $jobs[$name] ?? []);
        $j['last'] = time();
        $j['status'] = $res[0];
        $j['msg'] = (string) $res[1];
        if ($res[0] === 'ok') { $j['file'] = (string) $res[2]; $j['size'] = (int) $res[3]; $j['sig'] = (string) $res[4]; }
        if (!empty($j['on'])) $j['next'] = self::next_run($j);
        $jobs[$name] = $j;
        update_option(self::JOBS, $jobs, false);
        if (!$manual) self::notify($name, $res);
        return $res;
    }

    private static function do_run($name, $manual) {
        $o = TNGBook::profile_options($name);
        if ($o === null) throw new Exception(tngs_t('Profil introuvable.'));
        $s = self::settings();
        $dir = self::dir(true);
        $jobs = self::jobs();
        $old = array_merge(self::job_defaults(), $jobs[$name] ?? []);

        $sig = self::signature($o);
        if (!$manual && $s['only_changed'] && $sig !== '' && $sig === $old['sig'] && $old['file'] !== '' && is_file($dir . '/' . $old['file'])) {
            return ['skip', tngs_t('Données inchangées depuis la dernière génération.')];
        }

        $b = TNGBook::build($o, false, true);
        $ext = $b['format'];
        $base = self::base($name);
        $file = $base . '__' . wp_date('Y-m-d_His') . '.' . $ext;
        $dest = $dir . '/' . $file;
        if ($ext === 'pdf') {
            TNGBook::pdf_output($b['html'], $dest, 'F');
        } elseif ($b['doc'] !== '') {
            if (!@copy($b['doc'], $dest)) { wp_delete_file($b['doc']); throw new Exception(tngs_t('Écriture impossible dans le dossier de destination.')); }
            wp_delete_file($b['doc']);
        } elseif (file_put_contents($dest, $b['html']) === false) {
            throw new Exception(tngs_t('Écriture impossible dans le dossier de destination.'));
        }
        if (!is_file($dest) || filesize($dest) < 100) throw new Exception(tngs_t('Le fichier généré est vide.'));
        self::rotate($dir, $base, (int) $s['keep']);
        return ['ok', tngs_t('Livre généré.'), $file, (int) filesize($dest), $sig];
    }

    /** Empreinte des données du livre : réglages du profil, version du plugin, effectif et dernière modification des tables TNG. */
    private static function signature(array $o) {
        $db = TNGBook::db();
        if (is_wp_error($db)) return '';
        $p = esc_sql($o['prefix']);
        $tree = $o['tree'] !== '' ? $o['tree'] : tngs_gedcom();
        $parts = [TNGSUITE_VERSION, md5((string) wp_json_encode($o))];
        foreach (['people', 'families', 'media'] as $t) {
            $sql = 'SELECT COUNT(*), MAX(changedate) FROM `' . $p . $t . '`' . ($tree !== '' ? ' WHERE gedcom = %s' : '');
            $db->suppress_errors(true);
            $r = $db->get_row($tree !== '' ? $db->prepare($sql, $tree) : $sql, ARRAY_N);
            $db->suppress_errors(false);
            $parts[] = $r ? implode('|', $r) : 'x';
        }
        return md5(implode('~', $parts));
    }

    private static function base($name) {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_accents($name))), '-');
        return ($slug !== '' ? substr($slug, 0, 40) : 'livre') . '-' . substr(md5($name), 0, 6);
    }

    private static function pattern($base) {
        return '/^' . preg_quote($base, '/') . '__\d{4}-\d{2}-\d{2}_\d{6}\.(' . implode('|', self::EXTS) . ')$/';
    }

    /** Fichiers d'un profil, du plus récent au plus ancien. */
    public static function files($name) {
        $dir = self::dir(false);
        if (!is_dir($dir)) return [];
        $out = [];
        foreach ((array) scandir($dir) as $f) if (preg_match(self::pattern(self::base($name)), $f)) $out[] = $f;
        rsort($out);
        return $out;
    }

    /** Ne garde que les $keep derniers fichiers du profil (et uniquement ceux qui portent le nom généré par le plugin). */
    private static function rotate($dir, $base, $keep) {
        $all = [];
        foreach ((array) scandir($dir) as $f) if (preg_match(self::pattern($base), $f)) $all[] = $f;
        rsort($all);
        foreach (array_slice($all, $keep) as $f) wp_delete_file($dir . '/' . $f);
    }

    /* ---------- Dossier de destination ---------- */

    public static function default_dir() {
        $key = (string) get_option(self::KEY, '');
        if ($key === '') {
            $key = strtolower(wp_generate_password(16, false, false));
            update_option(self::KEY, $key, false);
        }
        $u = wp_upload_dir();
        return wp_normalize_path($u['basedir']) . '/tngbook-auto-' . $key;
    }

    public static function dir($create = false) {
        $s = self::settings();
        $d = trim((string) $s['dir']);
        $d = rtrim(wp_normalize_path($d !== '' ? $d : self::default_dir()), '/');
        if ($create) {
            if (!is_dir($d) && !wp_mkdir_p($d)) throw new Exception(tngs_t('Impossible de créer le dossier de destination :') . ' ' . $d);
            if (!is_writable($d)) throw new Exception(tngs_t('Le dossier de destination n’est pas accessible en écriture :') . ' ' . $d);
            if (!file_exists($d . '/index.php')) @file_put_contents($d . '/index.php', "<?php\n// Silence is golden.\n");
            if (!file_exists($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        return $d;
    }

    /** Vérifie un dossier saisi par l'administrateur. Retourne '' si correct, sinon le motif du refus. */
    private static function check_dir($d) {
        $d = rtrim(wp_normalize_path($d), '/');
        if (!preg_match('#^(/|[A-Za-z]:/)#', $d)) return tngs_t('Indiquez un chemin absolu sur le serveur.');
        if (preg_match('#(^|/)\.\.(/|$)#', $d)) return tngs_t('Le chemin ne doit pas contenir « .. ».');
        $abs = rtrim(wp_normalize_path(ABSPATH), '/');
        foreach ([$abs, $abs . '/wp-admin', $abs . '/wp-includes', rtrim(wp_normalize_path(WP_PLUGIN_DIR), '/'), rtrim(wp_normalize_path(TNGSUITE_DIR), '/')] as $bad) {
            if ($d === $bad || strpos($d . '/', $bad . '/') === 0) {
                // Seul le sous-dossier wp-content (hors plugin) est admis à l'intérieur de WordPress.
                if ($bad === $abs && strpos($d . '/', $abs . '/wp-content/') === 0 && strpos($d . '/', rtrim(wp_normalize_path(TNGSUITE_DIR), '/') . '/') !== 0) continue;
                return tngs_t('Ce dossier est réservé à WordPress ou au plugin : choisissez-en un autre.');
            }
        }
        if (!is_dir($d) && !wp_mkdir_p($d)) return tngs_t('Impossible de créer ce dossier.');
        if (!is_writable($d)) return tngs_t('Ce dossier n’est pas accessible en écriture.');
        return '';
    }

    /* ---------- E-mail ---------- */

    private static function notify($name, array $res) {
        $s = self::settings();
        $to = sanitize_email($s['mail']);
        if ($to === '' || $s['mail_on'] === 'never' || $res[0] === 'skip') return;
        if ($res[0] === 'ok' && $s['mail_on'] !== 'always') return;
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $ok = $res[0] === 'ok';
        $subject = '[' . $site . '] ' . ($ok ? tngs_t('Livre généré') : tngs_t('Échec de la génération du livre')) . ' — ' . $name;
        $body = tngs_t('Profil :') . ' ' . $name . "\n" . tngs_t('Résultat :') . ' ' . $res[1] . "\n";
        if ($ok) $body .= tngs_t('Fichier :') . ' ' . $res[2] . ' (' . size_format((int) $res[3]) . ")\n" . tngs_t('Dossier :') . ' ' . self::dir(false) . "\n";
        wp_mail($to, $subject, $body);
    }

    /* ---------- Actions d'administration ---------- */

    private static function guard($action) {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        if (!isset($_REQUEST['_tngauto']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_tngauto'])), $action)) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
    }

    private static function back($msg, $page = 'tngbook-settings') {
        wp_safe_redirect(add_query_arg(['page' => 'tngbook', 'tngbook_msg' => $msg], admin_url('admin.php')) . '#' . $page);
        exit;
    }

    /** Réglages communs (dossier, rotation, e-mail). La planification de chaque profil se règle dans le profil lui-même. */
    public static function save() {
        self::guard('tngbook_auto');
        $in = (isset($_POST['tngbook_auto']) && is_array($_POST['tngbook_auto'])) ? wp_unslash($_POST['tngbook_auto']) : [];
        $msg = 'auto_saved';
        $set = (array) ($in['settings'] ?? []);
        $cur = self::settings();
        $dir = trim(sanitize_text_field((string) ($set['dir'] ?? '')));
        if ($dir !== '') {
            $why = self::check_dir($dir);
            if ($why !== '') { $dir = $cur['dir']; $msg = 'auto_dir'; set_transient('tngbook_auto_why', $why, 60); }
        }
        update_option(self::OPT, [
            'dir' => $dir,
            'keep' => max(1, min(30, (int) ($set['keep'] ?? 4))),
            'only_changed' => empty($set['only_changed']) ? 0 : 1,
            'mail' => sanitize_email((string) ($set['mail'] ?? '')),
            'mail_on' => in_array($set['mail_on'] ?? '', ['never', 'fail', 'always'], true) ? $set['mail_on'] : 'fail',
        ], false);

        self::ensure_schedule();
        self::back($msg);
    }

    /**
     * Enregistre la planification du profil $name à partir du formulaire du livre (appelé par l'enregistrement du profil, jeton déjà vérifié).
     */
    public static function save_profile_job($name) {
        if (!isset($_POST['tngbook_auto_job']) || !is_array($_POST['tngbook_auto_job'])) return;   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- jeton vérifié par TNGBook::profile_save()
        $row = wp_unslash($_POST['tngbook_auto_job']);                                              // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $jobs = self::jobs();
        $j = array_merge(self::job_defaults(), $jobs[$name] ?? []);
        $j['on'] = empty($row['on']) ? 0 : 1;
        $j['freq'] = in_array($row['freq'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $row['freq'] : 'weekly';
        $j['dow'] = max(0, min(6, (int) ($row['dow'] ?? 1)));
        $j['dom'] = max(1, min(28, (int) ($row['dom'] ?? 1)));
        $j['hour'] = max(0, min(23, (int) ($row['hour'] ?? 3)));
        $j['next'] = $j['on'] ? self::next_run($j) : 0;
        $jobs[$name] = $j;
        update_option(self::JOBS, $jobs, false);
        self::ensure_schedule();
    }

    /** « Chaque semaine, le lundi à 03 h » : description lisible d'une planification. */
    public static function describe(array $j) {
        if (empty($j['on'])) return tngs_t('Désactivée');
        $days = [0 => 'Dimanche', 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'];
        $h = sprintf('%02d h', (int) $j['hour']);
        if ($j['freq'] === 'daily') return tngs_t('Chaque jour') . ' ' . tngs_t('à') . ' ' . $h;
        if ($j['freq'] === 'monthly') return tngs_t('Chaque mois') . ', ' . tngs_t('le') . ' ' . (int) $j['dom'] . ' ' . tngs_t('à') . ' ' . $h;
        return tngs_t('Chaque semaine') . ', ' . tngs_t($days[(int) $j['dow']] ?? 'Lundi') . ' ' . tngs_t('à') . ' ' . $h;
    }

    /** Section du formulaire du livre : planification du profil actif (enregistrée avec « Enregistrer comme profil » / « Mettre à jour le profil actif »). */
    public static function profile_fields($active) {
        $jobs = self::jobs();
        $j = array_merge(self::job_defaults(), ($active !== '' ? ($jobs[$active] ?? []) : []));
        $days = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche'];
        $k = 'tngbook_auto_job';
        echo '<h2 id="tngbook-auto-job">' . esc_html(tngs_t('Génération automatique de ce profil')) . ' <span style="background:#dba617;color:#1d2327;font-size:11px;padding:2px 7px;border-radius:9px;vertical-align:middle">' . esc_html(tngs_t('Bêta')) . '</span></h2>';
        echo '<p class="description">' . esc_html($active !== ''
            ? tngs_t('Profil actif :') . ' ' . $active . ' — ' . tngs_t('ces réglages sont enregistrés avec le profil (bouton « Mettre à jour le profil actif »).')
            : tngs_t('Ces réglages sont enregistrés avec le profil : donnez un nom au profil, puis « Enregistrer comme profil ».')) . '</p>';
        echo '<table class="form-table" data-tngjob><tr><th scope="row">' . esc_html(tngs_t('Générer automatiquement')) . '</th><td><label><input type="checkbox" name="' . esc_attr($k) . '[on]" value="1"' . checked(!empty($j['on']), true, false) . '> '
            . esc_html(tngs_t('Générer le livre de ce profil à intervalle régulier')) . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html(tngs_t('Fréquence')) . '</th><td><select name="' . esc_attr($k) . '[freq]" class="tngauto-freq">';
        foreach (['daily' => 'Chaque jour', 'weekly' => 'Chaque semaine', 'monthly' => 'Chaque mois'] as $v => $l) echo '<option value="' . esc_attr($v) . '"' . selected($j['freq'], $v, false) . '>' . esc_html(tngs_t($l)) . '</option>';
        echo '</select> <select name="' . esc_attr($k) . '[dow]" class="tngauto-dow">';
        foreach ($days as $v => $l) echo '<option value="' . (int) $v . '"' . selected((int) $j['dow'], $v, false) . '>' . esc_html(tngs_t($l)) . '</option>';
        echo '</select><span class="tngauto-dom"><input type="number" min="1" max="28" name="' . esc_attr($k) . '[dom]" value="' . (int) $j['dom'] . '" class="small-text"> ' . esc_html(tngs_t('(jour du mois, de 1 à 28)')) . '</span> '
            . esc_html(tngs_t('à')) . ' <select name="' . esc_attr($k) . '[hour]">';
        for ($h = 0; $h < 24; $h++) echo '<option value="' . (int) $h . '"' . selected((int) $j['hour'], $h, false) . '>' . sprintf('%02d h', $h) . '</option>';
        echo '</select>';
        if (!empty($j['on']) && $j['next']) echo '<p class="description">' . esc_html(tngs_t('Prochaine génération :')) . ' ' . esc_html(wp_date('d/m/Y H:i', (int) $j['next'])) . '</p>';
        echo '<p class="description">' . esc_html(tngs_t('Le dossier de destination, le nombre de fichiers conservés et l’e-mail sont communs à tous les profils : voir l’onglet « Réglages ».')) . '</p></td></tr></table>';
        echo '<script>(function(){var t=document.querySelector("[data-tngjob]");if(!t)return;var f=t.querySelector(".tngauto-freq"),w=t.querySelector(".tngauto-dow"),d=t.querySelector(".tngauto-dom");function u(){w.style.display=f.value==="weekly"?"":"none";d.style.display=f.value==="monthly"?"":"none";}f.addEventListener("change",u);u();})();</script>';
    }

    /** « Générer maintenant » : exécution immédiate pour un profil (ignore l'option « seulement si modifié »). */
    public static function now() {
        self::guard('tngbook_auto');
        $name = sanitize_text_field(wp_unslash((string) ($_POST['now'] ?? '')));
        if ($name === '' || !isset(TNGBook::profiles()[$name])) self::back('missing', 'tngbook-overview');
        $res = self::run($name, true);
        self::back('auto_' . $res[0], 'tngbook-overview');
    }

    /** Téléchargement d'un livre généré (le dossier peut se trouver hors du site). */
    public static function download() {
        self::guard('tngbook_auto');
        $f = isset($_GET['f']) ? sanitize_text_field(wp_unslash($_GET['f'])) : '';
        if (!preg_match('/^[a-z0-9-]+__\d{4}-\d{2}-\d{2}_\d{6}\.(' . implode('|', self::EXTS) . ')$/', $f, $m)) wp_die(esc_html(tngs_t('Fichier introuvable.')), 404);
        $path = self::dir(false) . '/' . $f;
        if (!is_file($path)) wp_die(esc_html(tngs_t('Fichier introuvable.')), 404);
        $types = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text', 'html' => 'text/html; charset=utf-8'];
        nocache_headers();
        header('Content-Type: ' . $types[$m[1]]);
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /* ---------- Page d'administration ---------- */

    /** Bandeau de bêta + notes de retour d'action. */
    private static function notices(array $keys) {
        $notes = ['auto_saved' => ['success', 'Réglages communs de la génération automatique enregistrés.'], 'auto_ok' => ['success', 'Livre généré : voir la colonne « Dernière génération ».'],
            'auto_fail' => ['error', 'La génération a échoué : voir le message dans la colonne « Dernière génération ».'], 'auto_skip' => ['info', 'Génération non lancée : voir le message dans la colonne « Dernière génération ».'],
            'auto_dir' => ['warning', 'Dossier refusé : l’ancien réglage est conservé.'], 'missing' => ['warning', 'Profil introuvable.']];
        $m = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (in_array($m, $keys, true) && isset($notes[$m])) {
            echo '<div class="notice notice-' . esc_attr($notes[$m][0]) . ' is-dismissible"><p>' . esc_html(tngs_t($notes[$m][1]));
            if ($m === 'auto_dir' && ($why = get_transient('tngbook_auto_why'))) echo ' ' . esc_html($why);
            echo '</p></div>';
        }
    }

    private static function beta_badge() {
        return ' <span style="background:#dba617;color:#1d2327;font-size:11px;padding:2px 7px;border-radius:9px;vertical-align:middle">' . esc_html(tngs_t('Bêta')) . '</span>';
    }

    /** Onglet « Vue d'ensemble des profils » : tableau en lecture seule (planification, dernière génération, générer maintenant, modifier). */
    public static function overview() {
        $profiles = TNGBook::profiles();
        $jobs = self::jobs();
        echo '<div id="tngbook-overview"><h2>' . esc_html(tngs_t('Vue d’ensemble des profils')) . self::beta_badge() . '</h2>';
        self::notices(['auto_ok', 'auto_fail', 'auto_skip', 'missing']);
        echo '<p class="description">' . esc_html(tngs_t('Génère automatiquement le livre des profils pour lesquels la génération automatique est activée, à intervalle régulier, dans un dossier du serveur (les anciens fichiers sont supprimés au-delà du nombre conservé).')) . '</p>';
        if (!$profiles) {
            echo '<p>' . esc_html(tngs_t('Aucun profil pour le moment. Réglez un livre dans l’onglet « Générer un livre », puis enregistrez-le comme profil : la génération automatique se règle dans chaque profil.')) . '</p></div>';
            return;
        }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(tngs_t('Le planificateur interne de WordPress est désactivé sur ce site : vérifiez qu’une tâche cron du serveur appelle bien wp-cron.php (par exemple toutes les heures).')) . '</p></div>';
        } else {
            echo '<p class="description">' . esc_html(tngs_t('Le planificateur de WordPress ne s’exécute que lorsque le site est visité : l’heure choisie est donc approximative. Pour une heure précise, programmez une tâche cron du serveur qui appelle wp-cron.php.')) . '</p>';
        }
        echo '<p class="description">' . esc_html(tngs_t('La planification se règle dans chaque profil : cliquez sur « Modifier », réglez « Génération automatique de ce profil », puis « Mettre à jour le profil actif ».')) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="_tngauto" value="' . esc_attr(wp_create_nonce('tngbook_auto')) . '">';
        echo '<input type="hidden" name="tngbook_pnonce" value="' . esc_attr(wp_create_nonce('tngbook_profile')) . '">';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html(tngs_t('Profil')) . '</th><th>' . esc_html(tngs_t('Planification')) . '</th><th>' . esc_html(tngs_t('Dernière génération')) . '</th><th></th></tr></thead><tbody>';
        ksort($profiles, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($profiles as $name => $prof) {
            $j = array_merge(self::job_defaults(), $jobs[$name] ?? []);
            echo '<tr><td><strong>' . esc_html($name) . '</strong><br><span class="description">' . esc_html(strtoupper((string) ($prof['format'] ?? 'html'))) . '</span>';
            if (($prof['format'] ?? '') === 'pdf' && !TNGBook::mpdf_available()) echo '<br><span style="color:#b32d2e">' . esc_html(tngs_t('PDF direct indisponible (mPDF absent) : la génération échouera.')) . '</span>';
            echo '</td><td>' . (!empty($j['on']) ? '<span style="color:#1a7f37">●</span> ' : '<span style="color:#8c8f94">●</span> ') . esc_html(self::describe($j));
            if (!empty($j['on']) && $j['next']) echo '<br><span class="description">' . esc_html(tngs_t('Prochaine :')) . ' ' . esc_html(wp_date('d/m/Y H:i', (int) $j['next'])) . '</span>';
            echo '</td><td>';
            if ($j['last']) {
                $col = $j['status'] === 'ok' ? '#1a7f37' : ($j['status'] === 'fail' ? '#b32d2e' : '#6b6b6b');
                echo '<span style="color:' . esc_attr($col) . '">●</span> ' . esc_html(wp_date('d/m/Y H:i', (int) $j['last'])) . ' — ' . esc_html($j['msg']);
            } else {
                echo '<span class="description">' . esc_html(tngs_t('Jamais')) . '</span>';
            }
            $files = self::files($name);
            if ($files) {
                $url = wp_nonce_url(add_query_arg(['action' => 'tngbook_auto_dl', 'f' => $files[0]], admin_url('admin-post.php')), 'tngbook_auto', '_tngauto');
                echo '<br><a href="' . esc_url($url) . '">' . esc_html($files[0]) . '</a>' . ($j['size'] && $files[0] === $j['file'] ? ' (' . esc_html(size_format((int) $j['size'])) . ')' : '') . ' <span class="description">· ' . (int) count($files) . ' ' . esc_html(tngs_t('fichier(s) conservé(s)')) . '</span>';
            }
            echo '</td><td style="white-space:nowrap"><button type="submit" class="button" name="now" value="' . esc_attr($name) . '" formaction="' . esc_url(admin_url('admin-post.php?action=tngbook_auto_now')) . '">' . esc_html(tngs_t('Générer maintenant')) . '</button> '
                . '<button type="submit" class="button" name="tngbook_profile" value="' . esc_attr($name) . '" formaction="' . esc_url(admin_url('admin-post.php?action=tngbook_profile_apply')) . '">' . esc_html(tngs_t('Modifier')) . '</button></td></tr>';
        }
        echo '</tbody></table></form></div>';
    }

    /** Réglages communs à tous les profils (dossier, rotation, e-mail), à placer dans l'onglet « Réglages ». */
    public static function settings_form() {
        $s = self::settings();
        echo '<div id="tngbook-auto" style="margin-top:28px"><h2>' . esc_html(tngs_t('Génération automatique')) . self::beta_badge() . '</h2>';
        self::notices(['auto_saved', 'auto_dir']);
        echo '<p class="description">' . esc_html(tngs_t('Réglages communs à tous les profils. La planification (fréquence, jour, heure) se règle dans chaque profil.')) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="_tngauto" value="' . esc_attr(wp_create_nonce('tngbook_auto')) . '">';
        echo '<table class="form-table" style="max-width:1100px">';
        echo '<tr><th scope="row">' . esc_html(tngs_t('Dossier de destination')) . '</th><td><input type="text" class="large-text code" name="tngbook_auto[settings][dir]" value="' . esc_attr($s['dir']) . '" placeholder="' . esc_attr(self::default_dir()) . '">'
            . '<p class="description">' . esc_html(tngs_t('Chemin absolu sur le serveur. Vide = dossier protégé dans les téléversements de WordPress (nom aléatoire). Idéalement, choisissez un dossier situé hors du site web. Seuls les fichiers créés par le plugin peuvent être supprimés.')) . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html(tngs_t('Fichiers conservés par profil')) . '</th><td><input type="number" min="1" max="30" class="small-text" name="tngbook_auto[settings][keep]" value="' . (int) $s['keep'] . '"></td></tr>';
        echo '<tr><th scope="row">' . esc_html(tngs_t('Seulement si les données ont changé')) . '</th><td><label><input type="checkbox" name="tngbook_auto[settings][only_changed]" value="1"' . checked(!empty($s['only_changed']), true, false) . '> '
            . esc_html(tngs_t('Ne pas régénérer si rien n’a été modifié dans la base TNG (personnes, familles, médias) ni dans le profil depuis la dernière fois.')) . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html(tngs_t('E-mail')) . '</th><td><input type="email" class="regular-text" name="tngbook_auto[settings][mail]" value="' . esc_attr($s['mail']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '"> <select name="tngbook_auto[settings][mail_on]">';
        foreach (['fail' => 'En cas d’échec seulement', 'always' => 'À chaque génération', 'never' => 'Jamais'] as $v => $l) echo '<option value="' . esc_attr($v) . '"' . selected($s['mail_on'], $v, false) . '>' . esc_html(tngs_t($l)) . '</option>';
        echo '</select></td></tr></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary" name="action" value="tngbook_auto_save">' . esc_html(tngs_t('Enregistrer la génération automatique')) . '</button></p></form>';
        echo '</div>';
    }
}

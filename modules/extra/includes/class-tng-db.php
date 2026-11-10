<?php
if (!defined('ABSPATH')) exit;

/**
 * Accès à la base TNG pour ce module. La connexion, le préfixe et la gestion des
 * pannes sont fournis par le cœur de TNG Suite (tngs_db(), tngs_prefix()).
 */
class TNG_Extra_DB {

    private static $message = '';

    /** Retourne la connexion TNG commune ou null en cas d'échec. */
    public static function get($force = false) {
        $db = tngs_db($force);
        if (!$db) {
            self::$message = tngs_db_error_message();
            return null;
        }
        return $db;
    }

    /** Exécute une requête déjà préparée. Tableau (éventuellement vide) ou null en cas d'erreur. */
    public static function results($sql) {
        $db = self::get();
        if (!$db) {
            return null;
        }

        $rows = $db->get_results($sql);

        if ($rows === null || $db->last_error !== '') {
            self::log('Erreur de requête : ' . $db->last_error);
            self::$message = __('Erreur de requête TNG (voir le journal PHP).', 'tng-suite');
            return null;
        }
        return $rows;
    }

    /** Nom de table protégé : `prefixe` + nom (préfixe validé par le cœur). */
    public static function table($name) {
        return '`' . tngs_prefix() . $name . '`';
    }

    /** Dernier message d'erreur lisible (jamais le détail brut de MySQL). */
    public static function message() {
        return self::$message !== '' ? self::$message : __('Données TNG indisponibles.', 'tng-suite');
    }

    /** Invalide tout le cache du module (changement de « génération »). */
    public static function flush_cache() {
        update_option('tng_exsc_gen', (int) get_option('tng_exsc_gen', 1) + 1);
    }

    public static function log($message) {
        error_log('[TNG Suite / Extra] ' . $message);
    }
}

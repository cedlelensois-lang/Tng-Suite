<?php
if (!defined('ABSPATH')) exit;

/**
 * Pays des lieux, hors connexion : table interne de pays (noms en fr/en/es/ca) et indices infranationaux
 * (départements français, provinces espagnoles et belges, États américains, provinces canadiennes…).
 */
final class TNGBook_Country {
    private static $D, $alias, $sub, $reg, $langs = ['fr' => 0, 'en' => 1, 'es' => 2, 'ca' => 3];

    private static function load() {
        if (self::$D) return;
        self::$D = require TNGBOOK_DIR . 'includes/countries.php';
        self::$alias = []; self::$sub = [];
        foreach (self::$D['countries'] as $code => $r) {
            for ($i = 0; $i < 4; $i++) self::$alias[TNGBook_Map::norm($r[$i])] = $code;
            foreach (explode('|', $r[4]) as $a) if ($a !== '') self::$alias[TNGBook_Map::norm($a)] = $code;
        }
        self::$reg = [];
        foreach (require TNGBOOK_DIR . 'includes/regions-names.php' as $r) {
            for ($i = 1; $i <= 4; $i++) self::$reg[TNGBook_Map::norm($r[$i])][$r[0]] = $r;
            foreach (explode('|', $r[5]) as $a) if ($a !== '') self::$reg[TNGBook_Map::norm($a)][$r[0]] = $r;
        }
        foreach (self::$D['sub'] as $code => $list) foreach (explode('|', $list) as $a) if ($a !== '') self::$sub[TNGBook_Map::norm($a)] = $code;
    }

    /** Nom du pays dans la langue demandée (français par défaut). */
    public static function name($code, $lang) {
        self::load();
        $r = self::$D['countries'][$code] ?? null;
        return $r ? $r[self::$langs[$lang] ?? 0] : '';
    }

    /** Code pays d'un texte de pays seul (« Allemagne », « Germany », « USA »…), ou null. */
    public static function code($text) {
        self::load();
        $n = TNGBook_Map::norm($text);
        return $n !== '' && isset(self::$alias[$n]) ? self::$alias[$n] : null;
    }

    /**
     * Analyse un lieu : retourne ['code' => pays|null, 'rest' => segments sans le pays, 'explicit' => le pays était écrit].
     */
    public static function analyse($place) {
        self::load();
        $segs = array_values(array_filter(array_map('trim', explode(',', (string) $place)), 'strlen'));
        $res = ['code' => null, 'rest' => $segs, 'explicit' => false];
        if (!$segs) return $res;
        $norm = array_map(['TNGBook_Map', 'norm'], $segs);
        // 1. un segment est un pays (de droite à gauche) ; « Géorgie / Georgia » seul reste un pays, accompagné d'une ville c'est un État américain
        for ($i = count($segs) - 1; $i >= 0; $i--) {
            if (!isset(self::$alias[$norm[$i]])) continue;
            $c = self::$alias[$norm[$i]];
            if ($c === 'GE' && count($segs) > 1 && $norm[$i] === 'georgia') { $res['code'] = 'US'; return $res; }
            $rest = $segs; array_splice($rest, $i, 1);
            return ['code' => $c, 'rest' => $rest, 'explicit' => true];
        }
        // 2. territoire infranational connu (État, province, nation du Royaume-Uni…)
        for ($i = count($segs) - 1; $i >= 0; $i--) {
            if (isset(self::$sub[$norm[$i]])) { $res['code'] = self::$sub[$norm[$i]]; return $res; }
        }
        // 3. département français, province espagnole ou belge, code postal
        $c = TNGBook_Map::make()->countryOf($place);
        if ($c) $res['code'] = $c;
        return $res;
    }

    /** Nom d'une région / d'un État / d'une province dans la langue demandée, ou null si elle n'est pas connue. */
    public static function region($text, $lang, $code = null) {
        self::load();
        $n = TNGBook_Map::norm($text);
        if ($n === '' || !isset(self::$reg[$n])) return null;
        $c = self::$reg[$n];
        if ($code !== null && isset($c[$code])) $r = $c[$code];
        elseif ($code === null && count($c) === 1) $r = reset($c);
        else return null;
        return $r[1 + (self::$langs[$lang] ?? 0)];
    }

    /** Libellé d'un pays de la carte du monde : traduit si le pays est connu, sinon le libellé d'origine. */
    public static function label($key, $label, $lang) {
        $c = self::code($key) ?: self::code($label);
        return $c ? self::name($c, $lang) : TNGBook_Map::nice($label);
    }
}

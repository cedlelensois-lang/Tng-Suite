<?php
/**
 * Contexte historique : listes de faits (événements datés et périodes : guerres, révolutions, épidémies, grandes lois…). Neuf listes modèles
 * sont fournies (textes français et anglais) ; l'utilisateur crée les siennes, dans la langue de son choix, avec des mots-clés de lieu qui
 * rattachent chaque liste aux personnes (lieu de naissance, ou de baptême). Le livre choisit les listes à utiliser.
 */
if (!defined('ABSPATH')) exit;

class TNGBook_History {
    /** Pays disponibles : code => [nom français, nom anglais]. */
    public static function countries() {
        return [
            'FR' => ['France', 'France'], 'BE' => ['Belgique', 'Belgium'], 'CH' => ['Suisse', 'Switzerland'], 'ES' => ['Espagne', 'Spain'],
            'IT' => ['Italie', 'Italy'], 'DE' => ['Allemagne', 'Germany'], 'GB' => ['Royaume-Uni', 'United Kingdom'],
            'US' => ['États-Unis', 'United States'], 'CA' => ['Canada', 'Canada'],
        ];
    }

    public static function country_name($code, $lang) {
        $c = self::countries();
        if (!isset($c[$code])) return $code;
        return $lang === 'fr' ? $c[$code][0] : $c[$code][1];
    }

    public static function norm($s) {
        $s = function_exists('remove_accents') ? remove_accents((string) $s) : (string) $s;
        $s = strtolower(trim($s));
        return trim(preg_replace('/[^a-z]+/', ' ', $s));
    }

    /** Mots désignant chaque pays modèle (forme normalisée : minuscules, sans accent) ; '' = pays connus sans liste fournie. */
    private static function aliases() {
        return [
            'FR' => ['france', 'republique francaise', 'algerie', 'algeria', 'corse', 'guadeloupe', 'martinique', 'reunion', 'nouvelle caledonie', 'polynesie francaise'],
            'BE' => ['belgique', 'belgium', 'belgie', 'belgica', 'belgien'],
            'CH' => ['suisse', 'switzerland', 'schweiz', 'svizzera', 'swiss'],
            'ES' => ['espagne', 'spain', 'espana', 'espanya'],
            'IT' => ['italie', 'italy', 'italia'],
            'DE' => ['allemagne', 'germany', 'deutschland', 'prusse', 'prussia', 'baviere', 'saxe', 'westphalie'],
            'GB' => ['royaume uni', 'united kingdom', 'uk', 'u k', 'angleterre', 'england', 'ecosse', 'scotland', 'pays de galles', 'wales', 'grande bretagne', 'great britain', 'irlande du nord', 'northern ireland'],
            'US' => ['etats unis', 'etats unis d amerique', 'usa', 'u s a', 'us', 'united states', 'united states of america'],
            'CA' => ['canada', 'quebec', 'nouveau brunswick', 'new brunswick', 'nova scotia', 'nouvelle ecosse', 'ontario', 'acadie', 'acadia', 'manitoba', 'saskatchewan', 'alberta', 'colombie britannique', 'british columbia', 'terre neuve', 'newfoundland', 'ile du prince edouard', 'prince edward island', 'yukon'],
            '' => ['pologne', 'poland', 'portugal', 'luxembourg', 'pays bas', 'hollande', 'netherlands', 'autriche', 'austria', 'russie', 'russia', 'irlande', 'ireland',
                'tunisie', 'maroc', 'grece', 'greece', 'roumanie', 'hongrie', 'tchequie', 'tchecoslovaquie', 'ukraine', 'danemark', 'suede', 'norvege', 'finlande', 'turquie',
                'bresil', 'argentine', 'mexique', 'chine', 'japon', 'inde', 'australie', 'liban', 'syrie', 'egypte', 'senegal', 'vietnam', 'cambodge', 'madagascar', 'armenie', 'bulgarie', 'serbie', 'croatie', 'slovenie', 'lituanie', 'lettonie', 'estonie', 'chili', 'perou', 'cuba', 'haiti'],
        ];
    }

    /* ---------- Listes de faits historiques ---------- */

    const OPT = 'tngbook_history_lists';

    /** Listes de l'utilisateur : id => [name, match, text]. */
    public static function user_lists() {
        $l = get_option(self::OPT, []);
        $out = [];
        if (is_array($l)) foreach ($l as $id => $x) {
            if (!is_array($x) || !preg_match('/^u[a-z0-9]{4,12}$/', (string) $id)) continue;
            $out[$id] = ['name' => (string) ($x['name'] ?? ''), 'match' => (string) ($x['match'] ?? ''), 'text' => (string) ($x['text'] ?? '')];
        }
        return $out;
    }

    public static function save_user_lists(array $lists) {
        update_option(self::OPT, $lists, false);
    }

    /** Identifiants des listes modèles (« tpl-FR »…). */
    public static function template_ids() {
        return array_map(function ($c) { return 'tpl-' . $c; }, array_keys(self::countries()));
    }

    /** Choix proposés dans le formulaire du livre : id => libellé (listes de l'utilisateur d'abord). */
    public static function choices($lang) {
        $out = [];
        foreach (self::user_lists() as $id => $x) $out[$id] = $x['name'] !== '' ? $x['name'] : $id;
        foreach (array_keys(self::countries()) as $c) $out['tpl-' . $c] = self::country_name($c, $lang);
        return $out;
    }

    public static function is_known($id) {
        return isset(self::choices('fr')[$id]);
    }

    /** Mots-clés de lieu : « Catalunya, Cataluña » => formes normalisées. */
    public static function keywords($text) {
        $out = [];
        foreach (explode(',', (string) $text) as $w) {
            $n = self::norm($w);
            if ($n !== '') $out[$n] = $n;
        }
        return array_values($out);
    }

    /** Date AAAA, AAAA-MM ou AAAA-MM-JJ => [année, mois, jour] (0 = inconnu) ou null. */
    private static function date3($t) {
        if (!preg_match('/^(\d{3,4})(?:-(\d{1,2}))?(?:-(\d{1,2}))?$/', trim($t), $m)) return null;
        $mo = (int) ($m[2] ?? 0);
        $da = (int) ($m[3] ?? 0);
        if ($mo > 12 || $da > 31 || ($da && !$mo)) return null;
        return [(int) $m[1], $mo, $da];
    }

    /**
     * Lit les entrées d'une liste : une par ligne, « date | texte » (événement) ou « date .. date | texte » (période : guerre, régime…, avec
     * son article, ex. « la Première Guerre mondiale »). Date : AAAA, AAAA-MM ou AAAA-MM-JJ. Les lignes vides ou commençant par # sont ignorées.
     * Retourne ['events' => [[y, m, d, texte]], 'periods' => [[début, fin, nom]]].
     */
    public static function parse_entries($text) {
        $ev = [];
        $pe = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $f = array_map('trim', explode('|', $line));
            if (count($f) < 2 || $f[1] === '') continue;
            $r = preg_split('/\s*(?:\.\.|→|—|–|\/)\s*/u', $f[0]);
            if (count($r) === 2 && ($a = self::date3($r[0])) && ($b = self::date3($r[1]))) {
                $pe[] = [self::fmt($a), self::fmt($b), $f[1]];
            } elseif (count($r) === 1 && ($a = self::date3($f[0]))) {
                $ev[] = [$a[0], $a[1], $a[2], $f[1]];
            }
        }
        return ['events' => $ev, 'periods' => $pe];
    }

    private static function fmt(array $a) {
        return $a[1] ? sprintf($a[2] ? '%04d-%02d-%02d' : '%04d-%02d', $a[0], $a[1], $a[2]) : sprintf('%04d', $a[0]);
    }

    /** Texte d'une liste modèle dans une langue (« fr » ou « en ») : format de saisie, prêt à être copié. */
    public static function template_text($code, $lang) {
        $fr = $lang === 'fr';
        $o = [];
        foreach (self::periods($code) as $p) $o[] = $p[0] . ' .. ' . $p[1] . ' | ' . ($fr ? $p[2] : $p[3]);
        foreach (self::events($code) as $e) $o[] = self::fmt([$e[0], $e[1], $e[2]]) . ' | ' . ($fr ? $e[3] : $e[4]);
        return implode("\n", $o);
    }

    /**
     * Listes choisies pour un livre, prêtes à l'emploi, dans l'ordre choisi : [id => ['id', 'name', 'match' (mots-clés), 'events', 'periods']].
     * Les textes des modèles suivent la langue du livre ; ceux des listes de l'utilisateur sont pris tels quels.
     */
    public static function resolve($ids, $lang) {
        $user = self::user_lists();
        $sets = [];
        foreach ((array) $ids as $id) {
            $id = (string) $id;
            if (isset($sets[$id])) continue;
            if (isset($user[$id])) {
                $p = self::parse_entries($user[$id]['text']);
                $sets[$id] = ['id' => $id, 'name' => $user[$id]['name'], 'match' => self::keywords($user[$id]['match']), 'events' => $p['events'], 'periods' => $p['periods']];
            } elseif (strpos($id, 'tpl-') === 0 && isset(self::countries()[substr($id, 4)])) {
                $c = substr($id, 4);
                $fr = $lang === 'fr';
                $ev = [];
                foreach (self::events($c) as $e) $ev[] = [$e[0], $e[1], $e[2], $fr ? $e[3] : $e[4]];
                $pe = [];
                foreach (self::periods($c) as $p) $pe[] = [$p[0], $p[1], $fr ? $p[2] : $p[3]];
                $al = self::aliases();
                $sets[$id] = ['id' => $id, 'name' => self::country_name($c, $lang), 'match' => $al[$c] ?? [], 'events' => $ev, 'periods' => $pe];
            }
        }
        return $sets;
    }

    /**
     * Listes qui s'appliquent à une personne née à $place. Une liste sans mot-clé de lieu vaut pour tout le monde ; parmi les listes qui en
     * ont, une seule choisie vaut pour tout le monde, plusieurs : celles dont un mot-clé est un élément du lieu de naissance (de la fin vers le
     * début), à défaut la liste par défaut (sauf si le lieu nomme un pays connu mais sans liste choisie : alors aucune).
     */
    public static function pick(array $sets, $place, $default = '', $scope = 'place') {
        if ($scope === 'all') return array_values($sets);   // listes cochées : pour tout le livre, sans tenir compte du lieu
        $reg = [];
        $com = [];
        foreach ($sets as $s) {
            if ($s['match']) $reg[] = $s; else $com[] = $s;
        }
        $out = [];
        if (count($reg) === 1) {
            $out = $reg;
        } elseif ($reg) {
            $norm = [];
            foreach (array_reverse(array_map('trim', explode(',', (string) $place))) as $part) {
                $n = self::norm($part);
                if ($n !== '') $norm[] = $n;
            }
            foreach ($reg as $s) foreach ($norm as $n) if (in_array($n, $s['match'], true)) { $out[] = $s; break; }
            if (!$out) {
                $foreign = $norm && in_array($norm[0], self::aliases()[''], true);
                if (!$foreign) $out[] = isset($sets[$default]) && $sets[$default]['match'] ? $sets[$default] : $reg[0];
            }
        }
        return array_merge($out, $com);
    }

    /** Événements d'un pays : liste de [année, mois, jour, texte français, texte anglais] (mois / jour 0 = inconnu). */
    public static function events($code) {
        static $all = null;
        if ($all === null) $all = self::data();
        return $all[$code] ?? [];
    }

    /**
     * Périodes marquantes d'un pays (guerres, régimes…) : [début, fin, nom français avec article, nom anglais avec article].
     * Bornes AAAA, AAAA-MM ou AAAA-MM-JJ (début = premier jour possible, fin = dernier jour possible).
     */
    public static function periods($code) {
        static $all = null;
        if ($all === null) {
            $w1 = ['1914-08-02', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'];
            $w2 = ['1939-09-03', '1945-05-08', 'la Seconde Guerre mondiale', 'the Second World War'];
            $all = [
                'FR' => [['1789-07-14', '1799-11-09', 'la Révolution française', 'the French Revolution'], ['1803-05-18', '1815-06-18', 'les guerres napoléoniennes', 'the Napoleonic Wars'],
                    ['1870-07-19', '1871-05-10', 'la guerre franco-prussienne', 'the Franco-Prussian War'], $w1, $w2, ['1954-11-01', '1962-03-19', 'la guerre d’Algérie', 'the Algerian War']],
                'ES' => [['1808-05-02', '1814', 'la guerre d’Indépendance espagnole', 'the Peninsular War'], ['1833-10', '1840', 'la première guerre carliste', 'the First Carlist War'],
                    ['1872', '1876', 'la troisième guerre carliste', 'the Third Carlist War'], ['1923-09-13', '1930-01-28', 'la dictature de Primo de Rivera', 'the Primo de Rivera dictatorship'],
                    ['1936-07-17', '1939-04-01', 'la guerre civile espagnole', 'the Spanish Civil War'], ['1939-04-01', '1975-11-20', 'la dictature franquiste', 'the Franco dictatorship']],
                'BE' => [['1914-08-04', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'], ['1940-05-10', '1945-05-08', 'la Seconde Guerre mondiale', 'the Second World War']],
                'IT' => [['1915-05-24', '1918-11-04', 'la Première Guerre mondiale', 'the First World War'], ['1940-06-10', '1945-05-08', 'la Seconde Guerre mondiale', 'the Second World War']],
                'DE' => [['1870-07-19', '1871-05-10', 'la guerre franco-allemande', 'the Franco-German War'], ['1914-08-01', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'],
                    ['1939-09-01', '1945-05-08', 'la Seconde Guerre mondiale', 'the Second World War']],
                'GB' => [['1642-08-22', '1651-09-03', 'la guerre civile anglaise', 'the English Civil War'], ['1803-05-18', '1815-06-18', 'les guerres napoléoniennes', 'the Napoleonic Wars'],
                    ['1837-06-20', '1901-01-22', 'l’époque victorienne', 'the Victorian era'], ['1914-08-04', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'], $w2],
                'CH' => [['1914-08-03', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'], ['1939-09-02', '1945-05-08', 'la Seconde Guerre mondiale', 'the Second World War']],
                'US' => [['1775-04-19', '1783-09-03', 'la guerre d’Indépendance', 'the War of Independence'], ['1861-04-12', '1865-04-09', 'la guerre de Sécession', 'the Civil War'],
                    ['1917-04-06', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'], ['1920-01-17', '1933-12-05', 'la Prohibition', 'Prohibition'],
                    ['1929-10-29', '1939-12-31', 'la Grande Dépression', 'the Great Depression'], ['1941-12-07', '1945-09-02', 'la Seconde Guerre mondiale', 'the Second World War'],
                    ['1950-06-25', '1953-07-27', 'la guerre de Corée', 'the Korean War'], ['1965-03-08', '1973-03-29', 'la guerre du Vietnam', 'the Vietnam War']],
                'CA' => [['1837-11', '1838-12', 'les rébellions de 1837-1838', 'the Rebellions of 1837–1838'], ['1914-08-04', '1918-11-11', 'la Première Guerre mondiale', 'the First World War'],
                    ['1929-10-29', '1939-12-31', 'la Grande Dépression', 'the Great Depression'], ['1939-09-10', '1945-05-08', 'la Seconde Guerre mondiale', 'the Second World War']],
            ];
        }
        return $all[$code] ?? [];
    }

    /** Périodes (indices dans $periods) qui contiennent entièrement la date (année, mois ou jour connu) : prudent, une date imprécise n'est retenue que si toutes ses possibilités tombent dans la période. */
    public static function periods_at(array $periods, $y, $m, $d) {
        $lo = sprintf('%04d-%02d-%02d', $y, $m ?: 1, $d ?: 1);
        $hi = $d ? $lo : sprintf('%04d-%02d-%02d', $y, $m ?: 12, $m ? (int) date('t', mktime(0, 0, 0, $m, 1, $y)) : 31);
        $hits = [];
        foreach ($periods as $i => $p) {
            $a = self::bound($p[0], false);
            $b = self::bound($p[1], true);
            if ($lo >= $a && $hi <= $b) $hits[] = $i;
        }
        return $hits;
    }

    /** Fusionne les périodes de même nom issues de plusieurs listes (ex. « la Première Guerre mondiale » en France et en Belgique) : du plus tôt début à la plus tardive fin, par ordre chronologique. */
    public static function merge_periods(array $periods) {
        $m = [];
        foreach ($periods as $p) {
            $k = self::norm($p[2]) !== '' ? self::norm($p[2]) : $p[2];
            if (!isset($m[$k])) { $m[$k] = $p; continue; }
            if (self::bound($p[0], false) < self::bound($m[$k][0], false)) $m[$k][0] = $p[0];
            if (self::bound($p[1], true) > self::bound($m[$k][1], true)) $m[$k][1] = $p[1];
        }
        $m = array_values($m);
        usort($m, function ($a, $b) { return strcmp(self::bound($a[0], false), self::bound($b[0], false)); });
        return $m;
    }

    private static function bound($s, $end) {
        $f = explode('-', $s);
        $y = (int) $f[0];
        $m = isset($f[1]) ? (int) $f[1] : 0;
        $d = isset($f[2]) ? (int) $f[2] : 0;
        if (!$m) return sprintf('%04d-%s', $y, $end ? '12-31' : '01-01');
        if (!$d) return sprintf('%04d-%02d-%02d', $y, $m, $end ? (int) date('t', mktime(0, 0, 0, $m, 1, $y)) : 1);
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private static function data() {
        return [
            'FR' => [
                [1562, 3, 1, 'Massacre de Wassy : début des guerres de Religion', 'Massacre of Wassy: the Wars of Religion begin'],
                [1572, 8, 24, 'Massacre de la Saint-Barthélemy', 'Saint Bartholomew’s Day massacre'],
                [1598, 4, 13, 'Édit de Nantes', 'Edict of Nantes'],
                [1610, 5, 14, 'Assassinat d’Henri IV', 'Assassination of Henry IV'],
                [1643, 5, 14, 'Mort de Louis XIII ; début du règne de Louis XIV', 'Death of Louis XIII; Louis XIV’s reign begins'],
                [1685, 10, 18, 'Révocation de l’édit de Nantes', 'Revocation of the Edict of Nantes'],
                [1693, 0, 0, 'Grande famine de 1693-1694', 'Great famine of 1693–1694'],
                [1709, 0, 0, 'Grand hiver de 1709', 'The Great Winter of 1709'],
                [1715, 9, 1, 'Mort de Louis XIV', 'Death of Louis XIV'],
                [1789, 7, 14, 'Prise de la Bastille : début de la Révolution', 'Storming of the Bastille: the Revolution begins'],
                [1792, 8, 10, 'Chute de la monarchie', 'Fall of the monarchy'],
                [1792, 9, 22, 'Proclamation de la Première République', 'First Republic proclaimed'],
                [1793, 1, 21, 'Exécution de Louis XVI', 'Execution of Louis XVI'],
                [1793, 3, 11, 'Soulèvement de la Vendée', 'Uprising in the Vendée'],
                [1793, 9, 5, 'La Terreur', 'The Terror'],
                [1799, 11, 9, 'Coup d’État du 18 brumaire : Bonaparte prend le pouvoir', 'Coup of 18 Brumaire: Bonaparte takes power'],
                [1804, 12, 2, 'Sacre de Napoléon Ier', 'Napoleon crowned Emperor'],
                [1812, 6, 24, 'Campagne de Russie', 'Russian campaign'],
                [1815, 6, 18, 'Défaite de Waterloo', 'Battle of Waterloo'],
                [1830, 7, 27, 'Révolution de Juillet (les Trois Glorieuses)', 'July Revolution'],
                [1832, 0, 0, 'Épidémie de choléra', 'Cholera epidemic'],
                [1848, 2, 24, 'Révolution de février ; Seconde République', 'February Revolution; Second Republic'],
                [1851, 12, 2, 'Coup d’État de Louis-Napoléon Bonaparte', 'Coup d’état of Louis-Napoléon Bonaparte'],
                [1852, 12, 2, 'Second Empire', 'Second Empire'],
                [1870, 7, 19, 'Début de la guerre franco-prussienne', 'Franco-Prussian War begins'],
                [1870, 9, 4, 'Proclamation de la Troisième République', 'Third Republic proclaimed'],
                [1871, 3, 18, 'Commune de Paris', 'Paris Commune'],
                [1871, 5, 10, 'Traité de Francfort : l’Alsace et une partie de la Lorraine passent à l’Allemagne', 'Treaty of Frankfurt: Alsace and part of Lorraine pass to Germany'],
                [1905, 12, 9, 'Loi de séparation des Églises et de l’État', 'Law separating Church and State'],
                [1914, 8, 2, 'Mobilisation générale : début de la Première Guerre mondiale', 'General mobilisation: First World War begins'],
                [1916, 2, 21, 'Début de la bataille de Verdun', 'Battle of Verdun begins'],
                [1918, 11, 11, 'Armistice de la Première Guerre mondiale', 'Armistice ending the First World War'],
                [1919, 6, 28, 'Traité de Versailles', 'Treaty of Versailles'],
                [1936, 6, 20, 'Front populaire : congés payés', 'Popular Front: paid holidays'],
                [1939, 9, 3, 'La France entre en guerre', 'France enters the Second World War'],
                [1940, 5, 10, 'Offensive allemande : bataille de France', 'German offensive: Battle of France'],
                [1940, 6, 22, 'Armistice avec l’Allemagne ; début de l’Occupation', 'Armistice with Germany; the Occupation begins'],
                [1940, 7, 10, 'Pleins pouvoirs à Pétain : régime de Vichy', 'Full powers to Pétain: Vichy regime'],
                [1944, 6, 6, 'Débarquement en Normandie', 'Normandy landings'],
                [1944, 8, 25, 'Libération de Paris', 'Liberation of Paris'],
                [1945, 5, 8, 'Victoire en Europe', 'Victory in Europe'],
                [1946, 10, 27, 'Quatrième République', 'Fourth Republic'],
                [1954, 11, 1, 'Début de la guerre d’Algérie', 'Algerian War begins'],
                [1958, 10, 4, 'Cinquième République', 'Fifth Republic'],
                [1962, 3, 19, 'Accords d’Évian : cessez-le-feu en Algérie', 'Évian Accords: ceasefire in Algeria'],
                [1968, 5, 0, 'Mai 68', 'May 1968 unrest'],
                [1981, 10, 9, 'Abolition de la peine de mort', 'Abolition of the death penalty'],
                [2002, 1, 1, 'L’euro remplace le franc', 'The euro replaces the franc'],
            ],
            'ES' => [
                [1700, 11, 1, 'Mort de Charles II : guerre de Succession d’Espagne', 'Death of Charles II: War of the Spanish Succession'],
                [1713, 4, 11, 'Traité d’Utrecht', 'Treaty of Utrecht'],
                [1808, 5, 2, 'Soulèvement du 2 mai : guerre d’Indépendance', 'Uprising of 2 May: Peninsular War'],
                [1812, 3, 19, 'Constitution de Cadix', 'Constitution of Cádiz'],
                [1814, 3, 24, 'Retour de Ferdinand VII', 'Return of Ferdinand VII'],
                [1833, 9, 29, 'Mort de Ferdinand VII : crise de succession et guerres carlistes', 'Death of Ferdinand VII: succession crisis and Carlist wars'],
                [1868, 9, 18, 'Révolution de 1868 : chute d’Isabelle II', 'Revolution of 1868: fall of Isabella II'],
                [1873, 2, 11, 'Première République', 'First Republic'],
                [1874, 12, 29, 'Restauration des Bourbons', 'Bourbon Restoration'],
                [1898, 12, 10, 'Traité de Paris : perte de Cuba, de Porto Rico et des Philippines', 'Treaty of Paris: loss of Cuba, Puerto Rico and the Philippines'],
                [1909, 7, 26, 'Semaine tragique de Barcelone', 'Tragic Week in Barcelona'],
                [1918, 0, 0, 'Épidémie de grippe dite « espagnole »', 'Influenza pandemic (“Spanish flu”)'],
                [1923, 9, 13, 'Dictature de Primo de Rivera', 'Dictatorship of Primo de Rivera'],
                [1931, 4, 14, 'Seconde République', 'Second Republic'],
                [1936, 7, 17, 'Début de la guerre civile', 'Spanish Civil War begins'],
                [1937, 4, 26, 'Bombardement de Guernica', 'Bombing of Guernica'],
                [1939, 4, 1, 'Fin de la guerre civile', 'End of the Civil War'],
                [1975, 11, 20, 'Mort de Franco', 'Death of Franco'],
                [1978, 12, 6, 'Référendum sur la Constitution démocratique', 'Referendum on the democratic Constitution'],
                [1981, 2, 23, 'Tentative de coup d’État du 23-F', 'Attempted coup of 23 February'],
                [1986, 1, 1, 'Entrée dans la Communauté européenne', 'Spain joins the European Community'],
                [2002, 1, 1, 'L’euro remplace la peseta', 'The euro replaces the peseta'],
            ],
            'BE' => [
                [1795, 10, 1, 'Annexion à la France', 'Annexation by France'],
                [1815, 6, 18, 'Bataille de Waterloo', 'Battle of Waterloo'],
                [1830, 10, 4, 'Indépendance de la Belgique', 'Belgian independence'],
                [1831, 7, 21, 'Léopold Ier prête serment', 'Leopold I sworn in'],
                [1839, 4, 19, 'Traité de Londres : indépendance reconnue', 'Treaty of London: independence recognised'],
                [1893, 4, 18, 'Grève générale et suffrage universel masculin plural', 'General strike and plural male suffrage'],
                [1914, 8, 4, 'Invasion allemande', 'German invasion'],
                [1914, 10, 0, 'Bataille de l’Yser', 'Battle of the Yser'],
                [1918, 11, 11, 'Armistice', 'Armistice'],
                [1940, 5, 10, 'Invasion allemande', 'German invasion'],
                [1940, 5, 28, 'Capitulation de l’armée belge', 'Belgian army capitulates'],
                [1944, 9, 3, 'Libération de Bruxelles', 'Liberation of Brussels'],
                [1944, 12, 16, 'Bataille des Ardennes', 'Battle of the Bulge'],
                [1957, 3, 25, 'Traité de Rome', 'Treaty of Rome'],
                [1960, 6, 30, 'Indépendance du Congo', 'Congo independence'],
                [1993, 0, 0, 'La Belgique devient un État fédéral', 'Belgium becomes a federal state'],
                [2002, 1, 1, 'L’euro remplace le franc belge', 'The euro replaces the Belgian franc'],
            ],
            'IT' => [
                [1796, 0, 0, 'Campagne d’Italie de Bonaparte', 'Bonaparte’s Italian campaign'],
                [1848, 3, 23, 'Première guerre d’indépendance', 'First War of Independence'],
                [1859, 4, 29, 'Seconde guerre d’indépendance', 'Second War of Independence'],
                [1860, 5, 11, 'Expédition des Mille', 'Expedition of the Thousand'],
                [1861, 3, 17, 'Proclamation du royaume d’Italie', 'Kingdom of Italy proclaimed'],
                [1866, 6, 20, 'Troisième guerre d’indépendance', 'Third War of Independence'],
                [1870, 9, 20, 'Prise de Rome', 'Capture of Rome'],
                [1908, 12, 28, 'Séisme de Messine', 'Messina earthquake'],
                [1915, 5, 24, 'L’Italie entre dans la Première Guerre mondiale', 'Italy enters the First World War'],
                [1917, 10, 24, 'Défaite de Caporetto', 'Defeat at Caporetto'],
                [1918, 11, 4, 'Armistice avec l’Autriche-Hongrie', 'Armistice with Austria-Hungary'],
                [1922, 10, 28, 'Marche sur Rome', 'March on Rome'],
                [1940, 6, 10, 'L’Italie entre dans la Seconde Guerre mondiale', 'Italy enters the Second World War'],
                [1943, 7, 25, 'Chute de Mussolini', 'Fall of Mussolini'],
                [1943, 9, 8, 'Armistice de Cassibile', 'Armistice of Cassibile'],
                [1945, 4, 25, 'Libération', 'Liberation'],
                [1946, 6, 2, 'Référendum : l’Italie devient une République', 'Referendum: Italy becomes a republic'],
                [1957, 3, 25, 'Traité de Rome', 'Treaty of Rome'],
                [1976, 5, 6, 'Séisme du Frioul', 'Friuli earthquake'],
                [2002, 1, 1, 'L’euro remplace la lire', 'The euro replaces the lira'],
            ],
            'DE' => [
                [1648, 10, 24, 'Traités de Westphalie : fin de la guerre de Trente Ans', 'Peace of Westphalia: end of the Thirty Years’ War'],
                [1806, 8, 6, 'Fin du Saint-Empire romain germanique', 'End of the Holy Roman Empire'],
                [1813, 10, 16, 'Bataille des Nations à Leipzig', 'Battle of the Nations at Leipzig'],
                [1815, 6, 8, 'Confédération germanique', 'German Confederation'],
                [1848, 3, 18, 'Révolution de mars', 'March Revolution'],
                [1866, 6, 0, 'Guerre austro-prussienne', 'Austro-Prussian War'],
                [1870, 7, 19, 'Guerre franco-allemande', 'Franco-German War'],
                [1871, 1, 18, 'Proclamation de l’Empire allemand', 'German Empire proclaimed'],
                [1914, 8, 1, 'L’Allemagne entre dans la Première Guerre mondiale', 'Germany enters the First World War'],
                [1918, 11, 9, 'Révolution de novembre : proclamation de la République', 'November Revolution: Republic proclaimed'],
                [1918, 11, 11, 'Armistice', 'Armistice'],
                [1919, 6, 28, 'Traité de Versailles', 'Treaty of Versailles'],
                [1923, 0, 0, 'Hyperinflation', 'Hyperinflation'],
                [1933, 1, 30, 'Hitler nommé chancelier', 'Hitler appointed Chancellor'],
                [1938, 11, 9, 'Nuit de Cristal', 'Kristallnacht'],
                [1939, 9, 1, 'Invasion de la Pologne : début de la Seconde Guerre mondiale', 'Invasion of Poland: Second World War begins'],
                [1943, 2, 2, 'Défaite de Stalingrad', 'Defeat at Stalingrad'],
                [1945, 5, 8, 'Capitulation de l’Allemagne', 'German surrender'],
                [1949, 5, 23, 'Naissance de la République fédérale', 'Federal Republic founded'],
                [1949, 10, 7, 'Naissance de la République démocratique allemande', 'German Democratic Republic founded'],
                [1961, 8, 13, 'Construction du mur de Berlin', 'Berlin Wall built'],
                [1989, 11, 9, 'Chute du mur de Berlin', 'Fall of the Berlin Wall'],
                [1990, 10, 3, 'Réunification allemande', 'German reunification'],
                [2002, 1, 1, 'L’euro remplace le mark', 'The euro replaces the mark'],
            ],
            'GB' => [
                [1642, 8, 22, 'Début de la guerre civile anglaise', 'English Civil War begins'],
                [1649, 1, 30, 'Exécution de Charles Ier', 'Execution of Charles I'],
                [1660, 5, 29, 'Restauration de la monarchie', 'Restoration of the monarchy'],
                [1666, 9, 2, 'Grand incendie de Londres', 'Great Fire of London'],
                [1707, 5, 1, 'Acte d’Union entre l’Angleterre et l’Écosse', 'Act of Union between England and Scotland'],
                [1801, 1, 1, 'Union avec l’Irlande', 'Union with Ireland'],
                [1805, 10, 21, 'Bataille de Trafalgar', 'Battle of Trafalgar'],
                [1815, 6, 18, 'Bataille de Waterloo', 'Battle of Waterloo'],
                [1833, 8, 28, 'Abolition de l’esclavage dans l’Empire', 'Abolition of slavery in the Empire'],
                [1837, 6, 20, 'Avènement de la reine Victoria', 'Accession of Queen Victoria'],
                [1845, 0, 0, 'Grande famine irlandaise', 'Great Irish Famine'],
                [1851, 5, 1, 'Grande Exposition universelle de Londres', 'Great Exhibition'],
                [1901, 1, 22, 'Mort de la reine Victoria', 'Death of Queen Victoria'],
                [1914, 8, 4, 'Entrée en guerre', 'Britain enters the First World War'],
                [1916, 7, 1, 'Début de la bataille de la Somme', 'Battle of the Somme begins'],
                [1918, 2, 6, 'Droit de vote pour une partie des femmes', 'Votes for some women'],
                [1918, 11, 11, 'Armistice', 'Armistice'],
                [1926, 5, 4, 'Grève générale', 'General Strike'],
                [1939, 9, 3, 'Entrée en guerre', 'Britain enters the Second World War'],
                [1940, 9, 7, 'Début du Blitz', 'The Blitz begins'],
                [1945, 5, 8, 'Victoire en Europe', 'Victory in Europe'],
                [1948, 7, 5, 'Création du service national de santé (NHS)', 'National Health Service founded'],
                [1952, 2, 6, 'Avènement d’Élisabeth II', 'Accession of Elizabeth II'],
                [1973, 1, 1, 'Entrée dans la Communauté européenne', 'UK joins the European Community'],
                [1982, 4, 2, 'Guerre des Malouines', 'Falklands War'],
                [2016, 6, 23, 'Référendum sur le Brexit', 'Brexit referendum'],
                [2022, 9, 8, 'Mort d’Élisabeth II', 'Death of Elizabeth II'],
            ],
            'CH' => [
                [1798, 4, 12, 'République helvétique', 'Helvetic Republic'],
                [1803, 2, 19, 'Acte de Médiation', 'Act of Mediation'],
                [1815, 11, 20, 'Neutralité perpétuelle reconnue', 'Perpetual neutrality recognised'],
                [1847, 11, 0, 'Guerre du Sonderbund', 'Sonderbund War'],
                [1848, 9, 12, 'Constitution fédérale', 'Federal Constitution'],
                [1863, 2, 17, 'Fondation de la Croix-Rouge à Genève', 'Red Cross founded in Geneva'],
                [1874, 4, 19, 'Révision de la Constitution fédérale', 'Revision of the Federal Constitution'],
                [1914, 8, 3, 'Mobilisation générale', 'General mobilisation'],
                [1918, 11, 12, 'Grève générale', 'General strike'],
                [1920, 5, 16, 'Adhésion à la Société des Nations', 'Vote to join the League of Nations'],
                [1939, 9, 2, 'Mobilisation générale', 'General mobilisation'],
                [1940, 7, 25, 'Rapport du Grütli : le Réduit national', 'Rütli Report: the National Redoubt'],
                [1971, 2, 7, 'Droit de vote des femmes au niveau fédéral', 'Women’s suffrage at federal level'],
                [2002, 3, 3, 'Adhésion à l’ONU', 'Switzerland joins the United Nations'],
            ],
            'US' => [
                [1776, 7, 4, 'Déclaration d’indépendance', 'Declaration of Independence'],
                [1783, 9, 3, 'Traité de Paris : indépendance reconnue', 'Treaty of Paris: independence recognised'],
                [1803, 4, 30, 'Achat de la Louisiane', 'Louisiana Purchase'],
                [1812, 6, 18, 'Guerre de 1812', 'War of 1812'],
                [1861, 4, 12, 'Début de la guerre de Sécession', 'Civil War begins'],
                [1863, 1, 1, 'Proclamation d’émancipation', 'Emancipation Proclamation'],
                [1865, 4, 9, 'Fin de la guerre de Sécession', 'End of the Civil War'],
                [1869, 5, 10, 'Achèvement du chemin de fer transcontinental', 'Transcontinental railroad completed'],
                [1898, 4, 25, 'Guerre hispano-américaine', 'Spanish–American War'],
                [1917, 4, 6, 'Entrée dans la Première Guerre mondiale', 'US enters the First World War'],
                [1918, 11, 11, 'Armistice', 'Armistice'],
                [1920, 1, 17, 'Début de la Prohibition', 'Prohibition begins'],
                [1920, 8, 18, 'Droit de vote des femmes', 'Women’s right to vote'],
                [1929, 10, 29, 'Krach boursier', 'Stock market crash'],
                [1941, 12, 7, 'Attaque de Pearl Harbor', 'Attack on Pearl Harbor'],
                [1945, 9, 2, 'Capitulation du Japon', 'Japan surrenders'],
                [1950, 6, 25, 'Début de la guerre de Corée', 'Korean War begins'],
                [1963, 11, 22, 'Assassinat de John F. Kennedy', 'Assassination of John F. Kennedy'],
                [1964, 7, 2, 'Loi sur les droits civiques', 'Civil Rights Act'],
                [1969, 7, 20, 'Premiers pas sur la Lune', 'First Moon landing'],
                [2001, 9, 11, 'Attentats du 11 septembre', 'September 11 attacks'],
            ],
            'CA' => [
                [1608, 7, 3, 'Fondation de Québec', 'Founding of Quebec City'],
                [1713, 4, 11, 'Traité d’Utrecht : l’Acadie passe à l’Angleterre', 'Treaty of Utrecht: Acadia ceded to Britain'],
                [1755, 0, 0, 'Début de la déportation des Acadiens', 'Expulsion of the Acadians begins'],
                [1759, 9, 13, 'Bataille des plaines d’Abraham', 'Battle of the Plains of Abraham'],
                [1763, 2, 10, 'Traité de Paris : la Nouvelle-France cédée à l’Angleterre', 'Treaty of Paris: New France ceded to Britain'],
                [1837, 11, 0, 'Rébellions des Patriotes', 'Rebellions of 1837–1838'],
                [1841, 2, 10, 'Entrée en vigueur de l’Acte d’Union', 'Act of Union takes effect'],
                [1867, 7, 1, 'Confédération canadienne', 'Canadian Confederation'],
                [1885, 11, 16, 'Pendaison de Louis Riel', 'Execution of Louis Riel'],
                [1914, 8, 4, 'Entrée en guerre', 'Canada enters the First World War'],
                [1917, 8, 29, 'Crise de la conscription', 'Conscription Crisis'],
                [1918, 11, 11, 'Armistice', 'Armistice'],
                [1939, 9, 10, 'Entrée en guerre', 'Canada enters the Second World War'],
                [1944, 6, 6, 'Débarquement en Normandie (plage Juno)', 'D-Day landings (Juno Beach)'],
                [1949, 4, 1, 'Terre-Neuve rejoint le Canada', 'Newfoundland joins Canada'],
                [1960, 0, 0, 'Début de la Révolution tranquille', 'Quiet Revolution begins'],
                [1970, 10, 0, 'Crise d’Octobre', 'October Crisis'],
                [1980, 5, 20, 'Premier référendum sur la souveraineté du Québec', 'First Quebec sovereignty referendum'],
                [1982, 4, 17, 'Rapatriement de la Constitution', 'Patriation of the Constitution'],
                [1995, 10, 30, 'Second référendum sur la souveraineté du Québec', 'Second Quebec sovereignty referendum'],
            ],
        ];
    }

    /* ---------- Réglages hérités (avant la 2.10.0 : pays par défaut + événements personnalisés) ---------- */

    /** Liste de l'utilisateur contenant ce texte d'événements (créée si besoin) ; retourne son identifiant. */
    public static function legacy_custom_list($text) {
        $text = trim((string) $text);
        if ($text === '') return '';
        $lists = self::user_lists();
        foreach ($lists as $id => $x) if (trim($x['text']) === $text && $x['name'] === 'Événements personnalisés') return $id;
        $id = self::new_id($lists);
        $lists[$id] = ['name' => 'Événements personnalisés', 'match' => '', 'text' => $text];
        self::save_user_lists($lists);
        return $id;
    }

    /** Sélection équivalente à l'ancien fonctionnement : tous les modèles (pays reconnu d'après le lieu) + les événements personnalisés. */
    public static function legacy_selection(array $in) {
        $ids = self::template_ids();
        $cu = self::legacy_custom_list($in['history_custom'] ?? '');
        if ($cu !== '') $ids[] = $cu;
        $c = strtoupper((string) ($in['history_country'] ?? 'FR'));
        return ['history_lists' => $ids, 'history_default' => isset(self::countries()[$c]) ? 'tpl-' . $c : 'tpl-FR', 'history_scope' => 'place'];
    }

    /** Listes cochées dans un formulaire : ne garde que les listes qui existent. */
    public static function sanitize_selection(array $in) {
        $ids = [];
        foreach ((array) ($in['history_lists'] ?? []) as $id) {
            $id = sanitize_text_field((string) $id);
            if ($id !== '' && self::is_known($id) && !in_array($id, $ids, true)) $ids[] = $id;
        }
        $def = sanitize_text_field((string) ($in['history_default'] ?? ''));
        return ['history_lists' => $ids, 'history_default' => in_array($def, $ids, true) ? $def : '', 'history_scope' => (($in['history_scope'] ?? '') === 'place') ? 'place' : 'all'];
    }

    private static function new_id(array $lists) {
        do {
            $id = 'u' . substr(md5(uniqid('', true)), 0, 6);
        } while (isset($lists[$id]));
        return $id;
    }

    /* ---------- Formulaire du livre ---------- */

    /** Ligne du formulaire du livre : listes de faits historiques à utiliser (cases à cocher) et liste par défaut. */
    public static function form_row($name, array $o, $admin) {
        $lang = (string) ($o['lang'] ?? 'fr');
        $ch = self::choices($lang);
        $sel = (array) ($o['history_lists'] ?? []);
        $user = self::user_lists();
        $h = '<tr><th scope="row">' . esc_html(tngs_t('Contexte historique : listes à utiliser')) . '</th><td>';
        $h .= '<input type="hidden" name="' . esc_attr($name) . '[history_lists][]" value="">';
        $box = function ($id, $label) use ($name, $sel) {
            return '<label style="display:inline-block;min-width:200px;margin:0 14px 4px 0"><input type="checkbox" name="' . esc_attr($name) . '[history_lists][]" value="' . esc_attr($id) . '"' . (in_array($id, $sel, true) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
        };
        if ($user) {
            $h .= '<p style="margin:0 0 4px"><strong>' . esc_html(tngs_t('Mes listes')) . '</strong></p><div>';
            foreach ($user as $id => $x) $h .= $box($id, $ch[$id]);
            $h .= '</div>';
        }
        $h .= '<p style="margin:6px 0 4px"><strong>' . esc_html(tngs_t('Listes fournies (textes français ou anglais selon la langue du livre)')) . '</strong></p><div>';
        foreach (self::countries() as $c => $n) $h .= $box('tpl-' . $c, $ch['tpl-' . $c]);
        $h .= '</div>';
        $sc = (string) ($o['history_scope'] ?? 'all') === 'place' ? 'place' : 'all';
        $h .= '<p style="margin:10px 0 0"><label>' . esc_html(tngs_t('Listes cochées appliquées')) . ' : <select name="' . esc_attr($name) . '[history_scope]"><option value="all"' . selected($sc, 'all', false) . '>' . esc_html(tngs_t('à tout le livre (toutes les personnes)')) . '</option><option value="place"' . selected($sc, 'place', false) . '>' . esc_html(tngs_t('selon le lieu de naissance de chaque personne (mots-clés de lieu)')) . '</option></select></label></p>';
        $h .= '<p style="margin:8px 0 0"><label>' . esc_html(tngs_t('Liste par défaut (utilisée selon le lieu de naissance : lieux sans mot-clé reconnu)')) . ' : <select name="' . esc_attr($name) . '[history_default]"><option value="">' . esc_html(tngs_t('la première liste cochée')) . '</option>';
        foreach ($ch as $id => $l) $h .= '<option value="' . esc_attr($id) . '"' . selected((string) ($o['history_default'] ?? ''), $id, false) . '>' . esc_html($l) . '</option>';
        $h .= '</select></label></p>';
        $h .= '<p class="description">' . esc_html(tngs_t('Utilisé quand la chronologie est réglée sur « avec contexte historique » ou que le contexte historique des fiches est coché. Choisissez ici, pour chaque livre, les listes à afficher. « À tout le livre » : toutes les listes cochées valent pour toutes les personnes. « Selon le lieu de naissance » : avec plusieurs listes, chaque personne reçoit celles dont un mot-clé de lieu figure dans son lieu de naissance (une liste sans mot-clé vaut pour tout le monde), et une liste n’apparaît dans la chronologie que si elle concerne au moins deux personnes du livre (ou 3 %).'));
        if ($admin) $h .= ' <a href="#tngbook-settings">' . esc_html(tngs_t('Créez et modifiez vos listes dans Livre > Réglages > Faits historiques.')) . '</a>';
        $h .= '</p></td></tr>';
        return $h;
    }

    /* ---------- Gestion des listes (Livre > Réglages) ---------- */

    public static function init() {
        add_action('admin_post_tngbook_hist_save', [__CLASS__, 'admin_save']);
        add_action('admin_post_tngbook_hist_export', [__CLASS__, 'admin_export']);
    }

    public static function admin_form() {
        $lists = self::user_lists();
        $msg = isset($_GET['tngbook_msg']) ? sanitize_key(wp_unslash($_GET['tngbook_msg'])) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<h2 id="tngbook-history">' . esc_html(tngs_t('Faits historiques')) . '</h2>';
        if ($msg === 'hist_saved') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(tngs_t('Listes enregistrées.')) . '</p></div>';
        echo '<p class="description" style="max-width:900px">' . esc_html(tngs_t('Une liste est un ensemble de faits dans la langue de votre choix : vous écrivez les textes vous-même. Une entrée par ligne : « date | texte » pour un événement (date AAAA, AAAA-MM ou AAAA-MM-JJ), « date .. date | nom » pour une période (guerre, régime… avec son article, par exemple « la Première Guerre mondiale » : sert à la phrase « Contexte historique » des fiches). Les mots-clés de lieu (séparés par des virgules) rattachent la liste aux personnes dont un élément du lieu de naissance correspond ; sans mot-clé, la liste vaut pour tout le monde. Les listes se cochent dans le formulaire du livre.')) . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tngbook_hist_save">';
        wp_nonce_field('tngbook_hist', 'tngbook_hnonce');
        $block = function ($key, $x, $isNew) {
            $exp = (!$isNew) ? ' <a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tngbook_hist_export&id=' . rawurlencode($key)), 'tngbook_hist_export')) . '">' . esc_html(tngs_t('Exporter (.txt)')) . '</a>' : '';
            $del = (!$isNew) ? ' &nbsp; <label><input type="checkbox" name="hl[' . esc_attr($key) . '][delete]" value="1"> ' . esc_html(tngs_t('Supprimer cette liste')) . '</label>' : '';
            echo '<div style="border:1px solid #c3c4c7;background:#fff;padding:10px 12px;margin:0 0 12px;max-width:900px"><p style="margin:0 0 6px"><label><strong>' . esc_html(tngs_t('Nom')) . '</strong> <input type="text" name="hl[' . esc_attr($key) . '][name]" value="' . esc_attr($x['name']) . '" maxlength="60" class="regular-text"></label> '
                . '<label style="margin-left:10px"><strong>' . esc_html(tngs_t('Mots-clés de lieu')) . '</strong> <input type="text" name="hl[' . esc_attr($key) . '][match]" value="' . esc_attr($x['match']) . '" maxlength="300" class="regular-text" placeholder="Catalunya, Cataluña, Espanya"></label>' . $del . $exp . '</p>'
                . '<textarea name="hl[' . esc_attr($key) . '][text]" rows="' . ($isNew ? 4 : 10) . '" class="large-text code" placeholder="1714-09-11 | Caiguda de Barcelona&#10;1936-07-17 .. 1939-04-01 | la guerra civil">' . esc_textarea($x['text']) . '</textarea></div>';
        };
        foreach ($lists as $id => $x) {
            echo '<h3 style="margin:14px 0 6px">' . esc_html($x['name'] !== '' ? $x['name'] : $id) . '</h3>';
            $block($id, $x, false);
        }
        echo '<h3 style="margin:14px 0 6px">' . esc_html(tngs_t('Nouvelle liste')) . '</h3>';
        $block('new', ['name' => '', 'match' => '', 'text' => ''], true);
        echo '<p><label>' . esc_html(tngs_t('Ou partir d’une liste fournie :')) . ' <select name="hist_copy"><option value="">—</option>';
        foreach (self::countries() as $c => $n) {
            echo '<option value="' . esc_attr($c . ':fr') . '">' . esc_html($n[0] . ' (français)') . '</option><option value="' . esc_attr($c . ':en') . '">' . esc_html($n[1] . ' (English)') . '</option>';
        }
        echo '</select></label> <span class="description">' . esc_html(tngs_t('Une copie modifiable est ajoutée à vos listes.')) . '</span></p>';
        echo '<p><label>' . esc_html(tngs_t('Importer une liste (fichier .txt exporté) :')) . ' <input type="file" name="hist_file" accept=".txt,text/plain"></label></p>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(tngs_t('Enregistrer les listes')) . '</button></p></form>';
    }

    /** Nettoie le texte des entrées d'une liste (lignes de 300 caractères, 3000 lignes au plus). */
    private static function clean_text($t) {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $t) as $l) {
            $l = trim(wp_strip_all_tags($l));
            if ($l === '') continue;
            $lines[] = mb_substr($l, 0, 300);
            if (count($lines) >= 3000) break;
        }
        return implode("\n", $lines);
    }

    /** Fichier texte d'une liste : « # name: », « # match: » puis les entrées. */
    private static function parse_file($txt) {
        $x = ['name' => '', 'match' => '', 'text' => ''];
        $body = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $txt) as $l) {
            if (preg_match('/^#\s*name\s*:\s*(.*)$/i', $l, $m)) $x['name'] = trim($m[1]);
            elseif (preg_match('/^#\s*match\s*:\s*(.*)$/i', $l, $m)) $x['match'] = trim($m[1]);
            else $body[] = $l;
        }
        $x['text'] = implode("\n", $body);
        return $x;
    }

    public static function admin_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        if (!isset($_POST['tngbook_hnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tngbook_hnonce'])), 'tngbook_hist')) wp_die(esc_html(tngs_t('Jeton de sécurité invalide.')), 403);
        $in = (isset($_POST['hl']) && is_array($_POST['hl'])) ? wp_unslash($_POST['hl']) : [];
        $lists = self::user_lists();
        $clean = function ($x) {
            return ['name' => mb_substr(sanitize_text_field((string) ($x['name'] ?? '')), 0, 60), 'match' => mb_substr(sanitize_text_field((string) ($x['match'] ?? '')), 0, 300), 'text' => self::clean_text($x['text'] ?? '')];
        };
        foreach ($lists as $id => $x) {
            if (!isset($in[$id]) || !is_array($in[$id])) continue;
            if (!empty($in[$id]['delete'])) { unset($lists[$id]); continue; }
            $lists[$id] = $clean($in[$id]);
            if ($lists[$id]['name'] === '') $lists[$id]['name'] = $x['name'] !== '' ? $x['name'] : $id;
        }
        $add = [];
        if (isset($in['new']) && is_array($in['new'])) {
            $n = $clean($in['new']);
            if ($n['text'] !== '' || $n['name'] !== '') $add[] = $n;
        }
        $copy = sanitize_text_field(wp_unslash($_POST['hist_copy'] ?? ''));
        if (preg_match('/^([A-Z]{2}):(fr|en)$/', $copy, $m) && isset(self::countries()[$m[1]])) {
            $al = self::aliases();
            $add[] = ['name' => self::country_name($m[1], $m[2]), 'match' => implode(', ', $al[$m[1]] ?? []), 'text' => self::template_text($m[1], $m[2])];
        }
        if (!empty($_FILES['hist_file']['tmp_name']) && is_uploaded_file($_FILES['hist_file']['tmp_name']) && (int) ($_FILES['hist_file']['size'] ?? 0) < 400000) {   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $f = self::parse_file((string) file_get_contents($_FILES['hist_file']['tmp_name']));   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $f = $clean($f);
            if ($f['text'] !== '') $add[] = $f;
        }
        foreach ($add as $x) {
            if ($x['name'] === '') $x['name'] = tngs_t('Nouvelle liste');
            $lists[self::new_id($lists)] = $x;
        }
        self::save_user_lists($lists);
        wp_safe_redirect(add_query_arg(['page' => 'tngbook', 'tngbook_msg' => 'hist_saved'], admin_url('admin.php')) . '#tngbook-settings');   // section « Livre : réglages » (un identifiant inconnu ouvrirait la première section)
        exit;
    }

    public static function admin_export() {
        if (!current_user_can('manage_options')) wp_die(esc_html(tngs_t('Accès refusé.')), 403);
        check_admin_referer('tngbook_hist_export');
        $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
        $lists = self::user_lists();
        if (!isset($lists[$id])) wp_die(esc_html(tngs_t('Liste introuvable.')), 404);
        $x = $lists[$id];
        $fn = sanitize_file_name('faits-historiques-' . ($x['name'] !== '' ? $x['name'] : $id) . '.txt');
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        echo "# name: " . $x['name'] . "\n# match: " . $x['match'] . "\n" . $x['text'] . "\n";   // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }
}

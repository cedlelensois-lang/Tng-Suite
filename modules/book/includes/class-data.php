<?php
if (!defined('ABSPATH')) exit;

/**
 * Lecture seule des tables TNG et parcours de l'arbre (ascendance Sosa, descendance d'Aboville).
 * Aucun appel à une IA : tout est déduit de la base.
 */
class TNGBook_Data {
    public $people = [], $fams = [], $kidsOf = [], $famsOf = [], $parentFam = [], $famsLoaded = [];
    public $sosa = [], $sosaOf = [], $collapse = [];
    public $desc = [], $descGen = [], $childNum = [], $dupDesc = [], $dupPid = [], $listed = [];
    public $occ = [], $photos = [], $cites = [], $sources = [];
    public $photoDir = '';
    public $notes = [], $mil = [], $mpf = [];
    private $noDiv = false;   // colonnes divdate / divdatetr absentes de la table des familles
    /** Notes liées aux événements : [persID ou famID][BIRT|DEAT|BURI|MARR|… ou ID d'événement] => notes */
    public $evnotes = [];

    private $db, $p, $tree, $living;

    /** $living : 'exclude' | 'anonymize' | 'include' */
    public function __construct($db, $prefix, $tree, $living) {
        $this->db = $db;
        $this->p = $prefix;
        $this->tree = $tree;
        $this->living = $living;
    }

    /* ---------- Utilitaires SQL ---------- */

    private function t($n) { return '`' . $this->p . $n . '`'; }
    private function g($alias = '') { return $this->db->prepare("{$alias}gedcom = %s", $this->tree); }

    private function in(array $a) {
        $v = [];
        foreach ($a as $x) {
            $x = preg_replace('/[^\w\-.]/u', '', (string) $x);
            if ($x !== '') $v[] = $x;
        }
        if (!$v) return "''";
        // Liste IN (...) préparée : chaque identifiant est échappé et placé entre guillemets par $wpdb->prepare().
        return call_user_func_array([$this->db, 'prepare'], array_merge([implode(',', array_fill(0, count($v), '%s'))], $v));
    }

    private function rows($sql) {
        $r = $this->db->get_results($sql, ARRAY_A);
        if ($r === null || $this->db->last_error) {
            error_log('[TNG Suite / Livre] ' . $this->db->last_error);
            throw new Exception(current_user_can('manage_options') ? tngs_t('Erreur SQL TNG :') . ' ' . $this->db->last_error : tngs_t('Erreur de lecture de la base TNG.'));
        }
        return $r;
    }

    private function chunks(array $ids) {
        return array_chunk(array_values(array_unique(array_filter($ids, function ($x) { return $x !== '' && $x !== null; }))), 400);
    }

    /** Arbres (gedcom) dans lesquels l'identifiant existe. */
    public static function trees($db, $prefix, $pid) {
        $pid = preg_replace('/[^\w\-.]/u', '', (string) $pid);
        $r = $db->get_col($db->prepare("SELECT DISTINCT gedcom FROM `{$prefix}people` WHERE personID = %s", $pid));
        return $r ?: [];
    }

    /* ---------- Vie privée ---------- */

    public function hidden($pid) {
        if ($this->living === 'include') return false;
        $p = $this->people[$pid] ?? null;
        return $p && ((int) $p['living'] || (int) $p['private']);
    }

    /** Personne à retirer complètement du livre. */
    public function dropped($pid) {
        return $this->living === 'exclude' && $this->hidden($pid);
    }

    /* ---------- Chargements ---------- */

    public function loadPeople(array $ids) {
        $need = array_diff(array_unique(array_filter($ids)), array_keys($this->people));
        foreach ($this->chunks($need) as $ch) {
            $rows = $this->rows("SELECT personID, firstname, nickname, lnprefix, lastname, sex, living, private, birthdate, birthdatetr, birthplace, baptdate, baptdatetr, baptplace, altbirthtype, altbirthdate, altbirthdatetr, altbirthplace, deathdate, deathdatetr, deathplace, burialdate, burialdatetr, burialplace, burialtype FROM {$this->t('people')} WHERE {$this->g()} AND personID IN (" . $this->in($ch) . ')');
            foreach ($rows as $r) $this->people[$r['personID']] = $r;
        }
    }

    private function loadFams(array $fids) {
        $need = array_diff(array_unique(array_filter($fids)), array_keys($this->fams));
        foreach ($this->chunks($need) as $ch) {
            $rows = $this->famRows("{$this->g()} AND familyID IN (" . $this->in($ch) . ')');
            foreach ($rows as $r) $this->fams[$r['familyID']] = $r;
        }
    }

    /** Famille dans laquelle chaque personne est enfant. */
    public function loadParentFams(array $pids) {
        $todo = [];
        foreach ($pids as $p) {
            if ($p !== '' && !array_key_exists($p, $this->parentFam)) $todo[] = $p;
        }
        foreach ($this->chunks($todo) as $ch) {
            $rows = $this->rows("SELECT personID, familyID FROM {$this->t('children')} WHERE {$this->g()} AND personID IN (" . $this->in($ch) . ') ORDER BY ordernum');
            foreach ($rows as $r) {
                if (empty($this->parentFam[$r['personID']])) $this->parentFam[$r['personID']] = $r['familyID'];
            }
            foreach ($ch as $p) {
                if (!array_key_exists($p, $this->parentFam)) $this->parentFam[$p] = null;
            }
        }
        $f = [];
        foreach ($pids as $p) {
            if (!empty($this->parentFam[$p])) $f[] = $this->parentFam[$p];
        }
        $this->loadFams($f);
    }

    /** Unions et enfants de chaque personne. */
    private function loadFamiliesOf(array $pids) {
        $todo = [];
        foreach ($pids as $p) {
            if (!isset($this->famsLoaded[$p])) $todo[] = $p;
        }
        foreach ($this->chunks($todo) as $ch) {
            $set = array_flip($ch);
            $in = $this->in($ch);
            $rows = $this->famRows("{$this->g()} AND (husband IN ($in) OR wife IN ($in)) ORDER BY marrdatetr, familyID");
            $fids = [];
            foreach ($rows as $r) {
                $fid = $r['familyID'];
                $this->fams[$fid] = $r;
                $fids[] = $fid;
                foreach (['husband', 'wife'] as $role) {
                    $x = $r[$role];
                    if ($x !== '' && isset($set[$x])) {
                        if (!isset($this->famsOf[$x]) || !in_array($fid, $this->famsOf[$x], true)) $this->famsOf[$x][] = $fid;
                    }
                }
            }
            foreach ($ch as $p) {
                $this->famsLoaded[$p] = true;
                if (!isset($this->famsOf[$p])) $this->famsOf[$p] = [];
            }
            $needKids = array_diff($fids, array_keys($this->kidsOf));
            foreach ($this->chunks($needKids) as $fch) {
                $kids = $this->rows("SELECT familyID, personID FROM {$this->t('children')} WHERE {$this->g()} AND familyID IN (" . $this->in($fch) . ') ORDER BY familyID, ordernum');
                foreach ($fch as $f) $this->kidsOf[$f] = [];
                foreach ($kids as $k) $this->kidsOf[$k['familyID']][] = $k['personID'];
            }
        }
    }

    /* ---------- Ascendance (Sosa-Stradonitz) ---------- */

    public function buildAscendants($root, $up) {
        $this->loadPeople([$root]);
        if (!isset($this->people[$root])) {
            throw new Exception(tngs_t('Personne') . ' "' . $root . '" ' . tngs_t('introuvable dans l’arbre') . ' "' . $this->tree . '".');
        }
        $this->sosa = [1 => $root];
        $this->sosaOf = [$root => 1];
        $level = [1 => $root];
        for ($g = 1; $g <= $up && $level; $g++) {
            $this->loadParentFams(array_values($level));
            $next = [];
            foreach ($level as $n => $pid) {
                $fid = $this->parentFam[$pid] ?? null;
                if (!$fid || !isset($this->fams[$fid])) continue;
                $f = $this->fams[$fid];
                if (!empty($f['husband'])) $next[2 * $n] = $f['husband'];
                if (!empty($f['wife'])) $next[2 * $n + 1] = $f['wife'];
            }
            $this->loadPeople(array_values($next));
            $level = [];
            foreach ($next as $n => $pid) {
                if (!isset($this->people[$pid]) || $this->dropped($pid)) continue;
                $this->sosa[$n] = $pid;
                if (isset($this->sosaOf[$pid])) {
                    $this->collapse[$n] = $this->sosaOf[$pid];       // implexe : ancêtre déjà décrit
                } else {
                    $this->sosaOf[$pid] = $n;
                    $level[$n] = $pid;
                }
            }
        }
    }

    /* ---------- Descendance (d'Aboville) ---------- */

    public function buildDescendants($root, $down) {
        $this->loadPeople([$root]);
        if (!isset($this->people[$root])) {
            throw new Exception(tngs_t('Personne') . ' "' . $root . '" ' . tngs_t('introuvable dans l’arbre') . ' "' . $this->tree . '".');
        }
        $this->desc[$root] = ['num' => '1', 'gen' => 1];
        $this->descGen[1] = [$root];
        $items = [['pid' => $root, 'num' => '1']];

        for ($g = 1; $g <= $down + 1 && $items; $g++) {
            $this->loadFamiliesOf(array_column($items, 'pid'));

            $allKids = [];
            foreach ($items as $it) {
                foreach ($this->famsOf[$it['pid']] ?? [] as $fid) {
                    foreach ($this->kidsOf[$fid] ?? [] as $kid) $allKids[] = $kid;
                }
            }
            $this->loadPeople($allKids);

            $next = [];
            foreach ($items as $it) {
                $i = 0;
                foreach ($this->famsOf[$it['pid']] ?? [] as $fid) {
                    foreach ($this->kidsOf[$fid] ?? [] as $kid) {
                        if (!isset($this->people[$kid]) || $this->dropped($kid)) continue;
                        $i++;
                        $num = $it['num'] . '.' . $i;
                        $this->childNum[$fid . '|' . $kid] = $num;
                        if (empty($this->parentFam[$kid])) $this->parentFam[$kid] = $fid;
                        if ($g <= $down) {
                            if (isset($this->desc[$kid])) {
                                $this->dupDesc[$num] = $this->desc[$kid]['num'];     // cousins mariés : déjà décrit
                                $this->dupPid[$num] = $kid;
                            } else {
                                $this->desc[$kid] = ['num' => $num, 'gen' => $g + 1];
                                $this->descGen[$g + 1][] = $kid;
                                $next[] = ['pid' => $kid, 'num' => $num];
                            }
                        } else {
                            $this->listed[$kid] = $num;      // dernière génération : simple mention
                        }
                    }
                }
            }
            $items = $next;
        }
    }

    /* ---------- Compléments : parents, conjoints, métiers, photos, sources ---------- */

    public function finalize($withPhotos, $withSources, $withNotes = false, $withMil = false, $withKids = false, $withSibs = false, $withAllUnions = false, $withMpf = false) {
        $core = array_values(array_unique(array_merge(array_values($this->sosa), array_keys($this->desc), array_keys($this->listed))));
        $this->loadParentFams($core);
        if ($withKids) $this->loadAncestorKids();
        if ($withSibs) $this->loadSiblings($core);

        $sp = [];
        $spOf = array_keys($this->desc);
        if ($withAllUnions) {                     // autres unions des ancêtres : leurs familles, puis leurs conjoints
            $anc = array_values($this->sosa);
            $this->loadFamiliesOf($anc);
            $spOf = array_merge($spOf, $anc);
        }
        foreach ($spOf as $pid) {
            foreach ($this->famsOf[$pid] ?? [] as $fid) {
                $f = $this->fams[$fid] ?? null;
                if (!$f) continue;
                foreach (['husband', 'wife'] as $r) {
                    if (!empty($f[$r])) $sp[] = $f[$r];
                }
            }
        }
        $all = array_values(array_unique(array_merge($core, $sp)));
        $this->loadPeople($all);
        $this->loadParentFams($sp);

        $par = [];
        foreach ($all as $pid) {
            $fid = $this->parentFam[$pid] ?? null;
            if ($fid && isset($this->fams[$fid])) {
                foreach (['husband', 'wife'] as $r) {
                    if (!empty($this->fams[$fid][$r])) $par[] = $this->fams[$fid][$r];
                }
            }
        }
        $all = array_values(array_unique(array_merge($all, $par)));
        $this->loadPeople($all);

        $this->loadOcc($all);
        if ($withPhotos) $this->loadPhotos($all);
        if ($withSources) $this->loadCites($all);
        if ($withNotes) {
            $ids = array_merge($all, array_keys($this->fams));
            $this->loadNotes($ids);
            $this->loadEventNotes($ids);
        }
        if ($withMil) $this->loadMilitary($all);
        if ($withMpf) $this->loadMpf($all);
    }

    /** Fratries (option « frères et sœurs ») : enfants de la famille d'origine de chaque personne, avec leurs fiches. */
    private function loadSiblings(array $pids) {
        $fids = [];
        foreach ($pids as $pid) {
            if (!empty($this->parentFam[$pid])) $fids[] = $this->parentFam[$pid];
        }
        $need = array_diff(array_unique($fids), array_keys($this->kidsOf));
        $kids = [];
        foreach ($this->chunks($need) as $fch) {
            foreach ($fch as $f) $this->kidsOf[$f] = [];
            foreach ($this->rows("SELECT familyID, personID FROM {$this->t('children')} WHERE {$this->g()} AND familyID IN (" . $this->in($fch) . ') ORDER BY familyID, ordernum') as $k) {
                $this->kidsOf[$k['familyID']][] = $k['personID'];
                $kids[] = $k['personID'];
            }
        }
        $this->loadPeople($kids);
    }

    /** Enfants des couples d'ancêtres (modèle « récit ») : enfants de la famille d'origine de chaque ancêtre. */
    private function loadAncestorKids() {
        $fids = [];
        foreach ($this->sosa as $pid) {
            if (!empty($this->parentFam[$pid])) $fids[] = $this->parentFam[$pid];
        }
        $need = array_diff(array_unique($fids), array_keys($this->kidsOf));
        $kids = [];
        foreach ($this->chunks($need) as $fch) {
            foreach ($fch as $f) $this->kidsOf[$f] = [];
            foreach ($this->rows("SELECT familyID, personID FROM {$this->t('children')} WHERE {$this->g()} AND familyID IN (" . $this->in($fch) . ') ORDER BY familyID, ordernum') as $k) {
                $this->kidsOf[$k['familyID']][] = $k['personID'];
                $kids[] = $k['personID'];
            }
        }
        $this->loadPeople($kids);
    }

    /**
     * Données pour les statistiques : enfants (et donc fratries) de toutes les familles chargées, avec leurs fiches,
     * ainsi que tous les conjoints. N'affecte ni la numérotation ni le contenu des fiches.
     */
    public function loadStatsData() {
        $need = array_diff(array_keys($this->fams), array_keys($this->kidsOf));
        foreach ($this->chunks($need) as $fch) {
            $kids = $this->rows("SELECT familyID, personID FROM {$this->t('children')} WHERE {$this->g()} AND familyID IN (" . $this->in($fch) . ') ORDER BY familyID, ordernum');
            foreach ($fch as $f) $this->kidsOf[$f] = [];
            foreach ($kids as $k) $this->kidsOf[$k['familyID']][] = $k['personID'];
        }
        $ids = [];
        foreach ($this->fams as $f) {
            foreach (['husband', 'wife'] as $r) if (!empty($f[$r])) $ids[] = $f[$r];
        }
        foreach ($this->kidsOf as $ks) foreach ($ks as $k) $ids[] = $k;
        $this->loadPeople($ids);
    }

    /** Personnes réellement présentes dans le livre : ascendants, descendants, liste, et conjoints des descendants. */
    public function featured() {
        $feat = [];
        foreach ($this->sosa as $x) $feat[$x] = true;
        foreach (array_keys($this->desc) as $x) $feat[$x] = true;
        foreach (array_keys($this->listed) as $x) $feat[$x] = true;
        foreach (array_keys($this->desc) as $x) {
            foreach ($this->famsOf[$x] ?? [] as $fid) {
                $f = $this->fams[$fid] ?? null;
                if ($f) foreach (['husband', 'wife'] as $r) if (!empty($f[$r])) $feat[$f[$r]] = true;
            }
        }
        return array_keys($feat);
    }

    /**
     * Carte des lieux de vie : lieux des événements de résidence (RESI) des personnes présentes, et coordonnées
     * connues dans la table des lieux de TNG. Remplit $this->resi [personID => [lieux]] et $this->coords [lieu => [lat, lon]].
     */
    public $resi = [], $coords = [];

    /** Résidences (événements RESI) des personnes, du plus ancien au plus récent : [personID => [[lieu, date triable], …]]. */
    public $resiEv = [];

    public function loadResidences(array $pids) {
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows("SELECT e.persfamID, e.eventplace, e.eventdatetr, e.eventID FROM {$this->t('events')} e INNER JOIN {$this->t('eventtypes')} t ON t.eventtypeID = e.eventtypeID WHERE t.tag = 'RESI' AND t.type = 'I' AND " . $this->g('e.') . ' AND e.persfamID IN (' . $this->in($ch) . ") AND e.eventplace <> '' ORDER BY e.eventID");
            $tmp = [];
            foreach ($rows as $i => $r) {
                $v = trim((string) $r['eventplace']);
                if ($v === '') continue;
                $tr = trim((string) ($r['eventdatetr'] ?? ''));
                if ($tr === '' || strpos($tr, '0000') === 0) $tr = '9999-99-99';      // sans date : après les autres, dans l'ordre de saisie
                $tmp[$r['persfamID']][] = [$v, $tr, $i];
            }
            foreach ($tmp as $pid => $list) {
                usort($list, function ($a, $b) { return $a[1] === $b[1] ? $a[2] - $b[2] : strcmp($a[1], $b[1]); });
                $this->resiEv[$pid] = array_map(function ($x) { return [$x[0], $x[1]]; }, $list);
            }
        }
    }

    public function loadMapData(array $pids, array $placeNames) {
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows("SELECT e.persfamID, e.eventplace FROM {$this->t('events')} e INNER JOIN {$this->t('eventtypes')} t ON t.eventtypeID = e.eventtypeID WHERE t.tag = 'RESI' AND t.type = 'I' AND " . $this->g('e.') . ' AND e.persfamID IN (' . $this->in($ch) . ") AND e.eventplace <> ''");
            foreach ($rows as $r) {
                $v = trim($r['eventplace']);
                if ($v !== '') $this->resi[$r['persfamID']][] = $v;
            }
        }
        $names = array_values(array_unique(array_merge($placeNames, array_merge(...array_values($this->resi ?: [[]])))));
        foreach (array_chunk($names, 200) as $ch) {
            $ph = implode(',', array_fill(0, count($ch), '%s'));
            $sql = call_user_func_array([$this->db, 'prepare'], array_merge(["SELECT place, latitude, longitude FROM {$this->t('places')} WHERE gedcom = %s AND place IN ($ph) AND latitude <> '' AND longitude <> ''", $this->tree], $ch));
            foreach ($this->rows($sql) as $r) {
                $la = (float) str_replace(',', '.', $r['latitude']); $lo = (float) str_replace(',', '.', $r['longitude']);
                if ($la != 0.0 || $lo != 0.0) $this->coords[$r['place']] = [$la, $lo];
            }
        }
    }

    private function loadOcc(array $pids) {
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows("SELECT e.persfamID, e.info FROM {$this->t('events')} e INNER JOIN {$this->t('eventtypes')} t ON t.eventtypeID = e.eventtypeID WHERE t.tag = 'OCCU' AND t.type = 'I' AND " . $this->g('e.') . ' AND e.persfamID IN (' . $this->in($ch) . ") AND e.info <> '' ORDER BY e.eventdatetr");
            foreach ($rows as $r) {
                $v = trim($r['info']);
                if ($v !== '' && (!isset($this->occ[$r['persfamID']]) || !in_array($v, $this->occ[$r['persfamID']], true))) {
                    $this->occ[$r['persfamID']][] = $v;
                }
            }
        }
    }

    /** Dossier des photos déclaré dans TNG (table mediatypes), s'il existe. */
    private function loadMediaTypeDir() {
        $old = $this->db->suppress_errors(true);
        $v = $this->db->get_var($this->db->prepare("SELECT path FROM {$this->t('mediatypes')} WHERE mediatypeID = %s", 'photos'));
        $this->db->suppress_errors($old);
        $this->photoDir = is_string($v) ? trim($v) : '';
    }

    private function loadPhotos(array $pids) {
        $this->loadMediaTypeDir();
        $ok = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows("SELECT l.personID, m.path, m.thumbpath, m.usecollfolder, m.mediatypeID FROM {$this->t('medialinks')} l INNER JOIN {$this->t('media')} m ON m.mediaID = l.mediaID WHERE " . $this->g('l.') . " AND l.linktype = 'I' AND l.defphoto = 1 AND l.dontshow = 0 AND l.personID IN (" . $this->in($ch) . ") ORDER BY (m.mediatypeID = 'photos') DESC, l.defphoto DESC, l.ordernum");
            foreach ($rows as $r) {
                if (isset($this->photos[$r['personID']])) continue;
                $ext = strtolower(pathinfo((string) $r['path'], PATHINFO_EXTENSION));
                if (!in_array($ext, $ok, true)) continue;      // ignore PDF, vidéos, etc.
                $this->photos[$r['personID']] = $r;
            }
        }
    }

    /** Notes générales (liées à la personne ou à la famille, hors notes d'événements, hors notes secrètes). */
    private function loadNotes(array $ids) {
        foreach ($this->chunks($ids) as $ch) {
            $base = "SELECT nl.persfamID, x.note FROM {$this->t('notelinks')} nl INNER JOIN {$this->t('xnotes')} x ON x.ID = nl.xnoteID WHERE " . $this->g('nl.') . ' AND nl.persfamID IN (' . $this->in($ch) . ") AND nl.eventID = ''";
            try {
                $rows = $this->rows($base . ' AND nl.secret = 0 ORDER BY nl.persfamID, nl.ordernum');
            } catch (Exception $e) {                       // anciennes versions sans colonne « secret »
                $rows = $this->rows($base . ' ORDER BY nl.persfamID, nl.ordernum');
            }
            foreach ($rows as $r) {
                $t = trim((string) $r['note']);
                if ($t !== '') $this->notes[$r['persfamID']][] = $t;
            }
        }
    }

    /** Lecture des familles, avec la date de divorce quand la colonne existe. */
    private function famRows($where) {
        $cols = 'familyID, husband, wife, marrdate, marrdatetr, marrplace, living, private';
        if (!$this->noDiv) {
            try {
                return $this->rows("SELECT $cols, divdate, divdatetr FROM {$this->t('families')} WHERE $where");
            } catch (Exception $e) {
                $this->noDiv = true;
            }
        }
        return $this->rows("SELECT $cols FROM {$this->t('families')} WHERE $where");
    }

    /** Notes attachées aux événements (naissance, mariage, décès, inhumation… et événements personnalisés), hors notes secrètes. */
    private function loadEventNotes(array $ids) {
        foreach ($this->chunks($ids) as $ch) {
            $base = "SELECT nl.persfamID, nl.eventID, x.note FROM {$this->t('notelinks')} nl INNER JOIN {$this->t('xnotes')} x ON x.ID = nl.xnoteID WHERE " . $this->g('nl.') . ' AND nl.persfamID IN (' . $this->in($ch) . ") AND nl.eventID <> ''";
            try {
                $rows = $this->rows($base . ' AND nl.secret = 0 ORDER BY nl.persfamID, nl.ordernum');
            } catch (Exception $e) {                       // anciennes versions sans colonne « secret »
                $rows = $this->rows($base . ' ORDER BY nl.persfamID, nl.ordernum');
            }
            foreach ($rows as $r) {
                $t = trim((string) $r['note']);
                if ($t !== '') $this->evnotes[$r['persfamID']][strtoupper(trim((string) $r['eventID']))][] = $t;
            }
        }
    }

    /** Événements militaires individuels (balises MILI/_MILT… ou type dont le nom évoque le militaire / la guerre). */
    private function loadMilitary(array $pids) {
        foreach ($this->militaryRows($pids) as $pid => $evs) foreach ($evs as $ev) $this->mil[$pid][] = $ev;
    }

    private function militaryRows(array $pids) {
        $out = [];
        $like = [];
        foreach (['milit', 'guerre', 'army', 'soldat', 'conscri', 'combattant', 'mobilis', 'poilu', 'bataille', 'campagne', 'prisonnier'] as $w) {
            $pat = '%' . $this->db->esc_like($w) . '%';
            $like[] = $this->db->prepare('t.description LIKE %s', $pat);
            $like[] = $this->db->prepare('t.display LIKE %s', $pat);
        }
        $cond = "(UPPER(t.tag) IN ('MILI','_MILT','MILT','_MIL','MIL','_MILI') OR " . implode(' OR ', $like) . ')';
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows("SELECT e.eventID, e.persfamID, e.eventdate, e.eventdatetr, e.eventplace, e.info, e.agency, e.cause FROM {$this->t('events')} e INNER JOIN {$this->t('eventtypes')} t ON t.eventtypeID = e.eventtypeID WHERE t.type = 'I' AND $cond AND " . $this->g('e.') . ' AND e.persfamID IN (' . $this->in($ch) . ') ORDER BY e.eventdatetr');
            foreach ($rows as $r) {
                $txt = [];
                foreach (['agency', 'info', 'cause'] as $k) {
                    $v = trim((string) $r[$k]);
                    if ($v !== '') $txt[] = rtrim($v, " .;,");
                }
                $out[$r['persfamID']][] = [
                    'id' => (string) $r['eventID'], 'dt' => $r['eventdatetr'], 'd' => $r['eventdate'], 'pl' => $r['eventplace'], 'txt' => implode(', ', $txt),
                    'det' => trim(preg_replace('/\\s*[\\r\\n]+\\s*/', ' ; ', trim((string) $r['info'] . ' ' . ((string) $r['agency'] !== '' ? "\n" . $r['agency'] : '')))),
                ];
            }
        }
        return $out;
    }

    /**
     * « Mort pour la France » : personnes dont une cause (décès) commence par cette mention. Le conflit (guerre, bataille) est le
     * complément éventuel de la cause, sinon le texte des événements militaires de la personne. $this->mpf[pid] = ['war' => texte].
     */
    private function loadMpf(array $pids) {
        $pat = $this->db->esc_like('mort pour la fran') . '%';
        $pat2 = $this->db->esc_like('morte pour la fran') . '%';
        $cause = [];
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows($this->db->prepare("SELECT e.persfamID, e.cause FROM {$this->t('events')} e WHERE (e.cause LIKE %s OR e.cause LIKE %s)", $pat, $pat2) . ' AND ' . $this->g('e.') . ' AND e.persfamID IN (' . $this->in($ch) . ')');
            foreach ($rows as $r) $cause[$r['persfamID']] = trim((string) $r['cause']);
        }
        if (!$cause) return;
        $mil = $this->militaryRows(array_keys($cause));
        foreach ($cause as $pid => $c) {
            $rest = trim(preg_replace('/^morte?\s+pour\s+la\s+fran[cç]e\b/iu', '', $c), " \t\n\r-–—:;,.()");
            // Conflit : suite de la cause ; sinon champ date des événements militaires (« 1914-1918 ») ; détails : champ « Détail » (grade, unité…)
            $dates = []; $dets = [];
            foreach ($mil[$pid] ?? [] as $ev) {
                $dd = trim((string) $ev['d']);
                if ($dd !== '' && !in_array($dd, $dates, true)) $dates[] = $dd;
                if ($ev['det'] !== '' && !in_array($ev['det'], $dets, true)) $dets[] = $ev['det'];
            }
            // le champ Date est toujours affiché dès qu'il est renseigné, à la suite du conflit éventuel indiqué dans la cause
            $dtxt = implode(' ; ', array_slice($dates, 0, 2));
            if ($dtxt !== '' && mb_stripos($rest, $dtxt) === false) $rest = $rest === '' ? $dtxt : $rest . ', ' . $dtxt;
            $this->mpf[$pid] = ['war' => $rest, 'det' => implode(' ; ', array_slice($dets, 0, 2))];
        }
    }

    private function loadCites(array $pids) {
        $sids = [];
        foreach ($this->chunks($pids) as $ch) {
            $rows = $this->rows("SELECT persfamID, sourceID FROM {$this->t('citations')} WHERE {$this->g()} AND persfamID IN (" . $this->in($ch) . ") AND sourceID <> ''");
            foreach ($rows as $r) {
                if (!isset($this->cites[$r['persfamID']]) || !in_array($r['sourceID'], $this->cites[$r['persfamID']], true)) {
                    $this->cites[$r['persfamID']][] = $r['sourceID'];
                }
                $sids[] = $r['sourceID'];
            }
        }
        foreach ($this->chunks($sids) as $ch) {
            $rows = $this->rows("SELECT sourceID, shorttitle, title FROM {$this->t('sources')} WHERE {$this->g()} AND sourceID IN (" . $this->in($ch) . ')');
            foreach ($rows as $r) {
                $this->sources[$r['sourceID']] = $r['title'] !== '' ? $r['title'] : $r['shorttitle'];
            }
        }
    }
}

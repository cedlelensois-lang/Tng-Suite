<?php
/**
 * Export du livre en DOCX (Word) et ODT (OpenDocument), sans bibliothèque externe.
 * Le HTML produit par TNGBook_Renderer (mode « doc ») est converti en blocs (titres, paragraphes, listes, photos),
 * puis écrit au format voulu dans une archive ZIP (extension PHP « zip » requise).
 */
if (!defined('ABSPATH')) exit;

class TNGBook_Doc {
    private $blocks = [];
    private $images = [];          // [ ['name'=>, 'ext'=>, 'mime'=>, 'bin'=>, 'w'=>px, 'h'=>px] ]
    private $c;                    // couleurs
    private $pb = false;           // saut de page avant le prochain paragraphe
    private $pendBm = [];          // signets en attente
    private $pendImg = null;       // photo à ancrer au prochain paragraphe
    private $pendFloat = [];       // images du texte libre (patronyme) alignées à gauche / à droite : ancrées au prochain paragraphe de texte, le texte les entoure
    private $usedBm = [];
    private $groups = [];         // fiche => clé de la photo (tableau texte | photo)
    private $gid = 0;
    private $tpl = [];            // modèle : docfont, cols, banner, center, band, sz

    public static function available() {
        return class_exists('ZipArchive') && class_exists('DOMDocument');
    }

    /** @return string chemin d'un fichier temporaire contenant le document */
    public static function convert($html, array $colors, array $meta, $format) {
        $d = new self();
        $d->c = $colors;
        $d->tpl = isset($meta['tpl']) && is_array($meta['tpl']) ? $meta['tpl'] : [];
        $d->parse($html);
        if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam('tngbook-doc');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) throw new Exception('Impossible de créer le fichier.');
        if ($format === 'odt') $d->writeOdt($zip, $meta); else $d->writeDocx($zip, $meta);
        $zip->close();
        return $tmp;
    }

    /* ======================================================== HTML → blocs */

    private function parse($html) {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) $this->walk($body, ['cls' => '']);
    }

    private static function cls(DOMElement $e) { return ' ' . trim($e->getAttribute('class')) . ' '; }
    private static function has(DOMElement $e, $c) { return strpos(self::cls($e), ' ' . $c . ' ') !== false; }

    private function bookmarkFrom(DOMElement $e) {
        $id = $e->getAttribute('id');
        if ($id !== '') $this->pendBm[] = self::bmName($id);
    }

    private static function bmName($id) {
        $n = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $id);
        if ($n === '' || !preg_match('/^[A-Za-z_]/', $n)) $n = '_' . $n;
        return substr($n, 0, 38);
    }

    private function walk(DOMElement $parent, array $ctx) {
        foreach ($parent->childNodes as $n) {
            if ($n->nodeType === XML_TEXT_NODE) {
                if (trim($n->nodeValue) !== '') $this->para($this->inlineOf([$n], $ctx), 'body', $ctx);
                continue;
            }
            if (!($n instanceof DOMElement)) continue;
            $tag = strtolower($n->tagName);
            if (in_array($tag, ['style', 'script', 'head', 'title', 'svg', 'button'], true)) continue;
            if (self::has($n, 'noprint')) continue;
            $c = $ctx;
            switch ($tag) {
                case 'div':
                    if (self::has($n, 'author') || self::has($n, 'gen-date') || self::has($n, 'cover-stats')) { $c['cover'] = true; $this->paragraph($n, $c); break; }
                    if (self::has($n, 'part')) $this->pb = true;
                    if (self::has($n, 'cover')) $c['cover'] = true;
                    if (self::has($n, 'fiche')) { $c['fiche'] = true; $this->bookmarkFrom($n); $this->ficheGroup($n, $c); break; }
                    if (self::has($n, 'cols')) $c['cols'] = true;
                    if (self::has($n, 'notice')) $c['just'] = true;
                    if (self::has($n, 'toc')) $c['toc'] = true;
                    $this->walk($n, $c);
                    if (self::has($n, 'surn')) $this->flushFloats($c);   // image flottante restée sans paragraphe : elle prend sa place sous forme d'image seule
                    break;
                case 'table': $this->tableBlock($n, $c); break;
                case 'h1': $this->heading($n, 'title', $c); break;
                case 'h2': $this->heading($n, 'h1', $c); break;
                case 'h3': $this->heading($n, 'h2', $c); break;
                case 'h4': $this->heading($n, 'h3', $c); break;
                case 'h5': $this->heading($n, 'h4', $c); break;
                case 'p': $this->paragraph($n, $c); break;
                case 'img':
                    if (self::has($n, 'tfloat')) { $this->floatOf($n); break; }
                    if (self::has($n, 'tpng')) {          // schéma d'arbre : image seule, centrée
                        $k = $this->image($n->getAttribute('src'));
                        if ($k) {
                            $this->blocks[] = ['fig' => $k, 'cm' => max(1.0, (float) $n->getAttribute('data-cm')), 'cols' => !empty($c['cols']), 'pb' => $this->pb, 'bm' => $this->pendBm, 'runs' => [], 'style' => 'body', 'o' => [], 'img' => null];
                            $this->pb = false; $this->pendBm = [];
                        }
                        break;
                    }
                    if (!self::has($n, 'ph')) { $this->pendImg = $this->image($n->getAttribute('src')); break; }
                    $this->pendImg = $this->image($n->getAttribute('src'));
                    if ($this->pendImg && self::has($n, 'rnd')) $this->images[$this->pendImg]['rnd'] = true;   // silhouette ronde : pas de cadre carré autour
                    break;
                case 'ul': case 'ol': $this->listOf($n, $c, 0); break;
                default: $this->walk($n, $c);
            }
        }
    }

    /** Fiche : si elle porte une photo, texte et photo vont dans un tableau sans bordure (pas de superposition possible). */
    private function ficheGroup(DOMElement $n, array $c) {
        $start = count($this->blocks);
        $this->walk($n, $c);
        if (isset($this->blocks[$start]) && $this->blocks[$start]['img']) {
            $g = ++$this->gid;
            $this->groups[$g] = $this->blocks[$start]['img'];
            for ($i = $start, $e = count($this->blocks); $i < $e; $i++) { $this->blocks[$i]['grp'] = $g; $this->blocks[$i]['img'] = null; }
        }
    }

    /** Tableau simple : lignes de cellules (th = en-tête), texte en ligne uniquement. */
    private function tableBlock(DOMElement $t, array $ctx) {
        $rows = [];
        foreach ($t->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof DOMElement)) continue;
                $tag = strtolower($td->tagName);
                if ($tag !== 'td' && $tag !== 'th') continue;
                $cells[] = ['runs' => $this->inlineOf($td->childNodes, $ctx), 'th' => $tag === 'th', 'r' => self::has($td, 'r'), 'span' => max(1, (int) $td->getAttribute('colspan')), 'g' => self::has($td, 'gh')];
            }
            if ($cells) $rows[] = $cells;
        }
        if (!$rows) return;
        $this->blocks[] = ['tbl' => $rows, 'cols' => !empty($ctx['cols']), 'pb' => $this->pb, 'bm' => $this->pendBm, 'runs' => [], 'style' => 'body', 'o' => [], 'img' => null];
        $this->pb = false; $this->pendBm = [];
    }

    private function heading(DOMElement $e, $style, array $ctx) {
        $this->bookmarkFrom($e);
        $ctx['head'] = true;
        $o = !empty($ctx['cover']) ? ['align' => 'center'] : [];
        if (self::has($e, 'gen')) $o['gen'] = true;
        if (!empty($this->tpl['center']) && empty($ctx['fiche']) && ($style === 'h1' || $style === 'h2')) $o['align'] = 'center';
        if (!empty($this->tpl['banner']) && self::has($e, 'gen')) $o['align'] = 'center';
        $this->para($this->inlineOf($e->childNodes, $ctx), $style, $ctx, $o);
    }

    private function paragraph(DOMElement $e, array $ctx) {
        $cls = self::cls($e);
        $o = [];
        $style = 'body';
        if (!empty($ctx['cover'])) {
            $o['align'] = 'center';
            if (self::has($e, 'author')) $style = 'author'; elseif (self::has($e, 'gen-date')) $style = 'gendate'; elseif (self::has($e, 'cover-stats')) $style = 'small';
        }
        if (self::has($e, 'src')) $style = 'small';
        if (self::has($e, 'evnote')) { $style = 'evnote'; }
        if (self::has($e, 'cap')) { $style = 'small'; $o['align'] = 'center'; }
        if (strpos($cls, ' l2 ') !== false) { $style = 'toc2'; }
        elseif (!empty($ctx['toc'])) { $style = 'toc'; }
        if (self::has($e, 'kidshead')) $o['italic'] = true;
        if (self::has($e, 'nh')) $o['bold'] = true;
        if (!empty($ctx['just']) || (!empty($ctx['fiche']) && $style === 'body' && empty($this->tpl['cols']) && empty($this->tpl['modern']))) $o['align'] = 'both';
        $this->bookmarkFrom($e);
        $this->para($this->inlineOf($e->childNodes, $ctx), $style, $ctx, $o);
    }

    private function listOf(DOMElement $ul, array $ctx, $depth) {
        $outline = self::has($ul, 'otree') || $depth > 0;
        foreach ($ul->childNodes as $li) {
            if (!($li instanceof DOMElement) || strtolower($li->tagName) !== 'li') continue;
            $inl = []; $subs = [];
            foreach ($li->childNodes as $ch) {
                if ($ch instanceof DOMElement && in_array(strtolower($ch->tagName), ['ul', 'ol'], true)) $subs[] = $ch; else $inl[] = $ch;
            }
            $runs = $this->inlineOf($inl, $ctx);
            $bullet = self::has($ul, 'kids') ? '• ' : '';
            if ($bullet !== '') array_unshift($runs, ['t' => $bullet]);
            $o = ['indent' => $outline ? 0.55 * $depth : (self::has($ul, 'kids') ? 1.2 : 0)];
            $this->para($runs, $outline ? 'tree' : 'body', $ctx, $o);
            foreach ($subs as $s) $this->listOf($s, $ctx, $depth + 1);
        }
    }

    /** Retourne la liste des runs d'un ensemble de nœuds en ligne. */
    private function inlineOf($nodes, array $ctx, array $st = []) {
        $runs = [];
        foreach ($nodes as $n) {
            if ($n->nodeType === XML_TEXT_NODE) {
                $t = preg_replace('/\s+/u', ' ', $n->nodeValue);
                if ($t !== '') $runs[] = ['t' => $t] + $st;
                continue;
            }
            if (!($n instanceof DOMElement)) continue;
            $tag = strtolower($n->tagName);
            $s = $st;
            if ($tag === 'br') { $runs[] = ['br' => true]; continue; }
            if ($tag === 'img' && self::has($n, 'tfloat')) { $this->floatOf($n); continue; }
            if ($tag === 'svg' || $tag === 'img') continue;
            if ($n->hasAttribute('id')) $this->pendBm[] = self::bmName($n->getAttribute('id'));
            if ($tag === 'strong' || $tag === 'b') $s['b'] = true;
            if ($tag === 'em' || $tag === 'i') $s['i'] = true;
            if ($tag === 'span') {
                if (self::has($n, 'num')) { $s['b'] = true; $s['c'] = $this->c['head']; }
                if (self::has($n, 'yrs')) { $s['c'] = $this->c['meta']; $s['sz'] = 10; }
                if (self::has($n, 'sx')) { $s['c'] = $this->c['meta']; }
                if (self::has($n, 'on')) { $s['b'] = true; }
            }
            if ($tag === 'a') {
                $h = $n->getAttribute('href');
                if (strlen($h) > 1 && $h[0] === '#') $s['link'] = self::bmName(substr($h, 1));
                if (self::has($n, 'ref') && empty($ctx['head'])) $s['c'] = $this->c['head'];
            }
            foreach ($this->inlineOf($n->childNodes, $ctx, $s) as $r) $runs[] = $r;
        }
        return $runs;
    }

    /** Image alignée à gauche / à droite dans le texte libre : mise en attente jusqu'au prochain paragraphe de texte. */
    private function floatOf(DOMElement $n) {
        $k = $this->image($n->getAttribute('src'));
        if (!$k) return;
        $this->pendFloat[] = ['k' => $k, 'cm' => max(1.0, (float) $n->getAttribute('data-cm')), 'side' => $n->getAttribute('data-side') === 'right' ? 'right' : 'left'];
    }

    private function flushFloats(array $c) {
        foreach ($this->pendFloat as $f) {
            $this->blocks[] = ['fig' => $f['k'], 'cm' => $f['cm'], 'cols' => !empty($c['cols']), 'pb' => false, 'bm' => [], 'runs' => [], 'style' => 'body', 'o' => [], 'img' => null];
        }
        $this->pendFloat = [];
    }

    private function image($src) {
        if (!preg_match('#^data:(image/(?:jpeg|png|gif));base64,(.+)$#s', (string) $src, $m)) return null;
        $bin = base64_decode($m[2], true);
        if ($bin === false) return null;
        $key = md5($bin);
        if (isset($this->images[$key])) return $key;
        $sz = @getimagesizefromstring($bin);
        if (!$sz) return null;
        $ext = $m[1] === 'image/png' ? 'png' : ($m[1] === 'image/gif' ? 'gif' : 'jpg');
        $this->images[$key] = ['name' => 'img' . (count($this->images) + 1) . '.' . $ext, 'ext' => $ext, 'mime' => $m[1], 'bin' => $bin, 'w' => max(1, $sz[0]), 'h' => max(1, $sz[1])];
        return $key;
    }

    private function para(array $runs, $style, array $ctx, array $o = []) {
        // nettoyage des espaces en début et fin de paragraphe
        while ($runs && isset($runs[0]['t'])) { $runs[0]['t'] = ltrim($runs[0]['t']); if ($runs[0]['t'] === '') array_shift($runs); else break; }
        while ($runs && isset($runs[count($runs) - 1]['t'])) { $k = count($runs) - 1; $runs[$k]['t'] = rtrim($runs[$k]['t']); if ($runs[$k]['t'] === '') array_pop($runs); else break; }
        $hasText = false;
        foreach ($runs as $r) if (isset($r['t']) || isset($r['br'])) { $hasText = true; break; }
        if (!$hasText && !$this->pendImg) { return; }
        $b = ['runs' => $runs, 'style' => $style, 'o' => $o, 'cols' => !empty($ctx['cols']), 'pb' => $this->pb, 'bm' => $this->pendBm, 'img' => $this->pendImg, 'flt' => ($style === 'body' && $hasText) ? $this->pendFloat : []];
        $this->pb = false; $this->pendBm = []; $this->pendImg = null;
        if ($style === 'body' && $hasText) $this->pendFloat = [];
        $this->blocks[] = $b;
    }

    /* ======================================================== caractéristiques de style */

    private static function xml($s) {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Taille (pt), gras, italique, couleur, avant/après (pt) par style de paragraphe. */
    private function spec($style) {
        $c = $this->c;
        $t = [
            'body' => ['sz' => 11, 'b' => false, 'i' => false, 'c' => $c['text'], 'bf' => 0, 'af' => 4],
            'title' => ['sz' => 30, 'b' => true, 'i' => false, 'c' => $c['title'], 'bf' => 170, 'af' => 18],
            'author' => ['sz' => 14, 'b' => false, 'i' => false, 'c' => $c['text'], 'bf' => 40, 'af' => 0],
            'gendate' => ['sz' => 10, 'b' => false, 'i' => false, 'c' => $c['meta'], 'bf' => 130, 'af' => 0],
            'h1' => ['sz' => 22, 'b' => true, 'i' => false, 'c' => $c['title'], 'bf' => 0, 'af' => 14, 'lvl' => 1],
            'h2' => ['sz' => 15, 'b' => true, 'i' => false, 'c' => $c['head'], 'bf' => 18, 'af' => 8, 'lvl' => 2],
            'h3' => ['sz' => 12.5, 'b' => true, 'i' => false, 'c' => $c['name'], 'bf' => 8, 'af' => 3, 'lvl' => 3],
            'h4' => ['sz' => 12, 'b' => true, 'i' => false, 'c' => $c['head'], 'bf' => 8, 'af' => 3, 'lvl' => 4],
            'small' => ['sz' => 9, 'b' => false, 'i' => false, 'c' => $c['meta'], 'bf' => 0, 'af' => 3],
            'evnote' => ['sz' => 10, 'b' => false, 'i' => true, 'c' => $c['text'], 'bf' => 0, 'af' => 4, 'ind' => 1.2],
            'toc' => ['sz' => 11, 'b' => false, 'i' => false, 'c' => $c['text'], 'bf' => 0, 'af' => 2],
            'toc2' => ['sz' => 10, 'b' => false, 'i' => false, 'c' => $c['text'], 'bf' => 0, 'af' => 2, 'ind' => 1.2],
            'tree' => ['sz' => 10, 'b' => false, 'i' => false, 'c' => $c['text'], 'bf' => 0, 'af' => 1.5],
        ];
        if (!empty($this->tpl['sz'])) {
            $k = (float) $this->tpl['sz'];
            $t['body']['sz'] = $k; $t['toc']['sz'] = $k;
            $t['evnote']['sz'] = max(8, $k - 1); $t['toc2']['sz'] = max(8, $k - 1); $t['tree']['sz'] = max(8, $k - 1);
        }
        return $t[$style] ?? $t['body'];
    }

    private function docFont() {
        $f = trim((string) ($this->tpl['docfont'] ?? ''));
        return $f !== '' ? preg_replace('/[^\w \-]/u', '', $f) : 'Georgia';
    }

    private function band() {
        return (string) ($this->tpl['band'] ?? '#eee6d4');
    }

    private static function hex($c) { return strtoupper(ltrim((string) $c, '#')); }

    /* ======================================================== DOCX */

    private function writeDocx(ZipArchive $zip, array $meta) {
        $rels = ''; $media = [];
        foreach ($this->images as $key => $im) {
            $rid = 'rIdImg' . (count($media) + 1);
            $media[$key] = $rid;
            $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $im['name'] . '"/>';
            $zip->addFromString('word/media/' . $im['name'], $im['bin']);
        }
        $bmId = 0; $docPr = 0; $relH = 0; $body = '';
        $open = null;
        $gap = '<w:p><w:pPr><w:spacing w:before="0" w:after="60" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
        $colsOn = false; $secCount = 0; $openCols = false;
        $sect = function ($cols, $cont) {
            return '<w:sectPr><w:footerReference w:type="default" r:id="rIdFooter"/>' . ($cont ? '<w:type w:val="continuous"/>' : '')
                . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1247" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>'
                . ($cols ? '<w:cols w:num="2" w:space="567"/>' : '') . '</w:sectPr>';
        };
        $closeTbl = function ($g, $cols) use (&$docPr, $media) {
            $im = $this->images[$this->groups[$g]]; $docPr++;
            $cx = $cols ? 720000 : 1152000; $cy = (int) round($cx * $im['h'] / $im['w']);
            return '</w:tc><w:tc><w:tcPr><w:tcW w:w="' . ($cols ? 1000 : 2000) . '" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:spacing w:before="40" w:after="0"/><w:jc w:val="right"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
                . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $docPr . '" name="Photo ' . $docPr . '"/><wp:cNvGraphicFramePr/>'
                . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $docPr . '" name="' . $im['name'] . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                . '<pic:blipFill><a:blip r:embed="' . $media[$this->groups[$g]] . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:tc></w:tr></w:tbl>';
        };
        foreach ($this->blocks as $b) {
            $g = $b['grp'] ?? null;
            $bc = !empty($b['cols']) && !empty($this->tpl['cols']);
            if ($g !== $open && $open !== null) { $body .= $closeTbl($open, $openCols) . $gap; $open = null; }
            // changement de mise en colonnes : fin de section (continue, sans saut de page)
            if ($bc !== $colsOn) {
                if ($body !== '') {
                    $body .= '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/>' . $sect($colsOn, $secCount > 0) . '</w:pPr></w:p>';
                    $secCount++;
                }
                $colsOn = $bc;
            }
            if ($g !== $open) {
                if ($g !== null && isset($media[$this->groups[$g]])) {
                    $tw = $colsOn ? 4535 : 9638; $pw = $colsOn ? 1000 : 2000; $xw = $tw - $pw;
                    $body .= '<w:tbl><w:tblPr><w:tblW w:w="' . $tw . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                        . '<w:tblGrid><w:gridCol w:w="' . $xw . '"/><w:gridCol w:w="' . $pw . '"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="' . $xw . '" w:type="dxa"/></w:tcPr>';
                } else { $g = null; }
                $open = $g; $openCols = $colsOn;
            }
            if (isset($b['tbl'])) { $body .= $this->docxTable($b['tbl']) . $gap; continue; }
            if (isset($b['fig'])) {
                $im = $this->images[$b['fig']]; $docPr++;
                $cm = min($b['cm'], $colsOn ? 8.0 : 17.0);
                $cx = (int) round($cm * 360000); $cy = (int) round($cx * $im['h'] / $im['w']);
                $figBm = '';
                foreach ($b['bm'] ?? [] as $name) {
                    if (isset($this->usedBm[$name])) continue;
                    $this->usedBm[$name] = 1; $bmId++;
                    $figBm .= '<w:bookmarkStart w:id="' . $bmId . '" w:name="' . $name . '"/><w:bookmarkEnd w:id="' . $bmId . '"/>';
                }
                $body .= '<w:p><w:pPr><w:keepLines/>' . ($b['pb'] ? '<w:pageBreakBefore/>' : '') . '<w:spacing w:before="120" w:after="120"/><w:jc w:val="center"/></w:pPr>'
                    . $figBm
                    . '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $docPr . '" name="Arbre ' . $docPr . '"/><wp:cNvGraphicFramePr/>'
                    . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                    . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $docPr . '" name="' . $im['name'] . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                    . '<pic:blipFill><a:blip r:embed="' . $media[$b['fig']] . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                    . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                    . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
                continue;
            }
            $sp = $this->spec($b['style']);
            $o = $b['o'];
            $map = ['title' => 'Title', 'h1' => 'Heading1', 'h2' => 'Heading2', 'h3' => 'Heading3', 'h4' => 'Heading4'];
            $ppr = '';
            if (isset($map[$b['style']])) $ppr .= '<w:pStyle w:val="' . $map[$b['style']] . '"/>';
            if (isset($sp['lvl']) || $b['style'] === 'title') $ppr .= '<w:keepNext/>';
            if ($b['pb']) $ppr .= '<w:pageBreakBefore/>';
            if (!empty($o['gen']) && empty($this->tpl['solid'])) { $bc = self::hex($this->c['head']); $ppr .= '<w:pBdr><w:top w:val="single" w:sz="16" w:space="3" w:color="' . $bc . '"/><w:bottom w:val="single" w:sz="6" w:space="3" w:color="' . $bc . '"/></w:pBdr>'; }
            if (!empty($o['gen']) && !empty($this->tpl['banner'])) $ppr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . self::hex(!empty($this->tpl['solid']) ? $this->c['head'] : $this->band()) . '"/>';
            if (!isset($map[$b['style']])) $ppr .= '<w:spacing w:before="' . (int) round($sp['bf'] * 20) . '" w:after="' . (int) round($sp['af'] * 20) . '"/>';
            $ind = ($sp['ind'] ?? 0) + ($o['indent'] ?? 0);
            if ($ind > 0) $ppr .= '<w:ind w:left="' . (int) round($ind * 567) . '"/>';
            $jc = $o['align'] ?? (($b['style'] === 'title' || !empty($o['gen'])) ? 'center' : null);
            if ($jc) $ppr .= '<w:jc w:val="' . $jc . '"/>';
            $x = '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '');
            foreach ($b['bm'] as $name) {
                if (isset($this->usedBm[$name])) continue;
                $this->usedBm[$name] = 1; $bmId++;
                $x .= '<w:bookmarkStart w:id="' . $bmId . '" w:name="' . $name . '"/><w:bookmarkEnd w:id="' . $bmId . '"/>';
            }
            foreach ($b['flt'] ?? [] as $f) {   // image à gauche / à droite : ancrée dans le paragraphe, le texte l'entoure
                $im = $this->images[$f['k']]; $docPr++; $relH++;
                $cm = min($f['cm'], $colsOn ? 4.0 : 10.0);
                $cx = (int) round($cm * 360000); $cy = (int) round($cx * $im['h'] / $im['w']);
                $left = $f['side'] === 'left';
                $x .= '<w:r><w:drawing><wp:anchor distT="0" distB="72000" distL="' . ($left ? 0 : 216000) . '" distR="' . ($left ? 216000 : 0) . '" simplePos="0" relativeHeight="' . (251650000 + $relH) . '" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="0">'
                    . '<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="column"><wp:align>' . ($left ? 'left' : 'right') . '</wp:align></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV>'
                    . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapSquare wrapText="bothSides"/>'
                    . '<wp:docPr id="' . $docPr . '" name="Image ' . $docPr . '"/><wp:cNvGraphicFramePr/>'
                    . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                    . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $docPr . '" name="' . $im['name'] . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                    . '<pic:blipFill><a:blip r:embed="' . $media[$f['k']] . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                    . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                    . '</a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>';
            }
            foreach ($b['runs'] as $r) {
                if (!empty($r['br'])) { $x .= '<w:r><w:br/></w:r>'; continue; }
                $rpr = '';
                $bold = !empty($r['b']) || !empty($o['bold']);
                $ital = !empty($r['i']) || !empty($o['italic']);
                if ($bold && !$sp['b']) $rpr .= '<w:b/>';
                if ($ital && !$sp['i']) $rpr .= '<w:i/>';
                if (!empty($o['gen']) && !empty($this->tpl['solid'])) $rpr .= '<w:color w:val="FFFFFF"/>';
                elseif (!empty($r['c']) && strtolower($r['c']) !== strtolower($sp['c'])) $rpr .= '<w:color w:val="' . self::hex($r['c']) . '"/>';
                if (!empty($r['sz'])) $rpr .= '<w:sz w:val="' . (int) round($r['sz'] * 2) . '"/><w:szCs w:val="' . (int) round($r['sz'] * 2) . '"/>';
                $run = '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . self::xml($r['t']) . '</w:t></w:r>';
                $x .= !empty($r['link']) ? '<w:hyperlink w:anchor="' . $r['link'] . '" w:history="1">' . $run . '</w:hyperlink>' : $run;
            }
            $body .= $x . '</w:p>';
        }
        if ($open !== null) $body .= $closeTbl($open, $openCols) . $gap;
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . $sect($colsOn, $secCount > 0) . '</w:body></w:document>');
        $zip->addFromString('word/styles.xml', $this->docxStyles());
        $zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:color w:val="' . self::hex($this->c['meta']) . '"/><w:sz w:val="18"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>' . $rels . '</Relationships>');
        $ct = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/><Default Extension="gif" ContentType="image/gif"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($meta['title'] ?? '') . '</dc:title><dc:creator>' . self::xml($meta['author'] ?? '') . '</dc:creator><dc:language>' . self::xml($meta['lang'] ?? 'fr') . '</dc:language></cp:coreProperties>');
    }

    private function docxTable(array $rows) {
        $n = 0;
        foreach ($rows as $r) $n = max($n, count($r));
        $w = $n === 2 ? [4000, 5638] : ($n === 6 ? [1250, 2040, 1700, 2380, 1700, 568] : array_fill(0, $n, (int) floor(9638 / $n)));
        $x = '<w:tbl><w:tblPr><w:tblW w:w="9638" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:bottom w:val="single" w:sz="4" w:space="0" w:color="C9B8B8"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="E6DEDE"/></w:tblBorders>'
            . '<w:tblCellMar><w:left w:w="60" w:type="dxa"/><w:right w:w="60" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($w as $cw) $x .= '<w:gridCol w:w="' . $cw . '"/>';
        $x .= '</w:tblGrid>';
        foreach ($rows as $row) {
            $x .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            $cells = array_values($row);
            $used = 0; foreach ($cells as $c0) $used += min($n, max(1, (int) ($c0['span'] ?? 1)));
            while ($used < $n) { $cells[] = ['runs' => [], 'th' => false, 'r' => false]; $used++; }
            $i = 0;
            foreach ($cells as $c) {
                if ($i >= $n) break;
                $span = min($n - $i, max(1, (int) ($c['span'] ?? 1)));
                $tw = array_sum(array_slice($w, $i, $span));
                $i += $span;
                $hd = $c['th'] || !empty($c['g']);
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $tw . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '') . ($c['th'] ? '<w:tcBorders><w:bottom w:val="single" w:sz="8" w:space="0" w:color="' . self::hex($this->c['tree']) . '"/></w:tcBorders>' : '') . (!empty($c['g']) ? '<w:shd w:val="clear" w:color="auto" w:fill="F3EDED"/>' : '') . '</w:tcPr>'
                    . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/>' . ($c['r'] ? '<w:jc w:val="right"/>' : '') . '</w:pPr>';
                foreach ($c['runs'] as $r) {
                    if (!empty($r['br'])) { $x .= '<w:r><w:br/></w:r>'; continue; }
                    $rpr = '<w:sz w:val="19"/><w:szCs w:val="19"/>';
                    if ($hd) $rpr = '<w:b/><w:color w:val="' . self::hex($this->c['head']) . '"/>' . $rpr;
                    elseif (!empty($r['b'])) $rpr = '<w:b/>' . $rpr;
                    $x .= '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">' . self::xml($r['t']) . '</w:t></w:r>';
                }
                $x .= '</w:p></w:tc>';
            }
            $x .= '</w:tr>';
        }
        return $x . '</w:tbl>';
    }

    private function docxStyles() {
        $c = $this->c;
        $fn = $this->docFont();
        $font = '<w:rFonts w:ascii="' . $fn . '" w:hAnsi="' . $fn . '" w:cs="' . $fn . '" w:eastAsia="' . $fn . '"/>';
        $st = function ($id, $name, $sp, $extra = '') use ($font) {
            return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
                . '<w:pPr>' . $extra . '<w:spacing w:before="' . (int) round($sp['bf'] * 20) . '" w:after="' . (int) round($sp['af'] * 20) . '"/>' . (isset($sp['lvl']) ? '<w:outlineLvl w:val="' . ($sp['lvl'] - 1) . '"/>' : '') . '</w:pPr>'
                . '<w:rPr>' . ($sp['b'] ? '<w:b/>' : '') . '<w:color w:val="' . self::hex($sp['c']) . '"/><w:sz w:val="' . (int) round($sp['sz'] * 2) . '"/><w:szCs w:val="' . (int) round($sp['sz'] * 2) . '"/></w:rPr></w:style>';
        };
        $border = '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="3" w:color="' . self::hex($c['title']) . '"/></w:pBdr>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>' . $font . '<w:sz w:val="' . (int) round($this->spec('body')['sz'] * 2) . '"/><w:szCs w:val="' . (int) round($this->spec('body')['sz'] * 2) . '"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/><w:rPr><w:color w:val="' . self::hex($c['text']) . '"/></w:rPr></w:style>'
            . $st('Title', 'Title', $this->spec('title'), '<w:jc w:val="center"/>')
            . $st('Heading1', 'heading 1', $this->spec('h1'), '<w:keepNext/>' . $border)
            . $st('Heading2', 'heading 2', $this->spec('h2'), '<w:keepNext/>')
            . $st('Heading3', 'heading 3', $this->spec('h3'), '<w:keepNext/>')
            . $st('Heading4', 'heading 4', $this->spec('h4'), '<w:keepNext/>')
            . '</w:styles>';
    }

    /* ======================================================== ODT */

    private function writeOdt(ZipArchive $zip, array $meta) {
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        if (method_exists($zip, 'setCompressionName')) $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $man = '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
            . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>';
        foreach ($this->images as $im) {
            $zip->addFromString('Pictures/' . $im['name'], $im['bin']);
            $man .= '<manifest:file-entry manifest:full-path="Pictures/' . $im['name'] . '" manifest:media-type="' . $im['mime'] . '"/>';
        }
        $zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">' . $man . '</manifest:manifest>');
        $zip->addFromString('meta.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" office:version="1.2"><office:meta><dc:title>'
            . self::xml($meta['title'] ?? '') . '</dc:title><meta:initial-creator>' . self::xml($meta['author'] ?? '') . '</meta:initial-creator><dc:language>' . self::xml($meta['lang'] ?? 'fr') . '</dc:language></office:meta></office:document-meta>');

        $auto = [];                                   // styles automatiques : clé => [nom, xml]
        $reg = function ($kind, $props, $attrs) use (&$auto) {
            $key = $kind . '|' . $props . '|' . $attrs;
            if (!isset($auto[$key])) {
                $name = ($kind === 'p' ? 'P' : 'T') . (count($auto) + 1);
                $auto[$key] = [$name, '<style:style style:name="' . $name . '" style:family="' . ($kind === 'p' ? 'paragraph' : 'text') . '"' . $attrs . '>' . $props . '</style:style>'];
            }
            return $auto[$key][0];
        };
        $names = ['title' => 'Title', 'h1' => 'Heading_20_1', 'h2' => 'Heading_20_2', 'h3' => 'Heading_20_3', 'h4' => 'Heading_20_4'];
        $body = ''; $frame = 0; $bmUsed = []; $open = null; $tbl = 0; $tblStyles = '';
        $colsOn = false; $secN = 0; $openCols = false;
        $photoCell = function ($g, $cols) use (&$frame, $reg) {
            $im = $this->images[$this->groups[$g]]; $frame++;
            $w = $cols ? 2.0 : 3.2; $h = round($w * $im['h'] / $im['w'], 3);
            return '</table:table-cell><table:table-cell table:style-name="CellB" office:value-type="string"><text:p text:style-name="PhotoP"><draw:frame draw:style-name="' . (!empty($im['rnd']) ? 'fr2' : 'fr1') . '" draw:name="Photo' . $frame . '" text:anchor-type="as-char" svg:width="' . $w . 'cm" svg:height="' . $h . 'cm"><draw:image xlink:href="Pictures/' . $im['name'] . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame></text:p></table:table-cell></table:table-row></table:table>';
        };
        foreach ($this->blocks as $b) {
            $g = $b['grp'] ?? null;
            $bc = !empty($b['cols']) && !empty($this->tpl['cols']);
            if ($g !== $open && $open !== null) { $body .= $photoCell($open, $openCols); $open = null; }
            if ($bc !== $colsOn) {                       // début / fin d'une section à 2 colonnes
                if ($colsOn) $body .= '</text:section>';
                if ($bc) { $secN++; $body .= '<text:section text:style-name="SectCols" text:name="Colonnes' . $secN . '">'; }
                $colsOn = $bc;
            }
            if ($g !== $open) {
                if ($g !== null && isset($this->images[$this->groups[$g] ?? ''])) {
                    $tbl++;
                    $sfx = $colsOn ? '2' : '';
                    $body .= '<table:table table:name="Fiche' . $tbl . '" table:style-name="Tbl' . $sfx . '"><table:table-column table:style-name="TblA' . $sfx . '"/><table:table-column table:style-name="TblB' . $sfx . '"/><table:table-row><table:table-cell table:style-name="CellA" office:value-type="string">';
                } else { $g = null; }
                $open = $g; $openCols = $colsOn;
            }
            if (isset($b['tbl'])) { $tbl++; $body .= $this->odtTable($b['tbl'], $reg, $tbl, $tblStyles); continue; }
            if (isset($b['fig'])) {
                $im = $this->images[$b['fig']]; $frame++;
                $cm = min($b['cm'], $colsOn ? 8.0 : 17.0); $hc = round($cm * $im['h'] / $im['w'], 3);
                $ps = $reg('p', '<style:paragraph-properties fo:text-align="center" fo:margin-top="0.2cm" fo:margin-bottom="0.2cm"' . ($b['pb'] ? ' fo:break-before="page"' : '') . '/>', ' style:parent-style-name="Standard"');
                $bk = '';
                foreach ($b['bm'] ?? [] as $name) { if (isset($bmUsed[$name])) continue; $bmUsed[$name] = 1; $bk .= '<text:bookmark text:name="' . $name . '"/>'; }
                $body .= '<text:p text:style-name="' . $ps . '">' . $bk . '<draw:frame draw:style-name="fr1" draw:name="Arbre' . $frame . '" text:anchor-type="as-char" svg:width="' . round($cm, 3) . 'cm" svg:height="' . $hc . 'cm"><draw:image xlink:href="Pictures/' . $im['name'] . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame></text:p>';
                continue;
            }
            $sp = $this->spec($b['style']);
            $o = $b['o'];
            $pp = '';
            if ($b['pb']) $pp .= ' fo:break-before="page"';
            $ind = ($sp['ind'] ?? 0) + ($o['indent'] ?? 0);
            if ($ind > 0) $pp .= ' fo:margin-left="' . round($ind, 2) . 'cm"';
            $jc = $o['align'] ?? ($b['style'] === 'title' ? 'center' : null);
            if ($jc) $pp .= ' fo:text-align="' . ($jc === 'both' ? 'justify' : $jc) . '"';
            $genTp = '';
            if (!empty($o['gen']) && !empty($this->tpl['banner'])) {
                $solid = !empty($this->tpl['solid']);
                $pp .= ' fo:background-color="' . ($solid ? $this->c['head'] : $this->band()) . '" fo:padding="0.08cm"';
                if ($solid) $genTp = '<style:text-properties fo:color="#ffffff"/>';
            }
            if (!isset($names[$b['style']])) $pp .= ' fo:margin-top="' . round($sp['bf'] * 0.0353, 3) . 'cm" fo:margin-bottom="' . round($sp['af'] * 0.0353, 3) . 'cm"';
            $parent = $names[$b['style']] ?? 'Standard';
            $pstyle = $reg('p', '<style:paragraph-properties' . $pp . '/>' . (isset($names[$b['style']]) ? $genTp : '<style:text-properties fo:font-size="' . $sp['sz'] . 'pt"' . ($sp['b'] ? ' fo:font-weight="bold"' : '') . ($sp['i'] ? ' fo:font-style="italic"' : '') . ' fo:color="' . $sp['c'] . '"/>'), ' style:parent-style-name="' . $parent . '"');
            $inner = '';
            foreach ($b['bm'] as $name) {
                if (isset($bmUsed[$name])) continue;
                $bmUsed[$name] = 1;
                $inner .= '<text:bookmark text:name="' . $name . '"/>';
            }
            foreach ($b['flt'] ?? [] as $f) {   // image à gauche / à droite : ancrée au paragraphe, le texte l'entoure
                $im = $this->images[$f['k']]; $frame++;
                $cm = min($f['cm'], $colsOn ? 4.0 : 10.0); $hc = round($cm * $im['h'] / $im['w'], 3);
                $inner .= '<draw:frame draw:style-name="' . ($f['side'] === 'left' ? 'frFL' : 'frFR') . '" draw:name="Image' . $frame . '" text:anchor-type="paragraph" svg:width="' . round($cm, 3) . 'cm" svg:height="' . $hc . 'cm" draw:z-index="' . $frame . '"><draw:image xlink:href="Pictures/' . $im['name'] . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame>';
            }
            foreach ($b['runs'] as $r) {
                if (!empty($r['br'])) { $inner .= '<text:line-break/>'; continue; }
                $tp = '';
                $bold = !empty($r['b']) || !empty($o['bold']);
                $ital = !empty($r['i']) || !empty($o['italic']);
                if ($bold && !$sp['b']) $tp .= ' fo:font-weight="bold"';
                if ($ital && !$sp['i']) $tp .= ' fo:font-style="italic"';
                if (!empty($r['c']) && strtolower($r['c']) !== strtolower($sp['c'])) $tp .= ' fo:color="' . $r['c'] . '"';
                if (!empty($r['sz'])) $tp .= ' fo:font-size="' . $r['sz'] . 'pt"';
                $t = self::xml($r['t']);
                $t = preg_replace('/ {2,}/', ' ', $t);
                $x = $tp !== '' ? '<text:span text:style-name="' . $reg('t', '<style:text-properties' . $tp . '/>', '') . '">' . $t . '</text:span>' : $t;
                $inner .= !empty($r['link']) ? '<text:a xlink:type="simple" xlink:href="#' . $r['link'] . '">' . $x . '</text:a>' : $x;
            }
            $body .= isset($sp['lvl'])
                ? '<text:h text:style-name="' . $pstyle . '" text:outline-level="' . $sp['lvl'] . '">' . $inner . '</text:h>'
                : '<text:p text:style-name="' . $pstyle . '">' . $inner . '</text:p>';
        }
        if ($open !== null) $body .= $photoCell($open, $openCols);
        if ($colsOn) $body .= '</text:section>';
        $autoXml = '';
        foreach ($auto as $a) $autoXml .= $a[1];
        $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"';
        $fl = 'style:wrap="parallel" style:number-wrapped-paragraphs="no-limit" style:wrap-contour="false" style:vertical-pos="top" style:vertical-rel="paragraph" style:horizontal-rel="paragraph" fo:padding="0cm" fo:border="none"';
        $fr = '<style:style style:name="frFL" style:family="graphic"><style:graphic-properties ' . $fl . ' style:horizontal-pos="left" fo:margin-left="0cm" fo:margin-right="0.45cm" fo:margin-top="0.05cm" fo:margin-bottom="0.2cm"/></style:style>'
            . '<style:style style:name="frFR" style:family="graphic"><style:graphic-properties ' . $fl . ' style:horizontal-pos="right" fo:margin-left="0.45cm" fo:margin-right="0cm" fo:margin-top="0.05cm" fo:margin-bottom="0.2cm"/></style:style>'
            . '<style:style style:name="fr2" style:family="graphic"><style:graphic-properties style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline" fo:border="none"/></style:style>'
            . '<style:style style:name="fr1" style:family="graphic"><style:graphic-properties style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline" fo:border="0.5pt solid #bbbbbb"/></style:style>'
            . '<style:style style:name="Tbl" style:family="table"><style:table-properties style:width="17cm" table:align="left" fo:margin-bottom="0.25cm"/></style:style>'
            . '<style:style style:name="TblA" style:family="table-column"><style:table-column-properties style:column-width="13.6cm"/></style:style>'
            . '<style:style style:name="TblB" style:family="table-column"><style:table-column-properties style:column-width="3.4cm"/></style:style>'
            . '<style:style style:name="CellA" style:family="table-cell"><style:table-cell-properties fo:padding="0cm" fo:padding-right="0.3cm" fo:border="none" style:vertical-align="top"/></style:style>'
            . '<style:style style:name="CellB" style:family="table-cell"><style:table-cell-properties fo:padding="0cm" fo:border="none" style:vertical-align="top"/></style:style>'
            . '<style:style style:name="SectCols" style:family="section"><style:section-properties style:editable="false" text:dont-balance-text-columns="false"><style:columns fo:column-count="2" fo:column-gap="0.9cm"/></style:section-properties></style:style>'
            . '<style:style style:name="Tbl2" style:family="table"><style:table-properties style:width="8cm" table:align="left" fo:margin-bottom="0.2cm"/></style:style>'
            . '<style:style style:name="TblA2" style:family="table-column"><style:table-column-properties style:column-width="6cm"/></style:style>'
            . '<style:style style:name="TblB2" style:family="table-column"><style:table-column-properties style:column-width="2cm"/></style:style>'
            . '<style:style style:name="PhotoP" style:family="paragraph"><style:paragraph-properties fo:text-align="end" fo:margin-top="0.1cm"/></style:style>';
        $zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . $ns . ' office:version="1.2"><office:automatic-styles>' . $autoXml . $fr . $tblStyles . '</office:automatic-styles><office:body><office:text>' . $body . '</office:text></office:body></office:document-content>');
        $zip->addFromString('styles.xml', $this->odtStyles($ns));
    }

    private function odtTable(array $rows, $reg, $idx, &$styles) {
        $n = 0;
        foreach ($rows as $r) $n = max($n, count($r));
        $name = 'Stat' . $idx;
        $widths = $n === 2 ? [6.8, 10.2] : ($n === 6 ? [2.2, 3.6, 3.0, 4.2, 3.0, 1.0] : array_fill(0, $n, round(17 / $n, 3)));
        $styles .= '<style:style style:name="' . $name . '" style:family="table"><style:table-properties style:width="17cm" table:align="left" fo:margin-bottom="0.25cm"/></style:style>';
        $x = '<table:table table:name="' . $name . '" table:style-name="' . $name . '">';
        foreach ($widths as $i => $cw) {
            $styles .= '<style:style style:name="' . $name . 'c' . $i . '" style:family="table-column"><style:table-column-properties style:column-width="' . $cw . 'cm"/></style:style>';
            $x .= '<table:table-column table:style-name="' . $name . 'c' . $i . '"/>';
        }
        $styles .= '<style:style style:name="' . $name . 'H" style:family="table-cell"><style:table-cell-properties fo:padding="0.05cm" fo:border-top="none" fo:border-left="none" fo:border-right="none" fo:border-bottom="1pt solid ' . $this->c['tree'] . '"/></style:style>'
            . '<style:style style:name="' . $name . 'D" style:family="table-cell"><style:table-cell-properties fo:padding="0.05cm" fo:border-top="none" fo:border-left="none" fo:border-right="none" fo:border-bottom="0.25pt solid #dddddd"/></style:style>';
        foreach ($rows as $row) {
            $x .= '<table:table-row>';
            $cells = array_values($row);
            $used = 0; foreach ($cells as $c0) $used += min($n, max(1, (int) ($c0['span'] ?? 1)));
            while ($used < $n) { $cells[] = ['runs' => [], 'th' => false, 'r' => false]; $used++; }
            $i = 0;
            foreach ($cells as $c) {
                if ($i >= $n) break;
                $span = min($n - $i, max(1, (int) ($c['span'] ?? 1)));
                $i += $span;
                $ps = $reg('p', '<style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0cm" fo:text-align="' . ($c['r'] ? 'end' : 'start') . '"/><style:text-properties fo:font-size="9.5pt"' . (($c['th'] || !empty($c['g'])) ? ' fo:font-weight="bold" fo:color="' . $this->c['head'] . '"' : '') . '/>', ' style:parent-style-name="Standard"');
                $t = '';
                foreach ($c['runs'] as $r) $t .= !empty($r['br']) ? '<text:line-break/>' : preg_replace('/ {2,}/', ' ', self::xml($r['t']));
                $x .= '<table:table-cell table:style-name="' . $name . ($c['th'] ? 'H' : 'D') . '" office:value-type="string"' . ($span > 1 ? ' table:number-columns-spanned="' . $span . '"' : '') . '><text:p text:style-name="' . $ps . '">' . $t . '</text:p></table:table-cell>'
                    . str_repeat('<table:covered-table-cell/>', $span - 1);
            }
            $x .= '</table:table-row>';
        }
        return $x . '</table:table><text:p text:style-name="Standard"/>';
    }

    private function odtStyles($ns) {
        $c = $this->c;
        $ps = function ($name, $disp, $sp, $extra = '') {
            return '<style:style style:name="' . $name . '" style:display-name="' . $disp . '" style:family="paragraph" style:parent-style-name="Standard"' . (isset($sp['lvl']) ? ' style:default-outline-level="' . $sp['lvl'] . '"' : '') . ' style:class="text">'
                . '<style:paragraph-properties fo:margin-top="' . round($sp['bf'] * 0.0353, 3) . 'cm" fo:margin-bottom="' . round($sp['af'] * 0.0353, 3) . 'cm"' . (isset($sp['lvl']) ? ' fo:keep-with-next="always"' : '') . $extra . '/>'
                . '<style:text-properties fo:font-size="' . $sp['sz'] . 'pt" fo:font-weight="' . ($sp['b'] ? 'bold' : 'normal') . '" fo:color="' . $sp['c'] . '"/></style:style>';
        };
        return '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . $ns . ' office:version="1.2">'
            . '<office:font-face-decls><style:font-face style:name="' . $this->docFont() . '" svg:font-family="' . $this->docFont() . '"/></office:font-face-decls>'
            . '<office:styles><style:default-style style:family="paragraph"><style:paragraph-properties fo:line-height="115%"/><style:text-properties style:font-name="' . $this->docFont() . '" fo:font-size="' . $this->spec('body')['sz'] . 'pt" fo:language="fr" fo:country="FR"/></style:default-style>'
            . '<style:style style:name="Standard" style:family="paragraph" style:class="text"><style:text-properties fo:color="' . $c['text'] . '"/></style:style>'
            . $ps('Title', 'Title', $this->spec('title'), ' fo:text-align="center"')
            . $ps('Heading_20_1', 'Heading 1', $this->spec('h1'), ' fo:border-bottom="1.5pt solid ' . $c['title'] . '" fo:padding-bottom="0.1cm"')
            . $ps('Heading_20_2', 'Heading 2', $this->spec('h2'))
            . $ps('Heading_20_3', 'Heading 3', $this->spec('h3'))
            . $ps('Heading_20_4', 'Heading 4', $this->spec('h4'))
            . '<style:style style:name="Footer" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="9pt" fo:color="' . $c['meta'] . '"/></style:style>'
            . '</office:styles><office:automatic-styles><style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="21cm" fo:page-height="29.7cm" fo:margin-top="2cm" fo:margin-bottom="1cm" fo:margin-left="2cm" fo:margin-right="2cm"/>'
            . '<style:footer-style><style:header-footer-properties fo:min-height="0.6cm" fo:margin-top="0.4cm"/></style:footer-style></style:page-layout></office:automatic-styles>'
            . '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1"><style:footer><text:p text:style-name="Footer"><text:page-number text:select-page="current">1</text:page-number></text:p></style:footer></style:master-page></office:master-styles></office:document-styles>';
    }
}

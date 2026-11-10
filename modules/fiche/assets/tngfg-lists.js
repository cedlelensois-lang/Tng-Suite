/* TNG – Listes de documents : ascendants (Sosa), descendants (d'Aboville), fiche individuelle.
 * Exports PDF / Excel (.xlsx) / Word (.docx) générés dans le navigateur, sans bibliothèque externe. */
(function () {
  'use strict';
  var C = (typeof window !== 'undefined' && window.TNGFG_L) || {};
  var LG = window.TNGFG_LANG, T = LG.t, X = function () { return LG.x(); };
  LG.init(C);

  /* ================================================================ */
  /* Champs affichés : réglés dans l'administration, modifiables sur la page si autorisé */
  /* ================================================================ */
  var FIELDS = JSON.parse(JSON.stringify(C.fields || {}));
  function on(group, key) { return !(FIELDS[group] && FIELDS[group][key] === 0); }
  var LIST_KEYS = ['num', 'name', 'birth', 'birthp', 'spouse', 'union', 'unionp', 'death', 'deathp', 'age'];

  /* ================================================================ */
  /* Métriques Helvetica (WinAnsi) : largeurs en 1/1000 d'em           */
  /* ================================================================ */
  var WR = [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,761,222,556,333,1000,556,556,333,1000,667,333,1000,761,611,761,761,222,222,333,333,350,556,1000,333,1000,500,333,944,761,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500], WB = [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,761,278,556,500,1000,556,556,333,1000,667,333,1000,761,611,761,761,278,278,500,500,350,556,1000,333,1000,556,333,944,761,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];
  var CP1252 = { 8364: 128, 8218: 130, 402: 131, 8222: 132, 8230: 133, 8224: 134, 8225: 135, 710: 136, 8240: 137, 352: 138, 8249: 139, 338: 140,
    381: 142, 8216: 145, 8217: 146, 8220: 147, 8221: 148, 8226: 149, 8211: 150, 8212: 151, 732: 152, 8482: 153, 353: 154, 8250: 155, 339: 156, 382: 158, 376: 159,
    10013: 134, 8239: 32, 8201: 32, 8202: 32, 8203: 32 };
  function cp(c) {
    if (c < 128 || (c >= 160 && c < 256)) return c;
    return CP1252[c] || 63;
  }
  function tw(str, idx, size) {
    var W = (idx & 1) ? WB : WR, t = 0;
    for (var i = 0; i < str.length; i++) t += W[cp(str.charCodeAt(i))] || 0;
    return t * size / 1000;
  }

  /* ================================================================ */
  /* Dates, noms, lieux                                                */
  /* ================================================================ */
    function monthNum(t) {
    t = String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\./g, '');
    if (/^jan/.test(t)) return 1; if (/^fev|^feb/.test(t)) return 2; if (/^mar/.test(t)) return 3; if (/^avr|^apr/.test(t)) return 4;
    if (/^mai|^may/.test(t)) return 5; if (/^juin|^jun/.test(t)) return 6; if (/^juil|^jul/.test(t)) return 7; if (/^aou|^aug/.test(t)) return 8;
    if (/^sep/.test(t)) return 9; if (/^oct/.test(t)) return 10; if (/^nov/.test(t)) return 11; if (/^dec/.test(t)) return 12;
    return 0;
  }
  function pdate(s) {
    s = String(s == null ? '' : s).trim();
    if (!s || /^[yn]$/i.test(s)) return null;   // « Y » TNG = fait établi, sans date
    var q = '', m;
    if ((m = s.match(/^(abt|about|cal|est|circa|ca|c|vers|env|environ)\.?\s+(.*)$/i))) { q = 'vers'; s = m[2]; }
    else if ((m = s.match(/^(bef|before|avant)\.?\s+(.*)$/i))) { q = 'avant'; s = m[2]; }
    else if ((m = s.match(/^(aft|after|apr[eè]s)\.?\s+(.*)$/i))) { q = 'après'; s = m[2]; }
    else if ((m = s.match(/^(bet|between|entre|from)\s+(.*?)(?:\s+(?:and|et|to)\s+.*)?$/i))) { q = 'entre'; s = m[2]; }
    var y = 0, mo = 0, d = 0;
    if ((m = s.match(/^(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4})/))) { d = +m[1]; mo = +m[2]; y = +m[3]; }
    else if ((m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/))) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else if ((m = s.match(/^(\d{1,2})[.\/\-](\d{4})$/))) { mo = +m[1]; y = +m[2]; }
    else if ((m = s.match(/^(?:(\d{1,2})\s+)?([A-Za-zÀ-ÿ.]+)\s+(\d{4})/)) && monthNum(m[2])) { d = m[1] ? +m[1] : 0; mo = monthNum(m[2]); y = +m[3]; }
    else if ((m = s.match(/(\d{4})/))) { y = +m[1]; }
    else return { raw: s, y: 0, m: 0, d: 0, q: q, txt: true };
    if (mo < 1 || mo > 12) { mo = 0; d = 0; }
    if (d < 1 || d > 31) d = 0;
    if (!mo) d = 0;
    return { y: y, m: mo, d: d, q: q };
  }
  function qpre(dt) { return dt.q ? X().q[dt.q] + ' ' : ''; }
  function fmtShort(dt) {
    if (!dt) return '';
    if (dt.txt) return dt.raw;
    return qpre(dt) + (dt.d ? dt.d + '.' : '') + (dt.m ? dt.m + '.' : '') + dt.y;
  }
  function fmtLong(dt) {
    if (!dt) return '';
    if (dt.txt) return dt.raw;
    return qpre(dt) + X().fmt(dt.d, dt.m, dt.y);
  }
  function onDate(dt) { // "le 1er mars 1900" / "en 1900" / "vers 1900"
    if (!dt) return '';
    var s = fmtLong(dt);
    if (dt.q || dt.txt) return s;
    return X().on(dt.d) + s;
  }
  function daysIn(y, m) { return new Date(y, m, 0).getDate(); }
  function dEarly(dt) { return [dt.y, dt.m || 1, dt.d || 1]; }
  function dLate(dt) { var m = dt.m || 12; return [dt.y, m, dt.d || daysIn(dt.y, m)]; }
  function yearsBetween(a, b) {
    var n = b[0] - a[0];
    if (b[1] < a[1] || (b[1] === a[1] && b[2] < a[2])) n--;
    return n;
  }
  function ageRange(birth, ev) {
    if (!birth || !ev || !birth.y || !ev.y || birth.txt || ev.txt) return null;
    var lb = yearsBetween(dLate(birth), dEarly(ev)), ub = yearsBetween(dEarly(birth), dLate(ev));
    return { lb: lb, ub: ub, exact: lb === ub && !birth.q && !ev.q };
  }
  function ageList(birth, ev) { // colonne « Âge au décès »
    var r = ageRange(birth, ev);
    if (!r || r.ub < 0) return '';
    return r.exact ? String(r.lb) : '~' + Math.max(0, Math.round((r.lb + r.ub) / 2));
  }
  function ageLB(birth, ev) { // âge minimal certain (chronologie)
    var r = ageRange(birth, ev);
    return r && r.lb >= 0 ? r.lb : null;
  }
  function dkey(dt) { return dt && dt.y ? dt.y * 10000 + (dt.m || 1) * 100 + (dt.d || 1) : Infinity; }

  function upper(s) { return String(s || '').toLocaleUpperCase(X().loc); }
  function priv(p) { return !p || p.priv || p.missing; }
  function fnick(p) { return p.nick ? (p.first + ' (' + p.nick + ')').trim() : p.first; }   // Prénoms (Surnom)
  function nameList(p) { return priv(p) ? '' : (fnick(p) + ' ' + upper(p.last)).trim(); }   // Prénoms NOM
  function nameFiche(p) { return priv(p) ? '' : (upper(p.last) + ' ' + fnick(p)).trim(); }  // NOM Prénoms
  function firstGiven(p) { return priv(p) ? '' : String(p.first || '').split(/\s+/)[0]; }
  function shownName(p, fn) { return priv(p) ? '' : (fn(p) || T('(sans nom)')); }
  function sexColor(p) { return p && p.sex === 'F' ? '#e00000' : (p && p.sex === 'M' ? '#1a1a9c' : '#333333'); }
  function placeParts(s) { return String(s || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean); }
  function placeShort(s) { return placeParts(s)[0] || ''; }
  function placeDept(s) { var a = placeParts(s); return a.length > 1 ? a[0] + ' (' + a[1] + ')' : (a[0] || ''); }
  function birthOf(p) { // [date, lieu]
    if (priv(p)) return [null, ''];
    var useBirth = p.birth || p.birthp;
    return [pdate(useBirth ? p.birth : p.bapt), placeShort(useBirth ? p.birthp : p.baptp)];
  }
  /* Date de décès pour l'affichage : « † » quand TNG ne donne que « Y » (décédé, date inconnue) */
  var DAGGER = { txt: true, raw: '†' };
  function deathDate(p) {
    var dt = pdate(p.death);
    if (!dt && !p.deathp) dt = pdate(p.burial);
    if (!dt && /^y$/i.test(String(p.death || '').trim())) dt = DAGGER;
    return dt;
  }
  function deathOf(p) {
    if (priv(p)) return [null, ''];
    var useDeath = p.death || p.deathp;
    return [deathDate(p), placeShort(useDeath ? p.deathp : p.burialp)];
  }
  function years(p) {
    if (priv(p)) return '';
    var by = (pdate(p.birth || p.bapt) || {}).y, dy = (pdate(p.death || p.burial) || {}).y;
    if (!by && !dy) return '';
    return '(' + (by || '') + '-' + (dy || '') + ')';
  }
  function plural(n, w) { return n + ' ' + X()[w][n > 1 ? 1 : 0]; }
  function listFr(a) { return a.length > 1 ? a.slice(0, -1).join(', ') + ' ' + X().and + ' ' + a[a.length - 1] : (a[0] || ''); }
  function todayFr() { return fmtLong(pdate(new Date().toISOString().slice(0, 10))); }
  function slug(s) { return String(s || 'document').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase() || 'document'; }

  /* ================================================================ */
  /* Modèle de document commun                                         */
  /*  model  = {orient, title, subtitle, header, footer, blocks[], xl} */
  /*  block  = {cols[], headerRows, rows[], gap}                        */
  /*  row    = {cells[], fill, keep}                                    */
  /*  cell   = {paras[], span, fill, align, num}                        */
  /*  para   = {runs[{t,b,i,c,s}], bullet, indent, align, size, after}  */
  /* ================================================================ */
  function P(runs, o) {
    o = o || {};
    if (typeof runs === 'string') runs = [{ t: runs, b: o.b, i: o.i, c: o.c, s: o.s }];
    return { runs: runs, bullet: o.bullet, indent: o.indent, align: o.align, size: o.size, after: o.after };
  }
  function cell(text, o) {
    o = o || {};
    var lines = String(text == null ? '' : text).split('\n');
    return { paras: lines.map(function (l) { return P(l, o); }), span: o.span || 1, fill: o.fill, align: o.align, num: o.num };
  }
  var BAND = '#9db8cb', DARK = '#27455f', LINE = '#7ea1b8', SHADE = '#f0f0f0', GREYTXT = '#6b6b6b';

  /* ---------------- Ascendants ---------------- */
  var LIST_COLS = [34, 112, 58, 66, 112, 58, 62, 58, 66, 34];
  var LIST_HEAD = ['N° Sosa/réf.', 'Nom', 'Date Naiss./Bapt.', 'Lieu Naiss./Bapt.', 'Conjoint', 'Date d\'union', 'Lieu d\'union', 'Date Décès/Inhum.', 'Lieu Décès/Inhum.', 'Âge au décès'];  // clés de traduction
  function fracs(a) { var t = a.reduce(function (x, y) { return x + y; }, 0); return a.map(function (v) { return v / t; }); }

  function nameCell(p) {
    if (priv(p)) return cell(p && p.priv ? T('(personne vivante)') : '', { i: true, c: GREYTXT });
    return cell(nameList(p) || T('(sans nom)'), { b: true, c: sexColor(p) });
  }
  function listBlock(firstHead, rowsBuilder, wide) {
    var head = LIST_HEAD.map(T); head[0] = T(firstHead);
    var rows = [{ cells: head.map(function (h) { return cell(h, { b: true, align: 'center', fill: '#eaf0f4' }); }) }];
    rowsBuilder(rows);
    var cols = LIST_COLS.slice(); if (wide) cols[0] = 64;
    return { cols: fracs(cols), headerRows: 1, rows: rows, gap: 0, size: 7.5,
      xl: { widths: [wide ? 16 : 9, 30, 13, 16, 30, 13, 15, 13, 16, 8], numCols: [0, 9] } };
  }
  function genRow(g) { return { cells: [cell(T('Génération {n}', { n: g }), { b: true, span: 10, fill: '#f3f3f3' })], keep: true }; }

  /* retire les colonnes décochées (le nom reste toujours) */
  function filterCols(block, group) {
    var keep = [];
    LIST_KEYS.forEach(function (k, i) { if (k === 'name' || on(group, k)) keep.push(i); });
    if (keep.length === LIST_KEYS.length) return block;
    block.rows.forEach(function (r) {
      if (r.cells.length === LIST_KEYS.length) r.cells = keep.map(function (i) { return r.cells[i]; });
      else if (r.cells.length === 1) r.cells[0].span = keep.length;
    });
    block.cols = fracs(keep.map(function (i) { return block.cols[i]; }));
    block.xl.widths = keep.map(function (i) { return block.xl.widths[i]; });
    block.xl.numCols = block.xl.numCols.map(function (n) { return keep.indexOf(n); }).filter(function (n) { return n >= 0; });
    return block;
  }

  /* Implexe : la personne figure déjà sous un autre numéro -> renvoi sous son nom */
  function dupNote(c, dup) {
    if (dup) c.paras.push(P(T('implexe : voir n° {n}', { n: dup }), { i: true, c: GREYTXT, s: 7 }));
    return c;
  }
  function personCells(p, num, dup) {
    var b = birthOf(p), d = deathOf(p);
    return [cell(String(num), { align: 'right', num: true }), dupNote(nameCell(p), dup),
      cell(fmtShort(b[0])), cell(b[1]), null, null, null, cell(fmtShort(d[0])), cell(d[1]), cell(ageList(b[0], d[0]), { align: 'right', num: true })];
  }

  function buildAsc(data, meta) {
    var rows = data.rows, root = rows[0] ? rows[0].p : null, gen = 0, count = rows.length;
    var block = listBlock('N° Sosa/réf.', function (out) {
      rows.forEach(function (r) {
        if (r.gen !== gen) { gen = r.gen; out.push(genRow(gen)); }
        var c = personCells(r.p, r.sosa, r.dup), sp = r.sp, m = r.marr || {};
        c[4] = priv(sp) ? cell(sp && sp.priv ? T('(personne vivante)') : '', { i: true, c: GREYTXT }) : cell(nameList(sp), { b: true, c: sexColor(sp) });
        c[5] = cell(fmtShort(pdate(m.date))); c[6] = cell(placeShort(m.place));
        out.push({ cells: c });
      });
    });
    var maxGen = rows.length ? rows[rows.length - 1].gen : 0;
    return {
      orient: 'landscape', filename: 'ascendants-' + slug(nameList(root)), sheet: T('Ascendants'),
      title: T('Liste des ascendants de {n}', { n: nameList(root) || T('la personne') }),
      subtitle: T('{a} sur {b} – numérotation Sosa-Stradonitz – établie le {d}', { a: plural(count, 'pe'), b: plural(maxGen, 'ge'), d: todayFr() }) +
        (data.truncated ? T(' – liste tronquée à {n} lignes', { n: count }) : ''),
      header: T('Ascendants de {n}', { n: nameList(root) }), footer: meta.site || '', blocks: [filterCols(block, 'asc')]
    };
  }

  /* ---------------- Descendants ---------------- */
  function buildDesc(data, meta) {
    var rows = data.rows, root = rows[0] ? rows[0].p : null, gen = 0, count = rows.length;
    var block = listBlock('N° d\'Aboville', function (out) {
      rows.forEach(function (r) {
        if (r.gen !== gen) { gen = r.gen; out.push(genRow(gen)); }
        var c = personCells(r.p, r.num, r.dup);
        c[0].num = false; c[0].align = 'left';
        var us = r.unions || [];
        if (us.length) {
          var spP = [], dP = [], plP = [];
          us.forEach(function (u) {
            var sp = u.sp;
            spP.push(priv(sp) ? P(sp && sp.priv ? T('(personne vivante)') : '', { i: true, c: GREYTXT }) : P(nameList(sp), { b: true, c: sexColor(sp) }));
            // couple dont les enfants sont déjà numérotés sous l'autre conjoint (implexe)
            if (u.kidsRef) spP.push(P(T('enfants : voir n° {n}', { n: u.kidsRef }), { i: true, c: GREYTXT, s: 7 }));
            dP.push(P(fmtShort(pdate(u.marr.date)))); plP.push(P(placeShort(u.marr.place)));
            if (u.kidsRef) { dP.push(P('', { s: 7 })); plP.push(P('', { s: 7 })); }   // garde les colonnes alignées
          });
          c[4] = { paras: spP, span: 1 }; c[5] = { paras: dP, span: 1 }; c[6] = { paras: plP, span: 1 };
        } else { c[4] = cell(''); c[5] = cell(''); c[6] = cell(''); }
        out.push({ cells: c });
      });
    }, true);
    var maxGen = rows.length ? rows[rows.length - 1].gen : 0;
    return {
      orient: 'landscape', filename: 'descendants-' + slug(nameList(root)), sheet: T('Descendants'),
      title: T('Liste des descendants de {n}', { n: nameList(root) || T('la personne') }),
      subtitle: T('{a} sur {b} – numérotation d\'Aboville – établie le {d}', { a: plural(count, 'pe'), b: plural(maxGen, 'ge'), d: todayFr() }) +
        (data.truncated ? T(' – liste tronquée à {n} lignes', { n: count }) : ''),
      header: T('Descendants de {n}', { n: nameList(root) }), footer: meta.site || '', blocks: [filterCols(block, 'desc')]
    };
  }

  /* Libellé d'un événement : la base ne donne parfois que le code GEDCOM (OCCU, RESI…) quand la description est vide */
  var EVT = {
    OCCU: "Profession",
    RESI: "Résidence",
    EDUC: "Éducation",
    EMIG: "Émigration",
    IMMI: "Immigration",
    NATU: "Naturalisation",
    CENS: "Recensement",
    PROB: "Homologation du testament",
    WILL: "Testament",
    GRAD: "Diplôme",
    RETI: "Retraite",
    EVEN: "Événement",
    DSCR: "Description physique",
    NATI: "Nationalité",
    RELI: "Religion",
    TITL: "Titre",
    PROP: "Propriété",
    CONF: "Confirmation",
    FCOM: "Première communion",
    ORDN: "Ordination",
    BARM: "Bar-mitsva",
    BASM: "Bat-mitsva",
    BLES: "Bénédiction",
    CAST: "Caste",
    SSN: "N° de sécurité sociale",
    ADOP: "Adoption",
    CHRA: "Baptême adulte",
    ENGA: "Fiançailles",
    MARB: "Publication des bans",
    MARC: "Contrat de mariage",
    MARL: "Licence de mariage",
    ANUL: "Annulation",
    "_MILT": "Service militaire",
    "_MILI": "Service militaire",
    "_DEG": "Diplôme"
  };
  function evName(tag, desc) {
    var t = String(tag || '').toUpperCase(), d = String(desc || '').trim();
    if (d && d.toUpperCase() !== t) return d;
    if (EVT[t]) return T(EVT[t]);
    return d || (t.charAt(0) === '_' ? t.slice(1) : t);
  }

  /* ---------------- Fiche individuelle ---------------- */
  function band(title, ncols) {
    return { cells: [{ paras: [P([{ t: title.toUpperCase(), b: true, c: DARK }], { size: 9 })], span: ncols, fill: BAND }], keep: true };
  }
  function blockOf(cols, rows) { return { cols: cols, headerRows: 0, rows: rows, gap: 9, size: 8.5 }; }
  function kidsLine(kids) {
    kids = (kids || []).filter(function (k) { return !priv(k) || k.priv; });
    if (!kids.length) return T('Sans descendance connue');
    var names = kids.map(function (k) { return k.priv ? T('personne vivante') : (firstGiven(k) || T('sans prénom')); });
    return plural(kids.length, 'ch') + T(' : ') + listFr(names);
  }
  function ageText(parent, childBirth) {
    var r = ageRange(pdate(parent.birth || parent.bapt), childBirth);
    if (!r || r.ub < 0) return '';
    return T('Âge :') + ' ' + (r.exact ? '' : T('environ') + ' ') + plural(Math.max(0, r.exact ? r.lb : Math.round((r.lb + r.ub) / 2)), 'un');
  }
  function relWord(p, m, f, o) { return p && p.sex === 'M' ? m : (p && p.sex === 'F' ? f : o); }
  function dateLine(dt, place) { return [fmtLong(dt), placeDept(place)].filter(Boolean).join(' - '); }
  function rank(n) { return X().rank(n); }

  function buildFiche(d, meta) {
    var p = d.p, sosa = on('fiche', 'sosa') ? d.sosa : null, blocks = [], birth = pdate(p.birth || p.bapt);
    var yrs = years(p);
    var header = nameFiche(p) + (yrs ? ' ' + yrs : '') + (sosa ? ' - ' + T('N° {n}', { n: sosa.n }) + ' (' + T('G{n}', { n: sosa.g }) + ')' : '');

    /* événements personnels */
    var rubriques = [], sign = '', jobs = [], extra = [];
    var CORE = /^(BIRT|DEAT|BURI|BAPT|CHR|MARR|DIV|CREM)$/i;
    (d.events || []).forEach(function (e) {
      var tag = String(e.tag || ''), desc = evName(tag, e.desc), info = String(e.info || '').trim(), dt = pdate(e.date);
      if (CORE.test(tag)) return;
      if (/^_?SIGN/i.test(tag) || /signer/i.test(desc)) { sign = info && !/^y$/i.test(info) ? info : desc; return; }
      if (/^OCCU$/i.test(tag)) { if (info) jobs.push(info); if (dt || e.place) extra.push({ dt: dt, title: desc + (info ? ' : ' + info : ''), place: e.place }); return; }
      if (tag.charAt(0) === '_') { if (info) rubriques.push([desc, info]); return; }
      if (dt || e.place) extra.push({ dt: dt, title: desc + (info ? ' : ' + info : ''), place: e.place });
      else if (info) rubriques.push([desc, info]);
    });

    /* titre + identité */
    var titleParas = [P([{ t: nameFiche(p) || T('(sans nom)'), b: true, c: DARK }], { size: 13, align: 'center' })];
    var nLine = [fmtLong(pdate(p.birth || p.bapt)), placeDept(p.birthp || p.baptp)].filter(Boolean).join(' – ');
    if (nLine) titleParas.push(P([{ t: (p.birth || !p.bapt ? T('N :') + ' ' : T('Bapt. :') + ' ') + nLine, b: true, c: DARK }], { size: 10, align: 'center' }));
    var dLine = [fmtLong(deathDate(p)), placeDept(p.deathp || p.burialp)].filter(Boolean).join(' – ');
    if (dLine) titleParas.push(P([{ t: (p.death || !p.burial ? T('D :') + ' ' : T('Inhum. :') + ' ') + dLine, b: true, c: DARK }], { size: 10, align: 'center' }));
    blocks.push({ cols: [1], headerRows: 0, gap: 0, size: 8.5, rows: [{ cells: [{ paras: titleParas, span: 1, fill: BAND }] }] });

    var idp = [];
    if (sosa) { idp.push(P([{ t: T('N° {n}', { n: sosa.n }), b: true, c: DARK }])); idp.push(P(T('Génération {n}', { n: sosa.g }))); }
    if (jobs.length && on('fiche', 'job')) idp.push(P(T('Profession : {j}', { j: jobs.join(', ') })));
    if (sign && on('fiche', 'sign')) { idp.push(P('')); idp.push(P(sign, { s: 8 })); }
    if (idp.length) blocks.push(blockOf([1], [{ cells: [{ paras: idp, span: 1 }] }]));

    if (rubriques.length && on('fiche', 'rubr')) {
      blocks.push(blockOf([1], [band(T('Rubriques personnelles'), 1)].concat(rubriques.map(function (r) {
        return { cells: [{ paras: [P([{ t: r[0] + T(' : '), b: true }, { t: r[1] }])], span: 1 }] };
      }))));
    }

    /* parents */
    var pr = d.parents;
    if (pr && on('fiche', 'parents')) {
      var father = pr.father, mother = pr.mother;
      var pcell = function (q, n, unknown) {
        if (!q || q.missing) return { paras: [P(unknown, { i: true, c: GREYTXT })], span: 1 };
        if (q.priv) return { paras: [P(T('Personne vivante'), { i: true, c: GREYTXT })], span: 1 };
        var ps = [P([{ t: (n ? n + ' - ' : '') + nameFiche(q) + (years(q) ? ' ' + years(q) : ''), b: true, c: DARK }])];
        var a = on('fiche', 'pages') ? ageText(q, birth) : ''; if (a) ps.push(P(a));
        return { paras: ps, span: 1 };
      };
      var me = (pr.sibs || []).filter(function (s) { return s.self; })[0];
      var legit = '';
      var my = pdate(pr.marr && pr.marr.date);
      if (my && birth && my.y <= birth.y && on('fiche', 'legit')) legit = ' ' + T('(légitime)');
      var rows = [band(T('Parents'), 2),
        { cells: [pcell(father, sosa ? 2 * sosa.n : 0, T('Père inconnu')), pcell(mother, sosa ? 2 * sosa.n + 1 : 0, T('Mère inconnue'))] }];
      if (me && on('fiche', 'rank')) rows.push({ cells: [{ paras: [P(rank(me.rank) + legit)], span: 2 }] });
      blocks.push(blockOf([0.5, 0.5], rows));

      var sibs = (pr.sibs || []).filter(function (s) { return !s.self; });
      if (sibs.length && on('fiche', 'sibs')) {
        blocks.push(blockOf([1], [band(T('Frères et sœurs'), 1)].concat(sibs.map(function (s) {
          var ps = [P([{ t: s.rank + '. ' }, { t: (nameFiche(s.p) || T('Personne vivante')) + (years(s.p) ? ' ' + years(s.p) : ''), b: true, c: DARK }])];
          if (s.sp && !s.sp.missing && on('fiche', 'sibkids')) ps.push(P([{ t: (nameFiche(s.sp) || T('Personne vivante')) + (years(s.sp) ? ' ' + years(s.sp) : ''), i: true, c: DARK }], { bullet: true, indent: 12 }));
          if (s.kids && s.kids.length && on('fiche', 'sibkids')) ps.push(P(kidsLine(s.kids), { bullet: true, indent: 12 }));
          return { cells: [{ paras: ps, span: 1 }] };
        }))));
      }
    }

    /* conjoints et enfants */
    if (d.unions && d.unions.length && on('fiche', 'unions')) {
      var urows = [band(T('Conjoints et enfants'), 2)];
      d.unions.forEach(function (u) {
        var left = [];
        if (u.sp && !u.sp.missing) left.push(P([{ t: (nameFiche(u.sp) || T('Personne vivante')) + (years(u.sp) ? ' ' + years(u.sp) : ''), b: true, c: DARK }]));
        else left.push(P(T('Conjoint inconnu'), { i: true, c: GREYTXT }));
        var md = pdate(u.marr.date), mp = placeDept(u.marr.place);
        if ((md || mp) && on('fiche', 'marr')) left.push(P(T('Mariage') + (md ? ' ' + onDate(md) : '') + (mp ? ' ' + T('à {p}', { p: mp }) : ''), { s: 7.5 }));
        var right = [];
        if (u.kids.length) {
          right.push(P(T('Enfant(s)')));
          u.kids.forEach(function (k) {
            right.push(P([{ t: (nameFiche(k.p) || T('Personne vivante')) + (years(k.p) ? ' ' + years(k.p) : ''), b: true, c: DARK }]));
            if (on('fiche', 'grand')) right.push(P(kidsLine(k.kids), { bullet: true, indent: 12 }));
          });
        } else right.push(P(T('Aucun enfant connu'), { i: true, c: GREYTXT }));
        urows.push({ cells: [{ paras: left, span: 1 }, { paras: right, span: 1 }] });
      });
      blocks.push(blockOf([0.5, 0.5], urows));
    }

    /* chronologie */
    var ev = [];
    function add(dt, title, place, sub) { if (dt || place || sub === 'always') ev.push({ dt: dt, title: title, place: place, i: ev.length }); }
    add(pdate(p.birth || ''), T('Naissance'), p.birthp);
    if (!p.birth && !p.birthp && p.bapt) add(pdate(p.bapt), T('Baptême'), p.baptp);
    else if (p.bapt && (p.birth || p.birthp)) add(pdate(p.bapt), T('Baptême'), p.baptp);
    var kin = on('fiche', 'kin');
    (d.unions || []).forEach(function (u) {
      add(pdate(u.marr.date), T('Mariage') + (u.sp && !priv(u.sp) ? ' ' + T('avec {n}', { n: nameFiche(u.sp) }) : ''), u.marr.place, 'always');
      (u.kids || []).forEach(function (k) {
        var q = k.p; if (priv(q) || !kin) return;
        var rel = relWord(q, T('son fils'), T('sa fille'), T('son enfant'));
        if (q.birth || q.birthp) add(pdate(q.birth), T('Naissance de {r} {n}', { r: rel, n: nameFiche(q) }), q.birthp);
        else if (q.bapt) add(pdate(q.bapt), T('Baptême de {r} {n}', { r: rel, n: nameFiche(q) }), q.baptp);
        if (q.death || q.deathp) add(pdate(q.death), T('Décès de {r} {n}', { r: rel, n: nameFiche(q) }), q.deathp);
      });
      var s = u.sp;
      if (s && !priv(s) && kin) {
        var rs = relWord(s, T('son époux'), T('son épouse'), T('son conjoint'));
        if (s.death || s.deathp) add(pdate(s.death), T('Décès de {r} {n}', { r: rs, n: nameFiche(s) }), s.deathp);
        if (s.burial || s.burialp) add(pdate(s.burial), T('Inhumation de {r} {n}', { r: rs, n: nameFiche(s) }), s.burialp);
      }
    });
    if (pr && kin) [[pr.father, T('son père')], [pr.mother, T('sa mère')]].forEach(function (x) {
      var q = x[0]; if (priv(q)) return;
      if (q.death || q.deathp) add(pdate(q.death), T('Décès de {r} {n}', { r: x[1], n: nameFiche(q) }), q.deathp);
      if (q.burial || q.burialp) add(pdate(q.burial), T('Inhumation de {r} {n}', { r: x[1], n: nameFiche(q) }), q.burialp);
    });
    extra.forEach(function (x) { add(x.dt, x.title, x.place); });
    if (p.death || p.deathp) add(pdate(p.death), T('Décès'), p.deathp);
    if (p.burial || p.burialp) add(pdate(p.burial), T('Inhumation'), p.burialp);
    ev.sort(function (a, b) { var ka = dkey(a.dt), kb = dkey(b.dt); return ka === kb ? a.i - b.i : (ka < kb ? -1 : 1); });
    if (ev.length && on('fiche', 'chrono')) {
      var crows = [band(T('Chronologie'), 2)];
      ev.forEach(function (e, n) {
        var age = (e.title === T('Naissance') || !on('fiche', 'ages')) ? null : ageLB(birth, e.dt);
        var left = age === null ? [P('')] : [P([{ t: String(age), b: true }], { align: 'center' }), P([{ t: X().un[age > 1 ? 1 : 0], s: 7 }], { align: 'center' })];
        var right = [P([{ t: e.title, b: true }])];
        var dl = dateLine(e.dt, e.place); if (dl) right.push(P(dl));
        crows.push({ fill: n % 2 ? SHADE : null, cells: [{ paras: left, span: 1 }, { paras: right, span: 1 }] });
      });
      blocks.push(blockOf([0.085, 0.915], crows));
    }
    return { orient: 'portrait', filename: 'fiche-' + slug(nameFiche(p)), title: '', header: header, footer: meta.site || '', blocks: blocks };
  }

  /* ================================================================ */
  /* Rendu HTML (aperçu)                                               */
  /* ================================================================ */
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function toHTML(model) {
    var h = '<div class="tngl-doc ' + model.orient + '">';
    if (model.title) h += '<h3>' + esc(model.title) + '</h3><p class="tngl-sub">' + esc(model.subtitle || '') + '</p>';
    else if (model.header) h += '<p class="tngl-run">' + esc(model.header) + '</p>';
    model.blocks.forEach(function (b) {
      var fs = (b.size || 8.5) * 1.3;
      h += '<table style="font-size:' + fs.toFixed(1) + 'px;margin-top:' + (b.gap || 0) + 'px"><colgroup>' +
        b.cols.map(function (c) { return '<col style="width:' + (c * 100).toFixed(2) + '%">'; }).join('') + '</colgroup>';
      b.rows.forEach(function (r, ri) {
        h += (ri < (b.headerRows || 0) ? '<tr class="hd">' : '<tr>');
        r.cells.forEach(function (c) {
          var fill = c.fill || r.fill;
          h += '<td colspan="' + (c.span || 1) + '"' + (fill ? ' style="background:' + fill + '"' : '') + '>';
          c.paras.forEach(function (pa) {
            var st = (pa.align || c.align ? 'text-align:' + (pa.align || c.align) + ';' : '') + (pa.bullet ? 'padding-left:' + (pa.indent || 10) + 'px;text-indent:-8px;' : '') + (pa.size ? 'font-size:' + (pa.size * 1.3).toFixed(1) + 'px;' : '');
            h += '<div' + (st ? ' style="' + st + '"' : '') + '>' + (pa.bullet ? '• ' : '') + (pa.runs.map(function (r2) {
              var s2 = (r2.b ? 'font-weight:700;' : '') + (r2.i ? 'font-style:italic;' : '') + (r2.c ? 'color:' + r2.c + ';' : '') + (r2.s ? 'font-size:' + (r2.s * 1.3).toFixed(1) + 'px;' : '');
              return s2 ? '<span style="' + s2 + '">' + esc(r2.t) + '</span>' : esc(r2.t);
            }).join('') || '&nbsp;') + '</div>';
          });
          h += '</td>';
        });
        h += '</tr>';
      });
      h += '</table>';
    });
    return h + '</div>';
  }

  /* ================================================================ */
  /* Rendu PDF                                                         */
  /* ================================================================ */
  function rgb(hex, stroke) {
    var n = parseInt(String(hex || '#000000').replace('#', ''), 16);
    return [(n >> 16 & 255) / 255, (n >> 8 & 255) / 255, (n & 255) / 255].map(function (v) { return v.toFixed(3); }).join(' ') + (stroke ? ' RG' : ' rg');
  }
  function pstr(s) {
    var o = '(';
    for (var i = 0; i < s.length; i++) {
      var c = cp(s.charCodeAt(i));
      if (c === 40 || c === 41 || c === 92) o += '\\' + String.fromCharCode(c);
      else if (c < 32 || c > 126) o += '\\' + ('000' + c.toString(8)).slice(-3);
      else o += String.fromCharCode(c);
    }
    return o + ')';
  }
  function layoutPara(pa, width, baseSize) {
    var size0 = pa.size || baseSize, ind = pa.bullet ? (pa.indent || 10) : (pa.indent || 0), avail = Math.max(10, width - ind), toks = [];
    pa.runs.forEach(function (r) {
      var size = r.s || size0, idx = (r.b ? 1 : 0) + (r.i ? 2 : 0), color = r.c || '#000000';
      String(r.t == null ? '' : r.t).split(/(\s+)/).forEach(function (w) {
        if (w === '') return;
        var sp = /^\s+$/.test(w);
        if (!sp && tw(w, idx, size) > avail) { // mot trop long : découpe
          var cur = '';
          for (var i = 0; i < w.length; i++) {
            if (tw(cur + w[i], idx, size) > avail && cur) { toks.push({ t: cur, sp: false, idx: idx, size: size, color: color, w: tw(cur, idx, size) }); cur = ''; }
            cur += w[i];
          }
          if (cur) toks.push({ t: cur, sp: false, idx: idx, size: size, color: color, w: tw(cur, idx, size) });
        } else toks.push({ t: sp ? ' ' : w, sp: sp, idx: idx, size: size, color: color, w: sp ? tw(' ', idx, size) : tw(w, idx, size) });
      });
    });
    var lines = [], line = { items: [], w: 0 };
    function trim() { while (line.items.length && line.items[line.items.length - 1].sp) line.w -= line.items.pop().w; }
    function flush() { trim(); line.h = line.items.reduce(function (m, k) { return Math.max(m, k.size); }, size0) * 1.22; lines.push(line); line = { items: [], w: 0 }; }
    toks.forEach(function (k) {
      if (k.sp) { if (line.items.length) { line.items.push(k); line.w += k.w; } return; }
      if (line.w + k.w > avail + 0.01 && line.items.length) flush();
      line.items.push(k); line.w += k.w;
    });
    flush();
    var h = lines.reduce(function (a, l) { return a + l.h; }, 0);
    return { lines: lines, ind: ind, h: h, after: pa.after || 0, align: pa.align, bullet: pa.bullet, size: size0 };
  }
  function toPDF(model) {
    var land = model.orient === 'landscape', PW = land ? 841.89 : 595.28, PH = land ? 595.28 : 841.89, M = land ? 28 : 34;
    var padX = 3, padY = 2.2, bottom = M + 12, pages = [], cur = null, y = 0;
    function newPage() {
      cur = []; pages.push(cur);
      y = PH - M;
      if (model.header && !(pages.length === 1 && model.title)) {
        var s = 7.5, w = tw(model.header, 0, s);
        text(cur, (PW - w) / 2, PH - M + 4, model.header, 0, s, '#808080');
        y = PH - M - 8;
      }
      if (pages.length === 1 && model.title) {
        text(cur, M, y - 14, model.title, 1, 15, DARK); y -= 22;
        if (model.subtitle) { text(cur, M, y - 8, model.subtitle, 0, 8.5, '#555555'); y -= 18; }
      }
    }
    function text(ops, x, yb, str, idx, size, color) {
      ops.push('BT /F' + (idx + 1) + ' ' + size.toFixed(2) + ' Tf ' + rgb(color) + ' ' + x.toFixed(2) + ' ' + yb.toFixed(2) + ' Td ' + pstr(str) + ' Tj ET');
    }
    newPage();
    var cw = PW - 2 * M;

    model.blocks.forEach(function (b, bi) {
      var colX = [0], base = b.size || 8.5;
      b.cols.forEach(function (f) { colX.push(colX[colX.length - 1] + f * cw); });
      function measure(row) {
        var col = 0, hmax = 0, out = [];
        row.cells.forEach(function (c) {
          var span = c.span || 1, w = colX[col + span] - colX[col];
          var lay = c.paras.map(function (pa) { return layoutPara(pa, w - 2 * padX, base); });
          var h = lay.reduce(function (a, l) { return a + l.h + l.after; }, 0) + 2 * padY;
          hmax = Math.max(hmax, h); out.push({ c: c, x: colX[col], w: w, lay: lay }); col += span;
        });
        return { h: hmax, cells: out, row: row };
      }
      function draw(m) {
        var top = y;
        m.cells.forEach(function (k) {
          var fill = k.c.fill || m.row.fill, x = M + k.x;
          if (fill) cur.push(rgb(fill) + ' ' + x.toFixed(2) + ' ' + (top - m.h).toFixed(2) + ' ' + k.w.toFixed(2) + ' ' + m.h.toFixed(2) + ' re f');
          var ty = top - padY;
          k.lay.forEach(function (l) {
            l.lines.forEach(function (ln, li) {
              var avail = k.w - 2 * padX - l.ind, al = l.align || k.c.align || 'left';
              var x0 = x + padX + l.ind + (al === 'center' ? (avail - ln.w) / 2 : (al === 'right' ? avail - ln.w : 0));
              var size = ln.items.reduce(function (a, i) { return Math.max(a, i.size); }, l.size);
              var base0 = ty - size * 0.95;
              if (l.bullet && li === 0) text(cur, x + padX + l.ind - 8, base0, '•', 0, l.size, '#000000');
              ln.items.forEach(function (it) { if (!it.sp) text(cur, x0, base0, it.t, it.idx, it.size, it.color); x0 += it.w; });
              ty -= ln.h;
            });
            ty -= l.after;
          });
        });
        cur.push(rgb(b.line || LINE, true) + ' 0.5 w');
        m.cells.forEach(function (k) {
          cur.push((M + k.x).toFixed(2) + ' ' + (top - m.h).toFixed(2) + ' ' + k.w.toFixed(2) + ' ' + m.h.toFixed(2) + ' re S');
        });
        y -= m.h;
      }
      var ms = b.rows.map(function (r) { return null; });
      function get(i) { return ms[i] || (ms[i] = measure(b.rows[i])); }
      if (bi > 0 && b.gap) y -= b.gap;
      b.rows.forEach(function (r, ri) {
        var m = get(ri), need = m.h + (r.keep && b.rows[ri + 1] ? get(ri + 1).h : 0);
        if (y - need < bottom) {
          newPage();
          if (ri >= (b.headerRows || 0)) for (var h = 0; h < (b.headerRows || 0); h++) draw(get(h));
        }
        draw(m);
      });
    });

    /* pieds de page */
    pages.forEach(function (ops, i) {
      var s = 7, lab = 'Page ' + (i + 1) + ' / ' + pages.length;
      text(ops, PW - M - tw(lab, 0, s), M - 10, lab, 0, s, '#808080');
      if (model.footer) text(ops, M, M - 10, model.footer, 0, s, '#808080');
    });

    /* assemblage */
    var enc = new TextEncoder(), parts = [], off = 0, offs = {};
    function push(x) { var u = typeof x === 'string' ? enc.encode(x) : x; parts.push(u); off += u.length; }
    function obj(n, body) { offs[n] = off; push(n + ' 0 obj\n' + body + '\nendobj\n'); }
    var nObj = 7 + 2 * pages.length;
    push('%PDF-1.4\n');
    obj(1, '<</Type/Catalog/Pages 2 0 R>>');
    obj(2, '<</Type/Pages/Kids[' + pages.map(function (_, i) { return (8 + 2 * i) + ' 0 R'; }).join(' ') + ']/Count ' + pages.length + '>>');
    ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique'].forEach(function (f, i) {
      obj(3 + i, '<</Type/Font/Subtype/Type1/BaseFont/' + f + '/Encoding/WinAnsiEncoding>>');
    });
    var ttl = model.title || model.header || 'Document', hex = 'FEFF';
    for (var i = 0; i < ttl.length; i++) hex += ('0000' + ttl.charCodeAt(i).toString(16)).slice(-4);
    obj(7, '<</Title<' + hex + '>/Producer(TNG Fiche Genealogique)>>');
    pages.forEach(function (ops, i) {
      var stream = ops.join('\n');
      obj(8 + 2 * i, '<</Type/Page/Parent 2 0 R/MediaBox[0 0 ' + PW.toFixed(2) + ' ' + PH.toFixed(2) + ']/Resources<</Font<</F1 3 0 R/F2 4 0 R/F3 5 0 R/F4 6 0 R>>>>/Contents ' + (9 + 2 * i) + ' 0 R>>');
      offs[9 + 2 * i] = off;
      push((9 + 2 * i) + ' 0 obj\n<</Length ' + stream.length + '>>\nstream\n' + stream + '\nendstream\nendobj\n');
    });
    var xref = off, x = 'xref\n0 ' + (nObj + 1) + '\n0000000000 65535 f \n';
    for (var n = 1; n <= nObj; n++) x += ('0000000000' + offs[n]).slice(-10) + ' 00000 n \n';
    push(x + 'trailer\n<</Size ' + (nObj + 1) + '/Root 1 0 R/Info 7 0 R>>\nstartxref\n' + xref + '\n%%EOF');
    return concat(parts);
  }
  function concat(parts) {
    var n = parts.reduce(function (a, p) { return a + p.length; }, 0), out = new Uint8Array(n), o = 0;
    parts.forEach(function (p) { out.set(p, o); o += p.length; });
    return out;
  }

  /* ================================================================ */
  /* ZIP (sans compression) pour .xlsx / .docx                         */
  /* ================================================================ */
  var CRC = null;
  function crc32(u8) {
    if (!CRC) { CRC = []; for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; CRC[n] = c >>> 0; } }
    var c2 = -1;
    for (var i = 0; i < u8.length; i++) c2 = CRC[(c2 ^ u8[i]) & 255] ^ (c2 >>> 8);
    return (c2 ^ -1) >>> 0;
  }
  function zip(files) {
    var enc = new TextEncoder(), out = [], central = [], off = 0;
    function w16(n) { return [n & 255, (n >> 8) & 255]; }
    function w32(n) { return [n & 255, (n >> 8) & 255, (n >> 16) & 255, (n >>> 24) & 255]; }
    files.forEach(function (f) {
      var nm = enc.encode(f.name), data = typeof f.data === 'string' ? enc.encode(f.data) : f.data, crc = crc32(data);
      var lh = [0x50, 0x4b, 3, 4].concat(w16(20), w16(0x0800), w16(0), w16(0), w16(0x21), w32(crc), w32(data.length), w32(data.length), w16(nm.length), w16(0));
      out.push(new Uint8Array(lh), nm, data);
      central.push(new Uint8Array([0x50, 0x4b, 1, 2].concat(w16(20), w16(20), w16(0x0800), w16(0), w16(0), w16(0x21), w32(crc), w32(data.length), w32(data.length),
        w16(nm.length), w16(0), w16(0), w16(0), w16(0), w32(0), w32(off))), nm);
      off += lh.length + nm.length + data.length;
    });
    var cdSize = central.reduce(function (a, p) { return a + p.length; }, 0);
    return concat(out.concat(central, [new Uint8Array([0x50, 0x4b, 5, 6].concat(w16(0), w16(0), w16(files.length), w16(files.length), w32(cdSize), w32(off), w16(0)))]));
  }
  function xesc(s) { return String(s == null ? '' : s).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /* ================================================================ */
  /* Rendu XLSX (listes)                                               */
  /* ================================================================ */
  function colName(n) { var s = ''; n++; while (n > 0) { var m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; }
  function toXLSX(model) {
    var b = model.blocks[0], ncol = b.cols.length, fonts = [], fills = [], xfs = [], fk = {}, flk = {}, xk = {};
    function fontId(o) { var k = JSON.stringify(o); if (!(k in fk)) { fk[k] = fonts.length; fonts.push(o); } return fk[k]; }
    function fillId(c) { if (!c) return 0; if (!(c in flk)) { flk[c] = fills.length + 2; fills.push(c); } return flk[c]; }
    function xf(o) { var k = JSON.stringify(o); if (!(k in xk)) { xk[k] = xfs.length; xfs.push(o); } return xk[k]; }
    fontId({ s: 10 }); xf({ f: 0, fl: 0, bd: 0, al: 'left', wr: 0 });
    var sd = '', merges = [], r = 1;
    function str(ref, s, st) { return '<c r="' + ref + '" s="' + st + '" t="inlineStr"><is><t xml:space="preserve">' + xesc(s) + '</t></is></c>'; }
    sd += '<row r="1" ht="22" customHeight="1">' + str('A1', model.title, xf({ f: fontId({ b: 1, s: 14, c: DARK }), fl: 0, bd: 0, al: 'left', wr: 0 })) + '</row>'; r++;
    sd += '<row r="2">' + str('A2', model.subtitle || '', xf({ f: fontId({ i: 1, s: 9, c: '#555555' }), fl: 0, bd: 0, al: 'left', wr: 0 })) + '</row>'; r++;
    r++; // ligne vide
    var headerRow = r;
    b.rows.forEach(function (row, ri) {
      var rr = r++, cs = '', col = 0;
      row.cells.forEach(function (c) {
        var span = c.span || 1, ref = colName(col) + rr, f0 = (c.paras[0] && c.paras[0].runs[0]) || {};
        var txt = c.paras.map(function (pa) { return pa.runs.map(function (x) { return x.t; }).join(''); }).join('\n');
        var al = c.align || (c.paras[0] && c.paras[0].align) || 'left', isNum = c.num && /^\d+$/.test(txt) && b.xl.numCols.indexOf(col) >= 0;
        var st = xf({ f: fontId({ b: f0.b ? 1 : 0, i: f0.i ? 1 : 0, s: 9, c: f0.c || '#000000' }), fl: fillId(c.fill || row.fill), bd: 1, al: al, wr: 1 });
        cs += isNum ? '<c r="' + ref + '" s="' + st + '"><v>' + txt + '</v></c>' : str(ref, txt, st);
        if (span > 1) {
          for (var k = 1; k < span; k++) cs += '<c r="' + colName(col + k) + rr + '" s="' + st + '"/>';
          merges.push(ref + ':' + colName(col + span - 1) + rr);
        }
        col += span;
      });
      sd += '<row r="' + rr + '">' + cs + '</row>';
    });
    var last = r - 1;
    var fontsX = fonts.map(function (f) {
      return '<font>' + (f.b ? '<b/>' : '') + (f.i ? '<i/>' : '') + '<sz val="' + (f.s || 10) + '"/><color rgb="FF' + String(f.c || '#000000').replace('#', '').toUpperCase() + '"/><name val="Arial"/></font>';
    }).join('');
    var fillsX = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' + fills.map(function (c) {
      return '<fill><patternFill patternType="solid"><fgColor rgb="FF' + c.replace('#', '').toUpperCase() + '"/><bgColor indexed="64"/></patternFill></fill>';
    }).join('');
    var bd = '<border><left/><right/><top/><bottom/><diagonal/></border>' +
      '<border>' + ['left', 'right', 'top', 'bottom'].map(function (s) { return '<' + s + ' style="thin"><color rgb="FFBFBFBF"/></' + s + '>'; }).join('') + '<diagonal/></border>';
    var xfsX = xfs.map(function (o) {
      return '<xf numFmtId="0" fontId="' + o.f + '" fillId="' + o.fl + '" borderId="' + o.bd + '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' +
        '<alignment horizontal="' + o.al + '" vertical="top"' + (o.wr ? ' wrapText="1"' : '') + '/></xf>';
    }).join('');
    var styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
      '<fonts count="' + fonts.length + '">' + fontsX + '</fonts><fills count="' + (fills.length + 2) + '">' + fillsX + '</fills><borders count="2">' + bd + '</borders>' +
      '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="' + xfs.length + '">' + xfsX + '</cellXfs>' +
      '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    var cols = b.xl.widths.map(function (w, i) { return '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + w + '" customWidth="1"/>'; }).join('');
    var sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
      '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' + colName(ncol - 1) + last + '"/>' +
      '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' + headerRow + '" topLeftCell="A' + (headerRow + 1) + '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' +
      '<sheetFormatPr defaultRowHeight="13"/><cols>' + cols + '</cols><sheetData>' + sd + '</sheetData>' +
      (merges.length ? '<mergeCells count="' + merges.length + '">' + merges.map(function (m) { return '<mergeCell ref="' + m + '"/>'; }).join('') + '</mergeCells>' : '') +
      '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    var sn = xesc(model.sheet || T('Liste'));
    var wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
      '<sheets><sheet name="' + sn + '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\'' + sn + '\'!$' + headerRow + ':$' + headerRow + '</definedName></definedNames></workbook>';
    return zip([
      { name: '[Content_Types].xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' },
      { name: '_rels/.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' },
      { name: 'xl/workbook.xml', data: wb },
      { name: 'xl/_rels/workbook.xml.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' },
      { name: 'xl/styles.xml', data: styles },
      { name: 'xl/worksheets/sheet1.xml', data: sheet }
    ]);
  }

  /* ================================================================ */
  /* Rendu DOCX                                                        */
  /* ================================================================ */
  function toDOCX(model) {
    var land = model.orient === 'landscape';
    var PGW = land ? 16838 : 11906, PGH = land ? 11906 : 16838, MAR = land ? 560 : 680, CW = PGW - 2 * MAR;
    function hx(c) { return String(c || '#000000').replace('#', '').toUpperCase(); }
    function run(r, base) {
      var sz = Math.round((r.s || base) * 2);
      return '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>' + (r.b ? '<w:b/>' : '') + (r.i ? '<w:i/>' : '') +
        (r.c ? '<w:color w:val="' + hx(r.c) + '"/>' : '') + '<w:sz w:val="' + sz + '"/><w:szCs w:val="' + sz + '"/></w:rPr><w:t xml:space="preserve">' + xesc(r.t) + '</w:t></w:r>';
    }
    function para(pa, base, align, keep) {
      var ind = pa.bullet ? Math.round((pa.indent || 10) * 20) : 0;
      var jc = pa.align || align;
      var ppr = '<w:pPr>' + (keep ? '<w:keepNext/>' : '') + '<w:spacing w:before="0" w:after="' + Math.round((pa.after || 0) * 20) + '" w:line="240" w:lineRule="auto"/>' +
        (ind ? '<w:ind w:left="' + ind + '" w:hanging="' + Math.min(ind, 180) + '"/>' : '') + (jc && jc !== 'left' ? '<w:jc w:val="' + (jc === 'right' ? 'right' : 'center') + '"/>' : '') + '</w:pPr>';
      var runs = (pa.bullet ? run({ t: '• ' }, pa.size || base) : '') + pa.runs.map(function (r) { return run(r, pa.size || base); }).join('');
      if (!runs) runs = run({ t: '' }, pa.size || base);
      return '<w:p>' + ppr + runs + '</w:p>';
    }
    var body = '';
    if (model.title) {
      body += '<w:p><w:pPr><w:spacing w:before="0" w:after="40"/></w:pPr>' + run({ t: model.title, b: true, c: DARK }, 15) + '</w:p>';
      if (model.subtitle) body += '<w:p><w:pPr><w:spacing w:before="0" w:after="120"/></w:pPr>' + run({ t: model.subtitle, c: '#555555' }, 8.5) + '</w:p>';
    }
    model.blocks.forEach(function (b, bi) {
      var base = b.size || 8.5, grid = b.cols.map(function (f) { return Math.round(f * CW); }), line = hx(b.line || LINE);
      if (bi > 0 || model.title) body += '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="' + Math.max(40, Math.round((b.gap || 0) * 20)) + '" w:lineRule="exact"/></w:pPr></w:p>';
      var t = '<w:tbl><w:tblPr><w:tblW w:w="' + CW + '" w:type="dxa"/><w:tblBorders>' +
        ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'].map(function (s) { return '<w:' + s + ' w:val="single" w:sz="4" w:space="0" w:color="' + line + '"/>'; }).join('') +
        '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="70" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' +
        grid.map(function (g) { return '<w:gridCol w:w="' + g + '"/>'; }).join('') + '</w:tblGrid>';
      b.rows.forEach(function (row, ri) {
        t += '<w:tr><w:trPr><w:cantSplit/>' + (ri < (b.headerRows || 0) ? '<w:tblHeader/>' : '') + '</w:trPr>';
        var col = 0;
        row.cells.forEach(function (c) {
          var span = c.span || 1, w = 0, k;
          for (k = 0; k < span; k++) w += grid[col + k];
          var fill = c.fill || row.fill;
          t += '<w:tc><w:tcPr><w:tcW w:w="' + w + '" w:type="dxa"/>' + (span > 1 ? '<w:gridSpan w:val="' + span + '"/>' : '') +
            (fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' + hx(fill) + '"/>' : '') + '</w:tcPr>';
          t += c.paras.map(function (pa) { return para(pa, base, c.align, row.keep); }).join('');
          t += '</w:tc>'; col += span;
        });
        t += '</w:tr>';
      });
      body += t + '</w:tbl>';
    });
    body += '<w:p/>';
    var NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    var doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' + NS + '><w:body>' + body +
      '<w:sectPr><w:headerReference w:type="default" r:id="rId2"/><w:footerReference w:type="default" r:id="rId3"/><w:pgSz w:w="' + PGW + '" w:h="' + PGH + '"' + (land ? ' w:orient="landscape"' : '') +
      '/><w:pgMar w:top="' + (MAR + 200) + '" w:right="' + MAR + '" w:bottom="' + (MAR + 200) + '" w:left="' + MAR + '" w:header="300" w:footer="300" w:gutter="0"/></w:sectPr></w:body></w:document>';
    var hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' + NS + '><w:p><w:pPr><w:jc w:val="center"/></w:pPr>' + (model.header ? run({ t: model.header, c: '#808080' }, 7.5) : '') + '</w:p></w:hdr>';
    var fld = function (code) { return '<w:fldSimple w:instr="' + code + '"><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:color w:val="808080"/><w:sz w:val="14"/></w:rPr><w:t>1</w:t></w:r></w:fldSimple>'; };
    var ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' + NS + '><w:p><w:pPr><w:tabs><w:tab w:val="right" w:pos="' + CW + '"/></w:tabs></w:pPr>' +
      run({ t: model.footer || '', c: '#808080' }, 7) + '<w:r><w:tab/></w:r>' + run({ t: 'Page ', c: '#808080' }, 7) + fld(' PAGE ') + run({ t: ' / ', c: '#808080' }, 7) + fld(' NUMPAGES ') + '</w:p></w:ftr>';
    var styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="17"/><w:szCs w:val="17"/><w:lang w:val="' + X().docLang + '"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
    return zip([
      { name: '[Content_Types].xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>' },
      { name: '_rels/.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' },
      { name: 'word/_rels/document.xml.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>' },
      { name: 'word/document.xml', data: doc }, { name: 'word/styles.xml', data: styles },
      { name: 'word/header1.xml', data: hdr }, { name: 'word/footer1.xml', data: ftr }
    ]);
  }

  var API = { pdate: pdate, fmtShort: fmtShort, fmtLong: fmtLong, ageList: ageList, ageLB: ageLB, buildAsc: buildAsc, buildDesc: buildDesc, buildFiche: buildFiche,
    toHTML: toHTML, toPDF: toPDF, toXLSX: toXLSX, toDOCX: toDOCX };
  if (typeof module !== 'undefined' && module.exports) { module.exports = API; return; }

  /* ================================================================ */
  /* Interface                                                         */
  /* ================================================================ */
  var MIME = { pdf: 'application/pdf', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' };
  function download(bytes, name, mime) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([bytes], { type: mime })); a.download = name;
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }
  function api(action, params) {
    var fd = new FormData();
    fd.append('action', action); fd.append('nonce', C.nonce);
    Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

  function picker(label, hint) {
    var box = el('div', 'tngfg-search');
    box.innerHTML = '<label>' + esc(label) + '</label><input type="text" class="tngfg-input" autocomplete="off" placeholder="' + esc(T('Commencez à saisir un nom ou un prénom…')) + '"><ul class="tngfg-list" hidden></ul>' +
      (hint ? '<p class="tngl-hint">' + hint + '</p>' : '');
    var input = box.querySelector('input'), list = box.querySelector('ul'), items = [], idx = -1, timer, self = { el: box, sel: null };
    function show(rows) {
      items = rows; idx = -1; list.innerHTML = '';
      if (!rows.length) { list.hidden = true; return; }
      rows.forEach(function (r, i) {
        var li = el('li', '', '<strong>' + esc(r.name) + '</strong> <span>' + esc((r.by || '?') + ' – ' + (r.dy || '')) + '</span>');
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
        list.appendChild(li);
      });
      list.hidden = false;
    }
    function pick(i) { self.sel = items[i]; input.value = items[i].name; list.hidden = true; if (self.onpick) self.onpick(self.sel); }
    input.addEventListener('input', function () {
      clearTimeout(timer); self.sel = null; if (self.onpick) self.onpick(null);
      var v = input.value.trim();
      if (v.length < 2) { show([]); return; }
      timer = setTimeout(function () {
        api('tngfg_search', { term: v }).then(function (r) { if (input.value.trim() === v) show(r.success ? r.data : []); }).catch(function () { show([]); });
      }, 250);
    });
    input.addEventListener('keydown', function (e) {
      var lis = list.querySelectorAll('li');
      if (list.hidden || !lis.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + lis.length) % lis.length;
        lis.forEach(function (l, i) { l.classList.toggle('on', i === idx); });
      } else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); pick(idx); }
      else if (e.key === 'Escape') list.hidden = true;
    });
    input.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; }, 150); });
    return self;
  }

  function init(root) {
    var model = null, busy = false;
    var kinds = [['asc', T('Liste des ascendants')], ['desc', T('Liste des descendants')], ['fiche', T('Fiche individuelle')]];
    root.innerHTML = LG.picker(function () { init(root); }) + '<fieldset class="tngl-kind"><legend>' + esc(T('Type de document')) + '</legend>' + kinds.map(function (k, i) {
      return '<label><input type="radio" name="tngl-k" value="' + k[0] + '"' + (i ? '' : ' checked') + '> ' + esc(k[1]) + '</label>';
    }).join('') + '</fieldset>';
    LG.bind(root, function () { init(root); });
    var main = picker(T('Personne'), ''), ref = picker(T('Personne de référence « Sosa 1 » (facultatif)'),
      C.root ? esc(T('Si vous laissez ce champ vide, la personne définie dans les réglages du site ({r}) sert de départ à la numérotation.', { r: C.root })) : esc(T('Sert à numéroter les ancêtres sur la fiche. Laissez vide pour ne pas afficher de numéro Sosa.')));
    var opts = el('div', 'tngl-opts', '<label class="tngl-gens">' + esc(T('Nombre de générations')) + ' <input type="number" min="1" max="15" value="6"></label>');
    var fbox = el('details', 'tngl-fields');
    fbox.hidden = !C.front;
    var go = el('button', 'tngfg-btn', T('Créer le document')); go.type = 'button';
    var status = el('div', 'tngfg-status'); status.setAttribute('role', 'status');
    var out = el('div', 'tngl-out'); out.hidden = true;
    var bar = el('div', 'tngfg-bar'), prev = el('div', 'tngl-prev');
    out.appendChild(bar); out.appendChild(prev);
    [main.el, ref.el, opts, fbox, go, status, out].forEach(function (n) { root.appendChild(n); });
    var gens = opts.querySelector('input');

    function kind() { return root.querySelector('input[name="tngl-k"]:checked').value; }
    function sync() {
      var k = kind();
      main.el.querySelector('label').textContent = T(k === 'asc' ? 'Personne de départ (Sosa 1)' : (k === 'desc' ? 'Ancêtre de départ' : 'Personne de la fiche'));
      ref.el.hidden = k !== 'fiche'; opts.hidden = k === 'fiche';
      gens.value = k === 'desc' ? 4 : 6; gens.max = k === 'desc' ? 12 : 15;
      drawFields(k);
    }
    function drawFields(k) {
      var set = (C.labels || {})[k] || {}, h = '<summary>' + esc(T('Champs affichés dans le document')) + '</summary><div class="tngl-fgrid">';
      Object.keys(set).forEach(function (key) {
        var locked = key === 'name';
        h += '<label><input type="checkbox" data-g="' + k + '" data-k="' + key + '"' + (locked || on(k, key) ? ' checked' : '') + (locked ? ' disabled' : '') + '> ' + esc(T(set[key])) + '</label>';
      });
      fbox.innerHTML = h + '</div>';
      fbox.querySelectorAll('input[data-k]').forEach(function (c) {
        c.addEventListener('change', function () { FIELDS[k] = FIELDS[k] || {}; FIELDS[k][c.getAttribute('data-k')] = c.checked ? 1 : 0; });
      });
    }
    root.querySelectorAll('input[name="tngl-k"]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
    function say(t, err) { status.textContent = t || ''; status.className = 'tngfg-status' + (err ? ' tngfg-err' : ''); }

    go.addEventListener('click', function () {
      if (busy) return;
      if (!main.sel) { say(T('Choisissez d’abord une personne dans la liste de suggestions.'), true); return; }
      var k = kind(), params = { id: main.sel.id, tree: main.sel.tree, lang: LG.lang() };
      if (k === 'fiche') { if (ref.sel) params.root = ref.sel.id; } else params.gens = gens.value;
      busy = true; go.disabled = true; out.hidden = true; say(T('Chargement des données TNG…'));
      api(k === 'asc' ? 'tngfg_asc' : (k === 'desc' ? 'tngfg_desc' : 'tngfg_fiche'), params).then(function (r) {
        busy = false; go.disabled = false;
        if (!r.success) { say(r.data && r.data.msg ? T(r.data.msg) : T('Erreur.'), true); return; }
        var meta = { site: C.site };
        model = k === 'asc' ? buildAsc(r.data, meta) : (k === 'desc' ? buildDesc(r.data, meta) : buildFiche(r.data, meta));
        prev.innerHTML = toHTML(model);
        bar.innerHTML = '';
        var fmts = k === 'fiche' ? [['pdf', T('Télécharger en PDF')], ['docx', T('Télécharger en Word (.docx)')]] : [['pdf', T('Télécharger en PDF')], ['xlsx', T('Télécharger en Excel (.xlsx)')]];
        fmts.forEach(function (f) {
          var b = el('button', 'tngfg-btn', f[1]); b.type = 'button';
          b.addEventListener('click', function () {
            try {
              var bytes = f[0] === 'pdf' ? toPDF(model) : (f[0] === 'xlsx' ? toXLSX(model) : toDOCX(model));
              download(bytes, model.filename + '.' + f[0], MIME[f[0]]); say(T('Fichier généré.'));
            } catch (e) { say(T('Génération impossible : {m}', { m: e.message }), true); }
          });
          bar.appendChild(b);
        });
        out.hidden = false; say(r.data.truncated ? T('Liste tronquée à {n} lignes : réduisez le nombre de générations.', { n: r.data.rows.length }) : '');
      }).catch(function () { busy = false; go.disabled = false; say(T('Erreur de communication avec le serveur.'), true); });
    });
  }
  function boot() { document.querySelectorAll('.tngl').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

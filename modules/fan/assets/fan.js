/* i18n : window.TNGS_I18N est fourni par TNG Suite quand la langue est l'anglais */
function tngsT(s) { var d = window.TNGS_I18N; return (d && Object.prototype.hasOwnProperty.call(d, s)) ? d[s] : s; }

/**
 * TNG Suite – éventail / roue de parenté (SVG, sans bibliothèque externe).
 * Les fonctions de géométrie et de mise en page sont pures (testables) : TNGFanLib.
 * Les tailles de police sont toujours calculées en pixels écran (unités × échelle de zoom) :
 * en zoomant, les textes sont recalculés et s'enrichissent. L'export PDF réutilise le même calcul en points.
 */
var TNGFanLib = (function () {
  'use strict';

  function rad(d) { return d * Math.PI / 180; }
  function r2(n) { return Math.round(n * 100) / 100; }
  /** Point à la distance r du centre, angle en degrés dans le sens horaire depuis le nord. */
  function pt(r, deg) { var a = rad(deg); return [r * Math.sin(a), -r * Math.cos(a)]; }

  /** Secteur annulaire (couronne) entre les rayons r0 et r1 et les angles a0 et a1. */
  function sector(r0, r1, a0, a1) {
    var d = a1 - a0;
    if (d > 359.99) { a1 = a0 + 359.99; d = 359.99; }
    var large = d > 180 ? 1 : 0;
    var p0 = pt(r1, a0), p1 = pt(r1, a1), p2 = pt(r0, a1), p3 = pt(r0, a0);
    return 'M' + r2(p0[0]) + ' ' + r2(p0[1]) + 'A' + r1 + ' ' + r1 + ' 0 ' + large + ' 1 ' + r2(p1[0]) + ' ' + r2(p1[1]) +
      'L' + r2(p2[0]) + ' ' + r2(p2[1]) + 'A' + r0 + ' ' + r0 + ' 0 ' + large + ' 0 ' + r2(p3[0]) + ' ' + r2(p3[1]) + 'Z';
  }

  /** Arc de cercle (support du texte) ; reverse = parcouru dans le sens anti-horaire (texte lisible en bas). */
  function arc(r, a0, a1, reverse) {
    var large = (a1 - a0) > 180 ? 1 : 0, p0 = pt(r, a0), p1 = pt(r, a1);
    if (!reverse) {
      return 'M' + r2(p0[0]) + ' ' + r2(p0[1]) + 'A' + r + ' ' + r + ' 0 ' + large + ' 1 ' + r2(p1[0]) + ' ' + r2(p1[1]);
    }
    return 'M' + r2(p1[0]) + ' ' + r2(p1[1]) + 'A' + r + ' ' + r + ' 0 ' + large + ' 0 ' + r2(p0[0]) + ' ' + r2(p0[1]);
  }

  /** Ascendance : anc[g] = tableau de 2^g identifiants (ou null). Chaque case a la même largeur. */
  function layoutAncestors(anc, start, span) {
    var out = [], g, i, n, w;
    for (g = 1; g < anc.length; g++) {
      n = anc[g].length; w = span / n;
      for (i = 0; i < n; i++) {
        out.push({ id: anc[g][i], g: g, i: i, a0: start + i * w, a1: start + (i + 1) * w, branch: g === 1 ? i : Math.floor(i * 4 / n) });
      }
    }
    return out;
  }

  /**
   * Nombre d'unions « sans enfant affiché » de chaque descendant : elles reçoivent chacune une place
   * (feuille) à la fin de l'arc du descendant, pour que le conjoint puisse être dessiné.
   * gens[d] = [{id, p, f}] ; unions[id] = [[famille, conjoint, nbEnfants]].
   */
  function extraCounts(gens, unions) {
    var extra = [], d, i, next, fams, list, cnt;
    unions = unions || {};
    for (d = 0; d < gens.length; d++) {
      extra[d] = [];
      for (i = 0; i < gens[d].length; i++) { extra[d][i] = 0; }
    }
    for (d = 0; d < gens.length; d++) {
      next = gens[d + 1]; fams = {};
      if (next) { next.forEach(function (c) { (fams[c.p] = fams[c.p] || {})[c.f] = 1; }); }
      for (i = 0; i < gens[d].length; i++) {
        list = unions[gens[d][i].id] || []; cnt = 0;
        list.forEach(function (u) { if (u[1] && !(fams[i] && fams[i][u[0]])) { cnt++; } });
        extra[d][i] = cnt;
      }
    }
    return extra;
  }

  /**
   * Descendance : gens[d] = [{id, p, f}] ; la largeur d'un nœud est proportionnelle à son nombre de feuilles
   * (+ les places réservées aux conjoints sans enfant : extra[d][i], racine comprise).
   */
  function layoutDescendants(gens, start, span, extra) {
    var n = gens.length, leaves = [], d, i, out = [];
    for (d = 0; d < n; d++) { leaves[d] = []; for (i = 0; i < gens[d].length; i++) { leaves[d][i] = (extra && extra[d] && extra[d][i]) || 0; } }
    for (d = n - 1; d >= 0; d--) {
      for (i = 0; i < gens[d].length; i++) {
        if (leaves[d][i] === 0) { leaves[d][i] = 1; }
        if (d > 0) { leaves[d - 1][gens[d][i].p] += leaves[d][i]; }
      }
    }
    var range = [[[start, start + span]]], cursor = [[start]], branch = [[0]];
    for (d = 1; d < n; d++) {
      range[d] = []; cursor[d] = []; branch[d] = [];
      for (i = 0; i < gens[d].length; i++) {
        var p = gens[d][i].p, pr = range[d - 1][p], total = leaves[d - 1][p];
        var w = (pr[1] - pr[0]) * leaves[d][i] / total;
        var a0 = cursor[d - 1][p];
        cursor[d - 1][p] = a0 + w;
        range[d][i] = [a0, a0 + w];
        cursor[d][i] = a0;
        branch[d][i] = d === 1 ? i : branch[d - 1][p];
        out.push({ id: gens[d][i].id, g: d, i: i, p: p, f: gens[d][i].f, a0: a0, a1: a0 + w, branch: branch[d][i] });
      }
    }
    return out;
  }

  /**
   * Arcs des conjoints d'un descendant : celui de chaque union qui a des enfants affichés couvre leurs secteurs,
   * les unions sans enfant se partagent la place laissée libre à la fin de l'arc.
   * node = {a0, a1}, kids = secteurs des enfants du nœud, unions = [[famille, conjoint, nbEnfants]].
   */
  function spouseSegments(node, kids, unions) {
    var byFam = {}, lastEnd = node.a0, out = [], free = [];
    kids.forEach(function (k) {
      var s = byFam[k.f];
      if (!s) { byFam[k.f] = { a0: k.a0, a1: k.a1 }; } else { s.a0 = Math.min(s.a0, k.a0); s.a1 = Math.max(s.a1, k.a1); }
      lastEnd = Math.max(lastEnd, k.a1);
    });
    (unions || []).forEach(function (u) {
      if (!u[1]) { return; }
      if (byFam[u[0]]) { out.push({ id: u[1], fam: u[0], a0: byFam[u[0]].a0, a1: byFam[u[0]].a1 }); } else { free.push(u); }
    });
    var slot = free.length ? (node.a1 - lastEnd) / free.length : 0;
    free.forEach(function (u, j) { out.push({ id: u[1], fam: u[0], a0: lastEnd + j * slot, a1: lastEnd + (j + 1) * slot }); });
    return out;
  }

  function hexToRgb(h) {
    h = String(h || '').replace('#', '');
    if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
    var n = parseInt(h, 16);
    if (isNaN(n) || h.length !== 6) { return [150, 150, 150]; }
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  /** Éclaircit une couleur : t = 0 inchangée, 1 blanc. Retourne [r, g, b]. */
  function tintRgb(hex, t) {
    return hexToRgb(hex).map(function (v) { return Math.round(v * (1 - t) + 255 * t); });
  }
  function tint(hex, t) { return 'rgb(' + tintRgb(hex, t).join(',') + ')'; }
  function toHex(rgb) { return '#' + rgb.map(function (v) { return (v < 16 ? '0' : '') + v.toString(16); }).join(''); }
  function luminance(hex) {
    var c = hexToRgb(hex);
    return (0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]) / 255;
  }

  /** Couleur d'un nœud selon la coloration choisie. */
  function colorFor(node, st, persons) {
    var cols = st.colors, n = cols.length;
    if (st.color === 'sex') {
      var p = persons[node.id], s = (p && !p.h) ? p.s : '';
      return s === 'M' ? cols[0] : (s === 'F' ? cols[1 % n] : '#9aa0a6');
    }
    return cols[(st.color === 'branch' ? node.branch : node.g - 1) % n];
  }

  function normAngle(a) {
    a = a % 360;
    if (a > 180) { a -= 360; }
    if (a < -180) { a += 360; }
    return a;
  }

  function cut(s, max) {
    s = String(s);
    if (max < 2) { return ''; }
    return s.length > max ? s.slice(0, Math.max(1, max - 1)) + '…' : s;
  }

  /** Niveau de détail selon le zoom (0 = réglages du graphique, 3 = tout). */
  function detailFor(k, st) {
    if (!st || !st.zoomDetail) { return 0; }
    return k < 2 ? 0 : (k < 3.5 ? 1 : (k < 6 ? 2 : 3));
  }

  /** Lignes de texte d'une personne, par ordre d'importance (les dernières sont abandonnées faute de place). */
  function lines(p, st, hiddenLabel, detail) {
    if (!p) { return []; }
    if (p.h) { return [hiddenLabel]; }
    detail = detail || 0;
    var dates = st.dates, places = st.places;
    if (detail >= 1 && dates === 'none') { dates = 'years'; }
    if (detail >= 2 && places === 'none') { places = 'commune'; }
    if (detail >= 3) { dates = 'full'; places = 'full'; }
    var fn = p.fn || '';
    if (st.firstnames === 'usual') { fn = fn.split(/\s+/)[0] || ''; }
    var name = ((p.ln || '').toUpperCase() + (fn ? ' ' + fn : '')).trim() || '?';
    var out = [name], letters = st.labels === 'letters', pb = letters ? 'N : ' : '° ', pd = letters ? 'D : ' : '† ';
    var place = function (s) { return places === 'commune' ? String(s || '').split(',')[0].trim() : String(s || '').trim(); };
    var bp = places !== 'none' ? place(p.bp) : '', dp = places !== 'none' ? place(p.dp) : '';
    if (dates === 'years') {
      if (p.by || p.dy) { out.push((p.by || '') + ' – ' + (p.dy || '')); }
    } else if (dates === 'full') {
      var bd = p.bf || p.by, dd = p.df || p.dy;
      if (letters) {                                  // une ligne « N : date - lieu »
        if (bd || bp) { out.push(pb + [bd, bp].filter(Boolean).join(' - ')); }
        if (dd || dp) { out.push(pd + [dd, dp].filter(Boolean).join(' - ')); }
        return out;
      }
      if (bd) { out.push(pb + bd); }
      if (dd) { out.push(pd + dd); }
    }
    if (bp) { out.push(pb + bp); }
    if (dp) { out.push(pd + dp); }
    return out;
  }

  /** Textes de la bande d'un conjoint : « NOM Prénom (années) » puis le mariage (« M : date » ou « x date »). */
  function spouseLines(p, st, hiddenLabel, detail, mi, ownerHidden) {
    if (!p) { return []; }
    if (p.h) { return [hiddenLabel]; }
    detail = detail || 0;
    var fn = p.fn || '';
    if (st.firstnames === 'usual') { fn = fn.split(/\s+/)[0] || ''; }
    var name = ((p.ln || '').toUpperCase() + (fn ? ' ' + fn : '')).trim() || '?';
    if (st.dates !== 'none' || detail >= 1) { if (p.by || p.dy) { name += ' (' + (p.by || '') + '-' + (p.dy || '') + ')'; } }
    var out = [name];
    if (mi && !ownerHidden) {
      var md = detail >= 2 ? (mi.f || mi.y) : mi.y;
      if (md) { out.push((st.labels === 'letters' ? 'M : ' : 'x ') + md); }
    }
    return out;
  }

  /**
   * Choisit l'orientation du texte d'un secteur et la taille de police. TOUT est en pixels écran (ou en points).
   * Retourne { mode: 'tangent'|'radial', fs, lines } ou null si le texte ne rentre pas.
   */
  function fitText(all, r0, r1, a0, a1, minFs, maxFs) {
    minFs = minFs || 6; maxFs = maxFs || 14;
    var w = r1 - r0, rm = (r0 + r1) / 2, da = rad(a1 - a0);
    var arcMid = rm * da;
    var tangent = arcMid >= w * 1.1;
    var n, fs, avail, maxChars, out, longest, fs2;
    for (n = all.length; n >= 1; n--) {
      if (tangent) {
        fs = Math.min(maxFs, w * 0.9 / (n * 1.2));
        avail = (r0 + w * 0.12) * da * 0.92;
      } else {
        fs = Math.min(maxFs - 2, (r0 + w * 0.1) * da * 0.9 / (n * 1.2));
        avail = w * 0.92;
      }
      if (fs < minFs) { continue; }
      longest = 0;
      all.slice(0, n).forEach(function (s) { if (s.length > longest) { longest = s.length; } });
      maxChars = Math.floor(avail / (fs * 0.55));
      if (longest > maxChars) {                     // réduire un peu la police avant de tronquer
        fs2 = Math.max(minFs, fs * 0.72, Math.min(fs, avail / (longest * 0.55)));
        fs = fs2;
        maxChars = Math.floor(avail / (fs * 0.55));
      }
      if (maxChars >= 3) {
        out = all.slice(0, n).map(function (s) { return cut(s, maxChars); });
        return { mode: tangent ? 'tangent' : 'radial', fs: fs, lines: out };
      }
    }
    return null;
  }

  /**
   * Plan de pose du texte d'un secteur (r0, r1 en unités ; S = pixels ou points par unité).
   * Résultat en unités : tangent → lignes posées sur des arcs ; radial → lignes dans un repère tourné.
   */
  function labelPlan(all, r0, r1, a0, a1, S) {
    var fit = fitText(all, r0 * S, r1 * S, a0, a1);
    if (!fit) { return null; }
    var fs = fit.fs / S, lh = fs * 1.2, cnt = fit.lines.length, k;
    var mid = normAngle((a0 + a1) / 2), rm = (r0 + r1) / 2, out;
    if (fit.mode === 'tangent') {
      var bottom = Math.abs(mid) > 90;
      out = { mode: 'tangent', fs: fs, a0: a0, a1: a1, mid: mid, bottom: bottom, lines: [] };
      for (k = 0; k < cnt; k++) {
        out.lines.push({ text: fit.lines[k], bold: k === 0, r: bottom ? rm - (cnt - 1) / 2 * lh + k * lh : rm + (cnt - 1) / 2 * lh - k * lh });
      }
      return out;
    }
    out = { mode: 'radial', fs: fs, mid: mid, rm: rm, rot: mid > 0 ? mid - 90 : mid + 90, lines: [] };
    for (k = 0; k < cnt; k++) { out.lines.push({ text: fit.lines[k], bold: k === 0, y: (k - (cnt - 1) / 2) * lh }); }
    return out;
  }

  /** Texte du centre : lignes horizontales dans un disque de rayon R (unités) ; null si rien ne rentre. */
  function centerPlan(all, R, S) {
    var n, fs, maxChars, W = R * S * 1.7;
    for (n = all.length; n >= 1; n--) {
      fs = Math.min(14, R * S * 1.6 / (n * 1.3));
      if (fs < 6) { continue; }
      maxChars = Math.floor(W / (fs * 0.55));
      if (maxChars < 3) { continue; }
      var fu = fs / S, ls = all.slice(0, n).map(function (s) { return cut(s, maxChars); });
      return { fs: fu, lines: ls.map(function (t, k) { return { text: t, bold: k === 0, y: (k - (n - 1) / 2) * fu * 1.3 }; }) };
    }
    return null;
  }

  return {
    rad: rad, pt: pt, sector: sector, arc: arc, layoutAncestors: layoutAncestors, layoutDescendants: layoutDescendants,
    extraCounts: extraCounts, spouseSegments: spouseSegments,
    tint: tint, tintRgb: tintRgb, toHex: toHex, hexToRgb: hexToRgb, luminance: luminance, colorFor: colorFor, normAngle: normAngle,
    cut: cut, lines: lines, spouseLines: spouseLines, detailFor: detailFor, fitText: fitText, labelPlan: labelPlan, centerPlan: centerPlan
  };
})();

if (typeof module !== 'undefined' && module.exports) { module.exports = TNGFanLib; }

/* ===================================================================== export PDF (vectoriel, sans bibliothèque) */
var TNGFanPdf = (function () {
  'use strict';
  var L = TNGFanLib;

  var PAPERS = { A4: [210, 297], A3: [297, 420], A2: [420, 594], A1: [594, 841], A0: [841, 1189] };
  var CP1252 = { 8364: 128, 8218: 130, 402: 131, 8222: 132, 8230: 133, 8224: 134, 8225: 135, 710: 136, 8240: 137, 352: 138, 8249: 139, 338: 140, 381: 142,
    8216: 145, 8217: 146, 8220: 147, 8221: 148, 8226: 149, 8211: 150, 8212: 151, 732: 152, 8482: 153, 353: 154, 8250: 155, 339: 156, 382: 158, 376: 159 };

  function num(n) { return String(Math.round(n * 100) / 100); }
  function mm(v) { return v * 72 / 25.4; }

  /** Texte → chaîne PDF entre parenthèses, en WinAnsi (caractères inconnus remplacés par « ? »). */
  function pdfString(s) {
    var out = '(', i, c, b;
    s = String(s);
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c < 128) { b = c; } else if (c >= 160 && c <= 255) { b = c; } else { b = CP1252[c] || 63; }
      if (b === 40 || b === 41 || b === 92) { out += '\\' + String.fromCharCode(b); }
      else if (b < 32 || b > 126) { out += '\\' + ('00' + b.toString(8)).slice(-3); }
      else { out += String.fromCharCode(b); }
    }
    return out + ')';
  }

  /** Largeurs des caractères (Arial/Helvetica ont les mêmes), mesurées avec un canvas ; repli : 0,55 em. */
  function metrics() {
    var ctx = null, cache = { r: {}, b: {} };
    try { ctx = document.createElement('canvas').getContext('2d'); } catch (e) { ctx = null; }
    function w(str, bold) {
      var key = bold ? 'b' : 'r', tot = 0, i, ch, v;
      for (i = 0; i < str.length; i++) {
        ch = str.charAt(i); v = cache[key][ch];
        if (v === undefined) {
          if (ctx) { ctx.font = (bold ? 'bold ' : '') + '100px Helvetica, Arial, "Liberation Sans", sans-serif'; v = ctx.measureText(ch).width / 100; } else { v = 0.55; }
          cache[key][ch] = v;
        }
        tot += v;
      }
      return tot;
    }
    return w;
  }

  function parseColor(c) {
    var m = /^rgb\((\d+),(\d+),(\d+)\)$/.exec(c);
    if (m) { return [m[1] / 255, m[2] / 255, m[3] / 255]; }
    return L.hexToRgb(c).map(function (v) { return v / 255; });
  }
  function rg(c, stroke) { var v = parseColor(c); return num(v[0]) + ' ' + num(v[1]) + ' ' + num(v[2]) + (stroke ? ' RG' : ' rg'); }

  /** Choix automatique du papier selon la taille du graphique. */
  function autoPaper(nodeCount, maxG) {
    if (nodeCount > 1500 || maxG >= 10) { return 'A1'; }
    if (nodeCount > 400 || maxG >= 8) { return 'A2'; }
    if (nodeCount > 120) { return 'A3'; }
    return 'A4';
  }


  /** Mise en page d'une feuille : papier (« A4 »… ou {w, h} en points), orientation (auto|portrait|landscape). */
  function pageFor(scene, paper, orient) {
    var vb = scene.vb, pw, ph, key = null;
    if (paper && paper.w) { pw = paper.w; ph = paper.h; }
    else {
      key = PAPERS[paper] ? paper : autoPaper(scene.nodes.length, scene.maxG);
      var size = PAPERS[key];
      var land = orient === 'landscape' ? true : (orient === 'portrait' ? false : vb[2] > vb[3] * 1.1);
      pw = mm(land ? size[1] : size[0]); ph = mm(land ? size[0] : size[1]);
    }
    var f = Math.min(pw, ph) / 595;                        // facteur d'échelle des textes de page
    var mg = mm(10) * Math.max(1, f * 0.8), headH = 34 * f, footH = 18 * f;
    var boxW = pw - 2 * mg, boxH = ph - 2 * mg - headH - footH;
    var sc = Math.min(boxW / vb[2], boxH / vb[3]);
    return {
      pw: pw, ph: ph, f: f, mg: mg, boxW: boxW, boxH: boxH, sc: sc,
      ox: mg + (boxW - vb[2] * sc) / 2 - vb[0] * sc, oy: mg + headH + (boxH - vb[3] * sc) / 2 - vb[1] * sc,
      label: (key ? key + ' ' + (pw > ph ? 'landscape' : 'portrait') : Math.round(pw * 25.4 / 72 / 10) + ' × ' + Math.round(ph * 25.4 / 72 / 10) + ' cm')
    };
  }

  /** Cases dont le texte (au niveau de détail demandé) tient EN ENTIER sur cette feuille : { full, total }. */
  function coverage(scene, pg, stopAtFail, detail) {
    var full = 0, total = scene.nodes.length, i, n, lines, plan, ok, k;
    for (i = 0; i < total; i++) {
      n = scene.nodes[i]; lines = n.lines(detail || 0);
      plan = L.labelPlan(lines, n.r0, n.r1, n.a0, n.a1, pg.sc);
      ok = !!plan && plan.lines.length === lines.length;
      for (k = 0; ok && k < lines.length; k++) { if (plan.lines[k].text !== lines[k]) { ok = false; } }
      if (ok) { full++; } else if (stopAtFail) { return { full: full, total: total }; }
    }
    return { full: full, total: total };
  }

  /** Plus petite feuille (A4…A0, puis format sur mesure jusqu'à 5 m) où tout le texte tient en entier. */
  function fitToText(scene, orient, detail) {
    var keys = ['A4', 'A3', 'A2', 'A1', 'A0'], i, pg, cov, last = null;
    for (i = 0; i < keys.length; i++) {
      pg = pageFor(scene, keys[i], orient); last = pg;
      if (coverage(scene, pg, true, detail).full === scene.nodes.length) { return { page: pg, exact: true }; }
    }
    var vb = scene.vb, land = pg.pw > pg.ph, sc = pg.sc * 1.12, MAX = 14400, big;
    for (i = 0; i < 40; i++, sc *= 1.12) {
      var w = vb[2] * sc * 1.08 + 120, h = vb[3] * sc * 1.08 + 260;
      if (w > MAX || h > MAX) { break; }
      big = pageFor(scene, { w: w, h: h }, orient);
      if (coverage(scene, big, true, detail).full === scene.nodes.length) { return { page: big, exact: true }; }
    }
    return { page: last, exact: false };
  }

  /**
   * scene : { vb:[x,y,w,h], nodes:[{id,g,a0,a1,r0,r1,fill,stroke,sw,txt,lines(detail)}], center:{R, lines(detail), fill, stroke} }
   * opts : { paper: 'auto'|'A4'…, title, footer, detail }
   * Retourne une promesse d'Uint8Array.
   */
  function build(scene, opts) {
    var pg = opts.page || pageFor(scene, opts.paper, opts.orient);
    var vb = scene.vb, pw = pg.pw, ph = pg.ph, f = pg.f, mg = pg.mg, boxW = pg.boxW, sc = pg.sc, ox = pg.ox, oy = pg.oy;
    var tw = metrics(), ops = [], K = 0.5522847498;

    function X(x) { return ox + x * sc; }
    function Y(y) { return ph - (oy + y * sc); }

    function arcOps(r, a0, a1, first) {
      var segs = Math.max(1, Math.ceil(Math.abs(a1 - a0) / 90)), s, t0, t1, d, k, p0, p1, c1, c2, out = [];
      for (s = 0; s < segs; s++) {
        t0 = a0 + (a1 - a0) * s / segs; t1 = a0 + (a1 - a0) * (s + 1) / segs;
        d = L.rad(t1 - t0); k = 4 / 3 * Math.tan(d / 4);
        p0 = L.pt(r, t0); p1 = L.pt(r, t1);
        c1 = [p0[0] + k * r * Math.cos(L.rad(t0)), p0[1] + k * r * Math.sin(L.rad(t0))];
        c2 = [p1[0] - k * r * Math.cos(L.rad(t1)), p1[1] - k * r * Math.sin(L.rad(t1))];
        out.push(num(X(c1[0])) + ' ' + num(Y(c1[1])) + ' ' + num(X(c2[0])) + ' ' + num(Y(c2[1])) + ' ' + num(X(p1[0])) + ' ' + num(Y(p1[1])) + ' c');
      }
      return out.join('\n');
    }
    function sectorOps(r0, r1, a0, a1) {
      if (a1 - a0 > 359.99) { a1 = a0 + 359.99; }
      var p = L.pt(r1, a0), q = L.pt(r0, a1);
      return num(X(p[0])) + ' ' + num(Y(p[1])) + ' m\n' + arcOps(r1, a0, a1) + '\n' + num(X(q[0])) + ' ' + num(Y(q[1])) + ' l\n' + arcOps(r0, a1, a0) + '\nh';
    }
    function text(str, bold, fs, a, b, c, d, e, g) {
      ops.push('/' + (bold ? 'F2' : 'F1') + ' ' + num(fs) + ' Tf ' + num(a) + ' ' + num(b) + ' ' + num(c) + ' ' + num(d) + ' ' + num(e) + ' ' + num(g) + ' Tm ' + pdfString(str) + ' Tj');
    }
    /** Pose un texte dont l'origine (gauche, ligne de base) est en (x, y) unités, tourné de rot degrés (sens horaire). */
    function placed(str, bold, fsU, x, y, rot) {
      var r = L.rad(rot), co = Math.cos(r), si = Math.sin(r);
      text(str, bold, fsU * sc, co, -si, si, co, X(x), Y(y));
    }
    function drawPlan(plan, color) {
      var k, ln, w, p, ang, i, ch, cw, rb, x0;
      ops.push('BT\n' + rg(color));
      if (plan.mode === 'tangent') {
        for (k = 0; k < plan.lines.length; k++) {
          ln = plan.lines[k]; w = tw(ln.text, ln.bold) * plan.fs;
          rb = plan.bottom ? ln.r + 0.35 * plan.fs : ln.r - 0.35 * plan.fs;
          // angle de départ : le texte est centré sur le milieu du secteur
          ang = plan.bottom ? plan.mid + (w / 2 / rb) * 180 / Math.PI : plan.mid - (w / 2 / rb) * 180 / Math.PI;
          for (i = 0; i < ln.text.length; i++) {
            ch = ln.text.charAt(i); cw = tw(ch, ln.bold) * plan.fs;
            p = L.pt(rb, ang);
            placed(ch, ln.bold, plan.fs, p[0], p[1], plan.bottom ? ang + 180 : ang);
            ang += (plan.bottom ? -1 : 1) * (cw / rb) * 180 / Math.PI;
          }
        }
      } else {
        var c = L.pt(plan.rm, plan.mid), r = L.rad(plan.rot), co = Math.cos(r), si = Math.sin(r);
        for (k = 0; k < plan.lines.length; k++) {
          ln = plan.lines[k]; w = tw(ln.text, ln.bold) * plan.fs;
          var lx = -w / 2, ly = ln.y + 0.35 * plan.fs;
          x0 = c[0] + co * lx - si * ly; var y0 = c[1] + si * lx + co * ly;
          placed(ln.text, ln.bold, plan.fs, x0, y0, plan.rot);
        }
      }
      ops.push('ET');
    }

    // titre et pied de page
    var tfs = 16 * f, tW = tw(opts.title || '', true) * tfs;
    if (tW > boxW) { tfs = tfs * boxW / tW; }
    ops.push('BT\n0.1 0.12 0.14 rg');
    text(opts.title || '', true, tfs, 1, 0, 0, 1, mg, ph - mg - 14 * f);
    ops.push('ET');

    // secteurs
    scene.nodes.forEach(function (n) {
      ops.push(rg(n.fill) + '\n' + rg(n.stroke, true) + '\n' + num(Math.max(0.15, Math.min(0.7, n.sw * 0.5))) + ' w\n' + sectorOps(n.r0, n.r1, n.a0, n.a1) + '\nB');
    });
    (scene.edges || []).forEach(function (e) { ops.push(rg(e.fill) + '\n' + sectorOps(e.r0, e.r1, e.a0, e.a1) + '\nf'); });
    // disque central
    ops.push('1 1 1 rg\n0.58 0.64 0.72 RG\n' + num(Math.max(0.5, 2 * sc)) + ' w\n' + sectorOps(0, scene.center.R, 0, 360) + '\nB');
    // textes
    scene.nodes.forEach(function (n) {
      var plan = L.labelPlan(n.lines(opts.detail), n.r0, n.r1, n.a0, n.a1, sc);
      if (plan) { drawPlan(plan, n.txt); }
    });
    var cl = scene.center.lines(opts.detail), cp = L.centerPlan(cl, scene.center.R, sc);
    if (cp) {
      ops.push('BT\n0.1 0.12 0.14 rg');
      cp.lines.forEach(function (ln) {
        var w = tw(ln.text, ln.bold) * cp.fs;
        placed(ln.text, ln.bold, cp.fs, -w / 2, ln.y + 0.35 * cp.fs, 0);
      });
      ops.push('ET');
    }
    ops.push('BT\n0.4 0.44 0.5 rg');
    text(opts.footer || '', false, 8 * f, 1, 0, 0, 1, mg, mg - 2 * f + 4);
    ops.push('ET');

    var content = ops.join('\n');
    return deflate(content).then(function (packed) {
      var enc = function (s) { var a = new Uint8Array(s.length), i; for (i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i) & 255; } return a; };
      var chunks = [], offsets = [], len = 0;
      function add(bytes) { chunks.push(bytes); len += bytes.length; }
      function obj(n, body) { offsets[n] = len; add(enc(n + ' 0 obj\n' + body + '\nendobj\n')); }
      add(enc('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n'));
      obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
      obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
      obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + num(pw) + ' ' + num(ph) + '] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>');
      offsets[4] = len;
      add(enc('4 0 obj\n<< /Length ' + packed.bytes.length + (packed.flate ? ' /Filter /FlateDecode' : '') + ' >>\nstream\n'));
      add(packed.bytes);
      add(enc('\nendstream\nendobj\n'));
      obj(5, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
      obj(6, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
      var xref = len, i, x = 'xref\n0 7\n0000000000 65535 f \n';
      for (i = 1; i <= 6; i++) { x += ('0000000000' + offsets[i]).slice(-10) + ' 00000 n \n'; }
      add(enc(x + 'trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n'));
      var out = new Uint8Array(len), pos = 0;
      chunks.forEach(function (c) { out.set(c, pos); pos += c.length; });
      return { bytes: out, paper: pg.label };
    });
  }

  /** Compression Flate si le navigateur la propose, sinon flux brut. */
  function deflate(str) {
    var raw = new Uint8Array(str.length), i;
    for (i = 0; i < str.length; i++) { raw[i] = str.charCodeAt(i) & 255; }
    if (typeof CompressionStream === 'function' && typeof Response === 'function') {
      try {
        var cs = new CompressionStream('deflate'), w = cs.writable.getWriter();
        w.write(raw); w.close();
        return new Response(cs.readable).arrayBuffer().then(function (b) { return { bytes: new Uint8Array(b), flate: true }; });
      } catch (e) { /* repli ci-dessous */ }
    }
    return Promise.resolve({ bytes: raw, flate: false });
  }

  return { build: build, PAPERS: PAPERS, autoPaper: autoPaper, pdfString: pdfString, pageFor: pageFor, coverage: coverage, fitToText: fitToText };
})();

/* ===================================================================== interface */
(function () {
  'use strict';
  if (typeof document === 'undefined') { return; }
  var L = TNGFanLib, NS = 'http://www.w3.org/2000/svg', uid = 0, ZMIN = 0.5, ZMAX = 80;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag), k;
    for (k in (attrs || {})) { if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); } }
    (kids || []).forEach(function (c) { if (c) { e.appendChild(c); } });
    return e;
  }
  function sv(tag, attrs) {
    var e = document.createElementNS(NS, tag), k;
    for (k in (attrs || {})) { if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); } }
    return e;
  }
  function copy(o) { return JSON.parse(JSON.stringify(o)); }
  function r2(n) { return Math.round(n * 100) / 100; }

  function Fan(root) {
    this.root = root;
    this.cfg = JSON.parse(root.getAttribute('data-config'));
    this.st = copy(this.cfg);
    this.have = null;      // { root, tree, asc, desc, spouses } déjà chargés
    this.data = null;
    this.scene = null;
    this.history = [];
    this.sync = [];        // fonctions qui remettent le panneau d'options à jour
    this.view = { k: 1, x: 0, y: 0 };
    this.moved = false;
    this.token = 0;
    this.id = ++uid;
    this.build();
    this.load();
  }

  Fan.prototype.build = function () {
    var self = this, c = this.cfg;
    this.root.innerHTML = '';
    this.root.classList.add('tngfan-ready');

    if (c.controls) {
      this.tabs = el('div', { 'class': 'tngfan-tabs' });
      [['ancestors', tngsT('Roue d\'ascendance')], ['descendants', tngsT('Roue de descendance')], ['mixed', tngsT('Roue mixte')]].forEach(function (m) {
        var b = el('button', { type: 'button', 'class': 'tngfan-tab', text: m[1], 'data-mode': m[0] });
        b.addEventListener('click', function () { self.st.mode = m[0]; self.refresh(); });
        self.tabs.appendChild(b);
      });
      this.back = el('button', { type: 'button', 'class': 'tngfan-back', text: '← ' + tngsT('Retour'), hidden: 'hidden' });
      this.back.addEventListener('click', function () {
        var prev = self.history.pop();
        if (prev) { self.st.root = prev; self.refresh(); }
      });
      var gear = el('button', { type: 'button', 'class': 'tngfan-gear', 'aria-label': tngsT('Options'), title: tngsT('Options'), text: '⚙' });
      gear.addEventListener('click', function () { self.panel.hidden = !self.panel.hidden; });
      var kids = [this.tabs, this.back];
      if (c.pdf) {
        this.pdfBtn = el('button', { type: 'button', 'class': 'tngfan-pdf', text: '⤓ PDF', title: tngsT('Exporter en PDF') });
        this.pdfBtn.addEventListener('click', function () { self.openPdfDialog(); });
        kids.push(this.pdfBtn);
      }
      kids.push(gear);
      this.root.appendChild(el('div', { 'class': 'tngfan-bar' }, kids));
    }

    this.stage = el('div', { 'class': 'tngfan-stage' });
    this.svg = sv('svg', { 'class': 'tngfan-svg', 'preserveAspectRatio': 'xMidYMid meet' });
    this.vp = sv('g', {});
    this.svg.appendChild(this.vp);
    this.stage.appendChild(this.svg);

    var zoomBtns = [
      this.zbtn('+', tngsT('Zoom avant'), function () { self.zoomBy(1.4); }),
      this.zbtn('−', tngsT('Zoom arrière'), function () { self.zoomBy(1 / 1.4); }),
      this.zbtn('⌖', tngsT('Recentrer'), function () { self.view = { k: 1, x: 0, y: 0 }; self.applyView(); })
    ];
    this.stage.appendChild(el('div', { 'class': 'tngfan-zoom' }, zoomBtns));
    this.msg = el('div', { 'class': 'tngfan-status' });
    this.stage.appendChild(this.msg);
    this.tip = el('div', { 'class': 'tngfan-tip', hidden: 'hidden' });
    this.card = el('div', { 'class': 'tngfan-card', hidden: 'hidden' });
    this.stage.appendChild(this.tip);
    this.stage.appendChild(this.card);

    var body = el('div', { 'class': 'tngfan-body' }, [this.stage]);
    if (c.controls) { this.buildPanel(); body.appendChild(this.panel); }
    this.root.appendChild(body);
    this.bindView();
  };

  Fan.prototype.zbtn = function (t, label, fn) {
    var b = el('button', { type: 'button', text: t, 'aria-label': label, title: label });
    b.addEventListener('click', fn);
    return b;
  };

  /* ------------------------------------------------------------ requêtes (vivants : signature du shortcode) */
  Fan.prototype.livingQuery = function () {
    var c = this.cfg;
    if (c.living === 'show' && c.lsig) { return '&lv=show&ls=' + encodeURIComponent(c.lsig); }
    return c.living === 'hide' ? '&lv=hide' : '';
  };

  /* ------------------------------------------------------------ panneau d'options */
  Fan.prototype.buildPanel = function () {
    var self = this, c = this.cfg;
    this.panel = el('aside', { 'class': 'tngfan-panel', hidden: 'hidden' });
    var close = el('button', { type: 'button', 'class': 'tngfan-close', text: '×', 'aria-label': tngsT('Fermer') });
    close.addEventListener('click', function () { self.panel.hidden = true; });
    this.panel.appendChild(el('div', { 'class': 'tngfan-ph' }, [el('strong', { text: tngsT('Options') }), close]));

    var sel = function (label, key, choices, numeric) {
      var s = el('select', {});
      choices.forEach(function (ch) { s.appendChild(el('option', { value: ch[0], text: ch[1] })); });
      s.value = String(self.st[key]);
      s.addEventListener('change', function () { self.st[key] = numeric ? +s.value : s.value; self.refresh(); });
      self.sync.push(function () { s.value = String(self.st[key]); });
      self.panel.appendChild(el('label', { 'class': 'tngfan-f' }, [el('span', { text: label }), s]));
      return s;
    };
    var chk = function (label, key) {
      var i = el('input', { type: 'checkbox' });
      i.checked = !!self.st[key];
      i.addEventListener('change', function () { self.st[key] = i.checked ? 1 : 0; self.refresh(); });
      self.sync.push(function () { i.checked = !!self.st[key]; });
      self.panel.appendChild(el('label', { 'class': 'tngfan-chk' }, [i, el('span', { text: ' ' + label })]));
    };
    var nums = function (max) { var o = [], i; for (i = 1; i <= max; i++) { o.push([String(i), String(i)]); } return o; };

    // Personne (recherche)
    var q = el('input', { type: 'search', placeholder: tngsT('Rechercher une personne…'), autocomplete: 'off' });
    var res = el('div', { 'class': 'tngfan-res' });
    var timer = null;
    q.addEventListener('input', function () {
      clearTimeout(timer);
      var v = q.value.trim();
      if (v.length < 2) { res.innerHTML = ''; return; }
      timer = setTimeout(function () { self.search(v, res, q); }, 250);
    });
    this.panel.appendChild(el('label', { 'class': 'tngfan-f' }, [el('span', { text: tngsT('Personne') }), q]));
    this.panel.appendChild(res);

    sel(tngsT('Générations d\'ascendance'), 'asc', nums(c.ascMax), true);
    sel(tngsT('Générations de descendance'), 'desc', nums(c.descMax), true);
    sel(tngsT('Style des textes'), 'labels', [['symbols', tngsT('° † x (symboles)')], ['letters', tngsT('N : D : M : (lettres)')]]);
    sel(tngsT('Prénoms'), 'firstnames', [['usual', tngsT('Prénom usuel')], ['all', tngsT('Tous les prénoms')]]);

    // Forme
    var shapes = el('div', { 'class': 'tngfan-shapes' });
    [['full', '●', tngsT('Cercle complet')], ['three', '◕', tngsT('Trois quarts')], ['half', '◓', tngsT('Demi-cercle')]].forEach(function (s) {
      var b = el('button', { type: 'button', text: s[1], title: s[2], 'aria-label': s[2], 'data-shape': s[0] });
      b.addEventListener('click', function () { self.st.shape = s[0]; self.refresh(); });
      shapes.appendChild(b);
    });
    this.shapes = shapes;
    this.panel.appendChild(el('div', { 'class': 'tngfan-f' }, [el('span', { text: tngsT('Forme') }), shapes]));

    sel(tngsT('Coloration par'), 'color', [['generation', tngsT('Génération')], ['branch', tngsT('Branche')], ['sex', tngsT('Sexe')]]);
    sel(tngsT('Style'), 'style', [['tint', tngsT('Teinte légère')], ['solid', tngsT('Couleur pleine')]]);

    // Palette
    var pal = el('select', {});
    Object.keys(c.presets).forEach(function (id) { pal.appendChild(el('option', { value: id, text: c.presets[id][0] })); });
    if (!c.presets[c.palette]) { pal.appendChild(el('option', { value: c.palette, text: tngsT('Personnalisée') })); }
    pal.value = c.palette;
    this.sw = el('div', { 'class': 'tngfan-sw' });
    pal.addEventListener('change', function () {
      var p = c.presets[pal.value];
      if (p) { self.st.colors = p[1].slice(); self.st.palette = pal.value; }
      self.drawSwatches(); self.refresh();
    });
    this.sync.push(function () { pal.value = self.st.palette; self.drawSwatches(); });
    this.panel.appendChild(el('label', { 'class': 'tngfan-f' }, [el('span', { text: tngsT('Palette') }), pal]));
    this.panel.appendChild(this.sw);
    this.drawSwatches();

    sel(tngsT('Dates'), 'dates', [['years', tngsT('Années')], ['full', tngsT('Dates complètes')], ['none', tngsT('Aucune')]]);
    sel(tngsT('Lieux'), 'places', [['none', tngsT('Aucun')], ['commune', tngsT('Commune')], ['full', tngsT('Lieu complet')]]);
    chk(tngsT('Conjoints'), 'spouses');
    chk(tngsT('Texte enrichi au zoom'), 'zoomDetail');
    chk(tngsT('Ancêtres inconnus'), 'showEmpty');

    if (c.pdf) {
      var go = el('button', { type: 'button', 'class': 'tngfan-reset', text: '⤓ ' + tngsT('Exporter en PDF') + '…' });
      go.addEventListener('click', function () { self.openPdfDialog(); });
      this.panel.appendChild(go);
    }

    var reset = el('button', { type: 'button', 'class': 'tngfan-reset', text: tngsT('Paramètres par défaut') });
    reset.addEventListener('click', function () {
      var rootId = self.st.root;
      self.st = copy(self.cfg); self.st.root = rootId;
      self.sync.forEach(function (fn) { fn(); });
      self.refresh();
    });
    this.panel.appendChild(reset);
  };

  Fan.prototype.drawSwatches = function () {
    this.sw.innerHTML = '';
    var self = this;
    this.st.colors.forEach(function (col) { var s = el('span', {}); s.style.background = col; self.sw.appendChild(s); });
  };

  Fan.prototype.search = function (v, box, input) {
    var self = this, R = window.TNGFAN_REST || {};
    fetch(R.url + '/search?q=' + encodeURIComponent(v) + '&tree=' + encodeURIComponent(this.st.tree || '') + this.livingQuery(), { credentials: 'same-origin', headers: { 'X-WP-Nonce': R.nonce || '' } })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        box.innerHTML = '';
        if (!Array.isArray(list) || !list.length) { box.appendChild(el('div', { 'class': 'tngfan-none', text: tngsT('Aucun résultat.') })); return; }
        list.forEach(function (p) {
          var b = el('button', { type: 'button', text: p.name + (p.b || p.d ? ' (' + (p.b || '') + ' – ' + (p.d || '') + ')' : '') });
          b.addEventListener('click', function () { box.innerHTML = ''; input.value = ''; self.recenter(p.id); });
          box.appendChild(b);
        });
      })
      .catch(function () { box.innerHTML = ''; });
  };

  /* ------------------------------------------------------------ données */
  Fan.prototype.need = function () {
    var st = this.st;
    return { asc: st.mode === 'descendants' ? 0 : st.asc, desc: st.mode === 'ancestors' ? 0 : st.desc, spouses: st.spouses ? 1 : 0 };
  };

  Fan.prototype.recenter = function (id) {
    if (!id || id === this.st.root) { return; }
    this.history.push(this.st.root);
    this.st.root = id;
    this.refresh();
  };

  Fan.prototype.refresh = function () {
    var n = this.need(), h = this.have;
    if (h && h.root === this.st.root && h.tree === (this.st.tree || '') && h.asc >= n.asc && h.desc >= n.desc && h.spouses >= n.spouses && this.data) {
      this.render();
    } else {
      this.load();
    }
  };

  Fan.prototype.load = function () {
    var self = this, st = this.st, R = window.TNGFAN_REST || {}, n = this.need(), h = this.have;
    var asc = n.asc, desc = n.desc, spouses = n.spouses;
    if (h && h.root === st.root && h.tree === (st.tree || '')) { asc = Math.max(asc, h.asc); desc = Math.max(desc, h.desc); spouses = Math.max(spouses, h.spouses); }
    this.setMsg(tngsT('Chargement…'));
    var url = R.url + '/data?person=' + encodeURIComponent(st.root) + '&tree=' + encodeURIComponent(st.tree || '') + '&asc=' + asc + '&desc=' + desc + '&spouses=' + spouses + this.livingQuery();
    var token = ++this.token;
    fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': R.nonce || '' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (token !== self.token) { return; }
        if (!res.ok) { self.setMsg(tngsT((res.j && res.j.message) || 'Erreur de chargement.')); return; }
        self.data = res.j;
        self.have = { root: st.root, tree: st.tree || '', asc: asc, desc: desc, spouses: spouses };
        if (!st.tree && res.j.tree) { self.st.tree = res.j.tree; self.have.tree = res.j.tree; }
        self.setMsg('');
        self.render();
      })
      .catch(function () { self.setMsg(tngsT('Erreur de chargement.')); });
  };

  Fan.prototype.setMsg = function (t) { this.msg.textContent = t; this.msg.style.display = t ? '' : 'none'; };

  /* ------------------------------------------------------------ composition (géométrie + couleurs) */
  Fan.prototype.hiddenLabel = function () { return tngsT('Personne vivante'); };

  /** Calcule tous les secteurs : indépendant du DOM, utilisé par le dessin SVG et par l'export PDF. */
  Fan.prototype.compose = function () {
    var self = this, st = this.st, d = this.data, P = d.persons, mode = st.mode, unions = d.unions || {};
    var span = ({ full: 360, three: 270, half: 180 })[st.shape] || 360;
    var ancRows = mode === 'descendants' ? [[d.root]] : d.anc.slice(0, st.asc + 1);
    var descRows = mode === 'ancestors' ? [[{ id: d.root, p: -1, f: '' }]] : d.desc.slice(0, st.desc + 1);
    var showSp = !!st.spouses && mode !== 'ancestors';
    var extra = showSp ? L.extraCounts(descRows, unions) : null;
    var nodes = [], maxG = 0;

    if (mode === 'ancestors') { nodes = L.layoutAncestors(ancRows, -span / 2, span); }
    else if (mode === 'descendants') { nodes = L.layoutDescendants(descRows, -span / 2, span, extra); }
    else { span = 360; nodes = L.layoutAncestors(ancRows, -90, 180).concat(L.layoutDescendants(descRows, 90, 180, extra)); }
    nodes.forEach(function (n) { if (n.g > maxG) { maxG = n.g; } });

    var R0 = 64, w = (maxG > 10 ? 44 : (maxG > 7 ? 54 : 66)) * (showSp ? 1.3 : 1);
    // couronne des conjoints de la personne centrale (un arc par union, face à ses enfants)
    var rootSegs = [];
    if (showSp) {
      var rr = mode === 'mixed' ? [90, 270] : [-span / 2, span / 2];
      var rkids = nodes.filter(function (n) { return n.p !== undefined && n.g === 1; });
      rootSegs = L.spouseSegments({ a0: rr[0], a1: rr[1] }, rkids, unions[d.root] || []).filter(function (s) { return P[s.id]; });
    }
    var Rb = R0 + (rootSegs.length ? w * 0.45 : 0);
    var Rmax = Rb + Math.max(1, maxG) * w;
    var tintStyle = st.style === 'tint';
    var out = [];

    // secteurs enfants de chaque descendant (pour caler les conjoints)
    var kidsOf = {};
    if (showSp) {
      nodes.forEach(function (n) { if (n.p !== undefined) { (kidsOf[(n.g - 1) + '_' + n.p] = kidsOf[(n.g - 1) + '_' + n.p] || []).push(n); } });
    }
    var edges = [], ew = 2.6;
    var marr = function (ownerId, fam) {
      var u = (unions[ownerId] || []).filter(function (x) { return x[0] === fam; })[0];
      return u && (u[3] || u[4]) ? { y: u[3] || '', f: u[4] || '' } : null;
    };
    var mkNode = function (n, r0, r1, kind, owner) {
      var p = P[n.id], col = L.colorFor(owner || n, st, P), fill, stroke, sw, txtHex;
      var slot = ((r0 + r1) / 2) * L.rad(n.a1 - n.a0) * 0.7;      // largeur approximative du secteur à l'écran (px)
      if (kind === 'sp') {
        fill = tintStyle ? L.tint(col, 0.92) : L.tint(col, 0.5);
        txtHex = tintStyle ? '#1b1f23' : (L.luminance(L.toHex(L.tintRgb(col, 0.5))) > 0.6 ? '#111111' : '#ffffff');
        stroke = tintStyle ? col : '#ffffff'; sw = Math.max(0.12, Math.min(tintStyle ? 1 : 0.8, slot * 0.2));
      } else {
        fill = tintStyle ? L.tint(col, 0.82) : col;
        txtHex = tintStyle ? '#1b1f23' : (L.luminance(col) > 0.6 ? '#111111' : '#ffffff');
        stroke = tintStyle ? col : '#ffffff'; sw = Math.max(0.12, Math.min(tintStyle ? 1.4 : 1, slot * 0.2));
      }
      return {
        id: n.id, kind: kind, g: n.g, a0: n.a0, a1: n.a1, r0: r0, r1: r1, fill: fill, stroke: stroke, sw: sw, txt: txtHex, hidden: !!(p && p.h),
        lines: function (detail) {
          if (kind === 'sp') { return L.spouseLines(P[n.id], st, self.hiddenLabel(), detail, n.fam ? marr(owner.id, n.fam) : null, !!(P[owner.id] && P[owner.id].h)); }
          return L.lines(P[n.id], st, self.hiddenLabel(), detail);
        }, empty: false
      };
    };

    rootSegs.forEach(function (sg) { out.push(mkNode({ id: sg.id, fam: sg.fam, g: 1, a0: sg.a0, a1: sg.a1 }, R0, Rb, 'sp', { id: d.root, g: 1, branch: 0, i: 0 })); });
    nodes.forEach(function (n) {
      var r0 = Rb + (n.g - 1) * w, r1 = r0 + w;
      if (n.id === null || n.id === undefined || !P[n.id]) {
        if (st.showEmpty) { out.push({ id: null, kind: 'empty', g: n.g, a0: n.a0, a1: n.a1, r0: r0, r1: r1, empty: true }); }
        return;
      }
      var segs = [];
      if (showSp && n.p !== undefined) {
        segs = L.spouseSegments(n, kidsOf[n.g + '_' + n.i] || [], unions[n.id] || []).filter(function (s) { return P[s.id]; });
      }
      var rp = segs.length ? r0 + w * 0.74 : r1;
      out.push(mkNode(n, r0, rp, 'p'));
      segs.forEach(function (s) { out.push(mkNode({ id: s.id, fam: s.fam, g: n.g, a0: s.a0, a1: s.a1 }, rp, r1, 'sp', n)); });
      if (tintStyle) { edges.push({ a0: n.a0, a1: n.a1, r0: r1 - ew, r1: r1, fill: L.colorFor(n, st, P) }); }
    });

    // centre : la personne et ses conjoints
    var spIds = [];
    if (st.spouses) { (unions[d.root] || []).forEach(function (u) { if (u[1] && P[u[1]] && spIds.indexOf(u[1]) < 0) { spIds.push(u[1]); } }); }
    var center = {
      id: d.root, R: R0 - 3, spouses: spIds,
      lines: function (detail) {
        var ls = L.lines(P[d.root] || { h: 1 }, st, self.hiddenLabel(), detail), extraLines = [];
        spIds.slice(0, 4).forEach(function (sid) {
          var sl = L.lines(P[sid], st, self.hiddenLabel(), detail);
          if (sl.length) { extraLines.push('x ' + sl[0]); if (detail >= 1 && sl[1]) { extraLines.push('   ' + sl[1]); } }
        });
        return ls.concat(extraLines);
      }
    };

    var m = 12, vb;
    if (mode !== 'mixed' && st.shape === 'half') { vb = [-Rmax - m, -Rmax - m, 2 * Rmax + 2 * m, Rmax + Rb + 2 * m]; }
    else { vb = [-Rmax - m, -Rmax - m, 2 * Rmax + 2 * m, 2 * Rmax + 2 * m]; }
    if (mode === 'descendants' && st.shape === 'half') { vb = [-Rmax - m, -Rb - m, 2 * Rmax + 2 * m, Rmax + Rb + 2 * m]; }
    return { nodes: out, edges: edges, center: center, maxG: maxG, Rmax: Rmax, vb: vb };
  };

  /* ------------------------------------------------------------ dessin */
  Fan.prototype.render = function () {
    var self = this, d = this.data;
    var sc = this.scene = this.compose();
    this.vp.innerHTML = '';
    this.defs = sv('defs', {});
    this.vp.appendChild(this.defs);

    var frag = document.createDocumentFragment();
    sc.nodes.forEach(function (n, idx) {
      if (n.empty) { frag.appendChild(sv('path', { d: L.sector(n.r0, n.r1, n.a0, n.a1), 'class': 'tngfan-empty' })); return; }
      var g = sv('g', { 'class': 'tngfan-node' + (n.hidden ? ' is-hidden' : '') + (n.kind === 'sp' ? ' is-spouse' : ''), 'data-i': idx });
      g.appendChild(sv('path', { d: L.sector(n.r0, n.r1, n.a0, n.a1), fill: n.fill, stroke: n.stroke, 'stroke-width': n.sw }));
      frag.appendChild(g);
    });
    (sc.edges || []).forEach(function (e) { frag.appendChild(sv('path', { d: L.sector(e.r0, e.r1, e.a0, e.a1), fill: e.fill, stroke: 'none', 'class': 'tngfan-edge', 'pointer-events': 'none' })); });
    this.vp.appendChild(frag);

    var cg = sv('g', { 'class': 'tngfan-node tngfan-center', 'data-i': 'c' });
    cg.appendChild(sv('circle', { r: sc.center.R, fill: '#ffffff', stroke: '#94a3b8', 'stroke-width': 2 }));
    this.vp.appendChild(cg);
    this.layer = sv('g', { 'class': 'tngfan-labels', 'pointer-events': 'none' });
    this.vp.appendChild(this.layer);

    this.svg.setAttribute('viewBox', sc.vb.join(' '));
    this.view = { k: 1, x: 0, y: 0 };
    this.applyView();
    this.tip.hidden = true; this.card.hidden = true;

    var msg = '';
    if (d.truncAnc) { msg = tngsT('Ascendance limitée à 8 400 personnes : réduisez le nombre de générations.'); }
    else if (d.truncDesc) { msg = tngsT('Descendance limitée à 3 000 personnes : réduisez le nombre de générations.'); }
    this.setMsg(msg);
    this.syncControls();
  };

  Fan.prototype.syncControls = function () {
    var st = this.st;
    if (this.tabs) {
      [].forEach.call(this.tabs.children, function (b) { b.classList.toggle('on', b.getAttribute('data-mode') === st.mode); });
      this.back.hidden = !this.history.length;
    }
    if (this.shapes) {
      [].forEach.call(this.shapes.children, function (b) { b.classList.toggle('on', b.getAttribute('data-shape') === st.shape); b.disabled = st.mode === 'mixed'; });
    }
  };

  /* ------------------------------------------------------------ textes recalculés selon le zoom */
  /** Pixels écran par unité du dessin, zoom compris. */
  Fan.prototype.pxPerUnit = function () {
    var vb = this.scene.vb, w = this.stage.clientWidth || 800, h = this.stage.clientHeight || 600;
    return Math.min(w / vb[2], h / vb[3]) * this.view.k;
  };

  /** Rectangle visible, en coordonnées du dessin (hors zoom) : [xmin, ymin, xmax, ymax]. */
  Fan.prototype.visibleRect = function () {
    var vb = this.scene.vb, w = this.stage.clientWidth || 800, h = this.stage.clientHeight || 600, v = this.view;
    var s0 = Math.min(w / vb[2], h / vb[3]), cx = vb[0] + vb[2] / 2, cy = vb[1] + vb[3] / 2;
    var hw = w / (2 * s0), hh = h / (2 * s0);
    return [(cx - hw - v.x) / v.k, (cy - hh - v.y) / v.k, (cx + hw - v.x) / v.k, (cy + hh - v.y) / v.k];
  };

  Fan.prototype.scheduleLabels = function () {
    var self = this;
    clearTimeout(this.lt);
    this.lt = setTimeout(function () { self.labelAll(); }, 90);
  };

  Fan.prototype.labelAll = function () {
    if (!this.scene || !this.layer) { return; }
    var self = this, S = this.pxPerUnit(), det = L.detailFor(this.view.k, this.st), vr = this.visibleRect(), sc = this.scene;
    while (this.layer.firstChild) { this.layer.removeChild(this.layer.firstChild); }
    while (this.defs.firstChild) { this.defs.removeChild(this.defs.firstChild); }
    var frag = document.createDocumentFragment();
    sc.nodes.forEach(function (n) {
      if (n.empty) { return; }
      var rm = (n.r0 + n.r1) / 2, mid = (n.a0 + n.a1) / 2, c = L.pt(rm, mid);
      var margin = (n.r1 - n.r0) + rm * L.rad(n.a1 - n.a0) / 2;
      if (c[0] + margin < vr[0] || c[0] - margin > vr[2] || c[1] + margin < vr[1] || c[1] - margin > vr[3]) { return; }
      var plan = L.labelPlan(n.lines(det), n.r0, n.r1, n.a0, n.a1, S);
      if (plan) { self.drawPlan(frag, plan, n.txt); }
    });
    // centre
    var cp = L.centerPlan(sc.center.lines(det), sc.center.R, S);
    if (cp) {
      cp.lines.forEach(function (ln) {
        var t = sv('text', { x: 0, y: r2(ln.y), dy: '0.35em', 'text-anchor': 'middle', 'font-size': r2(cp.fs), 'font-weight': ln.bold ? '700' : '400', fill: '#1b1f23' });
        t.textContent = ln.text;
        frag.appendChild(t);
      });
    }
    this.layer.appendChild(frag);
  };

  Fan.prototype.drawPlan = function (frag, plan, color) {
    var common = { 'font-size': r2(plan.fs), fill: color }, k, ln;
    if (plan.mode === 'tangent') {
      for (k = 0; k < plan.lines.length; k++) {
        ln = plan.lines[k];
        var pid = 'tf' + this.id + '_' + (++uid);
        this.defs.appendChild(sv('path', { id: pid, d: L.arc(ln.r, plan.a0, plan.a1, plan.bottom) }));
        var t = sv('text', Object.assign({ dy: '0.35em', 'text-anchor': 'middle', 'font-weight': ln.bold ? '600' : '400' }, common));
        var tp = sv('textPath', { startOffset: '50%' });
        tp.setAttribute('href', '#' + pid);
        tp.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', '#' + pid);
        tp.textContent = ln.text;
        t.appendChild(tp);
        frag.appendChild(t);
      }
    } else {
      var p = L.pt(plan.rm, plan.mid);
      var grp = sv('g', { transform: 'translate(' + r2(p[0]) + ' ' + r2(p[1]) + ') rotate(' + r2(plan.rot) + ')' });
      for (k = 0; k < plan.lines.length; k++) {
        ln = plan.lines[k];
        var t2 = sv('text', Object.assign({ x: 0, y: r2(ln.y), dy: '0.35em', 'text-anchor': 'middle', 'font-weight': ln.bold ? '600' : '400' }, common));
        t2.textContent = ln.text;
        grp.appendChild(t2);
      }
      frag.appendChild(grp);
    }
  };

  /* ------------------------------------------------------------ export PDF */
  /** Données de la roue pour l'export : secteurs avec leurs textes (sans les cases vides). */
  Fan.prototype.pdfScene = function () {
    var scene = this.scene;
    return {
      vb: scene.vb, maxG: scene.maxG, edges: scene.edges || [],
      nodes: scene.nodes.filter(function (n) { return !n.empty; }).map(function (n) {
        return { fill: n.fill, stroke: n.stroke, sw: n.sw, txt: n.txt, a0: n.a0, a1: n.a1, r0: n.r0, r1: n.r1, lines: n.lines };
      }),
      center: scene.center
    };
  };

  /** Boîte de dialogue : format de la feuille, orientation, et part des cases dont le texte tient en entier. */
  Fan.prototype.openPdfDialog = function () {
    var self = this;
    if (!this.scene || !this.data) { return; }
    if (this.dlg) { this.dlg.remove(); this.dlg = null; }
    var scene = this.pdfScene(), state = { pg: null }, timer = null;
    var paper = el('select', {}), orient = el('select', {}), content = el('select', {});
    [['text', tngsT('Adapté au texte (tout le texte en entier)')], ['auto', tngsT('Automatique')], ['A4', 'A4'], ['A3', 'A3'], ['A2', 'A2'], ['A1', 'A1'], ['A0', 'A0']].forEach(function (o) { paper.appendChild(el('option', { value: o[0], text: o[1] })); });
    [['auto', tngsT('Automatique')], ['portrait', tngsT('Portrait')], ['landscape', tngsT('Paysage')]].forEach(function (o) { orient.appendChild(el('option', { value: o[0], text: o[1] })); });
    [['0', tngsT('Comme à l\'écran')], ['3', tngsT('Complet : dates et lieux complets')]].forEach(function (o) { content.appendChild(el('option', { value: o[0], text: o[1] })); });
    var info = el('div', { 'class': 'tngfan-dlg-info' });
    var ok = el('button', { type: 'button', 'class': 'tngfan-dlg-ok', text: '⤓ ' + tngsT('Exporter en PDF') });
    var cancel = el('button', { type: 'button', text: tngsT('Fermer') });
    var close = function () { if (self.dlg) { self.dlg.remove(); self.dlg = null; } };
    cancel.addEventListener('click', close);

    var update = function () {
      clearTimeout(timer);
      ok.disabled = true;
      info.textContent = tngsT('Calcul…');
      timer = setTimeout(function () {
        var o = orient.value, det = +content.value, pg, exact = true, cov;
        if (paper.value === 'text') { var r = TNGFanPdf.fitToText(scene, o, det); pg = r.page; exact = r.exact; }
        else { pg = TNGFanPdf.pageFor(scene, paper.value === 'auto' ? null : paper.value, o); }
        cov = TNGFanPdf.coverage(scene, pg, false, det);
        state.pg = pg; state.detail = det;
        var txt = tngsT('Feuille') + ' : ' + pg.label.replace(' portrait', ' ' + tngsT('portrait')).replace(' landscape', ' ' + tngsT('paysage')) + ' – ' +
          cov.full + ' / ' + cov.total + ' ' + tngsT('cases avec le texte complet');
        if (cov.full < cov.total) { txt += (paper.value === 'text' && !exact) ? ' – ' + tngsT('même au format maximal, certains textes restent tronqués.') : ' – ' + tngsT('les autres cases s\'affichent sans texte ou tronquées : choisissez une feuille plus grande.'); }
        info.textContent = txt;
        ok.disabled = false;
      }, 40);
    };
    paper.addEventListener('change', update);
    orient.addEventListener('change', update);
    content.addEventListener('change', update);
    ok.addEventListener('click', function () { if (state.pg) { self.exportPdf(state.pg, state.detail); close(); } });

    this.dlg = el('div', { 'class': 'tngfan-dlg', role: 'dialog' }, [
      el('strong', { text: tngsT('Exporter en PDF') }),
      el('label', { 'class': 'tngfan-f' }, [el('span', { text: tngsT('Format de la feuille') }), paper]),
      el('label', { 'class': 'tngfan-f' }, [el('span', { text: tngsT('Orientation') }), orient]),
      el('label', { 'class': 'tngfan-f' }, [el('span', { text: tngsT('Contenu') }), content]),
      info,
      el('div', { 'class': 'tngfan-acts' }, [ok, cancel])
    ]);
    this.stage.appendChild(this.dlg);
    update();
  };

  Fan.prototype.exportPdf = function (page, detail) {
    var self = this, st = this.st, d = this.data;
    if (!this.scene || !d) { return; }
    var P = d.persons, rp = P[d.root] || { h: 1 };
    var name = rp.h ? this.hiddenLabel() : (((rp.ln || '').toUpperCase() + ' ' + (rp.fn || '')).trim() || d.root);
    var modeName = ({ ancestors: tngsT('Roue d\'ascendance'), descendants: tngsT('Roue de descendance'), mixed: tngsT('Roue mixte') })[st.mode];
    var info = '';
    if (st.mode !== 'descendants') { info += ' – ' + st.asc + ' ' + tngsT('gén. d\'ascendance'); }
    if (st.mode !== 'ancestors') { info += ' – ' + st.desc + ' ' + tngsT('gén. de descendance'); }
    var opts = {
      page: page || null,
      title: modeName + ' : ' + name + info,
      footer: tngsT('Généré avec TNG Suite') + ' – ' + new Date().toLocaleDateString(),
      detail: detail || 0
    };
    var wrap = this.pdfScene();
    if (this.pdfBtn) { this.pdfBtn.disabled = true; }
    this.setMsg(tngsT('Création du PDF…'));
    setTimeout(function () {
      TNGFanPdf.build(wrap, opts).then(function (res) {
        var blob = new Blob([res.bytes], { type: 'application/pdf' }), a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'roue-' + String(d.root).replace(/[^A-Za-z0-9_\-]/g, '') + '.pdf';
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
        self.setMsg('');
      }).catch(function () { self.setMsg(tngsT('Échec de l\'export PDF.')); })
        .then(function () { if (self.pdfBtn) { self.pdfBtn.disabled = false; } });
    }, 30);
  };

  /* ------------------------------------------------------------ infobulle et fiche */
  Fan.prototype.describe = function (id) {
    var p = this.data.persons[id];
    if (!p) { return []; }
    if (p.h) { return [tngsT('Personne vivante')]; }
    var fn = p.fn || '', out = [((p.ln || '').toUpperCase() + ' ' + fn).trim() || '?'];
    if (p.bf || p.by || p.bp) { out.push('° ' + (p.bf || p.by || '') + (p.bp ? ' – ' + p.bp : '')); }
    if (p.df || p.dy || p.dp) { out.push('† ' + (p.df || p.dy || '') + (p.dp ? ' – ' + p.dp : '')); }
    return out;
  };

  Fan.prototype.nodeId = function (target) {
    var g = target && target.closest ? target.closest('.tngfan-node') : null;
    if (!g) { return null; }
    var i = g.getAttribute('data-i');
    if (i === 'c') { return this.scene.center.id; }
    var n = this.scene.nodes[+i];
    return n ? n.id : null;
  };

  Fan.prototype.showTip = function (id, ev) {
    var l = this.describe(id);
    this.tip.innerHTML = '';
    l.forEach(function (s, i) { this.tip.appendChild(el(i ? 'div' : 'strong', { text: s })); }, this);
    this.tip.hidden = false;
    this.moveTip(ev);
  };

  Fan.prototype.moveTip = function (ev) {
    var r = this.stage.getBoundingClientRect();
    this.tip.style.left = Math.min(ev.clientX - r.left + 14, Math.max(0, r.width - 200)) + 'px';
    this.tip.style.top = (ev.clientY - r.top + 14) + 'px';
  };

  Fan.prototype.showCard = function (id, ev) {
    var self = this, p = this.data.persons[id];
    this.card.innerHTML = '';
    this.describe(id).forEach(function (s, i) { self.card.appendChild(el(i ? 'div' : 'strong', { text: s })); });
    var acts = el('div', { 'class': 'tngfan-acts' });
    if (id !== this.st.root) {
      var c = el('button', { type: 'button', text: tngsT('Centrer ici') });
      c.addEventListener('click', function () { self.card.hidden = true; self.recenter(id); });
      acts.appendChild(c);
    }
    if (p && !p.h && this.cfg.siteUrl) {
      var url = this.cfg.siteUrl + 'getperson.php?personID=' + encodeURIComponent(id) + '&tree=' + encodeURIComponent(this.st.tree || '');
      var a = el('a', { href: url, target: '_blank', rel: 'noopener noreferrer', text: tngsT('Fiche') + ' ↗' });
      acts.appendChild(a);
    }
    var x = el('button', { type: 'button', text: tngsT('Fermer') });
    x.addEventListener('click', function () { self.card.hidden = true; });
    acts.appendChild(x);
    this.card.appendChild(acts);
    this.card.hidden = false;
    this.tip.hidden = true;
    var r = this.stage.getBoundingClientRect();
    this.card.style.left = Math.max(8, Math.min(ev.clientX - r.left + 8, r.width - 230)) + 'px';
    this.card.style.top = Math.max(8, Math.min(ev.clientY - r.top + 8, r.height - 130)) + 'px';
  };

  /* ------------------------------------------------------------ zoom et déplacement */
  Fan.prototype.applyView = function () {
    var v = this.view;
    this.vp.setAttribute('transform', 'translate(' + v.x + ' ' + v.y + ') scale(' + v.k + ')');
    this.scheduleLabels();
  };

  Fan.prototype.zoomBy = function (f, cx, cy) {
    var v = this.view, k2 = Math.max(ZMIN, Math.min(ZMAX, v.k * f));
    f = k2 / v.k;
    cx = cx === undefined ? 0 : cx; cy = cy === undefined ? 0 : cy;
    v.x = cx - (cx - v.x) * f; v.y = cy - (cy - v.y) * f; v.k = k2;
    this.applyView();
  };

  Fan.prototype.toSvg = function (ev) {
    var pt = this.svg.createSVGPoint();
    pt.x = ev.clientX; pt.y = ev.clientY;
    var m = this.svg.getScreenCTM();
    return m ? pt.matrixTransform(m.inverse()) : { x: 0, y: 0 };
  };

  Fan.prototype.bindView = function () {
    var self = this, down = null;
    this.svg.addEventListener('wheel', function (ev) {
      ev.preventDefault();
      var p = self.toSvg(ev);
      self.zoomBy(ev.deltaY < 0 ? 1.2 : 1 / 1.2, p.x, p.y);
    }, { passive: false });
    this.svg.addEventListener('pointerdown', function (ev) {
      if (ev.button !== undefined && ev.button !== 0) { return; }
      down = { x: ev.clientX, y: ev.clientY, vx: self.view.x, vy: self.view.y };
      self.moved = false;
    });
    var onMove = function (ev) {
      if (!self.root.isConnected) { return; }
      if (!down) { return; }
      var dx = ev.clientX - down.x, dy = ev.clientY - down.y;
      if (!self.moved && Math.abs(dx) + Math.abs(dy) < 5) { return; }
      self.moved = true;
      var m = self.svg.getScreenCTM(), s = m ? m.a : 1;
      self.view.x = down.vx + dx / s; self.view.y = down.vy + dy / s;
      self.applyView();
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', function () {
      down = null;
      setTimeout(function () { self.moved = false; }, 0);
    });
    window.addEventListener('resize', function () { if (self.root.isConnected && self.scene) { self.scheduleLabels(); } });

    // survol et clic : un seul écouteur pour des milliers de secteurs
    this.svg.addEventListener('mouseover', function (ev) {
      var id = self.nodeId(ev.target);
      if (id && self.data.persons[id]) { self.showTip(id, ev); } else { self.tip.hidden = true; }
    });
    this.svg.addEventListener('mousemove', function (ev) { if (!self.tip.hidden) { self.moveTip(ev); } });
    this.svg.addEventListener('mouseleave', function () { self.tip.hidden = true; });
    this.svg.addEventListener('click', function (ev) {
      if (self.moved) { return; }
      var id = self.nodeId(ev.target);
      if (id) { self.showCard(id, ev); } else { self.card.hidden = true; }
    });
  };

  function init() {
    [].forEach.call(document.querySelectorAll('.tngfan[data-config]'), function (n) {
      if (!n.getAttribute('data-ready')) { n.setAttribute('data-ready', '1'); new Fan(n); }
    });
  }
  window.TNGFanMount = function (node) { return new Fan(node); };
  window.TNGFanInit = init;
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();

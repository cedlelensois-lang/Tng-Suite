/* TNG Suite – Liste flash : recherche, filtres (lieu, période), tri, pagination et copie pour un forum. */
(function () {
  'use strict';
  var T = window.TNGFLASH_T || {};

  function norm(s) {
    s = String(s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }

  function init(box) {
    var table = box.querySelector('.tngflash-table'), body = table.tBodies[0];
    var rows = [].slice.call(body.rows), limit = parseInt(box.getAttribute('data-limit'), 10) || 50, shown = limit;
    var q = box.querySelector('.tngflash-q'), pq = box.querySelector('.tngflash-pl-q');
    var from = box.querySelector('.tngflash-from'), to = box.querySelector('.tngflash-to');
    var count = box.querySelector('.tngflash-count'), btn = box.querySelector('.tngflash-more-btn');
    var copy = box.querySelector('.tngflash-copy');
    var visible = rows.slice();

    rows.forEach(function (r) { try { r.tngPls = JSON.parse(r.getAttribute('data-pls') || '[]'); } catch (e) { r.tngPls = []; } });

    /** Le lieu (nom, année min, année max) correspond-il au texte de lieu et à la période ? */
    function placeOk(pl, p, a, b) {
      if (p && pl[0].indexOf(p) < 0) { return false; }
      if (a !== null || b !== null) {
        if (!pl[1] && !pl[2]) { return false; }
        if (a !== null && pl[2] && pl[2] < a) { return false; }
        if (b !== null && pl[1] && pl[1] > b) { return false; }
      }
      return true;
    }

    function apply() {
      var t = q ? norm(q.value).trim() : '', p = pq ? norm(pq.value).trim() : '';
      var a = from && from.value !== '' ? parseInt(from.value, 10) : null, b = to && to.value !== '' ? parseInt(to.value, 10) : null;
      var byPlace = !!p;
      visible = rows.filter(function (r) {
        if (t && r.getAttribute('data-name').indexOf(t) < 0) { return false; }
        var mn = +r.getAttribute('data-min'), mx = +r.getAttribute('data-max');
        if (a !== null && mx && mx < a) { return false; }
        if (b !== null && mn && mn > b) { return false; }
        if ((a !== null || b !== null) && !mn && !mx) { return false; }
        // lieu et période portent sur le MÊME lieu : un lieu cité dans une autre période ne compte pas
        if (byPlace) { return r.tngPls.some(function (pl) { return placeOk(pl, p, a, b); }); }
        return true;
      });
      // les lieux qui ne correspondent pas aux filtres sont estompés
      // filtre actif : on montre les lieux qui correspondent (même ceux cachés par « +N »), les autres sont estompés
      var on = !!(p || a !== null || b !== null);
      rows.forEach(function (r) {
        var hiddenLeft = 0, chips = r.querySelectorAll('.tngflash-pl'), more = r.querySelector('.tngflash-more');
        [].forEach.call(chips, function (c) {
          var ok = placeOk([c.getAttribute('data-nm'), +c.getAttribute('data-a'), +c.getAttribute('data-b')], p, a, b), extra = c.classList.contains('tngflash-x');
          c.classList.toggle('tngflash-dim', on && !ok);
          c.hidden = extra && !(on && ok);
          if (c.hidden) { hiddenLeft++; }
        });
        if (more) {
          hiddenLeft += +more.getAttribute('data-extra') || 0;
          more.textContent = '+' + hiddenLeft;
          more.hidden = hiddenLeft <= 0;
        }
      });
      shown = limit;
      render();
    }

    function render() {
      rows.forEach(function (r) { r.hidden = true; });
      visible.forEach(function (r, i) { r.hidden = i >= shown; });
      count.textContent = visible.length ? Math.min(shown, visible.length) + ' / ' + visible.length + ' ' + (T.shown || '') : (T.none || '');
      if (btn) { btn.hidden = visible.length <= shown; }
    }

    [q, pq, from, to].forEach(function (el) { if (el) { el.addEventListener('input', apply); } });
    if (btn) { btn.addEventListener('click', function () { shown += limit; render(); }); }

    [].forEach.call(table.tHead.querySelectorAll('th[data-sort]'), function (th) {
      th.addEventListener('click', function () {
        var key = th.getAttribute('data-sort'), asc = th.getAttribute('aria-sort') !== 'ascending';
        [].forEach.call(table.tHead.querySelectorAll('th'), function (h) { h.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        rows.sort(function (r1, r2) {
          var x, y;
          if (key === 'name') { x = r1.getAttribute('data-name'); y = r2.getAttribute('data-name'); return asc ? (x < y ? -1 : x > y ? 1 : 0) : (x < y ? 1 : x > y ? -1 : 0); }
          x = +r1.getAttribute('data-' + key); y = +r2.getAttribute('data-' + key);
          if (key === 'min') { x = x || 99999; y = y || 99999; }
          return asc ? x - y : y - x;
        });
        rows.forEach(function (r) { body.appendChild(r); });
        apply();
      });
    });

    if (copy) {
      copy.addEventListener('click', function () {
        var lines = visible.map(function (r) {
          var c = r.cells, name = c[0].textContent.replace(/\s+/g, ' ').trim();
          var places = [].map.call(c[3].querySelectorAll('.tngflash-pl'), function (s) { return s.firstChild.textContent.trim(); }).join(', ');
          return name + ' ; ' + (+r.getAttribute('data-min') || '') + ' ; ' + (+r.getAttribute('data-max') || '') + ' ; ' + places;
        });
        var txt = lines.join('\n');
        var done = function () { count.textContent = T.copied || ''; };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(txt).then(done, function () { count.textContent = T.copyfail || ''; });
        } else {
          var ta = document.createElement('textarea');
          ta.value = txt; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); done(); } catch (e) { count.textContent = T.copyfail || ''; }
          ta.remove();
        }
      });
    }
    render();
  }

  document.addEventListener('DOMContentLoaded', function () {
    [].forEach.call(document.querySelectorAll('.tngflash'), init);
  });
})();

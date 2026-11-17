/* TNG Suite – Cohérence des données : lancement de l'analyse par lots (AJAX), barre de progression. */
(function () {
  'use strict';
  var C = window.TNGCOH || {};
  var M = C.msg || {};
  var go = document.getElementById('tngcoh-go');
  if (!go) { return; }
  var cancel = document.getElementById('tngcoh-cancel');
  var bar = document.getElementById('tngcoh-bar');
  var fill = document.getElementById('tngcoh-fill');
  var status = document.getElementById('tngcoh-status');
  var box = document.getElementById('tngcoh-run');
  var treeSel = document.getElementById('tngcoh-tree');
  var token = '', tree = '', stopped = false;

  function post(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', C.nonce);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
  }
  function text(t) { status.textContent = t; }
  function ui(running) {
    go.disabled = running;
    cancel.hidden = !running;
    bar.hidden = !running;
  }
  function show(d) {
    fill.style.width = (d.pct || 0) + '%';
    text((d.pct || 0) + ' % – ' + (d.label || '') + ' – ' + d.found + ' ' + (M.found || ''));
  }
  function reload() {
    var u = new URL(window.location.href);
    u.searchParams.set('tngtree', tree);
    u.hash = '#tng-coherence';
    window.location.href = u.toString();
  }
  function end(d) {
    ui(false);
    if (d.status === 'done') { text(M.done || ''); setTimeout(reload, 400); }
    else if (d.status === 'canceled') { text(M.canceled || ''); setTimeout(reload, 800); }
    else { text((M.error || '') + ' ' + (d.err || '')); }
  }
  function step() {
    if (stopped) { return; }
    post('tngcoh_step', { token: token }).then(function (r) {
      if (!r.success) { ui(false); text((M.error || '') + ' ' + ((r.data && r.data.message) || '')); return; }
      var d = r.data;
      show(d);
      if (d.status === 'running') { setTimeout(step, d.busy ? 1500 : 50); } else { end(d); }
    }).catch(function () { ui(false); text(M.network || ''); });
  }
  function begin(t, tk) {
    tree = t; token = tk; stopped = false;
    ui(true);
    step();
  }

  go.addEventListener('click', function () {
    tree = treeSel ? treeSel.value : '';
    ui(true);
    text(M.starting || '');
    post('tngcoh_start', { tree: tree }).then(function (r) {
      if (!r.success) { ui(false); text((M.error || '') + ' ' + ((r.data && r.data.message) || '')); return; }
      begin(tree, r.data.token);
    }).catch(function () { ui(false); text(M.network || ''); });
  });
  cancel.addEventListener('click', function () {
    stopped = true;
    post('tngcoh_cancel', { token: token }).then(function (r) { if (r.success) { end(r.data); } else { ui(false); } }).catch(function () { ui(false); });
  });
  var resume = document.getElementById('tngcoh-resume');
  if (resume && box) {
    resume.addEventListener('click', function () { begin(box.getAttribute('data-resume-tree') || '', box.getAttribute('data-resume') || ''); });
  }
})();

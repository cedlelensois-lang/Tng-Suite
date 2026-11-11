/* i18n : window.TNGS_I18N est fourni par TNG Suite quand la langue est l'anglais */
function tngsT(s) { var d = window.TNGS_I18N; return (d && Object.prototype.hasOwnProperty.call(d, s)) ? d[s] : s; }
(function () {
	'use strict';

	var PALETTE = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac',
		'#86a8c9', '#f6b26b', '#ea8a8a', '#a3cfcb', '#8cc784', '#f1db8a', '#c9a8d1', '#ffc4ca', '#c3a38f', '#d4cfcb'];
	var TYPE_MAP = { bar: 'bar', hbar: 'bar', line: 'line', area: 'line', pie: 'pie', doughnut: 'doughnut', polarArea: 'polarArea', radar: 'radar', pyramid: 'bar' };
	var nf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });

	function alpha(hex, a) {
		var m = /^#([0-9a-f]{6})$/i.exec(hex || '');
		if (!m) return hex;
		var n = parseInt(m[1], 16);
		return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
	}

	function renderTable(body, t) {
		var table = document.createElement('table');
		table.className = 'tngs-table';
		var thead = document.createElement('thead');
		var hr = document.createElement('tr');
		t.head.forEach(function (h) {
			var th = document.createElement('th');
			th.textContent = h;
			hr.appendChild(th);
		});
		thead.appendChild(hr);
		table.appendChild(thead);
		var tbody = document.createElement('tbody');
		t.body.forEach(function (row) {
			var tr = document.createElement('tr');
			row.forEach(function (c, i) {
				var td = document.createElement('td');
				if (typeof c === 'number') {
					td.textContent = nf.format(c);
					td.className = 'num';
				} else {
					td.textContent = c == null ? '—' : String(c);
					if (c == null) td.className = 'num';
				}
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		var wrap = document.createElement('div');
		wrap.className = 'tngs-scroll';
		wrap.appendChild(table);
		body.appendChild(wrap);
	}

	function renderChart(body, cfg, height) {
		var t = cfg.type;
		var pieLike = t === 'pie' || t === 'doughnut' || t === 'polarArea';
		var multi = cfg.datasets.length > 1;
		var pyramid = t === 'pyramid';   // pyramide : hommes à gauche (valeurs négatives affichées en positif), femmes à droite
		var horizontal = t === 'hbar' || pyramid;

		var datasets = cfg.datasets.map(function (ds, i) {
			var o = { label: ds.label, data: (pyramid && i === 0) ? ds.data.map(function (v) { return v == null ? v : -v; }) : ds.data };
			if (pyramid) { o.categoryPercentage = 1; o.barPercentage = 0.95; }
			var color = ds.color || PALETTE[i % PALETTE.length];
			if (pieLike) {
				var base = cfg.colors || cfg.labels.map(function (_, k) { return PALETTE[k % PALETTE.length]; });
				o.backgroundColor = (t === 'polarArea') ? base.map(function (c) { return alpha(c, 0.85); }) : base;
				o.borderColor = '#fff';
				o.borderWidth = 1;
			} else if (t === 'bar' || horizontal) {
				o.backgroundColor = (!multi && cfg.colors) ? cfg.colors : color;
			} else {
				o.borderColor = color;
				o.backgroundColor = (t === 'area' || t === 'radar') ? alpha(color, 0.6) : color;
				o.fill = (t === 'area' || t === 'radar') ? 'origin' : false;
				o.tension = 0.25;
				o.spanGaps = true;
				o.pointRadius = 3;
				if (t === 'radar') o.fill = true;
			}
			return o;
		});

		var lg = cfg.legend || {};
		var legendMode = lg.mode || 'auto';
		var legendPos = lg.pos || 'auto';

		var options = {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				legend: {
					display: legendMode === 'show' ? true : (legendMode === 'hide' ? false : (pieLike || multi)),
					position: (legendPos && legendPos !== 'auto') ? legendPos : (pieLike ? 'right' : 'top')
				},
				tooltip: {
					callbacks: {
						label: function (ctx) {
							var v = ctx.raw;
							if (v == null) return '';
							if (pyramid) v = Math.abs(v);
							var head = pieLike ? ctx.label : ctx.dataset.label;
							var txt = nf.format(v);
							if (cfg.unit === '%') txt += ' %';
							else if (cfg.unit) txt += ' ' + cfg.unit;
							if (pieLike && cfg.unit !== '%') {
								var sum = ctx.dataset.data.reduce(function (a, b) { return a + (b || 0); }, 0);
								if (sum) txt += ' (' + nf.format(100 * v / sum) + ' %)';
							}
							return head + ' : ' + txt;
						}
					}
				}
			}
		};
		if (horizontal) options.indexAxis = 'y';
		if (!pieLike && t !== 'radar') {
			var cat = { title: { display: !!cfg.xTitle, text: cfg.xTitle } };
			var val = { beginAtZero: true, title: { display: !!cfg.yTitle, text: cfg.yTitle } };
			options.scales = pyramid
				? { x: { stacked: true, title: { display: !!cfg.xTitle, text: cfg.xTitle }, ticks: { callback: function (v) { return nf.format(Math.abs(v)); } } }, y: { stacked: true, reverse: true, title: { display: false } } }
				: horizontal
				? { x: { beginAtZero: true, title: { display: !!cfg.xTitle, text: cfg.xTitle } }, y: { title: { display: false } } }
				: { x: cat, y: val };
		}

		var box = document.createElement('div');
		box.className = 'tngs-canvas';
		box.style.height = height + 'px';
		var canvas = document.createElement('canvas');
		box.appendChild(canvas);
		body.appendChild(box);
		new window.Chart(canvas, { type: TYPE_MAP[t] || 'bar', data: { labels: cfg.labels, datasets: datasets }, options: options });
	}

	function init(fig) {
		var cfg;
		try { cfg = JSON.parse(fig.getAttribute('data-config')); } catch (e) { return; }
		var body = fig.querySelector('.tngs-body');
		var height = parseInt(fig.getAttribute('data-height') || '380', 10);
		if (!cfg.labels || !cfg.labels.length) {
			body.textContent = tngsT('Aucune donnée disponible.');
			return;
		}
		if (cfg.type === 'table') { renderTable(body, cfg.table); return; }
		if (!window.Chart) { body.textContent = tngsT('Bibliothèque de graphiques indisponible.'); return; }
		renderChart(body, cfg, height);
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.tngs[data-config]'), init);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();

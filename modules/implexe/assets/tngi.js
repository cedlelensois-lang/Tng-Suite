/* i18n : window.TNGS_I18N est fourni par TNG Suite quand la langue est l'anglais */
function tngsT(s) { var d = window.TNGS_I18N; return (d && Object.prototype.hasOwnProperty.call(d, s)) ? d[s] : s; }
(function () {
	'use strict';

	var C = window.TNGI || {};
	var GEN_NAMES = [tngsT('Probant'), tngsT('Parents'), tngsT('Grands-parents'), tngsT('Arrière-grands-parents'), tngsT('Trisaïeuls'), tngsT('Quadrisaïeuls'), tngsT('Quintaïeuls'), tngsT('Sextaïeuls')];
	var nf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
	var genName = function (g) { return GEN_NAMES[g] || tngsT('Génération ') + g; };
	var pct = function (v) { return nf.format(v) + ' %'; };

	/* ---------- utilitaires DOM (tout texte passe par textContent) ---------- */
	function el(tag, props) {
		var n = document.createElement(tag);
		var kids = Array.prototype.slice.call(arguments, 2);
		if (props) {
			Object.keys(props).forEach(function (k) {
				if (props[k] == null) return;
				if (k === 'class') n.className = props[k];
				else if (k === 'text') n.textContent = props[k];
				else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), props[k]);
				else n.setAttribute(k, props[k]);
			});
		}
		(function add(list) {
			list.forEach(function (c) {
				if (c == null || c === false) return;
				if (Array.isArray(c)) add(c);
				else n.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
			});
		})(kids);
		return n;
	}

	function api(path, params, method) {
		var url = new URL(C.rest + path);
		Object.keys(params || {}).forEach(function (k) { url.searchParams.set(k, params[k]); });
		return fetch(url.toString(), { method: method || 'GET', headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' })
			.then(function (r) {
				return r.json().then(function (j) {
					if (!r.ok) throw new Error(j && j.message ? j.message : tngsT('Erreur ') + r.status);
					return j;
				});
			});
	}

	function hslHex(h, s, l) {
		s /= 100; l /= 100;
		var a = s * Math.min(l, 1 - l);
		var f = function (n) {
			var k = (n + h / 30) % 12;
			var v = l - a * Math.max(-1, Math.min(k - 3, Math.min(9 - k, 1)));
			return Math.round(255 * v).toString(16).padStart(2, '0');
		};
		return '#' + f(0) + f(8) + f(4);
	}

	function personCell(p) {
		return el('span', { class: 'tngi-person' },
			el('strong', { text: p.name }),
			p.years ? el('span', { class: 'tngi-years', text: ' ' + p.years }) : null,
			el('span', { class: 'tngi-id', text: p.id })
		);
	}

	function table(headers, rows) {
		return el('div', { class: 'tngi-scroll' },
			el('table', { class: 'tngi-table' },
				el('thead', null, el('tr', null, headers.map(function (h) { return el('th', { text: h }); }))),
				el('tbody', null, rows)
			)
		);
	}

	function td(content, cls) {
		return el('td', cls ? { class: cls } : null, content);
	}

	/* ---------- application ---------- */
	function init(root) {
		var state = { data: null, charts: [], network: null, netLayout: 'force', physics: true };
		var maxGens = parseInt(root.dataset.maxgens || '25', 10);

		var treeSel = el('select', { id: 'tngi-tree-' + Math.random().toString(36).slice(2) });
		var personIn = el('input', { type: 'text', value: root.dataset.person || '', placeholder: 'ex. I1' });
		var gensIn = el('input', { type: 'number', min: '2', max: String(maxGens), value: root.dataset.gens || String(maxGens) });
		var searchIn = el('input', { type: 'search', placeholder: tngsT('Rechercher par nom…') });
		var results = el('div', { class: 'tngi-results' });
		var status = el('div', { class: 'tngi-status', role: 'status' });
		var out = el('div', { class: 'tngi-out' });

		var runBtn = el('button', { type: 'button', class: 'tngi-btn', text: tngsT('Analyser'), onclick: run });
		var searchBtn = el('button', { type: 'button', class: 'tngi-btn tngi-btn-light', text: tngsT('Chercher'), onclick: search });
		searchIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
		personIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });

		root.appendChild(el('div', { class: 'tngi-controls' },
			el('label', null, tngsT('Arbre'), treeSel),
			el('label', null, tngsT('Personne (ID)'), personIn),
			el('label', null, tngsT('Générations'), gensIn),
			runBtn,
			el('div', { class: 'tngi-search' }, searchIn, searchBtn)
		));
		root.appendChild(results);
		root.appendChild(status);
		root.appendChild(out);

		function setStatus(msg, isError) {
			status.textContent = msg || '';
			status.className = 'tngi-status' + (isError ? ' tngi-error' : '');
		}

		function loadTrees() {
			api('trees').then(function (trees) {
				trees.forEach(function (t) { treeSel.appendChild(el('option', { value: t.id, text: t.name })); });
				if (root.dataset.tree) {
					if (!trees.some(function (t) { return t.id === root.dataset.tree; })) {
						treeSel.appendChild(el('option', { value: root.dataset.tree, text: root.dataset.tree }));
					}
					treeSel.value = root.dataset.tree;
				}
				if (root.dataset.person && treeSel.value) run();
			}).catch(function (e) { setStatus(e.message, true); });
		}

		function search() {
			var q = searchIn.value.trim();
			results.textContent = '';
			if (q.length < 2 || !treeSel.value) return;
			api('search', { tree: treeSel.value, q: q }).then(function (list) {
				if (!list.length) { results.appendChild(el('em', { text: tngsT('Aucun résultat.') })); return; }
				list.forEach(function (p) {
					results.appendChild(el('button', {
						type: 'button', class: 'tngi-result',
						onclick: function () { personIn.value = p.id; results.textContent = ''; run(); }
					}, p.name, p.years ? ' ' + p.years : '', ' · ' + p.id));
				});
			}).catch(function (e) { setStatus(e.message, true); });
		}

		function run() {
			var person = personIn.value.trim();
			if (!treeSel.value || !person) { setStatus(tngsT('Choisissez un arbre et une personne.'), true); return; }
			var gens = Math.max(2, Math.min(maxGens, parseInt(gensIn.value, 10) || maxGens));
			var net = Math.min(gens, parseInt(root.dataset.net || '6', 10));
			setStatus(tngsT('Analyse en cours… (cela peut prendre quelques secondes sur un grand arbre)'));
			runBtn.disabled = true;
			api('analyze', { tree: treeSel.value, person: person, generations: gens, net_gen: net })
				.then(function (d) { state.data = d; setStatus(''); render(); })
				.catch(function (e) { out.textContent = ''; setStatus(e.message, true); })
				.then(function () { runBtn.disabled = false; });
		}

		function destroyViz() {
			state.charts.forEach(function (c) { c.destroy(); });
			state.charts = [];
			if (state.network) { state.network.destroy(); state.network = null; }
		}

		/* ---------- rendu ---------- */
		function render() {
			destroyViz();
			out.textContent = '';
			var d = state.data;
			var s = d.summary;

			out.appendChild(el('h3', { class: 'tngi-title' }, tngsT('Ascendance de '), personCell(d.root)));
			out.appendChild(el('div', { class: 'tngi-cards' },
				card(tngsT('Générations atteintes'), String(s.depth)),
				card(tngsT('Ancêtres distincts'), nf.format(s.cum_distinct)),
				card(tngsT('Cases d’ancêtres connues'), nf.format(s.cum_slots)),
				card(tngsT('Implexe cumulé'), pct(s.cum_implexe)),
				card(tngsT('Ancêtres répétés'), nf.format(s.implexe_ancestors)),
				card(tngsT('Couples fondateurs'), nf.format(s.founders_total))
			));

			var tabs = [
				{ id: 'implexe', label: tngsT('Implexe par génération'), build: buildImplexe },
				{ id: 'top', label: tngsT('Top 100 ancêtres'), build: buildTop },
				{ id: 'evol', label: tngsT('Évolution'), build: buildEvolution },
				{ id: 'founders', label: tngsT('Couples fondateurs'), build: buildFounders },
				{ id: 'network', label: tngsT('Réseau'), build: buildNetwork }
			];
			var bar = el('div', { class: 'tngi-tabs', role: 'tablist' });
			var panels = {};
			var built = {};
			tabs.forEach(function (t) {
				var btn = el('button', { type: 'button', role: 'tab', 'data-tab': t.id, text: t.label, onclick: function () { show(t.id); } });
				bar.appendChild(btn);
				panels[t.id] = el('div', { class: 'tngi-panel', role: 'tabpanel', hidden: 'hidden' });
			});
			out.appendChild(bar);
			tabs.forEach(function (t) { out.appendChild(panels[t.id]); });

			function show(id) {
				Array.prototype.forEach.call(bar.children, function (b) {
					b.classList.toggle('active', b.getAttribute('data-tab') === id);
				});
				tabs.forEach(function (t) {
					if (t.id === id) panels[t.id].removeAttribute('hidden'); else panels[t.id].setAttribute('hidden', 'hidden');
				});
				if (!built[id]) {
					built[id] = true;
					tabs.filter(function (t) { return t.id === id; })[0].build(panels[id], d);
				} else if (id === 'network' && state.network) {
					state.network.redraw(); state.network.fit();
				}
			}
			show('implexe');
		}

		function card(label, value) {
			return el('div', { class: 'tngi-card' }, el('div', { class: 'tngi-card-v', text: value }), el('div', { class: 'tngi-card-l', text: label }));
		}

		function chartBox(height) {
			var canvas = el('canvas');
			return { wrap: el('div', { class: 'tngi-chart', style: 'height:' + height + 'px' }, canvas), canvas: canvas };
		}

		function addChart(canvas, cfg) {
			state.charts.push(new window.Chart(canvas, cfg));
		}

		/* ----- Onglet 1 : implexe ----- */
		function buildImplexe(panel, d) {
			var g = d.generations;
			if (!g.length) { panel.appendChild(el('p', { text: tngsT('Aucun ancêtre connu pour cette personne.') })); return; }
			var box = chartBox(320);
			panel.appendChild(box.wrap);
			addChart(box.canvas, {
				data: {
					labels: g.map(function (r) { return 'G' + r.gen; }),
					datasets: [
						{ type: 'bar', label: tngsT('Implexe de la génération (%)'), data: g.map(function (r) { return r.implexe; }), backgroundColor: 'rgba(214,54,56,.65)' },
						{ type: 'line', label: tngsT('Implexe cumulé (%)'), data: g.map(function (r) { return r.cum_implexe; }), borderColor: '#1d4ed8', backgroundColor: '#1d4ed8', tension: 0.25 }
					]
				},
				options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100, title: { display: true, text: '%' } } } }
			});
			panel.appendChild(el('p', { class: 'tngi-note', text: tngsT('Implexe = (cases d’ancêtres connues − ancêtres distincts) ÷ cases connues. Une personne présente plusieurs fois dans une même génération compte pour un seul ancêtre distinct.') }));
			panel.appendChild(table(
				[tngsT('Gén.'), tngsT('Niveau'), tngsT('Théorique'), tngsT('Connus'), tngsT('Distincts'), tngsT('Complétude'), tngsT('Implexe'), tngsT('Implexe cumulé')],
				g.map(function (r) {
					return el('tr', null,
						td(String(r.gen)), td(genName(r.gen)), td(nf.format(r.theoretical), 'num'), td(nf.format(r.slots), 'num'),
						td(nf.format(r.distinct), 'num'), td(pct(r.completeness), 'num'), td(pct(r.implexe), 'num'), td(pct(r.cum_implexe), 'num'));
				})
			));
		}

		/* ----- Onglet 2 : top 100 ----- */
		function buildTop(panel, d) {
			if (!d.top.length) { panel.appendChild(el('p', { text: tngsT('Aucun ancêtre.') })); return; }
			var maxOcc = d.top[0].occ || 1;
			var filter = el('input', { type: 'search', placeholder: tngsT('Filtrer la liste…'), class: 'tngi-filter' });
			var rows = d.top.map(function (p) {
				var tr = el('tr', null,
					td(String(p.rank)), td(personCell(p)), td('G' + p.first_gen),
					td(el('div', { class: 'tngi-bar' }, el('span', { style: 'width:' + Math.max(2, 100 * p.occ / maxOcc) + '%' }), el('b', { text: nf.format(p.occ) })), 'tngi-barcell'),
					td(pct(p.contrib), 'num'));
				tr._txt = (p.name + ' ' + p.id).toLowerCase();
				return tr;
			});
			filter.addEventListener('input', function () {
				var q = filter.value.trim().toLowerCase();
				rows.forEach(function (tr) { tr.style.display = !q || tr._txt.indexOf(q) !== -1 ? '' : 'none'; });
			});
			panel.appendChild(el('p', { class: 'tngi-note', text: tngsT('Classés par nombre d’occurrences dans l’ascendance (nombre de chemins menant au probant). La part théorique correspond à la somme des 1/2^g de chaque occurrence.') }));
			panel.appendChild(filter);
			panel.appendChild(table(['#', tngsT('Ancêtre'), tngsT('1re gén.'), tngsT('Occurrences'), tngsT('Part théorique')], rows));
		}

		/* ----- Onglet 3 : évolution ----- */
		function buildEvolution(panel, d) {
			var g = d.generations;
			if (!g.length) { panel.appendChild(el('p', { text: tngsT('Pas de données.') })); return; }
			var labels = g.map(function (r) { return 'G' + r.gen; });

			var b1 = chartBox(320);
			panel.appendChild(el('h4', { text: tngsT('Ancêtres théoriques, connus et distincts (échelle logarithmique)') }));
			panel.appendChild(b1.wrap);
			addChart(b1.canvas, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [
						{ label: tngsT('Théorique (2^g)'), data: g.map(function (r) { return r.theoretical; }), borderColor: '#9ca3af', borderDash: [6, 4], pointRadius: 2 },
						{ label: tngsT('Connus (avec répétitions)'), data: g.map(function (r) { return r.slots; }), borderColor: '#d63638', backgroundColor: '#d63638', tension: 0.2 },
						{ label: tngsT('Distincts'), data: g.map(function (r) { return r.distinct; }), borderColor: '#1d4ed8', backgroundColor: '#1d4ed8', tension: 0.2 }
					]
				},
				options: { responsive: true, maintainAspectRatio: false, scales: { y: { type: 'logarithmic', min: 1 } } }
			});

			var b2 = chartBox(300);
			panel.appendChild(el('h4', { text: tngsT('Complétude et implexe (%)') }));
			panel.appendChild(b2.wrap);
			addChart(b2.canvas, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [
						{ label: tngsT('Complétude de la génération'), data: g.map(function (r) { return r.completeness; }), borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.2 },
						{ label: tngsT('Implexe de la génération'), data: g.map(function (r) { return r.implexe; }), borderColor: '#d63638', backgroundColor: '#d63638', tension: 0.2 },
						{ label: tngsT('Implexe cumulé'), data: g.map(function (r) { return r.cum_implexe; }), borderColor: '#1d4ed8', backgroundColor: '#1d4ed8', tension: 0.2 }
					]
				},
				options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100, title: { display: true, text: '%' } } } }
			});
		}

		/* ----- Onglet 4 : couples fondateurs ----- */
		function buildFounders(panel, d) {
			if (!d.founders.length) {
				panel.appendChild(el('p', { text: tngsT('Aucun couple fondateur détecté dans la profondeur analysée.') }));
				return;
			}
			panel.appendChild(el('p', { class: 'tngi-note', text: tngsT('Un couple fondateur est un couple d’ancêtres dont aucun des deux conjoints n’a de parents connus dans l’arbre. Le nombre d’apparitions compte les chemins distincts reliant ce couple au probant : au-delà de 1, il révèle un implexe.') }));
			panel.appendChild(table(
				['#', tngsT('Époux'), tngsT('Épouse'), tngsT('Gén. des conjoints'), tngsT('Apparitions'), tngsT('Part théorique')],
				d.founders.map(function (f, i) {
					return el('tr', null, td(String(i + 1)), td(personCell(f.husband)), td(personCell(f.wife)),
						td('G' + f.gen), td(nf.format(f.occ), 'num'), td(pct(f.contrib), 'num'));
				})
			));
			if (d.summary.founders_total > d.founders.length) {
				panel.appendChild(el('p', { class: 'tngi-note', text: d.founders.length + tngsT(' couples affichés sur ') + nf.format(d.summary.founders_total) + '.' }));
			}
		}

		/* ----- Onglet 5 : réseau ----- */
		function buildNetwork(panel, d) {
			var net = d.network;
			if (!window.vis) { panel.appendChild(el('p', { class: 'tngi-error', text: tngsT('Bibliothèque vis-network indisponible.') })); return; }
			var layoutSel = el('select', null,
				el('option', { value: 'force', text: tngsT('Disposition libre') }),
				el('option', { value: 'tree', text: tngsT('Disposition hiérarchique') }));
			var physBtn = el('button', { type: 'button', class: 'tngi-btn tngi-btn-light', text: tngsT('Figer / relancer') });
			var box = el('div', { class: 'tngi-network' });
			panel.appendChild(el('div', { class: 'tngi-netbar' }, layoutSel, physBtn,
				el('span', { class: 'tngi-legend' }, tngsT('◆ couple fondateur · bord rouge = ancêtre répété · taille = nombre d’occurrences · couleur = génération'))));
			if (net.truncated) {
				panel.appendChild(el('p', { class: 'tngi-note', text: tngsT('Réseau limité à ') + net.nodes.length + tngsT(' personnes sur ') + nf.format(net.total) + tngsT('. Réduisez la profondeur du réseau pour tout afficher.') }));
			}
			panel.appendChild(box);

			function draw() {
				if (state.network) state.network.destroy();
				var nodes = net.nodes.map(function (n) {
					var tip = el('div', { text: n.name + (n.years ? ' (' + n.years + ')' : '') + tngsT(' · génération ') + n.gen + ' · ' + n.occ + ' occurrence(s)' });
					return {
						id: n.id, label: n.name + (n.years ? '\n' + n.years : ''), title: tip,
						shape: n.founder ? 'diamond' : 'dot',
						size: 9 + Math.min(26, Math.sqrt(n.occ) * 4),
						borderWidth: n.implexe ? 3 : 1,
						color: { background: hslHex((210 + n.gen * 37) % 360, 65, 62), border: n.implexe ? '#d63638' : '#4b5563' },
						font: { size: 11 }
					};
				});
				var edges = net.edges.map(function (e) {
					return e.type === 'union'
						? { from: e.from, to: e.to, dashes: true, arrows: '', color: { color: '#c026d3' }, width: 1 }
						: { from: e.from, to: e.to, arrows: { to: { enabled: true, scaleFactor: 0.4 } }, color: { color: '#9ca3af' } };
				});
				var hier = state.netLayout === 'tree';
				var opts = {
					interaction: { hover: true, tooltipDelay: 150 },
					layout: hier ? { hierarchical: { enabled: true, direction: tngsT('DU'), sortMethod: 'directed', levelSeparation: 90, nodeSpacing: 110 } } : {},
					physics: hier ? false : { solver: 'forceAtlas2Based', forceAtlas2Based: { gravitationalConstant: -70, springLength: 90 }, stabilization: { iterations: 250 } }
				};
				state.network = new window.vis.Network(box, { nodes: new window.vis.DataSet(nodes), edges: new window.vis.DataSet(edges) }, opts);
				state.physics = !hier;
				if (!hier) {
					state.network.once('stabilizationIterationsDone', function () {
						state.network.setOptions({ physics: false });
						state.physics = false;
					});
				}
			}
			layoutSel.addEventListener('change', function () { state.netLayout = layoutSel.value; draw(); });
			physBtn.addEventListener('click', function () {
				if (!state.network || state.netLayout === 'tree') return;
				state.physics = !state.physics;
				state.network.setOptions({ physics: state.physics });
			});
			draw();
		}

		loadTrees();
	}

	/* ---------- classement de consanguinité ---------- */
	var nf4 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 4 });
	var HINTS = {
		'1/4': tngsT('parents frère et sœur (ou parent et enfant)'),
		'1/8': tngsT('parents demi-frère/sœur, oncle et nièce, tante et neveu, ou grand-parent et petit-enfant'),
		'1/16': tngsT('parents cousins germains'),
		'1/32': tngsT('parents cousins germains éloignés d’un degré, ou demi-cousins germains'),
		'1/64': tngsT('parents cousins issus de germains'),
		'1/128': tngsT('parents cousins issus de germains éloignés d’un degré'),
		'1/256': tngsT('parents cousins au 3e degré')
	};
	function fraction(f) {
		for (var k = 0; k <= 24; k++) {
			var n = f * Math.pow(2, k);
			if (Math.round(n) >= 1 && Math.abs(n - Math.round(n)) < 1e-7) return Math.round(n) + (k ? '/' + Math.pow(2, k) : '');
		}
		return '';
	}

	function initInb(root) {
		var chart = null;
		var isAdmin = root.dataset.admin === '1';
		var state = { page: 1, calculating: false };
		var treeSel = el('select');
		var perSel = el('select', null, [25, 50, 100].map(function (n) { return el('option', { value: String(n), text: n + tngsT(' par page') }); }));
		perSel.value = root.dataset.per || '50';
		var minIn = el('input', { type: 'number', min: '0', step: '0.1', value: '0' });
		var info = el('div', { class: 'tngi-status', role: 'status' });
		var progress = el('div', { class: 'tngi-progress', hidden: 'hidden' }, el('span'));
		var out = el('div', { class: 'tngi-out' });

		var controls = el('div', { class: 'tngi-controls' },
			el('label', null, tngsT('Arbre'), treeSel),
			el('label', null, 'F minimum (%)', minIn),
			el('label', null, tngsT('Affichage'), perSel),
			el('button', { type: 'button', class: 'tngi-btn tngi-btn-light', text: tngsT('Actualiser'), onclick: function () { load(1); } })
		);
		var calcBtn = null;
		if (isAdmin) {
			calcBtn = el('button', { type: 'button', class: 'tngi-btn', text: tngsT('Calculer / recalculer'), onclick: calculate });
			controls.appendChild(calcBtn);
		}
		root.appendChild(controls);
		root.appendChild(info);
		root.appendChild(progress);
		root.appendChild(out);

		function setInfo(msg, err) { info.textContent = msg || ''; info.className = 'tngi-status' + (err ? ' tngi-error' : ''); }

		api('trees').then(function (trees) {
			trees.forEach(function (t) { treeSel.appendChild(el('option', { value: t.id, text: t.name })); });
			if (root.dataset.tree) {
				if (!trees.some(function (t) { return t.id === root.dataset.tree; })) treeSel.appendChild(el('option', { value: root.dataset.tree, text: root.dataset.tree }));
				treeSel.value = root.dataset.tree;
			}
			treeSel.addEventListener('change', function () { load(1); });
			load(1);
		}).catch(function (e) { setInfo(e.message, true); });

		function calculate() {
			if (state.calculating || !treeSel.value) return;
			state.calculating = true;
			calcBtn.disabled = true;
			progress.removeAttribute('hidden');
			var bar = progress.firstChild;
			bar.style.width = '0%';
			(function step(offset) {
				setInfo(tngsT('Calcul en cours… ne fermez pas cette page.'));
				api('inbreeding/step', { tree: treeSel.value, offset: offset }, 'POST').then(function (r) {
					bar.style.width = (r.total ? Math.min(100, 100 * r.next / r.total) : 100) + '%';
					setInfo(tngsT('Calcul en cours : ') + nf.format(r.next) + ' / ' + nf.format(r.total) + tngsT(' individus examinés, ') + nf.format(r.inbred) + tngsT(' consanguins trouvés.'));
					if (!r.done) { step(r.next); return; }
					finish();
					setInfo(tngsT('Calcul terminé.'));
					load(1);
				}).catch(function (e) { finish(); setInfo(e.message, true); });
			})(0);
			function finish() { state.calculating = false; calcBtn.disabled = false; progress.setAttribute('hidden', 'hidden'); }
		}

		function load(page) {
			if (!treeSel.value) return;
			state.page = page;
			api('inbreeding/rank', { tree: treeSel.value, page: page, per_page: perSel.value, min: minIn.value || 0 })
				.then(render)
				.catch(function (e) { out.textContent = ''; setInfo(e.message, true); });
		}

		function render(d) {
			if (chart) { chart.destroy(); chart = null; }
			out.textContent = '';
			var m = d.meta;
			if (!m || !m.complete) {
				out.appendChild(el('p', { class: 'tngi-note', text: isAdmin
					? (m ? tngsT('Le dernier calcul est incomplet : relancez-le.') : tngsT('Aucun calcul pour cet arbre. Cliquez sur « Calculer / recalculer » (la durée dépend de la taille de l’arbre).'))
					: tngsT('Le calcul n’a pas encore été effectué pour cet arbre.') }));
				if (!m) return;
			}
			var share = m.total ? 100 * m.inbred / m.total : 0;
			var meanAll = m.total ? 100 * m.sum_f / m.total : 0;
			var meanInb = m.inbred ? 100 * m.sum_f / m.inbred : 0;
			out.appendChild(el('div', { class: 'tngi-cards' },
				card(tngsT('Individus évalués'), nf.format(m.total || 0)),
				card(tngsT('Individus consanguins'), nf.format(m.inbred)),
				card(tngsT('Part de consanguins'), pct(share)),
				card('F moyen (tous)', nf4.format(meanAll) + ' %'),
				card('F moyen (consanguins)', nf4.format(meanInb) + ' %'),
				card('F maximum', nf4.format(100 * m.max_f) + ' %')
			));
			if (m.date) out.appendChild(el('p', { class: 'tngi-note', text: tngsT('Dernier calcul : ') + m.date + tngsT('. Seuls les individus dont les deux parents sont connus sont évalués ; les autres ne peuvent pas être consanguins d’après les données.') }));

			var canvas = el('canvas');
			out.appendChild(el('h4', { text: tngsT('Répartition des individus consanguins par classe de F') }));
			out.appendChild(el('div', { class: 'tngi-chart', style: 'height:240px' }, canvas));
			chart = new window.Chart(canvas, {
				type: 'bar',
				data: {
					labels: ['≥ 25 %', '12,5–25 %', '6,25–12,5 %', '3,13–6,25 %', '1,56–3,13 %', '0,78–1,56 %', '< 0,78 %'],
					datasets: [{ label: tngsT('Individus'), data: d.distribution, backgroundColor: 'rgba(214,54,56,.7)' }]
				},
				options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
			});

			if (!d.items.length) { out.appendChild(el('p', { text: tngsT('Aucun individu à afficher avec ce filtre.') })); return; }
			var maxF = d.items[0].f || 1;
			out.appendChild(el('p', { class: 'tngi-note', text: nf.format(d.total) + tngsT(' individu(s) — classés par F décroissant (à égalité : profondeur de l’ascendance connue). F est la probabilité que les deux allèles d’un même gène soient identiques par descendance.') }));
			out.appendChild(table(
				[tngsT('Rang'), tngsT('Individu'), tngsT('Père'), tngsT('Mère'), 'F', tngsT('Fraction'), tngsT('Profondeur'), tngsT('Anc. communs')],
				d.items.map(function (r) {
					var fr = fraction(r.f);
					return el('tr', null,
						td(String(r.rank)),
						td(personCell(r.person)),
						td(r.father ? personCell(r.father) : '—'),
						td(r.mother ? personCell(r.mother) : '—'),
						td(el('div', { class: 'tngi-bar' }, el('span', { style: 'width:' + Math.max(2, 100 * r.f / maxF) + '%' }), el('b', { text: nf4.format(100 * r.f) + ' %' })), 'tngi-barcell'),
						td(fr ? el('span', { title: HINTS[fr] || '', text: fr + (HINTS[fr] ? ' ⓘ' : '') }) : '', 'num'),
						td('G' + r.depth, 'num'),
						td(String(r.common), 'num'));
				})
			));
			if (d.pages > 1) {
				out.appendChild(el('div', { class: 'tngi-pager' },
					el('button', { type: 'button', class: 'tngi-btn tngi-btn-light', text: tngsT('← Précédent'), disabled: d.page <= 1 ? 'disabled' : null, onclick: function () { load(d.page - 1); } }),
					el('span', { text: tngsT(' Page ') + d.page + ' / ' + d.pages + ' ' }),
					el('button', { type: 'button', class: 'tngi-btn tngi-btn-light', text: tngsT('Suivant →'), disabled: d.page >= d.pages ? 'disabled' : null, onclick: function () { load(d.page + 1); } })
				));
			}
		}

		function card(label, value) {
			return el('div', { class: 'tngi-card' }, el('div', { class: 'tngi-card-v', text: value }), el('div', { class: 'tngi-card-l', text: label }));
		}
	}

	function boot() {
		if (!C.rest) return;
		Array.prototype.forEach.call(document.querySelectorAll('.tngi-app'), function (n) {
			if (n.classList.contains('tngi-inb')) initInb(n); else init(n);
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();

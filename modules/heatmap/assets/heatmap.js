/* i18n : window.TNGS_I18N est fourni par TNG Suite quand la langue est l'anglais */
function tngsT(s) { var d = window.TNGS_I18N; return (d && Object.prototype.hasOwnProperty.call(d, s)) ? d[s] : s; }
/* TNG Ancestors Heatmap 1.21 - front */
(function () {
    'use strict';

    var CFG = window.TNG_HEATMAP_CONFIG || {};

    var EVENT_COLORS = {
        birth: '#0055ff',
        baptism: '#00d4ff',
        marriage: '#00cc44',
        death: '#ff2222',
        burial: '#ff9900'
    };

    var OSM = '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
    var ESRI = 'Tiles © Esri';
    var ESRI_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/';
    // Tous gratuits, sans clé d'API. « z » = niveau de zoom maximal réellement fourni (au-delà, la carte est agrandie au lieu de rester vide).
    var TILES = {
        osm:           { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attr: OSM, z: 19 },
        osm_fr:        { url: 'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', attr: OSM + ' · <a href="https://www.openstreetmap.fr/">OpenStreetMap France</a>', z: 20 },
        opentopo:      { url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', attr: OSM + ', SRTM · © <a href="https://opentopomap.org">OpenTopoMap</a> (CC-BY-SA)', z: 17 },
        esri_gray:     { url: ESRI_URL + 'Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', attr: ESRI + ' — Esri, HERE, Garmin, OpenStreetMap contributors', z: 16 },
        esri_street:   { url: ESRI_URL + 'World_Street_Map/MapServer/tile/{z}/{y}/{x}', attr: ESRI + ' — Esri, HERE, Garmin, OpenStreetMap contributors', z: 19 },
        esri_topo:     { url: ESRI_URL + 'World_Topo_Map/MapServer/tile/{z}/{y}/{x}', attr: ESRI + ' — Esri, HERE, Garmin, USGS, OpenStreetMap contributors', z: 19 },
        esri_imagery:  { url: ESRI_URL + 'World_Imagery/MapServer/tile/{z}/{y}/{x}', attr: ESRI + ' — Esri, Maxar, Earthstar Geographics', z: 19 },
        esri_ocean:    { url: ESRI_URL + 'Ocean/World_Ocean_Base/MapServer/tile/{z}/{y}/{x}', attr: ESRI + ' — Esri, GEBCO, NOAA', z: 13 }
    };

    // Les données sont téléchargées une seule fois même avec plusieurs cartes sur la page.
    var dataPromise = null;
    function loadData() {
        if (!dataPromise) {
            dataPromise = fetch(CFG.restUrl, { credentials: 'same-origin' }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
        }
        return dataPromise;
    }

    function debounce(fn, ms) {
        var t = null;
        return function () {
            clearTimeout(t);
            t = setTimeout(fn, ms);
        };
    }

    function fillDatalist(datalist, values) {
        if (!datalist) return;
        var frag = document.createDocumentFragment();
        values.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v; // propriété value : aucune interprétation HTML
            frag.appendChild(o);
        });
        datalist.appendChild(frag);
    }

    function buildSearchUrl(place) {
        if (!CFG.searchUrl) return null;
        var sep = CFG.searchUrl.indexOf('?') === -1 ? '?' : '&';
        return CFG.searchUrl + sep + 'psearch=' + encodeURIComponent(place);
    }

    function calculateRadius(count) {
        return Math.min(60, 8 + (Math.sqrt(count) * 4));
    }

    function build(root, data) {
        var types = data.t || [];
        var places = data.p || [];
        var surnames = data.s || [];
        var rows = data.r || [];

        var mapEl = root.querySelector('.tng-hm-map');
        var evSelect = root.querySelector('.tng-hm-ev');
        var surnameInput = root.querySelector('.tng-hm-surname');
        var placeInput = root.querySelector('.tng-hm-place');
        var centuryRadios = root.querySelectorAll('.tng-hm-century input[type="radio"]');

        var map = L.map(mapEl, { minZoom: 2 }).setView([20, 0], 2);
        var tile = TILES[CFG.provider] || TILES.esri_gray;
        L.tileLayer(tile.url, { attribution: tile.attr, maxNativeZoom: tile.z, maxZoom: 18, subdomains: 'abc' }).addTo(map);

        var heatLayer = null;
        var markers = L.layerGroup().addTo(map);

        // Regroupe les lieux partageant les mêmes coordonnées.
        var groups = [];
        var groupOfPlace = new Array(places.length);
        var coordIndex = {};
        places.forEach(function (p, i) {
            var k = p[1] + '_' + p[2];
            if (coordIndex[k] === undefined) {
                coordIndex[k] = groups.length;
                groups.push({ lat: p[1], lng: p[2], names: [] });
            }
            var g = groups[coordIndex[k]];
            groupOfPlace[i] = coordIndex[k];
            if (g.names.indexOf(p[0]) === -1) g.names.push(p[0]);
        });

        function draw() {
            if (heatLayer) { map.removeLayer(heatLayer); heatLayer = null; }
            markers.clearLayers();

            var typeFilter = evSelect ? evSelect.value : 'all';
            var typeIdx = typeFilter === 'all' ? -1 : types.indexOf(typeFilter);

            var century = null;
            for (var i = 0; i < centuryRadios.length; i++) {
                if (centuryRadios[i].checked && centuryRadios[i].value !== 'all') {
                    century = parseInt(centuryRadios[i].value, 10);
                }
            }

            var surnameIdx = null; // null = pas de filtre ; -2 = nom inconnu (aucun résultat)
            if (surnameInput) {
                var wanted = surnameInput.value.trim().toUpperCase();
                if (wanted !== '') {
                    surnameIdx = surnames.indexOf(wanted);
                    if (surnameIdx === -1) surnameIdx = -2;
                }
            }

            var agg = new Array(groups.length);
            for (var r = 0; r < rows.length; r++) {
                var row = rows[r]; // [lieu, patronyme, type, siècle, nombre]
                if (typeIdx !== -1 && row[2] !== typeIdx) continue;
                if (century !== null && row[3] !== century) continue;
                if (surnameIdx !== null && row[1] !== surnameIdx) continue;

                var g = groupOfPlace[row[0]];
                var a = agg[g];
                if (!a) {
                    a = agg[g] = { count: 0, typeCounts: new Array(types.length) };
                    for (var t = 0; t < types.length; t++) a.typeCounts[t] = 0;
                }
                a.count += row[4];
                a.typeCounts[row[2]] += row[4];
            }

            var heatPoints = [];
            var circles = [];
            for (var gi = 0; gi < agg.length; gi++) {
                if (!agg[gi]) continue;
                var grp = groups[gi];
                var cnt = agg[gi].count;
                var intensity = 0.2;
                if (cnt >= CFG.thresholdMed) intensity = 1.0;
                else if (cnt >= CFG.thresholdLow) intensity = 0.6;
                heatPoints.push([grp.lat, grp.lng, intensity]);
                circles.push({ grp: grp, agg: agg[gi] });
            }

            var gradient = {};
            gradient[0.2] = CFG.colorLow;
            gradient[0.6] = CFG.colorMed;
            gradient[1.0] = CFG.colorHigh;
            heatLayer = L.heatLayer(heatPoints, { radius: 25, blur: 15, maxZoom: 10, gradient: gradient }).addTo(map);

            circles.forEach(function (c) {
                var count = c.agg.count;
                var radius = calculateRadius(count);

                var color = CFG.solidColor;
                var opacity = CFG.useSolid === 1 ? CFG.solidOpacity : 0;
                var weight = CFG.useSolid === 1 ? 1.5 : 0;

                if (CFG.distinctColors === 1) {
                    weight = 1.5;
                    opacity = CFG.solidOpacity > 0 ? CFG.solidOpacity : 0.4;
                    if (typeIdx !== -1) {
                        color = EVENT_COLORS[types[typeIdx]] || CFG.solidColor;
                    } else {
                        var best = -1, bestCount = 0;
                        for (var k = 0; k < c.agg.typeCounts.length; k++) {
                            if (c.agg.typeCounts[k] > bestCount) { bestCount = c.agg.typeCounts[k]; best = k; }
                        }
                        color = best !== -1 ? (EVENT_COLORS[types[best]] || CFG.solidColor) : CFG.solidColor;
                    }
                }

                var circle = L.circleMarker([c.grp.lat, c.grp.lng], {
                    radius: radius, weight: weight, color: color, fillColor: color, fillOpacity: opacity
                }).addTo(markers);

                // Tooltip construit en DOM + textContent : jamais d'HTML issu de la base.
                var tip = document.createElement('span');
                tip.textContent = c.grp.names.join(' / ') + ' (' + count + ')';
                circle.bindTooltip(tip, { direction: 'top' });

                var url = buildSearchUrl(c.grp.names[0]);
                if (url) {
                    circle.on('click', function () { window.open(url, '_blank', 'noopener'); });
                }

                if (CFG.showNumber === 1) {
                    var box = document.createElement('div');
                    box.style.lineHeight = (radius * 2) + 'px';
                    box.style.fontSize = (10 + Math.min(count, 6)) + 'px';
                    box.textContent = String(count);
                    var icon = L.divIcon({
                        className: 'tng-map-number',
                        html: box,
                        iconSize: [radius * 2, radius * 2],
                        iconAnchor: [radius, radius]
                    });
                    L.marker([c.grp.lat, c.grp.lng], { icon: icon, interactive: false }).addTo(markers);
                }
            });
        }

        // Listes de suggestions (construites une seule fois, triées).
        if (surnameInput) {
            fillDatalist(root.querySelector('datalist[id$="-sn-list"]'), surnames.slice().sort());
        }
        if (placeInput) {
            var names = [];
            var seen = {};
            places.forEach(function (p) { if (!seen[p[0]]) { seen[p[0]] = 1; names.push(p[0]); } });
            names.sort();
            fillDatalist(root.querySelector('datalist[id$="-pl-list"]'), names);

            placeInput.addEventListener('input', function (e) {
                var val = e.target.value.trim();
                for (var i = 0; i < places.length; i++) {
                    if (places[i][0] === val) { map.setView([places[i][1], places[i][2]], 12); break; }
                }
            });
            var clearPlace = root.querySelector('.tng-hm-clear-place');
            if (clearPlace) clearPlace.addEventListener('click', function () {
                placeInput.value = '';
                map.setView([20, 0], 2);
            });
        }

        if (evSelect) evSelect.addEventListener('change', draw);
        for (var i = 0; i < centuryRadios.length; i++) centuryRadios[i].addEventListener('change', draw);

        if (surnameInput) {
            surnameInput.addEventListener('input', debounce(draw, 150));
            var clearSurname = root.querySelector('.tng-hm-clear-surname');
            if (clearSurname) clearSurname.addEventListener('click', function () {
                surnameInput.value = '';
                draw();
            });
        }

        draw();
        map.invalidateSize();
    }

    function init(root) {
        if (root.getAttribute('data-ready')) return;
        root.setAttribute('data-ready', '1');

        var status = root.querySelector('.tng-hm-status');
        function fail(msg) {
            if (status) { status.textContent = msg; status.style.display = ''; }
        }

        if (typeof L === 'undefined' || typeof L.heatLayer !== 'function') {
            fail(tngsT('Bibliothèque cartographique indisponible.'));
            return;
        }

        loadData().then(function (data) {
            if (status) status.style.display = 'none';
            build(root, data);
        }).catch(function () {
            fail(tngsT('Données indisponibles pour le moment, veuillez réessayer plus tard.'));
        });
    }

    function initAll() {
        var roots = document.querySelectorAll('.tng-hm');
        for (var i = 0; i < roots.length; i++) init(roots[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();

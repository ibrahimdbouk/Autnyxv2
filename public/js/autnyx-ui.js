/*
 * Autnyx UI behaviours shared by every panel page (WP7.3). Loaded once in the
 * <head> with data-navigate-once, so SPA navigation never registers a second
 * copy of any listener. No inline event handlers anywhere: the CSP forbids
 * them (script-src-attr 'none').
 *
 *   data-href="…"      a clickable row / card; click or Enter opens the URL
 *                      (links and buttons inside it keep their own behaviour)
 *   data-confirm="…"   a link or submit button that asks before it proceeds
 *   axLoadChartJs()    loads the self-hosted Chart.js once, returns a promise
 *   data-ax-map="…"    v2.5: a street map (self-hosted Leaflet, loaded on first
 *                      use) with the stores given as JSON; initialised when it is
 *                      first visible, and again after SPA navigation
 */
(function () {
    'use strict';

    if (window.__axUi) {
        return;
    }
    window.__axUi = true;

    var CHART_JS = '/vendor/chartjs/chart-4.4.0.umd.min.js';
    var chartPromise = null;

    window.axLoadChartJs = function () {
        if (window.Chart) {
            return Promise.resolve(window.Chart);
        }
        if (!chartPromise) {
            chartPromise = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = CHART_JS;
                s.async = true;
                s.onload = function () {
                    // Charts use the panel's font stack, which starts with the
                    // AxCurrency family (the new dirham / riyal signs). Wait for
                    // that font so a tooltip never draws an empty box.
                    try {
                        window.Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
                    } catch (e) { /* keep Chart.js default */ }
                    var ready = (document.fonts && document.fonts.load)
                        ? Promise.race([
                            document.fonts.load('16px AxCurrency', '\u20C3\u20C1'),
                            new Promise(function (r) { setTimeout(r, 1500); })
                        ]).catch(function () {})
                        : Promise.resolve();
                    ready.then(function () { resolve(window.Chart); });
                };
                s.onerror = function () { chartPromise = null; reject(new Error('Chart.js failed to load')); };
                document.head.appendChild(s);
            });
        }
        return chartPromise;
    };

    // ── v2.5 street maps ─────────────────────────────────────────────────────
    var LEAFLET_JS = '/vendor/leaflet/leaflet.js';
    var LEAFLET_CSS = '/vendor/leaflet/leaflet.css';
    var leafletPromise = null;

    function loadLeaflet() {
        if (window.L && window.L.map) {
            return Promise.resolve(window.L);
        }
        if (!leafletPromise) {
            leafletPromise = new Promise(function (resolve, reject) {
                if (!document.querySelector('link[data-ax-leaflet]')) {
                    var css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = LEAFLET_CSS;
                    css.setAttribute('data-ax-leaflet', '1');
                    document.head.appendChild(css);
                }
                var s = document.createElement('script');
                s.src = LEAFLET_JS;
                s.async = true;
                s.onload = function () { resolve(window.L); };
                s.onerror = function () { leafletPromise = null; reject(new Error('Leaflet failed to load')); };
                document.head.appendChild(s);
            });
        }
        return leafletPromise;
    }

    function textEl(tag, text, cls) {
        var el = document.createElement(tag);
        el.textContent = text;
        if (cls) { el.className = cls; }
        return el;
    }

    function popupFor(p) {
        var box = document.createElement('div');
        box.className = 'ax-map-popup';
        box.appendChild(textEl('div', p.tip || '', 'ax-map-popup-tip'));
        if (p.links) {
            var row = document.createElement('div');
            row.className = 'ax-map-popup-links';
            p.links.forEach(function (l) {
                var a = textEl('a', l.label + ' \u2197');
                a.href = l.url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                row.appendChild(a);
            });
            box.appendChild(row);
        }
        return box;
    }

    function initMaps() {
        document.querySelectorAll('[data-ax-map]:not([data-ax-map-ready])').forEach(function (el) {
            if (el.offsetWidth === 0) {
                return; // hidden (schematic view chosen): set up when it is shown
            }
            var cfg;
            try { cfg = JSON.parse(el.getAttribute('data-ax-map')); } catch (e) { return; }
            el.setAttribute('data-ax-map-ready', '1');
            loadLeaflet().then(function (L) {
                var map = L.map(el, { scrollWheelZoom: false, zoomControl: true });
                L.tileLayer(cfg.tiles, { maxZoom: cfg.maxZoom || 19, attribution: cfg.attribution || '', subdomains: 'abc' }).addTo(map);
                var bounds = [];
                // Faint stores first, so the ones in focus sit on top.
                cfg.points.slice().sort(function (a, b) { return (b.dim ? 1 : 0) - (a.dim ? 1 : 0); }).forEach(function (p) {
                    var m = L.circleMarker([p.lat, p.lng], {
                        radius: p.r || 6, className: p.cls, weight: 1.5,
                        fillOpacity: p.dim ? 0.25 : (p.approx ? 0.45 : 0.85),
                        dashArray: p.approx ? '3 3' : null
                    });
                    m.bindTooltip(textEl('span', p.tip || ''), { direction: 'top', offset: [0, -4] });
                    m.bindPopup(popupFor(p));
                    m.addTo(map);
                    bounds.push([p.lat, p.lng]);
                });
                if (bounds.length) {
                    map.fitBounds(bounds, { padding: [32, 32], maxZoom: 14 });
                } else {
                    map.setView([25.2, 55.3], 9);
                }
                el.__axMap = map;
            }).catch(function () {
                el.removeAttribute('data-ax-map-ready');
                el.classList.add('ax-leaflet--failed');
            });
        });
        document.querySelectorAll('[data-ax-map-ready]').forEach(function (el) {
            if (el.__axMap && el.offsetWidth > 0) { el.__axMap.invalidateSize(); }
        });
    }

    var mapTimer = null;
    function initMapsSoon() {
        clearTimeout(mapTimer);
        mapTimer = setTimeout(initMaps, 60);
    }
    document.addEventListener('DOMContentLoaded', initMapsSoon);
    document.addEventListener('livewire:navigated', initMapsSoon);
    window.addEventListener('ax-map-shown', initMapsSoon);
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].addedNodes.length) { initMapsSoon(); return; }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    function go(href) {
        if (window.Livewire && typeof window.Livewire.navigate === 'function') {
            window.Livewire.navigate(href);
        } else {
            window.location.href = href;
        }
    }

    document.addEventListener('click', function (e) {
        var confirmEl = e.target.closest('[data-confirm]');
        if (confirmEl && !window.confirm(confirmEl.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }

        var row = e.target.closest('[data-href]');
        if (!row || e.defaultPrevented) {
            return;
        }
        if (e.target.closest('a, button, input, select, textarea, label, [data-no-row-link]')) {
            return;
        }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) {
            window.open(row.getAttribute('data-href'), '_blank', 'noopener');
            return;
        }
        go(row.getAttribute('data-href'));
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') {
            return;
        }
        var row = e.target.closest && e.target.closest('[data-href]');
        if (row && row === e.target) {
            e.preventDefault();
            go(row.getAttribute('data-href'));
        }
    });
})();

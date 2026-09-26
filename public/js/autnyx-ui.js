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

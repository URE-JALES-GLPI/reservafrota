/* global document */

/**
 * Reservafrota — gráficos do painel de análise.
 * Desenha um gráfico de pizza (SVG puro, sem bibliotecas) e barras.
 * Só age quando #reservafrota-analytics existe.
 */
(function () {
    'use strict';

    var COLORS = ['#4263eb', '#10b981', '#f59e0b', '#a855f7', '#ef4444',
                  '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b'];

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function polar(cx, cy, r, deg) {
        var a = (deg - 90) * Math.PI / 180;
        return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
    }

    function slicePath(cx, cy, r, start, end) {
        var p1 = polar(cx, cy, r, end);
        var p2 = polar(cx, cy, r, start);
        var large = (end - start) <= 180 ? 0 : 1;
        return 'M ' + cx + ' ' + cy + ' L ' + p1[0].toFixed(2) + ' ' + p1[1].toFixed(2)
            + ' A ' + r + ' ' + r + ' 0 ' + large + ' 0 ' + p2[0].toFixed(2) + ' ' + p2[1].toFixed(2) + ' Z';
    }

    function entries(obj) {
        return Object.keys(obj || {}).map(function (k) { return [k, obj[k]]; });
    }

    function drawPie(data) {
        var pieEl = document.getElementById('reservafrota-pie');
        var legendEl = document.getElementById('reservafrota-pie-legend');
        if (!pieEl) { return; }

        var items = entries(data);
        var total = items.reduce(function (s, e) { return s + e[1]; }, 0);
        if (!total) { return; }

        var cx = 110, cy = 110, r = 105;
        var angle = 0;
        var paths = '';
        var legend = '';

        items.forEach(function (e, i) {
            var color = COLORS[i % COLORS.length];
            var frac = e[1] / total;
            var sweep = frac * 360;
            var pct = Math.round(frac * 100);

            // fatia única (100%) -> círculo cheio
            var d;
            if (items.length === 1) {
                d = 'M ' + cx + ' ' + (cy - r) + ' A ' + r + ' ' + r + ' 0 1 1 ' + (cx - 0.01)
                    + ' ' + (cy - r) + ' Z';
            } else {
                d = slicePath(cx, cy, r, angle, angle + sweep);
            }
            paths += '<path d="' + d + '" fill="' + color + '" stroke="#fff" stroke-width="2" '
                + 'data-i="' + i + '" class="reservafrota-slice"><title>' + escapeHtml(e[0])
                + ': ' + e[1] + ' (' + pct + '%)</title></path>';

            legend += '<li data-i="' + i + '"><span class="dot" style="background:' + color + '"></span>'
                + '<span class="lbl">' + escapeHtml(e[0]) + '</span>'
                + '<span class="val">' + e[1] + ' · ' + pct + '%</span></li>';

            angle += sweep;
        });

        pieEl.innerHTML = '<svg viewBox="0 0 220 220" class="reservafrota-pie-svg">' + paths + '</svg>';
        if (legendEl) { legendEl.innerHTML = legend; }

        // realce recíproco entre fatia e legenda
        function highlight(idx, on) {
            pieEl.querySelectorAll('.reservafrota-slice').forEach(function (p) {
                p.style.opacity = (on && p.dataset.i !== String(idx)) ? '0.35' : '1';
            });
            if (legendEl) {
                legendEl.querySelectorAll('li').forEach(function (li) {
                    li.style.opacity = (on && li.dataset.i !== String(idx)) ? '0.45' : '1';
                });
            }
        }
        pieEl.querySelectorAll('.reservafrota-slice').forEach(function (p) {
            p.addEventListener('mouseenter', function () { highlight(p.dataset.i, true); });
            p.addEventListener('mouseleave', function () { highlight(null, false); });
        });
        if (legendEl) {
            legendEl.querySelectorAll('li').forEach(function (li) {
                li.addEventListener('mouseenter', function () { highlight(li.dataset.i, true); });
                li.addEventListener('mouseleave', function () { highlight(null, false); });
            });
        }
    }

    function drawBars(data) {
        var el = document.getElementById('reservafrota-bars');
        if (!el) { return; }
        var items = entries(data);
        if (!items.length) { return; }
        var max = items.reduce(function (m, e) { return Math.max(m, e[1]); }, 0);

        el.innerHTML = items.map(function (e, i) {
            var color = COLORS[i % COLORS.length];
            var w = max ? (e[1] / max * 100) : 0;
            return '<div class="reservafrota-bar-row">'
                + '<span class="reservafrota-bar-label" title="' + escapeHtml(e[0]) + '">' + escapeHtml(e[0]) + '</span>'
                + '<div class="reservafrota-bar-track"><div class="reservafrota-bar-fill" '
                + 'style="width:' + w.toFixed(1) + '%;background:' + color + '"></div></div>'
                + '<span class="reservafrota-bar-val">' + e[1] + '</span>'
                + '</div>';
        }).join('');
    }

    // Donut de status (pendente/aprovado/recusado)
    function drawDonut(statusObj) {
        var el = document.getElementById('reservafrota-donut');
        var legendEl = document.getElementById('reservafrota-donut-legend');
        if (!el) { return; }

        var map = [
            { key: '1', label: 'Pendente', color: '#f59e0b' },
            { key: '2', label: 'Aprovado', color: '#6366f1' },
            { key: '3', label: 'Recusado', color: '#f43f5e' }
        ];
        var items = map.map(function (m) {
            return { label: m.label, color: m.color, value: (statusObj && +statusObj[m.key]) || 0 };
        }).filter(function (x) { return x.value > 0; });

        var total = items.reduce(function (s, x) { return s + x.value; }, 0);
        if (!total) {
            el.innerHTML = '<p class="text-muted" style="padding:1rem">Sem dados.</p>';
            if (legendEl) { legendEl.innerHTML = ''; }
            return;
        }

        var cx = 110, cy = 110, r = 105, ri = 62, angle = 0;
        var paths = '', legend = '';
        items.forEach(function (it) {
            var frac = it.value / total;
            var sweep = frac * 360;
            var pct = Math.round(frac * 100);
            var d;
            if (items.length === 1) {
                d = 'M ' + cx + ' ' + (cy - r) + ' A ' + r + ' ' + r + ' 0 1 1 ' + (cx - 0.01) + ' ' + (cy - r)
                  + ' M ' + cx + ' ' + (cy - ri) + ' A ' + ri + ' ' + ri + ' 0 1 0 ' + (cx - 0.01) + ' ' + (cy - ri) + ' Z';
            } else {
                d = donutSlice(cx, cy, r, ri, angle, angle + sweep);
            }
            paths += '<path d="' + d + '" fill="' + it.color + '" stroke="#fff" stroke-width="2"></path>';
            legend += '<li><span class="dot" style="background:' + it.color + '"></span>'
                + '<span class="lbl">' + escapeHtml(it.label) + '</span>'
                + '<span class="val">' + it.value + ' · ' + pct + '%</span></li>';
            angle += sweep;
        });

        el.innerHTML = '<svg viewBox="0 0 220 220" class="reservafrota-pie-svg">' + paths
            + '<text x="110" y="104" text-anchor="middle" class="reservafrota-donut-num">' + total + '</text>'
            + '<text x="110" y="126" text-anchor="middle" class="reservafrota-donut-cap">pedidos</text></svg>';
        if (legendEl) { legendEl.innerHTML = legend; }
    }

    function donutSlice(cx, cy, r, ri, start, end) {
        var o1 = polar(cx, cy, r, end), o2 = polar(cx, cy, r, start);
        var i1 = polar(cx, cy, ri, start), i2 = polar(cx, cy, ri, end);
        var large = (end - start) <= 180 ? 0 : 1;
        return 'M ' + o2[0].toFixed(2) + ' ' + o2[1].toFixed(2)
            + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + o1[0].toFixed(2) + ' ' + o1[1].toFixed(2)
            + ' L ' + i2[0].toFixed(2) + ' ' + i2[1].toFixed(2)
            + ' A ' + ri + ' ' + ri + ' 0 ' + large + ' 0 ' + i1[0].toFixed(2) + ' ' + i1[1].toFixed(2) + ' Z';
    }

    function init() {
        var root = document.getElementById('reservafrota-analytics');
        if (!root) { return; }
        var sector = {}, car = {}, status = {};
        try { sector = JSON.parse(root.dataset.sector || '{}'); } catch (e) { sector = {}; }
        try { car = JSON.parse(root.dataset.car || '{}'); } catch (e) { car = {}; }
        try { status = JSON.parse(root.dataset.status || '{}'); } catch (e) { status = {}; }
        drawPie(sector);
        drawBars(car);
        drawDonut(status);

        // troca de sub-abas Mês / Ano
        var subtabs = root.querySelectorAll('.reservafrota-subtab');
        subtabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                subtabs.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                var view = btn.dataset.view;
                var m = document.getElementById('reservafrota-view-month');
                var y = document.getElementById('reservafrota-view-year');
                if (m) { m.hidden = (view !== 'month'); }
                if (y) { y.hidden = (view !== 'year'); }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());

/**
 * BAIT - Statistics: colours, number labels and the click-to-filter ("cross-filter") behaviour.
 *
 * Needs Chart.js 4 and chartjs-plugin-datalabels (assets/js/vendor/). Two entry points:
 *   BaitStats.explorer(config)    public statistics pages: every chart / table / card reacts to what the visitor clicks
 *   BaitStats.staticChart(config) admin pages: plain charts, but with the same colours and number labels
 *
 * The filter works on the raw rows (a few dozen numbers), so nothing is sent to the server when clicking:
 *   - clicking a bar / slice / legend entry / table filter button toggles that item in its group
 *   - several items of one group (OR) and several groups at once (AND) can be combined
 *   - a chart keeps showing ALL of its own bars, the chosen ones stay solid and the rest fade
 *   - the reset button, the Esc key, or a click on empty space clears everything
 * Nothing is remembered between visits: every visitor starts from the default view.
 */
(function () {
  'use strict';

  // One soft (muted pastel) palette for every chart on the site and in the admin: each colour is a clearly different hue (no two blues /
  // two greens side by side), so the bars / slices of one chart are easy to tell apart.
  var PALETTE = ['#6199D5', '#ED9257', '#6BBE7E', '#E576A0', '#EBC244', '#9277E2', '#5EBCBA', '#AFCC5F', '#E05E5E'];

  function toHsl(hex) {
    var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || ''); if (!m) return null;
    var r = parseInt(m[1], 16) / 255, g = parseInt(m[2], 16) / 255, b = parseInt(m[3], 16) / 255;
    var mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2, d = mx - mn, h = 0, s = 0;
    if (d) {
      s = d / (1 - Math.abs(2 * l - 1));
      h = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
      h = (h * 60 + 360) % 360;
    }
    return { h: h, s: s, l: l, r: r, g: g, b: b };
  }
  // true when two colours would look alike in one chart: close hue and close lightness (greys: close in RGB)
  function tooClose(a, b) {
    var x = toHsl(a), y = toHsl(b); if (!x || !y) return false;
    if (x.s > 0.25 && y.s > 0.25) { var dh = Math.abs(x.h - y.h); dh = Math.min(dh, 360 - dh); return dh < 20 && Math.abs(x.l - y.l) < 0.25; }
    return Math.sqrt(Math.pow(x.r - y.r, 2) + Math.pow(x.g - y.g, 2) + Math.pow(x.b - y.b, 2)) < 0.3;
  }
  var SEQ = PALETTE.slice(), seqN = 0;
  function colorAt(i) {
    while (SEQ.length <= i) {   // past the palette: golden-angle hues, skipping (for a while) any that looks like one already used
      for (var tries = 0, c; tries < 24; tries++) {
        seqN++; c = hsl((seqN * 137.508 + 28) % 360, 58, 54 + (seqN % 3) * 6);
        if (!SEQ.some(function (u) { return tooClose(u, c); })) break;
      }
      SEQ.push(c);
    }
    return SEQ[i];
  }
  // a strong colour (e.g. one an admin picked for a news category) mixed with white, so it sits with the pastel palette
  function soften(hex) {
    var x = toHsl(hex); if (!x || x.l >= 0.55) return hex;
    var m = function (v) { return Math.round((v + (1 - v) * 0.3) * 255).toString(16).padStart(2, '0'); };
    return '#' + m(x.r) + m(x.g) + m(x.b);
  }
  // fixed colours (e.g. the ones an admin picked for each news category) are kept, except one that looks like a colour
  // already in the same chart: that one gets the first palette colour that looks like none of them
  function distinctColors(list) {
    var out = [];
    list.forEach(function (c) {
      if (c && !out.some(function (u) { return tooClose(u, c); })) { out.push(c); return; }
      for (var k = 0; ; k++) { var p = colorAt(k); if (k > 60 || !out.concat(list).some(function (u) { return u && tooClose(u, p); })) { out.push(p); return; } }
    });
    return out;
  }

  function hsl(h, s, l) {
    s /= 100; l /= 100;
    var k = function (n) { return (n + h / 30) % 12; };
    var a = s * Math.min(l, 1 - l);
    var f = function (n) { var v = l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1))); return Math.round(255 * v).toString(16).padStart(2, '0'); };
    return '#' + f(0) + f(8) + f(4);
  }
  function rgb(hex) { var n = parseInt(hex.slice(1), 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
  function rgba(hex, a) { var c = rgb(hex); return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function textOn(hex) {   // black or white text, whichever reads better on this colour
    var c = rgb(hex).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return (0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]) > 0.4 ? '#14203A' : '#FFFFFF';
  }
  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function numberFormat(lang) { var loc = lang === 'en' ? 'en-US' : 'th-TH'; return function (n) { return Number(n).toLocaleString(loc); }; }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var chartsRegistered = false;
  function ensurePlugin() {
    if (chartsRegistered || !window.Chart) return;
    if (window.ChartDataLabels) window.Chart.register(window.ChartDataLabels);
    chartsRegistered = true;
  }

  var segmenter = null;
  try { if (window.Intl && Intl.Segmenter) segmenter = new Intl.Segmenter('th', { granularity: 'word' }); } catch (e) { segmenter = null; }
  /** Words of a label: real Thai word boundaries when the browser knows them, otherwise just the spaces. */
  function wordsOf(text) {
    text = String(text);
    if (segmenter) return Array.from(segmenter.segment(text), function (x) { return x.segment; });
    return text.split(/(\s+)/).filter(Boolean);
  }
  /**
   * Greedy wrap to `maxW` pixels (at most `maxLines` lines). Lines break only BETWEEN words (Thai included, so a word such as
   * "ดิจิทัล" is never torn apart); only a single word wider than a whole line is cut by characters, and then never before a
   * vowel / tone mark. The caller sets ctx.font first.
   */
  function wrapText(ctx, text, maxW, maxLines) {
    var lines = [], line = '', w = function (t) { return ctx.measureText(t).width; };
    wordsOf(text).forEach(function (tok) {
      if (/^\s+$/.test(tok)) { if (line) line += ' '; return; }
      if (w(line + tok) <= maxW) { line += tok; return; }
      if (line.trim()) { lines.push(line.trim()); line = ''; }
      while (tok.length > 1 && w(tok) > maxW) {
        var k = tok.length;
        while (k > 1 && w(tok.slice(0, k)) > maxW) k--;
        while (k > 1 && (isThaiMark(tok.charAt(k)) || isThaiLead(tok.charAt(k - 1)))) k--;
        lines.push(tok.slice(0, k)); tok = tok.slice(k);
      }
      line = tok;
    });
    if (line.trim()) lines.push(line.trim());
    if (lines.length > maxLines) { lines = lines.slice(0, maxLines); lines[maxLines - 1] += '…'; }
    return lines;
  }
  /* ---------------------------------------------------------------- doughnut: ring on the left, a list (dot, name, number) on the right, the total above.
     On a narrow screen the list drops below the ring. The list is plain HTML, so names can be long and every row can be clicked. */
  function ensureDonutCss() {
    if (document.getElementById('dn-style')) return;
    var st = document.createElement('style'); st.id = 'dn-style';
    st.textContent = [
      '.dn{display:flex;flex-direction:column;align-items:center;gap:14px;width:100%}',
      '.dn-body{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:16px 32px;width:100%}',
      '.dn-ring{position:relative;flex:0 0 auto;width:min(100%,270px);aspect-ratio:1}',
      '.dn-ring canvas{position:absolute;inset:0}',
      '.dn-legend{list-style:none;margin:0;padding:0;flex:1 1 250px;min-width:0;max-width:440px;display:grid;gap:2px}',
      '.dn-legend li{display:flex;align-items:center;gap:11px;padding:7px 10px;border-radius:9px;transition:background .15s,opacity .15s}',
      '.dn-legend li[data-id]{cursor:pointer}',
      '.dn-legend li[data-id]:hover{background:rgba(120,140,190,.14)}',
      '.dn-legend li.is-selected{background:rgba(120,140,190,.2)}',
      '.dn-legend li.is-faded{opacity:.45}',
      '.dn-legend .dot{width:12px;height:12px;border-radius:50%;flex:none}',
      '.dn-legend .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink-soft,#4A5878)}',
      '.dn-legend li.is-selected .nm{color:var(--ink,#14203A);font-weight:700}',
      '.dn-legend .vl{font-weight:700;font-variant-numeric:tabular-nums;color:var(--ink,#14203A);flex:none}',
      '@media (prefers-reduced-motion:reduce){.dn-legend li{transition:none}}'
    ].join('');
    document.head.appendChild(st);
  }
  /** Wraps a doughnut canvas into the ring + list layout and returns handles to update it. */
  function donutShell(canvas) {
    ensureDonutCss();
    var host = canvas.parentElement; if (host) host.style.height = 'auto';
    var dn = document.createElement('div'); dn.className = 'dn';
    dn.innerHTML = '<div class="dn-body"><div class="dn-ring"></div><ul class="dn-legend"></ul></div>';
    host.insertBefore(dn, canvas);
    dn.querySelector('.dn-ring').appendChild(canvas);
    return { root: dn, list: dn.querySelector('.dn-legend'), centre: { num: '', unit: '' } };   // centre = what the ring shows in its hole (see centrePlugin)
  }
  /** items: [{id?, name, value, color, selected?, faded?}] ; total shown above the ring. */
  function donutRender(shell, items, total, o) {
    shell.centre.num = o.fmt(total); shell.centre.unit = o.unit || '';
    shell.list.innerHTML = items.map(function (x) {
      return '<li' + (x.id != null ? ' data-id="' + esc(x.id) + '" tabindex="0" role="button" aria-pressed="' + (x.selected ? 'true' : 'false') + '"' : '') + ' class="' + (x.selected ? 'is-selected ' : '') + (x.faded ? 'is-faded' : '') + '" title="' + esc(x.name) + '">'
        + '<span class="dot" style="background:' + x.color + '"></span><span class="nm">' + esc(x.name) + '</span><span class="vl">' + o.fmt(x.value) + '</span></li>';
    }).join('');
  }
  /** The total in the middle of the ring: a big number with its unit underneath. */
  function centrePlugin(shell) {
    return {
      id: 'ringCentre',
      afterDatasetsDraw: function (chart) {
        var arc = chart.getDatasetMeta(0).data[0]; if (!arc || !shell.centre.num) return;
        var c = arc.getProps(['x', 'y', 'innerRadius'], true), ctx = chart.ctx, fam = cssVar('--font-body', 'Kanit, sans-serif');
        var big = Math.max(14, Math.min(30, c.innerRadius * 0.5)), withUnit = shell.centre.unit && c.innerRadius > 38;
        ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillStyle = cssVar('--ink', '#14203A'); ctx.font = '700 ' + big + 'px ' + fam;
        ctx.fillText(shell.centre.num, c.x, c.y - (withUnit ? 7 : 0));
        if (withUnit) { ctx.fillStyle = cssVar('--ink-soft', '#4A5878'); ctx.font = '400 11px ' + fam; ctx.fillText(shell.centre.unit, c.x, c.y + big * 0.55 + 2); }
        ctx.restore();
      }
    };
  }
  var NARROW_BELOW = 520;
  function doughnutPadding(width) { return width < NARROW_BELOW ? { top: 24, bottom: 8, left: 46, right: 46 } : { top: 26, bottom: 26, left: 170, right: 170 }; }
  function isThaiMark(ch) { var c = ch.charCodeAt(0); return c === 0x0E31 || (c >= 0x0E34 && c <= 0x0E3A) || (c >= 0x0E47 && c <= 0x0E4E); }
  function isThaiLead(ch) { var c = ch.charCodeAt(0); return c >= 0x0E40 && c <= 0x0E44; }
  /**
   * Doughnut extras: the total in the middle, and for every slice a leader line ending in its number (and its name when
   * there is room). Labels on each side are pushed apart so they never overlap. getState(chart) -> {centre?, unit?, faded?[]}
   */
  function leaderPlugin(getState) {
    return {
      id: 'leaderLabels',
      afterDatasetsDraw: function (chart) {
        var meta = chart.getDatasetMeta(0), ds = chart.data.datasets[0], ctx = chart.ctx, st = (getState && getState(chart)) || {};
        var ink = cssVar('--ink', '#14203A'), soft = cssVar('--ink-soft', '#4A5878'), fam = cssVar('--font-body', 'Kanit, sans-serif');
        var nf = numberFormat(st.lang), narrow = chart.width < NARROW_BELOW, arcs = meta.data;
        var total = ds.data.reduce(function (a, b) { return a + (b || 0); }, 0);
        if (!arcs.length || !total) return;
        ctx.save();
        // total in the middle
        var c0 = arcs[0].getProps(['x', 'y', 'innerRadius'], true), big = Math.max(14, Math.min(30, c0.innerRadius * 0.5));
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = ink; ctx.font = '700 ' + big + 'px ' + fam;
        var centre = st.centre != null ? st.centre : total;
        ctx.fillText(nf(centre), c0.x, c0.y - (st.unit && c0.innerRadius > 38 ? 7 : 0));
        if (st.unit && c0.innerRadius > 38) { ctx.fillStyle = soft; ctx.font = '400 11px ' + fam; ctx.fillText(st.unit, c0.x, c0.y + big * 0.55 + 2); }
        // leader lines + labels
        var items = [], maxR = 0;
        arcs.forEach(function (arc, i) {
          var v = ds.data[i]; if (!(v > 0)) return;
          var p = arc.getProps(['x', 'y', 'startAngle', 'endAngle', 'outerRadius'], true), off = (arc.options && arc.options.offset) || 0;
          var a = (p.startAngle + p.endAngle) / 2, R = p.outerRadius + off; if (R > maxR) maxR = R;
          items.push({ i: i, cx: p.x, cy: p.y, cos: Math.cos(a), sin: Math.sin(a), R: R, v: v });
        });
        // every label sits in a column just outside the ring, on its own side, so it can never cover the ring or a neighbour
        items.forEach(function (it) { it.right = it.cos >= 0; });
        // slices piled up right at 12 / 6 o'clock would all land in one column: hand every other one to the opposite side
        [-1, 1].forEach(function (vs) {
          var near = items.filter(function (it) { return it.sin * vs > 0.95 && it.cos < 0; }).sort(function (a, b) { return b.cos - a.cos; });
          near.forEach(function (it, k) { if (k % 2 === 0) it.right = true; });
        });
        items.forEach(function (it) {
          it.x1 = it.cx + it.cos * it.R; it.y1 = it.cy + it.sin * it.R;
          it.x2 = it.cx + it.cos * (it.R + 10); it.y2 = it.cy + it.sin * (it.R + 10);
          it.col = it.cx + (it.right ? 1 : -1) * (maxR + (narrow ? 6 : 34));
          it.tx = it.col + (it.right ? 5 : -5);
          it.lines = [];
          if (!narrow) { ctx.font = '400 11px ' + fam; it.lines = wrapText(ctx, chart.data.labels[it.i], Math.max(36, (it.right ? chart.width - it.tx : it.tx) - 6), 2); }
          it.h = 15 + it.lines.length * 13;
          it.y = it.y2 - 8 + it.h / 2;                               // the line meets the first text line (the number)
        });
        [true, false].forEach(function (side) {
          var g = items.filter(function (it) { return it.right === side; }).sort(function (a, b) { return a.y2 - b.y2; });
          for (var k = 1; k < g.length; k++) { var minY = g[k - 1].y + g[k - 1].h / 2 + g[k].h / 2 + 3; if (g[k].y < minY) g[k].y = minY; }
          var bottom = Math.min(chart.height - 3, chart.chartArea.bottom + 28);   // never run into the legend below
          if (g.length && g[g.length - 1].y + g[g.length - 1].h / 2 > bottom) {
            g[g.length - 1].y = bottom - g[g.length - 1].h / 2;
            for (var m = g.length - 2; m >= 0; m--) { var maxY = g[m + 1].y - g[m + 1].h / 2 - g[m].h / 2 - 3; if (g[m].y > maxY) g[m].y = maxY; }
          }
          if (g.length && g[0].y - g[0].h / 2 < 2) g[0].y = 2 + g[0].h / 2;
        });
        items.forEach(function (it) {
          var fade = st.faded && st.faded[it.i], dir = it.right ? 1 : -1;
          ctx.strokeStyle = Array.isArray(ds.backgroundColor) ? ds.backgroundColor[it.i] : ds.backgroundColor; ctx.lineWidth = 1.3;
          var ly = it.y - it.h / 2 + 8;
          ctx.beginPath(); ctx.moveTo(it.x1, it.y1); ctx.lineTo(it.x2, it.y2);
          if (Math.abs(ly - it.y2) > 0.5) { var xe = it.x2 + dir * Math.abs(ly - it.y2); if ((xe - it.col) * dir > 0) xe = it.col; ctx.lineTo(xe, ly); }   // 45-degree slant, then always a straight horizontal run into the label
          ctx.lineTo(it.col, ly); ctx.stroke();
          ctx.fillStyle = ctx.strokeStyle; ctx.beginPath(); ctx.arc(it.x1, it.y1, 2.2, 0, Math.PI * 2); ctx.fill();
          ctx.textAlign = it.right ? 'left' : 'right'; ctx.textBaseline = 'middle';
          var top = it.y - it.h / 2;
          ctx.fillStyle = fade ? soft : ink; ctx.font = '700 12px ' + fam; ctx.fillText(nf(it.v), it.tx, top + 8);
          ctx.fillStyle = soft; ctx.font = '400 11px ' + fam;
          it.lines.forEach(function (ln, k) { ctx.fillText(ln, it.tx, top + 8 + 14 + k * 13); });
        });
        ctx.restore();
      }
    };
  }

  var BAR_NARROW = 480, BAR_SLOT = 76;
  /**
   * Horizontal bars on a narrow chart: the name goes on its own line(s) above the bar, across the full width, wrapped at spaces,
   * instead of being squeezed (and cut in the middle of a word) in a column on the left. getState(chart) -> {faded?[]}
   */
  function barLabelPlugin(getState) {
    return {
      id: 'barLabelsAbove',
      beforeUpdate: function (chart) {
        var ds = chart.data.datasets[0]; if (ds) ds.maxBarThickness = chart.width < BAR_NARROW ? 16 : 34;
      },
      afterDatasetsDraw: function (chart) {
        if (chart.width >= BAR_NARROW) return;
        var meta = chart.getDatasetMeta(0), area = chart.chartArea, n = meta.data.length; if (!n) return;
        var ctx = chart.ctx, st = (getState && getState(chart)) || {}, slot = area.height / n, fam = cssVar('--font-body', 'Kanit, sans-serif');
        var ink = cssVar('--ink', '#14203A'), soft = cssVar('--ink-soft', '#4A5878');
        ctx.save(); ctx.font = '500 12px ' + fam; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        meta.data.forEach(function (bar, i) {
          var lines = wrapText(ctx, chart.data.labels[i], chart.width - area.left - 10, 2), last = bar.y - 8 - 10;   // the last line sits right above the bar
          ctx.fillStyle = st.faded && st.faded[i] ? soft : ink;
          lines.forEach(function (ln, k) { ctx.fillText(ln, area.left + 2, last - (lines.length - 1 - k) * 15); });
        });
        ctx.restore();
      }
    };
  }

  /** Chart.js options shared by every bar / doughnut. `kind` = 'bar' | 'barH' | 'doughnut'. */
  function chartOptions(kind, fmt, leader) {   // leader = doughnut with labels on leader lines (admin pages); otherwise ring + HTML list (public pages)
    var ink = cssVar('--ink', '#14203A'), soft = cssVar('--ink-soft', '#4A5878'), grid = cssVar('--border', '#E3E6EE');
    var font = { family: cssVar('--font-body', 'Kanit, sans-serif'), size: 11 };
    var labelBase = { color: ink, font: { family: font.family, size: 11, weight: '600' }, formatter: function (v) { return fmt(v); }, clip: false, display: true };
    if (kind === 'doughnut' && leader) {
      // names + numbers sit outside the ring on leader lines (see leaderPlugin); on a narrow chart only the numbers do, and the legend names the colours
      return {
        responsive: true, maintainAspectRatio: false, animation: false, cutout: '58%',
        layout: { padding: doughnutPadding(NARROW_BELOW + 1) },
        onResize: function (chart, size) {
          var narrow = size.width < NARROW_BELOW;
          if (chart.options.plugins.legend.display !== narrow) { chart.options.plugins.legend.display = narrow; chart.options.layout.padding = doughnutPadding(size.width); chart.update('none'); }
        },
        plugins: { legend: { display: false, position: 'bottom', labels: { color: ink, boxWidth: 10, padding: 8, font: font },
            title: { display: true, text: ' ', font: { size: 1 }, padding: { top: 24, bottom: 0 } } },   // the empty title is a gap between the ring's labels and the legend
          tooltip: {},
          datalabels: { display: false } }
      };
    }
    if (kind === 'doughnut') {   // the names and numbers live in the HTML list next to the ring (donutShell), not on the canvas
      return {
        responsive: true, maintainAspectRatio: false, animation: false, cutout: '62%', layout: { padding: 6 },
        plugins: { legend: { display: false }, tooltip: {}, datalabels: { display: false } }
      };
    }
    var horizontal = kind === 'barH';
    var valueAxis = { beginAtZero: true, grace: '12%', ticks: { precision: 0, color: soft, font: font }, grid: { color: grid } };
    var catAxis = { grid: { display: false }, ticks: { color: soft, font: font, autoSkip: false } };
    if (horizontal) catAxis.ticks.callback = function (v) {   // long names wrap between words (a phone puts them above the bars instead, see barLabelPlugin)
      var ch = this.chart; if (ch.width < BAR_NARROW) return '';
      var ctx = ch.ctx; ctx.save(); ctx.font = font.size + 'px ' + font.family;
      var lines = wrapText(ctx, this.getLabelForValue(v), Math.min(300, ch.width * 0.4), 4); ctx.restore();
      return lines.length > 1 ? lines : lines[0];
    };
    return {
      indexAxis: horizontal ? 'y' : 'x', responsive: true, maintainAspectRatio: false, animation: false,
      layout: { padding: horizontal ? { right: 34, left: 4, top: 4, bottom: 4 } : { top: 22, left: 6, right: 6 } },
      plugins: { legend: { display: false }, tooltip: {},
        datalabels: Object.assign({}, labelBase, { anchor: 'end', align: 'end', offset: 2 }) },
      scales: horizontal ? { x: valueAxis, y: catAxis } : { y: valueAxis, x: catAxis }
    };
  }

  /**
   * Upright bars: names under the bars, wrapped BETWEEN words; if even one word is wider than its column they are tilted
   * instead (never cut in the middle of a word). Patches opts.scales.x and returns the plugin that decides about the tilt.
   */
  function uprightLabels(opts) {
    var xt = opts.scales.x.ticks; xt.autoSkip = false; xt.maxRotation = 0; xt.minRotation = 0;
    var needTilt = false;
    var slotOf = function (ch) { return ((ch.chartArea ? ch.chartArea.width : ch.width - 56) / ch.data.labels.length) - 14; };
    xt.callback = function (v) {
      var ch = this.chart, lab = this.getLabelForValue(v);
      if (needTilt) return lab;
      var ctx = ch.ctx; ctx.save(); ctx.font = '11px ' + cssVar('--font-body', 'Kanit, sans-serif');
      var lines = wrapText(ctx, lab, Math.max(48, slotOf(ch)), 3); ctx.restore();
      return lines.length > 1 ? lines : lines[0];
    };
    return { id: 'xTilt', beforeLayout: function (ch) {
      var ctx = ch.ctx, slot = Math.max(48, slotOf(ch)), wide = false;
      ctx.save(); ctx.font = '11px ' + cssVar('--font-body', 'Kanit, sans-serif');
      ch.data.labels.forEach(function (l) { wordsOf(l).forEach(function (w) { if (!/^\s+$/.test(w) && ctx.measureText(w).width > slot) wide = true; }); });
      ctx.restore();
      needTilt = wide; ch.options.scales.x.ticks.minRotation = ch.options.scales.x.ticks.maxRotation = wide ? 40 : 0;
    } };
  }

  /* ------------------------------------------------------------------ admin: plain charts */
  function staticChart(cfg) {
    ensurePlugin();
    var el = document.getElementById(cfg.id);
    if (!el || !cfg.data || !cfg.data.length) return null;
    // an entry with 0 is not drawn (house rule for every chart): drop it together with its label and colour
    var keep = []; cfg.data.forEach(function (v, i) { if (Number(v) > 0) keep.push(i); });
    if (keep.length !== cfg.data.length) {
      cfg = Object.assign({}, cfg, { labels: keep.map(function (i) { return cfg.labels[i]; }), data: keep.map(function (i) { return cfg.data[i]; }), colors: cfg.colors ? keep.map(function (i) { return cfg.colors[i]; }) : cfg.colors });
      if (!cfg.data.length) { if (el.parentNode) el.parentNode.classList.add('is-empty'); return null; }
    }
    var fmt = numberFormat('th');
    var kind = cfg.type === 'doughnut' ? 'doughnut' : (cfg.horizontal ? 'barH' : 'bar');
    var colors = distinctColors(cfg.labels.map(function (_, i) { return (cfg.colors && cfg.colors[i] && soften(cfg.colors[i])) || colorAt(i); }));   // cfg.colors: fixed colours (e.g. the ones the admin picked for each news category)
    var opts = chartOptions(kind, fmt);
    var xPlugin = null;
    if (kind === 'bar') {
      if (el.parentElement && el.parentElement.clientHeight < 280) el.parentElement.style.height = '280px';
      xPlugin = uprightLabels(opts);
    }
    var shell = kind === 'doughnut' ? donutShell(el) : null;
    if (kind === 'barH' && el.parentElement && el.parentElement.clientWidth < BAR_NARROW) el.parentElement.style.height = (cfg.data.length * BAR_SLOT + 40) + 'px';   // room for the names above the bars
    var total = cfg.data.reduce(function (a, b) { return a + b; }, 0);
    opts.plugins.tooltip.callbacks = { label: function (c) { var v = kind === 'doughnut' ? c.parsed : c.parsed[kind === 'barH' ? 'x' : 'y']; return ' ' + fmt(v) + ' ' + (cfg.unit || '') + (total ? ' (' + (v / total * 100).toFixed(1) + '%)' : ''); } };
    var ds = { data: cfg.data, backgroundColor: colors };
    if (kind === 'doughnut') { ds.borderWidth = 3; ds.borderColor = cssVar('--surface', '#fff'); ds.hoverOffset = 6; }
    else { ds.borderRadius = 5; ds.maxBarThickness = 34; }
    if (shell) donutRender(shell, cfg.labels.map(function (l, i) { return { name: String(l), value: cfg.data[i], color: colors[i] }; }), total, { fmt: fmt, unit: cfg.unit });
    return new window.Chart(el, { type: kind === 'doughnut' ? 'doughnut' : 'bar', data: { labels: cfg.labels.map(String), datasets: [ds] }, options: opts,
      plugins: shell ? [centrePlugin(shell)] : kind === 'barH' ? [barLabelPlugin()] : (xPlugin ? [xPlugin] : []) });
  }

  /* ------------------------------------------------------------------ public: the explorer */
  function explorer(cfg) {
    ensurePlugin();
    var T = cfg.i18n || {};
    var fmt = numberFormat(cfg.lang);
    var dims = {}, dimKeys = Object.keys(cfg.dims);
    dimKeys.forEach(function (k) {
      var d = cfg.dims[k], map = {};
      d.items.forEach(function (it, i) { it.color = colorAt(i); map[it.id] = it; });
      dims[k] = { title: d.title, items: d.items, byId: map };
    });
    var rows = cfg.rows;
    var sel = {}; dimKeys.forEach(function (k) { sel[k] = new Set(); });
    var charts = [];

    function anySel() { return dimKeys.some(function (k) { return sel[k].size > 0; }); }
    function pass(row, ignore) {
      for (var i = 0; i < dimKeys.length; i++) {
        var k = dimKeys[i];
        if (ignore && ignore.indexOf(k) !== -1) continue;
        if (sel[k].size && (row[k] == null || !sel[k].has(row[k]))) return false;
      }
      return true;
    }
    /** id -> total for one group, using the filters of all OTHER groups (the group's own choice does not shrink its own list). */
    function aggregate(dim, ignoreToo) {
      var out = new Map(), ignore = [dim].concat(ignoreToo || []);
      rows.forEach(function (r) { if (r[dim] != null && pass(r, ignore)) out.set(r[dim], (out.get(r[dim]) || 0) + r.v); });
      return out;
    }
    function sumAll() { var s = 0; rows.forEach(function (r) { if (pass(r)) s += r.v; }); return s; }
    function itemsFor(dim, agg) {
      return dims[dim].items.filter(function (it) { return (agg.get(it.id) || 0) > 0 || sel[dim].has(it.id); })
        .map(function (it) { return { id: it.id, name: it.name, value: agg.get(it.id) || 0, color: it.color, item: it }; })
        .sort(function (a, b) { return b.value - a.value; });
    }

    function toggle(dim, id) { if (sel[dim].has(id)) sel[dim].delete(id); else sel[dim].add(id); render(); }
    function reset() { if (!anySel()) return; dimKeys.forEach(function (k) { sel[k].clear(); }); render(); }

    /* ---- summary cards ---- */
    var cardEls = (cfg.cards || []).map(function (c) { return { cfg: c, el: document.querySelector(c.el), shown: null }; });
    // counts the card from one number to another; a timer (not requestAnimationFrame) so it always ends on the real number
    function run(card, from, to, dur) {
      var el = card.el, t0 = Date.now();
      if (card.timer) { clearInterval(card.timer); card.timer = null; }
      el.textContent = fmt(from);
      card.timer = setInterval(function () {
        var p = Math.min((Date.now() - t0) / dur, 1);
        el.textContent = fmt(p >= 1 ? to : Math.round(from + (to - from) * p));
        if (p >= 1) { clearInterval(card.timer); card.timer = null; }
      }, 30);
    }
    function tween(card, to) {
      var el = card.el; if (!el) return;
      if (card.io) { card.io.disconnect(); card.io = null; }   // a filter came before the first count-up: just show the new number
      if (card.shown == null) {
        // first render: count up from 0 once the card scrolls into view, like the numbers on the About page (counters.js)
        // The real number stays in place until then, so a page that is never scrolled to / printed / captured never reads "0".
        card.shown = to;
        el.textContent = fmt(to);
        if (reduceMotion || to === 0 || !('IntersectionObserver' in window)) return;
        card.io = new IntersectionObserver(function (entries) {
          if (!entries.some(function (e) { return e.isIntersecting; })) return;
          card.io.disconnect(); card.io = null;
          run(card, 0, card.shown, 1400);
        }, { threshold: 0.5 });
        card.io.observe(el);
        return;
      }
      var from = card.shown; card.shown = to;
      if (reduceMotion || from === to) { if (card.timer) { clearInterval(card.timer); card.timer = null; } el.textContent = fmt(to); return; }
      run(card, from, to, 300);   // a short count to the new value after a filter click
    }
    function renderCards() {
      var filtered = anySel();
      cardEls.forEach(function (card) {
        var c = card.cfg, value;
        if (c.kind === 'sum') value = sumAll();
        else if (!filtered) value = c.initial;
        else if (c.kind === 'withData') { var s = new Set(); rows.forEach(function (r) { if (r[c.dim] != null && r.v > 0 && pass(r)) s.add(r[c.dim]); }); value = s.size; }
        else if (c.kind === 'offered') {   // programs that fit the choices (level / major / program / offered at a chosen campus)
          value = dims[c.dim].items.filter(function (it) {
            if (it.active === false) return false;   // closed programs are not counted
            var a = it.attrs || {};
            return dimKeys.every(function (k) {
              if (!sel[k].size) return true;
              if (k === c.dim) return sel[k].has(it.id);
              var v = a[k]; if (v == null) return false;
              return Array.isArray(v) ? v.some(function (x) { return sel[k].has(x); }) : sel[k].has(v);
            });
          }).length;
        }
        tween(card, value);
      });
    }

    /* ---- charts ---- */
    function makeChart(c) {
      var canvas = document.querySelector(c.canvas); if (!canvas) return null;
      var kind = c.kind, box0 = canvas.parentElement;
      // upright bars turn into horizontal ones (names above the bars) when the card is too narrow for their names, e.g. a portrait tablet
      if (kind === 'bar' && box0 && box0.clientWidth && box0.clientWidth < BAR_NARROW) kind = 'barH';
      if (kind === 'bar' && box0 && box0.clientHeight < 280) box0.style.height = '280px';
      var inst = { cfg: c, canvas: canvas, ids: [], chart: null };
      var opts = chartOptions(kind, fmt);
      var xPlug = kind === 'bar' ? uprightLabels(opts) : null;
      inst.shell = kind === 'doughnut' ? donutShell(canvas) : null;
      opts.onHover = function (evt, els) { if (evt.native && evt.native.target) evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; };
      opts.onClick = function (evt, els) {
        if (els.length) toggle(c.dim, inst.ids[els[0].index]);
        else reset();                                              // empty part of the chart = back to the default view
      };
      opts.plugins.tooltip.callbacks = { label: function (ctx) {
        var v = kind === 'doughnut' ? ctx.parsed : ctx.parsed[kind === 'barH' ? 'x' : 'y'];
        var tot = (ctx.dataset.data || []).reduce(function (a, b) { return a + b; }, 0);
        return ' ' + ctx.label + ': ' + fmt(v) + ' ' + (cfg.unit || '') + (tot ? ' (' + (v / tot * 100).toFixed(1) + '%)' : '');
      } };
      if (inst.shell) {   // a click / Enter on a row of the list filters just like a click on the slice
        var pick = function (e) { var li = e.target.closest('li[data-id]'); if (li) toggle(c.dim, Number(li.dataset.id)); };
        inst.shell.list.addEventListener('click', pick);
        inst.shell.list.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(e); } });
      }
      inst.colors = [];
      inst.chart = new window.Chart(canvas, { type: kind === 'doughnut' ? 'doughnut' : 'bar', data: { labels: [], datasets: [{ data: [], backgroundColor: [] }] }, options: opts,
        plugins: inst.shell ? [centrePlugin(inst.shell)] : kind === 'barH' ? [barLabelPlugin(function () { return { faded: inst.faded }; })] : (xPlug ? [xPlug] : []) });
      inst.box = canvas.parentElement; inst.boxH = inst.box ? inst.box.style.height : '';
      inst.kind = kind;
      return inst;
    }
    function renderCharts() {
      var ink = cssVar('--ink', '#14203A'), soft = cssVar('--ink-soft', '#4A5878'), grid = cssVar('--border', '#E3E6EE'), surface = cssVar('--surface', '#fff');
      charts.forEach(function (inst) {
        var c = inst.cfg, agg = aggregate(c.dim), list = itemsFor(c.dim, agg), chosen = sel[c.dim];
        var any = chosen.size > 0;
        inst.ids = list.map(function (x) { return x.id; });
        inst.colors = list.map(function (x) { return x.color; });
        var ch = inst.chart, ds = ch.data.datasets[0];
        ch.data.labels = list.map(function (x) { return x.name; });
        ds.data = list.map(function (x) { return x.value; });
        ds.backgroundColor = list.map(function (x) { return any && !chosen.has(x.id) ? rgba(x.color, 0.35) : x.color; });
        // a chosen bar / slice is shown "zoomed in" a little (no outline): the bar grows on every side, the slice is pulled out of the ring
        if (inst.kind === 'doughnut') {
          ds.borderColor = surface; ds.borderWidth = 3; ds.hoverOffset = 6;
          ds.offset = list.map(function (x) { return any && chosen.has(x.id) ? 14 : 0; });
          inst.faded = list.map(function (x) { return any && !chosen.has(x.id); });
          var shown = any ? list.reduce(function (a, x) { return a + (chosen.has(x.id) ? x.value : 0); }, 0) : list.reduce(function (a, x) { return a + x.value; }, 0);
          donutRender(inst.shell, list.map(function (x, i) { return { id: x.id, name: x.name, value: x.value, color: x.color, selected: any && chosen.has(x.id), faded: inst.faded[i] }; }), shown, { fmt: fmt, unit: cfg.unit, totalLabel: T.total || (cfg.lang === 'en' ? 'Total' : 'รวม') });
        }
        else {
          ds.borderRadius = 5; ds.borderWidth = 0;
          inst.faded = list.map(function (x) { return any && !chosen.has(x.id); });
          if (inst.kind === 'barH' && inst.box) inst.box.style.height = inst.box.clientWidth && inst.box.clientWidth < BAR_NARROW ? (list.length * BAR_SLOT + 40) + 'px' : inst.boxH;
          ds.inflateAmount = list.map(function (x) { return any && chosen.has(x.id) ? 5 : 0; });
        }
        // colours that may have changed with the light / dark theme
        var o = ch.options, dl = o.plugins.datalabels;
        if (inst.kind === 'doughnut') { /* colours of the list come from CSS variables, nothing to do */ }
        else {
          dl.color = function (ctx) { return any && !chosen.has(inst.ids[ctx.dataIndex]) ? soft : ink; };
          dl.offset = function (ctx) { return any && chosen.has(inst.ids[ctx.dataIndex]) ? 8 : 2; };
          var v = o.scales[inst.kind === 'barH' ? 'x' : 'y'], k = o.scales[inst.kind === 'barH' ? 'y' : 'x'];
          v.ticks.color = soft; v.grid.color = grid; k.ticks.color = soft;
        }
        inst.canvas.parentNode.classList.toggle('is-empty', list.length === 0);
        ch.update('none');
      });
    }

    /* ---- tables ---- */
    var tables = (cfg.tables || []).map(function (t) { return { cfg: t, el: document.querySelector(t.el), page: 1 }; }).filter(function (t) { return t.el; });
    function renderTable(t) {
      var c = t.cfg, agg = aggregate(c.dim), list = itemsFor(c.dim, agg), chosen = sel[c.dim];
      var total = list.reduce(function (a, x) { return a + x.value; }, 0);
      var per = c.pageSize || list.length || 1, pages = Math.max(1, Math.ceil(list.length / per));
      if (t.page > pages) t.page = pages;
      var from = (t.page - 1) * per, slice = list.slice(from, from + per);
      var tbody = t.el.querySelector('tbody'), html = '';
      if (!list.length) html = '<tr><td colspan="3" class="stat-none">' + esc(T.noData || '') + '</td></tr>';
      slice.forEach(function (x) {
        var pct = total > 0 ? Math.round(x.value / total * 1000) / 10 : 0, isSel = chosen.has(x.id);
        html += '<tr class="stat-row' + (isSel ? ' is-selected' : '') + '" data-id="' + x.id + '">'
          + '<td><span class="legend-dot" style="background:' + x.color + '"></span>' + esc(x.name) + '</td>'
          + '<td class="num-cell">' + fmt(x.value) + '</td>'
          + '<td style="min-width:200px"><div class="cluster gap-2"><div class="progress-track"><div class="progress-fill" style="width:' + pct + '%;background:' + x.color + '"></div></div><small class="text-faint" style="min-width:40px">' + pct + '%</small></div></td></tr>';
      });
      tbody.innerHTML = html;
      var foot = t.el.querySelector('tfoot td.num-cell'); if (foot) foot.textContent = fmt(total);
      var pager = c.pager ? document.querySelector(c.pager) : null;
      if (pager) {
        if (pages <= 1) { pager.innerHTML = ''; }
        else {
          var h = ''; for (var p = 1; p <= pages; p++) h += '<button type="button" class="btn btn-sm ' + (p === t.page ? 'btn-primary' : 'btn-ghost') + '" data-page-btn="' + p + '">' + p + '</button>';
          pager.innerHTML = h;
        }
      }
      t.last = list;
    }
    function wireTable(t) {
      var c = t.cfg;
      var pager = c.pager ? document.querySelector(c.pager) : null;
      if (pager) pager.addEventListener('click', function (e) { var b = e.target.closest('[data-page-btn]'); if (b) { t.page = Number(b.dataset.pageBtn); renderTable(t); } });
    }

    /* ---- filter bar (chips + reset) ---- */
    var bar = document.querySelector(cfg.filterBar || '[data-filterbar]');
    var hint = document.querySelector(cfg.hint || '[data-filterhint]');
    /** Titles such as "(all campuses)" follow the campus filter: they name the chosen campuses instead. */
    var scopeEls = Array.prototype.slice.call(document.querySelectorAll('[data-scope]'));
    scopeEls.forEach(function (el) { el.dataset.scopeDefault = el.textContent; });
    function renderScope() {
      var names = [];
      if (dims.campus) sel.campus.forEach(function (id) { var it = dims.campus.byId[id]; if (it) names.push(it.name); });
      scopeEls.forEach(function (el) { el.textContent = names.length ? '(' + names.join(', ') + ')' : el.dataset.scopeDefault; });
    }
    function renderBar() {
      if (!bar) return;
      var on = anySel(), chips = bar.querySelector('[data-chips]'), h = '';
      dimKeys.forEach(function (k) {
        sel[k].forEach(function (id) {
          var it = dims[k].byId[id]; if (!it) return;
          h += '<button type="button" class="stat-chip" data-chip-dim="' + k + '" data-chip-id="' + id + '" style="--chip:' + it.color + '"><i></i>' + esc(dims[k].title) + ': ' + esc(it.name) + ' <span aria-hidden="true">×</span><span class="visually-hidden">' + esc(T.remove || '') + '</span></button>';
        });
      });
      chips.innerHTML = h;
      bar.hidden = !on;
      if (hint) hint.hidden = on;
    }
    if (bar) {
      bar.addEventListener('click', function (e) {
        if (e.target.closest('[data-reset]')) { reset(); return; }
        var chip = e.target.closest('[data-chip-dim]'); if (chip) toggle(chip.dataset.chipDim, Number(chip.dataset.chipId));
      });
    }

    function render() { renderCards(); renderCharts(); tables.forEach(renderTable); renderBar(); renderScope(); }

    /* ---- go ---- */
    (cfg.charts || []).forEach(function (c) { var inst = makeChart(c); if (inst) charts.push(inst); });
    tables.forEach(wireTable);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && anySel()) reset(); });
    var root = document.querySelector(cfg.root || '.stat-explorer');
    if (root) root.addEventListener('click', function (e) {
      // a click on blank space (not on a chart, table, button, link or text control) clears the filters
      if (e.target === root || (e.target.classList && (e.target.classList.contains('chart-card') || e.target.classList.contains('grid') || e.target.classList.contains('section-header') || e.target.classList.contains('chart-box')))) reset();
    });
    var resizeTimer = 0;
    window.addEventListener('resize', function () {   // rotating a tablet can cross the "too narrow for upright bars" width in either direction
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        var changed = false;
        charts.forEach(function (inst, i) {
          if (inst.cfg.kind !== 'bar' || !inst.box || !inst.box.clientWidth) return;
          var want = inst.box.clientWidth < BAR_NARROW ? 'barH' : 'bar';
          if (want === inst.kind) return;
          inst.chart.destroy(); inst.box.style.height = inst.boxH;
          var again = makeChart(inst.cfg); if (again) { charts[i] = again; changed = true; }
        });
        if (changed) render();
      }, 200);
    });
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      var again = function () { render(); };
      if (mq.addEventListener) mq.addEventListener('change', again); else if (mq.addListener) mq.addListener(again);
    }
    render();
    return { reset: reset, select: function (dim, id) { sel[dim].add(id); render(); }, selection: function () { var o = {}; dimKeys.forEach(function (k) { o[k] = Array.from(sel[k]); }); return o; } };
  }

  window.BaitStats = { explorer: explorer, staticChart: staticChart, colorAt: colorAt, distinctColors: distinctColors };
})();

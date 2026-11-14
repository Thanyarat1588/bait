/**
 * BAIT Admin - Dashboard charts you can play with (admin/dashboard.php). Same data, colours and chart style as before
 * (BaitStats.staticChart from assets/js/stats-explorer.js); on top of it, per chart:
 *  - view: ring or bars, and order: most first / fewest first / ก-ฮ (remembered in this browser)
 *  - the list under the chart: click a row to hide / show that item (the total and percentages follow); ↗ opens the
 *    admin list filtered to it
 *  - clicking a slice / bar opens that filtered list too
 * Needs Chart.js, chartjs-plugin-datalabels and stats-explorer.js loaded first. config: BaitDashboard.init([{
 *   id, title, unit, items: [{id, name, value, color?, url?}] }])
 */
(function () {
  'use strict';
  var KEY = 'bait-dash-';
  var fmt = function (n) { return Number(n).toLocaleString('th-TH'); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var load = function (k, d) { try { var v = JSON.parse(localStorage.getItem(KEY + k)); return v || d; } catch (e) { return d; } };
  var save = function (k, v) { try { localStorage.setItem(KEY + k, JSON.stringify(v)); } catch (e) { /* private window: just not remembered */ } };

  function panel(cfg) {
    var host = document.querySelector('[data-dash="' + cfg.id + '"]');
    if (!host || !cfg.items.length) return;
    // fixed colour per item, worked out once, so hiding or re-ordering never repaints the others
    var base = BaitStats.distinctColors(cfg.items.map(function (it, i) { return it.color || BaitStats.colorAt(i); }));
    cfg.items.forEach(function (it, i) { it.colorFixed = base[i]; });
    var st = load(cfg.id, { view: 'doughnut', sort: 'desc' });
    var hidden = new Set();
    var chart = null;

    var head = host.closest('.ne-panel').querySelector('.ne-panel-head');
    var tools = document.createElement('div'); tools.className = 'dash-tools';
    tools.innerHTML =
      '<div class="dash-seg" role="group" aria-label="รูปแบบกราฟ">'
      + '<button type="button" data-view="doughnut" title="กราฟวงกลม" aria-label="กราฟวงกลม"><i class="bi bi-pie-chart"></i></button>'
      + '<button type="button" data-view="bar" title="กราฟแท่ง" aria-label="กราฟแท่ง"><i class="bi bi-bar-chart"></i></button></div>'
      + '<select class="dash-sort" aria-label="เรียงลำดับ"><option value="desc">มาก → น้อย</option><option value="asc">น้อย → มาก</option><option value="az">ก → ฮ</option></select>';
    head.appendChild(tools);
    var list = document.createElement('ul'); list.className = 'dash-list';
    host.parentNode.appendChild(list);
    var hint = document.createElement('p'); hint.className = 'dash-hint';
    hint.textContent = 'กดรายการเพื่อซ่อน/แสดง · กด ↗ หรือคลิกที่กราฟเพื่อเปิดรายการที่กรองไว้';
    host.parentNode.appendChild(hint);

    function ordered() {
      var a = cfg.items.slice();
      if (st.sort === 'az') a.sort(function (x, y) { return x.name.localeCompare(y.name, 'th'); });
      else a.sort(function (x, y) { return st.sort === 'asc' ? x.value - y.value : y.value - x.value; });
      return a;
    }
    function draw() {
      tools.querySelectorAll('[data-view]').forEach(function (b) { var on = b.dataset.view === st.view; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      tools.querySelector('.dash-sort').value = st.sort;
      var all = ordered(), shown = all.filter(function (it) { return !hidden.has(it.id); });
      var total = shown.reduce(function (s, it) { return s + it.value; }, 0);
      // list: every item (hidden ones greyed), its share of what is shown
      list.innerHTML = all.map(function (it) {
        var off = hidden.has(it.id), pct = !off && total ? (it.value / total * 100).toFixed(1) + '%' : '–';
        return '<li class="' + (off ? 'is-off' : '') + '"><button type="button" class="dash-row" data-id="' + esc(it.id) + '" aria-pressed="' + (off ? 'false' : 'true') + '" title="' + (off ? 'แสดง' : 'ซ่อน') + ' ' + esc(it.name) + '">'
          + '<span class="dot" style="background:' + it.colorFixed + '"></span><span class="nm">' + esc(it.name) + '</span>'
          + '<span class="vl">' + fmt(it.value) + '</span><span class="pc">' + pct + '</span></button>'
          + (it.url ? '<a class="dash-go" href="' + esc(it.url) + '" title="เปิดรายการ ' + esc(it.name) + '" aria-label="เปิดรายการ ' + esc(it.name) + '"><i class="bi bi-box-arrow-up-right"></i></a>' : '')
          + '</li>';
      }).join('');
      // chart: a fresh canvas each time (staticChart wraps it in its own layout)
      if (chart) { chart.destroy(); chart = null; }
      host.innerHTML = '';
      host.classList.toggle('is-bar', st.view === 'bar');
      host.style.height = '';
      if (!shown.length) { host.innerHTML = '<div class="dash-empty">ซ่อนทุกรายการอยู่ · กดรายการด้านล่างเพื่อแสดง</div>'; return; }
      var canvas = document.createElement('canvas'); canvas.id = cfg.id; host.appendChild(canvas);
      chart = BaitStats.staticChart({ id: cfg.id, type: st.view === 'bar' ? 'bar' : 'doughnut', horizontal: st.view === 'bar', unit: cfg.unit,
        labels: shown.map(function (it) { return it.name; }), data: shown.map(function (it) { return it.value; }), colors: shown.map(function (it) { return it.colorFixed; }) });
      if (!chart) return;
      chart.options.onHover = function (e, els) { e.native.target.style.cursor = els.length && shown[els[0].index].url ? 'pointer' : 'default'; };
      chart.options.onClick = function (e, els) { if (els.length && shown[els[0].index].url) window.location.href = shown[els[0].index].url; };
      chart.update('none');
    }

    tools.addEventListener('click', function (e) {
      var b = e.target.closest('[data-view]'); if (!b) return;
      st.view = b.dataset.view; save(cfg.id, st); draw();
    });
    tools.querySelector('.dash-sort').addEventListener('change', function () { st.sort = this.value; save(cfg.id, st); draw(); });
    list.addEventListener('click', function (e) {
      var b = e.target.closest('.dash-row'); if (!b) return;
      var id = b.dataset.id;
      if (hidden.has(id)) hidden.delete(id); else hidden.add(id);
      draw();
      var again = list.querySelector('.dash-row[data-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]'); if (again) again.focus();
    });
    draw();
  }

  window.BaitDashboard = { init: function (configs) { configs.forEach(panel); } };
})();

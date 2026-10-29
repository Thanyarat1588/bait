<?php
/**
 * BAIT Admin - Website Statistics Module
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// The numbers below count every row of page_views, which grows with each visit: they are worked out at most once every
// 5 minutes (and again when the date changes) and kept in cache/, so opening this page often does not re-scan the table.
// ?fresh=1 skips the copy.
$statsCacheFile = is_dir(BASE_PATH . '/cache') && is_writable(BASE_PATH . '/cache') ? BASE_PATH . '/cache/admin_statistics_visits.json' : null;
$statsCache = null;
if ($statsCacheFile && empty($_GET['fresh']) && is_file($statsCacheFile) && filemtime($statsCacheFile) > time() - 300) {
    $statsCache = json_decode((string)@file_get_contents($statsCacheFile), true);
    if (!is_array($statsCache) || ($statsCache['day'] ?? '') !== date('Y-m-d')) $statsCache = null;
}
if ($statsCache) {
    ['totalViews' => $totalViews, 'viewsToday' => $viewsToday, 'viewsThisMonth' => $viewsThisMonth, 'viewsThisYear' => $viewsThisYear,
     'dailyRaw' => $dailyRaw, 'monthlyRaw' => $monthlyRaw, 'popularPages' => $popularPages, 'topNews' => $topNews] = $statsCache;
} else {
    // ── Summary counters ──
    $totalViews = (int)($db->fetchOne("SELECT COUNT(*) as c FROM page_views")['c'] ?? 0) + pageViewsRemoved();   // + visits already removed by the 400-day retention
    $viewsToday = (int)($db->fetchOne(
        "SELECT COUNT(*) as c FROM page_views WHERE viewed_at >= CURDATE()"
    )['c'] ?? 0);
    $viewsThisMonth = (int)($db->fetchOne(
        "SELECT COUNT(*) as c FROM page_views WHERE viewed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )['c'] ?? 0);
    $viewsThisYear = (int)($db->fetchOne(
        "SELECT COUNT(*) as c FROM page_views WHERE viewed_at >= MAKEDATE(YEAR(CURDATE()), 1)"
    )['c'] ?? 0);

    // visitors = visits (step 18: the first page of each visit); before that migration every page opened counts
    $visitOnly = hasVisitCounting() ? 'new_visit = 1 AND ' : '';

    // ── Daily trend, last 30 days ──
    $dailyRaw = $db->fetchAll(
        "SELECT DATE(viewed_at) as d, COUNT(*) as c
         FROM page_views WHERE {$visitOnly}viewed_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
         GROUP BY DATE(viewed_at)"
    );

    // ── Monthly trend, last 12 months ──
    $monthlyRaw = $db->fetchAll(
        "SELECT DATE_FORMAT(viewed_at,'%Y-%m') as m, COUNT(*) as c
         FROM page_views WHERE {$visitOnly}viewed_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
         GROUP BY m"
    );

    // ── Popular pages, last 30 days ──
    $popularPages = $db->fetchAll(
        "SELECT page_url, page_title, COUNT(*) as c
         FROM page_views
         WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND page_title != ''
         GROUP BY page_url, page_title
         ORDER BY c DESC LIMIT 10"
    );

    // ── Most viewed news ──
    $topNews = $db->fetchAll(
        "SELECT n.news_id, n.title, n.view_count, n.created_at, c.category_name, c.color
         FROM news n JOIN categories c ON c.category_id = n.category_id
         WHERE n.status = 'เผยแพร่'
         ORDER BY n.view_count DESC LIMIT 10"
    );
    if ($statsCacheFile) @file_put_contents($statsCacheFile, json_encode(['day' => date('Y-m-d')] + compact(
        'totalViews', 'viewsToday', 'viewsThisMonth', 'viewsThisYear', 'dailyRaw', 'monthlyRaw', 'popularPages', 'topNews'), JSON_UNESCAPED_UNICODE), LOCK_EX);
}
// The four summary numbers come from the same snapshot as the public footer (siteVisitStats), so both always agree.
if ($vs = siteVisitStats(empty($_GET['fresh']) ? 60 : 0)) {
    ['all' => $totalViews, 'today' => $viewsToday, 'month' => $viewsThisMonth, 'year' => $viewsThisYear] = $vs;
}

$dailyMap = array_column($dailyRaw, 'c', 'd');
// Thai-style dates (dd/mm/พ.ศ.) and weekday names for the daily chart; the page can show the last 7 / 15 / 30 of these
$thaiWeekdays = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$dailyLabels = []; $dailyWeekday = []; $dailyData = [];
for ($i = 29; $i >= 0; $i--) {
    $ts = strtotime("-$i days");
    $d  = date('Y-m-d', $ts);
    $dailyLabels[]  = date('d/m/', $ts) . (date('Y', $ts) + 543);
    $dailyWeekday[] = 'วัน' . $thaiWeekdays[(int)date('w', $ts)];
    $dailyData[]    = (int)($dailyMap[$d] ?? 0);
}

$monthlyMap = array_column($monthlyRaw, 'c', 'm');
$thaiMonthsShort = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
$monthLabels = []; $monthData = [];
// Month $i months ago, counted from the 1st: "-1 month" on the 31st lands in the month after (Oct 31 -> "Sep 31" = Oct 1),
// which repeated some months and skipped others on the 29th-31st.
$monthStart = fn(int $i): int => mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
for ($i = 11; $i >= 0; $i--) {
    $ts = $monthStart($i);
    $m  = date('Y-m', $ts);
    $monthLabels[] = $thaiMonthsShort[(int)date('n', $ts)] . ' ' . substr((string)(date('Y', $ts) + 543), 2, 2);
    $monthData[]   = (int)($monthlyMap[$m] ?? 0);
}

$maxPopular = $popularPages[0]['c'] ?? 0;

$maxNewsViews = $topNews[0]['view_count'] ?? 0;

// ── Excel export (?export=xlsx&days=7|15|30) ── the same numbers the page shows, as a real .xlsx
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $days = (int)($_GET['days'] ?? 30); if (!in_array($days, [7, 15, 30], true)) $days = 30;
    $be = fn(int $ts) => date('d/m/', $ts) . (date('Y', $ts) + 543);
    $x = new SimpleXlsx();
    $x->addSheet('สรุป', [
        ['หัวข้อ', 'ค่า'],
        ['ผู้เข้าชมทั้งหมด', $totalViews],
        ['ผู้เข้าชมวันนี้', $viewsToday],
        ['ผู้เข้าชมเดือนนี้', $viewsThisMonth],
        ['ผู้เข้าชมปีนี้ (พ.ศ. ' . (date('Y') + 543) . ')', $viewsThisYear],
        ['ส่งออกเมื่อ', $be(time()) . ' ' . date('H:i')],
    ], [38, 22]);
    $rows = [['วันที่', 'วัน', 'ผู้เข้าชม']];
    for ($i = count($dailyData) - $days; $i < count($dailyData); $i++) $rows[] = [$dailyLabels[$i], $dailyWeekday[$i], $dailyData[$i]];
    $rows[] = ['รวม ' . $days . ' วัน', '', array_sum(array_slice($dailyData, -$days))];
    $x->addSheet('ผู้เข้าชมรายวัน', $rows, [16, 16, 14]);
    $rows = [['เดือน', 'ผู้เข้าชม']];
    for ($i = 11; $i >= 0; $i--) { $ts = $monthStart($i); $rows[] = [$thaiMonthsShort[(int)date('n', $ts)] . ' ' . (date('Y', $ts) + 543), (int)($monthlyMap[date('Y-m', $ts)] ?? 0)]; }
    $x->addSheet('ผู้เข้าชมรายเดือน', $rows, [16, 14]);
    $rows = [['#', 'ชื่อหน้า', 'URL', 'เปิดดู (ครั้ง, 30 วัน)']];
    foreach ($popularPages as $i => $r) $rows[] = [$i + 1, (string)$r['page_title'], (string)$r['page_url'], (int)$r['c']];
    $x->addSheet('หน้ายอดนิยม', $rows, [6, 46, 46, 18]);
    $rows = [['#', 'หัวข้อข่าว', 'หมวดหมู่', 'วันที่ลงข่าว', 'ยอดเข้าชม']];
    foreach ($topNews as $i => $n) $rows[] = [$i + 1, (string)$n['title'], (string)$n['category_name'], $be(strtotime($n['created_at'])), (int)$n['view_count']];
    $x->addSheet('ข่าวยอดนิยม', $rows, [6, 60, 22, 16, 14]);
    $x->download('bait-website-statistics-' . date('Ymd') . '.xlsx');
}

$pageTitle   = 'สถิติเว็บไซต์';
$activeMenu  = 'statistics-overview';
$breadcrumb  = [['label' => 'สถิติเว็บไซต์']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <a id="exportXlsx" class="ne-btn ne-btn-excel" href="?export=xlsx&days=30" title="ส่งออกสถิติเว็บไซต์เป็นไฟล์ Excel (ผู้เข้าชมรายวันตามช่วงที่เลือก)"><i class="bi bi-file-earmark-excel"></i> ส่งออกรายงานสถิติ (Excel)</a>
  </div>

  <!-- Summary cards -->
  <div class="ne-stat-grid">
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-blue"><i class="bi bi-eye"></i></div>
      <div><div class="ne-stat-num"><?= number_format($totalViews) ?></div><div class="ne-stat-label">ผู้เข้าชมทั้งหมด</div></div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-green"><i class="bi bi-sun"></i></div>
      <div><div class="ne-stat-num"><?= number_format($viewsToday) ?></div><div class="ne-stat-label">ผู้เข้าชมวันนี้</div></div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-gold"><i class="bi bi-bar-chart-line"></i></div>
      <div><div class="ne-stat-num"><?= number_format($viewsThisMonth) ?></div><div class="ne-stat-label">ผู้เข้าชมเดือนนี้</div></div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-danger"><i class="bi bi-graph-up-arrow"></i></div>
      <div><div class="ne-stat-num"><?= number_format($viewsThisYear) ?></div><div class="ne-stat-label">ผู้เข้าชมปีนี้</div></div>
    </div>
  </div>

  <div class="ne-two-col">
    <div class="ne-panel">
      <div class="ne-panel-head" style="flex-wrap:wrap;gap:8px 12px;">
        <span><i class="bi bi-graph-up"></i>ผู้เข้าชมรายวัน</span>
        <select id="dailyRange" class="ne-select" style="width:auto;min-width:130px;min-height:36px;padding-top:4px;padding-bottom:4px;" aria-label="ช่วงเวลา">
          <option value="7">7 วันล่าสุด</option>
          <option value="15">15 วันล่าสุด</option>
          <option value="30" selected>30 วันล่าสุด</option>
        </select>
      </div>
      <div class="ne-panel-body"><div class="ne-chart-box" style="height:260px"><canvas id="dailyViewsChart"></canvas></div></div>
    </div>
    <div class="ne-panel">
      <div class="ne-panel-head"><span><i class="bi bi-bar-chart"></i>ผู้เข้าชมรายเดือน (12 เดือนล่าสุด)</span></div>
      <div class="ne-panel-body"><div class="ne-chart-box" style="height:260px"><canvas id="monthlyViewsChart"></canvas></div></div>
    </div>
  </div>

  <!-- Popular pages -->
  <div class="ne-panel" style="margin-top:24px;">
    <div class="ne-panel-head"><span><i class="bi bi-fire"></i>หน้าเว็บไซต์ที่ได้รับความนิยม (30 วันล่าสุด)</span></div>
    <?php if (!empty($popularPages)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr><th style="width:44px">#</th><th>หน้า</th><th class="text-end" style="width:110px">เปิดดู (ครั้ง)</th><th style="min-width:160px">สัดส่วน</th></tr>
        </thead>
        <tbody>
          <?php foreach ($popularPages as $i => $row): ?>
          <tr>
            <td class="ne-num"><?= $i + 1 ?></td>
            <td>
              <a href="<?= e($row['page_url']) ?>" target="_blank" class="ne-title-link"><?= e($row['page_title']) ?></a>
              <div class="ne-byline"><?= e($row['page_url']) ?></div>
            </td>
            <td class="text-end fw-600" style="color:var(--ne-blue)"><?= number_format($row['c']) ?></td>
            <td>
              <div style="height:8px;background:var(--ne-blue-soft);border-radius:4px;overflow:hidden;">
                <div style="height:100%;background:var(--ne-blue);width:<?= $maxPopular > 0 ? round(($row['c']/$maxPopular)*100,1) : 0 ?>%"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-fire"></i>ยังไม่มีข้อมูลผู้เข้าชม</div>
    <?php endif; ?>
  </div>

  <!-- Most viewed news -->
  <div class="ne-panel" style="margin-top:24px;">
    <div class="ne-panel-head"><span><i class="bi bi-newspaper"></i>ข่าวสารที่มียอดการเข้าชมสูงสุด</span></div>
    <?php if (!empty($topNews)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr><th style="width:44px">#</th><th>หัวข้อข่าว</th><th style="width:150px">หมวดหมู่</th><th class="text-end" style="width:110px">ยอดเข้าชม</th><th style="min-width:160px">สัดส่วน</th></tr>
        </thead>
        <tbody>
          <?php foreach ($topNews as $i => $n): ?>
          <tr>
            <td class="ne-num"><?= $i + 1 ?></td>
            <td>
              <a href="<?= BASE_URL ?>/admin/modules/news/edit.php?id=<?= $n['news_id'] ?>" class="ne-title-link"><?= e(mb_strimwidth($n['title'], 0, 40, '...', 'UTF-8')) ?></a>
              <div class="ne-byline"><?= thaiDate($n['created_at'], true) ?></div>
            </td>
            <td><span class="ne-chip" style="background:<?= e($n['color']) ?>22;color:<?= e($n['color']) ?>"><?= e($n['category_name']) ?></span></td>
            <td class="text-end fw-600" style="color:var(--ne-blue)"><?= number_format($n['view_count']) ?></td>
            <td>
              <div style="height:8px;background:var(--ne-blue-soft);border-radius:4px;overflow:hidden;">
                <div style="height:100%;background:var(--ne-blue);width:<?= $maxNewsViews > 0 ? round(($n['view_count']/$maxNewsViews)*100,1) : 0 ?>%"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-newspaper"></i>ยังไม่มีข่าวสารที่เผยแพร่</div>
    <?php endif; ?>
  </div>
</div>

<?php
$vendor = BASE_URL . '/assets/js/vendor';
$ver = fn(string $f) => '?v=' . (@filemtime(BASE_PATH . '/' . $f) ?: time());
$daily = ['labels' => $dailyLabels, 'weekday' => $dailyWeekday, 'data' => $dailyData];
$extraJs = '
<script src="' . $vendor . '/chart.umd.min.js' . $ver('assets/js/vendor/chart.umd.min.js') . '"></script>
<script src="' . $vendor . '/chartjs-plugin-datalabels.min.js' . $ver('assets/js/vendor/chartjs-plugin-datalabels.min.js') . '"></script>
<script src="' . BASE_URL . '/assets/js/stats-explorer.js' . $ver('assets/js/stats-explorer.js') . '"></script>
<script>
(function () {
  // monthly: every bar its own colour, number on each bar (same rules as the other charts)
  BaitStats.staticChart({ id: "monthlyViewsChart", type: "bar", unit: "ครั้ง", labels: ' . json_encode($monthLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ', data: ' . json_encode($monthData) . ' });

  // daily: last 7 / 15 / 30 days; moving the mouse (or a finger) along the chart shows that day and its number, like a stock chart
  var D = ' . json_encode($daily, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
  var el = document.getElementById("dailyViewsChart"), sel = document.getElementById("dailyRange");
  if (!el || !window.Chart) return;
  if (window.ChartDataLabels) Chart.register(ChartDataLabels);
  var fam = "Kanit, sans-serif", n = 30;
  function part(a) { return a.slice(a.length - n); }
  var crosshair = { id: "crosshair", afterDatasetsDraw: function (c) {
    var act = c.tooltip && c.tooltip.getActiveElements ? c.tooltip.getActiveElements() : [];
    if (!act.length) return;
    var x = act[0].element.x, a = c.chartArea, ctx = c.ctx;
    ctx.save(); ctx.strokeStyle = "rgba(22,46,147,.45)"; ctx.lineWidth = 1; ctx.setLineDash([4, 4]);
    ctx.beginPath(); ctx.moveTo(x, a.top); ctx.lineTo(x, a.bottom); ctx.stroke(); ctx.restore();
  } };
  var chart = new Chart(el, {
    type: "line",
    data: { labels: part(D.labels), datasets: [{ label: "ผู้เข้าชม", data: part(D.data), borderColor: "#162E93", backgroundColor: "rgba(22,46,147,0.10)", fill: true, tension: 0, borderWidth: 2,
      pointRadius: 0, pointHoverRadius: 6, pointHitRadius: 20, pointBackgroundColor: "#162E93", pointBorderColor: "#fff", pointBorderWidth: 2 }] },
    options: { responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: "index", intersect: false },
      layout: { padding: { top: 22, right: 12 } },
      plugins: { legend: { display: false },
        tooltip: { displayColors: false, padding: 10, titleFont: { family: fam, size: 13 }, bodyFont: { family: fam, size: 13 },
          callbacks: { title: function (it) { var i = it[0].dataIndex, off = D.labels.length - n; return D.weekday[off + i] + " " + it[0].label; },
                       label: function (c) { return "ผู้เข้าชม " + c.parsed.y.toLocaleString("th-TH") + " ครั้ง"; } } },
        datalabels: { display: function () { return n <= 7; }, align: "top", anchor: "end", offset: 2, color: "#14203A", font: { family: fam, size: 11, weight: "600" }, formatter: function (v) { return v.toLocaleString("th-TH"); } } },
      scales: { y: { beginAtZero: true, grace: "10%", ticks: { precision: 0, font: { family: fam, size: 11 } } },
                x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 45, minRotation: 0, font: { family: fam, size: 11 } } } } },
    plugins: [crosshair]
  });
  function apply() {
    n = parseInt(sel.value, 10) || 30;
    var narrow = el.parentElement.clientWidth < 520;
    chart.data.labels = part(D.labels); chart.data.datasets[0].data = part(D.data);
    chart.data.datasets[0].pointRadius = n <= 7 ? 4 : 0;
    var x = chart.options.scales.x.ticks;
    x.autoSkip = n > 7; x.maxTicksLimit = n > 7 ? (narrow ? 5 : 8) : undefined;       // 7 days: every day is named; 15 / 30: evenly spaced dates
    chart.update("none");
  }
  sel.addEventListener("change", apply);
  sel.addEventListener("change", function () { var b = document.getElementById("exportXlsx"); if (b) b.href = "?export=xlsx&days=" + sel.value; });
  apply();
  window.addEventListener("resize", function () { if (n > 7) apply(); });
})();
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

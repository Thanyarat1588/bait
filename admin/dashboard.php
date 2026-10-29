<?php
/**
 * BAIT Admin - Dashboard
 */

require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// ── Stats ──
// Content/system counts only — website visit analytics have their own
// dedicated page (admin/modules/statistics/index.php, "สถิติเว็บไซต์"),
// so this dashboard does not duplicate them.
$stats = [
    'news'        => $db->count('news',    "status = 'เผยแพร่'"),
    'articles'    => $db->count('articles',"status = 'เผยแพร่'"),
    'programs'    => $db->count('programs','is_active = 1'),
    'staff'       => $db->count('staff',   staffCurrentSql('')),   // current staff: not resigned
    'majors'      => $db->count('majors'),
    'campuses'    => $db->count('campuses'),
    'departments' => $db->count('departments'),
    'forms'       => $db->count('forms'),
];

// ── Latest news ──
$latestNews = $db->fetchAll(
    "SELECT n.news_id, n.title, n.status, n.view_count, n.created_at, c.category_name, u.full_name
     FROM news n
     JOIN categories c ON c.category_id = n.category_id
     JOIN users u ON u.user_id = n.user_id
     ORDER BY n.created_at DESC LIMIT 8"
);

// ── Chart data ──

// ข่าวสารแยกตามหมวดหมู่
$newsByCat = $db->fetchAll(
    "SELECT c.category_id, c.category_name, c.color, COUNT(n.news_id) as c
     FROM categories c
     LEFT JOIN news n ON n.category_id = c.category_id AND n.status = 'เผยแพร่'
     GROUP BY c.category_id ORDER BY c.category_name"
);

// หลักสูตรแยกตามระดับการศึกษา
$programsByLevel = $db->fetchAll(
    "SELECT el.level_id, el.level_name, COUNT(p.program_id) as c
     FROM education_levels el
     LEFT JOIN programs p ON p.level_id = el.level_id AND p.is_active = 1
     GROUP BY el.level_id ORDER BY el.level_order"
);

// บุคลากรแยกตามประเภท
$staffByType = $db->fetchAll(
    "SELECT st.staff_type_id, st.staff_type_name, COUNT(s.staff_id) as c
     FROM staff_types st
     LEFT JOIN staff s ON s.staff_type_id = st.staff_type_id AND " . staffCurrentSql('s') . "
     GROUP BY st.staff_type_id ORDER BY c DESC"
);



$pageTitle  = 'แดชบอร์ด';
$activeMenu = 'dashboard';
include __DIR__ . '/layout/header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.ne {
  --ne-ink: #1e2a45;
  --ne-ink-soft: #5b6480;
  --ne-paper: #f5f6fa;
  --ne-surface: #ffffff;
  --ne-line: #e3e6ee;
  --ne-gold: #FBC02D;
  --ne-gold-soft: #f7f1e3;
  --ne-blue: #162E93;
  --ne-blue-deep: #11236E;
  --ne-blue-soft: #ECEEF6;
  --ne-green: #3e8564;
  --ne-green-soft: #e5f2eb;
  --ne-slate: #8b93a7;
  --ne-slate-soft: #eef0f5;
  --ne-danger: #c1443d;
  --ne-danger-soft: #fbeae9;
  font-family: 'Kanit', sans-serif;
  color: var(--ne-ink);
}
.ne * { box-sizing: border-box; }

/* ── Top bar ───────────────────────────────── */
.ne-topbar {
  display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  margin-bottom: 22px; padding-bottom: 16px; border-bottom: 1px solid var(--ne-line);
}
.ne-topbar-eyebrow { font-size: 12.5px; letter-spacing: .08em; color: var(--ne-slate); text-transform: uppercase; margin: 0 0 4px; font-weight: 600; }
.ne-topbar h1 { font-family: 'Kanit', sans-serif; font-size: 26px; font-weight: 700; margin: 0; color: var(--ne-ink); }
.ne-btn {
  font-family: 'Kanit', sans-serif; font-weight: 600; font-size: 14.5px; border-radius: 9px; padding: 10px 18px;
  border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  text-decoration: none; transition: all .15s ease; line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }

/* ── Stat cards ───────────────────────────── */
.ne-stat-grid { display: grid; grid-template-columns: 1fr; gap: 14px; margin-bottom: 22px; }
@media (min-width: 768px) { .ne-stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .ne-stat-grid { grid-template-columns: repeat(4, 1fr); } }
.ne-stat-card {
  background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px;
  padding: 18px 20px; display: flex; align-items: center; gap: 14px;
  transition: box-shadow .15s ease;
}
.ne-stat-card:hover { box-shadow: 0 6px 18px rgba(30,42,69,.06); }
.ne-stat-icon {
  width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: 20px;
}
.ne-stat-icon.is-blue  { background: var(--ne-blue-soft);  color: var(--ne-blue-deep); }
.ne-stat-icon.is-green { background: var(--ne-green-soft); color: var(--ne-green); }
.ne-stat-icon.is-gold  { background: var(--ne-gold-soft);  color: #a06e00; }
.ne-stat-icon.is-danger{ background: var(--ne-danger-soft);color: var(--ne-danger); }
.ne-stat-num { font-family: 'Kanit', sans-serif; font-size: 24px; font-weight: 700; line-height: 1.1; color: var(--ne-ink); }
.ne-stat-label { font-size: 12.5px; color: var(--ne-slate); margin-top: 3px; }

/* ── Panel ────────────────────────────────── */
.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; height: 100%; }
.ne-panel-head {
  display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft);
}
.ne-panel-head span:first-child { display: flex; align-items: center; gap: 8px; }
.ne-panel-head i { color: var(--ne-blue); }
.ne-panel-body { padding: 18px 20px 20px; }

/* ── Charts ───────────────────────────────── */
.ne-chart-grid { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 16px; }
@media (min-width: 768px) { .ne-chart-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1200px) { .ne-chart-grid.is-triple { grid-template-columns: repeat(3, 1fr); } }
.ne-chart-box { position: relative; height: 190px; }
.ne-chart-box.is-compact { height: 150px; }
.ne-chart-legend { font-size: 11.5px; color: var(--ne-ink-soft); }

/* ── Table ────────────────────────────────── */
.ne-table { width: 100%; border-collapse: collapse; }
.ne-table thead th {
  text-align: left; font-size: 11.5px; letter-spacing: .05em; text-transform: uppercase; color: var(--ne-slate);
  font-weight: 600; padding: 12px 20px; background: var(--ne-paper); border-bottom: 1px solid var(--ne-line); white-space: nowrap;
}
.ne-table tbody tr { border-bottom: 1px solid var(--ne-line); transition: background .12s ease; }
.ne-table tbody tr:last-child { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-table td { padding: 12px 20px; vertical-align: middle; font-size: 14px; }

.ne-title-link { font-weight: 600; color: var(--ne-ink); text-decoration: none; font-family: 'Kanit', sans-serif; font-size: 14.5px; line-height: 1.4; }
.ne-title-link:hover { color: var(--ne-blue); }
.ne-byline { font-size: 12px; color: var(--ne-slate); margin-top: 2px; }

.ne-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; white-space: nowrap; background: var(--ne-slate-soft); color: var(--ne-ink-soft); }
.ne-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
.ne-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.ne-badge-published { background: var(--ne-green-soft); color: #1f6b47; }
.ne-badge-draft { background: var(--ne-slate-soft); color: var(--ne-ink-soft); }

.ne-empty { text-align: center; padding: 46px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 30px; display: block; margin-bottom: 8px; color: var(--ne-line); }
</style>

<div class="ne">

  <?php if ($auth->isSuperAdmin() && loginAttemptsFile() === null): ?>
  <div class="ne-alert" role="alert" style="margin-bottom:14px;"><strong><i class="bi bi-shield-exclamation me-1"></i>ระบบกันการเดารหัสผ่านปิดอยู่</strong>เซิร์ฟเวอร์เขียนโฟลเดอร์ <code>cache/</code> ไม่ได้ (และเขียนโฟลเดอร์ชั่วคราวของระบบไม่ได้) จึงนับการเข้าสู่ระบบผิดไม่ได้ กรุณาตั้งสิทธิ์โฟลเดอร์ <code>bait/cache</code> ให้เขียนได้ (เช่น 755) ในโปรแกรม FTP หรือ File Manager ของโฮสต์</div>
  <?php endif; ?>

  <div class="ne-topbar">
    <div>
      <p class="ne-topbar-eyebrow">ภาพรวมระบบ</p>
    </div>
  </div>

  <!-- Stat cards -->
  <div class="ne-stat-grid">
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-blue"><i class="bi bi-newspaper"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['news']) ?></div>
        <div class="ne-stat-label">ข่าวสารที่เผยแพร่</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-green"><i class="bi bi-file-earmark-text"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['articles']) ?></div>
        <div class="ne-stat-label">บทความที่เผยแพร่</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-gold"><i class="bi bi-book"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['programs']) ?></div>
        <div class="ne-stat-label">หลักสูตรทั้งหมด</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-danger"><i class="bi bi-people"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['staff']) ?></div>
        <div class="ne-stat-label">บุคลากรทั้งหมด</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-blue"><i class="bi bi-diagram-3"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['majors']) ?></div>
        <div class="ne-stat-label">สาขาวิชา</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-green"><i class="bi bi-geo-alt"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['campuses']) ?></div>
        <div class="ne-stat-label">ศูนย์พื้นที่</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-gold"><i class="bi bi-diagram-2"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['departments']) ?></div>
        <div class="ne-stat-label">หน่วยงาน</div>
      </div>
    </div>
    <div class="ne-stat-card">
      <div class="ne-stat-icon is-danger"><i class="bi bi-file-earmark-arrow-down"></i></div>
      <div>
        <div class="ne-stat-num"><?= number_format($stats['forms']) ?></div>
        <div class="ne-stat-label">แบบฟอร์มดาวน์โหลด</div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="ne-chart-grid is-triple">
    <div class="ne-panel">
      <div class="ne-panel-head"><span><i class="bi bi-bar-chart"></i>ข่าวสารตามหมวดหมู่</span></div>
      <div class="ne-panel-body"><div class="ne-chart-box"><canvas id="newsCatChart"></canvas></div></div>
    </div>
    <div class="ne-panel">
      <div class="ne-panel-head"><span><i class="bi bi-bar-chart-line"></i>หลักสูตรแยกตามระดับการศึกษา</span></div>
      <div class="ne-panel-body"><div class="ne-chart-box"><canvas id="programsLevelChart"></canvas></div></div>
    </div>
    <div class="ne-panel">
      <div class="ne-panel-head"><span><i class="bi bi-people"></i>บุคลากรแยกตามประเภท</span></div>
      <div class="ne-panel-body"><div class="ne-chart-box"><canvas id="staffTypeChart"></canvas></div></div>
    </div>
  </div>
  <!-- Latest news -->
  <div class="ne-panel">
    <div class="ne-panel-head">
      <span><i class="bi bi-newspaper"></i>ข่าวสารล่าสุด</span>
      <a href="<?= BASE_URL ?>/admin/modules/news/index.php" class="ne-btn ne-btn-ghost" style="padding:6px 14px;font-size:13px;">ดูทั้งหมด</a>
        </div>
        <?php if (!empty($latestNews)): ?>
        <div class="table-responsive">
          <table class="ne-table">
            <thead>
              <tr><th>หัวข้อ</th><th>ประเภท</th><th>สถานะ</th><th>วันที่</th></tr>
            </thead>
            <tbody>
              <?php foreach ($latestNews as $n): ?>
              <tr>
                <td>
                  <a href="<?= BASE_URL ?>/admin/modules/news/edit.php?id=<?= $n['news_id'] ?>" class="ne-title-link">
                    <?= e(mb_strimwidth($n['title'], 0, 40, '...', 'UTF-8')) ?>
                  </a>
                  <div class="ne-byline"><?= e($n['full_name']) ?></div>
                </td>
                <td><span class="ne-chip"><?= e($n['category_name']) ?></span></td>
                <td>
                  <?php if ($n['status'] === 'เผยแพร่'): ?>
                    <span class="ne-badge ne-badge-published"><?= e($n['status']) ?></span>
                  <?php else: ?>
                    <span class="ne-badge ne-badge-draft"><?= e($n['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td><small class="text-muted"><?= thaiDate($n['created_at']) ?></small></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="ne-empty"><i class="bi bi-newspaper"></i>ยังไม่มีข่าวสาร</div>
        <?php endif; ?>
  </div>

</div>

<?php
$newsCatLabels = array_map(fn($r) => $r['category_name'], $newsByCat);
$newsCatData   = array_map(fn($r) => (int)$r['c'], $newsByCat);
$newsCatColors = array_map(fn($r) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$r['color']) ? $r['color'] : '#162E93', $newsByCat);   // the colour the admin chose on the ประเภทข่าวสาร page
$progLevelLabels = array_map(fn($r) => $r['level_name'], $programsByLevel);
$progLevelData   = array_map(fn($r) => (int)$r['c'], $programsByLevel);
$staffTypeLabels = array_map(fn($r) => $r['staff_type_name'], $staffByType);
$staffTypeData   = array_map(fn($r) => (int)$r['c'], $staffByType);

// A type / level / category with nothing in it (0) is left out: a ring has no slice for it and a row of "0" is just noise.
$nonZero = function (array $labels, array $data, ?array $colors = null): array {
    $out = ['labels' => [], 'data' => [], 'colors' => []];
    foreach ($data as $i => $v) { if ($v > 0) { $out['labels'][] = $labels[$i]; $out['data'][] = $v; if ($colors) $out['colors'][] = $colors[$i]; } }
    return $out;
};
$cNews  = $nonZero($newsCatLabels, $newsCatData, $newsCatColors);
$cLevel = $nonZero($progLevelLabels, $progLevelData);
$cStaff = $nonZero($staffTypeLabels, $staffTypeData);

// Same chart rules as the statistics pages: every bar in a chart gets its own clearly different colour, with its number on it
$vendor = BASE_URL . '/assets/js/vendor';
$ver = fn(string $f) => '?v=' . (@filemtime(dirname(__DIR__) . '/' . $f) ?: time());
$chartData = [
    ['id' => 'newsCatChart',      'type' => 'doughnut', 'unit' => 'ข่าว',     'labels' => $cNews['labels'],  'data' => $cNews['data'],  'colors' => $cNews['colors']],   // colours = the ones chosen on the ประเภทข่าวสาร page
    ['id' => 'programsLevelChart','type' => 'doughnut', 'unit' => 'หลักสูตร', 'labels' => $cLevel['labels'], 'data' => $cLevel['data']],
    ['id' => 'staffTypeChart',    'type' => 'doughnut', 'unit' => 'คน',       'labels' => $cStaff['labels'],  'data' => $cStaff['data']],
];
$extraJs = '
<script src="' . $vendor . '/chart.umd.min.js' . $ver('assets/js/vendor/chart.umd.min.js') . '"></script>
<script src="' . $vendor . '/chartjs-plugin-datalabels.min.js' . $ver('assets/js/vendor/chartjs-plugin-datalabels.min.js') . '"></script>
<script src="' . BASE_URL . '/assets/js/stats-explorer.js' . $ver('assets/js/stats-explorer.js') . '"></script>
<script>const CH=' . json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';CH.forEach(c=>BaitStats.staticChart(c));</script>';
include __DIR__ . '/layout/footer.php'; ?>

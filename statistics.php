<?php
/**
 * BAIT - Student Statistics Page
 * Split out of the old combined statistics.php (which mixed student +
 * staff numbers on one page) — see statistics-staff.php for the staff side.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/components.php';

$db   = Database::getInstance();
$lang = currentLang();
if (!in_array($lang, ['th', 'en'])) $lang = 'th';
$_SESSION['lang'] = $lang;
$t = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;

// Scoped to the most recent ปีการศึกษา that actually has data (same rule
// admin/modules/student-counts/index.php defaults to) — summing every year
// ever entered here instead would double-count students across years and
// silently drift out of sync with whatever the admin dashboard's default
// view shows, which is exactly what made the two pages' totals disagree.
$statsYear = (int)($db->fetchOne("SELECT MAX(year) as y FROM student_counts")['y'] ?? date('Y'));

$studentByCampus = $db->fetchAll(
    "SELECT c.campus_id, c.campus_name, SUM(sc.total) as total
     FROM student_counts sc JOIN campuses c ON c.campus_id = sc.campus_id
     WHERE sc.year = ?
     GROUP BY c.campus_id ORDER BY total DESC",
    [$statsYear]
);
$studentByLevel = $db->fetchAll(
    "SELECT el.level_id, el.level_name, el.level_abbr, SUM(sc.total) as total
     FROM student_counts sc
     JOIN programs p ON p.program_id = sc.program_id
     JOIN education_levels el ON el.level_id = p.level_id
     WHERE sc.year = ?
     GROUP BY el.level_id ORDER BY el.level_order",
    [$statsYear]
);
// บัณฑิตศึกษา programs (major_id NULL) are excluded here on purpose — this
// breakdown is specifically "by สาขาวิชา", and บัณฑิตศึกษา isn't one (it
// shows up in the by-level and by-program breakdowns instead).
$studentByMajor = $db->fetchAll(
    "SELECT m.major_id, m.major_name, SUM(sc.total) as total
     FROM student_counts sc
     JOIN programs p ON p.program_id = sc.program_id
     JOIN majors m ON m.major_id = p.major_id
     WHERE sc.year = ?
     GROUP BY m.major_id ORDER BY total DESC",
    [$statsYear]
);
$studentByProgram = $db->fetchAll(
    "SELECT p.program_id, p.program_name, SUM(sc.total) as total
     FROM student_counts sc
     JOIN programs p ON p.program_id = sc.program_id
     WHERE sc.year = ?
     GROUP BY p.program_id ORDER BY total DESC",
    [$statsYear]
);
$colors = chartColors();
$totalStudents = array_sum(array_column($studentByCampus, 'total'));
// Subtotal of just the rows in $studentByMajor — used as the "รวมทั้งหมด" /
// 100% base for that table specifically. Using $totalStudents there instead
// would visibly not match the sum of the rows shown (บัณฑิตศึกษา students
// are correctly excluded from this table, but still counted in the
// site-wide $totalStudents), reading as broken/missing data.
$totalStudentsByMajor = array_sum(array_column($studentByMajor, 'total'));

$programsByLevel = $db->fetchAll(
    "SELECT el.level_name, COUNT(*) as total
     FROM programs p JOIN education_levels el ON el.level_id = p.level_id
     WHERE p.is_active=1 GROUP BY el.level_id ORDER BY el.level_order"
);
$totalPrograms = array_sum(array_column($programsByLevel, 'total'));
$totalCampuses = count($db->fetchAll("SELECT campus_id FROM campuses"));

// ---- data for the click-to-filter explorer (raw rows: a few dozen numbers) ----
$lastUpdated = $db->fetchOne("SELECT MAX(updated_at) AS u FROM student_counts WHERE year = ?", [$statsYear])['u'] ?? null;
$exCampuses = array_map(fn($r) => ['id' => (int)$r['campus_id'], 'name' => $t($r['campus_name'])], $db->fetchAll("SELECT campus_id, campus_name FROM campuses ORDER BY campus_id"));
$exLevels   = array_map(fn($r) => ['id' => (int)$r['level_id'], 'name' => $t($r['level_abbr'])], $db->fetchAll("SELECT level_id, level_abbr FROM education_levels ORDER BY level_order, level_id"));
$exMajors   = array_map(fn($r) => ['id' => (int)$r['major_id'], 'name' => $t($r['major_name'])], $db->fetchAll("SELECT major_id, major_name FROM majors ORDER BY major_id"));
$pcMap = [];
foreach ($db->fetchAll("SELECT program_id, campus_id FROM program_campuses") as $r) { $pcMap[(int)$r['program_id']][] = (int)$r['campus_id']; }
$exPrograms = array_map(fn($r) => [
    'id' => (int)$r['program_id'], 'name' => $t($r['program_name']), 'active' => (int)$r['is_active'] === 1,
    'attrs' => ['level' => (int)$r['level_id'], 'major' => $r['major_id'] !== null ? (int)$r['major_id'] : null, 'campus' => $pcMap[(int)$r['program_id']] ?? []],
], $db->fetchAll(
    "SELECT p.program_id, p.program_name, p.level_id, p.major_id, p.is_active FROM programs p
      WHERE p.is_active = 1 OR EXISTS (SELECT 1 FROM student_counts s WHERE s.program_id = p.program_id AND s.year = ?)
      ORDER BY p.program_id", [$statsYear]));
$exRows = array_map(fn($r) => ['campus' => (int)$r['campus_id'], 'level' => (int)$r['level_id'], 'major' => $r['major_id'] !== null ? (int)$r['major_id'] : null, 'program' => (int)$r['program_id'], 'v' => (int)$r['total']],
    $db->fetchAll("SELECT sc.campus_id, sc.program_id, sc.total, p.level_id, p.major_id FROM student_counts sc JOIN programs p ON p.program_id = sc.program_id WHERE sc.year = ?", [$statsYear]));
$currentLabel = $t('ข้อมูลปัจจุบัน') . ($lastUpdated ? ' (' . $t('อัปเดตล่าสุด') . ' ' . thaiDate($lastUpdated) . ')' : '');

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Student Statistics' : 'สถิตินักศึกษา');
$pageTitle = $lang === 'en' ? 'Student Statistics' : 'สถิตินักศึกษา';
$metaDesc = $lang === 'en'
    ? 'Student statistics of the Faculty by campus, education level, major and program, with interactive charts of the current data.'
    : 'สถิติจำนวนนักศึกษาของคณะ จำแนกตามศูนย์พื้นที่ ระดับการศึกษา สาขาวิชา และหลักสูตร พร้อมกราฟโต้ตอบแสดงข้อมูลปัจจุบัน';
$activePage = 'info';
$activeSubPage = 'statistics-student';
include __DIR__ . '/layout/header.php';

$colors = chartColors();
$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('สถิตินักศึกษา')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('สถิตินักศึกษา') ?></h1>
    <p class="text-faint" style="margin-top:4px"><?= e($currentLabel) ?></p>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section stat-explorer">

  <div class="cluster gap-2" style="justify-content:flex-end;margin-bottom:20px">
    <a href="<?= BASE_URL ?>/statistics-staff.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm">
      <?= icon('users', 'icon-sm') ?> <?= $t('ดูสถิติบุคลากร') ?> <?= icon('arrow-right', 'icon-sm') ?>
    </a>
  </div>

  <div class="stat-filterbar" data-filterbar hidden role="status" aria-live="polite">
    <span class="stat-filterbar-title"><?= e($t('กำลังกรอง')) ?>:</span>
    <span data-chips></span>
    <button type="button" class="btn btn-ghost btn-sm" data-reset><?= e($t('รีเซ็ตตัวกรอง')) ?></button>
  </div>
  <p class="stat-hint" data-filterhint style="margin:-6px 0 14px"><?= e($t('คลิกที่กราฟเพื่อกรองข้อมูลทั้งหน้า คลิกที่ว่างเพื่อล้างตัวกรอง')) ?></p>

  <div class="grid grid-4 reveal" style="margin-bottom:40px">
    <?php
    $summaryCards = [
      ['id' => 'statCardTotal',    'icon' => 'graduation-cap', 'count' => $totalStudents, 'label' => $t('นักศึกษาทั้งหมด')],
      ['id' => 'statCardPrograms', 'icon' => 'book',           'count' => $totalPrograms, 'label' => $t('หลักสูตร')],
      ['id' => 'statCardCampuses', 'icon' => 'building',       'count' => $totalCampuses, 'label' => $t('ศูนย์พื้นที่')],
      ['id' => 'statCardLevels',   'icon' => 'award',          'count' => count($studentByLevel), 'label' => $t('ระดับการศึกษา')],
    ];
    foreach ($summaryCards as $sc): ?>
    <div class="stat-mini stat-mini--row">
      <div class="stat-mini-icon"><?= icon($sc['icon']) ?></div>
      <div class="stat-mini-num" id="<?= $sc['id'] ?>"><?= number_format($sc['count']) ?></div>
      <div class="stat-mini-label"><?= $sc['label'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="section-header"><div><h2><?= $t('สถิตินักศึกษา') ?></h2><p><?= $t('ข้อมูลจำนวนนักศึกษา จำแนกตามศูนย์พื้นที่และระดับการศึกษา') ?></p></div></div>

  <div class="grid grid-2" style="margin-bottom:32px">
    <div class="card chart-card reveal">
      <h3><?= icon('building', 'icon-sm') ?> <?= $t('นักศึกษาตามศูนย์พื้นที่') ?></h3>
      <?php if ($studentByCampus): ?>
      <div class="chart-box" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="studentCampusChart" role="img" aria-label="<?= e($t('นักศึกษาตามศูนย์พื้นที่')) ?>"></canvas></div>
      <?php else: ?>
      <div class="empty-state" style="padding:32px"><?= $t('ยังไม่มีข้อมูล') ?></div>
      <?php endif; ?>
    </div>
    <div class="card chart-card reveal">
      <h3><?= icon('list', 'icon-sm') ?> <?= $t('นักศึกษาตามระดับการศึกษา') ?> <small class="text-faint" data-scope style="font-weight:400">(<?= $t('ทุกศูนย์พื้นที่') ?>)</small></h3>
      <?php if ($studentByLevel): ?>
      <div class="chart-box" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="studentLevelChart" role="img" aria-label="<?= e($t('นักศึกษาตามระดับการศึกษา')) ?>"></canvas></div>
      <?php else: ?>
      <div class="empty-state" style="padding:32px"><?= $t('ยังไม่มีข้อมูล') ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($studentByMajor): ?>
  <div class="card chart-card reveal" style="margin-bottom:40px">
    <h3><?= icon('list', 'icon-sm') ?> <?= $t('นักศึกษาตามสาขาวิชา') ?> <small class="text-faint" data-scope style="font-weight:400">(<?= $t('ทุกศูนย์พื้นที่') ?>)</small></h3>
    <p class="text-faint" style="font-size:.82rem;margin:-8px 0 16px">
      <?= $lang === 'en' ? 'Only programs tied to a major (field of study) — graduate programs have none and are counted in the by-level and by-program breakdowns instead.' : 'นับเฉพาะหลักสูตรที่มีสาขาวิชา — หลักสูตรบัณฑิตศึกษาไม่มีสาขาวิชา จึงนับรวมอยู่ในตามระดับการศึกษาและตามหลักสูตรแทน' ?>
    </p>
    <div class="chart-box is-compact" style="margin-bottom:20px" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="studentMajorChart" role="img" aria-label="<?= e($t('นักศึกษาตามสาขาวิชา')) ?>"></canvas></div>
    <div class="tbl-wrap" style="overflow-x:auto">
      <table class="data-table" id="tblMajor">
        <thead><tr><th><?= $t('สาขาวิชา') ?></th><th style="text-align:right"><?= $t('นักศึกษา') ?></th><th><?= $t('สัดส่วน') ?></th></tr></thead>
        <tbody>
          <?php foreach ($studentByMajor as $idx => $row): $pct = $totalStudentsByMajor > 0 ? round(($row['total'] / $totalStudentsByMajor) * 100, 1) : 0; $color = $colors[$idx % count($colors)]; ?>
          <tr class="stat-row">
            <td><span class="legend-dot" style="background:<?= $color ?>"></span><?= e($t($row['major_name'])) ?></td>
            <td class="num-cell"><?= number_format($row['total']) ?></td>
            <td style="min-width:200px">
              <div class="cluster gap-2">
                <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                <small class="text-faint" style="min-width:40px"><?= $pct ?>%</small>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td><?= $t('รวมทั้งหมด') ?></td><td class="num-cell"><?= number_format($totalStudentsByMajor) ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($studentByProgram): $programPerPage = 10; $programPageCount = (int)ceil(count($studentByProgram) / $programPerPage); ?>
  <div class="card chart-card reveal" style="margin-bottom:40px">
    <h3><?= icon('book', 'icon-sm') ?> <?= $t('นักศึกษาตามหลักสูตร') ?> <small class="text-faint" data-scope style="font-weight:400">(<?= $t('ทุกศูนย์พื้นที่') ?>)</small></h3>
    <div class="tbl-wrap" style="overflow-x:auto">
      <table class="data-table" id="tblProgram">
        <thead><tr><th><?= $t('หลักสูตร') ?></th><th style="text-align:right"><?= $t('นักศึกษา') ?></th><th><?= $t('สัดส่วน') ?></th></tr></thead>
        <tbody data-paginate="programTable">
          <?php foreach ($studentByProgram as $idx => $row): $pct = $totalStudents > 0 ? round(($row['total'] / $totalStudents) * 100, 1) : 0; $color = $colors[$idx % count($colors)]; $page = intdiv($idx, $programPerPage) + 1; ?>
          <tr class="stat-row" data-page="<?= $page ?>"<?= $page > 1 ? ' hidden' : '' ?>>
            <td><span class="legend-dot" style="background:<?= $color ?>"></span><?= e($t($row['program_name'])) ?></td>
            <td class="num-cell"><?= number_format($row['total']) ?></td>
            <td style="min-width:200px">
              <div class="cluster gap-2">
                <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                <small class="text-faint" style="min-width:40px"><?= $pct ?>%</small>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td><?= $t('รวมทั้งหมด') ?></td><td class="num-cell"><?= number_format($totalStudents) ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
    <div class="cluster gap-2" style="justify-content:center;margin-top:16px" id="pagerProgram">
      <?php if ($programPageCount > 1): for ($p = 1; $p <= $programPageCount; $p++): ?>
      <button type="button" class="btn btn-sm <?= $p === 1 ? 'btn-primary' : 'btn-ghost' ?>" data-page-btn="<?= $p ?>"><?= $p ?></button>
      <?php endfor; endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php
$vendor = BASE_URL . '/assets/js/vendor';
$ver = fn(string $p) => '?v=' . (@filemtime(__DIR__ . '/' . $p) ?: time());
$explorerCfg = [
    'lang' => $lang, 'unit' => $t('คน'),
    'i18n' => ['noData' => $t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก'), 'remove' => $t('ลบตัวกรองนี้')],
    'dims' => [
        'campus'  => ['title' => $t('ศูนย์พื้นที่'),     'items' => $exCampuses],
        'level'   => ['title' => $t('ระดับการศึกษา'),   'items' => $exLevels],
        'major'   => ['title' => $t('สาขาวิชา'),         'items' => $exMajors],
        'program' => ['title' => $t('หลักสูตร'),         'items' => $exPrograms],
    ],
    'rows' => $exRows,
    'cards' => [
        ['el' => '#statCardTotal',    'kind' => 'sum'],
        ['el' => '#statCardPrograms', 'kind' => 'offered',  'dim' => 'program', 'initial' => (int)$totalPrograms],
        ['el' => '#statCardCampuses', 'kind' => 'withData', 'dim' => 'campus',  'initial' => (int)$totalCampuses],
        ['el' => '#statCardLevels',   'kind' => 'withData', 'dim' => 'level',   'initial' => count($studentByLevel)],
    ],
    'charts' => [
        ['canvas' => '#studentCampusChart', 'dim' => 'campus', 'kind' => 'bar'],
        ['canvas' => '#studentLevelChart',  'dim' => 'level',  'kind' => 'doughnut'],
        ['canvas' => '#studentMajorChart',  'dim' => 'major',  'kind' => 'barH'],
    ],
    'tables' => [
        ['el' => '#tblMajor',   'dim' => 'major',   'pageSize' => 0],
        ['el' => '#tblProgram', 'dim' => 'program', 'pageSize' => 10, 'pager' => '#pagerProgram'],
    ],
];
$extraJs = '
<script src="' . $vendor . '/chart.umd.min.js' . $ver('assets/js/vendor/chart.umd.min.js') . '"></script>
<script src="' . $vendor . '/chartjs-plugin-datalabels.min.js' . $ver('assets/js/vendor/chartjs-plugin-datalabels.min.js') . '"></script>
<script src="' . BASE_URL . '/assets/js/stats-explorer.js' . $ver('assets/js/stats-explorer.js') . '"></script>
<script>BaitStats.explorer(' . json_encode($explorerCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>';
include __DIR__ . '/layout/footer.php';
?>

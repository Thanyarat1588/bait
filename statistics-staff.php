<?php
/**
 * BAIT - Staff Statistics Page
 * Split out of the old combined statistics.php (which mixed student +
 * staff numbers on one page) — see statistics.php for the student side.
 * Mirrors that page's layout/logic exactly (same year-defaulting, same
 * "(ทุกศูนย์พื้นที่)" scope labels, same paginated long-list tables).
 *
 * Staff counts here are counted live from the staff records (current staff only), see staffCountRows() in helpers.php.
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

// Counted live from the staff records (helpers.php staffCountRows()): current staff only — people who resigned are not
// counted, and outside committee members are not staff records at all. (Until 22 Oct 2026 these numbers were typed in
// by hand into staff_counts; that table is kept but no longer used.)
$liveRows = staffCountRows();
$nameMap = fn(string $sql, string $id, string $name) => array_column($db->fetchAll($sql), $name, $id);
$campusNames = $nameMap('SELECT campus_id, campus_name FROM campuses ORDER BY campus_id', 'campus_id', 'campus_name');
$typeNames   = $nameMap('SELECT staff_type_id, staff_type_name FROM staff_types ORDER BY staff_type_id', 'staff_type_id', 'staff_type_name');
$majorNames  = $nameMap('SELECT major_id, major_name FROM majors ORDER BY major_id', 'major_id', 'major_name');
$deptNames   = $nameMap('SELECT department_id, department_name FROM departments ORDER BY department_id', 'department_id', 'department_name');
// one breakdown: [[id col => id, name col => name, 'total' => n], ...] largest first, rows without that value left out
$sumBy = function (string $key, array $names, string $idCol, string $nameCol) use ($liveRows): array {
    $sum = [];
    foreach ($liveRows as $r) { $k = $r[$key]; if ($k === null || !isset($names[$k])) continue; $sum[$k] = ($sum[$k] ?? 0) + (int)$r['total']; }
    arsort($sum);
    $out = [];
    foreach ($sum as $k => $v) $out[] = [$idCol => (int)$k, $nameCol => $names[$k], 'total' => $v];
    return $out;
};
$staffByCampus = $sumBy('campus_id', $campusNames, 'campus_id', 'campus_name');
$staffByType   = $sumBy('staff_type_id', $typeNames, 'staff_type_id', 'staff_type_name');
// บุคลากรที่ไม่มีสาขาวิชา (เช่น เจ้าหน้าที่สายสนับสนุน) ถูกตัดออกโดยตั้งใจ — หัวข้อนี้เป็น "ตามสาขาวิชา" โดยเฉพาะ
$staffByMajor  = $sumBy('major_id', $majorNames, 'major_id', 'major_name');
$staffByDept   = $sumBy('department_id', $deptNames, 'department_id', 'department_name');
$colors = chartColors();
$totalStaff = array_sum(array_column($liveRows, 'total'));
// Subtotal of just the rows in $staffByMajor — used as the "รวมทั้งหมด" / 100% base for that table specifically.
$totalStaffByMajor = array_sum(array_column($staffByMajor, 'total'));
$totalDepartments = count($deptNames);
$totalCampuses = count($campusNames);

// ---- data for the click-to-filter explorer (raw rows: a few dozen numbers) ----
$lastUpdated = staffCountsUpdatedAt();
$exCampuses = array_map(fn($id, $n) => ['id' => (int)$id, 'name' => $t($n)], array_keys($campusNames), $campusNames);
$exTypes    = array_map(fn($id, $n) => ['id' => (int)$id, 'name' => $t($n)], array_keys($typeNames), $typeNames);
$exMajors   = array_map(fn($id, $n) => ['id' => (int)$id, 'name' => $t($n)], array_keys($majorNames), $majorNames);
$exDepts    = array_map(fn($id, $n) => ['id' => (int)$id, 'name' => $t($n)], array_keys($deptNames), $deptNames);
$exRows = array_map(fn($r) => ['campus' => (int)$r['campus_id'], 'major' => $r['major_id'] !== null ? (int)$r['major_id'] : null, 'dept' => (int)$r['department_id'], 'type' => (int)$r['staff_type_id'], 'v' => (int)$r['total']], $liveRows);
$currentLabel = $t('ข้อมูลปัจจุบัน') . ($lastUpdated ? ' (' . $t('อัปเดตล่าสุด') . ' ' . thaiDate($lastUpdated) . ')' : '');

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Staff Statistics' : 'สถิติบุคลากร');
$pageTitle = $lang === 'en' ? 'Staff Statistics' : 'สถิติบุคลากร';
$metaDesc = $lang === 'en'
    ? 'Staff statistics of the Faculty by campus, staff type, major and department, with interactive charts of the current data.'
    : 'สถิติบุคลากรของคณะ จำแนกตามศูนย์พื้นที่ ประเภทบุคลากร สาขาวิชา และหน่วยงาน พร้อมกราฟโต้ตอบแสดงข้อมูลปัจจุบัน';
$activePage = 'info';
$activeSubPage = 'statistics-staff';
include __DIR__ . '/layout/header.php';

$colors = chartColors();
$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('สถิติบุคลากร')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('สถิติบุคลากร') ?></h1>
    <p class="text-faint" style="margin-top:4px"><?= e($currentLabel) ?></p>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section stat-explorer">

  <div class="cluster gap-2" style="justify-content:flex-end;margin-bottom:20px">
    <a href="<?= BASE_URL ?>/statistics.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm">
      <?= icon('graduation-cap', 'icon-sm') ?> <?= $t('ดูสถิตินักศึกษา') ?> <?= icon('arrow-right', 'icon-sm') ?>
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
      ['id' => 'statCardTotal',    'icon' => 'users',    'count' => $totalStaff,        'label' => $t('บุคลากรทั้งหมด')],
      ['id' => 'statCardDepts',    'icon' => 'briefcase','count' => $totalDepartments,  'label' => $t('หน่วยงาน')],
      ['id' => 'statCardCampuses', 'icon' => 'building', 'count' => $totalCampuses,     'label' => $t('ศูนย์พื้นที่')],
      ['id' => 'statCardTypes',    'icon' => 'list',     'count' => count($staffByType), 'label' => $t('ประเภทบุคลากร')],
    ];
    foreach ($summaryCards as $sc): ?>
    <div class="stat-mini stat-mini--row">
      <div class="stat-mini-icon"><?= icon($sc['icon']) ?></div>
      <div class="stat-mini-num" id="<?= $sc['id'] ?>"><?= number_format($sc['count']) ?></div>
      <div class="stat-mini-label"><?= $sc['label'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="section-header"><div><h2><?= $t('สถิติบุคลากร') ?></h2><p><?= $t('ข้อมูลบุคลากรจำแนกตามศูนย์พื้นที่และประเภท') ?></p></div></div>

  <div class="grid grid-2" style="margin-bottom:32px">
    <div class="card chart-card reveal">
      <h3><?= icon('building', 'icon-sm') ?> <?= $t('บุคลากรตามศูนย์พื้นที่') ?></h3>
      <?php if ($staffByCampus): ?>
      <div class="chart-box" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="staffCampusChart" role="img" aria-label="<?= e($t('บุคลากรตามศูนย์พื้นที่')) ?>"></canvas></div>
      <?php else: ?>
      <div class="empty-state" style="padding:32px"><?= $t('ยังไม่มีข้อมูล') ?></div>
      <?php endif; ?>
    </div>
    <div class="card chart-card reveal">
      <h3><?= icon('list', 'icon-sm') ?> <?= $t('บุคลากรตามประเภท') ?> <small class="text-faint" data-scope style="font-weight:400">(<?= $t('ทุกศูนย์พื้นที่') ?>)</small></h3>
      <?php if ($staffByType): ?>
      <div class="chart-box" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="staffTypeChart" role="img" aria-label="<?= e($t('บุคลากรตามประเภท')) ?>"></canvas></div>
      <?php else: ?>
      <div class="empty-state" style="padding:32px"><?= $t('ยังไม่มีข้อมูล') ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($staffByMajor): ?>
  <div class="card chart-card reveal" style="margin-bottom:40px">
    <h3><?= icon('list', 'icon-sm') ?> <?= $t('บุคลากรตามสาขาวิชา') ?> <small class="text-faint" data-scope style="font-weight:400">(<?= $t('ทุกศูนย์พื้นที่') ?>)</small></h3>
    <p class="text-faint" style="font-size:.82rem;margin:-8px 0 16px">
      <?= $lang === 'en' ? 'Only staff tied to a major (field of study) —administrative/support staff have none and are counted in the by-type and by-department breakdowns instead.' : 'นับเฉพาะบุคลากรที่มีสาขาวิชา — บุคลากรสายสนับสนุน/ธุรการไม่มีสาขาวิชา จึงนับรวมอยู่ในตามประเภทและตามหน่วยงานแทน' ?>
    </p>
    <div class="chart-box is-compact" style="margin-bottom:20px" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="staffMajorChart" role="img" aria-label="<?= e($t('บุคลากรตามสาขาวิชา')) ?>"></canvas></div>
    <div class="tbl-wrap" style="overflow-x:auto">
      <table class="data-table" id="tblMajor">
        <thead><tr><th><?= $t('สาขาวิชา') ?></th><th style="text-align:right"><?= $t('บุคลากร') ?></th><th><?= $t('สัดส่วน') ?></th></tr></thead>
        <tbody>
          <?php foreach ($staffByMajor as $idx => $row): $pct = $totalStaffByMajor > 0 ? round(($row['total'] / $totalStaffByMajor) * 100, 1) : 0; $color = $colors[$idx % count($colors)]; ?>
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
        <tfoot><tr><td><?= $t('รวมทั้งหมด') ?></td><td class="num-cell"><?= number_format($totalStaffByMajor) ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($staffByDept): $deptPerPage = 10; $deptPageCount = (int)ceil(count($staffByDept) / $deptPerPage); ?>
  <div class="card chart-card reveal" style="margin-bottom:40px">
    <h3><?= icon('briefcase', 'icon-sm') ?> <?= $t('บุคลากรตามหน่วยงาน') ?> <small class="text-faint" data-scope style="font-weight:400">(<?= $t('ทุกศูนย์พื้นที่') ?>)</small></h3>
    <div class="chart-box is-compact" style="margin-bottom:20px" data-empty="<?= e($t('ไม่พบข้อมูลตามเงื่อนไขที่เลือก')) ?>"><canvas id="staffDeptChart" role="img" aria-label="<?= e($t('บุคลากรตามหน่วยงาน')) ?>"></canvas></div>
    <div class="tbl-wrap" style="overflow-x:auto">
      <table class="data-table" id="tblDept">
        <thead><tr><th><?= $t('หน่วยงาน') ?></th><th style="text-align:right"><?= $t('บุคลากร') ?></th><th><?= $t('สัดส่วน') ?></th></tr></thead>
        <tbody data-paginate="deptTable">
          <?php foreach ($staffByDept as $idx => $row): $pct = $totalStaff > 0 ? round(($row['total'] / $totalStaff) * 100, 1) : 0; $color = $colors[$idx % count($colors)]; $page = intdiv($idx, $deptPerPage) + 1; ?>
          <tr class="stat-row" data-page="<?= $page ?>"<?= $page > 1 ? ' hidden' : '' ?>>
            <td><span class="legend-dot" style="background:<?= $color ?>"></span><?= e($t($row['department_name'])) ?></td>
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
        <tfoot><tr><td><?= $t('รวมทั้งหมด') ?></td><td class="num-cell"><?= number_format($totalStaff) ?></td><td>100%</td></tr></tfoot>
      </table>
    </div>
    <div class="cluster gap-2" style="justify-content:center;margin-top:16px" id="pagerDept">
      <?php if ($deptPageCount > 1): for ($p = 1; $p <= $deptPageCount; $p++): ?>
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
        'campus' => ['title' => $t('ศูนย์พื้นที่'),     'items' => $exCampuses],
        'type'   => ['title' => $t('ประเภทบุคลากร'),   'items' => $exTypes],
        'major'  => ['title' => $t('สาขาวิชา'),         'items' => $exMajors],
        'dept'   => ['title' => $t('หน่วยงาน'),         'items' => $exDepts],
    ],
    'rows' => $exRows,
    'cards' => [
        ['el' => '#statCardTotal',    'kind' => 'sum'],
        ['el' => '#statCardDepts',    'kind' => 'withData', 'dim' => 'dept',   'initial' => (int)$totalDepartments],
        ['el' => '#statCardCampuses', 'kind' => 'withData', 'dim' => 'campus', 'initial' => (int)$totalCampuses],
        ['el' => '#statCardTypes',    'kind' => 'withData', 'dim' => 'type',   'initial' => count($staffByType)],
    ],
    'charts' => [
        ['canvas' => '#staffCampusChart', 'dim' => 'campus', 'kind' => 'bar'],
        ['canvas' => '#staffTypeChart',   'dim' => 'type',   'kind' => 'doughnut'],
        ['canvas' => '#staffMajorChart',  'dim' => 'major',  'kind' => 'barH'],
        ['canvas' => '#staffDeptChart',   'dim' => 'dept',   'kind' => 'barH'],
    ],
    'tables' => [
        ['el' => '#tblMajor', 'dim' => 'major', 'pageSize' => 0],
        ['el' => '#tblDept',  'dim' => 'dept',  'pageSize' => 10, 'pager' => '#pagerDept'],
    ],
];
$extraJs = '
<script src="' . $vendor . '/chart.umd.min.js' . $ver('assets/js/vendor/chart.umd.min.js') . '"></script>
<script src="' . $vendor . '/chartjs-plugin-datalabels.min.js' . $ver('assets/js/vendor/chartjs-plugin-datalabels.min.js') . '"></script>
<script src="' . BASE_URL . '/assets/js/stats-explorer.js' . $ver('assets/js/stats-explorer.js') . '"></script>
<script>BaitStats.explorer(' . json_encode($explorerCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>';
include __DIR__ . '/layout/footer.php';
?>

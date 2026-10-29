<?php
/**
 * BAIT Admin - สถิติบุคลากร (dashboard only)
 * The numbers are counted live from the staff records (helpers.php staffCountRows()): current staff only — not resigned,
 * not deleted; outside committee members are not staff records and are never counted. Nothing is typed in here any more
 * (until 22 Oct 2026 the totals were entered by hand into staff_counts, which is kept but no longer used): to change a
 * number, add / edit / mark as resigned in "บุคลากร".
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
$auth = new Auth(); $auth->requireLogin(); $db = Database::getInstance();

$campuses    = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$departments = $db->fetchAll("SELECT * FROM departments ORDER BY department_name");
$staffTypes  = $db->fetchAll("SELECT * FROM staff_types ORDER BY staff_type_id");
$majors      = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");
$names = [
    'campus_id'     => array_column($campuses, 'campus_name', 'campus_id'),
    'department_id' => array_column($departments, 'department_name', 'department_id'),
    'staff_type_id' => array_column($staffTypes, 'staff_type_name', 'staff_type_id'),
    'major_id'      => array_column($majors, 'major_name', 'major_id'),
];

$filterCampus = (int)getStr('campus');
$filterType   = (int)getStr('type');
$filterDept   = getStr('dept') === 'none' ? 'none' : (int)getStr('dept');
$filterMajor  = getStr('major') === 'none' ? 'none' : (int)getStr('major');   // 'none' = staff without a สาขาวิชา
$anyFilter = $filterCampus || $filterType || $filterDept || $filterMajor;

$rows = array_values(array_filter(staffCountRows(), function ($r) use ($filterCampus, $filterType, $filterDept, $filterMajor) {
    if ($filterCampus && (int)$r['campus_id'] !== $filterCampus) return false;
    if ($filterType && (int)$r['staff_type_id'] !== $filterType) return false;
    if ($filterDept === 'none' ? $r['department_id'] !== null : ($filterDept && (int)$r['department_id'] !== $filterDept)) return false;
    if ($filterMajor === 'none' ? $r['major_id'] !== null : ($filterMajor && (int)$r['major_id'] !== $filterMajor)) return false;
    return true;
}));
$grandTotal = array_sum(array_column($rows, 'total'));

/** name => total for one column, largest first; rows without that value are counted under $noneLabel (or left out). */
$sumBy = function (string $key, ?string $noneLabel = null) use ($rows, $names): array {
    $out = [];
    foreach ($rows as $r) {
        $label = $r[$key] !== null && isset($names[$key][$r[$key]]) ? $names[$key][$r[$key]] : $noneLabel;
        if ($label === null) continue;
        $out[$label] = ($out[$label] ?? 0) + (int)$r['total'];
    }
    arsort($out);
    return $out;
};
$byCampus = $sumBy('campus_id');
$byType   = $sumBy('staff_type_id');
$byDept   = $sumBy('department_id', 'ยังไม่เลือกหน่วยงาน');
$byMajor  = $sumBy('major_id');   // "ตามสาขาวิชา" only for people who really have one (support staff have none)
$cardDepts    = count(array_unique(array_filter(array_column($rows, 'department_id'), fn($v) => $v !== null)));
$cardCampuses = count(array_unique(array_column($rows, 'campus_id')));
$cardTypes    = count(array_unique(array_column($rows, 'staff_type_id')));
$scopeParts = [];
if ($filterCampus) $scopeParts[] = $names['campus_id'][$filterCampus] ?? '';
if ($filterType)   $scopeParts[] = $names['staff_type_id'][$filterType] ?? '';
if ($filterDept)   $scopeParts[] = $filterDept === 'none' ? 'ยังไม่เลือกหน่วยงาน' : ($names['department_id'][$filterDept] ?? '');
if ($filterMajor)  $scopeParts[] = $filterMajor === 'none' ? 'ไม่มีสาขาวิชา' : ($names['major_id'][$filterMajor] ?? '');
$scopeLabel = $scopeParts ? 'เฉพาะ' . implode(' · ', array_filter($scopeParts)) : 'ทุกศูนย์พื้นที่';
$updated = staffCountsUpdatedAt();
$updatedLabel = 'นับจากข้อมูลบุคลากรที่สถานะ "คงอยู่"' . ($updated ? ' (ข้อมูลบุคลากรแก้ไขล่าสุด ' . thaiDate($updated) . ')' : '');
// per campus, the rows behind the numbers (read only)
$grouped = [];
foreach ($rows as $r) {
    $cid = (int)$r['campus_id'];
    $grouped[$cid]['name'] = $names['campus_id'][$cid] ?? 'ไม่ระบุศูนย์';
    $grouped[$cid]['rows'][] = $r;
}
ksort($grouped);

$pageTitle = 'สถิติบุคลากร'; $activeMenu = 'staff-counts';
$breadcrumb = [['label' => 'สถิติบุคลากร']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
$chartData = [
    ['id' => 'chCampus', 'type' => 'bar',      'horizontal' => true, 'unit' => 'คน', 'labels' => array_keys($byCampus), 'data' => array_values($byCampus)],
    ['id' => 'chType',   'type' => 'doughnut', 'unit' => 'คน', 'labels' => array_keys(array_filter($byType)),   'data' => array_values(array_filter($byType))],
    ['id' => 'chMajor',  'type' => 'bar',      'horizontal' => true, 'unit' => 'คน', 'labels' => array_keys($byMajor),  'data' => array_values($byMajor)],
    ['id' => 'chDept',   'type' => 'bar',      'horizontal' => true, 'unit' => 'คน', 'labels' => array_keys($byDept),   'data' => array_values($byDept)],
];
$label = fn(string $key, $id, string $none) => $id !== null && isset($names[$key][$id]) ? $names[$key][$id] : $none;
?>
<div class="ne">
  <div class="ne-topbar">
    <div>
      <h1>สถิติบุคลากร <span class="ne-count"><?= number_format($grandTotal) ?> คน</span></h1>
      <p class="ne-hint" style="margin:2px 0 0"><?= e($updatedLabel) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/admin/modules/staff/index.php" class="ne-btn ne-btn-ghost" style="margin-left:auto;"><i class="bi bi-people"></i>จัดการบุคลากร</a>
  </div>

  <div class="ne-panel" style="margin-bottom:16px"><div class="ne-panel-body">
    <p class="ne-hint" style="margin:0;"><i class="bi bi-info-circle"></i> ตัวเลขทั้งหมดในหน้านี้และหน้า "สถิติบุคลากร" บนเว็บ <b>นับจากข้อมูลบุคลากรโดยอัตโนมัติ</b> เฉพาะคนที่สถานะ "คงอยู่" (ไม่นับคนที่ลาออก และไม่นับกรรมการที่เป็นบุคคลภายนอก) — ถ้าต้องการเปลี่ยนตัวเลข ให้เพิ่ม/แก้ไข/ตั้งสถานะลาออก ในเมนู "บุคลากร"</p>
  </div></div>

  <div class="ne-stat-grid">
    <div class="ne-stat-card"><div class="ne-stat-icon is-blue"><i class="bi bi-people-fill"></i></div>
      <div><div class="ne-stat-num"><?= number_format($grandTotal) ?></div><div class="ne-stat-label"><?= $anyFilter ? 'บุคลากร (ตามตัวกรอง)' : 'บุคลากรรวม (คงอยู่)' ?></div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-gold"><i class="bi bi-briefcase"></i></div>
      <div><div class="ne-stat-num"><?= number_format($cardDepts) ?></div><div class="ne-stat-label">หน่วยงาน</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-green"><i class="bi bi-building"></i></div>
      <div><div class="ne-stat-num"><?= number_format($cardCampuses) ?></div><div class="ne-stat-label">ศูนย์พื้นที่</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-blue"><i class="bi bi-person-badge"></i></div>
      <div><div class="ne-stat-num"><?= number_format($cardTypes) ?></div><div class="ne-stat-label">ประเภทบุคลากร</div></div></div>
  </div>

  <form class="ne-filters" method="GET">
    <div class="ne-filters-field">
      <label for="fCampusFilter">ศูนย์พื้นที่</label>
      <select id="fCampusFilter" class="ne-select" name="campus" onchange="this.form.submit()">
        <option value="">— ทุกศูนย์ —</option>
        <?php foreach ($campuses as $c): ?><option value="<?= $c['campus_id'] ?>" <?= $filterCampus == $c['campus_id'] ? 'selected' : '' ?>><?= e($c['campus_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fDeptFilter">หน่วยงาน</label>
      <select id="fDeptFilter" class="ne-select" name="dept" onchange="this.form.submit()">
        <option value="">— ทุกหน่วยงาน —</option>
        <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>" <?= $filterDept === (int)$d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
        <option value="none" <?= $filterDept === 'none' ? 'selected' : '' ?>>ยังไม่เลือกหน่วยงาน</option>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fTypeFilter">ประเภทบุคลากร</label>
      <select id="fTypeFilter" class="ne-select" name="type" onchange="this.form.submit()">
        <option value="">— ทุกประเภท —</option>
        <?php foreach ($staffTypes as $t): ?><option value="<?= $t['staff_type_id'] ?>" <?= $filterType == $t['staff_type_id'] ? 'selected' : '' ?>><?= e($t['staff_type_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fMajorFilter">สาขาวิชา</label>
      <select id="fMajorFilter" class="ne-select" name="major" onchange="this.form.submit()">
        <option value="">— ทุกสาขาวิชา —</option>
        <option value="none" <?= $filterMajor === 'none' ? 'selected' : '' ?>>ไม่มีสาขาวิชา</option>
        <?php foreach ($majors as $m): ?><option value="<?= $m['major_id'] ?>" <?= $filterMajor === (int)$m['major_id'] ? 'selected' : '' ?>><?= e($m['major_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($anyFilter): ?><div class="ne-filters-actions"><a href="?" class="ne-btn ne-btn-ghost">ล้างตัวกรอง</a></div><?php endif; ?>
  </form>

  <div class="ne-panel" style="margin-bottom:16px">
    <div class="ne-panel-head"><span><i class="bi bi-bar-chart-line" style="color:var(--ne-blue);margin-right:6px;"></i>กราฟสรุป — ข้อมูลปัจจุบัน</span></div>
    <div class="ne-panel-body">
      <?php if ($rows): ?>
      <div class="ne-chart-grid">
        <div><p class="ne-chart-title">ตามศูนย์พื้นที่</p><div class="ne-chart-box" style="height:<?= max(180, 46 * count($byCampus) + 50) ?>px"><canvas id="chCampus"></canvas></div></div>
        <div><p class="ne-chart-title">ตามประเภทบุคลากร (<?= e($scopeLabel) ?>)</p><div class="ne-chart-box"><canvas id="chType"></canvas></div></div>
        <?php if ($byMajor): ?><div><p class="ne-chart-title">ตามสาขาวิชา (<?= e($scopeLabel) ?>)</p><div class="ne-chart-box" style="height:<?= max(180, 46 * count($byMajor) + 50) ?>px"><canvas id="chMajor"></canvas></div></div><?php endif; ?>
      </div>
      <p class="ne-chart-title" style="margin-top:8px">ตามหน่วยงาน (<?= e($scopeLabel) ?>)</p>
      <div class="ne-chart-box" style="height:<?= max(200, 34 * count($byDept) + 60) ?>px"><canvas id="chDept"></canvas></div>
      <?php else: ?>
      <div class="ne-empty"><i class="bi bi-bar-chart"></i><?= $anyFilter ? 'ไม่พบข้อมูลตามตัวกรองที่เลือก' : 'ยังไม่มีข้อมูลบุคลากร' ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($rows): ?>
  <div class="ne-panel">
    <div class="ne-panel-head"><span>รายละเอียดตามศูนย์พื้นที่ (นับจากข้อมูลบุคลากร)</span></div>
    <div class="ne-panel-body" style="overflow-x:auto;">
      <table class="ne-table" style="width:100%;">
        <thead><tr><th>หน่วยงาน · ประเภท · สาขาวิชา</th><th style="text-align:right;white-space:nowrap;">จำนวน (คน)</th></tr></thead>
        <tbody>
        <?php foreach ($grouped as $cid => $g): ?>
          <tr><th colspan="2" style="background:#f3f5f9;"><?= e($g['name']) ?> — รวม <?= number_format(array_sum(array_column($g['rows'], 'total'))) ?> คน</th></tr>
          <?php foreach ($g['rows'] as $r): ?>
          <tr>
            <td><?= e($label('department_id', $r['department_id'], 'ยังไม่เลือกหน่วยงาน')) ?> · <?= e($label('staff_type_id', $r['staff_type_id'], '-')) ?> · <?= e($label('major_id', $r['major_id'], 'ไม่มีสาขาวิชา')) ?></td>
            <td style="text-align:right;"><?= number_format((int)$r['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>รวมทั้งหมด</th><th style="text-align:right;"><?= number_format($grandTotal) ?></th></tr></tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
$vendor = BASE_URL . '/assets/js/vendor';
$ver = fn(string $p) => '?v=' . (@filemtime(BASE_PATH . '/' . $p) ?: time());
$extraJs = '
<script src="' . $vendor . '/chart.umd.min.js' . $ver('assets/js/vendor/chart.umd.min.js') . '"></script>
<script src="' . $vendor . '/chartjs-plugin-datalabels.min.js' . $ver('assets/js/vendor/chartjs-plugin-datalabels.min.js') . '"></script>
<script src="' . BASE_URL . '/assets/js/stats-explorer.js' . $ver('assets/js/stats-explorer.js') . '"></script>
<script>const CH=' . json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';CH.forEach(c=>BaitStats.staticChart(c));</script>';
include BASE_PATH . '/admin/layout/footer.php'; ?>

<?php
/**
 * BAIT Admin - จำนวนนักศึกษา (Student Counts)
 * Only the CURRENT numbers are kept (no year picker): every existing row is listed in one editable grid; the admin
 * types the change (+5 / -3) or the new total, presses "บันทึกทั้งหมด" once, and only rows that really changed are
 * overwritten. A row remembers its previous total so a single row can be undone. Brand-new rows (a new program or
 * campus) are added with the "เพิ่มรายการใหม่" form. See admin/layout/stat_grid.php and assets/js/stat-grid.js.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
require_once BASE_PATH . '/admin/layout/stat_grid.php';
$auth = new Auth(); $auth->requireLogin(); $db = Database::getInstance();
$state = statGridState($db, 'student_counts');
$year = $state['year']; $hasPrev = $state['hasPrev'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $campusId = (int)($_POST['campus_id'] ?? 0); $programId = (int)($_POST['program_id'] ?? 0); $total = (int)($_POST['total'] ?? 0);
        if (!$campusId || !$programId) jsonError('กรุณาเลือกศูนย์และหลักสูตร');
        if (!$db->fetchOne('SELECT 1 FROM campuses WHERE campus_id=?', [$campusId]) || !$db->fetchOne('SELECT 1 FROM programs WHERE program_id=?', [$programId])) jsonError('ไม่พบศูนย์หรือหลักสูตรที่เลือก กรุณาเลือกใหม่');
        if ($total < 0 || $total > 1000000) jsonError('จำนวนต้องเป็นตัวเลขตั้งแต่ 0 ถึง 1,000,000');
        if ($db->fetchOne('SELECT 1 FROM student_counts WHERE campus_id=? AND program_id=? AND year=?', [$campusId, $programId, $year]))
            jsonError('ศูนย์ + หลักสูตรนี้มีอยู่ในตารางแล้ว กรุณาแก้ตัวเลขในตารางได้เลย');
        $db->insert('student_counts', ['campus_id' => $campusId, 'program_id' => $programId, 'total' => $total, 'year' => $year]);
        jsonSuccess([], 'เพิ่มรายการใหม่แล้ว');
    }
    statGridHandle($db, 'student_counts', $state);
    jsonError('คำสั่งไม่ถูกต้อง');
}

$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$majors   = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");
$levels   = $db->fetchAll("SELECT * FROM education_levels ORDER BY level_order");
$programs = $db->fetchAll("SELECT p.*, m.major_name, el.level_abbr FROM programs p LEFT JOIN majors m ON m.major_id=p.major_id JOIN education_levels el ON el.level_id=p.level_id ORDER BY p.is_active DESC, el.level_order, m.major_name");
foreach ($programs as &$p) { $p['major_name'] = $p['major_name'] ?? 'บัณฑิตศึกษา'; } unset($p);

$filterCampus = (int)($_GET['campus'] ?? 0);
$filterLevel  = (int)($_GET['level'] ?? 0);
$filterMajor  = ($_GET['major'] ?? '') === 'none' ? 'none' : (int)($_GET['major'] ?? 0);   // 'none' = บัณฑิตศึกษา (no สาขาวิชา)
$anyFilter = $filterCampus || $filterLevel || $filterMajor;
$where = 'sc.year = ?'; $params = [$year];
if ($filterCampus) { $where .= ' AND sc.campus_id = ?'; $params[] = $filterCampus; }
if ($filterLevel)  { $where .= ' AND p.level_id = ?'; $params[] = $filterLevel; }
if ($filterMajor === 'none') $where .= ' AND p.major_id IS NULL';
elseif ($filterMajor)        { $where .= ' AND p.major_id = ?'; $params[] = $filterMajor; }
$prevCol = $hasPrev ? ', sc.prev_total' : ', NULL AS prev_total';
$counts = $db->fetchAll("SELECT sc.count_id, sc.campus_id, sc.program_id, sc.total$prevCol, c.campus_name, p.program_name, m.major_name, el.level_abbr, el.level_id
    FROM student_counts sc JOIN campuses c ON c.campus_id=sc.campus_id JOIN programs p ON p.program_id=sc.program_id
    LEFT JOIN majors m ON m.major_id=p.major_id JOIN education_levels el ON el.level_id=p.level_id
    WHERE $where ORDER BY c.campus_id, el.level_order, m.major_name, p.program_name", $params);
$grandTotal = array_sum(array_column($counts, 'total'));

function sumBy(array $rows, string $key): array {
    $out = [];
    foreach ($rows as $r) { $out[$r[$key]] = ($out[$r[$key]] ?? 0) + (int)$r['total']; }
    arsort($out);
    return $out;
}
$byCampus  = sumBy($counts, 'campus_name');
$byMajorRows = array_filter($counts, fn($r) => $r['major_name'] !== null);   // บัณฑิตศึกษา (no สาขาวิชา) is not a major
$byLevel   = sumBy($counts, 'level_abbr');
$byMajor   = sumBy($byMajorRows, 'major_name');
// Programs are told apart by id, not by name: two different programs may carry the same name (then the level is added), and must not be merged into one bar.
$progNames = [];
foreach ($counts as $r) $progNames[$r['program_id']] = $r['program_name'];
$dupProgNames = array_keys(array_filter(array_count_values($progNames), fn($c) => $c > 1));
$byProgram = [];
foreach ($counts as $r) {
    $label = in_array($r['program_name'], $dupProgNames, true) ? $r['program_name'] . ' (' . ($r['level_abbr'] ?? '') . ')' : $r['program_name'];
    $byProgram[$label] = ($byProgram[$label] ?? 0) + (int)$r['total'];
}
arsort($byProgram);
// Summary cards (same four as the public page): with no filter they show the whole picture, with a filter what matches it
$cardPrograms = $anyFilter ? count(array_unique(array_column($counts, 'program_id'))) : count($programs);
$cardCampuses = $anyFilter ? count(array_unique(array_column($counts, 'campus_id'))) : count($campuses);
$cardLevels   = count(array_unique(array_column($counts, 'level_id')));
$scopeParts = [];
foreach ($campuses as $c) { if ($c['campus_id'] == $filterCampus) $scopeParts[] = $c['campus_name']; }
foreach ($levels as $lv)  { if ($lv['level_id'] == $filterLevel)  $scopeParts[] = $lv['level_name']; }
if ($filterMajor === 'none') $scopeParts[] = 'บัณฑิตศึกษา';
else foreach ($majors as $m) { if ($m['major_id'] == $filterMajor) $scopeParts[] = $m['major_name']; }
$scopeLabel = $scopeParts ? 'เฉพาะ' . implode(' · ', $scopeParts) : 'ทุกศูนย์พื้นที่';
$updatedLabel = 'ข้อมูลปัจจุบัน' . ($state['updated'] ? ' (อัปเดตล่าสุด ' . thaiDate($state['updated']) . ')' : '');
$grouped = [];
foreach ($counts as $r) { $grouped[$r['campus_id']]['name'] = $r['campus_name']; $grouped[$r['campus_id']]['rows'][] = $r; }

$pageTitle = 'จำนวนนักศึกษา'; $activeMenu = 'student-counts';
$breadcrumb = [['label' => 'จำนวนนักศึกษา']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
$chartData = [
    ['id' => 'chCampus',  'type' => 'bar',      'horizontal' => true, 'unit' => 'คน', 'labels' => array_keys($byCampus),  'data' => array_values($byCampus)],
    ['id' => 'chLevel',   'type' => 'doughnut', 'unit' => 'คน', 'labels' => array_keys(array_filter($byLevel)),   'data' => array_values(array_filter($byLevel))],
    ['id' => 'chMajor',   'type' => 'bar',      'horizontal' => true, 'unit' => 'คน', 'labels' => array_keys($byMajor),   'data' => array_values($byMajor)],
    ['id' => 'chProgram', 'type' => 'bar',      'horizontal' => true, 'unit' => 'คน', 'labels' => array_keys($byProgram), 'data' => array_values($byProgram)],
];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/stat-grid.css?v=<?= @filemtime(BASE_PATH . '/admin/assets/css/stat-grid.css') ?: time() ?>">
<div class="ne">
  <div class="ne-topbar">
    <div>
      <h1>จำนวนนักศึกษา <span class="ne-count"><?= count($counts) ?> รายการ</span></h1>
      <p class="ne-hint" style="margin:2px 0 0"><?= e($updatedLabel) ?></p>
    </div>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openAdd()">
      <i class="bi bi-plus-lg"></i>เพิ่มรายการใหม่
    </button>
  </div>

  <div class="ne-stat-grid">
    <div class="ne-stat-card"><div class="ne-stat-icon is-blue"><i class="bi bi-mortarboard-fill"></i></div>
      <div><div class="ne-stat-num"><?= number_format($grandTotal) ?></div><div class="ne-stat-label"><?= $anyFilter ? 'นักศึกษา (ตามตัวกรอง)' : 'นักศึกษารวม (ปัจจุบัน)' ?></div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-gold"><i class="bi bi-book"></i></div>
      <div><div class="ne-stat-num"><?= number_format($cardPrograms) ?></div><div class="ne-stat-label">หลักสูตร</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-green"><i class="bi bi-building"></i></div>
      <div><div class="ne-stat-num"><?= number_format($cardCampuses) ?></div><div class="ne-stat-label">ศูนย์พื้นที่</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-blue"><i class="bi bi-award"></i></div>
      <div><div class="ne-stat-num"><?= number_format($cardLevels) ?></div><div class="ne-stat-label">ระดับการศึกษา</div></div></div>
  </div>

  <form class="ne-filters" method="GET">
    <div class="ne-filters-field">
      <label for="fCampusFilter">แสดงเฉพาะศูนย์พื้นที่</label>
      <select id="fCampusFilter" class="ne-select" name="campus" onchange="statFilterChange(this)">
        <option value="">— ทุกศูนย์ —</option>
        <?php foreach ($campuses as $c): ?><option value="<?= $c['campus_id'] ?>" <?= $filterCampus == $c['campus_id'] ? 'selected' : '' ?>><?= e($c['campus_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fLevelFilter">ระดับการศึกษา</label>
      <select id="fLevelFilter" class="ne-select" name="level" onchange="statFilterChange(this)">
        <option value="">— ทุกระดับ —</option>
        <?php foreach ($levels as $lv): ?><option value="<?= $lv['level_id'] ?>" <?= $filterLevel == $lv['level_id'] ? 'selected' : '' ?>><?= e($lv['level_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fMajorFilter">สาขาวิชา</label>
      <select id="fMajorFilter" class="ne-select" name="major" onchange="statFilterChange(this)">
        <option value="">— ทุกสาขาวิชา —</option>
        <option value="none" <?= $filterMajor === 'none' ? 'selected' : '' ?>>บัณฑิตศึกษา (ไม่มีสาขาวิชา)</option>
        <?php foreach ($majors as $m): ?><option value="<?= $m['major_id'] ?>" <?= $filterMajor === (int)$m['major_id'] ? 'selected' : '' ?>><?= e($m['major_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($anyFilter): ?><div class="ne-filters-actions"><a href="?" class="ne-btn ne-btn-ghost">ล้างตัวกรอง</a></div><?php endif; ?>
  </form>

  <div class="ne-panel" style="margin-bottom:16px">
    <div class="ne-panel-head"><span><i class="bi bi-bar-chart-line" style="color:var(--ne-blue);margin-right:6px;"></i>กราฟสรุป — ข้อมูลปัจจุบัน</span></div>
    <div class="ne-panel-body">
      <?php if ($counts): ?>
      <div class="ne-chart-grid">
        <div><p class="ne-chart-title">ตามศูนย์พื้นที่</p><div class="ne-chart-box" style="height:<?= max(180, 46 * count($byCampus) + 50) ?>px"><canvas id="chCampus"></canvas></div></div>
        <div><p class="ne-chart-title">ตามระดับการศึกษา (<?= e($scopeLabel) ?>)</p><div class="ne-chart-box"><canvas id="chLevel"></canvas></div></div>
        <?php if ($byMajor): ?><div><p class="ne-chart-title">ตามสาขาวิชา (<?= e($scopeLabel) ?>)</p><div class="ne-chart-box" style="height:<?= max(180, 46 * count($byMajor) + 50) ?>px"><canvas id="chMajor"></canvas></div></div><?php endif; ?>
      </div>
      <p class="ne-chart-title" style="margin-top:8px">ตามหลักสูตร (<?= e($scopeLabel) ?>)</p>
      <div class="ne-chart-box" style="height:<?= max(200, 34 * count($byProgram) + 60) ?>px"><canvas id="chProgram"></canvas></div>
      <?php else: ?>
      <div class="ne-empty"><i class="bi bi-bar-chart"></i>ยังไม่มีข้อมูล</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="ne-panel" data-statgrid data-csrf="<?= e(csrfToken()) ?>">
    <div class="ne-panel-head"><span>แก้ไขจำนวนนักศึกษา — <?= e($updatedLabel) ?></span></div>
    <div class="ne-panel-body">
      <?php if ($counts): ?>
      <p class="statgrid-help"><i class="bi bi-info-circle"></i> ขึ้นปีการศึกษาใหม่: พิมพ์ <b>จำนวนที่เพิ่มหรือลด</b> (เช่น <b>+5</b> หรือ <b>-3</b>) ในช่อง "เพิ่ม/ลด" หรือพิมพ์ <b>จำนวนรวมใหม่</b> ในช่อง "ใหม่" ระบบคำนวณให้ แถวที่ไม่ได้แตะจะคงเดิม แล้วกด "บันทึกทั้งหมด" ครั้งเดียว</p>
      <table class="statgrid-table">
        <thead><tr><th>ศูนย์พื้นที่ / หลักสูตร</th><th class="num">ตอนนี้ (คน)</th><th>เพิ่ม / ลด</th><th>ใหม่ (คน)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($grouped as $cid => $g): $gTotal = array_sum(array_column($g['rows'], 'total')); ?>
          <tr data-group-row="<?= (int)$cid ?>"><th colspan="5"><?= e($g['name']) ?> — รวม <span data-group-total><?= number_format($gTotal) ?></span><span data-group-diff></span> <small>คน</small></th></tr>
          <?php foreach ($g['rows'] as $r):
            $label = $r['program_name'];
            $hasUndo = $hasPrev && $r['prev_total'] !== null && (int)$r['prev_total'] !== (int)$r['total']; ?>
          <tr data-row data-id="<?= (int)$r['count_id'] ?>" data-old="<?= (int)$r['total'] ?>" data-group="<?= (int)$cid ?>" data-name="<?= e($g['name'] . ' · ' . $label) ?>">
            <td class="sg-name"><?= e($label) ?><small>[<?= e($r['level_abbr'] ?? '') ?>] · <?= e($r['major_name'] ?? 'บัณฑิตศึกษา') ?></small></td>
            <td class="sg-now"><label>ตอนนี้</label><b><?= number_format($r['total']) ?></b></td>
            <td class="sg-delta"><label>เพิ่ม / ลด</label><input type="number" step="1" inputmode="numeric" data-delta placeholder="+ / −" aria-label="เพิ่มหรือลด: <?= e($label) ?>"></td>
            <td class="sg-new"><label>ใหม่ (รวม)</label><input type="number" min="0" step="1" inputmode="numeric" data-new value="<?= (int)$r['total'] ?>" aria-label="จำนวนใหม่: <?= e($label) ?>"></td>
            <td class="sg-actions">
              <?php if ($hasUndo): ?><button type="button" class="sg-undo" data-undo data-prev="<?= (int)$r['prev_total'] ?>" title="ย้อนกลับเป็น <?= number_format((int)$r['prev_total']) ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= number_format((int)$r['prev_total']) ?></button><?php endif; ?>
              <button type="button" class="sg-del" data-del title="ลบแถวนี้ (เมื่อไม่มีรายการนี้แล้ว)" aria-label="ลบ <?= e($label) ?>"><i class="bi bi-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="statgrid-pager" data-pager hidden></div>
      <div class="statgrid-grand"><span>รวมทั้งหมด</span><span><span data-grand><?= number_format($grandTotal) ?></span><span data-grand-diff></span> คน</span></div>
      <div class="statgrid-savebar" data-savebar hidden>
        <span data-summary></span>
        <button type="button" class="ne-btn ne-btn-ghost" data-discard style="color:#fff">ยกเลิกการแก้ไข</button>
        <button type="button" class="ne-btn ne-btn-primary" data-save><i class="bi bi-check-lg"></i> บันทึกทั้งหมด</button>
      </div>
      <?php else: ?>
      <div class="ne-empty"><i class="bi bi-mortarboard"></i><?= $anyFilter ? 'ไม่พบข้อมูลตามตัวกรองที่เลือก' : 'ยังไม่มีข้อมูล — กด "เพิ่มรายการใหม่" เพื่อเริ่มกรอก' ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header">
    <h5 class="modal-title" style="font-family:'Kanit',serif;">เพิ่มรายการใหม่ (ศูนย์ + หลักสูตรที่ยังไม่เคยมีข้อมูล)</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body" style="padding:20px;">
    <div class="ne-field"><label for="fCampus">ศูนย์พื้นที่ <span style="color:var(--ne-danger)">*</span></label>
      <select class="ne-select" id="fCampus"><option value="">— เลือก —</option><?php foreach ($campuses as $c): ?><option value="<?= $c['campus_id'] ?>"><?= e($c['campus_name']) ?></option><?php endforeach; ?></select></div>
    <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;flex-wrap:wrap;">
      <div class="ne-meta-field"><label for="fLevel">ระดับการศึกษา</label>
        <select class="ne-select" id="fLevel"><option value="">— ทุกระดับ —</option><?php foreach ($levels as $lv): ?><option value="<?= $lv['level_id'] ?>"><?= e($lv['level_name']) ?></option><?php endforeach; ?></select></div>
      <div class="ne-meta-field"><label for="fMajor">สาขาวิชา</label>
        <select class="ne-select" id="fMajor"><option value="">— ทุกสาขาวิชา —</option><option value="none">บัณฑิตศึกษา (ไม่มีสาขาวิชา)</option><?php foreach ($majors as $m): ?><option value="<?= $m['major_id'] ?>"><?= e($m['major_name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="ne-hint" style="margin-top:12px;margin-bottom:14px;">เลือกระดับ/สาขาก่อน เพื่อกรองรายการหลักสูตรด้านล่างให้สั้นลง</p>
    <div class="ne-field"><label for="fProgram">หลักสูตร <span style="color:var(--ne-danger)">*</span></label>
      <select class="ne-select" id="fProgram"><option value="">— เลือกหลักสูตร —</option><?php foreach ($programs as $p): ?><option value="<?= $p['program_id'] ?>" data-major="<?= $p['major_id'] ?>" data-level="<?= $p['level_id'] ?>">[<?= e($p['level_abbr'] ?? '') ?>] <?= e($p['program_name']) ?> (<?= e($p['major_name'] ?? '') ?>)<?= (int)$p['is_active'] !== 1 ? ' — ปิดรับสมัคร' : '' ?></option><?php endforeach; ?></select></div>
    <div class="ne-field" style="margin-bottom:0;"><label for="fTotal">จำนวนนักศึกษา (คน)</label><input type="number" class="ne-input" id="fTotal" value="0" min="0" step="1"></div>
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" onclick="addRow()"><i class="bi bi-check-lg"></i>เพิ่ม</button>
  </div>
</div></div></div>

<?php
$vendor = BASE_URL . '/assets/js/vendor';
$ver = fn(string $p) => '?v=' . (@filemtime(BASE_PATH . '/' . $p) ?: time());
$extraJs = '<script>
const csrf=' . json_encode(csrfToken()) . ';let mi;
function statFilterChange(el){if(window.__statGridDirty&&!confirm("มีการแก้ไขที่ยังไม่บันทึก ต้องการออกจากหน้านี้?")){el.value=el.dataset.orig;return;}el.form.submit();}
document.querySelectorAll(".ne-filters select").forEach(s=>s.dataset.orig=s.value);
function filterPrograms(){const majorId=document.getElementById("fMajor").value;const levelId=document.getElementById("fLevel").value;const sel=document.getElementById("fProgram");let stillVisible=false;Array.from(sel.options).forEach(opt=>{if(!opt.value)return;const majorMatch=!majorId||(majorId==="none"?opt.dataset.major==="":opt.dataset.major===majorId);const match=majorMatch&&(!levelId||opt.dataset.level===levelId);opt.hidden=!match;if(match&&opt.value===sel.value)stillVisible=true;});if(!stillVisible)sel.value="";}
document.getElementById("fMajor").addEventListener("change",filterPrograms);
document.getElementById("fLevel").addEventListener("change",filterPrograms);
function openAdd(){["fCampus","fLevel","fMajor","fProgram"].forEach(id=>document.getElementById(id).value="");document.getElementById("fTotal").value=0;filterPrograms();mi=new bootstrap.Modal(document.getElementById("modal"));mi.show();}
async function addRow(){const fd=new FormData();fd.append("action","add");fd.append("campus_id",document.getElementById("fCampus").value);fd.append("program_id",document.getElementById("fProgram").value);fd.append("total",document.getElementById("fTotal").value);fd.append("csrf_token",csrf);const r=await axios.post("",fd);if(r.data.status==="success"){mi.hide();showSuccess(r.data.message);setTimeout(()=>location.reload(),1000);}else showError(r.data.message);}
</script>
<script src="' . $vendor . '/chart.umd.min.js' . $ver('assets/js/vendor/chart.umd.min.js') . '"></script>
<script src="' . $vendor . '/chartjs-plugin-datalabels.min.js' . $ver('assets/js/vendor/chartjs-plugin-datalabels.min.js') . '"></script>
<script src="' . BASE_URL . '/assets/js/stats-explorer.js' . $ver('assets/js/stats-explorer.js') . '"></script>
<script src="' . BASE_URL . '/admin/assets/js/stat-grid.js' . $ver('admin/assets/js/stat-grid.js') . '"></script>
<script>const CH=' . json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';CH.forEach(c=>BaitStats.staticChart(c));</script>';
include BASE_PATH . '/admin/layout/footer.php'; ?>

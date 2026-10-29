<?php
/**
 * BAIT Admin - Programs Management
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();   // delete is a POST with the CSRF token from the confirm dialog, never a plain link
    $id = (int)($_POST['id'] ?? 0);
    $p  = $db->fetchOne("SELECT * FROM programs WHERE program_id = ?", [$id]);
    if ($p) {
        fileQueueDelete($p['file']); fileQueueDelete($p['image']);   // the PDF and the cover image, deleted below once the row is really gone
        // one transaction: if the programme itself cannot be deleted, its campuses and student counts are not lost either
        $db->beginTransaction();
        $db->delete('program_campuses','program_id = ?',[$id]);
        // student_counts.program_id has a foreign key back to this table with
        // no ON DELETE clause (defaults to RESTRICT) — deleting a program
        // that still has recorded student counts throws an uncaught
        // PDOException (fatal error / HTTP 500) unless those rows go first.
        $db->delete('student_counts','program_id = ?',[$id]);
        $feeRows = hasTuition() ? (int)($db->fetchOne('SELECT COUNT(*) AS c FROM tuition_fees WHERE program_id = ?', [$id])['c'] ?? 0) : 0;   // removed with it (ON DELETE CASCADE)
        $db->delete('programs','program_id = ?',[$id]);
        $db->commit();
        fileCommitChanges();
        $_SESSION['flash_success'] = 'ลบหลักสูตรเรียบร้อยแล้ว' . ($feeRows ? ' (ลบค่าเทอมของหลักสูตรนี้ ' . $feeRows . ' แถวไปด้วย)' : '');
    }
    header('Location: index.php'); exit;
}

$levelId  = (int)($_GET['level'] ?? 0);
$campusId = (int)($_GET['campus'] ?? 0);
$page     = max(1,(int)($_GET['page']??1));
$perPage  = 20;

// Programs whose PDF is recorded in the database but missing from the server (visitors get no download button for these).
$missingDocIds = [];
foreach ($db->fetchAll("SELECT program_id, file FROM programs WHERE file IS NOT NULL AND file <> ''") as $dp) {
    if (!is_file(UPLOAD_PATH . '/' . $dp['file'])) $missingDocIds[] = (int)$dp['program_id'];
}
$onlyMissingDocs = ($_GET['docs'] ?? '') === 'missing';

$where  = '1=1'; $params = [];
if ($onlyMissingDocs) $where .= ' AND p.program_id IN (' . ($missingDocIds ? implode(',', $missingDocIds) : '0') . ')';
if ($levelId)  { $where .= ' AND p.level_id = ?';  $params[] = $levelId; }
if ($campusId) {
    // Match programs explicitly assigned to this campus, OR programs with no
    // campus restriction at all (meaning: open to every campus).
    $where .= ' AND (
        EXISTS (SELECT 1 FROM program_campuses pc WHERE pc.program_id = p.program_id AND pc.campus_id = ?)
        OR NOT EXISTS (SELECT 1 FROM program_campuses pc2 WHERE pc2.program_id = p.program_id)
    )';
    $params[] = $campusId;
}

$total = (int)($db->fetchOne("SELECT COUNT(*) as c FROM programs p WHERE $where",$params)['c']??0);
$pager = paginate($total,$perPage,$page,'?level='.$levelId.'&campus='.$campusId.($onlyMissingDocs ? '&docs=missing' : '').'&page={page}');

$programs = $db->fetchAll(
    "SELECT p.*, m.major_name, el.level_name,
            GROUP_CONCAT(DISTINCT c.campus_name ORDER BY c.campus_name SEPARATOR ', ') AS campus_names,
            (SELECT COUNT(*) FROM student_counts sc WHERE sc.program_id = p.program_id) AS student_count_rows,
            " . (hasTuition() ? '(SELECT COUNT(*) FROM tuition_fees tf WHERE tf.program_id = p.program_id)' : '0') . " AS tuition_rows
     FROM programs p
     LEFT JOIN majors m ON m.major_id = p.major_id
     JOIN education_levels el ON el.level_id = p.level_id
     LEFT JOIN program_campuses pc ON pc.program_id = p.program_id
     LEFT JOIN campuses c ON c.campus_id = pc.campus_id
     WHERE $where
     GROUP BY p.program_id
     ORDER BY el.level_order, m.major_name
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);

$levels   = $db->fetchAll("SELECT * FROM education_levels ORDER BY level_order");
$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");

$pageTitle   = 'จัดการหลักสูตร';
$activeMenu  = 'programs-list';
$breadcrumb  = [['label' => 'จัดการหลักสูตร']];
$pageActions = ''; // ปุ่มเพิ่มหลักสูตรย้ายไปอยู่ใน topbar ที่ออกแบบใหม่ด้านล่างแทน (เหมือนหน้าข่าวสาร)
include BASE_PATH . '/admin/layout/header.php';
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
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 22px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--ne-line);
}
.ne-topbar h1 {
  font-family: 'Kanit', sans-serif;
  font-size: 26px;
  font-weight: 700;
  margin: 0;
  color: var(--ne-ink);
  display: flex;
  align-items: baseline;
  gap: 10px;
}
.ne-topbar h1 .ne-count {
  font-family: 'Kanit', sans-serif;
  font-size: 14px;
  font-weight: 600;
  color: var(--ne-gold);
  background: var(--ne-gold-soft);
  border-radius: 999px;
  padding: 3px 11px;
}
.ne-btn {
  font-family: 'Kanit', sans-serif;
  font-weight: 600;
  font-size: 14.5px;
  border-radius: 9px;
  padding: 10px 18px;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
  text-decoration: none;
  transition: all .15s ease;
  line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }

.ne-input, .ne-select {
  width: 100%;
  border: 1px solid var(--ne-line);
  border-radius: 8px;
  padding: 9px 12px;
  font-family: 'Kanit', sans-serif;
  font-size: 14px;
  color: var(--ne-ink);
  background: var(--ne-surface);
  outline: none;
  transition: border-color .15s ease;
}
.ne-input:focus, .ne-select:focus { border-color: var(--ne-blue); }
.ne-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%235b6480' stroke-width='1.6' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }

/* ── Table panel ──────────────────────────── */
.ne-panel {
  background: var(--ne-surface);
  border: 1px solid var(--ne-line);
  border-radius: 14px;
  overflow: hidden;
}
.ne-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line);
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ne-ink-soft);
}
.ne-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.ne-table thead th {
  text-align: left;
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--ne-slate);
  background: var(--ne-paper);
  padding: 10px 16px;
  border-bottom: 1px solid var(--ne-line);
}
.ne-table tbody td { padding: 12px 16px; border-bottom: 1px solid var(--ne-line); vertical-align: middle; }
.ne-table tbody tr:last-child td { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-num { color: var(--ne-slate); font-size: 13px; }
.ne-title-link { color: var(--ne-ink); font-weight: 600; text-decoration: none; }
.ne-title-link:hover { color: var(--ne-blue); }
.ne-byline { font-size: 12px; color: var(--ne-slate); margin-top: 2px; }
/* Name column: wraps (max 2 lines, then "…") so the status and the edit/delete buttons always stay on screen */
.ne-table td.ne-name-cell { white-space: normal; min-width: 180px; }
.ne-table .ne-title-link { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.45; }
.ne-table td:last-child { white-space: nowrap; }
.ne-table td { white-space: normal; }
.ne-table .ne-badge, .ne-table .ne-chip { white-space: nowrap; }
/* mobile-first: secondary columns appear only when there is room; below that they move under the name */
.ne-hide-md, .ne-hide-lg { display: none; }
.ne-meta-small { display: block; }
.ne-meta-major, .ne-meta-level { display: inline; }
.ne-meta-status { font-size: 12.5px; font-weight: 600; margin-top: 2px; color: var(--ne-ink-soft); }
@media (min-width: 768px) { th.ne-hide-md, td.ne-hide-md { display: table-cell; } .ne-meta-level, .ne-meta-status { display: none; } }
@media (min-width: 1200px) { th.ne-hide-lg, td.ne-hide-lg { display: table-cell; } .ne-meta-major { display: none; } .ne-meta-small { display: none; } }

.ne-chip {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--ne-blue-soft);
  color: var(--ne-blue-deep);
}

.ne-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; padding: 4px 11px; border-radius: 999px; }
.ne-badge-published { background: var(--ne-green-soft); color: #1f6b47; }
.ne-badge-draft { background: var(--ne-danger-soft); color: var(--ne-danger); }

.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn {
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line); background: var(--ne-surface);
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px;
}
.ne-icon-btn:hover { background: var(--ne-paper) !important; color: var(--ne-ink) !important; border-color: var(--ne-ink) !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-icon-btn.ne-icon-delete { color: var(--ne-danger) !important; border-color: #f0c9c6 !important; background: var(--ne-danger-soft) !important; }
.ne-icon-btn.ne-icon-delete:hover { background: var(--ne-danger) !important; border-color: var(--ne-danger) !important; color: #fff !important; }
.ne-icon-btn i { color: inherit !important; }

.ne-empty { text-align: center; padding: 64px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 34px; display: block; margin-bottom: 10px; color: var(--ne-line); }

.ne-footer {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
  padding: 14px 20px; border-top: 1px solid var(--ne-line); font-size: 13px; color: var(--ne-slate);
}
.ne-footer .pagination { margin: 0; }
.ne-footer .page-link {
  border: 1px solid var(--ne-line); color: var(--ne-ink-soft); font-size: 13px;
  border-radius: 7px !important; margin-left: 4px; padding: 5px 11px;
}
.ne-footer .page-item.active .page-link { background: var(--ne-blue); border-color: var(--ne-blue); color: #fff; }
.ne-footer .page-item.disabled .page-link { color: var(--ne-ink-soft); background: var(--ne-paper); }
</style>

<div class="ne">
  <div class="ne-topbar">
    <div style="min-width:0;flex:1 1 auto;">
      <h1>หลักสูตรทั้งหมด <span class="ne-count"><?= number_format($total) ?> รายการ</span></h1>
    </div>
    <a href="<?= BASE_URL ?>/admin/modules/programs/create.php" class="ne-btn ne-btn-primary" style="margin-left:auto;">
      <i class="bi bi-plus-lg"></i>เพิ่มหลักสูตร
    </a>
  </div>

  <!-- Filters -->
  <form class="ne-filters" method="GET">
    <div class="ne-filters-field">
      <label for="fLevel">ระดับการศึกษา</label>
      <select id="fLevel" class="ne-select" name="level">
        <option value="">-- ทุกระดับ --</option>
        <?php foreach ($levels as $l): ?>
        <option value="<?= $l['level_id'] ?>" <?= $levelId==$l['level_id']?'selected':'' ?>><?= e($l['level_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fCampus">ศูนย์พื้นที่</label>
      <select id="fCampus" class="ne-select" name="campus">
        <option value="">-- ทุกศูนย์ --</option>
        <?php foreach ($campuses as $c): ?>
        <option value="<?= $c['campus_id'] ?>" <?= $campusId==$c['campus_id']?'selected':'' ?>><?= e($c['campus_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>กรอง</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <?php if ($missingDocIds): ?>
  <div class="ne-alert ne-alert-warn" role="alert" style="margin-bottom:16px">
    <i class="bi bi-exclamation-triangle"></i>
    <div>
      <strong>ไฟล์เอกสารหลักสูตร (PDF) หาย <?= count($missingDocIds) ?> หลักสูตร</strong>
      ระบบบันทึกชื่อไฟล์ไว้แต่ไม่พบไฟล์บนเซิร์ฟเวอร์ ผู้เข้าชมจึงไม่เห็นปุ่มดาวน์โหลดของหลักสูตรเหล่านี้ กรุณาอัปโหลดไฟล์ใหม่ที่ปุ่มแก้ไข (ไอคอนดินสอ) ของแต่ละหลักสูตร
      <?php if ($onlyMissingDocs): ?>
      <a href="?" class="ne-link-strong">แสดงหลักสูตรทั้งหมด</a>
      <?php else: ?>
      <a href="?docs=missing" class="ne-link-strong">แสดงเฉพาะหลักสูตรที่ไฟล์หาย</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Table -->
  <div class="ne-panel">
    <div class="ne-panel-head">
      <span>หลักสูตรทั้งหมด</span>
      <span>หน้า <?= $pager['current_page'] ?> / <?= max(1, $pager['total_pages']) ?></span>
    </div>

    <?php if (!empty($programs)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th class="ne-hide-md" style="width:44px">#</th>
            <th>ชื่อหลักสูตร</th>
            <th class="ne-hide-lg" style="width:170px">สาขาวิชา</th>
            <th class="ne-hide-md" style="width:110px">ระดับ</th>
            <th class="ne-hide-md" style="width:110px">สถานะ</th>
            <th style="width:100px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($programs as $i => $p): ?>
          <tr>
            <td class="ne-num ne-hide-md"><?= $pager['offset'] + $i + 1 ?></td>
            <td class="ne-name-cell">
              <a href="view.php?id=<?= $p['program_id'] ?>" class="ne-title-link" title="<?= e($p['program_name']) ?>"><?= e($p['program_name']) ?></a>
              <?php if ($p['program_abbr']): ?><div class="ne-byline"><?= e($p['program_abbr']) ?></div><?php endif; ?>
              <div class="ne-byline ne-meta-small"><span class="ne-meta-major"><?= $p['major_name'] ? e($p['major_name']) : 'บัณฑิตศึกษา' ?></span><span class="ne-meta-level"> · <?= e($p['level_name']) ?></span></div>
              <div class="ne-meta-status"><?= $p['is_active'] ? '🟢 เปิดรับ' : '🔴 ปิดรับ' ?></div>
              <?php if (in_array((int)$p['program_id'], $missingDocIds, true)): ?>
              <a href="create.php?id=<?= (int)$p['program_id'] ?>" class="ne-doc-warn" title="ไม่พบไฟล์ PDF บนเซิร์ฟเวอร์ — คลิกเพื่ออัปโหลดใหม่"><i class="bi bi-exclamation-triangle-fill"></i> ไฟล์ PDF หาย อัปโหลดใหม่</a>
              <?php endif; ?>
            </td>
            <td class="ne-hide-lg"><small><?= $p['major_name'] ? e($p['major_name']) : '<span class="text-muted">บัณฑิตศึกษา</span>' ?></small></td>
            <td class="ne-hide-md"><span class="ne-chip"><?= e($p['level_name']) ?></span></td>
            <td class="ne-hide-md">
              <?php if ($p['is_active']): ?>
              <span class="ne-badge ne-badge-published">🟢 เปิดรับ</span>
              <?php else: ?>
              <span class="ne-badge ne-badge-draft">🔴 ปิดรับ</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="ne-actions">
                <a href="create.php?id=<?= $p['program_id'] ?>" class="ne-icon-btn ne-icon-edit" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </a>
                <button class="ne-icon-btn ne-icon-delete" title="ลบ"
                  onclick="confirmDeleteProgram('?action=delete&id=<?= $p['program_id'] ?>',
                  '<?= e(addslashes($p['program_name'])) ?>', <?= (int)$p['student_count_rows'] ?>, <?= (int)$p['tuition_rows'] ?>)">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pager['total_pages'] > 1): ?>
    <div class="ne-footer">
      <span>แสดง <?= $pager['offset'] + 1 ?>–<?= min($pager['offset'] + $perPage, $total) ?> จาก <?= number_format($total) ?> รายการ</span>
      <?= paginationHtml($pager) ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="ne-empty">
      <i class="bi bi-book"></i>
      ไม่พบหลักสูตร
      <div class="mt-2">
        <a href="<?= BASE_URL ?>/admin/modules/programs/create.php" class="ne-btn ne-btn-primary" style="display:inline-flex;margin-top:10px;">
          <i class="bi bi-plus-lg"></i>เพิ่มหลักสูตรแรกของคุณ
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
// Separate from the shared confirmDelete() in footer.php — deleting a program
// also silently wipes its student_counts rows (real recorded enrollment
// numbers, not junk) with no FK block, so this warns about that data loss
// specifically instead of the generic "cannot be undone" note.
function confirmDeleteProgram(url, name, studentCountRows, tuitionRows) {
  const lost = [];
  if (studentCountRows > 0) lost.push(`ข้อมูลจำนวนนักศึกษาที่บันทึกไว้ ${studentCountRows} รายการ`);
  if (tuitionRows > 0) lost.push(`ค่าเทอม (หลักสูตร &gt; ค่าเทอม) ${tuitionRows} แถว`);
  const extra = lost.length
    ? `<br><br><span class="text-danger">คำเตือน: หลักสูตรนี้มี${lost.join(' และ')} ข้อมูลเหล่านี้จะถูกลบไปพร้อมกันด้วย</span>`
    : '';
  Swal.fire({
    title: 'ยืนยันการลบ?',
    html: `ต้องการลบ <strong>${escapeHtml(name)}</strong> ใช่หรือไม่?<br><small class="text-muted">การดำเนินการนี้ไม่สามารถย้อนกลับได้</small>${extra}`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#c1443d',
    cancelButtonColor: '#8b93a7',
    confirmButtonText: '<i class="bi bi-trash me-1"></i>ลบ',
    cancelButtonText: 'ยกเลิก',
    reverseButtons: true
  }).then((result) => {
    if (result.isConfirmed) submitPostForm(url);
  });
}
</script>

<?php include BASE_PATH . '/admin/layout/footer.php'; ?>
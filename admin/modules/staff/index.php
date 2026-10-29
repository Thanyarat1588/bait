<?php
/**
 * BAIT Admin - Staff Management (Index)
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$search    = getStr('search');
$campusId  = (int)($_GET['campus']  ?? 0);
$typeId    = (int)($_GET['type']    ?? 0);
$majorId   = (int)($_GET['major']   ?? 0);
$enPending = ($_GET['en'] ?? '') === 'pending';
$deptF     = getStr('dept') === 'none' ? 'none' : (int)getStr('dept');   // หน่วยงาน (สายวิชาการ / สายสนับสนุน); 'none' = not chosen yet
$statusF   = in_array(getStr('status'), ['resigned', 'all'], true) ? getStr('status') : 'active';   // สถานะบุคลากร (default: คงอยู่)   // only people whose English name is not confirmed yet
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;

if (!hasStaffStatus()) $statusF = 'active';
$where  = $statusF === 'active' ? staffCurrentSql('s') : ($statusF === 'resigned' ? "s.is_active = 1 AND s.work_status = 'resigned'" : 's.is_active = 1');
$params = [];
if ($search) {
    $where   .= ' AND (s.full_name LIKE ? OR s.email LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($campusId) { $where .= ' AND s.campus_id = ?'; $params[] = $campusId; }
if ($typeId)   { $where .= ' AND s.staff_type_id = ?'; $params[] = $typeId; }
if ($majorId)  { $where .= ' AND s.major_id = ?'; $params[] = $majorId; }
if ($enPending) { $where .= ' AND s.name_en_verified = 0'; }
if ($deptF === 'none') $where .= ' AND s.department_id IS NULL';
elseif ($deptF)        { $where .= ' AND s.department_id = ?'; $params[] = $deptF; }
$enPendingCount = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM staff s WHERE ' . staffCurrentSql('s') . ' AND s.name_en_verified = 0')['c'];   // same people the default list shows

$total = (int)($db->fetchOne(
    "SELECT COUNT(*) as c FROM staff s WHERE $where", $params
)['c'] ?? 0);
$pager = paginate($total, $perPage, $page, '?search=' . urlencode($search) . '&campus=' . $campusId . '&type=' . $typeId . '&major=' . $majorId . '&dept=' . urlencode((string)$deptF) . '&status=' . $statusF . ($enPending ? '&en=pending' : '') . '&page={page}');

$staffList = $db->fetchAll(
    "SELECT s.*, p.position_name, c.campus_name, m.major_name, st.staff_type_name, d.department_name
     FROM staff s
     JOIN positions p ON p.position_id = s.position_id
     LEFT JOIN campuses c ON c.campus_id = s.campus_id
     LEFT JOIN majors m ON m.major_id = s.major_id
     JOIN staff_types st ON st.staff_type_id = s.staff_type_id
     LEFT JOIN departments d ON d.department_id = s.department_id
     WHERE $where
     ORDER BY s.display_order ASC, p.position_level DESC, s.full_name ASC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);

$campuses   = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$staffTypes = $db->fetchAll("SELECT * FROM staff_types ORDER BY staff_type_id");
$majors     = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");
$departments = $db->fetchAll("SELECT * FROM departments ORDER BY department_id");
$noDeptCount = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM staff s WHERE ' . staffCurrentSql('s') . ' AND s.department_id IS NULL')['c'] ?? 0);

$pageTitle   = 'จัดการบุคลากร';
$activeMenu  = 'staff';
$breadcrumb  = [['label' => 'จัดการบุคลากร']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>บุคลากรทั้งหมด <span class="ne-count"><?= number_format($total) ?> คน</span></h1>
    <a href="<?= BASE_URL ?>/admin/modules/staff/create.php" class="ne-btn ne-btn-primary" style="margin-left:auto;">
      <i class="bi bi-plus-lg"></i>เพิ่มบุคลากร
    </a>
  </div>

  <!-- Filters -->
  <form class="ne-filters" method="GET">
    <div class="ne-filters-field ne-search">
      <label for="staffSearch">ค้นหา</label>
      <input type="text" class="ne-input" id="staffSearch" name="search" placeholder="ค้นหาชื่อบุคลากร..." value="<?= e($search) ?>">
    </div>
    <div class="ne-filters-field">
      <label for="staffCampus">ศูนย์พื้นที่</label>
      <select class="ne-select" id="staffCampus" name="campus">
        <option value="">-- ทุกศูนย์ --</option>
        <?php foreach ($campuses as $c): ?>
        <option value="<?= $c['campus_id'] ?>" <?= $campusId == $c['campus_id'] ? 'selected' : '' ?>><?= e($c['campus_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="staffType">ประเภท</label>
      <select class="ne-select" id="staffType" name="type">
        <option value="">-- ทุกประเภท --</option>
        <?php foreach ($staffTypes as $t): ?>
        <option value="<?= $t['staff_type_id'] ?>" <?= $typeId == $t['staff_type_id'] ? 'selected' : '' ?>><?= e($t['staff_type_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="staffMajor">สาขาวิชา</label>
      <select class="ne-select" id="staffMajor" name="major">
        <option value="">-- ทุกสาขา --</option>
        <?php foreach ($majors as $m): ?>
        <option value="<?= $m['major_id'] ?>" <?= $majorId == $m['major_id'] ? 'selected' : '' ?>><?= e($m['major_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="staffDept">หน่วยงาน</label>
      <select class="ne-select" id="staffDept" name="dept">
        <option value="">-- ทุกหน่วยงาน --</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= $d['department_id'] ?>" <?= $deptF === (int)$d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
        <?php endforeach; ?>
        <option value="none" <?= $deptF === 'none' ? 'selected' : '' ?>>ยังไม่เลือกหน่วยงาน<?= $noDeptCount ? ' (' . $noDeptCount . ' คน)' : '' ?></option>
      </select>
    </div>
    <?php if (hasStaffStatus()): ?>
    <div class="ne-filters-field">
      <label for="staffStatus">สถานะ</label>
      <select class="ne-select" id="staffStatus" name="status">
        <option value="active" <?= $statusF === 'active' ? 'selected' : '' ?>>คงอยู่</option>
        <option value="resigned" <?= $statusF === 'resigned' ? 'selected' : '' ?>>ลาออก</option>
        <option value="all" <?= $statusF === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary">ค้นหา</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <?php if ($enPendingCount > 0 || $enPending): ?>
  <div style="margin:0 0 12px;font-size:.88rem;">
    <?php if ($enPending): ?>
    กำลังแสดงเฉพาะคนที่ชื่ออังกฤษ <b>รอตรวจ</b> (<?= (int)$total ?> คน) · <a href="?">ดูทั้งหมด</a>
    <?php else: ?>
    <a href="?en=pending" class="ne-btn ne-btn-ghost" style="padding:4px 12px;">ชื่ออังกฤษรอตรวจ <?= $enPendingCount ?> คน</a>
    <span style="color:var(--ne-slate);">ระบบเดาให้ ต้องตรวจแล้วกดยืนยันในหน้าแก้ไขก่อน จึงจะแสดงในหน้าเว็บ EN</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Table -->
  <div class="ne-panel">
    <div class="ne-panel-head">
      <span>บุคลากรทั้งหมด</span>
      <span class="ne-hint" style="margin:0;">หน้า <?= $pager['current_page'] ?>/<?= max(1,$pager['total_pages']) ?></span>
    </div>
    <?php if (!empty($staffList)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th style="width:55px">ภาพ</th>
            <th>ชื่อ-นามสกุล</th>
            <th>ตำแหน่ง</th>
            <th>ศูนย์พื้นที่</th>
            <th>ประเภท</th>
            <th style="width:100px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffList as $i => $s): ?>
          <tr>
            <td class="ne-num"><?= $pager['offset'] + $i + 1 ?></td>
            <td>
              <?php if ($s['image']): ?>
              <img src="<?= UPLOAD_URL . '/' . e($s['image']) ?>" alt=""
                   style="width:40px;height:40px;object-fit:cover;border-radius:50%;border:2px solid var(--ne-line);">
              <?php else: ?>
              <div style="width:40px;height:40px;background:var(--ne-blue);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-size:0.9rem;font-weight:700">
                <?= mb_substr($s['prefix'] . $s['full_name'], 0, 1, 'UTF-8') ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="fw-500"<?= ($s['work_status'] ?? 'active') === 'resigned' ? ' style="color:var(--ne-slate)"' : '' ?>><?= e($s['prefix'] . $s['full_name']) ?></span>
              <?php if (($s['work_status'] ?? 'active') === 'resigned'): ?><span class="ne-badge" style="background:#fdecec;color:#b42318;margin-left:6px;">ลาออก<?= !empty($s['resigned_on']) ? ' ' . e(thaiDate($s['resigned_on'])) : '' ?></span><?php endif; ?>
              <?php if ($s['department_id'] === null): ?><span class="ne-badge" style="background:#fff4e5;color:#9a5b00;margin-left:6px;">ยังไม่เลือกหน่วยงาน</span><?php endif; ?>
              <div style="font-size:.78rem;color:var(--ne-slate);margin-top:2px;">
                <?php if (trim($s['full_name_en'] ?? '') === ''): ?>
                <span style="color:#b45309;">ยังไม่มีชื่ออังกฤษ</span>
                <?php else: ?>
                <?= e($s['full_name_en']) ?>
                <?php if (!empty($s['name_en_verified'])): ?>
                <span class="ne-badge" style="background:#e3f6ec;color:#0b8a4b;margin-left:4px;" title="ตรวจสอบแล้ว แสดงในหน้าเว็บ EN"><i class="bi bi-check2"></i> ยืนยันแล้ว</span>
                <?php else: ?>
                <span class="ne-badge" style="background:#fff1d9;color:#a86400;margin-left:4px;" title="ระบบเดาให้ ยังไม่ได้ตรวจ ยังไม่แสดงในหน้าเว็บ EN">รอตรวจ</span>
                <?php endif; ?>
                <?php endif; ?>
              </div>
              <?php if (!empty($s['e_profile_url'])): ?>
              <a href="<?= e($s['e_profile_url']) ?>" target="_blank" rel="noopener" style="color:var(--ne-slate);margin-left:4px;" title="E-Profile">
                <i class="bi bi-box-arrow-up-right" style="font-size:0.8rem"></i>
              </a>
              <?php endif; ?>
              <?php if ($s['email']): ?>
              <div class="ne-byline"><?= e($s['email']) ?></div>
              <?php endif; ?>
            </td>
            <td class="ne-num">
              <?= e($s['position_name']) ?>
              <?php if ($s['responsibility']): ?>
              <div class="ne-byline"><?= e($s['responsibility']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="ne-num"><?= e($s['campus_name']) ?></span></td>
            <td><span class="ne-badge ne-badge-published"><?= e($s['staff_type_name']) ?></span></td>
            <td>
              <div class="ne-actions">
                <a href="<?= BASE_URL ?>/admin/modules/staff/create.php?id=<?= $s['staff_id'] ?>"
                   class="ne-icon-btn ne-icon-edit" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </a>
                <button type="button" class="ne-icon-btn ne-icon-delete" title="ลบถาวร"
                  data-delete-staff="<?= (int)$s['staff_id'] ?>" data-name="<?= e($s['prefix'] . $s['full_name']) ?>">
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
    <div style="padding:14px 20px;border-top:1px solid var(--ne-line);display:flex;justify-content:space-between;align-items:center;">
      <span class="ne-hint" style="margin:0;">แสดง <?= $pager['offset'] + 1 ?>–<?= min($pager['offset'] + $perPage, $total) ?> จาก <?= number_format($total) ?> คน</span>
      <?= paginationHtml($pager) ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-people"></i>ไม่พบบุคลากร</div>
    <?php endif; ?>
  </div>
</div>

<?php
$deleteUrl = BASE_URL . '/admin/modules/staff/delete.php';
$extraJs = '<script>
document.querySelectorAll("[data-delete-staff]").forEach(function (btn) {
  btn.addEventListener("click", function () {
    var name = btn.dataset.name;
    Swal.fire({
      icon: "warning",
      title: "ลบบุคลากรถาวร?",
      html: "ต้องการลบ <strong></strong> ออกจากระบบถาวรใช่หรือไม่?<br><br><span style=\"color:#c1443d;font-weight:600;\">ข้อมูลและรูปภาพจะถูกลบทั้งหมด และไม่สามารถกู้คืนได้</span>",
      showCancelButton: true,
      confirmButtonColor: "#c1443d",
      cancelButtonColor: "#8b93a7",
      confirmButtonText: "ลบถาวร",
      cancelButtonText: "ยกเลิก",
      reverseButtons: true,
      focusCancel: true,
      didOpen: function (el) { el.querySelector("strong").textContent = name; }
    }).then(function (r) {
      if (!r.isConfirmed) return;
      var f = document.createElement("form");
      f.method = "POST"; f.action = ' . json_encode($deleteUrl) . ';
      [["id", btn.dataset.deleteStaff], ["csrf_token", ' . json_encode(csrfToken()) . ']].forEach(function (p) {
        var i = document.createElement("input"); i.type = "hidden"; i.name = p[0]; i.value = p[1]; f.appendChild(i);
      });
      document.body.appendChild(f); f.submit();
    });
  });
});
</script>';
include BASE_PATH . '/admin/layout/footer.php'; ?>
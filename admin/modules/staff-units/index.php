<?php
/**
 * BAIT Admin - งาน (สายสนับสนุน): the list of งาน the support-staff chart is grouped by
 * (หน้าบุคลากร › บุคลากรสายสนับสนุน: campus → งาน → หัวหน้างาน on top, then the rest). Needs migration step 15.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
$auth = new Auth(); $auth->requireLogin(); $db = Database::getInstance();
$ready = hasStaffUnits();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = postStr('action');
    if ($action === 'save') {
        $id    = (int)postStr('unit_id');
        $name  = postStr('unit_name');
        $order = max(0, min(9999, (int)postStr('sort_order')));
        if ($name === '') jsonError('กรุณากรอกชื่องาน');
        if ($id && !$db->fetchOne('SELECT 1 FROM staff_units WHERE unit_id = ?', [$id])) jsonError('ไม่พบงานนี้ อาจถูกลบไปแล้ว กรุณาโหลดหน้าใหม่');
        if (nameTaken($db, 'staff_units', 'unit_name', 'unit_id', $name, $id)) jsonError(dupMsg('งาน', $name));
        if ($id) $db->update('staff_units', ['unit_name' => $name, 'sort_order' => $order], 'unit_id = ?', [$id]);
        else     $db->insert('staff_units', ['unit_name' => $name, 'sort_order' => $order]);
        jsonSuccess([], $id ? 'แก้ไขงานแล้ว' : 'เพิ่มงานแล้ว');
    }
    if ($action === 'delete') {
        $id = (int)postStr('unit_id');
        if (!$db->fetchOne('SELECT 1 FROM staff_units WHERE unit_id = ?', [$id])) jsonError('ไม่พบงานนี้ อาจถูกลบไปแล้ว กรุณาโหลดหน้าใหม่');
        $n = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM staff WHERE unit_id = ? AND is_active = 1', [$id])['c'] ?? 0);
        if ($n) jsonError("ลบไม่ได้ เพราะยังมีบุคลากร $n คนอยู่ในงานนี้ กรุณาย้ายไปงานอื่นก่อน");
        $db->delete('staff_units', 'unit_id = ?', [$id]);
        jsonSuccess([], 'ลบงานแล้ว');
    }
    jsonError('คำสั่งไม่ถูกต้อง');
}

$rows = $ready ? $db->fetchAll('SELECT u.*, (SELECT COUNT(*) FROM staff s WHERE s.unit_id = u.unit_id AND ' . staffCurrentSql('s') . ') AS sc FROM staff_units u ORDER BY u.sort_order, u.unit_name') : [];
$pageTitle = 'งาน (สายสนับสนุน)'; $activeMenu = 'staff-units';
$breadcrumb = [['label' => 'งาน (สายสนับสนุน)']]; $pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>
<div class="ne">
  <div class="ne-topbar">
    <h1>งาน (สายสนับสนุน) <span class="ne-count"><?= count($rows) ?> งาน</span></h1>
    <?php if ($ready): ?><button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openForm(0)"><i class="bi bi-plus-lg"></i>เพิ่มงาน</button><?php endif; ?>
  </div>
  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังใช้งานไม่ได้: ต้องรันไฟล์ <code>sql/1_bait_migrations_13_to_16.sql</code> (ข้อ 15) ใน phpMyAdmin หนึ่งครั้งก่อน</p></div></div>
  <?php else: ?>
  <div class="ne-panel">
    <div class="ne-panel-head"><span>เรียงตามลำดับ (ใช้เรียงกล่องงานในผังบนเว็บ)</span></div>
    <div class="ne-panel-body">
      <p class="ne-hint" style="margin-top:0;">ผังบุคลากรสายสนับสนุนบนเว็บ: ศูนย์พื้นที่ → งาน → คนที่ติ๊ก "เป็นหัวหน้างาน" อยู่บนสุด ตามด้วยคนอื่นในงาน (ตั้งค่าที่ฟอร์มบุคลากร)</p>
      <?php if (!$rows): ?><div class="ne-empty"><i class="bi bi-diagram-3"></i>ยังไม่มีงาน</div><?php else: ?>
      <div style="overflow-x:auto;">
      <table class="ne-table">
        <thead><tr><th style="width:70px">ลำดับ</th><th>ชื่องาน</th><th style="width:110px;text-align:center">บุคลากร</th><th style="width:100px">จัดการ</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="ne-num"><?= (int)$r['sort_order'] ?></td>
            <td class="fw-500"><?= e($r['unit_name']) ?></td>
            <td style="text-align:center"><span class="ne-badge-count"><?= (int)$r['sc'] ?></span></td>
            <td><div class="ne-actions">
              <button type="button" class="ne-icon-btn ne-icon-edit" title="แก้ไข" onclick="openForm(<?= (int)$r['unit_id'] ?>)"><i class="bi bi-pencil"></i></button>
              <button type="button" class="ne-icon-btn ne-icon-delete" title="ลบ" onclick="delUnit(<?= (int)$r['unit_id'] ?>)"><i class="bi bi-trash"></i></button>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php if ($ready): ?>
<div class="modal fade" id="uModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header"><h5 class="modal-title" id="uTitle" style="font-family:'Kanit',serif;">งาน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
  <div class="modal-body" style="padding:20px;">
    <input type="hidden" id="uId" value="0">
    <div class="ne-field"><label for="uName">ชื่องาน <span style="color:var(--ne-danger)">*</span></label><input type="text" class="ne-input" id="uName" maxlength="120" placeholder="เช่น งานวิชาการและวิจัย"></div>
    <div class="ne-field" style="margin-bottom:0;"><label for="uOrder">ลำดับ (น้อยแสดงก่อน)</label><input type="number" class="ne-input" id="uOrder" value="0" min="0" max="9999"></div>
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" onclick="saveUnit()"><i class="bi bi-check-lg"></i>บันทึก</button>
  </div>
</div></div></div>
<?php endif; ?>
<?php
$extraJs = '<script>
const csrf = ' . json_encode(csrfToken()) . ';
const UN = ' . json_encode((object)array_column(array_map(fn($r) => ['id' => (int)$r['unit_id'], 'name' => $r['unit_name'], 'order' => (int)$r['sort_order'], 'sc' => (int)$r['sc']], $rows), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
let uModal;
function openForm(id) {
  const u = UN[id] || { name: "", order: Object.keys(UN).length + 1 };
  document.getElementById("uId").value = id; document.getElementById("uName").value = u.name; document.getElementById("uOrder").value = u.order;
  document.getElementById("uTitle").textContent = id ? "แก้ไขงาน" : "เพิ่มงาน";
  uModal = uModal || new bootstrap.Modal(document.getElementById("uModal")); uModal.show();
}
async function saveUnit() {
  const fd = new FormData(); fd.append("action", "save"); fd.append("csrf_token", csrf);
  fd.append("unit_id", document.getElementById("uId").value); fd.append("unit_name", document.getElementById("uName").value); fd.append("sort_order", document.getElementById("uOrder").value);
  const r = await axios.post("", fd);
  if (r.data.status === "success") { uModal.hide(); showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); } else showError(r.data.message);
}
async function delUnit(id) {
  const u = UN[id]; if (!u) return;
  if (u.sc > 0) { showError("ลบไม่ได้ เพราะยังมีบุคลากร " + u.sc + " คนอยู่ในงานนี้ กรุณาย้ายไปงานอื่นก่อน"); return; }
  const c = await Swal.fire({ title: "ลบงานนี้?", text: u.name, icon: "warning", showCancelButton: true, confirmButtonColor: "#c1443d", confirmButtonText: "ลบ", cancelButtonText: "ยกเลิก" });
  if (!c.isConfirmed) return;
  const fd = new FormData(); fd.append("action", "delete"); fd.append("unit_id", id); fd.append("csrf_token", csrf);
  const r = await axios.post("", fd);
  if (r.data.status === "success") { showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); } else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';

<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $a = $_POST['action'] ?? '';
  if ($a === 'save') {
    $id = (int)($_POST['staff_type_id'] ?? 0);
    $name = postStr('staff_type_name');
    if (!$name) jsonError('กรุณากรอกชื่อประเภท');
    if (nameTaken($db, 'staff_types', 'staff_type_name', 'staff_type_id', $name, $id)) jsonError(dupMsg('ประเภทบุคลากร', $name));
    if ($id) {
      $db->update('staff_types', ['staff_type_name' => $name], 'staff_type_id=?', [$id]);
      jsonSuccess([], 'แก้ไขแล้ว');
    } else {
      $db->insert('staff_types', ['staff_type_name' => $name]);
      jsonSuccess([], 'เพิ่มแล้ว');
    }
  }
  if ($a === 'delete') {
    $id = (int)($_POST['staff_type_id'] ?? 0);
    $c = $db->count('staff', 'staff_type_id=?', [$id]);
    if ($c) jsonError("ลบไม่ได้ มีบุคลากร $c คนเป็นประเภทนี้ (นับรวมผู้ที่ลาออกแล้ว)");
    $db->delete('staff_types', 'staff_type_id=?', [$id]);
    jsonSuccess([], 'ลบแล้ว');
  }
}
$rows = $db->fetchAll("SELECT t.*,(SELECT COUNT(*) FROM staff WHERE staff_type_id=t.staff_type_id AND " . staffCurrentSql('') . ") sc FROM staff_types t ORDER BY t.staff_type_id");
$pageTitle = 'ประเภทบุคลากร';
$activeMenu = 'staff-types';
$breadcrumb = [['label' => 'ประเภทบุคลากร']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php'; ?>

<div class="ne">
  <div class="ne-topbar">
    <h1>ประเภทบุคลากรทั้งหมด <span class="ne-count"><?= count($rows) ?> ประเภท</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มประเภท
    </button>
  </div>

  <div class="ne-grid">
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>ประเภทบุคลากรทั้งหมด</span></div>
        <?php if (!empty($rows)): ?>
        <div class="table-responsive">
          <table class="ne-table">
            <thead>
              <tr>
                <th style="width:44px">#</th>
                <th>ประเภทบุคลากร</th>
                <th style="width:120px" class="text-center">จำนวน (คน)</th>
                <th style="width:100px">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r): ?>
              <tr>
                <td class="ne-num"><?= $i + 1 ?></td>
                <td class="fw-500"><?= e($r['staff_type_name']) ?></td>
                <td class="text-center"><span class="ne-badge-count"><?= $r['sc'] ?></span></td>
                <td>
                  <div class="ne-actions">
                    <button class="ne-icon-btn ne-icon-edit" onclick="openModal(<?= $r['staff_type_id'] ?>)" title="แก้ไข"><i class="bi bi-pencil"></i></button>
                    <button class="ne-icon-btn ne-icon-delete" onclick="del(<?= $r['staff_type_id'] ?>,'<?= e(addslashes($r['staff_type_name'])) ?>')" title="ลบ"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="ne-empty"><i class="bi bi-person-lines-fill"></i>ยังไม่มีข้อมูล</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="modal fade" id="modal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content ne-modal-content ne">
      <div class="modal-header ne-modal-header">
        <h5 class="modal-title" id="mt" style="font-family:'Kanit',serif;">ประเภทบุคลากร</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:20px;">
        <input type="hidden" id="fId">
        <div class="ne-field" style="margin-bottom:0;"><label for="fName">ชื่อประเภท <span style="color:var(--ne-danger)">*</span></label><input type="text" class="ne-input" id="fName"></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
        <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="ne-btn ne-btn-primary" onclick="save()">บันทึก</button>
      </div>
    </div>
  </div>
</div>
<script id="dd" type="application/json">
  <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>
</script>
<?php $extraJs = '<script>
const dd=JSON.parse(document.getElementById("dd").textContent);const csrf="' . csrfToken() . '";let mi;
function openModal(id){const d=dd.find(x=>x.staff_type_id==id);document.getElementById("fId").value=id;document.getElementById("fName").value=d?d.staff_type_name:"";document.getElementById("mt").textContent=id?"แก้ไขประเภท":"เพิ่มประเภท";mi=new bootstrap.Modal(document.getElementById("modal"));mi.show();}
async function save(){const fd=new FormData();fd.append("action","save");fd.append("staff_type_id",document.getElementById("fId").value);fd.append("staff_type_name",document.getElementById("fName").value);fd.append("csrf_token",csrf);const r=await axios.post("",fd);if(r.data.status==="success"){mi.hide();showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}else showError(r.data.message);}
async function del(id,name){
  const d=dd.find(x=>x.staff_type_id==id);const sc=d?Number(d.sc)||0:0;
  if(sc>0){await Swal.fire({title:"ไม่สามารถลบได้",html:`ประเภทบุคลากร <b>${escapeHtml(name)}</b> ยังมีบุคลากร ${sc} คนที่ใช้ประเภทนี้อยู่ กรุณาย้ายหรือลบข้อมูลที่เกี่ยวข้องก่อน`,icon:"warning",confirmButtonText:"เข้าใจแล้ว"});return;}
  const r=await Swal.fire({title:"ลบ?",html:`ลบ <b>${escapeHtml(name)}</b>?`,icon:"warning",showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});if(!r.isConfirmed)return;const fd=new FormData();fd.append("action","delete");fd.append("staff_type_id",id);fd.append("csrf_token",csrf);const res=await axios.post("",fd);if(res.data.status==="success"){showSuccess(res.data.message);setTimeout(()=>location.reload(),1300);}else showError(res.data.message);}
</script>';
include BASE_PATH . '/admin/layout/footer.php'; ?>
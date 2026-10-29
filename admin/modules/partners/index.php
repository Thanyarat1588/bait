<?php
/**
 * BAIT Admin - Partner Logo Management
 * Mirrors admin/modules/banners/index.php's CRUD pattern (same upload,
 * reorder, toggle, delete flow) with an added required `name` field.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// AJAX reorder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    verifyCsrf();
    $ids = json_decode(postRaw('ids', '[]'), true); if (!is_array($ids)) $ids = [];
    foreach ($ids as $order => $id) {
        $db->update('partners', ['display_order' => $order + 1], 'partner_id = ?', [(int)$id]);
    }
    jsonSuccess([], 'บันทึกลำดับแล้ว');
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    $id = (int)($_POST['partner_id'] ?? 0);
    $p  = $db->fetchOne("SELECT is_active FROM partners WHERE partner_id = ?", [$id]);
    if ($p) { $db->update('partners', ['is_active' => $p['is_active'] ? 0 : 1], 'partner_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
    jsonError('ไม่พบพันธมิตร');
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['partner_id'] ?? 0);
    $p  = $db->fetchOne("SELECT * FROM partners WHERE partner_id = ?", [$id]);
    if ($p) {
        $db->delete('partners','partner_id = ?',[$id]);
        fileQueueDelete($p['logo']); fileCommitChanges();   // the file goes only once the row is really gone
        jsonSuccess([], 'ลบพันธมิตรแล้ว');
    }
    jsonError('ไม่พบพันธมิตร');
}

// Add new partner, or edit an existing one — same form, same endpoint,
// distinguished by whether partner_id is set. Editing only replaces the
// logo file if a new one was actually chosen; otherwise the existing
// image (and its filename on disk) is left alone.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $id      = (int)($_POST['partner_id'] ?? 0);
    $name    = postStr('name');
    $linkUrl = postStr('link_url');
    if ($linkUrl !== '' && !isHttpUrl($linkUrl)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://');
    if (!$name) jsonError('กรุณากรอกชื่อพันธมิตร');
    if (nameTaken($db, 'partners', 'name', 'partner_id', $name, $id)) jsonError(dupMsg('พันธมิตร', $name));

    if ($id) {
        $existing = $db->fetchOne("SELECT * FROM partners WHERE partner_id = ?", [$id]);
        if (!$existing) jsonError('ไม่พบพันธมิตร');
        $data = ['name' => $name, 'link_url' => $linkUrl ?: null];
        if (!empty($_FILES['logo']['name'])) {
            $up = uploadImage($_FILES['logo'], 'partners');
            if (!$up) jsonError('อัปโหลดภาพไม่สำเร็จ');
            fileTrackNew($up); fileQueueDelete($existing['logo']);   // the old logo goes only once the change is saved
            $data['logo'] = $up;
        }
        $db->update('partners', $data, 'partner_id = ?', [$id]);
        fileCommitChanges();
        jsonSuccess(['logo' => UPLOAD_URL.'/'.($data['logo'] ?? $existing['logo'])], 'แก้ไขพันธมิตรเรียบร้อยแล้ว');
    } else {
        if (empty($_FILES['logo']['name'])) jsonError('กรุณาเลือกโลโก้');
        $up = uploadImage($_FILES['logo'], 'partners');
        if (!$up) jsonError('อัปโหลดภาพไม่สำเร็จ');
        fileTrackNew($up);

        $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM partners")['m'] ?? 0);
        $db->insert('partners', [
            'name'          => $name,
            'logo'          => $up,
            'link_url'      => $linkUrl ?: null,
            'display_order' => $maxOrder + 1,
            'is_active'     => 1,
        ]);
        fileCommitChanges();
        jsonSuccess(['logo' => UPLOAD_URL.'/'.$up], 'เพิ่มพันธมิตรเรียบร้อยแล้ว');
    }
}

$partners = $db->fetchAll("SELECT * FROM partners ORDER BY display_order ASC");

$pageTitle  = 'จัดการโลโก้พันธมิตร';
$activeMenu = 'partners';
$breadcrumb = [['label'=>'จัดการโลโก้พันธมิตร']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>พันธมิตรทั้งหมด <span class="ne-count"><?=count($partners)?> รายการ</span></h1>
    <span class="ne-hint" style="margin:0;margin-left:auto;">ลาก-วาง เพื่อเรียงลำดับ</span>
  </div>

  <div class="ne-grid">
    <!-- Partners grid -->
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>โลโก้พันธมิตรทั้งหมด</span></div>
        <div class="ne-panel-body">
          <div id="partnerGrid" class="row g-2">
            <?php foreach ($partners as $pi => $p): ?>
            <div class="col-md-4" data-id="<?=$p['partner_id']?>">
              <div class="partner-item border rounded overflow-hidden position-relative" style="cursor:grab">
                <div style="height:120px;display:flex;align-items:center;justify-content:center;background:#fff;padding:12px">
                  <img src="<?=UPLOAD_URL.'/'.e($p['logo'])?>" style="max-width:100%;max-height:100%;object-fit:contain" alt="<?=e($p['name'])?>">
                </div>
                <div class="position-absolute bottom-0 start-0 w-100 p-2" style="background:rgba(0,0,0,0.45)">
                  <p class="mb-0 text-white small fw-500"><?=e($p['name'])?></p>
                  <?php if ($p['link_url']): ?>
                  <small class="text-white-50"><?=e(mb_strimwidth($p['link_url'],0,30,'...','UTF-8'))?></small>
                  <?php endif; ?>
                </div>
                <!-- Actions overlay -->
                <div class="position-absolute top-0 end-0 p-1 ne-actions">
                  <button class="ne-icon-btn ne-icon-edit" style="background:#fff;"
                          onclick="editPartner(<?=$p['partner_id']?>)" title="แก้ไข">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="ne-icon-btn <?=$p['is_active']?'ne-icon-edit':''?>"
                          style="background:#fff;<?=!$p['is_active']?'color:var(--ne-slate);':''?>"
                          onclick="togglePartner(<?=$p['partner_id']?>)" title="<?=$p['is_active']?'ซ่อน':'แสดง'?>">
                    <i class="bi bi-<?=$p['is_active']?'eye':'eye-slash'?>"></i>
                  </button>
                  <button class="ne-icon-btn ne-icon-delete" style="background:#fff;"
                          onclick="deletePartner(<?=$p['partner_id']?>)" title="ลบ">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
                <!-- Order badge -->
                <div class="position-absolute top-0 start-0 m-1">
                  <span class="ne-badge-count" data-pos style="background:rgba(0,0,0,.6);color:#fff;">#<?= $pi + 1 ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($partners)): ?>
            <div class="col-12"><div class="ne-empty"><i class="bi bi-building"></i>ยังไม่มีพันธมิตร</div></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid var(--ne-line);text-align:right;">
          <button class="ne-btn ne-btn-primary" onclick="saveOrder()">
            <i class="bi bi-save"></i>บันทึกลำดับ
          </button>
        </div>
      </div>
    </div>

    <!-- Add / edit form — doubles as both, switched by editPartner()/cancelEdit() -->
    <div class="ne-side">
      <div class="ne-panel">
        <div class="ne-panel-head">
          <span id="formTitle"><i class="bi bi-upload" style="color:var(--ne-blue);margin-right:6px;"></i>เพิ่มพันธมิตรใหม่</span>
        </div>
        <div class="ne-panel-body">
          <input type="hidden" id="partnerId" value="0">
          <div class="ne-field">
            <label for="partnerName">ชื่อพันธมิตร <span style="color:var(--ne-danger)">*</span></label>
            <input type="text" class="ne-input" id="partnerName" placeholder="ชื่อบริษัท/หน่วยงาน">
          </div>
          <div class="ne-field">
            <label for="partnerLogo">โลโก้ <span id="logoRequiredMark" style="color:var(--ne-danger)">*</span></label>
            <input type="file" data-has-preview class="form-control" id="partnerLogo" accept="image/*" onchange="previewLogo(this)">
            <img id="logoPreview" class="img-fluid rounded mt-2 d-none" style="max-height:140px;object-fit:contain;background:#f8f9fc;padding:8px" alt="">
            <p class="ne-hint" id="logoHint">แนะนำภาพพื้นหลังโปร่งใส (PNG หรือ SVG) รองรับไฟล์ JPG, PNG, GIF, WEBP, SVG, BMP, ICO, AVIF ขนาดไม่เกิน 5MB</p>
          </div>
          <div class="ne-field">
            <label for="partnerLink">ลิงก์ (เมื่อคลิก)</label>
            <input type="url" class="ne-input" id="partnerLink" placeholder="https://...">
          </div>
          <button class="ne-btn ne-btn-primary ne-btn-block" id="saveBtn" onclick="savePartner()">
            <i class="bi bi-plus-lg"></i>เพิ่มพันธมิตร
          </button>
          <button class="ne-btn ne-btn-ghost ne-btn-block mt-2 d-none" id="cancelEditBtn" onclick="cancelEdit()">
            ยกเลิกการแก้ไข
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
const csrf = ' . json_encode(csrfToken()) . ';
const partners = ' . json_encode(array_map(fn($p) => [
    'id'       => (int)$p['partner_id'],
    'name'     => $p['name'],
    'link_url' => $p['link_url'],
    'logo'     => UPLOAD_URL . '/' . $p['logo'],
], $partners), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';

// the badge on each card is its position in the list (1, 2, 3 ...) and follows the dragging
function renumberCards() {
  document.querySelectorAll("#partnerGrid [data-id]").forEach((el, i) => { const b = el.querySelector("[data-pos]"); if (b) b.textContent = "#" + (i + 1); });
}
new Sortable(document.getElementById("partnerGrid"), {
  animation: 150, ghostClass: "opacity-50", onEnd: renumberCards
});

function saveOrder() {
  const ids = [...document.querySelectorAll("#partnerGrid [data-id]")].map(el => el.dataset.id);
  const fd  = new FormData();
  fd.append("action","reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function togglePartner(id) {
  const fd = new FormData();
  fd.append("action","toggle"); fd.append("partner_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
}

async function deletePartner(id) {
  const r = await Swal.fire({title:"ลบพันธมิตร?",icon:"warning",showCancelButton:true,
    confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("partner_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
}

function previewLogo(input) {
  const prev = document.getElementById("logoPreview");
  if (input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => { prev.src = e.target.result; prev.classList.remove("d-none"); };
    reader.readAsDataURL(input.files[0]);
  }
}

function editPartner(id) {
  const p = partners.find(x => x.id === id);
  if (!p) return;
  document.getElementById("partnerId").value = p.id;
  document.getElementById("partnerName").value = p.name;
  document.getElementById("partnerLink").value = p.link_url || "";
  document.getElementById("partnerLogo").value = "";
  const prev = document.getElementById("logoPreview");
  prev.src = p.logo; prev.classList.remove("d-none");
  document.getElementById("logoRequiredMark").classList.add("d-none");
  document.getElementById("logoHint").textContent = "เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนโลโก้ ไม่เลือกจะคงรูปเดิมไว้";
  document.getElementById("formTitle").innerHTML = "<i class=\"bi bi-pencil-square\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>แก้ไขพันธมิตร";
  document.getElementById("saveBtn").innerHTML = "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข";
  document.getElementById("cancelEditBtn").classList.remove("d-none");
  document.querySelector(".ne-side").scrollIntoView({behavior:"smooth",block:"start"});
}

function cancelEdit() {
  document.getElementById("partnerId").value = "0";
  document.getElementById("partnerName").value = "";
  document.getElementById("partnerLink").value = "";
  document.getElementById("partnerLogo").value = "";
  document.getElementById("logoPreview").classList.add("d-none");
  document.getElementById("logoRequiredMark").classList.remove("d-none");
  document.getElementById("logoHint").textContent = "แนะนำภาพพื้นหลังโปร่งใส (PNG หรือ SVG) รองรับไฟล์ JPG, PNG, GIF, WEBP, SVG, BMP, ICO, AVIF ขนาดไม่เกิน 5MB";
  document.getElementById("formTitle").innerHTML = "<i class=\"bi bi-upload\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>เพิ่มพันธมิตรใหม่";
  document.getElementById("saveBtn").innerHTML = "<i class=\"bi bi-plus-lg\"></i>เพิ่มพันธมิตร";
  document.getElementById("cancelEditBtn").classList.add("d-none");
}

async function savePartner() {
  const id   = document.getElementById("partnerId").value;
  const name = document.getElementById("partnerName").value.trim();
  const file = document.getElementById("partnerLogo").files[0];
  if (!name) { showError("กรุณากรอกชื่อพันธมิตร"); return; }
  if (id === "0" && !file) { showError("กรุณาเลือกโลโก้"); return; }
  const fd = new FormData();
  fd.append("action","save");
  fd.append("partner_id",id);
  fd.append("name",name);
  if (file) fd.append("logo",file);
  fd.append("link_url",document.getElementById("partnerLink").value);
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1500); }
  else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

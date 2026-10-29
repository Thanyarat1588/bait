<?php
/**
 * BAIT Admin - Homepage Popup Management
 * A popup ad shown once per visitor session on index.php (dismissible, or
 * click-through if a link is set) — same CRUD flow as
 * admin/modules/highlights/index.php (reorder, toggle, delete, add/edit
 * in the sidebar). Only the first active row (by display_order) is ever
 * shown publicly, but multiple rows can be kept ready and toggled.
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
        $db->update('popup_ads', ['display_order' => $order + 1], 'popup_id = ?', [(int)$id]);
    }
    jsonSuccess([], 'บันทึกลำดับแล้ว');
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    $id = (int)($_POST['popup_id'] ?? 0);
    $p  = $db->fetchOne("SELECT is_active FROM popup_ads WHERE popup_id = ?", [$id]);
    if ($p) { $db->update('popup_ads', ['is_active' => $p['is_active'] ? 0 : 1], 'popup_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
    jsonError('ไม่พบป๊อปอัพ');
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['popup_id'] ?? 0);
    $p  = $db->fetchOne("SELECT * FROM popup_ads WHERE popup_id = ?", [$id]);
    if ($p) {
        $db->delete('popup_ads','popup_id = ?',[$id]);
        fileQueueDelete($p['image']); fileCommitChanges();   // the file goes only once the row is really gone
        jsonSuccess([], 'ลบป๊อปอัพแล้ว');
    }
    jsonError('ไม่พบป๊อปอัพ');
}

// Add new, or edit an existing one — image upload is only required for a
// brand-new row; editing only replaces the file if a new one was chosen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $id      = (int)($_POST['popup_id'] ?? 0);
    $title   = postStr('title');
    $linkUrl = postStr('link_url');
    if ($linkUrl && !isHttpUrl($linkUrl)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://');

    $imagePath = null;
    if (!empty($_FILES['image']['name'])) {
        $up = uploadMedia($_FILES['image'], 'popups');
        if ($up) fileTrackNew($up['path']);   // first: a video/PDF refused below must not stay behind in uploads/
        if (!$up || $up['type'] !== 'image') jsonError('อัปโหลดรูปไม่สำเร็จ (รองรับ JPG, PNG, GIF, WEBP, SVG ขนาดไม่เกิน 20MB)');
        $imagePath = $up['path'];
        fileTrackNew($imagePath);   // dropped again if this save does not go through
    }

    if ($id) {
        $existing = $db->fetchOne("SELECT * FROM popup_ads WHERE popup_id = ?", [$id]);
        if (!$existing) jsonError('ไม่พบป๊อปอัพ');
        $data = ['title' => $title ?: null, 'link_url' => $linkUrl ?: null];
        if ($imagePath) {
            $data['image'] = $imagePath;
            fileQueueDelete($existing['image']);   // the old picture goes only once the change is saved
        }
        $db->update('popup_ads', $data, 'popup_id = ?', [$id]);
        fileCommitChanges();
        jsonSuccess([], 'แก้ไขป๊อปอัพเรียบร้อยแล้ว');
    } else {
        if (!$imagePath) jsonError('กรุณาเลือกรูปภาพป๊อปอัพ');
        $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM popup_ads")['m'] ?? 0);
        $db->insert('popup_ads', [
            'title'         => $title ?: null,
            'image'         => $imagePath,
            'link_url'      => $linkUrl ?: null,
            'display_order' => $maxOrder + 1,
            'is_active'     => 1,
        ]);
        fileCommitChanges();
        jsonSuccess([], 'เพิ่มป๊อปอัพเรียบร้อยแล้ว');
    }
}

$popups = $db->fetchAll("SELECT * FROM popup_ads ORDER BY display_order ASC");

$pageTitle  = 'ป๊อปอัพหน้าเว็บ';
$activeMenu = 'popups';
$breadcrumb = [['label'=>'ป๊อปอัพหน้าเว็บ']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>ป๊อปอัพหน้าเว็บ <span class="ne-count"><?=count($popups)?> รายการ</span></h1>
    <span class="ne-hint" style="margin:0;margin-left:auto;">แสดงเฉพาะรายการแรกที่เปิดใช้งาน — ลาก-วาง เพื่อเรียงลำดับ</span>
  </div>

  <div class="ne-grid">
    <!-- Popups grid -->
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>ป๊อปอัพทั้งหมด (แสดงตอนเข้าหน้าแรก ครั้งละ 1 รายการต่อรอบเข้าชม)</span></div>
        <div class="ne-panel-body">
          <div id="popupGrid" class="row g-2">
            <?php foreach ($popups as $i => $p): ?>
            <div class="col-md-4" data-id="<?=$p['popup_id']?>">
              <div class="highlight-item border rounded overflow-hidden position-relative" style="cursor:grab">
                <div style="height:160px;display:flex;align-items:center;justify-content:center;background:#f8f9fc;padding:8px">
                  <img src="<?=UPLOAD_URL.'/'.e($p['image'])?>" style="max-width:100%;max-height:100%;object-fit:contain" alt="">
                </div>
                <div class="position-absolute bottom-0 start-0 w-100 p-2" style="background:rgba(0,0,0,0.45)">
                  <p class="mb-0 text-white small fw-500"><?=e($p['title'] ?: 'ไม่มีชื่อ')?></p>
                  <small class="text-white-50"><?=$p['link_url'] ? 'มีลิงก์คลิกผ่าน' : 'ไม่มีลิงก์'?></small>
                </div>
                <div class="position-absolute top-0 end-0 p-1 ne-actions">
                  <button class="ne-icon-btn ne-icon-edit" style="background:#fff;"
                          onclick="editPopup(<?=$p['popup_id']?>)" title="แก้ไข">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="ne-icon-btn <?=$p['is_active']?'ne-icon-edit':''?>"
                          style="background:#fff;<?=!$p['is_active']?'color:var(--ne-slate);':''?>"
                          onclick="togglePopup(<?=$p['popup_id']?>)" title="<?=$p['is_active']?'ซ่อน':'แสดง'?>">
                    <i class="bi bi-<?=$p['is_active']?'eye':'eye-slash'?>"></i>
                  </button>
                  <button class="ne-icon-btn ne-icon-delete" style="background:#fff;"
                          onclick="deletePopup(<?=$p['popup_id']?>)" title="ลบ">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
                <div class="position-absolute top-0 start-0 m-1">
                  <span class="ne-badge-count" data-pos style="background:rgba(0,0,0,.6);color:#fff;">#<?=$i+1?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($popups)): ?>
            <div class="col-12"><div class="ne-empty"><i class="bi bi-window-stack"></i>ยังไม่มีป๊อปอัพ</div></div>
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

    <!-- Add / edit form -->
    <div class="ne-side">
      <div class="ne-panel">
        <div class="ne-panel-head">
          <span id="formTitle"><i class="bi bi-plus-lg" style="color:var(--ne-blue);margin-right:6px;"></i>เพิ่มป๊อปอัพใหม่</span>
        </div>
        <div class="ne-panel-body">
          <input type="hidden" id="popupId" value="0">
          <div class="ne-field">
            <label for="popupTitle">ชื่อ/คำอธิบาย (ไม่บังคับ)</label>
            <input type="text" class="ne-input" id="popupTitle" placeholder="เช่น ประชาสัมพันธ์รับสมัครนักศึกษา 2569">
          </div>
          <div class="ne-field">
            <label for="popupLink">ลิงก์เมื่อคลิกที่รูป (ไม่บังคับ)</label>
            <input type="url" class="ne-input" id="popupLink" placeholder="https://...">
          </div>
          <div class="ne-field">
            <label for="popupImage">รูปภาพป๊อปอัพ <span id="imgRequiredMark" style="color:var(--ne-danger)">*</span></label>
            <div style="background:var(--ne-blue-soft);color:var(--ne-blue-deep);padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:13px;line-height:1.6;">
              <i class="bi bi-info-circle"></i> <strong>ขนาดที่แนะนำ: 800 × 1000 พิกเซล</strong> (แนวตั้ง อัตราส่วน 4:5)<br>
              รูปจะถูกย่อให้พอดีกรอบ กว้างไม่เกิน 480px และสูงไม่เกิน 90% ของจอ — แนวตั้งหรือสี่เหลี่ยมจัตุรัสจะดูเต็มกรอบสวยกว่าภาพแนวนอน (ภาพแนวนอนจะดูเล็กลงเพราะถูกจำกัดความกว้างไว้ก่อน)
            </div>
            <input type="file" data-has-preview class="form-control" id="popupImage" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" onchange="previewImage(this)">
            <div id="imgPreviewWrap" class="d-none mt-2">
              <img id="imgPreview" class="img-fluid rounded" style="max-height:160px;object-fit:contain;background:#f8f9fc;padding:8px" alt="">
            </div>
            <p class="ne-hint" id="imgHint">รองรับ JPG, PNG, GIF, WEBP, SVG ขนาดไฟล์ไม่เกิน 20MB</p>
          </div>
          <button class="ne-btn ne-btn-primary ne-btn-block" id="saveBtn" onclick="savePopup()">
            <i class="bi bi-plus-lg"></i>เพิ่มป๊อปอัพ
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
const popups = ' . json_encode(array_map(fn($p) => [
    'id'       => (int)$p['popup_id'],
    'title'    => $p['title'],
    'link_url' => $p['link_url'],
], $popups), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';

// the badge on each card is its position in the list (1, 2, 3 ...) and follows the dragging
function renumberCards() {
  document.querySelectorAll("#popupGrid [data-id]").forEach((el, i) => { const b = el.querySelector("[data-pos]"); if (b) b.textContent = "#" + (i + 1); });
}
new Sortable(document.getElementById("popupGrid"), {
  animation: 150, ghostClass: "opacity-50", onEnd: renumberCards
});

function saveOrder() {
  const ids = [...document.querySelectorAll("#popupGrid [data-id]")].map(el => el.dataset.id);
  const fd  = new FormData();
  fd.append("action","reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function togglePopup(id) {
  const fd = new FormData();
  fd.append("action","toggle"); fd.append("popup_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
}

async function deletePopup(id) {
  const r = await Swal.fire({title:"ลบป๊อปอัพนี้?",icon:"warning",showCancelButton:true,
    confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("popup_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
}

function previewImage(input) {
  const wrap = document.getElementById("imgPreviewWrap");
  const img  = document.getElementById("imgPreview");
  const file = input.files[0];
  if (!file) { wrap.classList.add("d-none"); return; }
  img.src = URL.createObjectURL(file);
  wrap.classList.remove("d-none");
}

function editPopup(id) {
  const p = popups.find(x => x.id === id);
  if (!p) return;
  document.getElementById("popupId").value = p.id;
  document.getElementById("popupTitle").value = p.title || "";
  document.getElementById("popupLink").value = p.link_url || "";
  document.getElementById("popupImage").value = "";
  document.getElementById("imgPreviewWrap").classList.add("d-none");
  document.getElementById("imgRequiredMark").classList.add("d-none");
  document.getElementById("imgHint").textContent = "เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนรูป ไม่เลือกจะคงรูปเดิมไว้";
  document.getElementById("formTitle").innerHTML = "<i class=\"bi bi-pencil-square\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>แก้ไขป๊อปอัพ";
  document.getElementById("saveBtn").innerHTML = "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข";
  document.getElementById("cancelEditBtn").classList.remove("d-none");
  document.querySelector(".ne-side").scrollIntoView({behavior:"smooth",block:"start"});
}

function cancelEdit() {
  document.getElementById("popupId").value = "0";
  document.getElementById("popupTitle").value = "";
  document.getElementById("popupLink").value = "";
  document.getElementById("popupImage").value = "";
  document.getElementById("imgPreviewWrap").classList.add("d-none");
  document.getElementById("imgRequiredMark").classList.remove("d-none");
  document.getElementById("imgHint").textContent = "รองรับ JPG, PNG, GIF, WEBP, SVG ขนาดไฟล์ไม่เกิน 20MB";
  document.getElementById("formTitle").innerHTML = "<i class=\"bi bi-plus-lg\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>เพิ่มป๊อปอัพใหม่";
  document.getElementById("saveBtn").innerHTML = "<i class=\"bi bi-plus-lg\"></i>เพิ่มป๊อปอัพ";
  document.getElementById("cancelEditBtn").classList.add("d-none");
}

async function savePopup() {
  const id    = document.getElementById("popupId").value;
  const title = document.getElementById("popupTitle").value.trim();
  const link  = document.getElementById("popupLink").value.trim();
  const file  = document.getElementById("popupImage").files[0];
  if (id === "0" && !file) { showError("กรุณาเลือกรูปภาพป๊อปอัพ"); return; }
  const fd = new FormData();
  fd.append("action","save");
  fd.append("popup_id",id);
  fd.append("title",title);
  fd.append("link_url",link);
  if (file) fd.append("image",file);
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1500); }
  else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

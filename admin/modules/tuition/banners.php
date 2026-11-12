<?php
/**
 * BAIT Admin - หลักสูตร > ค่าเทอม > แบนเนอร์: the slideshow at the top of the public tuition page (tuition.php).
 * Several pictures, each may link to an outside site; drag to reorder, hide/show, edit, delete. Same pattern as
 * admin/modules/banners (home page), table tuition_banners.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db    = Database::getInstance();
$ready = hasTuitionBanners();
$post  = $ready && $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

if ($post === 'reorder') {
    verifyCsrf();
    $ids = json_decode(postRaw('ids', '[]'), true); if (!is_array($ids)) $ids = [];
    foreach (array_values($ids) as $order => $id) $db->update('tuition_banners', ['display_order' => $order + 1], 'banner_id = ?', [(int)$id]);
    jsonSuccess([], 'บันทึกลำดับแล้ว');
}

if ($post === 'toggle') {
    verifyCsrf();
    $id = (int)postStr('banner_id');
    $b  = $db->fetchOne('SELECT is_active FROM tuition_banners WHERE banner_id = ?', [$id]);
    if (!$b) jsonError('ไม่พบแบนเนอร์');
    $db->update('tuition_banners', ['is_active' => $b['is_active'] ? 0 : 1], 'banner_id = ?', [$id]);
    jsonSuccess([], $b['is_active'] ? 'ซ่อนแบนเนอร์แล้ว' : 'แสดงแบนเนอร์แล้ว');
}

if ($post === 'delete') {
    verifyCsrf();
    $id = (int)postStr('banner_id');
    $b  = $db->fetchOne('SELECT * FROM tuition_banners WHERE banner_id = ?', [$id]);
    if (!$b) jsonError('ไม่พบแบนเนอร์');
    $db->delete('tuition_banners', 'banner_id = ?', [$id]);
    fileQueueDelete($b['image']); fileCommitChanges();   // the file goes only once the row is really gone
    jsonSuccess([], 'ลบแบนเนอร์แล้ว');
}

// add, or edit (the picture is replaced only when a new one was chosen)
if ($post === 'save') {
    verifyCsrf();
    $id      = (int)postStr('banner_id');
    $title   = mb_substr(postStr('title'), 0, 255);
    $linkUrl = postStr('link_url');
    if ($linkUrl !== '' && !isHttpUrl($linkUrl)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://');
    if (mb_strlen($linkUrl) > 500) jsonError('ลิงก์ยาวเกิน 500 ตัวอักษร');

    if ($id) {
        $existing = $db->fetchOne('SELECT * FROM tuition_banners WHERE banner_id = ?', [$id]);
        if (!$existing) jsonError('ไม่พบแบนเนอร์');
        $data = ['title' => $title !== '' ? $title : null, 'link_url' => $linkUrl !== '' ? $linkUrl : null];
        if (!empty($_FILES['image']['name'])) {
            $up = uploadImage($_FILES['image'], 'banners');
            if (!$up) jsonError('อัปโหลดภาพไม่สำเร็จ (รับเฉพาะไฟล์ภาพ ไม่เกิน 5MB)');
            fileTrackNew($up); fileQueueDelete($existing['image']);   // the old picture goes only once the change is saved
            $data['image'] = $up;
        }
        $db->update('tuition_banners', $data, 'banner_id = ?', [$id]);
        fileCommitChanges();
        jsonSuccess([], 'แก้ไขแบนเนอร์เรียบร้อยแล้ว');
    }
    if (empty($_FILES['image']['name'])) jsonError('กรุณาเลือกภาพ');
    $up = uploadImage($_FILES['image'], 'banners');
    if (!$up) jsonError('อัปโหลดภาพไม่สำเร็จ (รับเฉพาะไฟล์ภาพ ไม่เกิน 5MB)');
    fileTrackNew($up);
    $maxOrder = (int)($db->fetchOne('SELECT MAX(display_order) AS m FROM tuition_banners')['m'] ?? 0);
    $db->insert('tuition_banners', [
        'image' => $up, 'title' => $title !== '' ? $title : null, 'link_url' => $linkUrl !== '' ? $linkUrl : null,
        'display_order' => $maxOrder + 1, 'is_active' => 1,
    ]);
    fileCommitChanges();
    jsonSuccess([], 'เพิ่มแบนเนอร์เรียบร้อยแล้ว');
}

$banners = $ready ? $db->fetchAll('SELECT * FROM tuition_banners ORDER BY display_order, banner_id') : [];
$shownN  = count(array_filter($banners, fn($b) => (int)$b['is_active'] === 1));

$pageTitle   = 'แบนเนอร์หน้าค่าเทอม';
$activeMenu  = 'programs-tuition';
$breadcrumb  = [['label' => 'หลักสูตร', 'url' => BASE_URL . '/admin/modules/programs/index.php'], ['label' => 'ค่าเทอม', 'url' => BASE_URL . '/admin/modules/tuition/index.php'], ['label' => 'แบนเนอร์']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
$sizeHint = '<strong>แนะนำภาพขนาด 1500×500 พิกเซล</strong> (อัตราส่วน 3:1) ไม่เกิน 5MB · ภาพที่ไม่ตรงสัดส่วนจะแสดงครบทั้งภาพ แต่มีขอบว่างด้านข้างหรือบนล่าง';
?>
<style>
.tb-item { position: relative; border: 1px solid var(--ne-line); border-radius: 10px; overflow: hidden; background: #0C3566; cursor: grab; }
.tb-item img { display: block; width: 100%; aspect-ratio: 3 / 1; object-fit: contain; }
.tb-item.is-off img { opacity: .35; }
.tb-cap { position: absolute; inset: auto 0 0 0; padding: 8px 10px; background: linear-gradient(transparent, rgba(0,0,0,.72)); color: #fff; font-size: 13px; line-height: 1.35; }
.tb-cap small { display: block; opacity: .8; word-break: break-all; }
.tb-pos { position: absolute; top: 6px; left: 6px; background: rgba(0,0,0,.6); color: #fff; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 999px; }
.tb-off { position: absolute; top: 6px; left: 44px; background: #fff; color: #5b6480; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 999px; }
.tb-actions { position: absolute; top: 4px; right: 4px; display: flex; gap: 4px; }
.tb-actions .ne-icon-btn { background: #fff; }
#tbPreview { width: 100%; aspect-ratio: 3 / 1; object-fit: contain; background: #0C3566; border-radius: 8px; }
</style>

<div class="ne">
  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังใช้งานไม่ได้: ฐานข้อมูลยังไม่มีตาราง <code>tuition_banners</code> กรุณาแจ้งผู้ดูแลระบบ</p></div></div>
  <?php else: ?>
  <div class="ne-topbar">
    <h1>แบนเนอร์หน้าค่าเทอม <span class="ne-count">แสดง <?= $shownN ?> จาก <?= count($banners) ?> ภาพ</span></h1>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-left:auto;">
      <a href="index.php" class="ne-btn ne-btn-ghost"><i class="bi bi-arrow-left"></i>กลับไปหน้าค่าเทอม</a>
      <a href="<?= BASE_URL ?>/tuition.php" target="_blank" rel="noopener" class="ne-btn ne-btn-ghost"><i class="bi bi-box-arrow-up-right"></i>ดูบนเว็บไซต์</a>
    </div>
  </div>

  <div class="ne-grid">
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>ภาพสไลด์ (ลาก-วาง เพื่อเรียงลำดับ)</span></div>
        <div class="ne-panel-body">
          <p class="ne-hint" style="margin-top:0;">แสดงเป็นสไลด์ด้านซ้ายบนสุดของหน้าค่าเทอม เปลี่ยนภาพเองทุก 6 วินาที · ไม่มีภาพที่แสดงอยู่ = หน้าเว็บแสดงเฉพาะการ์ดข้อมูลปีการศึกษาเต็มความกว้าง</p>
          <div id="tbGrid" class="row g-2">
            <?php foreach ($banners as $i => $b): $on = (int)$b['is_active'] === 1; ?>
            <div class="col-md-6" data-id="<?= (int)$b['banner_id'] ?>">
              <div class="tb-item<?= $on ? '' : ' is-off' ?>">
                <img src="<?= UPLOAD_URL . '/' . e($b['image']) ?>" alt="">
                <span class="tb-pos" data-pos title="ลำดับที่แสดงบนหน้าเว็บ">#<?= $i + 1 ?></span>
                <?php if (!$on): ?><span class="tb-off">ซ่อนอยู่</span><?php endif; ?>
                <div class="tb-cap"><?= e($b['title'] ?: 'ไม่มีชื่อ') ?>
                  <small><?= $b['link_url'] ? '<i class="bi bi-link-45deg"></i> ' . e(mb_strimwidth($b['link_url'], 0, 60, '…', 'UTF-8')) : 'ไม่มีลิงก์ (คลิกแล้วขยายภาพ)' ?></small></div>
                <div class="tb-actions ne-actions">
                  <button type="button" class="ne-icon-btn ne-icon-edit" onclick="tbEdit(<?= (int)$b['banner_id'] ?>)" title="แก้ไข" aria-label="แก้ไข"><i class="bi bi-pencil"></i></button>
                  <button type="button" class="ne-icon-btn<?= $on ? ' ne-icon-edit' : '' ?>" onclick="tbToggle(<?= (int)$b['banner_id'] ?>)" title="<?= $on ? 'ซ่อน' : 'แสดง' ?>" aria-label="<?= $on ? 'ซ่อน' : 'แสดง' ?>"><i class="bi bi-<?= $on ? 'eye' : 'eye-slash' ?>"></i></button>
                  <button type="button" class="ne-icon-btn ne-icon-delete" onclick="tbDelete(<?= (int)$b['banner_id'] ?>)" title="ลบ" aria-label="ลบ"><i class="bi bi-trash"></i></button>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$banners): ?>
            <div class="col-12"><div class="ne-empty"><i class="bi bi-image"></i>ยังไม่มีแบนเนอร์ — เพิ่มภาพแรกจากแผงด้านขวา</div></div>
            <?php endif; ?>
          </div>
        </div>
        <?php if (count($banners) > 1): ?>
        <div style="padding:12px 20px;border-top:1px solid var(--ne-line);text-align:right;">
          <button type="button" class="ne-btn ne-btn-primary" onclick="tbSaveOrder()"><i class="bi bi-save"></i>บันทึกลำดับ</button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ne-side">
      <div class="ne-panel">
        <div class="ne-panel-head"><span id="tbFormTitle"><i class="bi bi-upload" style="color:var(--ne-blue);margin-right:6px;"></i>เพิ่มแบนเนอร์ใหม่</span></div>
        <div class="ne-panel-body">
          <input type="hidden" id="tbId" value="0">
          <div class="ne-field">
            <label for="tbFile">ภาพแบนเนอร์ <span id="tbReq" style="color:var(--ne-danger)">*</span></label>
            <input type="file" data-has-preview class="form-control" id="tbFile" accept="image/*" onchange="tbPreviewFile(this)">
            <img id="tbPreview" class="mt-2 d-none" alt="">
            <p class="ne-hint" id="tbHint"><?= $sizeHint ?></p>
          </div>
          <div class="ne-field">
            <label for="tbTitle">ชื่อแบนเนอร์ / คำอธิบายภาพ</label>
            <input type="text" class="ne-input" id="tbTitle" maxlength="255" placeholder="เช่น เปิดรับสมัครนักศึกษาใหม่ ลดค่าเทอม 40%">
            <p class="ne-hint">ใช้เป็นข้อความแทนภาพสำหรับผู้ใช้โปรแกรมอ่านหน้าจอ ควรสรุปข้อความสำคัญในภาพ</p>
          </div>
          <div class="ne-field">
            <label for="tbLink">ลิงก์เมื่อคลิก (เว็บไซต์ภายนอกได้)</label>
            <input type="url" class="ne-input" id="tbLink" maxlength="500" placeholder="https://...">
            <p class="ne-hint">เปิดในแท็บใหม่ · เว้นว่าง = คลิกแล้วขยายดูภาพ</p>
          </div>
          <button type="button" class="ne-btn ne-btn-primary ne-btn-block" id="tbSaveBtn" onclick="tbSave()"><i class="bi bi-plus-lg"></i>เพิ่มแบนเนอร์</button>
          <button type="button" class="ne-btn ne-btn-ghost ne-btn-block mt-2 d-none" id="tbCancelBtn" onclick="tbReset()">ยกเลิกการแก้ไข</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
$extraJs = !$ready ? '' : '
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
const csrf = ' . json_encode(csrfToken()) . ';
const tbData = ' . json_encode(array_map(fn($b) => ['id' => (int)$b['banner_id'], 'title' => $b['title'], 'link_url' => $b['link_url'], 'image' => $b['image']], $banners), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';
const tbUploadUrl = ' . json_encode(UPLOAD_URL . '/') . ';
const tbHintNew = ' . json_encode($sizeHint) . ';
const $ = id => document.getElementById(id);

if (window.Sortable && $("tbGrid")) new Sortable($("tbGrid"), { animation: 150, ghostClass: "opacity-50", onEnd: () => {
  document.querySelectorAll("#tbGrid [data-id]").forEach((el, i) => { const p = el.querySelector("[data-pos]"); if (p) p.textContent = "#" + (i + 1); });
}});

function tbPost(fields) {
  const fd = new FormData();
  Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
  fd.append("csrf_token", csrf);
  return axios.post("", fd).then(r => r.data).catch(() => ({ status: "error", message: "เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่อีกครั้ง" }));
}
async function tbSaveOrder() {
  const ids = [...document.querySelectorAll("#tbGrid [data-id]")].map(el => el.dataset.id);
  const r = await tbPost({ action: "reorder", ids: JSON.stringify(ids) });
  if (r.status === "success") showSuccess(r.message); else showError(r.message);
}
async function tbToggle(id) {
  const r = await tbPost({ action: "toggle", banner_id: id });
  if (r.status === "success") { showSuccess(r.message); setTimeout(() => location.reload(), 900); } else showError(r.message);
}
async function tbDelete(id) {
  const c = await Swal.fire({ title: "ลบแบนเนอร์นี้?", text: "ภาพจะถูกลบออกจากระบบด้วย กู้คืนไม่ได้", icon: "warning", showCancelButton: true,
    confirmButtonColor: "#c1443d", confirmButtonText: "ลบ", cancelButtonText: "ยกเลิก" });
  if (!c.isConfirmed) return;
  const r = await tbPost({ action: "delete", banner_id: id });
  if (r.status === "success") { showSuccess(r.message); setTimeout(() => location.reload(), 900); } else showError(r.message);
}
function tbPreviewFile(input) {
  const f = input.files[0]; if (!f) return;
  const rd = new FileReader();
  rd.onload = e => { $("tbPreview").src = e.target.result; $("tbPreview").classList.remove("d-none"); };
  rd.readAsDataURL(f);
}
function tbEdit(id) {
  const b = tbData.find(x => x.id === id); if (!b) return;
  $("tbId").value = b.id; $("tbTitle").value = b.title || ""; $("tbLink").value = b.link_url || ""; $("tbFile").value = "";
  $("tbReq").classList.add("d-none");
  $("tbHint").innerHTML = "เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนภาพ ไม่เลือก = คงภาพเดิม · " + tbHintNew;
  $("tbPreview").src = tbUploadUrl + b.image; $("tbPreview").classList.remove("d-none");
  $("tbFormTitle").innerHTML = "<i class=\"bi bi-pencil-square\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>แก้ไขแบนเนอร์";
  $("tbSaveBtn").innerHTML = "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข";
  $("tbCancelBtn").classList.remove("d-none");
  document.querySelector(".ne-side").scrollIntoView({ behavior: "smooth", block: "start" });
}
function tbReset() {
  $("tbId").value = "0"; $("tbTitle").value = ""; $("tbLink").value = ""; $("tbFile").value = "";
  $("tbReq").classList.remove("d-none"); $("tbHint").innerHTML = tbHintNew; $("tbPreview").classList.add("d-none");
  $("tbFormTitle").innerHTML = "<i class=\"bi bi-upload\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>เพิ่มแบนเนอร์ใหม่";
  $("tbSaveBtn").innerHTML = "<i class=\"bi bi-plus-lg\"></i>เพิ่มแบนเนอร์";
  $("tbCancelBtn").classList.add("d-none");
}
async function tbSave() {
  const id = $("tbId").value, file = $("tbFile").files[0];
  if (id === "0" && !file) { showError("กรุณาเลือกภาพ"); return; }
  const btn = $("tbSaveBtn"); btn.disabled = true;
  const fields = { action: "save", banner_id: id, title: $("tbTitle").value, link_url: $("tbLink").value.trim() };
  if (file) fields.image = file;
  const r = await tbPost(fields);
  btn.disabled = false;
  if (r.status === "success") { showSuccess(r.message); setTimeout(() => location.reload(), 1000); } else showError(r.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

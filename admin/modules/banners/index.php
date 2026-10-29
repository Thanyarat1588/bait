<?php
/**
 * BAIT Admin - Banner Management
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
        $db->update('banners', ['display_order' => $order + 1], 'banner_id = ?', [(int)$id]);
    }
    jsonSuccess([], 'บันทึกลำดับแล้ว');
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    $id = (int)($_POST['banner_id'] ?? 0);
    $b  = $db->fetchOne("SELECT is_active FROM banners WHERE banner_id = ?", [$id]);
    if ($b) { $db->update('banners', ['is_active' => $b['is_active'] ? 0 : 1], 'banner_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
    jsonError('ไม่พบแบนเนอร์');
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['banner_id'] ?? 0);
    $b  = $db->fetchOne("SELECT * FROM banners WHERE banner_id = ?", [$id]);
    if ($b) {
        $db->delete('banners','banner_id = ?',[$id]);
        fileQueueDelete($b['image']); fileCommitChanges();   // the file goes only once the row is really gone
        jsonSuccess([], 'ลบแบนเนอร์แล้ว');
    }
    jsonError('ไม่พบแบนเนอร์');
}

// Add new banner, or edit an existing one — editing only replaces the
// image if a new one was actually chosen, same pattern as forms/index.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $id      = (int)($_POST['banner_id'] ?? 0);
    $title   = postStr('title');
    $linkUrl = postStr('link_url');
    if ($linkUrl !== '' && !isHttpUrl($linkUrl)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://');

    if ($id) {
        $existing = $db->fetchOne("SELECT * FROM banners WHERE banner_id = ?", [$id]);
        if (!$existing) jsonError('ไม่พบแบนเนอร์');
        $data = ['title' => $title ?: null, 'link_url' => $linkUrl ?: null];
        if (!empty($_FILES['image']['name'])) {
            $up = uploadImage($_FILES['image'], 'banners');
            if (!$up) jsonError('อัปโหลดภาพไม่สำเร็จ');
            fileTrackNew($up); fileQueueDelete($existing['image']);   // the old picture goes only once the change is saved
            $data['image'] = $up;
        }
        $db->update('banners', $data, 'banner_id = ?', [$id]);
        fileCommitChanges();
        jsonSuccess([], 'แก้ไขแบนเนอร์เรียบร้อยแล้ว');
    } else {
        if (empty($_FILES['image']['name'])) jsonError('กรุณาเลือกภาพ');
        $up = uploadImage($_FILES['image'], 'banners');
        if (!$up) jsonError('อัปโหลดภาพไม่สำเร็จ');
        fileTrackNew($up);

        $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM banners")['m'] ?? 0);
        $db->insert('banners', [
            'image'         => $up,
            'title'         => $title ?: null,
            'link_url'      => $linkUrl ?: null,
            'display_order' => $maxOrder + 1,
            'is_active'     => 1,
        ]);
        fileCommitChanges();
        jsonSuccess(['image' => UPLOAD_URL.'/'.$up], 'เพิ่มแบนเนอร์เรียบร้อยแล้ว');
    }
}

// Marquee strip under the banner (stored in site_settings.marquee_options; panel hidden until the column exists)
$hasMarquee = (bool)$db->fetchOne("SHOW COLUMNS FROM site_settings LIKE 'marquee_options'");
if ($hasMarquee && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_marquee') {
    verifyCsrf();
    $words = preg_split('/\R/u', postRaw('words')) ?: [];
    $opt = marqueeOptions([
        'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        'words'   => $words,
        'bg' => $_POST['bg'] ?? '', 'fg' => $_POST['fg'] ?? '', 'mark_color' => $_POST['mark_color'] ?? '',
        'marker' => $_POST['marker'] ?? '', 'direction' => $_POST['direction'] ?? '',
        'speed' => $_POST['speed'] ?? '', 'size' => $_POST['size'] ?? '',
        'pause' => !empty($_POST['pause']) ? 1 : 0,
    ]);
    if ($opt['enabled'] && !$opt['words']) jsonError('กรุณาใส่ข้อความอย่างน้อย 1 คำ หรือปิดการแสดงแถบนี้');
    $data = ['marquee_options' => json_encode($opt, JSON_UNESCAPED_UNICODE)];
    $row = $db->fetchOne("SELECT setting_id FROM site_settings LIMIT 1");
    if ($row) $db->update('site_settings', $data, 'setting_id = ?', [$row['setting_id']]);
    else $db->insert('site_settings', $data);
    jsonSuccess([], 'บันทึกแถบข้อความวิ่งเรียบร้อยแล้ว');
}
$mqRow    = $hasMarquee ? $db->fetchOne("SELECT marquee_options FROM site_settings LIMIT 1") : null;
$mqOpt    = marqueeOptions($mqRow['marquee_options'] ?? null);
$mqChoice = marqueeChoices();

$banners = $db->fetchAll("SELECT * FROM banners ORDER BY display_order ASC");

$pageTitle  = 'จัดการแบนเนอร์';
$activeMenu = 'banners';
$breadcrumb = [['label'=>'จัดการแบนเนอร์']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>แบนเนอร์ทั้งหมด <span class="ne-count"><?=count($banners)?> รายการ</span></h1>
    <span class="ne-hint" style="margin:0;margin-left:auto;">ลาก-วาง เพื่อเรียงลำดับ</span>
  </div>

  <div class="ne-grid">
    <!-- Banners grid -->
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>แบนเนอร์ทั้งหมด</span></div>
        <div class="ne-panel-body">
          <div id="bannerGrid" class="row g-2">
            <?php foreach ($banners as $bi => $b): ?>
            <div class="col-md-6" data-id="<?=$b['banner_id']?>">
              <div class="banner-item border rounded overflow-hidden position-relative" style="cursor:grab">
                <img src="<?=UPLOAD_URL.'/'.e($b['image'])?>" class="w-100" style="height:120px;object-fit:cover" alt="">
                <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-end"
                     style="background:rgba(0,0,0,0.45)">
                  <div class="p-2 w-100">
                    <p class="mb-0 text-white small fw-500"><?=e($b['title']??'แบนเนอร์')?></p>
                    <?php if ($b['link_url']): ?>
                    <small class="text-white-50"><?=e(mb_strimwidth($b['link_url'],0,30,'...','UTF-8'))?></small>
                    <?php endif; ?>
                  </div>
                </div>
                <!-- Actions overlay -->
                <div class="position-absolute top-0 end-0 p-1 ne-actions">
                  <button class="ne-icon-btn ne-icon-edit" style="background:#fff;"
                          onclick="editBanner(<?=$b['banner_id']?>)" title="แก้ไข">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="ne-icon-btn <?=$b['is_active']?'ne-icon-edit':''?>"
                          style="background:#fff;<?=!$b['is_active']?'color:var(--ne-slate);':''?>"
                          onclick="toggleBanner(<?=$b['banner_id']?>)" title="<?=$b['is_active']?'ซ่อน':'แสดง'?>">
                    <i class="bi bi-<?=$b['is_active']?'eye':'eye-slash'?>"></i>
                  </button>
                  <button class="ne-icon-btn ne-icon-delete" style="background:#fff;"
                          onclick="deleteBanner(<?=$b['banner_id']?>)" title="ลบ">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
                <!-- Order badge -->
                <div class="position-absolute top-0 start-0 m-1">
                  <span class="ne-badge-count" data-pos title="ลำดับที่แสดงบนหน้าเว็บ" style="background:rgba(0,0,0,.6);color:#fff;">#<?= $bi + 1 ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($banners)): ?>
            <div class="col-12"><div class="ne-empty"><i class="bi bi-image"></i>ยังไม่มีแบนเนอร์</div></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid var(--ne-line);text-align:right;">
          <button class="ne-btn ne-btn-primary" onclick="saveOrder()">
            <i class="bi bi-save"></i>บันทึกลำดับ
          </button>
        </div>
      </div>

      <?php if ($hasMarquee): ?>
      <div class="ne-panel mt-3" id="marqueePanel">
        <div class="ne-panel-head"><span><i class="bi bi-chevron-double-right" style="color:var(--ne-blue);margin-right:6px;"></i>แถบข้อความวิ่งใต้แบนเนอร์</span></div>
        <div class="ne-panel-body">
          <div class="mqp-stage" id="mqPreview" aria-hidden="true"><div class="mqp-track"></div></div>
          <p class="ne-hint">ตัวอย่างแถบที่จะแสดงบนหน้าแรก (ปรับแล้วเห็นผลทันที กด "บันทึกแถบข้อความ" เพื่อใช้จริง)</p>

          <label class="d-flex align-items-center gap-2 mb-3" style="cursor:pointer;"><input type="checkbox" id="mqEnabled" <?= $mqOpt['enabled'] ? 'checked' : '' ?>> แสดงแถบข้อความวิ่งบนหน้าแรก</label>

          <div class="ne-field">
            <label for="mqWords">ข้อความ (บรรทัดละ 1 คำ)</label>
            <textarea class="ne-input" id="mqWords" rows="7" placeholder="ธุรกิจ&#10;เทคโนโลยี&#10;นวัตกรรม"><?= e(implode("\n", $mqOpt['words'])) ?></textarea>
            <p class="ne-hint">ควรเป็นคำสั้นๆ ไม่เกิน 3–4 คำต่อบรรทัด หน้าภาษาอังกฤษระบบแปลให้เอง หรือกำหนดเองโดยพิมพ์ <strong>|</strong> ตามด้วยคำอังกฤษ เช่น <strong>ธุรกิจ|Business</strong></p>
          </div>

          <div class="ne-opt-grid">
            <div class="ne-field">
              <label for="mqBg">สีพื้นหลัง</label>
              <div class="ne-color-row">
                <input type="color" class="ne-color-input" id="mqBg" value="<?= e($mqOpt['bg']) ?>">
                <div class="ne-swatches" id="mqSwatches">
                  <?php foreach (['#0A0A0A' => 'ดำ', '#0F439B' => 'น้ำเงินคณะ', '#31A7EC' => 'ฟ้า', '#FF914D' => 'ส้ม', '#FFFFFF' => 'ขาว', '#EEF0FA' => 'เทาอ่อน'] as $hex => $nm): ?>
                  <button type="button" class="ne-swatch" data-bg="<?= $hex ?>" title="<?= $nm ?>" aria-label="<?= $nm ?>" style="background:<?= $hex ?>;"></button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div class="ne-field">
              <label for="mqFg">สีตัวอักษร</label>
              <div class="ne-color-row"><input type="color" class="ne-color-input" id="mqFg" value="<?= e($mqOpt['fg']) ?>"></div>
            </div>
            <div class="ne-field">
              <label for="mqMarkColor">สีตัวคั่น</label>
              <div class="ne-color-row"><input type="color" class="ne-color-input" id="mqMarkColor" value="<?= e($mqOpt['mark_color']) ?>"></div>
            </div>
            <div class="ne-field">
              <label for="mqMarker">ตัวคั่นระหว่างคำ</label>
              <select class="ne-input" id="mqMarker"><?php foreach ($mqChoice['marker'] as $v => $lab): ?><option value="<?= e($v) ?>" <?= $mqOpt['marker'] === $v ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
            </div>
            <div class="ne-field">
              <label for="mqDirection">ทิศทางการวิ่ง</label>
              <select class="ne-input" id="mqDirection"><?php foreach ($mqChoice['direction'] as $v => $lab): ?><option value="<?= e($v) ?>" <?= $mqOpt['direction'] === $v ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
            </div>
            <div class="ne-field">
              <label for="mqSpeed">ความเร็ว</label>
              <select class="ne-input" id="mqSpeed"><?php foreach ($mqChoice['speed'] as $v => $lab): ?><option value="<?= e($v) ?>" <?= $mqOpt['speed'] === $v ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
            </div>
            <div class="ne-field">
              <label for="mqSize">ขนาดตัวอักษร</label>
              <select class="ne-input" id="mqSize"><?php foreach ($mqChoice['size'] as $v => $lab): ?><option value="<?= e($v) ?>" <?= $mqOpt['size'] === $v ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
            </div>
          </div>

          <label class="d-flex align-items-center gap-2 mb-2" style="cursor:pointer;"><input type="checkbox" id="mqPause" <?= $mqOpt['pause'] ? 'checked' : '' ?>> หยุดวิ่งเมื่อเอาเมาส์ชี้</label>
          <p class="ne-hint">ผู้ชมที่ตั้งค่า "ลดการเคลื่อนไหว" ในเครื่อง จะเห็นข้อความเรียงนิ่งๆ แทนการวิ่งโดยอัตโนมัติ</p>
          <div style="text-align:right;margin-top:14px;">
            <button class="ne-btn ne-btn-primary" id="mqSaveBtn" onclick="saveMarquee()"><i class="bi bi-save"></i>บันทึกแถบข้อความ</button>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="ne-alert mt-3"><i class="bi bi-info-circle"></i> ตัวเลือก "แถบข้อความวิ่ง" ยังไม่พร้อมใช้งาน ต้องอัปเดตฐานข้อมูลก่อน (รันไฟล์ site_settings_marquee_options.sql) กรุณาแจ้งผู้ดูแลระบบ</div>
      <?php endif; ?>
    </div>

    <!-- Add / edit form -->
    <div class="ne-side">
      <div class="ne-panel">
        <div class="ne-panel-head">
          <span id="bannerFormTitle"><i class="bi bi-upload" style="color:var(--ne-blue);margin-right:6px;"></i>เพิ่มแบนเนอร์ใหม่</span>
        </div>
        <div class="ne-panel-body">
          <input type="hidden" id="bannerId" value="0">
          <div class="ne-field">
            <label for="bannerFile">ภาพแบนเนอร์ <span id="bannerImageRequiredMark" style="color:var(--ne-danger)">*</span></label>
            <input type="file" data-has-preview class="form-control" id="bannerFile" accept="image/*" onchange="previewBanner(this)">
            <img id="bannerPreview" class="img-fluid rounded mt-2 d-none" alt="">
            <p class="ne-hint" id="bannerImageHint"><strong>ต้องใช้ภาพขนาด 1920×600 พิกเซล</strong> (อัตราส่วน 16:5) เท่านั้น ไม่เกิน 5MB — หน้าเว็บจะแสดงผลที่อัตราส่วนนี้เพียงขนาดเดียว ภาพที่ไม่ตรงสัดส่วนจะถูกครอปให้พอดีอัตโนมัติ</p>
          </div>
          <div class="ne-field">
            <label for="bannerTitle">ชื่อแบนเนอร์</label>
            <input type="text" class="ne-input" id="bannerTitle" placeholder="ชื่อแบนเนอร์">
          </div>
          <div class="ne-field">
            <label for="bannerLink">ลิงก์ (เมื่อคลิก)</label>
            <input type="url" class="ne-input" id="bannerLink" placeholder="https://...">
          </div>
          <button class="ne-btn ne-btn-primary ne-btn-block" id="bannerSaveBtn" onclick="saveBanner()">
            <i class="bi bi-plus-lg"></i>เพิ่มแบนเนอร์
          </button>
          <button class="ne-btn ne-btn-ghost ne-btn-block mt-2 d-none" id="bannerCancelEditBtn" onclick="cancelBannerEdit()">
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
const bannersData = ' . json_encode(array_map(fn($b) => [
    'id'       => (int)$b['banner_id'],
    'title'    => $b['title'],
    'link_url' => $b['link_url'],
    'image'    => $b['image'],
], $banners), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';

// Sortable
// the badge on each banner is its position in the list (1, 2, 3 ...) and follows the dragging; "บันทึกลำดับ" stores it
function renumberBanners() {
  document.querySelectorAll("#bannerGrid [data-id]").forEach((el, i) => { const b = el.querySelector("[data-pos]"); if (b) b.textContent = "#" + (i + 1); });
}
new Sortable(document.getElementById("bannerGrid"), {
  animation: 150, ghostClass: "opacity-50", onEnd: renumberBanners
});

function saveOrder() {
  const ids = [...document.querySelectorAll("#bannerGrid [data-id]")].map(el => el.dataset.id);
  const fd  = new FormData();
  fd.append("action","reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function toggleBanner(id) {
  const fd = new FormData();
  fd.append("action","toggle"); fd.append("banner_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
}

async function deleteBanner(id) {
  const r = await Swal.fire({title:"ลบแบนเนอร์?",icon:"warning",showCancelButton:true,
    confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("banner_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
}

function previewBanner(input) {
  const prev = document.getElementById("bannerPreview");
  if (input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => { prev.src = e.target.result; prev.classList.remove("d-none"); };
    reader.readAsDataURL(input.files[0]);
  }
}

function editBanner(id) {
  const b = bannersData.find(x => x.id === id);
  if (!b) return;
  document.getElementById("bannerId").value = b.id;
  document.getElementById("bannerTitle").value = b.title || "";
  document.getElementById("bannerLink").value = b.link_url || "";
  document.getElementById("bannerFile").value = "";
  document.getElementById("bannerImageRequiredMark").classList.add("d-none");
  document.getElementById("bannerImageHint").innerHTML = "เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนภาพ ไม่เลือกจะคงภาพเดิมไว้ — ถ้าเปลี่ยน <strong>ต้องใช้ภาพขนาด 1920×600 พิกเซล</strong> (อัตราส่วน 16:5) ไม่เกิน 5MB";
  const prev = document.getElementById("bannerPreview");
  prev.src = ' . json_encode(UPLOAD_URL . '/') . ' + b.image;
  prev.classList.remove("d-none");
  document.getElementById("bannerFormTitle").innerHTML = "<i class=\"bi bi-pencil-square\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>แก้ไขแบนเนอร์";
  document.getElementById("bannerSaveBtn").innerHTML = "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข";
  document.getElementById("bannerCancelEditBtn").classList.remove("d-none");
  document.querySelector(".ne-side").scrollIntoView({behavior:"smooth",block:"start"});
}

function cancelBannerEdit() {
  document.getElementById("bannerId").value = "0";
  document.getElementById("bannerTitle").value = "";
  document.getElementById("bannerLink").value = "";
  document.getElementById("bannerFile").value = "";
  document.getElementById("bannerImageRequiredMark").classList.remove("d-none");
  document.getElementById("bannerImageHint").innerHTML = "<strong>ต้องใช้ภาพขนาด 1920×600 พิกเซล</strong> (อัตราส่วน 16:5) เท่านั้น ไม่เกิน 5MB — หน้าเว็บจะแสดงผลที่อัตราส่วนนี้เพียงขนาดเดียว ภาพที่ไม่ตรงสัดส่วนจะถูกครอปให้พอดีอัตโนมัติ";
  document.getElementById("bannerPreview").classList.add("d-none");
  document.getElementById("bannerFormTitle").innerHTML = "<i class=\"bi bi-upload\" style=\"color:var(--ne-blue);margin-right:6px;\"></i>เพิ่มแบนเนอร์ใหม่";
  document.getElementById("bannerSaveBtn").innerHTML = "<i class=\"bi bi-plus-lg\"></i>เพิ่มแบนเนอร์";
  document.getElementById("bannerCancelEditBtn").classList.add("d-none");
}

async function saveBanner() {
  const id   = document.getElementById("bannerId").value;
  const file = document.getElementById("bannerFile").files[0];
  if (id === "0" && !file) { showError("กรุณาเลือกภาพ"); return; }
  const fd = new FormData();
  fd.append("action","save");
  fd.append("banner_id",id);
  if (file) fd.append("image",file);
  fd.append("title",document.getElementById("bannerTitle").value);
  fd.append("link_url",document.getElementById("bannerLink").value);
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1500); }
  else showError(r.data.message);
}

/* ---- Marquee strip: live preview + save ---- */
(function () {
  const stage = document.getElementById("mqPreview");
  if (!stage) return;
  const $ = id => document.getElementById(id);
  const track = stage.querySelector(".mqp-track");
  const speeds = {slow: 30, normal: 50, fast: 85};
  const sizes = {sm: 13, md: 16, lg: 20};
  const markers = {diamond: "mqp-diamond", dot: "mqp-dot", star: "mqp-star", slash: "mqp-slash", none: "mqp-none"};
  function words() {
    return $("mqWords").value.split(/\r?\n/).map(s => s.trim().split("|")[0].trim()).filter(Boolean).slice(0, 24);
  }
  function render() {
    const list = words();
    stage.style.background = $("mqBg").value;
    stage.style.color = $("mqFg").value;
    stage.style.setProperty("--mq-mark", $("mqMarkColor").value);
    stage.style.opacity = $("mqEnabled").checked ? "1" : ".45";
    stage.className = "mqp-stage " + markers[$("mqMarker").value] + " mqp-" + $("mqSize").value;
    track.innerHTML = "";
    if (!list.length) { track.textContent = "(ยังไม่มีข้อความ)"; track.style.animation = "none"; return; }
    let unit = 0; list.forEach(w => unit += w.length * sizes[$("mqSize").value] * 0.58 + 40);
    const reps = Math.max(2, Math.ceil(900 / Math.max(unit, 80)));
    const ul = () => { const u = document.createElement("ul"); for (let r = 0; r < reps; r++) list.forEach(w => { const li = document.createElement("li"); li.textContent = w; u.appendChild(li); }); return u; };
    track.appendChild(ul()); track.appendChild(ul());
    track.style.animation = "none"; void track.offsetWidth;
    track.style.animation = "mqp-slide " + Math.max(6, Math.round(reps * unit / speeds[$("mqSpeed").value])) + "s linear infinite";
    track.style.animationDirection = $("mqDirection").value === "right" ? "reverse" : "normal";
  }
  function luminance(hex) {
    const n = parseInt(hex.slice(1), 16), c = [n >> 16 & 255, n >> 8 & 255, n & 255].map(v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); });
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2];
  }
  document.querySelectorAll("#mqSwatches .ne-swatch").forEach(b => b.addEventListener("click", () => {
    $("mqBg").value = b.dataset.bg;
    $("mqFg").value = luminance(b.dataset.bg) > .4 ? "#111111" : "#FFFFFF";   // keep the text readable on the chosen colour
    if ($("mqMarkColor").value.toLowerCase() === b.dataset.bg.toLowerCase()) $("mqMarkColor").value = $("mqFg").value;   // never hide the separators
    render();
  }));
  document.querySelectorAll("#marqueePanel input, #marqueePanel select, #marqueePanel textarea").forEach(el => el.addEventListener("input", render));
  render();

  window.saveMarquee = async function () {
    const btn = $("mqSaveBtn"); btn.disabled = true;
    const fd = new FormData();
    fd.append("action", "save_marquee");
    if ($("mqEnabled").checked) fd.append("enabled", "1");
    if ($("mqPause").checked) fd.append("pause", "1");
    fd.append("words", $("mqWords").value);
    [["bg", "mqBg"], ["fg", "mqFg"], ["mark_color", "mqMarkColor"], ["marker", "mqMarker"], ["direction", "mqDirection"], ["speed", "mqSpeed"], ["size", "mqSize"]].forEach(p => fd.append(p[0], $(p[1]).value));
    fd.append("csrf_token", csrf);
    try {
      const r = await axios.post("", fd);
      if (r.data.status === "success") showSuccess(r.data.message); else showError(r.data.message);
    } catch (e) { showError("บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"); }
    btn.disabled = false;
  };
})();
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

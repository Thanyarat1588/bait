<?php
/**
 * BAIT Admin - "สารสนเทศ" (external system links) Management
 * Split out of the old "แบบฟอร์มดาวน์โหลด" module, which used to also allow
 * a link_url in place of a file — external systems (ระบบทะเบียน, ระบบส่ง
 * เกรด, ฯลฯ) are a different kind of thing from a downloadable document,
 * so they get their own table/page/module instead of sharing `forms`.
 * CRUD flow mirrors admin/modules/highlights/index.php (reorder, toggle,
 * delete, add/edit-in-sidebar), with icon a Bootstrap Icons slug (typed or
 * picked from a curated grid) instead of a fixed enum, plus an optional
 * uploaded icon image that overrides it when set.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// The public site is framework-free (no Bootstrap — see layout/header.php's
// own header comment), so icon names here can't be arbitrary Bootstrap
// Icons slugs like the rest of this admin uses: info.php has to render them
// from the site's own small custom SVG sprite (layout/icons.php), which
// only has a fixed, curated set of symbols. Mapped here to a Bootstrap
// Icons class purely so this picker's buttons have something to preview
// with (admin already loads that font) — the value actually saved/used is
// the sprite slug on the left. Anything beyond this fixed set has to go
// through the icon image upload instead, which is not limited this way.
$iconOptions = [
    'book' => 'bi-book', 'building' => 'bi-building', 'briefcase' => 'bi-briefcase',
    'calendar' => 'bi-calendar3', 'mail' => 'bi-envelope', 'phone' => 'bi-telephone',
    'users' => 'bi-people', 'graduation-cap' => 'bi-mortarboard', 'globe' => 'bi-globe',
    'shield-check' => 'bi-shield-check', 'home' => 'bi-house-door', 'award' => 'bi-award',
    'diagram' => 'bi-diagram-3', 'list' => 'bi-card-checklist', 'target' => 'bi-bullseye',
    'download' => 'bi-download', 'link' => 'bi-link-45deg', 'star' => 'bi-star',
];

// AJAX reorder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    verifyCsrf();
    $ids = json_decode(postRaw('ids', '[]'), true); if (!is_array($ids)) $ids = [];
    foreach ($ids as $order => $id) {
        $db->update('info_links', ['display_order' => $order + 1], 'link_id = ?', [(int)$id]);
    }
    jsonSuccess([], 'บันทึกลำดับแล้ว');
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    $id = (int)($_POST['link_id'] ?? 0);
    $l  = $db->fetchOne("SELECT is_active FROM info_links WHERE link_id = ?", [$id]);
    if ($l) { $db->update('info_links', ['is_active' => $l['is_active'] ? 0 : 1], 'link_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
    jsonError('ไม่พบรายการ');
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['link_id'] ?? 0);
    $l  = $db->fetchOne("SELECT * FROM info_links WHERE link_id = ?", [$id]);
    if ($l) {
        $db->delete('info_links','link_id = ?',[$id]);
        fileQueueDelete($l['icon_image']); fileCommitChanges();   // the file goes only once the row is really gone
        jsonSuccess([], 'ลบรายการแล้ว');
    }
    jsonError('ไม่พบรายการ');
}

// Add new, or edit an existing one — icon image upload is optional either
// way; editing only replaces it if a new one was actually chosen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $id       = (int)($_POST['link_id'] ?? 0);
    $title    = postStr('title');
    $linkUrl  = postStr('link_url');
    if ($linkUrl !== '' && !isHttpUrl($linkUrl)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://');
    $audience = ($_POST['audience'] ?? 'student') === 'staff' ? 'staff' : 'student';
    // Must be one of the fixed sprite slugs above — info.php renders this
    // from the public site's own small custom icon set, not free-typed
    // Bootstrap Icons, so anything unrecognized falls back to a default
    // rather than saving a slug that can't actually render.
    $icon = array_key_exists($_POST['icon'] ?? '', $iconOptions) ? $_POST['icon'] : 'link';

    if (!$title) jsonError('กรุณากรอกชื่อรายการ');
    if (!$linkUrl) jsonError('กรุณากรอกลิงก์');
    $chkAud = $id ? (string)($db->fetchOne('SELECT audience FROM info_links WHERE link_id = ?', [$id])['audience'] ?? $audience) : $audience;
    $audWord = $chkAud === 'staff' ? 'บุคลากร' : 'นักศึกษา';
    if (rowExists($db, 'info_links', ['title' => $title, 'audience' => $chkAud], 'link_id', $id)) jsonError('มีรายการ "' . $title . '" ในสารสนเทศสำหรับ' . $audWord . 'อยู่แล้ว กรุณาใช้ชื่ออื่น หรือแก้ไขรายการเดิมแทนการเพิ่มใหม่');
    if (rowExists($db, 'info_links', ['link_url' => $linkUrl, 'audience' => $chkAud], 'link_id', $id)) jsonError('ลิงก์นี้มีอยู่แล้วในสารสนเทศสำหรับ' . $audWord . ' กรุณาตรวจสอบรายการเดิม');
    if (!filter_var($linkUrl, FILTER_VALIDATE_URL)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ให้ครบ เช่น https://...');

    $iconImagePath = null;
    if (!empty($_FILES['icon_image']['name'])) {
        $up = uploadMedia($_FILES['icon_image'], 'info-links');
        if ($up) fileTrackNew($up['path']);   // first: a video/PDF refused below must not stay behind in uploads/
        if (!$up || $up['type'] !== 'image') jsonError('อัปโหลดรูปไอคอนไม่สำเร็จ (รองรับ JPG, PNG, GIF, WEBP, SVG)');
        $iconImagePath = $up['path'];
        fileTrackNew($iconImagePath);   // dropped again if this save does not go through
    }
    // "ลบรูปไอคอนนี้" on an existing custom image — clears it back to the
    // picked sprite icon. A fresh upload always wins if both are sent.
    $removeIconImage = empty($iconImagePath) && !empty($_POST['remove_image']);

    if ($id) {
        $existing = $db->fetchOne("SELECT * FROM info_links WHERE link_id = ?", [$id]);
        if (!$existing) jsonError('ไม่พบรายการ');
        $data = ['title' => $title, 'link_url' => $linkUrl, 'icon' => $icon];   // the group (audience) of an existing item is never changed here
        if ($iconImagePath) {
            $data['icon_image'] = $iconImagePath;
            fileQueueDelete($existing['icon_image']);   // the old picture goes only once the change is saved
        } elseif ($removeIconImage) {
            $data['icon_image'] = null;
            fileQueueDelete($existing['icon_image']);
        }
        $db->update('info_links', $data, 'link_id = ?', [$id]);
        fileCommitChanges();
        jsonSuccess([], 'แก้ไขรายการเรียบร้อยแล้ว');
    } else {
        $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM info_links")['m'] ?? 0);
        $db->insert('info_links', [
            'title'         => $title,
            'link_url'      => $linkUrl,
            'audience'      => $audience,
            'icon'          => $icon,
            'icon_image'    => $iconImagePath,
            'display_order' => $maxOrder + 1,
            'is_active'     => 1,
        ]);
        fileCommitChanges();
        jsonSuccess([], 'เพิ่มรายการเรียบร้อยแล้ว');
    }
}

$audFilter = in_array($_GET['audience'] ?? '', ['student', 'staff'], true) ? $_GET['audience'] : '';
if ($audFilter === '') { header('Location: index.php?audience=student'); exit; }   // each group has its own menu entry
$audLabel  = $audFilter === 'staff' ? 'บุคลากร' : 'นักศึกษา';
$links = $db->fetchAll("SELECT * FROM info_links WHERE audience = ? ORDER BY display_order ASC", [$audFilter]);

$pageTitle  = 'สารสนเทศสำหรับ' . $audLabel;
$activeMenu = 'info-' . $audFilter;
$breadcrumb = [['label'=>'สารสนเทศ'], ['label'=>'สำหรับ' . $audLabel]];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>สารสนเทศสำหรับ<?= $audLabel ?> <span class="ne-count"><?=count($links)?> รายการ</span></h1>
    <div class="d-flex align-items-center gap-3" style="margin-left:auto;">
      <span class="ne-hint" style="margin:0;">ลาก-วาง เพื่อเรียงลำดับ</span>
      <button type="button" class="ne-btn ne-btn-primary" onclick="openLinkModal(0)">
        <i class="bi bi-plus-lg"></i>เพิ่มรายการใหม่
      </button>
    </div>
  </div>

    <div class="ne-alert ne-alert-info" style="margin-bottom:16px;"><i class="bi bi-info-circle"></i> รายการที่เพิ่มในหน้านี้จะแสดงให้ <b>ผู้เข้าชมกลุ่ม<?= $audLabel ?></b> เท่านั้น ถ้าต้องการเพิ่มของอีกกลุ่ม ให้เลือกจากเมนู <b>สารสนเทศ → สำหรับ<?= $audFilter === 'staff' ? 'นักศึกษา' : 'บุคลากร' ?></b> ด้านซ้าย</div>

<div class="ne-panel">
        <div class="ne-panel-head"><span>รายการสารสนเทศ</span></div>
        <div class="ne-panel-body">
          <div id="linkGrid" class="row g-2">
            <?php foreach ($links as $l): ?>
            <div class="col-md-4" data-id="<?=$l['link_id']?>">
              <div class="highlight-item border rounded overflow-hidden position-relative" style="cursor:grab">
                <div style="height:120px;display:flex;align-items:center;justify-content:center;background:#f8f9fc;padding:12px">
                  <?php if ($l['icon_image']): ?>
                  <img src="<?=UPLOAD_URL.'/'.e($l['icon_image'])?>" style="max-width:70%;max-height:70%;object-fit:contain" alt="">
                  <?php else: ?>
                  <i class="bi <?=e($iconOptions[$l['icon']] ?? 'bi-link-45deg')?>" style="font-size:2.6rem;color:var(--ne-blue)"></i>
                  <?php endif; ?>
                </div>
                <div class="position-absolute bottom-0 start-0 w-100 p-2" style="background:rgba(0,0,0,0.45)">
                  <p class="mb-0 text-white small fw-500"><?=e($l['title'])?></p>
                  <small class="text-white-50">สำหรับ<?= $audLabel ?></small>
                </div>
                <div class="position-absolute top-0 end-0 p-1 ne-actions">
                  <button class="ne-icon-btn ne-icon-edit" style="background:#fff;"
                          onclick="editLink(<?=$l['link_id']?>)" title="แก้ไข">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="ne-icon-btn <?=$l['is_active']?'ne-icon-edit':''?>"
                          style="background:#fff;<?=!$l['is_active']?'color:var(--ne-slate);':''?>"
                          onclick="toggleLink(<?=$l['link_id']?>)" title="<?=$l['is_active']?'ซ่อน':'แสดง'?>">
                    <i class="bi bi-<?=$l['is_active']?'eye':'eye-slash'?>"></i>
                  </button>
                  <button class="ne-icon-btn ne-icon-delete" style="background:#fff;"
                          onclick="deleteLink(<?=$l['link_id']?>)" title="ลบ">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
                <div class="position-absolute top-0 start-0 m-1">
                  <span class="ne-badge-count" style="background:rgba(0,0,0,.6);color:#fff;">#<?=$l['display_order']?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($links)): ?>
            <div class="col-12"><div class="ne-empty"><i class="bi bi-diagram-2"></i>ยังไม่มีรายการสารสนเทศ</div></div>
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

<!-- Add/edit modal — same form used for both, triggered by the "เพิ่ม
     รายการใหม่" topbar button (add) or a card's pencil icon (edit). -->
<div class="modal fade" id="linkModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content ne-modal-content ne">
      <div class="modal-header ne-modal-header">
        <h5 class="modal-title" id="linkModalTitle" style="font-family:'Kanit',serif;">เพิ่มรายการใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body ne-modal-body">
        <input type="hidden" id="linkId" value="0">
        <div class="ne-field">
          <label for="linkTitle">ชื่อระบบ/รายการ <span style="color:var(--ne-danger)">*</span></label>
          <input type="text" class="ne-input" id="linkTitle" placeholder="เช่น ระบบส่งเกรด">
        </div>
        <div class="ne-field">
          <label for="linkUrl">ลิงก์ <span style="color:var(--ne-danger)">*</span></label>
          <input type="url" class="ne-input" id="linkUrl" placeholder="https://...">
        </div>
        <div class="ne-field">
          <label>กลุ่มเป้าหมาย</label>
          <div><span class="ne-chip"><i class="bi bi-<?= $audFilter === 'staff' ? 'person-badge' : 'mortarboard' ?>"></i> สำหรับ<?= $audLabel ?></span></div>
          <p class="ne-hint">กำหนดตามหน้าที่เปิดอยู่ (เมนู สารสนเทศ → สำหรับ<?= $audLabel ?>) ไม่ต้องเลือก</p>
        </div>
        <div class="ne-field">
          <label>ไอคอน</label>
          <input type="hidden" id="linkIcon" value="link">
          <div class="cluster gap-2" style="align-items:center;margin-bottom:8px;">
            <span id="iconPreview" class="bi bi-link-45deg" style="font-size:1.6rem;color:var(--ne-blue);width:32px;text-align:center;"></span>
            <span class="ne-hint" style="margin:0;">เลือกจากตัวอย่างด้านล่าง หรืออัปโหลดรูปไอคอนเองแทน</span>
          </div>
          <div id="iconPicker" style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($iconOptions as $slug => $biClass): ?>
            <button type="button" class="ne-icon-btn" data-icon="<?=e($slug)?>" onclick="pickIcon('<?=e($slug)?>')" title="<?=e($slug)?>"><i class="bi <?=e($biClass)?>"></i></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="ne-field" style="margin-bottom:0;">
          <label for="linkIconImage">หรืออัปโหลดรูปไอคอนเอง (ถ้ามี จะใช้แทนไอคอนด้านบน)</label>
          <div style="background:var(--ne-blue-soft);color:var(--ne-blue-deep);padding:8px 12px;border-radius:8px;margin-bottom:8px;font-size:12.5px;line-height:1.6;">
            <i class="bi bi-info-circle"></i> ให้เข้าชุดกับไอคอนอื่นในเว็บ ใช้ไอคอนสไตล์เส้น (outline) จาก
            <a href="https://feathericons.com/" target="_blank" rel="noopener">feathericons.com</a>
            ดาวน์โหลดเป็นไฟล์ SVG แนะนำที่สุด — ระบบจะปรับสีให้เข้ากับธีมเว็บอัตโนมัติเหมือนไอคอนอื่นๆ (ไม่ต้องแก้สีเอง) ถ้าเป็น PNG/WEBP ต้องเซฟสีเส้นมาให้ตรงกับธีมเว็บเองก่อนอัปโหลด
          </div>
          <input type="file" data-has-preview class="form-control" id="linkIconImage" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" onchange="previewIconImage(this)">
          <input type="hidden" id="linkIconRemoveImage" value="0">
          <div id="iconImagePreviewWrap" class="d-none cluster gap-2 mt-2" style="align-items:center;">
            <img id="iconImagePreview" class="img-fluid rounded" style="max-height:80px;object-fit:contain;background:#f8f9fc;padding:8px" alt="">
            <button type="button" class="ne-btn ne-btn-ghost" style="padding:6px 12px;font-size:12.5px;" onclick="removeIconImage()"><i class="bi bi-trash"></i>ลบรูปนี้</button>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
        <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="ne-btn ne-btn-primary" id="saveBtn" onclick="saveLink()">
          <i class="bi bi-plus-lg"></i>เพิ่มรายการ
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
const csrf = ' . json_encode(csrfToken()) . ';
const iconMap = ' . json_encode($iconOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
const links = ' . json_encode(array_map(fn($l) => [
    'id'         => (int)$l['link_id'],
    'title'      => $l['title'],
    'link_url'   => $l['link_url'],
    'audience'   => $l['audience'],
    'icon'       => $l['icon'],
    'icon_image' => $l['icon_image'],
], $links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';

new Sortable(document.getElementById("linkGrid"), {
  animation: 150, ghostClass: "opacity-50"
});

function saveOrder() {
  const ids = [...document.querySelectorAll("#linkGrid [data-id]")].map(el => el.dataset.id);
  const fd  = new FormData();
  fd.append("action","reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function toggleLink(id) {
  const fd = new FormData();
  fd.append("action","toggle"); fd.append("link_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
}

async function deleteLink(id) {
  const r = await Swal.fire({title:"ลบรายการนี้?",icon:"warning",showCancelButton:true,
    confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("link_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
}

function pickIcon(name) {
  document.getElementById("linkIcon").value = name;
  updateIconPreview();
}
function updateIconPreview() {
  const name = document.getElementById("linkIcon").value || "link";
  document.getElementById("iconPreview").className = "bi " + (iconMap[name] || "bi-link-45deg");
  document.querySelectorAll("#iconPicker [data-icon]").forEach(b => b.classList.toggle("ne-icon-edit", b.dataset.icon === name));
}

function previewIconImage(input) {
  const wrap = document.getElementById("iconImagePreviewWrap");
  const img  = document.getElementById("iconImagePreview");
  const file = input.files[0];
  document.getElementById("linkIconRemoveImage").value = "0";
  if (!file) { wrap.classList.add("d-none"); return; }
  img.src = URL.createObjectURL(file);
  wrap.classList.remove("d-none");
}

function removeIconImage() {
  document.getElementById("linkIconRemoveImage").value = "1";
  document.getElementById("iconImagePreviewWrap").classList.add("d-none");
  document.getElementById("linkIconImage").value = "";
}

let linkModal;

function openLinkModal(id) {
  const l = id ? links.find(x => x.id === id) : null;
  document.getElementById("linkId").value = l ? l.id : 0;
  document.getElementById("linkTitle").value = l ? l.title : "";
  document.getElementById("linkUrl").value = l ? l.link_url : "";
  document.getElementById("linkIcon").value = l ? l.icon : "link";
  updateIconPreview();
  document.getElementById("linkIconImage").value = "";
  document.getElementById("linkIconRemoveImage").value = "0";
  const wrap = document.getElementById("iconImagePreviewWrap");
  if (l && l.icon_image) { document.getElementById("iconImagePreview").src = ' . json_encode(UPLOAD_URL . '/') . ' + l.icon_image; wrap.classList.remove("d-none"); }
  else { wrap.classList.add("d-none"); }
  document.getElementById("linkModalTitle").textContent = l ? "แก้ไขรายการสารสนเทศ" : "เพิ่มรายการใหม่";
  document.getElementById("saveBtn").innerHTML = l
    ? "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข"
    : "<i class=\"bi bi-plus-lg\"></i>เพิ่มรายการ";
  linkModal = new bootstrap.Modal(document.getElementById("linkModal"));
  linkModal.show();
}

function editLink(id) {
  openLinkModal(id);
}

async function saveLink() {
  const id    = document.getElementById("linkId").value;
  const title = document.getElementById("linkTitle").value.trim();
  const url   = document.getElementById("linkUrl").value.trim();
  const file  = document.getElementById("linkIconImage").files[0];
  if (!title) { showError("กรุณากรอกชื่อรายการ"); return; }
  if (!url) { showError("กรุณากรอกลิงก์"); return; }
  const fd = new FormData();
  fd.append("action","save");
  fd.append("link_id",id);
  fd.append("title",title);
  fd.append("link_url",url);
  fd.append("audience", ' . json_encode($audFilter) . ');
  fd.append("icon",document.getElementById("linkIcon").value.trim() || "link-45deg");
  if (file) fd.append("icon_image",file);
  else if (document.getElementById("linkIconRemoveImage").value === "1") fd.append("remove_image","1");
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { linkModal.hide(); showSuccess(r.data.message); setTimeout(()=>location.reload(),1500); }
  else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

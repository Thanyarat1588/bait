<?php
/**
 * BAIT Admin - คณะกรรมการประจำคณะ (faculty committee)
 * A member is either one of the faculty's staff (their name, photo and position come from the staff record, so they stay
 * up to date; once they resign they disappear from the public chart) or an outside person (ผู้ทรงคุณวุฒิภายนอก ...:
 * name / position-affiliation / photo typed here). Shown on the public staff page, tab "คณะกรรมการประจำคณะ", as a chart:
 * the chair on top, everyone else below in role order. Needs migration step 14 (table committee_members).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db    = Database::getInstance();
$ready = hasCommittee();
$roles = committeeRoles();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = postStr('action');

    if ($action === 'save') {
        $id      = (int)postStr('member_id');
        $kind    = postStr('kind') === 'external' ? 'external' : 'staff';
        $role    = postStr('role');
        $order   = max(0, min(9999, (int)postStr('sort_order')));
        if (!isset($roles[$role])) jsonError('กรุณาเลือกตำแหน่งในคณะกรรมการ');
        $existing = null;
        if ($id) {
            $existing = $db->fetchOne('SELECT * FROM committee_members WHERE member_id = ?', [$id]);
            if (!$existing) jsonError('ไม่พบรายชื่อนี้ อาจถูกลบไปแล้ว กรุณาโหลดหน้าใหม่');
        }
        $data = ['role' => $role, 'sort_order' => $order];
        if ($kind === 'staff') {
            $staffId = (int)postStr('staff_id');
            if (!$staffId) jsonError('กรุณาเลือกบุคลากร');
            // someone already on the committee who has since resigned can still have their role / order edited
            // (they are hidden on the website anyway); only a newly picked person must be current staff
            $samePerson = $existing && (int)$existing['staff_id'] === $staffId;
            if (!$db->fetchOne('SELECT 1 FROM staff s WHERE s.staff_id = ?' . ($samePerson ? '' : ' AND ' . staffCurrentSql('s')), [$staffId])) jsonError('ไม่พบบุคลากรที่เลือก (หรือลาออกแล้ว) กรุณาเลือกใหม่');
            if ($db->fetchOne('SELECT 1 FROM committee_members WHERE staff_id = ? AND member_id <> ?', [$staffId, $id])) jsonError('บุคลากรคนนี้อยู่ในคณะกรรมการแล้ว แก้ไขรายการเดิมได้เลย');
            $data += ['staff_id' => $staffId, 'name' => '', 'title' => '', 'image' => null];
            if (hasCommitteeNameEn()) $data['name_en'] = '';   // a staff member's English name comes from their staff record
            if ($existing && $existing['image']) fileQueueDelete($existing['image']);   // was an outside person with a photo
        } else {
            $name  = postStr('name');
            $title = postStr('title');
            if ($name === '') jsonError('กรุณากรอกชื่อ-นามสกุลของกรรมการภายนอก');
            $data += ['staff_id' => null, 'name' => $name, 'title' => $title];
            if (hasCommitteeNameEn()) {
                // shown as typed on the English page (title included, e.g. Assoc. Prof. Dr. Somchai Jaidee); empty = Thai name
                $nameEn = trim(preg_replace('/\s+/', ' ', postStr('name_en')));
                if ($nameEn !== '' && !preg_match('/^[A-Za-z][A-Za-z .\'-]*$/', $nameEn)) jsonError('ชื่อภาษาอังกฤษใช้ได้เฉพาะตัวอักษรอังกฤษ เว้นวรรค จุด และขีด');
                $data['name_en'] = mb_substr($nameEn, 0, 255);
            }
            $image = $existing['image'] ?? null;
            $imageData = postStr('image_data');
            if ($imageData !== '') {
                // framed in the browser (same circle cropper as the staff form): a square JPEG as a data URL
                $raw = preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/', $imageData, $dm) ? base64_decode($dm[2], true) : false;
                if ($raw === false || !@getimagesizefromstring($raw)) jsonError('รูปที่ปรับตำแหน่งไม่ถูกต้อง กรุณาเลือกรูปใหม่');
                $up = uploadImageFromDataUrl($imageData, 'committee');
                if (!$up) jsonError('บันทึกรูปไม่สำเร็จ (ไม่เกิน 5MB) กรุณาลองใหม่');
                fileTrackNew($up);
                if ($image) fileQueueDelete($image);
                $image = $up;
            } elseif (!empty($_FILES['image']['name'])) {
                $up = uploadImage($_FILES['image'], 'committee');
                if (!$up) jsonError('อัปโหลดรูปไม่สำเร็จ (รองรับไฟล์รูปภาพ เช่น JPG, PNG, WEBP ไม่เกิน 5MB)');
                fileTrackNew($up);
                if ($image) fileQueueDelete($image);
                $image = $up;
            } elseif (postStr('remove_image') === '1' && $image) {
                fileQueueDelete($image);
                $image = null;
            }
            $data['image'] = $image;
        }
        try {
            if ($id) $db->update('committee_members', $data, 'member_id = ?', [$id]);
            else     $db->insert('committee_members', $data);
        } catch (Throwable $e) {
            fileRollbackChanges();
            throw $e;
        }
        fileCommitChanges();
        jsonSuccess([], $id ? 'แก้ไขรายชื่อคณะกรรมการแล้ว' : 'เพิ่มรายชื่อคณะกรรมการแล้ว');
    }

    if ($action === 'delete') {
        $id = (int)postStr('member_id');
        $m  = $db->fetchOne('SELECT * FROM committee_members WHERE member_id = ?', [$id]);
        if (!$m) jsonError('ไม่พบรายชื่อนี้ อาจถูกลบไปแล้ว กรุณาโหลดหน้าใหม่');
        $db->delete('committee_members', 'member_id = ?', [$id]);
        if ($m['image']) { fileQueueDelete($m['image']); fileCommitChanges(); }
        jsonSuccess([], 'ลบออกจากคณะกรรมการแล้ว');
    }
    jsonError('คำสั่งไม่ถูกต้อง');
}

$members = $ready ? committeeMembers(false) : [];
$rawById = [];
if ($ready) foreach ($db->fetchAll('SELECT * FROM committee_members') as $r) $rawById[(int)$r['member_id']] = $r;
$staffOptions = $db->fetchAll(
    'SELECT s.staff_id, s.prefix, s.full_name, p.position_name, c.campus_name
     FROM staff s JOIN positions p ON p.position_id = s.position_id LEFT JOIN campuses c ON c.campus_id = s.campus_id
     WHERE ' . staffCurrentSql('s') . ' ORDER BY p.position_level DESC, s.full_name ASC'
);

$pageTitle   = 'คณะกรรมการประจำคณะ';
$activeMenu  = 'committee';
$breadcrumb  = [['label' => 'คณะกรรมการประจำคณะ']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>
<style>
.cm-list { display: flex; flex-direction: column; gap: 8px; }
.cm-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--ne-line); border-radius: 12px; background: #fff; }
.cm-item img, .cm-item .cm-ini { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; flex: 0 0 auto; }
.cm-item .cm-ini { background: var(--ne-blue); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.cm-item .cm-main { min-width: 0; flex: 1 1 auto; }
.cm-item .cm-name { font-weight: 600; }
.cm-item .cm-sub { font-size: .8rem; color: var(--ne-slate); }
.cm-role { display: inline-block; font-size: .75rem; background: #343C60; color: #fff; border-radius: 4px; padding: 2px 8px; white-space: nowrap; margin-top: 3px; }
.cm-tag { display: inline-block; font-size: .7rem; border-radius: 4px; padding: 1px 6px; margin-left: 4px; white-space: nowrap; }
.cm-tag.ext { background: #fff4e5; color: #9a5b00; } .cm-tag.out { background: #fdecec; color: #b42318; }
.cm-item .ne-actions { display: flex; gap: 6px; flex: 0 0 auto; }
.cm-kind { display: flex; flex-wrap: wrap; gap: 8px; }
.cm-photo { display: flex; flex-direction: column; align-items: center; gap: 8px; margin-bottom: 8px; }
.cm-crop { width: 180px; height: 180px; border-radius: 50%; overflow: hidden; position: relative; background: var(--ne-paper, #eef1f6); border: 4px solid var(--ne-line); }
.cm-crop img { position: absolute; left: 0; top: 0; width: 100%; height: auto; }
.cm-crop:not(.has-img) img { display: none; }
.cm-photo input[type="range"] { width: 180px; }
.cm-kind label { display: flex; align-items: center; gap: 6px; border: 1.5px solid var(--ne-line); border-radius: 10px; padding: 8px 12px; cursor: pointer; white-space: nowrap; }
</style>
<div class="ne">
  <div class="ne-topbar">
    <h1>คณะกรรมการประจำคณะ <span class="ne-count"><?= count($members) ?> คน</span></h1>
    <?php if ($ready): ?>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openForm(0)"><i class="bi bi-plus-lg"></i>เพิ่มกรรมการ</button>
    <?php endif; ?>
  </div>

  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังใช้งานไม่ได้: ต้องรันไฟล์ <code>sql/1_bait_migrations_13_to_16.sql</code> (ข้อ 14) ใน phpMyAdmin หนึ่งครั้งก่อน</p></div></div>
  <?php else: ?>
  <div class="ne-panel">
    <div class="ne-panel-head"><span>รายชื่อ (เรียงตามตำแหน่ง แล้วตามลำดับ)</span></div>
    <div class="ne-panel-body">
      <p class="ne-hint" style="margin-top:0;">แสดงบนเว็บที่ หน้าบุคลากร › แท็บ "คณะกรรมการประจำคณะ" เป็นผัง: ประธานกรรมการอยู่บนสุด ตามด้วย ผู้ทรงคุณวุฒิภายนอก · ผู้ทรงคุณวุฒิภายใน · กรรมการ · เลขานุการ · บุคคลภายนอกไม่นับในสถิติบุคลากร</p>
      <?php if (!$members): ?>
      <div class="ne-empty"><i class="bi bi-people"></i>ยังไม่มีรายชื่อ — กด "เพิ่มกรรมการ"</div>
      <?php else: ?>
      <div class="cm-list">
        <?php foreach ($members as $m): $ini = mb_substr(preg_replace('/^(นาย|นางสาว|นาง|ดร\.|ผศ\.|รศ\.|ศ\.)+/u', '', $m['full_name']), 0, 1, 'UTF-8'); ?>
        <div class="cm-item">
          <?php if ($m['image']): ?><img src="<?= UPLOAD_URL . '/' . e($m['image']) ?>" alt=""><?php else: ?><span class="cm-ini" aria-hidden="true"><?= e($ini) ?></span><?php endif; ?>
          <div class="cm-main">
            <div class="cm-name"><?= e(trim($m['prefix'] . $m['full_name'])) ?>
              <?php if ($m['is_external']): ?><span class="cm-tag ext">บุคคลภายนอก</span><?php endif; ?>
              <?php if ($m['resigned']): ?><span class="cm-tag out">ลาออกแล้ว — ไม่แสดงบนเว็บ</span><?php endif; ?>
            </div>
            <?php if ($m['is_external'] && $m['full_name_en'] !== ''): ?><div class="cm-sub">EN: <?= e($m['full_name_en']) ?></div><?php endif; ?>
            <div class="cm-sub"><?= e($m['responsibility']) ?></div>
            <span class="cm-role"><?= e($m['role_label']) ?></span> <small class="cm-sub">ลำดับ <?= (int)$m['sort_order'] ?></small>
          </div>
          <div class="ne-actions">
            <button type="button" class="ne-icon-btn ne-icon-edit" title="แก้ไข" onclick="openForm(<?= (int)$m['member_id'] ?>)"><i class="bi bi-pencil"></i></button>
            <button type="button" class="ne-icon-btn ne-icon-delete" title="ลบ" onclick="delMember(<?= (int)$m['member_id'] ?>, <?= e(json_encode(trim($m['prefix'] . $m['full_name']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)"><i class="bi bi-trash"></i></button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($ready): ?>
<div class="modal fade" id="cmModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header">
    <h5 class="modal-title" id="cmTitle" style="font-family:'Kanit',serif;">เพิ่มกรรมการ</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
  </div>
  <div class="modal-body" style="padding:20px;">
    <input type="hidden" id="cmId" value="0">
    <div class="ne-field"><span class="ne-label" style="font-weight:600;display:block;margin-bottom:6px;">กรรมการเป็น</span>
      <div class="cm-kind">
        <label><input type="radio" name="cmKind" value="staff" checked onchange="kindChange()"> บุคลากรของคณะ</label>
        <label><input type="radio" name="cmKind" value="external" onchange="kindChange()"> บุคคลภายนอก</label>
      </div>
    </div>
    <div id="cmStaffBox" class="ne-field"><label for="cmStaffSearch">บุคลากร <span style="color:var(--ne-danger)">*</span></label>
      <div style="position:relative;margin-bottom:8px;">
        <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ne-slate);pointer-events:none;"></i>
        <input type="search" class="ne-input" id="cmStaffSearch" placeholder="พิมพ์ชื่อ ตำแหน่ง หรือศูนย์ เพื่อค้นหา" autocomplete="off" style="padding-left:36px;">
      </div>
      <select class="ne-select" id="cmStaff" aria-label="เลือกบุคลากร">
        <option value="">— เลือกบุคลากร —</option>
        <?php foreach ($staffOptions as $s): ?><option value="<?= (int)$s['staff_id'] ?>"><?= e($s['prefix'] . $s['full_name'] . ' · ' . $s['position_name'] . ($s['campus_name'] ? ' (' . $s['campus_name'] . ')' : '')) ?></option><?php endforeach; ?>
      </select>
      <p class="ne-hint" style="margin:4px 0 0;"><span id="cmStaffCount"></span>ชื่อ รูป และตำแหน่งดึงจากข้อมูลบุคลากรโดยอัตโนมัติ</p>
    </div>
    <div id="cmExtBox" hidden>
      <div class="ne-field"><label for="cmName">ชื่อ-นามสกุล (พร้อมคำนำหน้า/ตำแหน่งทางวิชาการ) <span style="color:var(--ne-danger)">*</span></label><input type="text" class="ne-input" id="cmName" maxlength="255" placeholder="เช่น รศ.ดร.สมชาย ใจดี"></div>
      <?php if (hasCommitteeNameEn()): ?>
      <div class="ne-field"><label for="cmNameEn">ชื่อ-นามสกุล (ภาษาอังกฤษ) <span class="ne-hint" style="display:inline;margin:0;">แสดงในหน้าเว็บ EN · ไม่บังคับ</span></label>
        <input type="text" class="ne-input" id="cmNameEn" maxlength="255" placeholder="เช่น Assoc. Prof. Dr. Somchai Jaidee" autocomplete="off" spellcheck="false">
        <p class="ne-hint" style="margin:4px 0 0;">ใส่คำนำหน้า/ตำแหน่งทางวิชาการเป็นภาษาอังกฤษในช่องนี้ด้วย · เว้นว่าง = หน้าเว็บ EN แสดงชื่อภาษาไทย</p></div>
      <?php endif; ?>
      <div class="ne-field"><label for="cmTitleIn">ตำแหน่ง / หน่วยงานต้นสังกัด</label><input type="text" class="ne-input" id="cmTitleIn" maxlength="255" placeholder="เช่น ผู้ทรงคุณวุฒิ มหาวิทยาลัย..."></div>
      <div class="ne-field"><label for="cmImage">รูป (ไม่บังคับ)</label>
        <!-- same framing as the staff form: the photo fills the circle's width, the slider moves it up / down -->
        <div class="cm-photo">
          <div class="cm-crop" id="cmCrop"><img id="cmCropImg" alt="" draggable="false"></div>
          <input type="range" id="cmCropSlider" min="0" max="0" value="0" aria-label="เลื่อนรูปขึ้น-ลง" hidden>
          <button type="button" class="ne-btn ne-btn-ghost" id="cmReposition" hidden><i class="bi bi-arrows-move"></i>ปรับตำแหน่งรูปเดิม</button>
          <p class="ne-hint" id="cmRepoNote" hidden style="margin:0;">รูปนี้เป็นสี่เหลี่ยมจัตุรัสอยู่แล้ว จึงเลื่อนขึ้น-ลงไม่ได้ — ถ้าต้องการจัดกรอบใหม่ให้เลือกไฟล์รูปต้นฉบับอีกครั้ง</p>
        </div>
        <input type="file" data-has-preview class="ne-input" id="cmImage" accept="image/jpeg,image/png,image/webp">
        <p class="ne-hint" style="margin:4px 0 0;">เลือกไฟล์แล้วเลื่อนแถบใต้รูปเพื่อขยับรูปขึ้น-ลงให้หน้าอยู่กลางวงกลม</p>
        <label id="cmRemoveWrap" hidden style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:.85rem;"><input type="checkbox" id="cmRemove"> เอารูปเดิมออก</label></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
      <div class="ne-field"><label for="cmRole">ตำแหน่งในคณะกรรมการ <span style="color:var(--ne-danger)">*</span></label>
        <select class="ne-select" id="cmRole"><?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>"<?= $k === 'member' ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="ne-field"><label for="cmOrder">ลำดับ (น้อยแสดงก่อน)</label><input type="number" class="ne-input" id="cmOrder" value="0" min="0" max="9999" step="1"></div>
    </div>
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" id="cmSave" onclick="saveMember()"><i class="bi bi-check-lg"></i>บันทึก</button>
  </div>
</div></div></div>
<?php endif; ?>

<?php
$rows = [];
foreach ($rawById as $id => $r) $rows[$id] = ['staff_id' => $r['staff_id'] !== null ? (int)$r['staff_id'] : 0, 'name' => $r['name'], 'name_en' => (string)($r['name_en'] ?? ''), 'title' => $r['title'], 'image_url' => $r['image'] ? UPLOAD_URL . '/' . $r['image'] : '', 'has_image' => (bool)$r['image'], 'role' => $r['role'], 'sort_order' => (int)$r['sort_order']];
$extraJs = '<script>
const csrf = ' . json_encode(csrfToken()) . ';
const CM = ' . json_encode((object)$rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
let cmModal;
// photo framing for an outside member (same as the staff form): the photo fills the circle\'s width and the slider
// chooses which vertical slice shows; the result is a 500x500 JPEG sent as image_data
const cmCrop = { box: document.getElementById("cmCrop"), img: document.getElementById("cmCropImg"), slider: document.getElementById("cmCropSlider"), data: "", h: 0 };
const CM_VIEW = 180, CM_OUT = 500;
function cmCropApply() { cmCrop.img.style.transform = "translateY(-" + cmCrop.slider.value + "px)"; }
function cmCropRender() {
  const c = document.createElement("canvas"); c.width = CM_OUT; c.height = CM_OUT;
  const k = CM_OUT / CM_VIEW;
  c.getContext("2d").drawImage(cmCrop.img, 0, -parseFloat(cmCrop.slider.value) * k, CM_OUT, cmCrop.h * k);
  cmCrop.data = c.toDataURL("image/jpeg", 0.9);
}
function cmCropLoad(src, renderNow, sliderOn) {
  cmCrop.img.onload = function () {
    cmCrop.h = cmCrop.img.naturalHeight * (CM_VIEW / cmCrop.img.naturalWidth);
    const max = Math.max(0, cmCrop.h - CM_VIEW);
    cmCrop.slider.max = max; cmCrop.slider.value = max / 2; cmCropApply();
    cmCrop.box.classList.add("has-img");
    cmCrop.slider.hidden = !sliderOn || max === 0;
    if (renderNow) cmCropRender(); else cmCrop.data = "";
  };
  cmCrop.img.src = src;
}
function cmCropReset(url) {
  cmCrop.data = ""; cmCrop.slider.hidden = true; cmCrop.box.classList.remove("has-img"); cmCrop.img.removeAttribute("src");
  document.getElementById("cmRepoNote").hidden = true;
  const repo = document.getElementById("cmReposition");
  repo.hidden = !url; repo.dataset.src = url || "";
  if (url) cmCropLoad(url, false, false);   // show the current photo, unframed until asked
}
if (cmCrop.box) {
  document.getElementById("cmImage").addEventListener("change", function () {
    const f = this.files[0]; if (!f) return;
    const r = new FileReader(); r.onload = e => { cmCropLoad(e.target.result, true, true); document.getElementById("cmReposition").hidden = true; document.getElementById("cmRemove").checked = false; }; r.readAsDataURL(f);
  });
  cmCrop.slider.addEventListener("input", () => { cmCropApply(); cmCropRender(); });
  document.getElementById("cmReposition").addEventListener("click", function () {
    const src = this.dataset.src, btn = this, probe = new Image();
    probe.onload = () => {
      // a square photo has nothing to slide (it was cropped when saved)
      if (probe.naturalHeight <= probe.naturalWidth * 1.01) { document.getElementById("cmRepoNote").hidden = false; return; }
      btn.hidden = true; cmCropLoad(src + (src.indexOf("?") < 0 ? "?t=" + Date.now() : ""), false, true);
    };
    probe.src = src;
  });
}
// บุคลากร search: the dropdown is rebuilt with only the matching people; the choice stays only while it still matches,
// and a single match is picked straight away
const cmStaffAll = document.getElementById("cmStaff") ? Array.from(document.getElementById("cmStaff").options).slice(1).map(o => ({ v: o.value, t: o.text })) : [];
const cmNorm = s => s.toLowerCase().replace(/\s+/g, "");
function filterStaff() {
  const sel = document.getElementById("cmStaff"), cur = sel.value, q = cmNorm(document.getElementById("cmStaffSearch").value);
  const matches = cmStaffAll.filter(o => !q || cmNorm(o.t).includes(q));
  sel.length = 1;
  matches.forEach(o => sel.add(new Option(o.t, o.v)));
  sel.value = q && matches.length === 1 ? matches[0].v : (matches.some(o => o.v === cur) ? cur : "");
  document.getElementById("cmStaffCount").textContent = q ? (matches.length ? "พบ " + matches.length + " คน · " : "ไม่พบชื่อที่ค้นหา · ") : "";
}
if (document.getElementById("cmStaffSearch")) document.getElementById("cmStaffSearch").addEventListener("input", filterStaff);
function kindChange() {
  const ext = document.querySelector("input[name=cmKind]:checked").value === "external";
  document.getElementById("cmStaffBox").hidden = ext; document.getElementById("cmExtBox").hidden = !ext;
}
function openForm(id) {
  const m = CM[id] || { staff_id: 0, name: "", name_en: "", title: "", has_image: false, role: "member", sort_order: 0 };
  document.getElementById("cmId").value = id;
  document.getElementById("cmTitle").textContent = id ? "แก้ไขกรรมการ" : "เพิ่มกรรมการ";
  document.querySelector("input[name=cmKind][value=" + (id && !m.staff_id ? "external" : "staff") + "]").checked = true;
  document.getElementById("cmStaffSearch").value = ""; filterStaff();
  document.getElementById("cmStaff").value = m.staff_id || "";
  document.getElementById("cmName").value = m.name || ""; document.getElementById("cmTitleIn").value = m.title || "";
  if (document.getElementById("cmNameEn")) document.getElementById("cmNameEn").value = m.name_en || "";
  document.getElementById("cmImage").value = ""; document.getElementById("cmRemove").checked = false;
  document.getElementById("cmRemoveWrap").hidden = !(id && m.has_image);
  if (cmCrop.box) cmCropReset(id && !m.staff_id ? m.image_url : "");
  document.getElementById("cmRole").value = m.role; document.getElementById("cmOrder").value = m.sort_order;
  kindChange();
  cmModal = cmModal || new bootstrap.Modal(document.getElementById("cmModal")); cmModal.show();
}
async function saveMember() {
  const btn = document.getElementById("cmSave"); if (btn.disabled) return;
  const kind = document.querySelector("input[name=cmKind]:checked").value;
  if (kind === "staff" && !document.getElementById("cmStaff").value) { showError("กรุณาเลือกบุคลากร"); return; }
  if (kind === "external" && !document.getElementById("cmName").value.trim()) { showError("กรุณากรอกชื่อ-นามสกุลของกรรมการภายนอก"); return; }
  const fd = new FormData();
  fd.append("action", "save"); fd.append("csrf_token", csrf); fd.append("member_id", document.getElementById("cmId").value); fd.append("kind", kind);
  fd.append("staff_id", document.getElementById("cmStaff").value); fd.append("name", document.getElementById("cmName").value); fd.append("title", document.getElementById("cmTitleIn").value);
  if (document.getElementById("cmNameEn")) fd.append("name_en", document.getElementById("cmNameEn").value);
  fd.append("role", document.getElementById("cmRole").value); fd.append("sort_order", document.getElementById("cmOrder").value);
  if (document.getElementById("cmRemove").checked) fd.append("remove_image", "1");
  // a framed photo goes as image_data (the original file is then not sent); the raw file is only the fallback
  const f = document.getElementById("cmImage").files[0];
  if (kind === "external" && cmCrop.data) fd.append("image_data", cmCrop.data);
  else if (kind === "external" && f) fd.append("image", f);
  btn.disabled = true;
  try {
    const r = await axios.post("", fd);
    if (r.data.status === "success") { cmModal.hide(); showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); }
    else showError(r.data.message);
  } finally { btn.disabled = false; }
}
async function delMember(id, name) {
  const c = await Swal.fire({ title: "ลบออกจากคณะกรรมการ?", text: name, icon: "warning", showCancelButton: true, confirmButtonColor: "#c1443d", confirmButtonText: "ลบ", cancelButtonText: "ยกเลิก" });
  if (!c.isConfirmed) return;
  const fd = new FormData(); fd.append("action", "delete"); fd.append("member_id", id); fd.append("csrf_token", csrf);
  const r = await axios.post("", fd);
  if (r.data.status === "success") { showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); } else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';

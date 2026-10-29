<?php
/**
 * BAIT Admin - Faculty Information
 * Two tabs on one page (JS show/hide, no framework tab component needed):
 *  1. ข้อมูลทั่วไป  — about text + logo (unchanged legacy full-page POST form)
 *  2. หัวข้อหน้าเกี่ยวกับคณะ — about_sections: freely reorderable content
 *     blocks rendered on about.php. Each row is 'text' (icon+color+rich
 *     content+optional image), 'grid' (its own independent image gallery,
 *     items stored in about_section_items), or 'highlights' (the one fixed
 *     จุดเด่นของคณะ slot, whose items come from the shared `highlights`
 *     table instead — the site's one fixed homepage grid). All three types
 *     are ordinary rows here — same title-link/edit/drag-reorder — and all
 *     route to section-form.php, which now also owns the highlights CRUD
 *     (there used to be a 3rd "จุดเด่นของคณะ" tab here for that; it's gone,
 *     merged into section-form.php instead so everything about one topic
 *     lives on one page).
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// Same fixed sprite-slug picker pattern as info-links/form-categories — the
// public about.php page is framework-free, so icons here render from the
// site's own small custom SVG sprite (layout/icons.php), not an arbitrary
// Bootstrap Icons class. The value on the right is only for THIS picker's
// buttons to preview with (admin already loads Bootstrap Icons); it is
// never saved.
$sectionIconOptions = [
    'eye' => 'bi-eye', 'target' => 'bi-bullseye', 'lightbulb' => 'bi-lightbulb', 'award' => 'bi-award',
    'users' => 'bi-people', 'shield-check' => 'bi-shield-check', 'globe' => 'bi-globe', 'heart' => 'bi-heart',
    'book' => 'bi-book', 'graduation-cap' => 'bi-mortarboard', 'building' => 'bi-building', 'briefcase' => 'bi-briefcase',
    'diagram' => 'bi-diagram-3', 'star' => 'bi-star', 'compass' => 'bi-compass', 'flame' => 'bi-fire',
    'check-circle' => 'bi-check-circle', 'calendar' => 'bi-calendar3', 'home' => 'bi-house-door', 'list' => 'bi-card-checklist',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ============================================================
    // Legacy: About text + logo (full-page POST-redirect, no `action`)
    // ============================================================
    if ($action === '') {
        $about = postRaw('about');
        $data = ['about' => $about];

        $exists = $db->fetchOne("SELECT faculty_id, logo FROM faculty LIMIT 1");
        // a logo that could not be uploaded used to be skipped silently while the page said "saved": say so, change nothing
        if (!empty($_FILES['logo']['name'])) {
            $up = uploadImage($_FILES['logo'], 'faculty');
            if (!$up) {
                $_SESSION['flash_error'] = 'อัปโหลดโลโก้คณะไม่สำเร็จ (รองรับไฟล์รูปภาพ เช่น JPG, PNG, WEBP, SVG ขนาดไม่เกิน 5MB) โลโก้เดิมยังใช้อยู่';
                header('Location: index.php'); exit;
            }
            fileTrackNew($up); fileQueueDelete($exists['logo'] ?? null);   // the old logo goes only once the change is saved
            $data['logo'] = $up;
        }

        if ($exists) $db->update('faculty', $data, 'faculty_id = ?', [$exists['faculty_id']]);
        else $db->insert('faculty', array_merge($data, ['faculty_name_th' => 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ']));
        fileCommitChanges();

        $_SESSION['flash_success'] = 'บันทึกข้อมูลคณะเรียบร้อยแล้ว';
        header('Location: index.php'); exit;
    }

    // ============================================================
    // About-page sections CRUD (AJAX)
    // ============================================================
    if ($action === 'section_reorder') {
        $ids = json_decode(postRaw('ids', '[]'), true); if (!is_array($ids)) $ids = [];
        foreach ($ids as $order => $id) {
            $db->update('about_sections', ['display_order' => $order + 1], 'section_id = ?', [(int)$id]);
        }
        jsonSuccess([], 'บันทึกลำดับแล้ว');
    }

    if ($action === 'section_toggle') {
        $id = (int)($_POST['section_id'] ?? 0);
        $s  = $db->fetchOne("SELECT is_active FROM about_sections WHERE section_id = ?", [$id]);
        if ($s) { $db->update('about_sections', ['is_active' => $s['is_active'] ? 0 : 1], 'section_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
        jsonError('ไม่พบหัวข้อ');
    }

    if ($action === 'section_delete') {
        $id = (int)($_POST['section_id'] ?? 0);
        $s  = $db->fetchOne("SELECT * FROM about_sections WHERE section_id = ?", [$id]);
        if ($s) {
            fileQueueDelete($s['image']);   // files go only once the rows are really gone
            // 'grid' sections own a whole independent gallery — deleting the
            // section without its items would just orphan them (and their
            // uploaded files) forever, unreachable from any admin screen.
            if ($s['section_type'] === 'grid') {
                $items = $db->fetchAll("SELECT * FROM about_section_items WHERE section_id = ?", [$id]);
                foreach ($items as $item) fileQueueDelete($item['image']);
                $db->delete('about_section_items', 'section_id = ?', [$id]);
            }
            $db->delete('about_sections', 'section_id = ?', [$id]);
            fileCommitChanges();
            jsonSuccess([], 'ลบหัวข้อแล้ว');
        }
        jsonError('ไม่พบหัวข้อ');
    }

    // Adding/editing a text/grid/highlights section — including the
    // จุดเด่นของคณะ image CRUD (highlight_save/toggle/delete/reorder) — is
    // now entirely on section-form.php instead of an AJAX modal or a 3rd
    // tab here. Only reorder/toggle/delete of the topic list itself stay as
    // in-place AJAX actions on this list.
}

$faculty  = $db->fetchOne("SELECT * FROM faculty LIMIT 1") ?: [];
// Every row — 'text', 'grid', or 'highlights' — is a first-class topic now:
// same list, same title-link/edit/drag-reorder treatment, all routing to
// section-form.php (which now also owns the จุดเด่นของคณะ image CRUD for
// the 'highlights' row). Item counts for 'grid' rows are looked up in one
// query rather than N+1 per row.
$sections = $db->fetchAll("SELECT * FROM about_sections ORDER BY display_order ASC");
$gridItemCounts = array_column(
    $db->fetchAll("SELECT section_id, COUNT(*) as c FROM about_section_items GROUP BY section_id"),
    'c', 'section_id'
);
$highlightsCount = (int)($db->fetchOne("SELECT COUNT(*) as c FROM highlights")['c'] ?? 0);

$pageTitle  = 'ข้อมูลคณะ';
$activeMenu = 'faculty-info';
$breadcrumb = [['label' => 'ข้อมูลคณะ']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="d-flex gap-2 mb-3" style="border-bottom:1px solid var(--ne-line);padding-bottom:0;">
    <button type="button" class="ne-btn ne-tab-btn active" data-tab="general" onclick="switchTab('general')">
      <i class="bi bi-info-circle"></i>ข้อมูลทั่วไป
    </button>
    <button type="button" class="ne-btn ne-tab-btn" data-tab="sections" onclick="switchTab('sections')">
      <i class="bi bi-layout-text-window-reverse"></i>หัวข้อหน้าเกี่ยวกับคณะ
    </button>
  </div>

  <style>
    .ne-tab-btn { background: transparent; border: none; border-bottom: 3px solid transparent; border-radius: 0; color: var(--ne-ink-soft); padding-bottom: 10px; }
    .ne-tab-btn.active { color: var(--ne-blue); border-bottom-color: var(--ne-blue); font-weight: 700; }
    .ne-tab-panel.d-none { display: none !important; }
  </style>

  <!-- ============ TAB 1: ข้อมูลทั่วไป ============ -->
  <div id="tab-general" class="ne-tab-panel">
    <div class="ne-grid">
      <div class="ne-main">
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="ne-panel" style="margin-bottom:14px;">
            <div class="ne-panel-head"><span><i class="bi bi-info-circle" style="color:var(--ne-blue);margin-right:6px;"></i>เกี่ยวกับคณะ (About)</span></div>
            <div class="ne-panel-body">
              <textarea name="about" id="aboutEditor" rows="8"><?= e($faculty['about'] ?? '') ?></textarea>
            </div>
          </div>
          <button type="submit" class="ne-btn ne-btn-primary">
            <i class="bi bi-check-lg"></i>บันทึกข้อมูล
          </button>
        </form>
      </div>

      <div class="ne-side">
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-building" style="color:var(--ne-blue);margin-right:6px;"></i>ข้อมูลทั่วไป</span></div>
          <div class="ne-panel-body">
            <dl class="row mb-0">
              <dt class="col-5 ne-hint" style="margin:0;">ชื่อภาษาไทย</dt>
              <dd class="col-7 small"><?= e($faculty['faculty_name_th'] ?? '') ?></dd>
              <dt class="col-5 ne-hint" style="margin:0;">ชื่อภาษาอังกฤษ</dt>
              <dd class="col-7 small"><?= e($faculty['faculty_name_en'] ?? '') ?></dd>
              <dt class="col-5 ne-hint" style="margin:0;">ชื่อย่อ</dt>
              <dd class="col-7 small"><code><?= e($faculty['faculty_abbr'] ?? '') ?></code></dd>
            </dl>
          </div>
        </div>

        <div class="ne-panel">
          <div class="ne-panel-head"><span><i class="bi bi-image" style="color:var(--ne-blue);margin-right:6px;"></i>โลโก้คณะ</span></div>
          <div class="ne-panel-body">
            <?php if (!empty($faculty['logo'])): ?>
            <img src="<?= UPLOAD_URL . '/' . e($faculty['logo']) ?>" class="img-fluid rounded mb-3 d-block mx-auto" style="max-height:120px" alt="Logo">
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
              <?= csrfField() ?>
              <input type="hidden" name="about" value="<?= e($faculty['about'] ?? '') ?>">
              <input type="file" class="form-control form-control-sm mb-2" name="logo" accept="image/*">
              <p class="ne-hint" style="margin:0 0 10px;"><b>ใช้ที่:</b> ส่วน "เกี่ยวกับคณะ" ในหน้าแรก, หน้าเข้าสู่ระบบหลังบ้าน และข้อมูลคณะที่ส่งให้ Google<br>
                <b>ไม่เปลี่ยน:</b> โลโก้หัวเว็บและท้ายเว็บไซต์ (เป็นไฟล์คงที่ของเว็บ) และโลโก้แถบเมนูหลังบ้าน (ตั้งที่ ตั้งค่าเว็บไซต์ &gt; ข้อมูลทั่วไป &amp; โลโก้)</p>
              <button type="submit" class="ne-btn ne-btn-outline ne-btn-block">
                <i class="bi bi-upload"></i>อัปโหลดโลโก้
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============ TAB 2: หัวข้อหน้าเกี่ยวกับคณะ ============ -->
  <div id="tab-sections" class="ne-tab-panel d-none">
    <div class="ne-topbar" style="margin-bottom:14px;">
      <span class="ne-hint" style="margin:0;">ลาก-วาง เพื่อเรียงลำดับที่จะแสดงในหน้า "เกี่ยวกับคณะ" — เพิ่ม/แก้ไข/ลบหัวข้อได้อิสระ</span>
      <a href="section-form.php" class="ne-btn ne-btn-primary" style="margin-left:auto;">
        <i class="bi bi-plus-lg"></i>เพิ่มหัวข้อใหม่
      </a>
    </div>
    <div class="ne-panel">
      <div class="ne-panel-head"><span>หัวข้อทั้งหมด (<?= count($sections) ?>)</span></div>
      <div class="ne-panel-body">
        <div id="sectionList" class="d-flex flex-column gap-2">
          <?php foreach ($sections as $s):
            $isGrid = $s['section_type'] === 'grid';
            $isLegacyHighlights = $s['section_type'] === 'highlights';
            $editUrl = 'section-form.php?id=' . $s['section_id'];
          ?>
          <div class="border rounded d-flex align-items-center gap-2 p-2" data-id="<?= $s['section_id'] ?>" style="cursor:grab;background:#fff;">
            <i class="bi bi-grip-vertical text-muted"></i>
            <?php if ($isGrid || $isLegacyHighlights): ?>
            <span class="ne-icon-btn" style="background:var(--ne-gold-soft);color:var(--ne-gold);border-color:transparent;"><i class="bi <?= $isLegacyHighlights ? 'bi-stars' : 'bi-grid-3x3-gap-fill' ?>"></i></span>
            <?php else: ?>
            <span class="ne-icon-btn" style="background:<?= e($s['color']) ?>1f;color:<?= e($s['color']) ?>;border-color:transparent;"><i class="bi <?= e($sectionIconOptions[$s['icon']] ?? 'bi-target') ?>"></i></span>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
              <a href="<?= e($editUrl) ?>" class="ne-title-link fw-500 text-truncate d-block"><?= e($s['title']) ?></a>
              <small class="text-muted">
                <?php if ($isLegacyHighlights): ?>
                จุดเด่นของคณะ (<?= (int)($highlightsCount ?? 0) ?> รายการ) — คลิกเพื่อจัดการรูปภาพ
                <?php elseif ($isGrid): ?>
                กริดรูปภาพ (<?= (int)($gridItemCounts[$s['section_id']] ?? 0) ?> รายการ)
                <?php else: ?>
                <?= ['list' => 'รายการหัวข้อย่อยแบบตัวเลข', 'bullet' => 'รายการหัวข้อย่อยแบบจุด'][$s['content_format']] ?? '' ?><?= $s['slug'] ? ' · ' . e($s['slug']) : '' ?>
                <?php endif; ?>
              </small>
            </div>
            <div class="d-flex gap-1">
              <button class="ne-icon-btn <?= $s['is_active'] ? 'ne-icon-edit' : '' ?>" style="<?= !$s['is_active'] ? 'color:var(--ne-slate);' : '' ?>" onclick="toggleSection(<?= $s['section_id'] ?>)" title="<?= $s['is_active'] ? 'ซ่อน' : 'แสดง' ?>">
                <i class="bi bi-<?= $s['is_active'] ? 'eye' : 'eye-slash' ?>"></i>
              </button>
              <a href="<?= e($editUrl) ?>" class="ne-icon-btn ne-icon-edit" title="แก้ไข"><i class="bi bi-pencil"></i></a>
              <?php if (!$isLegacyHighlights): ?>
              <button class="ne-icon-btn ne-icon-delete" onclick="deleteSection(<?= $s['section_id'] ?>, '<?= e(addslashes($s['title'])) ?>')" title="ลบ"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($sections)): ?>
          <div class="ne-empty"><i class="bi bi-layout-text-window-reverse"></i>ยังไม่มีหัวข้อ</div>
          <?php endif; ?>
        </div>
      </div>
      <div style="padding:12px 20px;border-top:1px solid var(--ne-line);text-align:right;">
        <button class="ne-btn ne-btn-primary" onclick="saveSectionOrder()"><i class="bi bi-save"></i>บันทึกลำดับ</button>
      </div>
    </div>
  </div>

</div>

<?php
$extraJs = '
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js" integrity="sha384-69SUO5s28dXCoNTUaA/KXhfDJu21xD394Gxk/S6d/YJZUxrz7Zagi+seruzXFTed" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
ClassicEditor.create(document.querySelector("#aboutEditor"),{language:"th"}).catch(console.error);

const csrf = ' . json_encode(csrfToken()) . ';

function switchTab(name) {
  document.querySelectorAll(".ne-tab-panel").forEach(p => p.classList.add("d-none"));
  document.getElementById("tab-" + name).classList.remove("d-none");
  document.querySelectorAll(".ne-tab-btn").forEach(b => b.classList.toggle("active", b.dataset.tab === name));
}

// Land back on the right tab after redirecting here from section-form.php
// (?tab=sections) or a bookmarked/shared link to a specific tab.
const requestedTab = new URLSearchParams(window.location.search).get("tab");
if (requestedTab && document.getElementById("tab-" + requestedTab)) switchTab(requestedTab);

// ===================== ABOUT SECTIONS =====================
new Sortable(document.getElementById("sectionList"), { animation: 150, ghostClass: "opacity-50" });

function saveSectionOrder() {
  const ids = [...document.querySelectorAll("#sectionList [data-id]")].map(el => el.dataset.id);
  const fd = new FormData();
  fd.append("action","section_reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function toggleSection(id) {
  const fd = new FormData();
  fd.append("action","section_toggle"); fd.append("section_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
}

async function deleteSection(id, title) {
  const r = await Swal.fire({title:"ลบหัวข้อนี้?",html:`ลบ <b>${escapeHtml(title)}</b>? หัวข้อนี้จะหายไปจากหน้าเกี่ยวกับคณะทันที`,icon:"warning",
    showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","section_delete"); fd.append("section_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
  else showError(res.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

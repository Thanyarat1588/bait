<?php
/**
 * BAIT Admin - Form Category Management
 * Mirrors admin/modules/categories/index.php's CRUD pattern, scoped to the
 * `forms` table (download-forms feature) instead of `news`.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// Same fixed sprite-slug picker as admin/modules/info-links/index.php — the
// public site is framework-free (no Bootstrap Icons font), so forms.php's
// category cards render this from the site's own small custom SVG sprite
// (layout/icons.php), not an arbitrary Bootstrap Icons class. The value on
// the right is only a Bootstrap Icons class for THIS picker's buttons to
// preview with (admin already loads that font); it is never saved.
$iconOptions = [
    'book' => 'bi-book', 'building' => 'bi-building', 'briefcase' => 'bi-briefcase',
    'calendar' => 'bi-calendar3', 'mail' => 'bi-envelope', 'phone' => 'bi-telephone',
    'users' => 'bi-people', 'graduation-cap' => 'bi-mortarboard', 'globe' => 'bi-globe',
    'shield-check' => 'bi-shield-check', 'home' => 'bi-house-door', 'award' => 'bi-award',
    'diagram' => 'bi-diagram-3', 'list' => 'bi-card-checklist', 'target' => 'bi-bullseye',
    'download' => 'bi-download', 'link' => 'bi-link-45deg', 'star' => 'bi-star',
];

// AJAX handlers — adding/editing a category is now its own full page
// (form.php) instead of the modal + quick-add-panel this used to have.
// Only delete stays as an in-place AJAX action on this list.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id    = (int)($_POST['category_id'] ?? 0);
        $count = $db->count('forms', 'category_id = ?', [$id]);
        if ($count > 0) jsonError("ไม่สามารถลบได้ มีแบบฟอร์ม $count รายการที่ใช้หมวดหมู่นี้อยู่");
        $cat = $db->fetchOne("SELECT image FROM form_categories WHERE category_id = ?", [$id]);
        $db->delete('form_categories', 'category_id = ?', [$id]);
        if ($cat) { fileQueueDelete($cat['image']); fileCommitChanges(); }   // the picture goes only once the row is really gone
        jsonSuccess([], 'ลบหมวดหมู่เรียบร้อยแล้ว');
    }
}

$categories = $db->fetchAll(
    "SELECT c.*, COUNT(f.form_id) as form_count
     FROM form_categories c LEFT JOIN forms f ON f.category_id = c.category_id
     GROUP BY c.category_id ORDER BY c.category_id ASC"
);

// แปลงสี hex ของหมวดหมู่เป็นสไตล์ chip (พื้นอ่อน 12% + ตัวอักษรเป็นสีเข้ม)
function categoryChipStyle(?string $hex): string {
    $hex = $hex ?: '#162E93';
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '3e5fa3';
    return "background:#{$hex}1f;color:#{$hex};box-shadow:inset 0 0 0 1px #{$hex}33;";
}

$pageTitle   = 'หมวดหมู่แบบฟอร์ม';
$activeMenu  = 'form-categories';
$breadcrumb  = [['label' => 'หมวดหมู่แบบฟอร์ม']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.ne {
  --ne-ink: #1e2a45;
  --ne-ink-soft: #5b6480;
  --ne-paper: #f5f6fa;
  --ne-surface: #ffffff;
  --ne-line: #e3e6ee;
  --ne-gold: #FBC02D;
  --ne-blue: #162E93;
  --ne-blue-deep: #11236E;
  --ne-blue-soft: #ECEEF6;
  --ne-gold-soft: #f7f1e3;
  --ne-green: #3e8564;
  --ne-green-soft: #e5f2eb;
  --ne-slate: #8b93a7;
  --ne-slate-soft: #eef0f5;
  --ne-danger: #c1443d;
  --ne-danger-soft: #fbeae9;
  font-family: 'Kanit', sans-serif;
  color: var(--ne-ink);
}
.ne * { box-sizing: border-box; }

/* ── Top bar ───────────────────────────────── */
.ne-topbar {
  display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  margin-bottom: 22px; padding-bottom: 16px; border-bottom: 1px solid var(--ne-line);
}
.ne-topbar h1 {
  font-family: 'Kanit', sans-serif; font-size: 26px; font-weight: 700; margin: 0; color: var(--ne-ink);
  display: flex; align-items: baseline; gap: 10px;
}
.ne-topbar h1 .ne-count {
  font-family: 'Kanit', sans-serif; font-size: 14px; font-weight: 600; color: var(--ne-gold);
  background: var(--ne-gold-soft); border-radius: 999px; padding: 3px 11px;
}
.ne-btn {
  font-family: 'Kanit', sans-serif; font-weight: 600; font-size: 14.5px; border-radius: 9px; padding: 10px 18px;
  border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  text-decoration: none; transition: all .15s ease; line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue) !important; color: #fff !important; }
.ne-btn-primary:hover { background: var(--ne-blue-deep) !important; color: #fff !important; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }
.ne-btn-block { width: 100%; justify-content: center; }

/* ── Layout grid ──────────────────────────── */
.ne-grid { display: flex; flex-direction: column; gap: 24px; align-items: stretch; }
.ne-main { flex: 1 1 0; min-width: 0; }
.ne-side { width: 100%; flex-shrink: 0; }
@media (min-width: 1024px) { .ne-grid { flex-direction: row; align-items: flex-start; } .ne-side { width: 320px; } }

/* ── Panel ────────────────────────────────── */
.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; }
.ne-panel-head {
  display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft);
}
.ne-panel-body { padding: 18px 20px 20px; }
.ne-panel-title {
  font-size: 12.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--ne-ink-soft); display: flex; align-items: center; gap: 7px; margin: 0;
}
.ne-panel-title i { color: var(--ne-blue); font-size: 14px; }

/* ── Table ────────────────────────────────── */
.ne-table { width: 100%; border-collapse: collapse; }
.ne-table thead th {
  text-align: left; font-size: 11.5px; letter-spacing: .05em; text-transform: uppercase; color: var(--ne-slate);
  font-weight: 600; padding: 12px 20px; background: var(--ne-paper); border-bottom: 1px solid var(--ne-line); white-space: nowrap;
}
.ne-table tbody tr { border-bottom: 1px solid var(--ne-line); transition: background .12s ease; }
.ne-table tbody tr:last-child { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-table td { padding: 12px 20px; vertical-align: middle; font-size: 14px; }
.ne-table td.ne-num { color: var(--ne-slate); font-size: 13px; }

.ne-chip {
  display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; padding: 5px 12px;
  border-radius: 999px; white-space: nowrap;
}
.ne-chip::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

.ne-slug { font-size: 12.5px; color: var(--ne-slate); background: var(--ne-paper); border-radius: 6px; padding: 3px 8px; font-family: monospace; }

.ne-badge-count {
  display: inline-flex; align-items: center; justify-content: center; min-width: 26px;
  font-size: 12.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
  background: var(--ne-slate-soft); color: var(--ne-ink-soft);
}

.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn {
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line);
  background: var(--ne-surface); color: var(--ne-ink-soft);
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px;
}
.ne-icon-btn i { color: inherit !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-icon-btn.ne-icon-delete { color: var(--ne-danger) !important; border-color: #f0c9c6 !important; background: var(--ne-danger-soft) !important; }
.ne-icon-btn.ne-icon-delete:hover { background: var(--ne-danger) !important; border-color: var(--ne-danger) !important; color: #fff !important; }

.ne-empty { text-align: center; padding: 56px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 32px; display: block; margin-bottom: 10px; color: var(--ne-line); }

/* ── Quick add form ───────────────────────── */
.ne-field { margin-bottom: 16px; }
.ne-field label {
  font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 7px;
}
.ne-input {
  width: 100%; border: 1px solid var(--ne-line); border-radius: 8px; padding: 9px 12px;
  font-family: 'Kanit', sans-serif; font-size: 14px; color: var(--ne-ink);
  background: var(--ne-surface); outline: none; transition: border-color .15s ease;
}
.ne-input:focus { border-color: var(--ne-blue); }
.ne-color-row { display: flex; gap: 10px; align-items: center; }
.ne-color-input {
  width: 44px; height: 38px; border: 1px solid var(--ne-line); border-radius: 8px;
  padding: 3px; cursor: pointer; background: var(--ne-surface); flex-shrink: 0;
}
.ne-swatches { display: flex; gap: 6px; flex-wrap: wrap; }
.ne-swatch {
  width: 24px; height: 24px; border-radius: 7px; border: 2px solid var(--ne-surface);
  box-shadow: 0 0 0 1px var(--ne-line); cursor: pointer; transition: transform .12s ease;
}
.ne-swatch:hover { transform: scale(1.12); }

/* ── Modal restyle ────────────────────────── */
.ne-modal-header { background: var(--ne-blue-deep); color: #fff; border: none; }
.ne-modal-header .btn-close { filter: brightness(0) invert(1); }
.ne-modal-content { border: none; border-radius: 14px; overflow: hidden; }
.ne-modal-body label {
  font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 7px;
}
.ne-modal-body .ne-input { margin-bottom: 14px; }
</style>

<div class="ne">
  <div class="ne-topbar">
    <h1>หมวดหมู่แบบฟอร์ม <span class="ne-count"><?= count($categories) ?> หมวดหมู่</span></h1>
    <a href="form.php" class="ne-btn ne-btn-primary" style="margin-left:auto;">
      <i class="bi bi-plus-lg"></i>เพิ่มหมวดหมู่
    </a>
  </div>

  <div class="ne-panel">
    <div class="ne-panel-head">
      <span>หมวดหมู่แบบฟอร์มทั้งหมด</span>
    </div>

    <?php if (!empty($categories)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th style="width:60px">ไอคอน</th>
            <th>ชื่อหมวดหมู่</th>
            <th>Slug</th>
            <th style="width:110px" class="text-center">จำนวนแบบฟอร์ม</th>
            <th style="width:100px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $i => $cat): ?>
          <tr>
            <td class="ne-num"><?= $i + 1 ?></td>
            <td>
              <?php if ($cat['image']): ?>
              <img src="<?= UPLOAD_URL . '/' . e($cat['image']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px;">
              <?php else: ?>
              <span class="ne-badge-count" style="width:40px;height:40px;border-radius:8px;"><i class="bi <?= e($iconOptions[$cat['icon']] ?? 'bi-download') ?>"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <span class="ne-chip" style="<?= categoryChipStyle($cat['color']) ?>"><?= e($cat['category_name']) ?></span>
            </td>
            <td><span class="ne-slug"><?= e($cat['slug']) ?></span></td>
            <td class="text-center"><span class="ne-badge-count"><?= $cat['form_count'] ?></span></td>
            <td>
              <div class="ne-actions">
                <a href="form.php?id=<?= $cat['category_id'] ?>" class="ne-icon-btn ne-icon-edit" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </a>
                <button class="ne-icon-btn ne-icon-delete"
                        onclick="deleteCategory(<?= $cat['category_id'] ?>, '<?= e(addslashes($cat['category_name'])) ?>')"
                        title="ลบ">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty">
      <i class="bi bi-folder2-open"></i>
      ยังไม่มีหมวดหมู่แบบฟอร์ม
    </div>
    <?php endif; ?>
  </div>
</div>

<script id="catsData" type="application/json"><?= json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php
$extraJs = '
<script>
const cats = JSON.parse(document.getElementById("catsData").textContent);
const csrf = ' . json_encode(csrfToken()) . ';

async function deleteCategory(id, name) {
  const c = cats.find(x => x.category_id == id);
  const fc = c ? Number(c.form_count) || 0 : 0;
  if (fc > 0) {
    await Swal.fire({title:"ไม่สามารถลบได้",html:`หมวดหมู่ <strong>${escapeHtml(name)}</strong> ยังมีแบบฟอร์ม ${fc} รายการที่ใช้หมวดหมู่นี้อยู่ กรุณาย้ายหรือลบแบบฟอร์มที่เกี่ยวข้องก่อน`,icon:"warning",confirmButtonText:"เข้าใจแล้ว"});
    return;
  }
  const r = await Swal.fire({title:"ลบหมวดหมู่?",html:`ลบ <strong>${escapeHtml(name)}</strong> ?`,icon:"warning",
    showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("category_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success"){ showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
  else showError(res.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

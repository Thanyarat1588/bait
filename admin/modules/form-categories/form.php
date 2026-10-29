<?php
/**
 * BAIT Admin - หมวดหมู่แบบฟอร์ม (add/edit)
 * A full page instead of the old edit modal — the icon picker plus the
 * "custom icon upload" instructions made that modal awkward to scroll
 * through in a cramped popup. Mirrors the plain-page pattern already used
 * by admin/modules/faculty/section-form.php (GET ?id= for edit, otherwise
 * add; redisplay with $errors on validation failure instead of redirecting,
 * so nothing typed is lost). The quick-add panel still on index.php's list
 * page is untouched — this page is the equivalent full-featured alternative,
 * used for editing an existing category.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// Same fixed sprite-slug picker as index.php (kept in sync there too) — the
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

$isEdit = isset($_GET['id']);
$catId  = $isEdit ? (int)$_GET['id'] : 0;
$cat    = [];

if ($isEdit) {
    $cat = $db->fetchOne("SELECT * FROM form_categories WHERE category_id = ?", [$catId]);
    if (!$cat) { header('Location: index.php'); exit; }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name  = postStr('category_name');
    $color = postStr('color', '#162E93');
    $icon  = array_key_exists($_POST['icon'] ?? '', $iconOptions) ? $_POST['icon'] : 'download';
    if (!$name) $errors[] = 'กรุณากรอกชื่อหมวดหมู่';
    elseif (nameTaken($db, 'form_categories', 'category_name', 'category_id', $name, $catId)) $errors[] = dupMsg('หมวดหมู่แบบฟอร์ม', $name);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $errors[] = 'สีไม่ถูกต้อง กรุณาเลือกสีจากช่องเลือกสี';

    $data = ['category_name' => $name, 'color' => $color, 'icon' => $icon];

    $imagePath = null;
    if (!empty($_FILES['image']['name'])) {
        $up = uploadMedia($_FILES['image'], 'form-categories');
        if (!$up || $up['type'] !== 'image') $errors[] = 'อัปโหลดรูปไอคอนไม่สำเร็จ (รองรับ JPG, PNG, GIF, WEBP, SVG)';
        else { $imagePath = $up['path']; fileTrackNew($imagePath); }
    }
    $removeImage = empty($imagePath) && !empty($_POST['remove_image']);

    if (empty($errors)) {
        if ($isEdit) {
            if ($imagePath) {
                $data['image'] = $imagePath;
                fileQueueDelete($cat['image']);   // deleted only once the change is saved
            } elseif ($removeImage) {
                $data['image'] = null;
                fileQueueDelete($cat['image']);   // deleted only once the change is saved
            }
            $db->update('form_categories', $data, 'category_id = ?', [$catId]);
            $_SESSION['flash_success'] = 'แก้ไขหมวดหมู่เรียบร้อยแล้ว';
        } else {
            $data['image'] = $imagePath;
            $data['slug']  = createSlug($name);
            $db->insert('form_categories', $data);
            $_SESSION['flash_success'] = 'เพิ่มหมวดหมู่เรียบร้อยแล้ว';
        }
        fileCommitChanges();
        header('Location: index.php'); exit;
    }

    // Re-populate so the form redisplays what was typed instead of losing it.
    fileRollbackChanges();   // rejected: drop the new upload
    $cat = array_merge($cat, $data);
}

$pageTitle   = $isEdit ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่ใหม่';
$activeMenu  = 'form-categories';
$breadcrumb  = [['label' => 'หมวดหมู่แบบฟอร์ม', 'url' => 'index.php'], ['label' => $pageTitle]];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <a href="index.php" class="ne-btn ne-btn-ghost" style="margin-left:auto;"><i class="bi bi-arrow-left"></i>กลับ</a>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="ne-alert">
    <strong><i class="bi bi-exclamation-circle me-1"></i>กรุณาตรวจสอบข้อมูล</strong>
    <?= implode('<br>', array_map('e', $errors)) ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div style="max-width:720px;margin-inline:auto;">
        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-tag"></i>รายละเอียดหมวดหมู่</p>
          <div class="ne-panel-body">
            <div class="ne-field">
              <label for="categoryName">ชื่อหมวดหมู่ <span style="color:var(--ne-danger)">*</span></label>
              <input type="text" class="ne-input" id="categoryName" name="category_name" placeholder="เช่น แบบฟอร์มขอผ่อนผัน" value="<?= e($cat['category_name'] ?? '') ?>">
            </div>
            <div class="ne-field" style="margin-bottom:0;">
              <label>สี</label>
              <div class="ne-color-row">
                <input type="color" class="ne-color-input" id="colorInput" name="color" value="<?= e($cat['color'] ?? '#162E93') ?>">
                <div class="ne-swatches">
                  <?php foreach (['#162E93','#1F56D6','#6FA0F5','#3e8564','#9c8355','#c1443d','#8b93a7','#8b93a7'] as $clr): ?>
                  <span class="ne-swatch" style="background:<?= $clr ?>" onclick="document.getElementById('colorInput').value='<?= $clr ?>'"></span>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="ne-panel mt-3">
          <p class="ne-panel-title"><i class="bi bi-palette"></i>ไอคอน</p>
          <div class="ne-panel-body">
            <input type="hidden" id="iconInput" name="icon" value="<?= e($cat['icon'] ?? 'download') ?>">
            <div class="cluster gap-2" style="align-items:center;margin-bottom:10px;">
              <span id="iconPreview" class="bi" style="font-size:1.6rem;color:var(--ne-blue);width:32px;text-align:center;"></span>
              <span class="ne-hint" style="margin:0;">เลือกจากตัวอย่างด้านล่าง หรืออัปโหลดรูปไอคอนเองแทน</span>
            </div>
            <div id="iconPicker" style="display:flex;flex-wrap:wrap;gap:6px;">
              <?php foreach ($iconOptions as $slug => $biClass): ?>
              <button type="button" class="ne-icon-btn" data-icon="<?= e($slug) ?>" onclick="pickIcon('<?= e($slug) ?>')" title="<?= e($slug) ?>"><i class="bi <?= e($biClass) ?>"></i></button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="ne-panel mt-3">
          <p class="ne-panel-title"><i class="bi bi-image"></i>หรืออัปโหลดรูปไอคอนเอง</p>
          <div class="ne-panel-body">
            <div style="background:var(--ne-blue-soft);color:var(--ne-blue-deep);padding:8px 12px;border-radius:8px;margin-bottom:10px;font-size:12.5px;line-height:1.6;">
              <i class="bi bi-info-circle"></i> ให้เข้าชุดกับไอคอนอื่นในเว็บ ใช้ไอคอนสไตล์เส้น (outline) จาก
              <a href="https://feathericons.com/" target="_blank" rel="noopener">feathericons.com</a>
              ดาวน์โหลดเป็นไฟล์ SVG แนะนำที่สุด — ระบบจะปรับสีให้เข้ากับธีมเว็บอัตโนมัติเหมือนไอคอนอื่นๆ (ไม่ต้องแก้สีเอง) ถ้าเป็น PNG/WEBP ต้องเซฟสีเส้นมาให้ตรงกับธีมเว็บเองก่อนอัปโหลด
            </div>
            <input type="hidden" id="removeImageFlag" name="remove_image" value="0">
            <?php if (!empty($cat['image'])): ?>
            <div id="imagePreviewWrap" class="d-flex align-items-center gap-2 mb-2">
              <img id="imagePreview" src="<?= UPLOAD_URL . '/' . e($cat['image']) ?>" alt="" class="img-fluid rounded" style="max-height:100px;object-fit:contain;background:#f8f9fc;padding:6px;">
              <button type="button" class="ne-btn ne-btn-ghost" style="padding:6px 12px;font-size:12.5px;" onclick="removeImage()"><i class="bi bi-trash"></i>ลบรูปนี้</button>
            </div>
            <?php else: ?>
            <div id="imagePreviewWrap" class="d-none d-flex align-items-center gap-2 mb-2">
              <img id="imagePreview" src="" alt="" class="img-fluid rounded" style="max-height:100px;object-fit:contain;background:#f8f9fc;padding:6px;">
              <button type="button" class="ne-btn ne-btn-ghost" style="padding:6px 12px;font-size:12.5px;" onclick="removeImage()"><i class="bi bi-trash"></i>ลบรูปนี้</button>
            </div>
            <?php endif; ?>
            <input type="file" data-has-preview class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" onchange="previewImage(this)">
            <p class="ne-hint">เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนรูป ไม่เลือกจะคงรูปเดิมไว้ — ถ้ามีรูป จะใช้แทนไอคอนด้านบน</p>
          </div>
        </div>

        <div class="ne-panel mt-3">
          <p class="ne-panel-title"><i class="bi bi-send"></i>บันทึกข้อมูล</p>
          <div class="ne-panel-body">
            <div class="ne-panel-actions" style="flex-direction:row;">
              <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-check-lg"></i><?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มหมวดหมู่' ?></button>
              <a href="index.php" class="ne-btn ne-btn-ghost">ยกเลิก</a>
            </div>
          </div>
        </div>

    </div>
  </form>
</div>

<?php
$extraJs = '
<script>
const csrf = ' . json_encode(csrfToken()) . ';
const iconMap = ' . json_encode($iconOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';

function pickIcon(slug) {
  document.getElementById("iconInput").value = slug;
  document.getElementById("iconPreview").className = "bi " + (iconMap[slug] || "bi-download");
  document.querySelectorAll("#iconPicker [data-icon]").forEach(b => b.classList.toggle("ne-icon-edit", b.dataset.icon === slug));
}
pickIcon(document.getElementById("iconInput").value);

function previewImage(input) {
  const wrap = document.getElementById("imagePreviewWrap");
  const img  = document.getElementById("imagePreview");
  const file = input.files[0];
  document.getElementById("removeImageFlag").value = "0";
  if (!file) { wrap.classList.add("d-none"); return; }
  img.src = URL.createObjectURL(file);
  wrap.classList.remove("d-none");
}

function removeImage() {
  document.getElementById("removeImageFlag").value = "1";
  document.getElementById("imagePreviewWrap").classList.add("d-none");
  document.getElementById("image").value = "";
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

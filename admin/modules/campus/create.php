<?php
/**
 * BAIT Admin - Campus Create / Edit
 * Full page, same layout pattern as admin/modules/programs/create.php
 * (main column + side column, no local page-title bar — the global
 * header's $pageTitle/$breadcrumb cover that already).
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$contactTypeLabels = contactTypeLabels($db);

$isEdit   = isset($_GET['id']);
$campusId = $isEdit ? (int)$_GET['id'] : 0;
$campus   = [];
$contacts = [];

if ($isEdit) {
    $campus = $db->fetchOne("SELECT * FROM campuses WHERE campus_id = ?", [$campusId]);
    if (!$campus) { header('Location: index.php'); exit; }
    $contacts = $db->fetchAll("SELECT * FROM campus_contacts WHERE campus_id = ? ORDER BY contact_id", [$campusId]);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name    = postStr('campus_name');
    $address = postStr('address');
    $mapUrl  = postStr('map_url');
    $removeImage = !empty($_POST['remove_image']);

    if (!$name) $errors[] = 'กรุณากรอกชื่อศูนย์พื้นที่';
    elseif (nameTaken($db, 'campuses', 'campus_name', 'campus_id', $name, $campusId)) $errors[] = dupMsg('ศูนย์พื้นที่', $name);
    if ($mapUrl !== '' && !isHttpUrl($mapUrl)) $errors[] = 'ลิงก์แผนที่ไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://';

    $imagePath = $campus['image'] ?? null;
    if (!empty($_FILES['image']['name'])) {
        $up = uploadImage($_FILES['image'], 'campus');
        if ($up) {
            fileQueueDelete($imagePath);
            fileTrackNew($up);
            $imagePath = $up;
        } else {
            $errors[] = 'อัปโหลดภาพไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
        }
    } elseif ($removeImage && $imagePath) {
        fileQueueDelete($imagePath);
        $imagePath = null;
    }

    // Dynamic contact rows — parallel arrays, same convention as
    // programs/create.php's topics_icon[]/topics_title[].
    $cTypes  = $_POST['contact_type'] ?? [];
    $cValues = $_POST['contact_value'] ?? [];
    $cLabels = $_POST['display_label'] ?? [];
    $submittedContacts = [];
    foreach ($cValues as $i => $value) {
        $value = trim($value);
        $type  = trim($cTypes[$i] ?? '');
        $label = trim($cLabels[$i] ?? '');
        if ($value === '' || !isset($contactTypeLabels[$type])) continue;
        $submittedContacts[] = ['contact_type' => $type, 'contact_value' => $value, 'display_label' => $label ?: null];
    }

    // the same channel typed twice in this form
    $seenContacts = [];
    foreach ($submittedContacts as $sc) {
        $k = $sc['contact_type'] . '|' . mb_strtolower($sc['contact_value'], 'UTF-8');
        if (isset($seenContacts[$k])) { $errors[] = 'ช่องทางติดต่อซ้ำกัน: ' . ($contactTypeLabels[$sc['contact_type']] ?? $sc['contact_type']) . ' "' . $sc['contact_value'] . '" ถูกกรอกมากกว่าหนึ่งครั้ง'; break; }
        $seenContacts[$k] = true;
    }

    if (empty($errors)) {
        $data = ['campus_name' => $name, 'address' => $address ?: null, 'map_url' => $mapUrl ?: null, 'image' => $imagePath];
        if ($isEdit) {
            $db->update('campuses', $data, 'campus_id = ?', [$campusId]);
            $_SESSION['flash_success'] = 'แก้ไขศูนย์พื้นที่เรียบร้อยแล้ว';
        } else {
            $campusId = $db->insert('campuses', $data);
            $_SESSION['flash_success'] = 'เพิ่มศูนย์พื้นที่เรียบร้อยแล้ว';
        }

        $db->delete('campus_contacts', 'campus_id = ?', [$campusId]);
        foreach ($submittedContacts as $c) {
            $db->insert('campus_contacts', array_merge(['campus_id' => $campusId], $c));
        }

        fileCommitChanges();
        header('Location: index.php'); exit;
    }

    // Re-populate so the form redisplays what was submitted, contacts included.
    fileRollbackChanges();   // rejected: keep the current image, drop the new upload
    $imagePath = $campus['image'] ?? null;
    $campus = array_merge($campus, ['campus_name' => $name, 'address' => $address, 'map_url' => $mapUrl, 'image' => $imagePath]);
    $contacts = $submittedContacts ?: $contacts;
}

$pageTitle  = $isEdit ? 'แก้ไขศูนย์พื้นที่' : 'เพิ่มศูนย์พื้นที่';
$activeMenu = 'campus';
$breadcrumb = [['label' => 'ศูนย์พื้นที่', 'url' => BASE_URL . '/admin/modules/campus/index.php'], ['label' => $pageTitle]];
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
  --ne-gold-soft: #f7f1e3;
  --ne-blue: #162E93;
  --ne-blue-deep: #11236E;
  --ne-blue-soft: #ECEEF6;
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

/* ── Errors ───────────────────────────────── */
.ne-alert {
  background: var(--ne-danger-soft);
  border: 1px solid #f0c9c6;
  color: #8a2e29;
  border-radius: 10px;
  padding: 14px 16px;
  margin-bottom: 20px;
  font-size: 14.5px;
  line-height: 1.7;
}
.ne-alert strong { display: block; margin-bottom: 2px; }

/* ── Layout grid ──────────────────────────── */
.ne-grid { display: flex; flex-direction: column; gap: 24px; align-items: stretch; }
.ne-main { flex: 1 1 0; min-width: 0; }
.ne-side { width: 100%; flex-shrink: 0; display: flex; flex-direction: column; gap: 18px; }
@media (min-width: 1024px) {
  .ne-grid { flex-direction: row; align-items: flex-start; }
  .ne-side { width: 320px; }
}

/* ── Panels ───────────────────────────────── */
.ne-panel {
  background: var(--ne-surface);
  border: 1px solid var(--ne-line);
  border-radius: 14px;
  margin-bottom: 18px;
}
.ne-panel:last-child { margin-bottom: 0; }
.ne-panel-title {
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ne-ink-soft);
  display: flex;
  align-items: center;
  gap: 7px;
  margin: 0;
  padding: 16px 22px;
  border-bottom: 1px solid var(--ne-line);
}
.ne-panel-title i { color: var(--ne-gold); font-size: 14px; }
.ne-panel-body { padding: 20px 22px; }

.ne-label {
  font-size: 12.5px;
  font-weight: 600;
  letter-spacing: .04em;
  color: var(--ne-ink-soft);
  display: block;
  margin-bottom: 8px;
}
.ne-label .req { color: var(--ne-danger); }

.ne-input, .ne-select, textarea.ne-input {
  width: 100%;
  border: 1px solid var(--ne-line);
  border-radius: 8px;
  padding: 9px 12px;
  font-family: 'Kanit', sans-serif;
  font-size: 14px;
  color: var(--ne-ink);
  background: var(--ne-surface);
  outline: none;
  transition: border-color .15s ease;
}
.ne-input:focus, .ne-select:focus { border-color: var(--ne-blue); }

.ne-btn {
  font-family: 'Kanit', sans-serif;
  font-weight: 600;
  font-size: 14.5px;
  border-radius: 9px;
  padding: 10px 18px;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
  text-decoration: none;
  transition: all .15s ease;
  line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }
.ne-btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 7px; }
.ne-panel .ne-btn-primary, .ne-panel .ne-btn-ghost { width: 100%; justify-content: center; }
.ne-panel-actions { display: flex; flex-direction: column; gap: 8px; }

.ne-hint { font-size: 12.5px; color: var(--ne-slate); margin-top: 8px; line-height: 1.6; }

/* ── Image preview ────────────────────────── */
.ne-image-preview-wrap { position: relative; margin-bottom: 10px; }
.ne-image-preview-wrap img { width: 100%; max-height: 160px; object-fit: cover; border-radius: 8px; border: 1px solid var(--ne-line); display: block; }
.ne-image-remove-btn {
  position: absolute; top: 6px; right: 6px; width: 28px; height: 28px; border-radius: 50%;
  border: none; background: rgba(17,35,110,.75); color: #fff; display: flex; align-items: center;
  justify-content: center; cursor: pointer;
}

/* ── Contact rows ─────────────────────────── */
.ne-contact-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
.ne-contact-row select { flex: 1 1 100%; }
.ne-contact-row input { flex: 1 1 0; min-width: 0; }
@media (min-width: 560px) { .ne-contact-row { flex-wrap: nowrap; } .ne-contact-row select { flex: 0 0 130px; } }
.ne-contact-remove {
  flex: 0 0 auto; width: 34px; height: 34px; border-radius: 8px; border: 1px solid #f0c9c6;
  background: var(--ne-danger-soft); color: var(--ne-danger); display: inline-flex; align-items: center;
  justify-content: center; cursor: pointer; transition: .15s;
}
.ne-contact-remove:hover { background: var(--ne-danger); border-color: var(--ne-danger); color: #fff; }
</style>

<div class="ne">

  <?php if (!empty($errors)): ?>
  <div class="ne-alert">
    <strong><i class="bi bi-exclamation-circle me-1"></i>กรุณาตรวจสอบข้อมูล</strong>
    <?= implode('<br>', array_map('e', $errors)) ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div class="ne-grid">

      <!-- Main column -->
      <div class="ne-main">

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-geo-alt"></i>ข้อมูลศูนย์พื้นที่</p>
          <div class="ne-panel-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="ne-label">ชื่อศูนย์พื้นที่ <span class="req">*</span></label>
                <input type="text" class="ne-input" name="campus_name" value="<?= e($campus['campus_name'] ?? '') ?>" placeholder="ชื่อศูนย์พื้นที่">
              </div>
              <div class="col-12">
                <label class="ne-label">ที่อยู่</label>
                <textarea class="ne-input" name="address" rows="2" placeholder="ที่อยู่ศูนย์..."><?= e($campus['address'] ?? '') ?></textarea>
              </div>
              <div class="col-12">
                <label class="ne-label">ลิงก์ Google Maps</label>
                <input type="url" class="ne-input" name="map_url" value="<?= e($campus['map_url'] ?? '') ?>" placeholder="https://maps.google.com/...">
              </div>
            </div>
          </div>
        </div>

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-telephone"></i>ช่องทางติดต่อ</p>
          <div class="ne-panel-body">
            <p class="ne-hint" style="margin-top:0;margin-bottom:12px;">โทรศัพท์ / อีเมล / โซเชียลมีเดีย — เลือกได้หลายรายการ</p>
            <div id="contactRows"></div>
            <button type="button" class="ne-btn ne-btn-ghost ne-btn-sm" onclick="addContactRow()">
              <i class="bi bi-plus-lg"></i>เพิ่มช่องทางติดต่อ
            </button>
          </div>
        </div>

      </div>

      <!-- Side column -->
      <div class="ne-side">

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-image"></i>ภาพศูนย์</p>
          <div class="ne-panel-body">
            <?php if (!empty($campus['image'])): ?>
            <div class="ne-image-preview-wrap">
              <img src="<?= UPLOAD_URL . '/' . e($campus['image']) ?>" alt="">
              <label class="ne-image-remove-btn" title="ลบภาพนี้">
                <input type="checkbox" name="remove_image" value="1" style="display:none;" onchange="this.closest('.ne-image-preview-wrap').style.opacity=this.checked?0.4:1">
                <i class="bi bi-trash"></i>
              </label>
            </div>
            <?php endif; ?>
            <input type="file" class="ne-input" name="image" accept="image/*">
            <p class="ne-hint">เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนภาพ ไม่เลือกจะคงภาพเดิมไว้ หรือกดถังขยะเพื่อลบภาพ</p>
          </div>
        </div>

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-send"></i>บันทึกข้อมูล</p>
          <div class="ne-panel-body">
            <div class="ne-panel-actions">
              <button type="submit" class="ne-btn ne-btn-primary">
                <i class="bi bi-check-lg"></i><?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มศูนย์พื้นที่' ?>
              </button>
              <a href="index.php" class="ne-btn ne-btn-ghost">ยกเลิก</a>
            </div>
          </div>
        </div>

      </div>

    </div>
  </form>
</div>

<script id="existingContacts" type="application/json"><?= json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="contactTypeLabels" type="application/json"><?= json_encode($contactTypeLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php
$extraJs = '
<script>
const existingContacts = JSON.parse(document.getElementById("existingContacts").textContent);
const contactTypeLabels = JSON.parse(document.getElementById("contactTypeLabels").textContent);

function contactRowHtml(type, value, label) {
  type = type || "phone"; value = value || ""; label = label || "";
  let opts = "";
  for (const t in contactTypeLabels) {
    opts += `<option value="${t}" ${t === type ? "selected" : ""}>${contactTypeLabels[t]}</option>`;
  }
  return `<div class="ne-contact-row">
    <select class="ne-select" name="contact_type[]">${opts}</select>
    <input type="text" class="ne-input" name="contact_value[]" placeholder="เบอร์โทร / URL / @username" value="${value.replace(/"/g,"&quot;")}">
    <input type="text" class="ne-input" name="display_label[]" placeholder="ชื่อแสดง (ไม่บังคับ)" value="${label.replace(/"/g,"&quot;")}">
    <button type="button" class="ne-contact-remove" onclick="this.closest(\'.ne-contact-row\').remove()"><i class="bi bi-trash"></i></button>
  </div>`;
}
function addContactRow(type, value, label) {
  document.getElementById("contactRows").insertAdjacentHTML("beforeend", contactRowHtml(type, value, label));
}
existingContacts.forEach(c => addContactRow(c.contact_type, c.contact_value, c.display_label || ""));
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

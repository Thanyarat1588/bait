<?php
/**
 * BAIT Admin - Downloadable Forms Management
 * Same add/edit-in-sidebar CRUD flow as admin/modules/partners/index.php,
 * but for document files (PDF/Word) instead of logo images, with a
 * category (form_categories) and audience (student/staff) on each row.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

function uploadFormDocument(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 50 * 1024 * 1024) return null; // 50MB limit

    $allowedExt  = ['pdf', 'doc', 'docx'];
    $allowedMime = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip', // some servers report docx as zip via finfo
    ];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowedMime, true)) return null;
    }

    $destDir = rtrim(UPLOAD_PATH, '/') . '/forms-docs';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

    $filename = 'form_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) return null;

    return 'forms-docs/' . $filename;
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    $id = (int)($_POST['form_id'] ?? 0);
    $f  = $db->fetchOne("SELECT is_active FROM forms WHERE form_id = ?", [$id]);
    if ($f) { $db->update('forms', ['is_active' => $f['is_active'] ? 0 : 1], 'form_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
    jsonError('ไม่พบแบบฟอร์ม');
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['form_id'] ?? 0);
    $f  = $db->fetchOne("SELECT * FROM forms WHERE form_id = ?", [$id]);
    if ($f) {
        $db->delete('forms', 'form_id = ?', [$id]);
        fileQueueDelete($f['file']); fileCommitChanges();   // the file goes only once the row is really gone
        jsonSuccess([], 'ลบแบบฟอร์มแล้ว');
    }
    jsonError('ไม่พบแบบฟอร์ม');
}

// Add / edit — editing only replaces the file if a new one was chosen.
// File-only: the link_url option that used to live here moved to its own
// "สารสนเทศ" module/table (admin/modules/info-links), since an external
// system link isn't really the same kind of thing as a downloadable form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $id         = (int)($_POST['form_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $name       = postStr('form_name');
    $audience   = ($_POST['audience'] ?? 'student') === 'staff' ? 'staff' : 'student';

    if (!$categoryId) jsonError('กรุณาเลือกหมวดหมู่');
    if (!$db->fetchOne("SELECT 1 FROM form_categories WHERE category_id = ?", [$categoryId])) jsonError('ไม่พบหมวดหมู่ที่เลือก กรุณาเลือกใหม่');
    if (!$name) jsonError('กรุณากรอกชื่อแบบฟอร์ม');
    // (an existing form keeps its group, so compare inside the group it really belongs to)
    $chkAud = $id ? (string)($db->fetchOne('SELECT audience FROM forms WHERE form_id = ?', [$id])['audience'] ?? $audience) : $audience;
    if (rowExists($db, 'forms', ['form_name' => $name, 'audience' => $chkAud], 'form_id', $id)) jsonError('มีแบบฟอร์ม "' . $name . '" สำหรับ' . ($chkAud === 'staff' ? 'บุคลากร' : 'นักศึกษา') . 'อยู่ในระบบแล้ว กรุณาใช้ชื่ออื่น หรือแก้ไขรายการเดิมแทนการเพิ่มใหม่');

    if ($id) {
        $existing = $db->fetchOne("SELECT * FROM forms WHERE form_id = ?", [$id]);
        if (!$existing) jsonError('ไม่พบแบบฟอร์ม');
        $data = ['category_id' => $categoryId, 'form_name' => $name];   // the group (audience) of an existing form is never changed here

        if (!empty($_FILES['document']['name'])) {
            $up = uploadFormDocument($_FILES['document']);
            if (!$up) jsonError('อัปโหลดไฟล์ไม่สำเร็จ (รองรับ PDF, DOC, DOCX ไม่เกิน 50MB)');
            $data['file'] = $up;
            fileTrackNew($up); fileQueueDelete($existing['file']);   // the old file goes only once the change is saved
        } elseif (empty($existing['file'])) {
            jsonError('กรุณาเลือกไฟล์แบบฟอร์ม');
        }
        $db->update('forms', $data, 'form_id = ?', [$id]);
        fileCommitChanges();
        jsonSuccess([], 'แก้ไขแบบฟอร์มเรียบร้อยแล้ว');
    } else {
        if (empty($_FILES['document']['name'])) jsonError('กรุณาเลือกไฟล์แบบฟอร์ม');
        $up = uploadFormDocument($_FILES['document']);
        if (!$up) jsonError('อัปโหลดไฟล์ไม่สำเร็จ (รองรับ PDF, DOC, DOCX ไม่เกิน 50MB)');
        fileTrackNew($up);
        $db->insert('forms', ['category_id' => $categoryId, 'form_name' => $name, 'audience' => $audience, 'file' => $up, 'is_active' => 1]);
        fileCommitChanges();
        jsonSuccess([], 'เพิ่มแบบฟอร์มเรียบร้อยแล้ว');
    }
}

$search      = getStr('search');
$catFilter   = (int)($_GET['cat'] ?? 0);
$audFilter   = in_array($_GET['audience'] ?? '', ['student', 'staff'], true) ? $_GET['audience'] : '';
if ($audFilter === '') { header('Location: index.php?audience=student'); exit; }   // each group has its own menu entry
$audLabel    = $audFilter === 'staff' ? 'บุคลากร' : 'นักศึกษา';

$where  = '1=1';
$params = [];
if ($search)    { $where .= ' AND f.form_name LIKE ?'; $params[] = "%$search%"; }
if ($catFilter) { $where .= ' AND f.category_id = ?';  $params[] = $catFilter; }
$where .= ' AND f.audience = ?'; $params[] = $audFilter;

$categories = $db->fetchAll("SELECT * FROM form_categories ORDER BY category_id ASC");
$forms = $db->fetchAll(
    "SELECT f.*, c.category_name, c.color
     FROM forms f JOIN form_categories c ON c.category_id = f.category_id
     WHERE $where
     ORDER BY c.category_id ASC, f.form_id DESC",
    $params
);
$totalForms = (int)($db->fetchOne("SELECT COUNT(*) as c FROM forms WHERE audience = ?", [$audFilter])['c'] ?? 0);

function categoryChipStyle(?string $hex): string {
    $hex = $hex ?: '#162E93';
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '3e5fa3';
    return "background:#{$hex}1f;color:#{$hex};box-shadow:inset 0 0 0 1px #{$hex}33;";
}

$pageTitle   = 'แบบฟอร์มดาวน์โหลดสำหรับ' . $audLabel;
$activeMenu  = 'forms-' . $audFilter;
$breadcrumb  = [['label' => 'แบบฟอร์มดาวน์โหลด'], ['label' => 'สำหรับ' . $audLabel]];
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
.ne-hint { font-size: 12.5px; color: var(--ne-slate); }
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

.ne-grid { display: flex; flex-direction: column; gap: 24px; align-items: stretch; }
.ne-main { flex: 1 1 0; min-width: 0; }
.ne-side { width: 100%; flex-shrink: 0; }
@media (min-width: 1024px) { .ne-grid { flex-direction: row; align-items: flex-start; } .ne-side { width: 340px; } }

.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; }
.ne-panel-head {
  display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft);
}
.ne-panel-body { padding: 18px 20px 20px; }

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
.ne-table tr.is-inactive { opacity: .55; }

.ne-chip {
  display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; padding: 4px 11px;
  border-radius: 999px; white-space: nowrap;
}
.ne-chip::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

.ne-audience-chip {
  display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; padding: 3px 10px;
  border-radius: 999px; white-space: nowrap;
}
.ne-audience-student { background: var(--ne-blue-soft); color: var(--ne-blue); }
.ne-audience-staff { background: var(--ne-green-soft); color: var(--ne-green); }

.ne-file-link {
  display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600;
  color: var(--ne-blue); text-decoration: none;
}
.ne-file-link:hover { text-decoration: underline; }
.ne-file-ext {
  font-size: 10.5px; font-weight: 700; padding: 2px 6px; border-radius: 5px;
  background: var(--ne-slate-soft); color: var(--ne-ink-soft); letter-spacing: .03em;
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

.ne-field { margin-bottom: 16px; }
.ne-field label {
  font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 7px;
}
.ne-input, .ne-select {
  width: 100%; border: 1px solid var(--ne-line); border-radius: 8px; padding: 9px 12px;
  font-family: 'Kanit', sans-serif; font-size: 14px; color: var(--ne-ink);
  background: var(--ne-surface); outline: none; transition: border-color .15s ease;
}
.ne-input:focus, .ne-select:focus { border-color: var(--ne-blue); }

</style>

<div class="ne">
  <div class="ne-topbar">
    <h1>แบบฟอร์มดาวน์โหลดสำหรับ<?= $audLabel ?> <span class="ne-count"><?= number_format($totalForms) ?> รายการ</span></h1>
    <div class="d-flex gap-2" style="margin-left:auto;">
      <a href="<?= BASE_URL ?>/admin/modules/form-categories/index.php" class="ne-btn ne-btn-ghost">
        <i class="bi bi-folder2-open"></i>จัดการหมวดหมู่
      </a>
      <button type="button" class="ne-btn ne-btn-primary" onclick="openFormModal(0)" <?= empty($categories) ? 'disabled' : '' ?>>
        <i class="bi bi-upload"></i>เพิ่มแบบฟอร์มใหม่
      </button>
    </div>
  </div>

  <?php if (empty($categories)): ?>
  <div class="ne-panel">
    <div class="ne-empty">
      <i class="bi bi-folder2-open"></i>
      กรุณาเพิ่ม<a href="<?= BASE_URL ?>/admin/modules/form-categories/index.php">หมวดหมู่แบบฟอร์ม</a>ก่อน
    </div>
  </div>
  <?php else: ?>

  <div class="ne-alert ne-alert-info" style="margin-bottom:16px;"><i class="bi bi-info-circle"></i> แบบฟอร์มที่เพิ่มในหน้านี้จะแสดงให้ <b>ผู้เข้าชมกลุ่ม<?= $audLabel ?></b> เท่านั้น ถ้าต้องการเพิ่มของอีกกลุ่ม ให้เลือกจากเมนู <b>แบบฟอร์มดาวน์โหลด → สำหรับ<?= $audFilter === 'staff' ? 'นักศึกษา' : 'บุคลากร' ?></b> ด้านซ้าย</div>

  <!-- Filters -->
  <form class="ne-filters" method="GET">
    <input type="hidden" name="audience" value="<?= e($audFilter) ?>">
    <div class="ne-filters-field ne-search">
      <label for="fSearch">ค้นหา</label>
      <div class="ne-search-input-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="fSearch" class="ne-input" name="search"
               placeholder="ค้นหาชื่อแบบฟอร์ม..." value="<?= e($search) ?>">
      </div>
    </div>
    <div class="ne-filters-field">
      <label for="fCat">หมวดหมู่</label>
      <select id="fCat" class="ne-select" name="cat">
        <option value="">-- ทุกหมวดหมู่ --</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['category_id'] ?>" <?= $catFilter === (int)$c['category_id'] ? 'selected' : '' ?>>
          <?= e($c['category_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>ค้นหา</button>
      <a href="?audience=<?= e($audFilter) ?>" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <div class="ne-panel">
    <div class="ne-panel-head"><span>แบบฟอร์มทั้งหมด</span></div>

    <?php if (!empty($forms)): ?>
        <div class="table-responsive">
          <table class="ne-table">
            <thead>
              <tr>
                <th style="width:44px">#</th>
                <th>ชื่อแบบฟอร์ม</th>
                <th>หมวดหมู่</th>
                <th>เอกสาร</th>
                <th style="width:90px" class="text-center">สถานะ</th>
                <th style="width:110px">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($forms as $i => $f): $ext = $f['file'] ? strtoupper(pathinfo($f['file'], PATHINFO_EXTENSION)) : ''; ?>
              <tr data-id="<?= $f['form_id'] ?>" class="<?= $f['is_active'] ? '' : 'is-inactive' ?>">
                <td class="ne-num"><?= $i + 1 ?></td>
                <td class="fw-500"><?= e($f['form_name']) ?></td>
                <td><span class="ne-chip" style="<?= categoryChipStyle($f['color']) ?>"><?= e($f['category_name']) ?></span></td>
                <td>
                  <a href="<?= UPLOAD_URL . '/' . e($f['file']) ?>" target="_blank" class="ne-file-link">
                    <i class="bi bi-file-earmark-arrow-down"></i><span class="ne-file-ext"><?= e($ext) ?></span>
                  </a>
                </td>
                <td class="text-center">
                  <button class="ne-icon-btn <?= $f['is_active'] ? 'ne-icon-edit' : '' ?>"
                          style="<?= !$f['is_active'] ? 'color:var(--ne-slate);' : '' ?>"
                          onclick="toggleForm(<?= $f['form_id'] ?>)" title="<?= $f['is_active'] ? 'ซ่อน' : 'แสดง' ?>">
                    <i class="bi bi-<?= $f['is_active'] ? 'eye' : 'eye-slash' ?>"></i>
                  </button>
                </td>
                <td>
                  <div class="ne-actions">
                    <button class="ne-icon-btn ne-icon-edit" onclick="editForm(<?= $f['form_id'] ?>)" title="แก้ไข">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <button class="ne-icon-btn ne-icon-delete" onclick="deleteForm(<?= $f['form_id'] ?>, '<?= e(addslashes($f['form_name'])) ?>')" title="ลบ">
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
          <i class="bi bi-file-earmark-arrow-down"></i>
          <?= ($search || $catFilter) ? 'ไม่พบแบบฟอร์มที่ตรงกับตัวกรอง' : 'ยังไม่มีแบบฟอร์มสำหรับ' . $audLabel ?>
        </div>
        <?php endif; ?>
  </div>
  <?php endif; // categories ?>
</div>

<!-- Add/edit modal — same form used for both, triggered by the "เพิ่ม
     แบบฟอร์มใหม่" topbar button (add) or a row's pencil icon (edit). -->
<div class="modal fade" id="formModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content ne-modal-content ne">
      <div class="modal-header ne-modal-header">
        <h5 class="modal-title" id="formModalTitle" style="font-family:'Kanit',serif;">เพิ่มแบบฟอร์มใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body ne-modal-body">
        <input type="hidden" id="formId" value="0">
        <div class="ne-field">
          <label for="fCategory">หมวดหมู่ <span style="color:var(--ne-danger)">*</span></label>
          <select class="ne-select" id="fCategory" <?= empty($categories) ? 'disabled' : '' ?>>
            <option value="">-- เลือกหมวดหมู่ --</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['category_id'] ?>"><?= e($c['category_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ne-field">
          <label for="fName">ชื่อแบบฟอร์ม <span style="color:var(--ne-danger)">*</span></label>
          <input type="text" class="ne-input" id="fName" placeholder="เช่น แบบฟอร์มขอผ่อนผันการชำระเงิน" <?= empty($categories) ? 'disabled' : '' ?>>
        </div>
        <div class="ne-field">
          <label>กลุ่มเป้าหมาย</label>
          <div><span class="ne-audience-chip ne-audience-<?= e($audFilter) ?>"><i class="bi bi-<?= $audFilter === 'staff' ? 'person-badge' : 'mortarboard' ?>"></i> สำหรับ<?= $audLabel ?></span></div>
          <p class="ne-hint">กำหนดตามหน้าที่เปิดอยู่ (เมนู แบบฟอร์มดาวน์โหลด → สำหรับ<?= $audLabel ?>) ไม่ต้องเลือก</p>
        </div>
        <div class="ne-field" id="fFileGroup" style="margin-bottom:0;">
          <label for="fFile">ไฟล์แบบฟอร์ม <span id="fileRequiredMark" style="color:var(--ne-danger)">*</span></label>
          <input type="file" class="form-control" id="fFile" accept=".pdf,.doc,.docx" <?= empty($categories) ? 'disabled' : '' ?>>
          <p class="ne-hint" id="fileHint">รองรับ PDF, DOC, DOCX ขนาดไม่เกิน 50MB</p>
        </div>
        <p class="ne-hint">ลิงก์ไประบบภายนอก (เช่น Google Form) ย้ายไปจัดการที่เมนู <a href="<?= BASE_URL ?>/admin/modules/info-links/index.php">สารสนเทศ</a> แทนแล้ว</p>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
        <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="ne-btn ne-btn-primary" id="saveBtn" onclick="saveForm()">
          <i class="bi bi-plus-lg"></i>เพิ่มแบบฟอร์ม
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script>
const csrf = ' . json_encode(csrfToken()) . ';
const formsData = ' . json_encode(array_map(fn($f) => [
    'id' => (int)$f['form_id'], 'category_id' => (int)$f['category_id'],
    'form_name' => $f['form_name'], 'audience' => $f['audience'],
], $forms), JSON_UNESCAPED_UNICODE) . ';

async function toggleForm(id) {
  const fd = new FormData();
  fd.append("action","toggle"); fd.append("form_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1000); }
}

async function deleteForm(id, name) {
  const r = await Swal.fire({title:"ลบแบบฟอร์ม?",html:`ลบ <strong>${escapeHtml(name)}</strong> ?`,icon:"warning",
    showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("form_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
}

let formModal;

function openFormModal(id) {
  const f = id ? formsData.find(x => x.id === id) : null;
  document.getElementById("formId").value = f ? f.id : 0;
  document.getElementById("fCategory").value = f ? f.category_id : "";
  document.getElementById("fName").value = f ? f.form_name : "";
  document.getElementById("fFile").value = "";
  document.getElementById("fileRequiredMark").classList.toggle("d-none", !!f);
  document.getElementById("fileHint").textContent = f
    ? "เลือกไฟล์ใหม่เฉพาะตอนต้องการเปลี่ยนไฟล์ ไม่เลือกจะคงไฟล์เดิมไว้"
    : "รองรับ PDF, DOC, DOCX ขนาดไม่เกิน 50MB";
  document.getElementById("formModalTitle").textContent = f ? "แก้ไขแบบฟอร์ม" : "เพิ่มแบบฟอร์มใหม่";
  document.getElementById("saveBtn").innerHTML = f
    ? "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข"
    : "<i class=\"bi bi-plus-lg\"></i>เพิ่มแบบฟอร์ม";
  formModal = new bootstrap.Modal(document.getElementById("formModal"));
  formModal.show();
}

function editForm(id) {
  openFormModal(id);
}

async function saveForm() {
  const id       = document.getElementById("formId").value;
  const catId    = document.getElementById("fCategory").value;
  const name     = document.getElementById("fName").value.trim();
  const audience = ' . json_encode($audFilter) . ';   // the group of the page that is open
  const file     = document.getElementById("fFile").files[0];
  if (!catId) { showError("กรุณาเลือกหมวดหมู่"); return; }
  if (!name) { showError("กรุณากรอกชื่อแบบฟอร์ม"); return; }
  if (id === "0" && !file) { showError("กรุณาเลือกไฟล์แบบฟอร์ม"); return; }
  if (file && file.size > 50 * 1024 * 1024) { showError("ไฟล์ใหญ่เกินไป (สูงสุด 50MB)"); return; }
  const fd = new FormData();
  fd.append("action","save");
  fd.append("form_id",id);
  fd.append("category_id",catId);
  fd.append("form_name",name);
  fd.append("audience",audience);
  if (file) fd.append("document",file);
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { formModal.hide(); showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
  else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

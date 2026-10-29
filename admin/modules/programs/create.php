<?php
/**
 * BAIT Admin - Program Create / Edit
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$isEdit     = isset($_GET['id']);
$programId  = $isEdit ? (int)$_GET['id'] : 0;
$program    = [];

if ($isEdit) {
    $program = $db->fetchOne("SELECT * FROM programs WHERE program_id = ?",[$programId]);
    if (!$program) { header('Location: index.php'); exit; }
}

$majors  = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");
$levels  = $db->fetchAll("SELECT * FROM education_levels ORDER BY level_order");
$campuses= $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");

$selectedCampusIds = [];
if ($isEdit) {
    $selectedCampusIds = array_column(
        $db->fetchAll("SELECT campus_id FROM program_campuses WHERE program_id = ?", [$programId]),
        'campus_id'
    );
}

/**
 * Upload handler for program document (PDF / Word) — replaces old image upload.
 * Stored physically under UPLOAD_PATH/programs-docs, path saved in the existing
 * `image` column (no schema change needed).
 */
function uploadProgramDocument(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 25 * 1024 * 1024) return null; // 25MB limit

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

    $destDir = rtrim(UPLOAD_PATH, '/') . '/programs-docs';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

    $filename = 'program_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) return null;

    return 'programs-docs/' . $filename;
}

/** Decode the JSON payload kept in the `about` column into a predictable shape. */
function decodeAboutPayload(?string $raw): array
{
    $default = ['description' => '', 'topics' => []];
    if (!$raw) return $default;

    $decoded = json_decode($raw, true);
    if (is_array($decoded) && array_key_exists('description', $decoded)) {
        $decoded['topics'] = $decoded['topics'] ?? [];
        return $decoded;
    }

    // Legacy plain-text `about` value from before this change.
    $default['description'] = $raw;
    return $default;
}

/**
 * Decode the JSON payload kept in the `overview` column into a list of
 * ['title' => ..., 'content' => ...] sections. Falls back gracefully if the
 * column still holds old freeform CKEditor HTML from before this change.
 */
function decodeOverviewPayload(?string $raw): array
{
    if (!$raw) return [];

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $sections = [];
        foreach ($decoded as $item) {
            if (is_array($item) && (isset($item['title']) || isset($item['content']))) {
                $sections[] = ['title' => $item['title'] ?? '', 'content' => $item['content'] ?? ''];
            }
        }
        if ($sections) return $sections;
    }

    // Legacy raw HTML from the old CKEditor field — convert to plain text
    // and drop it into a single editable section so nothing is lost.
    $legacyText = str_replace(['<br>', '<br/>', '<br />'], "\n", $raw);
    $legacyText = trim(strip_tags(str_replace(['</p>', '</div>', '</li>'], "\n", $legacyText)));
    if ($legacyText === '') return [];
    return [['title' => 'ข้อมูลหลักสูตร', 'content' => $legacyText]];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name        = postStr('program_name');
    $abbr        = postStr('program_abbr');
    // สาขาวิชา is optional — บัณฑิตศึกษา programs (ป.โท/ป.เอก) are often
    // stand-alone, not tied to any one undergraduate สาขาวิชา.
    $majorId     = !empty($_POST['major_id']) ? (int)$_POST['major_id'] : null;
    $levelId     = (int)($_POST['level_id'] ?? 0);
    $campusIds   = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['campus_ids'] ?? null) ? $_POST['campus_ids'] : []))));
    $aboutDesc   = postStr('about_description');
    $ovTitles    = $_POST['overview_title'] ?? [];
    $ovContents  = $_POST['overview_content'] ?? [];
    $isActive    = (int)($_POST['is_active'] ?? 1);

    if (!$name)    $errors[] = 'กรุณากรอกชื่อหลักสูตร';
    if (!$levelId) $errors[] = 'กรุณาเลือกระดับการศึกษา';
    elseif (!$db->fetchOne('SELECT 1 FROM education_levels WHERE level_id = ?', [$levelId])) $errors[] = 'ไม่พบระดับการศึกษาที่เลือก กรุณาเลือกใหม่';
    if ($majorId && !$db->fetchOne('SELECT 1 FROM majors WHERE major_id = ?', [$majorId])) $errors[] = 'ไม่พบสาขาวิชาที่เลือก กรุณาเลือกใหม่';
    if ($campusIds) {
        $found = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM campuses WHERE campus_id IN (' . implode(',', array_fill(0, count($campusIds), '?')) . ')', $campusIds)['c'] ?? 0);
        if ($found !== count($campusIds)) $errors[] = 'ไม่พบศูนย์พื้นที่ที่เลือกบางรายการ กรุณาเลือกใหม่';
    }

    // "เรียนอะไรบ้าง" is no longer editable on this form — keep whatever
    // topics already exist for this program untouched instead of wiping
    // them every time an unrelated field on this form gets saved. Still
    // read by program-detail.php, just nothing here can add/change them
    // anymore.
    if ($name && $levelId && rowExists($db, 'programs', ['program_name' => $name, 'level_id' => $levelId], 'program_id', $isEdit ? $programId : 0)) $errors[] = 'มีหลักสูตร "' . $name . '" ในระดับการศึกษานี้อยู่ในระบบแล้ว กรุณาตรวจสอบ หรือแก้ไขรายการเดิมแทนการเพิ่มใหม่';

    $topics = $isEdit ? decodeAboutPayload($program['about'] ?? null)['topics'] : [];
    $aboutJson = json_encode(
        ['description' => $aboutDesc, 'topics' => $topics],
        JSON_UNESCAPED_UNICODE
    );

    // Build the "ข้อมูลเกี่ยวกับหลักสูตรเบื้องต้น" sections, skipping empty rows.
    $overviewSections = [];
    foreach (is_array($ovTitles) ? $ovTitles : [] as $i => $ovTitle) {
        $ovTitle   = is_string($ovTitle) ? trim($ovTitle) : '';
        $ovContent = is_array($ovContents) && is_string($ovContents[$i] ?? null) ? trim($ovContents[$i]) : '';
        if ($ovTitle === '' && $ovContent === '') continue;
        if ($ovTitle === 'ค่าธรรมเนียมการศึกษา') continue;   // tuition fees are entered in หลักสูตร > ค่าเทอม, not as free text here
        $overviewSections[] = ['title' => $ovTitle, 'content' => $ovContent];
    }
    $overviewJson = $overviewSections ? json_encode($overviewSections, JSON_UNESCAPED_UNICODE) : null;

    $documentPath = $program['file'] ?? null;
    if (!empty($_FILES['document']['name'])) {
        $up = uploadProgramDocument($_FILES['document']);
        if ($up) {
            fileQueueDelete($documentPath);
            fileTrackNew($up);
            $documentPath = $up;
        } else {
            $errors[] = 'อัปโหลดเอกสารไม่สำเร็จ (รองรับเฉพาะ PDF, DOC, DOCX ไม่เกิน 25MB)';
        }
    }

    // Cover photo — separate from the "เอกสารหลักสูตร" PDF/Word file above,
    // stored in its own `image` column. program-detail.php already renders
    // this as the page's hero image + og:image whenever it's set; this form
    // just never had a way to upload one until now.
    $imagePath = $program['image'] ?? null;
    $removeImg = !empty($_POST['remove_image']);
    if ($removeImg && empty($_FILES['image']['name'])) {
        fileQueueDelete($imagePath);
        $imagePath = null;
    }
    if (!empty($_FILES['image']['name'])) {
        $upImg = uploadImage($_FILES['image'], 'programs');
        if ($upImg) {
            fileQueueDelete($imagePath);
            fileTrackNew($upImg);
            $imagePath = $upImg;
        } else {
            $errors[] = 'อัปโหลดภาพไม่สำเร็จ (รองรับ JPG, PNG, WEBP ขนาดไม่เกิน 5MB)';
        }
    }

    if (empty($errors)) {
        $data = [
            'program_name' => $name,
            'program_abbr' => $abbr ?: null,
            'major_id'     => $majorId,
            'level_id'     => $levelId,
            'about'        => $aboutJson,
            'overview'     => $overviewJson,
            'file'         => $documentPath,
            'image'        => $imagePath,
            'is_active'    => $isActive,
        ];
        if ($isEdit) {
            $db->update('programs', $data, 'program_id = ?', [$programId]);
            $savedProgramId = $programId;
            $_SESSION['flash_success'] = 'แก้ไขหลักสูตรเรียบร้อยแล้ว';
        } else {
            $db->insert('programs', $data);
            $savedProgramId = (int)($db->fetchOne("SELECT LAST_INSERT_ID() as id")['id'] ?? 0);
            $_SESSION['flash_success'] = 'เพิ่มหลักสูตรเรียบร้อยแล้ว';
        }

        // Sync selected campuses (empty selection = applies to every campus).
        $db->delete('program_campuses', 'program_id = ?', [$savedProgramId]);
        foreach ($campusIds as $cid) {
            $db->insert('program_campuses', ['program_id' => $savedProgramId, 'campus_id' => $cid]);
        }

        fileCommitChanges();
        redirectThenPrewarm('index.php', [$name, $aboutDesc, mb_substr(trim(strip_tags($aboutDesc)), 0, 140, 'UTF-8')]);
    }

    // Re-populate $program with submitted values so the form redisplays correctly on error.
    fileRollbackChanges();   // rejected: keep the current files, drop the new uploads
    $documentPath = $program['file'] ?? null;
    $imagePath = $program['image'] ?? null;
    $program = array_merge($program, [
        'program_name' => $name, 'program_abbr' => $abbr, 'major_id' => $majorId,
        'level_id' => $levelId, 'about' => $aboutJson,
        'overview' => $overviewJson, 'file' => $documentPath, 'image' => $imagePath, 'is_active' => $isActive,
    ]);
    $selectedCampusIds = $campusIds;
}

$aboutData    = decodeAboutPayload($program['about'] ?? null);
$overviewData = array_values(array_filter(decodeOverviewPayload($program['overview'] ?? null), fn($s) => trim((string)($s['title'] ?? '')) !== 'ค่าธรรมเนียมการศึกษา'));
if (!$overviewData) {
    $overviewData = [
        ['title' => 'รหัสและชื่อหลักสูตร', 'content' => ''],
        ['title' => 'ชื่อวุฒิการศึกษาและสาขาวิชา', 'content' => ''],
        ['title' => 'วิชาเอก', 'content' => ''],
        ['title' => 'จำนวนหน่วยกิตที่เรียนตลอดหลักสูตร', 'content' => ''],
        ['title' => 'อาชีพที่สามารถประกอบได้หลังสำเร็จการศึกษา', 'content' => ''],
    ];
}

$pageTitle  = $isEdit ? 'แก้ไขหลักสูตร' : 'เพิ่มหลักสูตร';
$activeMenu = 'programs-list';
$breadcrumb = [['label'=>'จัดการหลักสูตร','url'=>BASE_URL.'/admin/modules/programs/index.php'],['label'=>$pageTitle]];
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
/* Auto-grow instead of a fixed rows= height + inner scrollbar — JS keeps
   these in sync with their own scrollHeight on load/input/paste. */
textarea.ne-input { overflow-y: hidden; resize: none; }
.ne-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%235b6480' stroke-width='1.6' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }

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
.ne-btn-outline { background: #fff; color: var(--ne-blue-deep); border-color: var(--ne-blue); }
.ne-btn-outline:hover { background: var(--ne-blue-soft); }
.ne-btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 7px; }
.ne-panel .ne-btn-primary, .ne-panel .ne-btn-ghost { width: 100%; justify-content: center; }
.ne-panel-actions { display: flex; flex-direction: column; gap: 8px; }

.ne-hint { font-size: 12.5px; color: var(--ne-slate); margin-top: 8px; line-height: 1.6; }

/* ── Campus picker ────────────────────────── */
.ne-campus-picker {
  display: flex; flex-wrap: wrap; gap: 8px; padding: 14px;
  border: 1px solid var(--ne-line); border-radius: 10px; background: var(--ne-paper);
}
.ne-campus-chip { position: relative; }
.ne-campus-chip input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.ne-campus-chip label {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1px solid var(--ne-line);
  border-radius: 999px; font-size: 13px; color: var(--ne-ink-soft); background: #fff; cursor: pointer;
  user-select: none; transition: .15s;
}
.ne-campus-chip input:checked + label { background: var(--ne-blue-soft); border-color: var(--ne-blue); color: var(--ne-blue-deep); font-weight: 600; }

/* ── Overview sections ────────────────────── */
.overview-item {
  display: flex; gap: 12px; align-items: flex-start; padding: 14px;
  border: 1px solid var(--ne-line); border-radius: 10px; background: var(--ne-paper); margin-bottom: 10px;
}
.overview-item:last-child { margin-bottom: 0; }
.overview-num {
  flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; background: var(--ne-blue-soft);
  color: var(--ne-blue-deep); font-size: 12.5px; font-weight: 700; display: flex; align-items: center;
  justify-content: center; margin-top: 2px;
}
.overview-fields { flex: 1 1 auto; min-width: 0; }
.overview-fields .ne-input { background: #fff; }

.ne-icon-btn-x {
  flex: 0 0 auto; margin-top: 2px;
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid #f0c9c6; background: var(--ne-danger-soft);
  color: var(--ne-danger); display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; transition: .15s;
}
.ne-icon-btn-x:hover { background: var(--ne-danger); border-color: var(--ne-danger); color: #fff; }

/* ── Document preview ─────────────────────── */
.ne-doc-preview {
  display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--ne-line);
  border-radius: 10px; text-decoration: none; color: inherit; margin-bottom: 10px;
}
.ne-doc-preview:hover { background: var(--ne-paper); }
.ne-doc-preview i { font-size: 28px; }
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
          <p class="ne-panel-title"><i class="bi bi-info-circle"></i>ข้อมูลหลักสูตร</p>
          <div class="ne-panel-body">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="ne-label">ชื่อหลักสูตร <span class="req">*</span></label>
                <input type="text" class="ne-input" name="program_name"
                       value="<?=e($program['program_name']??'')?>" placeholder="ชื่อหลักสูตร" required>
              </div>
              <div class="col-md-4">
                <label class="ne-label">ชื่อย่อ</label>
                <input type="text" class="ne-input" name="program_abbr"
                       value="<?=e($program['program_abbr']??'')?>" placeholder="เช่น บธ.บ.">
              </div>
              <div class="col-md-6">
                <label class="ne-label">สาขาวิชา</label>
                <select name="major_id" class="ne-select">
                  <option value="" <?=empty($program['major_id'])?'selected':''?>>-- ไม่มีสาขาวิชา (บัณฑิตศึกษา) --</option>
                  <?php foreach ($majors as $m): ?>
                  <option value="<?=$m['major_id']?>" <?=($program['major_id']??0)==$m['major_id']?'selected':''?>><?=e($m['major_name'])?></option>
                  <?php endforeach; ?>
                </select>
                <p class="ne-hint">เว้นว่างสำหรับหลักสูตรบัณฑิตศึกษา (ป.โท/ป.เอก) ที่ไม่ได้ผูกกับสาขาวิชาใดสาขาวิชาหนึ่งโดยเฉพาะ</p>
              </div>
              <div class="col-md-6">
                <label class="ne-label">ระดับการศึกษา <span class="req">*</span></label>
                <select name="level_id" class="ne-select" required>
                  <option value="">-- เลือกระดับ --</option>
                  <?php foreach ($levels as $l): ?>
                  <option value="<?=$l['level_id']?>" <?=($program['level_id']??0)==$l['level_id']?'selected':''?>><?=e($l['level_name'])?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="ne-label">ศูนย์พื้นที่</label>
                <div class="ne-campus-picker">
                  <?php if (empty($campuses)): ?>
                  <span style="font-size:13px;color:var(--ne-slate);">ยังไม่มีข้อมูลศูนย์พื้นที่ในระบบ</span>
                  <?php endif; ?>
                  <?php foreach ($campuses as $c): $cid = (int)$c['campus_id']; ?>
                  <div class="ne-campus-chip">
                    <input type="checkbox" id="campus_<?=$cid?>" name="campus_ids[]" value="<?=$cid?>"
                           <?=in_array($cid, $selectedCampusIds)?'checked':''?>>
                    <label for="campus_<?=$cid?>"><i class="bi bi-geo-alt"></i><?=e($c['campus_name'])?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
                <p class="ne-hint">ไม่เลือกศูนย์ใดเลย = เปิดรับสมัครทุกศูนย์ / เลือกได้มากกว่า 1 ศูนย์</p>
              </div>
            </div>
          </div>
        </div>

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-mortarboard"></i>เกี่ยวกับหลักสูตร</p>
          <div class="ne-panel-body">
            <label class="ne-label">เรียนเกี่ยวกับอะไร</label>
            <textarea class="ne-input" name="about_description" rows="4"
              placeholder="อธิบายภาพรวมของหลักสูตร เช่น หลักสูตรนี้เรียนเกี่ยวกับ..."><?=e($aboutData['description'])?></textarea>
          </div>
        </div>

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-file-text"></i>ข้อมูลเกี่ยวกับหลักสูตรเบื้องต้น</p>
          <div class="ne-panel-body">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <p class="ne-hint" style="margin:0;">แต่ละหัวข้อจะแสดงเรียงลำดับเป็นบล็อกในหน้ารายละเอียดหลักสูตร เช่น "รหัสและชื่อหลักสูตร", "วิชาเอก" · ค่าเทอมกรอกแยกที่เมนู <a href="<?= BASE_URL ?>/admin/modules/tuition/index.php">หลักสูตร &gt; ค่าเทอม</a></p>
              <button type="button" class="ne-btn ne-btn-outline ne-btn-sm text-nowrap ms-3" id="addOverviewBtn">
                <i class="bi bi-plus-lg"></i>เพิ่มหัวข้อ
              </button>
            </div>

            <div id="overviewWrap">
              <?php foreach ($overviewData as $idx => $sec): ?>
              <div class="overview-item">
                <div class="overview-num"><?=$idx+1?></div>
                <div class="overview-fields">
                  <input type="text" class="ne-input mb-2" name="overview_title[]"
                         placeholder="ชื่อหัวข้อ เช่น รหัสและชื่อหลักสูตร" value="<?=e($sec['title'])?>">
                  <textarea class="ne-input" name="overview_content[]" rows="3"
                            placeholder="รายละเอียดของหัวข้อนี้"><?=e($sec['content'])?></textarea>
                </div>
                <button type="button" class="ne-icon-btn-x remove-overview" title="ลบหัวข้อนี้">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div>

      <!-- Side column -->
      <div class="ne-side">

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-image"></i>ภาพหลักสูตร</p>
          <div class="ne-panel-body">
            <?php if (!empty($program['image'])): ?>
            <div class="ne-cover-preview" id="coverPreviewBlock">
              <img src="<?= UPLOAD_URL . '/' . e($program['image']) ?>" alt="">
              <div class="ne-cover-remove">
                <input type="checkbox" name="remove_image" id="removeImg" value="1" onchange="neToggleRemoveImage(this)">
                <label for="removeImg"><i class="bi bi-trash3"></i>ลบภาพนี้</label>
              </div>
            </div>
            <div class="ne-cover-removed-notice" id="coverRemovedNotice">
              <span><i class="bi bi-check-circle"></i>ภาพนี้จะถูกลบเมื่อคุณกดบันทึก</span>
              <button type="button" class="ne-undo-btn" onclick="neUndoRemoveImage()">เลิกทำ</button>
            </div>
            <?php endif; ?>

            <label class="ne-dropzone">
              <i class="bi bi-cloud-arrow-up"></i>
              <span>คลิกหรือลากไฟล์มาวางเพื่ออัปโหลด</span>
              <input type="file" name="image" id="imageInput"
                     accept="image/jpeg,image/png,image/webp,image/gif">
            </label>
            <p class="ne-dropzone-filename" id="imageFilename"></p>
            <p class="ne-hint">รองรับ JPG, PNG, WEBP ขนาดไม่เกิน 5MB — แสดงเป็นภาพหลักในหน้ารายละเอียดหลักสูตร</p>
          </div>
        </div>

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-file-earmark-text"></i>เอกสารหลักสูตร</p>
          <div class="ne-panel-body">
            <?php if (!empty($program['file'])):
                $docExt   = strtolower(pathinfo($program['file'], PATHINFO_EXTENSION));
                $docIcon  = $docExt === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-word';
                $docColor = $docExt === 'pdf' ? 'var(--ne-danger)' : 'var(--ne-blue)';
            ?>
            <a href="<?=UPLOAD_URL.'/'.e($program['file'])?>" target="_blank" class="ne-doc-preview">
              <i class="bi <?=$docIcon?>" style="color:<?=$docColor?>;"></i>
              <div class="text-truncate">
                <div style="font-weight:600;" class="text-truncate"><?=e(basename($program['file']))?></div>
                <small style="color:var(--ne-slate);">คลิกเพื่อเปิดเอกสาร</small>
              </div>
            </a>
            <?php endif; ?>
            <input type="file" class="ne-input" name="document" accept=".pdf,.doc,.docx">
            <p class="ne-hint">PDF, DOC, DOCX ไม่เกิน 25MB</p>
          </div>
        </div>
        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-send"></i>การเผยแพร่</p>
          <div class="ne-panel-body">
            <label class="ne-label">สถานะการรับสมัคร</label>
            <select name="is_active" class="ne-select" style="margin-bottom:14px;">
              <option value="1" <?=($program['is_active']??1)==1?'selected':''?>>🟢 เปิดรับสมัคร</option>
              <option value="0" <?=($program['is_active']??1)==0?'selected':''?>>🔴 ปิดรับสมัคร</option>
            </select>
            <p class="ne-hint" style="margin:-8px 0 14px;">ปิดรับสมัคร: หลักสูตรยังแสดงบนเว็บไซต์ตามปกติ (รายการหลักสูตร เมนู ค่าเทอม การค้นหา) พร้อมป้าย "ปิดรับสมัคร" และหน้าหลักสูตรจะแสดงปุ่มติดต่อแทนปุ่มสมัครเรียน</p>
            <div class="ne-panel-actions">
              <button type="submit" class="ne-btn ne-btn-primary">
                <i class="bi bi-check-lg"></i><?=$isEdit?'บันทึก':'เพิ่มหลักสูตร'?>
              </button>
              <a href="index.php" class="ne-btn ne-btn-ghost">ยกเลิก</a>
            </div>
          </div>
        </div>


      </div>
    </div>
  </form>
</div>

<script>
(function () {
  var fileInput = document.getElementById('imageInput');
  var filenameEl = document.getElementById('imageFilename');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) {
        filenameEl.textContent = 'เลือกไฟล์: ' + fileInput.files[0].name;
        filenameEl.style.display = 'block';
      } else {
        filenameEl.style.display = 'none';
      }
    });
  }
})();

function neToggleRemoveImage(checkbox) {
  var block  = document.getElementById('coverPreviewBlock');
  var notice = document.getElementById('coverRemovedNotice');
  if (!block || !notice) return;
  if (checkbox.checked) {
    block.style.display  = 'none';
    notice.style.display = 'flex';
  } else {
    block.style.display  = '';
    notice.style.display = 'none';
  }
}

function neUndoRemoveImage() {
  var checkbox = document.getElementById('removeImg');
  if (checkbox) {
    checkbox.checked = false;
    neToggleRemoveImage(checkbox);
  }
}
</script>

<?php
$extraJs = '
<script>
function renumberOverview() {
  document.querySelectorAll("#overviewWrap .overview-num").forEach((el, i) => {
    el.textContent = i + 1;
  });
}

// ── Auto-grow textareas: height tracks content instead of a fixed rows=
//    box with an inner scrollbar ──
function autoGrowTextarea(el) {
  el.style.height = "auto";
  // box-sizing:border-box means style.height sets the OUTER box, but
  // scrollHeight only reports content+padding (not the border) — without
  // adding it back the box ends up a couple px short of its own content.
  const cs = getComputedStyle(el);
  const border = parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
  el.style.height = (el.scrollHeight + border) + "px";
}
function autoGrowAll() {
  document.querySelectorAll("textarea.ne-input").forEach(autoGrowTextarea);
}
autoGrowAll();
// Sarabun (the page\'s own font) usually isn\'t downloaded yet at this point
// in a fresh page load — measuring scrollHeight against the fallback font\'s
// metrics can under/over-count wrapped lines, and nothing re-measures once
// Sarabun swaps in and reflows the text. Redo it once fonts are ready.
if (document.fonts && document.fonts.ready) { document.fonts.ready.then(autoGrowAll); }
document.addEventListener("input", (e) => {
  if (e.target.matches("textarea.ne-input")) autoGrowTextarea(e.target);
});
// A wider/narrower box re-wraps the same text to fewer/more lines (e.g.
// resizing the window, or toggling the sidebar) — recheck after that settles.
let autoGrowResizeTimer;
window.addEventListener("resize", () => {
  clearTimeout(autoGrowResizeTimer);
  autoGrowResizeTimer = setTimeout(autoGrowAll, 150);
});

function addOverviewSection(title = "", content = "") {
  const wrap = document.getElementById("overviewWrap");
  const div = document.createElement("div");
  div.className = "overview-item";
  div.innerHTML = `
    <div class="overview-num"></div>
    <div class="overview-fields">
      <input type="text" class="ne-input mb-2" name="overview_title[]"
             placeholder="ชื่อหัวข้อ เช่น รหัสและชื่อหลักสูตร" value="${title.replace(/"/g, "&quot;")}">
      <textarea class="ne-input" name="overview_content[]" rows="3"
                placeholder="รายละเอียดของหัวข้อนี้">${content}</textarea>
    </div>
    <button type="button" class="ne-icon-btn-x remove-overview" title="ลบหัวข้อนี้">
      <i class="bi bi-x-lg"></i>
    </button>`;
  wrap.appendChild(div);
  autoGrowTextarea(div.querySelector("textarea.ne-input"));
  renumberOverview();
}

document.getElementById("addOverviewBtn").addEventListener("click", () => addOverviewSection());

document.getElementById("overviewWrap").addEventListener("click", (e) => {
  const removeBtn = e.target.closest(".remove-overview");
  if (removeBtn) {
    removeBtn.closest(".overview-item").remove();
    renumberOverview();
  }
});

renumberOverview();
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>
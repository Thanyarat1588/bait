<?php
/**
 * BAIT Admin - Article Create / Edit
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();
$user = $auth->currentUser();

$isEdit    = isset($_GET['id']);
$articleId = $isEdit ? (int)$_GET['id'] : 0;
$article   = [];

if ($isEdit) {
    $article = $db->fetchOne("SELECT * FROM articles WHERE article_id = ?", [$articleId]);
    if (!$article) { $_SESSION['flash_error'] = 'ไม่พบบทความที่ต้องการแก้ไข'; header('Location: index.php'); exit; }
}

$allTags = $db->fetchAll("SELECT name FROM tags ORDER BY name ASC");

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title   = postStr('title');
    // Content pasted straight from a Facebook post carries Facebook's own
    // emoji as broken hotlinked <img> tags instead of plain Unicode
    // characters — clean it up once here, at save time, so it never has to
    // be fixed again at every place that reads it back out.
    $content = stripFacebookEmojiImages(postRaw('content'));
    $status  = in_array($_POST['status'] ?? '', ['เผยแพร่', 'แบบร่าง'], true) ? $_POST['status'] : 'แบบร่าง';
    $tags    = postStr('tags');
    $removeImg = !empty($_POST['remove_image']);

    if (!$title)   $errors[] = 'กรุณากรอกหัวข้อบทความ';
    elseif (nameTaken($db, 'articles', 'title', 'article_id', $title, $isEdit ? $articleId : 0)) $errors[] = 'มีบทความ "' . $title . '" อยู่ในระบบแล้ว กรุณาใช้หัวข้ออื่น หรือแก้ไขบทความเดิมแทนการเพิ่มใหม่';
    if (!$content) $errors[] = 'กรุณากรอกเนื้อหาบทความ';

    $imagePath = $article['image'] ?? null;

    if ($isEdit && $removeImg && empty($_FILES['image']['name'])) {
        fileQueueDelete($imagePath);
        $imagePath = null;
    }

    if (!empty($_FILES['image']['name'])) {
        $up = uploadImage($_FILES['image'], 'articles');
        if ($up) {
            fileQueueDelete($imagePath);
            fileTrackNew($up);
            $imagePath = $up;
        } else {
            $errors[] = 'อัปโหลดภาพไม่สำเร็จ (รองรับไฟล์รูปภาพ เช่น JPG, PNG, WEBP ขนาดไม่เกิน 5MB)';
        }
    }

    if (empty($errors)) {
        $data = [
            'title'   => $title,
            'content' => $content,
            'image'   => $imagePath,
            'status'  => $status,
            'tags'    => $tags ?: null,
            'user_id' => $user['user_id'],
        ];
        if ($isEdit) {
            unset($data['user_id']);   // the author stays the person who wrote it
            $db->update('articles', $data, 'article_id = ?', [$articleId]);
            $_SESSION['flash_success'] = 'แก้ไขบทความเรียบร้อยแล้ว';
        } else {
            $data['slug'] = createSlug($title);
            $db->insert('articles', $data);
            $_SESSION['flash_success'] = 'เพิ่มบทความเรียบร้อยแล้ว';
        }
        fileCommitChanges();
        redirectThenPrewarm('index.php', [$title, stripFacebookEmojiImages($content), mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($content))), 0, 160, 'UTF-8')]);
    }

    // มี error → merge ค่าที่กรอกกลับเข้าฟอร์ม
    fileRollbackChanges();   // rejected: keep the current picture, drop the new upload
    $imagePath = $article['image'] ?? null;
    $article = array_merge($article, [
        'title'   => $title,
        'content' => $content,
        'status'  => $status,
        'tags'    => $tags,
        'image'   => $imagePath,
    ]);
}

$pageTitle  = $isEdit ? 'แก้ไขบทความ' : 'เพิ่มบทความ';
$activeMenu = 'articles';
$breadcrumb = [
    ['label' => 'จัดการบทความ', 'url' => BASE_URL . '/admin/modules/articles/index.php'],
    ['label' => $pageTitle],
];
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
.ne-topbar-eyebrow {
  font-size: 12.5px; letter-spacing: .08em; color: var(--ne-slate); text-transform: uppercase;
  margin: 0 0 4px; font-weight: 600;
}
.ne-topbar h1 { font-family: 'Kanit', sans-serif; font-size: 26px; font-weight: 700; margin: 0; color: var(--ne-ink); }
.ne-topbar-actions { display: flex; gap: 10px; }
.ne-btn {
  font-family: 'Kanit', sans-serif; font-weight: 600; font-size: 14.5px; border-radius: 9px; padding: 10px 18px;
  border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  text-decoration: none; transition: all .15s ease; line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }

.ne-alert {
  background: var(--ne-danger-soft); border: 1px solid #f0c9c6; color: #8a2e29; border-radius: 10px;
  padding: 14px 16px; margin-bottom: 20px; font-size: 14.5px; line-height: 1.7;
}
.ne-alert strong { display: block; margin-bottom: 2px; }

.ne-grid { display: flex; flex-direction: column; gap: 24px; align-items: stretch; }
.ne-main { flex: 1 1 0; min-width: 0; }
.ne-side { width: 100%; flex-shrink: 0; display: flex; flex-direction: column; gap: 18px; }
@media (min-width: 1024px) { .ne-grid { flex-direction: row; align-items: flex-start; } .ne-side { width: 320px; } }

.ne-headline-wrap {
  background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px;
  padding: 22px 24px 18px; margin-bottom: 18px;
}
.ne-headline-wrap label {
  font-size: 12.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 10px;
}
.ne-headline-input {
  width: 100%; border: none; outline: none; font-family: 'Kanit', sans-serif; font-size: 30px;
  font-weight: 700; line-height: 1.35; color: var(--ne-ink); background: transparent; padding: 0;
}
.ne-headline-input::placeholder { color: #c7cbd8; }

.ne-meta-row { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--ne-line); }
.ne-meta-field { flex: 1 1 200px; }
.ne-meta-field label {
  font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 6px;
}
.ne-input {
  width: 100%; border: 1px solid var(--ne-line); border-radius: 8px; padding: 9px 12px;
  font-family: 'Kanit', sans-serif; font-size: 14.5px; color: var(--ne-ink); background: var(--ne-surface);
  outline: none; transition: border-color .15s ease;
}
.ne-input:focus { border-color: var(--ne-blue); }

.ne-editor-wrap { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; padding: 22px 24px 24px; }
.ne-editor-wrap > label {
  font-size: 12.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 12px;
}
.ne-editor-wrap .ck-editor__editable { min-height: 380px; font-family: 'Kanit', sans-serif; }
.ne-editor-wrap .ck.ck-editor__top .ck-sticky-panel__content { border-color: var(--ne-line) !important; }
.ne-editor-wrap .ck.ck-editor__main > .ck-editor__editable { border-color: var(--ne-line) !important; }

.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; padding: 18px 20px 20px; }
.ne-panel-title {
  font-size: 12.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--ne-ink-soft); display: flex; align-items: center; gap: 7px; margin: 0 0 14px;
}
.ne-panel-title i { color: var(--ne-gold); font-size: 14px; }

.ne-seg { display: flex; background: var(--ne-paper); border: 1px solid var(--ne-line); border-radius: 10px; padding: 4px; gap: 4px; margin-bottom: 14px; }
.ne-seg input { position: absolute; opacity: 0; pointer-events: none; }
.ne-seg label {
  flex: 1; text-align: center; font-size: 13.5px; font-weight: 600; padding: 8px 6px; border-radius: 7px;
  cursor: pointer; color: var(--ne-ink-soft); transition: all .15s ease; user-select: none;
}
.ne-seg input:checked + label.ne-seg-draft { background: var(--ne-slate-soft); color: var(--ne-ink); box-shadow: inset 0 0 0 1px var(--ne-line); }
.ne-seg input:checked + label.ne-seg-published { background: var(--ne-green-soft); color: #1f6b47; box-shadow: inset 0 0 0 1px #bfe2cd; }

.ne-panel .ne-btn { width: 100%; justify-content: center; }
.ne-panel-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.ne-hint { font-size: 12.5px; color: var(--ne-slate); margin-top: 8px; line-height: 1.6; }

.ne-cover-preview { position: relative; border-radius: 10px; overflow: hidden; margin-bottom: 12px; border: 1px solid var(--ne-line); }
.ne-cover-preview img { width: 100%; height: 150px; object-fit: cover; display: block; }
.ne-cover-remove { position: absolute; top: 8px; right: 8px; }
.ne-cover-remove input { position: absolute; opacity: 0; pointer-events: none; }
.ne-cover-remove label {
  display: flex; align-items: center; gap: 5px; background: rgba(30,42,69,.82); color: #fff; font-size: 12px;
  font-weight: 600; padding: 6px 10px; border-radius: 999px; cursor: pointer; backdrop-filter: blur(2px);
  transition: background .15s ease;
}
.ne-cover-remove input:checked + label { background: var(--ne-danger); }
.ne-cover-remove label i { font-size: 13px; }
.ne-cover-removed-notice {
  display: none;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: var(--ne-danger-soft);
  border: 1px solid #f0c9c6;
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 12px;
  font-size: 13px;
  color: #8a2e29;
}
.ne-cover-removed-notice span { display: flex; align-items: center; gap: 6px; }
.ne-undo-btn {
  background: #fff;
  border: 1px solid #f0c9c6;
  color: #8a2e29;
  font-size: 12.5px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 7px;
  cursor: pointer;
  flex-shrink: 0;
}
.ne-undo-btn:hover { background: var(--ne-danger); color: #fff; border-color: var(--ne-danger); }

.ne-dropzone {
  display: block; width: 100%; box-sizing: border-box;   /* full width of the card, so the box sits in the middle */
  border: 1.5px dashed var(--ne-line); border-radius: 10px; padding: 18px 14px; text-align: center;
  cursor: pointer; position: relative; transition: border-color .15s ease, background .15s ease;
}
.ne-dropzone:hover { border-color: var(--ne-gold); background: var(--ne-gold-soft); }
.ne-dropzone i { font-size: 20px; color: var(--ne-slate); margin-bottom: 6px; display: block; }
.ne-dropzone span { font-size: 13px; color: var(--ne-ink-soft); font-weight: 500; display: block; }
.ne-dropzone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.ne-dropzone-filename { font-size: 12.5px; color: var(--ne-gold); font-weight: 600; margin-top: 6px; display: none; }
</style>

<div class="ne">
  <?php if (!empty($errors)): ?>
  <div class="ne-alert">
    <strong><i class="bi bi-exclamation-circle me-1"></i>กรุณาตรวจสอบข้อมูล</strong>
    <?= implode('<br>', array_map('e', $errors)) ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="articleForm">
    <?= csrfField() ?>
    <div class="ne-grid">

      <div class="ne-main">
        <div class="ne-headline-wrap">
          <label for="titleInput">หัวข้อบทความ</label>
          <input type="text" id="titleInput" class="ne-headline-input" name="title"
                 placeholder="ตั้งชื่อบทความของคุณ..." value="<?= e($article['title'] ?? '') ?>" required>

          <div class="ne-meta-row">
            <div class="ne-meta-field">
              <label for="tagPickerInput">แท็ก</label>
              <div class="ne-tag-picker" id="tagPickerWrap">
                <div class="ne-tag-chips" id="tagChips"></div>
                <input type="text" id="tagPickerInput" class="ne-input" placeholder="พิมพ์เพื่อค้นหาหรือเพิ่มแท็กใหม่..." autocomplete="off">
                <div class="ne-tag-dropdown d-none" id="tagDropdown"></div>
              </div>
              <input type="hidden" id="tagsInput" name="tags" value="<?= e($article['tags'] ?? '') ?>">
            </div>
          </div>
        </div>

        <div class="ne-editor-wrap">
          <label for="articleContent">เนื้อหาบทความ</label>
          <textarea name="content" id="articleContent" rows="15"><?= e($article['content'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="ne-side">

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-image"></i>ภาพหน้าปก</p>
          <?php if (!empty($article['image'])): ?>
          <div class="ne-cover-preview" id="coverPreviewBlock">
            <img src="<?= UPLOAD_URL . '/' . e($article['image']) ?>" alt="">
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
            <input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp,image/gif">
          </label>
          <p class="ne-dropzone-filename" id="imageFilename"></p>
          <p class="ne-hint">รองรับไฟล์รูปภาพ เช่น JPG, PNG, WEBP ขนาดไม่เกิน 5MB</p>
        </div>

         <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-send"></i>การเผยแพร่</p>
          <div class="ne-seg">
            <input type="radio" name="status" id="statusDraft" value="แบบร่าง"
                   <?= ($article['status'] ?? '') === 'แบบร่าง' || empty($article['status']) ? 'checked' : '' ?>>
            <label for="statusDraft" class="ne-seg-draft">📝 แบบร่าง</label>
            <input type="radio" name="status" id="statusPublished" value="เผยแพร่"
                   <?= ($article['status'] ?? '') === 'เผยแพร่' ? 'checked' : '' ?>>
            <label for="statusPublished" class="ne-seg-published">🟢 เผยแพร่</label>
          </div>
          <div class="ne-panel-actions">
            <button type="submit" class="ne-btn ne-btn-primary">
              <i class="bi bi-check-lg"></i><?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มบทความ' ?>
            </button>
            <a href="index.php" class="ne-btn ne-btn-ghost">ยกเลิก</a>
          </div>
        </div>
      </div>

    </div>
  </form>
</div>

<script src="<?= BASE_URL ?>/admin/assets/js/tag-picker.js?v=<?= @filemtime(BASE_PATH . '/admin/assets/js/tag-picker.js') ?: time() ?>"></script>
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

initTagPicker({
  wrapId: 'tagPickerWrap',
  chipsId: 'tagChips',
  inputId: 'tagPickerInput',
  hiddenId: 'tagsInput',
  dropdownId: 'tagDropdown',
  allTags: <?= json_encode(array_column($allTags, 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  saveUrl: '../tags/save.php'
});

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
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js" integrity="sha384-69SUO5s28dXCoNTUaA/KXhfDJu21xD394Gxk/S6d/YJZUxrz7Zagi+seruzXFTed" crossorigin="anonymous"></script>
<script>
ClassicEditor.create(document.querySelector("#articleContent"), {
  language: "th",
  toolbar: {
    items: [
      "heading", "|",
      "bold","italic", "|",
      "bulletedList","numberedList", "|",
      "link","blockQuote","insertTable", "|",
      "undo","redo"
    ]
  }
}).catch(err => console.error(err));
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>
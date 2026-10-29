<?php
/**
 * BAIT Admin - หัวข้อหน้าเกี่ยวกับคณะ (add/edit)
 * A full page instead of a modal — the content field can run long (e.g.
 * พันธกิจ's numbered list), and a cramped popup made that awkward to type/
 * review. Mirrors the plain-page pattern already used by
 * admin/modules/programs/create.php (GET ?id= for edit, otherwise add;
 * redisplay with $errors on validation failure instead of redirecting, so
 * nothing typed is lost).
 *
 * Three section_type values share this one page:
 *  - 'text'       — icon + color + rich (CKEditor) content + up to 1 image.
 *  - 'grid'       — just a title/subtitle heading; its actual content is its
 *                   own independent photo gallery (about_section_items),
 *                   managed below via inline AJAX actions (item_save/
 *                   item_delete/item_reorder/item_toggle) once the section
 *                   itself exists — a brand new 'grid' section must be saved
 *                   once before items can be added, since they need a real
 *                   section_id to belong to.
 *  - 'highlights' — the one fixed จุดเด่นของคณะ slot (title/subtitle only,
 *                   like 'grid'), but its items come from the shared
 *                   `highlights` table instead — the site's one fixed
 *                   homepage grid, unrelated to 'grid' rows' own independent
 *                   galleries. Managed below via highlight_save/
 *                   highlight_delete/highlight_reorder/highlight_toggle,
 *                   merged in from admin/modules/faculty/index.php's old
 *                   Tab 3 (now gone — that page is just the 2 remaining
 *                   topics list + general info). Not scoped by $sectionId
 *                   since `highlights` has no section_id column, unlike
 *                   about_section_items — it's one single fixed list.
 *                   There is always at most one 'highlights' row and it's
 *                   never created via the ประเภทหัวข้อ dropdown (not an
 *                   option there), only ever edited.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();
// Display options need the about_sections.display_options column; until it exists the panel is hidden and nothing is saved to it.
$hasDisplayOptions = (bool)$db->fetchOne("SHOW COLUMNS FROM about_sections LIKE 'display_options'");

// Same fixed sprite-slug picker as admin/modules/faculty/index.php (kept in
// sync there too) — the public about.php page is framework-free, so icons
// render from the site's own small custom SVG sprite, not an arbitrary
// Bootstrap Icons class. The value on the right is only for THIS picker's
// buttons to preview with; it is never saved.
$sectionIconOptions = [
    'eye' => 'bi-eye', 'target' => 'bi-bullseye', 'lightbulb' => 'bi-lightbulb', 'award' => 'bi-award',
    'users' => 'bi-people', 'shield-check' => 'bi-shield-check', 'globe' => 'bi-globe', 'heart' => 'bi-heart',
    'book' => 'bi-book', 'graduation-cap' => 'bi-mortarboard', 'building' => 'bi-building', 'briefcase' => 'bi-briefcase',
    'diagram' => 'bi-diagram-3', 'star' => 'bi-star', 'compass' => 'bi-compass', 'flame' => 'bi-fire',
    'check-circle' => 'bi-check-circle', 'calendar' => 'bi-calendar3', 'home' => 'bi-house-door', 'list' => 'bi-card-checklist',
];
$itemIconOptions = [
    'lightbulb', 'briefcase', 'user', 'users', 'building', 'check-circle',
    'award', 'star', 'target', 'compass', 'book', 'graduation-cap',
    'shield-check', 'heart', 'flame', 'diagram', 'globe', 'calendar',
];
// Icon picker for the จุดเด่นของคณะ (highlights table) sub-manager below —
// same fixed slug list as the old admin/modules/highlights module this was
// merged in from, now a button grid with real icon previews (like
// $sectionIconOptions above) instead of a plain <select> of slug names.
$highlightIconOptions = [
    'lightbulb' => 'bi-lightbulb', 'briefcase' => 'bi-briefcase', 'user' => 'bi-person', 'users' => 'bi-people',
    'building' => 'bi-building', 'check-circle' => 'bi-check-circle', 'award' => 'bi-award', 'star' => 'bi-star',
    'target' => 'bi-bullseye', 'compass' => 'bi-compass', 'book' => 'bi-book', 'graduation-cap' => 'bi-mortarboard',
    'shield-check' => 'bi-shield-check', 'heart' => 'bi-heart', 'flame' => 'bi-fire', 'diagram' => 'bi-diagram-3',
    'globe' => 'bi-globe', 'calendar' => 'bi-calendar3',
];

/**
 * Old rows (from before this page used CKEditor) store plain text under
 * 'paragraph'/'list'/'bullet' — opening one here for the first time would
 * otherwise dump that as one run-on, unformatted line into the editor, so
 * convert it to real markup on the way in. Saving always writes back as
 * 'html' (see the POST handler below), so this only ever fires once per row.
 */
function legacyContentToHtml(string $content, string $format): string {
    if ($format === 'list' || $format === 'bullet') {
        $tag = $format === 'bullet' ? 'ul' : 'ol';
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $html = "<{$tag}>";
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = preg_replace('/^\d+[.\)]\s*/', '', $line);
            $html .= '<li>' . e($line) . '</li>';
        }
        return $html . "</{$tag}>";
    }
    return '<p>' . nl2br(e($content)) . '</p>';
}

$isEdit    = isset($_GET['id']);
$sectionId = $isEdit ? (int)$_GET['id'] : 0;
$section   = [];

if ($isEdit) {
    $section = $db->fetchOne("SELECT * FROM about_sections WHERE section_id = ?", [$sectionId]);
    if (!$section) { header('Location: index.php?tab=sections'); exit; }
    if ($section['section_type'] === 'text' && $section['content_format'] !== 'html' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $section['content'] = legacyContentToHtml($section['content'] ?? '', $section['content_format']);
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ============================================================
    // Grid items CRUD (AJAX) — only ever called against an existing 'grid'
    // section, so $sectionId is always trustworthy here.
    // ============================================================
    if ($action === 'item_save') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $title  = postStr('title');
        $icon   = in_array($_POST['icon'] ?? '', $itemIconOptions, true) ? $_POST['icon'] : 'lightbulb';
        if (!$title) jsonError('กรุณากรอกชื่อรายการ');
        if (rowExists($db, 'about_section_items', ['title' => $title, 'section_id' => $sectionId], 'item_id', $itemId)) jsonError(dupMsg('รายการ', $title) . ' ในหัวข้อนี้');
        if (!$sectionId || !$section) jsonError('ไม่พบหัวข้อที่จะเพิ่มรายการ กรุณาเปิดหน้าแก้ไขหัวข้อก่อน');

        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $up = uploadImage($_FILES['image'], 'about-section-items');
            if (!$up) jsonError('อัปโหลดรูปไม่สำเร็จ');
            $imagePath = $up;
            fileTrackNew($up);   // dropped again if this save does not go through
        }
        $removeImage = empty($imagePath) && !empty($_POST['remove_image']);

        if ($itemId) {
            $existing = $db->fetchOne("SELECT * FROM about_section_items WHERE item_id = ? AND section_id = ?", [$itemId, $sectionId]);
            if (!$existing) jsonError('ไม่พบรายการ');
            $data = ['title' => $title, 'icon' => $icon];
            if ($imagePath) {
                $data['image'] = $imagePath;
                fileQueueDelete($existing['image']);   // the old picture goes only once the change is saved
            } elseif ($removeImage) {
                $data['image'] = null;
                fileQueueDelete($existing['image']);
            }
            $db->update('about_section_items', $data, 'item_id = ?', [$itemId]);
            fileCommitChanges();
            jsonSuccess([], 'แก้ไขรายการเรียบร้อยแล้ว');
        } else {
            $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM about_section_items WHERE section_id = ?", [$sectionId])['m'] ?? 0);
            $db->insert('about_section_items', [
                'section_id'    => $sectionId,
                'title'         => $title,
                'icon'          => $icon,
                'image'         => $imagePath,
                'display_order' => $maxOrder + 1,
                'is_active'     => 1,
            ]);
            fileCommitChanges();
            jsonSuccess([], 'เพิ่มรายการเรียบร้อยแล้ว');
        }
    }

    if ($action === 'item_toggle') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $item = $db->fetchOne("SELECT is_active FROM about_section_items WHERE item_id = ? AND section_id = ?", [$itemId, $sectionId]);
        if ($item) { $db->update('about_section_items', ['is_active' => $item['is_active'] ? 0 : 1], 'item_id = ?', [$itemId]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
        jsonError('ไม่พบรายการ');
    }

    if ($action === 'item_delete') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $item = $db->fetchOne("SELECT * FROM about_section_items WHERE item_id = ? AND section_id = ?", [$itemId, $sectionId]);
        if ($item) {
            $db->delete('about_section_items', 'item_id = ?', [$itemId]);
            fileQueueDelete($item['image']); fileCommitChanges();   // the file goes only once the row is really gone
            jsonSuccess([], 'ลบรายการแล้ว');
        }
        jsonError('ไม่พบรายการ');
    }

    if ($action === 'item_reorder') {
        $ids = json_decode(postRaw('ids', '[]'), true); if (!is_array($ids)) $ids = [];
        foreach ($ids as $order => $id) {
            $db->update('about_section_items', ['display_order' => $order + 1], 'item_id = ? AND section_id = ?', [(int)$id, $sectionId]);
        }
        jsonSuccess([], 'บันทึกลำดับแล้ว');
    }

    // ============================================================
    // จุดเด่นของคณะ CRUD (AJAX) — the shared `highlights` table (same one
    // the homepage renders), managed here on this page only when editing
    // the one about_sections row with section_type='highlights'. Merged in
    // from admin/modules/faculty/index.php's old Tab 3, which is gone now.
    // Not scoped by $sectionId since `highlights` has no section_id column
    // — it's one single fixed list, not per-section like about_section_items.
    // ============================================================
    if ($action === 'highlight_reorder') {
        $ids = json_decode(postRaw('ids', '[]'), true); if (!is_array($ids)) $ids = [];
        foreach ($ids as $order => $id) {
            $db->update('highlights', ['display_order' => $order + 1], 'highlight_id = ?', [(int)$id]);
        }
        jsonSuccess([], 'บันทึกลำดับแล้ว');
    }

    if ($action === 'highlight_toggle') {
        $id = (int)($_POST['highlight_id'] ?? 0);
        $h  = $db->fetchOne("SELECT is_active FROM highlights WHERE highlight_id = ?", [$id]);
        if ($h) { $db->update('highlights', ['is_active' => $h['is_active'] ? 0 : 1], 'highlight_id = ?', [$id]); jsonSuccess([], 'เปลี่ยนสถานะแล้ว'); }
        jsonError('ไม่พบจุดเด่น');
    }

    if ($action === 'highlight_delete') {
        $id = (int)($_POST['highlight_id'] ?? 0);
        $h  = $db->fetchOne("SELECT * FROM highlights WHERE highlight_id = ?", [$id]);
        if ($h) {
            $db->delete('highlights', 'highlight_id = ?', [$id]);
            fileQueueDelete($h['media_path']); fileCommitChanges();   // the file goes only once the row is really gone
            jsonSuccess([], 'ลบจุดเด่นแล้ว');
        }
        jsonError('ไม่พบจุดเด่น');
    }

    if ($action === 'highlight_save') {
        $id          = (int)($_POST['highlight_id'] ?? 0);
        $title       = postStr('title');
        $description = postStr('description');
        $icon        = array_key_exists($_POST['icon'] ?? '', $highlightIconOptions) ? $_POST['icon'] : 'lightbulb';
        if (!$title) jsonError('กรุณากรอกชื่อจุดเด่น');
        if (nameTaken($db, 'highlights', 'title', 'highlight_id', $title, $id)) jsonError(dupMsg('จุดเด่น', $title));

        $mediaUpload = null;
        if (!empty($_FILES['media']['name'])) {
            $mediaUpload = uploadMedia($_FILES['media'], 'highlights');
            if (!$mediaUpload) jsonError('อัปโหลดไฟล์ไม่สำเร็จ (รองรับ JPG, PNG, GIF, WEBP, SVG, MP4, WEBM ขนาดไม่เกิน 20MB)');
            fileTrackNew($mediaUpload['path']);   // dropped again if this save does not go through
        }

        $cropScaleX = null; $cropScaleY = null;
        if ($mediaUpload && $mediaUpload['type'] === 'image') {
            $cx = isset($_POST['crop_scale_x']) ? (float)$_POST['crop_scale_x'] : null;
            $cy = isset($_POST['crop_scale_y']) ? (float)$_POST['crop_scale_y'] : null;
            if ($cx !== null && $cx >= 1.0 && $cx <= 1.6) $cropScaleX = $cx;
            if ($cy !== null && $cy >= 1.0 && $cy <= 1.6) $cropScaleY = $cy;
        }

        if ($id) {
            $existing = $db->fetchOne("SELECT * FROM highlights WHERE highlight_id = ?", [$id]);
            if (!$existing) jsonError('ไม่พบจุดเด่น');
            $data = ['title' => $title, 'description' => $description ?: null, 'icon' => $icon];
            if ($mediaUpload) {
                $data['media_type']   = $mediaUpload['type'];
                $data['media_path']   = $mediaUpload['path'];
                $data['crop_scale_x'] = $cropScaleX;
                $data['crop_scale_y'] = $cropScaleY;
                fileQueueDelete($existing['media_path']);   // the old file goes only once the change is saved
            }
            $db->update('highlights', $data, 'highlight_id = ?', [$id]);
            fileCommitChanges();
            jsonSuccess([], 'แก้ไขจุดเด่นเรียบร้อยแล้ว');
        } else {
            $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM highlights")['m'] ?? 0);
            $db->insert('highlights', [
                'title'         => $title,
                'description'   => $description ?: null,
                'icon'          => $icon,
                'media_type'    => $mediaUpload['type'] ?? 'none',
                'media_path'    => $mediaUpload['path'] ?? null,
                'crop_scale_x'  => $cropScaleX,
                'crop_scale_y'  => $cropScaleY,
                'display_order' => $maxOrder + 1,
                'is_active'     => 1,
            ]);
            fileCommitChanges();
            jsonSuccess([], 'เพิ่มจุดเด่นเรียบร้อยแล้ว');
        }
    }

    // ============================================================
    // The section form itself (plain POST-redirect, no `action`)
    // ============================================================
    if ($action === '') {
        // The legacy จุดเด่นของคณะ row (section_type='highlights') isn't a
        // choice on the ประเภทหัวข้อ dropdown — it stays 'highlights' no
        // matter what that field posts, so a save here can never silently
        // flip it to 'text'/'grid'. Its actual highlight items still live
        // in the shared `highlights` table, managed on the "จุดเด่นของคณะ" tab.
        if ($isEdit && $section['section_type'] === 'highlights') {
            $sectionType = 'highlights';
        } else {
            $sectionType = ($_POST['section_type'] ?? 'text') === 'grid' ? 'grid' : 'text';
        }
        $title       = postStr('title');
        $width       = 'full'; // every section is a full-width row on about.php
        if (!$title) $errors[] = 'กรุณากรอกชื่อหัวข้อ';
        elseif (nameTaken($db, 'about_sections', 'title', 'section_id', $title, $isEdit ? $sectionId : 0)) $errors[] = dupMsg('หัวข้อ', $title);

        $data = ['section_type' => $sectionType, 'title' => $title, 'width' => $width];

        // 'highlights' has no content/icon/color/image of its own here (same
        // as 'grid') — its subtitle is the only editable field besides title,
        // shown on about.php above the shared highlights grid.
        if ($sectionType === 'grid' || $sectionType === 'highlights') {
            $data['subtitle'] = postStr('subtitle') ?: null;
        } else {
            $content = postStr('content');
            $color   = postStr('color', '#0F439B');
            $icon    = array_key_exists($_POST['icon'] ?? '', $sectionIconOptions) ? $_POST['icon'] : 'target';
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#0F439B';
            // CKEditor's "empty" state is still markup like <p>&nbsp;</p>, not
            // "" — strip tags/entities first so a genuinely blank editor still
            // trips this.
            if (trim(html_entity_decode(strip_tags($content), ENT_QUOTES, 'UTF-8')) === '') $errors[] = 'กรุณากรอกเนื้อหา';

            $data['content']        = $content;
            // 'html' marks content as real CKEditor markup (rendered as-is on
            // about.php) rather than the old plain-text 'paragraph'/'list'/
            // 'bullet' rows, which are only ever read now, never written —
            // see about.php's renderSectionContent() for the fallback.
            $data['content_format'] = 'html';
            $data['color']          = $color;
            $data['icon']           = $icon;
            if ($hasDisplayOptions) {
                // every value is whitelisted inside aboutSectionOptions()
                $data['display_options'] = json_encode(aboutSectionOptions([
                    'style'    => $_POST['ds_style']    ?? 'card',
                    'autoplay' => $_POST['ds_autoplay'] ?? 0,
                    'badge'    => $_POST['ds_badge']    ?? 'auto',
                    'shape'    => $_POST['ds_shape']    ?? 'pill',
                    'effect'   => $_POST['ds_effect']   ?? 'fade',
                    'counter'  => !empty($_POST['ds_counter']),
                    'mark'     => !empty($_POST['ds_mark']),
                ]));
            }
        }

        if (empty($errors)) {
            if ($isEdit) {
                $db->update('about_sections', $data, 'section_id = ?', [$sectionId]);
                $_SESSION['flash_success'] = 'แก้ไขหัวข้อเรียบร้อยแล้ว';
                header('Location: section-form.php?id=' . $sectionId); exit;
            } else {
                $maxOrder = (int)($db->fetchOne("SELECT MAX(display_order) as m FROM about_sections")['m'] ?? 0);
                $data['display_order'] = $maxOrder + 1;
                $data['is_active'] = 1;
                $newId = $db->insert('about_sections', $data);
                $_SESSION['flash_success'] = $sectionType === 'grid'
                    ? 'เพิ่มหัวข้อเรียบร้อยแล้ว — เพิ่มรูปภาพลงในกริดได้เลย'
                    : 'เพิ่มหัวข้อเรียบร้อยแล้ว';
                // A new 'grid' section needs its own section_id before items
                // can be added, so land back here in edit mode instead of the
                // list — a 'text' section is already complete, so it's fine
                // to go straight back to the list.
                header('Location: ' . ($sectionType === 'grid' ? 'section-form.php?id=' . $newId : 'index.php?tab=sections')); exit;
            }
        }

        // Re-populate so the form redisplays what was typed instead of losing it.
        $section = array_merge($section, $data);
    }
}

$isGridType = ($section['section_type'] ?? 'text') === 'grid';
$isLegacyHighlights = ($section['section_type'] ?? '') === 'highlights';
// Field visibility only — 'highlights' shows the same title+subtitle+width
// fields as 'grid' (no content/icon/color/image of its own), but never gets
// the grid-items sub-manager below, since its items live in the highlights
// table/tab instead of about_section_items.
$showGridFields = $isGridType || $isLegacyHighlights;
$optChoices = aboutOptionChoices();
$secOpt     = aboutSectionOptions($section['display_options'] ?? null);
$gridItems  = ($isEdit && $isGridType) ? $db->fetchAll("SELECT * FROM about_section_items WHERE section_id = ? ORDER BY display_order ASC", [$sectionId]) : [];
$highlights = ($isEdit && $isLegacyHighlights) ? $db->fetchAll("SELECT * FROM highlights ORDER BY display_order ASC") : [];

$pageTitle   = $isEdit ? 'แก้ไขหัวข้อ' : 'เพิ่มหัวข้อใหม่';
$activeMenu  = 'faculty-info';
$breadcrumb  = [['label' => 'ข้อมูลคณะ', 'url' => 'index.php'], ['label' => $pageTitle]];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <a href="index.php?tab=sections" class="ne-btn ne-btn-ghost" style="margin-left:auto;"><i class="bi bi-arrow-left"></i>กลับ</a>
  </div>

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
        <div class="ne-panel" style="margin-bottom:14px;">
          <p class="ne-panel-title"><i class="bi bi-layout-text-window-reverse"></i>ประเภทหัวข้อ</p>
          <div class="ne-panel-body">
            <?php if ($isLegacyHighlights): ?>
            <input type="text" class="ne-input" value="จุดเด่นของคณะ (กริดรูปภาพของหน้าแรก)" disabled>
            <input type="hidden" name="section_type" value="highlights">
            <p class="ne-hint">หัวข้อนี้เป็นจุดเด่นของคณะที่แสดงบนหน้าแรกด้วย เปลี่ยนประเภทไม่ได้</p>
            <?php else: ?>
            <div class="ne-field" style="margin-bottom:0;">
              <select class="ne-input" id="sectionType" name="section_type" onchange="updateTypeUI()" <?= $isEdit ? 'disabled' : '' ?>>
                <option value="text" <?= !$isGridType ? 'selected' : '' ?>>ข้อความ</option>
                <option value="grid" <?= $isGridType ? 'selected' : '' ?>>กริดรูปภาพ</option>
              </select>
              <?php if ($isEdit): ?>
              <input type="hidden" name="section_type" value="<?= $isGridType ? 'grid' : 'text' ?>">
              <p class="ne-hint">เปลี่ยนประเภทไม่ได้หลังบันทึกแล้ว (ลบแล้วสร้างใหม่แทนถ้าต้องการเปลี่ยน)</p>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="ne-panel">
          <p class="ne-panel-title"><i class="bi bi-file-text"></i>รายละเอียดหัวข้อ</p>
          <div class="ne-panel-body">
            <div class="ne-field">
              <label for="title">ชื่อหัวข้อ <span style="color:var(--ne-danger)">*</span></label>
              <input type="text" class="ne-input" id="title" name="title" placeholder="เช่น วิสัยทัศน์" value="<?= e($section['title'] ?? '') ?>">
            </div>

            <div id="gridSubtitleField" class="ne-field" style="margin-bottom:0;display:<?= $showGridFields ? '' : 'none' ?>;">
              <label for="subtitle">คำอธิบายย่อย (ถ้ามี)</label>
              <input type="text" class="ne-input" id="subtitle" name="subtitle" placeholder="เช่น สิ่งที่ทำให้การเรียนที่ BAIT แตกต่าง" value="<?= e($section['subtitle'] ?? '') ?>">
            </div>

            <div id="textContentField" class="ne-field" style="margin-bottom:0;display:<?= $showGridFields ? 'none' : '' ?>;">
              <label for="content">เนื้อหา <span style="color:var(--ne-danger)">*</span></label>
              <textarea id="content" name="content"><?= e($section['content'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <?php if ($isEdit && $isGridType): ?>
        <div class="ne-panel mt-3">
          <div class="ne-panel-head"><span>รูปภาพในกริด (<?= count($gridItems) ?>)</span></div>
          <div class="ne-panel-body">
            <div id="itemList" class="row g-2">
              <?php foreach ($gridItems as $item): ?>
              <div class="col-md-4" data-id="<?= $item['item_id'] ?>">
                <div class="highlight-item border rounded overflow-hidden position-relative" style="cursor:grab">
                  <div style="height:120px;display:flex;align-items:center;justify-content:center;background:#f8f9fc;padding:12px">
                    <?php if ($item['image']): ?>
                    <img src="<?= UPLOAD_URL . '/' . e($item['image']) ?>" style="max-width:100%;max-height:100%;object-fit:contain" alt="">
                    <?php else: ?>
                    <i class="bi bi-<?= e($item['icon']) ?>" style="font-size:2.4rem;color:var(--ne-blue)"></i>
                    <?php endif; ?>
                  </div>
                  <div class="position-absolute bottom-0 start-0 w-100 p-2" style="background:rgba(0,0,0,0.45)">
                    <p class="mb-0 text-white small fw-500"><?= e($item['title']) ?></p>
                  </div>
                  <div class="position-absolute top-0 end-0 p-1 ne-actions">
                    <button type="button" class="ne-icon-btn ne-icon-edit" style="background:#fff;" onclick="editItem(<?= $item['item_id'] ?>)" title="แก้ไข"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="ne-icon-btn <?= $item['is_active'] ? 'ne-icon-edit' : '' ?>" style="background:#fff;<?= !$item['is_active'] ? 'color:var(--ne-slate);' : '' ?>" onclick="toggleItem(<?= $item['item_id'] ?>)" title="<?= $item['is_active'] ? 'ซ่อน' : 'แสดง' ?>"><i class="bi bi-<?= $item['is_active'] ? 'eye' : 'eye-slash' ?>"></i></button>
                    <button type="button" class="ne-icon-btn ne-icon-delete" style="background:#fff;" onclick="deleteItem(<?= $item['item_id'] ?>)" title="ลบ"><i class="bi bi-trash"></i></button>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
              <?php if (empty($gridItems)): ?>
              <div class="col-12"><div class="ne-empty"><i class="bi bi-images"></i>ยังไม่มีรูปภาพในกริดนี้</div></div>
              <?php endif; ?>
            </div>
            <?php if (count($gridItems) > 1): ?>
            <div class="text-end mt-2">
              <button type="button" class="ne-btn ne-btn-ghost" onclick="saveItemOrder()"><i class="bi bi-save"></i>บันทึกลำดับ</button>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="ne-panel mt-3">
          <p class="ne-panel-title" id="itemFormTitle"><i class="bi bi-plus-lg"></i>เพิ่มรูปในกริด</p>
          <div class="ne-panel-body">
            <input type="hidden" id="itemId" value="0">
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="ne-label" for="itemTitle">ชื่อรายการ <span style="color:var(--ne-danger)">*</span></label>
                <input type="text" class="ne-input" id="itemTitle" placeholder="เช่น หลักสูตรทันสมัย">
              </div>
              <div class="col-md-4">
                <label class="ne-label" for="itemImage">รูปภาพ</label>
                <input type="file" class="form-control" id="itemImage" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml">
              </div>
              <div class="col-md-3">
                <label class="ne-label" for="itemIcon">ไอคอน (ถ้าไม่มีรูป)</label>
                <select class="ne-input" id="itemIcon">
                  <?php foreach ($itemIconOptions as $ic): ?>
                  <option value="<?= e($ic) ?>"><?= e($ic) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-1">
                <button type="button" class="ne-btn ne-btn-primary w-100" id="itemSaveBtn" onclick="saveItem()"><i class="bi bi-plus-lg"></i></button>
              </div>
            </div>
            <button type="button" class="ne-btn ne-btn-ghost mt-2 d-none" id="itemCancelBtn" onclick="cancelItemEdit()">ยกเลิกการแก้ไข</button>
          </div>
        </div>
        <?php elseif ($isEdit && $isLegacyHighlights): ?>
        <div class="ne-panel mt-3">
          <div class="ne-panel-head"><span>จุดเด่นทั้งหมด (<?= count($highlights) ?>)</span></div>
          <div class="ne-panel-body">
            <div id="highlightGrid" class="row g-2">
              <?php foreach ($highlights as $h): ?>
              <div class="col-md-4" data-id="<?= $h['highlight_id'] ?>">
                <div class="highlight-item border rounded overflow-hidden position-relative" style="cursor:grab">
                  <div style="height:120px;display:flex;align-items:center;justify-content:center;background:#f8f9fc;padding:12px">
                    <?php if ($h['media_type'] === 'image'): ?>
                    <img src="<?= UPLOAD_URL . '/' . e($h['media_path']) ?>" style="max-width:100%;max-height:100%;object-fit:contain" alt="">
                    <?php elseif ($h['media_type'] === 'video'): ?>
                    <video src="<?= UPLOAD_URL . '/' . e($h['media_path']) ?>" style="max-width:100%;max-height:100%;object-fit:contain" muted></video>
                    <?php else: ?>
                    <i class="bi bi-<?= e($h['icon']) ?>" style="font-size:2.4rem;color:var(--ne-blue)"></i>
                    <?php endif; ?>
                  </div>
                  <div class="position-absolute bottom-0 start-0 w-100 p-2" style="background:rgba(0,0,0,0.45)">
                    <p class="mb-0 text-white small fw-500"><?= e($h['title']) ?></p>
                    <small class="text-white-50"><?= $h['media_type'] === 'video' ? 'วิดีโอ' : ($h['media_type'] === 'image' ? 'รูปภาพ' : 'ไอคอน (ยังไม่มีสื่อ)') ?></small>
                  </div>
                  <div class="position-absolute top-0 end-0 p-1 ne-actions">
                    <button type="button" class="ne-icon-btn ne-icon-edit" style="background:#fff;" onclick="editHighlight(<?= $h['highlight_id'] ?>)" title="แก้ไข">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="ne-icon-btn <?= $h['is_active'] ? 'ne-icon-edit' : '' ?>" style="background:#fff;<?= !$h['is_active'] ? 'color:var(--ne-slate);' : '' ?>" onclick="toggleHighlight(<?= $h['highlight_id'] ?>)" title="<?= $h['is_active'] ? 'ซ่อน' : 'แสดง' ?>">
                      <i class="bi bi-<?= $h['is_active'] ? 'eye' : 'eye-slash' ?>"></i>
                    </button>
                    <button type="button" class="ne-icon-btn ne-icon-delete" style="background:#fff;" onclick="deleteHighlight(<?= $h['highlight_id'] ?>)" title="ลบ">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                  <div class="position-absolute top-0 start-0 m-1">
                    <span class="ne-badge-count" style="background:rgba(0,0,0,.6);color:#fff;">#<?= $h['display_order'] ?></span>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
              <?php if (empty($highlights)): ?>
              <div class="col-12"><div class="ne-empty"><i class="bi bi-stars"></i>ยังไม่มีจุดเด่น</div></div>
              <?php endif; ?>
            </div>
          </div>
          <div style="padding:12px 20px;border-top:1px solid var(--ne-line);text-align:right;">
            <button type="button" class="ne-btn ne-btn-primary" onclick="saveHighlightOrder()"><i class="bi bi-save"></i>บันทึกลำดับ</button>
          </div>
        </div>

        <!-- Floating "add" button — opens the จุดเด่นของคณะ add/edit form in a
             modal instead of a permanently-visible side-column form, so the
             page stays short even with a long grid of items above. -->
        <button type="button" class="ne-btn ne-btn-primary" style="position:fixed;bottom:28px;right:28px;width:56px;height:56px;border-radius:50%;padding:0;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 6px 20px rgba(30,42,120,.35);z-index:1045;" onclick="openHighlightModal()" title="เพิ่มจุดเด่นใหม่">
          <i class="bi bi-plus-lg"></i>
        </button>
        <?php elseif (!$isEdit): ?>
        <div class="ne-alert" id="gridSavefirstHint" style="display:<?= $isGridType ? '' : 'none' ?>;">
          <i class="bi bi-info-circle"></i> บันทึกหัวข้อนี้ก่อน แล้วจะเพิ่มรูปภาพลงในกริดได้ในหน้าถัดไป
        </div>
        <?php endif; ?>
      </div>

      <!-- Side column -->
      <div class="ne-side">
        <div id="textSideFields" style="display:<?= $showGridFields ? 'none' : '' ?>;">
          <div class="ne-panel mt-3">
            <p class="ne-panel-title"><i class="bi bi-palette"></i>ไอคอนและสี</p>
            <div class="ne-panel-body">
              <div class="d-flex align-items-center gap-2 mb-2">
                <span id="iconPreview" class="bi" style="font-size:1.8rem;width:36px;text-align:center;"></span>
                <input type="color" id="colorInput" name="color" value="<?= e($section['color'] ?? '#0F439B') ?>" onchange="updateIconPreview()" style="width:44px;height:36px;padding:2px;border:1px solid var(--ne-line);border-radius:6px;">
              </div>
              <input type="hidden" id="iconInput" name="icon" value="<?= e($section['icon'] ?? 'target') ?>">
              <div id="iconPicker" style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($sectionIconOptions as $slug => $biClass): ?>
                <button type="button" class="ne-icon-btn" data-icon="<?= e($slug) ?>" onclick="pickIcon('<?= e($slug) ?>')" title="<?= e($slug) ?>" style="width:40px;height:40px;font-size:16px;"><i class="bi <?= e($biClass) ?>"></i></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <?php if ($hasDisplayOptions): ?>
          <div class="ne-panel mt-3">
            <p class="ne-panel-title"><i class="bi bi-sliders"></i>รูปแบบการแสดงผล</p>
            <div class="ne-panel-body">
              <label class="ne-label" for="dsStyle">แสดงเป็น</label>
                <select class="ne-input" name="ds_style" id="dsStyle">
                  <?php foreach ($optChoices['style'] as $v => $lab): ?>
                  <option value="<?= e($v) ?>" <?= (string)$secOpt['style'] === (string)$v ? 'selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              <p class="ne-hint">"สไลด์การ์ด" ใช้กับเนื้อหาที่เป็นรายการ (ข้อ 1, 2, 3 ...) อย่างน้อย 2 ข้อ แต่ละข้อจะขึ้นเป็นหนึ่งแผ่น กดลูกศรเพื่อเปลี่ยน ถ้าเนื้อหาไม่ใช่รายการ จะแสดงเป็นการ์ดปกติ<br>"กล่องประโยคเด่น" ใช้กับข้อความสั้นๆ เช่น วิสัยทัศน์ จะแสดงเป็นกล่องสีเต็มความกว้างพร้อมประโยคตัวใหญ่ ใช้สี ไอคอน และชื่อหัวข้อที่ตั้งไว้ (ถ้าข้อความยาว ระบบจะลดขนาดตัวอักษรให้อัตโนมัติ)</p>
              <div id="dsSliderOpts" style="display:<?= $secOpt['style'] === 'slider' ? '' : 'none' ?>;">
                <label class="ne-label mt-2" for="dsBadge">ข้อความในวงกลม</label>
                <select class="ne-input" name="ds_badge" id="dsBadge">
                  <?php foreach ($optChoices['badge'] as $v => $lab): ?>
                  <option value="<?= e($v) ?>" <?= (string)$secOpt['badge'] === (string)$v ? 'selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
                <label class="ne-label mt-2" for="dsEffect">เอฟเฟกต์เปลี่ยนแผ่น</label>
                <select class="ne-input" name="ds_effect" id="dsEffect">
                  <?php foreach ($optChoices['effect'] as $v => $lab): ?>
                  <option value="<?= e($v) ?>" <?= (string)$secOpt['effect'] === (string)$v ? 'selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
                <label class="ne-label mt-2" for="dsShape">รูปทรงการ์ด</label>
                <select class="ne-input" name="ds_shape" id="dsShape">
                  <?php foreach ($optChoices['shape'] as $v => $lab): ?>
                  <option value="<?= e($v) ?>" <?= (string)$secOpt['shape'] === (string)$v ? 'selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
                <label class="ne-label mt-2" for="dsAuto">เลื่อนอัตโนมัติ</label>
                <select class="ne-input" name="ds_autoplay" id="dsAuto">
                  <?php foreach ($optChoices['autoplay'] as $v => $lab): ?>
                  <option value="<?= e($v) ?>" <?= (string)$secOpt['autoplay'] === (string)$v ? 'selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="ne-hint">เลื่อนเองจะหยุดเมื่อเอาเมาส์ชี้หรือผู้ชมกดปุ่มเอง และไม่ทำงานกับผู้ที่ตั้งค่าลดการเคลื่อนไหวในเครื่อง</p>
                <label class="d-flex align-items-center gap-2 mt-2" style="cursor:pointer;"><input type="checkbox" name="ds_counter" value="1" <?= $secOpt['counter'] ? 'checked' : '' ?>> แสดงตัวนับ (เช่น 1 / 5)</label>
                <label class="d-flex align-items-center gap-2 mt-2" style="cursor:pointer;"><input type="checkbox" name="ds_mark" value="1" <?= $secOpt['mark'] ? 'checked' : '' ?>> แสดงลายไอคอนจางๆ เป็นพื้นหลัง</label>
                <p class="ne-hint mt-2">สีวงกลมและไอคอนตั้งได้ที่กล่อง "ไอคอนและสี" ด้านบน</p>
              </div>
            </div>
          </div>
          <?php else: ?>
          <div class="ne-alert mt-3"><i class="bi bi-info-circle"></i> ตัวเลือก "รูปแบบการแสดงผล" ยังไม่พร้อมใช้งาน ต้องอัปเดตฐานข้อมูลก่อน กรุณาแจ้งผู้ดูแลระบบ</div>
          <?php endif; ?>

        </div>

        <div class="ne-panel mt-3">
          <p class="ne-panel-title"><i class="bi bi-send"></i>บันทึกข้อมูล</p>
          <div class="ne-panel-body">
            <div class="ne-panel-actions">
              <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-check-lg"></i><?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มหัวข้อ' ?></button>
              <a href="index.php?tab=sections" class="ne-btn ne-btn-ghost">ยกเลิก</a>
            </div>
          </div>
        </div>
      </div>

    </div>
  </form>

  <?php if ($isEdit && $isLegacyHighlights): ?>
  <!-- Add/edit จุดเด่นของคณะ — opened by the floating "+" button above -->
  <div class="modal fade" id="highlightModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content ne-modal-content ne">
        <div class="modal-header ne-modal-header">
          <h5 class="modal-title" id="highlightModalTitle" style="font-family:'Kanit',serif;">เพิ่มจุดเด่นใหม่</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ne-modal-body">
          <input type="hidden" id="highlightId" value="0">
          <div class="ne-field">
            <label for="highlightTitle">ชื่อจุดเด่น <span style="color:var(--ne-danger)">*</span></label>
            <input type="text" class="ne-input" id="highlightTitle" placeholder="เช่น หลักสูตรทันสมัย">
          </div>
          <div class="ne-field">
            <label for="highlightDesc">คำอธิบาย</label>
            <textarea class="ne-input" id="highlightDesc" rows="3" placeholder="รายละเอียดสั้นๆ"></textarea>
          </div>
          <div class="ne-field">
            <label>ไอคอน (แสดงตอนยังไม่มีรูป/วิดีโอ)</label>
            <input type="hidden" id="highlightIcon" value="lightbulb">
            <div id="highlightIconPicker" style="display:flex;flex-wrap:wrap;gap:8px;">
              <?php foreach ($highlightIconOptions as $slug => $biClass): ?>
              <button type="button" class="ne-icon-btn" data-icon="<?= e($slug) ?>" onclick="pickHighlightIcon('<?= e($slug) ?>')" title="<?= e($slug) ?>" style="width:40px;height:40px;font-size:16px;"><i class="bi <?= e($biClass) ?>"></i></button>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="ne-field" style="margin-bottom:0;">
            <label for="highlightMedia">รูปภาพหรือวิดีโอสั้นๆ</label>
            <input type="file" data-has-preview class="form-control" id="highlightMedia" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml,video/mp4,video/webm" onchange="previewMedia(this)">
            <div id="mediaPreviewWrap" class="d-none mt-2">
              <img id="mediaPreviewImg" class="img-fluid rounded d-none" style="max-height:140px;object-fit:contain;background:#f8f9fc;padding:8px" alt="">
              <video id="mediaPreviewVideo" class="d-none" style="max-height:140px;width:100%;background:#f8f9fc" controls></video>
            </div>
            <p class="ne-hint">รองรับ JPG, PNG, GIF, WEBP, SVG, MP4, WEBM ขนาดไม่เกิน 20MB — ถ้าไม่อัปโหลด จะแสดงไอคอนแทน</p>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
          <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="button" class="ne-btn ne-btn-primary" id="highlightSaveBtn" onclick="saveHighlight()"><i class="bi bi-check-lg"></i>เพิ่มจุดเด่น</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
$extraJs = '<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js" integrity="sha384-69SUO5s28dXCoNTUaA/KXhfDJu21xD394Gxk/S6d/YJZUxrz7Zagi+seruzXFTed" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
let contentEditor;
if (document.getElementById("content")) {
  ClassicEditor.create(document.querySelector("#content"), {language:"th"}).catch(console.error);
}

const csrf = ' . json_encode(csrfToken()) . ';
const iconMap = ' . json_encode($sectionIconOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';

function updateTypeUI() {
  const isGrid = document.getElementById("sectionType").value === "grid";
  document.getElementById("gridSubtitleField").style.display = isGrid ? "" : "none";
  document.getElementById("textContentField").style.display = isGrid ? "none" : "";
  document.getElementById("textSideFields").style.display = isGrid ? "none" : "";
  const hint = document.getElementById("gridSavefirstHint");
  if (hint) hint.style.display = isGrid ? "" : "none";
}

document.getElementById("dsStyle")?.addEventListener("change", function () {
  document.getElementById("dsSliderOpts").style.display = this.value === "slider" ? "" : "none";
});

function pickIcon(slug) {
  document.getElementById("iconInput").value = slug;
  updateIconPreview();
  document.querySelectorAll("#iconPicker [data-icon]").forEach(b => b.classList.toggle("ne-icon-edit", b.dataset.icon === slug));
}

function updateIconPreview() {
  const slug = document.getElementById("iconInput").value || "target";
  const color = document.getElementById("colorInput").value || "#0F439B";
  const preview = document.getElementById("iconPreview");
  preview.className = "bi " + (iconMap[slug] || "bi-bullseye");
  preview.style.color = color;
}

if (document.getElementById("iconInput")) pickIcon(document.getElementById("iconInput").value);
if (document.getElementById("sectionType")) updateTypeUI();

// ===================== GRID ITEMS =====================
const items = ' . json_encode(array_map(fn($i) => [
    'id' => (int)$i['item_id'], 'title' => $i['title'], 'icon' => $i['icon'],
], $gridItems), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';

if (document.getElementById("itemList")) {
  new Sortable(document.getElementById("itemList"), { animation: 150, ghostClass: "opacity-50" });
}

function saveItemOrder() {
  const ids = [...document.querySelectorAll("#itemList [data-id]")].map(el => el.dataset.id);
  const fd = new FormData();
  fd.append("action","item_reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function toggleItem(id) {
  const fd = new FormData();
  fd.append("action","item_toggle"); fd.append("item_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1000); }
}

async function deleteItem(id) {
  const r = await Swal.fire({title:"ลบรูปนี้?",icon:"warning",showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","item_delete"); fd.append("item_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1000); }
}

function editItem(id) {
  const item = items.find(x => x.id === id);
  if (!item) return;
  document.getElementById("itemId").value = item.id;
  document.getElementById("itemTitle").value = item.title;
  document.getElementById("itemIcon").value = item.icon;
  document.getElementById("itemImage").value = "";
  document.getElementById("itemFormTitle").innerHTML = "<i class=\"bi bi-pencil-square\"></i>แก้ไขรูปในกริด";
  document.getElementById("itemSaveBtn").innerHTML = "<i class=\"bi bi-save\"></i>";
  document.getElementById("itemCancelBtn").classList.remove("d-none");
}

function cancelItemEdit() {
  document.getElementById("itemId").value = "0";
  document.getElementById("itemTitle").value = "";
  document.getElementById("itemImage").value = "";
  document.getElementById("itemFormTitle").innerHTML = "<i class=\"bi bi-plus-lg\"></i>เพิ่มรูปในกริด";
  document.getElementById("itemSaveBtn").innerHTML = "<i class=\"bi bi-plus-lg\"></i>";
  document.getElementById("itemCancelBtn").classList.add("d-none");
}

async function saveItem() {
  const id = document.getElementById("itemId").value;
  const title = document.getElementById("itemTitle").value.trim();
  if (!title) { showError("กรุณากรอกชื่อรายการ"); return; }
  const fd = new FormData();
  fd.append("action","item_save");
  fd.append("item_id",id);
  fd.append("title",title);
  fd.append("icon",document.getElementById("itemIcon").value);
  const file = document.getElementById("itemImage").files[0];
  if (file) fd.append("image",file);
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1000); }
  else showError(r.data.message);
}

// ===================== HIGHLIGHTS (จุดเด่นของคณะ, merged in from index.php) =====================
const highlights = ' . json_encode(array_map(fn($h) => [
    'id'          => (int)$h['highlight_id'],
    'title'       => $h['title'],
    'description' => $h['description'],
    'icon'        => $h['icon'],
], $highlights), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';

if (document.getElementById("highlightGrid")) {
  new Sortable(document.getElementById("highlightGrid"), { animation: 150, ghostClass: "opacity-50" });
}

function saveHighlightOrder() {
  const ids = [...document.querySelectorAll("#highlightGrid [data-id]")].map(el => el.dataset.id);
  const fd  = new FormData();
  fd.append("action","highlight_reorder"); fd.append("ids",JSON.stringify(ids)); fd.append("csrf_token",csrf);
  axios.post("",fd).then(r => { if (r.data.status==="success") showSuccess("บันทึกลำดับแล้ว"); else orderNotSaved(r.data.message); });
}

async function toggleHighlight(id) {
  const fd = new FormData();
  fd.append("action","highlight_toggle"); fd.append("highlight_id",id); fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { showSuccess(r.data.message); setTimeout(()=>location.reload(),1200); }
}

async function deleteHighlight(id) {
  const r = await Swal.fire({title:"ลบจุดเด่นนี้?",icon:"warning",showCancelButton:true,
    confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","highlight_delete"); fd.append("highlight_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success") { showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
}

let computedCrop = null;

function detectCropScale(file) {
  return new Promise((resolve) => {
    if (!file.type.startsWith("image/")) { resolve(null); return; }
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      try {
        const w = img.naturalWidth, h = img.naturalHeight;
        const canvas = document.createElement("canvas");
        canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(img, 0, 0);
        const alphaAt = (x, y) => ctx.getImageData(x, y, 1, 1).data[3];
        const midY = Math.floor(h / 2), midX = Math.floor(w / 2);
        let leftX = null, rightX = null, topY = null, bottomY = null;
        for (let x = 0; x < w; x += 2) { if (alphaAt(x, midY) > 200) { leftX = x; break; } }
        for (let x = w - 1; x >= 0; x -= 2) { if (alphaAt(x, midY) > 200) { rightX = x; break; } }
        for (let y = 0; y < h; y += 2) { if (alphaAt(midX, y) > 200) { topY = y; break; } }
        for (let y = h - 1; y >= 0; y -= 2) { if (alphaAt(midX, y) > 200) { bottomY = y; break; } }
        URL.revokeObjectURL(url);
        if (leftX === null || rightX === null || topY === null || bottomY === null) { resolve(null); return; }
        const leftPct = leftX / w * 100, rightPct = (w - 1 - rightX) / w * 100;
        const topPct = topY / h * 100, bottomPct = (h - 1 - bottomY) / h * 100;
        if (leftPct < 0.5 && rightPct < 0.5 && topPct < 0.5 && bottomPct < 0.5) { resolve({ x: 1, y: 1 }); return; }
        const boxRatio = 4 / 3, imgRatio = w / h;
        const coverW = imgRatio >= boxRatio ? boxRatio / imgRatio : 1.0;
        const coverH = imgRatio >= boxRatio ? 1.0 : imgRatio / boxRatio;
        const buffer = 1.5;
        const needW = 1 - ((leftPct + rightPct + buffer * 2) / 100);
        const needH = 1 - ((topPct + bottomPct + buffer * 2) / 100);
        let sx = needW > 0 ? Math.max(1, coverW / needW) : 1;
        let sy = needH > 0 ? Math.max(1, coverH / needH) : 1;
        sx = Math.min(1.6, sx); sy = Math.min(1.6, sy);
        resolve({ x: +sx.toFixed(3), y: +sy.toFixed(3) });
      } catch (e) { resolve(null); }
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

async function previewMedia(input) {
  const wrap = document.getElementById("mediaPreviewWrap");
  const img  = document.getElementById("mediaPreviewImg");
  const vid  = document.getElementById("mediaPreviewVideo");
  img.classList.add("d-none"); vid.classList.add("d-none");
  const file = input.files[0];
  computedCrop = null;
  if (!file) { wrap.classList.add("d-none"); return; }
  wrap.classList.remove("d-none");
  const url = URL.createObjectURL(file);
  if (file.type.startsWith("video/")) { vid.src = url; vid.classList.remove("d-none"); }
  else { img.src = url; img.classList.remove("d-none"); computedCrop = await detectCropScale(file); }
}

let highlightModal;

function pickHighlightIcon(slug) {
  document.getElementById("highlightIcon").value = slug;
  document.querySelectorAll("#highlightIconPicker [data-icon]").forEach(b => b.classList.toggle("ne-icon-edit", b.dataset.icon === slug));
}

function openHighlightModal(id) {
  const h = id ? highlights.find(x => x.id === id) : null;
  document.getElementById("highlightId").value = h ? h.id : 0;
  document.getElementById("highlightTitle").value = h ? h.title : "";
  document.getElementById("highlightDesc").value = h ? (h.description || "") : "";
  pickHighlightIcon(h ? h.icon : "lightbulb");
  document.getElementById("highlightMedia").value = "";
  document.getElementById("mediaPreviewWrap").classList.add("d-none");
  computedCrop = null;
  document.getElementById("highlightModalTitle").textContent = h ? "แก้ไขจุดเด่น" : "เพิ่มจุดเด่นใหม่";
  document.getElementById("highlightSaveBtn").innerHTML = h ? "<i class=\"bi bi-check-lg\"></i>บันทึกการแก้ไข" : "<i class=\"bi bi-check-lg\"></i>เพิ่มจุดเด่น";
  highlightModal = new bootstrap.Modal(document.getElementById("highlightModal"));
  highlightModal.show();
}

function editHighlight(id) {
  openHighlightModal(id);
}

async function saveHighlight() {
  const id    = document.getElementById("highlightId").value;
  const title = document.getElementById("highlightTitle").value.trim();
  const file  = document.getElementById("highlightMedia").files[0];
  if (!title) { showError("กรุณากรอกชื่อจุดเด่น"); return; }
  const fd = new FormData();
  fd.append("action","highlight_save");
  fd.append("highlight_id",id);
  fd.append("title",title);
  fd.append("description",document.getElementById("highlightDesc").value);
  fd.append("icon",document.getElementById("highlightIcon").value);
  if (file) {
    fd.append("media",file);
    if (computedCrop) { fd.append("crop_scale_x",computedCrop.x); fd.append("crop_scale_y",computedCrop.y); }
  }
  fd.append("csrf_token",csrf);
  const r = await axios.post("",fd);
  if (r.data.status==="success") { highlightModal.hide(); showSuccess(r.data.message); setTimeout(()=>location.reload(),1500); }
  else showError(r.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>

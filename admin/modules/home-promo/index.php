<?php
/**
 * BAIT Admin - วิดีโอโปรโมทหน้าแรก (Home page promo video)
 * One clip, two columns, shown on index.php just above "ข่าวเด่น / ข่าวล่าสุด": the video on the left or the right, the other
 * side holds a heading + description the admin can style (font sizes, alignment, colours, an optional button).
 * The clip is either a link (YouTube / Vimeo / Facebook / a direct .mp4 - no length limit, the host streams it) or an uploaded
 * .mp4 / .webm file. Single record in `home_promo` (run SQL home_promo.sql once).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
$auth = new Auth(); $auth->requireLogin(); $db = Database::getInstance();

$tableReady = (bool)$db->fetchOne("SHOW TABLES LIKE 'home_promo'");
$row = $tableReady ? $db->fetchOne("SELECT * FROM home_promo ORDER BY promo_id LIMIT 1") : null;
$hasLogoCols = $tableReady && (bool)$db->fetchOne("SHOW COLUMNS FROM home_promo LIKE 'logo_mode'");   // false until home_promo_logo.sql has been run
// 100 MB at most, and never more than PHP will actually accept on this server (free hosts often allow far less):
// the form used to promise 100 MB and then fail with an unexplained error.
$iniMb = function (string $k): float {
    $v = trim((string)ini_get($k)); $n = (float)$v; $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n * 1024 : ($u === 'm' ? $n : ($u === 'k' ? $n / 1024 : $n / 1048576));
};
define('PROMO_MAX_VIDEO_MB', max(1, (int)floor(min(100, $iniMb('upload_max_filesize') ?: 100, $iniMb('post_max_size') ?: 100))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); $action = $_POST['action'] ?? '';
    if (!$tableReady) jsonError('ยังไม่ได้สร้างตาราง home_promo กรุณารัน SQL ไฟล์ home_promo.sql ใน phpMyAdmin ก่อน');

    if ($action === 'delete') {
        if ($row) {
            $db->delete('home_promo', 'promo_id = ?', [(int)$row['promo_id']]);
            foreach (['video_file', 'poster', 'logo'] as $f) fileQueueDelete($row[$f] ?? null);   // the files go only once the row is really gone
            fileCommitChanges();
        }
        jsonSuccess([], 'ลบวิดีโอโปรโมทแล้ว');
    }

    if ($action === 'save') {
        $source  = ($_POST['video_source'] ?? 'url') === 'upload' ? 'upload' : 'url';
        $url     = postStr('video_url');
        $heading = postStr('heading');
        $body    = trim(str_replace("\r\n", "\n", postRaw('body')));
        $btnLabel = postStr('btn_label'); $btnUrl = postStr('btn_url');
        if (mb_strlen($heading, 'UTF-8') > 200) jsonError('หัวข้อยาวเกินไป (ไม่เกิน 200 ตัวอักษร)');
        if (mb_strlen($body, 'UTF-8') > 4000) jsonError('คำบรรยายยาวเกินไป (ไม่เกิน 4,000 ตัวอักษร)');
        if (mb_strlen($btnLabel, 'UTF-8') > 60) jsonError('ข้อความบนปุ่มยาวเกินไป (ไม่เกิน 60 ตัวอักษร)');
        if ($btnUrl !== '' && !isHttpUrl($btnUrl)) jsonError('ลิงก์ของปุ่มไม่ถูกต้อง กรุณาใส่ URL ที่ขึ้นต้นด้วย http:// หรือ https://');
        if ($btnUrl !== '' && $btnLabel === '') jsonError('กรุณาใส่ข้อความบนปุ่ม');

        $data = [
            'is_active'    => empty($_POST['is_active']) ? 0 : 1,
            'video_source' => $source,
            'autoplay'     => empty($_POST['autoplay']) ? 0 : 1,
            'video_side'   => ($_POST['video_side'] ?? 'left') === 'right' ? 'right' : 'left',
            'heading'      => $heading !== '' ? $heading : null,
            'body'         => $body !== '' ? $body : null,
            'heading_size' => max(18, min(64, (int)($_POST['heading_size'] ?? 32))),
            'body_size'    => max(13, min(34, (int)($_POST['body_size'] ?? 18))),
            'text_align'   => in_array($_POST['text_align'] ?? '', ['left', 'center', 'right'], true) ? $_POST['text_align'] : 'left',
            'v_align'      => in_array($_POST['v_align'] ?? '', ['start', 'center', 'end'], true) ? $_POST['v_align'] : 'center',
            'text_color'   => (!empty($_POST['text_color_on']) && preg_match('/^#[0-9A-Fa-f]{6}$/', postRaw('text_color'))) ? strtoupper(postRaw('text_color')) : null,
            'bg_color'     => (!empty($_POST['bg_color_on'])   && preg_match('/^#[0-9A-Fa-f]{6}$/', postRaw('bg_color')))   ? strtoupper(postRaw('bg_color'))   : null,
            'btn_label'    => $btnLabel !== '' ? $btnLabel : null,
            'btn_url'      => $btnUrl !== '' ? $btnUrl : null,
        ];

        $newFiles = []; $oldFiles = [];
        if ($source === 'url') {
            if ($url === '') jsonError('กรุณาวางลิงก์วิดีโอ');
            if (!promoEmbed($url)) jsonError('ลิงก์วิดีโอไม่รองรับ — ใช้ลิงก์ YouTube, Vimeo, Facebook หรือไฟล์ .mp4 / .webm ที่ขึ้นต้นด้วย https://');
            $data['video_url'] = $url;
            if ($row && !empty($row['video_file'])) { $oldFiles[] = $row['video_file']; $data['video_file'] = null; }   // the old upload is no longer used
        } else {
            if (!empty($_FILES['video_file']['name'])) {
                $up = uploadMedia($_FILES['video_file'], 'promo', PROMO_MAX_VIDEO_MB);
                if ($up) fileTrackNew($up['path']);   // first: an image refused below must not stay behind in uploads/
                if (!$up || $up['type'] !== 'video') jsonError('อัปโหลดวิดีโอไม่สำเร็จ (รองรับ .mp4 / .webm ขนาดไม่เกิน ' . PROMO_MAX_VIDEO_MB . 'MB — คลิปยาวหรือไฟล์ใหญ่ให้อัปโหลดขึ้น YouTube แล้ววางลิงก์แทน)');
                $newFiles[] = $up['path']; fileTrackNew($up['path']); $data['video_file'] = $up['path'];
                if ($row && !empty($row['video_file'])) $oldFiles[] = $row['video_file'];
            } elseif (!$row || empty($row['video_file'])) {
                jsonError('กรุณาเลือกไฟล์วิดีโอ');
            }
            $data['video_url'] = null;
        }
        if (!empty($_POST['poster_remove']) && $row && !empty($row['poster'])) { $oldFiles[] = $row['poster']; $data['poster'] = null; }
        if (!empty($_FILES['poster']['name'])) {
            $pp = uploadImage($_FILES['poster'], 'promo', 5);
            if (!$pp) { foreach ($newFiles as $f) @unlink(UPLOAD_PATH . '/' . $f); jsonError('อัปโหลดภาพปกไม่สำเร็จ (JPG, PNG, WEBP ไม่เกิน 5MB)'); }
            $newFiles[] = $pp; fileTrackNew($pp); $data['poster'] = $pp;
            if ($row && !empty($row['poster'])) $oldFiles[] = $row['poster'];
        }

        if ($hasLogoCols) {
            $mode = postStr('logo_mode', 'none');
            if (!in_array($mode, ['none', 'site', 'upload'], true)) $mode = 'none';   // (a missing field used to come out as NULL and fail the save)
            $data['logo_mode'] = $mode;
            $data['logo_size'] = max(60, min(320, (int)($_POST['logo_size'] ?? 150)));
            if (!empty($_FILES['logo']['name'])) {
                $lp = uploadImage($_FILES['logo'], 'promo', 5);
                if (!$lp) { foreach ($newFiles as $f) @unlink(UPLOAD_PATH . '/' . $f); jsonError('อัปโหลดโลโก้ไม่สำเร็จ (JPG, PNG, WEBP, SVG ไม่เกิน 5MB)'); }
                $newFiles[] = $lp; fileTrackNew($lp); $data['logo'] = $lp;
                if ($row && !empty($row['logo'])) $oldFiles[] = $row['logo'];
            }
            if ($mode === 'upload' && empty($data['logo']) && (!$row || empty($row['logo']))) { foreach ($newFiles as $f) @unlink(UPLOAD_PATH . '/' . $f); jsonError('กรุณาเลือกไฟล์โลโก้ (หรือเปลี่ยนเป็น "ไม่แสดง" / "ใช้โลโก้ของเว็บ")'); }
            if ($mode !== 'upload' && $row && !empty($row['logo']) && empty($data['logo'])) { $oldFiles[] = $row['logo']; $data['logo'] = null; }   // not used any more: free the file
        }

        try {
            if ($row) $db->update('home_promo', $data, 'promo_id = ?', [(int)$row['promo_id']]);
            else      $db->insert('home_promo', $data);
        } catch (Throwable $e) {
            foreach ($newFiles as $f) @unlink(UPLOAD_PATH . '/' . $f);
            error_log('[home-promo] save failed: ' . $e->getMessage());
            jsonError('บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }
        fileCommitChanges();   // saved: the new files stay (a request stopped before this line drops them)
        foreach (array_unique($oldFiles) as $f) if ($f && is_file(UPLOAD_PATH . '/' . $f)) @unlink(UPLOAD_PATH . '/' . $f);
        jsonSuccess([], 'บันทึกวิดีโอโปรโมทแล้ว');
    }
    jsonError('คำสั่งไม่ถูกต้อง');
}

$o = promoOptions($row);
$pageTitle = 'วิดีโอโปรโมทหน้าแรก'; $activeMenu = 'home-promo';
$breadcrumb = [['label' => 'วิดีโอโปรโมทหน้าแรก']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
$choice = function (string $name, array $opts, string $cur) {
    foreach ($opts as $v => $label) {
        echo '<label class="promo-choice"><input type="radio" name="' . $name . '" value="' . e((string)$v) . '"' . ($cur === (string)$v ? ' checked' : '') . '><span>' . $label . '</span></label>';
    }
};
?>
<style>
.promo-choices { display: flex; flex-wrap: wrap; gap: 8px; }
.promo-choice { cursor: pointer; margin: 0 !important; text-transform: none !important; letter-spacing: 0 !important; }
.promo-choice input { position: absolute; opacity: 0; pointer-events: none; }
.promo-choice span { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border: 1.5px solid var(--ne-line, #e3e6ee); border-radius: 9px; background: #fff; font-weight: 500; min-height: 42px; }
.promo-choice input:checked + span { border-color: var(--ne-blue, #162E93); background: var(--ne-blue-soft, #E8EEFB); color: var(--ne-blue-deep, #162E93); font-weight: 700; }
.promo-choice input:focus-visible + span { outline: 2px solid var(--ne-blue, #162E93); outline-offset: 2px; }
.promo-range { display: flex; align-items: center; gap: 12px; }
.promo-range input[type=range] { flex: 1; min-width: 0; }
.promo-range output { min-width: 56px; text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.promo-colorrow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.promo-colorrow input[type=color] { width: 52px; height: 42px; padding: 2px; border: 1.5px solid var(--ne-line, #e3e6ee); border-radius: 9px; background: #fff; }
.promo-colorrow label { margin: 0; font-weight: 400; display: inline-flex; align-items: center; gap: 6px; text-transform: none; letter-spacing: 0; }
.promo-cols { display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 900px) { .promo-cols { grid-template-columns: 1fr 1fr; } }
.promo-preview-grid { display: grid; grid-template-columns: 1fr; gap: 14px; border-radius: 12px; padding: 14px; background: var(--ne-paper, #f5f6fa); }
@media (min-width: 700px) { .promo-preview-grid { grid-template-columns: 1fr 1fr; } }
.promo-preview-video { aspect-ratio: 16/9; border-radius: 10px; background: #0b0f1d; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; font-size: .85rem; }
.promo-preview-video i { font-size: 2.2rem; }
.promo-preview-text { display: flex; flex-direction: column; border-radius: 10px; padding: 10px; min-width: 0; overflow-wrap: anywhere; }
.promo-preview-text h3 { margin: 0 0 .4em; line-height: 1.25; font-weight: 700; }
.promo-preview-text p { margin: 0 0 .6em; line-height: 1.6; white-space: pre-line; }
.promo-preview-btn { display: inline-block; padding: 8px 18px; border-radius: 9px; background: var(--ne-blue, #162E93); color: #fff; font-weight: 600; }
</style>

<div class="ne">
  <div class="ne-topbar">
    <div>
      <h1>วิดีโอโปรโมทหน้าแรก</h1>
      <p class="ne-hint" style="margin:2px 0 0">แสดงเหนือหัวข้อ "ข่าวเด่น / ข่าวล่าสุด" • 1 คลิป 2 คอลัมน์ (วิดีโอ + คำบรรยาย)</p>
    </div>
    <?php if ($row): ?><button type="button" class="ne-btn ne-btn-ghost" style="margin-left:auto;color:var(--ne-danger,#c1443d)" onclick="deletePromo()"><i class="bi bi-trash"></i>ลบวิดีโอนี้</button><?php endif; ?>
  </div>

  <?php if (!$tableReady): ?>
  <div class="ne-panel"><div class="ne-panel-body"><div class="ne-empty"><i class="bi bi-database-exclamation"></i>ยังไม่ได้สร้างตาราง <b>home_promo</b> — รันไฟล์ <b>home_promo.sql</b> ใน phpMyAdmin หนึ่งครั้ง แล้วรีเฟรชหน้านี้</div></div></div>
  <?php else: ?>
  <form id="promoForm" enctype="multipart/form-data" onsubmit="return false">
    <input type="hidden" name="action" value="save"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="promo-cols">
      <div class="ne-panel">
        <div class="ne-panel-head"><span><i class="bi bi-play-btn" style="color:var(--ne-blue);margin-right:6px;"></i>วิดีโอ</span></div>
        <div class="ne-panel-body">
          <div class="ne-field"><label>การแสดงผล</label>
            <label class="form-check form-switch" style="margin:0;display:flex;gap:10px;align-items:center;">
              <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $o['is_active'] ? 'checked' : '' ?>><span style="font-weight:500">แสดงบนหน้าแรก</span>
            </label>
          </div>
          <div class="ne-field"><label>ที่มาของวิดีโอ</label>
            <div class="promo-choices" id="srcChoices"><?php $choice('video_source', ['url' => '<i class="bi bi-link-45deg"></i> ลิงก์ (YouTube / Vimeo / Facebook / .mp4)', 'upload' => '<i class="bi bi-upload"></i> อัปโหลดไฟล์'], $o['video_source']); ?></div>
          </div>
          <div class="ne-field" id="srcUrl"><label for="videoUrl">ลิงก์วิดีโอ</label>
            <input type="url" class="ne-input" id="videoUrl" name="video_url" value="<?= e($o['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=...">
            <p class="ne-hint" style="margin:6px 0 0">ไม่จำกัดความยาวคลิป — วิดีโอยาวแนะนำให้อัปโหลดขึ้น YouTube แล้ววางลิงก์ที่นี่</p>
          </div>
          <div class="ne-field" id="srcUpload"><label for="videoFile">ไฟล์วิดีโอ (.mp4 / .webm ไม่เกิน <?= PROMO_MAX_VIDEO_MB ?>MB)</label>
            <input type="file" class="form-control" id="videoFile" name="video_file" accept="video/mp4,video/webm">
            <?php if ($o['video_file']): ?><p class="ne-hint" style="margin:6px 0 0">ไฟล์ปัจจุบัน: <a href="<?= e(UPLOAD_URL . '/' . $o['video_file']) ?>" target="_blank" rel="noopener"><?= e(basename($o['video_file'])) ?></a> (เลือกไฟล์ใหม่เพื่อแทนที่)</p><?php endif; ?>
          </div>
          <div class="ne-field"><label for="posterFile">ภาพปก (ไม่บังคับ — แสดงก่อนกดเล่น เฉพาะไฟล์ที่อัปโหลด)</label>
            <input type="file" class="form-control" id="posterFile" name="poster" accept="image/jpeg,image/png,image/webp">
            <?php if ($o['poster']): ?><label style="margin:8px 0 0;font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="poster_remove" value="1"> ลบภาพปกปัจจุบัน (<a href="<?= e(UPLOAD_URL . '/' . $o['poster']) ?>" target="_blank" rel="noopener">ดูภาพ</a>)</label><?php endif; ?>
          </div>
          <div class="ne-field" style="margin-bottom:0"><label class="form-check" style="margin:0;display:flex;gap:10px;align-items:center;font-weight:400">
            <input class="form-check-input" type="checkbox" name="autoplay" value="1" <?= $o['autoplay'] ? 'checked' : '' ?>> เล่นอัตโนมัติ (ปิดเสียง วนซ้ำ)</label></div>
        </div>
      </div>

      <div class="ne-panel">
        <div class="ne-panel-head"><span><i class="bi bi-layout-split" style="color:var(--ne-blue);margin-right:6px;"></i>การจัดวาง</span></div>
        <div class="ne-panel-body">
          <div class="ne-field"><label>วิดีโอวางไว้ฝั่ง</label>
            <div class="promo-choices"><?php $choice('video_side', ['left' => '<i class="bi bi-layout-sidebar"></i> ซ้าย (คำบรรยายอยู่ขวา)', 'right' => '<i class="bi bi-layout-sidebar-reverse"></i> ขวา (คำบรรยายอยู่ซ้าย)'], $o['video_side']); ?></div>
          </div>
          <div class="ne-field"><label>การจัดแนวข้อความ</label>
            <div class="promo-choices"><?php $choice('text_align', ['left' => '<i class="bi bi-text-left"></i> ชิดซ้าย', 'center' => '<i class="bi bi-text-center"></i> กึ่งกลาง', 'right' => '<i class="bi bi-text-right"></i> ชิดขวา'], $o['text_align']); ?></div>
          </div>
          <div class="ne-field"><label>ตำแหน่งข้อความในแนวตั้ง</label>
            <div class="promo-choices"><?php $choice('v_align', ['start' => 'ด้านบน', 'center' => 'กึ่งกลาง', 'end' => 'ด้านล่าง'], $o['v_align']); ?></div>
          </div>
          <div class="ne-field"><label for="headingSize">ขนาดตัวอักษรหัวข้อ</label>
            <div class="promo-range"><input type="range" id="headingSize" name="heading_size" min="18" max="64" step="1" value="<?= (int)$o['heading_size'] ?>"><output id="headingSizeOut"><?= (int)$o['heading_size'] ?> px</output></div></div>
          <div class="ne-field"><label for="bodySize">ขนาดตัวอักษรคำบรรยาย</label>
            <div class="promo-range"><input type="range" id="bodySize" name="body_size" min="13" max="34" step="1" value="<?= (int)$o['body_size'] ?>"><output id="bodySizeOut"><?= (int)$o['body_size'] ?> px</output></div>
            <p class="ne-hint" style="margin:6px 0 0">บนมือถือขนาดจะลดลงอัตโนมัติให้พอดีหน้าจอ</p></div>
          <p class="ne-hint" style="margin:0 0 12px">สีของ<b>หัวข้อ</b>ถูกกำหนดเป็นสีน้ำเงินของคณะเสมอ (เหมือนหัวข้ออื่นในหน้าแรก) — ตั้งสีได้เฉพาะคำบรรยายและพื้นหลัง</p>
          <div class="promo-cols" style="gap:12px">
            <div class="ne-field" style="margin:0"><label>สีตัวอักษรคำบรรยาย</label><div class="promo-colorrow">
              <input type="color" id="textColor" name="text_color" value="<?= e($o['text_color'] ?: '#1B2032') ?>">
              <label><input type="checkbox" id="textColorOn" name="text_color_on" value="1" <?= $o['text_color'] ? 'checked' : '' ?>> กำหนดเอง</label></div></div>
            <div class="ne-field" style="margin:0"><label>สีพื้นหลังฝั่งข้อความ</label><div class="promo-colorrow">
              <input type="color" id="bgColor" name="bg_color" value="<?= e($o['bg_color'] ?: '#EEF1FA') ?>">
              <label><input type="checkbox" id="bgColorOn" name="bg_color_on" value="1" <?= $o['bg_color'] ? 'checked' : '' ?>> กำหนดเอง</label></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="ne-panel" style="margin-top:16px">
      <div class="ne-panel-head"><span><i class="bi bi-badge-ad" style="color:var(--ne-blue);margin-right:6px;"></i>โลโก้เหนือหัวข้อ</span></div>
      <div class="ne-panel-body">
        <?php if (!$hasLogoCols): ?>
        <div class="ne-empty" style="padding:14px"><i class="bi bi-database-exclamation"></i>ยังไม่ได้เพิ่มคอลัมน์โลโก้ — รันไฟล์ <b>home_promo_logo.sql</b> ใน phpMyAdmin หนึ่งครั้ง แล้วรีเฟรชหน้านี้</div>
        <?php else: ?>
        <div class="ne-field"><label>โลโก้</label>
          <div class="promo-choices" id="logoChoices"><?php $choice('logo_mode', ['none' => 'ไม่แสดง', 'site' => '<i class="bi bi-image"></i> ใช้โลโก้ของเว็บ', 'upload' => '<i class="bi bi-upload"></i> อัปโหลดรูปโลโก้'], $o['logo_mode']); ?></div>
        </div>
        <div class="ne-field" id="logoUpload"><label for="logoFile">ไฟล์โลโก้ (JPG / PNG / WEBP / SVG ไม่เกิน 5MB)</label>
          <input type="file" data-has-preview class="form-control" id="logoFile" name="logo" accept="image/jpeg,image/png,image/webp,image/svg+xml">
          <?php if ($o['logo']): ?><p class="ne-hint" style="margin:6px 0 0">ไฟล์ปัจจุบัน: <a href="<?= e(UPLOAD_URL . '/' . $o['logo']) ?>" target="_blank" rel="noopener"><?= e(basename($o['logo'])) ?></a> (เลือกไฟล์ใหม่เพื่อแทนที่)</p><?php endif; ?>
          <p class="ne-hint" style="margin:6px 0 0">รูปพื้นขาวใช้ได้ — ระบบทำให้พื้นขาวกลืนไปกับสีพื้นหลังอ่อนของการ์ดให้เอง (ถ้าไม่ได้ตั้งสีพื้นหลัง ก็แสดงเป็นพื้นขาวตามปกติ)</p>
        </div>
        <div class="ne-field" style="margin-bottom:0"><label for="logoSize">ขนาดโลโก้ (ความกว้าง)</label>
          <div class="promo-range"><input type="range" id="logoSize" name="logo_size" min="60" max="320" step="5" value="<?= (int)$o['logo_size'] ?>"><output id="logoSizeOut"><?= (int)$o['logo_size'] ?> px</output></div>
          <p class="ne-hint" style="margin:6px 0 0">ตำแหน่งตามการจัดแนวข้อความ (ซ้าย / กึ่งกลาง / ขวา)</p></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ne-panel" style="margin-top:16px">
      <div class="ne-panel-head"><span><i class="bi bi-card-text" style="color:var(--ne-blue);margin-right:6px;"></i>คำบรรยาย (อีกฝั่ง)</span></div>
      <div class="ne-panel-body">
        <div class="ne-field"><label for="heading">หัวข้อ</label>
          <input type="text" class="ne-input" id="heading" name="heading" maxlength="200" value="<?= e($o['heading']) ?>" placeholder="เช่น รู้จักคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ"></div>
        <div class="ne-field"><label for="body">คำบรรยาย</label>
          <textarea class="ne-input" id="body" name="body" rows="6" maxlength="4000" placeholder="พิมพ์รายละเอียด เว้นหนึ่งบรรทัดเพื่อขึ้นย่อหน้าใหม่"><?= e($o['body']) ?></textarea></div>
        <div class="promo-cols" style="gap:12px">
          <div class="ne-field" style="margin:0"><label for="btnLabel">ปุ่ม — ข้อความ (ไม่บังคับ)</label><input type="text" class="ne-input" id="btnLabel" name="btn_label" maxlength="60" value="<?= e($o['btn_label']) ?>" placeholder="เช่น ดูหลักสูตรทั้งหมด"></div>
          <div class="ne-field" style="margin:0"><label for="btnUrl">ปุ่ม — ลิงก์</label><input type="url" class="ne-input" id="btnUrl" name="btn_url" value="<?= e($o['btn_url']) ?>" placeholder="https://..."></div>
        </div>
      </div>
    </div>

    <div class="ne-panel" style="margin-top:16px">
      <div class="ne-panel-head"><span><i class="bi bi-eye" style="color:var(--ne-blue);margin-right:6px;"></i>ตัวอย่าง (โดยประมาณ)</span></div>
      <div class="ne-panel-body">
        <div class="promo-preview-grid" id="pvGrid">
          <div class="promo-preview-video" id="pvVideo"><i class="bi bi-play-circle"></i><span>วิดีโอ</span></div>
          <div class="promo-preview-text" id="pvText"><div id="pvLogo"></div><h3 id="pvHeading"></h3><div id="pvBody"></div><div id="pvBtn"></div></div>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px;position:sticky;bottom:0;padding:12px 0;background:linear-gradient(transparent,var(--ne-paper,#f5f6fa) 30%)">
      <button type="button" class="ne-btn ne-btn-primary" id="saveBtn" onclick="savePromo()"><i class="bi bi-check-lg"></i>บันทึก</button>
      <?php if ($row): ?><a class="ne-btn ne-btn-ghost" href="<?= BASE_URL ?>/" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>ดูหน้าแรก</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php
$extraJs = '<script>
const csrf = ' . json_encode(csrfToken()) . ';
const q = (id) => document.getElementById(id);
const SITE_LOGO = ' . json_encode(BASE_URL . '/assets/img/logo-full-sm.png') . ', SAVED_LOGO = ' . json_encode($o['logo'] !== '' ? UPLOAD_URL . '/' . $o['logo'] : '') . ';
let logoBlob = "";
function logoToggle() { const u = q("logoUpload"); if (!u) return; u.style.display = document.querySelector("input[name=logo_mode]:checked").value === "upload" ? "" : "none"; }
function srcToggle() { const up = document.querySelector("input[name=video_source]:checked").value === "upload"; q("srcUpload").style.display = up ? "" : "none"; q("srcUrl").style.display = up ? "none" : ""; }
function preview() {
  const f = q("promoForm"); if (!f) return;
  const v = (n) => f.querySelector("[name=" + n + "]");
  const checked = (n) => (f.querySelector("input[name=" + n + "]:checked") || {}).value;
  q("headingSizeOut").textContent = v("heading_size").value + " px"; q("bodySizeOut").textContent = v("body_size").value + " px";
  const t = q("pvText"), g = q("pvGrid");
  t.style.textAlign = checked("text_align"); t.style.justifyContent = ({start:"flex-start",center:"center",end:"flex-end"})[checked("v_align")];
  t.style.color = q("textColorOn").checked ? q("textColor").value : "";
  t.style.background = q("bgColorOn").checked ? q("bgColor").value : "transparent";
  q("pvHeading").style.fontSize = Math.round(v("heading_size").value * 0.6) + "px"; q("pvHeading").style.color = "#0F439B";
  const lmEl = f.querySelector("input[name=logo_mode]:checked"), lm = lmEl ? lmEl.value : "none", pl = q("pvLogo"); pl.innerHTML = "";
  if (q("logoSize")) q("logoSizeOut").textContent = v("logo_size").value + " px";
  const lsrc = lm === "site" ? SITE_LOGO : (lm === "upload" ? (logoBlob || SAVED_LOGO) : "");
  if (lsrc) { const im = document.createElement("img"); im.src = lsrc; im.alt = ""; im.style.cssText = "display:inline-block;width:" + Math.round(v("logo_size").value * 0.6) + "px;max-width:100%;height:auto;mix-blend-mode:multiply;margin-bottom:10px"; pl.appendChild(im); } q("pvBody").style.fontSize = Math.round(v("body_size").value * 0.7) + "px";
  q("pvHeading").textContent = v("heading").value || "หัวข้อ"; q("pvHeading").style.display = "";
  q("pvBody").innerHTML = ""; (v("body").value.trim() || "คำบรรยาย").split(/\n{2,}/).forEach(p => { const el = document.createElement("p"); el.textContent = p; el.style.margin = "0 0 .6em"; el.style.whiteSpace = "pre-line"; q("pvBody").appendChild(el); });
  q("pvBtn").innerHTML = ""; if (v("btn_label").value.trim()) { const b = document.createElement("span"); b.className = "promo-preview-btn"; b.textContent = v("btn_label").value; q("pvBtn").appendChild(b); }
  q("pvVideo").style.order = checked("video_side") === "right" ? "2" : "0";
}
document.addEventListener("DOMContentLoaded", () => {
  if (!q("promoForm")) return;
  srcToggle(); logoToggle(); preview();
  if (q("logoFile")) q("logoFile").addEventListener("change", (e) => { const fl = e.target.files[0]; logoBlob = fl ? URL.createObjectURL(fl) : ""; preview(); });
  q("promoForm").addEventListener("input", preview); q("promoForm").addEventListener("change", () => { srcToggle(); logoToggle(); preview(); });
});
async function savePromo() {
  const btn = q("saveBtn"); btn.disabled = true;
  try {
    const fd = new FormData(q("promoForm"));
    const r = await axios.post("", fd);
    if (r.data.status === "success") { showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); } else showError(r.data.message);
  } catch (e) { showError("บันทึกไม่สำเร็จ ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้ — ลองใช้ลิงก์ YouTube แทน"); }
  btn.disabled = false;
}
async function deletePromo() {
  const r = await Swal.fire({title:"ลบวิดีโอโปรโมท?", text:"ข้อมูลและไฟล์ที่อัปโหลดจะถูกลบ (ลบแล้วกู้คืนไม่ได้)", icon:"warning", showCancelButton:true, confirmButtonColor:"#c1443d", confirmButtonText:"ลบ", cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData(); fd.append("action","delete"); fd.append("csrf_token",csrf);
  const res = await axios.post("", fd);
  if (res.data.status === "success") { showSuccess(res.data.message); setTimeout(() => location.reload(), 900); } else showError(res.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';

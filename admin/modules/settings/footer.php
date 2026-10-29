<?php
/**
 * BAIT Admin - Site Settings > ข้อความ & ภาพ Footer
 */
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $uploadFailed = [];
    $data = ['footer_text' => postStr('footer_text')];

    // Footer background pictures (only when the column exists, i.e. footer_bg.sql has been run)
    if ($hasFooterBg) {
        $cur = $db->fetchOne("SELECT footer_bg FROM site_settings LIMIT 1") ?: [];
        $fb = footerBgOptions($cur['footer_bg'] ?? null);   // what is stored now: kept unless replaced / reset
        foreach ([['tall', 'footer_bg_tall', 'footer_bg_tall_remove', 'ภาพพื้นหลัง Footer แนวตั้ง'], ['wide', 'footer_bg_wide', 'footer_bg_wide_remove', 'ภาพพื้นหลัง Footer แนวนอน']] as [$key, $field, $rm, $label]) {
            if (!empty($_FILES[$field]['name'])) {
                $okType = in_array($_FILES[$field]['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true);   // pictures only: no GIF / SVG behind the footer text
                // The grey + darker treatment that keeps the white text readable is done in the browser (upload-compress.js), which then
                // sends <field>_processed=1. Without it (old browser, script blocked, Save pressed before it finished) the photo is refused.
                $processed = postStr($field . '_processed') === '1';
                $up = ($okType && $processed) ? uploadImage($_FILES[$field], 'footer', 8) : false;
                if ($up) { fileQueueDelete($fb[$key]); fileTrackNew($up); $fb[$key] = $up; }
                else { $uploadFailed[] = $label; if ($okType && !$processed) $notProcessed = true; }
            } elseif (!empty($_POST[$rm])) {
                fileQueueDelete($fb[$key]); $fb[$key] = '';
            }
        }
        $data['footer_bg'] = json_encode($fb, JSON_UNESCAPED_UNICODE);
    }
    saveSettingsRow($db, $data);
    fileCommitChanges();
    settingsDone($uploadFailed, !empty($notProcessed)
        ? 'ภาพต้องถูกปรับเป็นขาวดำก่อน: ใช้ Chrome หรือ Edge รุ่นใหม่ เลือกไฟล์แล้วรอข้อความ "ปรับภาพเป็นขาวดำ…แล้ว" ก่อนกดบันทึก'
        : 'รองรับ JPG, PNG, WEBP ไม่เกิน 8MB');
}

$pageTitle  = 'ตั้งค่าเว็บไซต์ — ข้อความ & ภาพ Footer';
$activeMenu = 'settings-footer';
$breadcrumb = [['label' => 'ตั้งค่าเว็บไซต์', 'url' => BASE_URL . '/admin/modules/settings/index.php'], ['label' => 'ข้อความ & ภาพ Footer']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div>
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-layout-text-window" style="color:var(--ne-blue);margin-right:6px;"></i>ข้อความ Footer</span></div>
          <div class="ne-panel-body">
            <textarea class="ne-input" name="footer_text" rows="3"
                      placeholder="© 2024 คณะบริหารธุรกิจ..."><?=e($settings['footer_text']??'')?></textarea>
          </div>
        </div>
        <?php $fbo = footerBgOptions($settings['footer_bg'] ?? null); ?>
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-building" style="color:var(--ne-blue);margin-right:6px;"></i>ภาพพื้นหลัง Footer</span></div>
          <div class="ne-panel-body">
          <?php if (!$hasFooterBg): ?>
            <p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังเปลี่ยนภาพไม่ได้: ต้องรันไฟล์ <code>footer_bg.sql</code> ใน phpMyAdmin หนึ่งครั้งก่อน (ระหว่างนี้เว็บใช้ภาพมาตรฐานตามปกติ)</p>
          <?php else: ?>
            <p class="ne-hint" style="margin-top:0;">ภาพตึกจางๆ ที่อยู่ด้านหลัง Footer ตอนเลือกไฟล์ เบราว์เซอร์จะปรับภาพให้เป็นขาวดำและลดความสว่างให้อัตโนมัติ เพื่อให้ตัวอักษรสีขาวอ่านชัดเสมอ เลือกเฉพาะด้านที่ต้องการเปลี่ยน ด้านที่ไม่เลือกจะใช้ภาพมาตรฐาน</p>
            <div style="display:flex;flex-wrap:wrap;gap:18px;">
            <?php foreach ([
                ['tall', 'footer_bg_tall', 'footer_bg_tall_remove', 'ภาพแนวตั้ง (มือถือ / แท็บเล็ต)', 'แนะนำ 940×1672 px (สัดส่วน 9:16)', 'footer-building-tall.jpg', 92],
                ['wide', 'footer_bg_wide', 'footer_bg_wide_remove', 'ภาพแนวนอน (คอมพิวเตอร์ จอ 1024px ขึ้นไป)', 'แนะนำ 2000×666 px (สัดส่วน 3:1)', 'footer-building-wide.jpg', 240],
            ] as [$k, $field, $rm, $title, $hint, $default, $pw]):
                $mine = ($fbo[$k] !== '' && is_file(UPLOAD_PATH . '/' . $fbo[$k]));
                $src = $mine ? UPLOAD_URL . '/' . $fbo[$k] : BASE_URL . '/assets/img/' . $default;
            ?>
              <div style="flex:1 1 260px;min-width:0;">
                <label for="<?= $field ?>" style="font-weight:600;"><?= $title ?></label>
                <div style="margin:8px 0;"><img src="<?= e($src) ?>" alt="" style="width:<?= $pw ?>px;max-width:100%;height:auto;border-radius:8px;border:1px solid #e3e6ee;display:block;"></div>
                <p class="ne-hint" style="margin:0 0 6px;"><?= $mine ? 'ภาพที่อัปโหลดไว้' : 'ภาพมาตรฐานของระบบ' ?></p>
                <input type="file" class="form-control form-control-sm" id="<?= $field ?>" name="<?= $field ?>" accept="image/jpeg,image/png,image/webp" data-backdrop>
                <p class="ne-hint" data-backdrop-note style="margin:4px 0 0;font-weight:600;"></p>
                <p class="ne-hint" style="margin-bottom:0;"><?= $hint ?> · JPG/PNG/WEBP ไม่เกิน 8MB<?php if ($mine): ?> ·
                  <label style="display:inline-flex;gap:4px;align-items:center;"><input type="checkbox" name="<?= $rm ?>"> กลับไปใช้ภาพมาตรฐาน</label><?php endif; ?></p>
              </div>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
          </div>
        </div>

      <button type="submit" class="ne-btn ne-btn-primary">
        <i class="bi bi-check-lg"></i>บันทึกการตั้งค่า
      </button>
    </div>
  </form>
</div>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

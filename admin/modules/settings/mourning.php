<?php
/**
 * BAIT Admin - Site Settings > ไว้อาลัย (Mourning Mode)
 */
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$hasMourning) { header('Location: mourning.php'); exit; }   // the column is missing: nothing can be saved yet
    $uploadFailed = [];
    $data = [];
    $cur = $db->fetchOne("SELECT mourning_options FROM site_settings LIMIT 1") ?: [];
    $was = mourningOptions($cur['mourning_options'] ?? null);   // what is stored now: its pictures are kept unless replaced / removed
    $mo = mourningOptions([
        'enabled'    => isset($_POST['mourning_enabled']),
        'mode'       => $_POST['mourning_mode'] ?? 'manual',
        'start'      => $_POST['mourning_start'] ?? '',
        'end'        => $_POST['mourning_end'] ?? '',
        'gray'       => isset($_POST['mourning_gray']),
        'level'      => $_POST['mourning_level'] ?? 95,
        'ribbon'     => isset($_POST['mourning_ribbon']),
        'ribbon_image' => $was['ribbon_image'],
        'popup'      => isset($_POST['mourning_popup']),
        'popup_freq' => $_POST['mourning_popup_freq'] ?? 'session',
        'popup_image'=> $was['popup_image'],
    ]);
    // A date that was typed but is not a real date (2026-02-31, or dd/mm/yyyy from a browser without a date picker) used to be
    // dropped silently - an end date lost that way left mourning switched on with no end. Refuse it and say which one.
    if ($mo['mode'] === 'schedule') {
        foreach (['mourning_start' => ['start', 'วันที่เริ่ม'], 'mourning_end' => ['end', 'วันที่สิ้นสุด']] as $field => [$key, $label]) {
            $typed = postStr($field);
            if ($typed !== '' && $mo[$key] === '') {
                $_SESSION['flash_error'] = 'โหมดไว้อาลัย: ' . $label . ' "' . $typed . '" ไม่ใช่วันที่ที่ถูกต้อง กรุณาเลือกจากปฏิทินอีกครั้ง (ไม่บันทึกการเปลี่ยนแปลง)';
                header('Location: mourning.php'); exit;
            }
        }
    }
    if ($mo['enabled'] && $mo['mode'] === 'schedule') {
        if ($mo['start'] === '' && $mo['end'] === '') {
            $_SESSION['flash_error'] = 'โหมดไว้อาลัย: เลือก "กำหนดช่วงวันที่" แล้ว ต้องใส่อย่างน้อยหนึ่งวันที่ (ไม่บันทึกการเปลี่ยนแปลง)';
            header('Location: mourning.php'); exit;
        }
        if ($mo['start'] !== '' && $mo['end'] !== '' && $mo['end'] < $mo['start']) {
            $_SESSION['flash_error'] = 'โหมดไว้อาลัย: วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม (ไม่บันทึกการเปลี่ยนแปลง)';
            header('Location: mourning.php'); exit;
        }
    }
    // pictures: a new upload replaces the old one, the "remove" box clears it; the old file is deleted only once everything is saved
    foreach ([['popup_image', 'mourning_popup_image', 'mourning_popup_image_remove', 'รูปป๊อปอัปไว้อาลัย', 8], ['ribbon_image', 'mourning_ribbon_image', 'mourning_ribbon_image_remove', 'รูปในริบบิ้น', 2]] as [$key, $field, $rm, $label, $mb]) {
        if (!empty($_FILES[$field]['name'])) {
            // only what the public page can show (mourningOptions() keeps png/jpg/webp/gif paths): an SVG/BMP/ICO/AVIF used to be
            // accepted, replace the current picture, and then be thrown away - leaving no picture at all
            $okType = in_array($_FILES[$field]['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true);
            $up = $okType ? uploadImage($_FILES[$field], 'mourning', $mb) : false;
            if ($up) { fileQueueDelete($mo[$key]); fileTrackNew($up); $mo[$key] = $up; }
            else $uploadFailed[] = $label;
        } elseif (!empty($_POST[$rm])) {
            fileQueueDelete($mo[$key]); $mo[$key] = '';
        }
    }
    $data['mourning_options'] = json_encode($mo, JSON_UNESCAPED_UNICODE);
    saveSettingsRow($db, $data);
    fileCommitChanges();
    $notice = ($mo['enabled'] && $mo['popup'] && $mo['popup_image'] === '') ? 'ยังไม่ได้อัปโหลดรูปป๊อปอัป ป๊อปอัปจะไม่แสดงจนกว่าจะมีรูป' : '';
    settingsDone($uploadFailed, 'รองรับไฟล์รูปภาพ เช่น JPG, PNG, WEBP', $notice);
}

$pageTitle  = 'ตั้งค่าเว็บไซต์ — ไว้อาลัย';
$activeMenu = 'settings-mourning';
$breadcrumb = [['label' => 'ตั้งค่าเว็บไซต์', 'url' => BASE_URL . '/admin/modules/settings/index.php'], ['label' => 'ไว้อาลัย']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div>
        <?php $mo = mourningOptions($settings['mourning_options'] ?? null); ?>
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head" style="gap:8px 12px;flex-wrap:wrap;">
            <span><i class="bi bi-flower1" style="color:var(--ne-blue);margin-right:6px;"></i>ไว้อาลัย (Mourning Mode)</span>
            <?php if ($hasMourning): ?><span class="ne-hint" style="margin:0;font-weight:600;">สถานะตอนนี้: <?= e(mourningStatusText($mo)) ?></span><?php endif; ?>
          </div>
          <div class="ne-panel-body">
          <?php if (!$hasMourning): ?>
            <p class="ne-hint" style="margin:0;">ยังใช้ฟีเจอร์นี้ไม่ได้ ต้องรัน SQL <code>mourning_options.sql</code> ใน phpMyAdmin หนึ่งครั้งก่อน (ปลอดภัย ไม่แตะข้อมูลเดิม) แล้วโหลดหน้านี้ใหม่</p>
          <?php else: ?>
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:12px;">
              <input type="checkbox" name="mourning_enabled" id="mnEnabled" <?= $mo['enabled'] ? 'checked' : '' ?>> เปิดโหมดไว้อาลัย (สวิตช์หลัก — ปิดอันนี้ = เว็บกลับเป็นปกติทั้งหมด)
            </label>

            <div id="mnBody" style="<?= $mo['enabled'] ? '' : 'opacity:.55;' ?>">
              <!-- when -->
              <div class="ne-field" style="margin-bottom:14px;">
                <label style="font-weight:600;">เปิดเมื่อไหร่</label>
                <label style="display:flex;align-items:center;gap:8px;margin:4px 0;"><input type="radio" name="mourning_mode" value="manual" <?= $mo['mode'] === 'manual' ? 'checked' : '' ?>> เปิดทันที และเปิดอยู่จนกว่าจะปิดเอง</label>
                <label style="display:flex;align-items:center;gap:8px;margin:4px 0;"><input type="radio" name="mourning_mode" value="schedule" <?= $mo['mode'] === 'schedule' ? 'checked' : '' ?>> กำหนดช่วงวันที่ (เปิดและปิดเองอัตโนมัติ)</label>
                <div class="ne-meta-row" id="mnDates" style="margin-top:6px;padding-top:0;border-top:none;<?= $mo['mode'] === 'schedule' ? '' : 'opacity:.55;' ?>">
                  <div class="ne-meta-field"><label for="mnStart">ตั้งแต่วันที่</label><input type="date" class="ne-input" id="mnStart" name="mourning_start" value="<?= e($mo['start']) ?>"></div>
                  <div class="ne-meta-field"><label for="mnEnd">ถึงวันที่ (รวมวันนี้ด้วย)</label><input type="date" class="ne-input" id="mnEnd" name="mourning_end" value="<?= e($mo['end']) ?>"></div>
                </div>
                <p class="ne-hint">ใช้เวลาประเทศไทย ใส่เฉพาะ "ตั้งแต่วันที่" = เปิดตั้งแต่วันนั้นเป็นต้นไป ใส่เฉพาะ "ถึงวันที่" = เปิดทันทีจนถึงวันนั้น</p>
              </div>

              <!-- grey filter -->
              <div style="border-top:1px solid #e3e6ee;padding-top:12px;margin-bottom:14px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:8px;">
                  <input type="checkbox" name="mourning_gray" id="mnGray" <?= $mo['gray'] ? 'checked' : '' ?>> ฟิลเตอร์สีเทา (Grayscale) ทั่วทั้งเว็บไซต์
                </label>
                <div id="mnGrayBox">
                  <label for="mnLevel" style="font-weight:600;">ความเข้มสีเทา: <output id="mnLevelOut"><?= (int)$mo['level'] ?></output>%</label>
                  <input type="range" id="mnLevel" name="mourning_level" min="0" max="100" step="5" value="<?= (int)$mo['level'] ?>" style="width:100%;">
                  <p class="ne-hint">0% = สีปกติ, 100% = ขาว-ดำเต็มรูปแบบ, แนะนำ: 95% (ราว 40–60% = แค่หม่นลง ยังเห็นสีจางๆ)</p>
                  <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1 1 200px;"><div style="height:26px;border-radius:6px;background:linear-gradient(90deg,#e01384,#ff914d,#ffe14a,#00bf63,#31a7ec,#0f439b,#7456fc);"></div><small class="ne-hint">0% (สีปกติ)</small></div>
                    <div style="flex:1 1 200px;"><div id="mnStrip" style="height:26px;border-radius:6px;background:linear-gradient(90deg,#e01384,#ff914d,#ffe14a,#00bf63,#31a7ec,#0f439b,#7456fc);filter:grayscale(<?= (int)$mo['level'] ?>%);"></div><small class="ne-hint">ตัวอย่างที่ระดับที่เลือก (<span id="mnStripPct"><?= (int)$mo['level'] ?></span>%)</small></div>
                  </div>
                </div>
              </div>

              <!-- ribbon -->
              <div style="border-top:1px solid #e3e6ee;padding-top:12px;margin-bottom:14px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:8px;">
                  <input type="checkbox" name="mourning_ribbon" id="mnRibbon" <?= $mo['ribbon'] ? 'checked' : '' ?>> ริบบิ้นไว้อาลัย (Ribbon) ที่มุมซ้ายบน
                </label>
                <div id="mnRibbonBox" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                  <div style="width:96px;height:96px;position:relative;overflow:hidden;background:#f1f3f9;border-radius:8px;flex:none;">
                    <div style="position:absolute;top:20px;left:-40px;width:160px;height:24px;background:#111;transform:rotate(-45deg);"></div>
                    <span style="position:absolute;left:22px;top:14px;width:36px;height:36px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px #111;display:grid;place-items:center;">
                      <img src="<?= e($mo['ribbon_image'] !== '' ? UPLOAD_URL . '/' . $mo['ribbon_image'] : BASE_URL . '/assets/img/mourning-ribbon.png') ?>" alt="" style="height:24px;width:auto;max-width:26px;object-fit:contain;">
                    </span>
                  </div>
                  <div style="flex:1 1 240px;">
                    <label for="mnRibbonImg" style="font-size:.85rem;">เปลี่ยนรูปในวงกลม (ไม่บังคับ)</label>
                    <input type="file" class="form-control form-control-sm" id="mnRibbonImg" name="mourning_ribbon_image" accept="image/png,image/webp,image/jpeg">
                    <p class="ne-hint" style="margin-bottom:0;">แนะนำ .png พื้นโปร่งใส ภาพตั้ง ~ 160×260 px ไม่เกิน 2MB<?php if ($mo['ribbon_image'] !== ''): ?> ·
                      <label style="display:inline-flex;gap:4px;align-items:center;"><input type="checkbox" name="mourning_ribbon_image_remove"> ใช้รูปมาตรฐานแทน</label><?php endif; ?></p>
                  </div>
                </div>
              </div>

              <!-- pop-up -->
              <div style="border-top:1px solid #e3e6ee;padding-top:12px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:8px;">
                  <input type="checkbox" name="mourning_popup" id="mnPopup" <?= $mo['popup'] ? 'checked' : '' ?>> ป๊อปอัปไว้อาลัย (Popup) เมื่อเข้าหน้าเว็บ
                </label>
                <div id="mnPopupBox">
                  <div class="ne-field" style="margin-bottom:10px;">
                    <label style="font-weight:600;">ความถี่ในการแสดง</label>
                    <label style="display:flex;align-items:center;gap:8px;margin:4px 0;"><input type="radio" name="mourning_popup_freq" value="session" <?= $mo['popup_freq'] === 'session' ? 'checked' : '' ?>> แสดงครั้งเดียวต่อการเปิดเบราว์เซอร์ (แนะนำ)</label>
                    <label style="display:flex;align-items:center;gap:8px;margin:4px 0;"><input type="radio" name="mourning_popup_freq" value="always" <?= $mo['popup_freq'] === 'always' ? 'checked' : '' ?>> แสดงทุกครั้งที่โหลดหน้า</label>
                  </div>
                  <div class="ne-field" style="margin-bottom:0;">
                    <label for="mnPopupImg" style="font-weight:600;">รูปป๊อปอัป</label>
                    <?php if ($mo['popup'] && $mo['popup_image'] === ''): ?><p class="ne-hint" style="margin:4px 0;color:#b45309;font-weight:600;">เปิดป๊อปอัปไว้แต่ยังไม่มีรูป — ป๊อปอัปจะไม่แสดงจนกว่าจะอัปโหลดรูป</p><?php endif; ?>
                    <?php if ($mo['popup_image'] !== ''): ?>
                    <div style="margin:6px 0;"><img src="<?= e(UPLOAD_URL . '/' . $mo['popup_image']) ?>" alt="" style="max-height:180px;max-width:100%;border-radius:8px;border:1px solid #e3e6ee;"></div>
                    <?php endif; ?>
                    <input type="file" class="form-control form-control-sm" id="mnPopupImg" name="mourning_popup_image" accept="image/png,image/jpeg,image/webp">
                    <p class="ne-hint" style="margin-bottom:0;">แนะนำภาพตั้งหรือสี่เหลี่ยม ความกว้าง 600–1000 px ไม่เกิน 8MB (ป๊อปอัปจะไม่แสดงถ้ายังไม่มีรูป)<?php if ($mo['popup_image'] !== ''): ?> ·
                      <label style="display:inline-flex;gap:4px;align-items:center;"><input type="checkbox" name="mourning_popup_image_remove"> ลบรูปนี้</label><?php endif; ?></p>
                  </div>
                </div>
              </div>
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

<script>
(function () {
  var en = document.getElementById('mnEnabled'); if (!en) return;
  var body = document.getElementById('mnBody'), dates = document.getElementById('mnDates'), lv = document.getElementById('mnLevel'), out = document.getElementById('mnLevelOut'),
      strip = document.getElementById('mnStrip'), pct = document.getElementById('mnStripPct'),
      gray = document.getElementById('mnGray'), grayBox = document.getElementById('mnGrayBox'),
      rib = document.getElementById('mnRibbon'), ribBox = document.getElementById('mnRibbonBox'),
      pop = document.getElementById('mnPopup'), popBox = document.getElementById('mnPopupBox');
  function dim(el, on) { el.style.opacity = on ? '' : '.5'; }
  function sync() {
    dim(body, en.checked);
    var sch = document.querySelector('input[name="mourning_mode"]:checked');
    dim(dates, sch && sch.value === 'schedule');
    dim(grayBox, gray.checked); dim(ribBox, rib.checked); dim(popBox, pop.checked);
    out.textContent = lv.value; pct.textContent = lv.value; strip.style.filter = 'grayscale(' + lv.value + '%)';
  }
  [en, gray, rib, pop].forEach(function (c) { c.addEventListener('change', sync); });
  lv.addEventListener('input', sync);
  document.querySelectorAll('input[name="mourning_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

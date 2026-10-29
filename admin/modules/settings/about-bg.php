<?php
/**
 * BAIT Admin - ข้อมูลคณะ > พื้นหลัง "เกี่ยวกับคณะ" (the "เกี่ยวกับคณะ" section on the home page).
 * One photo behind the section, treated automatically in the chosen tone: "light" (grey photo under a white layer, dark text),
 * "blue" (grey, darker photo under the brand blue like the footer, white text) or "color" (the photo in its own colours under a
 * neutral dark layer, white text); the slider sets how strong the layer is. No photo = the plain white section.
 */
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasAboutBg) {
    verifyCsrf();
    $uploadFailed = [];
    $cur = aboutBgOptions(($db->fetchOne("SELECT about_bg FROM site_settings LIMIT 1") ?: [])['about_bg'] ?? null);
    $cur['tone'] = in_array(postStr('about_bg_tone'), ['blue', 'color'], true) ? postStr('about_bg_tone') : 'light';
    $cur['dim']  = max(30, min(90, (int)postStr('about_bg_dim', (string)$cur['dim'])));
    if (!empty($_FILES['about_bg_image']['name'])) {
        $okType = in_array($_FILES['about_bg_image']['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true);   // photos only
        $up = $okType ? uploadImage($_FILES['about_bg_image'], 'about', 8) : false;
        if ($up) { fileQueueDelete($cur['image']); fileTrackNew($up); $cur['image'] = $up; }
        else $uploadFailed[] = 'ภาพพื้นหลัง';
    } elseif (postStr('about_bg_remove') === '1') {
        fileQueueDelete($cur['image']); $cur['image'] = '';
    }
    saveSettingsRow($db, ['about_bg' => json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    fileCommitChanges();
    settingsDone($uploadFailed, 'รองรับไฟล์รูปภาพ เช่น JPG, PNG, WEBP ไม่เกิน 8MB');
}

$pageTitle  = 'ข้อมูลคณะ — พื้นหลัง "เกี่ยวกับคณะ"';
$activeMenu = 'settings-about-bg';
$breadcrumb = [['label' => 'ข้อมูลคณะ', 'url' => BASE_URL . '/admin/modules/faculty/index.php'], ['label' => 'พื้นหลัง "เกี่ยวกับคณะ"']];   // the page lives with the settings pages but sits under ข้อมูลคณะ in the menu
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';

$ab   = aboutBgOptions($settings['about_bg'] ?? null);
$mine = $ab['image'] !== '' && is_file(UPLOAD_PATH . '/' . $ab['image']);
$src  = $mine ? UPLOAD_URL . '/' . $ab['image'] . '?v=' . (int)@filemtime(UPLOAD_PATH . '/' . $ab['image']) : '';
?>
<style>
/* the preview copies the home page treatment (assets/css/pages.css, .about-band--light / --blue) */
.abg-preview { --photo: none; position: relative; isolation: isolate; border-radius: 12px; overflow: hidden; min-height: 170px; padding: 22px;
  display: flex; flex-direction: column; justify-content: center; gap: 6px; background: #fff; border: 1px solid #e3e6ee; color: #1B2032; }
.abg-preview::before { content: ""; position: absolute; inset: 0; z-index: -2; background: var(--photo) center / cover no-repeat; filter: grayscale(1); opacity: 0; }
.abg-preview::after { content: ""; position: absolute; inset: 0; z-index: -1; background: rgba(255, 255, 255, var(--d, .74)); opacity: 0; }
.abg-preview.has-photo::before, .abg-preview.has-photo::after { opacity: 1; }
.abg-preview strong { font-size: 1.35rem; color: #1558A6; }
.abg-preview small { font-weight: 700; letter-spacing: .12em; font-size: .7rem; color: #1558A6; }
.abg-preview.has-photo.is-blue::before { filter: grayscale(1) brightness(.62); }
.abg-preview.has-photo.is-blue::after { background: rgba(21, 88, 166, var(--d, .74)); }
.abg-preview.has-photo.is-color::before { filter: none; }
.abg-preview.has-photo.is-color::after { background: rgba(10, 18, 32, var(--d, .74)); }
.abg-preview.has-photo.is-blue, .abg-preview.has-photo.is-color { color: #E6EDF8; }
.abg-preview.has-photo.is-blue strong, .abg-preview.has-photo.is-color strong { color: #fff; }
.abg-preview.has-photo.is-blue small, .abg-preview.has-photo.is-color small { color: #FFC59E; }
.abg-tones { display: flex; flex-wrap: wrap; gap: 10px; margin: 8px 0 18px; }
.abg-tone input { position: absolute; opacity: 0; }
.abg-tone span { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; min-height: 44px; border: 1.5px solid #e3e6ee; border-radius: 10px; background: #fff; font-weight: 600; cursor: pointer; }
.abg-tone span i { width: 22px; height: 22px; border-radius: 6px; border: 1px solid #cfd6e4; display: inline-block; }
.abg-tone input:checked + span { border-color: #162E93; background: #E8EEFB; color: #162E93; }
.abg-tone input:focus-visible + span { outline: 3px solid #93B4F5; outline-offset: 2px; }
.abg-remove { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 10px 18px; border-radius: 10px; font-weight: 700; cursor: pointer;
  background: #fff; color: #B42318; border: 1.5px solid #B42318; }
.abg-remove:hover, .abg-remove:focus-visible { background: #B42318; color: #fff; }
.abg-remove.is-on { background: #B42318; color: #fff; }
</style>
<div class="ne">
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div class="ne-panel" style="margin-bottom:14px;">
      <div class="ne-panel-head"><span><i class="bi bi-image-alt" style="color:var(--ne-blue);margin-right:6px;"></i>ภาพพื้นหลังส่วน "เกี่ยวกับคณะ" หน้าแรก</span></div>
      <div class="ne-panel-body">
      <?php if (!$hasAboutBg): ?>
        <p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังตั้งภาพไม่ได้: ต้องรันไฟล์ <code>sql/bait_migrations_safe_to_rerun.sql</code> (ข้อ 8) ใน phpMyAdmin หนึ่งครั้งก่อน (ระหว่างนี้หน้าแรกแสดงแบบพื้นขาวตามเดิม)</p>
      <?php else: ?>
        <p class="ne-hint" style="margin-top:0;">ภาพจะแสดงเต็มความกว้างด้านหลังข้อความ "เกี่ยวกับคณะ" และระบบปรับภาพให้อัตโนมัติตามโทนที่เลือก ตัวอักษรจึงอ่านชัดเสมอ ถ้าไม่ใส่ภาพ ส่วนนี้จะเป็นพื้นขาวเหมือนเดิม</p>

        <div class="abg-preview<?= $mine ? ' has-photo' : '' ?><?= $ab['tone'] !== 'light' ? ' is-' . $ab['tone'] : '' ?>" id="abgPreview" style="<?= $mine ? '--photo:url(&quot;' . e($src) . '&quot;);' : '' ?>--d:<?= $ab['dim'] / 100 ?>">
          <small>เกี่ยวกับเรา</small>
          <strong>เกี่ยวกับคณะ</strong>
          <span>ตัวอย่างข้อความวิสัยทัศน์ / พันธกิจ</span>
        </div>
        <p class="ne-hint" style="margin:6px 0 16px;" id="abgState"><?= $mine ? 'ภาพที่ใช้อยู่' : 'ยังไม่มีภาพ (พื้นขาว)' ?></p>

        <div style="font-weight:600;">โทนสี</div>
        <div class="abg-tones" role="radiogroup" aria-label="โทนสี">
          <label class="abg-tone"><input type="radio" name="about_bg_tone" value="light"<?= $ab['tone'] === 'light' ? ' checked' : '' ?>><span><i style="background:#fff"></i>โทนขาว (ตัวอักษรสีเข้ม)</span></label>
          <label class="abg-tone"><input type="radio" name="about_bg_tone" value="blue"<?= $ab['tone'] === 'blue' ? ' checked' : '' ?>><span><i style="background:#1558A6"></i>โทนน้ำเงิน แบบ Footer (ตัวอักษรสีขาว)</span></label>
          <label class="abg-tone"><input type="radio" name="about_bg_tone" value="color"<?= $ab['tone'] === 'color' ? ' checked' : '' ?>><span><i style="background:linear-gradient(135deg,#4FA3E0,#6BBF59,#E8A93B)"></i>ภาพสีจริง (ตัวอักษรสีขาว)</span></label>
        </div>

        <label for="about_bg_dim" style="font-weight:600;">ความเข้มของชั้นสีทับภาพ: <span id="abgDimVal"><?= $ab['dim'] ?></span>%</label>
        <input type="range" class="form-range" id="about_bg_dim" name="about_bg_dim" min="30" max="90" step="2" value="<?= $ab['dim'] ?>" style="max-width:420px;display:block;">
        <p class="ne-hint" style="margin:2px 0 18px;">น้อยลง = เห็นภาพชัดขึ้น · มากขึ้น = ภาพจางลง ตัวอักษรอ่านง่ายขึ้น (ค่าเริ่มต้น 74%) <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="abgDimReset">กลับค่าเริ่มต้น</button></p>

        <label for="about_bg_image" style="font-weight:600;"><?= $mine ? 'เปลี่ยนภาพ' : 'เลือกภาพ' ?></label>
        <input type="file" data-has-preview class="form-control form-control-sm" id="about_bg_image" name="about_bg_image" accept="image/jpeg,image/png,image/webp" style="margin-top:6px;">
        <p class="ne-hint" style="margin:4px 0 14px;">แนะนำภาพแนวนอน กว้าง 1920 px ขึ้นไป (เช่น ภาพอาคารคณะ) · JPG/PNG/WEBP ไม่เกิน 8MB · ระบบย่อขนาดภาพให้อัตโนมัติ</p>

        <?php if ($mine): ?>
        <input type="hidden" name="about_bg_remove" id="about_bg_remove" value="0">
        <div><button type="button" class="abg-remove" id="abgRemove" aria-pressed="false"><i class="bi bi-trash3"></i><span>เอาภาพออก (กลับเป็นพื้นขาว)</span></button></div>
        <p class="ne-hint" style="margin:6px 0 0;">กดแล้วกด "บันทึกการตั้งค่า" ด้านล่างเพื่อยืนยัน · กดซ้ำเพื่อยกเลิก</p>
        <?php endif; ?>
      <?php endif; ?>
      </div>
    </div>
    <?php if ($hasAboutBg): ?>
    <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-check-lg"></i>บันทึกการตั้งค่า</button>
    <?php endif; ?>
  </form>
</div>
<?php if ($hasAboutBg): ?>
<script>
(function () {
  var pv = document.getElementById('abgPreview'), file = document.getElementById('about_bg_image'), state = document.getElementById('abgState');
  var rmBtn = document.getElementById('abgRemove'), rm = document.getElementById('about_bg_remove');
  var saved = pv.style.getPropertyValue('--photo');
  var show = function (url, text) { pv.style.setProperty('--photo', url || 'none'); pv.classList.toggle('has-photo', !!url); state.textContent = text; };
  var setRemove = function (on) {
    if (!rmBtn) return;
    rm.value = on ? '1' : '0';
    rmBtn.classList.toggle('is-on', on); rmBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    rmBtn.querySelector('span').textContent = on ? 'จะเอาภาพออกเมื่อบันทึก (กดเพื่อยกเลิก)' : 'เอาภาพออก (กลับเป็นพื้นขาว)';
  };
  var dim = document.getElementById('about_bg_dim'), dimVal = document.getElementById('abgDimVal');
  var setDim = function (v) { dim.value = v; pv.style.setProperty('--d', v / 100); dimVal.textContent = v; };
  dim.addEventListener('input', function () { setDim(dim.value); });
  document.getElementById('abgDimReset').addEventListener('click', function () { setDim(74); });
  document.querySelectorAll('input[name="about_bg_tone"]').forEach(function (r) {
    r.addEventListener('change', function () { if (!r.checked) return; pv.classList.toggle('is-blue', r.value === 'blue'); pv.classList.toggle('is-color', r.value === 'color'); });
  });
  file.addEventListener('change', function () {
    var f = file.files && file.files[0];
    if (!f) { show(saved, saved ? 'ภาพที่ใช้อยู่' : 'ยังไม่มีภาพ (พื้นขาว)'); return; }
    setRemove(false);
    show('url("' + URL.createObjectURL(f) + '")', 'ตัวอย่างภาพใหม่ (ยังไม่บันทึก)');
  });
  if (rmBtn) rmBtn.addEventListener('click', function () {
    var on = rm.value !== '1';
    setRemove(on);
    if (on) { file.value = ''; show('', 'จะกลับเป็นพื้นขาวเมื่อกดบันทึก'); } else show(saved, 'ภาพที่ใช้อยู่');
  });
})();
</script>
<?php endif; ?>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

<?php
/**
 * BAIT Admin - Site Settings > ข้อมูลทั่วไป & โลโก้ (name, SEO, logo, favicon)
 */
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $siteName = postStr('site_name');
    $siteAbbr = postStr('site_abbr');

    // The name and abbreviation appear in every page title, so they can never be saved empty.
    if ($siteName === '' || $siteAbbr === '') {
        $_SESSION['flash_error'] = 'กรุณากรอกชื่อเว็บไซต์และชื่อย่อ (ไม่บันทึกการเปลี่ยนแปลง)';
        header('Location: index.php'); exit;
    }
    $uploadFailed = [];
    $data = [
        'site_name'     => $siteName,
        'site_abbr'     => $siteAbbr,
        'meta_keywords' => postStr('meta_keywords'),
        'meta_desc'     => postStr('meta_desc'),
    ];

    // Logo / favicon: a new upload replaces the old one; the old file is deleted only once the save worked
    if (!empty($_FILES['logo']['name'])) {
        $up = uploadImage($_FILES['logo'], 'faculty');
        if ($up) { fileQueueDelete($settings['logo'] ?? null); fileTrackNew($up); $data['logo'] = $up; } else $uploadFailed[] = 'โลโก้';
    }
    if (!empty($_FILES['favicon']['name'])) {
        $up = uploadImage($_FILES['favicon'], 'faculty');
        if ($up) { fileQueueDelete($settings['favicon'] ?? null); fileTrackNew($up); $data['favicon'] = $up; } else $uploadFailed[] = 'ไอคอนแท็บ (favicon)';
    }
    saveSettingsRow($db, $data);
    fileCommitChanges();
    settingsDone($uploadFailed);
}

$pageTitle  = 'ตั้งค่าเว็บไซต์ — ข้อมูลทั่วไป & โลโก้';
$activeMenu = 'settings-general';
$breadcrumb = [['label' => 'ตั้งค่าเว็บไซต์', 'url' => BASE_URL . '/admin/modules/settings/index.php'], ['label' => 'ข้อมูลทั่วไป & โลโก้']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;">
      <div style="flex:2 1 420px;min-width:0;">
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-globe" style="color:var(--ne-blue);margin-right:6px;"></i>ข้อมูลทั่วไป</span></div>
          <div class="ne-panel-body">
            <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;">
              <div class="ne-meta-field" style="flex:2 1 300px;">
                <label for="siteName">ชื่อเว็บไซต์</label>
                <input type="text" class="ne-input" id="siteName" name="site_name"
                       value="<?=e($settings['site_name']??'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ')?>">
              </div>
              <div class="ne-meta-field">
                <label for="siteAbbr">ชื่อย่อ (Abbr)</label>
                <input type="text" class="ne-input" id="siteAbbr" name="site_abbr"
                       value="<?=e($settings['site_abbr']??'BAIT')?>">
              </div>
            </div>
          </div>
        </div>

        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-search" style="color:var(--ne-blue);margin-right:6px;"></i>SEO Settings</span></div>
          <div class="ne-panel-body">
            <div class="ne-field">
              <label for="metaKeywords">Meta Keywords</label>
              <input type="text" class="ne-input" id="metaKeywords" name="meta_keywords"
                     value="<?=e($settings['meta_keywords']??'')?>"
                     placeholder="BAIT,บริหารธุรกิจ,ราชมงคลสุวรรณภูมิ">
              <p class="ne-hint">คั่นด้วยเครื่องหมาย ,</p>
            </div>
            <div class="ne-field" style="margin-bottom:0;">
              <label for="metaDesc">Meta Description</label>
              <textarea class="ne-input" id="metaDesc" name="meta_desc" rows="3"
                        placeholder="คำอธิบายเว็บไซต์สำหรับ Search Engine"><?=e($settings['meta_desc']??'')?></textarea>
              <p class="ne-hint">แนะนำ 150-160 ตัวอักษร</p>
              <p class="ne-hint"><b>ใช้ที่:</b> หน้าแรกของเว็บไซต์ — เป็นข้อความสีเทาใต้ชื่อเว็บในผลการค้นหาของ Google และข้อความในกล่องตัวอย่างเมื่อแชร์ลิงก์หน้าแรกใน Facebook / LINE (หน้าภาษาอังกฤษแปลให้เอง)
                · เว้นว่าง = ใช้ข้อความมาตรฐานของระบบ · หน้าอื่น (ข่าว บทความ หลักสูตร ฯลฯ) ใช้คำอธิบายของหน้านั้นเอง<br>
                ตัวอย่าง: คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มทร.สุวรรณภูมิ เปิดสอนหลักสูตร ปวส. ปริญญาตรี และปริญญาโท ใน 4 ศูนย์พื้นที่ พร้อมข่าวสารและข้อมูลการรับสมัคร</p>
            </div>
          </div>
        </div>

      </div>

      <div style="flex:1 1 260px;min-width:0;">
        <!-- Logo -->
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-image" style="color:var(--ne-blue);margin-right:6px;"></i>โลโก้เว็บไซต์</span></div>
          <div class="ne-panel-body text-center">
            <?php if (!empty($settings['logo'])): ?>
            <img src="<?=UPLOAD_URL.'/'.e($settings['logo'])?>" class="img-fluid rounded mb-2" style="max-height:100px" alt="Logo">
            <?php else: ?>
            <div class="ne-thumb-empty" style="width:100%;height:90px;border-radius:8px;margin-bottom:10px;"><i class="bi bi-image"></i></div>
            <?php endif; ?>
            <input type="file" class="form-control form-control-sm" name="logo" accept="image/*">
            <p class="ne-hint">PNG/SVG แนะนำ 200×80 px</p>
            <p class="ne-hint" style="text-align:left;"><b>ใช้ที่:</b> แถบเมนูด้านซ้ายของหลังบ้าน และข้อมูลคณะที่ส่งให้ Google (เมื่อยังไม่ได้ใส่ "โลโก้คณะ" ในเมนูข้อมูลคณะ)<br>
              <b>ไม่เปลี่ยน:</b> โลโก้หัวเว็บและท้ายเว็บไซต์ (เป็นไฟล์คงที่ของเว็บ)</p>
          </div>
        </div>

        <!-- Favicon -->
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-file-image" style="color:var(--ne-blue);margin-right:6px;"></i>Favicon</span></div>
          <div class="ne-panel-body text-center">
            <?php if (!empty($settings['favicon'])): ?>
            <img src="<?=UPLOAD_URL.'/'.e($settings['favicon'])?>" class="mb-2" style="width:48px;height:48px" alt="Favicon">
            <?php else: ?>
            <div class="ne-thumb-empty" style="width:100%;height:70px;border-radius:8px;margin-bottom:10px;"><i class="bi bi-globe"></i></div>
            <?php endif; ?>
            <input type="file" class="form-control form-control-sm" name="favicon" accept="image/*">
            <p class="ne-hint">ICO/PNG 32×32 px</p>
            <p class="ne-hint" style="text-align:left;"><b>ใช้ที่:</b> ไอคอนเล็กบนแท็บเบราว์เซอร์และบุ๊กมาร์กของเว็บไซต์</p>
          </div>
        </div>
      </div>
    </div>
    <div style="margin-top:14px;">
      <button type="submit" class="ne-btn ne-btn-primary">
        <i class="bi bi-check-lg"></i>บันทึกการตั้งค่า
      </button>
    </div>
  </form>
</div>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

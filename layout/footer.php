<?php
/**
 * BAIT Frontend — Layout Footer (Phase 3 rebuild). Framework-free.
 */
$settings = $settings ?? $db->fetchOne("SELECT * FROM site_settings LIMIT 1") ?: [];
$faculty  = $faculty  ?? $db->fetchOne("SELECT logo, faculty_abbr, faculty_name_th, faculty_name_en FROM faculty LIMIT 1") ?: [];
$footerCampusList = $footerCampuses ?? $db->fetchAll("SELECT campus_id, campus_name FROM campuses ORDER BY campus_id");
$t = $t ?? fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;
// Default when the admin left "ข้อความ Footer" empty: Thai text with the Buddhist-era year (2569, not 2026), English with the CE year
$footerText = trim((string)($settings['footer_text'] ?? '')) !== '' ? $settings['footer_text'] : ($lang === 'en'
    ? '© ' . date('Y') . ' Faculty of Business Administration and Information Technology, Rajamangala University of Technology Suvarnabhumi'
    : '© ' . ((int)date('Y') + 543) . ' คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ');

// the address of the first campus (the faculty office, the same one about.php names), not a fixed campus_id = 1 that may
// not exist after campuses were deleted and added again
$campus1 = $db->fetchOne(
    "SELECT c.campus_name, c.address, cc.contact_value
     FROM campuses c LEFT JOIN campus_contacts cc ON cc.campus_id = c.campus_id AND cc.contact_type = 'phone'
     ORDER BY c.campus_id, cc.contact_id LIMIT 1"
);
?>
</main>

<footer class="site-footer">
  <div class="footer-main">
    <div class="container">
      <div class="footer-grid">

        <div>
          <div class="footer-brand">
            <img src="<?= BASE_URL ?>/assets/img/logo-full-sm.png" alt="<?= e($settings['site_abbr'] ?? 'BAIT') ?>" width="640" height="171">
          </div>
          <p class="footer-desc">
            <?= $lang === 'en'
              ? 'Faculty of Business Administration and Information Technology, Rajamangala University of Technology Suvarnabhumi'
              : 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ' ?>
          </p>
          <?php if (($visitStats = siteVisitStats()) !== null): ?>
          <p class="footer-heading footer-visits-heading" role="heading" aria-level="2"><?= $lang === 'en' ? 'Website visitors' : 'สถิติผู้เข้าชม' ?></p>
          <ul class="footer-visits">
            <?php foreach ([
                'today' => $lang === 'en' ? 'Today'      : 'วันนี้',
                'month' => $lang === 'en' ? 'This month' : 'เดือนนี้',
                'year'  => $lang === 'en' ? 'This year'  : 'ปีนี้',
                'all'   => $lang === 'en' ? 'All time'   : 'ทั้งหมด',
            ] as $k => $label): ?>
            <li><span class="footer-visits__num"><sup aria-hidden="true">+</sup><?= number_format($visitStats[$k]) ?></span><span class="footer-visits__label"><?= $label ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>

        <div>
          <p class="footer-heading" role="heading" aria-level="2"><?= $t('ศูนย์พื้นที่') ?></p>
          <ul class="footer-links">
            <?php foreach ($footerCampusList as $c): ?>
            <li><a href="<?= BASE_URL ?>/campus-detail.php?lang=<?= e($lang) ?>#campus-<?= (int)$c['campus_id'] ?>"><?= e($t($c['campus_name'])) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div>
          <p class="footer-heading" role="heading" aria-level="2"><?= $t('ลิงก์ด่วน') ?></p>
          <ul class="footer-links">
            <li><a href="<?= BASE_URL ?>/"><?= $t('หน้าหลัก') ?></a></li>
            <li><a href="<?= BASE_URL ?>/about.php?lang=<?= e($lang) ?>"><?= $t('เกี่ยวกับคณะ') ?></a></li>
            <li><a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>"><?= $t('หลักสูตร') ?></a></li>
            <li><a href="<?= BASE_URL ?>/news.php?lang=<?= e($lang) ?>"><?= $t('ข่าวสาร') ?></a></li>
            <li><a href="<?= BASE_URL ?>/staff.php?lang=<?= e($lang) ?>"><?= $t('บุคลากร') ?></a></li>
            <li><a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>"><?= $t('ติดต่อ') ?></a></li>
          </ul>
        </div>

        <div>
          <p class="footer-heading" role="heading" aria-level="2"><?= $t('ติดต่อเรา') ?></p>
          <?php if ($campus1): ?>
          <ul class="footer-links">
            <?php if (!empty($campus1['address'])): ?>
            <li><?= icon('pin', 'icon-sm') ?> <?= e(mb_strimwidth($t($campus1['address']), 0, 80, '...', 'UTF-8')) ?></li>
            <?php endif; ?>
            <?php if (!empty($campus1['contact_value'])): ?>
            <li><?= icon('phone', 'icon-sm') ?> <?= e($campus1['contact_value']) ?></li>
            <?php endif; ?>
          </ul>
          <?php endif; ?>
          <div style="margin-top:14px">
            <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" class="btn btn-on-dark btn-sm"><?= icon('mail', 'icon-sm') ?> <?= $t('ติดต่อเรา') ?></a>
          </div>
        </div>

      </div>
    </div>
  </div>

  <div class="footer-bottom">
    <div class="container">
      <small><?= e($t($footerText)) ?></small>
      <small><a href="<?= BASE_URL ?>/privacy.php?lang=<?= e($lang) ?>"><?= $lang === 'en' ? 'Privacy Policy' : 'นโยบายความเป็นส่วนตัว' ?></a> · <a href="<?= BASE_URL ?>/sitemap.xml.php">Sitemap</a> · Powered by BAIT CMS</small>
    </div>
  </div>
</footer>

<a href="https://baitrus.com/" target="_blank" rel="noopener" title="BAIT RUS" class="baitrus-float">
  <img src="<?= BASE_URL ?>/assets/img/baitrus-icon.png" alt="BAIT RUS"<?= imgSizeAttrs(BASE_PATH . '/assets/img/baitrus-icon.png', true) ?>>
</a>

<button type="button" class="back-to-top" aria-label="<?= $lang === 'en' ? 'Back to top' : 'กลับด้านบน' ?>">
  <?= icon('chevron-up') ?>
</button>

<script type="module" src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(BASE_PATH . '/assets/js/main.js') ?: time() ?>"></script>
<?php if (!empty($extraJs)) echo $extraJs; ?>
<?php
if (!empty($i18nOk)):   // set in layout/header.php: only a signed-in admin
  // Debug panel: add &i18n=1 to any English URL to see where each string came from.
  $i18n = $GLOBALS['__i18n'] ?? [];
  $groups = ['file' => ['จากพจนานุกรมในโค้ด (ไม่เรียก API)', '#0a7'], 'cache' => ['จากฐานข้อมูล — แปลไว้แล้ว (แคช)', '#07c'], 'api' => ['เรียก API ในครั้งนี้', '#c60'], 'failed' => ['แปลไม่สำเร็จ (ยังเป็นไทย)', '#c00']];
?>
<div style="position:fixed;left:8px;bottom:8px;width:min(460px,94vw);max-height:60vh;overflow:auto;background:#fff;color:#111;border:2px solid #333;border-radius:8px;padding:10px;font:12px/1.4 sans-serif;z-index:99999">
  <b>i18n debug</b>
  <?php foreach ($groups as $k => [$label, $col]): $rows = $i18n[$k] ?? []; ?>
  <details><summary style="color:<?= $col ?>;font-weight:700"><?= e($label) ?> — <?= count($rows) ?></summary>
    <ul style="margin:4px 0 8px 16px;padding:0"><?php foreach ($rows as $s => $tr): ?><li><?= e(mb_strimwidth($s, 0, 70, '…', 'UTF-8')) ?> → <?= e(mb_strimwidth($tr, 0, 70, '…', 'UTF-8')) ?></li><?php endforeach; ?></ul>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>

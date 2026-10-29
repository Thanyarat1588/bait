<?php
/**
 * BAIT - "สารสนเทศ" Page (external system links)
 * Split out of forms.php, which used to let a "form" be a link_url in
 * place of a file — an external system (ระบบทะเบียน, ระบบส่งเกรด, ฯลฯ) isn't
 * really the same kind of thing as a downloadable document, so it gets its
 * own page/table (info_links) instead.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/components.php';

$db   = Database::getInstance();
$lang = currentLang();
if (!in_array($lang, ['th', 'en'])) $lang = 'th';
$_SESSION['lang'] = $lang;
$t = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;

$audience = $_GET['audience'] ?? 'student';
if (!in_array($audience, ['student', 'staff'], true)) $audience = 'student';

$links = $db->fetchAll(
    "SELECT * FROM info_links WHERE audience = ? AND is_active = 1 ORDER BY display_order ASC",
    [$audience]
);

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Information Systems' : 'สารสนเทศ');
$pageTitle = $lang === 'en' ? 'Information Systems' : 'สารสนเทศ';
$metaDesc = $lang === 'en'
    ? 'Information systems and service links for students and staff of the Faculty of Business Administration and Information Technology, by category.'
    : 'ระบบสารสนเทศและลิงก์บริการต่างๆ สำหรับนักศึกษาและบุคลากรของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ แยกตามหมวดหมู่';
$activePage = 'info-links';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('สารสนเทศ')],
];
$audienceLabel = $audience === 'staff' ? $t('บุคลากร') : $t('นักศึกษา');
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('สารสนเทศ') ?> (<?= $audienceLabel ?>)</h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">

  <?php if ($links): ?>
  <div class="info-link-grid reveal">
    <?php foreach ($links as $l): ?>
    <a href="<?= e($l['link_url']) ?>" target="_blank" rel="noopener" class="info-link-card">
      <span class="info-link-icon">
        <?php if ($l['icon_image']): ?>
        <?= customIcon($l['icon_image']) ?>
        <?php else: ?>
        <?= icon($l['icon'] ?: 'link') ?>
        <?php endif; ?>
      </span>
      <span class="info-link-title"><?= e($t($l['title'])) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <?= icon('link') ?>
    <div><?= $t('ยังไม่มีรายการสารสนเทศในหมวดนี้') ?></div>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

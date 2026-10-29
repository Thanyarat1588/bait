<?php
/**
 * BAIT - Majors Listing Page (all สาขาวิชา, linked from the home page teaser)
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

$majors = $db->fetchAll(
    "SELECT m.*, (SELECT COUNT(*) FROM programs p WHERE p.major_id = m.major_id) AS program_count
     FROM majors m ORDER BY m.major_name ASC"
);

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Majors' : 'สาขาวิชา');

$pageTitle = $lang === 'en' ? 'Majors' : 'สาขาวิชา';
$metaDesc = $lang === 'en'
    ? 'All majors of the Faculty of Business Administration and Information Technology, with the programs and staff in each major.'
    : 'สาขาวิชาทั้งหมดของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ พร้อมหลักสูตรในแต่ละสาขาและบุคลากรที่สังกัด';
$activePage = 'majors';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('สาขาวิชา')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('สาขาวิชาทั้งหมด') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <h2 class="visually-hidden"><?= $t('รายการสาขาวิชา') ?></h2>
  <div class="grid grid-5">
    <?php foreach ($majors as $m): echo majorCard($m, $lang); endforeach; ?>
    <?php if (empty($majors)): ?>
    <div class="empty-state" style="grid-column:1/-1">
      <?= icon('graduation-cap') ?>
      <div><?= $t('ยังไม่มีสาขาวิชา') ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

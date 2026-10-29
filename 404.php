<?php
/**
 * BAIT - 404 Not Found (Phase 3 rebuild)
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

http_response_code(404);
$robotsMeta  = 'noindex,follow';   // a "not found" page (also used for a missing news/article/program id) is never indexed
$noCanonical = true;               // ... and names no canonical address of its own
$pageTitle = $lang === 'en' ? '404 - Page Not Found' : '404 - ไม่พบหน้าที่ต้องการ';
$activePage = '';
include __DIR__ . '/layout/header.php';
?>

<div class="container">
  <div class="status-hero reveal">
    <div class="status-code" aria-hidden="true">404</div>
    <div class="status-icon-badge"><?= icon('compass') ?></div>
    <h1 style="color:var(--primary);margin-bottom:10px"><?= $t('ไม่พบหน้าที่ต้องการ') ?></h1>
    <p class="text-muted" style="margin-bottom:28px">
      <?= $lang === 'en' ? 'The page you are looking for might have been moved or deleted.' : 'หน้าที่คุณกำลังค้นหาอาจถูกย้ายหรือลบออกแล้ว' ?>
    </p>
    <div class="cluster gap-3" style="justify-content:center">
      <a href="<?= BASE_URL ?>/?lang=<?= e($lang) ?>" class="btn btn-primary"><?= icon('home', 'icon-sm') ?> <?= $t('กลับหน้าหลัก') ?></a>
      <a href="<?= BASE_URL ?>/search.php?lang=<?= e($lang) ?>" class="btn btn-secondary"><?= icon('search', 'icon-sm') ?> <?= $t('ค้นหา') ?></a>
      <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" class="btn btn-secondary"><?= icon('mail', 'icon-sm') ?> <?= $t('ติดต่อเรา') ?></a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

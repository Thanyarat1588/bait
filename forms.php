<?php
/**
 * BAIT - Downloadable Forms Page
 * Two-step drill-down, same shape as info.php: the nav dropdown picks an
 * audience (นักศึกษา/บุคลากร); this page then shows that audience's
 * categories as icon cards first (same look as info.php's สารสนเทศ cards),
 * and only the files within a category once one is actually picked.
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
$catId = (int)($_GET['cat'] ?? 0);

// Only categories that actually have at least one active form for this
// audience — an empty category card would just be a dead end. Fetched
// either way: step 1 shows these as the picker, step 2 shows them again
// as a quick-switch row so visitors don't have to go back first.
$categories = $db->fetchAll(
    "SELECT c.*, COUNT(f.form_id) as form_count
     FROM form_categories c
     JOIN forms f ON f.category_id = c.category_id AND f.is_active = 1 AND f.audience = ?
     GROUP BY c.category_id
     ORDER BY c.category_id ASC",
    [$audience]
);

$category = null;
$forms = [];
$pager = null;
if ($catId) {
    $category = $db->fetchOne("SELECT * FROM form_categories WHERE category_id = ?", [$catId]);
    if ($category) {
        // Same LIMIT/OFFSET pagination pattern as news.php — any list on
        // this site long enough to need it gets cut to a page instead of
        // dumping everything at once.
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $formsTotal = (int)($db->fetchOne(
            "SELECT COUNT(*) as c FROM forms WHERE category_id = ? AND audience = ? AND is_active = 1",
            [$catId, $audience]
        )['c'] ?? 0);
        $pager = paginate($formsTotal, $perPage, $page, '?audience=' . e($audience) . '&cat=' . $catId . '&lang=' . e($lang) . '&page={page}');
        $forms = $db->fetchAll(
            "SELECT * FROM forms WHERE category_id = ? AND audience = ? AND is_active = 1
             ORDER BY form_id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            [$catId, $audience]
        );
    }
}

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Download Forms' : 'ดาวน์โหลดแบบฟอร์ม');
$pageTitle = $lang === 'en' ? 'Download Forms' : 'ดาวน์โหลดแบบฟอร์ม';
$metaDesc = $lang === 'en'
    ? 'Download forms and documents of the Faculty of Business Administration and Information Technology for students and staff, grouped by category.'
    : 'ดาวน์โหลดแบบฟอร์มและเอกสารต่างๆ ของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ สำหรับนักศึกษาและบุคลากร แยกตามหมวดหมู่';
$activePage = 'forms';
include __DIR__ . '/layout/header.php';

$audienceLabel = $audience === 'staff' ? $t('บุคลากร') : $t('นักศึกษา');
$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
];
if ($catId && $category) {
    $breadcrumbItems[] = ['label' => $t('ดาวน์โหลดแบบฟอร์ม') . ' (' . $audienceLabel . ')', 'url' => BASE_URL . '/forms.php?audience=' . e($audience) . '&lang=' . e($lang)];
    $breadcrumbItems[] = ['label' => $t($category['category_name'])];
} else {
    $breadcrumbItems[] = ['label' => $t('ดาวน์โหลดแบบฟอร์ม')];
}
?>

<section class="page-hero">
  <div class="container">
    <h1>
      <?= $t('ดาวน์โหลดแบบฟอร์ม') ?> (<?= $audienceLabel ?>)<?= ($catId && $category) ? ' — ' . e($t($category['category_name'])) : '' ?>
    </h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">

  <?php if (!$catId): ?>
  <!-- Step 1: pick a category -->
  <?php if ($categories): ?>
  <div class="section-header"><div><h2><?= $t('เลือกหมวดหมู่') ?></h2></div></div>
  <div class="info-link-grid reveal">
    <?php foreach ($categories as $c): ?>
    <a href="?audience=<?= e($audience) ?>&cat=<?= (int)$c['category_id'] ?>&lang=<?= e($lang) ?>" class="info-link-card">
      <span class="info-link-icon">
        <?php if ($c['image']): ?>
        <?= customIcon($c['image']) ?>
        <?php else: ?>
        <?= icon($c['icon'] ?: 'download') ?>
        <?php endif; ?>
      </span>
      <span class="info-link-title"><?= e($t($c['category_name'])) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <?= icon('download') ?>
    <div><?= $t('ยังไม่มีแบบฟอร์มสำหรับกลุ่มนี้') ?></div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- Step 2: files within the chosen category -->
  <a href="?audience=<?= e($audience) ?>&lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm" style="margin-bottom:16px">
    <?= icon('chevron-left', 'icon-sm') ?> <?= $t('กลับไปเลือกหมวดหมู่') ?>
  </a>

  <?php if (count($categories) > 1): ?>
  <div class="tab-pills" style="margin-bottom:24px">
    <?php foreach ($categories as $c): ?>
    <a href="?audience=<?= e($audience) ?>&cat=<?= (int)$c['category_id'] ?>&lang=<?= e($lang) ?>" class="tab-pill <?= (int)$c['category_id'] === $catId ? 'is-active' : '' ?>">
      <?= e($t($c['category_name'])) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!$category): ?>
  <div class="empty-state">
    <?= icon('download') ?>
    <div><?= $t('ไม่พบหมวดหมู่นี้') ?></div>
  </div>
  <?php elseif ($forms): ?>
  <div class="form-list reveal">
    <?php foreach ($forms as $f): $ext = strtoupper(pathinfo($f['file'], PATHINFO_EXTENSION)); ?>
    <a href="<?= UPLOAD_URL . '/' . e($f['file']) ?>" download class="form-file">
      <span class="value-icon" style="--value-color:var(--primary)"><?= icon('download') ?></span>
      <span class="form-file__body">
        <span class="form-file__name"><?= e($f['form_name']) ?></span>
        <span class="form-file__meta"><?= e($ext) ?> &middot; <?= $t('คลิกเพื่อดาวน์โหลด') ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($pager): ?>
  <div style="margin-top:24px"><?= paginationNav($pager) ?></div>
  <?php endif; ?>
  <?php else: ?>
  <div class="empty-state">
    <?= icon('download') ?>
    <div><?= $t('ยังไม่มีแบบฟอร์มในหมวดนี้') ?></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

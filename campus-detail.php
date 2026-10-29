<?php
/**
 * BAIT - Campus detail page (per-campus address/heads/contacts). The
 * campuses.php landing page is the interactive map that links here.
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

$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");

$contacts = $db->fetchAll("SELECT * FROM campus_contacts ORDER BY campus_id, contact_type");
$contactMap = [];
foreach ($contacts as $c) { $contactMap[$c['campus_id']][] = $c; }

// Who is shown under each campus: the deputy deans (position_level = 9, รองคณบดี — the คณบดี, level 10, belongs to the
// whole faculty) grouped by the campus they actually belong to (staff.campus_id, set on the staff form).
// Nothing is guessed from the responsibility text and the "หัวหน้าศูนย์" choice in the org chart is not used here.
$headsByCampus = [];
$heads = $db->fetchAll(
    "SELECT s.*, p.position_name
     FROM staff s JOIN positions p ON p.position_id = s.position_id
     WHERE " . staffCurrentSql('s') . " AND p.position_level = 9 AND s.campus_id IS NOT NULL
     ORDER BY s.display_order ASC, s.staff_id ASC"
);
foreach ($heads as $h) $headsByCampus[(int)$h['campus_id']][] = $h;
trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Campuses' : 'ศูนย์พื้นที่');
$pageTitle = $lang === 'en' ? 'Campus Details' : 'รายละเอียดศูนย์พื้นที่';
$metaDesc = $lang === 'en'
    ? 'Details of each campus: address, contact channels, campus executives and map, for the Faculty of Business Administration and Information Technology.'
    : 'ข้อมูลของแต่ละศูนย์พื้นที่ ที่อยู่ ช่องทางติดต่อ ผู้บริหารประจำศูนย์ และแผนที่ ของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ';

$schemaJson = json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',
    'name'     => 'BAIT Campuses',
    'itemListElement' => array_values(array_map(fn($c, $i) => [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'item'     => ['@type' => 'Place', 'name' => $c['campus_name'], 'address' => $c['address']],
    ], $campuses, array_keys($campuses))),
], JSON_UNESCAPED_UNICODE);

$activePage = 'info';
$activeSubPage = 'campuses';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('ศูนย์พื้นที่'), 'url' => BASE_URL . '/campuses.php?lang=' . $lang],
    ['label' => $t('รายละเอียดศูนย์')],
];

$contactIcons = [
    'phone'    => ['icon' => 'phone',   'label' => $t('โทรศัพท์')],
    'email'    => ['icon' => 'mail',    'label' => 'Email'],
    'facebook' => ['icon' => 'facebook','label' => 'Facebook'],
    'line'     => ['icon' => 'line',    'label' => 'Line'],
    'tiktok'   => ['icon' => 'tiktok',  'label' => 'TikTok'],
    'youtube'  => ['icon' => 'youtube', 'label' => 'YouTube'],
    'x'        => ['icon' => 'x',       'label' => 'X'],
    'instagram' => ['icon' => 'instagram', 'label' => 'Instagram'],
    'website'  => ['icon' => 'globe',   'label' => $t('เว็บไซต์')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('ศูนย์พื้นที่') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
    <p style="margin-top:10px"><a href="<?= BASE_URL ?>/campuses.php?lang=<?= e($lang) ?>" class="btn btn-on-dark btn-sm"><?= icon('chevron-left', 'icon-sm') ?> <?= $t('กลับไปแผนที่') ?></a></p>
  </div>
</section>

<div class="campus-tab-bar" role="navigation" aria-label="<?= e($t('เลือกศูนย์พื้นที่')) ?>">
  <div class="container">
    <?php foreach ($campuses as $c): ?>
    <a href="#campus-<?= (int)$c['campus_id'] ?>" class="tab-pill" data-campus-tab="campus-<?= (int)$c['campus_id'] ?>"><?= e($t($c['campus_name'])) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="container">
  <?php foreach ($campuses as $idx => $c):
    $cContacts = $contactMap[$c['campus_id']] ?? [];
    $cHeads    = $headsByCampus[$c['campus_id']] ?? [];
    $cName     = $t($c['campus_name']);
    $cAddress  = $c['address'] ? $t($c['address']) : '';
  ?>
  <section id="campus-<?= (int)$c['campus_id'] ?>" class="campus-section" data-campus-section>
    <div class="layout-with-sidebar campus-layout">

      <div class="reveal">
        <?php if ($c['image']):
          // Sized to this photo's own aspect ratio (not a fixed max-height
          // crop) so nothing gets cropped off — getimagesize() only reads
          // the file header, not the whole image, so this is cheap to do
          // on every render. Computed up front so the box reserves the
          // right height immediately instead of collapsing to 0 while the
          // (lazy-loaded) image itself is still downloading.
          $cDims = @getimagesize(UPLOAD_PATH . '/' . $c['image']);
          $cRatioStyle = $cDims ? ' style="aspect-ratio:' . $cDims[0] . '/' . $cDims[1] . '"' : '';
        ?>
        <img src="<?= UPLOAD_URL . '/' . e($c['image']) ?>" alt="<?= e($cName) ?>" class="campus-hero-img"<?= $cRatioStyle ?> loading="lazy" decoding="async"<?= imgSizeAttrs($c['image']) ?>>
        <?php else: ?>
        <div class="campus-hero-fallback"><?= icon('building', 'icon-lg') ?><p style="font-weight:700;margin:0"><?= e($cName) ?></p></div>
        <?php endif; ?>
      </div>

      <div class="reveal">
        <h2 class="campus-name-heading" style="color:var(--primary);margin-block:0 14px"><?= e($cName) ?></h2>

        <?php if ($cAddress): ?>
        <p class="text-muted cluster gap-2" style="align-items:flex-start;margin-bottom:16px"><?= icon('pin', 'icon-sm') ?> <span><?= e($cAddress) ?></span></p>
        <?php endif; ?>

        <?php if ($cHeads): ?>
        <div class="campus-head-row">
          <?php foreach ($cHeads as $cHead): ?>
          <div class="campus-head-card">
            <?php if ($cHead['image']): ?>
            <img src="<?= UPLOAD_URL . '/' . e($cHead['image']) ?>" class="campus-head-avatar" alt=""<?= imgSizeAttrs($cHead['image']) ?>>
            <?php else: ?>
            <div class="campus-head-avatar-fallback"><?= e(staffInitial($cHead, $lang)) ?></div>
            <?php endif; ?>
            <div style="min-width:0">
              <p style="margin:0;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(staffName($cHead, $lang)) ?></p>
              <small class="text-muted"><?= e($t($cHead['position_name'])) ?></small>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($cContacts): ?>
        <h3 class="fs-h4" style="color:var(--primary);margin-bottom:10px"><?= $t('ช่องทางติดต่อ') ?></h3>
        <div class="contact-chip-row" style="margin-bottom:16px">
          <?php foreach ($cContacts as $ct):
            $ci = $contactIcons[$ct['contact_type']] ?? ['icon' => 'link', 'label' => $ct['contact_type']];
            $href = contactHref($ct);
          ?>
          <a href="<?= e($href) ?>"<?= str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : '' ?> class="contact-chip"><?= icon($ci['icon'], 'icon-sm') ?> <?= e($ct['display_label'] ?? $ci['label']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($c['map_url']): ?>
        <a href="<?= e($c['map_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="margin-bottom:16px"><?= icon('pin', 'icon-sm') ?> <?= $t('เปิดแผนที่') ?></a>
        <?php endif; ?>

        <div class="cluster gap-2">
          <a href="<?= BASE_URL ?>/staff.php?view=campus&campus=<?= (int)$c['campus_id'] ?>&lang=<?= e($lang) ?>" class="btn btn-ghost btn-sm"><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?></a>
          <a href="<?= BASE_URL ?>/programs.php?campus=<?= (int)$c['campus_id'] ?>&lang=<?= e($lang) ?>" class="btn btn-ghost btn-sm"><?= icon('book', 'icon-sm') ?> <?= $t('หลักสูตร') ?></a>
        </div>
      </div>
    </div>
  </section>
  <?php endforeach; ?>

  <?php if (empty($campuses)): ?>
  <div class="empty-state"><?= icon('building') ?><div><?= $t('ยังไม่มีข้อมูลศูนย์พื้นที่') ?></div></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

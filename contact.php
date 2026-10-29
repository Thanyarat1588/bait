<?php
/**
 * BAIT - Contact Page
 * Shows contact details for all 4 campuses. There is no message-submission
 * feature here — the site does not collect/store contact messages.
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

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Contact' : 'ติดต่อเรา');
$pageTitle = $lang === 'en' ? 'Contact Us' : 'ติดต่อเรา';
$metaDesc = $lang === 'en'
    ? 'Contact the Faculty of Business Administration and Information Technology, RMUTSB: addresses, phone numbers and contact channels for every campus.'
    : 'ติดต่อคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ ที่อยู่ เบอร์โทรศัพท์ และช่องทางติดต่อของทุกศูนย์พื้นที่';

$schemaJson = json_encode([
    '@context' => 'https://schema.org', '@type' => 'ContactPage',
    'name' => 'Contact BAIT RMUTSB', 'url' => BASE_URL_ABSOLUTE . '/contact.php',
], JSON_UNESCAPED_UNICODE);

$activePage = 'contact';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('ติดต่อ')],
];
$contactIcons = [
    'phone' => 'phone', 'email' => 'mail', 'facebook' => 'facebook',
    'line' => 'line', 'tiktok' => 'tiktok', 'website' => 'globe', 'youtube' => 'youtube', 'x' => 'x', 'instagram' => 'instagram',
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('ติดต่อเรา') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">

  <div class="section-header">
    <div>
      <h2 style="color:var(--primary)"><?= $t('ศูนย์พื้นที่ของเรา') ?></h2>
      <p><?= $t('เข้าเยี่ยมชมหรือติดต่อผ่านศูนย์พื้นที่ทั้ง 4 แห่ง') ?></p>
    </div>
  </div>

  <div class="stack gap-6 reveal">
    <?php foreach ($campuses as $c): $cContacts = $contactMap[$c['campus_id']] ?? []; $cName = e($t($c['campus_name'])); ?>
    <div class="card contact-campus-card">
      <div class="card-thumb">
        <?php if (!empty($c['image'])): ?>
        <img src="<?= UPLOAD_URL . '/' . e($c['image']) ?>" alt="<?= $cName ?>" loading="lazy" decoding="async">
        <?php else: ?>
        <div class="card-thumb-fallback"><?= icon('building') ?></div>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <h3 class="fs-h4" style="color:var(--primary);margin-bottom:4px"><?= $cName ?></h3>
        <?php if ($c['address']): ?>
        <p class="text-muted" style="font-size:.84rem;margin-bottom:8px"><?= icon('pin', 'icon-sm') ?> <?= e($t($c['address'])) ?></p>
        <?php endif; ?>
        <div class="contact-chip-row">
          <?php if ($c['map_url']): ?>
          <a href="<?= e($c['map_url']) ?>" target="_blank" rel="noopener" class="contact-chip"><?= icon('pin', 'icon-sm') ?> Map</a>
          <?php endif; ?>
          <?php foreach ($cContacts as $ct):
            $href = contactHref($ct);
          ?>
          <a href="<?= e($href) ?>"<?= str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : '' ?> class="contact-chip"><?= icon($contactIcons[$ct['contact_type']] ?? 'link', 'icon-sm') ?> <?= e($ct['display_label'] ?? (['youtube' => 'YouTube', 'x' => 'X', 'tiktok' => 'TikTok', 'instagram' => 'Instagram'][$ct['contact_type']] ?? ucfirst($ct['contact_type']))) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="quick-info-box reveal">
    <h2 class="fs-h4" style="color:var(--primary);margin-bottom:12px"><?= icon('help-circle', 'icon-sm') ?> <?= $t('ข้อมูลด่วน') ?></h2>
    <div class="cluster gap-2">
      <a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= icon('book', 'icon-sm') ?> <?= $t('หลักสูตร') ?></a>
      <a href="<?= BASE_URL ?>/staff.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?></a>
      <a href="<?= BASE_URL ?>/about.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= icon('info', 'icon-sm') ?> <?= $t('เกี่ยวกับ') ?></a>
      <a href="<?= BASE_URL ?>/news.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= icon('newspaper', 'icon-sm') ?> <?= $t('ข่าวสาร') ?></a>
    </div>
  </div>

</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

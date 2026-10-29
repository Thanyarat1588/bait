<?php
/**
 * BAIT - Home Page (Phase 3 rebuild — framework-free frontend)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/components.php';


$db   = Database::getInstance();
$lang = currentLang();
if (!in_array($lang, ['th', 'en'])) $lang = 'th';
$_SESSION['lang'] = $lang;
$t = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;

// ---- Promo video block (admin: วิดีโอโปรโมท). Skipped quietly until the home_promo table exists. ----
$promoRow = null;
try { if ($db->fetchOne("SHOW TABLES LIKE 'home_promo'")) $promoRow = $db->fetchOne("SELECT * FROM home_promo WHERE is_active = 1 ORDER BY promo_id LIMIT 1"); } catch (Throwable $e) { $promoRow = null; }
$promo = $promoRow ? promoOptions($promoRow) : null;
$promoMedia = null;
if ($promo) {
    if ($promo['video_source'] === 'upload' && $promo['video_file'] !== '') $promoMedia = ['kind' => 'video', 'src' => UPLOAD_URL . '/' . $promo['video_file']];
    elseif ($promo['video_source'] === 'url') $promoMedia = promoEmbed($promo['video_url'], (bool)$promo['autoplay']);
    if (!$promoMedia) $promo = null;   // nothing playable -> no empty box on the home page
}

// ---- Data (unchanged from the previous build) ----
$banners = $db->fetchAll("SELECT * FROM banners WHERE is_active=1 ORDER BY display_order ASC LIMIT 6");
$latestNews = $db->fetchAll(
    "SELECT n.news_id, n.title, n.image, n.slug, n.created_at, n.view_count, c.category_name, c.color
     FROM news n JOIN categories c ON c.category_id = n.category_id
     WHERE n.status='เผยแพร่' ORDER BY n.created_at DESC LIMIT 3"
);
$latestNews = attachNewsSdgs($latestNews);   // SDG chips on the cards
// Home page only teases the first 5 majors — "สาขาวิชาทั้งหมด" below links
// to majors.php for the full list, same pattern as the news section's
// "ดูข่าวสารทั้งหมด". Ordered oldest-first (not alphabetically) so a newly
// added major lands at the back of the queue instead of possibly bumping
// an existing one out of the teaser by landing earlier in the alphabet.
$majorsTotal = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM majors")['c'] ?? 0);
$majors = $db->fetchAll(
    "SELECT m.*, (SELECT COUNT(*) FROM programs p WHERE p.major_id = m.major_id) AS program_count
     FROM majors m ORDER BY m.created_at ASC, m.major_id ASC LIMIT 5"
);
$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$faculty  = $db->fetchOne("SELECT * FROM faculty LIMIT 1") ?: [];

// "จุดเด่นของคณะ" step-path infographic — admin-managed via
// admin/modules/highlights (icon fallback + optional image/video per item).
$highlights = $db->fetchAll("SELECT * FROM highlights WHERE is_active = 1 ORDER BY display_order ASC");

// Homepage popup ad — only the first active row is ever shown; dismissal
// is remembered per-session (sessionStorage, see the JS below) so it
// doesn't reappear on every page within the same visit.
$popup = $db->fetchOne("SELECT * FROM popup_ads WHERE is_active = 1 ORDER BY display_order ASC LIMIT 1");

$heroFallbackImg = null;
foreach ($campuses as $c) { if (!empty($c['image'])) { $heroFallbackImg = $c['image']; break; } }

$partners = $db->fetchAll("SELECT * FROM partners WHERE is_active = 1 ORDER BY display_order ASC");

// "ภาพกิจกรรมของคณะ" should only be photos from actual event/activity news
// (category = กิจกรรม), not every news item that happens to carry an image.
$galleryImages = $db->fetchAll(
    "SELECT n.news_id, n.title, n.image FROM news n
     JOIN categories c ON c.category_id = n.category_id
     WHERE n.status='เผยแพร่' AND c.category_name = 'กิจกรรม'
       AND n.image IS NOT NULL AND n.image <> '' ORDER BY n.created_at DESC LIMIT 5"
);

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Home' : 'หน้าหลัก');

$pageTitle = $lang === 'en' ? 'Faculty of Business Administration & IT' : null;
$metaDesc = $lang === 'en'
    ? 'Faculty of Business Administration and IT, RMUTSB. Diploma, bachelor\'s and master\'s programs across 4 campuses, with news and admissions.'
    : 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มทร.สุวรรณภูมิ เปิดสอนหลักสูตร ปวส. ปริญญาตรี และปริญญาโท ใน 4 ศูนย์พื้นที่ พร้อมข่าวสารและข้อมูลการรับสมัคร';
// ตั้งค่าเว็บไซต์ > คำอธิบายเว็บไซต์ (Meta Description): when the admin wrote one, the home page uses it (Google's line under the
// title, and the text of a shared link); the English page gets it translated by layout/header.php. Empty = the text above.
$siteDesc = trim((string)($db->fetchOne('SELECT meta_desc FROM site_settings ORDER BY setting_id LIMIT 1')['meta_desc'] ?? ''));
if ($siteDesc !== '') $metaDesc = $siteDesc;
$orgSchemaFull = true;
$schemaJson = json_encode(orgSchema($db, $lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$activePage = 'home';
include __DIR__ . '/layout/header.php';
?>

<?php if ($popup): ?>
<div class="popup-ad-overlay" id="popupAdOverlay" data-popup-id="<?= (int)$popup['popup_id'] ?>" role="dialog" aria-modal="true" aria-label="<?= e($popup['title'] ? $t($popup['title']) : $t('ประกาศจากคณะ')) ?>">
  <div class="popup-ad-box">
    <button type="button" class="popup-ad-close" id="popupAdClose" aria-label="<?= $t('ปิด') ?>">&times;</button>
    <?php if (!empty($popup['link_url'])): ?>
    <a href="<?= e($popup['link_url']) ?>" target="_blank" rel="noopener">
      <img src="<?= UPLOAD_URL . '/' . e($popup['image']) ?>" alt="<?= e($popup['title'] ? $t($popup['title']) : '') ?>" class="popup-ad-img"<?= imgSizeAttrs($popup['image']) ?>>
    </a>
    <?php else: ?>
    <img src="<?= UPLOAD_URL . '/' . e($popup['image']) ?>" alt="<?= e($popup['title'] ? $t($popup['title']) : '') ?>" class="popup-ad-img"<?= imgSizeAttrs($popup['image']) ?>>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ============ HERO SLIDER ============ -->
<?php $hasRealBanners = !empty($banners); ?>
<?php if ($hasRealBanners): ?><h1 class="visually-hidden"><?= $lang === 'en' ? 'Faculty of Business Administration and Information Technology' : 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ' ?></h1><?php endif; ?>
<section class="hero <?= $hasRealBanners ? 'hero-banner-only' : '' ?>" data-hero-slider data-interval="6000" aria-roledescription="carousel" aria-label="<?= $t('ภาพเด่นหน้าแรก') ?>">
  <div class="hero-track">
    <?php if ($hasRealBanners): foreach ($banners as $i => $b): ?>
    <div class="hero-slide <?= $i === 0 ? 'is-active' : '' ?>">
      <?php if (!empty($b['link_url'])): ?>
      <a href="<?= e($b['link_url']) ?>" target="_blank" rel="noopener" aria-label="<?= e(!empty($b['title']) ? $t($b['title']) : 'Banner') ?>">
        <img src="<?= UPLOAD_URL . '/' . e($b['image']) ?>" alt="<?= e(!empty($b['title']) ? $t($b['title']) : 'Banner ' . $b['display_order']) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" decoding="async"<?= imgSizeAttrs($b['image']) ?><?= $i === 0 ? ' fetchpriority="high"' : '' ?>>
      </a>
      <?php else: ?>
      <img src="<?= UPLOAD_URL . '/' . e($b['image']) ?>" alt="<?= e(!empty($b['title']) ? $t($b['title']) : 'Banner ' . $b['display_order']) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" decoding="async"<?= imgSizeAttrs($b['image']) ?><?= $i === 0 ? ' fetchpriority="high"' : '' ?>>
      <?php endif; ?>
    </div>
    <?php endforeach; elseif ($heroFallbackImg): ?>
    <div class="hero-slide is-active"><img src="<?= UPLOAD_URL . '/' . e($heroFallbackImg) ?>" alt="BAIT" loading="eager" decoding="async"<?= imgSizeAttrs($heroFallbackImg) ?>></div>
    <?php else: ?>
    <div class="hero-slide is-active"><div class="hero-fallback"></div></div>
    <?php endif; ?>
  </div>

  <?php if (!$hasRealBanners): ?>
  <!-- Text overlay only appears on the plain fallback photo/gradient —
       real admin-uploaded banners are already complete marketing graphics
       (they carry their own headline + a clickable link_url) and must not
       have a second layer of text stacked on top of them. -->
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <div class="container">
      <div class="reveal">
        <span class="eyebrow hero-eyebrow"><?= $lang === 'en' ? 'FACULTY OF BUSINESS ADMINISTRATION AND INFORMATION TECHNOLOGY' : 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ' ?></span>
        <h1><?= $lang === 'en' ? 'Shape Your Future with BAIT' : 'ก้าวสู่อนาคตไปกับ BAIT' ?></h1>
        <p><?= $lang === 'en'
              ? 'Rajamangala University of Technology Suvarnabhumi — quality business and technology education across 4 campuses.'
              : 'มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ การศึกษาด้านบริหารธุรกิจและเทคโนโลยีสารสนเทศคุณภาพ ครอบคลุม 4 ศูนย์พื้นที่' ?></p>
        <div class="hero-ctas">
          <a href="https://admis.rmutsb.ac.th/admissions" target="_blank" rel="noopener" class="btn btn-accent"><?= $t('สมัครเรียน') ?></a>
          <a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" class="btn btn-on-dark"><?= $t('ดูหลักสูตร') ?></a>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (count($banners) > 1): ?>
  <button type="button" class="hero-arrow hero-arrow-prev" data-hero-prev aria-label="<?= $lang === 'en' ? 'Previous slide' : 'ภาพก่อนหน้า' ?>"><?= icon('chevron-left') ?></button>
  <button type="button" class="hero-arrow hero-arrow-next" data-hero-next aria-label="<?= $lang === 'en' ? 'Next slide' : 'ภาพถัดไป' ?>"><?= icon('chevron-right') ?></button>
  <button type="button" class="hero-pause" data-hero-pause aria-label="<?= $lang === 'en' ? 'Pause slideshow' : 'หยุดสไลด์' ?>"></button>
  <div class="hero-dots" data-hero-dots></div>
  <?php endif; ?>
</section>

<?php
// Keyword marquee under the hero — words, colours and motion are set in admin → จัดการแบนเนอร์.
$mqOpt = marqueeOptions($settings['marquee_options'] ?? null);
$mq    = marqueeRender($mqOpt, $lang);
if ($mqOpt['enabled'] && $mq['items']):
?>
<div class="marquee marquee--<?= e($mqOpt['size']) ?> mq-mk-<?= e($mqOpt['marker']) ?><?= $mqOpt['pause'] ? ' mq-pause' : '' ?>" role="presentation" data-pxs="<?= (int)$mq['pxs'] ?>"
     style="--mq-bg:<?= e($mqOpt['bg']) ?>;--mq-fg:<?= e($mqOpt['fg']) ?>;--mq-mark:<?= e($mqOpt['mark_color']) ?>;--mq-dur:<?= (int)$mq['duration'] ?>s;--mq-dir:<?= $mqOpt['direction'] === 'right' ? 'reverse' : 'normal' ?>">
  <div class="marquee-track">
    <?php for ($g = 0; $g < 2; $g++): ?>
    <ul class="marquee-group" <?= $g ? 'aria-hidden="true"' : '' ?>>
      <?php for ($r = 0; $r < $mq['reps']; $r++) foreach ($mq['items'] as $w): ?><li<?= $r ? ' class="is-dup"' : '' ?>><?= e($w) ?></li><?php endforeach; ?>
    </ul>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($hasRealBanners): ?>
<!-- Quick-action bar under the banner-only hero (no stats — a public page
     isn't the place for internal headcount numbers, and "0 students" reads
     as broken rather than informative when student_counts hasn't been
     filled in yet). -->
<div class="container">
  <div class="cluster gap-3 reveal" style="justify-content:center;padding-block:28px">
    <a href="https://admis.rmutsb.ac.th/admissions" target="_blank" rel="noopener" class="btn btn-accent"><?= $t('สมัครเรียน') ?></a>
    <a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" class="btn btn-secondary"><?= $t('ดูหลักสูตร') ?></a>
    <a href="<?= BASE_URL ?>/tuition.php?lang=<?= e($lang) ?>" class="btn btn-tuition"><?= $lang === 'en' ? 'Tuition fees' : 'ค่าเทอม' ?></a>
  </div>
</div>
<?php endif; ?>

<?php if ($promo):
    $pTx = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;
    $pHeading = $promo['heading'] !== '' ? $pTx($promo['heading']) : '';
    $pParas = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', str_replace("\r", '', $promo['body']))), fn($x) => $x !== ''));
    $pStyle = '--promo-h:' . (int)$promo['heading_size'] . 'px;--promo-b:' . (int)$promo['body_size'] . 'px;--promo-align:' . $promo['text_align'] . ';--promo-valign:' . ($promo['v_align'] === 'center' ? 'center' : 'flex-' . $promo['v_align']);
    if ($promo['text_color'] !== '') $pStyle .= ';--promo-fg:' . $promo['text_color'];
    if ($promo['bg_color'] !== '')   $pStyle .= ';--promo-bg:' . $promo['bg_color'];
    $pLogo = $promo['logo_mode'] === 'site' ? BASE_URL . '/assets/img/logo-full-sm.png' : ($promo['logo_mode'] === 'upload' && $promo['logo'] !== '' ? UPLOAD_URL . '/' . $promo['logo'] : '');
    $pStyle .= ';--promo-logo:' . (int)$promo['logo_size'] . 'px';
    $pLogoDim = '';   // real pixel size -> the browser reserves the right height before the picture loads (no layout jump)
    if ($pLogo !== '') { $pf = $promo['logo_mode'] === 'site' ? __DIR__ . '/assets/img/logo-full-sm.png' : UPLOAD_PATH . '/' . $promo['logo']; $pi = @getimagesize($pf); if ($pi) $pLogoDim = ' width="' . (int)$pi[0] . '" height="' . (int)$pi[1] . '"'; }
    $hasText = $pHeading !== '' || $pParas || $pLogo !== '' || ($promo['btn_label'] !== '' && $promo['btn_url'] !== '');
?>
<!-- ============ PROMO VIDEO ============ -->
<section class="section promo-section" aria-label="<?= e($pHeading !== '' ? $pHeading : $t('วิดีโอแนะนำคณะ')) ?>">
  <div class="container">
    <div class="promo promo--video-<?= e($promo['video_side']) ?><?= $hasText ? '' : ' promo--solo' ?><?= $promo['bg_color'] !== '' ? ' has-bg' : '' ?><?= $promo['text_color'] !== '' ? ' has-fg' : '' ?>" style="<?= e($pStyle) ?>">
      <div class="promo-media">
        <?php if ($promoMedia['kind'] === 'iframe'): ?>
        <iframe src="<?= e($promoMedia['src']) ?>" title="<?= e($pHeading !== '' ? $pHeading : 'Video') ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe>
        <?php else: ?>
        <video src="<?= e($promoMedia['src']) ?>" controls playsinline preload="metadata"<?= $promo['poster'] !== '' ? ' poster="' . e(UPLOAD_URL . '/' . $promo['poster']) . '"' : '' ?><?= $promo['autoplay'] ? ' autoplay muted loop' : '' ?>></video>
        <?php endif; ?>
      </div>
      <?php if ($hasText): ?>
      <div class="promo-text">
        <?php if ($pLogo !== ''): ?><p class="promo-logo"><img src="<?= e($pLogo) ?>" alt="<?= e($siteAbbr ?? 'BAIT') ?>"<?= $pLogoDim ?> decoding="async"></p><?php endif; ?>
        <?php if ($pHeading !== ''): ?><h2 class="promo-heading"><?= e($pHeading) ?></h2><?php endif; ?>
        <?php foreach ($pParas as $para): ?><p class="promo-body"><?= nl2br(e($pTx($para))) ?></p><?php endforeach; ?>
        <?php if ($promo['btn_label'] !== '' && $promo['btn_url'] !== ''): ?>
        <p class="promo-action"><a class="btn btn-primary" href="<?= e($promo['btn_url']) ?>"<?= str_starts_with($promo['btn_url'], (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ? '' : ' target="_blank" rel="noopener"' ?>><?= e($pTx($promo['btn_label'])) ?> <?= icon('arrow-right', 'icon-sm') ?></a></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ LATEST NEWS ============ -->
<section class="section">
  <div class="container">
    <div class="section-header">
      <div>
        <h2><?= $lang === 'en' ? 'Latest News' : 'ข่าวเด่น / ข่าวล่าสุด' ?></h2>
        <p><?= $lang === 'en' ? 'Stay informed with the latest updates from BAIT' : 'ติดตามข่าวสารและกิจกรรมล่าสุดจากคณะ' ?></p>
      </div>
      <a href="<?= BASE_URL ?>/news.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= $t('ดูข่าวสารทั้งหมด') ?> <?= icon('arrow-right', 'icon-sm') ?></a>
    </div>
    <div class="grid grid-3">
      <?php foreach ($latestNews as $n): echo newsCard($n, $lang); endforeach; ?>
      <?php if (empty($latestNews)): ?>
      <div class="empty-state" style="grid-column:1/-1"><?= $t('ยังไม่มีข่าวสาร') ?></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ============ MAJORS ============ -->
<section class="section section-alt">
  <div class="container">
    <div class="section-header">
      <div>
        <h2><?= $lang === 'en' ? 'Our Majors' : 'สาขาวิชาทั้งหมด' ?></h2>
        <p><?= $lang === 'en' ? 'Explore our diverse academic majors' : 'สาขาวิชาที่หลากหลายพร้อมสำหรับอนาคตของคุณ' ?></p>
      </div>
      <?php if ($majorsTotal > 5): ?>
      <a href="<?= BASE_URL ?>/majors.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= $t('ดูสาขาวิชาทั้งหมด') ?> <?= icon('arrow-right', 'icon-sm') ?></a>
      <?php endif; ?>
    </div>
    <div class="grid grid-5">
      <?php foreach ($majors as $m): echo majorCard($m, $lang); endforeach; ?>
      <?php if (empty($majors)): ?>
      <div class="empty-state" style="grid-column:1/-1"><?= $t('ยังไม่มีสาขาวิชา') ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:center;margin-top:32px" class="reveal">
      <a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" class="btn btn-primary"><?= $t('หลักสูตรทั้งหมด') ?> <?= icon('arrow-right', 'icon-sm') ?></a>
    </div>
  </div>
</section>

<!-- ============ WHY BAIT ============ -->
<section class="section">
  <div class="container">
    <div class="section-header center">
      <h2><?= $lang === 'en' ? 'Why Choose BAIT' : 'จุดเด่นของคณะ' ?></h2>
      <p><?= $lang === 'en' ? 'What makes a BAIT education different' : 'สิ่งที่ทำให้การเรียนที่ BAIT แตกต่าง' ?></p>
    </div>
    <!-- Cards in a row, same layout as the original — content is now
         admin-managed via admin/modules/highlights, so each card shows
         its uploaded image/video when set, otherwise falls back to the
         icon (see highlightCard() in includes/components.php). -->
    <div class="grid grid-5">
      <?php foreach ($highlights as $h): echo highlightCard($h, $lang); endforeach; ?>
      <?php if (empty($highlights)): ?>
      <div class="empty-state" style="grid-column:1/-1"><?= $t('ยังไม่มีจุดเด่น') ?></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ============ CAMPUSES ============ -->
<section class="section section-alt">
  <div class="container">
    <div class="section-header center">
      <h2><?= $lang === 'en' ? 'Our Campuses' : 'ศูนย์พื้นที่' ?></h2>
      <p><?= $lang === 'en' ? count($campuses) . ' campuses across central Thailand' : count($campuses) . ' ศูนย์พื้นที่ ครอบคลุมภาคกลางของประเทศ' ?></p>
    </div>
    <div class="grid grid-4">
      <?php foreach ($campuses as $c): echo campusCard($c, $lang); endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ ABOUT BAIT ============ -->
<?php $aboutBg = aboutBgAttrs($settings['about_bg'] ?? null);   // a background photo chosen in the admin (ตั้งค่าเว็บไซต์ > พื้นหลัง "เกี่ยวกับคณะ") ?>
<section class="section about-band<?= $aboutBg['class'] ?>"<?= $aboutBg['style'] ?>>
  <div class="container">
    <div class="about-split">
      <div class="reveal">
        <span class="eyebrow"><?= $t('เกี่ยวกับเรา') ?></span>
        <h2 class="about-band-title"><?= $t('เกี่ยวกับคณะ') ?></h2>

        <?php
        // วิสัยทัศน์/พันธกิจ moved out of the `faculty` table into the
        // admin-managed, freely-reorderable about_sections list (see
        // admin/modules/faculty/index.php + about.php) — looked up here by
        // their fixed `slug` rather than a title match, so this teaser
        // keeps working even if an admin renames the section's heading.
        // Rich-text-editor content carries literal entities like &nbsp;
        // alongside real tags — strip_tags() only removes the tags, so
        // decoding entities first keeps e() from re-escaping a leftover
        // "&nbsp;" into visible "&amp;nbsp;" text on the page.
        $visionRow    = $db->fetchOne("SELECT content FROM about_sections WHERE slug = 'vision' AND is_active = 1 LIMIT 1");
        $missionRow   = $db->fetchOne("SELECT content FROM about_sections WHERE slug = 'mission' AND is_active = 1 LIMIT 1");
        $visionPlain  = !empty($visionRow['content'])  ? html_entity_decode(strip_tags($visionRow['content']), ENT_QUOTES, 'UTF-8')  : '';
        $missionPlain = !empty($missionRow['content']) ? html_entity_decode(strip_tags($missionRow['content']), ENT_QUOTES, 'UTF-8') : '';
        ?>
        <?php if ($visionPlain): ?>
        <div class="about-block">
          <h3><?= icon('eye') ?> <?= $t('วิสัยทัศน์') ?></h3>
          <p class="text-muted"><?= e(mb_strimwidth($t($visionPlain), 0, 150, '...', 'UTF-8')) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($missionPlain): ?>
        <div class="about-block">
          <h3><?= icon('target') ?> <?= $t('พันธกิจ') ?></h3>
          <p class="text-muted"><?= e(mb_strimwidth($t($missionPlain), 0, 150, '...', 'UTF-8')) ?></p>
        </div>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/about.php?lang=<?= e($lang) ?>" class="btn btn-primary"><?= $t('อ่านเพิ่มเติม') ?><span class="visually-hidden">: <?= $t('เกี่ยวกับคณะ') ?></span></a>
      </div>
      <div class="reveal">
        <?php if (!empty($faculty['logo'])): ?>
        <div class="about-logo-showcase">
          <img src="<?= UPLOAD_URL . '/' . e($faculty['logo']) ?>" alt="<?= e(($lang === 'en' ? ($faculty['faculty_name_en'] ?? '') : ($faculty['faculty_name_th'] ?? '')) ?: 'BAIT') ?>" loading="lazy" decoding="async"<?= imgSizeAttrs($faculty['logo']) ?>>
        </div>
        <?php elseif ($heroFallbackImg): ?>
        <img src="<?= UPLOAD_URL . '/' . e($heroFallbackImg) ?>" class="about-img" alt="BAIT" loading="lazy" decoding="async"<?= imgSizeAttrs($heroFallbackImg) ?>>
        <?php else: ?>
        <div class="about-img about-img-fallback"><?= icon('graduation-cap') ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============ GALLERY ============ -->
<?php if (!empty($galleryImages)): ?>
<section class="section section-alt">
  <div class="container">
    <div class="section-header center">
      <h2><?= $lang === 'en' ? 'Faculty Life in Pictures' : 'ภาพกิจกรรมของคณะ' ?></h2>
      <p><?= $lang === 'en' ? 'A glimpse into life at BAIT' : 'ภาพบรรยากาศและกิจกรรมต่าง ๆ ของคณะ' ?></p>
    </div>
    <div class="gallery-grid reveal">
      <?php foreach ($galleryImages as $g): ?>
      <a href="<?= BASE_URL ?>/news-detail.php?id=<?= (int)$g['news_id'] ?>&lang=<?= e($lang) ?>" class="gallery-item">
        <img src="<?= UPLOAD_URL . '/' . e($g['image']) ?>" alt="<?= e($t($g['title'])) ?>" loading="lazy" decoding="async"<?= imgSizeAttrs($g['image']) ?>>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ PARTNERS ============ -->
<?php if (!empty($partners)): ?>
<section class="section">
  <div class="container">
    <div class="section-header center">
      <h2><?= $lang === 'en' ? 'Our Partners' : 'พันธมิตรของเรา' ?></h2>
      <p><?= $lang === 'en' ? 'Organizations we work with for co-op education and career placement' : 'องค์กรที่ร่วมมือด้านสหกิจศึกษาและการจ้างงาน' ?></p>
    </div>
    <div class="partner-strip reveal">
      <?php foreach ($partners as $p): ?>
      <?php if (!empty($p['link_url'])): ?>
      <a href="<?= e($p['link_url']) ?>" target="_blank" rel="noopener" class="partner-tile" title="<?= e($p['name']) ?>">
        <img src="<?= UPLOAD_URL . '/' . e($p['logo']) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async"<?= imgSizeAttrs($p['logo']) ?>>
      </a>
      <?php else: ?>
      <div class="partner-tile" title="<?= e($p['name']) ?>">
        <img src="<?= UPLOAD_URL . '/' . e($p['logo']) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async"<?= imgSizeAttrs($p['logo']) ?>>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ CTA BAND ============ -->
<section class="cta-band">
  <div class="container reveal">
    <h2><?= $lang === 'en' ? 'Ready to Join BAIT?' : 'พร้อมเป็นส่วนหนึ่งของ BAIT แล้วหรือยัง?' ?></h2>
    <p><?= $lang === 'en' ? 'Take the next step in your education journey with us' : 'ก้าวต่อไปในเส้นทางการศึกษาของคุณไปกับเรา' ?></p>
    <div class="cluster gap-3" style="justify-content:center">
      <a href="https://admis.rmutsb.ac.th/admissions" target="_blank" rel="noopener" class="btn btn-pill cta-btn cta-btn--apply"><?= icon('graduation-cap', 'icon-sm') ?> <?= $t('สมัครเรียน') ?></a>
      <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" class="btn btn-pill cta-btn cta-btn--contact"><?= icon('phone', 'icon-sm') ?> <?= $t('ติดต่อเรา') ?></a>
      <a href="<?= BASE_URL ?>/about.php?lang=<?= e($lang) ?>" class="btn btn-pill cta-btn cta-btn--visit"><?= icon('pin', 'icon-sm') ?> <?= $t('เยี่ยมชมคณะ') ?></a>
    </div>
  </div>
</section>

<?php
$extraJs = '
<script>
(function () {
  const overlay = document.getElementById("popupAdOverlay");
  if (!overlay) return;
  const key = "popupAdDismissed_" + overlay.dataset.popupId;
  // storage can be blocked (private mode, strict privacy settings): the pop-up then simply shows on each visit, it never breaks the page
  let seen = false; try { seen = !!sessionStorage.getItem(key); } catch (e) {}
  if (seen) { overlay.remove(); return; }
  // the mourning pop-up (if any) is shown first; this announcement waits until it is closed (assets/js/mourning.js fires the event)
  let started = false;
  function start() {
    if (started) return;
    started = true;
    show();
  }
  if (document.getElementById("mourningPopup") && !window.baitMourningDone) {
    document.addEventListener("bait:mourning-done", start, { once: true });
    setTimeout(() => { if (!document.documentElement.classList.contains("has-mourning-popup")) start(); }, 4000);   // safety net: the script failed to load, so do not hide the announcement for ever
    return;
  }
  start();
  function show() {
  const opener = document.activeElement;
  const closeBtn = document.getElementById("popupAdClose");
  let closed = false;
  function dismiss() {
    if (closed) return;
    closed = true;
    overlay.classList.remove("is-visible");
    try { sessionStorage.setItem(key, "1"); } catch (e) {}
    setTimeout(() => overlay.remove(), 300);
    if (opener && opener.focus) opener.focus();   // hand the keyboard back to where the visitor was
  }
  requestAnimationFrame(() => {
    overlay.classList.add("is-visible");
    setTimeout(() => { if (!closed) closeBtn.focus(); }, 350);   // after the fade-in: a hidden element cannot take focus
  });
  closeBtn.addEventListener("click", dismiss);
  overlay.addEventListener("click", (e) => { if (e.target === overlay) dismiss(); });
  document.addEventListener("keydown", (e) => {
    if (closed) return;
    if (e.key === "Escape") { dismiss(); return; }
    if (e.key === "Tab") {   // keep Tab inside the popup while it is open
      const items = overlay.querySelectorAll("a[href], button");
      if (!items.length) return;
      const first = items[0], last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
      else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
    }
  });
  }
})();
</script>
<script>
(function () {
  const bar = document.querySelector(".marquee");
  if (!bar || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  const groups = bar.querySelectorAll(".marquee-group");
  if (groups.length < 2) return;
  // Measure the real text width (fonts differ per device) and repeat the words until each group is wider than the screen,
  // so the strip never shows a gap; the loop time follows the real width so the chosen speed stays true.
  function fit() {
    groups.forEach((g) => g.querySelectorAll("li.is-dup").forEach((li) => li.remove()));
    let pass = 0;
    Array.from(groups[0].children).forEach((li) => { pass += li.getBoundingClientRect().width; });
    if (!pass) return;
    const reps = Math.max(1, Math.ceil((Math.max(window.innerWidth, 1200) + pass) / pass));
    groups.forEach((g) => {
      const src = Array.from(g.children);
      for (let r = 1; r < reps; r++) src.forEach((li) => { const c = li.cloneNode(true); c.classList.add("is-dup"); g.appendChild(c); });
    });
    bar.style.setProperty("--mq-dur", Math.max(8, Math.round(reps * pass / (+bar.dataset.pxs || 50))) + "s");
  }
  let t;
  const later = () => { clearTimeout(t); t = setTimeout(fit, 200); };
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
  window.addEventListener("resize", later);
})();
</script>';
include __DIR__ . '/layout/footer.php';
?>

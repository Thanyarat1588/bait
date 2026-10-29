<?php
/**
 * BAIT Frontend — Layout Header (Phase 3 rebuild)
 * Framework-free: no Bootstrap, no jQuery. Expects the including page to
 * have already required config/database.php, includes/Database.php,
 * includes/helpers.php and set $db, $lang before including this file.
 * Optional page variables: $pageTitle, $metaDesc, $metaKeywords, $ogImage,
 * $schemaJson, $activePage, $activeSubPage, $breadcrumbItems.
 */

if (!isset($db)) {
    require_once BASE_PATH . '/includes/Database.php';
    $db = Database::getInstance();
}
require_once BASE_PATH . '/includes/helpers.php';
require_once BASE_PATH . '/includes/components.php';

$lang = currentLang();
if (!in_array($lang, ['th', 'en'])) $lang = 'th';
$_SESSION['lang'] = $lang;

// Translation debug panel (?i18n=1 on an English page, drawn by layout/footer.php): signed-in admins only.
// A read-only look at the admin session, done here because it must happen before any output.
$i18nOk = false;
if (isset($_GET['i18n']) && $lang === 'en' && defined('SESSION_NAME') && isset($_COOKIE[SESSION_NAME]) && session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    @session_start(['read_and_close' => true]);
    $i18nOk = !empty($_SESSION['user_id']);
    $_SESSION['lang'] = $lang;
}

// Current page identifier for navbar highlighting — set by the page BEFORE
// including this file (e.g. $activePage = 'home'). Deliberately not derived
// from $_SERVER['PHP_SELF'] here: a rewrite rule that routes everything
// through index.php would make every page report as "home".
$currentPage = basename($_SERVER['SCRIPT_NAME']);
if (!isset($activePage)) {
    if     ($currentPage === 'index.php') $activePage = 'home';
    elseif ($currentPage === 'about.php') { $activePage = 'info'; $activeSubPage = 'about'; }
    elseif (in_array($currentPage, ['programs.php', 'program-detail.php', 'tuition.php'])) $activePage = 'programs';
    elseif (in_array($currentPage, ['news.php', 'news-detail.php'])) $activePage = 'news';
    elseif ($currentPage === 'articles.php') $activePage = 'articles';
    elseif (in_array($currentPage, ['staff.php', 'campuses.php', 'campus-detail.php', 'statistics.php', 'statistics-staff.php', 'forms.php'])) $activePage = 'info';
    elseif ($currentPage === 'contact.php') $activePage = 'contact';
    elseif ($currentPage === 'search.php') $activePage = 'search';
    else $activePage = '';
}

$settings = $db->fetchOne("SELECT * FROM site_settings LIMIT 1") ?: [];
$faculty  = $db->fetchOne("SELECT * FROM faculty LIMIT 1") ?: [];

$siteName   = $settings['site_name'] ?? 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ';
$siteAbbr   = $settings['site_abbr'] ?? 'BAIT';
$metaKeysFb = $settings['meta_keywords'] ?? '';
$metaDescFb = $settings['meta_desc'] ?? '';

$finalTitle = ($pageTitle ?? $siteName) . ' | ' . $siteAbbr . ' RMUTSB';
// Search engines cut titles at ~60 characters; keep the page name, drop the tail of long ones.
$shortTitle = static fn(string $t): string => mb_strlen($t, 'UTF-8') > 62 ? rtrim(mb_substr($t, 0, 60, 'UTF-8')) . '…' : $t;
$finalTitle = $shortTitle($finalTitle);
$finalDesc  = $metaDesc ?? $metaDescFb;
$finalKw    = $metaKeywords ?? $metaKeysFb;
$currentUrl = canonicalUrl();
// A detail page can pin its own clean address ($canonicalUrl) — the same one its
// share buttons use — so Facebook and Google see one URL per article.
if (!empty($canonicalUrl)) $currentUrl = $canonicalUrl;
// Cover photo when the page has one, else the faculty logo (og-default.jpg never existed).
$ogImgFull  = (!empty($ogImage) && is_file(UPLOAD_PATH . '/' . $ogImage))
    ? UPLOAD_URL_ABSOLUTE . '/' . $ogImage
    : BASE_URL_ABSOLUTE . '/assets/img/logo-full.png';
$ogImgFile  = (!empty($ogImage) && is_file(UPLOAD_PATH . '/' . $ogImage)) ? UPLOAD_PATH . '/' . $ogImage : BASE_PATH . '/assets/img/logo-full.png';
$ogDims     = @getimagesize($ogImgFile) ?: null;

if ($lang === 'en' && !empty($pageTitle)) {
    $finalTitle = $shortTitle(translateText($pageTitle, 'en') . ' | BAIT RMUTSB');
    if ($finalDesc) $finalDesc = translateText($finalDesc, 'en');
}

/** Build the same URL with a different ?lang= for hreflang alternates. */
function withLang(string $url, string $lang): string {
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $q);
    $q['lang'] = $lang;
    $qs = http_build_query($q);
    return ($parts['path'] ?? '/') . ($qs ? '?' . $qs : '');
}
// REQUEST_URI already includes the /bait subdirectory prefix (same as
// $currentUrl above), so prepend scheme+host only — prepending BASE_URL
// here would duplicate the subdirectory into /bait/bait/...
$urlOrigin = siteOrigin();
// the alternates point at the same clean address as the canonical, only ?lang= differs
$altPath = (string)parse_url($currentUrl, PHP_URL_PATH) . (parse_url($currentUrl, PHP_URL_QUERY) ? '?' . parse_url($currentUrl, PHP_URL_QUERY) : '');
$hrefThUrl = $urlOrigin . withLang($altPath, 'th');
$hrefEnUrl = $urlOrigin . withLang($altPath, 'en');
// TH/EN switch keeps the rest of the query (id, cat, q, page ...) — a bare "?lang=en" would drop it.
$switchThUrl = withLang($_SERVER['REQUEST_URI'], 'th');
$switchEnUrl = withLang($_SERVER['REQUEST_URI'], 'en');

// Mega-menu data (programs grouped by education level). Programs with no
// สาขาวิชา (major_id IS NULL — บัณฑิตศึกษา: ป.โท/ป.เอก not tied to any one
// undergraduate major) get pulled into their own $navGradPrograms group
// alongside the per-level ones, since they cut across levels rather than
// belonging to one.
// Form categories per audience for the ดาวน์โหลด dropdown's fly-out (only categories that really have an active form for that audience)
$navFormCats = ['student' => [], 'staff' => []];
foreach ($db->fetchAll("SELECT DISTINCT c.category_id, c.category_name, f.audience FROM form_categories c JOIN forms f ON f.category_id = c.category_id AND f.is_active = 1 ORDER BY c.category_id") as $r) {
    if (isset($navFormCats[$r['audience']])) $navFormCats[$r['audience']][] = $r;
}
$navLevels   = $db->fetchAll("SELECT * FROM education_levels ORDER BY level_order");
$navPrograms = $db->fetchAll("SELECT program_id, program_name, level_id, major_id, is_active FROM programs ORDER BY is_active DESC, program_name");
$navProgramsByLevel = [];
$navGradPrograms    = [];
foreach ($navPrograms as $p) {
    $navProgramsByLevel[$p['level_id']][] = $p;
    if (empty($p['major_id'])) $navGradPrograms[] = $p;
}

$t = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" class="no-js" data-theme="light">   <?php /* the site always shows its light design, even when the device / browser is set to dark */ ?>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="only light">   <!-- also stops Chrome's "auto dark mode" from darkening the light design -->
<title><?= e($finalTitle) ?></title>
<meta name="description" content="<?= e($finalDesc) ?>">
<meta name="keywords" content="<?= e($finalKw) ?>">
<meta name="robots" content="<?= e($robotsMeta ?? 'index,follow') ?>">
<?php if (empty($noCanonical)): ?><link rel="canonical" href="<?= e($currentUrl) ?>"><?php endif; ?>

<!-- Bilingual alternates (Phase 1 §3 SEO gap: hreflang was missing) -->
<link rel="alternate" hreflang="th" href="<?= e($hrefThUrl) ?>">
<link rel="alternate" hreflang="en" href="<?= e($hrefEnUrl) ?>">
<link rel="alternate" hreflang="x-default" href="<?= e($hrefThUrl) ?>">

<!-- Open Graph -->
<meta property="og:title" content="<?= e($finalTitle) ?>">
<meta property="og:description" content="<?= e($finalDesc) ?>">
<meta property="og:image" content="<?= e($ogImgFull) ?>">
<?php if ($ogDims): ?>
<meta property="og:image:width" content="<?= (int)$ogDims[0] ?>">
<meta property="og:image:height" content="<?= (int)$ogDims[1] ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($currentUrl) ?>">
<meta property="og:type" content="<?= e($ogType ?? 'website') ?>">
<meta property="og:locale" content="<?= $lang === 'en' ? 'en_US' : 'th_TH' ?>">
<meta property="og:site_name" content="<?= e($siteAbbr) ?> RMUTSB">
<meta name="twitter:card" content="summary_large_image">

<?php $iconHref = !empty($settings['favicon']) ? UPLOAD_URL . '/' . e($settings['favicon']) : BASE_URL . '/assets/img/baitrus-icon.png'; ?>
<link rel="icon" href="<?= $iconHref ?>">
<link rel="apple-touch-icon" href="<?= $iconHref ?>">

<?php if (!empty($schemaJson)): ?>
<script type="application/ld+json"><?= $schemaJson ?></script>
<?php endif; ?>
<?php if (empty($orgSchemaFull)): // pages that print the full organisation graph (home, about) set $orgSchemaFull; every other page gets this lean node with the same @id ?>
<?php
// json_encode, not e(): a name with " or \ used to make the JSON invalid (e() escapes for HTML, not for JSON)
$leanOrg = ['@context' => 'https://schema.org', '@type' => 'EducationalOrganization', '@id' => BASE_URL_ABSOLUTE . '/#faculty',
    'name' => (string)$siteName, 'alternateName' => 'BAIT RMUTSB', 'url' => BASE_URL_ABSOLUTE . '/'];
if (!empty($faculty['logo'])) $leanOrg['logo'] = UPLOAD_URL_ABSOLUTE . '/' . $faculty['logo'];
?>
<script type="application/ld+json"><?= json_encode($leanOrg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT) ?></script>
<?php endif; ?>

<link rel="sitemap" type="application/xml" href="<?= BASE_URL ?>/sitemap.xml">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Design system — framework-free (no Bootstrap, no jQuery) -->
<?php foreach (['tokens.css', 'base.css', 'components.css', 'pages.css'] as $cssFile): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/<?= $cssFile ?>?v=<?= @filemtime(BASE_PATH . '/assets/css/' . $cssFile) ?: time() ?>">
<?php endforeach; ?>
<?= footerBgStyle($settings['footer_bg'] ?? null) ?>
<?php $mourn = mourningOptions($settings['mourning_options'] ?? null); $mournOn = mourningActive($mourn); if ($mournOn): ?>
<?php if (!empty($mourn['gray']) && $mourn['level'] > 0): ?><style id="mourning-style">html { -webkit-filter: grayscale(<?= (int)$mourn['level'] ?>%); filter: grayscale(<?= (int)$mourn['level'] ?>%); }</style><?php endif; ?>
<?php endif; ?>
</head>
<body data-lang="<?= e($lang) ?>"<?= ($mournOn && $mourn['ribbon']) ? ' data-mourning-ribbon' : '' ?>>
<?php if ($mournOn): ?>
<?php if ($mourn['ribbon']): $mournIcon = ($mourn['ribbon_image'] !== '' && is_file(UPLOAD_PATH . '/' . $mourn['ribbon_image'])) ? UPLOAD_URL . '/' . $mourn['ribbon_image'] : BASE_URL . '/assets/img/mourning-ribbon.png?v=' . (@filemtime(BASE_PATH . '/assets/img/mourning-ribbon.png') ?: 1); ?><div class="mourning-ribbon" aria-hidden="true"><span class="mourning-ribbon__icon"><img src="<?= e($mournIcon) ?>" alt=""></span></div><?php endif; ?>
<?php if ($mourn['popup'] && $mourn['popup_image'] !== '' && is_file(UPLOAD_PATH . '/' . $mourn['popup_image'])): ?>
<div class="mourning-popup" id="mourningPopup" data-freq="<?= e($mourn['popup_freq']) ?>" role="dialog" aria-modal="true" aria-label="<?= $lang === 'en' ? 'In memoriam' : 'ไว้อาลัย' ?>" hidden>
  <div class="mourning-popup__box">
    <button type="button" class="mourning-popup__close" aria-label="<?= $lang === 'en' ? 'Close' : 'ปิด' ?>">&times;</button>
    <img class="mourning-popup__img" src="<?= e(UPLOAD_URL . '/' . $mourn['popup_image']) ?>" alt="<?= $lang === 'en' ? 'In memoriam' : 'ไว้อาลัย' ?>"<?= imgSizeAttrs($mourn['popup_image']) ?>>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php include BASE_PATH . '/layout/icons.php'; ?>

<?php
// Same reasoning as the CSS ?v=filemtime() below: main.js gets a fresh
// query string whenever it changes, but the ES modules it *imports*
// (nav.js, staff-tree-scale.js, ...) are referenced by bare relative
// paths with no query string of their own — the browser caches each by
// that bare URL forever, so editing e.g. staff-tree-scale.js alone could
// serve a stale cached copy indefinitely. asset-version.php exposes the
// newest mtime across assets/js/*.js so main.js can append it to each
// dynamic import and bust exactly the modules that actually changed.
require_once BASE_PATH . '/includes/asset-version.php';
?>
<script>window.BAIT = { baseUrl: <?= json_encode(BASE_URL) ?>, lang: <?= json_encode($lang) ?>, jsVersion: <?= json_encode(jsAssetVersion()) ?> };</script>

<header class="site-header">
  <div class="topbar">
    <div class="container">
      <span><?= icon('graduation-cap', 'icon-sm') ?> <?= $t('มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ') ?></span>
      <div class="topbar-links">
        <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>"><?= icon('mail', 'icon-sm') ?> <?= $t('ติดต่อเรา') ?></a>
        <a href="<?= BASE_URL ?>/admin/login.php" title="<?= $t('เข้าสู่ระบบ') ?>"><?= icon('login', 'icon-sm') ?><span class="visually-hidden"><?= $t('เข้าสู่ระบบ') ?></span></a>
      </div>
    </div>
  </div>

  <nav class="navbar" aria-label="Main">
    <div class="container">
      <a class="brand" href="<?= BASE_URL ?>/">
        <img src="<?= BASE_URL ?>/assets/img/logo-full-sm.png" alt="<?= e($siteAbbr) ?>" width="640" height="171">
      </a>

      <ul class="nav-links">
        <li><a class="nav-link" href="<?= BASE_URL ?>/" <?= $activePage === 'home' ? 'aria-current="page"' : '' ?>><?= $t('หน้าหลัก') ?></a></li>

        <li class="has-mega">
          <a class="nav-link" href="#" <?= $activePage === 'info' ? 'aria-current="page"' : '' ?>><?= $t('ภาพรวมคณะ') ?> <?= icon('chevron-down', 'icon-sm') ?></a>
          <div class="mega-menu">
            <div class="mega-col">
              <h5><?= $t('ข้อมูลคณะ') ?></h5>
              <a href="<?= BASE_URL ?>/about.php?lang=<?= e($lang) ?>" <?= ($activeSubPage ?? '') === 'about' ? 'aria-current="page"' : '' ?>><?= icon('building', 'icon-sm') ?> <?= $t('เกี่ยวกับคณะ') ?></a>
              <a href="<?= BASE_URL ?>/campuses.php?lang=<?= e($lang) ?>" <?= ($activeSubPage ?? '') === 'campuses' ? 'aria-current="page"' : '' ?>><?= icon('pin', 'icon-sm') ?> <?= $t('ศูนย์พื้นที่') ?></a>
              <a href="<?= BASE_URL ?>/staff.php?lang=<?= e($lang) ?>" <?= ($activeSubPage ?? '') === 'staff' ? 'aria-current="page"' : '' ?>><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?></a>
            </div>
            <div class="mega-col">
              <h5><?= $t('ข้อมูลเชิงลึก') ?></h5>
              <a href="<?= BASE_URL ?>/statistics.php?lang=<?= e($lang) ?>" <?= ($activeSubPage ?? '') === 'statistics-student' ? 'aria-current="page"' : '' ?>><?= icon('list', 'icon-sm') ?> <?= $t('สถิตินักศึกษา') ?></a>
              <a href="<?= BASE_URL ?>/statistics-staff.php?lang=<?= e($lang) ?>" <?= ($activeSubPage ?? '') === 'statistics-staff' ? 'aria-current="page"' : '' ?>><?= icon('users', 'icon-sm') ?> <?= $t('สถิติบุคลากร') ?></a>
              <a href="<?= BASE_URL ?>/search.php?lang=<?= e($lang) ?>"><?= icon('search', 'icon-sm') ?> <?= $t('ค้นหา') ?></a>
            </div>
          </div>
        </li>

        <li class="has-mega">
          <a class="nav-link" href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" <?= $activePage === 'programs' ? 'aria-current="page"' : '' ?>>
            <?= $t('หลักสูตร') ?> <?= icon('chevron-down', 'icon-sm') ?>
          </a>
          <div class="mega-menu">
            <div class="mega-col">
              <h5><?= $t('ระดับการศึกษา') ?></h5>
              <?php foreach ($navLevels as $lvl): if ($lvl['level_order'] > 3) continue; ?>
              <a href="<?= BASE_URL ?>/programs.php?level=<?= (int)$lvl['level_id'] ?>&lang=<?= e($lang) ?>">
                <?= icon('book', 'icon-sm') ?> <?= e($t($lvl['level_name'])) ?>
              </a>
              <?php endforeach; ?>
            </div>
            <div class="mega-col">
              <h5><?= $t('ระดับบัณฑิตศึกษา') ?></h5>
              <?php foreach ($navLevels as $lvl): if ($lvl['level_order'] <= 3) continue; ?>
              <a href="<?= BASE_URL ?>/programs.php?level=<?= (int)$lvl['level_id'] ?>&lang=<?= e($lang) ?>">
                <?= icon('book', 'icon-sm') ?> <?= e($t($lvl['level_name'])) ?>
              </a>
              <?php endforeach; ?>
              <?php if ($navGradPrograms): ?>
              <a href="<?= BASE_URL ?>/programs.php?major=none&lang=<?= e($lang) ?>">
                <?= icon('book', 'icon-sm') ?> <?= $t('บัณฑิตศึกษา') ?>
              </a>
              <?php endif; ?>
            </div>
            <div class="mega-footer">
              <a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm"><?= $t('หลักสูตรทั้งหมด') ?> <?= icon('arrow-right', 'icon-sm') ?></a>
              <a href="<?= BASE_URL ?>/tuition.php?lang=<?= e($lang) ?>" class="btn btn-tuition btn-sm"><?= $lang === 'en' ? 'Tuition fees' : 'ค่าธรรมเนียมการศึกษา' ?></a>
            </div>
          </div>
        </li>

        <li><a class="nav-link" href="<?= BASE_URL ?>/articles.php?lang=<?= e($lang) ?>" <?= $activePage === 'articles' ? 'aria-current="page"' : '' ?>><?= $t('บทความ') ?></a></li>

        <li class="has-mega">
          <a class="nav-link" href="#" <?= $activePage === 'forms' ? 'aria-current="page"' : '' ?>><?= $t('ดาวน์โหลด') ?> <?= icon('chevron-down', 'icon-sm') ?></a>
          <div class="mega-menu mega-menu--info">
            <div class="mega-col">
              <?php foreach ([['student', 'graduation-cap', 'นักศึกษา'], ['staff', 'users', 'บุคลากร']] as [$aud, $audIcon, $audName]): $audCats = $navFormCats[$aud]; ?>
              <div class="mega-sub">
                <a href="<?= BASE_URL ?>/forms.php?audience=<?= $aud ?>&lang=<?= e($lang) ?>"<?= $audCats ? ' aria-haspopup="true"' : '' ?>><?= icon($audIcon, 'icon-sm') ?> <?= $t($audName) ?><?= $audCats ? ' <span class="mega-sub-arrow">' . icon('chevron-right', 'icon-sm') . '</span>' : '' ?></a>
                <?php if ($audCats): ?>
                <div class="mega-sub-menu">
                  <h5><?= $t('หมวดหมู่แบบฟอร์ม') ?></h5>
                  <?php foreach ($audCats as $fc): ?>
                  <a href="<?= BASE_URL ?>/forms.php?audience=<?= $aud ?>&cat=<?= (int)$fc['category_id'] ?>&lang=<?= e($lang) ?>"><?= e($t($fc['category_name'])) ?></a>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </li>

        <li class="has-mega">
          <a class="nav-link" href="#" <?= $activePage === 'info-links' ? 'aria-current="page"' : '' ?>><?= $t('สารสนเทศ') ?> <?= icon('chevron-down', 'icon-sm') ?></a>
          <div class="mega-menu mega-menu--info">
            <div class="mega-col">
              <a href="<?= BASE_URL ?>/info.php?audience=student&lang=<?= e($lang) ?>"><?= icon('graduation-cap', 'icon-sm') ?> <?= $t('นักศึกษา') ?></a>
              <a href="<?= BASE_URL ?>/info.php?audience=staff&lang=<?= e($lang) ?>"><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?></a>
            </div>
          </div>
        </li>

        <li><a class="nav-link" href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" <?= $activePage === 'contact' ? 'aria-current="page"' : '' ?>><?= $t('ติดต่อ') ?></a></li>
      </ul>

      <div class="nav-actions">
        <button type="button" class="search-toggle" data-search-toggle aria-expanded="false" aria-controls="navSearchPanel" aria-label="<?= $t('ค้นหา') ?>"><?= icon('search') ?></button>
        <div class="lang-switch">
          <a href="<?= e($switchThUrl) ?>" <?= $lang === 'th' ? 'aria-current="true"' : '' ?>>TH</a>
          <a href="<?= e($switchEnUrl) ?>" <?= $lang === 'en' ? 'aria-current="true"' : '' ?>>EN</a>
        </div>
        <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="offcanvasNav" aria-label="<?= $lang === 'en' ? 'Open menu' : 'เปิดเมนู' ?>">
          <?= icon('menu') ?>
        </button>
      </div>
    </div>

    <div class="nav-search-panel" id="navSearchPanel" data-nav-search-panel>
      <div class="search-panel" data-search-panel>
        <form class="search-box" action="<?= BASE_URL ?>/search.php" method="GET" role="search">
          <?= icon('search') ?>
          <input type="search" name="q" data-search-input aria-label="<?= $lang === 'en' ? 'Search the site' : 'ค้นหาในเว็บไซต์' ?>" placeholder="<?= $lang === 'en' ? 'Search programs, news, staff…' : 'ค้นหาหลักสูตร ข่าวสาร บุคลากร...' ?>" autocomplete="off">
          <input type="hidden" name="lang" value="<?= e($lang) ?>">
        </form>
        <div class="search-suggestions" data-search-results role="listbox"></div>
      </div>
    </div>
  </nav>
</header>

<!-- Mobile offcanvas -->
<div class="offcanvas-backdrop" data-nav-backdrop></div>
<nav class="offcanvas-nav" id="offcanvasNav" aria-label="Mobile">
  <div class="offcanvas-header">
    <span class="brand-name"><?= e($siteAbbr) ?></span>
    <button type="button" data-nav-close aria-label="<?= $lang === 'en' ? 'Close menu' : 'ปิดเมนู' ?>"><?= icon('close') ?></button>
  </div>
  <ul>
    <li><a class="nav-link" href="<?= BASE_URL ?>/"><?= $t('หน้าหลัก') ?></a></li>
    <li>
      <details class="offcanvas-accordion">
        <summary><?= $t('ภาพรวมคณะ') ?> <?= icon('chevron-down', 'icon-sm') ?></summary>
        <div class="mega-col">
          <h5><?= $t('ข้อมูลคณะ') ?></h5>
          <a href="<?= BASE_URL ?>/about.php?lang=<?= e($lang) ?>"><?= icon('building', 'icon-sm') ?> <?= $t('เกี่ยวกับคณะ') ?></a>
          <a href="<?= BASE_URL ?>/campuses.php?lang=<?= e($lang) ?>"><?= icon('pin', 'icon-sm') ?> <?= $t('ศูนย์พื้นที่') ?></a>
          <a href="<?= BASE_URL ?>/staff.php?lang=<?= e($lang) ?>"><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?></a>
        </div>
        <div class="mega-col">
          <h5><?= $t('ข้อมูลเชิงลึก') ?></h5>
          <a href="<?= BASE_URL ?>/statistics.php?lang=<?= e($lang) ?>"><?= icon('list', 'icon-sm') ?> <?= $t('สถิตินักศึกษา') ?></a>
          <a href="<?= BASE_URL ?>/statistics-staff.php?lang=<?= e($lang) ?>"><?= icon('users', 'icon-sm') ?> <?= $t('สถิติบุคลากร') ?></a>
        </div>
      </details>
    </li>
    <li>
      <details class="offcanvas-accordion">
        <summary><?= $t('หลักสูตร') ?> <?= icon('chevron-down', 'icon-sm') ?></summary>
        <?php foreach ($navLevels as $lvl): $lvlPrograms = $navProgramsByLevel[$lvl['level_id']] ?? []; ?>
        <div class="mega-col">
          <h5><?= e($t($lvl['level_name'])) ?></h5>
          <?php foreach ($lvlPrograms as $p): ?>
          <a href="<?= BASE_URL ?>/program-detail.php?id=<?= (int)$p['program_id'] ?>&lang=<?= e($lang) ?>"><?= e($t($p['program_name'])) ?><?= (int)($p['is_active'] ?? 1) !== 1 ? '<span class="program-closed-tag">' . ($lang === 'en' ? 'Closed' : 'ปิดรับสมัคร') . '</span>' : '' ?></a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($navGradPrograms): ?>
        <div class="mega-col">
          <h5><?= $t('บัณฑิตศึกษา') ?></h5>
          <?php foreach ($navGradPrograms as $p): ?>
          <a href="<?= BASE_URL ?>/program-detail.php?id=<?= (int)$p['program_id'] ?>&lang=<?= e($lang) ?>"><?= e($t($p['program_name'])) ?><?= (int)($p['is_active'] ?? 1) !== 1 ? '<span class="program-closed-tag">' . ($lang === 'en' ? 'Closed' : 'ปิดรับสมัคร') . '</span>' : '' ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </details>
    </li>
    <li><a class="nav-link" href="<?= BASE_URL ?>/articles.php?lang=<?= e($lang) ?>"><?= $t('บทความ') ?></a></li>
    <li>
      <details class="offcanvas-accordion">
        <summary><?= $t('ดาวน์โหลด') ?> <?= icon('chevron-down', 'icon-sm') ?></summary>
        <div class="mega-col">
          <?php foreach ([['student', 'graduation-cap', 'นักศึกษา'], ['staff', 'users', 'บุคลากร']] as [$aud, $audIcon, $audName]): ?>
          <a href="<?= BASE_URL ?>/forms.php?audience=<?= $aud ?>&lang=<?= e($lang) ?>"><?= icon($audIcon, 'icon-sm') ?> <?= $t($audName) ?></a>
          <?php if (!empty($navFormCats[$aud])): ?>
          <div class="offcanvas-sub" aria-label="<?= e($t('หมวดหมู่แบบฟอร์ม')) ?>">
            <?php foreach ($navFormCats[$aud] as $fc): ?>
            <a href="<?= BASE_URL ?>/forms.php?audience=<?= $aud ?>&cat=<?= (int)$fc['category_id'] ?>&lang=<?= e($lang) ?>"><?= e($t($fc['category_name'])) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
    </li>
    <li>
      <details class="offcanvas-accordion">
        <summary><?= $t('สารสนเทศ') ?> <?= icon('chevron-down', 'icon-sm') ?></summary>
        <div class="mega-col">
          <a href="<?= BASE_URL ?>/info.php?audience=student&lang=<?= e($lang) ?>"><?= icon('graduation-cap', 'icon-sm') ?> <?= $t('นักศึกษา') ?></a>
          <a href="<?= BASE_URL ?>/info.php?audience=staff&lang=<?= e($lang) ?>"><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?></a>
        </div>
      </details>
    </li>
    <li><a class="nav-link" href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>"><?= $t('ติดต่อ') ?></a></li>
    <li><a class="nav-link" href="<?= BASE_URL ?>/search.php?lang=<?= e($lang) ?>"><?= icon('search', 'icon-sm') ?> <?= $t('ค้นหา') ?></a></li>
  </ul>
  <div class="cluster gap-2" style="margin-top:20px;justify-content:space-between">
    <div class="lang-switch">
      <a href="<?= e($switchThUrl) ?>" <?= $lang === 'th' ? 'aria-current="true"' : '' ?>>TH</a>
      <a href="<?= e($switchEnUrl) ?>" <?= $lang === 'en' ? 'aria-current="true"' : '' ?>>EN</a>
    </div>
    <a class="nav-link" href="<?= BASE_URL ?>/admin/login.php" style="display:flex;align-items:center;gap:6px;padding:8px 10px">
      <?= icon('login', 'icon-sm') ?> <?= $t('เข้าสู่ระบบ') ?>
    </a>
  </div>
</nav>

<main id="main">

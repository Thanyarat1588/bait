<?php
/**
 * BAIT - About / Faculty Page (Phase 3 rebuild)
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

$faculty  = $db->fetchOne("SELECT * FROM faculty LIMIT 1") ?: [];
$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$settings = $db->fetchOne("SELECT * FROM site_settings LIMIT 1") ?: [];

$deans = $db->fetchAll(
    "SELECT s.staff_id, s.prefix, s.full_name, s.full_name_en, s.name_en_verified, s.image, s.email, s.responsibility, s.e_profile_url,
            p.position_name, c.campus_name
     FROM staff s
     JOIN positions p ON p.position_id = s.position_id
     LEFT JOIN campuses c ON c.campus_id = s.campus_id
     WHERE " . staffCurrentSql('s') . " AND p.position_level >= 9
     ORDER BY p.position_level DESC, s.display_order ASC LIMIT 8"
);

// Scoped to each table's latest ปีการศึกษา with data — same rule
// statistics.php / statistics-staff.php default to, so these summary
// numbers agree with the dedicated statistics pages instead of summing
// every year ever entered (students) or counting the `staff` roster table
// (which only covers instructional staff with a public profile, not the
// faculty-wide headcount that `staff_counts` exists to track).
$latestStudentYear = (int)($db->fetchOne("SELECT MAX(year) as y FROM student_counts")['y'] ?? 0);
$stats = [
    'programs' => $db->count('programs', 'is_active=1'),
    'staff'    => $db->count('staff', staffCurrentSql('')),   // live: current staff (not resigned), same as statistics-staff.php
    'students' => (int)($db->fetchOne("SELECT SUM(total) as s FROM student_counts WHERE year = ?", [$latestStudentYear])['s'] ?? 0),
    'campuses' => count($campuses),
];

/**
 * Renders one manageable "about_sections" text block (admin/modules/faculty).
 * 'html' is real CKEditor markup — rendered raw/unescaped inside .prose,
 * same pattern news-detail.php already uses for news content (Thai passes
 * through as-is; English still runs through translateText() on the raw
 * markup, matching that same existing precedent). The other three formats
 * are legacy plain-text rows saved before the editor existed — 'list'/
 * 'bullet' strip any leading "1."/"1)" the admin had typed manually and
 * re-number via a real <ol>/<ul> instead of showing literal "1. 1. ...".
 * Every row still open in the *admin* form gets upgraded to 'html' the
 * moment it's next saved (see section-form.php); these paths only matter
 * for whatever hasn't been re-saved yet.
 */
function renderSectionContent(?string $content, string $format, string $lang, $t): string {
    if (!$content) return '';
    if ($format === 'html') {
        return '<div class="prose">' . cleanRichHtml($lang === 'en' ? translateText($content, 'en') : $content) . '</div>';
    }
    if ($format === 'list' || $format === 'bullet') {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $tag = $format === 'bullet' ? 'ul' : 'ol';
        $html = "<{$tag} class=\"prose\">";
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = preg_replace('/^\d+[.\)]\s*/', '', $line);
            $html .= '<li>' . ($lang === 'en' ? e(translateText($line, 'en')) : e($line)) . '</li>';
        }
        return $html . "</{$tag}>";
    }
    return '<p class="prose">' . ($lang === 'en' ? e(translateText($content, 'en')) : nl2br(e($content))) . '</p>';
}

/**
 * Slider data for a manageable text section — only when the admin chose "สไลด์การ์ด" for it
 * (about_sections.display_options). Items come from the section's own list (an <ol>/<ul> in the
 * editor, or the plain "list"/"bullet" formats); returns [options, items, lead], where $lead is
 * the text written outside the list. Null = render the ordinary card (also when the section has
 * fewer than 2 list items, so a bad setting can never produce an empty slider).
 */
function aboutCardSliderData(array $asec): ?array {
    static $cache = [];
    $key = $asec['section_id'] ?? md5($asec['content'] ?? '');
    if (array_key_exists($key, $cache)) return $cache[$key];
    $cache[$key] = null;
    if (($asec['section_type'] ?? '') !== 'text' || empty($asec['content'])) return null;
    $opt = aboutSectionOptions($asec['display_options'] ?? null);
    if ($opt['style'] !== 'slider') return null;

    $fmt = $asec['content_format'] ?? 'html';
    $items = []; $lead = '';
    $clean = fn(string $x): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode($x, ENT_QUOTES, 'UTF-8')));

    if ($fmt === 'list' || $fmt === 'bullet') {
        foreach (preg_split('/\r\n|\r|\n/', trim($asec['content'])) as $line) {
            $line = $clean(preg_replace('/^\d+[.\)]\s*/', '', trim($line)));
            if ($line !== '') $items[] = $line;
        }
    } elseif ($fmt === 'html' && class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="r">' . $asec['content'] . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//div[@id="r"]//li[not(ancestor::li)]') as $li) {
            $t = $clean($li->textContent);
            if ($t !== '') $items[] = $t;
        }
        foreach ($xp->query('//div[@id="r"]/*[not(self::ul) and not(self::ol)]') as $n) {
            $t = $clean($n->textContent);
            if ($t !== '') $lead .= ($lead ? ' ' : '') . $t;
        }
    }
    return $cache[$key] = count($items) >= 2 ? ['opt' => $opt, 'items' => $items, 'lead' => $lead] : null;
}

/** Dark or white text for a coloured badge, whichever reads better on $hex. */
function aboutBadgeInk(string $hex): string {
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match('/^[0-9a-f]{6}$/i', $h)) return '#fff';
    [$r, $g, $b] = array_map(fn($c) => hexdec($c) / 255, str_split($h, 2));
    $lin = fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    return (0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b)) > 0.4 ? '#1B2032' : '#fff';
}

/** One list-section as a card slider: big card, one point at a time, ← → buttons (see the admin's "รูปแบบการแสดงผล"). */
function renderAboutCardSlider(array $asec, array $d, string $lang, $t): string {
    $o     = $d['opt'];
    $color = preg_match('/^#[0-9a-f]{3,6}$/i', (string)$asec['color']) ? $asec['color'] : '#1558A6';
    $ink   = aboutBadgeInk($color);
    $total = count($d['items']);
    $icon  = $asec['icon'] ?: 'target';

    $cls = 'cs' . ($o['shape'] === 'rounded' ? ' cs--rounded' : '') . ($o['effect'] === 'slide' ? ' cs--slide' : '') . (!$o['mark'] ? ' cs--nomark' : '');
    $html  = '<div class="' . $cls . '" data-card-slider' . ($o['autoplay'] ? ' data-cs-autoplay="' . (int)$o['autoplay'] . '"' : '')
           . ' style="--cs-color:' . e($color) . ';--cs-ink:' . $ink . '">';
    $html .= '<div class="cluster gap-3 cs-head"><span class="value-icon" style="--value-color:' . e($color) . ';width:50px;height:50px">' . icon($icon) . '</span>'
           . '<div><h2 class="about-sec-title" style="color:' . e(legibleOnWhite($color)) . ';--tc:' . e($color) . ';margin:0">' . e($t($asec['title'])) . '</h2>'
           . ($d['lead'] !== '' ? '<p class="text-muted" style="margin:2px 0 0">' . e($t($d['lead'])) . '</p>' : '')
           . '</div></div>';
    $html .= '<div class="cs-card"><div class="cs-slides" aria-live="polite">';
    foreach ($d['items'] as $i => $text) {
        $n = $i + 1;
        $title = ''; $body = $text;
        // "English heading = Thai explanation" (the ค่านิยม list) — heading and body are shown separately
        if (preg_match('/^(.{2,80}?)\s+=\s+(.+)$/us', $text, $m)) { $title = $m[1]; $body = $m[2]; }
        $first = mb_strtoupper(mb_substr($title !== '' ? $title : $body, 0, 1, 'UTF-8'), 'UTF-8');
        switch ($o['badge']) {
            case 'number': $badge = e((string)$n); break;
            case 'letter': $badge = e($first); break;
            case 'icon':   $badge = icon($icon); break;
            default:       $badge = e(($title !== '' && preg_match('/^[A-Za-z]/', $title)) ? $first : (string)$n);
        }
        $heading = $title !== '' ? $t($title) : ($t($asec['title']) . ' ' . $t('ข้อ') . ' ' . $n);
        $html .= '<article class="cs-slide' . ($i === 0 ? ' is-active' : '') . '" data-cs-slide>'
              . '<div class="cs-text">' . ($o['counter'] ? '<p class="cs-count">' . $n . ' / ' . $total . '</p>' : '')
              . '<h3 class="cs-title">' . e($heading) . '</h3>'
              . '<p class="cs-body">' . e($t($body)) . '</p></div>'
              . '<div class="cs-badge' . ($o['badge'] === 'icon' ? ' cs-badge--icon' : '') . '" aria-hidden="true">' . $badge . '</div></article>';
    }
    $html .= '</div><div class="cs-nav">'
           . '<button type="button" class="cs-btn cs-btn--prev" data-cs-prev aria-label="' . e($t('ก่อนหน้า')) . '">' . icon('arrow-right') . '</button>'
           . '<button type="button" class="cs-btn cs-btn--next" data-cs-next aria-label="' . e($t('ถัดไป')) . '">' . icon('arrow-right') . '</button>'
           . '</div><span class="cs-mark" aria-hidden="true">' . icon($icon) . '</span></div></div>';
    return $html;
}
/**
 * "กล่องประโยคเด่น": one short statement (e.g. the vision) as a big sentence on a full-width coloured panel.
 * Uses the section's own colour + icon + title (all editable in the admin) and its text; HTML is flattened
 * to plain text. Returns null when the section has no text, so the ordinary card is used instead.
 */
function renderAboutStatement(array $asec, string $lang, $t): ?string {
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<\/(p|div|li|h[1-6]|br)>/i', '$0 ', (string)($asec['content'] ?? ''))), ENT_QUOTES, 'UTF-8')));
    if ($plain === '') return null;
    $color = preg_match('/^#[0-9a-f]{3,6}$/i', (string)$asec['color']) ? $asec['color'] : '#1558A6';
    $text  = $t($plain);
    $long  = mb_strlen($text, 'UTF-8') > 140 ? ' stmt--long' : '';
    return '<div class="stmt reveal' . $long . '" style="--stmt-color:' . e($color) . ';--stmt-ink:' . aboutBadgeInk($color) . '">'
         . '<span class="stmt-label">' . icon($asec['icon'] ?: 'eye') . e($t($asec['title'])) . '</span>'
         . '<p class="stmt-text">' . e($text) . '</p></div>';
}
/**
 * Renders the inner content of ONE about_sections row — no outer <section>/
 * .container, so the caller can place it either as a full-width row on its
 * own or side-by-side with another 'half'-width section (see the render
 * loop below). $standalone controls whether a 'text' row's card centers
 * itself with its own max-width (full-width row) or just fills its column
 * (paired row, where the shared 2-col grid already sizes it).
 */
function renderOneAboutSection(array $asec, string $lang, $t, Database $db, bool $standalone): string {
    if ($asec['section_type'] === 'grid') {
        $items = $db->fetchAll("SELECT * FROM about_section_items WHERE section_id = ? AND is_active = 1 ORDER BY display_order ASC", [$asec['section_id']]);
        $html = '<div class="section-header center"><h2>' . e($t($asec['title'])) . '</h2>';
        if (!empty($asec['subtitle'])) $html .= '<p>' . e($t($asec['subtitle'])) . '</p>';
        $html .= '</div><div class="grid grid-5">';
        foreach ($items as $item) $html .= aboutGridItemCard($item, $lang);
        if (empty($items)) $html .= '<div class="empty-state" style="grid-column:1/-1">' . e($t('ยังไม่มีรายการ')) . '</div>';
        return $html . '</div>';
    }

    // Legacy single fixed slot: จุดเด่นของคณะ, pulled from the same shared
    // `highlights` table the homepage uses (managed on the "จุดเด่นของคณะ"
    // tab in admin/modules/faculty/index.php) — unlike 'grid' rows above,
    // this isn't its own independent gallery, it's the one homepage set
    // rendered again here.
    if ($asec['section_type'] === 'highlights') {
        $homepageHighlights = $db->fetchAll("SELECT * FROM highlights WHERE is_active = 1 ORDER BY display_order ASC");
        $html = '<div class="section-header center"><h2>' . e($t($asec['title'])) . '</h2>';
        if (!empty($asec['subtitle'])) $html .= '<p>' . e($t($asec['subtitle'])) . '</p>';
        $html .= '</div><div class="grid grid-5">';
        foreach ($homepageHighlights as $h) $html .= highlightCard($h, $lang);
        if (empty($homepageHighlights)) $html .= '<div class="empty-state" style="grid-column:1/-1">' . e($t('ยังไม่มีจุดเด่น')) . '</div>';
        return $html . '</div>';
    }

    if ($asec['section_type'] === 'text' && aboutSectionOptions($asec['display_options'] ?? null)['style'] === 'statement'
        && ($stmt = renderAboutStatement($asec, $lang, $t)) !== null) {
        return $stmt;
    }

    if ($slider = aboutCardSliderData($asec)) {
        return renderAboutCardSlider($asec, $slider, $lang, $t);
    }

    if ($asec['section_type'] === 'text' && !empty($asec['content'])) {
        $cardStyle = 'padding:28px';
        $inner = '<div class="cluster gap-3" style="margin-bottom:14px">'
               . '<span class="value-icon" style="--value-color:' . e($asec['color']) . ';width:50px;height:50px">' . icon($asec['icon'] ?: 'target') . '</span>'
               . '<h2 class="about-sec-title" style="color:' . e(legibleOnWhite((string)$asec['color'])) . ';--tc:' . e((string)$asec['color']) . ';margin:0">' . e($t($asec['title'])) . '</h2></div>'
               . '<div class="text-muted">' . renderSectionContent($asec['content'], $asec['content_format'], $lang, $t) . '</div>';
        return '<div class="card reveal" style="' . $cardStyle . '">' . $inner . '</div>';
    }

    return '';
}

// Admin-managed, freely reorderable About-page sections (see
// admin/modules/faculty/index.php) — replaced the old fixed vision/mission
// grid-2 layout (unbalanced when one side is much shorter than the other)
// and the hardcoded 6-item "core values" grid (never had an admin UI at
// all). Each row is one of:
//   'text'       — icon+title+rich content+optional image
//   'grid'       — its own independent image gallery via about_section_items
//                  (see admin/modules/faculty/section-form.php)
//   'highlights' — the one fixed จุดเด่นของคณะ slot, reusing the same
//                  homepage `highlights` table/tab rather than its own data
// 'width' controls the paired-row layout in the render loop below: two
// consecutive 'half' rows share one 2-column grid; anything else renders
// as its own full-width row.
$aboutSections = $db->fetchAll("SELECT * FROM about_sections WHERE is_active = 1 AND (slug IS NULL OR slug <> 'history') ORDER BY display_order ASC");

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'About BAIT' : 'เกี่ยวกับคณะ');

$pageTitle = $lang === 'en' ? 'About BAIT' : 'เกี่ยวกับคณะ';
$metaDesc  = $lang === 'en'
    ? 'Learn about the Faculty of Business Administration and Information Technology (BAIT) at Rajamangala University of Technology Suvarnabhumi.'
    : 'ข้อมูลคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ';

$orgGraph = orgSchema($db, $lang);
$orgGraph['@graph'][0]['description'] = $metaDesc;
$orgSchemaFull = true;
$schemaJson = json_encode($orgGraph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$activePage = 'info'; $activeSubPage = 'about';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('เกี่ยวกับ')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('เกี่ยวกับคณะ') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<!-- ============ FACULTY INTRO ============ -->
<section class="section">
  <div class="container about-split">
    <div class="reveal">
      <?php if (!empty($faculty['logo'])): ?>
      <img src="<?= UPLOAD_URL . '/' . e($faculty['logo']) ?>" class="about-logo" alt="BAIT Logo"<?= imgSizeAttrs($faculty['logo']) ?> decoding="async" style="max-height:110px;margin-bottom:24px">
      <?php endif; ?>
      <h2 style="color:var(--primary);margin-bottom:8px">
        <?= $lang === 'en' ? e($faculty['faculty_name_en'] ?? 'Faculty of Business Administration and Information Technology') : e($faculty['faculty_name_th'] ?? 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ') ?>
      </h2>
      <p class="text-muted" style="margin-bottom:20px">
        <?= $lang === 'en' ? 'Rajamangala University of Technology Suvarnabhumi' : 'มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ' ?>
      </p>
      <?php if (!empty($faculty['about'])): ?>
      <?php
      // Content saved from a rich-text editor carries literal entities like
      // &nbsp; alongside real tags — strip_tags() only removes the tags, so
      // decoding entities first keeps e() from re-escaping that leftover
      // "&nbsp;" into visible "&amp;nbsp;" text on the page.
      $aboutPlain = html_entity_decode(strip_tags($faculty['about']), ENT_QUOTES, 'UTF-8');
      ?>
      <div class="text-muted prose"><?= $lang === 'en' ? translateText($aboutPlain, 'en') : nl2br(e($aboutPlain)) ?></div>
      <?php endif; ?>
    </div>
    <div class="reveal">
      <div class="stat-mini-grid">
        <?php
        $highlights = [
          ['icon' => 'graduation-cap', 'count' => $stats['programs'], 'label' => $t('หลักสูตร'), 'url' => BASE_URL . '/programs.php?lang=' . $lang],
          ['icon' => 'users',          'count' => $stats['staff'],    'label' => $t('บุคลากร'), 'url' => BASE_URL . '/staff.php?lang=' . $lang],
          ['icon' => 'user',           'count' => $stats['students'], 'label' => $t('นักศึกษา'), 'url' => BASE_URL . '/statistics.php?lang=' . $lang],
          ['icon' => 'building',       'count' => $stats['campuses'], 'label' => $t('ศูนย์พื้นที่'), 'url' => BASE_URL . '/campuses.php?lang=' . $lang],
        ];
        foreach ($highlights as $h): ?>
        <a href="<?= e($h['url']) ?>" class="stat-mini">
          <div class="stat-mini-icon"><?= icon($h['icon']) ?></div>
          <div class="stat-mini-num count" data-count="<?= $h['count'] ?>"><?= number_format($h['count']) ?></div>
          <div class="stat-mini-label"><?= $h['label'] ?></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============ HISTORY TIMELINE ============ -->
<?php
$historySteps = [
  ['ก่อตั้ง', 'จุดเริ่มต้นของคณะ',
    ['คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ เดิมเป็นสถานที่ตั้งของสถาบันเทคโนโลยีราชมงคล วิทยาเขตพระนครศรีอยุธยา วาสุกรี']],
  ['2548', '๘ มกราคม พ.ศ. ๒๕๔๘',
    ['พระบาทสมเด็จพระเจ้าอยู่หัว ภูมิพลอดุลยเดช ทรงลงพระปรมาภิไธยพระราชบัญญัติมหาวิทยาลัยเทคโนโลยีราชมงคล ทำให้เกิดมหาวิทยาลัยอีก ๙ แห่ง โดยแต่ละแห่งมีอำนาจในการบริหารจัดการองค์กรของตนเอง',
     'สถาบันเทคโนโลยีราชมงคล วิทยาเขตพระนครศรีอยุธยา วาสุกรี ได้เปลี่ยนชื่อเป็น “มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ วิทยาเขตพระนครศรีอยุธยา วาสุกรี”']],
  ['2549', '๒๘ ธันวาคม พ.ศ. ๒๕๔๙',
    ['มีประกาศในราชกิจจานุเบกษา ให้มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ จัดตั้งหน่วยงานเพื่อจัดการเรียนการสอนเป็นคณะ จำนวน ๖ คณะ']],
  ['ปัจจุบัน', 'ภารกิจและการจัดการเรียนการสอน',
    ['คณะมีภารกิจในการจัดการเรียนการสอนด้านบริหารธุรกิจและเทคโนโลยีสารสนเทศ วิจัย บริการวิชาการ และทำนุบำรุงศิลปวัฒนธรรม',
     'จัดการเรียนการสอนที่ศูนย์ทั้ง ๔ แห่ง คือ ศูนย์พระนครศรีอยุธยา วาสุกรี (สำนักงานคณบดี) ศูนย์พระนครศรีอยุธยา หันตรา ศูนย์นนทบุรี และศูนย์สุพรรณบุรี ในระดับ ปวส. ๖ หลักสูตร ปริญญาตรี ๕ หลักสูตร และปริญญาโท ๑ หลักสูตร']],
];
?>
<section class="section section-alt">
  <div class="container">
    <div class="section-header center reveal">
      <h2><?= $t('ประวัติคณะ') ?></h2>
    </div>
    <div class="timeline reveal" data-timeline>
      <div class="tl-view" aria-live="polite">
        <?php foreach ($historySteps as $hs): ?>
        <div class="tl-panel" data-tl-panel>
          <div class="tl-year"><?= e($t($hs[0])) ?></div>
          <h3><?= e($t($hs[1])) ?></h3>
          <?php foreach ($hs[2] as $para): ?><p><?= e($t($para)) ?></p><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="tl-strip">
        <button type="button" class="tl-arrow" data-tl-prev aria-label="<?= e($t('ก่อนหน้า')) ?>">‹</button>
        <div class="tl-track" data-tl-track><ul>
          <?php foreach ($historySteps as $hs): ?>
          <li><button type="button" data-tl-btn><span><?= e($t($hs[0])) ?></span></button></li>
          <?php endforeach; ?>
        </ul></div>
        <button type="button" class="tl-arrow" data-tl-next aria-label="<?= e($t('ถัดไป')) ?>">›</button>
      </div>
    </div>
  </div>
</section>

<!-- ============ ABOUT SECTIONS (admin-managed, freely reorderable) ============ -->
<?php
$sectionAlt = false;
$secCount = count($aboutSections);
$si = 0;
while ($si < $secCount):
  $cur = $aboutSections[$si];
  $sectionAlt = !$sectionAlt;
  $sectionClass = 'section' . ($sectionAlt ? ' section-alt' : '');
?>
  <section class="<?= $sectionClass ?>">
    <div class="container">
      <?= renderOneAboutSection($cur, $lang, $t, $db, true) ?>
    </div>
  </section>
<?php
  $si++;
endwhile;
?>

<!-- ============ LEADERSHIP ============ -->
<?php if ($deans): ?>
<section class="section section-alt">
  <div class="container">
    <div class="section-header center">
      <h2><?= $t('ผู้บริหาร') ?></h2>
      <p><?= $t('ทีมผู้บริหารคณะ') ?></p>
    </div>
    <div class="exec-scroll-wrap">
      <button type="button" class="exec-scroll-btn is-prev" data-exec-scroll="prev" aria-label="<?= $t('ก่อนหน้า') ?>"><?= icon('chevron-left') ?></button>
      <div class="exec-scroll">
        <?php foreach ($deans as $d):
          $d['tag_label'] = $d['campus_name'] ?? null;
          echo staffCard($d, $lang);
        endforeach; ?>
      </div>
      <button type="button" class="exec-scroll-btn is-next" data-exec-scroll="next" aria-label="<?= $t('ถัดไป') ?>"><?= icon('chevron-right') ?></button>
    </div>
    <div style="text-align:center;margin-top:28px">
      <a href="<?= BASE_URL ?>/staff.php?lang=<?= e($lang) ?>" class="btn btn-primary"><?= $t('ดูบุคลากรทั้งหมด') ?> <?= icon('arrow-right', 'icon-sm') ?></a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ CTA ============ -->
<section class="cta-band">
  <div class="container reveal">
    <h2><?= $t('พร้อมที่จะเข้าร่วม BAIT?') ?></h2>
    <p><?= $t('สำรวจหลักสูตรของเราและเริ่มต้นการเดินทางกับเรา') ?></p>
    <div class="cluster gap-3" style="justify-content:center">
      <a href="<?= BASE_URL ?>/programs.php?lang=<?= e($lang) ?>" class="btn btn-light"><?= icon('book', 'icon-sm') ?> <?= $t('ดูหลักสูตร') ?></a>
      <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" class="btn btn-on-dark"><?= icon('mail', 'icon-sm') ?> <?= $t('ติดต่อเรา') ?></a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/layout/footer.php'; ?>

<?php
/**
 * BAIT - Programs Page (Phase 3 rebuild)
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

// getStr: ?major[]=x is not a string (an array cast to int is 1, which used to filter by major id 1)
$levelId    = (int)getStr('level');
$campusId   = (int)getStr('campus');
$majorParam = getStr('major');
// "none" is the บัณฑิตศึกษา (graduate studies) filter — programs with no
// สาขาวิชา assigned (ป.โท/ป.เอก that stand on their own, not tied to any one
// undergraduate major). Anything else is a normal numeric major_id.
$majorId    = $majorParam === 'none' ? 'none' : (int)$majorParam;

$where = '1=1'; $params = [];   // programmes closed for applications are listed too, marked "ปิดรับสมัคร" (programCard)
if ($levelId)  { $where .= ' AND p.level_id = ?';  $params[] = $levelId; }
if ($campusId) {
    // A program with no rows in program_campuses is offered at every campus
    // (same convention as the admin form's "ไม่เลือกศูนย์ = ทุกศูนย์") — the
    // legacy single programs.campus_id column this used to filter on isn't
    // kept in sync with that table any more, so filtering on it silently
    // excluded every program edited through the current admin form.
    $where .= ' AND (EXISTS (SELECT 1 FROM program_campuses pc WHERE pc.program_id = p.program_id AND pc.campus_id = ?)
                 OR NOT EXISTS (SELECT 1 FROM program_campuses pc2 WHERE pc2.program_id = p.program_id))';
    $params[] = $campusId;
}
if ($majorId === 'none') { $where .= ' AND p.major_id IS NULL'; }
elseif ($majorId)        { $where .= ' AND p.major_id = ?'; $params[] = $majorId; }

$programs = $db->fetchAll(
    "SELECT p.*, m.major_name, el.level_name, el.level_abbr, el.level_order,
            GROUP_CONCAT(c.campus_name ORDER BY c.campus_id SEPARATOR ', ') AS campus_names
     FROM programs p
     LEFT JOIN majors m ON m.major_id = p.major_id
     JOIN education_levels el ON el.level_id = p.level_id
     LEFT JOIN program_campuses pc ON pc.program_id = p.program_id
     LEFT JOIN campuses c ON c.campus_id = pc.campus_id
     WHERE $where
     GROUP BY p.program_id
     ORDER BY el.level_order ASC, m.major_name ASC, p.is_active DESC",
    $params
);
$levels   = $db->fetchAll("SELECT * FROM education_levels ORDER BY level_order");
$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$majors   = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");

$grouped = [];
foreach ($programs as $p) { $grouped[$p['level_name']][] = $p; }

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Programs' : 'หลักสูตร');
$pageTitle = $lang === 'en' ? 'Academic Programs' : 'หลักสูตรการศึกษา';
$metaDesc = $lang === 'en'
    ? 'Browse all programs of the Faculty of Business Administration and Information Technology, from vocational to doctoral level. Filter by level, major and campus.'
    : 'รวมหลักสูตรของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ ตั้งแต่ ปวช. ปวส. ปริญญาตรี ปริญญาโท ถึงปริญญาเอก ค้นหาตามระดับการศึกษา สาขาวิชา และศูนย์พื้นที่';

$schemaJson = json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',
    'name'     => 'BAIT Programs',
    'itemListElement' => array_values(array_map(fn($p, $i) => [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'item'     => ['@type' => 'Course', 'name' => $p['program_name'], 'provider' => ['@type' => 'Organization', 'name' => 'BAIT RMUTSB']],
    ], $programs, array_keys($programs))),
], JSON_UNESCAPED_UNICODE);

$activePage = 'programs';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('หลักสูตร')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('หลักสูตรการศึกษา') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section" style="padding-top:32px">

  <form class="filter-bar">
    <input type="hidden" name="lang" value="<?= e($lang) ?>">
    <span class="filter-label"><?= icon('filter') ?> <?= $t('กรอง:') ?></span>
    <select class="field-select" name="level" data-filter-select="level" aria-label="<?= e($t('ระดับการศึกษา')) ?>">
      <option value=""><?= $t('ทุกระดับ') ?></option>
      <?php foreach ($levels as $l): ?>
      <option value="<?= (int)$l['level_id'] ?>" <?= $levelId == $l['level_id'] ? 'selected' : '' ?>><?= e($t($l['level_name'])) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="field-select" name="campus" data-filter-select="campus" aria-label="<?= e($t('ศูนย์พื้นที่')) ?>">
      <option value=""><?= $t('ทุกศูนย์') ?></option>
      <?php foreach ($campuses as $c): ?>
      <option value="<?= (int)$c['campus_id'] ?>" <?= $campusId == $c['campus_id'] ? 'selected' : '' ?>><?= e($t($c['campus_name'])) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="field-select" name="major" data-filter-select="major" aria-label="<?= e($t('สาขาวิชา')) ?>">
      <option value=""><?= $t('ทุกสาขา') ?></option>
      <?php foreach ($majors as $m): ?>
      <option value="<?= (int)$m['major_id'] ?>" <?= $majorId == $m['major_id'] ? 'selected' : '' ?>><?= e($t($m['major_name'])) ?></option>
      <?php endforeach; ?>
      <option value="none" <?= $majorId === 'none' ? 'selected' : '' ?>><?= $t('บัณฑิตศึกษา') ?></option>
    </select>
    <noscript><button type="submit" class="btn btn-primary btn-sm"><?= $t('กรอง') ?></button></noscript>
    <?php if ($levelId || $campusId || $majorId): ?>
    <a href="?lang=<?= e($lang) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> <?= $t('ล้างตัวกรอง') ?></a>
    <?php endif; ?>
    <span class="filter-count"><?= count($programs) ?> <?= $t('หลักสูตร') ?></span>
  </form>

  <?php if (empty($programs)): ?>
  <div class="empty-state"><?= icon('book') ?><div><?= $t('ไม่พบหลักสูตร') ?></div>
    <?php if ($levelId || $campusId || $majorId): ?>
    <small class="text-faint"><?= $t('ลองเปลี่ยนหรือล้างตัวกรอง') ?></small>
    <a href="?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm" style="margin-top:12px"><?= $t('ล้างตัวกรอง') ?></a>
    <?php endif; ?>
  </div>
  <?php else: foreach ($grouped as $levelName => $progs): ?>
  <div class="reveal" style="margin-bottom:48px">
    <div class="staff-cluster-heading">
      <div class="staff-cluster-icon"><?= icon('graduation-cap') ?></div>
      <div>
        <h2 style="font-size:1.3rem;color:var(--primary);margin:0"><?= e($t($levelName)) ?></h2>
        <small class="text-faint"><?= count($progs) ?> <?= $t('หลักสูตร') ?></small>
      </div>
    </div>
    <div class="grid grid-3">
      <?php foreach ($progs as $p): echo programCard($p, $lang); endforeach; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

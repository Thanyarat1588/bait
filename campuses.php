<?php
/**
 * BAIT - Campuses landing page: interactive map. Each pin (and the list
 * beside it) selects a campus and shows a summary panel; the panel's main
 * button leads to campus-detail.php, which holds the full per-campus page.
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

// Pin positions on the map (assets/img/campus-map.svg), as % of its width/
// height, keyed by campus_id. They come from each campus's real latitude/
// longitude (the Google Maps links in campuses.map_url) run through the
// same projection the SVG was drawn with. The two Ayutthaya campuses are
// only ~4.5 km apart, which stacks their pins on top of each other at phone
// width, so those two are nudged ~4% apart horizontally to stay tappable.
// A campus with no entry here still gets a pin (spread along the bottom
// edge) so a newly added campus never silently vanishes.
$pinPositions = [
    1 => [67.5, 42.2],  // ศูนย์พระนครศรีอยุธยา วาสุกรี (real: 71.5, 42.2)
    2 => [79.5, 40.8],  // ศูนย์พระนครศรีอยุธยา หันตรา (real: 75.5, 40.8)
    3 => [63.3, 82.0],  // ศูนย์นนทบุรี
    4 => [26.0, 12.9],  // ศูนย์สุพรรณบุรี
];

// Province names drawn over the map: [Thai, English, x%, y%, is a campus province].
// English is fixed here rather than machine-translated: single proper nouns
// come back from the translator as nonsense ("Lop County", "Suphanburi FC.").
$provinceLabels = [
    ['สุพรรณบุรี', 'Suphan Buri', 28.3, 33.2, true], ['พระนครศรีอยุธยา', 'Ayutthaya', 69.6, 50.5, true], ['นนทบุรี', 'Nonthaburi', 55.5, 75.5, true],
    ['อ่างทอง', 'Ang Thong', 50.3, 20.9, false], ['สิงห์บุรี', 'Sing Buri', 51.8, 7.3, false], ['ลพบุรี', 'Lop Buri', 83, 8.5, false],
    ['สระบุรี', 'Saraburi', 89, 18.5, false], ['ปทุมธานี', 'Pathum Thani', 70.1, 69, false], ['กรุงเทพมหานคร', 'Bangkok', 67.3, 91.2, false],
    ['นครปฐม', 'Nakhon Pathom', 31.9, 87.7, false], ['กาญจนบุรี', 'Kanchanaburi', 9, 45.7, false],
];

$mapData = [];
foreach ($campuses as $i => $c) {
    $id = (int)$c['campus_id'];
    [$x, $y] = $pinPositions[$id] ?? [15 + ($i * 18) % 70, 93];
    $mapData[] = [
        'id'       => $id,
        'name'     => $t($c['campus_name']),
        'image'    => $c['image'] ? UPLOAD_URL . '/' . $c['image'] : '',
        // The photo's own aspect ratio, so the card can show the whole image
        // (no crop) and reserve its height before it has finished loading.
        'ratio'    => ($d = $c['image'] ? @getimagesize(UPLOAD_PATH . '/' . $c['image']) : false) ? $d[0] . '/' . $d[1] : '',
        'x'        => $x,
        'y'        => $y,
        'detail'   => BASE_URL . '/campus-detail.php?lang=' . $lang . '#campus-' . $id,
        'map'      => $c['map_url'] ?? '',
    ];
}
$mapLabels = [
    'detail' => $t('ดูรายละเอียด'),
    'map'    => $t('เปิดแผนที่'),
    'close'  => $t('ปิด'),
];

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Campuses' : 'ศูนย์พื้นที่');
$pageTitle = $lang === 'en' ? 'Our Campuses' : 'ศูนย์พื้นที่';
$metaDesc = $lang === 'en'
    ? 'Map of the 4 campuses of the Faculty: Ayutthaya Wasukri, Hantra, Nonthaburi and Suphan Buri. Select a campus to see details and directions.'
    : 'แผนที่ศูนย์พื้นที่ทั้ง 4 แห่งของคณะ ได้แก่ พระนครศรีอยุธยา วาสุกรี, หันตรา, นนทบุรี และสุพรรณบุรี เลือกศูนย์บนแผนที่เพื่อดูข้อมูลและเส้นทาง';

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
    ['label' => $t('ศูนย์พื้นที่')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('ศูนย์พื้นที่') ?></h1>
    <p class="text-faint" style="margin-top:4px"><?= $t('เลือกศูนย์บนแผนที่เพื่อดูข้อมูลเบื้องต้น') ?></p>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
<?php if ($campuses): ?>
  <div class="cmap-grid" data-campus-map='<?= e(json_encode(['campuses' => $mapData, 'labels' => $mapLabels], JSON_UNESCAPED_UNICODE)) ?>'>

    <div class="cmap-stage reveal" data-cmap-stage>
      <img class="cmap-bg" src="<?= BASE_URL ?>/assets/img/campus-map.svg" alt="" width="440" height="560" decoding="async">
      <?php foreach ($provinceLabels as [$lTh, $lEn, $lx, $ly, $lHi]): ?>
      <span class="cmap-label<?= $lHi ? ' cmap-label--hi' : '' ?>" style="left:<?= $lx ?>%;top:<?= $ly ?>%"><?= e($lang === 'en' ? $lEn : $lTh) ?></span>
      <?php endforeach; ?>

      <?php foreach ($mapData as $m): ?>
      <a href="<?= e($m['detail']) ?>" class="cmap-pin" data-campus-id="<?= (int)$m['id'] ?>" style="left:<?= (float)$m['x'] ?>%;top:<?= (float)$m['y'] ?>%" aria-label="<?= e($m['name']) ?>">
        <span class="cmap-dot"></span><span class="cmap-tip"><?= e($m['name']) ?></span>
      </a>
      <?php endforeach; ?>
      <span class="cmap-credit">© OpenStreetMap contributors</span>
    </div>

    <aside class="cmap-panel reveal">
      <ul class="cmap-list">
        <?php foreach ($mapData as $m): ?>
        <li><a href="<?= e($m['detail']) ?>" data-campus-id="<?= (int)$m['id'] ?>"><i></i><span><?= e($m['name']) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </aside>

  </div>
<?php else: ?>
  <div class="empty-state"><?= icon('building') ?><div><?= $t('ยังไม่มีข้อมูลศูนย์พื้นที่') ?></div></div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

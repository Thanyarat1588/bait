<?php
/**
 * BAIT - E-Bidding: the faculty's procurement announcements (ประกาศประกวดราคาด้วยวิธีประกวดราคาอิเล็กทรอนิกส์),
 * newest first, 20 per page, with a title search and a year filter. Each one links out to its documents on Google Drive.
 * Rows come from admin → E-Bidding (table `ebidding`, migration 20).
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

$search = getStr('search');
$year   = (int)getStr('year');   // Gregorian year (the list shows it as a Buddhist-era year on Thai pages)
$status = in_array(getStr('status'), ['open', 'draft'], true) ? getStr('status') : '';   // open = ประกาศ, draft = ร่างประกาศ
$page   = max(1, (int)getStr('page'));
$filtered = $search !== '' || $year || $status !== '';
$rows = []; $years = []; $total = 0;
$pager = paginate(0, 20, 1);
if (hasEbidding()) {
    $years = [];   // [year => number of announcements], for the year drop-down
    foreach ($db->fetchAll("SELECT YEAR(announce_date) AS y, COUNT(*) AS c FROM ebidding WHERE is_active = 1 GROUP BY y ORDER BY y DESC") as $yr) $years[(int)$yr['y']] = (int)$yr['c'];
    if ($year && !isset($years[$year])) $year = 0;
    $where = 'is_active = 1'; $params = [];
    if ($search !== '') { $where .= ' AND title LIKE ?'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }
    if ($year) { $where .= ' AND YEAR(announce_date) = ?'; $params[] = $year; }
    if ($status !== '') $where .= $status === 'draft' ? ' AND is_draft = 1' : ' AND is_draft = 0';
    $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM ebidding WHERE $where", $params)['c'] ?? 0);
    $pager = paginate($total, 20, $page, '?' . http_build_query(array_filter(['search' => $search, 'year' => $year ?: '', 'status' => $status, 'lang' => $lang])) . '&page={page}');
    $rows = $db->fetchAll("SELECT * FROM ebidding WHERE $where ORDER BY announce_date DESC, bid_id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}", $params);
}
$yearLabel = fn(int $y) => (string)($lang === 'en' ? $y : $y + 543);

trackPageView($_SERVER['REQUEST_URI'], 'E-Bidding');
$pageTitle = 'E-Bidding';
$metaDesc = $lang === 'en'
    ? 'Electronic bidding (e-bidding) procurement announcements of the Faculty of Business Administration and Information Technology.'
    : 'ประกาศประกวดราคาด้วยวิธีประกวดราคาอิเล็กทรอนิกส์ (e-bidding) ของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ พร้อมเอกสารรายละเอียดให้ดาวน์โหลด';
$activePage = 'ebidding';
include __DIR__ . '/layout/header.php';
$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => 'E-Bidding'],
];
?>
<section class="page-hero">
  <div class="container">
    <h1>E-Bidding</h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <p class="bid-intro"><?= $t('ประกาศประกวดราคาด้วยวิธีประกวดราคาอิเล็กทรอนิกส์ (e-bidding) ของคณะ กดปุ่มเอกสารเพื่อเปิดรายละเอียดและดาวน์โหลดเอกสาร') ?></p>

  <form class="filter-bar bid-filter" method="GET" role="search">
    <input type="hidden" name="lang" value="<?= e($lang) ?>">
    <div class="search-box search-box--grow">
      <?= icon('search') ?>
      <input type="search" name="search" aria-label="<?= e($t('ค้นหาประกาศ')) ?>" placeholder="<?= e($t('ค้นหาประกาศ...')) ?>" value="<?= e($search) ?>">
    </div>
    <?php if ($years): ?>
    <select name="year" class="field-select" aria-label="<?= e($t('ปีที่ประกาศ')) ?>" onchange="this.form.submit()">
      <option value=""><?= $t('ทุกปี') ?> (<?= array_sum($years) ?>)</option>
      <?php foreach ($years as $y => $c): ?><option value="<?= $y ?>"<?= $year === $y ? ' selected' : '' ?>><?= $t('ปี') ?> <?= $yearLabel($y) ?> (<?= $c ?>)</option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="status" class="field-select" aria-label="<?= e($t('สถานะ')) ?>" onchange="this.form.submit()">
      <option value=""><?= $t('ทุกสถานะ') ?></option>
      <option value="open"<?= $status === 'open' ? ' selected' : '' ?>><?= $t('ประกาศ') ?></option>
      <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>><?= $t('ร่างประกาศ') ?></option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= $t('ค้นหา') ?></button>
    <?php if ($filtered): ?>
    <a href="?lang=<?= e($lang) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> <?= $t('ล้างตัวกรอง') ?></a>
    <?php endif; ?>
  </form>

  <?php if ($filtered): ?>
  <p class="text-muted" style="margin:-8px 0 16px;font-size:.88rem"><?= $lang === 'en' ? "{$total} announcements found" : "พบ {$total} ประกาศ" ?><?= $year ? ' · ' . $t('ปี') . ' ' . $yearLabel($year) : '' ?><?= $status !== '' ? ' · ' . ($status === 'draft' ? $t('ร่างประกาศ') : $t('ประกาศ')) : '' ?></p>
  <?php endif; ?>

  <?php if (!$rows): ?>
  <div class="empty-state"><?= icon('briefcase') ?><div><?= $filtered ? $t('ไม่พบประกาศที่ค้นหา') : $t('ยังไม่มีประกาศ') ?></div></div>
  <?php else: ?>
  <ol class="bid-list" start="<?= $pager['offset'] + 1 ?>">
    <?php foreach ($rows as $i => $r): $url = isHttpUrl($r['link_url']) ? $r['link_url'] : ''; ?>
    <li class="bid-item">
      <span class="bid-badge<?= $r['is_draft'] ? '' : ' is-open' ?>"><?= $r['is_draft'] ? $t('ร่างประกาศ') : $t('ประกาศ') ?></span>
      <span class="bid-no" aria-hidden="true"><?= $pager['offset'] + $i + 1 ?></span>
      <h2 class="bid-title"><?= e($t($r['title'])) ?></h2>
      <span class="bid-date"><?= icon('calendar', 'icon-sm') ?> <?= e(thaiDate($r['announce_date'])) ?></span>
      <?php if ($url): ?>
      <a class="btn btn-secondary btn-sm bid-doc" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= icon('download', 'icon-sm') ?> <?= $t('เอกสารรายละเอียด / ดาวน์โหลด') ?><span class="visually-hidden"> (<?= $t('เปิดในแท็บใหม่') ?>)</span></a>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <div style="margin-top:32px"><?= paginationNav($pager) ?></div>
</div>
<?php include __DIR__ . '/layout/footer.php'; ?>

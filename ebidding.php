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
$fy     = (int)getStr('fy');   // ปีงบประมาณ, Buddhist era as stored (2570); English pages show it as FY 2027
// Every bid goes up first as a ร่างประกาศ (เปิด / ปิดรับฟังความคิดเห็น), then as the ประกาศ itself (เปิด / ปิดประมูล):
// the four statuses are is_draft x is_closed. [filter value => [is_draft, is_closed, label]]
$statuses = [
    'hearing-open'   => [1, 0, 'เปิดรับฟังความคิดเห็น'],
    'hearing-closed' => [1, 1, 'ปิดรับฟังความคิดเห็น'],
    'bid-open'       => [0, 0, 'เปิดประมูล'],
    'bid-closed'     => [0, 1, 'ปิดประมูล'],
];
$st     = isset($statuses[getStr('st')]) ? getStr('st') : '';
$page   = max(1, (int)getStr('page'));
$filtered = $search !== '' || $fy || $st !== '';
$rows = []; $years = []; $total = 0;
$pager = paginate(0, 20, 1);
if (hasEbidding()) {
    $years = [];   // [fiscal year => number of announcements], for the drop-down
    foreach ($db->fetchAll("SELECT fiscal_year AS y, COUNT(*) AS c FROM ebidding WHERE is_active = 1 AND fiscal_year > 0 GROUP BY y ORDER BY y DESC") as $yr) $years[(int)$yr['y']] = (int)$yr['c'];
    if ($fy && !isset($years[$fy])) $fy = 0;
    $where = 'is_active = 1'; $params = [];
    if ($search !== '') { $where .= ' AND title LIKE ?'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }
    if ($fy) { $where .= ' AND fiscal_year = ?'; $params[] = $fy; }
    if ($st !== '') { $where .= ' AND is_draft = ? AND is_closed = ?'; $params[] = $statuses[$st][0]; $params[] = $statuses[$st][1]; }
    $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM ebidding WHERE $where", $params)['c'] ?? 0);
    $pager = paginate($total, 20, $page, '?' . http_build_query(array_filter(['search' => $search, 'fy' => $fy ?: '', 'st' => $st, 'lang' => $lang])) . '&page={page}');
    $rows = $db->fetchAll("SELECT * FROM ebidding WHERE $where ORDER BY announce_date DESC, bid_id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}", $params);
}
$fyYear  = fn(int $y) => (string)($lang === 'en' ? $y - 543 : $y);
$fyLabel = fn(int $y) => ($lang === 'en' ? 'FY ' : 'ปีงบประมาณ ') . $fyYear($y);
$statusOf = fn(array $r) => (int)$r['is_draft'] ? ((int)$r['is_closed'] ? 'hearing-closed' : 'hearing-open') : ((int)$r['is_closed'] ? 'bid-closed' : 'bid-open');

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
    <select name="fy" class="field-select" aria-label="<?= e($t('ปีงบประมาณ')) ?>" onchange="this.form.submit()">
      <option value=""><?= $t('ทุกปีงบประมาณ') ?> (<?= array_sum($years) ?>)</option>
      <?php foreach ($years as $y => $c): ?><option value="<?= $y ?>"<?= $fy === $y ? ' selected' : '' ?>><?= e($fyLabel($y)) ?> (<?= $c ?>)</option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="st" class="field-select" aria-label="<?= e($t('สถานะ')) ?>" onchange="this.form.submit()">
      <option value=""><?= $t('ทุกสถานะ') ?></option>
      <optgroup label="<?= e($t('ร่างประกาศ')) ?>">
        <?php foreach (['hearing-open', 'hearing-closed'] as $k): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= $t($statuses[$k][2]) ?></option><?php endforeach; ?>
      </optgroup>
      <optgroup label="<?= e($t('ประกาศ')) ?>">
        <?php foreach (['bid-open', 'bid-closed'] as $k): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= $t($statuses[$k][2]) ?></option><?php endforeach; ?>
      </optgroup>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= $t('ค้นหา') ?></button>
    <?php if ($filtered): ?>
    <a href="?lang=<?= e($lang) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> <?= $t('ล้างตัวกรอง') ?></a>
    <?php endif; ?>
  </form>

  <?php if ($filtered): ?>
  <p class="text-muted" style="margin:-8px 0 16px;font-size:.88rem"><?= $lang === 'en' ? "{$total} announcements found" : "พบ {$total} ประกาศ" ?><?= $fy ? ' · ' . e($fyLabel($fy)) : '' ?><?= $st !== '' ? ' · ' . $t($statuses[$st][2]) : '' ?></p>
  <?php endif; ?>

  <?php if (!$rows): ?>
  <div class="empty-state"><?= icon('briefcase') ?><div><?= $filtered ? $t('ไม่พบประกาศที่ค้นหา') : $t('ยังไม่มีประกาศ') ?></div></div>
  <?php else: ?>
  <ul class="bid-list">
    <?php foreach ($rows as $r): $url = isHttpUrl($r['link_url']) ? $r['link_url'] : ''; $s = $statusOf($r); ?>
    <li class="bid-item">
      <span class="bid-fy"><?php if ((int)$r['fiscal_year'] > 0): ?><small><?= $lang === 'en' ? 'FY' : 'ปีงบประมาณ' ?></small><?= e($fyYear((int)$r['fiscal_year'])) ?><?php endif; ?></span>
      <div class="bid-head">
        <span class="bid-pill <?= $r['is_draft'] ? 'is-draft' : 'is-announce' ?> <?= $r['is_closed'] ? 'is-closed' : 'is-open' ?>"><span class="bid-pill-type"><?= $r['is_draft'] ? $t('ร่างประกาศ') : $t('ประกาศ') ?></span><span class="bid-pill-status"><?= $t($statuses[$s][2]) ?></span></span>
        <h2 class="bid-title"><?= e($t($r['title'])) ?></h2>
      </div>
      <span class="bid-date"><?= icon('calendar', 'icon-sm') ?> <?= e(thaiDate($r['announce_date'])) ?></span>
      <?php if ($url): ?>
      <a class="btn btn-secondary btn-sm bid-doc" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= icon('download', 'icon-sm') ?> <?= $t('เอกสารรายละเอียด / ดาวน์โหลด') ?><span class="visually-hidden"> (<?= $t('เปิดในแท็บใหม่') ?>)</span></a>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div style="margin-top:32px"><?= paginationNav($pager) ?></div>
</div>
<?php include __DIR__ . '/layout/footer.php'; ?>

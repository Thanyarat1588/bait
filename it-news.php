<?php
/**
 * BAIT - ข่าวไอทีย้อนหลัง: the headlines of the last itNewsDays() days (7) read from the Thai IT-news feeds, newest first,
 * grouped by day, 30 per page, filter by label. Each headline links out to the full article on the source site.
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

$tag  = getStr('tag');
$page = max(1, (int)getStr('page'));
$rows = []; $tags = []; $total = 0;
$pager = paginate(0, 30, 1);
if (hasItNews()) {
    $since = itNewsSince();   // today + the 6 days before
    $tags = array_column($db->fetchAll('SELECT tag, COUNT(*) AS c FROM it_news WHERE is_hidden = 0 AND published_at >= ? GROUP BY tag ORDER BY c DESC', [$since]), 'c', 'tag');
    if ($tag !== '' && !isset($tags[$tag])) $tag = '';
    $where = 'is_hidden = 0 AND published_at >= ?' . ($tag !== '' ? ' AND tag = ?' : '');
    $params = $tag !== '' ? [$since, $tag] : [$since];
    $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM it_news WHERE $where", $params)['c'] ?? 0);
    $pager = paginate($total, 30, $page, '?' . http_build_query(array_filter(['tag' => $tag, 'lang' => $lang])) . '&page={page}');
    $rows = $db->fetchAll("SELECT * FROM it_news WHERE $where ORDER BY published_at DESC, item_id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}", $params);
}
$byDay = [];
foreach ($rows as $r) $byDay[substr($r['published_at'], 0, 10)][] = $r;

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'IT News' : 'ข่าวไอที');
$pageTitle = $lang === 'en' ? 'IT News' : 'ข่าวไอทีย้อนหลัง';
$metaDesc = $lang === 'en' ? 'Daily IT and technology headlines from Thai tech news sites.' : 'รวมหัวข้อข่าวไอทีและเทคโนโลยีรายวันจากเว็บข่าวไอทีไทย กดอ่านข่าวเต็มที่เว็บต้นทาง';
$activePage = 'news';
include __DIR__ . '/layout/header.php';
$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('ข่าวสาร'), 'url' => BASE_URL . '/news.php?lang=' . $lang],
    ['label' => $t('ข่าวไอทีย้อนหลัง')],
];
?>
<section class="page-hero">
  <div class="container">
    <h1><?= $t('ข่าวไอทีย้อนหลัง') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <p class="text-muted" style="margin-top:0;font-size:.9rem"><?= $lang === 'en'
      ? 'IT headlines of the last 7 days from Blognone, DroidSans and TechHub, updated every few hours. Open a headline to read the full article on the source site.'
      : 'ข่าวไอที 7 วันล่าสุดจาก Blognone, DroidSans และ TechHub อัปเดตทุกไม่กี่ชั่วโมง กดหัวข้อเพื่ออ่านข่าวเต็มที่เว็บต้นทาง' ?></p>

  <div id="itxArchive" data-itnews-live><?php /* swapped for a fresh copy when a background read adds headlines */ ?>
  <?php if ($tags): ?>
  <div class="tab-pills" style="margin:16px 0 8px">
    <a href="?lang=<?= e($lang) ?>" class="tab-pill <?= $tag === '' ? 'is-active' : '' ?>"><?= $t('ทั้งหมด') ?> (<?= array_sum($tags) ?>)</a>
    <?php foreach ($tags as $tg => $c): ?>
    <a href="?<?= e(http_build_query(['tag' => $tg, 'lang' => $lang])) ?>" class="tab-pill <?= $tag === $tg ? 'is-active' : '' ?>"><?= e($lang === 'en' ? translateFixed($tg, 'en') : $tg) ?> (<?= (int)$c ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
  <div class="empty-state"><?= icon('newspaper') ?><div><?= $t('ยังไม่มีข่าวไอที') ?></div></div>
  <?php endif; ?>

  <?php foreach ($byDay as $day => $items): ?>
  <h2 class="itx-day"><?= e(thaiDate($day)) ?></h2>
  <div class="itx-grid">
    <?php foreach ($items as $it): echo itNewsCard($it, $lang); endforeach; ?>
  </div>
  <?php endforeach; ?>

  <div style="margin-top:32px"><?= paginationNav($pager) ?></div>
  </div>
</div>
<?= itNewsFetchLater() ?>
<?php include __DIR__ . '/layout/footer.php'; ?>

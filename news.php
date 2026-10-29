<?php
/**
 * BAIT - News Listing Page (Phase 3 rebuild)
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

$catId   = (int)($_GET['cat'] ?? 0);
$search  = getStr('search');
$tagFilter = getStr('tag');
$sdgFilter = (int)getStr('sdg');   // one of the 17 SDGs (news_sdgs), 0 = all
if (!isset(sdgList()[$sdgFilter]) || !hasNewsSdgs()) $sdgFilter = 0;
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

// Each filter as its own [sql, param], so the counts beside the category pills and on the SDG tiles can follow all the
// OTHER filters (search word, tag, ...) — "(3)" then means "3 items if you click this", not the site-wide total.
$conds = [];
if ($catId)  $conds['cat']    = ['n.category_id = ?', $catId];
if ($search) $conds['search'] = ['n.title LIKE ?', '%' . addcslashes($search, '%_\\') . '%'];   // a typed % or _ searches for that character (as in search.php)
if ($tagFilter) {
    // tags is a flat "a, b, c" column, not a separate table — match a
    // whole tag, not a substring of one (e.g. "ครู" must not also match
    // inside "ไหว้ครู") by normalizing both sides to a comma-delimited
    // list with no spaces and requiring the tag to sit between two commas.
    $conds['tag'] = ["CONCAT(',', REPLACE(n.tags, ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')", addcslashes(str_replace(' ', '', $tagFilter), '%_\\')];   // ?tag=% must not match every item
}
if ($sdgFilter) $conds['sdg'] = ['n.news_id IN (SELECT news_id FROM news_sdgs WHERE sdg = ?)', $sdgFilter];
/** [where, params] of the published news matching every filter except $skip. */
$whereExcept = function (string $skip = '') use ($conds): array {
    $w = "n.status = 'เผยแพร่'"; $p = [];
    foreach ($conds as $k => [$sql, $val]) if ($k !== $skip) { $w .= ' AND ' . $sql; $p[] = $val; }
    return [$w, $p];
};
[$where, $params] = $whereExcept();

$total = (int)($db->fetchOne("SELECT COUNT(*) as c FROM news n WHERE $where", $params)['c'] ?? 0);
$pager = paginate($total, $perPage, $page, '?cat=' . $catId . '&search=' . urlencode($search) . '&tag=' . urlencode($tagFilter) . ($sdgFilter ? '&sdg=' . $sdgFilter : '') . '&lang=' . $lang . '&page={page}');

$newsList = $db->fetchAll(
    "SELECT n.news_id, n.title, n.image, n.slug, n.created_at, n.view_count, n.tags,
            c.category_name, c.color, c.category_id
     FROM news n JOIN categories c ON c.category_id = n.category_id
     WHERE $where ORDER BY n.created_at DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);
$newsList = attachNewsSdgs($newsList);
// published news per goal, for the SDG tiles in the sidebar
$sdgCounts = [];
if (hasNewsSdgs()) {
    try {
        [$sw, $sp] = $whereExcept('sdg');
        foreach ($db->fetchAll("SELECT s.sdg, COUNT(*) AS c FROM news_sdgs s JOIN news n ON n.news_id = s.news_id WHERE $sw GROUP BY s.sdg", $sp) as $r) $sdgCounts[(int)$r['sdg']] = (int)$r['c'];
    } catch (Throwable $e) { $sdgCounts = []; }
}
// site-wide counts: the sidebar list (its links start a fresh filter)
$categories = $db->fetchAll("SELECT c.*, COUNT(n.news_id) as cnt
     FROM categories c LEFT JOIN news n ON n.category_id=c.category_id AND n.status='เผยแพร่'
     GROUP BY c.category_id ORDER BY c.category_name");
// the pills above the list keep the other filters, so their counts do too
[$cw, $cp] = $whereExcept('cat');
$pillCounts = [];
foreach ($db->fetchAll("SELECT n.category_id, COUNT(*) AS c FROM news n WHERE $cw GROUP BY n.category_id", $cp) as $r) $pillCounts[(int)$r['category_id']] = (int)$r['c'];

$featuredNews = $db->fetchAll(
    "SELECT n.news_id, n.title, n.image, n.created_at, c.category_name, c.color
     FROM news n JOIN categories c ON c.category_id=n.category_id
     WHERE n.status='เผยแพร่' ORDER BY n.view_count DESC LIMIT 5"
);

// IT NEWS วันนี้ (sliding card at the top of the news column): only on the plain first page (not while searching / filtering);
// new headlines are read in the background after the page has loaded (every hour and at the first visit of a new day)
$showItNews = $page === 1 && !$search && !$catId && $tagFilter === '' && !$sdgFilter;
$itNews = $showItNews ? itNewsPick(6) : [];

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'News' : 'ข่าวสาร');

$pageTitle = $lang === 'en' ? 'News & Updates' : 'ข่าวสาร';
$metaDesc = $lang === 'en'
    ? 'Latest news, announcements and student activities from the Faculty of Business Administration and Information Technology.'
    : 'ข่าวสารและกิจกรรมล่าสุดของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ ประกาศ ข่าวประชาสัมพันธ์ และกิจกรรมของนักศึกษา';
$activePage = 'news';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('ข่าวสาร')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('ข่าวสารและกิจกรรม') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<?= itNewsFetchLater() ?>

<div class="container section">
  <?= itNewsCarousel($itNews, $lang) ?>
  <div class="layout-with-sidebar">

    <div>
      <!-- Search + category filter -->
      <form class="filter-bar" method="GET" role="search">
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
        <?php if ($catId): ?><input type="hidden" name="cat" value="<?= (int)$catId ?>"><?php endif; ?>
        <?php if ($tagFilter !== ''): ?><input type="hidden" name="tag" value="<?= e($tagFilter) ?>"><?php endif; ?>
        <?php if ($sdgFilter): ?><input type="hidden" name="sdg" value="<?= (int)$sdgFilter ?>"><?php endif; ?>
        <div class="search-box search-box--grow">
          <?= icon('search') ?>
          <input type="search" name="search" aria-label="<?= e($t('ค้นหาข่าวสาร')) ?>" placeholder="<?= $t('ค้นหาข่าวสาร...') ?>" value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><?= $t('ค้นหา') ?></button>
        <?php if ($search || $catId || $tagFilter || $sdgFilter): ?>
        <a href="?lang=<?= e($lang) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> <?= $t('ล้างตัวกรอง') ?></a>
        <?php endif; ?>
      </form>

      <?php if ($tagFilter): ?>
      <p class="text-muted" style="margin-bottom:16px;font-size:.88rem">
        <?= $lang === 'en' ? 'Filtered by tag:' : 'กรองตามแท็ก:' ?>
        <span class="tag-chip" style="pointer-events:none">#<?= e($tagFilter) ?></span>
        <a href="?lang=<?= e($lang) ?>" style="margin-left:6px"><?= $t('ล้างตัวกรอง') ?></a>
      </p>
      <?php endif; ?>

      <?php if ($sdgFilter): ?>
      <div class="text-muted" style="margin-bottom:16px;font-size:.88rem;display:flex;flex-wrap:wrap;align-items:center;gap:8px">
        <?= $lang === 'en' ? 'Filtered by SDG:' : 'กรองตามเป้าหมาย SDGs:' ?>
        <?= sdgBadges([$sdgFilter], $lang) ?>
        <a href="?<?= e(http_build_query(array_filter(['cat' => $catId ?: '', 'search' => $search, 'tag' => $tagFilter], fn($v) => $v !== '') + ['lang' => $lang])) ?>"><?= $t('ล้างตัวกรอง') ?></a>
      </div>
      <?php endif; ?>

      <div class="tab-pills" style="margin-bottom:24px">
        <?php $keep = array_filter(['search' => $search, 'tag' => $tagFilter, 'sdg' => $sdgFilter ?: ''], fn($v) => $v !== ''); ?>
        <a href="?<?= e(http_build_query($keep + ['lang' => $lang])) ?>" class="tab-pill <?= !$catId ? 'is-active' : '' ?>">
          <?= $t('ทั้งหมด') ?> (<?= number_format(array_sum($pillCounts)) ?>)
        </a>
        <?php foreach ($categories as $cat): ?>
        <a href="?<?= e(http_build_query(['cat' => (int)$cat['category_id']] + $keep + ['lang' => $lang])) ?>" class="tab-pill <?= $catId == $cat['category_id'] ? 'is-active' : '' ?>">
          <?= e($t($cat['category_name'])) ?> (<?= number_format($pillCounts[(int)$cat['category_id']] ?? 0) ?>)
        </a>
        <?php endforeach; ?>
      </div>

      <?php if ($search && $total): ?>
      <p class="text-muted" style="margin-bottom:16px;font-size:.88rem">
        <?= $lang === 'en' ? "Found {$total} results for \"" . e($search) . "\"" : "พบ {$total} ผลลัพธ์สำหรับ \"" . e($search) . "\"" ?>
      </p>
      <?php endif; ?>

      <h2 class="visually-hidden"><?= $t('รายการข่าวสาร') ?></h2>
      <div class="grid grid-2">
        <?php foreach ($newsList as $n): echo newsCard($n, $lang); endforeach; ?>
        <?php if (empty($newsList)): ?>
        <div class="empty-state" style="grid-column:1/-1">
          <?= icon('newspaper') ?>
          <div><?= $t('ไม่พบข่าวสาร') ?></div>
          <?php if ($search || $tagFilter || $catId || $sdgFilter): ?><small class="text-faint"><?= $t('ลองค้นหาด้วยคำอื่น') ?></small>
          <a href="?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm" style="margin-top:12px"><?= $t('ล้างตัวกรอง') ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Sidebar -->
    <aside class="sidebar-col">
    <div class="sidebar-sticky">
      <div class="sidebar-card">
        <h4><?= icon('flame') ?> <?= $t('ข่าวยอดนิยม') ?></h4>
        <?php foreach ($featuredNews as $i => $fn): ?>
        <a href="<?= BASE_URL ?>/news-detail.php?id=<?= (int)$fn['news_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none">
          <span class="side-rank rank-<?= $i + 1 ?>"><?= $i + 1 ?></span>
          <div>
            <p class="side-list-title" style="margin:0"><?= e($t($fn['title'])) ?></p>
            <small class="text-faint"><?= thaiDate($fn['created_at']) ?></small>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (hasNewsSdgs()): ?>
      <div class="sidebar-card">
        <h4><?= icon('target') ?> <?= $lang === 'en' ? 'Sustainable Development Goals (SDGs)' : 'เป้าหมายการพัฒนาที่ยั่งยืน (SDGs)' ?></h4>
        <div class="sdg-grid">
          <?php foreach (sdgList() as $no => $g): $cnt = $sdgCounts[$no] ?? 0; ?>
          <?php $sdgKeep = array_filter(['cat' => $catId ?: '', 'search' => $search, 'tag' => $tagFilter], fn($v) => $v !== ''); // a tile keeps the category / search / tag, as a category pill keeps the SDG ?>
          <a href="?<?= e(http_build_query($sdgFilter === $no ? $sdgKeep + ['lang' => $lang] : $sdgKeep + ['sdg' => $no, 'lang' => $lang])) ?>" class="sdg-tile<?= $sdgFilter === $no ? ' is-active' : '' ?><?= $cnt ? '' : ' is-empty' ?>" style="--sdg:<?= $g[0] ?>;--sdg-ink:<?= inkOn($g[0]) ?>" title="SDGs <?= $no ?>: <?= e($lang === 'en' ? $g[2] : $g[1]) ?> (<?= $cnt ?>)"<?= $sdgFilter === $no ? ' aria-current="true"' : '' ?>><?= $no ?><small><?= $cnt ?></small><span class="visually-hidden"> <?= e($lang === 'en' ? $g[2] : $g[1]) ?>, <?= $cnt ?> <?= $lang === 'en' ? 'news' : 'ข่าว' ?></span></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="sidebar-card">
        <h4><?= icon('tag') ?> <?= $t('หมวดหมู่') ?></h4>
        <?php foreach ($categories as $cat): ?>
        <a href="?cat=<?= (int)$cat['category_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none;align-items:center;justify-content:space-between">
          <span style="display:flex;align-items:center;gap:8px;font-size:.88rem;<?= $catId == $cat['category_id'] ? 'color:var(--primary);font-weight:700' : '' ?>">
            <span style="width:10px;height:10px;border-radius:50%;background:<?= e($cat['color'] ?? '#1558A6') ?>"></span>
            <?= e($t($cat['category_name'])) ?>
          </span>
          <span class="badge-soft"><?= $cat['cnt'] ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    </aside>

    <?php if ($pagerNav = paginationNav($pager)): ?>
    <div class="layout-with-sidebar__foot"><?= $pagerNav ?></div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

<?php
/**
 * BAIT - Site Search (Phase 3 new page)
 *
 * Cross-table keyword search using the existing news/articles/programs/staff
 * tables — no schema change required. Serves two purposes:
 *   1. ?ajax=1&q=...  -> JSON suggestions for the navbar live search (search.js)
 *   2. ?q=...          -> full results page
 *
 * Scope note: a dedicated search feature was not part of the Phase 1
 * approved backend scope; this was added because Phase 3 explicitly lists
 * "Search" as a required page. It only reads existing tables (LIKE queries,
 * parameterized) so it needed no schema changes — but the UX here (result
 * ranking, filters) should be confirmed with the faculty before Phase 4.
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

$q = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
$isAjax = isset($_GET['ajax']) && (($_GET['ajax'] ?? '') === '1');
$perPage = $isAjax ? 5 : 12;   // suggestions in the navbar box / cards per group on the results page

$results = ['news' => [], 'programs' => [], 'articles' => [], 'staff' => []];
$totals  = ['news' => 0, 'programs' => 0, 'articles' => 0, 'staff' => 0];
// each group has its own page number so paging one list does not reset the others
$pageKey = ['news' => 'np', 'programs' => 'pp', 'articles' => 'ap', 'staff' => 'sp'];
$pageNo  = [];
foreach ($pageKey as $g => $k) $pageNo[$g] = is_string($_GET[$k] ?? null) ? max(1, (int)$_GET[$k]) : 1;

/**
 * Rich-text rows (news, articles). SQL finds the candidates (title or body contains the words, newest first, at most 500) and returns
 * only their ids; a row that matched only through its body is kept only if the words are in the visible text, not inside a tag or
 * attribute. The bodies are read 20 at a time and thrown away again, so a broad search word never loads hundreds of full articles
 * into memory at once. Returns the matching ids in the original order.
 */
$richCapped = false;   // set when a group reached the 500-candidate cap, so the page can say only the newest are shown
$richHitIds = function (string $table, string $idCol, string $q, string $like) use ($db, &$richCapped): array {
    $cand = $db->fetchAll(
        "SELECT `$idCol` AS id, (title LIKE ?) AS in_title FROM `$table`
         WHERE status = 'เผยแพร่' AND (title LIKE ? OR content LIKE ?)
         ORDER BY created_at DESC LIMIT 500",
        [$like, $like, $like]
    );
    if (count($cand) >= 500) $richCapped = true;
    $keep = [];
    foreach ($cand as $c) if (!empty($c['in_title'])) $keep[(int)$c['id']] = true;
    $bodyOnly = [];
    foreach ($cand as $c) if (empty($c['in_title'])) $bodyOnly[] = (int)$c['id'];
    foreach (array_chunk($bodyOnly, 20) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($db->fetchAll("SELECT `$idCol` AS id, content FROM `$table` WHERE `$idCol` IN ($marks)", $chunk) as $r) {
            $text = html_entity_decode(strip_tags((string)$r['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (mb_stripos($text, $q) !== false) $keep[(int)$r['id']] = true;
        }
    }
    $ids = [];
    foreach ($cand as $c) if (isset($keep[(int)$c['id']])) $ids[] = (int)$c['id'];
    return $ids;
};
/** The page of results (only the columns the page shows) for these ids, in the same order. */
$richPage = function (string $table, string $idCol, string $cols, array $ids, string $g) use ($db, &$pageNo, $perPage): array {
    $pageIds = array_slice($ids, (min($pageNo[$g], max(1, (int)ceil(count($ids) / $perPage))) - 1) * $perPage, $perPage);
    if (!$pageIds) return [];
    $marks = implode(',', array_fill(0, count($pageIds), '?'));
    $byId = [];
    foreach ($db->fetchAll("SELECT $cols FROM `$table` WHERE `$idCol` IN ($marks)", $pageIds) as $r) $byId[(int)$r[$idCol]] = $r;
    $out = [];
    foreach ($pageIds as $id) if (isset($byId[$id])) $out[] = $byId[$id];
    return $out;
};
if (mb_strlen($q) >= 2) {
    $like = '%' . addcslashes($q, '%_\\') . '%';   // a typed % or _ searches for that character
    // internal numbers only (never user input) are inlined: PDO cannot bind LIMIT/OFFSET without quoting them
    $limitInt = (int)$perPage;

    $ids = $richHitIds('news', 'news_id', $q, $like);
    $totals['news'] = count($ids);
    $results['news'] = attachNewsSdgs($richPage('news', 'news_id', 'news_id, title, image, created_at, view_count', $ids, 'news'));

    $ids = $richHitIds('articles', 'article_id', $q, $like);
    $totals['articles'] = count($ids);
    $results['articles'] = $richPage('articles', 'article_id', 'article_id, title, image, created_at', $ids, 'articles');

    $progWhere = "(p.program_name LIKE ? OR m.major_name LIKE ?)";   // closed programmes are found too (marked on the card)
    $totals['programs'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM programs p LEFT JOIN majors m ON m.major_id = p.major_id WHERE $progWhere", [$like, $like])['c'] ?? 0);
    $off = (min($pageNo['programs'], max(1, (int)ceil($totals['programs'] / $perPage))) - 1) * $perPage;
    $results['programs'] = $db->fetchAll(
        "SELECT p.program_id, p.program_name, p.is_active, el.level_abbr, m.major_name
         FROM programs p
         LEFT JOIN majors m ON m.major_id = p.major_id
         JOIN education_levels el ON el.level_id = p.level_id
         WHERE $progWhere
         ORDER BY p.program_name LIMIT {$limitInt} OFFSET " . (int)$off,
        [$like, $like]
    );

    $staffWhere = staffCurrentSql('s') . " AND (s.full_name LIKE ? OR s.full_name_en LIKE ?)";
    $totals['staff'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM staff s JOIN positions p ON p.position_id = s.position_id WHERE $staffWhere", [$like, $like])['c'] ?? 0);
    $off = (min($pageNo['staff'], max(1, (int)ceil($totals['staff'] / $perPage))) - 1) * $perPage;
    $results['staff'] = $db->fetchAll(
        "SELECT s.staff_id, s.prefix, s.full_name, s.full_name_en, s.name_en_verified, s.image, p.position_name
         FROM staff s JOIN positions p ON p.position_id = s.position_id
         WHERE $staffWhere
         ORDER BY s.full_name LIMIT {$limitInt} OFFSET " . (int)$off,
        [$like, $like]
    );
}
// ---- AJAX branch: JSON suggestions for the navbar search box ----
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $groups = [
        [
            'label' => $lang === 'en' ? 'News' : 'ข่าวสาร',
            'items' => array_map(fn($n) => [
                'title' => $t($n['title']),
                'url'   => BASE_URL . '/news-detail.php?id=' . (int)$n['news_id'] . '&lang=' . $lang,
            ], $results['news']),
        ],
        [
            'label' => $lang === 'en' ? 'Programs' : 'หลักสูตร',
            'items' => array_map(fn($p) => [
                'title' => $t($p['program_name']),
                'url'   => BASE_URL . '/program-detail.php?id=' . (int)$p['program_id'] . '&lang=' . $lang,
            ], $results['programs']),
        ],
        [
            'label' => $lang === 'en' ? 'Articles' : 'บทความ',
            'items' => array_map(fn($a) => [
                'title' => $t($a['title']),
                'url'   => BASE_URL . '/articles.php?id=' . (int)$a['article_id'] . '&lang=' . $lang,
            ], $results['articles']),
        ],
        [
            'label' => $lang === 'en' ? 'Staff' : 'บุคลากร',
            'items' => array_map(fn($s) => [
                'title' => trim(staffName($s, $lang)),
                'url'   => BASE_URL . '/staff.php?lang=' . $lang,
            ], $results['staff']),
        ],
    ];
    echo json_encode($groups, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Full page branch ----
$totalResults = array_sum($totals);
// pager for one group: keeps q, lang and the other groups' pages in the links
$groupPager = function (string $g) use ($totals, $perPage, $pageNo, $pageKey, $q, $lang): string {
    $p = paginate($totals[$g], $perPage, $pageNo[$g], '');
    $qs = ['q' => $q, 'lang' => $lang];
    foreach ($pageKey as $gg => $k) if ($gg !== $g && $pageNo[$gg] > 1) $qs[$k] = $pageNo[$gg];
    $qs[$pageKey[$g]] = '__PAGE__';
    $p['url_pattern'] = '?' . str_replace('__PAGE__', '{page}', http_build_query($qs)) . '#group-' . $g;
    return paginationNav($p);
};
trackPageView($_SERVER['REQUEST_URI'], ($lang === 'en' ? 'Search: ' : 'ค้นหา: ') . $q);
$pageTitle = ($lang === 'en' ? 'Search' : 'ค้นหา') . ($q ? ': ' . $q : '');
$metaDesc = $lang === 'en'
    ? 'Search news, articles, programs and staff of the Faculty of Business Administration and Information Technology.'
    : 'ค้นหาข่าวสาร บทความ หลักสูตร และบุคลากรของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ';
$activePage = 'search';
$robotsMeta = 'noindex,follow'; // search result pages should not be indexed
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('ค้นหา')],
];
?>

<section class="search-hero page-hero" style="padding-block:36px 40px">
  <div class="container">
    <h1><?= $t('ค้นหา') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
    <div class="search-panel" data-search-panel style="margin-top:20px;max-width:640px">
      <form class="search-box" method="GET" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" data-search-input aria-label="<?= $lang === 'en' ? 'Search the website' : 'ค้นหาในเว็บไซต์' ?>" value="<?= e($q) ?>" placeholder="<?= $lang === 'en' ? 'Search programs, news, staff…' : 'ค้นหาหลักสูตร ข่าวสาร บุคลากร...' ?>" autocomplete="off" autofocus>
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
      </form>
      <div class="search-suggestions" data-search-results role="listbox"></div>
    </div>
  </div>
</section>

<div class="container section">
  <?php if (mb_strlen($q) < 2): ?>
  <div class="empty-state">
    <?= icon('search') ?>
    <div><?= $lang === 'en' ? 'Type at least 2 characters to search' : 'พิมพ์อย่างน้อย 2 ตัวอักษรเพื่อค้นหา' ?></div>
  </div>

  <?php elseif ($totalResults === 0): ?>
  <div class="empty-state">
    <?= icon('search') ?>
    <div><?= $lang === 'en' ? "No results found for \"" . e($q) . "\"" : "ไม่พบผลลัพธ์สำหรับ \"" . e($q) . "\"" ?></div>
    <small class="text-faint"><?= $t('ลองค้นหาด้วยคำอื่น') ?></small>
  </div>

  <?php else: ?>
  <p class="text-muted" style="margin-bottom:28px">
    <?= $lang === 'en' ? "Found {$totalResults} results for \"" . e($q) . "\"" : "พบ {$totalResults} ผลลัพธ์สำหรับ \"" . e($q) . "\"" ?>
    <?php if ($richCapped): ?><br><small class="text-faint"><?= $lang === 'en' ? 'Only the newest 500 matching news items / articles are shown. Try more specific words.' : 'แสดงเฉพาะข่าว/บทความล่าสุด 500 รายการที่ตรงคำค้น ลองระบุคำค้นให้เจาะจงขึ้น' ?></small><?php endif; ?>
  </p>

  <?php if ($results['news']): ?>
  <div class="search-results-group reveal" id="group-news">
    <h2><?= icon('newspaper', 'icon-sm') ?> <?= $t('ข่าวสาร') ?> (<?= $totals['news'] ?>)</h2>
    <div class="grid grid-3">
      <?php foreach ($results['news'] as $n) { $n['category_name'] = $t('ข่าว'); $n['color'] = '#1A6BC8'; echo newsCard($n, $lang); } ?>
    </div>
    <?= $groupPager('news') ?>
  </div>
  <?php endif; ?>

  <?php if ($results['programs']): ?>
  <div class="search-results-group reveal" id="group-programs">
    <h2><?= icon('graduation-cap', 'icon-sm') ?> <?= $t('หลักสูตร') ?> (<?= $totals['programs'] ?>)</h2>
    <div class="grid grid-3">
      <?php foreach ($results['programs'] as $p) echo programCard($p, $lang); ?>
    </div>
    <?= $groupPager('programs') ?>
  </div>
  <?php endif; ?>

  <?php if ($results['staff']): ?>
  <div class="search-results-group reveal" id="group-staff">
    <h2><?= icon('users', 'icon-sm') ?> <?= $t('บุคลากร') ?> (<?= $totals['staff'] ?>)</h2>
    <div class="grid grid-4">
      <?php foreach ($results['staff'] as $s) { $s['tag_label'] = null; echo staffCard($s, $lang); } ?>
    </div>
    <?= $groupPager('staff') ?>
  </div>
  <?php endif; ?>

  <?php if ($results['articles']): ?>
  <div class="search-results-group reveal" id="group-articles">
    <h2><?= icon('book', 'icon-sm') ?> <?= $t('บทความ') ?> (<?= $totals['articles'] ?>)</h2>
    <div class="grid grid-3">
      <?php foreach ($results['articles'] as $a): ?>
      <article class="card card-news">
        <a href="<?= BASE_URL ?>/articles.php?id=<?= (int)$a['article_id'] ?>&lang=<?= e($lang) ?>" class="card-thumb-link" aria-hidden="true" tabindex="-1">
          <div class="card-thumb">
            <?php if ($a['image']): ?><img src="<?= UPLOAD_URL . '/' . e($a['image']) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?><div class="card-thumb-fallback"><?= icon('newspaper') ?></div><?php endif; ?>
          </div>
        </a>
        <div class="card-body">
          <div class="card-meta"><?= thaiDate($a['created_at']) ?></div>
          <h3 class="card-title"><a href="<?= BASE_URL ?>/articles.php?id=<?= (int)$a['article_id'] ?>&lang=<?= e($lang) ?>"><?= e($t($a['title'])) ?></a></h3>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?= $groupPager('articles') ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

<?php
/**
 * BAIT - Articles Page (List + Detail) — Phase 3 rebuild
 * Not one of the 11 pages explicitly listed for Phase 3, but rebuilt anyway:
 * it shares layout/header.php + layout/footer.php with every other page,
 * and those no longer load Bootstrap, so leaving this file's Bootstrap
 * markup in place would have silently broken it.
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

$articleId = (int)($_GET['id'] ?? 0);

// ============ DETAIL MODE ============
if ($articleId) {
    $article = $db->fetchOne(
        "SELECT a.*, u.full_name as author FROM articles a
         JOIN users u ON u.user_id = a.user_id
         WHERE a.article_id = ? AND a.status = 'เผยแพร่'",
        [$articleId]
    );
    if (!$article) { require __DIR__ . '/404.php'; exit; }

    // in SQL, so two readers at once both count; updated_at = updated_at keeps "last edited" (sitemap) as it was; bots do not count
    if (!isBotRequest()) $db->query('UPDATE articles SET view_count = view_count + 1, updated_at = updated_at WHERE article_id = ?', [$articleId]);

    // Related = shares the most tags with this article (tags is a flat "a, b, c" column; compared
    // case-insensitively). A tag used by few articles counts for more than one used by almost all of
    // them ("ความรู้"), ties go to the newer article, and if fewer than 3 share a tag the rest are
    // filled with the newest articles so the box is never half empty.
    $tagSet = function (?string $s): array {
        $out = [];
        foreach (preg_split('/\s*,\s*/u', (string)$s) as $x) {
            $x = mb_strtolower(trim($x), 'UTF-8');
            if ($x !== '') $out[$x] = true;
        }
        return array_keys($out);
    };
    $pool = $db->fetchAll(
        "SELECT article_id, title, image, created_at, tags FROM articles
         WHERE status='เผยแพร่' AND article_id != ? ORDER BY created_at DESC LIMIT 300",
        [$articleId]
    );
    $mine = $tagSet($article['tags'] ?? '');
    $freq = [];
    foreach ($pool as $p) foreach ($tagSet($p['tags']) as $tg) $freq[$tg] = ($freq[$tg] ?? 0) + 1;
    $scored = [];
    foreach ($pool as $idx => $p) {
        $score = 0.0;
        foreach (array_intersect($mine, $tagSet($p['tags'])) as $tg) $score += 1 / max(1, $freq[$tg]);
        $scored[] = [$score, $idx, $p];
    }
    // highest score first; equal score keeps the pool order (newest first)
    usort($scored, fn($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
    $related = [];
    foreach ($scored as [$score, , $p]) {
        if ($score <= 0 && count($related) >= 3) break;
        $related[] = ['article_id' => $p['article_id'], 'title' => $p['title'], 'image' => $p['image'], 'created_at' => $p['created_at']];
        if (count($related) >= 3) break;
    }
    $latestA = $db->fetchAll(
        "SELECT article_id, title, created_at FROM articles WHERE status='เผยแพร่' AND article_id != ? ORDER BY created_at DESC LIMIT 6",
        [$articleId]
    );

    $displayTitle   = $t($article['title']);
    $displayContent = cleanRichHtml($t(stripFacebookEmojiImages($article['content'])));
    $shareLink   = BASE_URL_ABSOLUTE . '/articles.php?id=' . $articleId . '&lang=' . $lang;
$canonicalUrl = $shareLink;
$ogType       = 'article';
$pageTitle = $displayTitle;
    $metaDesc  = metaSnippet($displayContent);
    $ogImage   = $article['image'] ?? null;

    $schemaJson = json_encode([
        '@context' => 'https://schema.org', '@type' => 'Article',
        'headline' => $displayTitle,
        'author'   => ['@type' => 'Person', 'name' => $article['author']],
        'publisher'=> ['@type' => 'Organization', 'name' => 'BAIT RMUTSB'],
        'datePublished' => $article['created_at'],
        'url' => BASE_URL_ABSOLUTE . '/articles.php?id=' . $articleId,
    ], JSON_UNESCAPED_UNICODE);

    trackPageView($_SERVER['REQUEST_URI'], $article['title']);
    $activePage = 'articles';
    include __DIR__ . '/layout/header.php';

    $breadcrumbItems = [
        ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
        ['label' => $t('บทความ'), 'url' => BASE_URL . '/articles.php?lang=' . $lang],
        ['label' => mb_strimwidth($displayTitle, 0, 40, '...', 'UTF-8')],
    ];
?>

<section class="page-hero">
  <div class="container">
    <h1 style="font-size:clamp(1.1rem,2.5vw,1.6rem)"><?= e($displayTitle) ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <div class="layout-with-sidebar">
    <div>
      <article class="card" style="padding:0">
        <?php if ($article['image']): ?>
        <img src="<?= UPLOAD_URL . '/' . e($article['image']) ?>" class="article-cover" alt="<?= e($displayTitle) ?>"<?= imgSizeAttrs($article['image']) ?> decoding="async" fetchpriority="high">
        <?php endif; ?>
        <div style="padding:28px">
          <div class="article-meta-row" style="margin-bottom:14px">
            <span><?= icon('calendar', 'icon-sm') ?> <?= thaiDate($article['created_at'], true) ?></span>
            <span><?= icon('eye', 'icon-sm') ?> <?= number_format($article['view_count'] + 1) ?></span>
            <span><?= icon('user', 'icon-sm') ?> <?= e(translateFixed($article['author'], $lang)) ?></span>
          </div>
          <h2 style="color:var(--primary);margin-bottom:20px;font-size:clamp(1.4rem,3vw,2rem)"><?= e($displayTitle) ?></h2>
          <div class="prose"><?= $displayContent ?></div>
          <?php if ($article['tags']): ?>
          <div class="card-tags" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
            <?php foreach (explode(',', $article['tags']) as $tag): ?>
            <a href="<?= BASE_URL ?>/articles.php?tag=<?= urlencode(trim($tag)) ?>&lang=<?= e($lang) ?>" class="tag-chip">#<?= e($t(trim($tag))) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="share-row" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
            <span class="text-muted" style="font-size:.82rem;font-weight:700"><?= $t('แชร์:') ?></span>
            <?php // Facebook's sharer rejects BASE_URL's protocol-relative "//host/..."
            // form, so every share/copy address uses the full scheme (BASE_URL_ABSOLUTE).
            // $shareLink is defined above, next to the page title (it is also the canonical/og:url).
            $shareUrl = urlencode($shareLink); ?>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" rel="noopener" class="share-btn" data-share-facebook data-share-title="<?= e($displayTitle) ?>" data-share-url="<?= e($shareLink) ?>"><?= icon('facebook', 'icon-sm') ?> Facebook</a>
            <a href="https://line.me/R/msg/text/?<?= urlencode($displayTitle) . '%0A' . $shareUrl ?>" target="_blank" rel="noopener" class="share-btn"><?= icon('line', 'icon-sm') ?> Line</a>
            <button type="button" class="share-btn" data-copy-link="<?= e($shareLink) ?>" data-copied="<?= e($t('คัดลอกลิงก์แล้ว')) ?>"><?= icon('link', 'icon-sm') ?> <?= $t('คัดลอกลิงก์') ?></button>
          </div>
        </div>
      </article>

      <?php if ($related): ?>
      <div style="margin-top:40px">
        <h3 style="color:var(--primary);margin-bottom:16px"><?= $t('บทความที่เกี่ยวข้อง') ?></h3>
        <div class="grid grid-3">
          <?php foreach ($related as $r): ?>
          <article class="card card-news">
            <a href="?id=<?= (int)$r['article_id'] ?>&lang=<?= e($lang) ?>" class="card-thumb-link" aria-hidden="true" tabindex="-1">
              <div class="card-thumb">
                <?php if ($r['image']): ?><img src="<?= UPLOAD_URL . '/' . e($r['image']) ?>" alt="" loading="lazy" decoding="async">
                <?php else: ?><div class="card-thumb-fallback"><?= icon('book') ?></div><?php endif; ?>
              </div>
            </a>
            <div class="card-body">
              <div class="card-meta"><?= thaiDate($r['created_at']) ?></div>
              <h3 class="card-title"><a href="?id=<?= (int)$r['article_id'] ?>&lang=<?= e($lang) ?>"><?= e($t($r['title'])) ?></a></h3>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <aside class="sidebar-sticky">
      <div class="sidebar-card">
        <h4><?= $t('บทความล่าสุด') ?></h4>
        <?php foreach ($latestA as $la): ?>
        <a href="?id=<?= (int)$la['article_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none">
          <p class="side-list-title" style="margin:0"><?= e($t($la['title'])) ?></p>
          <small class="text-faint"><?= thaiDate($la['created_at']) ?></small>
        </a>
        <?php endforeach; ?>
      </div>
    </aside>
  </div>
</div>

<?php
    include __DIR__ . '/layout/footer.php';
    exit;
}

// ============ LIST MODE ============
$search  = getStr('search');
$tagFilter = getStr('tag');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = "status='เผยแพร่'"; $params = [];
if ($search) { $where .= ' AND title LIKE ?'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }   // a typed % or _ searches for that character (as in search.php)
if ($tagFilter) {
    // tags is a flat "a, b, c" column, not a separate table — match a
    // whole tag, not a substring of one, by normalizing both sides to a
    // comma-delimited list with no spaces and requiring the tag to sit
    // between two commas.
    $where .= " AND CONCAT(',', REPLACE(tags, ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')";
    $params[] = addcslashes(str_replace(' ', '', $tagFilter), '%_\\');   // ?tag=% must not match every item
}

$total = (int)($db->fetchOne("SELECT COUNT(*) as c FROM articles WHERE $where", $params)['c'] ?? 0);
$pager = paginate($total, $perPage, $page, '?search=' . urlencode($search) . '&tag=' . urlencode($tagFilter) . '&lang=' . $lang . '&page={page}');
$articles = $db->fetchAll(
    "SELECT a.*, u.full_name as author FROM articles a JOIN users u ON u.user_id=a.user_id
     WHERE $where ORDER BY a.created_at DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);

$featuredArticles = $db->fetchAll(
    "SELECT article_id, title, created_at FROM articles
     WHERE status='เผยแพร่' ORDER BY view_count DESC LIMIT 5"
);

// tags is a flat "a, b, c" column, not a separate table — build the cloud
// by exploding every article's tag list and counting how often each one
// shows up, most-used first. Tags that differ only in upper/lower case
// (#AI / #ai) are one tag: counted together and shown in the spelling used
// most often (the filter below is case-insensitive too, so both find the same articles).
$tagCounts = []; $tagSpell = [];
foreach ($db->fetchAll("SELECT tags FROM articles WHERE status='เผยแพร่' AND tags IS NOT NULL AND tags <> ''") as $tr) {
    foreach (explode(',', $tr['tags']) as $tagName) {
        $tagName = trim($tagName);
        if ($tagName === '') continue;
        $key = mb_strtolower($tagName, 'UTF-8');
        $tagCounts[$key] = ($tagCounts[$key] ?? 0) + 1;
        $tagSpell[$key][$tagName] = ($tagSpell[$key][$tagName] ?? 0) + 1;
    }
}
arsort($tagCounts);
$tagCounts = array_combine(array_map(function ($key) use ($tagSpell) { arsort($tagSpell[$key]); return (string)array_key_first($tagSpell[$key]); }, array_keys($tagCounts)), $tagCounts);
$tagTop = 7;    // the sidebar shows the most used ones; "ดูแท็กทั้งหมด" opens the rest in place

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Articles' : 'บทความ');
$pageTitle = $lang === 'en' ? 'Articles' : 'บทความ';
$metaDesc = $lang === 'en'
    ? 'Academic articles, knowledge and practical tips from the Faculty of Business Administration and Information Technology.'
    : 'บทความวิชาการ ความรู้ และเทคนิคที่เป็นประโยชน์จากบุคลากรของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ';
$activePage = 'articles';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('บทความ')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('บทความวิชาการ') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <div class="layout-with-sidebar">

    <div>
      <form class="filter-bar" method="GET" role="search">
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
        <?php if ($tagFilter !== ''): ?><input type="hidden" name="tag" value="<?= e($tagFilter) ?>"><?php endif; ?>
        <div class="search-box search-box--grow">
          <?= icon('search') ?>
          <input type="search" name="search" aria-label="<?= e($t('ค้นหาบทความ')) ?>" placeholder="<?= $t('ค้นหาบทความ...') ?>" value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><?= $t('ค้นหา') ?></button>
        <?php if ($search || $tagFilter): ?>
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

      <?php if ($search && $total): ?>
      <p class="text-muted" style="margin-bottom:16px;font-size:.88rem">
        <?= $lang === 'en' ? "Found {$total} results for \"" . e($search) . "\"" : "พบ {$total} ผลลัพธ์สำหรับ \"" . e($search) . "\"" ?>
      </p>
      <?php endif; ?>

      <h2 class="visually-hidden"><?= $t('รายการบทความ') ?></h2>
      <div class="grid grid-2">
        <?php foreach ($articles as $a):
          $aTitle = $t($a['title']);
          // Cut the Thai text first, then translate the short piece: translating the whole article just for a 100-char excerpt means dozens of API calls per card.
          $aExcerpt = mb_strimwidth($t(mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($a['content'] ?? ''))), 0, 160, 'UTF-8')), 0, 100, '...', 'UTF-8');
        ?>
        <article class="card card-news reveal">
          <a href="?id=<?= (int)$a['article_id'] ?>&lang=<?= e($lang) ?>" class="card-thumb-link" aria-hidden="true" tabindex="-1">
            <div class="card-thumb">
              <?php if ($a['image']): ?><img src="<?= UPLOAD_URL . '/' . e($a['image']) ?>" alt="<?= e($aTitle) ?>" loading="lazy" decoding="async">
              <?php else: ?><div class="card-thumb-fallback"><?= icon('book') ?></div><?php endif; ?>
            </div>
          </a>
          <div class="card-body">
            <h3 class="card-title"><a href="?id=<?= (int)$a['article_id'] ?>&lang=<?= e($lang) ?>"><?= e($aTitle) ?></a></h3>
            <p class="card-excerpt"><?= e($aExcerpt) ?></p>
            <div class="card-meta">
              <span><?= thaiDate($a['created_at']) ?></span>
              <span class="dot-sep" aria-hidden="true">&middot;</span>
              <span><?= icon('eye', 'icon-sm') ?> <?= number_format($a['view_count']) ?></span>
            </div>
          </div>
        </article>
        <?php endforeach; ?>

        <?php if (empty($articles)): ?>
        <div class="empty-state" style="grid-column:1/-1">
          <?= icon('book') ?>
          <div><?= $t('ไม่พบบทความ') ?></div>
          <?php if ($search || $tagFilter): ?><small class="text-faint"><?= $t('ลองค้นหาด้วยคำอื่น') ?></small>
          <a href="?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm" style="margin-top:12px"><?= $t('ล้างตัวกรอง') ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Sidebar -->
    <aside class="sidebar-col">
    <div class="sidebar-sticky">
      <?php if ($featuredArticles): ?>
      <div class="sidebar-card">
        <h4><?= icon('flame') ?> <?= $t('บทความยอดนิยม') ?></h4>
        <?php foreach ($featuredArticles as $i => $fa): ?>
        <a href="?id=<?= (int)$fa['article_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none">
          <span class="side-rank rank-<?= $i + 1 ?>"><?= $i + 1 ?></span>
          <div>
            <p class="side-list-title" style="margin:0"><?= e($t($fa['title'])) ?></p>
            <small class="text-faint"><?= thaiDate($fa['created_at']) ?></small>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($tagCounts): ?>
      <div class="sidebar-card">
        <h4><?= icon('tag') ?> <?= $t('แฮชแท็ก') ?></h4>
        <?php
          $tagChip = function ($tagName, $cnt) use ($tagFilter, $lang, $t) {
              $isActive = $tagFilter !== '' && mb_strtolower($tagFilter, 'UTF-8') === mb_strtolower($tagName, 'UTF-8');
              return '<a href="?tag=' . urlencode($tagName) . '&lang=' . e($lang) . '" class="tag-chip' . ($isActive ? ' is-active' : '') . '">#'
                  . e($t($tagName)) . ' <span class="badge-soft">' . $cnt . '</span></a>';
          };
          $tagExtra = array_slice($tagCounts, $tagTop, null, true);
          $activeIsExtra = $tagFilter !== '' && in_array(mb_strtolower($tagFilter, 'UTF-8'), array_map(function ($n) { return mb_strtolower($n, 'UTF-8'); }, array_keys($tagExtra)), true);
        ?>
        <div class="cluster gap-2 tag-cloud" id="tagCloud">
          <?php foreach (array_slice($tagCounts, 0, $tagTop, true) as $tagName => $cnt) echo $tagChip($tagName, $cnt); ?>
        </div>
        <?php if ($tagExtra): ?>
        <?php /* the script below hides the panel behind the button and shows the search box; without it every tag is listed */ ?>
        <div class="tag-panel" id="tagPanel">
          <input type="search" class="tag-search" id="tagSearch" hidden autocomplete="off"
                 placeholder="<?= e(str_replace('{n}', count($tagCounts), $t('ค้นหาแท็กจาก {n} แท็ก...'))) ?>" aria-label="<?= e(str_replace('{n}', count($tagCounts), $t('ค้นหาแท็กจาก {n} แท็ก...'))) ?>">
          <div class="tag-box" id="tagBox">
            <div class="cluster gap-2 tag-cloud" id="tagExtra">
              <?php foreach ($tagExtra as $tagName => $cnt) echo $tagChip($tagName, $cnt); ?>
            </div>
          </div>
          <p class="tag-note" id="tagNote" aria-live="polite" hidden
             data-rest="<?= e(str_replace('{n}', count($tagExtra), $t('อีก {n} แท็ก · เลื่อนในกล่องเพื่อดูเพิ่ม'))) ?>"
             data-found="<?= e($t('พบ {n} แท็ก')) ?>" data-none="<?= e($t('ไม่พบแท็กที่ค้นหา')) ?>"></p>
        </div>
        <button type="button" class="tag-more" id="tagMore" aria-controls="tagPanel" aria-expanded="<?= $activeIsExtra ? 'true' : 'false' ?>" hidden
                data-more="<?= e($t('ดูแท็กทั้งหมด')) ?> (<?= count($tagCounts) ?>)" data-less="<?= e($t('ย่อแท็ก')) ?>"></button>
        <script>(function () {
          var panel = document.getElementById('tagPanel'), btn = document.getElementById('tagMore'), q = document.getElementById('tagSearch'),
              box = document.getElementById('tagBox'), note = document.getElementById('tagNote'),
              chips = [].slice.call(document.querySelectorAll('#tagCloud .tag-chip, #tagExtra .tag-chip'));
          // the search looks through every tag (the top ones too), so a tag is found wherever it sits
          function filter() {
            var s = q.value.trim().toLowerCase(), n = 0;
            chips.forEach(function (c) { var hit = !s || c.textContent.toLowerCase().indexOf(s) !== -1; c.hidden = !hit; if (hit) n++; });
            note.textContent = !s ? note.dataset.rest : (n ? note.dataset.found.replace('{n}', n) : note.dataset.none);
            fade();
          }
          // the bottom edge of the box fades out while there is more to scroll to
          function fade() { box.classList.toggle('is-more', box.scrollHeight - box.clientHeight - box.scrollTop > 4); }
          box.addEventListener('scroll', fade, { passive: true });
          window.addEventListener('resize', fade);
          function set(open) {
            panel.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.innerHTML = (open ? btn.dataset.less + ' <span aria-hidden="true">▴</span>' : btn.dataset.more + ' <span aria-hidden="true">▾</span>');
            if (!open && q.value) { q.value = ''; filter(); }
            if (open) fade();
          }
          q.hidden = false; note.hidden = false; filter();
          q.addEventListener('input', filter);
          set(btn.getAttribute('aria-expanded') === 'true'); btn.hidden = false;
          btn.addEventListener('click', function () { var open = btn.getAttribute('aria-expanded') !== 'true'; set(open); if (open) q.focus({ preventScroll: true }); });
          var act = box.querySelector('.tag-chip.is-active');   // the chosen tag is further down: bring it into view inside the box
          if (act && !panel.hidden) box.scrollTop = Math.max(0, act.offsetTop - 8);
        })();</script>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    </aside>

    <?php if ($pagerNav = paginationNav($pager)): ?>
    <div class="layout-with-sidebar__foot"><?= $pagerNav ?></div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

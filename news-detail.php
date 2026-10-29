<?php
/**
 * BAIT - News Detail Page (Phase 3 rebuild)
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

$newsId = (int)($_GET['id'] ?? 0);
$news = $db->fetchOne(
    "SELECT n.*, c.category_name, c.color, u.full_name as author
     FROM news n
     JOIN categories c ON c.category_id = n.category_id
     JOIN users u ON u.user_id = n.user_id
     WHERE n.news_id = ? AND n.status = 'เผยแพร่'",
    [$newsId]
);

if (!$news) {
    require __DIR__ . '/404.php';   // a real 404 page (with search + links), not a redirect that looks like a valid page to search engines
    exit;
}

// in SQL, so two readers at once both count; updated_at = updated_at keeps "last edited" (sitemap, dateModified) as it was
$db->query('UPDATE news SET view_count = view_count + 1, updated_at = updated_at WHERE news_id = ?', [$newsId]);
$newsSdgs = newsSdgsMap([$newsId])[$newsId] ?? [];   // the SDGs this news is linked to

$related = $db->fetchAll(
    "SELECT news_id, title, image, created_at, view_count FROM news
     WHERE status = 'เผยแพร่' AND category_id = ? AND news_id != ?
     ORDER BY created_at DESC LIMIT 4",
    [$news['category_id'], $newsId]
);
$latestNews = $db->fetchAll(
    "SELECT news_id, title, image, created_at FROM news
     WHERE status = 'เผยแพร่' AND news_id != ?
     ORDER BY created_at DESC LIMIT 6",
    [$newsId]
);

trackPageView($_SERVER['REQUEST_URI'], $news['title']);

$displayTitle   = $t($news['title']);
$displayContent = cleanRichHtml($t(stripFacebookEmojiImages($news['content'])));
$displayCat     = $t($news['category_name']);

$shareLink   = BASE_URL_ABSOLUTE . '/news-detail.php?id=' . $newsId . '&lang=' . $lang;
$canonicalUrl = $shareLink;
$ogType       = 'article';
$pageTitle = $displayTitle;
$metaDesc  = metaSnippet($displayContent);
$ogImage   = $news['image'] ?? null;

$schemaJson = json_encode([
    '@context'      => 'https://schema.org',
    '@type'         => 'NewsArticle',
    'headline'      => $displayTitle,
    'image'         => $news['image'] ? UPLOAD_URL_ABSOLUTE . '/' . $news['image'] : null,
    'datePublished' => $news['created_at'],
    'dateModified'  => $news['updated_at'],
    'author'        => ['@type' => 'Person', 'name' => $news['author']],
    'publisher'     => ['@type' => 'Organization', 'name' => 'BAIT RMUTSB'],
    'description'   => $metaDesc,
    'url'           => BASE_URL_ABSOLUTE . '/news-detail.php?id=' . $newsId,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$activePage = 'news';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('ข่าวสาร'), 'url' => BASE_URL . '/news.php?lang=' . $lang],
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
        <?php if ($news['image']): ?>
        <img src="<?= UPLOAD_URL . '/' . e($news['image']) ?>" class="article-cover" alt="<?= e($displayTitle) ?>"<?= imgSizeAttrs($news['image']) ?> decoding="async" fetchpriority="high">
        <?php endif; ?>

        <div style="padding:28px">
          <div class="article-meta-row" style="margin-bottom:14px">
            <a href="<?= BASE_URL ?>/news.php?cat=<?= (int)$news['category_id'] ?>&lang=<?= e($lang) ?>" class="card-badge card-badge-news" style="position:static;--badge-color:<?= e($news['color'] ?? '#1558A6') ?>"><?= e($displayCat) ?></a>
            <span><?= icon('calendar', 'icon-sm') ?> <?= thaiDate($news['created_at'], true) ?></span>
            <span><?= icon('eye', 'icon-sm') ?> <?= number_format($news['view_count'] + 1) ?> <?= $t('ครั้ง') ?></span>
            <span><?= icon('user', 'icon-sm') ?> <?= e(translateFixed($news['author'], $lang)) ?></span>
          </div>

          <h2 style="color:var(--primary);margin-bottom:20px;font-size:clamp(1.4rem,3vw,2rem)"><?= e($displayTitle) ?></h2>

          <div class="prose"><?= $displayContent ?></div>

          <?php if (!empty($news['gallery_url']) && isHttpUrl($news['gallery_url'])): ?>
          <div class="callout note" style="background:var(--surface-soft);border-radius:var(--radius-md);padding:14px 18px;margin-top:20px">
            <?= icon('image', 'icon-sm') ?> <strong><?= $t('อัลบั้มภาพ:') ?></strong>
            <a href="<?= e($news['gallery_url']) ?>" target="_blank" rel="noopener"><?= $t('ดูภาพทั้งหมด') ?> <?= icon('external-link', 'icon-sm') ?></a>
          </div>
          <?php endif; ?>

          <?php if ($newsSdgs): ?>
          <section class="news-sdgs" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)" aria-labelledby="newsSdgsTitle">
            <h3 id="newsSdgsTitle" style="font-size:1rem;color:var(--primary);margin-bottom:10px;display:flex;align-items:center;gap:8px"><?= icon('target', 'icon-sm') ?> <?= $lang === 'en' ? 'Sustainable Development Goals (SDGs)' : 'เป้าหมายการพัฒนาที่ยั่งยืน (SDGs)' ?></h3>
            <?= sdgBadges($newsSdgs, $lang) ?>
          </section>
          <?php endif; ?>

          <?php if ($news['tags']): ?>
          <div class="card-tags" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
            <?php foreach (explode(',', $news['tags']) as $tag): ?>
            <a href="<?= BASE_URL ?>/news.php?tag=<?= urlencode(trim($tag)) ?>&lang=<?= e($lang) ?>" class="tag-chip">#<?= e($t(trim($tag))) ?></a>
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
        <h3 style="color:var(--primary);margin-bottom:16px"><?= icon('newspaper', 'icon-sm') ?> <?= $t('ข่าวที่เกี่ยวข้อง') ?></h3>
        <div class="grid grid-2">
          <?php foreach ($related as $r): ?>
          <a href="?id=<?= (int)$r['news_id'] ?>&lang=<?= e($lang) ?>" class="related-mini">
            <?php if ($r['image']): ?>
            <img src="<?= UPLOAD_URL . '/' . e($r['image']) ?>" alt=""<?= imgSizeAttrs($r['image']) ?> loading="lazy" decoding="async">
            <?php else: ?>
            <div class="related-mini-fallback"><?= icon('newspaper') ?></div>
            <?php endif; ?>
            <div class="related-mini-body">
              <p class="side-list-title" style="margin:0"><?= e($t($r['title'])) ?></p>
              <small class="text-faint"><?= thaiDate($r['created_at']) ?></small>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <aside class="sidebar-sticky">
      <div class="sidebar-card">
        <h4><?= icon('clock') ?> <?= $t('ข่าวล่าสุด') ?></h4>
        <?php foreach ($latestNews as $ln): ?>
        <a href="?id=<?= (int)$ln['news_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none">
          <?php if ($ln['image']): ?>
          <img src="<?= UPLOAD_URL . '/' . e($ln['image']) ?>" class="side-list-thumb" alt=""<?= imgSizeAttrs($ln['image']) ?> loading="lazy" decoding="async">
          <?php else: ?>
          <div class="side-list-thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ink-faint)"><?= icon('newspaper', 'icon-sm') ?></div>
          <?php endif; ?>
          <div>
            <p class="side-list-title" style="margin:0"><?= e($t($ln['title'])) ?></p>
            <small class="text-faint"><?= thaiDate($ln['created_at']) ?></small>
          </div>
        </a>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/news.php?lang=<?= e($lang) ?>" class="btn btn-secondary btn-sm btn-block" style="margin-top:12px"><?= $t('ดูข่าวทั้งหมด') ?></a>
      </div>
    </aside>

  </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>

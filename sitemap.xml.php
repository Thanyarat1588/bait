<?php
/**
 * BAIT - Sitemap XML
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/helpers.php';

if (!defined("BASE_PATH")) define("BASE_PATH", __DIR__);

$db = Database::getInstance();

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

$newsList    = $db->fetchAll("SELECT news_id, updated_at FROM news WHERE status='เผยแพร่' ORDER BY updated_at DESC LIMIT 200");
$articleList = $db->fetchAll("SELECT article_id, updated_at FROM articles WHERE status='เผยแพร่' ORDER BY updated_at DESC LIMIT 200");
$programList = $db->fetchAll("SELECT program_id, updated_at FROM programs ORDER BY program_id");

$today = date('Y-m-d');
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">

  <!-- Static pages -->
  <?php
  $staticPages = [
    ['loc'=>'/',               'priority'=>'1.0', 'freq'=>'daily'],
    ['loc'=>'/about.php',      'priority'=>'0.9', 'freq'=>'monthly'],
    ['loc'=>'/programs.php',   'priority'=>'0.9', 'freq'=>'weekly'],
    ['loc'=>'/tuition.php',    'priority'=>'0.8', 'freq'=>'monthly'],
    ['loc'=>'/news.php',       'priority'=>'0.8', 'freq'=>'daily'],
    ['loc'=>'/articles.php',   'priority'=>'0.7', 'freq'=>'weekly'],
    ['loc'=>'/staff.php',      'priority'=>'0.7', 'freq'=>'monthly'],
    ['loc'=>'/campuses.php',   'priority'=>'0.7', 'freq'=>'monthly'],
    ['loc'=>'/campus-detail.php', 'priority'=>'0.6', 'freq'=>'monthly'],
    ['loc'=>'/statistics.php',       'priority'=>'0.6', 'freq'=>'monthly'],
    ['loc'=>'/statistics-staff.php', 'priority'=>'0.6', 'freq'=>'monthly'],
    ['loc'=>'/forms.php',            'priority'=>'0.6', 'freq'=>'monthly'],
    ['loc'=>'/contact.php',    'priority'=>'0.6', 'freq'=>'monthly'],
    ['loc'=>'/privacy.php',    'priority'=>'0.3', 'freq'=>'yearly'],
  ];
  // lastmod = when the page really changed: the newest news / article for the pages that list them, otherwise the
  // page's own file (it used to say "today" every day, which search engines learn to ignore)
  $newest = fn(array $rows) => $rows ? max(array_map(fn($r) => strtotime($r['updated_at']), $rows)) : 0;
  $lastNews = $newest($newsList); $lastArticle = $newest($articleList);
  foreach ($staticPages as $p):
    $file = __DIR__ . ($p['loc'] === '/' ? '/index.php' : $p['loc']);
    $mod  = (int)@filemtime($file);
    if ($p['loc'] === '/' || $p['loc'] === '/news.php') $mod = max($mod, $lastNews);
    if ($p['loc'] === '/' || $p['loc'] === '/articles.php') $mod = max($mod, $lastArticle);
  ?>
  <url>
    <loc><?= e(BASE_URL_ABSOLUTE . $p['loc'] . '?lang=th') ?></loc>
    <lastmod><?= $mod ? date('Y-m-d', $mod) : $today ?></lastmod>
    <changefreq><?= $p['freq'] ?></changefreq>
    <priority><?= $p['priority'] ?></priority>
    <xhtml:link rel="alternate" hreflang="th" href="<?= e(BASE_URL_ABSOLUTE .$p['loc'] . '?lang=th') ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= e(BASE_URL_ABSOLUTE .$p['loc'] . '?lang=en') ?>"/>
  </url>
  <?php endforeach; ?>

  <!-- News pages -->
  <?php foreach ($newsList as $n): ?>
  <url>
    <loc><?= e(BASE_URL_ABSOLUTE .'/news-detail.php?id=' . $n['news_id'] . '&lang=th') ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($n['updated_at'])) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
    <xhtml:link rel="alternate" hreflang="th" href="<?= e(BASE_URL_ABSOLUTE .'/news-detail.php?id=' . $n['news_id'] . '&lang=th') ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= e(BASE_URL_ABSOLUTE .'/news-detail.php?id=' . $n['news_id'] . '&lang=en') ?>"/>
  </url>
  <?php endforeach; ?>

  <!-- Article pages -->
  <?php foreach ($articleList as $a): ?>
  <url>
    <loc><?= e(BASE_URL_ABSOLUTE .'/articles.php?id=' . $a['article_id'] . '&lang=th') ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($a['updated_at'])) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.6</priority>
    <xhtml:link rel="alternate" hreflang="th" href="<?= e(BASE_URL_ABSOLUTE .'/articles.php?id=' . $a['article_id'] . '&lang=th') ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= e(BASE_URL_ABSOLUTE .'/articles.php?id=' . $a['article_id'] . '&lang=en') ?>"/>
  </url>
  <?php endforeach; ?>

  <!-- Program detail pages -->
  <?php foreach ($programList as $prog): ?>
  <url>
    <loc><?= e(BASE_URL_ABSOLUTE .'/program-detail.php?id=' . $prog['program_id'] . '&lang=th') ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($prog['updated_at'])) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.8</priority>
    <xhtml:link rel="alternate" hreflang="th" href="<?= e(BASE_URL_ABSOLUTE .'/program-detail.php?id=' . $prog['program_id'] . '&lang=th') ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= e(BASE_URL_ABSOLUTE .'/program-detail.php?id=' . $prog['program_id'] . '&lang=en') ?>"/>
  </url>
  <?php endforeach; ?>

</urlset>

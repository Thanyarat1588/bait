<?php
/**
 * BAIT - robots.txt, generated so the Sitemap line always carries the real
 * domain (a static robots.txt can't know it). .htaccess maps /robots.txt here.
 */
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
// The site lives in a sub-folder (…/bait/) on the real host. Crawlers only look for /robots.txt at the DOMAIN ROOT and
// match rules against the full path, so every rule must carry the folder prefix ("/bait/admin/", not "/admin/").
// $p is '' when the site is installed at the root, '/bait' here.
$p = rtrim((string)(parse_url(BASE_URL, PHP_URL_PATH) ?: ''), '/');
?>
User-agent: *
Allow: <?= $p ?>/
Disallow: <?= $p ?>/admin/
Disallow: <?= $p ?>/includes/
Disallow: <?= $p ?>/config/
Disallow: <?= $p ?>/cache/
# Course documents (curriculum PDFs) are meant to be read — by search engines and AI answer engines too.
Allow: <?= $p ?>/uploads/programs-docs/
Disallow: <?= $p ?>/uploads/
Disallow: <?= $p ?>/search.php

Sitemap: <?= BASE_URL_ABSOLUTE ?>/sitemap.xml.php
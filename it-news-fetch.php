<?php
/**
 * BAIT - reads the IT-news feeds when it is due (called in the background by news.php / it-news.php after they have
 * loaded): every hour, and at the first visit of a new day. Does nothing when it is not due or another read is running
 * (lock file), so calling it often costs nothing. POST only; answers {"added": n} — the number of new headlines — so the
 * page can swap in the fresh IT news straight away when there are some.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
header('X-Robots-Tag: noindex');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ignore_user_abort(true);
@set_time_limit(40);
$added = 0;
if (itNewsDue()) { itNewsFetch(); $added = itNewsLastAdded(); }
echo json_encode(['added' => $added]);

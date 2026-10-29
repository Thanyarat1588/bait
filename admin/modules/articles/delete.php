<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
$auth = new Auth(); $auth->requireLogin();
$db   = Database::getInstance();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
verifyCsrf();   // deleting needs the token from the confirm dialog — a plain link can't delete
$id   = (int)($_POST['id'] ?? 0);
$a    = $db->fetchOne("SELECT * FROM articles WHERE article_id = ?", [$id]);
if ($a) {
    $db->delete('articles','article_id = ?',[$id]);
    fileQueueDelete($a['image']); fileCommitChanges();   // the picture goes only once the row is really gone
    $_SESSION['flash_success'] = 'ลบบทความเรียบร้อยแล้ว';
} else $_SESSION['flash_error'] = 'ไม่พบบทความ';
header('Location: index.php'); exit;
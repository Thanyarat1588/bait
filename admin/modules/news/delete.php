<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
verifyCsrf();   // deleting needs the token from the confirm dialog — a plain link can't delete
$id   = (int)($_POST['id'] ?? 0);
$news = $db->fetchOne("SELECT * FROM news WHERE news_id = ?", [$id]);

if ($news) {
    $db->delete('news', 'news_id = ?', [$id]);
    fileQueueDelete($news['image']); fileCommitChanges();   // the picture goes only once the row is really gone
    $_SESSION['flash_success'] = 'ลบข่าวสารเรียบร้อยแล้ว';
} else {
    $_SESSION['flash_error'] = 'ไม่พบข่าวสารที่ต้องการลบ';
}

header('Location: index.php');
exit;

<?php
/**
 * BAIT Admin - Logout. Signing out needs a POST carrying the CSRF token (the menu button sends one), so another website
 * cannot sign an admin out with an <img src=".../logout.php">. A plain visit shows one confirm button instead.
 */
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$auth = new Auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf_token'] ?? '';
    if (is_string($tok) && hash_equals(csrfToken(), $tok)) {
        $auth->logout();
        header('Location: ' . BASE_URL . '/admin/login.php?logout=1');
        exit;
    }
    http_response_code(403);
}
if (!$auth->isLoggedIn()) {   // nothing to sign out of
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>ออกจากระบบ | BAIT Admin</title>
<style>
  body { font-family: Kanit, system-ui, sans-serif; background: #f5f6fa; margin: 0; min-height: 100vh; display: grid; place-items: center; color: #1e2a45; }
  .box { background: #fff; border-radius: 16px; padding: 28px 24px; width: min(92vw, 360px); text-align: center; box-shadow: 0 8px 30px rgba(15,67,155,.12); }
  button { font: inherit; font-weight: 600; background: #0F439B; color: #fff; border: 0; border-radius: 10px; padding: 12px 20px; cursor: pointer; width: 100%; }
  a { display: inline-block; margin-top: 14px; color: #0F439B; }
</style>
</head>
<body>
<form method="POST" class="box">
  <?= csrfField() ?>
  <p style="margin:0 0 16px;font-size:1.05rem">ต้องการออกจากระบบใช่หรือไม่?</p>
  <button type="submit">ออกจากระบบ</button>
  <a href="<?= BASE_URL ?>/admin/dashboard.php">กลับไปแดชบอร์ด</a>
</form>
</body>
</html>
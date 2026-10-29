<?php
/**
 * BAIT Admin - First-run setup: creates the first (and only) Super Admin on a fresh install.
 *
 * Works ONLY while the users table is empty (just after database/schema.sql was imported). Once any account exists it
 * locks itself for good: every visit gets "already set up" and nothing is read from the form. Restoring a real
 * database dump brings its own accounts along, so on such a host this page is locked from the start.
 * Two people sending the form at the same moment cannot both win: the check and the insert run under one named lock.
 */

require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$auth = new Auth();
$db   = Database::getInstance();

header('X-Robots-Tag: noindex, nofollow');

/** null = the users table does not exist yet (schema.sql not imported); otherwise the number of accounts. */
$countUsers = function () use ($db): ?int {
    try { return (int)($db->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0); }
    catch (Throwable $e) { return null; }
};

$userCount = $countUsers();
$locked    = $userCount !== null && $userCount > 0;
$error     = '';
$done      = false;
$old       = ['username' => '', 'full_name' => '', 'email' => ''];

if ($locked) http_response_code(403);

if (!$locked && $userCount !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf_token'] ?? '';
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $old = ['username' => $str('username'), 'full_name' => $str('full_name'), 'email' => $str('email')];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';   // not trimmed: a password may start or end with a space
    $confirm  = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!is_string($tok) || !hash_equals(csrfToken(), $tok)) {
        $error = 'หน้านี้หมดอายุ กรุณาโหลดหน้าใหม่แล้วกรอกอีกครั้ง';
    } elseif ($old['username'] === '' || $old['full_name'] === '' || $old['email'] === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $old['username'])) {
        $error = 'ชื่อผู้ใช้ต้องเป็นตัวอักษรอังกฤษ ตัวเลข หรือ _ . - ยาว 3–50 ตัว';
    } elseif (mb_strlen($old['full_name'], 'UTF-8') > 255) {
        $error = 'ชื่อ-นามสกุลยาวเกินไป (ไม่เกิน 255 ตัวอักษร)';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 50) {
        $error = 'รูปแบบอีเมลไม่ถูกต้อง (ไม่เกิน 50 ตัวอักษร)';
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        $got = (int)($db->fetchOne("SELECT GET_LOCK('bait_first_setup', 10) AS l")['l'] ?? 0);
        try {
            if (!$got) {
                $error = 'ระบบกำลังสร้างบัญชีจากอีกหน้าต่างหนึ่ง กรุณาลองใหม่อีกครั้ง';
            } elseif ($countUsers() !== 0) {
                // somebody else finished first while this form was open
                $locked = true;
                http_response_code(403);
            } else {
                $db->insert('users', [
                    'username'      => $old['username'],
                    'full_name'     => $old['full_name'],
                    'email'         => $old['email'],
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                    'role'          => 'superadmin',
                    'is_active'     => 1,
                ]);
                $done = true;
                $locked = true;
            }
        } finally {
            if ($got) $db->fetchOne("SELECT RELEASE_LOCK('bait_first_setup') AS r");
        }
    }
}
$faculty = [];
if ($userCount !== null) { try { $faculty = $db->fetchOne("SELECT logo, faculty_name_th FROM faculty LIMIT 1") ?: []; } catch (Throwable $e) {} }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="only light">
<meta name="robots" content="noindex, nofollow">
<title>ตั้งค่าครั้งแรก | BAIT Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/ne-theme.css">
<style>
  * { box-sizing: border-box; }
  body { min-height: 100vh; background: var(--ne-blue-deep); display: flex; align-items: center; justify-content: center;
         font-family: 'Kanit', 'Segoe UI', sans-serif; padding: 1rem; }
  .setup-wrapper { width: 100%; max-width: 480px; }
  .setup-card { background: #fff; border-radius: 20px; padding: 1.75rem 1.25rem 1.5rem; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
  @media (min-width: 480px) { .setup-card { padding: 2.25rem 2.25rem 2rem; } }
  .logo-area { text-align: center; margin-bottom: 1.5rem; }
  .logo-icon { width: 72px; height: 72px; background: var(--ne-blue-deep); border-radius: 18px; display: inline-flex;
               align-items: center; justify-content: center; margin-bottom: .75rem; overflow: hidden; }
  .logo-icon.has-logo { background: #fff; padding: 8px; }
  .logo-icon.has-logo img { width: 100%; height: 100%; object-fit: contain; }
  .logo-icon i { font-size: 2.1rem; color: #fff; }
  .logo-area h1 { font-size: 1.3rem; font-weight: 700; color: var(--ne-ink); margin: 0 0 .3rem; }
  .logo-area p { font-size: .88rem; color: var(--ne-slate); margin: 0; }
  .form-label { font-weight: 600; color: var(--ne-ink); font-size: .9rem; margin-bottom: .35rem; }
  .form-control { padding: .65rem .9rem; font-size: 1rem; border-color: var(--ne-line); }   /* >=16px: no iOS zoom */
  .form-text { font-size: .8rem; }
  .btn-setup { width: 100%; padding: .8rem; background: var(--ne-blue-deep); border: none; border-radius: 10px; color: #fff;
               font-size: 1rem; font-weight: 600; margin-top: .5rem; }
  .btn-setup:hover { background: var(--ne-ink); color: #fff; }
  .alert { border: none; border-radius: 10px; font-size: .92rem; }
</style>
</head>
<body>
<div class="setup-wrapper">
  <div class="setup-card">
    <div class="logo-area">
      <div class="logo-icon <?= !empty($faculty['logo']) ? 'has-logo' : '' ?>">
        <?php if (!empty($faculty['logo'])): ?>
        <img src="<?= UPLOAD_URL . '/' . e($faculty['logo']) ?>" alt="">
        <?php else: ?>
        <i class="bi bi-gear-fill"></i>
        <?php endif; ?>
      </div>
      <h1>ตั้งค่าระบบครั้งแรก</h1>
      <p>สร้างบัญชี Super Admin สำหรับดูแลระบบ</p>
    </div>

    <?php if ($done): ?>
    <div class="alert alert-success d-flex gap-2"><i class="bi bi-check-circle-fill"></i>
      <span>สร้างบัญชี Super Admin <strong><?= e($old['username']) ?></strong> เรียบร้อยแล้ว หน้านี้ถูกปิดใช้งานโดยอัตโนมัติ</span></div>
    <a class="btn btn-setup" href="<?= BASE_URL ?>/admin/login.php"><i class="bi bi-box-arrow-in-right me-2"></i>ไปหน้าเข้าสู่ระบบ</a>

    <?php elseif ($locked): ?>
    <div class="alert alert-secondary d-flex gap-2"><i class="bi bi-lock-fill"></i>
      <span>ระบบนี้ตั้งค่าเรียบร้อยแล้ว (มีบัญชีผู้ใช้อยู่ในระบบ) หน้านี้จึงใช้งานไม่ได้
      หากลืมรหัสผ่าน Super Admin ให้ผู้ดูแลเซิร์ฟเวอร์ตั้งรหัสใหม่ตามคู่มือติดตั้ง (README.md)</span></div>
    <a class="btn btn-setup" href="<?= BASE_URL ?>/admin/login.php"><i class="bi bi-box-arrow-in-right me-2"></i>ไปหน้าเข้าสู่ระบบ</a>

    <?php elseif ($userCount === null): ?>
    <div class="alert alert-warning d-flex gap-2"><i class="bi bi-database-exclamation"></i>
      <span>ยังไม่พบตารางผู้ใช้ในฐานข้อมูล กรุณานำเข้าไฟล์ <code>database/schema.sql</code> (หรือไฟล์ฐานข้อมูลจริง) ก่อน แล้วโหลดหน้านี้ใหม่</span></div>

    <?php else: ?>
    <div class="alert alert-info d-flex gap-2"><i class="bi bi-info-circle-fill"></i>
      <span>หน้านี้ใช้ได้ครั้งเดียว ขณะที่ยังไม่มีบัญชีใดในระบบ เมื่อสร้างบัญชีแล้วจะปิดตัวเองทันที
      บัญชีอื่น (Sub Admin) เพิ่มภายหลังได้จากเมนู "จัดการผู้ใช้"</span></div>
    <?php if ($error): ?>
    <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label" for="username">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="username" name="username" value="<?= e($old['username']) ?>"
               required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.\-]{3,50}" autocomplete="username" autocapitalize="none">
        <div class="form-text">ตัวอักษรอังกฤษ ตัวเลข หรือ _ . - ยาว 3–50 ตัว ใช้สำหรับเข้าสู่ระบบ</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="full_name">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="255">
      </div>
      <div class="mb-3">
        <label class="form-label" for="email">อีเมล <span class="text-danger">*</span></label>
        <input type="email" class="form-control" id="email" name="email" value="<?= e($old['email']) ?>" required maxlength="50" autocomplete="email">
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">รหัสผ่าน <span class="text-danger">*</span></label>
        <input type="password" class="form-control" id="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="confirm_password">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-setup"><i class="bi bi-person-plus-fill me-2"></i>สร้างบัญชี Super Admin</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

<?php
/**
 * BAIT Admin - My Profile
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();
$me   = $auth->currentUser();

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $fullName = postStr('full_name');
        $email    = postStr('email');
        if (!$fullName || !$email) { $error = 'กรุณากรอกข้อมูลให้ครบ'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'รูปแบบอีเมลไม่ถูกต้อง'; }
        elseif (rowExists($db, 'users', ['email' => $email], 'user_id', (int)$me['user_id'])) { $error = 'อีเมล "' . $email . '" ถูกใช้โดยผู้ใช้อื่นแล้ว กรุณาใช้อีเมลอื่น'; }
        else {
            $db->update('users', ['full_name'=>$fullName,'email'=>$email], 'user_id = ?', [$me['user_id']]);
            $_SESSION['full_name'] = $fullName;
            $_SESSION['email']     = $email;
            $success = 'บันทึกข้อมูลเรียบร้อยแล้ว';
            $me = $auth->currentUser();
        }
    }

    if ($action === 'change_password') {
        $current = postRaw('current_password');   // not trimmed: a password may start or end with a space
        $new     = postRaw('new_password');
        $confirm = postRaw('confirm_password');
        $user    = $db->fetchOne("SELECT password_hash FROM users WHERE user_id = ?", [$me['user_id']]);

        // Somebody using a signed-in computer left open must not be able to guess the password here without limit:
        // 5 wrong guesses, then 15 minutes' wait (kept in the session; a new session needs the password to sign in anyway)
        $pwFails = array_values(array_filter((array)($_SESSION['pw_change_fails'] ?? []), fn($t) => is_int($t) && $t > time() - 900));
        if (count($pwFails) >= 5) {
            $error = 'ใส่รหัสผ่านปัจจุบันผิดหลายครั้งเกินไป กรุณารอ ' . max(1, (int)ceil(($pwFails[0] + 900 - time()) / 60)) . ' นาทีแล้วลองใหม่';
        } elseif (!password_verify($current, $user['password_hash'])) {
            $pwFails[] = time();
            $_SESSION['pw_change_fails'] = $pwFails;
            $error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } elseif (strlen($new) < 8) {
            $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
        } elseif ($new !== $confirm) {
            $error = 'รหัสผ่านใหม่และยืนยันไม่ตรงกัน';
        } else {
            $auth->changePassword($me['user_id'], $new);
            unset($_SESSION['pw_change_fails']);
            $success = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
        }
    }
}

$profileUser = $db->fetchOne("SELECT * FROM users WHERE user_id = ?", [$me['user_id']]);

$pageTitle  = 'โปรไฟล์ของฉัน';
$activeMenu = '';
$breadcrumb = [['label' => 'โปรไฟล์ของฉัน']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <?php if ($success): ?>
  <div class="alert alert-success border-0 rounded-3 mb-3"><?=e($success)?></div>
  <?php elseif ($error): ?>
  <div class="alert alert-danger border-0 rounded-3 mb-3"><?=e($error)?></div>
  <?php endif; ?>

  <div class="ne-grid">
    <!-- Profile card -->
    <div class="ne-side">
      <div class="ne-panel text-center">
        <div class="ne-panel-body">
          <div class="mx-auto mb-3" style="width:80px;height:80px;border-radius:50%;background:var(--ne-blue);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;color:white">
            <?=mb_substr($profileUser['full_name'],0,1,'UTF-8')?>
          </div>
          <h5 style="font-family:'Kanit',serif;font-weight:700;"><?=e($profileUser['full_name'])?></h5>
          <p style="color:var(--ne-ink-soft);margin-bottom:6px;"><?=e($profileUser['email'])?></p>
          <span class="ne-chip" style="background:<?=$profileUser['role']==='superadmin'?'var(--ne-blue-soft)':'var(--ne-slate-soft)'?>;color:<?=$profileUser['role']==='superadmin'?'var(--ne-blue-deep)':'var(--ne-ink-soft)'?>;">
            <?=$profileUser['role']==='superadmin'?'Super Admin':'Sub Admin'?>
          </span>
          <hr>
          <p class="ne-hint">เข้าใช้งานล่าสุด:<br><?=$profileUser['last_login']?thaiDate($profileUser['last_login'],true):'-'?></p>
        </div>
      </div>
    </div>

    <div class="ne-main">
      <!-- Update info -->
      <div class="ne-panel" style="margin-bottom:14px;">
        <div class="ne-panel-head"><span><i class="bi bi-person" style="color:var(--ne-blue);margin-right:6px;"></i>แก้ไขข้อมูล</span></div>
        <div class="ne-panel-body">
          <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="update_info">
            <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;">
              <div class="ne-meta-field"><label for="pFullName">ชื่อ-นามสกุล</label>
                <input type="text" class="ne-input" id="pFullName" name="full_name" value="<?=e($profileUser['full_name'])?>" required></div>
              <div class="ne-meta-field"><label for="pEmail">อีเมล</label>
                <input type="email" class="ne-input" id="pEmail" name="email" value="<?=e($profileUser['email'])?>" required></div>
            </div>
            <div class="ne-field" style="margin-bottom:0;"><label for="pUsername">ชื่อผู้ใช้</label>
              <input type="text" class="ne-input" id="pUsername" value="<?=e($profileUser['username'])?>" disabled style="background:var(--ne-paper);">
              <p class="ne-hint">ไม่สามารถเปลี่ยนชื่อผู้ใช้ได้</p></div>
            <button type="submit" class="ne-btn ne-btn-primary" style="margin-top:6px;">บันทึกข้อมูล</button>
          </form>
        </div>
      </div>

      <!-- Change password -->
      <div class="ne-panel">
        <div class="ne-panel-head"><span><i class="bi bi-lock" style="color:var(--ne-blue);margin-right:6px;"></i>เปลี่ยนรหัสผ่าน</span></div>
        <div class="ne-panel-body">
          <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="change_password">
            <div class="ne-field"><label for="pCurrent">รหัสผ่านปัจจุบัน</label>
              <input type="password" class="ne-input" id="pCurrent" name="current_password" required></div>
            <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;">
              <div class="ne-meta-field"><label for="pNew">รหัสผ่านใหม่</label>
                <input type="password" class="ne-input" id="pNew" name="new_password" required minlength="8">
                <p class="ne-hint">อย่างน้อย 8 ตัวอักษร</p></div>
              <div class="ne-meta-field"><label for="pConfirm">ยืนยันรหัสผ่านใหม่</label>
                <input type="password" class="ne-input" id="pConfirm" name="confirm_password" required></div>
            </div>
            <button type="submit" class="ne-btn ne-btn-primary" style="margin-top:6px;">เปลี่ยนรหัสผ่าน</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

<?php
/**
 * BAIT Admin - Login Page
 */

require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$auth = new Auth();
$db   = Database::getInstance();

// ถ้า login แล้ว redirect ไป dashboard
if ($auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$faculty = $db->fetchOne("SELECT logo, faculty_name_th FROM faculty LIMIT 1") ?: [];
// fresh install (no account yet): point to the one-time setup page that creates the Super Admin
$noUsersYet = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0) === 0;

$error   = '';
$success = '';

/**
 * Brute-force protection: after 5 wrong passwords for the same username from the same address, or 20 from one
 * address, logins from it are refused for 15 minutes. Counters live in cache/login_attempts.json; if that file
 * can't be written (read-only host) the protection simply stays off and login works as before.
 */
const LOGIN_WINDOW = 900, LOGIN_MAX_USER = 5, LOGIN_MAX_IP = 20;
/** Load, change and save the counters while holding one exclusive lock, so parallel attempts cannot overwrite each other.
 *  The file: loginAttemptsFile() in includes/helpers.php (cache/, else the system temp folder). */
function loginAttemptsMutate(callable $change): void {
    $path = loginAttemptsFile();
    $fh = $path ? @fopen($path, 'c+') : false;
    if (!$fh) {   // read-only host: protection stays off, login still works - but say so (log + super admin dashboard notice)
        error_log('BAIT: login guess limit is OFF - cannot write ' . ($path ?: 'cache/ or the system temp folder'));
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $d = @json_decode((string)$raw, true);
        $now = time(); $out = [];
        foreach ((is_array($d) ? $d : []) as $k => $times) {
            $times = array_values(array_filter((array)$times, fn($t) => is_int($t) && $now - $t < LOGIN_WINDOW));
            if ($times) $out[$k] = $times;
        }
        $out = $change($out);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($out)); fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}
function loginKeys(string $username): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return ['u:' . sha1($ip . '|' . mb_strtolower($username)), 'i:' . sha1($ip)];
}
/**
 * Checks the limit AND counts this attempt in one locked step: many guesses sent at the same moment used to all pass the
 * check before the first failure was written. Returns null when blocked, else the time stamp it recorded (handed back to
 * loginRelease() when the password turns out right, so a successful sign-in does not use up an attempt).
 * Without a writable counter file it allows the attempt (protection off, sign-in still works).
 */
function loginReserve(string $username): ?int {
    $ts = time(); $ok = true; $written = false;
    loginAttemptsMutate(function (array $d) use ($username, $ts, &$ok, &$written) {
        $written = true;
        [$ku, $ki] = loginKeys($username);
        if (count($d[$ku] ?? []) >= LOGIN_MAX_USER || count($d[$ki] ?? []) >= LOGIN_MAX_IP) { $ok = false; return $d; }
        $d[$ku][] = $ts; $d[$ki][] = $ts;
        return $d;
    });
    if (!$written) return 0;
    return $ok ? $ts : null;
}
/** Successful sign-in: forget this user's failures and give back the one attempt loginReserve() counted for the address. */
function loginRelease(string $username, int $ts): void {
    loginAttemptsMutate(function (array $d) use ($username, $ts) {
        [$ku, $ki] = loginKeys($username);
        unset($d[$ku]);
        if (isset($d[$ki]) && ($i = array_search($ts, $d[$ki], true)) !== false) { array_splice($d[$ki], $i, 1); if (!$d[$ki]) unset($d[$ki]); }
        return $d;
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? '') ? postStr('username') : '';
    $password = is_string($_POST['password'] ?? '') ? ($_POST['password'] ?? '') : '';

    $tok = $_POST['csrf_token'] ?? '';
    if (!is_string($tok) || !hash_equals(csrfToken(), $tok)) {
        $error = 'หน้านี้หมดอายุ กรุณาลองเข้าสู่ระบบอีกครั้ง';
    } elseif (!is_string($username) || !is_string($password) || empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif (($attempt = loginReserve($username)) === null) {
        $error = 'เข้าสู่ระบบผิดพลาดหลายครั้งเกินไป กรุณารอประมาณ 15 นาทีแล้วลองใหม่อีกครั้ง';
    } elseif ($auth->login($username, $password)) {
        loginRelease($username, $attempt);
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    } else {
        // the failed attempt is already counted (loginReserve)
        usleep(400000);   // slows down automated guessing
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="only light">   <!-- the admin has one (light) design; stops browsers from forcing a broken dark version of it -->
<title>เข้าสู่ระบบ | BAIT Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/ne-theme.css">
<style>
  :root {
    --primary: var(--ne-blue-deep);
    --secondary: var(--ne-blue);
    --accent: var(--ne-gold);
  }

  * { box-sizing: border-box; }

  body {
    min-height: 100vh;
    background: var(--ne-blue-deep);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Kanit', 'Segoe UI', sans-serif;
    padding: 1rem;
  }

  /* Background geometric shapes */
  body::before, body::after {
    content: '';
    position: fixed;
    border-radius: 50%;
    opacity: 0.07;
    pointer-events: none;
  }
  body::before {
    width: 600px; height: 600px;
    background: var(--ne-gold);
    top: -150px; right: -150px;
  }
  body::after {
    width: 400px; height: 400px;
    background: white;
    bottom: -100px; left: -100px;
  }

  .login-wrapper {
    width: 100%;
    max-width: 440px;
    position: relative;
    z-index: 1;
  }

  .login-card {
    background: rgba(255,255,255,0.97);
    border-radius: 20px;
    padding: 1.75rem 1.5rem 1.5rem;
    box-shadow: 0 30px 80px rgba(0,0,0,0.35);
    backdrop-filter: blur(10px);
  }
  @media (min-width: 480px) {
    .login-card { padding: 2.5rem 2.5rem 2rem; }
  }

  .logo-area {
    text-align: center;
    margin-bottom: 2rem;
  }

  .logo-icon {
    width: 80px; height: 80px;
    background: var(--ne-blue-deep);
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    box-shadow: 0 8px 24px rgba(22,46,147,0.35);
    overflow: hidden;
  }
  .logo-icon.has-logo { background: #fff; padding: 10px; }
  .logo-icon.has-logo img { width: 100%; height: 100%; object-fit: contain; }

  .logo-icon i { font-size: 2.4rem; color: white; }

  .logo-area h1 {
    font-family: 'Kanit', sans-serif;
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--ne-ink);
    margin: 0 0 0.3rem;
    line-height: 1.3;
  }

  .logo-area p {
    font-size: 0.85rem;
    color: var(--ne-slate);
    margin: 0;
  }

  .badge-admin {
    display: inline-block;
    background: var(--ne-blue-deep);
    color: white;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 0.25rem 0.85rem;
    border-radius: 20px;
    margin-bottom: 0.75rem;
  }

  .form-label {
    font-weight: 600;
    color: var(--ne-ink);
    font-size: 0.88rem;
    margin-bottom: 0.4rem;
  }

  .input-group-text {
    background: var(--ne-paper);
    border-right: none;
    border-color: var(--ne-line);
    color: var(--ne-blue-deep);
  }

  .form-control {
    border-left: none;
    padding: 0.7rem 1rem;
    font-size: 1rem; /* >=16px keeps iOS Safari from auto-zooming the page on input focus */
    border-color: var(--ne-line);
  }

  .form-control:focus {
    border-color: var(--ne-blue);
    box-shadow: 0 0 0 0.2rem rgba(22,46,147,0.2);
  }

  .input-group:focus-within .input-group-text {
    border-color: var(--ne-blue);
  }

  .btn-login {
    width: 100%;
    padding: 0.8rem;
    background: var(--ne-blue-deep);
    border: none;
    border-radius: 10px;
    color: white;
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    transition: all 0.3s;
    margin-top: 0.5rem;
  }

  .btn-login:hover {
    background: var(--ne-ink);
    box-shadow: 0 6px 20px rgba(30,42,69,0.4);
    transform: translateY(-1px);
  }

  .btn-login:active { transform: translateY(0); }

  .alert-custom {
    border-radius: 10px;
    font-size: 0.9rem;
    border: none;
    padding: 0.75rem 1rem;
  }

  .show-password-btn {
    background: var(--ne-paper);
    border: 1px solid var(--ne-line);
    border-left: none;
    cursor: pointer;
    color: var(--ne-slate);
    transition: color 0.2s;
  }
  .show-password-btn:hover { color: var(--ne-blue-deep); }

  .footer-note {
    text-align: center;
    margin-top: 1.5rem;
    font-size: 0.78rem;
    color: rgba(255,255,255,0.7);
  }

  .divider {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 1.25rem 0;
    color: var(--ne-slate);
    font-size: 0.8rem;
  }
  .divider::before, .divider::after {
    content: ''; flex: 1; height: 1px; background: var(--ne-line);
  }

  @keyframes shake {
    0%,100%{transform:translateX(0)}
    20%{transform:translateX(-6px)}
    40%{transform:translateX(6px)}
    60%{transform:translateX(-4px)}
    80%{transform:translateX(4px)}
  }
  .shake { animation: shake 0.4s ease; }
</style>
</head>
<body>

<div class="login-wrapper">
  <div class="login-card">
    <!-- Logo -->
    <div class="logo-area">
      <div class="badge-admin">Admin Panel</div>
      <div class="logo-icon <?= !empty($faculty['logo']) ? 'has-logo' : '' ?>">
        <?php if (!empty($faculty['logo'])): ?>
        <img src="<?= UPLOAD_URL . '/' . e($faculty['logo']) ?>" alt="<?= e($faculty['faculty_name_th'] ?? 'BAIT') ?>">
        <?php else: ?>
        <i class="bi bi-mortarboard-fill"></i>
        <?php endif; ?>
      </div>
      <h1>คณะบริหารธุรกิจ<br>และเทคโนโลยีสารสนเทศ</h1>
      <p>มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ</p>
    </div>

    <!-- Alert -->
    <?php $expired = getStr('expired'); if (!$error && in_array($expired, ['1', 'post'], true)): ?>
    <div class="alert alert-warning alert-custom d-flex align-items-start gap-2 mb-3" role="alert">
      <i class="bi bi-clock-history" style="margin-top:2px;"></i>
      <span><?= $expired === 'post'
        ? 'เซสชันหมดอายุ ข้อมูลที่เพิ่งกดบันทึก<strong>ยังไม่ได้บันทึก</strong> — กดปุ่มย้อนกลับของเบราว์เซอร์เพื่อคัดลอกข้อความที่พิมพ์ไว้ แล้วเข้าสู่ระบบใหม่และบันทึกอีกครั้ง'
        : 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่' ?></span>
    </div>
    <?php endif; ?>
    <?php if ($noUsersYet): ?>
    <div class="alert alert-info alert-custom d-flex align-items-start gap-2 mb-3" role="alert">
      <i class="bi bi-gear-fill" style="margin-top:2px;"></i>
      <span>ยังไม่มีบัญชีผู้ใช้ในระบบ — <a href="<?= BASE_URL ?>/admin/setup.php" class="fw-semibold">ตั้งค่าครั้งแรก (สร้างบัญชี Super Admin)</a></span>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-custom d-flex align-items-center gap-2 mb-3" id="errorAlert">
      <i class="bi bi-exclamation-circle-fill"></i>
      <?= e($error) ?>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" id="loginForm" novalidate>
      <?= csrfField() ?>

      <div class="mb-3">
        <label class="form-label" for="username">
          <i class="bi bi-person me-1"></i>ชื่อผู้ใช้
        </label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" class="form-control" id="username" name="username"
                 placeholder="กรอกชื่อผู้ใช้" autocomplete="username"
                 value="<?= e(is_string($_POST['username'] ?? '') ? ($_POST['username'] ?? '') : '') ?>" required autofocus>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="password">
          <i class="bi bi-lock me-1"></i>รหัสผ่าน
        </label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" class="form-control" id="password" name="password"
                 placeholder="กรอกรหัสผ่าน" autocomplete="current-password" required>
          <button type="button" class="show-password-btn px-3" id="togglePwd" tabindex="-1">
            <i class="bi bi-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <div class="divider">เข้าสู่ระบบด้วยบัญชีผู้ดูแล</div>

      <button type="submit" class="btn-login" id="loginBtn">
        <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
      </button>
    </form>
  </div>

  <p class="footer-note">
    <i class="bi bi-shield-lock me-1"></i>
    ระบบสงวนสิทธิ์สำหรับเจ้าหน้าที่ที่ได้รับอนุญาตเท่านั้น
  </p>
</div>

<script>
// Toggle password visibility
document.getElementById('togglePwd').addEventListener('click', function() {
  const pwd  = document.getElementById('password');
  const icon = document.getElementById('eyeIcon');
  if (pwd.type === 'password') {
    pwd.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    pwd.type = 'password';
    icon.className = 'bi bi-eye';
  }
});

// Loading state on submit
document.getElementById('loginForm').addEventListener('submit', function(e) {
  const btn = document.getElementById('loginBtn');
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังตรวจสอบ...';
  btn.disabled = true;
});

// Shake on error
<?php if ($error): ?>
document.getElementById('errorAlert')?.classList.add('shake');
<?php endif; ?>
</script>
</body>
</html>

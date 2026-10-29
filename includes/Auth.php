<?php
/**
 * BAIT - Authentication & Session Management
 */

require_once __DIR__ . '/Database.php';

class Auth {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->startSession();
    }

    private function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            // A session cookie PHP cannot use (made up / damaged: characters outside A-Z a-z 0-9 , -) made session_start()
            // log two warnings on every request; treat it as no cookie: a fresh session starts and the old one is replaced.
            if (isset($_COOKIE[SESSION_NAME]) && !(is_string($_COOKIE[SESSION_NAME]) && preg_match('/^[A-Za-z0-9,-]{1,256}$/', $_COOKIE[SESSION_NAME]))) unset($_COOKIE[SESSION_NAME]);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                // Secure cookie whenever THIS request came in over https (so it is never sent over plain http);
                // on a plain-http request the flag stays off, otherwise login would be impossible there.
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                              || ($_SERVER['SERVER_PORT'] ?? null) == 443
                              || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }

    // Login: ตรวจ username/password, คืน true/false
    /** The request came from page script asking for JSON (axios sends "Accept: application/json, ..."), not a normal page load. */
    private static function wantsJson(): bool {
        return stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
    }

    public function login(string $username, string $password): bool {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1",
            [$username]
        );

        if (!$user) {
            // same work as a real check, so "no such user" is not noticeably faster than "wrong password"
            password_verify($password, '$2y$12$LC4fxQnKr3aSm380OBDKO.4xirVYAhkDesep83sGefbjCdWSomLI6');
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) return false;

        // บันทึก session
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['login_at']  = time();
        $_SESSION['started_at'] = time();
        $_SESSION['verified_at'] = time();
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 0);   // see stillAllowed() / changePassword()

        // Regenerate session ID เพื่อป้องกัน Session Fixation
        session_regenerate_id(true);

        // อัปเดต last_login
        $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'user_id = ?', [$user['user_id']]);

        return true;
    }

    // Logout
    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    // ตรวจว่า login อยู่หรือไม่
    public function isLoggedIn(): bool {
        if (!isset($_SESSION['user_id'], $_SESSION['login_at'])) return false;
        // Session timeout
        if (time() - $_SESSION['login_at'] > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }
        // Sliding renewal must not keep a session alive forever: it ends after SESSION_ABSOLUTE seconds (default 12 h) from sign-in.
        $_SESSION['started_at'] = $_SESSION['started_at'] ?? time();
        if (time() - $_SESSION['started_at'] > (defined('SESSION_ABSOLUTE') ? SESSION_ABSOLUTE : 43200)) {
            $this->logout();
            return false;
        }
        return $this->stillAllowed();
    }

    /**
     * A session must stop working when the account is switched off or demoted while somebody is signed in, and when
     * its password was changed (users.session_version went up: the session was made with the old password).
     * Re-reads the user at most once a minute (one cheap primary-key query), so it costs nothing noticeable.
     */
    private function stillAllowed(): bool {
        $now = time();
        if (isset($_SESSION['verified_at']) && $now - (int)$_SESSION['verified_at'] < 60) return true;
        try {
            $u = $this->db->fetchOne('SELECT * FROM users WHERE user_id = ? LIMIT 1', [$_SESSION['user_id']]);
        } catch (Throwable $e) {
            error_log('BAIT: could not re-check the signed-in user: ' . $e->getMessage());
            return true;   // database hiccup: do not lock everybody out because of it
        }
        if (!$u || (int)$u['is_active'] !== 1) {
            $this->logout();
            return false;
        }
        // (before migration step 13 the column does not exist: the check is skipped)
        if (array_key_exists('session_version', $u) && (int)$u['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
            $this->logout();
            return false;
        }
        $_SESSION['role']        = $u['role'];        // a changed role applies immediately, no new sign-in needed
        $_SESSION['full_name']   = $u['full_name'];
        $_SESSION['verified_at'] = $now;
        return true;
    }

    // Require login (redirect ถ้าไม่ได้ login)
    public function requireLogin(string $redirect = '/admin/login.php'): void {
        if (!$this->isLoggedIn()) {
            // A save sent by page script (axios asks for JSON) used to be redirected to the login page: the script then
            // received an HTML page with "200 OK", showed an empty error box (or nothing at all) and the admin never learnt
            // that nothing was saved. It now gets a clear 401 the page can show. An ordinary form gets the login page with
            // a note that the session ended and the form was not saved.
            if (self::wantsJson()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'code' => 'session_expired', 'login' => BASE_URL . $redirect,
                    'message' => 'เซสชันหมดอายุ (ไม่ได้ใช้งานนานหรือออกจากระบบแล้ว) รายการนี้ยังไม่ได้บันทึก กรุณาเข้าสู่ระบบใหม่แล้วทำอีกครั้ง'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $expired = isset($_COOKIE[session_name()]) ? (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? '?expired=post' : '?expired=1') : '';
            header('Location: ' . BASE_URL . $redirect . $expired);
            exit;
        }
        // ต่ออายุ session
        $_SESSION['login_at'] = time();
    }

    // ตรวจสิทธิ์ superadmin
    public function requireSuperAdmin(): void {
        $this->requireLogin();
        if ($_SESSION['role'] !== 'superadmin') {
            if (self::wantsJson()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'code' => 'forbidden', 'message' => 'บัญชีนี้ไม่มีสิทธิ์ทำรายการนี้ (เฉพาะ Super Admin)'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            http_response_code(403);
            include BASE_PATH . '/admin/403.php';
            exit;
        }
    }

    // ดึงข้อมูล user ปัจจุบัน
    public function currentUser(): array {
        if (!$this->isLoggedIn()) return [];
        return [
            'user_id'   => $_SESSION['user_id'],
            'username'  => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role'      => $_SESSION['role'],
            'email'     => $_SESSION['email'],
        ];
    }

    public function isSuperAdmin(): bool {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin';
    }

    // เปลี่ยนรหัสผ่าน
    public function changePassword(int $userId, string $newPassword): bool {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $ok = $this->db->update('users', ['password_hash' => $hash], 'user_id = ?', [$userId]) > 0;
        if ($ok) $this->endOtherSessions($userId);
        return $ok;
    }

    /**
     * After a password change: every other session of this account ends within a minute (stillAllowed()).
     * The session doing the change stays signed in. Before migration step 13 (no users.session_version) nothing happens.
     */
    public function endOtherSessions(int $userId): void {
        try {
            $this->db->query('UPDATE users SET session_version = session_version + 1 WHERE user_id = ?', [$userId]);
            if ((int)($_SESSION['user_id'] ?? 0) === $userId) {
                $v = $this->db->fetchOne('SELECT session_version FROM users WHERE user_id = ?', [$userId]);
                $_SESSION['session_version'] = (int)($v['session_version'] ?? 0);
            }
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'session_version') === false) error_log('BAIT: endOtherSessions: ' . $e->getMessage());
        }
    }
}

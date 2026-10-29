<?php
/**
 * BAIT - Database Class (Singleton PDO)
 */

require_once dirname(__DIR__) . '/config/database.php';

// One clock for the whole site: Thai time. PHP's own default (often UTC or Europe/Berlin, depending on the server) and the
// database's clock used to disagree, so "last login" showed hours off. Both are set here, not in config/database.php.
if (!defined('SITE_TIMEZONE')) define('SITE_TIMEZONE', 'Asia/Bangkok');
date_default_timezone_set(SITE_TIMEZONE);

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // the database's NOW() / CURRENT_TIMESTAMP follow the same clock as PHP (+07:00 = Thailand, no daylight saving)
            try { $this->pdo->exec("SET time_zone = '+07:00'"); } catch (PDOException $e) { error_log('[Database] could not set time_zone: ' . $e->getMessage()); }
            // Opt-in: define('DB_STRICT_MODE', true) in config/database.php makes MySQL REFUSE out-of-range / wrong-type values and
            // enum values it does not know, instead of silently storing 0000 / '' (the way a bad status once hid a news item).
            // Off by default because it can only be switched on safely after the admin forms were tried with it (see the QA notes).
            if (defined('DB_STRICT_MODE') && DB_STRICT_MODE) {
                try { $this->pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode, ''), 'STRICT_TRANS_TABLES')"); } catch (PDOException $e) { error_log('[Database] could not set strict mode: ' . $e->getMessage()); }
            }
        } catch (PDOException $e) {
            error_log('[Database] connection failed: ' . $e->getMessage());
            http_response_code(503);
            die('ระบบไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ กรุณาลองใหม่อีกครั้งในภายหลัง');
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }

    // Execute query and return PDOStatement
    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch single row
    public function fetchOne(string $sql, array $params = []): array|false {
        return $this->query($sql, $params)->fetch();
    }

    // Fetch all rows
    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    /** column => max characters, for the varchar/char columns of a table (cached per request). */
    private function textLimits(string $table): array {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = [];
            try {
                $st = $this->pdo->prepare("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE IN ('varchar', 'char')");
                $st->execute([$table]);
                foreach ($st->fetchAll(PDO::FETCH_NUM) as [$c, $n]) $cache[$table][$c] = (int)$n;
            } catch (Throwable $e) { /* no limits known - let the query decide */ }
        }
        return $cache[$table];
    }

    /**
     * Admin saves only: a value longer than its column used to be cut off silently
     * (the form said "saved"). Stop instead and tell the admin which field is too long.
     */
    private function assertFits(string $table, array $data): void {
        if (!str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')) return;
        $limits = $this->textLimits($table);
        foreach ($data as $col => $val) {
            if (!is_string($val) || !isset($limits[$col])) continue;
            $len = mb_strlen($val, 'UTF-8');
            if ($len <= $limits[$col]) continue;
            $msg = 'ข้อมูลในช่อง "' . $col . '" ยาวเกินกำหนด (ใส่มา ' . $len . ' ตัวอักษร ใส่ได้ไม่เกิน ' . $limits[$col] . ') กรุณาตัดให้สั้นลงแล้วบันทึกใหม่';
            if (function_exists('adminFail')) adminFail($msg, 400);
            http_response_code(400);
            die($msg);
        }
    }

    // Insert and return last insert ID
    public function insert(string $table, array $data): int {
        $this->assertFits($table, $data);
        $columns = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    // Update rows
    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $this->assertFits($table, $data);
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        $sql = "UPDATE `$table` SET $set WHERE $where";
        // Admin edits only: remember the rows as they were, so the cached English
        // of any text that this update replaces can be dropped afterwards.
        $before = $this->translatedRowsBefore($table, $where, $whereParams);
        $stmt = $this->query($sql, [...array_values($data), ...$whereParams]);
        if ($before) {
            try {
                $after = $this->fetchAll("SELECT * FROM `$table` WHERE $where LIMIT 500", $whereParams);
                // If the update made the rows stop matching $where there is nothing
                // to compare against — leave the cache alone rather than drop it all.
                if ($after) forgetTranslationsOfRows($before, $after);
            } catch (Throwable $e) {
                // ignore
            }
        }
        return $stmt->rowCount();
    }

    /** Rows about to be updated, but only when this is an admin request (public pages update view counters constantly). */
    private function translatedRowsBefore(string $table, string $where, array $params): array {
        if (!function_exists('forgetTranslationsOfRows')) return [];
        if (in_array($table, ['translation_cache', 'program_campuses', 'campus_contacts', 'student_counts', 'staff_counts'], true)) return [];
        if (!str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')) return [];
        try {
            return $this->fetchAll("SELECT * FROM `$table` WHERE $where LIMIT 500", $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    // Delete rows
    public function delete(string $table, string $where, array $params = []): int {
        // Drop the cached English translations of whatever is being deleted
        // (see forgetTranslationsOfRows() in helpers.php). Never blocks the delete.
        if (function_exists('forgetTranslationsOfRows')
            && !in_array($table, ['translation_cache', 'program_campuses', 'campus_contacts', 'student_counts', 'staff_counts'], true)) {
            try {
                forgetTranslationsOfRows($this->fetchAll("SELECT * FROM `$table` WHERE $where LIMIT 500", $params));
            } catch (Throwable $e) {
                // ignore
            }
        }
        try {
            $stmt = $this->query("DELETE FROM `$table` WHERE $where", $params);
        } catch (PDOException $e) {
            // MySQL 1451 = other rows still point at this one (RESTRICT foreign key). In the admin, say so in plain
            // Thai instead of letting it surface as a generic "system error" popup.
            if (($e->errorInfo[1] ?? 0) === 1451 && str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')) {
                $child = preg_match('/fails \(`[^`]+`\.`([^`]+)`/', $e->getMessage(), $m) ? $m[1] : '';
                $labels = [
                    'staff' => 'บุคลากร', 'staff_counts' => 'สถิติบุคลากร (เมนู "สถิติบุคลากร")', 'student_counts' => 'สถิตินักศึกษา (เมนู "สถิตินักศึกษา")',
                    'programs' => 'หลักสูตร', 'news' => 'ข่าวสาร', 'articles' => 'บทความ', 'forms' => 'แบบฟอร์มดาวน์โหลด',
                    'campuses' => 'ศูนย์พื้นที่', 'majors' => 'สาขาวิชา',
                ];
                $what = $labels[$child] ?? 'อื่น ๆ';
                $msg = 'ลบไม่ได้ เพราะยังมีข้อมูล' . $what . ' ที่ใช้รายการนี้อยู่ กรุณาลบหรือย้ายข้อมูลนั้นก่อน แล้วลองลบอีกครั้ง';
                if (function_exists('adminFail')) adminFail($msg, 400);
                http_response_code(400);
                die($msg);
            }
            throw $e;
        }
        return $stmt->rowCount();
    }

    // Count rows
    public function count(string $table, string $where = '1', array $params = []): int {
        $row = $this->fetchOne("SELECT COUNT(*) as cnt FROM `$table` WHERE $where", $params);
        return (int)($row['cnt'] ?? 0);
    }

    // Begin transaction
    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void           { $this->pdo->commit(); }
    public function rollback(): void         { $this->pdo->rollBack(); }

    // Prevent cloning
    private function __clone() {}
}

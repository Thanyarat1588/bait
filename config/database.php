<?php
/**
 * BAIT Website - Database Configuration
 * คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ
 */

// เครื่องหรือโฮสต์: ตัดสินจากไฟล์ config/database.host.php ซึ่งมีอยู่เฉพาะบนโฮสต์ (ไม่ดูจาก Host header ที่ผู้เข้าชมส่งมาเองได้)
// - มีไฟล์ = โฮสต์: ใช้ค่าในไฟล์ (host / name / user / pass / base ต้องมีครบ — ดู config/database.host.example.php)
//   ไม่มีค่าของโฮสต์ใดเขียนไว้ในไฟล์นี้ (โค้ดเผยแพร่ได้)
// - ไม่มีไฟล์ + เปิดจาก localhost / 127.0.0.1 = เครื่อง XAMPP
// - ไม่มีไฟล์บนโดเมนอื่น = ยังตั้งค่าไม่ครบ: แจ้งแทนการเดาค่า
$hostCfg = null;
if (!defined('DB_HOST')) {   // (a test harness may have defined everything already)
    if (is_file(__DIR__ . '/database.host.php')) {
        // Buffered: a file saved "UTF-8 with BOM" (or with a blank line before <?php) would otherwise print those bytes
        // before every page, and then sessions, redirects and cookies stop working ("headers already sent").
        ob_start();
        try { $hostCfg = require __DIR__ . '/database.host.php'; } finally { ob_end_clean(); }
        if (!is_array($hostCfg)) $hostCfg = [];
        foreach ($hostCfg as $k => $v) if (is_string($v)) $hostCfg[$k] = trim($v, "\xEF\xBB\xBF\r\n\t ");
    }
}
$reqHost  = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$is_local = $hostCfg === null && (($reqHost === '' && PHP_SAPI === 'cli') || in_array($reqHost, ['localhost', '127.0.0.1', '[::1]', '::1'], true));
$setupFail = function (string $why): void {
    error_log('BAIT: config/database.host.php - ' . $why);
    if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    exit('ตั้งค่าฐานข้อมูลบนเซิร์ฟเวอร์ยังไม่ครบ กรุณาติดต่อผู้ดูแลระบบ');
};

// ตรวจ scheme จริงที่ browser ใช้เรียกหน้านี้ (http หรือ https) แทนที่จะ
// hardcode "http://" ตายตัว — ถ้า BASE_URL ฝัง http:// ไว้ตายตัวแต่ browser
// (เช่น Safari ที่เปิดใช้ "Upgrade to HTTPS" หรือ auto-complete เป็น https
// ตอนวางลิงก์ในแถบที่อยู่) โหลดหน้าเว็บผ่าน https จริง ไฟล์ css/js ที่ลิงก์
// เป็น http:// จะถูกเบราว์เซอร์บล็อกเป็น mixed content ทำให้ CSS ไม่ขึ้น
// ทั้งที่ URL ไฟล์ถูกต้องทุกอย่าง — ตรงนี้แก้ให้ BASE_URL ใช้ scheme เดียวกับ
// ที่ browser ร้องขอมาเสมอ
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || ($_SERVER['SERVER_PORT'] ?? null) == 443
       || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ? 'https' : 'http';

if (defined('DB_HOST')) {
    // ----- ค่าที่ตั้งไว้ก่อนแล้ว (สภาพแวดล้อมทดสอบ) -----
    $hostPart = defined('BASE_URL') ? substr(BASE_URL, 2) : 'localhost/bait';
} elseif ($is_local) {
    // ----- ค่าสำหรับเครื่อง localhost (XAMPP) -----
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'bait_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    $hostPart = 'localhost/bait';
} else {
    // ----- ค่าสำหรับโฮสต์: ทั้งหมดมาจาก config/database.host.php (รหัสผ่านไม่เคยอยู่ในไฟล์นี้) -----
    if ($hostCfg === null) $setupFail('missing');
    $str =function (string $k) use ($hostCfg): string { return isset($hostCfg[$k]) && is_string($hostCfg[$k]) ? $hostCfg[$k] : ''; };
    if ($str('pass') === '') $setupFail('no "pass" value');
    foreach (['host', 'name', 'user', 'base'] as $k) if ($str($k) === '') $setupFail('no "' . $k . '" value');
    // base = โดเมน + โฟลเดอร์ของเว็บ เช่น www.example.ac.th/bait (ไม่มี http:// และไม่มี / ท้าย)
    $base = rtrim(preg_replace('#^(https?:)?//#i', '', $str('base')), '/');
    if (!preg_match('#^[a-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~-]+)*$#i', $base)) $setupFail('"base" is not like www.example.ac.th/bait');
    define('DB_HOST', $str('host'));
    define('DB_NAME', $str('name'));
    define('DB_USER', $str('user'));
    define('DB_PASS', $str('pass'));
    $hostPart = $base;
    if ($str('mymemory_email') !== '') define('MYMEMORY_EMAIL', $str('mymemory_email'));
}
unset($hostCfg, $setupFail, $reqHost, $str, $base);

// BASE_URL แบบ protocol-relative ("//host/bait" ไม่มี http:/https: นำหน้า) —
// ใช้กับลิงก์ css/js/img/nav ทั้งหมด เพราะ browser จะเติม scheme ให้ตรงกับ
// scheme ของหน้าเว็บที่กำลังเปิดอยู่โดยอัตโนมัติเสมอ ไม่ว่า PHP ฝั่ง server
// จะเดา $_SERVER['HTTPS'] ผิดหรือ proxy ของ ByetHost จะไม่ส่งค่านั้นมาก็ตาม
// (สาเหตุที่การ detect scheme ด้วย $_SERVER ครั้งก่อนแก้ไม่หาย เพราะ hosting
// บางเจ้า proxy/terminate SSL ที่ชั้นหน้าเว็บ แล้วส่งต่อมาที่ PHP เป็น http
// เสมอ ทำให้ PHP มองไม่เห็นว่าโดน request มาแบบ https จริง) วิธีนี้ตัดปัญหา
// mixed-content ได้เด็ดขาดเพราะไม่ต้องพึ่งการเดา scheme ฝั่ง server เลย
if (!defined('BASE_URL')) define('BASE_URL', '//' . $hostPart);

// BASE_URL_ABSOLUTE มี scheme เต็ม ใช้เฉพาะจุดที่ spec บังคับต้องเป็น absolute
// URL จริง ๆ เท่านั้น (canonical, og:url, hreflang, schema.org, sitemap.xml)
// จุดพวกนี้ไม่ใช่การโหลด sub-resource จึงไม่ถูกบล็อกแบบ mixed-content ต่อให้
// เดา scheme พลาดก็แค่ไม่ perfect สำหรับ SEO ไม่ทำให้หน้าเว็บพัง
if (!defined('BASE_URL_ABSOLUTE')) define('BASE_URL_ABSOLUTE', $scheme . '://' . $hostPart);

define('DB_CHARSET', 'utf8mb4');

define('BASE_PATH',   dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('UPLOAD_URL',  BASE_URL . '/uploads');
define('UPLOAD_URL_ABSOLUTE', BASE_URL_ABSOLUTE . '/uploads'); // สำหรับ og:image ที่ต้องเป็น absolute URL
define('CACHE_PATH',  BASE_PATH . '/cache/translations');

// Translation settings — MyMemory (api.mymemory.translated.net), free, no API key.
// Anonymous quota is 5,000 words/day; an email here (yours, not a user's —
// MyMemory just uses it to raise the quota, never contacts it) raises that
// to 50,000 words/day. A host can use another one: 'mymemory_email' in database.host.php.
if (!defined('MYMEMORY_EMAIL')) define('MYMEMORY_EMAIL', 'bowvv55555@gmail.com');
define('TRANSLATION_CACHE_TTL', 86400 * 3650); // 10 ปี (ไม่หมดอายุ)

// Session
define('SESSION_NAME', 'bait_session');
define('SESSION_LIFETIME', 3600 * 8); // 8 ชั่วโมง
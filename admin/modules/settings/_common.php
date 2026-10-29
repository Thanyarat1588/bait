<?php
/**
 * BAIT Admin - Site Settings: shared start-up for the four settings pages
 *   index.php (general + SEO) · brand.php (logo, favicon) · mourning.php · footer.php (text + background pictures)
 * Every page saves only its own fields, so saving one page can never touch what another page holds.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$hasMourning = (bool)$db->fetchOne("SHOW COLUMNS FROM site_settings LIKE 'mourning_options'");   // false until mourning_options.sql has been run
$hasFooterBg = (bool)$db->fetchOne("SHOW COLUMNS FROM site_settings LIKE 'footer_bg'");          // false until footer_bg.sql has been run
$hasAboutBg  = (bool)$db->fetchOne("SHOW COLUMNS FROM site_settings LIKE 'about_bg'");           // false until the migration (step 8) has been run

/** Writes the given columns into the one settings row (created if there is none yet). */
function saveSettingsRow(Database $db, array $data): void {
    $existing = $db->fetchOne("SELECT setting_id FROM site_settings LIMIT 1");
    if ($existing) $db->update('site_settings', $data, 'setting_id = ?', [$existing['setting_id']]);
    else $db->insert('site_settings', $data);
}

/** Flash message + back to the page that was saved. $failed = names of uploads that did not work. */
function settingsDone(array $failed, string $hint = 'รองรับ JPG, PNG, GIF, WEBP, SVG ขนาดไม่เกิน 5MB', string $notice = ''): never {
    if ($failed) $_SESSION['flash_error'] = 'บันทึกข้อมูลอื่นแล้ว แต่อัปโหลด' . implode(' และ ', $failed) . 'ไม่สำเร็จ (' . $hint . ')';
    elseif ($notice !== '') $_SESSION['flash_error'] = 'บันทึกแล้ว แต่ ' . $notice;
    else $_SESSION['flash_success'] = 'บันทึกการตั้งค่าเรียบร้อยแล้ว';
    header('Location: ' . basename($_SERVER['SCRIPT_NAME'])); exit;
}

$settings = $db->fetchOne("SELECT * FROM site_settings LIMIT 1") ?: [];

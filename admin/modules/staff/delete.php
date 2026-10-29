<?php
/**
 * Permanently deletes one staff member (the row and the photo file). Cannot be undone.
 * Database foreign keys clear any reference to this person (a subordinate's supervisor,
 * a campus head) automatically. POST + CSRF token only — a plain link can no longer delete.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
$auth = new Auth(); $auth->requireLogin();
$db   = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
verifyCsrf();

$id = (int)postStr('id');
$s  = $db->fetchOne("SELECT * FROM staff WHERE staff_id = ?", [$id]);
// people still reporting to this person would be cut loose from the ผังผู้บริหาร (each becoming a separate top or vanishing)
$reports = $s ? (int)($db->fetchOne('SELECT COUNT(*) AS c FROM staff s WHERE s.supervisor_id = ? AND ' . staffCurrentSql('s'), [$id])['c'] ?? 0) : 0;
// a committee seat would be removed with them without anyone noticing (the database deletes it along): ask to do it on purpose
$inCommittee = $s && hasCommittee() && $db->fetchOne('SELECT 1 FROM committee_members WHERE staff_id = ?', [$id]);
if (!$s) {
    $_SESSION['flash_error'] = 'ไม่พบบุคลากรที่ต้องการลบ';
} elseif ($reports) {
    $_SESSION['flash_error'] = "ลบไม่ได้ เพราะมีบุคลากร $reports คนขึ้นตรงกับคนนี้ในผังผู้บริหาร กรุณาย้ายพวกเขาไปหัวหน้าคนอื่นก่อน";
} elseif ($inCommittee) {
    $_SESSION['flash_error'] = 'ลบไม่ได้ เพราะคนนี้อยู่ในคณะกรรมการประจำคณะ กรุณาเอาออกจากคณะกรรมการก่อน (หรือเปลี่ยนสถานะเป็น "ลาออก" แทนการลบ)';
} else {
    try {
        $db->delete('staff', 'staff_id = ?', [$id]);
        if (!empty($s['image']) && file_exists(UPLOAD_PATH . '/' . $s['image'])) @unlink(UPLOAD_PATH . '/' . $s['image']);
        $_SESSION['flash_success'] = 'ลบบุคลากรถาวรเรียบร้อยแล้ว';
    } catch (\Throwable $e) {
        error_log('[staff/delete.php] ' . $e->getMessage());
        $_SESSION['flash_error'] = 'ลบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หากยังไม่สำเร็จ กรุณาแจ้งผู้ดูแลระบบ';
    }
}
header('Location: index.php'); exit;

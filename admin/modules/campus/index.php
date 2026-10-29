<?php
/**
 * BAIT Admin - Campus Management (list)
 * Add/edit lives on its own page — create.php — rather than a popup or
 * side-panel form, so the campus fields + dynamic contacts list have
 * room to breathe.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$campuses = $db->fetchAll(
    "SELECT c.*, COUNT(DISTINCT s.staff_id) as staff_count, COUNT(DISTINCT cc.contact_id) as contact_count
     FROM campuses c
     LEFT JOIN staff s ON s.campus_id = c.campus_id AND " . staffCurrentSql('s') . "
     LEFT JOIN campus_contacts cc ON cc.campus_id = c.campus_id
     GROUP BY c.campus_id ORDER BY c.campus_id"
);

$pageTitle  = 'จัดการศูนย์พื้นที่';
$activeMenu = 'campus';
$breadcrumb = [['label' => 'จัดการศูนย์พื้นที่']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.ne {
  --ne-ink: #1e2a45; --ne-ink-soft: #5b6480; --ne-paper: #f5f6fa; --ne-surface: #ffffff; --ne-line: #e3e6ee;
  --ne-gold: #FBC02D; --ne-blue: #162E93; --ne-blue-deep: #11236E; --ne-blue-soft: #ECEEF6; --ne-gold-soft: #f7f1e3;
  --ne-green: #3e8564; --ne-green-soft: #e5f2eb; --ne-slate: #8b93a7; --ne-slate-soft: #eef0f5;
  --ne-danger: #c1443d; --ne-danger-soft: #fbeae9;
  font-family: 'Kanit', sans-serif; color: var(--ne-ink);
}
.ne * { box-sizing: border-box; }
.ne-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; padding-bottom: 16px; border-bottom: 1px solid var(--ne-line); }
.ne-topbar h1 { font-family: 'Kanit', sans-serif; font-size: 26px; font-weight: 700; margin: 0; color: var(--ne-ink); display: flex; align-items: baseline; gap: 10px; }
.ne-topbar h1 .ne-count { font-family: 'Kanit', sans-serif; font-size: 14px; font-weight: 600; color: var(--ne-gold); background: var(--ne-gold-soft); border-radius: 999px; padding: 3px 11px; }
.ne-btn { font-family: 'Kanit', sans-serif; font-weight: 600; font-size: 14.5px; border-radius: 9px; padding: 10px 18px; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px; cursor: pointer; text-decoration: none; transition: all .15s ease; line-height: 1; }
.ne-btn-primary { background: var(--ne-blue) !important; color: #fff !important; }
.ne-btn-primary:hover { background: var(--ne-blue-deep) !important; color: #fff !important; }
.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; }
.ne-panel-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft); }
.ne-table { width: 100%; border-collapse: collapse; }
.ne-table thead th { text-align: left; font-size: 11.5px; letter-spacing: .05em; text-transform: uppercase; color: var(--ne-slate); font-weight: 600; padding: 12px 20px; background: var(--ne-paper); border-bottom: 1px solid var(--ne-line); white-space: nowrap; }
.ne-table tbody tr { border-bottom: 1px solid var(--ne-line); }
.ne-table tbody tr:last-child { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-table td { padding: 12px 20px; vertical-align: middle; font-size: 14px; }
.ne-num { color: var(--ne-slate); font-size: 13px; }
.ne-byline { font-size: 12px; color: var(--ne-slate); margin-top: 2px; }
.ne-badge-count { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; font-size: 12.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: var(--ne-slate-soft); color: var(--ne-ink-soft); }
.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line); background: var(--ne-surface); color: var(--ne-ink-soft); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px; }
.ne-icon-btn i { color: inherit !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-empty { text-align: center; padding: 56px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 32px; display: block; margin-bottom: 10px; color: var(--ne-line); }
.ne-thumb-empty { background: var(--ne-paper); color: var(--ne-slate); display: flex; align-items: center; justify-content: center; }
</style>

<div class="ne">
  <div class="ne-topbar">
    <h1>ศูนย์พื้นที่ทั้งหมด <span class="ne-count"><?= count($campuses) ?> ศูนย์</span></h1>
  </div>

  <div class="ne-panel">
    <div class="ne-panel-head"><span>ศูนย์พื้นที่ทั้งหมด</span></div>
    <?php if (!empty($campuses)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr><th style="width:44px">#</th><th style="width:70px">ภาพ</th><th>ชื่อศูนย์</th><th>ช่องทางติดต่อ</th><th style="width:110px">บุคลากร</th><th style="width:90px">จัดการ</th></tr>
        </thead>
        <tbody>
          <?php foreach ($campuses as $i => $c): ?>
          <tr>
            <td class="ne-num"><?= $i + 1 ?></td>
            <td>
              <?php if ($c['image']): ?>
              <img src="<?= UPLOAD_URL . '/' . e($c['image']) ?>" style="width:55px;height:40px;border-radius:6px;object-fit:cover" alt="">
              <?php else: ?>
              <div class="ne-thumb-empty" style="width:55px;height:40px;border-radius:6px;"><i class="bi bi-building"></i></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="fw-500"><?= e($c['campus_name']) ?></span>
              <?php if ($c['address']): ?><div class="ne-byline"><?= e(mb_strimwidth($c['address'], 0, 60, '...', 'UTF-8')) ?></div><?php endif; ?>
            </td>
            <td><span class="ne-badge-count"><?= $c['contact_count'] ?> ช่องทาง</span></td>
            <td><span class="ne-badge-count"><?= $c['staff_count'] ?> คน</span></td>
            <td>
              <div class="ne-actions">
                <a href="create.php?id=<?= $c['campus_id'] ?>" class="ne-icon-btn ne-icon-edit" title="แก้ไข"><i class="bi bi-pencil"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-geo-alt"></i>ยังไม่มีข้อมูล</div>
    <?php endif; ?>
  </div>
</div>

<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

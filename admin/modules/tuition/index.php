<?php
/**
 * BAIT Admin - หลักสูตร > ค่าเทอม: settings of the public tuition page (academic year, date of the figures, footnote) and
 * every programme with how many fee rows it has. Each programme's rows are edited in edit.php.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
require_once __DIR__ . '/_options.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$ready = hasTuition();

$setErrors = []; $setPosted = null;   // settings form sent back with what was typed when something is wrong
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $year = mb_substr(postStr('academic_year'), 0, 20);
    if ($year === '__new__') $setErrors[] = '"ปีการศึกษา" เลือก "เพิ่มตัวเลือกใหม่" แต่ยังไม่ได้พิมพ์และกด "เพิ่ม"';
    elseif ($year !== '' && !preg_match('/^\d{4}(\/\d{1,4})?$/', $year)) $setErrors[] = '"ปีการศึกษา" ต้องเป็นตัวเลข 4 หลัก เช่น 2570';
    $date = postStr('data_date');   // empty = not shown (the page uses the latest fee update); anything else must be a real date
    if ($date === '') $date = null;
    elseif (!(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1]) && $dm[1] >= 2000 && $dm[1] <= 2100))
        $setErrors[] = '"ข้อมูล ณ วันที่" ไม่ใช่วันที่ที่ถูกต้อง (' . $date . ') กรุณาเลือกจากปฏิทิน หรือเว้นว่าง';
    $note = str_replace("\r\n", "\n", postStr('note'));   // the browser counts a line break as 1 character
    if (mb_strlen($note) > 2000) $setErrors[] = '"หมายเหตุท้ายหน้า" ยาว ' . number_format(mb_strlen($note)) . ' ตัวอักษร เกิน 2,000 ตัวอักษร กรุณาตัดให้สั้นลง';
    if ($setErrors) {
        $setPosted = ['academic_year' => $year === '__new__' ? '' : $year, 'data_date' => postStr('data_date'), 'note' => $note];
    } else {
    $db->query('INSERT INTO tuition_page (setting_id, academic_year, data_date, note) VALUES (1, ?, ?, ?)
                ON DUPLICATE KEY UPDATE academic_year = VALUES(academic_year), data_date = VALUES(data_date), note = VALUES(note)', [$year, $date, $note]);
    tuitionAddOption('year', $year);
    $_SESSION['flash_success'] = 'บันทึกการตั้งค่าหน้าค่าเทอมเรียบร้อยแล้ว';
    header('Location: index.php'); exit;
    }
}

$levelF  = (int)getStr('level');
$statusF = getStr('status');   // '' | done | todo
$programs = $db->fetchAll(
    "SELECT p.program_id, p.program_name, p.is_active, el.level_id, el.level_name, COALESCE(m.major_name, '') AS major_name,
            GROUP_CONCAT(c.campus_name ORDER BY c.campus_id SEPARATOR ', ') AS campus_names
     FROM programs p JOIN education_levels el ON el.level_id = p.level_id LEFT JOIN majors m ON m.major_id = p.major_id
     LEFT JOIN program_campuses pc ON pc.program_id = p.program_id LEFT JOIN campuses c ON c.campus_id = pc.campus_id
     GROUP BY p.program_id ORDER BY el.level_order, m.major_name, p.program_name");
$fees   = tuitionRowsByProgram(array_column($programs, 'program_id'));
$levels = [];
foreach ($programs as $p) $levels[(int)$p['level_id']] = $p['level_name'];
$todo = 0;
foreach ($programs as $p) if ($p['is_active'] && empty($fees[(int)$p['program_id']])) $todo++;
$list = array_filter($programs, function ($p) use ($levelF, $statusF, $fees) {
    if ($levelF && (int)$p['level_id'] !== $levelF) return false;
    $has = !empty($fees[(int)$p['program_id']]);
    if ($statusF === 'done' && !$has) return false;
    if ($statusF === 'todo' && $has) return false;
    return true;
});
$settings = $setPosted ? array_merge(tuitionSettings(), $setPosted) : tuitionSettings();
$types = tuitionFeeTypes();

$pageTitle   = 'ค่าธรรมเนียมการศึกษา (ค่าเทอม)';
$activeMenu  = 'programs-tuition';
$breadcrumb  = [['label' => 'หลักสูตร', 'url' => BASE_URL . '/admin/modules/programs/index.php'], ['label' => 'ค่าเทอม']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>
<style>
.tu-a-badge { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
.tu-a-done { background: #E6F4EC; color: #1f6b47; }
.tu-a-todo { background: #FFF4E5; color: #9a4a00; }
.tu-a-off { background: #EEF0F5; color: #5b6480; }
.tu-a-sum { font-size: 12px; color: #5b6480; margin-top: 3px; white-space: normal; }
.tu-a-table td { white-space: normal; }
.tu-a-table td:nth-child(2) { min-width: 280px; }
.tu-a-table td:nth-child(4) { min-width: 240px; }
.tu-a-table td:nth-child(3), .tu-a-table td:nth-child(5), .tu-a-table td:nth-child(6) { white-space: nowrap; }   /* level / status / button: never broken inside a Thai word */
</style>
<div class="ne">
  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังใช้งานไม่ได้: ต้องรันไฟล์ <code>sql/bait_migrations_safe_to_rerun.sql</code> (ข้อ 11) ใน phpMyAdmin หนึ่งครั้งก่อน</p></div></div>
  <?php else: ?>

  <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:14px;">
    <a href="<?= BASE_URL ?>/tuition.php" target="_blank" rel="noopener" class="ne-btn ne-btn-ghost"><i class="bi bi-box-arrow-up-right"></i>ดูหน้าค่าเทอมบนเว็บไซต์</a>
  </div>

  <form method="POST" class="ne-panel" style="margin-bottom:18px;">
    <?= csrfField() ?>
    <div class="ne-panel-head"><span><i class="bi bi-gear"></i>ตั้งค่าหน้าค่าเทอม</span></div>
    <div class="ne-panel-body">
      <?php if ($setErrors): ?><div class="ne-alert" role="alert" style="margin-bottom:12px;"><strong><i class="bi bi-exclamation-circle me-1"></i>ยังไม่ได้บันทึก กรุณาแก้ไข</strong><?= implode('<br>', array_map('e', $setErrors)) ?></div><?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
        <div><label class="ne-label" for="tuYear" style="font-weight:600;">ปีการศึกษา (แสดงบนหน้าค่าเทอม)</label><?= tuOptionSelect('academic_year', 'year', (string)$settings['academic_year'], 'tuYear', '-- ไม่แสดงปีการศึกษา --') ?></div>
        <div><label class="ne-label" for="tuDate" style="font-weight:600;">ข้อมูล ณ วันที่</label><input id="tuDate" type="date" class="ne-input" name="data_date" value="<?= e((string)$settings['data_date']) ?>">
          <p class="ne-hint" style="margin:4px 0 0;">เว้นว่าง = ใช้วันที่แก้ไขค่าเทอมล่าสุด</p></div>
      </div>
      <label class="ne-label" for="tuNote" style="font-weight:600;margin-top:12px;display:block;">หมายเหตุท้ายหน้า</label>
      <textarea id="tuNote" class="ne-input" name="note" rows="3" maxlength="2000" placeholder="เว้นว่าง = ใช้ข้อความมาตรฐาน (อัตราอาจเปลี่ยนแปลงตามประกาศของมหาวิทยาลัย ...)"><?= e((string)$settings['note']) ?></textarea>
      <button type="submit" class="ne-btn ne-btn-primary" style="margin-top:12px;"><i class="bi bi-check-lg"></i>บันทึกการตั้งค่า</button>
    </div>
  </form>

  <form class="ne-filters" method="GET">
    <div class="ne-filters-field">
      <label for="fLevel">ระดับการศึกษา</label>
      <select id="fLevel" class="ne-select" name="level"><option value="">-- ทุกระดับ --</option>
        <?php foreach ($levels as $id => $name): ?><option value="<?= $id ?>"<?= $levelF === $id ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fStatus">สถานะ</label>
      <select id="fStatus" class="ne-select" name="status">
        <option value="">-- ทั้งหมด --</option>
        <option value="todo"<?= $statusF === 'todo' ? ' selected' : '' ?>>ยังไม่กรอกค่าเทอม (<?= $todo ?>)</option>
        <option value="done"<?= $statusF === 'done' ? ' selected' : '' ?>>กรอกแล้ว</option>
      </select>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>กรอง</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <div class="ne-panel">
    <div class="ne-panel-head"><span><i class="bi bi-cash-coin"></i>ค่าเทอมรายหลักสูตร</span><span><?= count($list) ?> หลักสูตร</span></div>
    <div class="table-responsive">
      <table class="ne-table tu-a-table">
        <thead><tr><th style="width:44px">#</th><th>หลักสูตร</th><th style="width:140px">ระดับ</th><th>ค่าเทอมที่กรอกไว้</th><th style="width:130px">สถานะ</th><th style="width:120px">จัดการ</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($list as $p): $rows = $fees[(int)$p['program_id']] ?? []; ?>
          <tr>
            <td class="ne-num"><?= ++$i ?></td>
            <td><a class="ne-title-link" href="edit.php?program_id=<?= (int)$p['program_id'] ?>"><?= e(preg_replace('/\s+/u', ' ', $p['program_name'])) ?></a>
              <div class="ne-byline"><?= e($p['campus_names'] ?: 'ทุกศูนย์พื้นที่') ?></div></td>
            <td><?= e($p['level_name']) ?></td>
            <td><?php if ($rows): foreach ($rows as $r): ?>
              <div class="tu-a-sum"><?= e($r['schedule'] !== '' ? $r['schedule'] : '-') ?><?= $r['campus_id'] !== null ? ' (' . e($r['campus_name']) . ')' : '' ?> ·
                <?= e(tuitionYears($r['duration_years'], 'th')) ?> · <?= $r['term1'] !== null ? number_format((int)$r['term1']) . ' บาท' . ($r['fee_type'] === 'credit' ? '/หน่วยกิต' : '/ภาค') : '-' ?></div>
            <?php endforeach; else: ?><span class="ne-hint">-</span><?php endif; ?></td>
            <td><?php if (!$p['is_active']): ?><span class="tu-a-badge tu-a-off">ปิดหลักสูตร</span><?php elseif ($rows): ?><span class="tu-a-badge tu-a-done">กรอกแล้ว <?= count($rows) ?> แถว</span><?php else: ?><span class="tu-a-badge tu-a-todo">ยังไม่กรอก</span><?php endif; ?></td>
            <td><a href="edit.php?program_id=<?= (int)$p['program_id'] ?>" class="ne-btn ne-btn-primary" style="padding:6px 12px;"><i class="bi bi-pencil"></i>แก้ไข</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="6" class="ne-hint" style="text-align:center;padding:24px;">ไม่พบหลักสูตร</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
</div>
<?= tuOptionScript() ?>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

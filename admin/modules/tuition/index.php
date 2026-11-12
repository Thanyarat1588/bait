<?php
/**
 * BAIT Admin - หลักสูตร > ค่าเทอม: fees per academic year (migration 21). Only the current year (the newest) and the
 * previous one are kept: adding a new year removes the oldest when there would be more than two (asked first).
 * For the year chosen in the tabs: its page settings (date of the figures, footnote, link to the admission rules) and every
 * programme with its fee rows; each programme's rows are edited in edit.php?year=.
 * Before migration 21 the page works as before (one set of fees, settings in tuition_page).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
require_once __DIR__ . '/_options.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$ready  = hasTuition();
$byYear = $ready && hasTuitionYears();

$yearRows = $byYear ? tuitionYearRows() : [];
$curYear  = $byYear ? tuitionCurrentYear() : '';
$year = getStr('year');
if (!isset($yearRows[$year])) $year = $curYear;

$setErrors = []; $setPosted = null;   // settings form sent back with what was typed when something is wrong
$addErrors = []; $addPosted = '';
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = postStr('action');

    $modes = hasTuitionModes();
    $toMoney = function ($v) { $v = is_string($v) ? str_replace([',', ' ', '฿'], '', trim($v)) : ''; return $v === '' ? null : (ctype_digit($v) && (int)$v <= 9999999 ? (int)$v : false); };
    if ($modes && $action === 'rates') {
        // ---- general rates of the admission rules for the chosen year: level x mode ----
        $year = postStr('year');
        if (!isset($yearRows[$year])) { $_SESSION['flash_error'] = 'ไม่พบปีการศึกษาที่เลือก'; header('Location: index.php'); exit; }
        $levelNames = array_column($db->fetchAll('SELECT level_id, level_name FROM education_levels'), 'level_name', 'level_id');
        $save = []; $rateErr = [];
        foreach ((array)($_POST['rates'] ?? []) as $lid => $byMode) {
            if (!isset($levelNames[(int)$lid]) || !is_array($byMode)) continue;
            foreach ($byMode as $m => $vals) {
                if (!isset(TUITION_MODES[(int)$m]) || !is_array($vals)) continue;
                $row = [];
                foreach (['entry_fee' => 'ค่าแรกเข้า', 'term_fee' => 'ค่าเทอม', 'summer_fee' => 'ภาคฤดูร้อน'] as $k => $label) {
                    $v = $toMoney($vals[$k] ?? '');
                    if ($v === false) { $rateErr[] = $levelNames[(int)$lid] . ' ' . TUITION_MODES[(int)$m] . ': "' . $label . '" ต้องเป็นตัวเลขจำนวนเต็มบาท'; $v = null; }
                    $row[$k] = $v;
                }
                if ($row['entry_fee'] === null && $row['term_fee'] === null && $row['summer_fee'] === null) continue;   // left blank = no rate
                if ($row['term_fee'] === null) $rateErr[] = $levelNames[(int)$lid] . ' ' . TUITION_MODES[(int)$m] . ': กรุณากรอก "ค่าเทอม/ภาคการศึกษาปกติ" (หรือเว้นว่างทั้งแถวถ้าไม่มีอัตรานี้)';
                $save[] = ['academic_year' => $year, 'level_id' => (int)$lid, 'study_mode' => (int)$m] + $row;
            }
        }
        if ($rateErr) { $_SESSION['flash_error'] = 'ยังไม่ได้บันทึกอัตรา: ' . implode(' · ', array_unique($rateErr)); header('Location: index.php?year=' . urlencode($year) . '#rates'); exit; }
        $db->beginTransaction();
        try { $db->query('DELETE FROM tuition_rates WHERE academic_year = ?', [$year]); foreach ($save as $r) $db->insert('tuition_rates', $r); $db->commit(); }
        catch (Throwable $e) { $db->rollback(); throw $e; }
        $_SESSION['flash_success'] = 'บันทึกอัตราตามระเบียบการ ปีการศึกษา ' . $year . ' แล้ว (' . count($save) . ' อัตรา)';
        header('Location: index.php?year=' . urlencode($year) . '#rates'); exit;
    }
    if ($modes && $action === 'offers') {
        // ---- which study modes the listed programmes are open for in the chosen year ----
        $year = postStr('year');
        if (!isset($yearRows[$year])) { $_SESSION['flash_error'] = 'ไม่พบปีการศึกษาที่เลือก'; header('Location: index.php'); exit; }
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $checked = (array)($_POST['offer'] ?? []);
        $db->beginTransaction();
        try {
            foreach ($ids as $pid) {
                $db->query('DELETE FROM tuition_offers WHERE academic_year = ? AND program_id = ?', [$year, $pid]);
                foreach ((array)($checked[$pid] ?? []) as $m) if (isset(TUITION_MODES[(int)$m])) $db->insert('tuition_offers', ['academic_year' => $year, 'program_id' => $pid, 'study_mode' => (int)$m]);
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollback(); throw $e; }
        $_SESSION['flash_success'] = 'บันทึกรูปแบบที่เปิดสอน ปีการศึกษา ' . $year . ' แล้ว (' . count($ids) . ' หลักสูตร)';
        $back = array_filter(['year' => $year, 'level' => postStr('level'), 'status' => postStr('status')], fn($v) => $v !== '');
        header('Location: index.php?' . http_build_query($back) . '#offers'); exit;
    }
    if ($byYear && $action === 'add_year') {
        // ---- a new academic year ----
        $new = trim(postStr('new_year')); $addPosted = $new;
        if (!preg_match('/^\d{4}$/', $new) || (int)$new < 2500 || (int)$new > 2700) $addErrors[] = '"ปีการศึกษา" ต้องเป็นปี พ.ศ. 4 หลัก เช่น 2570';
        elseif (isset($yearRows[$new])) $addErrors[] = 'มีปีการศึกษา ' . $new . ' อยู่แล้ว เลือกจากแท็บด้านบนได้เลย';
        elseif (count($yearRows) >= TUITION_KEEP_YEARS && (int)$new < (int)$curYear) $addErrors[] = 'ระบบเก็บค่าเทอมได้ ' . TUITION_KEEP_YEARS . ' ปี (ปีปัจจุบันและปีก่อนหน้า) ปีใหม่ต้องมากกว่าปีปัจจุบัน (' . $curYear . ')';
        if (!$addErrors) {
            $removed = tuitionAddYear($new, postStr('copy') === '1' && $curYear !== '');
            $_SESSION['flash_success'] = 'เพิ่มปีการศึกษา ' . $new . ' แล้ว' . (postStr('copy') === '1' && $curYear !== '' ? ' (คัดลอกค่าเทอมจากปี ' . $curYear . ')' : '')
                . ($removed ? ' · ลบข้อมูลปีการศึกษา ' . implode(', ', $removed) . ' ออกแล้ว (เก็บไว้เฉพาะปีปัจจุบันและปีก่อนหน้า)' : '');
            header('Location: index.php?year=' . urlencode($new)); exit;
        }
    } else {
        // ---- settings of the chosen year (or, before migration 21, of the whole page) ----
        if ($byYear) {
            $year = postStr('year');
            if (!isset($yearRows[$year])) { $_SESSION['flash_error'] = 'ไม่พบปีการศึกษาที่เลือก'; header('Location: index.php'); exit; }
        } else {
            $legacyYear = mb_substr(postStr('academic_year'), 0, 20);
            if ($legacyYear === '__new__') $setErrors[] = '"ปีการศึกษา" เลือก "เพิ่มตัวเลือกใหม่" แต่ยังไม่ได้พิมพ์และกด "เพิ่ม"';
            elseif ($legacyYear !== '' && !preg_match('/^\d{4}(\/\d{1,4})?$/', $legacyYear)) $setErrors[] = '"ปีการศึกษา" ต้องเป็นตัวเลข 4 หลัก เช่น 2570';
        }
        $date = postStr('data_date');   // empty = not shown (the page uses the latest fee update); anything else must be a real date
        if ($date === '') $date = null;
        elseif (!(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1]) && $dm[1] >= 2000 && $dm[1] <= 2100))
            $setErrors[] = '"ข้อมูล ณ วันที่" ไม่ใช่วันที่ที่ถูกต้อง (' . $date . ') กรุณาเลือกจากปฏิทิน หรือเว้นว่าง';
        $note = str_replace("\r\n", "\n", postStr('note'));   // the browser counts a line break as 1 character
        if (mb_strlen($note) > 2000) $setErrors[] = '"หมายเหตุท้ายหน้า" ยาว ' . number_format(mb_strlen($note)) . ' ตัวอักษร เกิน 2,000 ตัวอักษร กรุณาตัดให้สั้นลง';
        $rules = trim(postStr('admission_url'));
        if ($rules !== '' && (!isHttpUrl($rules) || mb_strlen($rules) > 500)) $setErrors[] = '"ลิงก์ระเบียบการคัดเลือก ปวช. – ป.ตรี" ต้องขึ้นต้นด้วย http:// หรือ https:// และยาวไม่เกิน 500 ตัวอักษร';
        $gradOk = $byYear && hasTuitionGradRules();
        $rulesGrad = $gradOk ? trim(postStr('admission_url_grad')) : '';
        if ($rulesGrad !== '' && (!isHttpUrl($rulesGrad) || mb_strlen($rulesGrad) > 500)) $setErrors[] = '"ลิงก์ระเบียบการคัดเลือก ป.โท – ป.เอก" ต้องขึ้นต้นด้วย http:// หรือ https:// และยาวไม่เกิน 500 ตัวอักษร';
        if ($setErrors) {
            $setPosted = ['data_date' => postStr('data_date'), 'note' => $note, 'admission_url' => $rules, 'admission_url_grad' => $rulesGrad] + ($byYear ? [] : ['academic_year' => $legacyYear === '__new__' ? '' : $legacyYear]);
        } elseif ($byYear) {
            if ($gradOk) $db->query('UPDATE tuition_years SET data_date = ?, note = ?, admission_url = ?, admission_url_grad = ? WHERE academic_year = ?', [$date, $note, $rules, $rulesGrad, $year]);
            else $db->query('UPDATE tuition_years SET data_date = ?, note = ?, admission_url = ? WHERE academic_year = ?', [$date, $note, $rules, $year]);
            $_SESSION['flash_success'] = 'บันทึกการตั้งค่าปีการศึกษา ' . $year . ' เรียบร้อยแล้ว';
            header('Location: index.php?year=' . urlencode($year)); exit;
        } else {
            $db->query('INSERT INTO tuition_page (setting_id, academic_year, data_date, note) VALUES (1, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE academic_year = VALUES(academic_year), data_date = VALUES(data_date), note = VALUES(note)', [$legacyYear, $date, $note]);
            tuitionAddOption('year', $legacyYear);
            $_SESSION['flash_success'] = 'บันทึกการตั้งค่าหน้าค่าเทอมเรียบร้อยแล้ว';
            header('Location: index.php'); exit;
        }
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
$modes  = hasTuitionModes();
// what the public page shows for each programme: its own rows, or the general rate of its level, per study mode
$fees   = ($byYear && $year === '') ? [] : ($modes ? tuitionShownRows($programs, $year) : tuitionRowsByProgram(array_column($programs, 'program_id'), $byYear ? $year : null));
$offers = $modes && $year !== '' ? tuitionOffers($year) : [];
$rates  = $modes && $year !== '' ? tuitionRates($year) : [];
$levels = [];
foreach ($programs as $p) $levels[(int)$p['level_id']] = $p['level_name'];
// complete = has rows to show and none of them is "no rate yet"
$complete = fn(int $pid) => !empty($fees[$pid]) && !array_filter($fees[$pid], fn($r) => ($r['source'] ?? 'own') === 'none');
$todo = 0;
foreach ($programs as $p) if ($p['is_active'] && !$complete((int)$p['program_id'])) $todo++;
$list = array_filter($programs, function ($p) use ($levelF, $statusF, $complete) {
    if ($levelF && (int)$p['level_id'] !== $levelF) return false;
    $has = $complete((int)$p['program_id']);
    if ($statusF === 'done' && !$has) return false;
    if ($statusF === 'todo' && $has) return false;
    return true;
});
$base = $byYear ? tuitionSettings($year) : tuitionSettings();
$settings = $setPosted ? array_merge($base, $setPosted) : $base;
$types = tuitionFeeTypes();
$yq = $byYear && $year !== '' ? '&year=' . urlencode($year) : '';
$nextYear = $curYear !== '' ? (string)((int)$curYear + 1) : (string)((int)date('Y') + 543);
$oldest = count($yearRows) >= TUITION_KEEP_YEARS ? (string)array_key_last($yearRows) : '';

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
/* academic-year tabs: mobile first, they wrap */
.tu-years { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.tu-year { display: inline-flex; flex-direction: column; gap: 1px; padding: 8px 14px; border-radius: 10px; border: 1.5px solid var(--ne-line); background: #fff; color: var(--ne-ink); text-decoration: none; line-height: 1.25; }
.tu-year b { font-size: 16px; }
.tu-year small { font-size: 12px; color: var(--ne-ink-soft); }
.tu-year.is-active { border-color: #162E93; background: #EEF2FF; }
.tu-year.is-active b { color: #162E93; }
.tu-add { margin-top: 14px; border-top: 1px dashed var(--ne-line); padding-top: 12px; }
.tu-add summary { cursor: pointer; font-weight: 600; color: #162E93; }
.tu-add-grid { display: grid; grid-template-columns: 1fr; gap: 10px; margin-top: 10px; }
@media (min-width: 640px) { .tu-add-grid { grid-template-columns: 200px 1fr; align-items: end; } }
.tu-check { display: flex; gap: 8px; align-items: flex-start; font-size: 14px; }
.tu-check input { margin-top: 4px; }
.tu-offer { display: flex; align-items: center; gap: 6px; font-size: 14px; white-space: nowrap; margin: 2px 0; }
.tu-rate-table td { white-space: nowrap; }
.tu-rate-table .ne-input { min-width: 110px; }
</style>
<div class="ne">
  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังใช้งานไม่ได้: ต้องรันไฟล์ <code>sql/bait_migrations_safe_to_rerun.sql</code> (ข้อ 11) ใน phpMyAdmin หนึ่งครั้งก่อน</p></div></div>
  <?php else: ?>

  <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:14px;">
    <?php if (hasTuitionBanners()): ?><a href="banners.php" class="ne-btn ne-btn-ghost"><i class="bi bi-images"></i>แบนเนอร์หน้าค่าเทอม</a><?php endif; ?>
    <a href="<?= BASE_URL ?>/tuition.php<?= $byYear && $year !== '' && $year !== $curYear ? '?ay=' . urlencode($year) : '' ?>" target="_blank" rel="noopener" class="ne-btn ne-btn-ghost"><i class="bi bi-box-arrow-up-right"></i>ดูหน้าค่าเทอมบนเว็บไซต์</a>
  </div>

  <?php if (!$byYear): ?>
  <div class="ne-panel" style="margin-bottom:14px;"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ค่าเทอมแยกตามปีการศึกษายังใช้ไม่ได้: ต้องรันไฟล์ <code>bait_migration_21_tuition_years.sql</code> ใน phpMyAdmin หนึ่งครั้งก่อน (ระหว่างนี้หน้านี้ทำงานแบบเดิม)</p></div></div>
  <?php else: ?>
  <div class="ne-panel" style="margin-bottom:18px;">
    <div class="ne-panel-head"><span><i class="bi bi-calendar3"></i>ปีการศึกษา</span><span class="ne-hint" style="margin:0;">เก็บไว้ <?= TUITION_KEEP_YEARS ?> ปี: ปีปัจจุบันและปีก่อนหน้า</span></div>
    <div class="ne-panel-body">
      <?php if ($yearRows): ?>
      <nav class="tu-years" aria-label="เลือกปีการศึกษา">
        <?php foreach (array_keys($yearRows) as $y): $y = (string)$y; ?>
        <a class="tu-year<?= $y === $year ? ' is-active' : '' ?>" href="?year=<?= urlencode($y) ?>"<?= $y === $year ? ' aria-current="page"' : '' ?>><b>ปีการศึกษา <?= e($y) ?></b><small><?= $y === $curYear ? 'ปีปัจจุบัน (แสดงก่อนบนเว็บไซต์)' : 'ปีก่อนหน้า' ?></small></a>
        <?php endforeach; ?>
      </nav>
      <?php else: ?>
      <p class="ne-hint" style="margin:0;">ยังไม่มีปีการศึกษา กรุณาเพิ่มปีการศึกษาแรกด้านล่าง</p>
      <?php endif; ?>

      <details class="tu-add"<?= ($addErrors || !$yearRows) ? ' open' : '' ?>>
        <summary><i class="bi bi-plus-lg"></i> เพิ่มปีการศึกษาใหม่</summary>
        <form method="POST" id="tuAddYear" data-oldest="<?= e($oldest) ?>">
          <?= csrfField() ?><input type="hidden" name="action" value="add_year">
          <?php if ($addErrors): ?><div class="ne-alert" role="alert" style="margin-top:10px;"><strong><i class="bi bi-exclamation-circle me-1"></i>ยังไม่ได้เพิ่ม</strong><?= implode('<br>', array_map('e', $addErrors)) ?></div><?php endif; ?>
          <div class="tu-add-grid">
            <div><label class="ne-label" for="tuNewYear" style="font-weight:600;">ปีการศึกษา (พ.ศ.)</label>
              <input id="tuNewYear" class="ne-input" name="new_year" inputmode="numeric" maxlength="4" placeholder="เช่น <?= e($nextYear) ?>" value="<?= e($addPosted !== '' ? $addPosted : $nextYear) ?>"></div>
            <div>
              <?php if ($curYear !== ''): ?>
              <label class="tu-check"><input type="checkbox" name="copy" value="1" checked> <span>คัดลอกค่าเทอมทุกหลักสูตรจากปีการศึกษา <?= e($curYear) ?> มาเป็นค่าเริ่มต้น (แก้ตัวเลขทีหลังได้)</span></label>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($oldest !== ''): ?><p class="ne-hint" style="margin:8px 0 0;color:#b45309;">เพิ่มปีใหม่แล้ว ค่าเทอมและการตั้งค่าของปีการศึกษา <?= e($oldest) ?> จะถูกลบออก เพราะเก็บไว้เฉพาะปีปัจจุบันและปีก่อนหน้า</p><?php endif; ?>
          <button type="submit" class="ne-btn ne-btn-primary" style="margin-top:10px;"><i class="bi bi-plus-lg"></i>เพิ่มปีการศึกษา</button>
        </form>
      </details>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$byYear || $year !== ''): ?>
  <form method="POST" class="ne-panel" style="margin-bottom:18px;">
    <?= csrfField() ?><input type="hidden" name="action" value="settings">
    <?php if ($byYear): ?><input type="hidden" name="year" value="<?= e($year) ?>"><?php endif; ?>
    <div class="ne-panel-head"><span><i class="bi bi-gear"></i><?= $byYear ? 'ตั้งค่าปีการศึกษา ' . e($year) : 'ตั้งค่าหน้าค่าเทอม' ?></span></div>
    <div class="ne-panel-body">
      <?php if ($setErrors): ?><div class="ne-alert" role="alert" style="margin-bottom:12px;"><strong><i class="bi bi-exclamation-circle me-1"></i>ยังไม่ได้บันทึก กรุณาแก้ไข</strong><?= implode('<br>', array_map('e', $setErrors)) ?></div><?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
        <?php if (!$byYear): ?>
        <div><label class="ne-label" for="tuYear" style="font-weight:600;">ปีการศึกษา (แสดงบนหน้าค่าเทอม)</label><?= tuOptionSelect('academic_year', 'year', (string)$settings['academic_year'], 'tuYear', '-- ไม่แสดงปีการศึกษา --') ?></div>
        <?php endif; ?>
        <div><label class="ne-label" for="tuDate" style="font-weight:600;">ข้อมูล ณ วันที่</label><input id="tuDate" type="date" class="ne-input" name="data_date" value="<?= e((string)$settings['data_date']) ?>">
          <p class="ne-hint" style="margin:4px 0 0;">เว้นว่าง = ใช้วันที่แก้ไขค่าเทอมล่าสุด</p></div>
      </div>
      <?php if ($byYear): ?>
      <?php $gradField = hasTuitionGradRules(); ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px;">
        <div><label class="ne-label" for="tuRules" style="font-weight:600;">ลิงก์ระเบียบการคัดเลือก <?= $gradField ? 'ปวช. – ป.ตรี' : '' ?> ปีการศึกษา <?= e($year) ?></label>
          <input id="tuRules" type="url" class="ne-input" name="admission_url" maxlength="500" placeholder="https://..." value="<?= e((string)$settings['admission_url']) ?>"></div>
        <?php if ($gradField): ?>
        <div><label class="ne-label" for="tuRulesGrad" style="font-weight:600;">ลิงก์ระเบียบการคัดเลือก ป.โท – ป.เอก ปีการศึกษา <?= e($year) ?></label>
          <input id="tuRulesGrad" type="url" class="ne-input" name="admission_url_grad" maxlength="500" placeholder="https://..." value="<?= e((string)$settings['admission_url_grad']) ?>"></div>
        <?php endif; ?>
      </div>
      <p class="ne-hint" style="margin:4px 0 0;">ใส่แล้วการ์ดปีการศึกษาบนหน้าค่าเทอมจะมีปุ่ม "ระเบียบการคัดเลือก" ของแต่ละระดับ (เปิดเว็บไซต์ภายนอกในแท็บใหม่ ใช้ลิงก์ Google Drive ได้) · เว้นว่าง = ไม่แสดงปุ่มนั้น<?= $gradField ? '' : ' · ช่องลิงก์ ป.โท – ป.เอก จะแสดงหลังรันไฟล์ <code>database/migration_tuition_rules_grad.sql</code>' ?></p>
      <?php endif; ?>
      <label class="ne-label" for="tuNote" style="font-weight:600;margin-top:12px;display:block;">หมายเหตุท้ายหน้า</label>
      <textarea id="tuNote" class="ne-input" name="note" rows="3" maxlength="2000" placeholder="เว้นว่าง = ใช้ข้อความมาตรฐาน (อัตราอาจเปลี่ยนแปลงตามประกาศของมหาวิทยาลัย ...)"><?= e((string)$settings['note']) ?></textarea>
      <button type="submit" class="ne-btn ne-btn-primary" style="margin-top:12px;"><i class="bi bi-check-lg"></i>บันทึกการตั้งค่า</button>
    </div>
  </form>

  <?php if ($modes): $levelList = $db->fetchAll('SELECT el.level_id, el.level_name FROM education_levels el WHERE EXISTS (SELECT 1 FROM programs p WHERE p.level_id = el.level_id) ORDER BY el.level_order'); ?>
  <form method="POST" class="ne-panel" id="rates" style="margin-bottom:18px;">
    <?= csrfField() ?><input type="hidden" name="action" value="rates"><input type="hidden" name="year" value="<?= e($year) ?>">
    <div class="ne-panel-head"><span><i class="bi bi-table"></i>อัตราตามระเบียบการ ปีการศึกษา <?= e($year) ?></span></div>
    <div class="ne-panel-body">
      <p class="ne-hint" style="margin:0 0 10px;">กรอกตามตารางค่าธรรมเนียมในระเบียบการคัดเลือก ครั้งเดียวต่อระดับ × รูปแบบ (เช่น ปริญญาตรี ภาคสมทบ = แถว "หลักสูตรอื่น ๆ") · หลักสูตรที่เปิดสอนรูปแบบนั้นจะใช้อัตรานี้ เว้นแต่หลักสูตรนั้นกรอกค่าเทอมเอง (ปุ่ม "แก้ไข" ในรายการด้านล่าง) · เว้นว่างทั้งแถว = ไม่มีอัตรานี้</p>
      <div class="table-responsive">
        <table class="ne-table tu-rate-table">
          <thead><tr><th>ระดับการศึกษา</th><th style="width:110px">รูปแบบ</th><th>ค่าแรกเข้า (ครั้งเดียว)</th><th>ค่าเทอม/ภาคการศึกษาปกติ</th><th>ภาคฤดูร้อน</th></tr></thead>
          <tbody>
          <?php foreach ($levelList as $lv): foreach (TUITION_MODES as $m => $mName): $r = $rates[(int)$lv['level_id']][$m] ?? []; $nm = 'rates[' . (int)$lv['level_id'] . '][' . $m . ']'; $fmt = fn($k) => isset($r[$k]) && $r[$k] !== null ? number_format((int)$r[$k]) : ''; ?>
            <tr>
              <td><?= e($lv['level_name']) ?></td>
              <td><span class="tu-a-badge <?= $m === 2 ? 'tu-a-todo' : 'tu-a-done' ?>"><?= e($mName) ?></span></td>
              <td><input class="ne-input" name="<?= $nm ?>[entry_fee]" inputmode="numeric" placeholder="-" value="<?= e($fmt('entry_fee')) ?>" aria-label="ค่าแรกเข้า <?= e($lv['level_name'] . ' ' . $mName) ?>"></td>
              <td><input class="ne-input" name="<?= $nm ?>[term_fee]" inputmode="numeric" placeholder="-" value="<?= e($fmt('term_fee')) ?>" aria-label="ค่าเทอม <?= e($lv['level_name'] . ' ' . $mName) ?>"></td>
              <td><input class="ne-input" name="<?= $nm ?>[summer_fee]" inputmode="numeric" placeholder="-" value="<?= e($fmt('summer_fee')) ?>" aria-label="ภาคฤดูร้อน <?= e($lv['level_name'] . ' ' . $mName) ?>"></td>
            </tr>
          <?php endforeach; endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="ne-btn ne-btn-primary" style="margin-top:12px;"><i class="bi bi-check-lg"></i>บันทึกอัตรา</button>
    </div>
  </form>
  <?php endif; ?>

  <form class="ne-filters" method="GET">
    <?php if ($byYear): ?><input type="hidden" name="year" value="<?= e($year) ?>"><?php endif; ?>
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
        <option value="todo"<?= $statusF === 'todo' ? ' selected' : '' ?>><?= $modes ? 'ยังไม่ครบ (ไม่ได้ติ๊กรูปแบบ / ไม่มีอัตรา)' : 'ยังไม่กรอกค่าเทอม' ?> (<?= $todo ?>)</option>
        <option value="done"<?= $statusF === 'done' ? ' selected' : '' ?>><?= $modes ? 'ครบแล้ว' : 'กรอกแล้ว' ?></option>
      </select>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>กรอง</button>
      <a href="?<?= ltrim($yq, '&') ?>" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <?php if ($modes): ?><form method="POST" id="offers"><?= csrfField() ?><input type="hidden" name="action" value="offers"><input type="hidden" name="year" value="<?= e($year) ?>">
    <input type="hidden" name="level" value="<?= $levelF ?: '' ?>"><input type="hidden" name="status" value="<?= e($statusF) ?>"><?php endif; ?>
  <div class="ne-panel">
    <div class="ne-panel-head"><span><i class="bi bi-cash-coin"></i>ค่าเทอมรายหลักสูตร<?= $byYear ? ' ปีการศึกษา ' . e($year) : '' ?></span><span><?= count($list) ?> หลักสูตร</span></div>
    <?php if ($modes): ?><div class="ne-panel-body" style="padding-bottom:0;"><p class="ne-hint" style="margin:0;">ติ๊กรูปแบบที่แต่ละหลักสูตรเปิดสอนในปีนี้ (ติ๊กได้ทั้ง 2 แบบ) แล้วกด "บันทึกรูปแบบที่เปิดสอน" · ค่าเทอมจะใช้อัตราตามระเบียบการของระดับนั้นให้เอง · หลักสูตรที่ประกาศระบุอัตราเฉพาะ ให้กด "แก้ไข" เพื่อกรอกอัตราของหลักสูตรนั้น</p></div><?php endif; ?>
    <div class="table-responsive">
      <table class="ne-table tu-a-table">
        <thead><tr><th style="width:44px">#</th><th>หลักสูตร</th><th style="width:140px">ระดับ</th><?php if ($modes): ?><th style="width:150px">เปิดสอน</th><?php endif; ?><th><?= $modes ? 'ค่าเทอมที่แสดงบนเว็บไซต์' : 'ค่าเทอมที่กรอกไว้' ?></th><th style="width:130px">สถานะ</th><th style="width:120px">จัดการ</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($list as $p): $pid = (int)$p['program_id']; $rows = $fees[$pid] ?? []; $editUrl = 'edit.php?program_id=' . $pid . $yq; ?>
          <tr>
            <td class="ne-num"><?= ++$i ?></td>
            <td><a class="ne-title-link" href="<?= e($editUrl) ?>"><?= e(preg_replace('/\s+/u', ' ', $p['program_name'])) ?></a>
              <div class="ne-byline"><?= e($p['campus_names'] ?: 'ทุกศูนย์พื้นที่') ?></div></td>
            <td><?= e($p['level_name']) ?></td>
            <?php if ($modes): ?><td><input type="hidden" name="ids[]" value="<?= $pid ?>">
              <?php foreach (TUITION_MODES as $m => $mName): ?><label class="tu-offer"><input type="checkbox" name="offer[<?= $pid ?>][]" value="<?= $m ?>"<?= in_array($m, $offers[$pid] ?? [], true) ? ' checked' : '' ?>> <?= e($mName) ?></label><?php endforeach; ?></td><?php endif; ?>
            <td><?php if ($rows): foreach ($rows as $r): $src = $r['source'] ?? 'own'; ?>
              <div class="tu-a-sum"><?php if (!empty($r['study_mode'])): ?><b><?= e(tuitionModeName((int)$r['study_mode'])) ?></b> · <?php endif; ?>
                <?php if ($src === 'none'): ?><span style="color:#b42318;">ยังไม่มีอัตรา (กรอกในตารางอัตราด้านบน หรือกด "แก้ไข")</span><?php else: ?>
                <?= $r['schedule'] !== '' ? e($r['schedule']) . ' · ' : '' ?><?= $r['campus_id'] !== null ? 'เฉพาะ' . e($r['campus_name']) . ' · ' : '' ?><?= !empty($r['entry_fee']) ? 'แรกเข้า ' . number_format((int)$r['entry_fee']) . ' · ' : '' ?><?= $r['term1'] !== null ? number_format((int)$r['term1']) . ' บาท' . ($r['fee_type'] === 'credit' ? '/หน่วยกิต' : '/ภาค') : '-' ?>
                <?php if ($modes): ?> <span class="ne-hint" style="margin:0;"><?= $src === 'rate' ? '(อัตราตามระเบียบการ)' : '(อัตราเฉพาะหลักสูตร)' ?></span><?php endif; ?><?php endif; ?></div>
            <?php endforeach; else: ?><span class="ne-hint"><?= $modes ? 'ยังไม่ได้ติ๊กรูปแบบที่เปิดสอน' : '-' ?></span><?php endif; ?></td>
            <td><?php if (!$p['is_active']): ?><span class="tu-a-badge tu-a-off">ปิดหลักสูตร</span><?php elseif ($complete($pid)): ?><span class="tu-a-badge tu-a-done"><?= $modes ? 'ครบแล้ว' : 'กรอกแล้ว ' . count($rows) . ' แถว' ?></span><?php else: ?><span class="tu-a-badge tu-a-todo"><?= $modes ? 'ยังไม่ครบ' : 'ยังไม่กรอก' ?></span><?php endif; ?></td>
            <td><a href="<?= e($editUrl) ?>" class="ne-btn ne-btn-primary" style="padding:6px 12px;"><i class="bi bi-pencil"></i>แก้ไข</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="<?= $modes ? 7 : 6 ?>" class="ne-hint" style="text-align:center;padding:24px;">ไม่พบหลักสูตร</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($modes): ?>
    <?php if ($list): ?><div style="position:sticky;bottom:0;padding:12px 0;background:var(--ne-bg, #f5f6fa);"><button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-check-lg"></i>บันทึกรูปแบบที่เปิดสอน</button></div><?php endif; ?>
  </form>
  <?php endif; ?>
  <?php endif; ?>

  <?php endif; ?>
</div>
<script>
// adding a year that pushes the oldest one out: say so before anything is removed
(function () {
  var f = document.getElementById('tuAddYear'); if (!f) return;
  f.addEventListener('submit', function (ev) {
    var old = f.dataset.oldest; if (!old) return;
    if (!confirm('เพิ่มปีการศึกษาใหม่แล้ว ค่าเทอมและการตั้งค่าของปีการศึกษา ' + old + ' จะถูกลบถาวร (เก็บไว้เฉพาะปีปัจจุบันและปีก่อนหน้า)\n\nต้องการเพิ่มปีใหม่ใช่หรือไม่?')) ev.preventDefault();
  });
})();
</script>
<?= tuOptionScript() ?>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

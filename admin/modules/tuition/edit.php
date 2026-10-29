<?php
/**
 * BAIT Admin - หลักสูตร > ค่าเทอม > แก้ไข: the fee rows of one programme. Each row = one study schedule (ภาคเรียนและวัน),
 * for every campus (campus empty) or for one campus only. Saved all at once: the old rows are replaced in one transaction.
 *
 * Nothing the admin types is ever dropped without a message:
 *  - a form with an error is shown again exactly as it was sent (same rows, same order, the typed text, even the wrong part);
 *  - "แถวที่ n" in a message is the row's position on the screen (blank rows count too);
 *  - a "+ เพิ่มตัวเลือกใหม่" left unconfirmed, a campus the programme is not taught at, a wrong number or length: all refused
 *    with the reason, never saved as something else.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
require_once __DIR__ . '/_options.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
if (!hasTuition()) { header('Location: index.php'); exit; }

$programId = (int)getStr('program_id');
$program = $db->fetchOne(
    "SELECT p.program_id, p.program_name, el.level_name, GROUP_CONCAT(c.campus_name ORDER BY c.campus_id SEPARATOR ', ') AS campus_names,
            GROUP_CONCAT(c.campus_id ORDER BY c.campus_id) AS campus_ids
     FROM programs p JOIN education_levels el ON el.level_id = p.level_id
     LEFT JOIN program_campuses pc ON pc.program_id = p.program_id LEFT JOIN campuses c ON c.campus_id = pc.campus_id
     WHERE p.program_id = ? GROUP BY p.program_id", [$programId]);
if (!$program) { $_SESSION['flash_error'] = 'ไม่พบหลักสูตรที่ต้องการแก้ไข'; header('Location: index.php'); exit; }

$campuses  = $db->fetchAll('SELECT campus_id, campus_name FROM campuses ORDER BY campus_id');
$campusName = array_column($campuses, 'campus_name', 'campus_id');
// the campuses a fee row may be limited to: where the programme is taught (none listed = taught at every campus)
$offered   = $program['campus_ids'] ? array_map('intval', explode(',', $program['campus_ids'])) : array_map('intval', array_keys($campusName));
$types  = tuitionFeeTypes();
$errors = [];
$rows   = tuitionRowsByProgram([$programId])[$programId] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $in = $_POST['rows'] ?? [];
    $clean = []; $shown = [];
    $str = fn($v, int $max) => is_string($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, $max) : '';
    $num = function ($v, string $label, int $no) use (&$errors) {   // "12,500" -> 12500; '' -> null
        $v = is_string($v) ? str_replace([',', ' ', '฿'], '', trim($v)) : '';
        if ($v === '') return null;
        if (!ctype_digit($v) || (int)$v > 9999999) { $errors[] = "แถวที่ $no: \"$label\" ต้องเป็นตัวเลขจำนวนเต็มบาท (ไม่เกิน 9,999,999)"; return null; }
        return (int)$v;
    };
    $pos = 0;
    foreach (is_array($in) ? $in : [] as $r) {
        if (!is_array($r)) continue;
        $pos++;   // the row's place on the screen, blank rows included, so "แถวที่ n" points at the right one
        $raw = [
            'campus_id' => $str($r['campus_id'] ?? '', 10), 'schedule' => $str($r['schedule'] ?? '', 120), 'duration_years' => $str($r['duration_years'] ?? '', 10),
            'fee_type' => $str($r['fee_type'] ?? 'lump', 10), 'term1' => $str($r['term1'] ?? '', 20), 'term2' => $str($r['term2'] ?? '', 20),
            'summer' => $str($r['summer'] ?? '', 20), 'total' => $str($r['total'] ?? '', 20), 'loan_fund' => $str($r['loan_fund'] ?? '', 60), 'note' => $str($r['note'] ?? '', 255),
        ];
        $shown[] = $raw;   // what goes back on the screen when something is wrong: exactly what was typed
        $filled = array_filter(array_diff_key($raw, ['campus_id' => 1, 'fee_type' => 1]), fn($v) => $v !== '');
        if (!$filled) continue;   // a row added and never filled: not saved (and nothing to report)
        $row = $raw;
        // "+ เพิ่มตัวเลือกใหม่" chosen but the new text was never confirmed (only possible without the page script)
        foreach (['schedule' => 'ภาคเรียนและวัน', 'duration_years' => 'ระยะเวลาเรียน'] as $k => $label) {
            if ($row[$k] === '__new__') { $errors[] = "แถวที่ $pos: \"$label\" เลือก \"เพิ่มตัวเลือกใหม่\" แต่ยังไม่ได้พิมพ์และกด \"เพิ่ม\""; $row[$k] = ''; }
        }
        if ($row['campus_id'] === '') $row['campus_id'] = null;
        elseif (!ctype_digit($row['campus_id']) || !isset($campusName[(int)$row['campus_id']])) { $errors[] = "แถวที่ $pos: ไม่พบศูนย์พื้นที่ที่เลือก กรุณาเลือกใหม่"; $row['campus_id'] = null; }
        elseif (!in_array((int)$row['campus_id'], $offered, true)) {
            $errors[] = "แถวที่ $pos: หลักสูตรนี้ไม่ได้เปิดสอนที่" . $campusName[(int)$row['campus_id']] . " (เปิดสอนที่: " . ($program['campus_names'] ?: 'ทุกศูนย์') . ") กรุณาเลือกศูนย์ที่เปิดสอน หรือ \"ทุกศูนย์\"";
            $row['campus_id'] = null;
        } else $row['campus_id'] = (int)$row['campus_id'];
        if (!isset($types[$row['fee_type']])) { $errors[] = "แถวที่ $pos: รูปแบบค่าเทอมไม่ถูกต้อง กรุณาเลือกใหม่"; $row['fee_type'] = 'lump'; }
        if ($row['duration_years'] !== '') {
            $y = str_replace(',', '.', $row['duration_years']);
            if (!preg_match('/^\d+(\.\d)?$/', $y) || (float)$y <= 0 || (float)$y > 10) { $errors[] = "แถวที่ $pos: \"ระยะเวลาเรียน\" ต้องเป็นตัวเลข 0.5 – 10 ปี (ทศนิยม 1 ตำแหน่ง)"; $y = null; }
            $row['duration_years'] = $y === null ? null : round((float)$y, 1);
        } else $row['duration_years'] = null;
        foreach (['term1' => 'ค่าเทอม ภาคเรียนที่ 1', 'term2' => 'ค่าเทอม ภาคเรียนที่ 2', 'summer' => 'ภาคฤดูร้อน', 'total' => 'ตลอดหลักสูตร'] as $k => $label) $row[$k] = $num($row[$k], $label, $pos);
        if ($row['term1'] === null && $raw['term1'] === '') $errors[] = "แถวที่ $pos: กรุณากรอก \"ค่าเทอม ภาคเรียนที่ 1\"";
        $clean[] = $row;
    }
    if (!$errors) {
        $db->beginTransaction();
        try {
            $db->query('DELETE FROM tuition_fees WHERE program_id = ?', [$programId]);
            foreach ($clean as $i => $row) $db->insert('tuition_fees', ['program_id' => $programId, 'sort_order' => $i] + $row);
            $db->commit();
        } catch (Throwable $e) { $db->rollback(); throw $e; }
        foreach ($clean as $row) {   // a choice typed with "+ เพิ่มตัวเลือกใหม่" becomes a saved choice
            tuitionAddOption('schedule', $row['schedule']);
            if ($row['duration_years'] !== null) tuitionAddOption('duration', tuitionYearsKey($row['duration_years']));
        }
        $name = preg_replace('/\s+/u', ' ', $program['program_name']);
        $_SESSION['flash_success'] = $clean ? 'บันทึกค่าเทอม "' . $name . '" แล้ว (' . count($clean) . ' แถว)' : 'ลบค่าเทอมทั้งหมดของ "' . $name . '" แล้ว (หน้าเว็บจะแสดงว่า "ยังไม่มีข้อมูลค่าเทอม")';
        header('Location: index.php'); exit;
    }
    $rows = $shown;   // show the form again exactly as it was sent
}

$pageTitle   = 'แก้ไขค่าเทอม';
$activeMenu  = 'programs-tuition';
$breadcrumb  = [['label' => 'หลักสูตร', 'url' => BASE_URL . '/admin/modules/programs/index.php'], ['label' => 'ค่าเทอม', 'url' => BASE_URL . '/admin/modules/tuition/index.php'], ['label' => 'แก้ไข']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';

$val = fn(array $r, string $k) => e((string)($r[$k] ?? ''));
// a saved amount gets its thousands separators; text sent back after an error is shown exactly as typed
$money = function (array $r, string $k) {
    $v = $r[$k] ?? '';
    if ($v === null || $v === '') return '';
    return (is_int($v) || ctype_digit((string)$v)) ? number_format((int)$v) : e((string)$v);
};
$rowHtml = function ($idx, array $r) use ($campuses, $offered, $types, $val, $money) {
    $cur = (string)($r['campus_id'] ?? '');
    ob_start(); ?>
    <fieldset class="tu-row" data-row>
      <legend><span data-row-no>แถว</span></legend>
      <div class="tu-row-grid">
        <div class="tu-wide"><label>ภาคเรียนและวัน</label><?= tuOptionSelect('rows[' . $idx . '][schedule]', 'schedule', (string)($r['schedule'] ?? '')) ?></div>
        <div><label>ใช้กับศูนย์พื้นที่</label><select class="ne-select" name="rows[<?= $idx ?>][campus_id]">
          <option value="">ทุกศูนย์ (เหมือนกันทุกศูนย์)</option>
          <?php foreach ($campuses as $c): $cid = (int)$c['campus_id']; $taught = in_array($cid, $offered, true); if (!$taught && $cur !== (string)$cid) continue; ?>
          <option value="<?= $cid ?>"<?= $cur === (string)$cid ? ' selected' : '' ?>>เฉพาะ<?= e($c['campus_name']) ?><?= $taught ? '' : ' (หลักสูตรนี้ไม่ได้เปิดสอนที่นี่)' ?></option>
          <?php endforeach; ?>
        </select></div>
        <div><label>ระยะเวลาเรียน (ปี)</label><?= tuOptionSelect('rows[' . $idx . '][duration_years]', 'duration', is_numeric($r['duration_years'] ?? null) ? tuitionYearsKey($r['duration_years']) : (string)($r['duration_years'] ?? '')) ?></div>
        <div><label>รูปแบบค่าเทอม</label><select class="ne-select" name="rows[<?= $idx ?>][fee_type]">
          <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>"<?= ($r['fee_type'] ?? 'lump') === $k ? ' selected' : '' ?>><?= $k === 'lump' ? 'เหมาจ่ายต่อภาคเรียน' : 'ต่อหน่วยกิต' ?></option><?php endforeach; ?>
        </select></div>
        <div><label>ค่าเทอม ภาคเรียนที่ 1 (บาท) <span class="tu-req">*</span></label><input class="ne-input" name="rows[<?= $idx ?>][term1]" inputmode="numeric" placeholder="เช่น 11,500" value="<?= $money($r, 'term1') ?>"></div>
        <div><label>ค่าเทอม ภาคเรียนที่ 2 (บาท)</label><input class="ne-input" name="rows[<?= $idx ?>][term2]" inputmode="numeric" placeholder="เว้นว่าง = เท่าภาคเรียนที่ 1" value="<?= $money($r, 'term2') ?>"></div>
        <div><label>ภาคฤดูร้อน (บาท)</label><input class="ne-input" name="rows[<?= $idx ?>][summer]" inputmode="numeric" placeholder="ไม่มี = เว้นว่าง" value="<?= $money($r, 'summer') ?>"></div>
        <div><label>ตลอดหลักสูตร (บาท)</label><input class="ne-input" name="rows[<?= $idx ?>][total]" inputmode="numeric" placeholder="เว้นว่าง = คำนวณให้" value="<?= $money($r, 'total') ?>"></div>
        <div><label>กองทุนกู้ยืม</label><input class="ne-input" name="rows[<?= $idx ?>][loan_fund]" list="tuFunds" maxlength="60" placeholder="เช่น กยศ. / กรอ." value="<?= $val($r, 'loan_fund') ?>"></div>
        <div class="tu-wide"><label>หมายเหตุ (แสดงใต้ภาคเรียนและวัน)</label><input class="ne-input" name="rows[<?= $idx ?>][note]" maxlength="255" placeholder="เช่น รวมค่าแรกเข้าแล้ว" value="<?= $val($r, 'note') ?>"></div>
      </div>
      <div class="tu-row-actions">
        <button type="button" class="ne-btn ne-btn-ghost" data-copy><i class="bi bi-files"></i>คัดลอกแถวนี้</button>
        <button type="button" class="ne-btn tu-del" data-remove><i class="bi bi-trash3"></i>ลบแถวนี้</button>
      </div>
    </fieldset>
    <?php return ob_get_clean();
};
?>
<style>
.tu-row { border: 1.5px solid #e3e6ee; border-radius: 12px; padding: 14px; margin: 0 0 14px; background: #fff; }
.tu-row legend { float: none; width: auto; font-size: 14px; font-weight: 700; color: #162E93; padding: 0 6px; margin: 0; }
.tu-row-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
@media (min-width: 640px) { .tu-row-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1100px) { .tu-row-grid { grid-template-columns: repeat(4, 1fr); } .tu-wide { grid-column: span 2; } }
.tu-row-grid label { display: block; font-size: 13px; font-weight: 600; color: #1e2a45; margin-bottom: 4px; }
.tu-req { color: #b42318; }
.tu-row-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: 10px; }
.tu-del { background: #fff; color: #B42318; border: 1.5px solid #B42318; }
.tu-del:hover { background: #B42318; color: #fff; }
.tu-empty { border: 2px dashed #d5dcea; border-radius: 12px; padding: 22px; text-align: center; color: #5b6480; margin-bottom: 14px; }
</style>
<div class="ne">
  <?php if ($errors): ?><div class="ne-alert" role="alert"><strong><i class="bi bi-exclamation-circle me-1"></i>ยังไม่ได้บันทึก กรุณาแก้ไขข้อมูลต่อไปนี้</strong><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

  <div class="ne-panel" style="margin-bottom:16px;">
    <div class="ne-panel-body">
      <div style="font-size:18px;font-weight:700;color:#162E93;"><?= e(preg_replace('/\s+/u', ' ', $program['program_name'])) ?></div>
      <div class="ne-hint" style="margin:4px 0 0;"><?= e($program['level_name']) ?> · เปิดสอนที่: <?= e($program['campus_names'] ?: 'ทุกศูนย์พื้นที่') ?></div>
      <p class="ne-hint" style="margin:10px 0 0;">1 แถว = 1 รูปแบบการเรียน (เช่น ภาคปกติ จันทร์ - ศุกร์ / ภาคพิเศษ เสาร์ - อาทิตย์) · ถ้าค่าเทอม <b>เหมือนกันทุกศูนย์</b> ให้เลือก "ทุกศูนย์" ·
        ถ้า <b>แต่ละศูนย์ไม่เท่ากัน</b> ให้กด "คัดลอกแถวนี้" แล้วเลือกศูนย์และแก้ตัวเลขของแต่ละศูนย์ (เลือกได้เฉพาะศูนย์ที่หลักสูตรนี้เปิดสอน) · แถวที่ว่างทั้งแถวจะไม่ถูกบันทึก</p>
    </div>
  </div>

  <form method="POST" id="tuForm">
    <?= csrfField() ?>
    <div id="tuRows">
      <?php foreach ($rows as $i => $r) echo $rowHtml($i, $r); ?>
    </div>
    <div class="tu-empty" id="tuEmpty"<?= $rows ? ' hidden' : '' ?>>ยังไม่มีค่าเทอมของหลักสูตรนี้ · หน้าเว็บจะแสดงว่า "ยังไม่มีข้อมูลค่าเทอม สอบถามคณะ" · กด "เพิ่มแถว" เพื่อเริ่มกรอก</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <button type="button" class="ne-btn ne-btn-ghost" id="tuAdd"><i class="bi bi-plus-lg"></i>เพิ่มแถว</button>
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-check-lg"></i>บันทึกค่าเทอม</button>
      <a href="index.php" class="ne-btn ne-btn-ghost">ยกเลิก</a>
    </div>
  </form>
  <datalist id="tuFunds"><option value="กยศ."><option value="กยศ. / กรอ."><option value="ไม่มี"></datalist>
  <template id="tuTpl"><?= $rowHtml('__I__', ['fee_type' => 'lump']) ?></template>
</div>
<script>
(function () {
  var box = document.getElementById('tuRows'), tpl = document.getElementById('tuTpl'), empty = document.getElementById('tuEmpty');
  var next = <?= count($rows) ?> + 1000;   // names of new rows never clash with the ones already on the page
  function renumber() {
    var rows = box.querySelectorAll('[data-row]');
    rows.forEach(function (r, i) { r.querySelector('[data-row-no]').textContent = 'แถวที่ ' + (i + 1); });
    empty.hidden = rows.length > 0;
  }
  function add(copyFrom) {
    var html = tpl.innerHTML.replace(/__I__/g, String(next++));
    var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
    var row = wrap.firstElementChild;
    row.querySelectorAll('select[data-opt-kind]').forEach(function (s) {   // choices added on this page so far
      var other = box.querySelector('select[data-opt-kind="' + s.dataset.optKind + '"]'); if (!other) return;
      [].forEach.call(other.options, function (o) {
        if (![].some.call(s.options, function (x) { return x.value === o.value; })) {
          var c = document.createElement('option'); c.value = o.value; c.textContent = o.textContent; s.insertBefore(c, s.querySelector('option[value="__new__"]'));
        }
      });
    });
    if (copyFrom) {
      var src = copyFrom.querySelectorAll('input, select'), dst = row.querySelectorAll('input, select');
      src.forEach(function (el, i) {
        if (!dst[i]) return;
        if (el.tagName === 'SELECT' && ![].some.call(dst[i].options, function (o) { return o.value === el.value; })) {   // e.g. a campus shown only on that row
          var o = el.options[el.selectedIndex]; if (o) dst[i].appendChild(o.cloneNode(true));
        }
        dst[i].value = el.value === '__new__' ? '' : el.value;
      });
      copyFrom.after(row);
    } else box.appendChild(row);
    renumber();
    var first = row.querySelector('select, input'); if (first) first.focus();
  }
  document.getElementById('tuAdd').addEventListener('click', function () { add(null); });
  box.addEventListener('click', function (ev) {
    var b = ev.target.closest('button'); if (!b) return;
    var row = b.closest('[data-row]');
    if (b.hasAttribute('data-remove')) { row.remove(); renumber(); }
    if (b.hasAttribute('data-copy')) add(row);
  });
  // saving with no row left removes every fee of this programme: ask first
  document.getElementById('tuForm').addEventListener('submit', function (ev) {
    var filled = [].some.call(box.querySelectorAll('[data-row]'), function (r) {
      return [].some.call(r.querySelectorAll('input:not([type=hidden]), select[data-opt-kind]'), function (el) { return el.value.trim() !== '' && el.value !== '__new__'; });
    });
    if (!filled && <?= tuitionRowsByProgram([$programId]) ? 'true' : 'false' ?> && !confirm('ไม่มีแถวค่าเทอมเหลืออยู่ — บันทึกแล้วจะลบค่าเทอมทั้งหมดของหลักสูตรนี้ และหน้าเว็บจะแสดงว่า "ยังไม่มีข้อมูลค่าเทอม"\n\nต้องการลบทั้งหมดใช่หรือไม่?')) ev.preventDefault();
  });
  renumber();
  if (!box.children.length) add(null);
})();
</script>
<?= tuOptionScript() ?>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>

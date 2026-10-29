<?php
/**
 * BAIT Admin - ค่าเทอม: a dropdown of the saved choices (tuition_options) with "+ เพิ่มตัวเลือกใหม่…" at the end.
 * Picking it shows a small box to type the new choice; it is added to every dropdown of the same kind on the page and
 * saved for good when the form is saved (the page that receives the form calls tuitionAddOption()). There is no separate
 * page for the choices: they are only added from the forms. Saving the form with a typed but unconfirmed choice applies it
 * (or stops the save and says why), so nothing typed is ever dropped; the receiving page also refuses a bare "__new__".
 * Before migration step 12 there are no saved choices: the control is then a plain text box.
 */
const TU_OPT_LABELS = ['year' => 'ปีการศึกษา', 'schedule' => 'ภาคเรียนและวัน', 'duration' => 'ระยะเวลาเรียน (ปี)'];

/** One dropdown. $current is always offered even when it is not (or no longer) in the saved choices. */
function tuOptionSelect(string $name, string $kind, string $current, string $id = '', string $placeholder = '-- เลือก --'): string {
    $idAttr = $id !== '' ? ' id="' . e($id) . '"' : '';
    if (!hasTuitionOptions()) {
        return '<input class="ne-input" name="' . e($name) . '"' . $idAttr . ' value="' . e($current) . '" maxlength="120">';
    }
    $opts = tuitionOptions($kind);
    if ($current !== '' && !in_array($current, $opts, true)) $opts[] = $current;
    $html = '<select class="ne-select" name="' . e($name) . '"' . $idAttr . ' data-opt-kind="' . e($kind) . '"><option value="">' . e($placeholder) . '</option>';
    foreach ($opts as $o) $html .= '<option value="' . e($o) . '"' . ($o === $current ? ' selected' : '') . '>' . e($kind === 'duration' ? $o . ' ปี' : $o) . '</option>';
    $html .= '<option value="__new__">＋ เพิ่มตัวเลือกใหม่…</option></select>';
    return $html;
}

/** The script behind the dropdowns (print once per page). */
function tuOptionScript(): string {
    if (!hasTuitionOptions()) return '';
    ob_start(); ?>
<style>
.tu-opt-new { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.tu-opt-new input { flex: 1 1 160px; min-width: 0; }
.tu-opt-new .ne-btn { width: auto !important; flex: 0 0 auto; }
.tu-opt-err { flex-basis: 100%; font-size: 12px; color: #b42318; }
</style>
<script>
(function () {
  var hints = { year: 'เช่น 2572', schedule: 'เช่น ภาคพิเศษ เสาร์ - อาทิตย์', duration: 'เช่น 4 หรือ 4.5' };
  function check(kind, v) {
    if (v === '') return 'กรุณาพิมพ์ตัวเลือกใหม่';
    if (kind === 'year' && !/^\d{4}(\/\d{1,4})?$/.test(v)) return 'ปีการศึกษาต้องเป็นตัวเลข 4 หลัก เช่น 2572';
    if (kind === 'duration' && !(/^\d+(\.\d)?$/.test(v) && +v > 0 && +v <= 10)) return 'ระยะเวลาต้องเป็นตัวเลข 0.5 – 10 (ทศนิยม 1 ตำแหน่ง)';
    if (v.length > 120) return 'ยาวเกิน 120 ตัวอักษร';
    return '';
  }
  function label(kind, v) { return kind === 'duration' ? v + ' ปี' : v; }
  document.addEventListener('focusin', function (ev) {   // remember the choice before "+ เพิ่ม…" was picked
    var s = ev.target; if (s.matches && s.matches('select[data-opt-kind]')) s.dataset.prev = s.value === '__new__' ? '' : s.value;
  });
  document.addEventListener('change', function (ev) {
    var sel = ev.target;
    if (!sel.matches || !sel.matches('select[data-opt-kind]') || sel.value !== '__new__') return;
    var kind = sel.dataset.optKind, box = sel.parentNode.querySelector('.tu-opt-new');
    if (!box) {
      box = document.createElement('div'); box.className = 'tu-opt-new';
      box.innerHTML = '<input type="text" class="ne-input" maxlength="120"><button type="button" class="ne-btn ne-btn-primary" data-ok>เพิ่ม</button>'
        + '<button type="button" class="ne-btn ne-btn-ghost" data-cancel>ยกเลิก</button><span class="tu-opt-err" role="alert"></span>';
      sel.after(box);
      var input = box.querySelector('input'), err = box.querySelector('.tu-opt-err');
      input.placeholder = hints[kind] || '';
      var done = function (v) { box.remove(); if (v === null) sel.value = sel.dataset.prev || ''; };
      box.querySelector('[data-cancel]').addEventListener('click', function () { done(null); sel.focus(); });
      var add = function (fromSubmit) {   // true = applied; false = the typed text is not valid (the reason is shown)
        var v = input.value.trim().replace(/\s+/g, ' ');
        if (kind === 'duration') v = v.replace(',', '.').replace(/\.0$/, '');
        var msg = check(kind, v); err.textContent = msg; if (msg) { input.focus(); return false; }
        document.querySelectorAll('select[data-opt-kind="' + kind + '"]').forEach(function (s) {
          if (![].some.call(s.options, function (o) { return o.value === v; })) {
            var o = document.createElement('option'); o.value = v; o.textContent = label(kind, v);
            s.insertBefore(o, s.querySelector('option[value="__new__"]'));
          }
        });
        sel.value = v; done(v); if (!fromSubmit) sel.focus();
        return true;
      };
      box._apply = add; box._cancel = function () { done(null); };
      box.querySelector('[data-ok]').addEventListener('click', function () { add(false); });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(false); } if (e.key === 'Escape') { done(null); sel.focus(); } });
    }
    box.querySelector('input').focus();
  });
  // saving the form: apply every box that still holds typed text; stop the save if one of them is not valid
  document.addEventListener('submit', function (ev) {
    var form = ev.target, firstBad = null;
    form.querySelectorAll('select[data-opt-kind]').forEach(function (sel) {
      if (sel.value !== '__new__') return;
      var box = sel.parentNode.querySelector('.tu-opt-new');
      if (!box || !box._apply) { sel.value = sel.dataset.prev || ''; return; }
      if (box.querySelector('input').value.trim() === '') { box._cancel(); return; }
      if (!box._apply(true) && !firstBad) firstBad = box;
    });
    if (firstBad) { ev.preventDefault(); ev.stopImmediatePropagation(); firstBad.querySelector('input').focus(); }
  }, true);
})();
</script>
<?php return ob_get_clean();
}

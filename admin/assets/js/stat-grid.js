/**
 * Admin statistics grid (student-counts / staff-counts).
 * Each row: [now] [+/- change] [new total]. Typing a change fills the new total and the other way round.
 * One "save all" sends only the rows that differ; each row can be undone on its own; totals update live.
 *
 * Markup contract (see the two pages): [data-statgrid] root with data-csrf; rows tr[data-row][data-id][data-old];
 * inside a row: input[data-delta], input[data-new], button[data-undo], button[data-del]; group header rows
 * tr[data-group] with [data-group-total] / [data-group-old]; a save bar [data-savebar] with [data-save],
 * [data-discard], [data-summary]; grand total in [data-grand].
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-statgrid]');
  if (!root) return;
  var csrf = root.dataset.csrf;
  var fmt = function (n) { return Number(n).toLocaleString('th-TH'); };
  var signed = function (n) { return (n > 0 ? '+' : n < 0 ? '−' : '') + fmt(Math.abs(n)); };
  var rows = Array.prototype.slice.call(root.querySelectorAll('tr[data-row]'));
  var bar = root.querySelector('[data-savebar]');
  var saving = false;

  // ---- paging: all rows stay in the page (so one "save all" still sees every edit); only a slice is shown at a time ----
  var per = parseInt(root.dataset.per, 10) || 20, page = 1;
  var pager = root.querySelector('[data-pager]');
  var groupEls = Array.prototype.slice.call(root.querySelectorAll('tr[data-group-row]'));
  function pageCount() { return Math.max(1, Math.ceil(rows.length / per)); }
  function pageList(cur, total) {      // 1 … 4 5 [6] 7 8 … 20
    var out = [], add = function (p) { if (out.indexOf(p) === -1) out.push(p); };
    add(1); for (var p = cur - 1; p <= cur + 1; p++) if (p > 1 && p < total) add(p); if (total > 1) add(total);
    var withGaps = []; out.sort(function (a, b) { return a - b; }).forEach(function (p, i) { if (i && p - out[i - 1] > 1) withGaps.push(0); withGaps.push(p); });
    return withGaps;
  }
  function renderPager(from, to) {
    if (!pager) return;
    var n = rows.length, total = pageCount();
    if (total <= 1) { pager.innerHTML = ''; pager.hidden = true; return; }
    pager.hidden = false;
    var h = '<span class="statgrid-pager-info">แสดง ' + (from + 1) + '–' + Math.min(to, n) + ' จาก ' + n + ' รายการ</span><span class="statgrid-pager-btns">';
    h += '<button type="button" data-goto="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + ' aria-label="หน้าก่อนหน้า">‹</button>';
    pageList(page, total).forEach(function (p) { h += p ? '<button type="button" data-goto="' + p + '"' + (p === page ? ' class="is-current" aria-current="page"' : '') + '>' + p + '</button>' : '<span class="statgrid-pager-gap">…</span>'; });
    h += '<button type="button" data-goto="' + (page + 1) + '"' + (page === total ? ' disabled' : '') + ' aria-label="หน้าถัดไป">›</button></span>';
    pager.innerHTML = h;
  }
  function showPage(p, scrollUp) {
    page = Math.min(Math.max(1, p), pageCount());
    var from = (page - 1) * per, to = from + per, seen = {};
    rows.forEach(function (tr, i) { var on = i >= from && i < to; tr.classList.toggle('is-off', !on); if (on) seen[tr.dataset.group] = true; });
    groupEls.forEach(function (g) { g.classList.toggle('is-off', !seen[g.dataset.groupRow]); });   // a campus heading shows on every page that has some of its rows
    renderPager(from, to);
    if (scrollUp) { var t = root.querySelector('.statgrid-table'); if (t && t.scrollIntoView) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  if (pager) pager.addEventListener('click', function (e) { var b = e.target.closest('[data-goto]'); if (b && !b.disabled) showPage(Number(b.dataset.goto), true); });

  function readInt(input) {
    var v = input.value.trim();
    if (v === '') return null;
    if (!/^[-+]?\d+$/.test(v)) return NaN;
    return parseInt(v, 10);
  }
  function rowState(tr) {
    var old = parseInt(tr.dataset.old, 10), nw = readInt(tr.querySelector('[data-new]'));
    if (nw === null) nw = old;                                     // left empty = unchanged
    return { old: old, nw: nw, valid: !isNaN(nw) && nw >= 0 && nw <= 1000000 };
  }
  function refresh() {
    var changed = 0, diff = 0, invalid = 0, grandNew = 0, grandOld = 0;
    var groups = {};
    rows.forEach(function (tr) {
      var s = rowState(tr), isChanged = s.valid && s.nw !== s.old;
      tr.classList.toggle('is-changed', isChanged);
      tr.classList.toggle('is-invalid', !s.valid);
      var val = s.valid ? s.nw : s.old;
      grandNew += val; grandOld += s.old;
      var g = tr.dataset.group; groups[g] = groups[g] || { nw: 0, old: 0 };
      groups[g].nw += val; groups[g].old += s.old;
      if (!s.valid) invalid++;
      if (isChanged) { changed++; diff += s.nw - s.old; }
    });
    root.querySelectorAll('tr[data-group-row]').forEach(function (gr) {
      var g = groups[gr.dataset.groupRow] || { nw: 0, old: 0 };
      var t = gr.querySelector('[data-group-total]'); if (t) t.textContent = fmt(g.nw);
      var d = gr.querySelector('[data-group-diff]'); if (d) d.textContent = g.nw !== g.old ? ' (' + signed(g.nw - g.old) + ')' : '';
    });
    var gt = root.querySelector('[data-grand]'); if (gt) gt.textContent = fmt(grandNew);
    var gd = root.querySelector('[data-grand-diff]'); if (gd) gd.textContent = grandNew !== grandOld ? ' (' + signed(grandNew - grandOld) + ')' : '';
    if (bar) {
      bar.hidden = changed === 0 && invalid === 0;
      var sum = bar.querySelector('[data-summary]');
      if (sum) sum.textContent = invalid ? 'มี ' + invalid + ' แถวที่ตัวเลขไม่ถูกต้อง (ต้องเป็นจำนวนเต็ม 0 ขึ้นไป)' : 'แก้ไข ' + changed + ' แถว • รวมเปลี่ยน ' + signed(diff) + ' คน';
      bar.classList.toggle('has-error', invalid > 0);
      var btn = bar.querySelector('[data-save]'); if (btn) btn.disabled = invalid > 0 || changed === 0 || saving;
    }
    window.__statGridDirty = changed > 0;
  }

  root.addEventListener('input', function (e) {
    var tr = e.target.closest('tr[data-row]'); if (!tr) return;
    var old = parseInt(tr.dataset.old, 10), dIn = tr.querySelector('[data-delta]'), nIn = tr.querySelector('[data-new]');
    if (e.target === dIn) {
      var d = readInt(dIn);
      nIn.value = d === null ? String(old) : (isNaN(d) ? '' : String(old + d));
    } else if (e.target === nIn) {
      var n = readInt(nIn);
      dIn.value = (n === null || isNaN(n) || n === old) ? '' : String(n - old);
    }
    refresh();
  });
  // Enter inside a number box moves down the column instead of submitting anything
  root.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.matches('input[data-delta], input[data-new]')) return;
    e.preventDefault();
    var col = e.target.matches('[data-delta]') ? '[data-delta]' : '[data-new]';
    var i = rows.indexOf(e.target.closest('tr[data-row]')), next = rows[i + 1];
    if (next) { if (next.classList.contains('is-off')) showPage(Math.floor((i + 1) / per) + 1); var el = next.querySelector(col); el.focus(); el.select(); }
  });

  function post(action, fields) {
    var fd = new FormData(); fd.append('action', action); fd.append('csrf_token', csrf);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return axios.post('', fd).then(function (r) { return r.data; });
  }
  // Somebody else saved some of these rows first: nothing was written. Show their current numbers as the new starting point and keep every edit the admin typed.
  function markConflicts(list, message) {
    var names = [];
    list.forEach(function (c) {
      var tr = rows.filter(function (r) { return Number(r.dataset.id) === Number(c.id); })[0]; if (!tr) return;
      var typed = readInt(tr.querySelector('[data-new]'));
      tr.dataset.old = String(c.current);
      var dIn = tr.querySelector('[data-delta]');
      dIn.value = (typed === null || isNaN(typed) || typed === c.current) ? '' : String(typed - c.current);
      tr.classList.add('is-conflict'); names.push((tr.dataset.name || ('#' + c.id)) + ' (ตอนนี้ ' + fmt(c.current) + ')');
    });
    saving = false; refresh();
    Swal.fire({ icon: 'warning', title: 'ยังไม่ได้บันทึก', html: escapeHtml(message || '') + '<br><small>' + names.map(escapeHtml).join('<br>') + '</small><br>ตรวจตัวเลขใหม่ แล้วกดบันทึกอีกครั้ง', confirmButtonText: 'ตกลง' });
  }
  function reloadSoon() { window.__statGridDirty = false; setTimeout(function () { location.reload(); }, 900); }

  if (bar) {
    bar.querySelector('[data-save]').addEventListener('click', function () {
      var payload = [];
      rows.forEach(function (tr) { var s = rowState(tr); if (s.valid && s.nw !== s.old) payload.push({ id: Number(tr.dataset.id), old: s.old, total: s.nw }); });
      if (!payload.length) return;
      saving = true; refresh();
      post('save_bulk', { rows: JSON.stringify(payload) }).then(function (d) {
        saving = false;
        if (d.status === 'success') { showSuccess(d.message); reloadSoon(); }
        else if (d.status === 'conflict') { markConflicts(d.conflicts || [], d.message); }
        else { showError(d.message); refresh(); }
      }).catch(function (err) {
        saving = false;
        var d = err && err.response && err.response.data;
        if (d && d.status === 'conflict') markConflicts(d.conflicts || [], d.message); else refresh();
      });
    });
    bar.querySelector('[data-discard]').addEventListener('click', function () {
      rows.forEach(function (tr) { tr.querySelector('[data-new]').value = tr.dataset.old; tr.querySelector('[data-delta]').value = ''; });
      refresh();
    });
  }

  root.addEventListener('click', function (e) {
    var undo = e.target.closest('[data-undo]'), del = e.target.closest('[data-del]');
    if (!undo && !del) return;
    var tr = (undo || del).closest('tr[data-row]'), id = tr.dataset.id, name = tr.dataset.name || '';
    // these two actions reload the page, which would silently drop numbers typed but not saved yet: make that a conscious choice
    if (window.__statGridDirty && !e.__confirmedDiscard) {
      Swal.fire({ icon: 'warning', title: 'มีตัวเลขที่ยังไม่ได้บันทึก', text: 'ถ้าทำต่อ ตัวเลขที่พิมพ์ไว้จะหายไป', showCancelButton: true, confirmButtonText: 'ทิ้งตัวเลขที่แก้ไว้แล้วทำต่อ', cancelButtonText: 'กลับไปบันทึกก่อน' }).then(function (r) {
        if (r.isConfirmed) { var again = new MouseEvent('click', { bubbles: true }); again.__confirmedDiscard = true; (undo || del).dispatchEvent(again); }
      });
      return;
    }
    if (undo) {
      Swal.fire({ title: 'ย้อนกลับเป็น ' + fmt(undo.dataset.prev) + ' ?', text: name + ' (ตอนนี้ ' + fmt(tr.dataset.old) + ')', icon: 'question', showCancelButton: true, confirmButtonText: 'ย้อนกลับ', cancelButtonText: 'ยกเลิก' }).then(function (r) {
        if (!r.isConfirmed) return;
        post('undo', { count_id: id, old: tr.dataset.old }).then(function (d) { if (d.status === 'success') { showSuccess(d.message); reloadSoon(); } else showError(d.message); });
      });
    } else {
      Swal.fire({ title: 'ลบแถวนี้?', text: name + ' — ใช้เมื่อไม่มีรายการนี้แล้ว (ลบแล้วกู้คืนไม่ได้)', icon: 'warning', showCancelButton: true, confirmButtonColor: '#c1443d', confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก' }).then(function (r) {
        if (!r.isConfirmed) return;
        post('delete', { count_id: id }).then(function (d) { if (d.status === 'success') { showSuccess(d.message); reloadSoon(); } else showError(d.message); });
      });
    }
  });

  showPage(1);
  window.addEventListener('beforeunload', function (e) { if (window.__statGridDirty) { e.preventDefault(); e.returnValue = ''; } });
  refresh();
})();

/**
 * BAIT Admin - filter bars (form.ne-filters, GET) search as soon as something is picked: no need to press "ค้นหา".
 *  - a dropdown / checkbox / radio: right away
 *  - a calendar date: once a whole date is picked (or the field is cleared)
 *  - a text box: after a short pause in typing (not while a Thai input method is still composing); the cursor is put
 *    back in the box after the page reloads, so typing can simply go on
 *  - a tick list in a <details class="ne-ms"> (several choices): when the list is closed, only if something changed
 * Fields with their own onchange (e.g. a guard against leaving unsaved edits) and fields without a name are left alone.
 * The "ค้นหา" button still works as before.
 */
(function () {
  var KEY = 'bait-filter-focus';

  function submit(form, focusEl) {
    if (form.dataset.autoSubmitting) return;
    form.dataset.autoSubmitting = '1';
    if (focusEl) {
      try {
        sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname, name: focusEl.name, pos: focusEl.selectionStart }));
      } catch (e) {}
    }
    if (form.requestSubmit) form.requestSubmit(); else form.submit();
  }

  function wire(form) {
    var timer = null;
    var later = function (fn, ms) { clearTimeout(timer); timer = setTimeout(fn, ms); };

    form.querySelectorAll('input, select').forEach(function (el) {
      if (!el.name || el.hasAttribute('onchange') || el.closest('details.ne-ms')) return;
      var type = (el.type || '').toLowerCase();

      if (el.tagName === 'SELECT' || type === 'checkbox' || type === 'radio') {
        el.addEventListener('change', function () { submit(form); });
      } else if (type === 'date' || type === 'month') {
        el.addEventListener('change', function () {
          // while a year is still being typed digit by digit the browser already reports e.g. 0002-…: wait for a real one
          var y = parseInt((el.value || '').slice(0, 4), 10);
          if (el.value === '' || y >= 1900) later(function () { submit(form); }, 500);
        });
      } else if (type === 'text' || type === 'search' || type === 'number' || type === '') {
        var start = el.value, composing = false;
        el.addEventListener('compositionstart', function () { composing = true; });
        el.addEventListener('compositionend', function () { composing = false; el.dispatchEvent(new Event('input')); });
        el.addEventListener('input', function () {
          if (composing) return;
          later(function () { if (el.value.trim() !== start.trim()) submit(form, el); }, 700);
        });
      }
    });

    // several-choice tick lists: filter once the list is closed
    form.querySelectorAll('details.ne-ms').forEach(function (d) {
      var snap = function () {
        return Array.prototype.map.call(d.querySelectorAll('input[name]'), function (i) { return i.checked ? 1 : 0; }).join('');
      };
      var before = snap();
      d.addEventListener('toggle', function () { if (!d.open && snap() !== before) submit(form); });
    });
  }

  document.querySelectorAll('form.ne-filters').forEach(function (form) {
    if ((form.getAttribute('method') || 'get').toLowerCase() === 'get' && !form.hasAttribute('data-no-auto')) wire(form);
  });

  // put the cursor back in the text box that triggered the search
  try {
    var f = JSON.parse(sessionStorage.getItem(KEY) || 'null');
    sessionStorage.removeItem(KEY);
    if (f && f.path === location.pathname && f.name) {
      var el = document.querySelector('form.ne-filters [name="' + f.name.replace(/"/g, '') + '"]');
      if (el) {
        el.focus();
        var p = typeof f.pos === 'number' ? Math.min(f.pos, el.value.length) : el.value.length;
        try { el.setSelectionRange(p, p); } catch (e) {}
      }
    }
  } catch (e) {}
})();

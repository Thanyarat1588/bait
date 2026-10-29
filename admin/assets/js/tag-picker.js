/**
 * Shared tag picker for news/articles create+edit forms — lets the admin
 * pick from existing tags (autocomplete dropdown) instead of retyping them,
 * or add a brand new one on the fly (saved immediately via AJAX to the
 * shared `tags` table so it's available to pick from right away, on this
 * page and every other form after it). No dependency beyond axios, which
 * every admin page already loads (see admin/layout/footer.php) — matches
 * this codebase's vanilla-JS-only convention (no jQuery/Select2/TomSelect).
 *
 * The picker still writes a plain comma-joined string into the existing
 * hidden `tags` field on submit, so nothing downstream (the POST handlers
 * in news/articles create.php+edit.php, or any public-site code that reads
 * `tags` as a flat string) needs to change at all.
 */
function initTagPicker(opts) {
  var wrap      = document.getElementById(opts.wrapId);
  var chipsBox  = document.getElementById(opts.chipsId);
  var input     = document.getElementById(opts.inputId);
  var hidden    = document.getElementById(opts.hiddenId);
  var dropdown  = document.getElementById(opts.dropdownId);
  var saveUrl   = opts.saveUrl;
  var allTags   = (opts.allTags || []).slice(); // local copy — grows as new tags get added this session
  var selected  = (hidden.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  var activeIdx = -1; // keyboard-highlighted dropdown row

  function syncHidden() {
    hidden.value = selected.join(', ');
  }

  function renderChips() {
    chipsBox.innerHTML = '';
    selected.forEach(function (name) {
      var chip = document.createElement('span');
      chip.className = 'ne-tag-chip';
      chip.textContent = name;
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'ne-tag-chip-remove';
      x.innerHTML = '&times;';
      x.setAttribute('aria-label', 'ลบแท็ก ' + name);
      x.addEventListener('click', function () {
        selected = selected.filter(function (t) { return t !== name; });
        syncHidden();
        renderChips();
        input.focus();
      });
      chip.appendChild(x);
      chipsBox.appendChild(chip);
    });
  }

  function isSelected(name) {
    var low = name.toLowerCase();
    return selected.some(function (t) { return t.toLowerCase() === low; });
  }

  function findExisting(name) {
    var low = name.toLowerCase();
    return allTags.find(function (t) { return t.toLowerCase() === low; }) || null;
  }

  function addTag(name) {
    name = name.trim();
    if (!name) { input.value = ''; closeDropdown(); return; }
    if (isSelected(name)) {   // already on this item: say so instead of silently doing nothing
      input.value = ''; closeDropdown();
      if (typeof Swal !== 'undefined') Swal.fire({ icon: 'info', title: 'มีแท็กนี้แล้ว', text: 'แท็ก "' + name + '" ถูกเลือกไว้ในรายการนี้แล้ว', timer: 2200, showConfirmButton: false });
      else alert('แท็ก "' + name + '" ถูกเลือกไว้ในรายการนี้แล้ว');
      return;
    }
    var existing = findExisting(name);
    if (existing) {
      selected.push(existing);
      syncHidden();
      renderChips();
      input.value = '';
      closeDropdown();
      return;
    }
    createNewTag(name);
  }

  function createNewTag(name) {
    var fd = new FormData();
    fd.append('name', name);
    axios.post(saveUrl, fd).then(function (res) {
      if (res.data.status === 'success') {
        var savedName = res.data.data.name;
        if (!findExisting(savedName)) allTags.push(savedName);
        if (!isSelected(savedName)) selected.push(savedName);
        syncHidden();
        renderChips();
      } else if (typeof showError === 'function') {
        showError(res.data.message);
      }
    }).catch(function () {
      if (typeof showError === 'function') showError('เพิ่มแท็กใหม่ไม่สำเร็จ ลองอีกครั้ง');
    }).finally(function () {
      input.value = '';
      closeDropdown();
      input.focus();
    });
  }

  function closeDropdown() {
    dropdown.classList.add('d-none');
    dropdown.innerHTML = '';
    activeIdx = -1;
  }

  function openDropdown(query) {
    var q = query.trim().toLowerCase();
    var matches = allTags.filter(function (t) {
      return !isSelected(t) && (q === '' || t.toLowerCase().indexOf(q) !== -1);
    }).slice(0, 8);

    var rows = matches.map(function (t) { return { type: 'existing', label: t }; });
    var exactMatch = q !== '' && allTags.some(function (t) { return t.toLowerCase() === q; });
    if (q !== '' && !exactMatch) {
      rows.push({ type: 'new', label: 'เพิ่มแท็กใหม่ "' + query.trim() + '"' , value: query.trim() });
    }

    if (!rows.length) { closeDropdown(); return; }

    dropdown.innerHTML = '';
    rows.forEach(function (row, i) {
      var item = document.createElement('div');
      item.className = 'ne-tag-suggestion' + (row.type === 'new' ? ' ne-tag-suggestion-new' : '');
      item.textContent = row.type === 'new' ? row.label : row.label;
      if (row.type === 'new') {
        var icon = document.createElement('i');
        icon.className = 'bi bi-plus-circle';
        item.prepend(icon, ' ');
      }
      item.addEventListener('mousedown', function (e) {
        // mousedown (not click) fires before the input's blur, so the
        // dropdown doesn't disappear out from under the click.
        e.preventDefault();
        addTag(row.type === 'new' ? row.value : row.label);
      });
      dropdown.appendChild(item);
    });
    activeIdx = -1;
    dropdown.classList.remove('d-none');
  }

  input.addEventListener('input', function () {
    openDropdown(input.value);
  });

  input.addEventListener('focus', function () {
    openDropdown(input.value);
  });

  input.addEventListener('keydown', function (e) {
    var rows = dropdown.querySelectorAll('.ne-tag-suggestion');
    if (e.key === 'ArrowDown') {
      if (!rows.length) return;
      e.preventDefault();
      activeIdx = (activeIdx + 1) % rows.length;
      rows.forEach(function (r, i) { r.classList.toggle('active', i === activeIdx); });
    } else if (e.key === 'ArrowUp') {
      if (!rows.length) return;
      e.preventDefault();
      activeIdx = (activeIdx - 1 + rows.length) % rows.length;
      rows.forEach(function (r, i) { r.classList.toggle('active', i === activeIdx); });
    } else if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      if (activeIdx >= 0 && rows[activeIdx]) {
        rows[activeIdx].dispatchEvent(new Event('mousedown'));
      } else if (input.value.trim()) {
        addTag(input.value);
      }
    } else if (e.key === 'Backspace' && input.value === '' && selected.length) {
      selected.pop();
      syncHidden();
      renderChips();
    } else if (e.key === 'Escape') {
      closeDropdown();
    }
  });

  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) closeDropdown();
  });

  renderChips();
}

/**
 * Shows the picture as soon as it is chosen, under every image <input type="file"> in the admin that has no preview of
 * its own (those are marked data-has-preview; data-no-preview turns this off too). The box shows the picture, the file
 * name, its size and its pixel size, plus "ยกเลิกไฟล์นี้" to clear a wrong pick, so the admin can see the right file was
 * chosen before pressing Save. When upload-compress.js swaps in a smaller or converted copy (the footer pictures become
 * grey), it fires "upload:processed" and the box shows that copy. The box hides again when the field is emptied
 * (form reset, a pop-up window opened again, or a page script clearing the field).
 */
(function () {
  var css = '.up-prev{display:flex;flex-wrap:wrap;gap:10px 12px;align-items:center;margin:8px 0 4px;padding:8px;border:1px dashed #b9c6e4;border-radius:10px;background:#f6f8fd}'
    + '.up-prev[hidden]{display:none}'
    + '.up-prev__img{flex:0 1 auto;max-width:min(180px,100%);max-height:130px;border-radius:6px;object-fit:contain;'
    + 'background:repeating-conic-gradient(#e9edf5 0 25%,#fff 0 50%) 0 0/16px 16px;box-shadow:0 1px 3px rgba(0,0,0,.12)}'
    + '.up-prev__info{flex:1 1 150px;min-width:0;font-size:12.5px;line-height:1.45;color:#46536e}'
    + '.up-prev__info b{display:block;color:#14203a;font-size:13px;margin-bottom:2px}'
    + '.up-prev__name{word-break:break-all}'
    + '.up-prev__clear{white-space:nowrap;margin-top:4px;border:1px solid #f1c0bd;background:#fff;color:#b42318;border-radius:999px;padding:2px 10px;font:inherit;font-size:12px;cursor:pointer}'
    + '.up-prev__clear:hover{background:#b42318;color:#fff}'
    + '.ne-dropzone-filename:has(+ .up-prev:not([hidden])){display:none!important}'   // the box already names the file
    + '.up-drop[hidden]{display:none}'
    + '.up-drop__frame{position:relative;border-radius:12px;overflow:hidden;border:1px solid #e3e8f1;background:#f6f8fd}'
    + '.up-drop__img{display:block;width:100%;max-height:240px;object-fit:cover;cursor:pointer}'
    + '.up-drop__bin{position:absolute;top:8px;right:8px;width:34px;height:34px;border-radius:50%;border:0;background:rgba(22,46,147,.92);color:#fff;'
    + 'display:grid;place-items:center;font-size:15px;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.25)}'
    + '.up-drop__bin:hover{background:#b42318}'
    + '.up-drop__meta{margin:6px 0 0;font-size:12.5px;color:#46536e}.up-drop__meta .up-prev__name{word-break:break-all}.up-drop__meta .up-prev__meta{white-space:nowrap}'
    + '.up-drop__meta b{color:#14203a;font-weight:600}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  var isImageInput = function (el) {
    if (!el || el.type !== 'file' || el.hasAttribute('data-has-preview') || el.hasAttribute('data-no-preview')) return false;
    return /image/.test(el.getAttribute('accept') || '') || /^(image|logo|favicon|poster)$/i.test(el.name || '');
  };
  var kb = function (n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; };

  function box(input) {
    if (input._upPrev) return input._upPrev;
    var b = document.createElement('div');
    b.className = 'up-prev'; b.hidden = true; b.setAttribute('aria-live', 'polite');
    b.innerHTML = '<img class="up-prev__img" alt="ตัวอย่างภาพที่เลือก"><div class="up-prev__info"><b>ภาพที่เลือก (ยังไม่บันทึก)</b>'
      + '<div class="up-prev__name"></div><div class="up-prev__meta"></div><button type="button" class="up-prev__clear">ยกเลิกไฟล์นี้</button></div>';
    // put it after the field — or after its input group / the "click or drop a file" box (a <label>: a button inside it
    // would open the file picker again) and that box's file-name line — so the layout stays intact
    var anchor = input.closest('label, .input-group') || input;
    if (anchor.nextElementSibling && anchor.nextElementSibling.classList.contains('ne-dropzone-filename')) anchor = anchor.nextElementSibling;
    anchor.insertAdjacentElement('afterend', b);
    b.querySelector('.up-prev__clear').addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      input.value = '';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      hide(input);
    });
    input._upPrev = b;
    return b;
  }
  // The "click or drop a file" boxes (label.ne-dropzone: news, articles, programmes) work like the picture field of
  // สาขาวิชา: the chosen picture takes the place of the box, with a round bin button to drop it again; a click on the
  // picture picks another one. On an edit page the current cover is hidden meanwhile and comes back if the pick is dropped.
  function dropBox(input) {
    if (input._upPrev) return input._upPrev;
    var zone = input.closest('label.ne-dropzone'), panel = zone.closest('.ne-panel') || zone.parentNode;
    var b = document.createElement('div');
    b.className = 'up-drop'; b.hidden = true; b.setAttribute('aria-live', 'polite');
    b.innerHTML = '<div class="up-drop__frame"><img class="up-drop__img" alt="ภาพที่เลือก" title="คลิกเพื่อเลือกภาพอื่น">'
      + '<button type="button" class="up-drop__bin" title="ยกเลิกภาพนี้" aria-label="ยกเลิกภาพนี้"><i class="bi bi-trash3"></i></button></div>'
      + '<p class="up-drop__meta"><b>ภาพใหม่ (ยังไม่บันทึก)</b> · <span class="up-prev__name"></span> · <span class="up-prev__meta"></span></p>';
    zone.insertAdjacentElement('beforebegin', b);
    b._zone = zone;
    b._old = [].slice.call(panel.querySelectorAll('.ne-cover-preview, .ne-cover-removed-notice'));
    b.querySelector('.up-drop__img').addEventListener('click', function () { input.click(); });
    b.querySelector('.up-drop__bin').addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      input.value = '';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      hide(input);
    });
    input._upPrev = b;
    return b;
  }
  function hide(input) {
    var b = input._upPrev; if (!b) return;
    b.hidden = true;
    var img = b.querySelector('img'); if (img._url) { URL.revokeObjectURL(img._url); img._url = null; } img.removeAttribute('src');
    if (b._zone) {
      b._zone.style.display = '';
      b._old.forEach(function (el) { el.style.display = el._upDisplay || ''; });
      if (window.neToggleRemoveImage && document.getElementById('removeImg')) neToggleRemoveImage(document.getElementById('removeImg'));   // back to what the page showed
    }
  }
  function show(input) {
    var f = input.files && input.files[0];
    if (!f) { hide(input); return; }
    var b = input.closest('label.ne-dropzone') ? dropBox(input) : box(input), img = b.querySelector('img');
    if (b._zone && !(/^image\//.test(f.type) || /\.(ico|svg)$/i.test(f.name))) { hide(input); return; }
    if (b._zone) {
      b._zone.style.display = 'none';
      b._old.forEach(function (el) { if (el.style.display !== 'none') el._upDisplay = el.style.display; el.style.display = 'none'; });
      var fn = b._zone.nextElementSibling; if (fn && fn.classList.contains('ne-dropzone-filename')) fn.style.display = 'none';
    }
    if (!/^image\//.test(f.type) && !/\.(ico|svg)$/i.test(f.name)) { hide(input); return; }
    if (img._url) URL.revokeObjectURL(img._url);
    img._url = URL.createObjectURL(f);
    b.querySelector('.up-prev__name').textContent = f.name;
    b.querySelector('.up-prev__meta').textContent = kb(f.size);
    img.onload = function () { if (img.naturalWidth) b.querySelector('.up-prev__meta').textContent = kb(f.size) + ' · ' + img.naturalWidth + ' × ' + img.naturalHeight + ' px'; };
    img.src = img._url;
    b.hidden = false;
  }

  // the browser shows "No file chosen" as a tooltip over the click-or-drop boxes: say what the box does instead
  document.querySelectorAll('label.ne-dropzone input[type="file"]').forEach(function (i) { if (!i.title) i.title = 'คลิกเพื่อเลือกภาพ หรือลากไฟล์มาวาง'; });
  document.addEventListener('change', function (e) { if (isImageInput(e.target)) show(e.target); });
  document.addEventListener('upload:processed', function (e) { if (isImageInput(e.target)) show(e.target); });
  // a form reset or a pop-up window opened again empties the fields without a change event: hide boxes whose field is empty
  var sweep = function (root) { (root || document).querySelectorAll('input[type="file"]').forEach(function (i) { if (i._upPrev && !(i.files && i.files.length)) hide(i); }); };
  document.addEventListener('reset', function (e) { setTimeout(function () { sweep(e.target); }, 0); }, true);
  document.addEventListener('shown.bs.modal', function (e) { sweep(e.target); });
  document.addEventListener('click', function () { setTimeout(sweep, 0); }, true);   // page scripts that clear a field on a button press
})();

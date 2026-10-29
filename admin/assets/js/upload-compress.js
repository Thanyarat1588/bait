/**
 * Shrinks photos in the browser BEFORE they are uploaded (the server has no
 * image library, so it can't do this itself). Applies to every <input
 * type="file"> in the admin that accepts images:
 *   - longest side capped at MAX_SIDE px (phone/camera photos are often 4000+)
 *   - re-encoded as JPEG (or WebP when the picture has transparency)
 * The picture looks the same at the size the site displays it; only the
 * pixel dimensions above MAX_SIDE and the wasted bytes are dropped. Files
 * that are already small, GIF/SVG (animation / vector), and the staff photo
 * cropper (data-no-compress) are left untouched. If the result isn't
 * actually smaller, or anything goes wrong, the original file is kept.
 *
 * Inputs marked data-backdrop (the footer background pictures) are always converted instead: grey, 38% darker, longest side 2000px,
 * so the white footer text stays readable over any picture. Done here for the same reason (the server has no image library).
 */
(function () {
  var MAX_SIDE = 1920;          // px, longest side
  var SKIP_BELOW = 300 * 1024;  // bytes: already small enough
  var QUALITY = 0.85;

  function hasAlpha(ctx, w, h) {
    // Sample a downscaled copy — enough to tell "photo" from "logo with transparent corners".
    var sw = Math.min(w, 200), sh = Math.min(h, 200);
    var probe = document.createElement('canvas');
    probe.width = sw; probe.height = sh;
    var pctx = probe.getContext('2d');
    pctx.drawImage(ctx.canvas, 0, 0, sw, sh);
    var d = pctx.getImageData(0, 0, sw, sh).data;
    for (var i = 3; i < d.length; i += 4) if (d[i] < 250) return true;
    return false;
  }

  function toBlob(canvas, type, q) {
    return new Promise(function (res) { canvas.toBlob(res, type, q); });
  }

  async function shrink(file) {
    var bmp = await createImageBitmap(file); // honours EXIF rotation
    var w = bmp.width, h = bmp.height;
    var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
    if (scale === 1 && file.size < SKIP_BELOW * 2) { bmp.close && bmp.close(); return null; }
    var cw = Math.round(w * scale), ch = Math.round(h * scale);
    var canvas = document.createElement('canvas');
    canvas.width = cw; canvas.height = ch;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(bmp, 0, 0, cw, ch);
    bmp.close && bmp.close();

    var blob, ext;
    if (file.type !== 'image/jpeg' && hasAlpha(ctx, cw, ch)) {
      blob = await toBlob(canvas, 'image/webp', QUALITY);
      ext = 'webp';
      if (!blob || blob.type !== 'image/webp') { blob = await toBlob(canvas, 'image/png'); ext = 'png'; }
    } else {
      blob = await toBlob(canvas, 'image/jpeg', QUALITY);
      ext = 'jpg';
    }
    if (!blob || blob.size >= file.size) return null; // not an improvement — keep original
    var name = file.name.replace(/\.[^.]+$/, '') + '.' + ext;
    return { file: new File([blob], name, { type: blob.type, lastModified: Date.now() }), w: cw, h: ch };
  }

  var BACKDROP_SIDE = 2000, BACKDROP_BRIGHTNESS = 0.62;

  // Footer background picture: grey + darker + capped size, always re-encoded as JPEG.
  async function backdrop(file) {
    var bmp = await createImageBitmap(file);
    var scale = Math.min(1, BACKDROP_SIDE / Math.max(bmp.width, bmp.height));
    var cw = Math.max(1, Math.round(bmp.width * scale)), ch = Math.max(1, Math.round(bmp.height * scale));
    var canvas = document.createElement('canvas');
    canvas.width = cw; canvas.height = ch;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cw, ch);   // transparent pixels become black, never white
    ctx.drawImage(bmp, 0, 0, cw, ch);
    bmp.close && bmp.close();
    var img = ctx.getImageData(0, 0, cw, ch), d = img.data;
    for (var i = 0; i < d.length; i += 4) {
      var v = (0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) * BACKDROP_BRIGHTNESS;
      d[i] = d[i + 1] = d[i + 2] = v;
    }
    ctx.putImageData(img, 0, 0);
    var blob = await toBlob(canvas, 'image/jpeg', 0.82);
    if (!blob) throw new Error('encode failed');
    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
  }

  // Capture phase on document: runs before the inputs' own onchange previews,
  // so they (and the later form submit) already see the smaller file.
  // Footer pictures: the server accepts one only together with "<field>_processed=1", set here once the picture really
  // was converted, so an untreated photo (old browser, script blocked, Save pressed too early) can never end up behind the text.
  var pending = 0;
  function lockSave(form, on) {
    pending = Math.max(0, pending + (on ? 1 : -1));
    if (!form) return;
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) { b.disabled = pending > 0; });
  }
  function processedFlag(input) {
    var name = input.name + '_processed', el = input.form && input.form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!el && input.form) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; input.form.appendChild(el); }
    return el || { value: '' };
  }

  document.addEventListener('change', async function (e) {
    var input = e.target;
    if (!input || input.type !== 'file' || input.hasAttribute('data-no-compress') || input.id === 'imageFileInput') return;
    if (!input.files || !input.files.length) return;

    if (input.hasAttribute('data-backdrop')) {
      var note = input.parentNode.querySelector('[data-backdrop-note]'), f0 = input.files[0], flag = processedFlag(input);
      flag.value = '';
      if (!window.createImageBitmap || !window.DataTransfer) {
        input.value = '';
        if (note) note.textContent = 'เบราว์เซอร์นี้ปรับภาพให้อัตโนมัติไม่ได้ กรุณาใช้ Chrome หรือ Edge รุ่นใหม่แล้วเลือกไฟล์อีกครั้ง';
        return;
      }
      if (!/^image\/(jpeg|png|webp)$/.test(f0.type)) { input.value = ''; if (note) note.textContent = 'รองรับเฉพาะ JPG, PNG, WEBP'; return; }
      var token = (input._backdropToken || 0) + 1; input._backdropToken = token;   // a second pick while the first is still working: only the last one counts
      if (note) note.textContent = 'กำลังปรับภาพ... (รอสักครู่ก่อนกดบันทึก)';
      lockSave(input.form, true);
      try {
        var conv = await backdrop(f0);
        if (token !== input._backdropToken) return;
        var out = new DataTransfer();
        out.items.add(conv);
        input.files = out.files;
        flag.value = '1';
        input.dispatchEvent(new CustomEvent('upload:processed', { bubbles: true }));   // upload-preview.js shows the converted picture
        if (note) note.textContent = 'ปรับภาพเป็นขาวดำและลดความสว่างแล้ว (' + Math.round(out.files[0].size / 1024) + ' KB) — กดบันทึกเพื่อใช้ภาพนี้';
      } catch (err) {
        if (token !== input._backdropToken) return;
        input.value = '';
        if (note) note.textContent = 'ปรับภาพไม่สำเร็จ ลองไฟล์อื่น';
      } finally {
        lockSave(input.form, false);
      }
      return;
    }
    if (!window.createImageBitmap || !window.DataTransfer) return;

    var dt = new DataTransfer();
    var changed = false;
    for (var i = 0; i < input.files.length; i++) {
      var f = input.files[i];
      var ok = /^image\/(jpeg|png|webp|bmp)$/.test(f.type);
      if (!ok) { dt.items.add(f); continue; }
      try {
        var r = await shrink(f);
        if (r) { dt.items.add(r.file); changed = true; }
        else dt.items.add(f);
      } catch (err) {
        dt.items.add(f);
      }
    }
    if (changed) { input.files = dt.files; input.dispatchEvent(new CustomEvent('upload:processed', { bubbles: true })); }
  }, true);
})();

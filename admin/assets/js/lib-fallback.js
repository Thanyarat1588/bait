/**
 * BAIT Admin - stand-ins for the libraries loaded from the CDN (SweetAlert2, axios, Bootstrap's modal, CKEditor).
 * Loaded right after them in admin/layout/footer.php. If a CDN file did not arrive (CDN down, school network filter,
 * ad blocker, a changed file refused by its integrity hash), every Delete button and every save used to stop with a
 * JavaScript error and do nothing at all. These small versions keep the buttons working with the browser's own
 * confirm()/alert() and fetch(), and a bar at the top says which part is missing. Nothing here runs when the real library loaded.
 */
(function () {
  'use strict';
  var missing = [];

  function textOf(html, didOpen) {
    // DOMParser gives an inert document: markup in it never runs scripts or loads images.
    var doc = new DOMParser().parseFromString('<div>' + (html || '') + '</div>', 'text/html');
    var box = doc.body.firstChild;
    if (typeof didOpen === 'function') { try { didOpen(box); } catch (e) {} }
    return (box.textContent || '').replace(/\s+/g, ' ').trim();
  }

  // ── SweetAlert2: Swal.fire(options) or Swal.fire(title, text, icon) ──
  if (typeof window.Swal === 'undefined') {
    missing.push('กล่องข้อความ (SweetAlert2)');
    window.Swal = {
      fire: function (a, b) {
        var o = (a && typeof a === 'object') ? a : { title: a, text: b };
        var msg = [o.title, o.text !== undefined ? o.text : textOf(o.html, o.didOpen)].filter(Boolean).join('\n\n');
        if (o.showCancelButton) {
          var ok = window.confirm(msg);
          return Promise.resolve({ isConfirmed: ok, isDismissed: !ok, value: ok || undefined });
        }
        if (o.timer && o.showConfirmButton === false) {   // a short "done" note: do not block the page with alert()
          showBar(msg, 'ok');
          return Promise.resolve({ isConfirmed: true, isDismissed: false });
        }
        window.alert(msg);
        return Promise.resolve({ isConfirmed: true, isDismissed: false, value: true });
      },
      isVisible: function () { return false; },
      close: function () {},
      __fallback: true
    };
  }

  // ── axios: post/get with defaults.headers.common and one response interceptor, on top of fetch() ──
  if (typeof window.axios === 'undefined') {
    missing.push('ระบบส่งข้อมูล (axios)');
    var handlers = [];
    var ax = {
      defaults: { headers: { common: {} } },
      interceptors: { response: { use: function (ok, fail) { handlers.push([ok, fail]); } } },
      request: function (method, url, data) {
        var headers = Object.assign({ 'Accept': 'application/json, text/plain, */*' }, ax.defaults.headers.common);
        var body;
        if (data instanceof FormData || data === undefined || data === null) body = data || undefined;
        else if (typeof data === 'string') { body = data; headers['Content-Type'] = 'application/x-www-form-urlencoded'; }
        else { body = JSON.stringify(data); headers['Content-Type'] = 'application/json'; }
        var p = fetch(url || window.location.href, { method: method, headers: headers, body: method === 'GET' ? undefined : body, credentials: 'same-origin' })
          .then(function (r) {
            return r.text().then(function (t) {
              var d = t; try { d = JSON.parse(t); } catch (e) {}
              var res = { status: r.status, data: d, headers: {}, config: { method: method.toLowerCase(), url: url } };
              r.headers.forEach(function (v, k) { res.headers[k] = v; });
              if (r.ok) return res;
              var err = new Error('Request failed with status code ' + r.status); err.response = res; throw err;
            });
          }, function (e) { var err = new Error('Network Error'); err.response = undefined; err.cause = e; throw err; });
        handlers.forEach(function (h) { p = p.then(h[0] || function (x) { return x; }, h[1]); });
        return p;
      },
      post: function (url, data) { return ax.request('POST', url, data); },
      get: function (url) { return ax.request('GET', url); },
      __fallback: true
    };
    window.axios = ax;
  }

  // ── Bootstrap: only what the admin pages use (new bootstrap.Modal(el).show()/hide(), data-bs-dismiss="modal") ──
  if (typeof window.bootstrap === 'undefined') {
    missing.push('หน้าต่างฟอร์ม (Bootstrap)');
    var Modal = function (el) {
      this.el = typeof el === 'string' ? document.querySelector(el) : el;
      if (this.el) this.el.__bsModal = this;
    };
    Modal.prototype.show = function () {
      if (!this.el) return;
      this.el.style.display = 'block'; this.el.classList.add('show'); this.el.removeAttribute('aria-hidden');
      this.el.style.background = 'rgba(15,23,42,.45)'; this.el.style.overflowY = 'auto';
      document.body.classList.add('modal-open');
    };
    Modal.prototype.hide = function () {
      if (!this.el) return;
      this.el.style.display = 'none'; this.el.classList.remove('show'); this.el.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
    };
    Modal.getInstance = function (el) { return el && el.__bsModal || null; };
    Modal.getOrCreateInstance = function (el) { return Modal.getInstance(el) || new Modal(el); };
    window.bootstrap = { Modal: Modal, __fallback: true };
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-bs-dismiss="modal"]');
      if (t) { var m = t.closest('.modal'); if (m) Modal.getOrCreateInstance(m).hide(); }
      var o = e.target.closest && e.target.closest('[data-bs-toggle="modal"]');
      if (o) { var target = document.querySelector(o.getAttribute('data-bs-target') || o.getAttribute('href')); if (target) Modal.getOrCreateInstance(target).show(); }
    });
  }

  // ── CKEditor: pages load it themselves just after this file; if it never arrives the plain text box stays and is saved as is ──
  if (typeof window.ClassicEditor === 'undefined') {
    window.ClassicEditor = {
      create: function () {
        showBar('ตัวแก้ไขข้อความ (CKEditor) โหลดไม่สำเร็จ ใช้กล่องข้อความธรรมดาแทนได้ — ลองรีเฟรชหน้าเพื่อใช้ตัวแก้ไขแบบเต็ม', 'warn');
        return Promise.reject(new Error('CKEditor did not load'));
      },
      __fallback: true
    };
  }

  function showBar(msg, kind) {
    var go = function () {
      var bar = document.createElement('div');
      bar.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
      bar.style.cssText = 'position:fixed;left:12px;right:12px;top:12px;z-index:3000;padding:10px 14px;border-radius:10px;font:14px/1.5 Kanit,system-ui,sans-serif;'
        + 'box-shadow:0 6px 24px rgba(0,0,0,.18);' + (kind === 'ok' ? 'background:#e8f6ee;color:#1f6b47;border:1px solid #bfe2cd' : 'background:#fff4e5;color:#7a4b00;border:1px solid #f3c98b');
      bar.textContent = msg;
      document.body.appendChild(bar);
      if (kind === 'ok') setTimeout(function () { bar.remove(); }, 2500);
    };
    if (document.body) go(); else document.addEventListener('DOMContentLoaded', go);
  }
  if (missing.length) showBar('บางส่วนของหน้านี้โหลดไม่สำเร็จ: ' + missing.join(', ') + ' — ระบบใช้แบบสำรองให้ใช้งานต่อได้ ถ้าปุ่มใดยังไม่ทำงาน กรุณารีเฟรชหน้า', 'warn');
  window.__baitLibMissing = missing;
})();

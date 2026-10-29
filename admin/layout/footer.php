
</div><!-- /.content-area -->
</main><!-- /#mainContent -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js" integrity="sha384-nLoOnA/BDh8A/jxqtckg4DumuCGOBYUnNJLZdQz/zfYNp3wcjGSoWTAzgko06G/2" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1.20.0/dist/axios.min.js" integrity="sha384-mIJWc6qj9ALoBF2yHkNOf0fbGel66+X3UkhYw57p36/PaTyDpO9pvnxrVZ7ZGJfi" crossorigin="anonymous"></script>
<script src="<?= BASE_URL ?>/admin/assets/js/lib-fallback.js?v=<?= @filemtime(BASE_PATH . '/admin/assets/js/lib-fallback.js') ?: time() ?>"></script>
<script src="<?= BASE_URL ?>/admin/assets/js/upload-compress.js?v=<?= @filemtime(BASE_PATH . '/admin/assets/js/upload-compress.js') ?: time() ?>"></script>
<script src="<?= BASE_URL ?>/admin/assets/js/upload-preview.js?v=<?= @filemtime(BASE_PATH . '/admin/assets/js/upload-preview.js') ?: time() ?>"></script>
<script src="<?= BASE_URL ?>/admin/assets/js/filter-autosubmit.js?v=<?= @filemtime(BASE_PATH . '/admin/assets/js/filter-autosubmit.js') ?: time() ?>"></script>
<script>
// ── Sidebar toggle ──
let sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
const sidebar    = document.getElementById('sidebar');
const topbar     = document.getElementById('topbar');
const mainContent= document.getElementById('mainContent');
const overlay    = document.getElementById('sidebarOverlay');

// Below 1024px the sidebar is an off-canvas drawer (phone + tablet); at/above
// it becomes the fixed, optionally-collapsible desktop sidebar — must match
// the min-width:1024px tier used in admin/layout/header.php's CSS.
const DESKTOP_BREAKPOINT = 1024;

function applySidebarState() {
  const isMobile = window.innerWidth < DESKTOP_BREAKPOINT;
  if (isMobile) {
    sidebar.classList.remove('collapsed');
    sidebar.classList.remove('mobile-open');
    overlay.style.display = 'none';
    return;
  }
  if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    topbar.classList.add('sidebar-collapsed');
    mainContent.classList.add('sidebar-collapsed');
  } else {
    sidebar.classList.remove('collapsed');
    topbar.classList.remove('sidebar-collapsed');
    mainContent.classList.remove('sidebar-collapsed');
  }
}

function toggleSidebar() {
  const isMobile = window.innerWidth < DESKTOP_BREAKPOINT;
  if (isMobile) {
    sidebar.classList.toggle('mobile-open');
    overlay.style.display = sidebar.classList.contains('mobile-open') ? 'block' : 'none';
  } else {
    sidebarCollapsed = !sidebarCollapsed;
    localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
    applySidebarState();
  }
}

applySidebarState();
window.addEventListener('resize', applySidebarState);

// ── SweetAlert2 helpers ──
window.Swal = Swal; // ensure global

// Deleting is a POST with the CSRF token (never a plain link, which another site could trigger).
// The ?query of the url given is sent as POST fields: confirmDelete('delete.php?id=5', 'name').
function submitPostForm(url, fields = {}) {
  const u = new URL(url, window.location.href);
  const data = Object.assign({}, Object.fromEntries(u.searchParams), fields, { csrf_token: <?= json_encode(csrfToken()) ?> });
  u.search = '';
  const f = document.createElement('form');
  f.method = 'POST'; f.action = u.toString();
  Object.entries(data).forEach(([k, v]) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); });
  document.body.appendChild(f); f.submit();
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function confirmDelete(url, itemName = 'รายการนี้') {
  Swal.fire({
    title: 'ยืนยันการลบ?',
    html: `ต้องการลบ <strong>${escapeHtml(itemName)}</strong> ใช่หรือไม่?<br><small class="text-muted">การดำเนินการนี้ไม่สามารถย้อนกลับได้</small>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#c1443d',
    cancelButtonColor: '#8b93a7',
    confirmButtonText: '<i class="bi bi-trash me-1"></i>ลบ',
    cancelButtonText: 'ยกเลิก',
    reverseButtons: true
  }).then((result) => {
    if (result.isConfirmed) submitPostForm(url);
  });
}

// If the SweetAlert2 CDN script fails to load (ad-blocker, offline, slow
// network), window.Swal is undefined and calling Swal.fire() throws —
// which previously killed the calling function silently, making buttons
// look completely unresponsive with zero feedback. Fall back to a plain
// alert() so an action always produces *some* visible result.
function showSuccess(msg) {
  if (typeof Swal !== 'undefined') {
    Swal.fire({ icon:'success', title:'สำเร็จ', text: msg, timer: 2000, showConfirmButton: false });
  } else {
    alert(msg);
  }
}

// An error box never comes up empty, and the same message is not shown twice in a row (the request handler below shows
// it once, then the page's own "else showError(r.data.message)" would show it again).
function showError(msg) {
  msg = (msg === undefined || msg === null || String(msg).trim() === '') ? 'บันทึกไม่สำเร็จ (ระบบไม่ได้ส่งรายละเอียดกลับมา) กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง' : String(msg);
  var last = window.__lastErr;
  if (last && last.msg === msg && Date.now() - last.t < 2000) return;
  window.__lastErr = { msg: msg, t: Date.now() };
  if (typeof Swal !== 'undefined') {
    Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: msg });
  } else {
    alert('เกิดข้อผิดพลาด: ' + msg);
  }
}
// The session ended (signed out / idle too long): say so and offer the way back in, instead of a generic error.
function showSessionExpired(msg, loginUrl) {
  window.__lastErr = { msg: msg, t: Date.now() };
  Swal.fire({ icon: 'warning', title: 'เซสชันหมดอายุ', text: msg, showCancelButton: true, confirmButtonText: 'เข้าสู่ระบบใหม่', cancelButtonText: 'อยู่หน้านี้ก่อน' })
    .then(function (r) { if (r.isConfirmed) window.location.href = loginUrl || <?= json_encode(BASE_URL . '/admin/login.php') ?>; });
}
// A drag-and-drop order that was not saved: say so, then reload so the screen shows the order that is really stored.
function orderNotSaved(msg) {
  var m = (msg ? msg + ' — ' : '') + 'ลำดับใหม่ยังไม่ได้บันทึก หน้านี้จะโหลดใหม่เพื่อแสดงลำดับที่บันทึกไว้จริง';
  window.__lastErr = { msg: m, t: Date.now() };
  Swal.fire({ icon: 'error', title: 'ยังไม่ได้บันทึกลำดับ', text: m, confirmButtonText: 'ตกลง' }).then(function () { window.location.reload(); });
}

// ── Flash messages ──
<?php if (!empty($_SESSION['flash_success'])): ?>
showSuccess(<?= json_encode($_SESSION['flash_success']) ?>);
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
showError(<?= json_encode($_SESSION['flash_error']) ?>);
<?php unset($_SESSION['flash_error']); endif; ?>

// ── Axios CSRF default ──
axios.defaults.headers.common['X-CSRF-TOKEN'] = '<?= csrfToken() ?>';

// ── Failed requests ──
// The server answers a refused save (duplicate name, missing field, bad file ...) with HTTP 400 and a Thai message.
// axios turns every 4xx/5xx into an exception, so pages that only read r.data.status used to do NOTHING — no message at all.
// Here every failure is shown to the admin in plain Thai, and handed back to the page in the normal {status:"error", message} shape.
// A save must always come back as JSON {status: ...}. Anything else (an HTML page such as the login page, an empty reply)
// means it was not saved: before, pages read r.data.status of a string and showed nothing at all.
axios.interceptors.response.use(function (res) {
  var m = String((res.config && res.config.method) || '').toLowerCase();
  if (m === 'post' && (!res.data || typeof res.data !== 'object' || !('status' in res.data))) {
    var bad = 'ระบบตอบกลับไม่ถูกต้อง รายการนี้อาจยังไม่ได้บันทึก กรุณาโหลดหน้าใหม่แล้วตรวจสอบอีกครั้ง';
    showError(bad);
    return { status: res.status, data: { status: 'error', message: bad } };
  }
  return res;
}, function (err) {
  var r = err.response, msg;
  if (r && r.status === 401 && r.data && r.data.code === 'session_expired') {
    showSessionExpired(r.data.message, r.data.login);
    return Promise.resolve({ status: 401, data: { status: 'error', message: r.data.message } });
  }
  if (r && r.data && typeof r.data === 'object' && r.data.message) msg = r.data.message;
  else if (r && r.status === 403) msg = 'เซสชันหมดอายุหรือไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
  else if (r && r.status === 413) msg = 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ กรุณาลดขนาดไฟล์แล้วลองใหม่';
  else if (!r) msg = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่อีกครั้ง';
  else msg = 'ระบบขัดข้อง บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หากยังไม่สำเร็จ กรุณาแจ้งผู้ดูแลระบบ';
  showError(msg);
  return Promise.resolve({ status: r ? r.status : 0, data: { status: 'error', message: msg } });
});
</script>
<?php if (!empty($extraJs)) echo $extraJs; ?>
</body>
</html>

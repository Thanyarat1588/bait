/**
 * BAIT - Frontend entry point (ES modules, no build step, no jQuery).
 * Each feature module is self-guarding: it no-ops if its markup isn't
 * present on the current page, so this file is safe to load on every page.
 * Active nav-link highlighting stays server-side ($activePage in
 * layout/header.php) — do not duplicate it here; a path-prefix match broke
 * under the /bait/ subdirectory install previously.
 *
 * This file itself is cache-busted with ?v=filemtime() at the <script src>
 * in layout/footer.php, but a plain `import './x.js'` specifier can't carry
 * a query string, so the modules it imports were exempt from that busting —
 * the browser caches each by its bare URL forever, meaning an edit to say
 * staff-tree-scale.js alone could keep serving a stale cached copy
 * indefinitely (found the hard way: a logic fix kept silently not applying
 * in testing). Dynamic import() calls, unlike static imports, accept a
 * computed URL, so window.BAIT.jsVersion (assets/js/*.js's newest mtime,
 * see includes/asset-version.php) gets appended to each one instead.
 */
const v = window.BAIT?.jsVersion ? `?v=${window.BAIT.jsVersion}` : '';
// Each feature module is loaded on its own: if one file is missing or broken (a half-finished FTP upload, say) the
// others still start, and the failure is only logged, instead of Promise.all rejecting and killing the whole script.
const MODULES = [
  ['initNav', 'nav'], ['initHeroSlider', 'hero-slider'], ['initReveal', 'reveal'], ['initLazyImages', 'lazy'],
  ['initSearch', 'search'], ['initCampusTabs', 'campus-tabs'], ['initFilters', 'filters'], ['initCounters', 'counters'],
  ['initStaffTreeScale', 'staff-tree-scale'], ['initExecBoard', 'exec-board'], ['initExecScroll', 'exec-scroll'],
  ['initCampusMap', 'campus-map'], ['initHistoryTimeline', 'history-timeline'], ['initCardSlider', 'card-slider'], ['initThaiWrap', 'thai-wrap'], ['initMourning', 'mourning'],
];
const settled = await Promise.allSettled(MODULES.map(([, file]) => import(`./${file}.js${v}`)));
const INIT = {};
settled.forEach((r, i) => {
  const [name, file] = MODULES[i];
  if (r.status === 'fulfilled' && typeof r.value[name] === 'function') INIT[name] = r.value[name];
  else console.error(`BAIT: could not load ${file}.js`, r.status === 'rejected' ? r.reason : 'missing export ' + name);
});
const run = (name) => { try { if (INIT[name]) INIT[name](); } catch (err) { console.error(`BAIT: ${name} failed`, err); } };

document.documentElement.classList.remove('no-js');

/** Small message at the bottom of the screen (e.g. "link copied"). */
function showToast(message) {
  let el = document.querySelector('.toast-msg');
  if (!el) {
    el = document.createElement('div');
    el.className = 'toast-msg';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
  }
  el.textContent = message;
  el.classList.add('is-visible');
  clearTimeout(showToast.timer);
  showToast.timer = setTimeout(() => el.classList.remove('is-visible'), 2200);
}

/** Copies text. The Clipboard API only exists on https/localhost — the site may
 *  run on plain http, so fall back to a hidden textarea + execCommand. */
async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try { await navigator.clipboard.writeText(text); return true; } catch { /* fall through */ }
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
  document.body.appendChild(ta);
  ta.select();
  ta.setSelectionRange(0, text.length);
  let ok = false;
  try { ok = document.execCommand('copy'); } catch { ok = false; }
  ta.remove();
  return ok;
}

function initCopyLink() {
  document.querySelectorAll('[data-copy-link]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const url = btn.dataset.copyLink || window.location.href;
      const ok = await copyText(url);
      const en = window.BAIT?.lang === 'en';
      showToast(ok ? (btn.dataset.copied || (en ? 'Link copied' : 'คัดลอกลิงก์แล้ว')) : (en ? 'Could not copy — copy the address from the address bar' : 'คัดลอกไม่สำเร็จ กรุณาคัดลอกจากแถบที่อยู่'));
    });
  });
}

/** Facebook: on phones hand the link to the system share sheet (Facebook's web
 *  sharer often drops the link and lands on the feed there); on desktop open
 *  the sharer in a small window. Without JS the plain <a href> still works. */
function initShareFacebook() {
  document.querySelectorAll('[data-share-facebook]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const url = a.dataset.shareUrl;
      const title = a.dataset.shareTitle || document.title;
      const isPhone = window.matchMedia('(pointer: coarse)').matches && window.innerWidth < 900;
      if (isPhone && navigator.share) {
        e.preventDefault();
        navigator.share({ title, text: title, url }).catch(() => { /* cancelled */ });
        return;
      }
      e.preventDefault();
      // no 'noopener' feature here: it makes window.open() return null, which looked like a blocked popup and sent this page to Facebook as well
      const w = window.open(a.href, 'fbshare', 'width=640,height=520');
      if (w) { try { w.opener = null; } catch (err) { /* cross-origin already */ } }
      else window.location.href = a.href; // really blocked
    });
  });
}

function boot() {
  run('initMourning');
  run('initThaiWrap');   // before the hero slider / reveal so they see the final markup
  run('initNav');
  run('initHeroSlider');
  run('initReveal');
  run('initLazyImages');
  run('initSearch');
  run('initCampusTabs');
  run('initFilters');
  run('initCounters');
  run('initStaffTreeScale');
  run('initExecBoard');
  run('initExecScroll');
  run('initCampusMap');
  run('initHistoryTimeline');
  run('initCardSlider');
  initCopyLink();
  initShareFacebook();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

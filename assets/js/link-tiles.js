/**
 * BAIT - Link tiles row (E-Bidding "เว็บไซต์ที่เกี่ยวข้อง", linkTiles() in components.php): one dot per screenful of
 * tiles under the scrolling row, the current one filled. A dot scrolls to its page; the row also moves on by itself
 * every 5 s (back to the start after the last page) — paused while the pointer or keyboard focus is on it or the tab is
 * hidden, stopped for good once the visitor scrolls or picks a dot, never for prefers-reduced-motion.
 * No dots when everything fits. Without JS the row still scrolls sideways. Safe on every page.
 */
export function initLinkTiles() {
  document.querySelectorAll('[data-link-tiles]').forEach((root) => {
    const track = root.querySelector('.link-tiles-track');
    const dotsWrap = root.querySelector('[data-link-tiles-dots]');
    if (!track || !dotsWrap) return;
    const th = (document.documentElement.lang || 'th') !== 'en';
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let dots = [], timer = null, paused = false, stopped = reduced, auto = false;

    // rounded up: a row 1.4 screens wide still has a second page to scroll to (2px slack for sub-pixel widths)
    const pages = () => Math.max(1, Math.ceil((track.scrollWidth - 2) / track.clientWidth));
    const current = () => {
      const max = track.scrollWidth - track.clientWidth;
      if (max <= 0) return 0;
      return Math.round((track.scrollLeft / max) * (pages() - 1));
    };
    const goTo = (i) => {
      const max = track.scrollWidth - track.clientWidth;
      track.scrollTo({ left: pages() > 1 ? (max * i) / (pages() - 1) : 0, behavior: reduced ? 'auto' : 'smooth' });
    };
    const mark = () => { const c = current(); dots.forEach((d, i) => { d.classList.toggle('is-active', i === c); d.setAttribute('aria-current', i === c ? 'true' : 'false'); }); };

    function build() {
      const n = pages();
      dotsWrap.innerHTML = '';
      dots = [];
      dotsWrap.hidden = n < 2;
      if (n < 2) { stop(); return; }
      for (let i = 0; i < n; i++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'link-tiles-dot';
        b.setAttribute('aria-label', th ? `หน้าที่ ${i + 1} จาก ${n}` : `Page ${i + 1} of ${n}`);
        b.addEventListener('click', () => { stopped = true; stop(); goTo(i); });
        dotsWrap.appendChild(b);
        dots.push(b);
      }
      mark();
      start();
    }
    function start() {
      if (stopped || timer || pages() < 2) return;
      timer = setInterval(() => {
        if (paused) return;
        auto = true;
        const c = current();
        goTo(c >= pages() - 1 ? 0 : c + 1);
      }, 5000);
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    let raf = 0;
    track.addEventListener('scroll', () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(mark); }, { passive: true });
    // the visitor moving the row themselves (finger, wheel, keys) ends the auto-advance
    ['touchstart', 'wheel', 'keydown'].forEach((ev) => track.addEventListener(ev, () => { if (!auto) { stopped = true; stop(); } }, { passive: true }));
    track.addEventListener('scrollend', () => { auto = false; });
    root.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') paused = true; });
    root.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') paused = false; });
    root.addEventListener('focusin', () => { paused = true; });
    root.addEventListener('focusout', () => { paused = false; });
    document.addEventListener('visibilitychange', () => { paused = document.hidden; });

    let rt = 0;
    window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(build, 150); });
    build();
  });
}

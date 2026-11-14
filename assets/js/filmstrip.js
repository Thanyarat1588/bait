/**
 * BAIT - Filmstrip (home page "ภาพกิจกรรมของคณะ"): one row of photo cards that scrolls sideways (swipe / trackpad /
 * keyboard on the focused row). The ‹ › buttons move one card; the bar between them shows how far along the row is.
 * Without JS the row still scrolls. Safe on every page: no-ops without [data-filmstrip].
 */
export function initFilmstrip() {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('[data-filmstrip]').forEach((fs) => {
    const track = fs.querySelector('.fs-track');
    const prev = fs.querySelector('[data-fs-prev]');
    const next = fs.querySelector('[data-fs-next]');
    const bar = fs.querySelector('.fs-progress i');
    if (!track) return;
    const step = () => { const c = track.querySelector('.fs-card'); return c ? c.getBoundingClientRect().width + 16 : track.clientWidth; };
    const paint = () => {
      const max = track.scrollWidth - track.clientWidth;
      const vis = Math.min(1, track.clientWidth / Math.max(1, track.scrollWidth));
      const p = max > 0 ? track.scrollLeft / max : 0;
      if (bar) { bar.style.width = (vis * 100) + '%'; bar.style.transform = 'translateX(' + (vis < 1 ? p * (1 / vis - 1) * 100 : 0) + '%)'; }
      if (prev) prev.disabled = track.scrollLeft < 4;
      if (next) next.disabled = max <= 4 || track.scrollLeft > max - 4;
      const nav = fs.querySelector('.fs-nav'); if (nav) nav.hidden = max <= 4;   // everything fits: no arrows
    };
    const go = (dir) => track.scrollBy({ left: dir * step(), behavior: reduced ? 'auto' : 'smooth' });
    if (prev) prev.addEventListener('click', () => go(-1));
    if (next) next.addEventListener('click', () => go(1));
    let raf = 0;
    track.addEventListener('scroll', () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(paint); }, { passive: true });
    window.addEventListener('resize', paint);
    paint();

    // Auto-advance one card every 4 s; back to the first card after the last. Pauses while the pointer is over the row,
    // while something in it has keyboard focus, for a while after a swipe, while the row is off screen or the tab is hidden.
    // Off for prefers-reduced-motion.
    if (reduced) return;
    const DELAY = 4000;
    let hover = false, visible = true, touchedAt = 0;
    const tick = () => {
      if (hover || fs.querySelector(':focus-visible') || !visible || document.hidden || Date.now() - touchedAt < 8000) return;
      const max = track.scrollWidth - track.clientWidth;
      if (max <= 4) return;
      if (track.scrollLeft > max - 4) track.scrollTo({ left: 0, behavior: 'smooth' });
      else go(1);
    };
    setInterval(tick, DELAY);
    fs.addEventListener('mouseenter', () => { hover = true; });
    fs.addEventListener('mouseleave', () => { hover = false; });
    track.addEventListener('touchstart', () => { touchedAt = Date.now(); }, { passive: true });
    [prev, next].forEach((b) => b && b.addEventListener('click', () => { touchedAt = Date.now(); }));
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((es) => { visible = es[0].isIntersecting; }, { threshold: 0.3 }).observe(fs);
    }
  });
}

/**
 * Logo marquee (home "พันธมิตรของเรา", [data-marquee]): when the logos no longer fit in one row, they glide sideways in
 * a loop instead of wrapping onto more rows. The tiles are copied once so the loop has no gap; the copies are hidden
 * from screen readers and the keyboard. Pauses on hover / keyboard focus. When they fit (or with reduced motion) the
 * row stays still and centred as before.
 */
export function initMarquee() {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('[data-marquee]').forEach((strip) => {
    const tiles = [...strip.children];
    if (reduced || tiles.length < 2) return;
    const track = document.createElement('div');
    track.className = 'pm-track';
    tiles.forEach((t) => track.appendChild(t));
    tiles.forEach((t) => {
      const c = t.cloneNode(true);
      c.classList.add('pm-clone');
      c.setAttribute('aria-hidden', 'true');
      if (c.tagName === 'A') c.tabIndex = -1;
      c.querySelectorAll('img').forEach((i) => { i.loading = 'eager'; });
      track.appendChild(c);
    });
    strip.appendChild(track);
    track.style.setProperty('--pm-dur', (tiles.length * 3) + 's');   // ~3 s per logo, same speed whatever the count
    const fit = () => {
      const one = tiles.reduce((w, t) => w + t.getBoundingClientRect().width + 16, -16);
      strip.classList.toggle('is-marquee', one > strip.clientWidth);
    };
    window.addEventListener('resize', fit);
    fit();
  });
}

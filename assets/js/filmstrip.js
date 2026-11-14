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
  });
}

/**
 * Card slider (about.php: พันธกิจ / ค่านิยม) — one point at a time, fading between them, with ← →
 * buttons, left/right arrow keys and swipe. Without JS every point is simply listed one under the
 * other. Safe to load on every page; no-ops without [data-card-slider].
 */
export function initCardSlider() {
  document.querySelectorAll('[data-card-slider]').forEach((root) => {
    const slides = [...root.querySelectorAll('[data-cs-slide]')];
    if (slides.length < 2) return;
    root.classList.add('is-ready');
    let idx = Math.max(0, slides.findIndex((s) => s.classList.contains('is-active')));

    function show(i, dir) {
      if (dir) root.style.setProperty('--cs-dx', (dir > 0 ? 36 : -36) + 'px');
      idx = (i + slides.length) % slides.length;
      slides.forEach((s, k) => {
        const on = k === idx;
        s.classList.toggle('is-active', on);
        s.setAttribute('aria-hidden', on ? 'false' : 'true');
        if (!on) s.setAttribute('inert', ''); else s.removeAttribute('inert');
      });
    }

    // Optional auto-advance (data-cs-autoplay = seconds). It pauses while the pointer or keyboard focus is
    // on the card or the tab is hidden, never runs for people who asked for reduced motion, and stops for
    // good once the visitor uses the buttons / keys / swipe themselves.
    const secs = parseInt(root.dataset.csAutoplay || '0', 10);
    const canAuto = secs > 0 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let paused = false, stopped = false, timer = null;
    const stopAuto = () => { stopped = true; clearInterval(timer); };
    if (canAuto) {
      timer = setInterval(() => { if (!paused && !stopped && !document.hidden) show(idx + 1, 1); }, secs * 1000);
      root.addEventListener('mouseenter', () => { paused = true; });
      root.addEventListener('mouseleave', () => { paused = false; });
      root.addEventListener('focusin', () => { paused = true; });
      root.addEventListener('focusout', () => { paused = false; });
    }

    root.querySelector('[data-cs-prev]')?.addEventListener('click', () => { stopAuto(); show(idx - 1, -1); });
    root.querySelector('[data-cs-next]')?.addEventListener('click', () => { stopAuto(); show(idx + 1, 1); });
    root.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') { stopAuto(); show(idx - 1, -1); e.preventDefault(); }
      else if (e.key === 'ArrowRight') { stopAuto(); show(idx + 1, 1); e.preventDefault(); }
    });

    // swipe on touch screens (horizontal gestures only, so vertical scrolling is untouched)
    const card = root.querySelector('.cs-card') || root;
    let x0 = null, y0 = null;
    card.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
    card.addEventListener('touchend', (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
      x0 = y0 = null;
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.5) { stopAuto(); show(idx + (dx < 0 ? 1 : -1), dx < 0 ? 1 : -1); }
    }, { passive: true });

    show(idx);
  });
}
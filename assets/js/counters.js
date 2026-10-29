/**
 * BAIT - Animated count-up for stat numbers (e.g. "1,234 students").
 * Starts once the element scrolls into view; skipped for reduced motion
 * (final value is rendered immediately instead).
 */
export function initCounters() {
  const els = document.querySelectorAll('[data-count]');
  if (!els.length) return;

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const format = (n) => n.toLocaleString('th-TH');

  function animate(el, target) {
    if (reduced) { el.textContent = format(target); return; }
    const duration = 1400;
    let start = null;
    function step(ts) {
      if (start === null) start = ts;
      const progress = Math.min((ts - start) / duration, 1);
      el.textContent = format(Math.floor(progress * target));
      if (progress < 1) requestAnimationFrame(step);
      else el.textContent = format(target);
    }
    requestAnimationFrame(step);
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      const target = parseInt(el.dataset.count || el.textContent.replace(/,/g, ''), 10) || 0;
      animate(el, target);
      observer.unobserve(el);
    });
  }, { threshold: 0.5 });

  els.forEach((el) => observer.observe(el));
}

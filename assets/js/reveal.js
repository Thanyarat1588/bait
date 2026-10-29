/**
 * BAIT - Scroll-reveal via IntersectionObserver. Elements start hidden
 * (see .reveal in base.css) and fade/slide in once, with a small stagger
 * for siblings inside the same grid. Fully skipped for reduced motion
 * (base.css already renders .reveal at full opacity in that case).
 */
export function initReveal() {
  const items = document.querySelectorAll('.reveal');
  if (!items.length) return;

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
    items.forEach((el) => el.classList.add('is-visible'));
    return;
  }

  // Stagger siblings that reveal together (e.g. a card grid) by index.
  const groups = new Map();
  items.forEach((el) => {
    const parent = el.parentElement;
    if (!groups.has(parent)) groups.set(parent, []);
    groups.get(parent).push(el);
  });
  groups.forEach((siblings) => {
    siblings.forEach((el, i) => {
      el.style.transitionDelay = `${Math.min(i, 6) * 60}ms`;
    });
  });

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

  items.forEach((el) => {
    // Opt in to the hide-then-fade only once the observer is actually
    // watching this element — see base.css: without .is-revealing the
    // element is just visible, so a callback that never fires (or fires
    // late) can no longer leave it permanently blank.
    el.classList.add('is-revealing');
    observer.observe(el);
  });
}

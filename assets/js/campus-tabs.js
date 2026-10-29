/**
 * BAIT - Campus page sticky tab nav: smooth-scroll to section + scroll-spy
 * active state. Only runs when the campus tab markup is present.
 */
export function initCampusTabs() {
  const tabs = document.querySelectorAll('[data-campus-tab]');
  if (!tabs.length) return;

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  tabs.forEach((tab) => {
    tab.addEventListener('click', (e) => {
      e.preventDefault();
      const target = document.getElementById(tab.dataset.campusTab);
      if (!target) return;
      const offset = target.getBoundingClientRect().top + window.scrollY - 130;
      window.scrollTo({ top: offset, behavior: reduced ? 'auto' : 'smooth' });
    });
  });

  const sections = document.querySelectorAll('[data-campus-section]');
  const setActive = () => {
    let current = '';
    sections.forEach((s) => { if (window.scrollY >= s.offsetTop - 170) current = s.id; });
    tabs.forEach((t) => {
      const on = t.dataset.campusTab === current;
      t.classList.toggle('is-active', on);
      if (on) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
    });
  };
  window.addEventListener('scroll', setActive, { passive: true });
  setActive();

  // Incoming links with a #campus-N hash (e.g. from the statistics charts)
  // rely on the browser's native jump-to-anchor on load, but that's
  // unreliable here because `html { scroll-behavior: smooth }` (base.css)
  // races with this page's own reveal-on-scroll layout shifts — the smooth
  // scroll gets cancelled mid-flight and the page silently stays at the
  // top. Do the scroll ourselves instead, once layout has settled.
  if (location.hash.startsWith('#campus-')) {
    const target = document.getElementById(location.hash.slice(1));
    if (target) {
      // This module is dynamically imported, so `window`'s `load` event
      // may already have fired by the time we get here — a `load`
      // listener would then never run. Scroll directly instead, after
      // giving layout a tick to settle.
      setTimeout(() => {
        const offset = target.getBoundingClientRect().top + window.scrollY - 130;
        window.scrollTo({ top: offset, behavior: reduced ? 'auto' : 'smooth' });
      }, 60);
    }
  }
}

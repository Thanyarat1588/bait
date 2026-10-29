/**
 * BAIT - Navbar behaviour: sticky shrink, mobile offcanvas, mega-menu
 * keyboard support. No dependencies.
 */
export function initNav() {
  const navbar = document.querySelector('.navbar');
  if (navbar) {
    const onScroll = () => navbar.classList.toggle('is-scrolled', window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  // ---- Mobile offcanvas ----
  const toggle   = document.querySelector('[data-nav-toggle]');
  const offcanvas = document.querySelector('.offcanvas-nav');
  const backdrop  = document.querySelector('.offcanvas-backdrop');
  const closeBtn  = document.querySelector('[data-nav-close]');
  let lastFocused = null;

  function openOffcanvas() {
    lastFocused = document.activeElement;
    offcanvas.classList.add('is-open');
    backdrop.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    const firstLink = offcanvas.querySelector('a, button, summary');
    if (firstLink) firstLink.focus();
  }
  function closeOffcanvas() {
    offcanvas.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    if (lastFocused) lastFocused.focus();
  }
  if (toggle && offcanvas) {
    toggle.addEventListener('click', () => {
      offcanvas.classList.contains('is-open') ? closeOffcanvas() : openOffcanvas();
    });
    closeBtn?.addEventListener('click', closeOffcanvas);
    backdrop?.addEventListener('click', closeOffcanvas);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && offcanvas.classList.contains('is-open')) closeOffcanvas();
    });
    // accordions in the phone menu: opening one closes the one that was open (the toggle event does not bubble, hence capture)
    const accordions = [...offcanvas.querySelectorAll('details.offcanvas-accordion')];
    const closeAccordions = (except) => accordions.forEach((d) => { if (d !== except) d.open = false; });
    offcanvas.addEventListener('toggle', (e) => { if (e.target.open) closeAccordions(e.target); }, true);
    // tapping a plain menu entry also folds whatever was open
    offcanvas.querySelectorAll(':scope > ul > li > a.nav-link').forEach((a) => a.addEventListener('click', () => closeAccordions(null)));
    // basic focus trap
    offcanvas.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;
      const focusables = offcanvas.querySelectorAll('a, button, summary, input');
      if (!focusables.length) return;
      const first = focusables[0], last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  // ---- Mega menu: click-to-toggle for touch/keyboard users
  // (desktop hover/focus-within is handled purely in CSS) ----
  // Tell screen readers that these links open a sub-menu and whether it is open right now.
  document.querySelectorAll('.has-mega').forEach((li) => {
    const t = li.querySelector(':scope > .nav-link');
    if (!t) return;
    t.setAttribute('aria-haspopup', 'true');
    const sync = () => t.setAttribute('aria-expanded', (li.classList.contains('is-open') || li.matches(':hover') || li.matches(':focus-within')) ? 'true' : 'false');
    ['mouseenter', 'mouseleave', 'focusin', 'focusout'].forEach((ev) => li.addEventListener(ev, () => setTimeout(sync, 0)));
    new MutationObserver(sync).observe(li, { attributes: true, attributeFilter: ['class'] });
    sync();
  });

  // With a mouse, the menu under the pointer is the only one that may stay open. Pointing at another menu closes the
  // previous one at once: it used to stay open because its link still had keyboard focus after a click (":focus-within"),
  // and it also faded out slowly while the new one faded in.
  const megas = Array.from(document.querySelectorAll('.has-mega'));
  if (window.matchMedia('(hover: hover)').matches) {
    megas.forEach((li) => {
      li.addEventListener('mouseenter', () => {
        li.classList.remove('is-yielding');
        megas.forEach((other) => {
          if (other === li) return;
          other.classList.remove('is-open');
          other.classList.add('is-yielding');                       // hidden instantly (see .has-mega.is-yielding in components.css)
        });
        const active = document.activeElement;
        const owner = active && active !== document.body ? active.closest('.has-mega') : null;
        if (owner && owner !== li) active.blur();                    // drop the keyboard focus that would keep the old menu open
      });
    });
    const navList = document.querySelector('.nav-links');
    if (navList) navList.addEventListener('mouseleave', () => megas.forEach((m) => m.classList.remove('is-yielding')));
  }
  document.querySelectorAll('.has-mega > .nav-link').forEach((trigger) => {
    trigger.addEventListener('click', (e) => {
      if (window.matchMedia('(hover: hover)').matches) return; // let CSS hover handle it
      e.preventDefault();
      const parent = trigger.closest('.has-mega');
      const isOpen = parent.classList.contains('is-open');
      document.querySelectorAll('.has-mega.is-open').forEach((el) => el.classList.remove('is-open'));
      if (!isOpen) parent.classList.add('is-open');
    });
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.has-mega')) {
      document.querySelectorAll('.has-mega.is-open').forEach((el) => el.classList.remove('is-open'));
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.has-mega.is-open').forEach((el) => el.classList.remove('is-open'));
    }
  });

  // ---- Back to top ----
  const backToTop = document.querySelector('.back-to-top');
  const baitrusFloat = document.querySelector('.baitrus-float');
  if (backToTop) {
    window.addEventListener('scroll', () => {
      const scrolled = window.scrollY > 500;
      backToTop.classList.toggle('is-visible', scrolled);
      if (baitrusFloat) baitrusFloat.classList.toggle('is-shifted', scrolled);
    }, { passive: true });
    backToTop.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    });
  }
}

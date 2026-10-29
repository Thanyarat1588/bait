/**
 * Horizontal card carousel (e.g. the "ผู้บริหาร" row on about.php) — a
 * flex row that scrolls natively (touch/trackpad swipe works on its own),
 * plus <  > buttons that step by one card width. Safe to load on every
 * page; no-ops when no .exec-scroll-wrap is present.
 */
export function initExecScroll() {
  document.querySelectorAll('.exec-scroll-wrap').forEach((wrap) => {
    const track = wrap.querySelector('.exec-scroll');
    const prev = wrap.querySelector('[data-exec-scroll="prev"]');
    const next = wrap.querySelector('[data-exec-scroll="next"]');
    if (!track) return;

    const step = (dir) => {
      const card = track.querySelector(':scope > *');
      const gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap || '0');
      const amount = card ? card.getBoundingClientRect().width + gap : track.clientWidth * 0.8;
      track.scrollBy({ left: dir * amount, behavior: 'smooth' });
    };
    prev?.addEventListener('click', () => step(-1));
    next?.addEventListener('click', () => step(1));

    const updateButtons = () => {
      const max = track.scrollWidth - track.clientWidth;
      if (prev) prev.disabled = track.scrollLeft <= 4;
      if (next) next.disabled = track.scrollLeft >= max - 4;
      wrap.classList.toggle('is-scrollable', max > 4);
    };
    track.addEventListener('scroll', updateButtons, { passive: true });
    window.addEventListener('resize', updateButtons);
    updateButtons();
  });
}

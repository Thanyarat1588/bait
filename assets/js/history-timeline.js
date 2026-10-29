/**
 * Horizontal timeline (ประวัติคณะ on about.php) — years sit on a line;
 * tapping one (or the < > buttons) swaps the panel above it. Without JS every
 * panel simply shows stacked. No-ops without [data-timeline].
 */
export function initHistoryTimeline() {
  document.querySelectorAll('[data-timeline]').forEach((root) => {
    const panels = [...root.querySelectorAll('[data-tl-panel]')];
    const btns = [...root.querySelectorAll('[data-tl-btn]')];
    const track = root.querySelector('[data-tl-track]');
    if (!panels.length || panels.length !== btns.length) return;

    root.classList.add('is-ready');
    let idx = panels.length - 1;

    function select(i, slide) {
      const prev = idx;
      idx = (i + panels.length) % panels.length;
      panels.forEach((p, k) => {
        p.hidden = k !== idx;
        p.classList.remove('is-from-right', 'is-from-left');
        if (k === idx && slide && idx !== prev) p.classList.add(slide === 'left' ? 'is-from-left' : slide === 'right' ? 'is-from-right' : idx > prev ? 'is-from-right' : 'is-from-left');
      });
      btns.forEach((b, k) => {
        b.classList.toggle('is-active', k === idx);
        if (k === idx) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
      });
      const li = btns[idx].parentElement;
      track.scrollTo({ left: li.offsetLeft - track.clientWidth / 2 + li.offsetWidth / 2, behavior: 'smooth' });
    }

    btns.forEach((b, k) => b.addEventListener('click', () => select(k, true)));
    root.querySelector('[data-tl-prev]')?.addEventListener('click', () => select(idx - 1, 'left'));
    root.querySelector('[data-tl-next]')?.addEventListener('click', () => select(idx + 1, 'right'));
    select(idx);
  });
}

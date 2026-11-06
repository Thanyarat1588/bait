/**
 * Full-size picture viewer: a [data-zoom] button (a banner or pop-up picture that has no link) opens its picture
 * over the page. Closes with the × button, a tap beside the picture or Escape, and gives focus back to the button.
 * Escape / Tab are caught before the pop-up's own handlers, so closing the picture does not close the pop-up too.
 * No-ops without [data-zoom].
 */
export function initImageViewer() {
  if (!document.querySelector('[data-zoom]')) return;
  const th = (document.documentElement.lang || 'th') !== 'en';
  let viewer = null, opener = null;

  function close() {
    if (!viewer) return;
    viewer.remove(); viewer = null;
    document.documentElement.classList.remove('has-img-viewer');
    document.removeEventListener('keydown', onKey, true);
    if (opener && opener.focus) opener.focus();
  }
  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
    else if (e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); viewer.querySelector('button').focus(); }   // the close button is the only control
  }
  function open(btn) {
    opener = btn;
    const img = btn.querySelector('img');
    viewer = document.createElement('div');
    viewer.className = 'img-viewer';
    viewer.setAttribute('role', 'dialog');
    viewer.setAttribute('aria-modal', 'true');
    viewer.setAttribute('aria-label', img?.alt || (th ? 'ภาพขนาดใหญ่' : 'Full-size picture'));
    const big = document.createElement('img');
    big.src = btn.dataset.zoom;
    big.alt = img?.alt || '';
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'img-viewer-close';
    x.setAttribute('aria-label', th ? 'ปิด' : 'Close');
    x.textContent = '×';
    viewer.append(big, x);
    viewer.addEventListener('click', (e) => { if (e.target !== big) close(); });
    document.body.appendChild(viewer);
    document.documentElement.classList.add('has-img-viewer');
    document.addEventListener('keydown', onKey, true);
    x.focus();
  }
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-zoom]');
    if (btn) { e.preventDefault(); open(btn); }
  });
}

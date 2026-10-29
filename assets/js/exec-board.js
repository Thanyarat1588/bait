/**
 * Executive org chart (ผังผู้บริหาร) — collapsible .ex-unit toggles + live
 * search across the whole tree (matches by name or role, auto-expands any
 * unit on the path to a match). Safe to load on every page; no-ops when
 * .ex-board isn't present.
 */
export function initExecBoard() {
  const board = document.querySelector('.ex-board');
  if (!board) return;

  board.addEventListener('click', (e) => {
    const toggle = e.target.closest('.ex-unit__toggle');
    if (!toggle || toggle.tagName !== 'BUTTON') return;
    const body = document.getElementById(toggle.getAttribute('aria-controls'));
    const open = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!open));
    if (body) body.hidden = open;
  });

  const input = board.querySelector('[data-exec-search]');
  if (!input) return;
  const emptyState = board.querySelector('.ex-empty');

  function matchUnit(unitEl, q) {
    const toggle = unitEl.querySelector(':scope > .ex-unit__toggle');
    const own = toggle ? (toggle.dataset.search || '').includes(q) : false;
    const body = unitEl.querySelector(':scope > .ex-unit__body');
    let childMatch = false;
    if (body) {
      body.querySelectorAll(':scope > .ex-units > .ex-unit').forEach((child) => {
        if (matchUnit(child, q)) childMatch = true;
      });
    }
    const visible = !q || own || childMatch;
    unitEl.hidden = !visible;
    if (toggle && toggle.tagName === 'BUTTON' && body) {
      const openNow = !q || own || childMatch;
      toggle.setAttribute('aria-expanded', String(openNow));
      body.hidden = !openNow;
    }
    return visible;
  }

  let timer;
  input.addEventListener('input', (e) => {
    clearTimeout(timer);
    const value = e.target.value;
    timer = setTimeout(() => {
      const q = value.trim().toLowerCase();
      let anyVisible = false;
      board.querySelectorAll('.ex-units.is-top > .ex-unit').forEach((unit) => {
        if (matchUnit(unit, q)) anyVisible = true;
      });
      if (emptyState) emptyState.hidden = !(q && !anyVisible);
    }, 120);
  });
}

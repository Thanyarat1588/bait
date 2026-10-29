/**
 * Interactive campus map (campuses.php) — tapping a pin (or a name in the
 * list beside it) opens a small card anchored to that pin, like a Google
 * Maps info window: campus photo, name, an "open map" button (the campus's
 * own map link) and a button to the full campus-detail page. Pins/list are
 * real links, so with JS off they lead straight to campus-detail. Safe on
 * every page; no-ops without [data-campus-map].
 */
export function initCampusMap() {
  const root = document.querySelector('[data-campus-map]');
  if (!root) return;

  let payload;
  try { payload = JSON.parse(root.dataset.campusMap); } catch { return; }
  const { campuses, labels } = payload;
  if (!campuses || !campuses.length) return;

  const stage = root.querySelector('[data-cmap-stage]');
  const pins = root.querySelectorAll('.cmap-pin');
  const items = root.querySelectorAll('.cmap-list a');
  let pop = null;
  let openId = null;

  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };

  function close() {
    if (pop) { pop.remove(); pop = null; }
    openId = null;
    pins.forEach((p) => p.classList.remove('is-active'));
    items.forEach((a) => a.classList.remove('is-active'));
  }

  function place() {
    if (!pop) return;
    const pin = [...pins].find((p) => +p.dataset.campusId === openId);
    if (!pin) return;
    const sw = stage.clientWidth, sh = stage.clientHeight;
    const px = pin.offsetLeft, py = pin.offsetTop;
    const cw = pop.offsetWidth, ch = pop.offsetHeight;
    const gap = 26;
    const above = py - gap - ch >= 8;
    let left = Math.min(Math.max(px - cw / 2, 8), sw - cw - 8);
    let top = above ? py - gap - ch : Math.min(py + gap, sh - ch - 8);
    pop.style.left = left + 'px';
    pop.style.top = Math.max(top, 8) + 'px';
    pop.classList.toggle('is-below', !above);
    pop.style.setProperty('--arrow-x', Math.min(Math.max(px - left, 18), cw - 18) + 'px');
  }

  function open(id) {
    const c = campuses.find((x) => x.id === id);
    if (!c) return;
    close();
    openId = id;
    pins.forEach((p) => p.classList.toggle('is-active', +p.dataset.campusId === id));
    items.forEach((a) => a.classList.toggle('is-active', +a.dataset.campusId === id));

    pop = el('div', 'cmap-pop');
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', c.name);

    if (c.image) {
      const img = el('img', 'cmap-pop-photo');
      img.src = c.image; img.alt = c.name; img.loading = 'lazy';
      if (c.ratio) img.style.aspectRatio = c.ratio;
      img.addEventListener('error', () => { img.remove(); place(); });
      img.addEventListener('load', place);
      pop.append(img);
    }

    const x = el('button', 'cmap-pop-close'); x.type = 'button'; x.setAttribute('aria-label', labels.close); x.textContent = '×';
    x.addEventListener('click', close);
    pop.append(x);

    pop.append(el('h3', 'cmap-pop-name', c.name));

    const actions = el('div', 'cmap-pop-actions');
    const detail = el('a', 'btn btn-primary btn-sm', labels.detail);
    detail.href = c.detail;
    actions.append(detail);
    if (c.map) {
      const m = el('a', 'btn btn-secondary btn-sm'); m.href = c.map; m.target = '_blank'; m.rel = 'noopener';
      m.innerHTML = '<svg class="icon icon-sm" aria-hidden="true" focusable="false"><use href="#icon-pin"></use></svg> ';
      m.append(document.createTextNode(labels.map));
      actions.append(m);
    }
    pop.append(actions);

    stage.append(pop);
    place();
  }

  [...pins, ...items].forEach((node) => {
    node.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const id = +node.dataset.campusId;
      id === openId ? close() : open(id);
    });
  });
  stage.addEventListener('click', (e) => { if (!e.target.closest('.cmap-pop, .cmap-pin')) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  window.addEventListener('resize', place);
}

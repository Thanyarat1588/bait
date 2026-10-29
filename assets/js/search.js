/**
 * BAIT - Live search suggestions. Talks to search.php?ajax=1&q=... which
 * returns JSON grouped by content type. Debounced, abortable, keyboard
 * navigable. Degrades gracefully: with JS disabled the same input just
 * submits its form to search.php as a normal GET request.
 */
function debounce(fn, delay) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

// Works on the plain text and escapes each piece itself, so a search word can never land inside an entity such as &amp;.
function highlight(text, query) {
  text = String(text);
  if (!query) return esc(text);
  const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return text.split(new RegExp(`(${escaped})`, 'ig'))
    .map((part, i) => (i % 2 ? `<mark>${esc(part)}</mark>` : esc(part)))
    .join('');
}

const TEXT = {
  th: { hint: 'พิมพ์อย่างน้อย 2 ตัวอักษร', loading: 'กำลังค้นหา…', error: 'ค้นหาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', none: 'ไม่พบผลลัพธ์' },
  en: { hint: 'Type at least 2 characters', loading: 'Searching…', error: 'Search failed. Please try again.', none: 'No results found' },
};
const say = (key) => (TEXT[window.BAIT?.lang === 'en' ? 'en' : 'th'])[key];
const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

class SearchBox {
  constructor(panel) {
    this.panel  = panel;
    this.input  = panel.querySelector('[data-search-input]');
    this.results = panel.querySelector('[data-search-results]');
    if (!this.input || !this.results) return;

    this.controller = null;
    this.activeIndex = -1;
    this.results.setAttribute('aria-live', 'polite');   // screen readers hear the status / result text

    this.input.addEventListener('input', debounce(() => this.query(), 250));
    this.input.addEventListener('keydown', (e) => this.onKeydown(e));
    document.addEventListener('click', (e) => {
      if (!panel.contains(e.target)) this.close();
    });
  }

  async query() {
    const q = this.input.value.trim();
    if (q.length < 2) {
      this.controller?.abort();
      if (q.length === 1) this.note('hint'); else this.close();   // one character: tell the visitor why nothing appears
      return;
    }

    this.controller?.abort();
    this.controller = new AbortController();
    if (!this.results.querySelector('.suggest-item')) this.note('loading');

    try {
      const base = window.BAIT?.baseUrl || '';
      const res = await fetch(`${base}/search.php?ajax=1&q=${encodeURIComponent(q)}`, {
        signal: this.controller.signal,
        headers: { 'X-Requested-With': 'fetch' },
      });
      if (!res.ok) throw new Error('search request failed');
      const data = await res.json();
      this.render(data, q);
    } catch (err) {
      if (err.name !== 'AbortError') this.note('error');
    }
  }

  render(groups, q) {
    this.activeIndex = -1;
    const nonEmpty = groups.filter((g) => g.items.length);
    if (!nonEmpty.length) {
      this.note('none');
      return;
    }
    this.results.innerHTML = nonEmpty.map((g) => `
      <div class="suggest-group-label">${esc(g.label)}</div>
      ${g.items.map((item) => `
        <a class="suggest-item" href="${esc(item.url)}" role="option">${highlight(item.title, q)}</a>
      `).join('')}
    `).join('');
    this.open();
  }

  note(key) { this.results.innerHTML = `<div class="suggest-empty">${say(key)}</div>`; this.open(); }
  open()  { this.results.classList.add('is-open'); }
  close() { this.results.classList.remove('is-open'); this.results.innerHTML = ''; }

  onKeydown(e) {
    const items = Array.from(this.results.querySelectorAll('.suggest-item'));
    if (e.key === 'Escape') { this.close(); return; }
    if (e.key === 'Enter' && this.activeIndex >= 0 && items[this.activeIndex]) {
      e.preventDefault();
      window.location.href = items[this.activeIndex].href;
      return;
    }
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      this.activeIndex = Math.min(this.activeIndex + 1, items.length - 1);
      this.highlightActive(items);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      this.activeIndex = Math.max(this.activeIndex - 1, 0);
      this.highlightActive(items);
    }
  }

  highlightActive(items) {
    items.forEach((el, i) => el.classList.toggle('is-active', i === this.activeIndex));
    items[this.activeIndex]?.scrollIntoView({ block: 'nearest' });
  }
}

export function initSearch() {
  document.querySelectorAll('[data-search-panel]').forEach((panel) => new SearchBox(panel));

  // Navbar search icon toggles an inline search panel on smaller layouts.
  const navSearchToggle = document.querySelector('[data-search-toggle]');
  const navSearchPanel  = document.querySelector('[data-nav-search-panel]');
  navSearchToggle?.addEventListener('click', () => {
    navSearchPanel?.classList.toggle('is-open');
    if (navSearchPanel?.classList.contains('is-open')) {
      navSearchPanel.querySelector('input')?.focus();
    }
  });
}

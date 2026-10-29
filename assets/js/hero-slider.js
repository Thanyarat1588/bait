/**
 * BAIT - Accessible hero slider. Autoplay pauses on hover/focus/tab-blur
 * and is skipped entirely for prefers-reduced-motion. No dependencies.
 */
export function initHeroSlider() {
  const root = document.querySelector('[data-hero-slider]');
  if (!root) return;

  const slides = Array.from(root.querySelectorAll('.hero-slide'));
  if (slides.length <= 1) return; // nothing to animate

  const dotsWrap = root.querySelector('[data-hero-dots]');
  const prevBtn  = root.querySelector('[data-hero-prev]');
  const nextBtn  = root.querySelector('[data-hero-next]');
  const pauseBtn = root.querySelector('[data-hero-pause]');
  const interval = Number(root.dataset.interval || 6000);
  const reduced  = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let index = slides.findIndex((s) => s.classList.contains('is-active'));
  if (index < 0) index = 0;
  let timer = null;
  let playing = !reduced;
  let userPaused = false;   // the visitor pressed the pause button: hover / focus / tab changes must not start it again (WCAG 2.2.2)
  const th = (document.documentElement.lang || 'th') !== 'en';
  const L = {
    slide: (i, n) => (th ? `ไปยังภาพที่ ${i} จาก ${n}` : `Go to slide ${i} of ${n}`),
    pause: th ? 'หยุดสไลด์' : 'Pause slideshow',
    play:  th ? 'เล่นสไลด์' : 'Play slideshow',
  };

  const dots = slides.map((_, i) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'hero-dot' + (i === index ? ' is-active' : '');
    btn.setAttribute('aria-label', L.slide(i + 1, slides.length));
    btn.addEventListener('click', () => goTo(i));
    dotsWrap?.appendChild(btn);
    return btn;
  });

  function render() {
    slides.forEach((s, i) => s.classList.toggle('is-active', i === index));
    dots.forEach((d, i) => d.classList.toggle('is-active', i === index));
  }
  function goTo(i) { index = (i + slides.length) % slides.length; render(); }
  function next() { goTo(index + 1); }
  function prev() { goTo(index - 1); }

  function play() {
    if (reduced) return;
    stop();
    timer = window.setInterval(next, interval);
    playing = true;
    if (pauseBtn) pauseBtn.setAttribute('aria-label', L.pause);
  }
  function stop() {
    if (timer) { window.clearInterval(timer); timer = null; }
    playing = false;
    if (pauseBtn) pauseBtn.setAttribute('aria-label', L.play);
  }

  const resume = () => { if (!reduced && !userPaused) play(); };
  prevBtn?.addEventListener('click', () => { prev(); resume(); });
  nextBtn?.addEventListener('click', () => { next(); resume(); });
  pauseBtn?.addEventListener('click', () => { if (playing) { userPaused = true; stop(); } else { userPaused = false; play(); } });

  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', resume);
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', resume);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop(); else resume();
  });

  render();
  play();
}

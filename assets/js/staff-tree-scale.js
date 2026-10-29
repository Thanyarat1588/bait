/**
 * BAIT - Executive org-chart scale-to-fit (staff.php, view=executive).
 * "Spread across the screen" cuts both ways:
 * - Too WIDE for the viewport (a big unfiltered tree): shrink the whole
 *   chart with transform:scale until it fits, since the hierarchy (parent
 *   card + connector lines + sibling cards) can't reflow into a narrower
 *   column without breaking the parent/child visual relationship. Stops
 *   at MIN_SCALE rather than shrinking indefinitely — an institution-wide
 *   tree could otherwise scale down to unreadably small text — and
 *   whatever's left past that floor still scrolls (.staff-tree-scroll
 *   keeps overflow-x:auto as the no-js fallback too).
 * - Too NARROW (a handful of direct reports on a wide screen): with no
 *   width rule pushing the other way, the tree used to just render at
 *   its own shrink-to-fit size, leaving it small and centered with dead
 *   space on both sides — nowhere near "fills the screen". Stretching
 *   the wrapper to the viewport's available width instead lets the CSS
 *   (.staff-tree-children's width:100%/space-between) push the top-level
 *   siblings out to actually use that width.
 */
const MIN_SCALE = 0.55;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const peeked = new WeakSet();

/**
 * A visible scrollbar alone is easy to miss on a touch screen — nobody
 * reaches for a trackpad-era scrollbar with a thumb. So the one time a
 * chart still doesn't fit even at MIN_SCALE, it nudges itself right and
 * back a couple of times right after load, with the arrow hints fading in
 * for the same stretch, so the swipe affordance is *seen* once instead of
 * discovered by accident. Runs once per element (guarded by `peeked`) and
 * never for prefers-reduced-motion.
 */
function peekScroll(scrollEl) {
  if (reducedMotion || peeked.has(scrollEl)) return;
  const overflow = scrollEl.scrollWidth - scrollEl.clientWidth;
  if (overflow < 8) return;
  peeked.add(scrollEl);

  const hints = [
    scrollEl.querySelector(':scope > .staff-scroll-hint-left'),
    scrollEl.querySelector(':scope > .staff-scroll-hint-right'),
  ].filter(Boolean);
  if (!hints.length) return;

  const peekAmount = Math.min(60, overflow);
  const steps = [peekAmount, 0, peekAmount, 0];
  let i = 0;

  function advance() {
    if (i >= steps.length) {
      hints.forEach((h) => h.classList.remove('is-visible'));
      return;
    }
    scrollEl.scrollTo({ left: steps[i], behavior: 'smooth' });
    i += 1;
    setTimeout(advance, 650);
  }

  setTimeout(() => {
    hints.forEach((h) => h.classList.add('is-visible'));
    advance();
  }, 500);
}

export function initStaffTreeScale() {
  const trees = document.querySelectorAll('.staff-tree-scroll > ul.staff-tree');
  if (!trees.length) return;

  const wraps = Array.from(trees).map((tree) => {
    const wrap = document.createElement('div');
    wrap.className = 'staff-tree-scale-wrap';
    tree.parentNode.insertBefore(wrap, tree);
    wrap.appendChild(tree);
    return { scrollEl: wrap.parentElement, wrap, tree };
  });

  function fit() {
    wraps.forEach(({ scrollEl, wrap, tree }) => {
      const childrenRow = tree.querySelector(':scope > li > .staff-tree-children');
      wrap.style.width = '';
      wrap.style.height = '';
      tree.style.transform = '';
      // .staff-tree-children's CSS width:100% would otherwise stretch it
      // to match whatever width its ancestor already happens to have,
      // rather than reporting its own natural (shrink-to-fit) size —
      // pin both back to their intrinsic content size before measuring.
      tree.style.width = 'max-content';
      if (childrenRow) childrenRow.style.width = 'max-content';
      const naturalWidth = tree.scrollWidth;
      const available = scrollEl.clientWidth;

      if (naturalWidth > available) {
        // Too wide: shrink the whole thing uniformly, so it keeps
        // sizing off its true natural width (childrenRow stays pinned).
        const naturalHeight = tree.scrollHeight;
        const scale = Math.max(MIN_SCALE, available / naturalWidth);
        tree.style.transformOrigin = 'top left';
        tree.style.transform = `scale(${scale})`;
        wrap.style.width = Math.ceil(naturalWidth * scale) + 'px';
        wrap.style.height = Math.ceil(naturalHeight * scale) + 'px';
      } else {
        // Room to spare: let the CSS width:100%/space-between rule take
        // back over so the top-level siblings actually spread out to
        // fill the available width instead of staying their small,
        // centered, natural size.
        tree.style.width = '100%';
        if (childrenRow) childrenRow.style.width = '';
        wrap.style.width = available + 'px';
        wrap.style.height = tree.scrollHeight + 'px';
      }
      peekScroll(scrollEl);
    });
  }

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(fit, 150);
  });

  if (document.fonts?.ready) { document.fonts.ready.then(fit); }
  fit();
}

/**
 * Smarter line breaking for Thai copy (headings, intro lines, promo text).
 *
 * Thai has no spaces between words, so a browser breaks wherever its dictionary says a word ends, which on a phone often
 * strands a single word ("...ที่ / ยั่งยืน", "...ของ / โลก") or splits a phrase that reads as one thought. Two rules fix most of it:
 *  1. A phrase the author separated with a space stays together on one line (each one is wrapped in a .ph inline-block;
 *     if it is longer than the line it still wraps inside itself, it never overflows).
 *  2. Linking words (และ, หรือ, ของ, ที่, เพื่อ, ใน, ...) are glued to the word that follows them with a word-joiner,
 *     so a line never ends on one of them and a line may start with "และ..." instead.
 * Only plain text nodes inside the listed containers are touched; English text and everything else is left alone.
 */
const TARGETS = '.promo-text, .hero-content, .section-header, .page-hero, .stmt-text, .tu-name';
const SKIP = 'a.btn, .btn, button, script, style, textarea, [data-no-phrase], .hero-ctas, .ph, .tu-camp';
const THAI = /[฀-๿]/;
const WJ = '⁠';
const GLUE_NEXT = new Set([
  'และ', 'หรือ', 'ของ', 'ที่', 'ซึ่ง', 'อัน', 'เพื่อ', 'ใน', 'แก่', 'กับ', 'โดย', 'ต่อ', 'จาก', 'ถึง', 'ตาม', 'ด้วย', 'แห่ง', 'ให้', 'เป็น', 'ยัง', 'ได้รับ', 'ผ่าน', 'สู่', 'ภายใน', 'ระหว่าง', 'การ', 'ความ', 'ผู้',
]);

let segmenter = null;
try { if (typeof Intl !== 'undefined' && Intl.Segmenter) segmenter = new Intl.Segmenter('th', { granularity: 'word' }); } catch (e) { segmenter = null; }

/** Glue linking words to the following word inside one space-free chunk of Thai. */
function glue(chunk) {
  if (!segmenter) return chunk;
  const words = Array.from(segmenter.segment(chunk), (s) => s.segment);
  if (words.length < 2) return chunk;
  let out = '';
  for (let i = 0; i < words.length; i++) {
    out += words[i];
    if (i < words.length - 1 && GLUE_NEXT.has(words[i])) out += WJ;
  }
  return out;
}

function wrapTextNode(node) {
  const text = node.nodeValue;
  if (!text || !THAI.test(text)) return;
  const frag = document.createDocumentFragment();
  text.split(/(\s+)/).forEach((part) => {
    if (part === '') return;
    if (/^\s+$/.test(part) || !THAI.test(part)) { frag.appendChild(document.createTextNode(part)); return; }
    const span = document.createElement('span');
    span.className = 'ph';
    span.textContent = glue(part);
    frag.appendChild(span);
  });
  node.parentNode.replaceChild(frag, node);
}

function process(root) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode(n) {
      const p = n.parentElement;
      if (!p || p.closest(SKIP)) return NodeFilter.FILTER_REJECT;
      return THAI.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
    },
  });
  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);
  nodes.forEach(wrapTextNode);
}

export function initThaiWrap() {
  document.querySelectorAll(TARGETS).forEach((el) => {
    if (el.dataset.phraseDone) return;
    el.dataset.phraseDone = '1';
    process(el);
  });
}

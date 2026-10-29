/**
 * BAIT - Image loading polish. Native `loading="lazy"` (set in markup)
 * already defers the network request; this only adds the fade-in once
 * each image has actually painted, and guards against the 'load' event
 * having fired before this script attaches (cached images).
 */
export function initLazyImages() {
  const images = document.querySelectorAll('img[loading="lazy"]');
  images.forEach((img) => {
    if (img.complete && img.naturalWidth > 0) {
      img.classList.add('is-loaded');
      return;
    }
    // Opt this image into the hide-then-fade treatment only once we know JS
    // is actually driving it — see base.css: without .is-loading the image
    // is just visible, so a listener that never fires can't blank it forever.
    img.classList.add('is-loading');
    img.addEventListener('load', () => img.classList.add('is-loaded'), { once: true });
    img.addEventListener('error', () => img.classList.add('is-loaded'), { once: true }); // don't hide broken images forever
  });
}

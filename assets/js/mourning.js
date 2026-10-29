/**
 * BAIT - Mourning pop-up (admin: ตั้งค่าเว็บไซต์ > ไว้อาลัย). The picture is already in the page, hidden; this decides whether to show it:
 * "session" = once per browser session (until the browser is closed), "always" = on every page load.
 * It goes first: the home-page announcement pop-up (index.php) waits for the 'bait:mourning-done' event, fired when this one is closed or not needed.
 */
export function initMourning() {
  const el = document.getElementById('mourningPopup');
  if (!el) return;
  const done = () => { window.baitMourningDone = true; document.dispatchEvent(new Event('bait:mourning-done')); };
  const KEY = 'bait_mourning_popup_seen';
  if (el.dataset.freq !== 'always') {
    try {
      if (window.sessionStorage.getItem(KEY)) { done(); return; }
      window.sessionStorage.setItem(KEY, '1');
    } catch (e) { /* storage blocked: show it, it simply may repeat */ }
  }
  const close = el.querySelector('.mourning-popup__close');
  const opener = document.activeElement;
  const hide = () => {
    el.hidden = true;
    document.documentElement.classList.remove('has-mourning-popup');
    document.removeEventListener('keydown', onKey);
    if (opener && opener.focus) opener.focus();
    done();
  };
  const onKey = (e) => {
    if (e.key === 'Escape') { hide(); return; }
    if (e.key === 'Tab') { e.preventDefault(); close.focus(); }   // the close button is the only control: Tab must not wander to the page behind
  };
  el.addEventListener('click', (e) => { if (e.target === el) hide(); });   // a click on the dark backdrop
  close.addEventListener('click', hide);
  document.addEventListener('keydown', onKey);
  el.hidden = false;
  document.documentElement.classList.add('has-mourning-popup');
  close.focus();
}

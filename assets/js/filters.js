/**
 * BAIT - Auto-submitting filter selects (used on programs.php / staff.php).
 * Replaces the old inline onchange="applyFilter(...)" handler with a
 * reusable, CSP-friendly module. Preserves every other query param and
 * always keeps ?lang= in sync.
 */
export function initFilters() {
  document.querySelectorAll('[data-filter-select]').forEach((select) => {
    select.addEventListener('change', () => {
      const url = new URL(window.location.href);
      const key = select.dataset.filterSelect;
      if (select.value) url.searchParams.set(key, select.value);
      else url.searchParams.delete(key);
      const lang = window.BAIT?.lang;
      if (lang) url.searchParams.set('lang', lang);
      window.location.href = url.toString();
    });
  });
}

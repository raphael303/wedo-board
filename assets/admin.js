/* Bibliothek */
(function () {
  'use strict';
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    try {
      await navigator.clipboard.writeText(btn.dataset.copy);
      const old = btn.textContent; btn.textContent = 'Kopiert';
      setTimeout(() => { btn.textContent = old; }, 1500);
    } catch (e) { prompt('Kopieren:', btn.dataset.copy); }
  });
  document.addEventListener('submit', (ev) => {
    const f = ev.target.closest('form[data-confirm]');
    if (f && !confirm(f.dataset.confirm)) ev.preventDefault();
  });
  const type = document.getElementById('type');
  if (type) {
    const row = document.getElementById('columns-row');
    type.addEventListener('change', () => { row.hidden = type.value !== 'columns'; });
  }
})();

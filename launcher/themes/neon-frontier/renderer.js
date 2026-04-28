(function () {
  'use strict';

  if (!window.LauncherUI || typeof window.LauncherUI.mount !== 'function') {
    const box = document.getElementById('errorBox');
    if (box) box.textContent = 'Erreur : UI core indisponible.';
    return;
  }

  window.LauncherUI.mount();

  // Sync the launcher name into h1[data-text] so the chromatic split layers
  // stay in sync when the UI core writes the dynamic name.
  const nameEl = document.getElementById('launcherName');
  if (nameEl) {
    const sync = () => { nameEl.dataset.text = nameEl.textContent || ''; };
    sync();
    const obs = new MutationObserver(sync);
    obs.observe(nameEl, { childList: true, characterData: true, subtree: true });
  }
})();

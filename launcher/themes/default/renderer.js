(function () {
  'use strict';

  if (!window.LauncherUI || typeof window.LauncherUI.mount !== 'function') {
    const box = document.getElementById('errorBox');
    if (box) box.textContent = 'Erreur : UI core indisponible.';
    return;
  }

  window.LauncherUI.mount();
})();

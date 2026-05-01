(function () {
  'use strict';

  if (!window.LauncherUI || typeof window.LauncherUI.mount !== 'function') {
    const box = document.getElementById('errorBox');
    if (box) box.textContent = 'Erreur : UI core indisponible.';
    return;
  }

  // Mount the launcher UI
  window.LauncherUI.mount();

  // --- Extension Toggle Support ---
  // Observe for extensions being added by launcher-ui.js and add toggle functionality
  const observeExtensions = setInterval(() => {
    const extItemsContainer = document.getElementById('extItems');
    if (!extItemsContainer || extItemsContainer.textContent.trim() === '▶') return;

    const extItems = extItemsContainer.querySelectorAll('.ext-item:not([data-processed])');
    if (extItems.length === 0) return;

    extItems.forEach((item) => {
      item.dataset.processed = 'true';

      const nameEl = item.querySelector('.ext-name');
      if (!nameEl) return;

      const extName = nameEl.textContent;
      const currentHTML = item.innerHTML;

      // Create wrapper with checkbox
      item.innerHTML = `
        <input type="checkbox" class="ext-checkbox" checked style="width: 18px; height: 18px; cursor: pointer; accent-color: #4cd137;">
        <div style="flex: 1; overflow: hidden;">${currentHTML}</div>
      `;

      const checkbox = item.querySelector('.ext-checkbox');
      checkbox.addEventListener('change', (e) => {
        item.style.opacity = e.target.checked ? '1' : '0.6';

        if (window.launcherAPI && typeof window.launcherAPI.setExtensionState === 'function') {
          window.launcherAPI.setExtensionState(extName, e.target.checked);
        }
      });
    });

    if (extItems.length > 0) clearInterval(observeExtensions);
  }, 100);

  // Stop observing after 10 seconds
  setTimeout(() => clearInterval(observeExtensions), 10000);
})();

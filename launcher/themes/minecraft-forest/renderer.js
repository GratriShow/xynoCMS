(function () {
  'use strict';

  if (!window.LauncherUI || typeof window.LauncherUI.mount !== 'function') {
    const box = document.getElementById('errorBox');
    if (box) box.textContent = 'Erreur : UI core indisponible.';
    return;
  }

  window.LauncherUI.mount();

  // --- Extension Toggle Support ---
  // Listen for when launcher-ui.js populates the extensions
  const observeExtensions = setInterval(() => {
    const extItemsContainer = document.getElementById('extItems');
    if (!extItemsContainer) return;

    const extItems = extItemsContainer.querySelectorAll('.ext-item');
    if (extItems.length === 0) return;

    clearInterval(observeExtensions);

    // Add checkbox and toggle functionality to each extension
    extItems.forEach((item) => {
      // Check if already processed
      if (item.dataset.processed === 'true') return;
      item.dataset.processed = 'true';

      const nameEl = item.querySelector('.ext-name');
      const valueEl = item.querySelector('.ext-value');

      if (nameEl) {
        // Create checkbox
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'ext-item-checkbox';
        checkbox.checked = true;
        checkbox.setAttribute('aria-label', `Activer/Désactiver ${nameEl.textContent}`);

        // Rebuild item structure
        item.innerHTML = '';
        item.appendChild(checkbox);

        const contentDiv = document.createElement('div');
        contentDiv.style.overflow = 'hidden';
        contentDiv.appendChild(nameEl);
        if (valueEl) contentDiv.appendChild(valueEl);
        item.appendChild(contentDiv);

        // Toggle handler
        checkbox.addEventListener('change', (e) => {
          const enabled = e.target.checked;
          item.style.opacity = enabled ? '1' : '0.6';
          item.style.backgroundColor = enabled ? 'rgba(0, 0, 0, 0.2)' : 'rgba(76, 175, 80, 0.05)';

          // Store state
          if (window.launcherAPI && typeof window.launcherAPI.setExtensionState === 'function') {
            window.launcherAPI.setExtensionState(nameEl.textContent, enabled);
          }
        });
      }
    });
  }, 100);

  // Cleanup observer after 5 seconds
  setTimeout(() => {
    clearInterval(observeExtensions);
  }, 5000);
})();

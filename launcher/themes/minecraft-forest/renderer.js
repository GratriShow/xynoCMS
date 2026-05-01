(function () {
  'use strict';

  // Check if LauncherUI core is available
  if (!window.LauncherUI || typeof window.LauncherUI.mount !== 'function') {
    const box = document.getElementById('errorBox');
    if (box) box.textContent = 'Erreur : UI core indisponible (launcher-ui.js manquant).';
    const errScreen = document.getElementById('screen-error');
    if (errScreen) errScreen.classList.add('active');
    return;
  }

  // Navigation between screens
  function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
      item.addEventListener('click', function() {
        const screenId = this.getAttribute('data-screen');
        if (screenId) {
          switchScreen(screenId);
          // Update active nav item
          document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
          this.classList.add('active');
        }
      });
    });
  }

  // Switch between screens with animation
  function switchScreen(screenId) {
    const screens = document.querySelectorAll('.screen');
    screens.forEach(screen => {
      screen.classList.remove('active');
    });
    const newScreen = document.getElementById(screenId);
    if (newScreen) {
      newScreen.classList.add('active');
    }
  }

  // Initialize settings sliders
  function setupSliders() {
    const ramMinRange = document.getElementById('ramMinRange');
    const ramMaxRange = document.getElementById('ramMaxRange');
    const ramMinLabel = document.getElementById('ramMinLabel');
    const ramMaxLabel = document.getElementById('ramMaxLabel');

    if (ramMinRange && ramMinLabel) {
      ramMinRange.addEventListener('input', function() {
        const gb = (this.value / 1024).toFixed(1);
        ramMinLabel.textContent = gb + ' GB';
      });
    }

    if (ramMaxRange && ramMaxLabel) {
      ramMaxRange.addEventListener('input', function() {
        const gb = (this.value / 1024).toFixed(1);
        ramMaxLabel.textContent = gb + ' GB';
      });
    }
  }

  // Setup custom auth form
  function setupCustomAuth() {
    const authForm = document.getElementById('authCustomForm');
    const customAuthBtn = document.getElementById('customAuthBtn');
    const authBackBtn = document.getElementById('authBackBtn');
    const continueBtn = document.getElementById('continueBtn');

    if (customAuthBtn) {
      customAuthBtn.addEventListener('click', function() {
        switchScreen('screen-auth-custom');
      });
    }

    if (authBackBtn) {
      authBackBtn.addEventListener('click', function() {
        switchScreen('screen-ready');
      });
    }

    if (authForm) {
      authForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const email = document.getElementById('authCustomEmail').value;
        const password = document.getElementById('authCustomPassword').value;
        const errorEl = document.getElementById('authCustomError');

        try {
          const api = window.launcherAPI || window.auth;
          if (api && api.loginCustom) {
            await api.loginCustom({ email, password });
          }
        } catch (error) {
          if (errorEl) {
            errorEl.style.display = 'block';
            errorEl.textContent = 'Erreur: ' + (error.message || 'Authentification échouée');
          }
        }
      });
    }
  }

  // Initialize everything
  function init() {
    setupNavigation();
    setupSliders();
    setupCustomAuth();

    // Mount the core launcher UI
    window.LauncherUI.mount();
  }

  // Run when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

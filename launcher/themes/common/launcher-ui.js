(function () {
  'use strict';

  function getId(id) {
    return document.getElementById(id);
  }

  function createLegacyAdapter() {
    const launcher = window.launcher;
    const auth = window.auth;
    if (!launcher || !auth) return null;

    return {
      async login({ interactive } = {}) {
        if (interactive) return await auth.loginMicrosoft();
        return await auth.getSession();
      },
      async logout() {
        return await auth.logout();
      },
      async play() {
        return await launcher.play();
      },
      async retry() {
        return await launcher.retry();
      },
      async quit() {
        return await launcher.quit();
      },
      progression(handlers = {}) {
        const off = [];
        if (handlers.onStatus) off.push(launcher.onStatus(handlers.onStatus));
        if (handlers.onProgress) off.push(launcher.onProgress(handlers.onProgress));
        if (handlers.onError) off.push(launcher.onError(handlers.onError));
        if (handlers.onInfo) off.push(launcher.onInfo(handlers.onInfo));
        if (handlers.onUx) off.push(launcher.onUx(handlers.onUx));
        if (handlers.onMsaCode) off.push(auth.onMsaCode(handlers.onMsaCode));
        return () => off.forEach((fn) => {
          try {
            if (typeof fn === 'function') fn();
          } catch {
            // ignore
          }
        });
      },
    };
  }

  function getApi() {
    if (window.launcherAPI) return window.launcherAPI;
    // Backward compatibility only (should be removable later)
    return createLegacyAdapter();
  }

  function mount() {
    const api = getApi();
    if (!api) {
      const errorBox = getId('errorBox');
      if (errorBox) errorBox.textContent = 'Erreur : API indisponible (preload manquant).';
      const errScreen = getId('screen-error');
      if (errScreen) errScreen.classList.add('active');
      return;
    }

    const settingsApi = window.launcherAPI && window.launcherAPI.settings ? window.launcherAPI.settings : null;
    const fetchExtension =
      window.launcherAPI && typeof window.launcherAPI.fetchExtension === 'function'
        ? window.launcherAPI.fetchExtension
        : window.launcher && typeof window.launcher.fetchExtension === 'function'
        ? window.launcher.fetchExtension
        : null;
    const customAuthApi =
      window.launcherAPI && window.launcherAPI.auth ? window.launcherAPI.auth : window.auth || null;

    const launcherNameEl = getId('launcherName');
    const launcherLogoEl = getId('launcherLogo');

    const newsBoxEl = getId('newsBox');
    const newsItemsEl = getId('newsItems');

    const extPanelEl = getId('extPanel');
    const extItemsEl = getId('extItems');

    const screens = {
      INIT: getId('screen-init'),
      SYNC: getId('screen-sync'),
      DOWNLOAD: getId('screen-download'),
      READY: getId('screen-ready'),
      BLOCKED: getId('screen-blocked'),
      SETTINGS: getId('screen-settings'),
      ERROR: getId('screen-error'),
      AUTH_CUSTOM: getId('screen-auth-custom'),
    };

    const authCustomFormEl = getId('authCustomForm');
    const authCustomEmailEl = getId('authCustomEmail');
    const authCustomPasswordEl = getId('authCustomPassword');
    const authCustomErrorEl = getId('authCustomError');
    const authCustomSubmitEl = getId('authCustomSubmit');

    const initHintEl = getId('initHint');

    const syncStepEl = getId('syncStep');
    const syncProgressBarEl = getId('syncProgressBar');
    const syncProgressTextEl = getId('syncProgressText');

    const downloadStepEl = getId('downloadStep');
    const downloadProgressBarEl = getId('downloadProgressBar');
    const downloadProgressTextEl = getId('downloadProgressText');
    const currentFileEl = getId('currentFile');
    const downloadSpeedEl = getId('downloadSpeed');

    const continueBtn = getId('continueBtn');
    const authHintEl = getId('authHint');

    const blockedMessageEl = getId('blockedMessage');
    const renewBtn = getId('renewBtn');

    const settingsBtn = getId('settingsBtn');
    const settingsBackBtn = getId('settingsBackBtn');
    const ramMinRange = getId('ramMinRange');
    const ramMaxRange = getId('ramMaxRange');
    const ramMinLabel = getId('ramMinLabel');
    const ramMaxLabel = getId('ramMaxLabel');
    const javaPathInput = getId('javaPathInput');
    const javaAutoHint = getId('javaAutoHint');
    const fullscreenToggle = getId('fullscreenToggle');
    const resWidthInput = getId('resWidthInput');
    const resHeightInput = getId('resHeightInput');
    const settingsError = getId('settingsError');

    const errorBoxEl = getId('errorBox');
    const retryBtn = getId('retryBtn');
    const quitBtn = getId('quitBtn');

    /** @type {'INIT'|'FETCH_MANIFEST'|'SYNC'|'DOWNLOAD'|'UPDATE'|'READY'|'BLOCKED'|'SETTINGS'|'ERROR'} */
    let uxState = 'INIT';

    let lastSpeedSample = null;
    let smoothedBps = 0;

    function setLauncherName(name) {
      const safe = String(name || '').trim();
      if (launcherNameEl) launcherNameEl.textContent = safe || 'Launcher';
    }

    // --- Branding (logo + primary color) ---------------------------------
    const SAFE_HEX = /^#[0-9a-fA-F]{3,8}$/;
    function applyBranding(branding) {
      if (!branding || typeof branding !== 'object') return;

      const logoUrl = typeof branding.logo_url === 'string' ? branding.logo_url.trim() : '';
      if (launcherLogoEl) {
        if (logoUrl && (/^https?:\/\//i.test(logoUrl) || logoUrl.startsWith('/'))) {
          launcherLogoEl.src = logoUrl;
          launcherLogoEl.alt = launcherNameEl ? launcherNameEl.textContent : 'Logo';
          launcherLogoEl.style.display = 'block';
        } else {
          launcherLogoEl.removeAttribute('src');
          launcherLogoEl.style.display = 'none';
        }
      }

      const primary = typeof branding.primary === 'string' ? branding.primary.trim() : '';
      if (primary && SAFE_HEX.test(primary)) {
        try {
          document.documentElement.style.setProperty('--primary', primary);
        } catch {
          // ignore
        }
      }
    }

    // --- Extensions ------------------------------------------------------
    /** @type {Array<{key:string,name:string,enabled?:boolean,needs_api?:boolean,category?:string}>} */
    let lastExtensions = [];
    let extRefreshTimer = null;

    function formatExtValue(key, data) {
      if (!data || typeof data !== 'object') return '';
      // Built-in payloads have well-known shapes — format them for readability.
      switch (key) {
        case 'changelog': {
          const v = data.version ? String(data.version) : '';
          return v ? `v${v}` : '';
        }
        case 'modpack': {
          const loader = data.loader ? String(data.loader) : '';
          const mc = data.mc_version ? String(data.mc_version) : '';
          return loader || mc ? `${loader}${mc ? ' • MC ' + mc : ''}` : '';
        }
        case 'ram_slider': {
          const min = Number.isFinite(data.min) ? data.min : null;
          const max = Number.isFinite(data.max) ? data.max : null;
          if (min === null || max === null) return '';
          return `${min}–${max} ${data.unit || 'Go'}`;
        }
        case 'java_manager':
          return data.recommended ? `Java ${data.recommended}` : '';
        case 'crash_reporter':
          return data.enabled ? 'actif' : 'désactivé';
        case 'analytics':
          return data.enabled ? 'actif' : 'désactivé';
        default: {
          // For upstream extensions: pick a common "headline" field if present.
          const pick = ['title', 'message', 'status', 'count', 'total', 'online', 'players', 'value'];
          for (const k of pick) {
            if (data[k] !== undefined && data[k] !== null && typeof data[k] !== 'object') {
              return String(data[k]).slice(0, 80);
            }
          }
          return '';
        }
      }
    }

    function renderExtensionItem(ext, value) {
      const item = document.createElement('div');
      item.className = 'ext-item';

      const name = document.createElement('span');
      name.className = 'ext-name';
      name.textContent = String(ext.name || ext.key || '');
      item.appendChild(name);

      const val = document.createElement('span');
      val.className = 'ext-value muted';
      val.textContent = value || '…';
      item.appendChild(val);

      return item;
    }

    function setExtensions(extensions) {
      if (!extPanelEl || !extItemsEl) return;
      const enabled = Array.isArray(extensions)
        ? extensions.filter((e) => e && e.key && e.enabled !== false && e.enabled !== 0)
        : [];
      lastExtensions = enabled;

      extItemsEl.textContent = '';

      if (!enabled.length) {
        extPanelEl.style.display = 'none';
        return;
      }

      extPanelEl.style.display = 'block';
      const valueEls = new Map();

      for (const ext of enabled) {
        const el = renderExtensionItem(ext, '');
        extItemsEl.appendChild(el);
        valueEls.set(ext.key, el.querySelector('.ext-value'));
      }

      if (!fetchExtension) return;

      // Kick off one fetch per enabled extension; respect 30s server cache.
      for (const ext of enabled) {
        (async () => {
          try {
            const res = await fetchExtension(ext.key);
            const payload = res && res.ok && res.data && res.data.data ? res.data.data : null;
            const text = formatExtValue(ext.key, payload);
            const cell = valueEls.get(ext.key);
            if (cell) cell.textContent = text || '';
          } catch {
            const cell = valueEls.get(ext.key);
            if (cell) cell.textContent = '';
          }
        })();
      }

      // Light background refresh every 45s so news/player counts stay fresh.
      if (extRefreshTimer) clearInterval(extRefreshTimer);
      extRefreshTimer = setInterval(() => {
        if (!fetchExtension) return;
        for (const ext of lastExtensions) {
          (async () => {
            try {
              const res = await fetchExtension(ext.key);
              const payload = res && res.ok && res.data && res.data.data ? res.data.data : null;
              const text = formatExtValue(ext.key, payload);
              const cell = valueEls.get(ext.key);
              if (cell) cell.textContent = text || '';
            } catch {
              // ignore
            }
          })();
        }
      }, 45_000);
    }

    // --- Auth mode (microsoft / custom / offline) ------------------------
    /** @type {'microsoft'|'custom'|'offline'} */
    let authMode = 'microsoft';

    function applyAuth(auth) {
      if (!auth || typeof auth !== 'object') return;
      const mode = typeof auth.mode === 'string' ? auth.mode.toLowerCase() : '';
      if (mode === 'custom' || mode === 'offline' || mode === 'microsoft') {
        authMode = mode;
      }
      if (!continueBtn) return;
      if (authMode === 'custom') {
        continueBtn.textContent = session && session.username ? 'Continuer' : 'Se connecter';
      } else if (authMode === 'offline') {
        continueBtn.textContent = 'Jouer (hors-ligne)';
      } else {
        continueBtn.textContent = session && session.username ? 'Continuer' : 'Se connecter avec Microsoft';
      }
    }

    function setAuthCustomError(msg) {
      if (!authCustomErrorEl) return;
      const s = String(msg || '').trim();
      if (!s) {
        authCustomErrorEl.style.display = 'none';
        authCustomErrorEl.textContent = '';
        return;
      }
      authCustomErrorEl.style.display = 'block';
      authCustomErrorEl.textContent = s;
    }

    function normalizeNewsItem(item) {
      if (!item || typeof item !== 'object') return null;
      const title = typeof item.title === 'string' ? item.title.trim() : '';
      const content = typeof item.content === 'string' ? item.content.trim() : '';
      const date = typeof item.date === 'string' ? item.date.trim() : '';
      if (!title && !content) return null;
      return { title, content, date };
    }

    function setNews(news) {
      if (!newsBoxEl || !newsItemsEl) return;
      const items = Array.isArray(news) ? news.map(normalizeNewsItem).filter(Boolean) : [];
      newsItemsEl.textContent = '';

      if (!items.length) {
        newsBoxEl.style.display = 'none';
        return;
      }

      newsBoxEl.style.display = 'block';

      for (const it of items) {
        const article = document.createElement('article');
        article.className = 'news-item';

        const titleEl = document.createElement('div');
        titleEl.className = 'news-title';
        titleEl.textContent = it.title || 'News';

        const metaEl = document.createElement('div');
        metaEl.className = 'news-meta muted';
        metaEl.textContent = it.date || '';

        const contentEl = document.createElement('div');
        contentEl.className = 'news-content muted';
        contentEl.textContent = it.content || '';

        article.appendChild(titleEl);
        if (it.date) article.appendChild(metaEl);
        if (it.content) article.appendChild(contentEl);
        newsItemsEl.appendChild(article);
      }
    }

    function screenForState(state) {
      if (state === 'FETCH_MANIFEST' || state === 'SYNC') return 'SYNC';
      if (state === 'INIT') return 'INIT';
      if (state === 'DOWNLOAD' || state === 'UPDATE') return 'DOWNLOAD';
      if (state === 'READY') return 'READY';
      if (state === 'BLOCKED') return 'BLOCKED';
      if (state === 'SETTINGS') return 'SETTINGS';
      if (state === 'ERROR') return 'ERROR';
      if (state === 'AUTH_CUSTOM') return 'AUTH_CUSTOM';
      return 'INIT';
    }

    function setActiveScreen(state) {
      uxState = state;
      const screenKey = screenForState(state);
      for (const key of Object.keys(screens)) {
        const el = screens[key];
        if (!el) continue;
        el.classList.toggle('active', key === screenKey);
      }
    }

    function setSyncStep(text) {
      const msg = String(text || '').trim();
      if (syncStepEl) syncStepEl.textContent = msg || 'Synchronisation…';
    }

    function setInitHint(text) {
      const msg = String(text || '').trim();
      if (initHintEl) initHintEl.textContent = msg || 'Connexion et chargement…';
    }

    function clampPercent(p) {
      const n = Number.isFinite(p) ? p : 0;
      return Math.max(0, Math.min(100, Math.floor(n)));
    }

    function setSyncProgress({ done, total, percent } = {}) {
      const d = Number.isFinite(done) ? done : 0;
      const t = Number.isFinite(total) ? total : 0;
      const p = clampPercent(Number.isFinite(percent) ? percent : t === 0 ? 0 : (d / t) * 100);
      if (syncProgressBarEl) syncProgressBarEl.value = p;
      if (syncProgressTextEl) syncProgressTextEl.textContent = `${p}%`;
    }

    function formatBytes(bytes) {
      const b = Number.isFinite(bytes) ? bytes : 0;
      if (b < 1024) return `${Math.round(b)} o`;
      const kb = b / 1024;
      if (kb < 1024) return `${kb.toFixed(1)} Ko`;
      const mb = kb / 1024;
      if (mb < 1024) return `${mb.toFixed(1)} Mo`;
      const gb = mb / 1024;
      return `${gb.toFixed(1)} Go`;
    }

    function formatFileLabel(p) {
      const s = String(p || '').trim();
      if (!s) return '';
      const parts = s.split('/');
      const base = parts[parts.length - 1] || s;
      return base;
    }

    function setDownloadProgress({ done, total, percent, currentFile, bytesDone, bytesTotal } = {}) {
      const d = Number.isFinite(done) ? done : 0;
      const t = Number.isFinite(total) ? total : 0;
      const p = clampPercent(Number.isFinite(percent) ? percent : t === 0 ? 0 : (d / t) * 100);
      if (downloadProgressBarEl) downloadProgressBarEl.value = p;
      if (currentFileEl) currentFileEl.textContent = formatFileLabel(currentFile) || '';

      const bDone = Number.isFinite(bytesDone) ? bytesDone : NaN;
      const bTotal = Number.isFinite(bytesTotal) ? bytesTotal : NaN;
      const bytesPart =
        Number.isFinite(bDone) && Number.isFinite(bTotal) && bTotal > 0
          ? ` • ${formatBytes(bDone)} / ${formatBytes(bTotal)}`
          : '';

      if (downloadProgressTextEl) {
        downloadProgressTextEl.textContent = t > 0 ? `${p}% (${d} / ${t})${bytesPart}` : `${p}%${bytesPart}`;
      }

      if (!downloadSpeedEl) return;
      if (!Number.isFinite(bDone)) {
        downloadSpeedEl.style.display = 'none';
        lastSpeedSample = null;
        smoothedBps = 0;
        return;
      }

      const now = performance.now();
      if (!lastSpeedSample) {
        lastSpeedSample = { t: now, bytes: bDone };
        downloadSpeedEl.style.display = 'none';
        return;
      }

      const dt = now - lastSpeedSample.t;
      const db = bDone - lastSpeedSample.bytes;
      if (dt < 250 || db < 0) return;
      const instBps = (db / dt) * 1000;
      smoothedBps = smoothedBps ? smoothedBps * 0.8 + instBps * 0.2 : instBps;
      lastSpeedSample = { t: now, bytes: bDone };

      if (smoothedBps > 0) {
        downloadSpeedEl.textContent = `Vitesse : ${formatBytes(smoothedBps)}/s`;
        downloadSpeedEl.style.display = 'block';
      }
    }

    function showError(message) {
      const msg = String(message || '').trim();
      if (errorBoxEl) errorBoxEl.textContent = msg || 'Une erreur est survenue.';
      setActiveScreen('ERROR');
    }

    let lastRenewUrl = '';
    let isBlocked = false;
    function showBlocked({ message, renewUrl } = {}) {
      const msg = String(message || '').trim() || 'Votre abonnement a expiré';
      const url = String(renewUrl || '').trim();
      if (url) lastRenewUrl = url;

      if (blockedMessageEl) blockedMessageEl.textContent = msg;
      if (!screens.BLOCKED) {
        showError(msg);
        return;
      }

      isBlocked = true;
      if (continueBtn) continueBtn.disabled = true;
      if (settingsBtn) settingsBtn.disabled = true;
      setActiveScreen('BLOCKED');
    }

    function mbToGbLabel(mb) {
      const n = Number.isFinite(mb) ? mb : 0;
      return `${(n / 1024).toFixed(1)} Go`;
    }

    function setSettingsError(message) {
      if (!settingsError) return;
      const msg = String(message || '').trim();
      if (!msg) {
        settingsError.style.display = 'none';
        settingsError.textContent = '';
        return;
      }
      settingsError.style.display = 'block';
      settingsError.textContent = msg;
    }

    function readResolution() {
      const wRaw = resWidthInput ? String(resWidthInput.value || '').trim() : '';
      const hRaw = resHeightInput ? String(resHeightInput.value || '').trim() : '';

      if (!wRaw && !hRaw) return null;
      if (!wRaw || !hRaw) throw new Error('Résolution incomplète (largeur + hauteur).');

      const w = Math.trunc(Number(wRaw));
      const h = Math.trunc(Number(hRaw));
      if (!Number.isFinite(w) || !Number.isFinite(h)) throw new Error('Résolution invalide.');
      if (w < 640 || h < 480) throw new Error('Résolution trop faible.');
      if (w > 7680 || h > 4320) throw new Error('Résolution trop élevée.');

      return { width: w, height: h };
    }

    function applySettingsToForm(settings, javaAuto) {
      const s = settings || {};

      const ramMin = Number.isFinite(Number(s.ram_min)) ? Math.trunc(Number(s.ram_min)) : 2048;
      const ramMax = Number.isFinite(Number(s.ram_max)) ? Math.trunc(Number(s.ram_max)) : 4096;

      if (ramMinRange) ramMinRange.value = String(ramMin);
      if (ramMaxRange) ramMaxRange.value = String(ramMax);
      if (ramMinLabel) ramMinLabel.textContent = mbToGbLabel(ramMin);
      if (ramMaxLabel) ramMaxLabel.textContent = mbToGbLabel(ramMax);

      if (javaPathInput) javaPathInput.value = s.java_path ? String(s.java_path) : '';
      if (javaAutoHint) {
        const hint = javaAuto ? `Auto détecté : ${javaAuto}` : 'Auto détecté : (introuvable, utilisation de "java" si disponible)';
        javaAutoHint.textContent = hint;
      }

      if (fullscreenToggle) fullscreenToggle.checked = Boolean(s.fullscreen);

      const r = s.resolution && typeof s.resolution === 'object' ? s.resolution : null;
      if (resWidthInput) resWidthInput.value = r && r.width ? String(r.width) : '';
      if (resHeightInput) resHeightInput.value = r && r.height ? String(r.height) : '';

      setSettingsError('');
    }

    let lastLoadedSettings = null;
    let lastJavaAuto = null;

    async function loadSettingsFromBackend() {
      if (!settingsApi) return null;
      const res = await settingsApi.get();
      if (res && res.settings) {
        lastLoadedSettings = res.settings;
        lastJavaAuto = res.javaAuto || null;
      }
      return res;
    }

    async function openSettings() {
      if (!settingsApi) {
        showError('Erreur : API settings indisponible (preload obsolète).');
        return;
      }
      try {
        const res = await loadSettingsFromBackend();
        applySettingsToForm(res && res.settings ? res.settings : null, res ? res.javaAuto : null);
        setActiveScreen('SETTINGS');
      } catch (e) {
        showError(e && e.message ? e.message : String(e || 'Impossible de charger les paramètres.'));
      }
    }

    function closeSettings() {
      setSettingsError('');
      setActiveScreen('READY');
    }

    let saveTimer = null;
    function scheduleSave() {
      if (!settingsApi) return;
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(() => {
        (async () => {
          try {
            const ramMin = ramMinRange ? Math.trunc(Number(ramMinRange.value)) : 2048;
            const ramMax = ramMaxRange ? Math.trunc(Number(ramMaxRange.value)) : 4096;

            if (!Number.isFinite(ramMin) || !Number.isFinite(ramMax)) throw new Error('Paramètres RAM invalides.');
            if (ramMax > 16 * 1024) throw new Error('RAM max trop élevée (max 16 Go).');
            if (ramMin >= ramMax) throw new Error('RAM min doit être inférieure à RAM max.');

            const javaPath = javaPathInput ? String(javaPathInput.value || '').trim() : '';
            const fullscreen = fullscreenToggle ? Boolean(fullscreenToggle.checked) : false;
            const resolution = readResolution();

            setSettingsError('');
            const patch = {
              ram_min: ramMin,
              ram_max: ramMax,
              java_path: javaPath ? javaPath : null,
              fullscreen,
              resolution,
            };

            const res = await settingsApi.update(patch);
            if (res && res.settings) {
              lastLoadedSettings = res.settings;
              lastJavaAuto = res.javaAuto || lastJavaAuto;
              applySettingsToForm(res.settings, res.javaAuto || lastJavaAuto);
            }
          } catch (e) {
            setSettingsError(e && e.message ? e.message : String(e || 'Paramètres invalides.'));
          }
        })();
      }, 250);
    }

    // Default UI
    setLauncherName('Launcher');
    setActiveScreen('INIT');
    setInitHint('Connexion et chargement…');
    setSyncStep('Récupération du manifest');
    setSyncProgress({ done: 0, total: 0, percent: 0 });
    setDownloadProgress({ done: 0, total: 0, percent: 0, currentFile: '' });

    /** @type {null|{type:'microsoft',username:string,uuid:string,access_token:string}} */
    let session = null;

    function setAuthHint(text) {
      if (!authHintEl) return;
      authHintEl.textContent = String(text || '').trim();
    }

    function setContinueLabel() {
      if (!continueBtn) return;
      if (session && session.username) {
        continueBtn.textContent = 'Continuer';
        setAuthHint(`Connecté : ${session.username}`);
        return;
      }
      if (authMode === 'custom') {
        continueBtn.textContent = 'Se connecter';
      } else if (authMode === 'offline') {
        continueBtn.textContent = 'Jouer (hors-ligne)';
      } else {
        continueBtn.textContent = 'Se connecter avec Microsoft';
      }
      setAuthHint('');
    }

    async function refreshSession() {
      let res;
      try {
        res = await api.login({ interactive: false });
      } catch {
        res = null;
      }
      session = res && res.session ? res.session : null;
      setContinueLabel();
    }

    if (continueBtn) {
      continueBtn.addEventListener('click', () => {
        (async () => {
          if (continueBtn.disabled) return;
          continueBtn.disabled = true;

          try {
            if (!session) {
              if (authMode === 'custom') {
                // Route to the custom-auth screen instead of Microsoft flow.
                setAuthCustomError('');
                setActiveScreen('AUTH_CUSTOM');
                if (authCustomEmailEl) {
                  try { authCustomEmailEl.focus(); } catch { /* ignore */ }
                }
                return;
              }
              if (authMode === 'offline') {
                // Offline mode: no login needed, go straight to play.
                setActiveScreen('INIT');
                setInitHint('Lancement de Minecraft…');
                await api.play();
                return;
              }
              setActiveScreen('INIT');
              setInitHint('Connexion Microsoft…');
              const res = await api.login({ interactive: true });
              session = res && res.session ? res.session : null;
              if (!session) throw new Error('Connexion échouée.');
              setActiveScreen('READY');
              setContinueLabel();
              return;
            }

            setActiveScreen('INIT');
            setInitHint('Lancement de Minecraft…');
            await api.play();
          } catch (e) {
            const msg = e && e.message ? e.message : String(e || 'Une erreur est survenue.');
            if (String(msg || '').trim() === 'Votre abonnement a expiré') {
              showBlocked({ message: 'Votre abonnement a expiré', renewUrl: lastRenewUrl });
              return;
            }
            showError(msg);
          } finally {
            if (!isBlocked) continueBtn.disabled = false;
          }
        })();
      });
    }

    // Custom-auth form submission (only active when auth.mode === 'custom').
    if (authCustomFormEl) {
      authCustomFormEl.addEventListener('submit', (ev) => {
        ev.preventDefault();
        (async () => {
          if (authCustomSubmitEl && authCustomSubmitEl.disabled) return;
          if (authCustomSubmitEl) authCustomSubmitEl.disabled = true;
          setAuthCustomError('');

          const email = authCustomEmailEl ? String(authCustomEmailEl.value || '').trim() : '';
          const password = authCustomPasswordEl ? String(authCustomPasswordEl.value || '') : '';

          if (!email || !password) {
            setAuthCustomError('Email et mot de passe requis.');
            if (authCustomSubmitEl) authCustomSubmitEl.disabled = false;
            return;
          }

          try {
            if (!customAuthApi || typeof customAuthApi.loginCustom !== 'function') {
              throw new Error('API auth indisponible (preload obsolète).');
            }
            const res = await customAuthApi.loginCustom(email, password);
            if (!res || !res.ok) {
              const err = res && res.error ? String(res.error) : 'Authentification échouée.';
              throw new Error(err === 'Invalid credentials' ? 'Identifiants invalides.' : err);
            }
            // Build a synthetic local session so the rest of the UI behaves.
            const upstream = res.data && res.data.data ? res.data.data : {};
            const username = typeof upstream.username === 'string' ? upstream.username : email;
            session = {
              type: 'custom',
              username,
              uuid: typeof upstream.uuid === 'string' ? upstream.uuid : '',
              access_token: typeof upstream.token === 'string' ? upstream.token : '',
            };
            if (authCustomPasswordEl) authCustomPasswordEl.value = '';
            setActiveScreen('READY');
            setContinueLabel();
          } catch (e) {
            const msg = e && e.message ? e.message : String(e || 'Erreur inconnue.');
            setAuthCustomError(msg);
          } finally {
            if (authCustomSubmitEl) authCustomSubmitEl.disabled = false;
          }
        })();
      });
    }

    if (settingsBtn) {
      settingsBtn.addEventListener('click', () => {
        if (settingsBtn.disabled) return;
        openSettings();
      });
    }

    if (settingsBackBtn) {
      settingsBackBtn.addEventListener('click', () => closeSettings());
    }

    if (ramMinRange) {
      ramMinRange.addEventListener('input', () => {
        if (ramMinLabel) ramMinLabel.textContent = mbToGbLabel(Math.trunc(Number(ramMinRange.value)));
        scheduleSave();
      });
      ramMinRange.addEventListener('change', scheduleSave);
    }
    if (ramMaxRange) {
      ramMaxRange.addEventListener('input', () => {
        if (ramMaxLabel) ramMaxLabel.textContent = mbToGbLabel(Math.trunc(Number(ramMaxRange.value)));
        scheduleSave();
      });
      ramMaxRange.addEventListener('change', scheduleSave);
    }

    if (javaPathInput) {
      javaPathInput.addEventListener('input', scheduleSave);
      javaPathInput.addEventListener('change', scheduleSave);
    }

    if (fullscreenToggle) {
      fullscreenToggle.addEventListener('change', scheduleSave);
    }

    if (resWidthInput) {
      resWidthInput.addEventListener('input', scheduleSave);
      resWidthInput.addEventListener('change', scheduleSave);
    }
    if (resHeightInput) {
      resHeightInput.addEventListener('input', scheduleSave);
      resHeightInput.addEventListener('change', scheduleSave);
    }

    if (retryBtn) {
      retryBtn.addEventListener('click', async () => {
        retryBtn.disabled = true;
        if (errorBoxEl) errorBoxEl.textContent = '';
        setActiveScreen('INIT');
        setInitHint('Nouvelle tentative…');
        try {
          if (api.retry) await api.retry();
        } catch (e) {
          showError(e && e.message ? e.message : String(e || 'Une erreur est survenue.'));
        } finally {
          retryBtn.disabled = false;
        }
      });
    }

    if (quitBtn) {
      quitBtn.addEventListener('click', () => {
        if (api.quit) api.quit();
      });
    }

    // Subscribe to backend events
    api.progression({
      onInfo: (info) => {
        if (!info) return;
        if (info.name) setLauncherName(info.name);
        if (info.news) setNews(info.news);
        if (info.branding) applyBranding(info.branding);
        if (info.extensions) setExtensions(info.extensions);
        if (info.auth) applyAuth(info.auth);
      },
      onUx: (payload) => {
        if (!payload) return;
        const state = payload && payload.state ? String(payload.state) : '';
        const step = payload && payload.step ? String(payload.step) : '';

        if (state === 'BLOCKED') {
          showBlocked({ message: payload.message, renewUrl: payload.renewUrl });
          return;
        }

        if (isBlocked && state) {
          isBlocked = false;
          if (continueBtn) continueBtn.disabled = false;
          if (settingsBtn) settingsBtn.disabled = false;
        }

        // Don't override the Settings screen unless it's an error.
        if (uxState === 'SETTINGS' && state && state !== 'ERROR' && state !== 'BLOCKED') return;

        if (state) {
          setActiveScreen(state);
          if (state === 'FETCH_MANIFEST') {
            setSyncStep(step || 'Récupération du manifest');
            setSyncProgress({ done: 0, total: 0, percent: 0 });
          } else if (state === 'SYNC') {
            setSyncStep(step || 'Comparaison des fichiers');
          } else if (state === 'DOWNLOAD') {
            if (downloadStepEl) downloadStepEl.textContent = step || 'Téléchargement en cours';
          } else if (state === 'UPDATE') {
            if (downloadStepEl) downloadStepEl.textContent = step || 'Mise à jour en cours';
          }
        }
      },
      onStatus: (payload) => {
        if (!payload) return;
        const status = typeof payload === 'string' ? payload : payload.status;
        if (!status) return;
        if (uxState === 'INIT') setInitHint(status);
        if (uxState === 'FETCH_MANIFEST' || uxState === 'SYNC') setSyncStep(status);
        if ((uxState === 'DOWNLOAD' || uxState === 'UPDATE') && downloadStepEl) downloadStepEl.textContent = status;
      },
      onProgress: (p) => {
        if (!p) return;
        const phase = p.phase ? String(p.phase) : '';
        if (phase === 'SYNC') {
          setSyncProgress(p);
          return;
        }
        if (phase === 'DOWNLOAD') {
          setDownloadProgress(p);
          return;
        }
        if (phase === 'UPDATE') {
          setDownloadProgress(p);
          return;
        }
      },
      onError: (payload) => {
        if (!payload) return;
        const message = payload && payload.message ? payload.message : payload;
        showError(message);
      },
      onMsaCode: (payload) => {
        if (!payload) return;
        const msg = payload.message ? String(payload.message) : '';
        const code = payload.user_code ? String(payload.user_code) : '';
        const url = payload.verification_uri ? String(payload.verification_uri) : '';
        if (msg) {
          setActiveScreen('INIT');
          setInitHint(msg);
          return;
        }
        if (code && url) {
          setActiveScreen('INIT');
          setInitHint(`Ouvre ${url} et saisis le code ${code}.`);
        }
      },
    });

    if (renewBtn) {
      renewBtn.addEventListener('click', () => {
        const url = lastRenewUrl;
        if (!url) return;

        try {
          if (api && typeof api.openExternal === 'function') {
            api.openExternal(url);
          } else {
            window.open(url, '_blank', 'noopener');
          }
        } catch {
          // ignore
        }
      });
    }

    refreshSession();
    setContinueLabel();

    // Preload settings (so the first open is instant)
    if (settingsApi) {
      loadSettingsFromBackend().catch(() => {
        // ignore
      });
    }
  }

  function ready(fn) {
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      fn();
      return;
    }
    document.addEventListener('DOMContentLoaded', fn, { once: true });
  }

  window.LauncherUI = {
    mount: () => ready(mount),
  };
})();

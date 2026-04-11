(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);

  const launcherNameEl = $('launcherName');

  const screens = {
    INIT: $('screen-init'),
    SYNC: $('screen-sync'),
    DOWNLOAD: $('screen-download'),
    READY: $('screen-ready'),
    BLOCKED: $('screen-blocked'),
    ERROR: $('screen-error'),
  };

  const initHintEl = $('initHint');

  const syncStepEl = $('syncStep');
  const syncProgressBarEl = $('syncProgressBar');
  const syncProgressTextEl = $('syncProgressText');

  const downloadStepEl = $('downloadStep');
  const downloadProgressBarEl = $('downloadProgressBar');
  const downloadProgressTextEl = $('downloadProgressText');
  const currentFileEl = $('currentFile');
  const downloadSpeedEl = $('downloadSpeed');

  const continueBtn = $('continueBtn');
  const authHintEl = $('authHint');

  const blockedMessageEl = $('blockedMessage');
  const renewBtn = $('renewBtn');
  let lastRenewUrl = '';

  const errorBoxEl = $('errorBox');
  const retryBtn = $('retryBtn');
  const quitBtn = $('quitBtn');

  /** @type {'INIT'|'FETCH_MANIFEST'|'SYNC'|'DOWNLOAD'|'UPDATE'|'READY'|'ERROR'|'BLOCKED'} */
  let uxState = 'INIT';

  let lastSpeedSample = null;
  let smoothedBps = 0;

  function setLauncherName(name) {
    const safe = String(name || '').trim();
    launcherNameEl.textContent = safe || 'Launcher';
  }

  function screenForState(state) {
    if (state === 'FETCH_MANIFEST' || state === 'SYNC') return 'SYNC';
    if (state === 'INIT') return 'INIT';
    if (state === 'DOWNLOAD' || state === 'UPDATE') return 'DOWNLOAD';
    if (state === 'READY') return 'READY';
    if (state === 'BLOCKED') return 'BLOCKED';
    if (state === 'ERROR') return 'ERROR';
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
    syncStepEl.textContent = msg || 'Synchronisation…';
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
    if (syncProgressTextEl) {
      syncProgressTextEl.textContent = t > 0 ? `${p}%` : `${p}%`;
    }
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
    const bytesPart = Number.isFinite(bDone) && Number.isFinite(bTotal) && bTotal > 0 ? ` • ${formatBytes(bDone)} / ${formatBytes(bTotal)}` : '';

    if (downloadProgressTextEl) {
      downloadProgressTextEl.textContent = t > 0 ? `${p}% (${d} / ${t})${bytesPart}` : `${p}%${bytesPart}`;
    }

    // Optional speed
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
    errorBoxEl.textContent = msg || 'Une erreur est survenue.';
    setActiveScreen('ERROR');
  }

  // Default UI
  setLauncherName('Launcher');
  setActiveScreen('INIT');
  setInitHint('Connexion et chargement…');
  setSyncStep('Récupération du manifest');
  setSyncProgress({ done: 0, total: 0, percent: 0 });
  setDownloadProgress({ done: 0, total: 0, percent: 0, currentFile: '' });

  const api = window.launcher;
  const auth = window.auth;
  if (!api) {
    showError('Erreur : IPC indisponible (preload manquant).');
    return;
  }
  if (!auth) {
    showError('Erreur : IPC auth indisponible (preload manquant).');
    return;
  }

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
    continueBtn.textContent = 'Se connecter avec Microsoft';
    setAuthHint('');
  }

  async function refreshSession() {
    let res;
    try {
      res = await auth.getSession();
    } catch {
      res = null;
    }
    session = res && res.session ? res.session : null;
    setContinueLabel();
  }

  auth.onMsaCode((payload) => {
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
  });

  if (continueBtn) {
    continueBtn.addEventListener('click', () => {
      (async () => {
        if (continueBtn.disabled) return;
        continueBtn.disabled = true;

        try {
          if (!session) {
            setActiveScreen('INIT');
            setInitHint('Connexion Microsoft…');
            const res = await auth.loginMicrosoft();
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
            if (blockedMessageEl) blockedMessageEl.textContent = 'Votre abonnement a expiré';
            if (continueBtn) continueBtn.disabled = true;
            setActiveScreen('BLOCKED');
            return;
          }
          showError(msg);
        } finally {
          if (uxState !== 'BLOCKED') continueBtn.disabled = false;
        }
      })();
    });
  }

  if (renewBtn) {
    renewBtn.addEventListener('click', () => {
      if (!lastRenewUrl) return;
      try {
        if (api && typeof api.openExternal === 'function') {
          api.openExternal(lastRenewUrl);
        } else {
          window.open(lastRenewUrl, '_blank', 'noopener');
        }
      } catch {
        // ignore
      }
    });
  }

  if (retryBtn) {
    retryBtn.addEventListener('click', async () => {
      retryBtn.disabled = true;
      errorBoxEl.textContent = '';
      setActiveScreen('INIT');
      setInitHint('Nouvelle tentative…');
      try {
        await api.retry();
      } catch (e) {
        showError(e && e.message ? e.message : String(e || 'Une erreur est survenue.'));
      } finally {
        retryBtn.disabled = false;
      }
    });
  }

  if (quitBtn) {
    quitBtn.addEventListener('click', () => {
      api.quit();
    });
  }

  api.onInfo((info) => {
    if (info && info.name) setLauncherName(info.name);
  });

  api.onUx((payload) => {
    if (!payload) return;
    const state = payload && payload.state ? String(payload.state) : '';
    const step = payload && payload.step ? String(payload.step) : '';

    if (state === 'BLOCKED') {
      const msg = payload && payload.message ? String(payload.message) : 'Votre abonnement a expiré';
      const renewUrl = payload && payload.renewUrl ? String(payload.renewUrl) : '';
      lastRenewUrl = renewUrl || lastRenewUrl;
      if (blockedMessageEl) blockedMessageEl.textContent = msg;
      if (continueBtn) continueBtn.disabled = true;
      setActiveScreen('BLOCKED');
      return;
    }

    if (state) {
      setActiveScreen(state);
      if (state === 'FETCH_MANIFEST') {
        setSyncStep(step || 'Récupération du manifest');
        setSyncProgress({ done: 0, total: 0, percent: 0 });
      } else if (state === 'SYNC') {
        setSyncStep(step || 'Comparaison des fichiers');
      } else if (state === 'DOWNLOAD') {
        downloadStepEl.textContent = step || 'Téléchargement en cours';
      } else if (state === 'UPDATE') {
        downloadStepEl.textContent = step || 'Mise à jour en cours';
      }
    }
  });

  // Backward/fallback: keep listening to status, but avoid showing raw logs.
  api.onStatus((payload) => {
    if (!payload) return;
    const status = typeof payload === 'string' ? payload : payload.status;
    if (!status) return;

    // Use status to refine hints when UX state isn't explicit.
    if (uxState === 'INIT') setInitHint(status);
    if (uxState === 'FETCH_MANIFEST' || uxState === 'SYNC') setSyncStep(status);
    if ((uxState === 'DOWNLOAD' || uxState === 'UPDATE') && downloadStepEl) downloadStepEl.textContent = status;
  });

  api.onProgress((p) => {
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

    // Fallback (older payloads)
    if (uxState === 'DOWNLOAD' || uxState === 'UPDATE') setDownloadProgress(p);
    else setSyncProgress(p);
  });

  api.onError((err) => {
    if (!err) return;
    if (typeof err === 'string') {
      showError(err);
      return;
    }
    showError(err.message || 'Une erreur est survenue.');
  });

  refreshSession();
})();

// MUST be first: populates process.env from bundled src/config.json.
require('./src/bootstrap-env');

const { app, BrowserWindow, ipcMain, shell, dialog } = require('electron');
const path = require('node:path');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const crypto = require('node:crypto');

const { createClient: createApiV2Client } = require('./services/apiV2');
const { parseManifest } = require('./services/manifest');
const { ensureBaseFolders } = require('./services/fileManager');
const {
  planSync,
  downloadMissingFiles,
  deleteObsoleteFiles,
} = require('./services/downloader');
const { createEmptyState, loadState, saveState } = require('./services/state');
const { getLauncherPaths } = require('./utils/paths');
const { getSession, loginMicrosoft, logout } = require('./services/authService');
const { launchMinecraft } = require('./services/minecraft');
const { loadSettings, updateSettings, resolveJavaPath } = require('./services/settings');
const { readLocalVersion, writeLocalVersion } = require('./services/versionStore');
const { runAutoUpdate } = require('./services/autoUpdate');

function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function requireEnv(name) {
  const value = (process.env[name] || '').trim();
  if (!value) {
    throw new Error(`Missing env var: ${name}`);
  }
  return value;
}

function getRenewUrl(apiBaseUrl) {
  const fromEnv = (process.env.RENEW_URL || '').trim();
  if (fromEnv) return fromEnv;
  try {
    const base = new URL(apiBaseUrl);
    return new URL('/pricing.php', base).toString();
  } catch {
    return '';
  }
}

function getLicenseRecheckMs() {
  const raw = (process.env.LICENSE_RECHECK_MINUTES || '').trim();
  const minutes = raw ? Number(raw) : NaN;
  if (Number.isFinite(minutes) && minutes > 0) {
    return Math.floor(minutes * 60_000);
  }
  // Default: jitter between 2 and 5 minutes to avoid predictable bypass windows.
  const min = 2 * 60_000;
  const max = 5 * 60_000;
  return Math.floor(min + Math.random() * (max - min));
}

async function checkLicense(apiClient, { pub } = {}) {
  const res = await apiClient.getStatus();
  const active = res && res.status === 'active';
  if (pub && res && typeof res === 'object') {
    const launcher = res.launcher && typeof res.launcher === 'object' ? res.launcher : null;
    const name = launcher && typeof launcher.name === 'string' ? launcher.name : '';
    const news = Array.isArray(res.news) ? res.news : [];
    const config = res.config && typeof res.config === 'object' ? res.config : {};
    if (name || news.length || Object.keys(config).length) {
      pub.info({
        name,
        news,
        config,
      });
    }
  }
  if (!active && pub) {
    const apiBaseUrl = requireEnv('API_BASE_URL');
    pub.ux({
      state: 'BLOCKED',
      message: 'Votre abonnement a expiré',
      renewUrl: getRenewUrl(apiBaseUrl),
      status: res ? res.status : 'unknown',
    });
  }
  return { ok: true, active, status: res ? res.status : 'unknown' };
}

async function runSync(apiClient, pub) {
  if (!pub) {
    throw new Error('Missing publisher');
  }

  pub.ux({ state: 'INIT' });
  pub.status('Initialisation du launcher');

  const uuid = requireEnv('LAUNCHER_UUID');
  const apiBaseUrl = requireEnv('API_BASE_URL');

  const paths = getLauncherPaths(app);
  await ensureBaseFolders(paths);

  const stateLoad = await loadState(paths, { launcherId: uuid });
  const prevState = stateLoad.ok ? stateLoad.state : null;

  console.log('[sync] appData:', app.getPath('appData'));
  console.log('[sync] rootDir:', paths.rootDir);
  console.log('[sync] fetching manifest…');
  pub.ux({ state: 'FETCH_MANIFEST', step: 'Récupération du manifest' });
  pub.status('Récupération du manifest');

  const raw = await apiClient.getManifest();
  const manifest = parseManifest(raw, { apiBaseUrl });

  pub.info({
    name: manifest.launcher.name,
    version: manifest.launcher.version,
    loader: manifest.launcher.loader,
  });

  console.log(`[sync] manifest ok: ${manifest.files.length} fichiers, total ${manifest.totalSize} bytes`);
  console.log(`[sync] launcher: ${manifest.launcher.name} v${manifest.launcher.version} (${manifest.launcher.loader || 'unknown'})`);

  const majorUpdate = !prevState || prevState.version !== manifest.launcher.version;
  if (!prevState) {
    console.log(`[state] no/invalid state.json (${stateLoad.reason || 'unknown'}), full validation`);
  } else if (majorUpdate) {
    console.log(`[state] version changed: ${prevState.version || '(none)'} -> ${manifest.launcher.version}`);
  }

  pub.ux({ state: 'SYNC', step: 'Comparaison des fichiers' });
  pub.status('Comparaison des fichiers');

  const diff = await planSync({
    manifest,
    paths,
    state: prevState,
    forceRevalidate: majorUpdate,
    useStateFastPath: true,
    onProgress: (p) => {
      if (!p) return;
      pub.progress({
        phase: 'SYNC',
        done: p.done,
        total: p.total,
        percent: p.percent,
        currentFile: '',
      });
    },
  });
  console.log(
    `[sync] compare: ok=${diff.ok.length}, toDownload=${diff.toDownload.length}, obsolete=${diff.obsolete.length}`
  );

  const totalFiles = manifest.files.length;
  const okCount = diff.ok.length;

  if (totalFiles === 0) {
    pub.ux({ state: 'READY' });
    pub.status('Launcher prêt');
    pub.progress({ done: 0, total: 0, percent: 0, currentFile: '' });
    return manifest;
  }

  pub.ux({ state: 'DOWNLOAD', step: 'Téléchargement' });
  pub.status('Téléchargement');
  pub.progress({
    phase: 'DOWNLOAD',
    done: okCount,
    total: totalFiles,
    percent: Math.floor((okCount / totalFiles) * 100),
    currentFile: '',
  });

  const downloadTotal = diff.toDownload.length;

  const downloadedHashes = await downloadMissingFiles({
    files: diff.toDownload,
    paths,
    headersFor: (f) => apiClient.headersForUrl(f && f.url ? f.url : ''),
    onProgress: (p) => {
      const downloaded = Number.isFinite(p.done) ? p.done : 0;
      const overallDone = okCount + downloaded;
      const percent = Math.floor((overallDone / totalFiles) * 100);
      pub.progress({
        phase: 'DOWNLOAD',
        done: overallDone,
        total: totalFiles,
        percent,
        currentFile: p.currentFile || '',
        bytesDone: p.bytesDone,
        bytesTotal: p.bytesTotal,
      });
    },
  });
  await deleteObsoleteFiles({ obsolete: diff.obsolete, paths });

  // Update local state only after a successful sync.
  const nextState = createEmptyState({ launcherId: uuid });
  nextState.version = manifest.launcher.version;
  nextState.last_sync = new Date().toISOString();
  nextState.installed_files = {};
  for (const f of manifest.files) {
    const keyPath = f.path;
    const h = (f.hash || downloadedHashes[keyPath] || diff.localHashes[keyPath] || (prevState && prevState.installed_files[keyPath]) || '')
      .trim()
      .toLowerCase();
    if (h) nextState.installed_files[keyPath] = h;
  }
  await saveState(paths, nextState);

  pub.ux({ state: 'READY' });
  pub.status('Launcher prêt');
  pub.progress({
    phase: 'DOWNLOAD',
    done: totalFiles,
    total: totalFiles,
    percent: 100,
    currentFile: '',
  });

  console.log('[sync] done');
  return manifest;
}

function formatUserError(err) {
  const msg = err && err.message ? String(err.message) : String(err || '');
  const code = err && err.code ? String(err.code) : '';

  if (msg.trim() === 'Votre abonnement a expiré') {
    return 'Votre abonnement a expiré';
  }

  if (msg.startsWith('Missing env var:')) {
    const name = msg.replace('Missing env var:', '').trim();
    return `Configuration manquante : variable d'environnement ${name}.`;
  }

  if (msg === 'API unreachable (offline/timeout)') {
    return "Erreur réseau : l'API est injoignable (hors-ligne ou délai dépassé).";
  }
  if (msg === 'Invalid JSON from API') {
    return 'Erreur API : réponse invalide (JSON).';
  }
  if (msg.startsWith('Manifest API error:')) {
    return `Erreur API : ${msg.replace('Manifest API error:', '').trim()}`;
  }
  if (msg.startsWith('Launcher API error:')) {
    return `Erreur API : ${msg.replace('Launcher API error:', '').trim()}`;
  }
  if (msg.startsWith('Update API error:')) {
    return `Erreur API : ${msg.replace('Update API error:', '').trim()}`;
  }
  const m = msg.match(/^download_http_(\d{3})$/);
  if (m) {
    return `Erreur réseau : téléchargement HTTP ${m[1]}.`;
  }
  if (msg === 'download_timeout') {
    return 'Erreur réseau : délai dépassé pendant le téléchargement.';
  }

  if (msg === 'update_https_required') {
    return 'Erreur : mise à jour non sécurisée (HTTPS requis).';
  }
  if (msg === 'update_signature_mismatch') {
    return 'Erreur : mise à jour bloquée (signature SHA256 invalide).';
  }
  if (msg === 'update_invalid_payload') {
    return 'Erreur API : réponse de mise à jour invalide.';
  }
  if (msg === 'update_payload_missing_app_asar') {
    return "Erreur : archive de mise à jour invalide (app.asar manquant).";
  }
  if (msg === 'zip_unsafe_path') {
    return "Erreur : archive de mise à jour invalide (chemin dangereux).";
  }

  if (
    code === 'ENOTFOUND' ||
    code === 'ECONNREFUSED' ||
    code === 'ECONNRESET' ||
    code === 'EAI_AGAIN' ||
    code === 'ETIMEDOUT'
  ) {
    return `Erreur réseau : connexion impossible (${code}).`;
  }
  if (msg.startsWith('sha1_mismatch')) {
    return 'Erreur : fichier corrompu (hash incorrect).';
  }
  return `Erreur : ${msg || 'inconnue'}`;
}

function createPublisher(win) {
  let ready = false;
  const queue = [];
  const lastByChannel = new Map();

  win.webContents.on('did-start-loading', () => {
    ready = false;
  });

  win.webContents.on('did-finish-load', () => {
    ready = true;
    for (const [channel, payload] of queue) {
      if (!win.isDestroyed()) win.webContents.send(channel, payload);
    }
    queue.length = 0;

    // Rehydrate UI after navigation/theme switch.
    // Order matters a bit: info -> ux -> status/progress -> error.
    const order = ['launcher:info', 'launcher:ux', 'launcher:status', 'launcher:progress', 'launcher:error'];
    for (const ch of order) {
      if (!lastByChannel.has(ch)) continue;
      try {
        if (!win.isDestroyed()) win.webContents.send(ch, lastByChannel.get(ch));
      } catch {
        // ignore
      }
    }
  });

  function send(channel, payload) {
    if (win.isDestroyed()) return;

     // Cache last known state for reloads/theme switches.
     lastByChannel.set(channel, payload);

    if (!ready) {
      queue.push([channel, payload]);
      return;
    }
    win.webContents.send(channel, payload);
  }

  return {
    status: (status) => send('launcher:status', { status }),
    progress: (p) => send('launcher:progress', p),
    info: (info) => send('launcher:info', info),
    error: (message) => send('launcher:error', { message }),
    ux: (payload) => send('launcher:ux', payload),
  };
}

function createWindow() {
  const win = new BrowserWindow({
    width: 520,
    height: 260,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      devTools: !app.isPackaged,
      preload: path.join(__dirname, 'preload.js'),
    },
  });

  // Fail-safe: if devtools get opened in production, stop.
  win.webContents.on('devtools-opened', () => {
    if (!app.isPackaged) return;
    try {
      win.webContents.closeDevTools();
    } catch {
      // ignore
    }
    try {
      dialog.showErrorBox('Sécurité', 'Le launcher a détecté un outil de debug et a été bloqué.');
    } catch {
      // ignore
    }
    app.quit();
  });

  // Default theme first; real theme will be selected after fetching the manifest.
  win.loadFile(path.join(__dirname, 'themes', 'default', 'index.html'));

  return win;
}

async function sha256FileHex(filePath) {
  return await new Promise((resolve, reject) => {
    const h = crypto.createHash('sha256');
    const s = fsSync.createReadStream(filePath);
    s.on('error', reject);
    s.on('data', (chunk) => h.update(chunk));
    s.on('end', () => resolve(h.digest('hex')));
  });
}

async function computeIntegrity() {
  if (!app.isPackaged) return { asar_sha256: '' };
  const asarPath = path.join(process.resourcesPath, 'app.asar');
  try {
    await fs.access(asarPath);
  } catch {
    return { asar_sha256: '' };
  }
  const asar_sha256 = await sha256FileHex(asarPath);
  return { asar_sha256 };
}

function normalizeThemeName(name) {
  const raw = typeof name === 'string' ? name : '';
  let s = raw.trim().toLowerCase();
  if (!s) return 'default';
  // Allow dashboard values like "Cosmic" or "Violet Neon" by slugifying.
  s = s.replace(/\s+/g, '-');
  s = s.replace(/[^a-z0-9_-]/g, '');
  s = s.replace(/^[-_]+|[-_]+$/g, '');
  if (!s || s.length > 64) return 'default';
  return s;
}

async function themeIndexPath(theme) {
  const t = normalizeThemeName(theme);
  const candidate = path.join(__dirname, 'themes', t, 'index.html');
  try {
    await fs.access(candidate);
    return candidate;
  } catch {
    return path.join(__dirname, 'themes', 'default', 'index.html');
  }
}

app.whenReady().then(async () => {
  // Anti-debug: block common Electron/Node debug flags in production.
  if (app.isPackaged) {
    const args = [...process.execArgv, ...process.argv].map((s) => String(s || ''));
    const hasDebug = args.some((a) =>
      a.includes('--inspect') ||
      a.includes('--remote-debugging-port') ||
      a.includes('--inspect-brk')
    );
    if (hasDebug) {
      try {
        dialog.showErrorBox('Sécurité', 'Le launcher a détecté un mode debug et a été bloqué.');
      } catch {
        // ignore
      }
      app.quit();
      return;
    }
  }

  const win = createWindow();
  const pub = createPublisher(win);

  let apiClient = null;
  let updateChecked = false;
  let integrity = { asar_sha256: '' };
  let lastManifest = null;
  let licenseState = { active: null, status: 'unknown', checkedAt: 0 };
  let currentGamePid = null;

  async function bootstrapApiClient() {
    if (apiClient) return;

    const uuid = requireEnv('LAUNCHER_UUID');
    const apiBaseUrl = requireEnv('API_BASE_URL');

    // Always check updates at startup (once per run).
    if (!updateChecked) {
      try {
        const localVersion = await readLocalVersion(app);
        // Keep a canonical local version file (requested by spec).
        try {
          await writeLocalVersion(app, app.getVersion());
        } catch {
          // ignore
        }
        await runAutoUpdate(app, pub, { apiBaseUrl, uuid, currentVersion: localVersion });
        updateChecked = true;
      } catch (e) {
        // If update check fails, block: cannot guarantee integrity.
        updateChecked = false;
        throw e;
      }
    }

    const apiKey = requireEnv('LAUNCHER_KEY');

    // Integrity hash is computed locally (packaged) and sent to the server for enforcement.
    try {
      integrity = await computeIntegrity();
    } catch {
      integrity = { asar_sha256: '' };
    }

    if (app.isPackaged) {
      const expected = (process.env.LAUNCHER_EXPECTED_ASAR_SHA256 || '').trim().toLowerCase();
      const got = (integrity.asar_sha256 || '').trim().toLowerCase();
      if (expected && got && expected !== got) {
        throw new Error('Le launcher a été modifié et a été bloqué.');
      }
      // If an expected hash is configured but we couldn't compute it, fail-safe.
      if (expected && !got) {
        throw new Error("Impossible de vérifier l'intégrité du launcher.");
      }
    }

    apiClient = createApiV2Client({
      apiBaseUrl,
      uuid,
      apiKey,
      integrityProvider: () => integrity,
    });

    // Ensure settings.json exists early.
    try {
      const paths = getLauncherPaths(app);
      await loadSettings(paths);
    } catch {
      // ignore
    }
  }

  ipcMain.handle('auth:getSession', async () => {
    const paths = getLauncherPaths(app);
    const session = await getSession(paths);
    return { ok: true, session };
  });

  ipcMain.handle('auth:logout', async () => {
    const paths = getLauncherPaths(app);
    await logout(paths);
    return { ok: true };
  });

  ipcMain.handle('auth:loginMicrosoft', async (event) => {
    const paths = getLauncherPaths(app);

    const session = await loginMicrosoft(paths, {
      onMsaCode: (data) => {
        const payload = isPlainObject(data)
          ? {
              user_code: typeof data.user_code === 'string' ? data.user_code : '',
              verification_uri: typeof data.verification_uri === 'string' ? data.verification_uri : '',
              message: typeof data.message === 'string' ? data.message : '',
            }
          : { user_code: '', verification_uri: '', message: '' };

        if (payload.verification_uri) {
          try {
            shell.openExternal(payload.verification_uri);
          } catch {
            // ignore
          }
        }
        try {
          event.sender.send('auth:msaCode', payload);
        } catch {
          // ignore
        }
      },
    });

    return { ok: true, session };
  });

  ipcMain.handle('launcher:openExternal', async (_event, url) => {
    const s = typeof url === 'string' ? url.trim() : '';
    if (!s) return { ok: false };
    let parsed;
    try {
      parsed = new URL(s);
    } catch {
      return { ok: false };
    }
    if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') return { ok: false };
    await shell.openExternal(parsed.toString());
    return { ok: true };
  });

  ipcMain.handle('settings:get', async () => {
    const paths = getLauncherPaths(app);
    const settings = await loadSettings(paths);
    const javaAuto = resolveJavaPath({ java_path: null });
    return { ok: true, settings, javaAuto };
  });

  ipcMain.handle('settings:update', async (_event, patch) => {
    const paths = getLauncherPaths(app);
    const settings = await updateSettings(paths, patch);
    const javaAuto = resolveJavaPath({ java_path: null });
    return { ok: true, settings, javaAuto };
  });

  ipcMain.handle('launcher:play', async () => {
    try {
      await bootstrapApiClient();

      // Enforce subscription on every play.
      const lic = await checkLicense(apiClient, { pub });
      licenseState = { active: lic.active, status: lic.status, checkedAt: Date.now() };
      if (!lic.active) {
        throw new Error('Votre abonnement a expiré');
      }

      // Short-lived token request right before launching the game.
      const tokenRes = await apiClient.mintPlayToken();
      if (!tokenRes || tokenRes.status !== 'active' || !tokenRes.token) {
        throw new Error("Impossible de valider l'abonnement (token).");
      }

      const paths = getLauncherPaths(app);
      const session = await getSession(paths);
      if (!session) throw new Error('Non connecté.');

      if (!lastManifest) {
        throw new Error('Manifest indisponible. Relance une synchronisation.');
      }

      pub.ux({ state: 'INIT' });
      const settings = await loadSettings(paths);
      const javaPath = resolveJavaPath(settings);
      const res = await launchMinecraft({
        paths,
        session,
        manifest: lastManifest,
        settings,
        javaPath,
        onStatus: (s) => pub.status(s),
        onLog: (line) => {
          if (line) console.log('[mc]', line);
        },
        onClose: () => {
          currentGamePid = null;
        },
      });

      currentGamePid = res && res.pid ? res.pid : null;

      return { ok: true, ...res };
    } catch (err) {
      throw new Error(formatUserError(err));
    }
  });

  let syncInProgress = false;
  async function startSync() {
    if (syncInProgress) return;
    syncInProgress = true;
    try {
        await bootstrapApiClient();

        pub.ux({ state: 'INIT' });
        pub.status("Vérification de l'abonnement…");
        const lic = await checkLicense(apiClient, { pub });
        licenseState = { active: lic.active, status: lic.status, checkedAt: Date.now() };

        if (!lic.active) {
          lastManifest = null;
          return;
        }

        lastManifest = await runSync(apiClient, pub);

        // Switch theme dynamically based on manifest.launcher.theme
        const theme = lastManifest && lastManifest.launcher ? lastManifest.launcher.theme : 'default';
        const nextIndex = await themeIndexPath(theme);
        console.log(`[ui] theme requested=${String(theme || '')} resolved=${normalizeThemeName(theme)} index=${nextIndex}`);
        const currentUrl = win.webContents.getURL();
        const nextUrl = `file://${nextIndex}`;
        if (currentUrl !== nextUrl) {
          await win.loadFile(nextIndex);
        }
    } catch (err) {
      if (err && err.safeUrl) {
        console.error('[sync] request:', err.safeUrl);
      }
      console.error('[sync] fatal:', err && err.stack ? err.stack : err);
      pub.ux({ state: 'ERROR' });
      pub.error(formatUserError(err));
    } finally {
      syncInProgress = false;
    }
  }

  ipcMain.handle('launcher:retry', async () => {
    startSync();
    return { ok: true };
  });

  ipcMain.handle('launcher:quit', async () => {
    app.quit();
    return { ok: true };
  });

  await startSync();

  // Bonus: periodic re-check; block launcher and optionally stop the game.
  const recheckMs = getLicenseRecheckMs();
  if (recheckMs > 0) {
    let consecutiveFailures = 0;
    setInterval(async () => {
      try {
        const lic = await checkLicense(apiClient, { pub });
        const now = Date.now();
        licenseState = { active: lic.active, status: lic.status, checkedAt: now };
        consecutiveFailures = 0;

        if (!lic.active) {
          lastManifest = null;

          if (currentGamePid) {
            try {
              process.kill(currentGamePid);
            } catch {
              // ignore
            }
          }
        }
      } catch (err) {
        consecutiveFailures++;
        if (err && err.safeUrl) console.error('[license] request:', err.safeUrl);
        console.error('[license] check failed:', err && err.stack ? err.stack : err);

        // Fail-safe: after repeated failures, block access.
        if (consecutiveFailures >= 3) {
          lastManifest = null;
          pub.ux({ state: 'ERROR' });
          pub.error("Impossible de vérifier l'abonnement. Vérifie ta connexion et réessaie.");
          if (currentGamePid) {
            try {
              process.kill(currentGamePid);
            } catch {
              // ignore
            }
          }
        }
      }
    }, recheckMs);
  }
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

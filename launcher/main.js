// MUST be first: populates process.env from bundled src/config.json.
require('./src/bootstrap-env');

const { app, BrowserWindow, ipcMain, shell, dialog } = require('electron');
const path = require('node:path');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const crypto = require('node:crypto');
const os = require('node:os');

// Setup debug logging to file
const logDir = path.join(os.homedir(), '.xyno-launcher-logs');
const logFile = path.join(logDir, `launcher-${Date.now()}.log`);
fsSync.mkdirSync(logDir, { recursive: true });

const logStream = fsSync.createWriteStream(logFile, { flags: 'a' });
function debugLog(message) {
  const timestamp = new Date().toISOString();
  const logMsg = `[${timestamp}] ${message}\n`;
  logStream.write(logMsg);
  console.log(logMsg);
}

debugLog('=== LAUNCHER STARTED ===');

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
const { ensureJavaForMinecraft, getRequiredJavaVersion } = require('./services/javaInstaller');
const { readLocalVersion, writeLocalVersion } = require('./services/versionStore');
const { runAutoUpdate } = require('./services/autoUpdate');
const { createRpc } = require('./services/discord');

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

// ---------------------------------------------------------------------------
// Xyno proxy helpers (extensions + custom auth)
//
// These endpoints (/api/launcher_ext.php, /api/launcher_auth.php) use plain
// uuid+key auth on purpose: they're thin proxies whose only job is to hide the
// upstream api_key from the Electron process. The v2 HMAC machinery is not
// needed here — Xyno performs the sensitive network calls server-side.
// ---------------------------------------------------------------------------

function buildProxyUrl(apiBaseUrl, pathname) {
  const base = new URL(apiBaseUrl);
  const p = pathname.startsWith('/') ? pathname : `/${pathname}`;
  return new URL(p, base).toString();
}

async function proxyRequest(urlString, { method = 'GET', bodyObj = null, timeoutMs = 10_000 } = {}) {
  const { request } = require('node:https');
  const { request: httpRequest } = require('node:http');
  const url = new URL(urlString);
  const body = bodyObj ? JSON.stringify(bodyObj) : '';
  const requestFn = url.protocol === 'http:' ? httpRequest : request;

  return await new Promise((resolve, reject) => {
    const req = requestFn(
      url,
      {
        method,
        headers: {
          Accept: 'application/json',
          ...(body ? { 'Content-Type': 'application/json; charset=utf-8' } : {}),
        },
        timeout: timeoutMs,
      },
      (res) => {
        const chunks = [];
        res.on('data', (d) => chunks.push(d));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try { json = JSON.parse(text); } catch { json = null; }
          resolve({ statusCode: res.statusCode || 0, json, text });
        });
      }
    );
    req.on('timeout', () => { const e = new Error('Request timeout'); e.code = 'ETIMEDOUT'; req.destroy(e); });
    req.on('error', (err) => reject(err));
    if (body) req.write(body);
    req.end();
  });
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
    const branding = res.branding && typeof res.branding === 'object' ? res.branding : null;
    const extensions = Array.isArray(res.extensions) ? res.extensions : null;
    const auth = res.auth && typeof res.auth === 'object' ? res.auth : null;

    // Marketplace (Bloc 3): owned items + filtered settings coming from
    // /api/v2/status.php. Older servers won't send this → we pass null.
    const mp = res.marketplace && typeof res.marketplace === 'object' ? res.marketplace : null;
    const marketplace = mp
      ? {
          owned: Array.isArray(mp.owned) ? mp.owned.filter((k) => typeof k === 'string') : [],
          settings: mp.settings && typeof mp.settings === 'object' ? mp.settings : {},
        }
      : null;

    const hasExtras = (branding && Object.keys(branding).length)
      || (extensions && extensions.length)
      || (auth && auth.mode)
      || (marketplace && (marketplace.owned.length || Object.keys(marketplace.settings).length));
    if (name || news.length || Object.keys(config).length || hasExtras) {
      pub.info({
        name,
        news,
        config,
        branding,
        extensions,
        auth,
        marketplace,
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
  return { ok: true, active, status: res ? res.status : 'unknown', res: res || null };
}

// ---------------------------------------------------------------------------
// Extensions helpers
// Parse the `extensions` array returned by /api/v2/status.php into a Map
// keyed by ext_key → { enabled, needs_api, name }.
// ---------------------------------------------------------------------------

function buildExtensionsMap(extensions) {
  const map = new Map();
  if (!Array.isArray(extensions)) return map;
  for (const e of extensions) {
    if (!e || typeof e !== 'object') continue;
    const key = typeof e.key === 'string' ? e.key.toLowerCase() : '';
    if (!key) continue;
    map.set(key, {
      enabled: !!e.enabled,
      needs_api: !!e.needs_api,
      name: typeof e.name === 'string' ? e.name : key,
    });
  }
  return map;
}

function isExtEnabled(map, key) {
  const e = map && map.get ? map.get(String(key || '').toLowerCase()) : null;
  return !!(e && e.enabled);
}

async function fetchExtensionData(apiBaseUrl, uuid, apiKey, extKey) {
  const url = new URL(buildProxyUrl(apiBaseUrl, '/api/launcher_ext.php'));
  url.searchParams.set('uuid', uuid);
  url.searchParams.set('key', apiKey);
  url.searchParams.set('ext', extKey);
  const res = await proxyRequest(url.toString(), { method: 'GET', timeoutMs: 8_000 });
  if (res.statusCode === 200 && res.json) return res.json;
  return null;
}

// ---------------------------------------------------------------------------
// Anti-cheat (classique) — pragmatic pre-launch vector scan.
// Real anti-cheat is OS-level and out of scope here; we only refuse to launch
// Minecraft when obvious injection flags/env vars are present.
// ---------------------------------------------------------------------------

const ANTICHEAT_BANNED_JAVA_FLAGS = ['-javaagent:', '-agentpath:', '-agentlib:'];
const ANTICHEAT_BANNED_ENV = [
  'LD_PRELOAD',
  'LD_AUDIT',
  'DYLD_INSERT_LIBRARIES',
  'DYLD_FORCE_FLAT_NAMESPACE',
  '_JAVA_OPTIONS',
  'JAVA_TOOL_OPTIONS',
];

function runAntiCheatGuard(settings, advanced = null) {
  const reasons = [];

  // Java args on settings — only available if the launcher UI/settings added a
  // free-form arg field. We defensively scan it regardless.
  const userArgs = settings && typeof settings.java_args === 'string' ? settings.java_args : '';
  if (userArgs) {
    for (const flag of ANTICHEAT_BANNED_JAVA_FLAGS) {
      if (userArgs.toLowerCase().includes(flag)) {
        reasons.push(`Argument Java interdit : ${flag}`);
      }
    }
  }

  // Process env vars — dynamic-linker preload is a common Minecraft injection
  // vector on Linux/macOS, and _JAVA_OPTIONS / JAVA_TOOL_OPTIONS inject args
  // into every spawned JVM.
  for (const name of ANTICHEAT_BANNED_ENV) {
    const val = (process.env[name] || '').trim();
    if (val) reasons.push(`Variable d'environnement interdite : ${name}`);
  }

  // Our own execArgv — not forwarded to the child JVM, but still a signal.
  const execArgs = Array.isArray(process.execArgv) ? process.execArgv.join(' ').toLowerCase() : '';
  for (const flag of ANTICHEAT_BANNED_JAVA_FLAGS) {
    if (execArgs.includes(flag)) reasons.push(`Process Electron lancé avec : ${flag}`);
  }

  // Advanced mode (anticheat_advanced marketplace item owned) — extra checks
  // driven by per-launcher settings fetched from /api/launcher_ext.php.
  if (advanced && typeof advanced === 'object') {
    // require_sha256: the launch would be blocked at bootstrapApiClient already
    // (LAUNCHER_EXPECTED_ASAR_SHA256 must match), but we duplicate the check
    // here so the tenant knows the feature is active even if they forgot to
    // bake the expected hash into the build env.
    if (advanced.require_sha256) {
      const expected = (process.env.LAUNCHER_EXPECTED_ASAR_SHA256 || '').trim();
      if (!expected) {
        reasons.push("Anti-cheat avancé : intégrité SHA-256 exigée mais non configurée à la build.");
      }
    }

    // Process blacklist: we do a best-effort scan using `ps`/`tasklist`.
    // This is synchronous and cheap but NOT bulletproof — bypassable by a
    // motivated attacker. It's meant to catch script kiddies running obvious
    // cheat clients before launch.
    const blacklist = Array.isArray(advanced.process_blacklist)
      ? advanced.process_blacklist.map((s) => String(s).toLowerCase().trim()).filter(Boolean)
      : [];

    if (blacklist.length > 0) {
      try {
        const cp = require('node:child_process');
        const isWin = process.platform === 'win32';
        const cmd = isWin ? 'tasklist /FO CSV /NH' : 'ps -A -o comm=';
        const out = cp.execSync(cmd, { timeout: 1500, encoding: 'utf8' }).toLowerCase();
        for (const proc of blacklist) {
          if (out.includes(proc)) {
            reasons.push(`Processus interdit détecté : ${proc}`);
          }
        }
      } catch {
        // ignore: if we can't scan, we don't block.
      }
    }
  }

  if (reasons.length) {
    const err = new Error('Anti-cheat : vecteur d\'injection détecté. ' + reasons.join(' | '));
    err.code = 'ANTICHEAT_BLOCKED';
    throw err;
  }
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

  // Diagnostic: log the RAW server response for the launcher block so we can
  // see whether `background_url` is even present before parseManifest runs.
  debugLog(`[bg] raw.launcher = ${JSON.stringify(raw && raw.launcher ? raw.launcher : null)}`);
  debugLog(`[bg] apiBaseUrl = ${JSON.stringify(apiBaseUrl)}`);

  const manifest = parseManifest(raw, { apiBaseUrl });

  // Log the resolved background URL into the main-process log file so we can
  // diagnose "background not showing" without needing DevTools. Empty string
  // means the manifest endpoint didn't include one (column missing, file not
  // on disk, or origin validation rejected it in parseManifest).
  debugLog(`[bg] manifest.launcher.backgroundUrl = ${JSON.stringify(manifest.launcher.backgroundUrl || '')}`);

  pub.info({
    name: manifest.launcher.name,
    version: manifest.launcher.version,
    loader: manifest.launcher.loader,
    backgroundUrl: manifest.launcher.backgroundUrl || '',
    logoUrl: manifest.launcher.logoUrl || '',
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
    width: 1400,
    height: 900,
    minWidth: 800,
    minHeight: 600,
    frame: false,
    titleBarStyle: 'hidden',
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      devTools: true,
      preload: path.join(__dirname, 'preload.js'),
    },
  });

  // Open DevTools automatically for debugging
  if (true) { // Enable devtools
    win.webContents.openDevTools({ mode: 'right' });
  }

  // Fail-safe: if devtools get opened in production, stop.
  // DISABLED for debugging purposes
  /*
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
  */

  // Default theme first; real theme will be selected after fetching the manifest.
  // Catch a possible ERR_ABORTED here: if the manifest theme differs from
  // default, startSync() will fire a second loadFile that supersedes this one,
  // which makes Electron reject this initial promise. That's expected — we
  // don't want it surfacing as an unhandled rejection.
  win.loadFile(path.join(__dirname, 'themes', 'default', 'index.html')).catch((err) => {
    const m = String(err && err.message || '');
    if (!m.includes('-3')) {
      console.error('[ui] initial loadFile failed:', err && err.stack ? err.stack : err);
    }
  });

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

  let mainWin = createWindow();
  const win = mainWin;
  const pub = createPublisher(win);

  let apiClient = null;
  let updateChecked = false;
  let integrity = { asar_sha256: '' };
  let lastManifest = null;
  let licenseState = { active: null, status: 'unknown', checkedAt: 0 };
  let currentGamePid = null;
  // Auth mode reported by the status endpoint ('microsoft' | 'custom' | 'offline').
  let lastAuthMode = 'microsoft';
  // In-memory custom session built after a successful auth:loginCustom call.
  let lastCustomSession = null;

  // Extensions / Discord RPC / maintenance state (populated on every sync).
  let extensionsMap = new Map();
  let launcherName = '';
  /** @type {ReturnType<typeof createRpc> | null} */
  let rpc = null;
  let rpcStarted = false;
  let rpcStartTimestamp = 0;

  async function ensureDiscordRpc(apiBaseUrl, uuid, apiKey) {
    if (rpcStarted) return; // one-shot handshake
    if (!isExtEnabled(extensionsMap, 'discord_rpc')) return;
    try {
      const payload = await fetchExtensionData(apiBaseUrl, uuid, apiKey, 'discord_rpc');
      const data = payload && payload.data ? payload.data : null;
      if (!data || !data.enabled) return;
      const clientId = typeof data.client_id === 'string' ? data.client_id.trim() : '';
      if (!clientId) return; // Admin hasn't configured XYNO_DISCORD_APP_ID yet.

      rpc = createRpc();
      const ok = await rpc.start(clientId);
      if (!ok) { rpc = null; return; }
      rpcStarted = true;
      rpcStartTimestamp = Date.now();

      const details = typeof data.details === 'string'
        ? data.details.replace('{launcher_name}', launcherName || 'Minecraft')
        : (launcherName || 'Minecraft');
      const state = typeof data.state === 'string' ? data.state : 'Powered by XynoWeb';

      // Advanced mode may provide up to 2 buttons; fall back to the classic CTA.
      let buttons;
      if (Array.isArray(data.buttons) && data.buttons.length > 0) {
        buttons = data.buttons
          .filter((b) => b && typeof b.label === 'string' && typeof b.url === 'string')
          .slice(0, 2)
          .map((b) => ({ label: b.label, url: b.url }));
      } else {
        const cta = data.cta && typeof data.cta === 'object' ? data.cta : null;
        buttons = cta && typeof cta.label === 'string' && typeof cta.url === 'string'
          ? [{ label: cta.label, url: cta.url }]
          : undefined;
      }

      await rpc.setActivity({
        details,
        state,
        startTimestamp: rpcStartTimestamp,
        buttons,
      });
    } catch (e) {
      // RPC is best-effort — never let Discord errors disrupt the launcher.
      console.warn('[rpc] start failed:', e && e.message ? e.message : e);
      try { if (rpc) rpc.disconnect(); } catch { /* ignore */ }
      rpc = null;
      rpcStarted = false;
    }
  }

  async function checkMaintenance(apiBaseUrl, uuid, apiKey) {
    if (!isExtEnabled(extensionsMap, 'maintenance')) return { active: false };
    try {
      const payload = await fetchExtensionData(apiBaseUrl, uuid, apiKey, 'maintenance');
      const data = payload && payload.data ? payload.data : null;
      if (!data) return { active: false };
      const active = !!(data.active === true || data.maintenance === true);
      const message = typeof data.message === 'string' && data.message.trim()
        ? data.message.trim()
        : 'Le launcher est en maintenance. Reviens plus tard.';
      return { active, message };
    } catch (e) {
      // Fail-open: tenant's maintenance API is down → don't block their users.
      console.warn('[maintenance] check failed:', e && e.message ? e.message : e);
      return { active: false };
    }
  }

  async function bootstrapApiClient() {
    if (apiClient) return;

    debugLog('[api] Bootstrapping API client...');
    const uuid = requireEnv('LAUNCHER_UUID');
    const apiBaseUrl = requireEnv('API_BASE_URL');
    debugLog(`[api] UUID: ${uuid.substring(0, 8)}..., Base URL: ${apiBaseUrl}`);

    // Always check updates at startup (once per run).
    if (!updateChecked) {
      try {
        debugLog('[api] Starting update check...');
        const localVersion = await readLocalVersion(app);
        debugLog(`[api] Local version: ${localVersion}`);
        // Keep a canonical local version file (requested by spec).
        try {
          await writeLocalVersion(app, app.getVersion());
        } catch {
          // ignore
        }
        debugLog('[api] Running autoUpdate...');
        await runAutoUpdate(app, pub, { apiBaseUrl, uuid, currentVersion: localVersion });
        debugLog('[api] Update check completed');
        updateChecked = true;
      } catch (e) {
        // If update check fails, block: cannot guarantee integrity.
        debugLog(`[api] Update check failed: ${e && e.message ? e.message : e}`);
        updateChecked = false;
        throw e;
      }
    }

    debugLog('[api] Creating API client...');
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

  // Window control handlers (for custom title bar)
  ipcMain.handle('window:minimize', () => {
    mainWin?.minimize();
  });

  ipcMain.handle('window:maximize', () => {
    if (mainWin?.isMaximized()) {
      mainWin.unmaximize();
    } else {
      mainWin?.maximize();
    }
  });

  ipcMain.handle('window:close', () => {
    mainWin?.close();
  });

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
    debugLog('[ipc] auth:loginMicrosoft handler invoked');
    const paths = getLauncherPaths(app);

    // Tie the OAuth popup to the launcher window that initiated the request so
    // it stays in front of the launcher and the user can't accidentally focus
    // a different launcher window while signing in.
    const parentWindow = BrowserWindow.fromWebContents(event.sender) || null;

    try {
      const session = await loginMicrosoft(paths, { debugLog, parentWindow });
      debugLog(`[ipc] auth:loginMicrosoft succeeded, returning session for: ${session && session.username}`);
      return { ok: true, session };
    } catch (err) {
      debugLog(`[ipc] auth:loginMicrosoft failed: ${err && err.message ? err.message : err}`);
      throw err;
    }
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

  // Extension proxy: GET /api/launcher_ext.php?uuid=…&key=…&ext=<key>
  // Returns { ok, data?: {key, source, data}, error? }. Never exposes the
  // upstream api_key because the proxy server-side is the only one who has it.
  ipcMain.handle('launcher:fetchExtension', async (_event, rawKey) => {
    try {
      const key = String(rawKey || '').trim().toLowerCase();
      if (!key || !/^[a-z0-9_]{1,64}$/.test(key)) {
        return { ok: false, error: 'invalid_ext_key' };
      }
      const uuid = requireEnv('LAUNCHER_UUID');
      const apiBaseUrl = requireEnv('API_BASE_URL');
      const apiKey = requireEnv('LAUNCHER_KEY');

      const url = new URL(buildProxyUrl(apiBaseUrl, '/api/launcher_ext.php'));
      url.searchParams.set('uuid', uuid);
      url.searchParams.set('key', apiKey);
      url.searchParams.set('ext', key);

      const res = await proxyRequest(url.toString(), { method: 'GET', timeoutMs: 8_000 });
      if (res.statusCode === 200 && res.json) {
        return { ok: true, data: res.json };
      }
      const msg = res.json && res.json.error ? String(res.json.error) : `HTTP ${res.statusCode}`;
      return { ok: false, error: msg };
    } catch (err) {
      return { ok: false, error: err && err.message ? String(err.message) : 'network_error' };
    }
  });

  // Custom Bearer auth: POST /api/launcher_auth.php with { uuid, key, action, … }
  // Xyno proxies to the tenant's login_url / verify_url server-side so the
  // upstream api_key never leaves our backend.
  async function callAuthProxy(bodyObj) {
    const uuid = requireEnv('LAUNCHER_UUID');
    const apiBaseUrl = requireEnv('API_BASE_URL');
    const apiKey = requireEnv('LAUNCHER_KEY');
    const url = buildProxyUrl(apiBaseUrl, '/api/launcher_auth.php');
    const res = await proxyRequest(url, {
      method: 'POST',
      bodyObj: { uuid, key: apiKey, ...bodyObj },
      timeoutMs: 12_000,
    });
    if (res.statusCode >= 200 && res.statusCode < 300 && res.json) {
      return { ok: true, data: res.json };
    }
    const msg = res.json && res.json.error ? String(res.json.error) : `HTTP ${res.statusCode}`;
    const upstream = res.json && typeof res.json.upstream_status === 'number' ? res.json.upstream_status : 0;
    return { ok: false, error: msg, statusCode: res.statusCode, upstream_status: upstream };
  }

  ipcMain.handle('auth:loginCustom', async (_event, payload) => {
    try {
      const email = payload && typeof payload.email === 'string' ? payload.email.trim() : '';
      const password = payload && typeof payload.password === 'string' ? payload.password : '';
      if (!email || !password) return { ok: false, error: 'missing_credentials' };
      const result = await callAuthProxy({ action: 'login', email, password });
      // Persist the custom session in main-process memory so the play handler
      // can build a valid authorization even though no auth.json is on disk.
      if (result && result.ok && result.data) {
        const upstream = (result.data && result.data.data) ? result.data.data : (result.data || {});
        const username = typeof upstream.username === 'string' ? upstream.username.trim() : email;
        const uuid = typeof upstream.uuid === 'string' ? upstream.uuid.trim() : '';
        const access_token = typeof upstream.token === 'string' ? upstream.token.trim() : '';
        if (username && uuid && access_token) {
          lastCustomSession = { type: 'custom', username, uuid, access_token };
          debugLog(`[auth] custom session stored in memory for: ${username}`);
        }
      }
      return result;
    } catch (err) {
      return { ok: false, error: err && err.message ? String(err.message) : 'network_error' };
    }
  });

  ipcMain.handle('auth:verifyCustom', async (_event, payload) => {
    try {
      const token = payload && typeof payload.token === 'string' ? payload.token.trim() : '';
      if (!token) return { ok: false, error: 'missing_token' };
      return await callAuthProxy({ action: 'verify', token });
    } catch (err) {
      return { ok: false, error: err && err.message ? String(err.message) : 'network_error' };
    }
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
    debugLog('[play] === PLAY HANDLER START ===');

    // Wrap an awaitable in a hard timeout so a misbehaving network call (e.g.
    // a stalled API endpoint) can't keep the user staring at the loading bar
    // forever. Throws a labelled error on timeout that ends up in the UI.
    const withTimeout = (promise, ms, label) => {
      let timer;
      const t = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(`${label} timeout après ${Math.round(ms / 1000)}s`)), ms);
      });
      return Promise.race([promise, t]).finally(() => clearTimeout(timer));
    };

    try {
      pub.ux({ state: 'INIT' });
      pub.status('Préparation du lancement…');
      pub.progress({ done: 0, total: 0, percent: 0, currentFile: '' });

      pub.status("Connexion à l'API Xyno…");
      debugLog('[play] step 1: bootstrapApiClient');
      await withTimeout(bootstrapApiClient(), 15_000, "Connexion à l'API");

      // Enforce subscription on every play.
      pub.status("Vérification de l'abonnement…");
      debugLog('[play] step 2: checkLicense');
      const lic = await withTimeout(checkLicense(apiClient, { pub }), 15_000, 'Vérification abonnement');
      licenseState = { active: lic.active, status: lic.status, checkedAt: Date.now() };
      // Refresh auth mode from the latest status response.
      if (lic.res && lic.res.auth && typeof lic.res.auth.mode === 'string') {
        const m = lic.res.auth.mode.toLowerCase();
        if (m === 'offline' || m === 'custom' || m === 'microsoft') lastAuthMode = m;
        debugLog(`[play] auth mode refreshed: ${lastAuthMode}`);
      }
      if (!lic.active) {
        debugLog('[play] license inactive, aborting');
        throw new Error('Votre abonnement a expiré');
      }

      // Short-lived token request right before launching the game.
      pub.status('Demande du jeton de session…');
      debugLog('[play] step 3: mintPlayToken');
      const tokenRes = await withTimeout(apiClient.mintPlayToken(), 15_000, 'Jeton de session');
      if (!tokenRes || tokenRes.status !== 'active' || !tokenRes.token) {
        throw new Error("Impossible de valider l'abonnement (token).");
      }

      pub.status('Chargement de la session…');
      debugLog(`[play] step 4: getSession (authMode=${lastAuthMode})`);
      const paths = getLauncherPaths(app);
      let session = null;

      if (lastAuthMode === 'offline') {
        // Offline mode: synthesize a local session — no Microsoft account needed.
        // Minecraft will launch in offline/cracked mode; online-mode servers will
        // refuse the connection but that is expected behaviour for offline auth.
        session = {
          type: 'offline',
          username: 'Player',
          uuid: crypto.randomUUID().replace(/-/g, ''),
          access_token: crypto.randomUUID().replace(/-/g, ''),
        };
        debugLog('[play] offline session created');
      } else if (lastAuthMode === 'custom') {
        // Custom Bearer auth: session was stored in memory by auth:loginCustom.
        session = lastCustomSession;
        if (!session) throw new Error("Non connecté. Connecte-toi d'abord avec tes identifiants.");
        debugLog(`[play] custom session ok, user=${session.username}`);
      } else {
        // Default: Microsoft OAuth session persisted on disk.
        session = await getSession(paths);
        if (!session) throw new Error('Non connecté.');
        debugLog(`[play] microsoft session ok, user=${session.username}`);
      }

      if (!lastManifest) {
        throw new Error('Manifest indisponible. Relance une synchronisation.');
      }

      pub.status('Lecture des paramètres du launcher…');
      debugLog('[play] step 5: loadSettings');
      const settings = await loadSettings(paths);

      // Anti-cheat (classique) — refuse to launch if injection vectors are
      // present. Opt-in per launcher via the `anticheat` extension toggle.
      // If `anticheat_advanced` is owned (Bloc 3 marketplace), the payload
      // returned by /api/launcher_ext.php?ext=anticheat includes a process
      // blacklist and integrity requirements.
      if (isExtEnabled(extensionsMap, 'anticheat')) {
        pub.status('Vérification anti-cheat…');
        debugLog('[play] step 6: anti-cheat');
        let advanced = null;
        try {
          const uuidForExt = requireEnv('LAUNCHER_UUID');
          const apiKeyForExt = requireEnv('LAUNCHER_KEY');
          const apiBaseUrlForExt = requireEnv('API_BASE_URL');
          const payload = await withTimeout(
            fetchExtensionData(apiBaseUrlForExt, uuidForExt, apiKeyForExt, 'anticheat'),
            10_000,
            'Anti-cheat',
          );
          const data = payload && payload.data ? payload.data : null;
          if (data && data.mode === 'advanced') {
            advanced = {
              require_sha256: !!data.require_sha256,
              process_blacklist: Array.isArray(data.process_blacklist) ? data.process_blacklist : [],
            };
          }
        } catch (extErr) {
          // If we can't reach the server, fall back to classic mode.
          debugLog(`[play] anti-cheat advanced fetch failed (using classic mode): ${extErr && extErr.message}`);
        }
        runAntiCheatGuard(settings, advanced);
      }

      // Java path resolution. Order:
      //   1. If the user explicitly set a java_path in settings, respect it.
      //   2. Otherwise, download/reuse the right Adoptium Temurin JRE for the
      //      Minecraft version of this launcher. This avoids the "I have
      //      Java 25, why does Forge 1.13.2 crash?" class of bugs entirely.
      let javaPath = '';
      const manualJava = settings && typeof settings.java_path === 'string' ? settings.java_path.trim() : '';
      if (manualJava) {
        debugLog('[play] step 7: resolveJavaPath (user-configured)');
        javaPath = resolveJavaPath(settings);
        debugLog(`[play] java=${javaPath} (manual)`);
      } else {
        const mcVersion = lastManifest && lastManifest.launcher ? lastManifest.launcher.version : '';
        const requiredMajor = getRequiredJavaVersion(mcVersion);
        pub.status(`Préparation de Java ${requiredMajor}…`);
        debugLog(`[play] step 7: ensureJavaForMinecraft (mc=${mcVersion}, want=Java ${requiredMajor})`);
        try {
          javaPath = await ensureJavaForMinecraft(paths.rootDir, mcVersion, {
            debugLog,
            onStatus: (s) => { if (s) pub.status(s); },
            onProgress: (p) => {
              if (!p) return;
              const total = Number(p.total) || 0;
              const task = Number(p.task) || 0;
              const percent = total > 0 ? Math.min(100, Math.round((task / total) * 100)) : 0;
              pub.progress({ done: task, total, percent, currentFile: 'Java' });
            },
          });
          debugLog(`[play] java=${javaPath} (auto-installed)`);
          pub.progress({ done: 0, total: 0, percent: 0, currentFile: '' });
        } catch (err) {
          debugLog(`[play] auto-install Java failed: ${err && err.message ? err.message : err}`);
          // Fall back to system detection if the auto-install fails (e.g. no
          // network). Better to try the user's system Java than to refuse
          // launch entirely.
          javaPath = resolveJavaPath(settings);
          debugLog(`[play] falling back to system Java: ${javaPath || '(none)'}`);
        }
      }

      // launchMinecraft now reports detailed progress through onStatus/onProgress.
      // - onStatus receives free-form French strings for the loading text.
      // - onProgress receives MCLC progress events ({ type, task, total }) and
      //   we translate them into a percent for the UI bar.
      const res = await launchMinecraft({
        paths,
        session,
        manifest: lastManifest,
        settings,
        javaPath,
        debugLog,
        onStatus: (s) => {
          if (s) {
            debugLog(`[play] status: ${s}`);
            pub.status(s);
          }
        },
        onProgress: (p) => {
          if (!p) return;
          const total = Number(p.total) || 0;
          const task = Number(p.task) || 0;
          const percent = total > 0 ? Math.min(100, Math.round((task / total) * 100)) : 0;
          pub.progress({
            done: task,
            total,
            percent,
            currentFile: p.type ? `${p.type}` : '',
          });
        },
        onLog: (line) => {
          if (line) console.log('[mc]', line);
        },
        onClose: () => {
          currentGamePid = null;
          // Swap RPC back to "dans le launcher" once the game closes.
          if (rpc && rpcStarted) {
            const cleanName = launcherName || 'Minecraft';
            rpc.setActivity({
              details: cleanName,
              state: 'Powered by XynoWeb',
              startTimestamp: rpcStartTimestamp || Date.now(),
              buttons: [{ label: 'Créer le tien', url: 'https://xynoweb.fr' }],
            }).catch(() => {});
          }
        },
      });
      debugLog(`[play] launchMinecraft returned: pid=${res && res.pid}, version=${res && res.version}`);
      pub.progress({ done: 0, total: 0, percent: 0, currentFile: '' });

      currentGamePid = res && res.pid ? res.pid : null;

      // Update RPC presence to reflect the user is now in-game.
      if (rpc && rpcStarted) {
        const cleanName = launcherName || 'Minecraft';
        rpc.setActivity({
          details: `En jeu — ${cleanName}`,
          state: 'Powered by XynoWeb',
          startTimestamp: Date.now(),
          buttons: [{ label: 'Créer le tien', url: 'https://xynoweb.fr' }],
        }).catch(() => {});
      }

      return { ok: true, ...res };
    } catch (err) {
      throw new Error(formatUserError(err));
    }
  });

  let syncInProgress = false;
  let loadedThemeKey = 'default';
  async function startSync() {
    if (syncInProgress) return;
    syncInProgress = true;
    try {
        debugLog('[sync] startSync called, bootstrapping API client...');
        await bootstrapApiClient();
        debugLog('[sync] API client ready');

        pub.ux({ state: 'INIT' });
        pub.status("Vérification de l'abonnement…");
        debugLog('[sync] Checking license...');
        const lic = await checkLicense(apiClient, { pub });
        debugLog(`[sync] License check complete: active=${lic.active}, status=${lic.status}`);
        licenseState = { active: lic.active, status: lic.status, checkedAt: Date.now() };
        // Keep the auth mode in sync so the play handler knows which session to use.
        if (lic.res && lic.res.auth && typeof lic.res.auth.mode === 'string') {
          const m = lic.res.auth.mode.toLowerCase();
          if (m === 'offline' || m === 'custom' || m === 'microsoft') lastAuthMode = m;
          debugLog(`[sync] auth mode: ${lastAuthMode}`);
        }

        if (!lic.active) {
          debugLog('[sync] License is not active, aborting sync');
          lastManifest = null;
          return;
        }

        // Parse extensions from status response so maintenance / RPC know their
        // enabled flags without an extra round-trip.
        const res = lic.res || {};
        extensionsMap = buildExtensionsMap(res.extensions);
        if (res.launcher && typeof res.launcher.name === 'string') {
          launcherName = res.launcher.name;
        }

        // Maintenance gate: if the tenant's maintenance API says "active",
        // short-circuit before we even fetch the manifest.
        debugLog('[sync] Checking maintenance status...');
        const uuidForExt = requireEnv('LAUNCHER_UUID');
        const apiBaseUrlForExt = requireEnv('API_BASE_URL');
        const apiKeyForExt = requireEnv('LAUNCHER_KEY');
        const maintenance = await checkMaintenance(apiBaseUrlForExt, uuidForExt, apiKeyForExt);
        debugLog(`[sync] Maintenance check complete: active=${maintenance.active}`);
        if (maintenance.active) {
          lastManifest = null;
          pub.ux({
            state: 'BLOCKED',
            message: maintenance.message,
            renewUrl: '',
            status: 'maintenance',
          });
          return;
        }

        // Auto-update check: done early so we can restart with the latest version
        // before syncing the manifest. Best-effort: if update fails, we continue anyway.
        debugLog('[sync] Checking for launcher updates...');
        try {
          const appVersion = app.getVersion();
          const updateResult = await runAutoUpdate(mainWin || win, pub, {
            apiBaseUrl: apiBaseUrlForExt,
            uuid: uuidForExt,
            currentVersion: appVersion,
            debugLog,
          });
          debugLog(`[sync] Auto-update check complete: ok=${updateResult.ok}, updated=${updateResult.updated}, required=${updateResult.required}`);
          // Note: if updateResult.updated is true, runAutoUpdate will have called app.exit()
          // and we won't reach this line. The launcher will restart with the new version.
        } catch (updateErr) {
          // Auto-update is best-effort; log but don't fail the sync
          const errMsg = updateErr && updateErr.message ? updateErr.message : String(updateErr);
          debugLog(`[sync] Auto-update check failed (non-critical, continuing): ${errMsg}`);
        }

        // Fire-and-forget Discord Rich Presence (never blocks the sync).
        debugLog('[sync] Starting Discord RPC...');
        ensureDiscordRpc(apiBaseUrlForExt, uuidForExt, apiKeyForExt).catch(() => {});

        debugLog('[sync] Running sync...');
        lastManifest = await runSync(apiClient, pub);
        debugLog(`[sync] Sync complete, manifest exists: ${!!lastManifest}`);

        // Switch theme dynamically based on manifest.launcher.theme.
        // We compare normalized theme keys (not file URLs) to avoid spurious
        // reloads caused by encoding/translocation differences in the URL.
        const theme = lastManifest && lastManifest.launcher ? lastManifest.launcher.theme : 'default';
        const nextThemeKey = normalizeThemeName(theme);
        const nextIndex = await themeIndexPath(theme);
        debugLog(`[ui] theme requested=${String(theme || '')} resolved=${nextThemeKey}`);
        if (loadedThemeKey !== nextThemeKey) {
          try {
            debugLog(`[ui] Loading theme file: ${nextIndex}`);
            await win.loadFile(nextIndex);
            debugLog(`[ui] Theme loaded successfully`);
            loadedThemeKey = nextThemeKey;
          } catch (loadErr) {
            // Electron's loadFile rejects with -3 (ERR_ABORTED) whenever a
            // newer navigation supersedes this one — that's expected when
            // the boot loadFile is still in flight. Swallow it; the latest
            // navigation will still complete on its own.
            const m = String(loadErr && loadErr.message || '');
            if (!m.includes('-3')) {
              debugLog(`[ui] loadFile failed: ${m}`);
              throw loadErr;
            }
            debugLog(`[ui] loadFile aborted (-3), ignoring`);
          }
        } else {
          debugLog(`[ui] Theme already loaded (${nextThemeKey}), skipping reload`);
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
    try { if (rpc) rpc.disconnect(); } catch { /* ignore */ }
    app.quit();
    return { ok: true };
  });

  app.on('before-quit', () => {
    try { if (rpc) rpc.disconnect(); } catch { /* ignore */ }
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

  // -------------------------------------------------------------------------
  // Heartbeat (admin observability).
  //
  // POST /api/launcher_heartbeat.php every 30s with version, OS, theme,
  // uptime, tick rate (real interval since last beat) and current UX state.
  // Best-effort: any failure is swallowed, the launcher keeps working.
  // -------------------------------------------------------------------------
  try {
    const sessionId   = crypto.randomUUID();
    const sessionStart = Date.now();
    let lastBeatAt    = sessionStart;
    let beatTimer     = null;

    async function sendHeartbeat() {
      try {
        const uuid       = requireEnv('LAUNCHER_UUID');
        const apiBaseUrl = requireEnv('API_BASE_URL');
        const apiKey     = requireEnv('LAUNCHER_KEY');
        const url = buildProxyUrl(apiBaseUrl, '/api/launcher_heartbeat.php');

        const now = Date.now();
        const tickRateMs = now - lastBeatAt;
        lastBeatAt = now;

        const body = {
          uuid,
          key: apiKey,
          app_version: app.getVersion(),
          os: process.platform,                  // 'darwin' | 'win32' | 'linux'
          os_version: os.release(),              // e.g. "23.4.0"
          arch: process.arch,                    // 'x64' | 'arm64'
          theme: loadedThemeKey || 'default',
          uptime_s: Math.round((now - sessionStart) / 1000),
          tick_rate_ms: tickRateMs,
          session_id: sessionId,
          state: licenseState && licenseState.status
            ? String(licenseState.status)
            : 'unknown',
        };

        await proxyRequest(url, { method: 'POST', bodyObj: body, timeoutMs: 6000 });
      } catch (e) {
        // Heartbeat is best-effort.
        // console.warn('[heartbeat] failed:', e && e.message ? e.message : e);
      }
    }

    // Fire one immediately, then every 30 seconds.
    sendHeartbeat();
    beatTimer = setInterval(sendHeartbeat, 30_000);

    app.on('before-quit', () => {
      if (beatTimer) {
        clearInterval(beatTimer);
        beatTimer = null;
      }
    });
  } catch (e) {
    console.warn('[heartbeat] init failed:', e && e.message ? e.message : e);
  }
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

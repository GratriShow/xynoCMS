'use strict';

const fsp = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const { request } = require('node:https');
const { request: httpRequest } = require('node:http');

const { Client } = require('minecraft-launcher-core');

function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function normalizeLoader(loader) {
  const s = String(loader || '').trim().toLowerCase();
  if (!s || s === 'vanilla') return 'vanilla';
  if (s === 'forge') return 'forge';
  if (s === 'neoforge') return 'neoforge';
  return s;
}

function getRequestFn(url) {
  return url.protocol === 'http:' ? httpRequest : request;
}

async function getText(url, { timeoutMs = 15_000 } = {}) {
  const requestFn = getRequestFn(url);
  return await new Promise((resolve, reject) => {
    const req = requestFn(
      url,
      { method: 'GET', timeout: timeoutMs, headers: { Accept: '*/*' } },
      (res) => {
        const chunks = [];
        res.on('data', (d) => chunks.push(d));
        res.on('end', () => {
          const body = Buffer.concat(chunks).toString('utf8');
          resolve({ statusCode: res.statusCode || 0, body });
        });
      }
    );
    req.on('timeout', () => req.destroy(Object.assign(new Error('timeout'), { code: 'ETIMEDOUT' })));
    req.on('error', (err) => reject(err));
    req.end();
  });
}

async function getJson(url, opts) {
  const res = await getText(url, opts);
  if (res.statusCode !== 200) {
    const err = new Error(`HTTP ${res.statusCode}`);
    err.statusCode = res.statusCode;
    throw err;
  }
  try {
    return JSON.parse(res.body);
  } catch {
    throw new Error('Invalid JSON');
  }
}

async function fileExists(p) {
  try {
    await fsp.access(p);
    return true;
  } catch {
    return false;
  }
}

async function ensureDir(p) {
  await fsp.mkdir(p, { recursive: true });
}

async function downloadToFile(url, destPath, { timeoutMs = 60_000 } = {}) {
  await ensureDir(path.dirname(destPath));
  const tmpPath = destPath + '.tmp';

  const requestFn = getRequestFn(url);
  await new Promise((resolve, reject) => {
    const req = requestFn(url, { method: 'GET', timeout: timeoutMs }, (res) => {
      if ((res.statusCode || 0) !== 200) {
        reject(new Error(`download_http_${res.statusCode || 0}`));
        res.resume();
        return;
      }
      const out = require('node:fs').createWriteStream(tmpPath);
      res.pipe(out);
      out.on('finish', () => out.close(resolve));
      out.on('error', reject);
    });
    req.on('timeout', () => req.destroy(Object.assign(new Error('download_timeout'), { code: 'ETIMEDOUT' })));
    req.on('error', reject);
    req.end();
  });

  await fsp.rename(tmpPath, destPath);
  return destPath;
}

async function runJavaInstaller(javaPath, installerPath, args, { cwd } = {}) {
  return await new Promise((resolve, reject) => {
    const exe = javaPath || 'java';
    const child = spawn(exe, ['-jar', installerPath, ...args], {
      cwd: cwd || process.cwd(),
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (d) => {
      stdout += d.toString('utf8');
      if (stdout.length > 16_000) stdout = stdout.slice(-16_000);
    });
    child.stderr.on('data', (d) => {
      stderr += d.toString('utf8');
      if (stderr.length > 16_000) stderr = stderr.slice(-16_000);
    });

    child.on('error', (err) => {
      if (err && err.code === 'ENOENT') {
        reject(new Error('Java introuvable. Installe Java (JRE/JDK) pour installer Forge/NeoForge.'));
      } else {
        reject(err);
      }
    });

    child.on('close', (code) => {
      if (code === 0) return resolve({ ok: true });
      const msg = [stdout, stderr].filter(Boolean).join('\n').trim();
      reject(new Error(`Installation échouée (code=${code}).\n${msg}`.trim()));
    });
  });
}

function getVanillaVersionJsonPath(paths, mcVersion) {
  return path.join(paths.versionsDir, mcVersion, `${mcVersion}.json`);
}

function getLauncherProfilesPath(paths) {
  return path.join(paths.rootDir, 'launcher_profiles.json');
}

async function ensureLauncherProfiles(paths, { mcVersion } = {}) {
  const p = getLauncherProfilesPath(paths);
  if (await fileExists(p)) return p;

  const now = new Date().toISOString();
  const profileName = 'xyno';
  const payload = {
    profiles: {
      [profileName]: {
        name: profileName,
        type: 'custom',
        created: now,
        lastUsed: now,
        icon: 'Furnace',
        lastVersionId: String(mcVersion || '').trim() || undefined,
      },
    },
    selectedProfile: profileName,
    clientToken: crypto.randomUUID(),
  };

  // Remove undefined fields for cleaner JSON.
  if (!payload.profiles[profileName].lastVersionId) delete payload.profiles[profileName].lastVersionId;

  await ensureDir(path.dirname(p));
  await fsp.writeFile(p, JSON.stringify(payload, null, 2) + '\n', 'utf8');
  return p;
}

function getForgeProfileName(mcVersion, forgeBuild) {
  return `${mcVersion}-forge-${forgeBuild}`;
}

function getVersionJsonPath(paths, versionName) {
  return path.join(paths.versionsDir, versionName, `${versionName}.json`);
}

async function resolveForgeBuild(mcVersion) {
  const url = new URL('https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json');
  const json = await getJson(url);
  const promos = json && json.promos ? json.promos : null;
  if (!promos || typeof promos !== 'object') throw new Error('Impossible de récupérer les versions Forge.');

  const rec = promos[`${mcVersion}-recommended`];
  const latest = promos[`${mcVersion}-latest`];
  const build = (rec || latest || '').trim ? (rec || latest).trim() : String(rec || latest || '').trim();
  if (!build) throw new Error(`Aucune version Forge trouvée pour Minecraft ${mcVersion}.`);
  return build;
}

function forgeInstallerUrl(mcVersion, forgeBuild) {
  const full = `${mcVersion}-${forgeBuild}`;
  return new URL(
    `https://maven.minecraftforge.net/net/minecraftforge/forge/${full}/forge-${full}-installer.jar`
  );
}

async function listVersionDirs(paths) {
  let entries = [];
  try {
    entries = await fsp.readdir(paths.versionsDir, { withFileTypes: true });
  } catch (err) {
    if (err && err.code === 'ENOENT') return [];
    throw err;
  }
  return entries.filter((e) => e.isDirectory()).map((e) => e.name);
}

async function detectNewVersionDir(paths, before, { hint } = {}) {
  const after = await listVersionDirs(paths);
  const beforeSet = new Set(before);
  const created = after.filter((n) => !beforeSet.has(n));
  if (created.length === 1) return created[0];
  if (created.length > 1) {
    const h = String(hint || '').toLowerCase();
    const match = created.find((n) => n.toLowerCase().includes(h));
    if (match) return match;
    return created[0];
  }
  return '';
}

async function ensureForgeInstalled({ paths, mcVersion, onStatus, javaPath } = {}) {
  const forgeBuild = await resolveForgeBuild(mcVersion);
  const profileName = getForgeProfileName(mcVersion, forgeBuild);
  const profileJson = getVersionJsonPath(paths, profileName);

  if (await fileExists(profileJson)) {
    return { profileName, forgeBuild, installed: true };
  }

  const installerDir = path.join(paths.installersDir, 'forge', `${mcVersion}-${forgeBuild}`);
  const installerPath = path.join(installerDir, `forge-${mcVersion}-${forgeBuild}-installer.jar`);
  const installerUrl = forgeInstallerUrl(mcVersion, forgeBuild);

  if (!(await fileExists(installerPath))) {
    if (typeof onStatus === 'function') onStatus(`Téléchargement de Forge ${forgeBuild}…`);
    await downloadToFile(installerUrl, installerPath);
  }

  if (typeof onStatus === 'function') onStatus('Installation de Forge…');
  await ensureLauncherProfiles(paths, { mcVersion });
  const before = await listVersionDirs(paths);
  await runJavaInstaller(javaPath, installerPath, ['--installClient', paths.rootDir], { cwd: paths.rootDir });

  if (await fileExists(profileJson)) {
    return { profileName, forgeBuild, installed: true };
  }

  const created = await detectNewVersionDir(paths, before, { hint: 'forge' });
  if (created && (await fileExists(getVersionJsonPath(paths, created)))) {
    return { profileName: created, forgeBuild, installed: true };
  }

  throw new Error('Installation Forge terminée mais profil introuvable dans versions/.');
}

async function resolveNeoForgeVersion(mcVersion) {
  // NeoForge versioning uses `major.minor.patch` matching MC `1.major.minor` -> `major.minor.*`
  const parts = String(mcVersion || '').trim().split('.');
  if (parts.length < 3 || parts[0] !== '1') {
    throw new Error(`Version Minecraft invalide pour NeoForge: ${mcVersion}`);
  }
  const prefix = `${parts[1]}.${parts[2]}.`;
  const url = new URL('https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml');
  const res = await getText(url, { timeoutMs: 15_000 });
  if (res.statusCode !== 200) throw new Error('Impossible de récupérer les versions NeoForge.');

  const versions = Array.from(res.body.matchAll(/<version>([^<]+)<\/version>/g)).map((m) => m[1]);
  const candidates = versions.filter((v) => typeof v === 'string' && v.startsWith(prefix));
  if (candidates.length === 0) {
    throw new Error(`Aucune version NeoForge trouvée pour Minecraft ${mcVersion}.`);
  }
  // Sort semver-ish by numeric segments.
  candidates.sort((a, b) => {
    const pa = a.split('.').map((n) => Number(n) || 0);
    const pb = b.split('.').map((n) => Number(n) || 0);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
      const da = pa[i] || 0;
      const db = pb[i] || 0;
      if (da !== db) return db - da;
    }
    return 0;
  });
  return candidates[0];
}

function neoforgeInstallerUrl(neoforgeVersion) {
  return new URL(
    `https://maven.neoforged.net/releases/net/neoforged/neoforge/${neoforgeVersion}/neoforge-${neoforgeVersion}-installer.jar`
  );
}

async function ensureNeoForgeInstalled({ paths, mcVersion, onStatus, javaPath } = {}) {
  const neoforgeVersion = await resolveNeoForgeVersion(mcVersion);
  const installerDir = path.join(paths.installersDir, 'neoforge', neoforgeVersion);
  const installerPath = path.join(installerDir, `neoforge-${neoforgeVersion}-installer.jar`);
  const installerUrl = neoforgeInstallerUrl(neoforgeVersion);

  // We cannot predict the final profile name reliably; detect it after install.
  const before = await listVersionDirs(paths);

  if (!(await fileExists(installerPath))) {
    if (typeof onStatus === 'function') onStatus(`Téléchargement de NeoForge ${neoforgeVersion}…`);
    await downloadToFile(installerUrl, installerPath);
  }

  if (typeof onStatus === 'function') onStatus('Installation de NeoForge…');
  await ensureLauncherProfiles(paths, { mcVersion });
  await runJavaInstaller(javaPath, installerPath, ['--installClient', paths.rootDir], { cwd: paths.rootDir });

  const created = await detectNewVersionDir(paths, before, { hint: 'neoforge' });
  if (created && (await fileExists(getVersionJsonPath(paths, created)))) {
    return { profileName: created, neoforgeVersion, installed: true };
  }

  // Fallback: any version dir containing neoforge.
  const after = await listVersionDirs(paths);
  const candidate = after.find((n) => n.toLowerCase().includes('neoforge'));
  if (candidate && (await fileExists(getVersionJsonPath(paths, candidate)))) {
    return { profileName: candidate, neoforgeVersion, installed: true };
  }

  throw new Error('Installation NeoForge terminée mais profil introuvable dans versions/.');
}

async function resolveLaunchVersion({ paths, manifest, onStatus, javaPath } = {}) {
  if (!manifest || !isPlainObject(manifest.launcher)) {
    throw new Error('Manifest invalide (missing launcher).');
  }

  const mcVersion = String(manifest.launcher.version || '').trim();
  if (!mcVersion) throw new Error('Manifest invalide : launcher.version manquant.');

  const loader = normalizeLoader(manifest.launcher.loader);

  if (loader === 'vanilla') {
    const jsonPath = getVanillaVersionJsonPath(paths, mcVersion);
    const installed = await fileExists(jsonPath);
    if (!installed && typeof onStatus === 'function') {
      onStatus(`Installation de Minecraft ${mcVersion}…`);
    }
    // MCLC téléchargera automatiquement si absent.
    return { number: mcVersion, type: 'release' };
  }

  if (loader === 'forge') {
    const { profileName } = await ensureForgeInstalled({ paths, mcVersion, onStatus, javaPath });
    return { number: mcVersion, type: 'release', custom: profileName };
  }

  if (loader === 'neoforge') {
    const { profileName } = await ensureNeoForgeInstalled({ paths, mcVersion, onStatus, javaPath });
    return { number: mcVersion, type: 'release', custom: profileName };
  }

  throw new Error(`Loader non supporté : ${loader}`);
}

function buildAuthorization(session) {
  const uuid = String(session.uuid || '').trim();
  const name = String(session.username || '').trim();
  const access_token = String(session.access_token || '').trim();

  if (!uuid || !name || !access_token) {
    throw new Error('Invalid session (missing username/uuid/access_token)');
  }

  return {
    access_token,
    client_token: crypto.randomUUID(),
    uuid,
    name,
    user_properties: {},
    meta: {
      type: 'msa',
    },
  };
}

// Translate a minecraft-launcher-core `progress` event type to a French
// human-readable label. MCLC emits one of: 'assets', 'classes', 'natives'.
function describeMclcProgressType(type) {
  const t = String(type || '').toLowerCase();
  if (t === 'assets') return 'des assets Minecraft';
  if (t === 'classes' || t === 'libraries') return 'des bibliothèques';
  if (t === 'natives') return 'des fichiers natifs';
  return t ? `de ${t}` : '';
}

async function launchMinecraft({ paths, session, manifest, settings, javaPath, onStatus, onProgress, onLog, onClose, debugLog } = {}) {
  const log = typeof onLog === 'function' ? onLog : () => {};
  const status = typeof onStatus === 'function' ? onStatus : () => {};
  const progress = typeof onProgress === 'function' ? onProgress : () => {};
  const dlog = typeof debugLog === 'function' ? debugLog : () => {};

  // Step A: figure out which version we need to launch (vanilla / Forge / NeoForge).
  // For Forge & NeoForge, this triggers the installer download/run if needed.
  status('Préparation de la version Minecraft…');
  dlog('[mc] resolveLaunchVersion: starting');
  const version = await resolveLaunchVersion({ paths, manifest, onStatus, javaPath });
  dlog(`[mc] resolveLaunchVersion: ok, name=${version.custom || version.number}`);

  status('Préparation du moteur de lancement…');
  const launcher = new Client();

  launcher.on('debug', (l) => log(String(l || '')));
  launcher.on('data', (d) => log(String(d || '')));

  // MCLC emits 'progress' for every batch of files it downloads (assets,
  // libraries, natives). Without this, the UI just sits at "Lancement…" while
  // 700 MB silently downloads. We translate the type into a friendly status
  // line and forward the raw {type, task, total} so the UI can show a bar.
  let lastProgressType = '';
  launcher.on('progress', (p) => {
    if (!p) return;
    const type = p.type ? String(p.type) : '';
    if (type && type !== lastProgressType) {
      lastProgressType = type;
      const label = describeMclcProgressType(type);
      if (label) status(`Téléchargement ${label}…`);
      dlog(`[mc] progress phase changed: ${type}`);
    }
    progress({ type, task: p.task || 0, total: p.total || 0 });
  });

  // 'arguments' fires once MCLC has built the full JVM command line, right
  // before spawning the process. This is the signal that all downloads are
  // done and the JVM is about to start.
  launcher.on('arguments', () => {
    status('Démarrage de la JVM Java…');
    dlog('[mc] arguments built, JVM about to start');
  });

  launcher.on('close', (code) => {
    log(`Minecraft fermé (code=${code})`);
    dlog(`[mc] close event, code=${code}`);
  });

  const ramMinMb = settings && Number.isFinite(Number(settings.ram_min)) ? Math.trunc(Number(settings.ram_min)) : 2048;
  const ramMaxMb = settings && Number.isFinite(Number(settings.ram_max)) ? Math.trunc(Number(settings.ram_max)) : 4096;

  const launchOpts = {
    authorization: buildAuthorization(session),
    root: paths.rootDir,
    version,
    memory: {
      max: `${ramMaxMb}M`,
      min: `${ramMinMb}M`,
    },
  };

  if (javaPath) {
    launchOpts.javaPath = String(javaPath);
  }

  const fullscreen = Boolean(settings && settings.fullscreen);
  const resolution = settings && settings.resolution && typeof settings.resolution === 'object' ? settings.resolution : null;
  if (fullscreen || resolution) {
    launchOpts.window = {
      fullscreen,
    };
    if (resolution && Number.isFinite(Number(resolution.width)) && Number.isFinite(Number(resolution.height))) {
      launchOpts.window.width = String(Math.trunc(Number(resolution.width)));
      launchOpts.window.height = String(Math.trunc(Number(resolution.height)));
    }
  }

  status('Vérification des fichiers Minecraft…');
  dlog('[mc] launcher.launch() called');
  const child = launcher.launch(launchOpts);

  // Give MCLC a moment to either spawn the JVM or fail. If after 10 minutes
  // we still don't have a pid, something is very wrong (MCLC stuck on a slow
  // download or unable to find Java) and we want to surface that to the user
  // rather than let them stare at the loading bar. 10 minutes is long enough
  // for a fresh install on a 5 Mbps connection (~700 MB).
  const LAUNCH_TIMEOUT_MS = 10 * 60 * 1000;
  if (child && typeof child.then === 'function') {
    // Older MCLC versions return a Promise; resolve it with a timeout.
    await Promise.race([
      child,
      new Promise((_, reject) => setTimeout(
        () => reject(new Error("Le lancement de Minecraft a dépassé 10 minutes — vérifie ta connexion et l'installation Java.")),
        LAUNCH_TIMEOUT_MS,
      )),
    ]);
  }

  if (child && typeof onClose === 'function' && typeof child.on === 'function') {
    try {
      child.on('close', () => onClose());
    } catch {
      // ignore
    }
  }

  status('Minecraft est lancé. Bon jeu !');
  return {
    ok: true,
    pid: child && child.pid ? child.pid : null,
    version: version.custom || version.number,
    loader: manifest && manifest.launcher ? normalizeLoader(manifest.launcher.loader) : undefined,
  };
}

module.exports = {
  resolveLaunchVersion,
  launchMinecraft,
};

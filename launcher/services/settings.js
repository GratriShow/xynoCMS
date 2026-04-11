'use strict';

const fsp = require('node:fs/promises');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const DEFAULT_SETTINGS = {
  ram_min: 2048,
  ram_max: 4096,
  java_path: null,
  fullscreen: false,
  // Optional; if present, should be { width, height }
  resolution: null,
};

function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function toInt(v) {
  const n = typeof v === 'string' && v.trim() ? Number(v) : Number(v);
  if (!Number.isFinite(n)) return NaN;
  return Math.trunc(n);
}

function normalizeJavaPath(value) {
  if (value === null || value === undefined) return null;
  const s = String(value).trim();
  if (!s) return null;

  // Allow passing a JAVA_HOME-like directory.
  const looksLikeDir = s.endsWith(path.sep) || (!path.extname(s) && !s.toLowerCase().endsWith('java') && !s.toLowerCase().endsWith('java.exe'));
  const candidate = looksLikeDir ? path.join(s, 'bin', process.platform === 'win32' ? 'java.exe' : 'java') : s;

  return candidate;
}

function javaPathExists(p) {
  if (!p) return false;
  try {
    const st = fs.statSync(p);
    return st.isFile();
  } catch {
    return false;
  }
}

function normalizeResolution(value) {
  if (value === null || value === undefined) return null;
  if (!isPlainObject(value)) return null;
  const w = toInt(value.width);
  const h = toInt(value.height);
  if (!Number.isFinite(w) || !Number.isFinite(h)) return null;
  return { width: w, height: h };
}

function mergeSettings(base, incoming) {
  const out = { ...base };
  if (!isPlainObject(incoming)) return out;

  if ('ram_min' in incoming) out.ram_min = toInt(incoming.ram_min);
  if ('ram_max' in incoming) out.ram_max = toInt(incoming.ram_max);
  if ('java_path' in incoming) out.java_path = incoming.java_path;
  if ('fullscreen' in incoming) out.fullscreen = Boolean(incoming.fullscreen);
  if ('resolution' in incoming) out.resolution = incoming.resolution;

  // Normalize types
  out.ram_min = toInt(out.ram_min);
  out.ram_max = toInt(out.ram_max);
  out.java_path = normalizeJavaPath(out.java_path);
  out.fullscreen = Boolean(out.fullscreen);
  out.resolution = normalizeResolution(out.resolution);

  return out;
}

function validateSettings(settings) {
  const ramMin = toInt(settings.ram_min);
  const ramMax = toInt(settings.ram_max);

  if (!Number.isFinite(ramMin) || !Number.isFinite(ramMax)) {
    throw new Error('Paramètres RAM invalides.');
  }

  // Reasonable boundaries (MB)
  if (ramMin < 512) throw new Error('RAM min trop faible (min 512 Mo).');
  if (ramMax < 1024) throw new Error('RAM max trop faible (min 1024 Mo).');
  if (ramMax > 16 * 1024) throw new Error('RAM max trop élevée (max 16 Go).');
  if (ramMin >= ramMax) throw new Error('RAM min doit être inférieure à RAM max.');

  if (settings.java_path !== null && typeof settings.java_path !== 'string') {
    throw new Error('Chemin Java invalide.');
  }
  if (typeof settings.java_path === 'string' && settings.java_path) {
    if (!javaPathExists(settings.java_path)) {
      throw new Error('Chemin Java introuvable.');
    }
  }
  if (typeof settings.fullscreen !== 'boolean') {
    throw new Error('Paramètre fullscreen invalide.');
  }

  if (settings.resolution !== null) {
    const r = settings.resolution;
    if (!r || typeof r.width !== 'number' || typeof r.height !== 'number') {
      throw new Error('Résolution invalide.');
    }
    if (r.width < 640 || r.height < 480) throw new Error('Résolution trop faible.');
    if (r.width > 7680 || r.height > 4320) throw new Error('Résolution trop élevée.');
  }

  return true;
}

async function ensureDir(p) {
  await fsp.mkdir(p, { recursive: true });
}

async function fileExists(p) {
  try {
    await fsp.access(p);
    return true;
  } catch {
    return false;
  }
}

function getBundledDefaultsPath() {
  // In source tree, a template file exists at ../Launcher/settings.json
  return path.join(__dirname, '..', 'Launcher', 'settings.json');
}

async function loadBundledDefaults() {
  const p = getBundledDefaultsPath();
  try {
    const raw = await fsp.readFile(p, 'utf8');
    const json = JSON.parse(raw);
    return isPlainObject(json) ? json : null;
  } catch {
    return null;
  }
}

function getSettingsPath(paths) {
  return path.join(paths.rootDir, 'settings.json');
}

async function loadSettings(paths) {
  if (!paths || !paths.rootDir) throw new Error('Invalid paths');
  await ensureDir(paths.rootDir);

  const bundled = await loadBundledDefaults();
  const base = mergeSettings(DEFAULT_SETTINGS, bundled);

  const p = getSettingsPath(paths);
  if (!(await fileExists(p))) {
    validateSettings(base);
    await saveSettings(paths, base);
    return base;
  }

  let disk;
  try {
    const raw = await fsp.readFile(p, 'utf8');
    disk = JSON.parse(raw);
  } catch {
    disk = null;
  }

  const merged = mergeSettings(base, disk);

  // If a previously saved manual java path is no longer valid, fallback to auto.
  if (typeof merged.java_path === 'string' && merged.java_path && !javaPathExists(merged.java_path)) {
    merged.java_path = null;
  }

  validateSettings(merged);

  // If file was missing optional keys, keep it updated (non-blocking).
  try {
    await saveSettings(paths, merged);
  } catch {
    // ignore
  }

  return merged;
}

async function saveSettings(paths, settings) {
  const p = getSettingsPath(paths);
  await ensureDir(path.dirname(p));

  const payload = {
    ram_min: toInt(settings.ram_min),
    ram_max: toInt(settings.ram_max),
    java_path: settings.java_path === null ? null : String(settings.java_path),
    fullscreen: Boolean(settings.fullscreen),
  };

  // Optional
  if (settings.resolution) {
    payload.resolution = { width: toInt(settings.resolution.width), height: toInt(settings.resolution.height) };
  }

  const tmp = p + '.tmp';
  await fsp.writeFile(tmp, JSON.stringify(payload, null, 2) + '\n', 'utf8');
  await fsp.rename(tmp, p);
}

async function updateSettings(paths, patch) {
  const current = await loadSettings(paths);
  const next = mergeSettings(current, patch);
  validateSettings(next);
  await saveSettings(paths, next);
  return next;
}

function tryRun(cmd, args) {
  try {
    const res = spawnSync(cmd, args, { encoding: 'utf8' });
    if (res && res.status === 0) return String(res.stdout || '').trim();
  } catch {
    // ignore
  }
  return '';
}

function detectJavaExecutable() {
  const exeName = process.platform === 'win32' ? 'java.exe' : 'java';

  const envHome = String(process.env.JAVA_HOME || '').trim();
  if (envHome) {
    const p = path.join(envHome, 'bin', exeName);
    if (fs.existsSync(p)) return p;
  }

  if (process.platform === 'darwin') {
    const home = tryRun('/usr/libexec/java_home', []);
    if (home) {
      const p = path.join(home, 'bin', exeName);
      if (fs.existsSync(p)) return p;
    }
  }

  const which = process.platform === 'win32' ? tryRun('where', ['java']) : tryRun('which', ['java']);
  if (which) {
    const first = which.split(/\r?\n/)[0].trim();
    if (first && fs.existsSync(first)) return first;
  }

  return null;
}

function resolveJavaPath(settings) {
  const manual = normalizeJavaPath(settings && settings.java_path);
  if (manual && javaPathExists(manual)) return manual;
  return detectJavaExecutable();
}

module.exports = {
  DEFAULT_SETTINGS,
  loadSettings,
  saveSettings,
  updateSettings,
  validateSettings,
  detectJavaExecutable,
  resolveJavaPath,
};

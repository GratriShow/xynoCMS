const fsp = require('node:fs/promises');
const path = require('node:path');

function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function isValidSha1(hex) {
  return typeof hex === 'string' && /^[a-f0-9]{40}$/i.test(hex);
}

function getStatePath(paths) {
  return path.join(paths.rootDir, 'state.json');
}

function createEmptyState({ launcherId }) {
  return {
    launcher_id: String(launcherId || ''),
    version: '',
    installed_files: {},
    last_sync: '',
  };
}

function normalizeInstalledFiles(v) {
  if (!isPlainObject(v)) return null;
  const out = {};
  for (const [k, val] of Object.entries(v)) {
    if (typeof k !== 'string' || !k) continue;
    if (typeof val !== 'string') continue;
    const h = val.trim().toLowerCase();
    if (!h) continue;
    if (!isValidSha1(h)) continue;
    out[k] = h;
  }
  return out;
}

function validateState(raw, { launcherId } = {}) {
  if (!isPlainObject(raw)) return { ok: false, reason: 'not_object' };

  const launcher_id = typeof raw.launcher_id === 'string' ? raw.launcher_id.trim() : '';
  const version = typeof raw.version === 'string' ? raw.version.trim() : '';
  const last_sync = typeof raw.last_sync === 'string' ? raw.last_sync.trim() : '';

  if (!launcher_id) return { ok: false, reason: 'missing_launcher_id' };
  if (launcherId && launcher_id !== String(launcherId)) {
    return { ok: false, reason: 'launcher_id_mismatch' };
  }

  const installed_files = normalizeInstalledFiles(raw.installed_files);
  if (!installed_files) return { ok: false, reason: 'invalid_installed_files' };

  return {
    ok: true,
    state: {
      launcher_id,
      version,
      installed_files,
      last_sync,
    },
  };
}

async function loadState(paths, { launcherId } = {}) {
  const statePath = getStatePath(paths);
  let text;
  try {
    text = await fsp.readFile(statePath, 'utf8');
  } catch (err) {
    if (err && err.code === 'ENOENT') {
      return { ok: false, reason: 'missing', statePath };
    }
    return { ok: false, reason: 'read_error', statePath };
  }

  let json;
  try {
    json = JSON.parse(text);
  } catch {
    return { ok: false, reason: 'json_parse', statePath };
  }

  const validated = validateState(json, { launcherId });
  if (!validated.ok) {
    return { ok: false, reason: validated.reason, statePath };
  }
  return { ok: true, state: validated.state, statePath };
}

async function saveState(paths, state) {
  const statePath = getStatePath(paths);
  const tmpPath = statePath + '.tmp';
  const payload = JSON.stringify(state, null, 2) + '\n';
  await fsp.mkdir(path.dirname(statePath), { recursive: true });
  await fsp.writeFile(tmpPath, payload, 'utf8');

  try {
    await fsp.rename(tmpPath, statePath);
  } catch (err) {
    // Some platforms/filesystems don't allow rename-over-existing.
    if (err && (err.code === 'EEXIST' || err.code === 'EPERM' || err.code === 'ENOTEMPTY')) {
      try {
        await fsp.unlink(statePath);
      } catch (e) {
        if (!(e && e.code === 'ENOENT')) throw e;
      }
      await fsp.rename(tmpPath, statePath);
    } else {
      throw err;
    }
  }
  return statePath;
}

module.exports = {
  getStatePath,
  createEmptyState,
  validateState,
  loadState,
  saveState,
};

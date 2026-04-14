'use strict';

const path = require('node:path');
const fs = require('node:fs/promises');

function versionFilePath(app) {
  return path.join(app.getPath('userData'), 'version.json');
}

function normalizeVersion(v) {
  const s = String(v || '').trim();
  if (!s) return '';
  // Minimal semver-ish validation: 1.2.3 or 1.2.3-beta.1
  if (!/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9a-zA-Z.-]+)?$/.test(s)) return '';
  return s;
}

async function readLocalVersion(app) {
  const fallback = normalizeVersion(app.getVersion && typeof app.getVersion === 'function' ? app.getVersion() : '') || '0.0.0';

  const filePath = versionFilePath(app);
  try {
    const raw = await fs.readFile(filePath, 'utf8');
    const json = JSON.parse(raw);
    const v = json && typeof json.version === 'string' ? normalizeVersion(json.version) : '';
    return v || fallback;
  } catch {
    return fallback;
  }
}

async function writeLocalVersion(app, version) {
  const v = normalizeVersion(version);
  if (!v) throw new Error('invalid_local_version');

  const filePath = versionFilePath(app);
  const tmp = filePath + '.tmp';
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(tmp, JSON.stringify({ version: v }, null, 2) + '\n', 'utf8');
  await fs.rename(tmp, filePath);
  return { ok: true, path: filePath, version: v };
}

module.exports = {
  readLocalVersion,
  writeLocalVersion,
};

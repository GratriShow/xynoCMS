'use strict';

const path = require('node:path');
const os = require('node:os');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const crypto = require('node:crypto');
const { request } = require('node:https');

function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function normalizeSemver(v) {
  const s = String(v || '').trim();
  if (!s) return '';
  const m = s.match(/^v?(\d+)\.(\d+)\.(\d+)(?:[-+].*)?$/);
  if (!m) return '';
  return `${Number(m[1])}.${Number(m[2])}.${Number(m[3])}`;
}

function compareSemver(a, b) {
  const na = normalizeSemver(a);
  const nb = normalizeSemver(b);
  if (!na || !nb) return 0;
  const pa = na.split('.').map((x) => Number(x));
  const pb = nb.split('.').map((x) => Number(x));
  for (let i = 0; i < 3; i++) {
    const da = pa[i] || 0;
    const db = pb[i] || 0;
    if (da > db) return 1;
    if (da < db) return -1;
  }
  return 0;
}

async function httpGetJson(url, { timeoutMs = 10_000 } = {}) {
  return await new Promise((resolve, reject) => {
    const req = request(
      url,
      {
        method: 'GET',
        headers: { Accept: 'application/json' },
        timeout: timeoutMs,
      },
      (res) => {
        const chunks = [];
        res.on('data', (d) => chunks.push(d));
        res.on('end', () => {
          const statusCode = res.statusCode || 0;
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(text);
          } catch {
            json = null;
          }
          resolve({ statusCode, json, text });
        });
      }
    );

    req.on('timeout', () => {
      const err = new Error('update_timeout');
      err.code = 'ETIMEDOUT';
      req.destroy(err);
    });
    req.on('error', (err) => reject(err));
    req.end();
  });
}

async function downloadFileWithSha256(url, destPath, { timeoutMs = 60_000, onProgress = null } = {}) {
  if (!url || url.protocol !== 'https:') {
    throw new Error('update_https_required');
  }

  await fs.mkdir(path.dirname(destPath), { recursive: true });

  return await new Promise((resolve, reject) => {
    const file = fsSync.createWriteStream(destPath);
    const hash = crypto.createHash('sha256');

    const req = request(
      url,
      {
        method: 'GET',
        timeout: timeoutMs,
        headers: {
          Accept: 'application/octet-stream',
        },
      },
      (res) => {
        const code = res.statusCode || 0;
        if (code < 200 || code >= 300) {
          res.resume();
          reject(new Error(`download_http_${code}`));
          return;
        }

        const total = Number(res.headers['content-length'] || 0) || 0;
        let done = 0;

        res.on('data', (chunk) => {
          done += chunk.length;
          hash.update(chunk);
          if (typeof onProgress === 'function') {
            try {
              const percent = total > 0 ? Math.floor((done / total) * 100) : 0;
              onProgress({ bytesDone: done, bytesTotal: total, percent });
            } catch {
              // ignore
            }
          }
        });

        res.pipe(file);

        file.on('finish', () => {
          file.close(() => {
            resolve({
              path: destPath,
              bytes: done,
              sha256: hash.digest('hex'),
            });
          });
        });

        file.on('error', (err) => {
          try {
            fsSync.unlinkSync(destPath);
          } catch {
            // ignore
          }
          reject(err);
        });
      }
    );

    req.on('timeout', () => {
      const err = new Error('download_timeout');
      err.code = 'ETIMEDOUT';
      req.destroy(err);
    });

    req.on('error', (err) => {
      try {
        fsSync.unlinkSync(destPath);
      } catch {
        // ignore
      }
      reject(err);
    });

    req.end();
  });
}

function validateUpdatePayload(payload) {
  if (!isPlainObject(payload)) return null;
  const version = typeof payload.version === 'string' ? payload.version.trim() : '';
  const url = typeof payload.url === 'string' ? payload.url.trim() : '';
  const signature = typeof payload.signature === 'string' ? payload.signature.trim().toLowerCase() : '';
  const required = !!payload.required;

  if (!version && !url && !signature) {
    return { version: '', url: '', signature: '', required: false };
  }
  if (!version || !url || !signature) return null;
  if (!/^[a-f0-9]{64}$/.test(signature)) return null;

  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  if (parsed.protocol !== 'https:') return null;

  return { version, url: parsed.toString(), signature, required };
}

function defaultTempDir(app) {
  try {
    return app.getPath('temp');
  } catch {
    return os.tmpdir();
  }
}

async function ensureEmptyDir(dir) {
  await fs.rm(dir, { recursive: true, force: true });
  await fs.mkdir(dir, { recursive: true });
}

async function extractZipSafe(zipPath, destDir, { onProgress = null } = {}) {
  const yauzl = require('yauzl');

  await fs.mkdir(destDir, { recursive: true });
  const destRoot = path.resolve(destDir);

  return await new Promise((resolve, reject) => {
    yauzl.open(zipPath, { lazyEntries: true }, (err, zipfile) => {
      if (err || !zipfile) return reject(err || new Error('zip_open_failed'));

      const total = zipfile.entryCount || 0;
      let done = 0;

      function safePath(entryName) {
        const name = String(entryName || '');
        if (!name) return null;
        if (name.includes('\\')) return null;
        if (name.startsWith('/')) return null;
        if (name.includes('\u0000')) return null;
        if (/(^|\/)\.\.(\/|$)/.test(name)) return null;
        const out = path.resolve(destRoot, name);
        if (!out.startsWith(destRoot + path.sep) && out !== destRoot) return null;
        return { name, out };
      }

      zipfile.readEntry();

      zipfile.on('entry', (entry) => {
        const s = safePath(entry.fileName);
        if (!s) {
          zipfile.close();
          reject(new Error('zip_unsafe_path'));
          return;
        }

        const isDir = /\/$/.test(s.name);
        if (isDir) {
          fs.mkdir(s.out, { recursive: true })
            .then(() => {
              done++;
              if (typeof onProgress === 'function') {
                try {
                  const percent = total > 0 ? Math.floor((done / total) * 100) : 0;
                  onProgress({ done, total, percent, currentFile: s.name });
                } catch {
                  // ignore
                }
              }
              zipfile.readEntry();
            })
            .catch((e) => {
              zipfile.close();
              reject(e);
            });
          return;
        }

        fs.mkdir(path.dirname(s.out), { recursive: true })
          .then(() => {
            zipfile.openReadStream(entry, (e, readStream) => {
              if (e || !readStream) {
                zipfile.close();
                reject(e || new Error('zip_stream_failed'));
                return;
              }

              const writeStream = fsSync.createWriteStream(s.out, { mode: 0o644 });
              readStream.on('error', (streamErr) => {
                try {
                  writeStream.close();
                } catch {
                  // ignore
                }
                zipfile.close();
                reject(streamErr);
              });
              writeStream.on('error', (streamErr) => {
                try {
                  readStream.destroy();
                } catch {
                  // ignore
                }
                zipfile.close();
                reject(streamErr);
              });
              writeStream.on('finish', () => {
                done++;
                if (typeof onProgress === 'function') {
                  try {
                    const percent = total > 0 ? Math.floor((done / total) * 100) : 0;
                    onProgress({ done, total, percent, currentFile: s.name });
                  } catch {
                    // ignore
                  }
                }
                zipfile.readEntry();
              });

              readStream.pipe(writeStream);
            });
          })
          .catch((e) => {
            zipfile.close();
            reject(e);
          });
      });

      zipfile.on('end', () => resolve({ ok: true, entries: total }));
      zipfile.on('error', (e) => reject(e));
    });
  });
}

function resolveAsarFromExtract(extractDir) {
  const candidates = [
    path.join(extractDir, 'resources', 'app.asar'),
    path.join(extractDir, 'app.asar'),
  ];
  for (const p of candidates) {
    try {
      if (fsSync.existsSync(p) && fsSync.statSync(p).isFile()) return p;
    } catch {
      // ignore
    }
  }
  return '';
}

async function checkForUpdate({ apiBaseUrl, uuid, timeoutMs = 10_000 } = {}) {
  const base = new URL(String(apiBaseUrl || ''));

  const uuidVal = String(uuid || '').trim();
  if (!uuidVal) throw new Error('Missing env var: LAUNCHER_UUID');

  const candidates = [
    // Dedicated standalone file — works on any nginx/apache without PATH_INFO.
    '/api/launcher_update.php',
    // Pretty URL — requires nginx rewrite to /api/launcher.php/update.
    '/api/launcher/update',
    // PATH_INFO fallback — requires nginx location ~ \.php(/|$).
    '/api/launcher.php/update',
  ];

  // On considère un 200 comme "JSON attendu". Si on reçoit un 200 + HTML
  // (fallback SPA/index.php du serveur), on ignore ce candidat et on passe
  // au suivant plutôt que d'échouer tout de suite.

  let lastRes = null;
  let sawMalformed = false;
  for (const p of candidates) {
    const url = new URL(p, base);
    url.searchParams.set('uuid', uuidVal);

    const res = await httpGetJson(url, { timeoutMs });
    lastRes = res;
    if (res.statusCode !== 200) continue;

    // If the server returns 200 with HTML (common when the URL falls through
    // to a SPA/index.php fallback), res.json will be null. Treat as "wrong
    // route" and try the next candidate before giving up.
    if (res.json === null || res.json === undefined) {
      sawMalformed = true;
      continue;
    }

    const parsed = validateUpdatePayload(res.json);
    if (!parsed) {
      sawMalformed = true;
      continue;
    }
    return parsed;
  }

  if (sawMalformed) throw new Error('update_invalid_payload');
  throw new Error(`Update API error: HTTP ${(lastRes && lastRes.statusCode) || 0}`);
}

async function runAutoUpdate(app, pub, { apiBaseUrl, uuid, currentVersion } = {}) {
  const localVersion = String(currentVersion || '').trim() || '0.0.0';

  pub.ux({ state: 'UPDATE', step: 'Vérification des mises à jour' });
  pub.status('Vérification des mises à jour');

  const update = await checkForUpdate({ apiBaseUrl, uuid });

  if (!update.version) {
    // No release configured.
    return { ok: true, updated: false, required: false };
  }

  const needsUpdate = compareSemver(update.version, localVersion) > 0;
  if (!needsUpdate) {
    // Still enforce server-provided required flag only when version is actually newer.
    return { ok: true, updated: false, required: false };
  }

  pub.ux({ state: 'UPDATE', step: update.required ? 'Mise à jour obligatoire' : 'Mise à jour disponible' });

  const tmpBase = path.join(defaultTempDir(app), 'xyno-launcher-update');
  const stageDir = path.join(tmpBase, `${Date.now()}-${crypto.randomBytes(6).toString('hex')}`);
  const zipPath = path.join(stageDir, 'update.zip');
  const extractDir = path.join(stageDir, 'extract');

  await ensureEmptyDir(stageDir);

  pub.ux({ state: 'UPDATE', step: 'Téléchargement de la mise à jour' });
  pub.status('Mise à jour en cours');

  const dl = await downloadFileWithSha256(new URL(update.url), zipPath, {
    timeoutMs: 120_000,
    onProgress: ({ bytesDone, bytesTotal, percent }) => {
      pub.progress({
        phase: 'UPDATE',
        done: percent,
        total: 100,
        percent,
        currentFile: 'launcher.zip',
        bytesDone,
        bytesTotal,
      });
    },
  });

  const got = String(dl.sha256 || '').trim().toLowerCase();
  const expected = String(update.signature || '').trim().toLowerCase();
  if (!got || !expected || got !== expected) {
    try {
      await fs.rm(stageDir, { recursive: true, force: true });
    } catch {
      // ignore
    }
    throw new Error('update_signature_mismatch');
  }

  pub.ux({ state: 'UPDATE', step: 'Extraction de la mise à jour' });
  pub.status('Extraction de la mise à jour');

  await ensureEmptyDir(extractDir);
  await extractZipSafe(zipPath, extractDir, {
    onProgress: ({ percent, currentFile }) => {
      const p = Number.isFinite(percent) ? percent : 0;
      pub.progress({ phase: 'UPDATE', done: p, total: 100, percent: p, currentFile: currentFile || '' });
    },
  });

  const srcAsar = resolveAsarFromExtract(extractDir);
  if (!srcAsar) {
    throw new Error('update_payload_missing_app_asar');
  }

  pub.ux({ state: 'UPDATE', step: 'Installation de la mise à jour' });
  pub.status('Installation de la mise à jour');

  // Apply is done by a detached helper (Electron run-as-node) after we exit,
  // so the app.asar is not locked when being replaced.
  const helper = path.join(__dirname, '..', 'tools', 'applyUpdate.js');
  const resourcesPath = process.resourcesPath;
  const userDataPath = app.getPath('userData');

  const child = require('node:child_process').spawn(
    process.execPath,
    [helper, '--stage', extractDir, '--resources', resourcesPath, '--userData', userDataPath, '--version', update.version],
    {
      detached: true,
      stdio: 'ignore',
      env: {
        ...process.env,
        ELECTRON_RUN_AS_NODE: '1',
      },
    }
  );

  child.unref();

  pub.ux({ state: 'UPDATE', step: 'Redémarrage…' });
  pub.status('Redémarrage…');

  // Give IPC a moment to flush.
  await sleep(500);

  // Exit: helper will apply + relaunch.
  app.exit(0);

  return { ok: true, updated: true, required: !!update.required };
}

module.exports = {
  compareSemver,
  checkForUpdate,
  runAutoUpdate,
};

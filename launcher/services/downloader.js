const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { pipeline } = require('node:stream/promises');
const { request } = require('node:https');
const { request: httpRequest } = require('node:http');

const { sha1File } = require('../utils/hash');
const { ensureParentDir, listFilesRecursive, safeUnlink, pathExists } = require('./fileManager');

function getRequestFn(url) {
  return url.protocol === 'http:' ? httpRequest : request;
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function computeLocalStatus({ absPath, expectedSize, expectedSha1 }) {
  if (!(await pathExists(absPath))) {
    return { exists: false, ok: false, reason: 'missing' };
  }

  const st = await fsp.stat(absPath);
  if (!st.isFile()) {
    return { exists: true, ok: false, reason: 'not_file' };
  }

  if (Number.isFinite(expectedSize) && expectedSize >= 0 && st.size !== expectedSize) {
    return { exists: true, ok: false, reason: 'size_mismatch' };
  }

  if (expectedSha1) {
    const actual = await sha1File(absPath);
    if (actual !== expectedSha1) {
      return { exists: true, ok: false, reason: 'hash_mismatch', actualSha1: actual };
    }
    return { exists: true, ok: true, reason: 'ok', actualSha1: actual };
  }

  return { exists: true, ok: true, reason: 'ok' };
}

async function computeLocalStatusFast({ absPath, expectedSize }) {
  if (!(await pathExists(absPath))) {
    return { exists: false, ok: false, reason: 'missing' };
  }

  const st = await fsp.stat(absPath);
  if (!st.isFile()) {
    return { exists: true, ok: false, reason: 'not_file' };
  }

  if (Number.isFinite(expectedSize) && expectedSize >= 0 && st.size !== expectedSize) {
    return { exists: true, ok: false, reason: 'size_mismatch' };
  }

  return { exists: true, ok: true, reason: 'ok', size: st.size, mtimeMs: st.mtimeMs };
}

async function compareLocalFiles(manifestOrOpts, maybePaths) {
  const manifest = manifestOrOpts && manifestOrOpts.manifest ? manifestOrOpts.manifest : manifestOrOpts;
  const paths = manifestOrOpts && manifestOrOpts.paths ? manifestOrOpts.paths : maybePaths;

  if (!manifest || !paths) {
    throw new Error('compareLocalFiles requires (manifest, paths)');
  }
  const wanted = new Map();
  for (const f of manifest.files) {
    wanted.set(f.path, f);
  }

  const ok = [];
  const toDownload = [];

  for (const f of manifest.files) {
    const absPath = path.join(paths.rootDir, f.path);
    const status = await computeLocalStatus({
      absPath,
      expectedSize: f.size,
      expectedSha1: f.hash,
    });

    if (status.ok) {
      ok.push(f);
    } else {
      toDownload.push(f);
    }
  }

  // Detect obsolete: only within the managed top-level folders.
  // IMPORTANT: `versions/` and `libraries/` are managed by Minecraft installer/launcher.
  const managedRoots = [paths.modsDir, paths.configDir, paths.assetsDir];
  const localFiles = [];
  for (const r of managedRoots) {
    const files = await listFilesRecursive(r);
    localFiles.push(...files);
  }
  const obsolete = [];
  for (const abs of localFiles) {
    const rel = path.relative(paths.rootDir, abs).split(path.sep).join('/');
    if (!wanted.has(rel)) {
      obsolete.push({ absPath: abs, relPath: rel });
    }
  }

  return { ok, toDownload, obsolete };
}

async function planSync({
  manifest,
  paths,
  state,
  forceRevalidate = false,
  useStateFastPath = true,
  onProgress,
}) {
  if (!manifest || !paths) {
    throw new Error('planSync requires ({ manifest, paths, ... })');
  }

  const progressCb = typeof onProgress === 'function' ? onProgress : null;

  const wanted = new Map();
  for (const f of manifest.files) {
    wanted.set(f.path, f);
  }

  const stateFiles = state && state.installed_files && typeof state.installed_files === 'object' ? state.installed_files : null;
  const fast = Boolean(useStateFastPath && !forceRevalidate && stateFiles);
  const lastSyncMs = fast && state && typeof state.last_sync === 'string' ? Date.parse(state.last_sync) : NaN;
  const hasLastSync = Number.isFinite(lastSyncMs) && lastSyncMs > 0;

  const ok = [];
  const toDownload = [];
  const localHashes = {}; // best-effort hashes for state rebuild

  const totalFiles = Array.isArray(manifest.files) ? manifest.files.length : 0;
  let compared = 0;
  if (progressCb) {
    progressCb({
      done: 0,
      total: totalFiles,
      percent: totalFiles === 0 ? 100 : 0,
      phase: 'SYNC',
    });
  }

  for (const f of manifest.files) {
    const absPath = path.join(paths.rootDir, f.path);

    if (!fast) {
      const status = await computeLocalStatus({
        absPath,
        expectedSize: f.size,
        expectedSha1: f.hash,
      });

      if (status.ok) {
        ok.push(f);
        if (status.actualSha1) localHashes[f.path] = status.actualSha1;
      } else {
        toDownload.push(f);
        if (status.actualSha1) localHashes[f.path] = status.actualSha1;
      }

      compared++;
      if (progressCb) {
        const percent = totalFiles === 0 ? 100 : Math.floor((compared / totalFiles) * 100);
        progressCb({
          done: compared,
          total: totalFiles,
          percent,
          phase: 'SYNC',
        });
      }
      continue;
    }

    // Fast path: never trust state alone.
    // We always validate against the manifest by checking path exists + size,
    // and only skip hashing when the state hash matches the manifest hash.
    const status = await computeLocalStatusFast({ absPath, expectedSize: f.size });
    if (!status.ok) {
      toDownload.push(f);

      compared++;
      if (progressCb) {
        const percent = totalFiles === 0 ? 100 : Math.floor((compared / totalFiles) * 100);
        progressCb({
          done: compared,
          total: totalFiles,
          percent,
          phase: 'SYNC',
        });
      }
      continue;
    }

    if (f.hash) {
      const cached = typeof stateFiles[f.path] === 'string' ? stateFiles[f.path] : '';
      if (cached && cached.toLowerCase() === f.hash) {
        // If the local file was modified after last sync, don't trust the cache: verify by hashing.
        if (hasLastSync && Number.isFinite(status.mtimeMs) && status.mtimeMs > lastSyncMs) {
          const verified = await computeLocalStatus({
            absPath,
            expectedSize: f.size,
            expectedSha1: f.hash,
          });
          if (verified.ok) {
            ok.push(f);
            if (verified.actualSha1) localHashes[f.path] = verified.actualSha1;
          } else {
            toDownload.push(f);
          }
        } else {
          ok.push(f);
        }
      } else {
        toDownload.push(f);
      }
    } else {
      ok.push(f);
    }

    compared++;
    if (progressCb) {
      const percent = totalFiles === 0 ? 100 : Math.floor((compared / totalFiles) * 100);
      progressCb({
        done: compared,
        total: totalFiles,
        percent,
        phase: 'SYNC',
      });
    }
  }

  let obsolete = [];
  if (fast) {
    for (const relPath of Object.keys(stateFiles)) {
      if (!wanted.has(relPath)) {
        obsolete.push({
          absPath: path.join(paths.rootDir, relPath),
          relPath,
        });
      }
    }
  } else {
    const managedRoots = [paths.modsDir, paths.configDir, paths.assetsDir, paths.versionsDir];
    const localFiles = [];
    for (const r of managedRoots) {
      const files = await listFilesRecursive(r);
      localFiles.push(...files);
    }
    for (const abs of localFiles) {
      const rel = path.relative(paths.rootDir, abs).split(path.sep).join('/');
      if (!wanted.has(rel)) {
        obsolete.push({ absPath: abs, relPath: rel });
      }
    }
  }

  return { ok, toDownload, obsolete, localHashes, usedFastPath: fast };
}

async function downloadOnce({ url, destPath, expectedSha1, timeoutMs = 30_000, headers = null }) {
  const parsedUrl = new URL(url);
  const requestFn = getRequestFn(parsedUrl);

  const tmpPath = destPath + '.part';
  await ensureParentDir(destPath);
  await safeUnlink(tmpPath);

  const hash = crypto.createHash('sha1');

  await new Promise((resolve, reject) => {
    const req = requestFn(
      parsedUrl,
      {
        method: 'GET',
        timeout: timeoutMs,
        headers: {
          Accept: '*/*',
          ...(headers && typeof headers === 'object' ? headers : {}),
        },
      },
      (res) => {
        const status = res.statusCode || 0;
        if (status >= 300 && status < 400 && res.headers.location) {
          reject(Object.assign(new Error('redirect'), { code: 'REDIRECT', location: res.headers.location }));
          return;
        }
        if (status !== 200) {
          reject(new Error(`download_http_${status}`));
          res.resume();
          return;
        }

        const out = fs.createWriteStream(tmpPath);
        res.on('data', (chunk) => hash.update(chunk));

        pipeline(res, out)
          .then(resolve)
          .catch(reject);
      }
    );

    req.on('timeout', () => {
      const err = new Error('download_timeout');
      err.code = 'ETIMEDOUT';
      req.destroy(err);
    });
    req.on('error', (err) => reject(err));
    req.end();
  });

  const got = hash.digest('hex');
  if (expectedSha1 && got !== expectedSha1) {
    await safeUnlink(tmpPath);
    throw new Error(`sha1_mismatch (expected ${expectedSha1}, got ${got})`);
  }

  await fsp.rename(tmpPath, destPath);
  return got;
}

async function downloadWithRetry({ url, destPath, expectedSha1, retries = 2, headers = null }) {
  let currentUrl = url;
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      return await downloadOnce({ url: currentUrl, destPath, expectedSha1, headers });
    } catch (err) {
      // Cleanup partial download before retry.
      await safeUnlink(destPath + '.part');

      if (err && err.code === 'REDIRECT' && err.location) {
        currentUrl = new URL(err.location, currentUrl).toString();
        continue;
      }

      const last = attempt === retries;
      if (last) throw err;
      await sleep(500 * (attempt + 1));
    }
  }
}

async function downloadMissingFiles(filesOrOpts, maybePaths) {
  const files = filesOrOpts && filesOrOpts.files ? filesOrOpts.files : filesOrOpts;
  const paths = filesOrOpts && filesOrOpts.paths ? filesOrOpts.paths : maybePaths;
  const onProgress = filesOrOpts && typeof filesOrOpts.onProgress === 'function' ? filesOrOpts.onProgress : null;
  const headersFor = filesOrOpts && typeof filesOrOpts.headersFor === 'function' ? filesOrOpts.headersFor : null;

  if (!Array.isArray(files) || !paths) {
    throw new Error('downloadMissingFiles requires (files, paths)');
  }

  const total = files.length;
  let done = 0;

  const bytesTotal = files.reduce((sum, f) => {
    const size = f && Number.isFinite(f.size) ? f.size : Number(f && f.size);
    return sum + (Number.isFinite(size) && size > 0 ? size : 0);
  }, 0);
  let bytesDone = 0;

  if (onProgress) {
    onProgress({
      done,
      total,
      percent: total === 0 ? 100 : 0,
      currentFile: '',
      bytesDone,
      bytesTotal,
      phase: 'DOWNLOAD',
    });
  }

  const downloadedHashes = {};

  for (const f of files) {
    const destPath = path.join(paths.rootDir, f.path);
    console.log(`[download] ${done}/${total} ${f.path}`);

    if (onProgress) {
      const pctBefore = total === 0 ? 0 : Math.floor((done / total) * 100);
      onProgress({
        done,
        total,
        percent: pctBefore,
        currentFile: f.path,
        bytesDone,
        bytesTotal,
        phase: 'DOWNLOAD',
      });
    }

    const headers = headersFor ? (headersFor(f) || null) : (f && f.headers ? f.headers : null);
    const got = await downloadWithRetry({ url: f.url, destPath, expectedSha1: f.hash, retries: 2, headers });
    if (got) downloadedHashes[f.path] = String(got).toLowerCase();

    done++;
    {
      const size = f && Number.isFinite(f.size) ? f.size : Number(f && f.size);
      if (Number.isFinite(size) && size > 0) bytesDone += size;
    }
    const pct = total === 0 ? 100 : Math.floor((done / total) * 100);
    console.log(`[download] progress: ${done}/${total} (${pct}%)`);

    if (onProgress) {
      onProgress({
        done,
        total,
        percent: pct,
        currentFile: f.path,
        bytesDone,
        bytesTotal,
        phase: 'DOWNLOAD',
      });
    }
  }

  return downloadedHashes;
}

async function deleteObsoleteFiles(manifestOrOpts, maybePaths) {
  // Backward-compat: deleteObsoleteFiles({ obsolete, paths })
  if (manifestOrOpts && Array.isArray(manifestOrOpts.obsolete) && manifestOrOpts.paths) {
    const { obsolete, paths } = manifestOrOpts;
    return await deleteObsoleteList({ obsolete, paths });
  }

  const manifest = manifestOrOpts;
  const paths = maybePaths;

  if (!manifest || !paths) {
    throw new Error('deleteObsoleteFiles requires (manifest, paths)');
  }

  const diff = await compareLocalFiles(manifest, paths);
  return await deleteObsoleteList({ obsolete: diff.obsolete, paths });
}

async function deleteObsoleteList({ obsolete, paths }) {
  for (const o of obsolete) {
    // Safety: ensure we only delete within rootDir.
    const rel = path.relative(paths.rootDir, o.absPath);
    if (rel.startsWith('..') || path.isAbsolute(rel)) {
      continue;
    }
    await safeUnlink(o.absPath);
    console.log(`[delete] ${o.relPath}`);
  }
}

module.exports = {
  compareLocalFiles,
  planSync,
  downloadMissingFiles,
  deleteObsoleteFiles,
};

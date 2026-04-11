const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');

async function pathExists(p) {
  try {
    await fsp.access(p, fs.constants.F_OK);
    return true;
  } catch {
    return false;
  }
}

async function ensureDir(dirPath) {
  await fsp.mkdir(dirPath, { recursive: true });
}

async function ensureParentDir(filePath) {
  await ensureDir(path.dirname(filePath));
}

async function safeUnlink(filePath) {
  try {
    await fsp.unlink(filePath);
  } catch (err) {
    if (err && err.code === 'ENOENT') return;
    throw err;
  }
}

async function listFilesRecursive(rootDir) {
  const out = [];
  async function walk(dir) {
    const entries = await fsp.readdir(dir, { withFileTypes: true });
    for (const ent of entries) {
      const full = path.join(dir, ent.name);
      if (ent.isDirectory()) {
        await walk(full);
      } else if (ent.isFile()) {
        out.push(full);
      }
    }
  }
  if (await pathExists(rootDir)) {
    await walk(rootDir);
  }
  return out;
}

async function ensureBaseFolders(paths) {
  await ensureDir(paths.rootDir);
  await Promise.all([
    ensureDir(paths.modsDir),
    ensureDir(paths.configDir),
    ensureDir(paths.assetsDir),
    ensureDir(paths.versionsDir),
    ensureDir(paths.librariesDir),
    ensureDir(paths.installersDir),
  ]);
}

module.exports = {
  ensureDir,
  ensureParentDir,
  safeUnlink,
  listFilesRecursive,
  pathExists,
  ensureBaseFolders,
};

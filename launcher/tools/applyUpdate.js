'use strict';

const path = require('node:path');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const { spawn } = require('node:child_process');

function argValue(args, key) {
  const i = args.indexOf(key);
  if (i < 0) return '';
  const v = args[i + 1];
  return typeof v === 'string' ? v : '';
}

function normalizeVersion(v) {
  const s = String(v || '').trim();
  if (!s) return '';
  if (!/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9a-zA-Z.-]+)?$/.test(s)) return '';
  return s;
}

async function sleep(ms) {
  return await new Promise((r) => setTimeout(r, ms));
}

async function copyFileAtomic(src, dest) {
  const dir = path.dirname(dest);
  await fs.mkdir(dir, { recursive: true });

  const tmp = dest + '.tmp';
  await fs.copyFile(src, tmp);
  await fs.rename(tmp, dest);
}

async function copyDirRecursive(srcDir, destDir) {
  await fs.mkdir(destDir, { recursive: true });
  const entries = await fs.readdir(srcDir, { withFileTypes: true });
  for (const ent of entries) {
    const src = path.join(srcDir, ent.name);
    const dst = path.join(destDir, ent.name);
    if (ent.isDirectory()) {
      await copyDirRecursive(src, dst);
    } else if (ent.isFile()) {
      await fs.mkdir(path.dirname(dst), { recursive: true });
      await fs.copyFile(src, dst);
    }
  }
}

function resolveAsarFromStage(stageDir) {
  const candidates = [
    path.join(stageDir, 'resources', 'app.asar'),
    path.join(stageDir, 'app.asar'),
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

async function main() {
  const args = process.argv.slice(2);

  const stageDir = argValue(args, '--stage');
  const resourcesPath = argValue(args, '--resources');
  const userDataPath = argValue(args, '--userData');
  const version = normalizeVersion(argValue(args, '--version'));

  if (!stageDir || !resourcesPath || !userDataPath || !version) {
    process.exitCode = 2;
    return;
  }

  const srcAsar = resolveAsarFromStage(stageDir);
  if (!srcAsar) {
    process.exitCode = 3;
    return;
  }

  const dstAsar = path.join(resourcesPath, 'app.asar');
  const dstUnpacked = path.join(resourcesPath, 'app.asar.unpacked');

  // Wait for the main app to fully exit so app.asar is not locked.
  for (let i = 0; i < 60; i++) {
    try {
      // Try opening for write in-place.
      const fd = fsSync.openSync(dstAsar, 'r+');
      fsSync.closeSync(fd);
      break;
    } catch {
      await sleep(500);
    }
  }

  // Replace app.asar
  await copyFileAtomic(srcAsar, dstAsar);

  // Replace app.asar.unpacked if provided
  const srcUnpacked = path.join(stageDir, 'resources', 'app.asar.unpacked');
  const srcUnpackedAlt = path.join(stageDir, 'app.asar.unpacked');
  const unpackedSrc = fsSync.existsSync(srcUnpacked) ? srcUnpacked : fsSync.existsSync(srcUnpackedAlt) ? srcUnpackedAlt : '';
  if (unpackedSrc) {
    await fs.rm(dstUnpacked, { recursive: true, force: true });
    await copyDirRecursive(unpackedSrc, dstUnpacked);
  }

  // Persist version.json in userData
  const versionFile = path.join(userDataPath, 'version.json');
  const tmp = versionFile + '.tmp';
  await fs.mkdir(path.dirname(versionFile), { recursive: true });
  await fs.writeFile(tmp, JSON.stringify({ version }, null, 2) + '\n', 'utf8');
  await fs.rename(tmp, versionFile);

  // Relaunch app (now loads new asar)
  const exe = process.execPath;
  const env = { ...process.env };
  delete env.ELECTRON_RUN_AS_NODE;
  const child = spawn(exe, [], { detached: true, stdio: 'ignore', env });
  child.unref();
}

main().catch(() => {
  process.exitCode = 1;
});

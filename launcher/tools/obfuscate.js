'use strict';

const fsp = require('node:fs/promises');
const fs = require('node:fs');
const path = require('node:path');

const JavaScriptObfuscator = require('javascript-obfuscator');

const ROOT = path.resolve(__dirname, '..');
const DIST = path.join(ROOT, 'dist');

const JS_EXT = new Set(['.js', '.cjs', '.mjs']);
const COPY_EXT = new Set(['.html', '.css', '.json', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.eot', '.mp3', '.ogg', '.wav', '.txt']);

const IGNORE_DIRS = new Set([
  'node_modules',
  'dist',
  '.git',
  '.idea',
  '.vscode',
]);

function shouldIgnore(relPath) {
  const parts = relPath.split(path.sep);
  if (parts.some((p) => IGNORE_DIRS.has(p))) return true;
  if (parts.includes('Launcher')) return false; // keep runtime config shipped
  return false;
}

async function ensureEmptyDir(dir) {
  await fsp.rm(dir, { recursive: true, force: true });
  await fsp.mkdir(dir, { recursive: true });
}

async function* walk(dir) {
  const entries = await fsp.readdir(dir, { withFileTypes: true });
  for (const e of entries) {
    const abs = path.join(dir, e.name);
    const rel = path.relative(ROOT, abs);
    if (shouldIgnore(rel)) continue;

    if (e.isDirectory()) {
      yield* walk(abs);
    } else if (e.isFile()) {
      yield abs;
    }
  }
}

function obfuscateCode(code, { fileName } = {}) {
  // Keep this conservative: we want obfuscation without breaking CommonJS/Electron.
  const res = JavaScriptObfuscator.obfuscate(code, {
    compact: true,
    renameGlobals: false,
    identifierNamesGenerator: 'hexadecimal',
    stringArray: true,
    stringArrayEncoding: ['base64'],
    stringArrayThreshold: 0.75,
    splitStrings: true,
    splitStringsChunkLength: 8,
    simplify: true,
    transformObjectKeys: false,
    unicodeEscapeSequence: false,
    // Avoid aggressive transforms that often break runtime behavior.
    controlFlowFlattening: false,
    deadCodeInjection: false,
    debugProtection: false,
    debugProtectionInterval: 0,
    disableConsoleOutput: false,
    sourceMap: false,
    comments: false,
    reservedNames: ['^require$', '^module$', '^exports$', '^__dirname$', '^__filename$'],
  });

  const out = res.getObfuscatedCode();
  if (!out || typeof out !== 'string') {
    const err = new Error('Obfuscation failed');
    err.fileName = fileName;
    throw err;
  }
  return out;
}

async function copyFile(src, dest) {
  await fsp.mkdir(path.dirname(dest), { recursive: true });
  await fsp.copyFile(src, dest);
}

async function writeText(dest, content) {
  await fsp.mkdir(path.dirname(dest), { recursive: true });
  await fsp.writeFile(dest, content, 'utf8');
}

async function main() {
  console.log('[obfuscate] root:', ROOT);
  console.log('[obfuscate] dist:', DIST);

  await ensureEmptyDir(DIST);

  // Copy package.json so `electron dist` works.
  const pkgPath = path.join(ROOT, 'package.json');
  const pkgRaw = await fsp.readFile(pkgPath, 'utf8');
  const pkg = JSON.parse(pkgRaw);
  pkg.scripts = { start: 'electron .'};
  pkg.main = 'main.js';
  await writeText(path.join(DIST, 'package.json'), JSON.stringify(pkg, null, 2) + '\n');

  // Copy lockfile if present (optional, for reproducibility).
  try {
    await copyFile(path.join(ROOT, 'package-lock.json'), path.join(DIST, 'package-lock.json'));
  } catch {
    // ignore
  }

  let countJs = 0;
  let countCopy = 0;

  for await (const abs of walk(ROOT)) {
    const rel = path.relative(ROOT, abs);
    if (rel === 'package.json' || rel === 'package-lock.json') continue;

    const ext = path.extname(abs).toLowerCase();
    const dest = path.join(DIST, rel);

    if (JS_EXT.has(ext)) {
      const code = await fsp.readFile(abs, 'utf8');
      const obf = obfuscateCode(code, { fileName: rel });
      await writeText(dest, obf);
      countJs++;
      continue;
    }

    if (COPY_EXT.has(ext) || ext === '') {
      await copyFile(abs, dest);
      countCopy++;
      continue;
    }

    // Default: copy anything unknown (keeps assets/configs working).
    await copyFile(abs, dest);
    countCopy++;
  }

  // Ensure Launcher/ configs are present (they are not JS).
  const launcherDir = path.join(ROOT, 'Launcher');
  if (fs.existsSync(launcherDir)) {
    // already walked/copied
  }

  console.log(`[obfuscate] done. js=${countJs} copied=${countCopy}`);
}

main().catch((err) => {
  console.error('[obfuscate] failed:', err && err.stack ? err.stack : err);
  process.exit(1);
});

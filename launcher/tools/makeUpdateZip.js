'use strict';

const path = require('node:path');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const crypto = require('node:crypto');

function argValue(name) {
  const i = process.argv.indexOf(name);
  if (i === -1) return '';
  const v = process.argv[i + 1];
  return typeof v === 'string' ? v : '';
}

function usage() {
  // eslint-disable-next-line no-console
  console.log('Usage: node tools/makeUpdateZip.js --asar <path/to/app.asar> --out <path/to/update.zip>');
}

async function sha256FileHex(p) {
  return await new Promise((resolve, reject) => {
    const h = crypto.createHash('sha256');
    const s = fsSync.createReadStream(p);
    s.on('error', reject);
    s.on('data', (chunk) => h.update(chunk));
    s.on('end', () => resolve(h.digest('hex')));
  });
}

async function main() {
  const asarPath = argValue('--asar');
  const outPath = argValue('--out');

  if (!asarPath || !outPath) {
    usage();
    process.exit(2);
  }

  const absAsar = path.resolve(asarPath);
  const absOut = path.resolve(outPath);

  await fs.access(absAsar);
  await fs.mkdir(path.dirname(absOut), { recursive: true });

  const yazl = require('yazl');

  const zipfile = new yazl.ZipFile();

  // autoUpdate.js accepts either extract/resources/app.asar or extract/app.asar
  // We include the canonical packaged path.
  zipfile.addFile(absAsar, 'resources/app.asar');

  await new Promise((resolve, reject) => {
    zipfile.outputStream
      .pipe(fsSync.createWriteStream(absOut))
      .on('error', reject)
      .on('close', resolve);
    zipfile.end();
  });

  const sha = await sha256FileHex(absOut);
  // eslint-disable-next-line no-console
  console.log(`[update-zip] wrote: ${absOut}`);
  // eslint-disable-next-line no-console
  console.log(`[update-zip] sha256: ${sha}`);
}

main().catch((err) => {
  // eslint-disable-next-line no-console
  console.error('[update-zip] failed:', err && err.stack ? err.stack : err);
  process.exit(1);
});

'use strict';

/**
 * build-multi-launchers.js
 *
 * Utilitaire LOCAL (optionnel) : lit uuids.json et enchaîne les builds
 * electron-builder pour chaque UUID. Sert aux cas où tu rebuilds en masse
 * depuis ton poste sans passer par GitHub Actions (ex : Linux + Windows via Wine).
 *
 * Pour un déploiement SaaS, la source de vérité reste GitHub Actions
 * (cf. .github/workflows/build.yml). Ce script fait juste la même chose
 * en local pour tester/dépanner.
 *
 * Usage :
 *   CMS_BASE_URL=https://cms.tld BUILD_FETCH_TOKEN=xxx \
 *     node build-multi-launchers.js [--targets=win,linux,mac] [--uuids=uuids.json]
 *
 * uuids.json format :
 *   [
 *     { "uuid": "…", "version": "…" },
 *     { "uuid": "…" }
 *   ]
 */

const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const https = require('node:https');
const http = require('node:http');
const { execSync } = require('node:child_process');

const ROOT = __dirname;

function parseArgs(argv) {
  const out = {};
  for (const a of argv.slice(2)) {
    const m = a.match(/^--([^=]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
    else if (a.startsWith('--')) out[a.slice(2)] = true;
  }
  return out;
}
const args = parseArgs(process.argv);
const TARGETS = (args.targets || 'win,linux,mac').split(',').map(s => s.trim()).filter(Boolean);
const UUIDS_FILE = path.resolve(ROOT, args.uuids || 'uuids.json');

const CMS = (process.env.CMS_BASE_URL || '').replace(/\/+$/, '');
const TOKEN = process.env.BUILD_FETCH_TOKEN || '';
if (!CMS || !TOKEN) {
  console.error('Missing env: CMS_BASE_URL and BUILD_FETCH_TOKEN are required.');
  process.exit(1);
}

function fetchJson(url, headers = {}) {
  return new Promise((resolve, reject) => {
    const lib = url.startsWith('https://') ? https : http;
    lib.get(url, { headers }, (res) => {
      let data = '';
      res.on('data', (c) => (data += c));
      res.on('end', () => {
        if (res.statusCode !== 200) {
          return reject(new Error(`HTTP ${res.statusCode}: ${data.slice(0, 300)}`));
        }
        try { resolve(JSON.parse(data)); } catch (e) { reject(e); }
      });
    }).on('error', reject);
  });
}

function fetchFile(url, dest, headers = {}) {
  return new Promise((resolve, reject) => {
    const lib = url.startsWith('https://') ? https : http;
    lib.get(url, { headers }, (res) => {
      if (res.statusCode !== 200) return reject(new Error(`HTTP ${res.statusCode} on ${url}`));
      const ws = fs.createWriteStream(dest);
      res.pipe(ws);
      ws.on('finish', () => ws.close(resolve));
      ws.on('error', reject);
    }).on('error', reject);
  });
}

async function writeConfig(uuid, version) {
  const url = `${CMS}/api/build_config.php?uuid=${encodeURIComponent(uuid)}`;
  const cfg = await fetchJson(url, { 'X-Build-Token': TOKEN });
  cfg.version = version;
  await fsp.mkdir(path.join(ROOT, 'src'), { recursive: true });
  await fsp.writeFile(path.join(ROOT, 'src', 'config.json'), JSON.stringify(cfg, null, 2));
  return cfg;
}

async function downloadAssets(cfg) {
  const dir = path.join(ROOT, 'src', 'assets');
  await fsp.mkdir(dir, { recursive: true });
  const assets = cfg.assets || {};
  for (const [k, url] of Object.entries(assets)) {
    if (!url) continue;
    const ext = (url.split('?')[0].split('.').pop() || 'bin').toLowerCase();
    const dest = path.join(dir, `${k}.${ext}`);
    process.stdout.write(`  asset ${k} -> ${dest}\n`);
    await fetchFile(url, dest, { 'X-Build-Token': TOKEN });
  }
}

function run(cmd) {
  console.log(`  $ ${cmd}`);
  execSync(cmd, { cwd: ROOT, stdio: 'inherit' });
}

async function buildOne(entry) {
  const uuid = String(entry.uuid || '').trim();
  if (!/^[a-f0-9-]{36}$/.test(uuid)) {
    console.warn(`  ! skip invalid uuid: ${uuid}`);
    return;
  }
  const autoVersion = new Date().toISOString()
    .replace(/[-:T]/g, '').slice(0, 12)
    .replace(/(\d{8})(\d{4})/, '$1-$2');
  const version = entry.version || autoVersion;

  console.log(`\n=== Build ${uuid} v${version} (${TARGETS.join(',')}) ===`);
  const cfg = await writeConfig(uuid, version);
  await downloadAssets(cfg);

  for (const t of TARGETS) {
    run(`npx electron-builder --${t} --publish never`);
  }
}

async function main() {
  if (!fs.existsSync(UUIDS_FILE)) {
    console.error(`uuids file not found: ${UUIDS_FILE}`);
    process.exit(1);
  }
  const list = JSON.parse(await fsp.readFile(UUIDS_FILE, 'utf8'));
  if (!Array.isArray(list) || list.length === 0) {
    console.error('uuids.json must contain a non-empty array');
    process.exit(1);
  }
  for (const entry of list) {
    try {
      await buildOne(entry);
    } catch (e) {
      console.error(`  ! build failed for ${entry.uuid}:`, e.message);
    }
  }
  console.log('\nDone.');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

/* eslint-disable no-console */
'use strict';

// Auto-installer for the Adoptium Temurin JRE matching a given Minecraft
// version. Players don't have to install Java themselves anymore — the
// launcher downloads the right JRE on first launch and reuses it after.
//
// Why Adoptium / Temurin: it's the OpenJDK distribution Mojang's own launcher
// switched to recommending, has a stable public API, ships standalone JREs
// (small footprint, no JDK fluff), and is mirrored worldwide.
//
// The JRE is installed under <launcherRoot>/runtime/java-<major>/ so each
// launcher tenant has its own runtime directory and we never collide with the
// system Java install.

const fsp = require('node:fs/promises');
const fsSync = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const os = require('node:os');
const { spawn } = require('node:child_process');
const { request } = require('node:https');

const ADOPTIUM_API = 'https://api.adoptium.net/v3/assets/feature_releases';

// Map a Minecraft version string to the Java major version Mojang's own
// launcher uses for that release. Sources:
//   - https://minecraft.wiki/w/Java_Edition#Versions (Java requirement column)
//   - https://launchermeta.mojang.com/v1/products/java-runtime/...
//
// Pre-1.17:   Java 8  (jre-legacy)
// 1.17:       Java 16 (java-runtime-alpha)
// 1.18-1.20.4: Java 17 (java-runtime-gamma / java-runtime-delta)
// 1.20.5+:    Java 21 (java-runtime-delta)
function getRequiredJavaVersion(mcVersion) {
	const m = String(mcVersion || '').trim().match(/^(\d+)\.(\d+)(?:\.(\d+))?/);
	if (!m) return 21; // unknown version → default to current LTS
	const minor = parseInt(m[2], 10);
	const patch = m[3] ? parseInt(m[3], 10) : 0;

	if (minor < 17) return 8;
	if (minor === 17) return 16;
	if (minor === 18 || minor === 19) return 17;
	if (minor === 20 && patch < 5) return 17;
	return 21;
}

function getPlatformInfo() {
	const platform = process.platform;
	const arch = process.arch;
	let mappedOs;
	if (platform === 'darwin') mappedOs = 'mac';
	else if (platform === 'linux') mappedOs = 'linux';
	else if (platform === 'win32') mappedOs = 'windows';
	else throw new Error(`Plateforme non supportée pour le téléchargement de Java : ${platform}`);

	let mappedArch;
	if (arch === 'x64') mappedArch = 'x64';
	else if (arch === 'arm64') mappedArch = 'aarch64';
	else if (arch === 'ia32') mappedArch = 'x86';
	else throw new Error(`Architecture non supportée pour Java : ${arch}`);

	return { os: mappedOs, arch: mappedArch };
}

function getJavaInstallDir(launcherRootDir, javaMajor) {
	return path.join(launcherRootDir, 'runtime', `java-${javaMajor}`);
}

// Adoptium archives unpack with different layouts depending on platform:
//   - mac:     <root>/<release-name>/Contents/Home/bin/java
//   - linux:   <root>/<release-name>/bin/java
//   - windows: <root>/<release-name>/bin/java.exe
// We don't know <release-name> in advance, so we scan the install dir for the
// first directory and look in known sub-paths.
function findExtractedJavaBinary(installDir) {
	let entries;
	try { entries = fsSync.readdirSync(installDir, { withFileTypes: true }); }
	catch { return ''; }

	const dirs = entries.filter((e) => e.isDirectory()).map((e) => e.name);
	if (dirs.length === 0) return '';

	const exe = process.platform === 'win32' ? 'java.exe' : 'java';
	for (const d of dirs) {
		const root = path.join(installDir, d);
		const candidates = process.platform === 'darwin'
			? [path.join(root, 'Contents', 'Home', 'bin', exe), path.join(root, 'bin', exe)]
			: [path.join(root, 'bin', exe)];
		for (const c of candidates) {
			try {
				if (fsSync.statSync(c).isFile()) return c;
			} catch { /* try next */ }
		}
	}
	return '';
}

async function httpGetJson(url, { timeoutMs = 15_000 } = {}) {
	return new Promise((resolve, reject) => {
		const req = request(
			url,
			{ method: 'GET', timeout: timeoutMs, headers: { Accept: 'application/json' } },
			(res) => {
				const chunks = [];
				res.on('data', (c) => chunks.push(c));
				res.on('end', () => {
					const text = Buffer.concat(chunks).toString('utf8');
					let json = null;
					try { json = JSON.parse(text); } catch { /* ignore */ }
					resolve({ statusCode: res.statusCode || 0, json, text });
				});
			},
		);
		req.on('timeout', () => req.destroy(new Error('Adoptium API timeout')));
		req.on('error', reject);
		req.end();
	});
}

// Stream a remote URL into a file, following redirects, with progress.
async function downloadToFile(urlStr, destPath, { onProgress, timeoutMs = 10 * 60 * 1000 } = {}) {
	await fsp.mkdir(path.dirname(destPath), { recursive: true });
	const tmpPath = destPath + '.tmp';

	let currentUrl = urlStr;
	for (let redirects = 0; redirects < 5; redirects += 1) {
		// eslint-disable-next-line no-await-in-loop
		const result = await new Promise((resolve, reject) => {
			const req = request(
				currentUrl,
				{ method: 'GET', timeout: timeoutMs, headers: { Accept: 'application/octet-stream' } },
				(res) => {
					const code = res.statusCode || 0;
					if (code >= 300 && code < 400 && res.headers.location) {
						res.resume();
						resolve({ redirectTo: res.headers.location });
						return;
					}
					if (code !== 200) {
						res.resume();
						reject(new Error(`Téléchargement Java échoué (HTTP ${code})`));
						return;
					}

					const total = Number(res.headers['content-length']) || 0;
					let received = 0;
					const file = fsSync.createWriteStream(tmpPath);
					res.on('data', (chunk) => {
						received += chunk.length;
						if (typeof onProgress === 'function' && total > 0) {
							onProgress({ task: received, total, type: 'java' });
						}
					});
					res.pipe(file);
					file.on('finish', () => file.close(() => resolve({ done: true })));
					file.on('error', reject);
				},
			);
			req.on('timeout', () => req.destroy(new Error('Téléchargement Java : timeout réseau')));
			req.on('error', reject);
			req.end();
		});

		if (result.done) {
			await fsp.rename(tmpPath, destPath);
			return;
		}
		if (result.redirectTo) {
			currentUrl = new URL(result.redirectTo, currentUrl).toString();
			continue;
		}
		throw new Error('Téléchargement Java : réponse inattendue');
	}
	throw new Error('Téléchargement Java : trop de redirections');
}

async function sha256File(filePath) {
	return new Promise((resolve, reject) => {
		const h = crypto.createHash('sha256');
		const s = fsSync.createReadStream(filePath);
		s.on('error', reject);
		s.on('data', (c) => h.update(c));
		s.on('end', () => resolve(h.digest('hex')));
	});
}

// Run a system command and return when it exits. tar is shipped on every
// platform we target (macOS/Linux always, Windows 10+ build 17063+ via
// libarchive) and handles both .tar.gz and .zip. Fall back to yauzl for .zip
// on older Windows.
function runCmd(cmd, args, { cwd, timeoutMs = 5 * 60 * 1000 } = {}) {
	return new Promise((resolve, reject) => {
		const child = spawn(cmd, args, { cwd, stdio: ['ignore', 'pipe', 'pipe'] });
		let stderr = '';
		const t = setTimeout(() => {
			try { child.kill(); } catch { /* ignore */ }
			reject(new Error(`${cmd} a dépassé le timeout`));
		}, timeoutMs);
		child.stderr.on('data', (d) => { stderr += d.toString('utf8'); if (stderr.length > 8000) stderr = stderr.slice(-8000); });
		child.on('error', (err) => { clearTimeout(t); reject(err); });
		child.on('close', (code) => {
			clearTimeout(t);
			if (code === 0) resolve();
			else reject(new Error(`${cmd} a échoué (code=${code}) ${stderr}`));
		});
	});
}

async function extractZipWithYauzl(zipPath, destDir) {
	const yauzl = require('yauzl');
	await fsp.mkdir(destDir, { recursive: true });
	const destRoot = path.resolve(destDir);

	return new Promise((resolve, reject) => {
		yauzl.open(zipPath, { lazyEntries: true }, (err, zipfile) => {
			if (err || !zipfile) return reject(err || new Error('Open zip failed'));

			const safePath = (entryName) => {
				if (!entryName) return null;
				if (entryName.includes('\\') || entryName.startsWith('/') || entryName.includes(' ')) return null;
				if (/(^|\/)\.\.(\/|$)/.test(entryName)) return null;
				const out = path.resolve(destRoot, entryName);
				if (!out.startsWith(destRoot + path.sep) && out !== destRoot) return null;
				return out;
			};

			zipfile.readEntry();
			zipfile.on('entry', (entry) => {
				const out = safePath(entry.fileName);
				if (!out) { zipfile.close(); return reject(new Error('Zip path unsafe')); }
				const isDir = /\/$/.test(entry.fileName);
				if (isDir) {
					fsp.mkdir(out, { recursive: true }).then(() => zipfile.readEntry()).catch(reject);
					return;
				}
				fsp.mkdir(path.dirname(out), { recursive: true })
					.then(() => zipfile.openReadStream(entry, (e, rs) => {
						if (e) return reject(e);
						const ws = fsSync.createWriteStream(out, { mode: 0o644 });
						rs.on('error', reject);
						ws.on('error', reject);
						ws.on('finish', () => zipfile.readEntry());
						rs.pipe(ws);
					}))
					.catch(reject);
			});
			zipfile.on('end', () => resolve());
			zipfile.on('error', reject);
		});
	});
}

async function extractArchive(archivePath, destDir) {
	await fsp.mkdir(destDir, { recursive: true });
	const lower = archivePath.toLowerCase();

	if (lower.endsWith('.tar.gz') || lower.endsWith('.tgz')) {
		// tar is universally available on macOS, Linux, and modern Windows.
		await runCmd('tar', ['-xf', archivePath, '-C', destDir]);
		return;
	}
	if (lower.endsWith('.zip')) {
		try {
			await runCmd('tar', ['-xf', archivePath, '-C', destDir]);
			return;
		} catch {
			// Older Windows tar can't read zips → fall back to a JS implementation.
			await extractZipWithYauzl(archivePath, destDir);
			return;
		}
	}
	throw new Error(`Format d'archive Java non géré : ${archivePath}`);
}

// Single Adoptium API call. Returns null on 404 / empty result so the caller
// can decide whether to retry with a different architecture.
async function tryFetchAdoptiumRelease(javaMajor, osTag, arch) {
	const url = new URL(`${ADOPTIUM_API}/${javaMajor}/ga`);
	url.searchParams.set('architecture', arch);
	url.searchParams.set('heap_size', 'normal');
	url.searchParams.set('image_type', 'jre');
	url.searchParams.set('jvm_impl', 'hotspot');
	url.searchParams.set('os', osTag);
	url.searchParams.set('page', '0');
	url.searchParams.set('page_size', '1');
	url.searchParams.set('project', 'jdk');
	url.searchParams.set('sort_method', 'DEFAULT');
	url.searchParams.set('sort_order', 'DESC');
	url.searchParams.set('vendor', 'eclipse');

	const res = await httpGetJson(url);
	// 404 = no build for this combination (e.g. Java 8 doesn't exist for
	// macOS aarch64 — Java 8 predates Apple Silicon by 6 years). Treat as
	// "not available" so the caller can fall back to a different arch.
	if (res.statusCode === 404) return null;
	if (res.statusCode !== 200) throw new Error(`Adoptium API HTTP ${res.statusCode}`);

	const list = Array.isArray(res.json) ? res.json : [];
	if (list.length === 0) return null;
	const release = list[0];
	const binary = Array.isArray(release.binaries) ? release.binaries[0] : null;
	if (!binary || !binary.package || !binary.package.link) return null;
	return {
		releaseVersion: (release.version_data && release.version_data.openjdk_version) || '',
		url: String(binary.package.link),
		sha256: String(binary.package.checksum || '').toLowerCase(),
		name: String(binary.package.name || `java-${javaMajor}.archive`),
		arch,
	};
}

async function fetchAdoptiumRelease(javaMajor, debugLog) {
	const dlog = typeof debugLog === 'function' ? debugLog : () => {};
	const { os: osTag, arch } = getPlatformInfo();

	// Build the architecture preference list. We always try the native arch
	// first, and on macOS we transparently fall back to x64 (which runs via
	// Rosetta 2 on Apple Silicon — preinstalled on macOS 11+, no UX impact).
	// This is the only practical way to get Java 8 on M1/M2/M3 Macs since
	// Adoptium never shipped an aarch64 Java 8 build for macOS.
	const archCandidates = [arch];
	if (osTag === 'mac' && arch === 'aarch64') archCandidates.push('x64');

	for (const tryArch of archCandidates) {
		dlog(`[java] querying Adoptium: java=${javaMajor} os=${osTag} arch=${tryArch}`);
		const release = await tryFetchAdoptiumRelease(javaMajor, osTag, tryArch);
		if (release) {
			if (tryArch !== arch) {
				dlog(`[java] no native ${arch} build; using ${tryArch} (will run via Rosetta 2 on Apple Silicon)`);
			}
			return release;
		}
	}

	throw new Error(`Aucune JRE Adoptium pour Java ${javaMajor} sur ${osTag} (architectures essayées : ${archCandidates.join(', ')})`);
}

// Public entry point. Returns the absolute path to the `java` binary that
// matches the requested Minecraft version. If a matching JRE is already
// installed under <launcherRoot>/runtime/java-<major>/ we reuse it; otherwise
// we download + extract Adoptium Temurin.
async function ensureJavaForMinecraft(launcherRootDir, mcVersion, { onStatus, onProgress, debugLog } = {}) {
	const dlog = typeof debugLog === 'function' ? debugLog : () => {};
	const javaMajor = getRequiredJavaVersion(mcVersion);
	const installDir = getJavaInstallDir(launcherRootDir, javaMajor);

	dlog(`[java] required Java major = ${javaMajor} (mc=${mcVersion})`);

	// Reuse existing install if the binary is still there.
	const existing = findExtractedJavaBinary(installDir);
	if (existing && fsSync.existsSync(existing)) {
		dlog(`[java] reusing existing JRE at ${existing}`);
		return existing;
	}

	if (typeof onStatus === 'function') onStatus(`Recherche de Java ${javaMajor} sur Adoptium…`);
	const release = await fetchAdoptiumRelease(javaMajor, debugLog);
	dlog(`[java] Adoptium release: ${release.releaseVersion}, arch=${release.arch}, ${release.url}`);

	await fsp.mkdir(installDir, { recursive: true });
	const archivePath = path.join(installDir, release.name);

	if (typeof onStatus === 'function') onStatus(`Téléchargement de Java ${javaMajor}…`);
	await downloadToFile(release.url, archivePath, { onProgress });

	if (release.sha256) {
		if (typeof onStatus === 'function') onStatus(`Vérification de Java ${javaMajor}…`);
		const actual = await sha256File(archivePath);
		if (actual !== release.sha256) {
			try { await fsp.unlink(archivePath); } catch { /* ignore */ }
			throw new Error(`Java ${javaMajor} : SHA-256 invalide (corrompu ?)`);
		}
		dlog(`[java] SHA-256 verified`);
	}

	if (typeof onStatus === 'function') onStatus(`Installation de Java ${javaMajor}…`);
	await extractArchive(archivePath, installDir);
	try { await fsp.unlink(archivePath); } catch { /* ignore */ }

	const javaBin = findExtractedJavaBinary(installDir);
	if (!javaBin) {
		throw new Error(`Java ${javaMajor} extrait mais binaire introuvable dans ${installDir}`);
	}
	if (process.platform !== 'win32') {
		try { await fsp.chmod(javaBin, 0o755); } catch { /* ignore */ }
	}
	dlog(`[java] installed Java ${javaMajor} at ${javaBin}`);
	return javaBin;
}

module.exports = {
	ensureJavaForMinecraft,
	getRequiredJavaVersion,
};

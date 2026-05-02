/* eslint-disable no-console */
'use strict';

const fsp = require('node:fs/promises');
const path = require('node:path');

const { Authflow, Titles } = require('prismarine-auth');

function isPlainObject(v) {
	return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function getAuthJsonPath(paths) {
	return path.join(paths.rootDir, 'auth.json');
}

function getAuthCacheDir(paths) {
	return path.join(paths.rootDir, 'auth-cache');
}

function validateSession(raw) {
	if (!isPlainObject(raw)) return null;

	const type = typeof raw.type === 'string' ? raw.type.trim() : '';
	const username = typeof raw.username === 'string' ? raw.username.trim() : '';
	const uuid = typeof raw.uuid === 'string' ? raw.uuid.trim() : '';
	const access_token = typeof raw.access_token === 'string' ? raw.access_token.trim() : '';

	if (type !== 'microsoft') return null;
	if (!username || !uuid || !access_token) return null;

	return { type, username, uuid, access_token };
}

async function readSession(paths) {
	const authPath = getAuthJsonPath(paths);
	let text;
	try {
		text = await fsp.readFile(authPath, 'utf8');
	} catch (err) {
		if (err && err.code === 'ENOENT') return null;
		throw err;
	}

	let json;
	try {
		json = JSON.parse(text);
	} catch {
		return null;
	}
	return validateSession(json);
}

async function writeSession(paths, session) {
	const authPath = getAuthJsonPath(paths);
	const tmpPath = authPath + '.tmp';
	const payload = JSON.stringify(session, null, 2) + '\n';

	await fsp.mkdir(path.dirname(authPath), { recursive: true });
	// Write with restrictive mode so we can secure it before rename
	await fsp.writeFile(tmpPath, payload, 'utf8');
	await fsp.chmod(tmpPath, 0o600); // owner read/write only

	try {
		await fsp.rename(tmpPath, authPath);
	} catch (err) {
		if (err && (err.code === 'EEXIST' || err.code === 'EPERM' || err.code === 'ENOTEMPTY')) {
			try {
				await fsp.unlink(authPath);
			} catch (e) {
				if (!(e && e.code === 'ENOENT')) throw e;
			}
			await fsp.rename(tmpPath, authPath);
			// Re-apply secure permissions after rename
			await fsp.chmod(authPath, 0o600);
		} else {
			throw err;
		}
	}

	// Ensure secure permissions on final file
	await fsp.chmod(authPath, 0o600);
	return authPath;
}

async function clearAuthCache(cacheDir, debugLog) {
	try {
		await fsp.rm(cacheDir, { recursive: true, force: true });
		await fsp.mkdir(cacheDir, { recursive: true });
		if (typeof debugLog === 'function') debugLog('[auth] 🧹 Auth cache cleared');
	} catch (err) {
		if (typeof debugLog === 'function') debugLog(`[auth] ⚠️ Could not clear cache: ${err && err.message ? err.message : err}`);
	}
}

function runWithTimeout(promise, timeoutMs, label) {
	let timer;
	const timeoutPromise = new Promise((_, reject) => {
		timer = setTimeout(() => reject(new Error(`${label} timeout after ${Math.round(timeoutMs / 1000)}s`)), timeoutMs);
	});
	return Promise.race([promise, timeoutPromise]).finally(() => clearTimeout(timer));
}

async function tryFlow(userIdentifier, cacheDir, options, timeoutMs, label, debugLog) {
	if (typeof debugLog === 'function') debugLog(`[auth] 🔵 Trying ${label} (flow=${options.flow}, title=${options.authTitle})`);
	const flow = new Authflow(userIdentifier, cacheDir, options);
	const result = await runWithTimeout(
		flow.getMinecraftJavaToken({ fetchProfile: true }),
		timeoutMs,
		label,
	);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅ ${label} succeeded`);
	return result;
}

async function loginMicrosoft(paths, { onMsaCode, debugLog } = {}) {
	if (typeof debugLog === 'function') debugLog('[auth] 🚀 Microsoft login starting...');

	const cacheDir = getAuthCacheDir(paths);
	await fsp.mkdir(cacheDir, { recursive: true });

	// Identifier used only for local caching.
	const userIdentifier = 'default';

	const codeCb = typeof onMsaCode === 'function' ? (data) => {
		if (typeof debugLog === 'function') debugLog(`[auth] 📱 Device code received: ${JSON.stringify(data)}`);
		try {
			onMsaCode(data);
		} catch (err) {
			if (typeof debugLog === 'function') debugLog(`[auth] ❌ Error in onMsaCode callback: ${err && err.message ? err.message : err}`);
		}
	} : undefined;

	// 2-minute timeout per attempt: long enough for the user to enter the code,
	// short enough to fall back quickly if Microsoft hangs.
	const PER_ATTEMPT_TIMEOUT_MS = 2 * 60 * 1000;

	// Strategy ordered by reliability for third-party launchers in 2026:
	//   1. live + MinecraftNintendoSwitch — the de-facto standard for unofficial
	//      launchers (Prism, ATLauncher, MultiMC, CMCL). Microsoft has not
	//      restricted this client ID and it always emits a device code via
	//      `onMsaCode`, which is what the renderer expects.
	//   2. sisu + MinecraftJava — official Java client. Increasingly restricted
	//      by Microsoft for third-party use, kept as a fallback only.
	const strategies = [
		{
			label: 'live+MinecraftNintendoSwitch',
			options: {
				flow: 'live',
				authTitle: Titles.MinecraftNintendoSwitch,
				deviceType: 'Nintendo',
				onMsaCode: codeCb,
			},
		},
		{
			label: 'sisu+MinecraftJava',
			options: {
				flow: 'sisu',
				authTitle: Titles.MinecraftJava,
				deviceType: 'Win32',
				onMsaCode: codeCb,
			},
		},
	];

	let result;
	let lastErr;
	for (let i = 0; i < strategies.length; i += 1) {
		const { label, options } = strategies[i];
		try {
			result = await tryFlow(userIdentifier, cacheDir, options, PER_ATTEMPT_TIMEOUT_MS, label, debugLog);
			break;
		} catch (err) {
			lastErr = err;
			const msg = err && err.message ? String(err.message) : String(err);
			if (typeof debugLog === 'function') debugLog(`[auth] ❌ ${label} failed: ${msg}`);

			// Always purge the cache between attempts: a half-finished flow can
			// leave stale tokens that cause the next strategy to short-circuit
			// onto the same broken code path without ever calling onMsaCode.
			await clearAuthCache(cacheDir, debugLog);
		}
	}

	if (!result) {
		const msg = lastErr && lastErr.message ? lastErr.message : 'unknown error';
		throw new Error(`Microsoft authentication failed after all strategies: ${msg}`);
	}

	const token = result && typeof result.token === 'string' ? result.token.trim() : '';
	const profile = result && result.profile ? result.profile : null;

	const id = profile && typeof profile.id === 'string' ? profile.id.trim() : '';
	const name = profile && typeof profile.name === 'string' ? profile.name.trim() : '';

	if (!token) throw new Error('Microsoft authentication failed: no access token');
	if (!id || !name) throw new Error('Microsoft authentication failed: incomplete profile');

	const session = {
		type: 'microsoft',
		username: name,
		uuid: id,
		access_token: token,
	};

	await writeSession(paths, session);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅✅ SUCCESS! Logged in as: ${name}`);
	return session;
}

async function logout(paths) {
	const authPath = getAuthJsonPath(paths);
	const cacheDir = getAuthCacheDir(paths);

	try {
		await fsp.unlink(authPath);
	} catch (err) {
		if (!(err && err.code === 'ENOENT')) throw err;
	}
	try {
		await fsp.rm(cacheDir, { recursive: true, force: true });
	} catch {
		// ignore
	}

	return { ok: true };
}

module.exports = {
	getAuthJsonPath,
	getAuthCacheDir,
	getSession: readSession,
	loginMicrosoft,
	logout,
};

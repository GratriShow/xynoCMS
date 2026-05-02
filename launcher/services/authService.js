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

async function tryFlow(userIdentifier, cacheDir, baseOptions, codeCb, label, debugLog) {
	if (typeof debugLog === 'function') debugLog(`[auth] 🔵 Trying ${label} (flow=${baseOptions.flow}, title=${baseOptions.authTitle})`);

	// Two-phase timeout:
	//   - PRE_CODE: 30s to obtain a device code (codeCb fires). If we don't
	//     hear back from Microsoft this fast, the endpoint is unreachable for
	//     this strategy and we should bail to the next one.
	//   - POST_CODE: once the user has the code, give them 5 minutes to enter it.
	const PRE_CODE_TIMEOUT_MS = 30 * 1000;
	const POST_CODE_TIMEOUT_MS = 5 * 60 * 1000;

	let codeReceived = false;
	let timer;
	let rejectTimeout;

	const timeoutPromise = new Promise((_, reject) => {
		rejectTimeout = reject;
		timer = setTimeout(() => {
			reject(new Error(`${label} timeout (no device code in ${PRE_CODE_TIMEOUT_MS / 1000}s — Microsoft endpoint unreachable?)`));
		}, PRE_CODE_TIMEOUT_MS);
	});

	const wrappedCodeCb = (data) => {
		if (!codeReceived) {
			codeReceived = true;
			if (typeof debugLog === 'function') debugLog(`[auth] ✅ Device code received for ${label}, extending timeout to ${POST_CODE_TIMEOUT_MS / 1000}s`);
			clearTimeout(timer);
			timer = setTimeout(() => {
				rejectTimeout(new Error(`${label} timeout (user didn't enter code within ${POST_CODE_TIMEOUT_MS / 1000}s)`));
			}, POST_CODE_TIMEOUT_MS);
		}
		if (typeof codeCb === 'function') {
			try { codeCb(data); } catch { /* ignore */ }
		}
	};

	// IMPORTANT: in prismarine-auth 3.x the device-code callback is the FOURTH
	// constructor argument, not an option. Passing it as `options.onMsaCode`
	// silently does nothing — prismarine-auth falls back to its built-in
	// console.log, the launcher never sees the code, and the user is stuck.
	const flow = new Authflow(userIdentifier, cacheDir, baseOptions, wrappedCodeCb);

	try {
		const result = await Promise.race([
			flow.getMinecraftJavaToken({ fetchProfile: true }),
			timeoutPromise,
		]);
		if (typeof debugLog === 'function') debugLog(`[auth] ✅ ${label} succeeded`);
		return result;
	} finally {
		clearTimeout(timer);
	}
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

	// loginMicrosoft is always interactive — purge any cached state from a
	// previous broken attempt so prismarine-auth starts a fresh device-code flow
	// instead of silently retrying a poisoned token.
	await clearAuthCache(cacheDir, debugLog);

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
			},
		},
		{
			label: 'sisu+MinecraftJava',
			options: {
				flow: 'sisu',
				authTitle: Titles.MinecraftJava,
				deviceType: 'Win32',
			},
		},
	];

	let result;
	let lastErr;
	for (let i = 0; i < strategies.length; i += 1) {
		const { label, options } = strategies[i];
		try {
			result = await tryFlow(userIdentifier, cacheDir, options, codeCb, label, debugLog);
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

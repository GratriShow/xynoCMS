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

async function loginMicrosoft(paths, { onMsaCode, debugLog } = {}) {
	if (typeof debugLog === 'function') debugLog('═════════════════════════════════════════');
	if (typeof debugLog === 'function') debugLog('[auth] 🚀 loginMicrosoft() STARTING');
	if (typeof debugLog === 'function') debugLog(`[auth] onMsaCode callback present: ${typeof onMsaCode === 'function' ? '✅ YES' : '❌ NO'}`);
	if (typeof debugLog === 'function') debugLog(`[auth] debugLog function present: ${typeof debugLog === 'function' ? '✅ YES' : '❌ NO'}`);
	if (typeof debugLog === 'function') debugLog('═════════════════════════════════════════');

	const cacheDir = getAuthCacheDir(paths);
	if (typeof debugLog === 'function') debugLog(`[auth] Cache directory: ${cacheDir}`);
	await fsp.mkdir(cacheDir, { recursive: true });
	if (typeof debugLog === 'function') debugLog('[auth] ✅ Cache directory ready');

	// Identifier used only for local caching.
	const userIdentifier = 'default';

	function isForbidden(err) {
		const msg = err && err.message ? String(err.message) : '';
		const code = err && (err.statusCode || err.status) ? String(err.statusCode || err.status) : '';
		return msg.includes('403') || code === '403';
	}

	async function getTokenWith(options) {
		if (typeof debugLog === 'function') debugLog(`[auth] 🔧 Creating Authflow with options: flow=${options.flow}, authTitle=${options.authTitle}, deviceType=${options.deviceType}`);

		// Add custom headers/agent to avoid being blocked by Minecraft servers
		const enhancedOptions = {
			...options,
			// Use a more standard User-Agent that Minecraft won't block
			userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		};

		const flow = new Authflow(userIdentifier, cacheDir, enhancedOptions);
		if (typeof debugLog === 'function') debugLog('[auth] ✅ Authflow created successfully');

		// Add explicit timeout (60 seconds) to prevent hanging - increased from 30s
		const timeoutMs = 60000;
		const timeoutPromise = new Promise((_, reject) =>
			setTimeout(() => reject(new Error(`Authentication timeout after ${timeoutMs/1000}s. Check your internet connection and firewall settings.`)), timeoutMs)
		);

		if (typeof debugLog === 'function') debugLog(`[auth] ⏳ Starting token fetch with ${timeoutMs/1000}s timeout...`);

		try {
			if (typeof debugLog === 'function') debugLog('[auth] 🌐 Calling getMinecraftJavaToken({ fetchProfile: true })...');
			const tokenPromise = flow.getMinecraftJavaToken({ fetchProfile: true });

			if (typeof debugLog === 'function') debugLog('[auth] ⏳ Waiting for token promise or timeout...');
			const result = await Promise.race([tokenPromise, timeoutPromise]);

			if (typeof debugLog === 'function') debugLog('[auth] ✅ Token fetch completed successfully');
			return result;
		} catch (err) {
			const isTimeout = err && err.message && err.message.includes('timeout');
			const errorMsg = err && err.message ? String(err.message) : String(err);

			if (isTimeout) {
				if (typeof debugLog === 'function') debugLog(`[auth] ⏱️ TIMEOUT after ${timeoutMs/1000}s: ${errorMsg}`);
				if (typeof debugLog === 'function') debugLog('[auth] ℹ️ Possible causes: No internet, DNS issues, firewall blocking, or Microsoft servers unreachable');
			} else {
				if (typeof debugLog === 'function') debugLog(`[auth] ❌ Token fetch error: ${errorMsg}`);
				if (typeof debugLog === 'function') debugLog(`[auth] Error type: ${err && err.constructor && err.constructor.name ? err.constructor.name : 'Unknown'}`);
				if (err && err.code) debugLog(`[auth] Error code: ${err.code}`);
			}
			throw err;
		}
	}

	const codeCb = typeof onMsaCode === 'function' ? (data) => {
		if (typeof debugLog === 'function') debugLog(`[auth] 📱 onMsaCode callback triggered with data: ${JSON.stringify(data)}`);
		try {
			onMsaCode(data);
			if (typeof debugLog === 'function') debugLog('[auth] ✅ onMsaCode callback executed successfully');
		} catch (err) {
			if (typeof debugLog === 'function') debugLog(`[auth] ❌ onMsaCode callback error: ${err && err.message ? err.message : err}`);
			throw err;
		}
	} : undefined;

	let result;
	let lastError = null;
	try {
		// Recommended for MinecraftJava authTitle (avoids some Forbidden issues).
		if (typeof debugLog === 'function') debugLog('[auth] 🔵 Trying MinecraftJava flow (sisu) with Microsoft servers...');
		result = await getTokenWith({
			flow: 'sisu',
			authTitle: Titles.MinecraftJava,
			deviceType: 'Win32',
			onMsaCode: codeCb,
		});
		if (typeof debugLog === 'function') debugLog('[auth] ✅ MinecraftJava flow succeeded');
	} catch (err) {
		lastError = err;
		const isTimeout = err && err.message && err.message.includes('timeout');
		const isForbidden403 = err && (err.statusCode === 403 || err.status === 403 || (err.message && err.message.includes('403')));
		const errorMsg = err && err.message ? String(err.message) : String(err);

		if (isTimeout) {
			if (typeof debugLog === 'function') debugLog(`[auth] ⏱️ MinecraftJava flow TIMEOUT: ${errorMsg}`);
			if (typeof debugLog === 'function') debugLog('[auth] ℹ️ Server blocked the request - may need different User-Agent');
		} else if (isForbidden403) {
			if (typeof debugLog === 'function') debugLog(`[auth] 🚫 MinecraftJava flow got 403 Forbidden: ${errorMsg}`);
			if (typeof debugLog === 'function') debugLog('[auth] ℹ️ Server rejected request - trying fallback flow...');
		} else {
			if (typeof debugLog === 'function') debugLog(`[auth] ❌ MinecraftJava flow failed: ${errorMsg}`);
		}

		// Try fallback on 403, not on other errors
		if (!isForbidden(err)) {
			if (typeof debugLog === 'function') debugLog('[auth] ❌ Fatal error, not attempting fallback');
			throw err;
		}

		if (typeof debugLog === 'function') debugLog('[auth] 🔄 Trying MinecraftNintendoSwitch fallback (live) with alternative flow...');
		try {
			result = await getTokenWith({
				flow: 'live',
				authTitle: Titles.MinecraftNintendoSwitch,
				deviceType: 'Nintendo',
				onMsaCode: codeCb,
			});
			if (typeof debugLog === 'function') debugLog('[auth] ✅ MinecraftNintendoSwitch fallback succeeded');
		} catch (fallbackErr) {
			const fallbackIsTimeout = fallbackErr && fallbackErr.message && fallbackErr.message.includes('timeout');
			const fallbackErrorMsg = fallbackErr && fallbackErr.message ? String(fallbackErr.message) : String(fallbackErr);

			if (fallbackIsTimeout) {
				if (typeof debugLog === 'function') debugLog(`[auth] ⏱️ Fallback flow TIMEOUT: ${fallbackErrorMsg}`);
				if (typeof debugLog === 'function') debugLog('[auth] ℹ️ Server still blocking - issue is likely server-side blocking');
			} else {
				if (typeof debugLog === 'function') debugLog(`[auth] ❌ Fallback flow also failed: ${fallbackErrorMsg}`);
			}

			throw fallbackErr;
		}
	}
	const token = result && typeof result.token === 'string' ? result.token.trim() : '';
	const profile = result && result.profile ? result.profile : null;

	const id = profile && typeof profile.id === 'string' ? profile.id.trim() : '';
	const name = profile && typeof profile.name === 'string' ? profile.name.trim() : '';

	if (typeof debugLog === 'function') debugLog(`[auth] Validating token and profile... token=${token ? '✅' : '❌'} profile=${profile ? '✅' : '❌'}`);

	if (!token) {
		if (typeof debugLog === 'function') debugLog('[auth] ❌ ERROR: Missing access token from Microsoft');
		throw new Error('Microsoft login failed: no access token received. Try again or check your internet.');
	}
	if (!id || !name) {
		if (typeof debugLog === 'function') debugLog(`[auth] ❌ ERROR: Missing profile data. id=${id ? '✅' : '❌'} name=${name ? '✅' : '❌'}`);
		throw new Error('Microsoft login failed: missing profile data. Try again.');
	}

	const session = {
		type: 'microsoft',
		username: name,
		uuid: id,
		access_token: token,
	};

	await writeSession(paths, session);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅ SUCCESS! Session saved for user: ${name}`);
	if (typeof debugLog === 'function') debugLog(`[auth] 🎮 Ready to play! User can now launch the game.`);
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


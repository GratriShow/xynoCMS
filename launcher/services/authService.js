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

	let result;
	try {
		if (typeof debugLog === 'function') debugLog('[auth] 🔵 Attempting MinecraftJava authentication flow...');

		// Create authflow with MinecraftJava title (most reliable for Minecraft Java Edition)
		const flow = new Authflow(userIdentifier, cacheDir, {
			flow: 'sisu',
			authTitle: Titles.MinecraftJava,
			deviceType: 'Win32',
			onMsaCode: codeCb,
		});

		if (typeof debugLog === 'function') debugLog('[auth] ⏳ Calling getMinecraftJavaToken()...');
		result = await flow.getMinecraftJavaToken({ fetchProfile: true });

		if (typeof debugLog === 'function') debugLog('[auth] ✅ Token retrieved successfully');
	} catch (err) {
		if (typeof debugLog === 'function') debugLog(`[auth] ❌ MinecraftJava flow failed: ${err && err.message ? err.message : err}`);

		// Try fallback on 403 error
		const code = err && (err.statusCode || err.status) ? String(err.statusCode || err.status) : '';
		const msg = err && err.message ? String(err.message) : '';

		if (code === '403' || msg.includes('403')) {
			if (typeof debugLog === 'function') debugLog('[auth] 🔄 Got 403, trying Nintendo Switch fallback...');

			try {
				const fallbackFlow = new Authflow(userIdentifier, cacheDir, {
					flow: 'live',
					authTitle: Titles.MinecraftNintendoSwitch,
					deviceType: 'Nintendo',
					onMsaCode: codeCb,
				});

				result = await fallbackFlow.getMinecraftJavaToken({ fetchProfile: true });
				if (typeof debugLog === 'function') debugLog('[auth] ✅ Fallback successful');
			} catch (fallbackErr) {
				if (typeof debugLog === 'function') debugLog(`[auth] ❌ Fallback also failed: ${fallbackErr && fallbackErr.message ? fallbackErr.message : fallbackErr}`);
				throw fallbackErr;
			}
		} else {
			throw err;
		}
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

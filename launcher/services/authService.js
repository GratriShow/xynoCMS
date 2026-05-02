/* eslint-disable no-console */
'use strict';

const fsp = require('node:fs/promises');
const path = require('node:path');

const { loginInteractive } = require('./msaInteractive');

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

// Interactive Microsoft login: opens an Electron BrowserWindow on the official
// Microsoft sign-in page (no device code), captures the OAuth redirect, then
// runs the Xbox Live → XSTS → Minecraft Services chain manually. See
// `services/msaInteractive.js` for the full implementation.
//
// `parentWindow` (optional) ties the popup to the launcher window so it stays
// in focus on top of it. `onMsaCode` is accepted but ignored — kept in the
// signature for backward compatibility with callers that haven't been updated
// yet (the device-code IPC event is no longer emitted).
async function loginMicrosoft(paths, { debugLog, parentWindow } = {}) {
	if (typeof debugLog === 'function') debugLog('[auth] 🚀 Interactive Microsoft login starting...');

	// We no longer use prismarine-auth's on-disk cache, but logout() still
	// clears the directory so users that ran an older version don't keep stale
	// state around. Make sure it exists so logout() doesn't fail.
	const cacheDir = getAuthCacheDir(paths);
	await fsp.mkdir(cacheDir, { recursive: true });

	const session = await loginInteractive({ debugLog, parentWindow });

	const token = session && typeof session.access_token === 'string' ? session.access_token.trim() : '';
	const id = session && typeof session.uuid === 'string' ? session.uuid.trim() : '';
	const name = session && typeof session.username === 'string' ? session.username.trim() : '';

	if (!token) throw new Error('Microsoft authentication failed: no access token');
	if (!id || !name) throw new Error('Microsoft authentication failed: incomplete profile');

	// Persist only the validated public-shape session. We deliberately drop the
	// refresh_token from disk for now: storing it would let us silently refresh
	// the Minecraft token, but it's also a credential worth protecting. If we
	// later want "stay signed in" UX, we'll wire the refresh_token through with
	// proper at-rest encryption.
	const persisted = {
		type: 'microsoft',
		username: name,
		uuid: id,
		access_token: token,
	};

	await writeSession(paths, persisted);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅✅ SUCCESS! Logged in as: ${name}`);
	return persisted;
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

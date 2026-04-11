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
	await fsp.writeFile(tmpPath, payload, 'utf8');

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
		} else {
			throw err;
		}
	}

	return authPath;
}

async function loginMicrosoft(paths, { onMsaCode } = {}) {
	const cacheDir = getAuthCacheDir(paths);
	await fsp.mkdir(cacheDir, { recursive: true });

	// Identifier used only for local caching.
	const userIdentifier = 'default';

	function isForbidden(err) {
		const msg = err && err.message ? String(err.message) : '';
		const code = err && (err.statusCode || err.status) ? String(err.statusCode || err.status) : '';
		return msg.includes('403') || code === '403';
	}

	async function getTokenWith(options) {
		const flow = new Authflow(userIdentifier, cacheDir, options);
		return await flow.getMinecraftJavaToken({ fetchProfile: true });
	}

	const codeCb = typeof onMsaCode === 'function' ? onMsaCode : undefined;

	let result;
	try {
		// Recommended for MinecraftJava authTitle (avoids some Forbidden issues).
		result = await getTokenWith({
			flow: 'sisu',
			authTitle: Titles.MinecraftJava,
			deviceType: 'Win32',
			onMsaCode: codeCb,
		});
	} catch (err) {
		// Fallback: known working title for device+title auth.
		if (!isForbidden(err)) throw err;
		result = await getTokenWith({
			flow: 'live',
			authTitle: Titles.MinecraftNintendoSwitch,
			deviceType: 'Nintendo',
			onMsaCode: codeCb,
		});
	}
	const token = result && typeof result.token === 'string' ? result.token.trim() : '';
	const profile = result && result.profile ? result.profile : null;

	const id = profile && typeof profile.id === 'string' ? profile.id.trim() : '';
	const name = profile && typeof profile.name === 'string' ? profile.name.trim() : '';

	if (!token) throw new Error('Microsoft login failed (missing token)');
	if (!id || !name) throw new Error('Microsoft login failed (missing profile)');

	const session = {
		type: 'microsoft',
		username: name,
		uuid: id,
		access_token: token,
	};

	await writeSession(paths, session);
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


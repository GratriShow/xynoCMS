/* eslint-disable no-console */
'use strict';

/**
 * authService.js — Gestion des sessions d'authentification
 *
 * Trois modes supportés :
 *  1. 'microsoft' — Compte Mojang premium via MSA (flux OAuth interactif)
 *  2. 'xyno'      — Compte XynoWeb offline : username validé par l'API XynoWeb,
 *                   UUID généré côté serveur et persisté. Aucun compte Mojang requis.
 *  3. 'offline'   — Mode local pur : username libre, UUID dérivé du username (v3/MD5),
 *                   aucun appel réseau. Serveur doit être en online-mode=false.
 */

const crypto = require('node:crypto');
const fsp = require('node:fs/promises');
const path = require('node:path');
const https = require('node:https');
const http = require('node:http');

const { loginInteractive } = require('./msaInteractive');

// ─────────────────────────────────────────────────────────────────────────────
// Helpers internes
// ─────────────────────────────────────────────────────────────────────────────

function isPlainObject(v) {
	return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function getAuthJsonPath(paths) {
	return path.join(paths.rootDir, 'auth.json');
}

function getAuthCacheDir(paths) {
	return path.join(paths.rootDir, 'auth-cache');
}

/**
 * Génère un UUID v3 (MD5-based) déterministe à partir d'un username.
 * Compatible avec le format UUID Minecraft offline vanilla.
 */
function offlineUuid(username) {
	const NAMESPACE = 'OfflinePlayer:'; // Préfixe vanilla Minecraft
	const hash = crypto.createHash('md5').update(NAMESPACE + username).digest();

	// Forcer les bits de version (v3) et de variant
	hash[6] = (hash[6] & 0x0f) | 0x30; // version 3
	hash[8] = (hash[8] & 0x3f) | 0x80; // variant RFC4122

	const hex = hash.toString('hex');
	return [
		hex.slice(0, 8),
		hex.slice(8, 12),
		hex.slice(12, 16),
		hex.slice(16, 20),
		hex.slice(20, 32),
	].join('-');
}

/**
 * Génère un faux access_token pour les modes offline (Minecraft l'accepte
 * si le serveur est en offline-mode=false ; sinon il est ignoré).
 */
function offlineToken() {
	return '0'.repeat(32);
}

// ─────────────────────────────────────────────────────────────────────────────
// Validation des sessions
// ─────────────────────────────────────────────────────────────────────────────

const VALID_TYPES = new Set(['microsoft', 'xyno', 'offline']);

function validateSession(raw) {
	if (!isPlainObject(raw)) return null;

	const type = typeof raw.type === 'string' ? raw.type.trim() : '';
	const username = typeof raw.username === 'string' ? raw.username.trim() : '';
	const uuid = typeof raw.uuid === 'string' ? raw.uuid.trim() : '';
	const access_token = typeof raw.access_token === 'string' ? raw.access_token.trim() : '';

	if (!VALID_TYPES.has(type)) return null;
	if (!username || !uuid) return null;

	// Microsoft requiert un access_token non vide
	if (type === 'microsoft' && !access_token) return null;

	return {
		type,
		username,
		uuid,
		access_token,
		// Champs optionnels présents sur les sessions 'xyno'
		...(raw.xyno_token ? { xyno_token: raw.xyno_token } : {}),
	};
}

// ─────────────────────────────────────────────────────────────────────────────
// Lecture / écriture de la session sur disque
// ─────────────────────────────────────────────────────────────────────────────

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
	await fsp.chmod(tmpPath, 0o600);

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
			await fsp.chmod(authPath, 0o600);
		} else {
			throw err;
		}
	}

	await fsp.chmod(authPath, 0o600);
	return authPath;
}

// ─────────────────────────────────────────────────────────────────────────────
// Appel HTTP simple vers l'API XynoWeb
// ─────────────────────────────────────────────────────────────────────────────

function apiPost(baseUrl, endpoint, body, timeoutMs = 10_000) {
	return new Promise((resolve, reject) => {
		let url;
		try {
			url = new URL(endpoint, baseUrl);
		} catch (e) {
			return reject(new Error(`URL invalide : ${baseUrl}${endpoint}`));
		}

		const payload = JSON.stringify(body);
		const requestFn = url.protocol === 'https:' ? https.request : http.request;

		const req = requestFn(
			{
				hostname: url.hostname,
				port: url.port || (url.protocol === 'https:' ? 443 : 80),
				path: url.pathname + url.search,
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Content-Length': Buffer.byteLength(payload),
					Accept: 'application/json',
				},
				timeout: timeoutMs,
			},
			(res) => {
				const chunks = [];
				res.on('data', (d) => chunks.push(d));
				res.on('end', () => {
					const text = Buffer.concat(chunks).toString('utf8');
					let json = null;
					try {
						json = JSON.parse(text);
					} catch {
						return reject(new Error(`Réponse API non-JSON : ${text.slice(0, 200)}`));
					}
					resolve({ status: res.statusCode, data: json });
				});
			}
		);

		req.on('timeout', () => {
			req.destroy();
			reject(new Error('Timeout appel API XynoWeb'));
		});
		req.on('error', reject);
		req.write(payload);
		req.end();
	});
}

// ─────────────────────────────────────────────────────────────────────────────
// MODE 1 : Authentification Microsoft (compte Mojang premium)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Lance le flux OAuth Microsoft interactif.
 * Ouvre une BrowserWindow Electron sur la page de connexion Microsoft,
 * puis enchaîne Xbox Live → XSTS → Minecraft Services.
 */
async function loginMicrosoft(paths, { debugLog, parentWindow } = {}) {
	if (typeof debugLog === 'function') debugLog('[auth] 🚀 Microsoft login démarré...');

	const cacheDir = getAuthCacheDir(paths);
	await fsp.mkdir(cacheDir, { recursive: true });

	const session = await loginInteractive({ debugLog, parentWindow });

	const token = session && typeof session.access_token === 'string' ? session.access_token.trim() : '';
	const id = session && typeof session.uuid === 'string' ? session.uuid.trim() : '';
	const name = session && typeof session.username === 'string' ? session.username.trim() : '';

	if (!token) throw new Error('Authentification Microsoft échouée : pas de access_token');
	if (!id || !name) throw new Error('Authentification Microsoft échouée : profil incomplet');

	const persisted = {
		type: 'microsoft',
		username: name,
		uuid: id,
		access_token: token,
	};

	await writeSession(paths, persisted);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅ Connecté (Microsoft) : ${name}`);
	return persisted;
}

// ─────────────────────────────────────────────────────────────────────────────
// MODE 2 : Authentification XynoWeb offline
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Authentifie un joueur via l'API XynoWeb sans compte Mojang premium.
 *
 * Le serveur XynoWeb :
 *  - Vérifie que le username est valide (pas de doublon, format correct).
 *  - Génère ou retrouve un UUID persistant pour ce joueur.
 *  - Retourne un xyno_token (JWT ou token opaque) pour les futures requêtes.
 *
 * @param {object} paths       — Chemins launcher (rootDir, etc.)
 * @param {string} username    — Pseudo choisi par le joueur
 * @param {string} apiBaseUrl  — URL de base de l'API XynoWeb (ex: https://api.xynoweb.fr)
 * @param {string} launcherId  — ID du launcher (identifie le projet côté XynoWeb)
 * @param {string} [hmacSecret]— Secret HMAC optionnel pour signer la requête
 * @param {function} [debugLog]
 */
async function loginXynoWeb(paths, { username, apiBaseUrl, launcherId, hmacSecret, debugLog } = {}) {
	if (!username || typeof username !== 'string' || username.trim().length < 3) {
		throw new Error('Username invalide (min 3 caractères)');
	}
	if (!apiBaseUrl) throw new Error('apiBaseUrl requis pour le mode XynoWeb');
	if (!launcherId) throw new Error('launcherId requis pour le mode XynoWeb');

	const cleanUsername = username.trim();
	if (typeof debugLog === 'function') debugLog(`[auth] 🔑 Login XynoWeb offline → ${cleanUsername}`);

	const body = {
		username: cleanUsername,
		launcher_id: launcherId,
		mode: 'offline',
		ts: Math.floor(Date.now() / 1000),
	};

	// Signature HMAC optionnelle
	if (hmacSecret) {
		const payload = `${launcherId}:${cleanUsername}:${body.ts}`;
		body.sig = crypto.createHmac('sha256', hmacSecret).update(payload).digest('hex');
	}

	let resp;
	try {
		resp = await apiPost(apiBaseUrl, '/api/v2/offline_auth.php', body);
	} catch (err) {
		throw new Error(`Erreur réseau XynoWeb : ${err.message}`);
	}

	if (resp.status !== 200 || !resp.data.ok) {
		const msg = resp.data && resp.data.error ? resp.data.error : `HTTP ${resp.status}`;
		throw new Error(`XynoWeb auth refusée : ${msg}`);
	}

	const { uuid, xyno_token } = resp.data;
	if (!uuid) throw new Error('UUID manquant dans la réponse XynoWeb');

	const persisted = {
		type: 'xyno',
		username: cleanUsername,
		uuid,
		access_token: offlineToken(), // Minecraft en offline-mode=false ignore ce token
		xyno_token: xyno_token || '',
	};

	await writeSession(paths, persisted);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅ Connecté (XynoWeb) : ${cleanUsername} [${uuid}]`);
	return persisted;
}

// ─────────────────────────────────────────────────────────────────────────────
// MODE 3 : Offline local pur (aucune API)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Crée une session offline locale sans aucun appel réseau.
 * L'UUID est dérivé du username de façon déterministe (compatible vanilla offline).
 * Le serveur Minecraft DOIT être en online-mode=false pour accepter ce mode.
 *
 * @param {object} paths
 * @param {string} username  — Pseudo du joueur
 * @param {function} [debugLog]
 */
async function loginOfflineLocal(paths, { username, debugLog } = {}) {
	if (!username || typeof username !== 'string' || username.trim().length < 3) {
		throw new Error('Username invalide (min 3 caractères)');
	}

	const cleanUsername = username.trim();

	// Validation basique : lettres, chiffres, tirets, underscores (standard MC)
	if (!/^[a-zA-Z0-9_\-]{3,16}$/.test(cleanUsername)) {
		throw new Error('Username invalide : 3-16 caractères, lettres/chiffres/_/-  uniquement');
	}

	const uuid = offlineUuid(cleanUsername);

	if (typeof debugLog === 'function') {
		debugLog(`[auth] 🔓 Login offline local : ${cleanUsername} → ${uuid}`);
	}

	const persisted = {
		type: 'offline',
		username: cleanUsername,
		uuid,
		access_token: offlineToken(),
	};

	await writeSession(paths, persisted);
	if (typeof debugLog === 'function') debugLog(`[auth] ✅ Session offline créée : ${cleanUsername}`);
	return persisted;
}

// ─────────────────────────────────────────────────────────────────────────────
// Déconnexion
// ─────────────────────────────────────────────────────────────────────────────

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
		// ignore — le dossier peut ne pas exister
	}

	return { ok: true };
}

// ─────────────────────────────────────────────────────────────────────────────
// Exports
// ─────────────────────────────────────────────────────────────────────────────

module.exports = {
	getAuthJsonPath,
	getAuthCacheDir,
	getSession: readSession,

	// Méthodes de connexion
	loginMicrosoft,     // Compte premium Mojang
	loginXynoWeb,       // Compte XynoWeb offline (API)
	loginOfflineLocal,  // Mode offline local pur

	// Déconnexion
	logout,

	// Utilitaires exposés pour les consommateurs
	offlineUuid,
};

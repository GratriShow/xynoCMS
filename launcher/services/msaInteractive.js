/* eslint-disable no-console */
'use strict';

// Interactive Microsoft login using an embedded Electron BrowserWindow.
// This module is main-process only (it requires electron).
//
// Flow (no device code):
//   1. Open a BrowserWindow on Microsoft's OAuth authorize URL.
//   2. User signs in with email/password as in the official launcher.
//   3. Microsoft redirects to oauth20_desktop.srf?code=… — we capture the code,
//      close the window, and exchange it for an MSA access_token + refresh_token.
//   4. MSA token → Xbox Live token → XSTS token → Minecraft Services token.
//   5. Fetch the Java profile (uuid + username) and return everything.
//
// Implementation notes:
//   - Client ID is MinecraftNintendoSwitch (00000000441cc96b). It's the same
//     client ID prismarine-auth uses for its `live` flow and the de-facto
//     standard for unofficial Java launchers (Prism, ATLauncher, …). Microsoft
//     has not restricted it for third-party use.
//   - We use a non-persistent BrowserWindow partition so previous logins don't
//     leak between launches and the user is always asked to re-authenticate
//     (which is the explicit intent — clicking "Connexion Microsoft" should be
//     a fresh login, not a silent SSO).
//   - All HTTP calls use the global fetch (Node 18+, Electron ships it).

const { BrowserWindow, session } = require('electron');

const CLIENT_ID = '00000000441cc96b';
const SCOPE = 'service::user.auth.xboxlive.com::MBI_SSL';
const REDIRECT_URI = 'https://login.live.com/oauth20_desktop.srf';

const URLS = {
	authorize: 'https://login.live.com/oauth20_authorize.srf',
	token: 'https://login.live.com/oauth20_token.srf',
	xbl: 'https://user.auth.xboxlive.com/user/authenticate',
	xsts: 'https://xsts.auth.xboxlive.com/xsts/authorize',
	mcLogin: 'https://api.minecraftservices.com/authentication/login_with_xbox',
	mcProfile: 'https://api.minecraftservices.com/minecraft/profile',
};

function buildAuthorizeUrl() {
	const params = new URLSearchParams({
		client_id: CLIENT_ID,
		response_type: 'code',
		redirect_uri: REDIRECT_URI,
		scope: SCOPE,
		// `select_account` always shows the account picker so a user signed in
		// to another Microsoft account in their default browser doesn't get
		// silently picked up by ours.
		prompt: 'select_account',
	});
	return `${URLS.authorize}?${params.toString()}`;
}

// Open a fresh BrowserWindow on the Microsoft login page and resolve with the
// authorization code as soon as Microsoft redirects to oauth20_desktop.srf.
function openOAuthWindow({ debugLog, parentWindow } = {}) {
	return new Promise((resolve, reject) => {
		// Non-persistent partition: no `persist:` prefix means the cookies and
		// storage live only in memory and disappear when the window closes.
		const partitionName = `msa-login-${Date.now()}`;
		const oauthSession = session.fromPartition(partitionName);

		// Belt-and-suspenders: also clear any leftover state from this partition
		// before opening (in case a previous attempt didn't clean up properly).
		oauthSession.clearStorageData().catch(() => { /* ignore */ });

		const win = new BrowserWindow({
			width: 520,
			height: 720,
			parent: parentWindow || undefined,
			modal: false,
			autoHideMenuBar: true,
			resizable: false,
			minimizable: false,
			maximizable: false,
			fullscreenable: false,
			title: 'Connexion Microsoft',
			backgroundColor: '#101418',
			webPreferences: {
				partition: partitionName,
				nodeIntegration: false,
				contextIsolation: true,
				sandbox: true,
				devTools: false,
				webviewTag: false,
			},
		});

		// Strip the menu (mainly relevant on Linux/Windows; harmless on macOS).
		win.setMenuBarVisibility(false);
		win.removeMenu();

		let settled = false;

		const cleanup = () => {
			try { win.removeAllListeners('closed'); } catch { /* ignore */ }
		};

		const settle = (fn) => {
			if (settled) return;
			settled = true;
			cleanup();
			try { fn(); } catch { /* ignore */ }
			try { if (!win.isDestroyed()) win.close(); } catch { /* ignore */ }
		};

		const checkUrl = (urlStr) => {
			if (settled) return;
			let parsed;
			try { parsed = new URL(urlStr); } catch { return; }
			// Compare scheme+host+path against REDIRECT_URI (ignore search/hash).
			const target = parsed.origin + parsed.pathname;
			if (target !== REDIRECT_URI) return;

			const error = parsed.searchParams.get('error');
			if (error) {
				const desc = parsed.searchParams.get('error_description') || '';
				if (typeof debugLog === 'function') debugLog(`[msa-interactive] ❌ OAuth error from Microsoft: ${error} — ${desc}`);
				settle(() => reject(new Error(`Microsoft OAuth error: ${error}${desc ? ` (${desc})` : ''}`)));
				return;
			}

			const code = parsed.searchParams.get('code');
			if (code) {
				if (typeof debugLog === 'function') debugLog('[msa-interactive] ✅ Authorization code received from Microsoft');
				settle(() => resolve(code));
			}
		};

		win.webContents.on('will-redirect', (_evt, url) => checkUrl(url));
		win.webContents.on('did-navigate', (_evt, url) => checkUrl(url));
		win.webContents.on('did-redirect-navigation', (_evt, url) => checkUrl(url));

		win.on('closed', () => {
			if (!settled) {
				settled = true;
				reject(new Error('Login window was closed before authentication completed'));
			}
		});

		const target = buildAuthorizeUrl();
		if (typeof debugLog === 'function') debugLog(`[msa-interactive] 🌐 Opening OAuth window: ${target}`);
		win.loadURL(target);
	});
}

// Generic JSON POST helper. Throws on non-2xx with a useful message.
async function postJson(url, body, headers = {}) {
	const init = {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			...headers,
		},
	};
	if (body instanceof URLSearchParams) {
		init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
		init.body = body.toString();
	} else if (typeof body === 'object' && body !== null) {
		init.headers['Content-Type'] = 'application/json';
		init.body = JSON.stringify(body);
	}

	const res = await fetch(url, init);
	const text = await res.text();
	let json;
	try { json = text ? JSON.parse(text) : {}; } catch { json = { _raw: text }; }
	if (!res.ok) {
		const detail = json && (json.error_description || json.error || json.Message || json.XErr) ? JSON.stringify(json) : text;
		throw new Error(`POST ${url} → HTTP ${res.status}: ${detail || '(no body)'}`);
	}
	return json;
}

async function getJson(url, headers = {}) {
	const res = await fetch(url, {
		method: 'GET',
		headers: { Accept: 'application/json', ...headers },
	});
	const text = await res.text();
	let json;
	try { json = text ? JSON.parse(text) : {}; } catch { json = { _raw: text }; }
	if (!res.ok) {
		throw new Error(`GET ${url} → HTTP ${res.status}: ${text || '(no body)'}`);
	}
	return json;
}

// Step 2: exchange the authorization code for an MSA access_token + refresh_token.
async function exchangeCodeForMsaTokens(code) {
	const body = new URLSearchParams({
		client_id: CLIENT_ID,
		code,
		grant_type: 'authorization_code',
		redirect_uri: REDIRECT_URI,
		scope: SCOPE,
	});
	const json = await postJson(URLS.token, body);
	if (!json.access_token) throw new Error('MSA token exchange returned no access_token');
	return {
		access_token: String(json.access_token),
		refresh_token: json.refresh_token ? String(json.refresh_token) : '',
		expires_in: typeof json.expires_in === 'number' ? json.expires_in : 0,
	};
}

// Step 3: trade the MSA access_token for an Xbox Live (XBL) token.
async function xblAuth(msaAccessToken) {
	const json = await postJson(URLS.xbl, {
		Properties: {
			AuthMethod: 'RPS',
			SiteName: 'user.auth.xboxlive.com',
			RpsTicket: `t=${msaAccessToken}`,
		},
		RelyingParty: 'http://auth.xboxlive.com',
		TokenType: 'JWT',
	}, { 'x-xbl-contract-version': '1' });
	const token = json && json.Token ? String(json.Token) : '';
	const uhs = json && json.DisplayClaims && Array.isArray(json.DisplayClaims.xui) && json.DisplayClaims.xui[0]
		? String(json.DisplayClaims.xui[0].uhs || '') : '';
	if (!token || !uhs) throw new Error('Xbox Live auth returned an incomplete response');
	return { token, uhs };
}

// Step 4: trade the XBL token for an XSTS token (scoped to Minecraft services).
async function xstsAuth(xblToken) {
	let json;
	try {
		json = await postJson(URLS.xsts, {
			Properties: {
				SandboxId: 'RETAIL',
				UserTokens: [xblToken],
			},
			RelyingParty: 'rp://api.minecraftservices.com/',
			TokenType: 'JWT',
		});
	} catch (err) {
		// XSTS uses a documented set of XErr codes for actionable failures.
		const m = String(err && err.message || '').match(/"XErr"\s*:\s*(\d+)/);
		if (m) {
			const xerr = m[1];
			const friendly = {
				'2148916233': "Ce compte Microsoft n'a pas de profil Xbox. Crée-en un sur https://www.xbox.com/ avant de continuer.",
				'2148916235': "Xbox Live n'est pas disponible dans le pays/région du compte.",
				'2148916236': "Vérification d'âge requise sur ce compte (ajoute une carte ou demande à un parent).",
				'2148916237': "Vérification d'âge requise sur ce compte (ajoute une carte ou demande à un parent).",
				'2148916238': "Compte enfant : doit être ajouté à une famille Microsoft pour utiliser Xbox Live.",
			}[xerr];
			throw new Error(friendly || `XSTS error XErr=${xerr}`);
		}
		throw err;
	}
	const token = json && json.Token ? String(json.Token) : '';
	const uhs = json && json.DisplayClaims && Array.isArray(json.DisplayClaims.xui) && json.DisplayClaims.xui[0]
		? String(json.DisplayClaims.xui[0].uhs || '') : '';
	if (!token || !uhs) throw new Error('XSTS auth returned an incomplete response');
	return { token, uhs };
}

// Step 5: log in to Minecraft Services with the XSTS token.
async function mcLogin(xstsToken, uhs) {
	const json = await postJson(URLS.mcLogin, {
		identityToken: `XBL3.0 x=${uhs};${xstsToken}`,
	});
	const token = json && json.access_token ? String(json.access_token) : '';
	if (!token) throw new Error('Minecraft Services login returned no access_token');
	return token;
}

// Step 6: fetch the Java profile (uuid + username + skins).
async function fetchMcProfile(mcToken) {
	const json = await getJson(URLS.mcProfile, { Authorization: `Bearer ${mcToken}` });
	if (!json || !json.id || !json.name) {
		// Common case: the Microsoft account doesn't own Minecraft Java Edition.
		// The endpoint returns 404 in that case and postJson would already have
		// thrown — but if it returns 200 with an empty body we still want to
		// give the user an actionable message instead of "incomplete profile".
		throw new Error("Ce compte Microsoft ne possède pas Minecraft: Java Edition. Vérifie que tu as bien acheté le jeu et que tu utilises le bon compte.");
	}
	return { id: String(json.id), name: String(json.name) };
}

// Public entry point: run the full interactive login chain.
// Returns: { type, username, uuid, access_token, refresh_token }
async function loginInteractive({ debugLog, parentWindow } = {}) {
	if (typeof debugLog === 'function') debugLog('[msa-interactive] 🚀 Starting interactive Microsoft login');

	const code = await openOAuthWindow({ debugLog, parentWindow });
	if (typeof debugLog === 'function') debugLog('[msa-interactive] 🔄 Exchanging code for MSA tokens');
	const msa = await exchangeCodeForMsaTokens(code);

	if (typeof debugLog === 'function') debugLog('[msa-interactive] 🔄 Authenticating with Xbox Live');
	const xbl = await xblAuth(msa.access_token);

	if (typeof debugLog === 'function') debugLog('[msa-interactive] 🔄 Authorizing with XSTS');
	const xsts = await xstsAuth(xbl.token);

	if (typeof debugLog === 'function') debugLog('[msa-interactive] 🔄 Logging in to Minecraft Services');
	const mcToken = await mcLogin(xsts.token, xsts.uhs);

	if (typeof debugLog === 'function') debugLog('[msa-interactive] 🔄 Fetching Minecraft profile');
	const profile = await fetchMcProfile(mcToken);

	if (typeof debugLog === 'function') debugLog(`[msa-interactive] ✅ Logged in as ${profile.name} (${profile.id})`);

	return {
		type: 'microsoft',
		username: profile.name,
		uuid: profile.id,
		access_token: mcToken,
		refresh_token: msa.refresh_token,
	};
}

module.exports = {
	loginInteractive,
};

/* eslint-disable no-console */
'use strict';

// Standalone diagnostic for the Microsoft auth flow.
// Run with: cd launcher && node tools/test-auth.js
//
// This bypasses Electron, AppTranslocation and the launcher code, so it tells
// us whether prismarine-auth itself works on this machine.

const path = require('node:path');
const fsp = require('node:fs/promises');
const os = require('node:os');

(async () => {
	const cacheDir = path.join(os.tmpdir(), 'prismarine-auth-test-' + Date.now());
	await fsp.mkdir(cacheDir, { recursive: true });
	console.log(`[test] Cache dir: ${cacheDir}`);

	let Authflow, Titles;
	try {
		({ Authflow, Titles } = require('prismarine-auth'));
	} catch (err) {
		console.error('[test] ❌ Could not require("prismarine-auth"):', err.message);
		console.error('[test] Run: cd launcher && npm install');
		process.exit(2);
	}

	console.log('[test] prismarine-auth loaded');
	console.log(`[test] Node ${process.version}, platform=${process.platform}, arch=${process.arch}`);

	const onMsaCode = (data) => {
		console.log('\n=========================================');
		console.log('[test] 📱 DEVICE CODE RECEIVED:');
		console.log(`[test]    user_code:        ${data && data.user_code}`);
		console.log(`[test]    verification_uri: ${data && data.verification_uri}`);
		console.log(`[test]    expires_in:       ${data && data.expires_in}`);
		console.log('=========================================\n');
		console.log('[test] If you see this, prismarine-auth works in plain Node.');
		console.log('[test] You can now Ctrl+C — we just wanted to confirm the device code.');
	};

	// prismarine-auth 3.x: device-code callback is the 4TH argument, not in options.
	const flow = new Authflow('test-user', cacheDir, {
		flow: 'live',
		authTitle: Titles.MinecraftNintendoSwitch,
		deviceType: 'Nintendo',
	}, onMsaCode);

	console.log('[test] 🔵 Starting getMinecraftJavaToken (live + MinecraftNintendoSwitch)...');
	console.log('[test] If no device code appears within 30 seconds, prismarine-auth itself is the problem.');

	const start = Date.now();
	const timeout = setTimeout(() => {
		console.error(`\n[test] ❌ HANG: ${((Date.now() - start) / 1000).toFixed(1)}s elapsed and no device code.`);
		console.error('[test] prismarine-auth is stuck. The launcher would be stuck for the same reason.');
		console.error('[test] Hint: try a different network or bypass corporate proxy / VPN if any.');
		process.exit(1);
	}, 35000);

	try {
		const result = await flow.getMinecraftJavaToken({ fetchProfile: true });
		clearTimeout(timeout);
		const elapsed = ((Date.now() - start) / 1000).toFixed(1);
		console.log(`\n[test] ✅ SUCCESS in ${elapsed}s`);
		console.log(`[test] username: ${result.profile && result.profile.name}`);
		console.log(`[test] uuid:     ${result.profile && result.profile.id}`);
		console.log(`[test] token:    ${result.token ? result.token.slice(0, 20) + '...' : '(none)'}`);
		console.log('\n[test] prismarine-auth works in plain Node. The bug is Electron-specific.');
		process.exit(0);
	} catch (err) {
		clearTimeout(timeout);
		console.error('\n[test] ❌ FAILED:', err && err.message ? err.message : err);
		if (err && err.stack) console.error(err.stack);
		process.exit(1);
	}
})();

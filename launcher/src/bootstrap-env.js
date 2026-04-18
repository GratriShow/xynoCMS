'use strict';

/**
 * bootstrap-env.js
 *
 * Charge ./config.json (injecté au build par GitHub Actions ou build-multi-launchers.js)
 * et expose chaque valeur sous forme de variable d'environnement — ainsi le reste
 * de main.js/services/*.js peut continuer à lire process.env.LAUNCHER_UUID etc.
 *
 * Ce fichier DOIT être require()'d EN PREMIER dans main.js, avant tout autre import.
 *
 * En développement (sans config.json), on tombe en fallback sur les variables
 * d'env existantes + un .env local (non versionné) — pratique pour les tests.
 */

const fs = require('node:fs');
const path = require('node:path');

function tryLoadJson(filePath) {
  try {
    if (!fs.existsSync(filePath)) return null;
    const raw = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(raw);
  } catch (err) {
    console.error('[bootstrap-env] failed to parse', filePath, err && err.message);
    return null;
  }
}

function setEnv(name, value) {
  if (value === undefined || value === null) return;
  // Ne jamais écraser une env var déjà fournie (ex. CI ou test local).
  if (process.env[name] !== undefined && process.env[name] !== '') return;
  process.env[name] = String(value);
}

(function bootstrap() {
  // config.json est bundlé à côté de ce fichier dans src/
  const here = __dirname;
  const config =
    tryLoadJson(path.join(here, 'config.json')) ||
    tryLoadJson(path.join(here, '..', 'config.json')) ||
    {};

  // --- Mapping config -> env (clé courte côté config, env var côté launcher) ---
  setEnv('LAUNCHER_UUID', config.uuid);
  setEnv('LAUNCHER_KEY', config.api_key);
  setEnv('API_BASE_URL', config.api_base_url);
  setEnv('RENEW_URL', config.renew_url);
  setEnv('LICENSE_RECHECK_MINUTES', config.license_recheck_minutes);
  setEnv('LAUNCHER_EXPECTED_ASAR_SHA256', config.expected_asar_sha256);

  // --- Info "publique" pour le renderer via un snapshot JSON read-only ---
  // Les modules activés et métadonnées UI sont exposés ici (pas de secret).
  const publicSnapshot = {
    uuid: config.uuid || '',
    name: config.name || 'XynoLauncher',
    version: config.version || '0.0.0',
    theme: config.theme || 'default',
    modules: Array.isArray(config.modules) ? config.modules : [],
    branding: config.branding || {},
  };
  // On l'expose via une env var JSON, consommable par le renderer aussi.
  setEnv('LAUNCHER_PUBLIC_CONFIG', JSON.stringify(publicSnapshot));

  if (!process.env.LAUNCHER_UUID) {
    console.warn('[bootstrap-env] no config.json found and no LAUNCHER_UUID in env — launcher will fail to start.');
  }
})();

module.exports = {};

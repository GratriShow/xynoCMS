# 📖 Comment Ajouter un Nouveau CMS au Launcher

## Exemple: Intégrer un CMS Personnalisé

Imaginons que vous voulez utiliser le launcher avec votre CMS personnalisé qui utilise une REST API simple (pas de HMAC, juste Bearer tokens).

### Étape 1: Créer la structure

```bash
mkdir -p integrations/my-custom-cms
cd integrations/my-custom-cms
touch MyCustomLauncherAPI.js index.js package.json
```

### Étape 2: Implémenter AbstractLauncherAPI

```javascript
// integrations/my-custom-cms/MyCustomLauncherAPI.js

'use strict';

const AbstractLauncherAPI = require('../../launcher-core/services/AbstractLauncherAPI');
const https = require('node:https');

class MyCustomLauncherAPI extends AbstractLauncherAPI {
  constructor(config) {
    super();
    this.apiBaseUrl = config.apiBaseUrl;
    this.apiToken = config.apiToken;  // Bearer token, pas HMAC
    this.timeoutMs = config.timeoutMs || 10000;

    if (!this.apiBaseUrl || !this.apiToken) {
      throw new Error('MyCustomLauncherAPI requires apiBaseUrl and apiToken');
    }

    if (!this.apiBaseUrl.startsWith('https://')) {
      throw new Error('apiBaseUrl must use HTTPS');
    }
  }

  /**
   * Simple HTTP request helper
   */
  async _request(path, method = 'GET', body = null) {
    const url = new URL(path, this.apiBaseUrl).toString();
    const headers = {
      'Authorization': `Bearer ${this.apiToken}`,
      'Accept': 'application/json',
    };

    if (body) {
      headers['Content-Type'] = 'application/json';
    }

    return new Promise((resolve, reject) => {
      const req = https.request(url, { method, headers, timeout: this.timeoutMs }, (res) => {
        const chunks = [];
        res.on('data', (d) => chunks.push(d));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(text);
          } catch {}
          resolve({ statusCode: res.statusCode || 0, json, text });
        });
      });

      req.on('timeout', () => {
        const err = new Error('Request timeout');
        err.code = 'ETIMEDOUT';
        req.destroy(err);
      });

      req.on('error', reject);
      if (body) req.write(body);
      req.end();
    });
  }

  async getStatus() {
    const { json, statusCode } = await this._request('/api/launcher/status');
    if (statusCode !== 200 || !json) {
      throw new Error(`Status check failed: ${statusCode}`);
    }
    return {
      status: json.license_active ? 'active' : 'inactive',
      name: json.launcher_name || 'MyLauncher',
      news: json.news_items || [],
      config: json.launcher_config || {},
      branding: json.branding || null,
      extensions: json.available_extensions || [],
      auth: json.auth_config || null,
      marketplace: null,
    };
  }

  async getExtension(extensionKey) {
    if (!/^[a-z0-9_]{1,64}$/.test(extensionKey)) {
      throw new Error('Invalid extension key format');
    }
    const { json, statusCode } = await this._request(`/api/extensions/${extensionKey}`);
    if (statusCode !== 200 || !json) {
      throw new Error(`Extension not found: ${extensionKey}`);
    }
    return {
      id: json.id,
      name: json.name,
      version: json.version,
      url: json.download_url,
      sha1: json.hash,
      description: json.description,
    };
  }

  async loginCustom(credentials) {
    const body = JSON.stringify({
      username: credentials.email,
      password: credentials.password,
    });
    const { json, statusCode } = await this._request('/api/auth/login', 'POST', body);
    if (statusCode !== 200 || !json || !json.token) {
      throw new Error('Login failed');
    }
    return { token: json.token, expiresIn: json.expires_in || 7200 };
  }

  async verifyCustomToken(token) {
    const body = JSON.stringify({ token });
    const { json, statusCode } = await this._request('/api/auth/verify', 'POST', body);
    if (statusCode !== 200) {
      return { valid: false, user: null };
    }
    return { valid: true, user: json.user || null };
  }

  async downloadFile(url, filePath, onProgress) {
    try {
      const urlObj = new URL(url);
      if (urlObj.protocol !== 'https:') {
        throw new Error('Download URLs must use HTTPS');
      }
    } catch {
      throw new Error('Invalid download URL');
    }
    return '';
  }

  async checkForUpdate() {
    const { json, statusCode } = await this._request('/api/launcher/update');
    if (statusCode !== 200 || !json) {
      return null;
    }
    return json.has_update ? {
      version: json.version,
      url: json.download_url,
      sha1: json.hash,
      changelog: json.changelog,
    } : null;
  }

  async applyUpdate(zipPath) {
    try {
      await this._request('/api/launcher/update-applied', 'POST', '{}');
    } catch {}
  }

  async sendHeartbeat() {
    try {
      const body = JSON.stringify({
        launcher_version: process.env.LAUNCHER_VERSION || '1.0.0',
        online_users: 1,
      });
      await this._request('/api/launcher/heartbeat', 'POST', body);
    } catch {}
  }

  async getLocalizedStrings(locale) {
    const { json, statusCode } = await this._request(`/api/i18n/${locale}`);
    return (statusCode === 200 && json) ? json : {};
  }
}

module.exports = MyCustomLauncherAPI;
```

### Étape 3: Créer le package.json

```json
{
  "name": "my-custom-launcher",
  "version": "1.0.0",
  "description": "Integration with MyCustomCMS",
  "main": "index.js",
  "private": true,
  "dependencies": {
    "launcher-core": "file:../../launcher-core"
  }
}
```

### Étape 4: Configurer le launcher

**config.json**:

```json
{
  "api_base_url": "https://mycompany.example.com",
  "api_token": "sk_live_...",
  "name": "MyGameLauncher",
  "version": "1.0.0"
}
```

### Étape 5: Initialiser dans main.js

```javascript
// main.js
require('./src/bootstrap-env');

const MyCustomLauncherAPI = require('../launcher-hub/integrations/my-custom-cms/MyCustomLauncherAPI');

let launcherAPI;

async function initializeLauncher() {
  launcherAPI = new MyCustomLauncherAPI({
    apiBaseUrl: process.env.API_BASE_URL,
    apiToken: process.env.API_TOKEN,
  });

  try {
    const status = await launcherAPI.getStatus();
    console.log('Launcher initialized:', status.name);
  } catch (err) {
    console.error('Failed to initialize:', err.message);
  }
}

app.whenReady().then(async () => {
  await initializeLauncher();
  createWindow();
});

// Tout le reste est identique!
ipcMain.handle('extension:fetch', async (_event, key) => {
  const ext = await launcherAPI.getExtension(key);
  return ext;
});
```

## APIs Requises du Backend

| Endpoint | Method | Response |
|----------|--------|----------|
| `/api/launcher/status` | GET | `{ license_active, launcher_name, news_items, ... }` |
| `/api/extensions/{key}` | GET | `{ id, name, version, download_url, hash }` |
| `/api/auth/login` | POST | `{ token, expires_in }` |
| `/api/auth/verify` | POST | `{ user }` |
| `/api/launcher/update` | GET | `{ has_update, version, download_url, hash }` |
| `/api/launcher/update-applied` | POST | `{}` |
| `/api/launcher/heartbeat` | POST | `{}` |
| `/api/i18n/{locale}` | GET | `{ key: "value" }` |

## Points Importants

✅ Aucune autre modification nécessaire  
✅ Sécurité: HTTPS requis, secrets côté main process  
✅ Adaptation: votre CMS retourne ses propres champs, MyCustomLauncherAPI les adapte

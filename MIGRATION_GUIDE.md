# 📚 Guide de Migration: De main.js monolithique à Architecture Modulaire

## Vue d'ensemble

Actuellement, `xynoCMS-git/launcher/main.js` fait ~1250 lignes et mélange:
- Logique launcher générique (téléchargement, minecraft, auth Microsoft)
- Détails spécifiques à Xyno (HMAC, extensions, heartbeat)
- IPC handlers
- Gestion de licence

**Objectif**: Séparer ça proprement en utilisant `XynoLauncherAPI`.

## Étapes de Migration

### Phase 1: Utiliser XynoLauncherAPI dans main.js

**Avant** (code Xyno mélangé):
```javascript
// main.js (1250 lignes)
const { createClient: createApiV2Client } = require('./services/apiV2');
const apiClient = createApiV2Client(apiKey, apiBaseUrl);
const status = await apiClient.getStatus();
```

**Après** (utilise l'abstraction):
```javascript
// main.js (~500 lignes)
const XynoLauncherAPI = require('../launcher-hub/integrations/xyno/XynoLauncherAPI');
const launcherAPI = new XynoLauncherAPI({
  apiBaseUrl: process.env.API_BASE_URL,
  launcherUuid: process.env.LAUNCHER_UUID,
  launcherKey: process.env.LAUNCHER_KEY,
});

const status = await launcherAPI.getStatus();
```

### Phase 2: Extraire Services Génériques → launcher-core

**Déplacer vers `launcher-core/services/`:**

```javascript
// launcher-core/services/MinecraftLauncher.js
class MinecraftLauncher {
  async launch(options) {
    // Code générique du lancement Minecraft
    // AUCUNE dépendance à Xyno
  }
}

// launcher-core/services/FileDownloader.js
class FileDownloader {
  async download(url, filePath, onProgress, expectedSha1) {
    // Téléchargement générique
    // Utilisé par launcher-core ET par XynoLauncherAPI
  }
}

// launcher-core/services/AuthenticationManager.js
class AuthenticationManager {
  async getMicrosoftSession(paths) { }
  async getCustomSession(token) { }
}

// launcher-core/services/StateManager.js
class StateManager {
  async loadState(paths) { }
  async saveState(paths, state) { }
}
```

### Phase 3: Réduire main.js

**main.js après refactoring (~300-400 lignes):**

```javascript
// main.js
require('./src/bootstrap-env');
const { app, BrowserWindow, ipcMain } = require('electron');

// Import services génériques
const MinecraftLauncher = require('../launcher-hub/launcher-core/services/MinecraftLauncher');
const FileDownloader = require('../launcher-hub/launcher-core/services/FileDownloader');

// Import intégration Xyno
const XynoLauncherAPI = require('../launcher-hub/integrations/xyno/XynoLauncherAPI');

// Créer l'API singleton
let launcherAPI;

async function initializeLauncher() {
  launcherAPI = new XynoLauncherAPI({
    apiBaseUrl: process.env.API_BASE_URL,
    launcherUuid: process.env.LAUNCHER_UUID,
    launcherKey: process.env.LAUNCHER_KEY,
  });

  try {
    const status = await launcherAPI.getStatus();
    // Handle status...
  } catch (err) {
    console.error('Failed to initialize launcher:', err);
  }
}

app.whenReady().then(createWindow);

// IPC handlers - maintenant très simples
ipcMain.handle('extension:fetch', async (_event, key) => {
  const ext = await launcherAPI.getExtension(key);
  return ext;
});

ipcMain.handle('minecraft:play', async () => {
  const launcher = new MinecraftLauncher();
  await launcher.launch(/* options */);
});

// ... etc
```

## Fichiers Affectés

### À REFACTORISER

| Fichier | Action | Raison |
|---------|--------|--------|
| `main.js` | Réduire à 300-400 lignes | Trop monolithique |
| `services/apiV2.js` | SUPPRIMER | Remplacé par XynoLauncherAPI |
| `services/autoUpdate.js` | Extraire la logique générique | La plupart est Xyno-spécifique |

### À EXTRAIRE VERS launcher-core

| Fichier Courant | Destination | Statut |
|-----------------|------------|--------|
| `services/minecraft.js` | `launcher-core/services/MinecraftLauncher.js` | 95% générique |
| `services/downloader.js` | `launcher-core/services/FileDownloader.js` | 100% générique |
| `services/authService.js` | `launcher-core/services/AuthenticationManager.js` | 100% générique |
| `utils/hash.js` | `launcher-core/utils/hash.js` | 100% générique |
| `utils/paths.js` | `launcher-core/utils/paths.js` | Légèrement adapté |

### À GARDER DANS integrations/xyno

| Fichier | Raison |
|---------|--------|
| `XynoLauncherAPI.js` | Implémentation spécifique à Xyno |
| `services/manifest.js` | Format spécifique à Xyno |
| `preload.js`, `renderer.js` | UI/IPC (pas de logique métier) |

## Exemple Détaillé: Téléchargement d'Extension

### AVANT (monolithique)

```javascript
// main.js, ligne 862
ipcMain.handle('launcher:fetchExtension', async (_event, rawKey) => {
  // Validation inline
  if (!/^[a-z0-9_]{1,64}$/.test(rawKey)) {
    throw new Error('Invalid extension key');
  }

  // Appel API Xyno inline
  const { json } = await proxyRequest(
    buildProxyUrl(apiBaseUrl, `/api/launcher_ext.php?ext=${rawKey}`),
    { method: 'GET' }
  );

  // Téléchargement inline
  const url = json.url;
  const filePath = path.join(extensionsDir, rawKey);
  
  // HMAC signature inline
  const signature = crypto.createHmac(...).update(...).digest();
  
  // Download
  const res = await https.request(url, { headers: { auth: signature } });
  // ... stream to file
});
```

**1250 lignes de code, logique mélangée.**

### APRÈS (modulaire)

```javascript
// main.js, ligne ~150
ipcMain.handle('launcher:fetchExtension', async (_event, rawKey) => {
  try {
    // Tout géré par XynoLauncherAPI
    const ext = await launcherAPI.getExtension(rawKey);
    
    // Téléchargement générique
    const downloader = new FileDownloader();
    const localPath = await downloader.download(
      ext.url,
      path.join(extensionsDir, rawKey),
      (progress) => console.log(`${progress}%`)
    );
    
    return { ok: true, path: localPath };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});
```

**~20 lignes, une seule responsabilité par classe.**

## Structure Finale

```
xynoCMS-git/
└── launcher/
    ├── main.js                    ← 300-400 lignes
    ├── preload.js
    ├── renderer.js
    ├── package.json
    └── src/
        └── bootstrap-env.js       ← Charge config Xyno

launcher-hub/
├── launcher-core/                ← Réutilisable par n'importe quel CMS
│   ├── services/
│   │   ├── MinecraftLauncher.js
│   │   ├── FileDownloader.js
│   │   ├── AuthenticationManager.js
│   │   ├── StateManager.js
│   │   └── AbstractLauncherAPI.js
│   ├── utils/
│   ├── index.js
│   └── package.json
│
└── integrations/
    └── xyno/                      ← Spécifique à Xyno
        ├── XynoLauncherAPI.js
        ├── services/
        │   └── manifest.js
        ├── preload.js             ← lié à UI
        └── package.json
```

## Avantages Immédiats

✅ **main.js passe de 1250 à 400 lignes** → facile à maintenir  
✅ **Aucune duplication** → httpJson consolidé  
✅ **Services testables** → mocker l'interface abstraite  
✅ **Prêt pour d'autres CMS** → copiez XynoLauncherAPI, changez les endpoints  
✅ **Secrets isolés** → LAUNCHER_KEY jamais exposé  

## Timeline d'Implémentation

| Semaine | Tâche |
|---------|-------|
| 1 | ✅ Créer AbstractLauncherAPI + XynoLauncherAPI |
| 2 | Utiliser XynoLauncherAPI dans main.js |
| 3 | Extraire services génériques → launcher-core |
| 4 | Refactoriser main.js (~1250 → 400 lignes) |
| 5 | Tests unitaires + documentation |

## Vérification de Compatibilité

Après refactoring, vérifier:

```bash
# 1. Lance sans erreur
npm start

# 2. Lint passe
npm run lint

# 3. Fonctionnalités still work
# - Launch Minecraft
# - Download extensions
# - Microsoft auth
# - Custom auth (si utilisé)
# - License check
```

## Questions Courantes

**Q: Et les services existants (antiCheat, discord, etc.)?**  
A: Les garder dans integrations/xyno car ils sont Xyno-spécifiques. Si vous les avez dans d'autres CMS, créez une interface abstraite pour chacun.

**Q: Dois-je créer des package.json séparés?**  
A: Oui! launcher-core est un "package" réutilisable. Les integrations dépendent de launcher-core.

**Q: Comment tester?**  
A: Mocker XynoLauncherAPI avec une classe test:
```javascript
class TestLauncherAPI extends AbstractLauncherAPI {
  async getStatus() { return { status: 'active' }; }
  // ... mock methods
}
```

## Next Steps

1. Implémenter les services dans launcher-core
2. Adapter main.js pour utiliser launcherAPI
3. Tester avec le même launcher Xyno
4. Documenter pour futurs CMS

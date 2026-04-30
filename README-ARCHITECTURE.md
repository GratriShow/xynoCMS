# 🚀 Launcher-Hub Architecture

## Objectif

Créer un **launcher Electron modulaire** où:
- ✅ Le code launcher est **100% CMS-agnostique**
- ✅ Les launchers ne connaissent que **vos APIs REST**
- ✅ Ajouter un nouveau CMS = implémenter une interface

```
┌─────────────────────────────────────────────┐
│   Launcher (Electron App)                   │
│   - Minecraft launch                        │
│   - File sync                               │
│   - Auth flow                               │
└──────────────┬──────────────────────────────┘
               │
               ├─→ Dépend de: ILauncherAPI (abstract)
               │
┌──────────────▼──────────────────────────────┐
│   API Implementation Layer                  │
│   (XynoLauncherAPI, CustomLauncherAPI, ...)│
└──────────────┬──────────────────────────────┘
               │
               ├─→ Implémente: ILauncherAPI
               ├─→ HTTP calls à votre backend
               ├─→ Authentification
               ├─→ Logique métier spécifique
               │
┌──────────────▼──────────────────────────────┐
│   Votre Backend CMS                         │
│   (Xyno, WordPress, custom, ...)            │
└─────────────────────────────────────────────┘
```

## Structure des Dossiers

```
launcher-hub/
│
├── launcher-core/                           ← Code générique réutilisable
│   ├── services/
│   │   ├── AbstractLauncherAPI.js           ← Interface abstraite (PIVOT)
│   │   ├── MinecraftLauncher.js
│   │   ├── FileDownloader.js
│   │   ├── SessionManager.js
│   │   └── StateManager.js
│   ├── utils/
│   │   └── hash.js
│   ├── index.js                            ← API publique
│   └── package.json
│
├── integrations/
│   │
│   ├── xyno/                                ← Implémentation Xyno
│   │   ├── XynoLauncherAPI.js               ← Implémente AbstractLauncherAPI
│   │   ├── index.js
│   │   ├── config.json
│   │   └── package.json
│   │
│   └── exemple-autre-cms/                   ← Futur: autre CMS
│       ├── CustomLauncherAPI.js
│       └── ...
│
├── examples/
│   └── how-to-add-new-cms.md
│
└── README-ARCHITECTURE.md                   ← Ce fichier
```

## Diagramme de Flux

### Au démarrage

```
app.whenReady()
  └─→ Charger config (bootstrap-env.js)
      └─→ Créer instance XynoLauncherAPI(config)
          │   (Singleton)
          └─→ Appeler launcherAPI.getStatus()
              └─→ HTTP: POST /api/v2/status.php
                  └─→ Réponse: { status, news, extensions, ... }
```

### Lors du download d'une extension

```
user clicks → extension:fetch (IPC)
  └─→ main.js calls launcherAPI.getExtension('my_ext')
      └─→ XynoLauncherAPI.getExtension()
          └─→ HTTP: GET /api/launcher_ext.php?ext=my_ext
              └─→ Réponse: { url: "https://...", sha1: "...", ... }
                  └─→ launcher-core's FileDownloader
                      └─→ Télécharge et valide
```

## Interface Abstraite: AbstractLauncherAPI

Toute implémentation (XynoLauncherAPI, CustomLauncherAPI, etc) **DOIT** implémenter:

```javascript
class MyLauncherAPI extends AbstractLauncherAPI {
  async getStatus() { /* ... */ }
  async getExtension(key) { /* ... */ }
  async loginCustom(credentials) { /* ... */ }
  async downloadFile(url, path, onProgress) { /* ... */ }
  async checkForUpdate() { /* ... */ }
  async applyUpdate(zipPath) { /* ... */ }
  async sendHeartbeat() { /* ... */ }
  async getLocalizedStrings(locale) { /* ... */ }
}
```

## Ajouter un Nouveau CMS

### 1. Créer la structure

```bash
mkdir integrations/my-cms/{services,src}
```

### 2. Implémenter l'interface

```javascript
// integrations/my-cms/MyLauncherAPI.js
const AbstractLauncherAPI = require('../../launcher-core/services/AbstractLauncherAPI');

class MyLauncherAPI extends AbstractLauncherAPI {
  async getStatus() {
    // HTTP calls to YOUR backend
    // Return the same structure as AbstractLauncherAPI
  }
  // ... implement other methods
}
```

### 3. Config et routes API

Votre backend DOIT implémenter ces endpoints:

| Endpoint | Method | Rôle |
|----------|--------|------|
| `/api/v2/status.php` | POST | License check + public config |
| `/api/launcher_ext.php` | GET | Extension metadata |
| `/api/launcher_auth.php` | POST | Custom email/password auth |
| `/api/launcher_heartbeat.php` | POST | Périodic heartbeat |

(Voir `XynoLauncherAPI.js` pour les signatures exactes)

### 4. Initialiser le launcher

```javascript
// main.js
const MyLauncherAPI = require('./integrations/my-cms/MyLauncherAPI');

const launcherAPI = new MyLauncherAPI({
  apiBaseUrl: process.env.API_BASE_URL,
  // ... other config
});

// Dès maintenant, tout le code launcher utilise launcherAPI
// au lieu de connaître les détails Xyno
```

## Avantages de Cette Architecture

### Pour le Développeur

✅ **Code réutilisable**: launcher-core n'a aucune dépendance Xyno  
✅ **Facile à tester**: Mocker AbstractLauncherAPI dans les tests  
✅ **Documentation claire**: Interface définit le contrat exact  
✅ **Évolutif**: Ajouter un CMS = 1 fichier qui implémente l'interface  

### Pour le Déploiement

✅ **Un seul build Electron**: utilisable par n'importe quel CMS  
✅ **Configuration au runtime**: `config.json` injecté au build  
✅ **Zéro code hardcodé**: Tout vient de l'API  
✅ **Sécurité**: Secrets jamais exposés au renderer  

## Exemple: Configuration Xyno vs Autre CMS

### Xyno

```json
// config.json
{
  "api_base_url": "https://xyno.example.com",
  "uuid": "launcher-001",
  "api_key": "secret-key-for-hmac"
}
```

```javascript
// main.js
const XynoLauncherAPI = require('./integrations/xyno/XynoLauncherAPI');
const launcherAPI = new XynoLauncherAPI(config);
```

### Autre CMS (exemple: WordPress avec REST API custom)

```json
// config.json
{
  "api_base_url": "https://wordpress.example.com",
  "api_token": "bearer-token-from-wordpress"
}
```

```javascript
// main.js
const WordPressLauncherAPI = require('./integrations/wordpress/WordPressLauncherAPI');
const launcherAPI = new WordPressLauncherAPI(config);
```

**Le reste du code launcher est identique!**

## Refactoring en Cours

🚧 Migration du code existant:

1. ✅ Créer AbstractLauncherAPI
2. ✅ Créer XynoLauncherAPI (implémentation)
3. 🔄 Extraire launcher-core services (MinecraftLauncher, Downloader, etc.)
4. 🔄 Refactoriser main.js pour utiliser launcherAPI au lieu de services spécifiques
5. 🔄 Ajouter tests unitaires
6. 🔄 Documenter chaque service publique

## Sécurité

- 🔐 LAUNCHER_KEY jamais exposé au renderer
- 🔐 HTTPS forcé pour API_BASE_URL
- 🔐 auth.json avec permissions 0o600
- 🔐 Validation stricte des URLs et chemins
- 🔐 Tous les secrets restent côté main process

## Questions?

Pour ajouter un nouveau CMS, consultez `integrations/xyno/XynoLauncherAPI.js` comme exemple de référence.

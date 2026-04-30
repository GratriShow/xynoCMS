# 🚀 Quickstart: Launcher-Hub

## 5 Minutes pour Comprendre l'Architecture

### 1️⃣ L'Idée Principale

Votre launcher Electron **ne connaît JAMAIS** les détails de votre backend.

```javascript
// ❌ AVANT: hardcodé pour Xyno
const status = await apiClient.getStatus(); // Xyno-spécifique

// ✅ APRÈS: générique
const status = await launcherAPI.getStatus(); // Fonctionne pour n'importe quel backend
```

### 2️⃣ Interface Abstraite

Toute implémentation doit implémenter 8 méthodes:

```javascript
class MyLauncherAPI extends AbstractLauncherAPI {
  async getStatus() { }           // License check
  async getExtension(key) { }      // Extension metadata
  async loginCustom(creds) { }     // Email+password auth
  async verifyCustomToken(token) { } // Token validity
  async downloadFile(url, path) { } // Download with progress
  async checkForUpdate() { }       // Launcher update check
  async applyUpdate(zip) { }       // Apply update
  async sendHeartbeat() { }        // Periodic ping
}
```

### 3️⃣ Implémentation Xyno

Xyno implémente l'interface avec ses spécificités:

```javascript
class XynoLauncherAPI extends AbstractLauncherAPI {
  async getStatus() {
    // HMAC-SHA256 signing
    // Appel /api/v2/status.php
    // Retourne status standardisé
  }
  // ... 7 autres méthodes
}
```

### 4️⃣ main.js Utilise l'Abstraction

```javascript
const launcherAPI = new XynoLauncherAPI(config);
// OU
const launcherAPI = new MyCustomLauncherAPI(config);
// OU
const launcherAPI = new WordPressLauncherAPI(config);

// Même code launcher fonctionne avec n'importe lequel!
const status = await launcherAPI.getStatus();
```

### 5️⃣ Résultat

**Launcher générique + Multiple implementations = Support de N CMS**

```
launcher-core/          ← Générique (zéro code hardcodé)
integrations/
├── xyno/              ← Xyno (HMAC, extensions, etc)
├── my-cms/            ← Custom CMS (Bearer tokens)
└── wordpress/         ← WordPress (REST API)
```

---

## 📁 Structure Actuelle

```
launcher-hub/                          ← NOUVEAU (créé maintenant)
├── launcher-core/
│   ├── services/
│   │   └── AbstractLauncherAPI.js     ← Interface abstraite pivot
│   ├── index.js
│   └── package.json
├── integrations/
│   └── xyno/
│       ├── XynoLauncherAPI.js         ← Implémentation Xyno
│       └── package.json
├── examples/
│   └── ADD_NEW_CMS.md                 ← Guide copier-coller
├── README-ARCHITECTURE.md              ← Architecture détaillée
├── MIGRATION_GUIDE.md                  ← Comment refactoriser main.js
├── DONE.md                             ← Résumé de ce qui est fait
└── QUICKSTART.md                       ← Ce fichier

xynoCMS-git/launcher/                   ← EXISTANT (à adapter)
├── main.js                             ← À refactoriser (1250 → 400 lignes)
├── package.json                        ← Sécurisé ✅
├── services/
│   └── authService.js                 ← Sécurisé ✅
├── src/
│   └── bootstrap-env.js                ← Sécurisé ✅
├── .eslintrc.json                      ← NOUVEAU ✅
└── .npmrc                              ← NOUVEAU ✅
```

---

## ✅ État Actuel

### Sécurité (COMPLÉTÉE)

- ✅ Permissions auth.json → 0o600
- ✅ HTTPS forcé pour API_BASE_URL
- ✅ npm audit configuré
- ✅ ESLint + Prettier

### Architecture (COMPLÉTÉE)

- ✅ AbstractLauncherAPI créée (interface abstraite)
- ✅ XynoLauncherAPI implémentée (concrete)
- ✅ Séparation launcher-core / integrations
- ✅ Documentation complète

### Refactorisation Code (À FAIRE)

- ⏳ Extraire services vers launcher-core
- ⏳ Refactoriser main.js
- ⏳ Tests unitaires

---

## 🎯 Prochaines Étapes

### Pour Démarrer Immédiatement (15 min)

1. **Lire la documentation**
   ```
   cat launcher-hub/README-ARCHITECTURE.md
   cat launcher-hub/examples/ADD_NEW_CMS.md
   ```

2. **Comprendre XynoLauncherAPI**
   ```
   cat launcher-hub/integrations/xyno/XynoLauncherAPI.js
   ```

3. **Voir comment l'utiliser**
   ```
   cat launcher-hub/MIGRATION_GUIDE.md
   ```

### Pour Ajouter un Nouveau CMS (1-2 heures)

1. Créer `integrations/my-cms/MyCMSLauncherAPI.js`
2. Implémenter 8 méthodes de `AbstractLauncherAPI`
3. Configurer endpoints API
4. Tester avec le launcher

Voir `examples/ADD_NEW_CMS.md` pour guide complet.

### Pour Terminer la Migration Xyno (1 semaine)

1. Extraire services génériques → launcher-core
2. Refactoriser main.js (1250 → 400 lignes)
3. Tester toutes les fonctionnalités
4. Déployer

Voir `MIGRATION_GUIDE.md` pour détails.

---

## 📊 Impact

### Avant

```
main.js: 1250 lignes
├── Code Minecraft (réutilisable)
├── Code Xyno HMAC (non-réutilisable)
├── Code extensions (Xyno-spécifique)
├── Code auth (Xyno-spécifique)
└── Tout mélangé, impossible à extraire
```

### Après

```
launcher-core:        ← 100% générique
├── MinecraftLauncher
├── FileDownloader
├── AuthenticationManager
└── StateManager

integrations/xyno:    ← 100% Xyno
└── XynoLauncherAPI (encapsule tous les détails Xyno)

main.js: 400 lignes    ← Utilise abstraction
└── Fonctionne avec n'importe quel CMS!
```

---

## 🔐 Sécurité

✅ **LAUNCHER_KEY**: Jamais exposé au renderer  
✅ **HTTPS**: Forcé pour API_BASE_URL  
✅ **auth.json**: Permissions 0o600 (propriétaire seul)  
✅ **Tokens**: Isolés côté main process  
✅ **Dépendances**: npm audit configuré  

---

## 💡 Astuces

### Déboguer l'API

```javascript
// Ajouter dans main.js
const oldFetch = launcherAPI.getStatus;
launcherAPI.getStatus = async function() {
  console.log('[API] Calling getStatus');
  try {
    const result = await oldFetch.call(this);
    console.log('[API] Success:', result);
    return result;
  } catch (err) {
    console.error('[API] Error:', err.message);
    throw err;
  }
};
```

### Tester avec une Mock API

```javascript
class TestLauncherAPI extends AbstractLauncherAPI {
  async getStatus() {
    return { status: 'active', name: 'Test' };
  }
  // ... mock autres méthodes
}

const launcherAPI = new TestLauncherAPI();
```

### Supporter Plusieurs Backends

```javascript
const config = require('./config.json');
let launcherAPI;

if (config.backend_type === 'xyno') {
  launcherAPI = new XynoLauncherAPI(config);
} else if (config.backend_type === 'custom') {
  launcherAPI = new CustomLauncherAPI(config);
}
// Même code launcher fonctionne!
```

---

## 📚 Documentation de Référence

| Document | Contenu | Temps |
|----------|---------|-------|
| README-ARCHITECTURE.md | Design pattern, diagrammes | 10 min |
| MIGRATION_GUIDE.md | Comment refactoriser main.js | 15 min |
| examples/ADD_NEW_CMS.md | Intégrer nouveau CMS (copier-coller) | 30 min |
| DONE.md | Résumé de ce qui est fait | 5 min |
| QUICKSTART.md | Ce fichier, démarrage rapide | 5 min |

---

## ❓ FAQ

**Q: Est-ce que mon launcher Xyno actuel continue de fonctionner?**  
A: Oui! Aucune change requise. C'est optionnel de migrer vers la nouvelle architecture.

**Q: Combien de temps pour migrer?**  
A: ~1 semaine pour refactoriser main.js et extraire services.

**Q: Puis-je supporter plusieurs CMS en même temps?**  
A: Oui! Créez une interface `ILauncherAPI` et plusieurs implémentations.

**Q: Où sont mes secrets?**  
A: Côté main process (jamais exposés au renderer). auth.json a permissions 0o600.

**Q: Comment tester?**  
A: Mocke `AbstractLauncherAPI` avec une classe test.

---

## 🎉 Vous Êtes Prêt!

Vous avez maintenant une architecture**modulaire et sécurisée** où:

✅ Les launchers ne connaissent que vos APIs  
✅ Ajouter un CMS = implémenter une interface  
✅ Zéro code hardcodé pour un CMS spécifique  
✅ Tous les secrets sont isolés  
✅ Code facilement testable  

**Commencez par lire `README-ARCHITECTURE.md`!**

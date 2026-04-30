# 🚀 Launcher-Hub: Electron Launcher Modulaire CMS-Agnostique

> Un launcher Electron générique conçu pour fonctionner avec **n'importe quel backend CMS** via une simple interface REST.

## ✨ Caractéristiques Clés

- **🎮 Générigue**: Zéro code hardcodé pour un CMS spécifique
- **🔐 Sécurisé**: HTTPS forcé, secrets jamais exposés, permissions strictes
- **📦 Modulaire**: Ajouter un CMS = implémenter une interface
- **🧪 Testable**: Dépendances injectables, interface abstraite
- **📚 Bien documenté**: Architecture claire, exemples complets

## Structure

```
launcher-hub/
├── launcher-core/              ← Code générique réutilisable
│   ├── services/
│   │   ├── AbstractLauncherAPI.js         ← Interface pivot
│   │   ├── MinecraftLauncher.js
│   │   ├── FileDownloader.js
│   │   ├── AuthenticationManager.js
│   │   └── StateManager.js
│   ├── utils/
│   └── index.js
│
├── integrations/
│   ├── xyno/                   ← Implémentation Xyno
│   │   ├── XynoLauncherAPI.js
│   │   └── ...
│   └── my-cms/                 ← Futurs CMS
│       ├── MyLauncherAPI.js
│       └── ...
│
├── docs/
├── examples/
│   └── ADD_NEW_CMS.md
├── README.md                   ← Vous êtes ici
├── README-ARCHITECTURE.md      ← Explique le design
├── MIGRATION_GUIDE.md          ← Comment migrer le code existant
└── package.json

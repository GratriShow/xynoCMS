# Launcher Electron (base technique)

Objectif : démarrer Electron, appeler l’API `manifest`, puis synchroniser les fichiers localement (download + delete) **sans** bouton PLAY et **sans** lancement Minecraft.

## Prérequis

- Node.js + npm
- Accès à l’API existante

## Configuration (env vars)

- `API_BASE_URL` : base URL du site (ex: `http://127.0.0.1:8000`)
- `LAUNCHER_UUID` : uuid du launcher
- `LAUNCHER_KEY` : clé API
- `LAUNCHER_SLUG` (optionnel) : nom du dossier local (ex: `kingwars` → `.${slug}Launcher`)

## Dossier local (multi-OS)

Le launcher stocke ses fichiers dans :

`app.getPath('appData') + '/.' + <slug> + 'Launcher'`

Avec la structure :

- `mods/`
- `config/`
- `assets/`
- `versions/`

## State local (cache de sync)

Le launcher maintient un fichier `state.json` dans le dossier local :

`app.getPath('appData') + '/.' + <slug> + 'Launcher/state.json'`

Objectif : éviter un recalcul complet (hash de tous les fichiers) à chaque lancement.

Règles :

- Le manifest API reste la source de vérité.
- Si `state.json` est manquant/corrompu → revalidation complète + rebuild.
- Si `manifest.launcher.version !== state.version` → revalidation complète (MAJ majeure).
- Sinon → vérification rapide (existence + taille + hash via state) et update delta.

## Lancer en dev

```bash
cd /Users/lucasnoel/Herd/xynoCMS/public/launcher
npm install

API_BASE_URL="http://127.0.0.1:8000" \
LAUNCHER_UUID="XXX" \
LAUNCHER_KEY="YYY" \
LAUNCHER_SLUG="kingwars" \
npm run dev
```

Les logs de progression (compare / download / delete) sont visibles dans la console.

## Notes sécurité

- Le manifest est validé (paths relatifs sûrs + top-level limité à `mods/`, `config/`, `assets/`, `versions/`).
- Les URLs de download doivent être sur le **même origin** que `API_BASE_URL`.
- Si l’API répond autre chose que `200`, la sync s’arrête (ex: abonnement inactif → `403`).

## Auto-update (sécurisé)

Au **démarrage**, le launcher vérifie toujours une mise à jour et refuse d’installer une archive non validée.

### API

Endpoint (préféré) :

- `GET /api/launcher/update?uuid=XXX`

Fallback si l’hébergement ne supporte pas les rewrites :

- `GET /api/launcher.php/update?uuid=XXX`

Réponse attendue :

```json
{ "version": "1.2.0", "url": "https://…/launcher/v1.2.0.zip", "signature": "<sha256>", "required": true }
```

Contraintes :

- `url` doit être en **HTTPS**
- `signature` = SHA256 hex (64 chars) de l’archive téléchargée

### Version locale

La version locale est persistée dans :

- `app.getPath('userData')/version.json`

### Format de l’archive

L’archive `.zip` doit contenir au minimum :

- `resources/app.asar` (ou `app.asar` à la racine)

Optionnel :

- `resources/app.asar.unpacked/` (ou `app.asar.unpacked/`)

## Thèmes UI (designs) dynamiques

Le launcher supporte plusieurs thèmes d’interface sans modifier la logique principale.

### Source de vérité

Le thème est choisi via le manifest API :

```json
{
	"launcher": {
		"theme": "cosmic"
	}
}
```

Si le thème est absent/invalide/introuvable → fallback sur `default`.

### Structure

Les thèmes vivent dans `themes/` :

- `themes/default/index.html`, `style.css`, `renderer.js`
- `themes/cosmic/index.html`, `style.css`, `renderer.js`

La logique (sync/download/auth/minecraft/...) reste dans `services/`.

### Chargement

Au démarrage, l’app charge `themes/default/index.html`, récupère le manifest puis charge `themes/{theme}/index.html`.

### Communication UI ⇄ backend

L’UI ne doit pas importer `services/*`.
Elle utilise uniquement l’API exposée par `preload.js` :

- `window.launcherAPI.login({ interactive })`
- `window.launcherAPI.play()`
- `window.launcherAPI.progression({ onStatus, onProgress, onError, onInfo, onUx, onMsaCode })`

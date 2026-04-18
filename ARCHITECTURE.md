# xynoCMS — Architecture du build multi-launchers

Ce document décrit le flux complet entre le CMS PHP, GitHub Actions et le launcher Electron pour générer, par UUID, des installers Windows/Linux/Mac.

## Vue d'ensemble

```
 ┌─────────────────────┐        POST /api/trigger_build.php
 │  Dashboard (PHP)    │─────────────────────────────────────┐
 │  user connecté      │                                     │
 └─────────────────────┘                                     ▼
                                                   ┌──────────────────┐
                                                   │ api/trigger_build│
                                                   │   .php           │
                                                   └────────┬─────────┘
                                                            │ repository_dispatch
                                                            │ (XYNO_GITHUB_TOKEN)
                                                            ▼
                                                   ┌──────────────────┐
                                                   │ GitHub API       │
                                                   │ /dispatches      │
                                                   └────────┬─────────┘
                                                            │ event: build_custom_launcher
                                                            ▼
                                    ┌───────────────────────────────────────────┐
                                    │  .github/workflows/build.yml               │
                                    │  ┌─────────────────────────────────────┐   │
                                    │  │ prepare (ubuntu-latest)              │   │
                                    │  │  → résout matrix [win, linux, mac]   │   │
                                    │  └─────────────────────────────────────┘   │
                                    │  ┌─────────────────────────────────────┐   │
                                    │  │ build (matrix, 3 runners parallèles) │   │
                                    │  │  1. GET /api/build_config.php?uuid=  │   │──────┐
                                    │  │  2. télécharge assets                │   │      │
                                    │  │  3. npm ci + electron-builder        │   │      │
                                    │  │  4. POST /api/release_upload.php     │   │──────┤ CMS (VPS)
                                    │  │  5. POST /api/build_status.php       │   │──────┘
                                    │  └─────────────────────────────────────┘   │
                                    └───────────────────────────────────────────┘
                                                            │
                                                            ▼
                                     ┌───────────────────────────────────────┐
                                     │  VPS Linux — stockage files/          │
                                     │  files/<uuid>/client/installer/win/   │
                                     │  files/<uuid>/client/installer/linux/ │
                                     │  files/<uuid>/client/installer/mac/   │
                                     │  files/<uuid>/client/update/          │
                                     └───────────────────────────────────────┘
                                                            │
                                                            ▼
                                     ┌───────────────────────────────────────┐
                                     │ GET /download_launcher.php?uuid=…     │
                                     │ → 302 vers le bon installer actif     │
                                     └───────────────────────────────────────┘
```

## Pièces de l'architecture

### 1. CMS PHP (sur ton VPS)

| Fichier | Rôle |
|---|---|
| `api/trigger_build.php` | Endpoint appelé par le dashboard pour demander un build (auth session user). |
| `api/build_config.php` | Lu par GitHub Actions (token serveur) pour récupérer la config complète d'un launcher (uuid, api_key, thème, modules, assets). |
| `api/build_status.php` | Reçoit le statut des jobs (success/failure) et met à jour `launcher_builds`. |
| `api/release_upload.php` | Reçoit les installers finaux (un POST multipart par OS) et les range dans `files/<uuid>/client/installer/<os>/`. |
| `download_launcher.php` | Redirige le client vers le bon installer actif selon son OS. |

### 2. GitHub Actions

Un seul workflow, `.github/workflows/build.yml`, consomme les évènements `repository_dispatch` `build_custom_launcher` et bâtit en matrice.

Secrets GitHub à configurer (Repo → Settings → Secrets → Actions) :

| Secret | Description |
|---|---|
| `BUILD_FETCH_TOKEN` | Token opaque partagé entre le CMS (`XYNO_BUILD_FETCH_TOKEN`) et GitHub Actions. Autorise le workflow à lire `build_config.php` et poster dans `build_status.php`. |
| `RELEASE_UPLOAD_TOKEN` | Token déjà utilisé par `release_upload.php` (`XYNO_RELEASE_UPLOAD_TOKEN` côté serveur). |

### 3. Launcher Electron

| Fichier | Rôle |
|---|---|
| `launcher/src/bootstrap-env.js` | Premier `require` de `main.js`. Lit `src/config.json` (bundlé par le build) et peuple `process.env.LAUNCHER_UUID`, `LAUNCHER_KEY`, `API_BASE_URL`, etc. |
| `launcher/src/config.example.json` | Template versionné (pas de secret). |
| `launcher/src/config.json` | **Généré au build — jamais commité.** Contient les valeurs réelles. |
| `launcher/src/assets/` | Logo, fond, icône téléchargés depuis le CMS au build. |
| `launcher/build-multi-launchers.js` | Script de batch local pour tester sans CI. |

## Flux détaillé — de "je crée un launcher" à "le client le télécharge"

### A) Le client (toi ou un futur abonné) clique "Build" dans le dashboard

1. Dashboard → `POST /api/trigger_build.php` avec `{uuid, targets: ["windows","linux","mac"]}`.
2. `trigger_build.php` :
   - Vérifie la session (user_id).
   - Vérifie que `launchers.user_id = session.user_id`.
   - Rate-limit (10/min/IP).
   - Génère une `version` = `YYYYMMDD-HHMM`.
   - INSERT dans `launcher_builds` (statut `queued`).
   - Appelle `POST https://api.github.com/repos/<owner>/<repo>/dispatches` avec :

     ```json
     {
       "event_type": "build_custom_launcher",
       "client_payload": {
         "uuid": "<uuid>",
         "version": "<version>",
         "targets": "windows,linux,mac",
         "cms_base_url": "https://cms.tld"
       }
     }
     ```

### B) GitHub Actions prend le relais

Le job `prepare` (ubuntu-latest) lit le payload, construit la matrice JSON, puis trois jobs `build` démarrent en parallèle :

1. **Checkout** du repo `xynoCMS` (contient le template du launcher dans `launcher/`).
2. **Fetch config** — `curl -H "X-Build-Token: …" "$CMS/api/build_config.php?uuid=…"` → `launcher/src/config.json`.
3. **Override version** — on réécrit `config.version` avec le timestamp de ce build.
4. **Download assets** — pour chaque `assets.<key>` dans la config, `curl` vers `launcher/src/assets/`.
5. **npm ci** dans `launcher/`.
6. **electron-builder --win / --linux / --mac** (un par runner selon la matrice).
7. **Upload** de l'installer via `POST /api/release_upload.php` (paramètres `type=installer&platform=<win|linux|mac>&version=<...>`).
8. **Notify** — `POST /api/build_status.php` (succès/échec/URL du run).

### C) Stockage et DB (VPS)

- L'installer est écrit dans `files/<uuid>/client/installer/<platform>/installer-<platform>-<version>.<ext>`.
- Une ligne est insérée dans `launcher_downloads` (launcher_id, platform, version_name, file_url, file_sha256, is_active=1).
- Les anciennes entrées actives pour la même (launcher_id, platform) sont basculées `is_active=0`.

### D) Le client télécharge son launcher

- Sur le site : `GET /download_launcher.php?uuid=<uuid>` détecte l'User-Agent, trouve la dernière ligne `launcher_downloads.is_active=1` pour cette plateforme, et redirige (ou stream) le fichier.
- Il est judicieux d'ajouter une vérification d'abonnement avant de servir le fichier (à brancher quand Stripe arrivera).

## Configuration requise (étape par étape)

### 1. Variables d'environnement du CMS (`config/.env.local`)

```
XYNO_GITHUB_TOKEN=<PAT GitHub avec scope "repo" ou fine-grained "Actions: write">
XYNO_GITHUB_REPO=GratriShow/xynoCMS
XYNO_BUILD_FETCH_TOKEN=<chaîne aléatoire de 32+ chars>
XYNO_RELEASE_UPLOAD_TOKEN=<chaîne aléatoire de 32+ chars, déjà utilisée>
XYNO_JWT_SECRET=<déjà utilisée pour l'API v2>
```

### 2. Secrets GitHub Actions (Repo → Settings → Secrets → Actions)

```
BUILD_FETCH_TOKEN     = même valeur que XYNO_BUILD_FETCH_TOKEN
RELEASE_UPLOAD_TOKEN  = même valeur que XYNO_RELEASE_UPLOAD_TOKEN
```

### 3. Migration DB

Exécuter cette requête (ou la laisser créer automatiquement par `trigger_build.php` au premier build) :

```sql
CREATE TABLE IF NOT EXISTS `launcher_builds` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `uuid` VARCHAR(36) NOT NULL,
  `version` VARCHAR(32) NOT NULL,
  `targets` VARCHAR(64) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'queued',
  `run_url` VARCHAR(512) NULL,
  `requested_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `launcher_builds_launcher_id_idx` (`launcher_id`),
  KEY `launcher_builds_uuid_idx` (`uuid`),
  UNIQUE KEY `launcher_builds_uniq` (`uuid`, `version`)
);

CREATE TABLE IF NOT EXISTS `launcher_modules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `module_key` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_modules_uniq` (`launcher_id`, `module_key`),
  KEY `launcher_modules_launcher_id_idx` (`launcher_id`)
);

ALTER TABLE `launchers`
  ADD COLUMN IF NOT EXISTS `api_key` VARCHAR(128) NULL AFTER `theme`;
```

### 4. Test bout-en-bout

1. Révoque le token GitHub qui était commité en dur (voir plus bas).
2. Dans un `INSERT` de test sur `launchers`, mets un `api_key` aléatoire.
3. Appelle depuis ton navigateur :
   ```
   curl -X POST https://cms/api/trigger_build.php \
     -H "Cookie: PHPSESSID=…" \
     -H "Content-Type: application/json" \
     -d '{"uuid":"<uuid>","targets":["linux"]}'
   ```
4. Va voir Actions sur GitHub → tu dois voir un run `Build Launcher (per-tenant)`.
5. À la fin, le fichier arrive dans `files/<uuid>/client/installer/linux/`.
6. `SELECT * FROM launcher_downloads WHERE launcher_id = …` → la ligne active.

## ⚠️ Sécurité — À FAIRE avant de rien déployer

1. **Révoque tout ancien PAT GitHub** qui aurait été committé en clair dans `api/trigger_build.php` (commits antérieurs). Va sur https://github.com/settings/tokens → Revoke, puis génère-en un nouveau et mets-le dans `XYNO_GITHUB_TOKEN` (dans `config/.env.local`, jamais dans le code).
2. **Purge l'historique git** si le repo est public et qu'un secret y apparaît encore :
   ```
   git filter-repo --replace-text <(echo "<TOKEN_A_REMPLACER>==>REMOVED")
   ```
   (nécessite `git-filter-repo`). Puis force-push sur main.
3. L'`api_key` de chaque launcher ne doit JAMAIS être exposée côté renderer. Dans `bootstrap-env.js`, on sépare `LAUNCHER_PUBLIC_CONFIG` (sans `api_key`) pour le front vs `process.env.LAUNCHER_KEY` (secret, main process only).
4. Active HTTPS obligatoire côté CMS : `release_upload.php` et le workflow refusent déjà le HTTP clair.

## Extensions futures

- **Stripe** : brancher l'abonnement sur l'accès à `/download_launcher.php` et sur le endpoint v2 `status.php`. Le check est déjà fait côté launcher (`checkLicense`) via `apiV2.getStatus()` — il suffit de gater le flag `status === 'active'` sur la DB d'abonnement.
- **Plugins/modules** : ajouter une UI dans le dashboard pour toggler les `launcher_modules`. Le launcher expose déjà `LAUNCHER_PUBLIC_CONFIG.modules[]` côté renderer pour activer/désactiver des features d'UI.
- **Signing Windows/macOS** : quand tu auras un certificat, passer les secrets `CSC_LINK`, `CSC_KEY_PASSWORD` à electron-builder et virer `CSC_IDENTITY_AUTO_DISCOVERY=false`.
- **Auto-update** : le ZIP `app.asar` est déjà géré via `release_upload.php?type=update`. On peut étendre `build.yml` pour produire aussi ce ZIP à chaque build (via `npm run build:update-zip`).

## Fichiers modifiés/créés lors de cette refonte

```
.github/workflows/build.yml           [réécrit]    matrix multi-OS + fetch config + upload
.gitignore                            [mis à jour] ignore src/config.json, src/assets/, release/
api/trigger_build.php                 [réécrit]    retire token hardcodé, payload enrichi
api/build_config.php                  [nouveau]    config par UUID pour le build
api/build_status.php                  [nouveau]    réception des statuts de jobs
launcher/main.js                      [patch]      require('./src/bootstrap-env') en tête
launcher/src/bootstrap-env.js         [nouveau]    config.json → process.env
launcher/src/config.example.json      [nouveau]    template versionné
launcher/build-multi-launchers.js     [rempli]     batch local (sans CI)
launcher/uuids.json                   [rempli]     exemple de liste
ARCHITECTURE.md                       [nouveau]    ce document
```

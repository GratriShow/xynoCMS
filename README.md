# XynoLauncher (front + backend PHP/MySQL)

Pages (multi-pages, prêtes à intégrer dans un SaaS) :
- `index.php` : landing
- `pricing.php` : tarifs (toggle mensuel/annuel)
- `builder.php` : tunnel multi-étapes (création launcher)
- `dashboard.php` : dashboard (protégé, liste + edit + delete)
- `dashboard/upload.php` : fichiers (protégé, upload + liste + suppression)
- `login.php` / `register.php` : auth (fonctionnel)

Assets :
- `assets/style.css` : styles globaux (dark SaaS)
- `assets/main.js` : interactions (sticky navbar, smooth scroll, pricing toggle, builder)

## Prévisualiser en local

Depuis `public/` :

```zsh
php -S 127.0.0.1:4173 -t .
```

Puis ouvrir :
- `http://127.0.0.1:4173/index.php`

## Backend (PHP + MySQL via phpMyAdmin)

### 1) Créer la base et les tables

Dans phpMyAdmin :
- crée la base `xynocms` (ou adapte `config/database.php`)
- importe `xynocms.sql`

Si tu as déjà une base existante (sans les tables/colonnes API), importe `migrations_api.sql`.

### 1bis) Fichiers (mods/config/assets)

- Stockage local dans `public/files/` avec la structure :
	- `/files/{launcher_uuid}/mods/`
	- `/files/{launcher_uuid}/config/`
	- `/files/{launcher_uuid}/assets/`
	- `/files/{launcher_uuid}/versions/{mc_version}/`
- DB : la table `files` doit contenir au minimum `type`, `module`, `mc_version`, `name`, `path`, `hash`, `size`.
- Page : `GET /dashboard/upload.php`
- API : `GET /api/files.php?uuid=...&key=...` retourne une liste filtrée selon `loader`, `version` et `modules`.

### 1ter) Manifest (pour launcher Electron)

- Endpoint : `GET /api/manifest.php?uuid=...&key=...`
- Réponse : un JSON avec la config launcher + la liste exhaustive des fichiers à synchroniser.
- Important : `path` est **toujours relatif à `.minecraft`** (ex: `mods/mod.jar`, `config/modpack/config.json`).
- `url` pointe vers un endpoint sécurisé id-based : `GET /api/file.php?uuid=...&key=...&id=...`.
- Métadonnées (optionnelles, mais fournies) : `file_count`, `total_size`.

#### Delta update (mise à jour intelligente)

Règles côté launcher (le manifest est la source de vérité) :
- Pour chaque entrée `files[]` :
	- chemin local = `.minecraft/{path}`
	- télécharger uniquement si le fichier n’existe pas **ou** si son SHA1 diffère (`hash`).
- Suppression : supprimer les fichiers locaux **dans les dossiers gérés par le manifest** (ex: `mods/`, `config/`, `assets/`, `versions/`) qui n’existent plus dans `files[]`.

Performance : éviter de recalculer le hash inutilement en gardant un cache local (ex: `{path -> size, mtime, hash}`) et ne recalculer SHA1 que si `size`/`mtime` a changé.

Script de référence (CLI) : `tools/launcher_delta_update.php`

```zsh
php tools/launcher_delta_update.php \
	--manifest "http://127.0.0.1:4173/api/manifest.php?uuid=...&key=..." \
	--minecraft "$HOME/.minecraft"
```

#### Versioning (build/release)

- Le manifest n’est plus généré dynamiquement depuis la table `files`.
- Il est servi depuis la **version active** stockée en base (table `launcher_versions`).
- Tant qu’aucune version n’est publiée, `GET /api/manifest.php` renvoie `404 No published version`.

Publier/activer une version :
- Dashboard → sélectionne un launcher → section **Versions** → **Publier une version**
- Puis (optionnel) activer une version depuis la liste.

Le endpoint renvoie un `ETag` et supporte `If-None-Match` (réponse `304 Not Modified`).

## Distribution du launcher Electron (installers + auto-update)

### Build multi-plateforme (installers)

Dans `public/launcher/` :

```zsh
npm run build
```

Sorties :
- Windows : NSIS `.exe`
- macOS : `.dmg`
- Linux : `.AppImage`

Note : l’output d’`electron-builder` est `launcher/release/` (pour ne pas écraser `launcher/dist/` utilisé par l’obfuscation).

### Auto-update (ZIP app.asar)

- Endpoint client : `GET /api/launcher/update?uuid=...` (retourne `{version,url,signature,required}`)
- Upload release (CI/CD) : `POST /api/release_upload.php` (multipart)
	- Auth via header `X-Upload-Token` (configure `XYNO_RELEASE_UPLOAD_TOKEN` côté serveur)
	- `type=update` attend un `.zip` contenant `resources/app.asar`
	- Le serveur calcule et stocke le `sha256` (champ `signature` côté client)

### CI/CD (GitHub Actions)

Workflow : `.github/workflows/build.yml`

Comportement :
- `push` sur `main` : bump automatique de version (patch), commit + tag `vX.Y.Z`.
- `push` d’un tag `v*` : build multi-OS (Windows/macOS/Linux), génération des installers + génération du ZIP d’auto-update, puis upload vers le CMS via `POST /api/release_upload.php`.

Secrets GitHub requis :
- `CMS_BASE_URL` : ex `https://ton-domaine.tld`
- `LAUNCHER_UUID` : UUID du launcher côté CMS
- `RELEASE_UPLOAD_TOKEN` : doit matcher la variable serveur `XYNO_RELEASE_UPLOAD_TOKEN`

Déclenchement "webhook" (bonus) : le workflow accepte aussi `repository_dispatch` avec le type `cms_rebuild`.
- Par défaut : rebuild/publish sans bump (réutilise la version courante de `launcher/package.json`).
- Si tu veux bump + tag via dispatch : envoyer `client_payload.bump=true`.

### Téléchargement via le site

Lien OS-aware : `GET /download_launcher.php?uuid=...`

Le dashboard affiche un bouton “Télécharger launcher” qui utilise ce endpoint.

### 1quater) API v2 (signée + anti-replay + token court)

Objectif : durcir la vérification d’abonnement en considérant le launcher compromis (client non fiable).

- Session (POST, preuve de possession de `api_key` via HMAC, sans envoyer la clé en clair) : `POST /api/v2/session.php`
- Status (POST signé, re-check abonnement + intégrité optionnelle) : `POST /api/v2/status.php`
- Manifest (GET signé) : `GET /api/v2/manifest.php`
- Download fichier (GET signé) : `GET /api/v2/file.php?id=...`
- Token “play” (POST signé, exp 10 min) : `POST /api/v2/token.php`

Requêtes signées (v2) :
- `Authorization: Bearer <JWT>`
- `X-Session-Id`, `X-TS` (epoch seconds), `X-Nonce` (unique), `X-Sig` (HMAC-SHA256)
- Anti-replay via table `api_nonces`

Configuration serveur (obligatoire) :
- définir `XYNO_JWT_SECRET` (secret HS256 pour JWT v2)

Astuce (Herd-friendly) :
- tu peux créer `config/.env.local` (voir `config/.env.local.example`)
- ce fichier est chargé automatiquement par `config/bootstrap.php`

Options serveur :
- `XYNO_API_ENFORCE_IP=1` : bind IP/session (plus strict)

Intégrité (optionnel, fail-safe si configuré) :
- colonne `launchers.client_integrity_sha256` : SHA256 attendu de `app.asar`
- si renseignée, le serveur refuse `status/token/session` si le hash envoyé ne matche pas

```sql
CREATE TABLE `users` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`uuid` VARCHAR(36) NOT NULL,
	`email` VARCHAR(190) NOT NULL,
	`password` VARCHAR(255) NOT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `users_uuid_unique` (`uuid`),
	UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `launchers` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`user_id` INT NOT NULL,
	`uuid` VARCHAR(36) NOT NULL,
	`name` VARCHAR(190) NOT NULL,
	`description` TEXT NULL,
	`version` VARCHAR(32) NOT NULL,
	`loader` VARCHAR(32) NOT NULL,
	`theme` VARCHAR(64) NOT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `launchers_uuid_unique` (`uuid`),
	KEY `launchers_user_id_index` (`user_id`),
	CONSTRAINT `launchers_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2) Configurer la connexion PDO

Édite `config/database.php` :
- `DB_HOST`, `DB_PORT`
- `DB_NAME`, `DB_USER`, `DB_PASS`

### 3) Pages backend

- `GET /register.php` puis inscription
- `GET /login.php` puis connexion
- `GET /builder.php` puis “Créer le launcher”
- `GET /dashboard.php` (protégé) : liste + édition + suppression
- `GET /logout.php`

## Notes

- L’auth et la gestion des launchers sont en PHP (sans framework) avec PDO + requêtes préparées.
- La page tarifs passe les paramètres au builder via query string : `builder.php?plan=pro&billing=yearly`.

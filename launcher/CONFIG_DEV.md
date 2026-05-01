# Configuration de Développement du Launcher

## ⚠️ Important

Le fichier `config.json` est **nécessaire** pour tester le launcher localement. Il est **JAMAIS** commité dans Git (voir `.gitignore`).

Chaque launcher a son propre UUID généré par le CMS. Le `config.json` est généré dynamiquement par `/api/build_config.php`.

## Setup Développement Local

### Option 1: Récupérer la config depuis votre CMS (Recommandé)

Si vous avez un launcher configuré dans votre CMS sur votre VPS:

```bash
# Récupérez la config généré par votre CMS
curl -s "https://votre-vps.com/api/build_config.php?uuid=YOUR_LAUNCHER_UUID" \
  -H "X-Build-Token: YOUR_BUILD_TOKEN" > launcher/config.json
```

Puis démarrez:
```bash
cd launcher
npm install
npm run dev
```

### Option 2: Générer la config via votre CMS

1. Allez dans l'admin du CMS: **Clients > Launchers**
2. Créez ou sélectionnez un launcher (ex: `minecraft-forest`)
3. Récupérez son UUID
4. Appelez l'API build_config avec le token Build Token configuré dans le CMS

### Option 3: Variables d'environnement

Si vous préférez définir directement:

```bash
export LAUNCHER_UUID="550e8400-e29b-41d4-a716-446655440000"
export API_BASE_URL="https://votre-vps.com"
export LAUNCHER_KEY="votre-api-key"
npm run dev
```

## Où trouver les valeurs?

| Valeur | Où la trouver |
|--------|---------------|
| `LAUNCHER_UUID` | Admin CMS → Clients → Launchers → UUID du launcher |
| `API_BASE_URL` | L'URL publique de votre CMS (ex: https://mon-vps.com) |
| `LAUNCHER_KEY` | Admin CMS → Launchers → API Key (générée automatiquement) |
| `X-Build-Token` | Admin CMS → Settings → Build Token (pour GitHub Actions) |

## Architecture

```
CMS (votre VPS)
  └── /api/build_config.php (génère config.json basé sur l'UUID du launcher)
         ↓
     config.json (téléchargé localement pour dev)
         ↓
     launcher/main.js (bootstrap-env.js charge la config)
         ↓
     Launcher démarre avec le thème configuré
```

## Troubleshooting

### "Missing env var: LAUNCHER_UUID"

→ Le `config.json` n'existe pas ou la variable d'environnement n'est pas définie.
→ Utilisez l'option 1 ou 2 ci-dessus pour récupérer la config.

### "API unreachable"

→ Vérifiez que `api_base_url` dans la config pointe vers votre CMS.
→ Assurez-vous que votre CMS est accessible (HTTPS requis).

### Localhost ne fonctionne pas

→ Le launcher DOIT pointer vers une vraie instance CMS (https://votre-vps.com).
→ Impossible de tester avec un mock ou localhost (architecture centralisée CMS).

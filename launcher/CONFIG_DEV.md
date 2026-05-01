# Configuration de Développement du Launcher

## ⚠️ Important

Le fichier `config.json` est **nécessaire** pour tester le launcher localement. Il est **JAMAIS** commité dans Git (voir `.gitignore`).

## Setup Développement Local

### 1. Créer un config.json local

Un fichier `config.json` exemple est fourni à la racine du dossier `/launcher`. C'est ce fichier qui sera utilisé lors du développement.

**NE PAS committer ce fichier dans Git avec de vraies clés API.**

### 2. Configurer pour votre API

Modifiez le `config.json` pour pointer vers votre API :

```json
{
  "uuid": "votre-uuid-launcher",
  "api_base_url": "https://votre-site.com",
  "api_key": "votre-clé-api",
  "theme": "minecraft-forest",
  ...
}
```

### 3. Démarrer le launcher en développement

```bash
cd launcher
npm install
npm run dev
```

### 4. Variables d'environnement alternatives

Si vous préférez ne pas committer de config.json, vous pouvez définir les variables d'environnement :

```bash
export LAUNCHER_UUID="mon-uuid"
export API_BASE_URL="https://mon-api.com"
export LAUNCHER_KEY="ma-clé-api"
npm run dev
```

## Structure de config.json

| Clé | Description | Exemple |
|-----|-------------|---------|
| `uuid` | UUID du launcher | `550e8400-e29b-41d4-a716-446655440000` |
| `api_base_url` | URL de base de l'API (HTTPS requis) | `https://monsite.com` |
| `api_key` | Clé API pour authentifier les requêtes | `secret-key-12345` |
| `theme` | Thème par défaut | `minecraft-forest` ou `cosmic` |
| `name` | Nom du launcher | `Mon Launcher` |
| `version` | Version du launcher | `1.0.0` |

## En Production

En production, `config.json` est généré automatiquement par :
- **GitHub Actions** (via `/api/build_config.php`)
- **Ou** `launcher/build-multi-launchers.js`

Le fichier local de développement n'est jamais utilisé.

## Troubleshooting

### "Missing env var: LAUNCHER_UUID"

→ Assurez-vous que `config.json` existe et contient une valeur pour `uuid`.

### "API unreachable"

→ Vérifiez que `api_base_url` pointe vers une API valide et accessible.
→ Si vous testez localement, créez une API mock ou utilisez votre VPS.

### "Configuration manquante"

→ Le config.json manque, est invalide, ou une variable d'environnement n'est pas définie.

# Bugfix: Microsoft Authentication Timeout

## Problème
Quand le joueur clique sur "Se connecter avec Microsoft", la page d'authentification reste en chargement infini et rien n'arrive après ❌

## Cause
La fonction `loginMicrosoft()` dans `launcher/services/authService.js` n'avait:
- ❌ Pas de timeout explicite → les erreurs réseau peuvent bloquer indéfiniment
- ❌ Logs insuffisants → impossible de diagnostiquer où ça bloque
- ❌ Mauvaise gestion d'erreurs → messages peu clairs pour l'utilisateur

## Solutions appliquées

### 1. **Timeout explicite (30 secondes)** ⏱️
```javascript
const timeoutMs = 30000;
const timeoutPromise = new Promise((_, reject) =>
  setTimeout(() => reject(new Error(...)), timeoutMs)
);
const result = await Promise.race([tokenPromise, timeoutPromise]);
```
- Si Microsoft ne répond pas en 30s → erreur claire au lieu de hang infini
- Le joueur reçoit un message explicite

### 2. **Logs de diagnostic détaillés** 📋
```
[auth] 🔵 Trying MinecraftJava flow (sisu) with Microsoft servers...
[auth] ⏱️ TIMEOUT: Authentication timeout after 30s. Check your internet connection...
[auth] ℹ️ This usually means: firewall blocking, internet down, or Microsoft servers unreachable
```
Permet de:
- Voir exactement où ça bloque
- Diagnostiquer les problèmes réseau/firewall
- Identifier si les serveurs Microsoft sont down

### 3. **Messages d'erreur explicites** 📢
```javascript
// Au lieu de: "Microsoft login failed (missing token)"
// Maintenant: "Microsoft login failed: no access token received. Try again or check your internet."
```
- Aide l'utilisateur à comprendre le problème
- Suggestions claires (redémarrer, vérifier internet, etc.)

### 4. **Amélioration de la validation** ✅
```javascript
debugLog(`Validating token and profile... token=✅ profile=✅`)
// Au lieu de crasher sans raison
```

## Fichiers modifiés
- `launcher/services/authService.js`
  - Fonction `getTokenWith()` → ajout timeout + logs
  - Fonction `loginMicrosoft()` → meilleure gestion d'erreurs

## Tests à faire
1. Tester la connexion Microsoft normale
2. Débrancher internet et tester (doit donner timeout après 30s)
3. Vérifier les logs dans la console du launcher (Ctrl+Shift+I)

## Logs attendus en cas de timeout
```
[auth] 🔵 Trying MinecraftJava flow (sisu) with Microsoft servers...
[auth] Starting token fetch with 30s timeout...
[auth] ⏱️ TIMEOUT: Authentication timeout after 30s. Check your internet connection and firewall settings.
[auth] ℹ️ This usually means: firewall blocking, internet down, or Microsoft servers unreachable
[auth] 🔄 Got 403 Forbidden, trying MinecraftNintendoSwitch fallback (live)...
[auth] ⏱️ Fallback flow TIMEOUT: ...
[auth] ❌ Fatal error, not attempting fallback
```

## Prochaines étapes
- Si ça timeout toujours → vérifier firewall/proxy
- Si message d'erreur différent → voir les logs pour plus de détails
- Si ça marche → passer le launcher en production! 🚀

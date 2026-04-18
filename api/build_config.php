<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * GET /api/build_config.php?uuid=...
 *
 * Appelé par GitHub Actions (header X-Build-Token) pour récupérer la config
 * complète d'un launcher au moment du build.
 *
 * Retourne un JSON consommé par launcher/src/bootstrap-env.js :
 *   {
 *     "uuid": "...",
 *     "name": "...",
 *     "version": "...",               // sera écrasé par le workflow
 *     "api_base_url": "https://cms",
 *     "api_key": "...",
 *     "renew_url": "https://cms/pricing.php",
 *     "license_recheck_minutes": 5,
 *     "expected_asar_sha256": "",
 *     "theme": "default",
 *     "modules": ["news","settings"],
 *     "branding": {...},
 *     "assets": { "logo": "https://cms/...", "background": "https://cms/...", "icon": "https://cms/..." }
 *   }
 */

$endpoint = 'build_config';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 60, 60);

if (api_method() !== 'GET') {
    api_json(['error' => 'Method Not Allowed'], 405);
}

$token = api_header('X-Build-Token', 512);
$expected = api_env('XYNO_BUILD_FETCH_TOKEN', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    api_log($endpoint, $ip, null, 401, 'unauthorized');
    api_json(['error' => 'Unauthorized'], 401);
}

$uuid = api_param('uuid', 64);
if ($uuid === '' || !preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
    api_json(['error' => 'invalid_uuid'], 400);
}

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT l.id, l.uuid, l.name, l.version, l.loader, l.theme,
            l.client_integrity_sha256,
            l.api_key
       FROM launchers l
      WHERE l.uuid = ?
      LIMIT 1"
);
$stmt->execute([$uuid]);
$l = $stmt->fetch();
if (!$l) {
    api_json(['error' => 'launcher_not_found'], 404);
}

// Modules activés pour ce launcher (table launcher_modules: launcher_id, module_key).
$modules = [];
try {
    $st = $pdo->prepare('SELECT module_key FROM launcher_modules WHERE launcher_id = ?');
    $st->execute([(int)$l['id']]);
    $modules = array_values(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
} catch (Throwable $e) {
    // Table peut ne pas exister encore : on ignore.
    $modules = [];
}

$base = api_public_base_url();

// Assets attendus : on pointe vers l'URL publique. Le workflow les télécharge
// ensuite dans launcher/src/assets/.
$assets = [];
$assetDir = __DIR__ . '/../files/' . $uuid . '/client/assets';
if (is_dir($assetDir)) {
    foreach (['logo', 'background', 'icon'] as $key) {
        foreach (['png', 'jpg', 'jpeg', 'ico', 'webp'] as $ext) {
            $candidate = $assetDir . '/' . $key . '.' . $ext;
            if (is_file($candidate)) {
                $assets[$key] = $base . '/files/' . rawurlencode($uuid) . '/client/assets/' . $key . '.' . $ext;
                break;
            }
        }
    }
}

api_json([
    'uuid' => (string)$l['uuid'],
    'name' => (string)$l['name'],
    'version' => (string)$l['version'],
    'api_base_url' => $base,
    'api_key' => (string)($l['api_key'] ?? ''),
    'renew_url' => $base . '/pricing.php',
    'license_recheck_minutes' => 5,
    'expected_asar_sha256' => (string)($l['client_integrity_sha256'] ?? ''),
    'theme' => (string)($l['theme'] ?? 'default'),
    'modules' => $modules,
    'branding' => [],
    'assets' => $assets,
]);

<?php

declare(strict_types=1);

/**
 * Per-extension proxy.
 *
 * The Electron launcher calls:
 *   GET /api/launcher_ext.php?uuid=…&key=…&ext=news
 *
 * Xyno validates ownership, looks up the stored api_url + api_key for that
 * extension, calls the client's API server-side (so the api_key never
 * leaves our backend), caches the JSON response on disk for 30s and returns
 * it verbatim to the Electron client.
 *
 * Errors are returned with neutral messages so the client never sees the
 * upstream api_key or internal error strings.
 */

require_once __DIR__ . '/utils.php';

$endpoint = 'launcher_ext';
$ip       = api_client_ip();
api_rate_limit($endpoint, $ip, 240, 60); // 240 req/min/IP (four per second, generous for UI polling)

$uuid   = api_param('uuid', 64);
$key    = api_param('key',  128);
$extKey = strtolower(trim((string)api_param('ext', 64)));

if ($uuid === '' || $key === '' || $extKey === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

// Whitelist check against the catalog — refuses arbitrary ext_key values.
$catalogKeys = array_column(api_extensions_catalog(), 'key');
if (!in_array($extKey, $catalogKeys, true)) {
    api_log($endpoint, $ip, $uuid, 400, 'unknown_ext:' . $extKey);
    api_json(['error' => 'Unknown extension'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null || !api_validate_key($launcher, $key)) {
        api_log($endpoint, $ip, $uuid, 401, 'unauthorized');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $launcherId = (int)$launcher['id'];

    if (!api_check_subscription($launcherId)) {
        api_log($endpoint, $ip, $uuid, 403, 'subscription_inactive');
        api_json(['error' => 'Subscription inactive'], 403);
    }

    $exts = api_get_launcher_extensions($launcherId, true);
    $target = null;
    foreach ($exts as $e) {
        if ($e['key'] === $extKey) { $target = $e; break; }
    }

    if ($target === null || empty($target['enabled'])) {
        api_log($endpoint, $ip, $uuid, 404, 'ext_disabled:' . $extKey);
        api_json(['error' => 'Extension disabled'], 404);
    }

    // Built-in extensions (needs_api=false) — served from our own DB.
    if (empty($target['needs_api'])) {
        $data = api_builtin_extension_payload($launcherId, $extKey, $launcher);
        api_log($endpoint, $ip, $uuid, 200, 'ok_builtin:' . $extKey);
        api_json(['key' => $extKey, 'source' => 'builtin', 'data' => $data], 200);
    }

    $apiUrl = trim((string)($target['api_url'] ?? ''));
    $apiKey = trim((string)($target['api_key'] ?? ''));

    if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl)) {
        api_log($endpoint, $ip, $uuid, 422, 'ext_missing_url:' . $extKey);
        api_json(['error' => 'Extension not configured'], 422);
    }

    // --- 30s disk cache keyed by (launcher, ext_key) ---
    $cacheDir = __DIR__ . '/../uploads/cache/ext';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/' . $launcherId . '_' . preg_replace('/[^a-z0-9_]/', '_', $extKey) . '.json';
    $ttl = 30;

    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            header('X-Cache: HIT');
            api_log($endpoint, $ip, $uuid, 200, 'cache_hit:' . $extKey);
            api_json_header();
            echo $cached;
            exit;
        }
    }

    // --- Upstream call (cURL, 6s timeout) ---
    if (!function_exists('curl_init')) {
        api_log($endpoint, $ip, $uuid, 500, 'curl_missing');
        api_json(['error' => 'Server misconfigured'], 500);
    }

    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'XynoLauncher-Proxy/1.0',
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300) {
        api_log($endpoint, $ip, $uuid, 502, 'upstream_fail:' . $http . ($err !== '' ? ':' . substr($err, 0, 50) : ''));
        api_json(['error' => 'Upstream unreachable', 'upstream_status' => $http], 502);
    }

    // Parse upstream JSON (fall back to raw string if it isn't JSON).
    $decoded = json_decode((string)$body, true);
    $payload = [
        'key'    => $extKey,
        'source' => 'upstream',
        'data'   => $decoded !== null ? $decoded : (string)$body,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    @file_put_contents($cacheFile, (string)$json);
    @chmod($cacheFile, 0644);

    header('X-Cache: MISS');
    api_log($endpoint, $ip, $uuid, 200, 'ok:' . $extKey);
    api_json_header();
    echo $json;
    exit;
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error:' . substr($e->getMessage(), 0, 120));
    api_json(['error' => 'Server error'], 500);
}

/**
 * Payload for built-in extensions (no external API).
 * Each returns a tiny JSON the renderer knows how to display.
 */
function api_builtin_extension_payload(int $launcherId, string $key, array $launcher): array
{
    switch ($key) {
        case 'changelog':
            // Pull latest active version name + created_at.
            try {
                $pdo = db();
                $q = $pdo->prepare('SELECT version_name, created_at FROM launcher_versions WHERE launcher_id = ? AND is_active = 1 ORDER BY created_at DESC LIMIT 1');
                $q->execute([$launcherId]);
                $row = $q->fetch();
                if ($row) {
                    return ['version' => (string)$row['version_name'], 'date' => (string)$row['created_at']];
                }
            } catch (Throwable $e) {}
            return ['version' => (string)($launcher['version'] ?? ''), 'date' => ''];

        case 'modpack':
            return ['loader' => (string)($launcher['loader'] ?? 'fabric'), 'mc_version' => (string)($launcher['version'] ?? '')];

        case 'ram_slider':
            return ['min' => 2, 'max' => 8, 'step' => 1, 'unit' => 'GB'];

        case 'java_manager':
            // Recommandation Mojang : Java 21 pour 1.21, Java 17 pour 1.18-1.20, Java 8 pour <= 1.16.5
            $v = (string)($launcher['version'] ?? '');
            $java = 17;
            if (preg_match('/^1\.(\d+)/', $v, $m)) {
                $minor = (int)$m[1];
                $java = $minor >= 21 ? 21 : ($minor >= 18 ? 17 : ($minor <= 16 ? 8 : 17));
            }
            return ['recommended' => $java, 'auto_download' => true];

        case 'crash_reporter':
            return ['enabled' => true, 'endpoint' => '/api/v2/crash']; // stubbed, the renderer just shows "active"

        case 'analytics':
            return ['enabled' => true]; // the launcher pings /api/launcher.php on startup — that's the signal

        default:
            return ['enabled' => true];
    }
}

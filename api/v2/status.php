<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils.php';

$endpoint = 'v2_status';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 240, 60);

try {
    $ctx = api_v2_require_auth($endpoint, true);
    $launcher = $ctx['launcher'];

    // Optional integrity enforcement (fail-safe when configured).
    $expected = strtolower(trim((string)($launcher['client_integrity_sha256'] ?? '')));
    if ($expected !== '') {
        $body = api_read_json_body();
        $got = '';
        if (isset($body['integrity']) && is_array($body['integrity'])) {
            $got = isset($body['integrity']['asar_sha256']) && is_string($body['integrity']['asar_sha256'])
                ? strtolower(trim($body['integrity']['asar_sha256']))
                : '';
        }
        if ($got === '' || !hash_equals($expected, $got)) {
            api_log($endpoint, $ip, (string)$launcher['uuid'], 403, 'integrity_mismatch');
            api_json(['error' => 'Integrity check failed'], 403);
        }
    }

    $modules = array_keys(api_parse_modules((string)($launcher['modules'] ?? '')));

    $launcherId = (int)($launcher['id'] ?? 0);
    $mods = api_parse_modules((string)($launcher['modules'] ?? ''));
    $news = isset($mods['news']) ? api_get_launcher_news($launcherId, 8) : [];
    $config = api_get_launcher_config($launcherId);

    // Feature-flagged extras introduced by migrations_v3. Helpers degrade
    // gracefully to safe defaults if the tables don't exist yet, so older
    // clients and older databases keep working.
    $branding   = api_get_launcher_branding($launcher);
    $extensions = api_get_launcher_extensions($launcherId, false); // client payload, NO api_key leak
    $auth       = api_get_launcher_auth($launcherId, false);       // client payload, NO api_key leak

    api_log($endpoint, $ip, (string)$launcher['uuid'], 200, 'ok');
    api_json([
        'ok' => true,
        'status' => 'active',
        'server_time' => time(),
        'config' => $config,
        'news' => $news,
        'launcher' => [
            'name' => (string)($launcher['name'] ?? ''),
            'version' => (string)($launcher['version'] ?? ''),
            'loader' => (string)($launcher['loader'] ?? ''),
            'theme' => (string)($launcher['theme'] ?? ''),
            'modules' => $modules,
        ],
        'branding'   => $branding,
        'extensions' => $extensions,
        'auth'       => $auth,
    ], 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, null, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

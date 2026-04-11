<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

$endpoint = 'files';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 240, 60);

$uuid = api_param('uuid', 64);
$key = api_param('key', 128);

if ($uuid === '' || $key === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_launcher');
        api_json(['error' => 'Unauthorized'], 401);
    }

    if (!api_validate_key($launcher, $key)) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_key');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $isActive = api_check_subscription((int)$launcher['id']);
    if (!$isActive) {
        api_touch_last_ping((int)$launcher['id']);
        api_log($endpoint, $ip, $uuid, 403, 'subscription_inactive');
        api_json(['error' => 'Subscription expired'], 403);
    }

    api_touch_last_ping((int)$launcher['id']);

    $launcherLoader = strtolower((string)($launcher['loader'] ?? ''));
    $launcherVersion = (string)($launcher['version'] ?? '');
    $modules = api_parse_modules((string)($launcher['modules'] ?? ''));

    $pdo = db();

    try {
        $stmt = $pdo->prepare('SELECT type, module, mc_version, name, path, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([(int)$launcher['id']]);
        $hasTypedFiles = true;
    } catch (PDOException $e) {
        $raw = $e->getMessage();
        if (stripos($raw, 'unknown column') === false) {
            throw $e;
        }
        // Backward compatibility: old schema without (type/module/mc_version)
        $stmt = $pdo->prepare('SELECT name, path, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([(int)$launcher['id']]);
        $hasTypedFiles = false;
    }

    $files = [];
    while ($row = $stmt->fetch()) {
        $type = $hasTypedFiles ? strtolower((string)($row['type'] ?? '')) : 'asset';
        $module = $hasTypedFiles ? strtolower((string)($row['module'] ?? '')) : '';
        $mcVersion = $hasTypedFiles ? (string)($row['mc_version'] ?? '') : '';

        if (!in_array($type, ['mod', 'config', 'asset', 'version'], true)) {
            continue;
        }

        if ($type === 'mod' && $launcherLoader === 'vanilla') {
            continue;
        }

        if ($type === 'version' && $mcVersion !== '' && $launcherVersion !== '' && $mcVersion !== $launcherVersion) {
            continue;
        }

        // Module-scoped files are only sent when module is enabled.
        if ($module !== '' && !isset($modules[$module])) {
            continue;
        }

        $path = (string)($row['path'] ?? '');
        $publicPath = api_public_url($path);

        $files[] = [
            'name' => (string)$row['name'],
            'type' => $type,
            'path' => $publicPath,
            'hash' => (string)$row['hash'],
            'size' => (int)$row['size'],
        ];
    }

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    api_json(['files' => $files], 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

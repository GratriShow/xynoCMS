<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

$requestPath = api_request_path();
$pathInfo = isset($_SERVER['PATH_INFO']) ? (string)$_SERVER['PATH_INFO'] : '';
$pathInfo = $pathInfo !== '' ? '/' . trim($pathInfo, '/') : '';

$isUpdate = false;
if ($pathInfo === '/update') {
    $isUpdate = true;
} elseif ($requestPath !== '' && preg_match('#/api/launcher/update/?$#', $requestPath)) {
    $isUpdate = true;
}

$endpoint = $isUpdate ? 'launcher_update' : 'launcher';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 120, 60);

$uuid = api_param('uuid', 64);

if ($isUpdate) {
    if (api_method() !== 'GET') {
        api_log($endpoint, $ip, $uuid ?: null, 405, 'method_not_allowed');
        api_json(['error' => 'Method Not Allowed'], 405);
    }

    if ($uuid === '') {
        api_log($endpoint, $ip, null, 400, 'missing_uuid');
        api_json(['error' => 'Missing parameters'], 400);
    }

    try {
        $launcher = api_get_launcher_by_uuid($uuid);
        if ($launcher === null) {
            api_log($endpoint, $ip, $uuid, 401, 'invalid_launcher');
            api_json(['error' => 'Unauthorized'], 401);
        }

        $launcherId = (int)($launcher['id'] ?? 0);
        $rel = api_get_latest_client_release($launcherId);
        if ($rel === null) {
            api_log($endpoint, $ip, $uuid, 200, 'no_release');
            api_json([
                'version' => '',
                'url' => '',
                'signature' => '',
                'required' => false,
            ], 200);
        }

        $version = trim((string)($rel['version'] ?? ''));
        $zipUrl = trim((string)($rel['zip_url'] ?? ''));
        $sig = strtolower(trim((string)($rel['zip_sha256'] ?? '')));
        $required = (bool)($rel['required'] ?? false);

        if ($version === '' || $zipUrl === '' || $sig === '' || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            api_log($endpoint, $ip, $uuid, 500, 'invalid_release_payload');
            api_json(['error' => 'Server error'], 500);
        }

        // HTTPS mandatory: either the stored URL is https://, or we reject.
        if (!preg_match('#^https://#i', $zipUrl)) {
            api_log($endpoint, $ip, $uuid, 500, 'https_required');
            api_json(['error' => 'Server misconfigured'], 500);
        }

        api_log($endpoint, $ip, $uuid, 200, 'ok');
        api_json([
            'version' => $version,
            'url' => $zipUrl,
            'signature' => $sig,
            'required' => $required,
        ], 200);
    } catch (Throwable $e) {
        api_log($endpoint, $ip, $uuid, 500, 'server_error');
        api_json(['error' => 'Server error'], 500);
    }
}

$key = api_param('key', 128);

if ($uuid === '' || $key === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_launcher');
        api_json(['status' => 'inactive', 'message' => 'Unauthorized'], 401);
    }

    if (!api_validate_key($launcher, $key)) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_key');
        api_json(['status' => 'inactive', 'message' => 'Unauthorized'], 401);
    }

    $isActive = api_check_subscription((int)$launcher['id']);
    if (!$isActive) {
        api_touch_last_ping((int)$launcher['id']);
        api_log($endpoint, $ip, $uuid, 200, 'subscription_inactive');
        api_json(['status' => 'inactive', 'message' => 'Subscription expired'], 200);
    }

    api_touch_last_ping((int)$launcher['id']);

    $modules = array_keys(api_parse_modules((string)($launcher['modules'] ?? '')));

    $launcherId = (int)($launcher['id'] ?? 0);
    $mods = api_parse_modules((string)($launcher['modules'] ?? ''));
    $news = isset($mods['news']) ? api_get_launcher_news($launcherId, 8) : [];
    $config = api_get_launcher_config($launcherId);

    $resp = [
        'status' => 'active',
        'name' => (string)$launcher['name'],
        'version' => (string)$launcher['version'],
        'loader' => (string)$launcher['loader'],
        'theme' => (string)$launcher['theme'],
        'modules' => $modules,
        'config' => $config,
        'news' => $news,
    ];

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    api_json($resp, 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

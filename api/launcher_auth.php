<?php

declare(strict_types=1);

/**
 * Custom-auth proxy (mode = "custom" Bearer API).
 *
 * Two actions via query string or JSON body:
 *   ?action=login  → POST to client's login_url with { email, password }
 *   ?action=verify → GET  client's verify_url with Bearer {token}
 *
 * For "microsoft" or "offline" auth modes this endpoint refuses — the
 * Electron client handles those modes directly.
 *
 * The upstream api_key (if configured) is added as X-Api-Key header so the
 * client's middleware can distinguish launcher traffic. Never exposed to
 * the Electron process.
 */

require_once __DIR__ . '/utils.php';

$endpoint = 'launcher_auth';
$ip       = api_client_ip();
api_rate_limit($endpoint, $ip, 30, 60); // 30/min/IP — auth is a sensitive endpoint

if (api_method() !== 'POST') {
    api_json(['error' => 'Method not allowed'], 405);
}

$body = api_read_json_body(8192);

$uuid   = (string)($body['uuid']   ?? api_param('uuid',  64));
$key    = (string)($body['key']    ?? api_param('key',   128));
$action = strtolower(trim((string)($body['action'] ?? api_param('action', 16))));

if ($uuid === '' || $key === '' || $action === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}
if (!in_array($action, ['login', 'verify', 'refresh'], true)) {
    api_log($endpoint, $ip, $uuid, 400, 'bad_action:' . $action);
    api_json(['error' => 'Unknown action'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null || !api_validate_key($launcher, $key)) {
        api_log($endpoint, $ip, $uuid, 401, 'unauthorized');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $launcherId = (int)$launcher['id'];
    $auth = api_get_launcher_auth($launcherId, true);

    if (($auth['mode'] ?? '') !== 'custom') {
        api_log($endpoint, $ip, $uuid, 400, 'mode_not_custom');
        api_json(['error' => 'Custom auth disabled for this launcher'], 400);
    }

    $target = '';
    if     ($action === 'login')   { $target = (string)($auth['login_url']   ?? ''); }
    elseif ($action === 'verify')  { $target = (string)($auth['verify_url']  ?? ''); }
    elseif ($action === 'refresh') { $target = (string)($auth['refresh_url'] ?? ''); }

    if ($target === '' || !preg_match('#^https?://#i', $target)) {
        api_log($endpoint, $ip, $uuid, 422, 'endpoint_missing:' . $action);
        api_json(['error' => 'Endpoint not configured for this action'], 422);
    }

    $apiKey = (string)($auth['api_key'] ?? '');

    // Build cURL request.
    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'X-Api-Key: ' . $apiKey;
    }

    $httpMethod = $action === 'login' ? 'POST' : ($action === 'refresh' ? 'POST' : 'GET');
    $sendBody   = null;

    if ($action === 'login') {
        $email    = trim((string)($body['email']    ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') {
            api_log($endpoint, $ip, $uuid, 400, 'missing_creds');
            api_json(['error' => 'Missing email or password'], 400);
        }
        $sendBody  = json_encode(['email' => $email, 'password' => $password]);
        $headers[] = 'Content-Type: application/json';
    } elseif ($action === 'refresh') {
        $token = (string)($body['token'] ?? '');
        if ($token === '') {
            api_json(['error' => 'Missing token'], 400);
        }
        $sendBody  = json_encode(['token' => $token]);
        $headers[] = 'Content-Type: application/json';
    } elseif ($action === 'verify') {
        $token = (string)($body['token'] ?? '');
        if ($token === '') {
            api_json(['error' => 'Missing token'], 400);
        }
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if (!function_exists('curl_init')) {
        api_log($endpoint, $ip, $uuid, 500, 'curl_missing');
        api_json(['error' => 'Server misconfigured'], 500);
    }

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'XynoLauncher-Auth/1.0',
        CURLOPT_CUSTOMREQUEST  => $httpMethod,
    ]);
    if ($sendBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sendBody);
    }

    $bodyResp = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($bodyResp === false) {
        api_log($endpoint, $ip, $uuid, 502, 'upstream_unreachable:' . substr($err, 0, 60));
        api_json(['error' => 'Upstream unreachable'], 502);
    }

    // Propagate upstream status so the client can distinguish 401 (bad creds) from 500 etc.
    $decoded = json_decode((string)$bodyResp, true);

    if ($http < 200 || $http >= 300) {
        $clientMsg = $action === 'login' ? 'Invalid credentials' : 'Authentication failed';
        api_log($endpoint, $ip, $uuid, $http, 'upstream_error');
        api_json([
            'error'           => $clientMsg,
            'upstream_status' => $http,
            'upstream_body'   => $decoded !== null ? $decoded : (string)$bodyResp,
        ], $http === 401 ? 401 : 502);
    }

    api_log($endpoint, $ip, $uuid, 200, 'ok:' . $action);
    api_json([
        'action' => $action,
        'data'   => $decoded !== null ? $decoded : ['raw' => (string)$bodyResp],
    ], 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error:' . substr($e->getMessage(), 0, 120));
    api_json(['error' => 'Server error'], 500);
}

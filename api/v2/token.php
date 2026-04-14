<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils.php';

$endpoint = 'v2_token';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 120, 60);

if (api_method() !== 'POST') {
    api_log($endpoint, $ip, null, 405, 'method_not_allowed');
    api_json(['error' => 'Method Not Allowed'], 405);
}

try {
    $ctx = api_v2_require_auth($endpoint, true);
    $launcher = $ctx['launcher'];

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

    $playToken = api_jwt_sign([
        'sid' => (string)($ctx['session']['session_id'] ?? ''),
        'lid' => (int)($launcher['id'] ?? 0),
        'uuid' => (string)($launcher['uuid'] ?? ''),
        'scope' => 'play',
    ], 600);

    if ($playToken === '') {
        api_log($endpoint, $ip, (string)$launcher['uuid'], 500, 'jwt_secret_missing');
        api_json(['error' => 'Server misconfigured'], 500);
    }

    api_log($endpoint, $ip, (string)$launcher['uuid'], 200, 'ok');
    api_json([
        'ok' => true,
        'status' => 'active',
        'server_time' => time(),
        'token' => $playToken,
        'expires_in' => 600,
    ], 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, null, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

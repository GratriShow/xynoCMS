<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils.php';

$endpoint = 'v2_session';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 60, 60);

if (api_method() !== 'POST') {
    api_log($endpoint, $ip, null, 405, 'method_not_allowed');
    api_json(['error' => 'Method Not Allowed'], 405);
}

$body = api_read_json_body();
$uuid = isset($body['uuid']) && is_string($body['uuid']) ? trim($body['uuid']) : '';
$ts = isset($body['ts']) ? (int)$body['ts'] : 0;
$nonce = isset($body['nonce']) && is_string($body['nonce']) ? trim($body['nonce']) : '';

$sig = api_header('X-Sig', 256);
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

if ($uuid === '' || strlen($uuid) > 64 || $ts <= 0 || $nonce === '' || strlen($nonce) < 8 || strlen($nonce) > 96 || $sig === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'bad_request');
    api_json(['error' => 'Bad request'], 400);
}

$now = time();
if (abs($now - $ts) > 120) {
    api_log($endpoint, $ip, $uuid, 400, 'ts_skew');
    api_json(['error' => 'Bad request'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_launcher');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $launcherId = (int)$launcher['id'];

    $expected = strtolower(trim((string)($launcher['client_integrity_sha256'] ?? '')));
    if ($expected !== '') {
        $got = '';
        if (isset($body['integrity']) && is_array($body['integrity'])) {
            $got = isset($body['integrity']['asar_sha256']) && is_string($body['integrity']['asar_sha256'])
                ? strtolower(trim($body['integrity']['asar_sha256']))
                : '';
        }
        if ($got === '' || !hash_equals($expected, $got)) {
            api_touch_last_ping($launcherId);
            api_log($endpoint, $ip, $uuid, 403, 'integrity_mismatch');
            api_json(['error' => 'Integrity check failed'], 403);
        }
    }

    // Anti-replay on session creation.
    $bootstrapKey = 'bootstrap:' . $uuid;
    if (!api_nonce_try_use($launcherId, $bootstrapKey, $nonce)) {
        api_log($endpoint, $ip, $uuid, 409, 'replay');
        api_json(['error' => 'Replay detected'], 409);
    }

    $active = api_check_subscription($launcherId);
    if (!$active) {
        api_touch_last_ping($launcherId);
        api_log($endpoint, $ip, $uuid, 403, 'subscription_inactive');
        api_json(['error' => 'Subscription expired'], 403);
    }

    $rawBody = api_read_raw_body();
    $bodyHash = api_sha256_hex($rawBody);

    $base = "SESSION\n" . $uuid . "\n" . $ts . "\n" . $nonce . "\n" . $bodyHash;
    $expected = api_hmac_sha256_hex((string)($launcher['api_key'] ?? ''), $base);
    if (!hash_equals($expected, $sig)) {
        api_log($endpoint, $ip, $uuid, 401, 'bad_hmac');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $sess = api_session_create($launcherId, $ip, $userAgent, 1800);
    if ($sess === null) {
        api_log($endpoint, $ip, $uuid, 500, 'session_create_failed');
        api_json(['error' => 'Server error'], 500);
    }

    $token = api_jwt_sign([
        'sid' => (string)$sess['session_id'],
        'lid' => $launcherId,
        'uuid' => (string)$launcher['uuid'],
        'scope' => 'api',
    ], 600);

    if ($token === '') {
        api_log($endpoint, $ip, $uuid, 500, 'jwt_secret_missing');
        api_json(['error' => 'Server misconfigured'], 500);
    }

    api_touch_last_ping($launcherId);
    api_log($endpoint, $ip, $uuid, 200, 'ok');

    api_json([
        'ok' => true,
        'status' => 'active',
        'server_time' => $now,
        'session' => [
            'id' => (string)$sess['session_id'],
            'secret' => (string)$sess['secret_hex'],
            'expires_at' => (string)$sess['expires_at'],
        ],
        'token' => $token,
    ], 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid ?: null, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

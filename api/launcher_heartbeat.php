<?php

declare(strict_types=1);

/**
 * Launcher heartbeat (observabilité côté admin).
 *
 * Le launcher Electron POST ici toutes les ~30s avec :
 *   { uuid, key, app_version, os, os_version, arch, theme,
 *     uptime_s, tick_rate_ms, session_id, state }
 *
 * Insère une row dans `launcher_heartbeats` et met à jour les colonnes
 * dénormalisées (last_seen_at, last_app_version, last_os) sur `launchers`.
 *
 * Renvoie 204 (No Content) en cas de succès — le launcher n'attend rien.
 *
 * Rate-limit : 6/min/IP (heartbeat normal = 2/min, on tolère un peu plus
 * pour permettre des bursts au démarrage).
 */

require_once __DIR__ . '/utils.php';

$endpoint = 'launcher_heartbeat';
$ip       = api_client_ip();
api_rate_limit($endpoint, $ip, 6, 60);

if (api_method() !== 'POST') {
    api_json(['error' => 'Method not allowed'], 405);
}

$body = api_read_json_body(8192);

$uuid = (string)($body['uuid'] ?? '');
$key  = (string)($body['key']  ?? '');

if ($uuid === '' || $key === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null || !api_validate_key($launcher, $key)) {
        api_log($endpoint, $ip, $uuid, 401, 'unauthorized');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $launcherId = (int)$launcher['id'];
    $userId     = isset($launcher['user_id']) ? (int)$launcher['user_id'] : null;

    // Tolérance large : tous les champs sont optionnels et tronqués.
    $appVersion = trim((string)($body['app_version'] ?? ''));
    $os         = trim((string)($body['os']          ?? ''));
    $osVersion  = trim((string)($body['os_version']  ?? ''));
    $arch       = trim((string)($body['arch']        ?? ''));
    $theme      = trim((string)($body['theme']       ?? ''));
    $sessionId  = trim((string)($body['session_id']  ?? ''));
    $state      = trim((string)($body['state']       ?? ''));

    $uptime    = isset($body['uptime_s'])     ? max(0, (int)$body['uptime_s'])     : null;
    $tickRate  = isset($body['tick_rate_ms']) ? max(0, (int)$body['tick_rate_ms']) : null;

    // Tronquer pour respecter les VARCHAR.
    $appVersion = mb_substr($appVersion, 0, 32);
    $os         = mb_substr($os,         0, 32);
    $osVersion  = mb_substr($osVersion,  0, 64);
    $arch       = mb_substr($arch,       0, 16);
    $theme      = mb_substr($theme,      0, 48);
    $sessionId  = mb_substr($sessionId,  0, 36);
    $state      = mb_substr($state,      0, 32);

    $pdo = db();

    // 1. Insert heartbeat row.
    try {
        $ins = $pdo->prepare(
            'INSERT INTO launcher_heartbeats '
          . '(launcher_id, user_id, app_version, os, os_version, arch, theme, '
          . ' uptime_s, tick_rate_ms, session_id, state, ip, created_at) '
          . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $launcherId, $userId,
            $appVersion ?: null, $os ?: null, $osVersion ?: null,
            $arch ?: null, $theme ?: null,
            $uptime, $tickRate,
            $sessionId ?: null, $state ?: null,
            $ip,
        ]);
    } catch (Throwable $e) {
        // Migration v6 pas appliquée → on continue silencieusement, le
        // launcher ne doit jamais voir d'erreur ici.
        api_log($endpoint, $ip, $uuid, 200, 'heartbeat_skip:no_table');
    }

    // 2. Update denormalized columns on `launchers`. Idempotent et safe.
    try {
        $upd = $pdo->prepare(
            'UPDATE launchers SET '
          . '  last_seen_at = NOW(), '
          . '  last_app_version = COALESCE(?, last_app_version), '
          . '  last_os = COALESCE(?, last_os) '
          . 'WHERE id = ? LIMIT 1'
        );
        $upd->execute([
            $appVersion !== '' ? $appVersion : null,
            $os !== '' ? $os : null,
            $launcherId,
        ]);
    } catch (Throwable $e) {
        // Anciennes colonnes manquantes → ignore.
    }

    // 3. last_ping (champ legacy, déjà existant) — utile si admin n'a pas
    //    encore appliqué la migration v6.
    try {
        $upd = $pdo->prepare('UPDATE launchers SET last_ping = NOW() WHERE id = ? LIMIT 1');
        $upd->execute([$launcherId]);
    } catch (Throwable $e) {}

    api_log($endpoint, $ip, $uuid, 204, 'ok');
    http_response_code(204);
    exit;
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error:' . substr($e->getMessage(), 0, 120));
    api_json(['error' => 'Server error'], 500);
}

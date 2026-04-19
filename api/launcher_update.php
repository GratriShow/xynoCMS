<?php

declare(strict_types=1);

/**
 * GET /api/launcher_update.php?uuid=<uuid>
 *
 * Endpoint dédié (pas de PATH_INFO) pour la vérification des mises à jour
 * client d'un launcher. Retourne :
 *   - {version, url, signature, required}   si une release est active
 *   - {version:"", url:"", signature:"", required:false}  sinon
 *
 * Ce fichier existe pour éviter les soucis de config nginx (PATH_INFO)
 * autour de /api/launcher/update et /api/launcher.php/update.
 */

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

$endpoint = 'launcher_update';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 120, 60);

if (api_method() !== 'GET') {
    api_log($endpoint, $ip, null, 405, 'method_not_allowed');
    api_json(['error' => 'Method Not Allowed'], 405);
}

$uuid = api_param('uuid', 64);
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
            'version'   => '',
            'url'       => '',
            'signature' => '',
            'required'  => false,
        ], 200);
    }

    $version  = trim((string)($rel['version'] ?? ''));
    $zipUrl   = trim((string)($rel['zip_url'] ?? ''));
    $sig      = strtolower(trim((string)($rel['zip_sha256'] ?? '')));
    $required = (bool)($rel['required'] ?? false);

    if ($version === '' || $zipUrl === '' || $sig === '' || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        api_log($endpoint, $ip, $uuid, 500, 'invalid_release_payload');
        api_json(['error' => 'Server error'], 500);
    }

    if (!preg_match('#^https://#i', $zipUrl)) {
        api_log($endpoint, $ip, $uuid, 500, 'https_required');
        api_json(['error' => 'Server misconfigured'], 500);
    }

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    api_json([
        'version'   => $version,
        'url'       => $zipUrl,
        'signature' => $sig,
        'required'  => $required,
    ], 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

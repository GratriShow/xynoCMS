<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * POST /api/build_status.php
 * Header: X-Build-Token
 * Body JSON:
 *   {
 *     "uuid": "...",
 *     "version": "YYYYMMDD-HHMM",
 *     "platform": "win|linux|mac",
 *     "status": "success|failure|cancelled",
 *     "run_url": "https://github.com/..."
 *   }
 *
 * Appelé par GitHub Actions à la fin de chaque job (matrix).
 * On met à jour la ligne launcher_builds pour refléter l'état global.
 */

$endpoint = 'build_status';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 120, 60);

if (api_method() !== 'POST') {
    api_json(['error' => 'Method Not Allowed'], 405);
}

$token = api_header('X-Build-Token', 512);
$expected = api_env('XYNO_BUILD_FETCH_TOKEN', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    api_json(['error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    api_json(['error' => 'invalid_json'], 400);
}

$uuid    = trim((string)($input['uuid'] ?? ''));
$version = trim((string)($input['version'] ?? ''));
$plat    = strtolower(trim((string)($input['platform'] ?? '')));
$status  = strtolower(trim((string)($input['status'] ?? '')));
$runUrl  = trim((string)($input['run_url'] ?? ''));

if (!preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
    api_json(['error' => 'invalid_uuid'], 400);
}
if (!preg_match('/^[0-9A-Za-z._-]{1,64}$/', $version)) {
    api_json(['error' => 'invalid_version'], 400);
}
if (!in_array($plat, ['win', 'linux', 'mac'], true)) {
    api_json(['error' => 'invalid_platform'], 400);
}
if (!in_array($status, ['success', 'failure', 'cancelled', 'skipped'], true)) {
    api_json(['error' => 'invalid_status'], 400);
}

$pdo = db();

try {
    // Récupérer l'état courant
    $sel = $pdo->prepare('SELECT id, status FROM launcher_builds WHERE uuid = ? AND version = ? LIMIT 1');
    $sel->execute([$uuid, $version]);
    $row = $sel->fetch();

    if (!$row) {
        api_json(['error' => 'build_not_found'], 404);
    }

    // Status agrégé : on conserve une liste JSON par plateforme dans le status.
    // Format : "per:win=success;linux=queued;mac=queued"
    // On le met à jour de façon incrémentale.
    $old = (string)$row['status'];
    $map = [];
    if (str_starts_with($old, 'per:')) {
        foreach (explode(';', substr($old, 4)) as $chunk) {
            [$k, $v] = array_pad(explode('=', $chunk, 2), 2, '');
            if ($k !== '') $map[$k] = $v;
        }
    }
    $map[$plat] = $status;

    // Calcule un statut global lisible.
    $all = array_values($map);
    $global = 'in_progress';
    if (count($map) >= 1 && !in_array('queued', $all, true) && !in_array('in_progress', $all, true)) {
        if (in_array('failure', $all, true)) {
            $global = 'failure';
        } elseif (array_unique($all) === ['success']) {
            $global = 'success';
        } else {
            $global = 'partial';
        }
    }

    $encoded = 'per:' . implode(';', array_map(
        fn($k, $v) => "$k=$v",
        array_keys($map),
        $map
    )) . '|global:' . $global;

    $up = $pdo->prepare(
        'UPDATE launcher_builds
            SET status = ?, run_url = COALESCE(NULLIF(?, ""), run_url), updated_at = NOW()
          WHERE id = ?'
    );
    $up->execute([$encoded, $runUrl, (int)$row['id']]);

    api_json([
        'ok' => true,
        'uuid' => $uuid,
        'version' => $version,
        'platform' => $plat,
        'status' => $status,
        'global' => $global,
    ]);
} catch (Throwable $e) {
    error_log('[build_status] ' . $e->getMessage());
    api_json(['error' => 'server_error'], 500);
}

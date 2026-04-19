<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * GET /api/build_status_public.php?uuid=<launcher-uuid>[&version=<version>]
 *
 * Session-authenticated (user must own the launcher).
 * Returns the current state of the latest build (or a specific version) so the
 * dashboard can show a live progress indicator.
 *
 * Response:
 *   {
 *     "uuid": "...",
 *     "version": "YYYYMMDD-HHMM",
 *     "targets": ["windows","linux","mac"],
 *     "per_platform": { "win": "queued", "linux": "success", "mac": "failure" },
 *     "global": "in_progress" | "success" | "failure" | "partial" | "cancelled",
 *     "run_url": "https://github.com/...",
 *     "created_at": "2026-04-19 09:00:00",
 *     "updated_at": "2026-04-19 09:03:21"
 *   }
 */

header('Content-Type: application/json; charset=utf-8');

if (api_method() !== 'GET') {
    api_json(['error' => 'Method Not Allowed'], 405);
}

start_secure_session();
if (!isset($_SESSION['user_id'])) {
    api_json(['error' => 'Unauthorized'], 401);
}
$userId = (int)$_SESSION['user_id'];

api_rate_limit('build_status_public', api_client_ip(), 240, 60);

$uuid = api_param('uuid', 64);
$version = api_param('version', 64);

if ($uuid === '' || !preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
    api_json(['error' => 'invalid_uuid'], 400);
}

$pdo = db();

// Ensure the launcher belongs to this user.
$ownerCheck = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
$ownerCheck->execute([$uuid, $userId]);
if (!$ownerCheck->fetch()) {
    api_json(['error' => 'launcher_not_found_or_forbidden'], 403);
}

try {
    if ($version !== '') {
        if (!preg_match('/^[0-9A-Za-z._-]{1,64}$/', $version)) {
            api_json(['error' => 'invalid_version'], 400);
        }
        $sel = $pdo->prepare(
            'SELECT uuid, version, targets, status, run_url, created_at, updated_at '
            . 'FROM launcher_builds WHERE uuid = ? AND version = ? LIMIT 1'
        );
        $sel->execute([$uuid, $version]);
    } else {
        $sel = $pdo->prepare(
            'SELECT uuid, version, targets, status, run_url, created_at, updated_at '
            . 'FROM launcher_builds WHERE uuid = ? ORDER BY id DESC LIMIT 1'
        );
        $sel->execute([$uuid]);
    }
    $row = $sel->fetch();
} catch (Throwable $e) {
    // Table may not exist yet (no build ever triggered).
    api_json([
        'uuid' => $uuid,
        'version' => '',
        'targets' => [],
        'per_platform' => new stdClass(),
        'global' => 'none',
        'run_url' => '',
        'created_at' => '',
        'updated_at' => '',
    ]);
}

if (!$row) {
    api_json([
        'uuid' => $uuid,
        'version' => '',
        'targets' => [],
        'per_platform' => new stdClass(),
        'global' => 'none',
        'run_url' => '',
        'created_at' => '',
        'updated_at' => '',
    ]);
}

$targets = array_values(array_filter(array_map('trim', explode(',', (string)($row['targets'] ?? '')))));

$rawStatus = (string)($row['status'] ?? '');
$perMap = [];
$global = 'queued';

// Format stocké : "per:win=success;linux=queued|global:in_progress"
if (str_starts_with($rawStatus, 'per:')) {
    $rest = substr($rawStatus, 4);
    $parts = explode('|global:', $rest, 2);
    $perStr = $parts[0];
    $global = $parts[1] ?? 'in_progress';

    foreach (explode(';', $perStr) as $chunk) {
        if ($chunk === '') continue;
        [$k, $v] = array_pad(explode('=', $chunk, 2), 2, '');
        if ($k !== '') $perMap[$k] = $v;
    }
} else {
    // Legacy / very fresh row: status is a plain word like "queued".
    $global = $rawStatus !== '' ? $rawStatus : 'queued';
}

// Normalize target names (workflow uses "windows"/"linux"/"mac" but build_status
// reports "win"/"linux"/"mac"). Keep both spellings for the client.
$targetShort = array_values(array_map(
    fn($t) => $t === 'windows' ? 'win' : $t,
    $targets
));

// Ensure every requested target has a key in per_platform (fill with "queued"
// if the runner hasn't reported yet).
foreach ($targetShort as $t) {
    if (!isset($perMap[$t])) {
        $perMap[$t] = 'queued';
    }
}

api_json([
    'uuid' => (string)($row['uuid'] ?? $uuid),
    'version' => (string)($row['version'] ?? ''),
    'targets' => $targetShort,
    'per_platform' => (object)$perMap,
    'global' => $global,
    'run_url' => (string)($row['run_url'] ?? ''),
    'created_at' => (string)($row['created_at'] ?? ''),
    'updated_at' => (string)($row['updated_at'] ?? ''),
]);

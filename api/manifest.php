<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

$endpoint = 'manifest';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 240, 60);

$uuid = api_param('uuid', 64);
$key = api_param('key', 128);

if ($uuid === '' || $key === '') {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

function manifest_send(string $json, string $etag, int $status = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    http_response_code($status);
    echo $json;
    exit;
}

function manifest_not_modified(string $etag): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    http_response_code(304);
    exit;
}

function manifest_normalize_minecraft_path(string $path): string
{
    $path = trim($path);
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.{1,2}(/|$)#', $path)) {
        return '';
    }

    return $path;
}

function manifest_is_allowed_top_level(string $path): bool
{
    return str_starts_with($path, 'mods/')
        || str_starts_with($path, 'config/')
        || str_starts_with($path, 'assets/')
        || str_starts_with($path, 'versions/');
}

function manifest_build_relative_path(string $type, string $module, string $mcVersion, string $name): string
{
    $type = strtolower(trim($type));
    $module = strtolower(trim($module));
    $mcVersion = trim($mcVersion);
    $name = trim($name);

    $modulePrefix = ($module !== '') ? ($module . '/') : '';

    return match ($type) {
        'mod' => 'mods/' . $name,
        'config' => 'config/' . $modulePrefix . $name,
        'asset' => 'assets/' . $modulePrefix . $name,
        'version' => 'versions/' . (($mcVersion !== '') ? ($mcVersion . '/') : '') . $name,
        default => '',
    };
}

function manifest_theme_slug(string $raw): string
{
    $s = strtolower(trim($raw));
    if ($s === '') {
        return 'default';
    }

    // Convert display names (e.g. "Violet Neon") to folder-friendly slugs.
    $s = preg_replace('/\s+/', '-', $s);
    $s = preg_replace('/[^a-z0-9_-]/', '', (string)$s);
    $s = trim((string)$s, '-_');
    if ($s === '' || strlen($s) > 64) {
        return 'default';
    }
    return $s;
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

    $launcherId = (int)$launcher['id'];
    $pdo = db();

    // Optional: active snapshot (kept only for debug/transition).
    $activeSnapshot = null;
    $activeSnapshotFileCount = 0;
    try {
        $sel = $pdo->prepare('SELECT version_name, manifest_json FROM launcher_versions WHERE launcher_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
        $sel->execute([$launcherId]);
        $versionRow = $sel->fetch();
        if ($versionRow && isset($versionRow['manifest_json'])) {
            $snapshotJson = (string)$versionRow['manifest_json'];
            $decoded = json_decode($snapshotJson, true);
            if (is_array($decoded)) {
                $activeSnapshot = $decoded;
                $snapFiles = $decoded['files'] ?? null;
                if (is_array($snapFiles)) {
                    $activeSnapshotFileCount = count($snapFiles);
                }
            }
        }
    } catch (Throwable $e) {
        // Ignore snapshot issues: manifest must reflect DB state.
        $activeSnapshot = null;
        $activeSnapshotFileCount = 0;
    }

    $launcherLoader = strtolower((string)($launcher['loader'] ?? ''));
    $launcherVersion = (string)($launcher['version'] ?? '');
    $modules = api_parse_modules((string)($launcher['modules'] ?? ''));

    $filtersApplied = [];
    $skipped = [
        'invalid_type' => 0,
        'vanilla_mod' => 0,
        'mc_version_mismatch' => 0,
        'module_disabled' => 0,
        'invalid_path' => 0,
        'forbidden_top_level' => 0,
    ];

    $manifest = [
        'launcher' => [
            'name' => (string)($launcher['name'] ?? ''),
            'version' => (string)($launcher['version'] ?? ''),
            'loader' => strtolower((string)($launcher['loader'] ?? '')),
            'theme' => manifest_theme_slug((string)($launcher['theme'] ?? '')),
        ],
        'file_count' => 0,
        'total_size' => 0,
        'files' => [],
    ];

    // Pull files from DB (source of truth).
    try {
        $stmt = $pdo->prepare('SELECT id, type, module, mc_version, name, relative_path, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([$launcherId]);
        $hasTypedFiles = true;
        $hasRelativePath = true;
    } catch (PDOException $e) {
        $raw = $e->getMessage();
        if (stripos($raw, 'unknown column') === false) {
            throw $e;
        }
        // Backward compatibility: old schema without (type/module/mc_version/relative_path)
        $stmt = $pdo->prepare('SELECT id, name, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([$launcherId]);
        $hasTypedFiles = false;
        $hasRelativePath = false;
    }

    $filesFound = 0;
    while ($row = $stmt->fetch()) {
        $filesFound++;

        $fileId = (int)($row['id'] ?? 0);
        $type = $hasTypedFiles ? strtolower((string)($row['type'] ?? '')) : 'asset';
        $module = $hasTypedFiles ? strtolower((string)($row['module'] ?? '')) : '';
        $mcVersion = $hasTypedFiles ? (string)($row['mc_version'] ?? '') : '';
        $name = (string)($row['name'] ?? '');
        $hash = (string)($row['hash'] ?? '');
        $size = (int)($row['size'] ?? 0);

        if ($fileId <= 0) {
            continue;
        }

        if (!in_array($type, ['mod', 'config', 'asset', 'version'], true)) {
            $skipped['invalid_type']++;
            continue;
        }

        // Valid business filters
        if ($type === 'mod' && $launcherLoader === 'vanilla') {
            $skipped['vanilla_mod']++;
            $filtersApplied['loader'] = 'vanilla';
            continue;
        }

        if ($type === 'version' && $mcVersion !== '' && $launcherVersion !== '' && $mcVersion !== $launcherVersion) {
            $skipped['mc_version_mismatch']++;
            $filtersApplied['mc_version'] = $launcherVersion;
            continue;
        }

        if ($module !== '' && !isset($modules[$module])) {
            $skipped['module_disabled']++;
            $filtersApplied['modules'] = array_keys($modules);
            continue;
        }

        $minecraftPathRaw = $hasRelativePath ? (string)($row['relative_path'] ?? '') : '';
        if ($minecraftPathRaw === '') {
            $minecraftPathRaw = manifest_build_relative_path($type, $module, $mcVersion, $name);
        }

        $minecraftPath = manifest_normalize_minecraft_path($minecraftPathRaw);
        if ($minecraftPath === '') {
            $skipped['invalid_path']++;
            continue;
        }
        if (!manifest_is_allowed_top_level($minecraftPath)) {
            $skipped['forbidden_top_level']++;
            continue;
        }

        $publicPath = api_public_url('/api/file.php?uuid=' . urlencode($uuid) . '&key=' . urlencode($key) . '&id=' . $fileId);

        $manifest['files'][] = [
            'path' => $minecraftPath,
            'hash' => $hash,
            'size' => $size,
            'url' => $publicPath,
        ];
    }

    // Keep deterministic output.
    usort($manifest['files'], fn (array $a, array $b) => strcmp((string)$a['path'], (string)$b['path']));

    $manifest['file_count'] = count($manifest['files']);
    $totalSize = 0;
    foreach ($manifest['files'] as $f) {
        $totalSize += (int)($f['size'] ?? 0);
    }
    $manifest['total_size'] = $totalSize;

    // Temporary debug block (for diagnosis).
    $manifest['debug'] = [
        'launcher_id' => $launcherId,
        'files_found' => $filesFound,
        'files_sent' => $manifest['file_count'],
        'filters_applied' => array_keys($filtersApplied),
        'filters_detail' => $filtersApplied,
        'skipped' => $skipped,
        'snapshot' => [
            'has_active_snapshot' => $activeSnapshot !== null,
            'active_snapshot_files' => $activeSnapshotFileCount,
        ],
        'source' => 'db',
    ];

    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode_failed');
    }

    $etag = '"' . sha1($json) . '"';

    $ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
        api_log($endpoint, $ip, $uuid, 304, 'not_modified');
        manifest_not_modified($etag);
    }

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    manifest_send($json, $etag, 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils.php';

$endpoint = 'v2_manifest';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 240, 60);

function v2_manifest_normalize_minecraft_path(string $path): string
{
    $path = trim($path);
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.{1,2}(/|$)#', $path)) {
        return '';
    }

    return $path;
}

function v2_manifest_is_allowed_top_level(string $path): bool
{
    return str_starts_with($path, 'mods/')
        || str_starts_with($path, 'config/')
        || str_starts_with($path, 'assets/')
        || str_starts_with($path, 'versions/');
}

function v2_manifest_build_relative_path(string $type, string $module, string $mcVersion, string $name): string
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

function v2_manifest_theme_slug(string $raw): string
{
    $s = strtolower(trim($raw));
    if ($s === '') {
        return 'default';
    }

    $s = preg_replace('/\s+/', '-', $s);
    $s = preg_replace('/[^a-z0-9_-]/', '', (string)$s);
    $s = trim((string)$s, '-_');
    if ($s === '' || strlen($s) > 64) {
        return 'default';
    }
    return $s;
}

try {
    $ctx = api_v2_require_auth($endpoint, true);
    $launcher = $ctx['launcher'];
    $launcherId = (int)($launcher['id'] ?? 0);

    $launcherLoader = strtolower((string)($launcher['loader'] ?? ''));
    $launcherVersion = (string)($launcher['version'] ?? '');
    $modules = api_parse_modules((string)($launcher['modules'] ?? ''));

    $pdo = db();

    $manifest = [
        'launcher' => [
            'name' => (string)($launcher['name'] ?? ''),
            'version' => (string)($launcher['version'] ?? ''),
            'loader' => strtolower((string)($launcher['loader'] ?? '')),
            'theme' => v2_manifest_theme_slug((string)($launcher['theme'] ?? '')),
        ],
        'file_count' => 0,
        'total_size' => 0,
        'files' => [],
    ];

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
        $stmt = $pdo->prepare('SELECT id, name, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([$launcherId]);
        $hasTypedFiles = false;
        $hasRelativePath = false;
    }

    while ($row = $stmt->fetch()) {
        $fileId = (int)($row['id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }

        $type = $hasTypedFiles ? strtolower((string)($row['type'] ?? '')) : 'asset';
        $module = $hasTypedFiles ? strtolower((string)($row['module'] ?? '')) : '';
        $mcVersion = $hasTypedFiles ? (string)($row['mc_version'] ?? '') : '';
        $name = (string)($row['name'] ?? '');
        $hash = (string)($row['hash'] ?? '');
        $size = (int)($row['size'] ?? 0);

        if (!in_array($type, ['mod', 'config', 'asset', 'version'], true)) {
            continue;
        }

        if ($type === 'mod' && $launcherLoader === 'vanilla') {
            continue;
        }

        if ($type === 'version' && $mcVersion !== '' && $launcherVersion !== '' && $mcVersion !== $launcherVersion) {
            continue;
        }

        if ($module !== '' && !isset($modules[$module])) {
            continue;
        }

        $minecraftPathRaw = $hasRelativePath ? (string)($row['relative_path'] ?? '') : '';
        if ($minecraftPathRaw === '') {
            $minecraftPathRaw = v2_manifest_build_relative_path($type, $module, $mcVersion, $name);
        }

        $minecraftPath = v2_manifest_normalize_minecraft_path($minecraftPathRaw);
        if ($minecraftPath === '' || !v2_manifest_is_allowed_top_level($minecraftPath)) {
            continue;
        }

        $publicPath = api_public_url('/api/v2/file.php?id=' . $fileId);

        $manifest['files'][] = [
            'id' => $fileId,
            'path' => $minecraftPath,
            'hash' => $hash,
            'size' => $size,
            'url' => $publicPath,
        ];
    }

    usort($manifest['files'], fn (array $a, array $b) => strcmp((string)$a['path'], (string)$b['path']));

    $manifest['file_count'] = count($manifest['files']);
    $totalSize = 0;
    foreach ($manifest['files'] as $f) {
        $totalSize += (int)($f['size'] ?? 0);
    }
    $manifest['total_size'] = $totalSize;

    api_log($endpoint, $ip, (string)($launcher['uuid'] ?? ''), 200, 'ok');
    api_json($manifest, 200);
} catch (Throwable $e) {
    api_log($endpoint, $ip, null, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

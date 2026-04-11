<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    flash_set('error', 'Session expirée. Ré-essaie.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}

function versions_parse_modules(string $modulesCsv): array
{
    $modulesCsv = trim($modulesCsv);
    if ($modulesCsv === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $modulesCsv) as $m) {
        $m = strtolower(trim($m));
        if ($m !== '') {
            $out[$m] = true;
        }
    }
    return $out;
}

function versions_next_name(?string $latest): string
{
    $latest = trim((string)$latest);
    if ($latest === '') {
        return 'v1.0.0';
    }

    if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/i', $latest, $m)) {
        $major = (int)$m[1];
        $minor = (int)$m[2];
        $patch = (int)$m[3];
        $patch++;
        return 'v' . $major . '.' . $minor . '.' . $patch;
    }

    // Fallback when existing naming is custom.
    return 'v1.0.0';
}

function versions_bump_patch(string $versionName): string
{
    if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/i', $versionName, $m)) {
        $major = (int)$m[1];
        $minor = (int)$m[2];
        $patch = (int)$m[3];
        $patch++;
        return 'v' . $major . '.' . $minor . '.' . $patch;
    }
    return 'v1.0.0';
}

function versions_build_manifest_snapshot(PDO $pdo, array $launcherRow): array
{
    $launcherId = (int)($launcherRow['id'] ?? 0);
    if ($launcherId <= 0) {
        throw new RuntimeException('invalid_launcher');
    }

    $launcherName = (string)($launcherRow['name'] ?? '');
    $launcherVersion = (string)($launcherRow['version'] ?? '');
    $launcherLoader = strtolower((string)($launcherRow['loader'] ?? ''));

    $modules = versions_parse_modules((string)($launcherRow['modules'] ?? ''));

    // Prefer new schema (relative_path + version)
    $hasNewSchema = true;
    try {
        $stmt = $pdo->prepare('SELECT id, type, module, mc_version, version, name, relative_path, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([$launcherId]);
    } catch (PDOException $e) {
        $raw = $e->getMessage();
        if (stripos($raw, 'unknown column') === false) {
            throw $e;
        }
        $hasNewSchema = false;

        // Backward compatibility: derive minecraft path on the fly.
        $stmt = $pdo->prepare('SELECT id, type, module, mc_version, name, hash, size FROM files WHERE launcher_id = ? ORDER BY id ASC');
        $stmt->execute([$launcherId]);
    }

    $files = [];
    while ($row = $stmt->fetch()) {
        $type = strtolower((string)($row['type'] ?? 'asset'));
        $module = strtolower((string)($row['module'] ?? ''));
        $mcVersion = (string)($row['mc_version'] ?? '');
        $fileVersion = $hasNewSchema ? (string)($row['version'] ?? '') : '';

        if (!in_array($type, ['mod', 'config', 'asset', 'version'], true)) {
            continue;
        }

        // Loader gates.
        if ($type === 'mod' && $launcherLoader === 'vanilla') {
            continue;
        }

        // Version gates.
        if ($type === 'version' && $mcVersion !== '' && $launcherVersion !== '' && $mcVersion !== $launcherVersion) {
            continue;
        }
        if ($fileVersion !== '' && $launcherVersion !== '' && $fileVersion !== $launcherVersion) {
            continue;
        }

        // Module gates.
        if ($module !== '' && !isset($modules[$module])) {
            continue;
        }

        $minecraftPath = '';
        if ($hasNewSchema) {
            $minecraftPath = (string)($row['relative_path'] ?? '');
        }
        if ($minecraftPath === '') {
            $minecraftPath = minecraft_relative_path($type, (string)($row['name'] ?? ''), $module, $mcVersion);
        }

        $fileId = (int)($row['id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }

        $files[] = [
            'id' => $fileId,
            'path' => $minecraftPath,
            'hash' => (string)($row['hash'] ?? ''),
            'size' => (int)($row['size'] ?? 0),
        ];
    }

    usort($files, fn (array $a, array $b) => strcmp((string)$a['path'], (string)$b['path']));

    return [
        'launcher' => [
            'name' => $launcherName,
            'version' => $launcherVersion,
            'loader' => $launcherLoader,
        ],
        'files' => $files,
    ];
}

try {
    $pdo = db();

    // Ownership check + load config used for gating.
    $sel = $pdo->prepare('SELECT id, uuid, user_id, name, version, loader, modules FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $sel->execute([$launcherUuid, $user['id']]);
    $launcherRow = $sel->fetch();

    if (!$launcherRow) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }

    $manifest = versions_build_manifest_snapshot($pdo, $launcherRow);
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode_failed');
    }

    $pdo->beginTransaction();

    // Compute next version name.
    $latest = null;
    $q = $pdo->prepare('SELECT version_name FROM launcher_versions WHERE launcher_id = ? ORDER BY id DESC LIMIT 1');
    $q->execute([(int)$launcherRow['id']]);
    $latest = $q->fetchColumn();
    if ($latest === false) {
        $latest = null;
    }

    $next = versions_next_name(is_string($latest) ? $latest : null);

    // Ensure uniqueness (avoid collisions if existing names are custom).
    $exists = $pdo->prepare('SELECT 1 FROM launcher_versions WHERE launcher_id = ? AND version_name = ? LIMIT 1');
    for ($i = 0; $i < 50; $i++) {
        $exists->execute([(int)$launcherRow['id'], $next]);
        $hit = $exists->fetchColumn();
        if ($hit === false) {
            break;
        }
        $next = versions_bump_patch($next);
    }

    // Deactivate previous active version(s).
    $off = $pdo->prepare('UPDATE launcher_versions SET is_active = 0 WHERE launcher_id = ? AND is_active = 1');
    $off->execute([(int)$launcherRow['id']]);

    $ins = $pdo->prepare('INSERT INTO launcher_versions (launcher_id, version_name, manifest_json, changelog, is_active, created_at) VALUES (?, ?, ?, NULL, 1, NOW())');
    $ins->execute([(int)$launcherRow['id'], $next, $json]);

    $pdo->commit();

    flash_set('success', 'Version publiée : ' . $next);
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#versions');
} catch (Throwable $e) {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e2) {
    }

    flash_set('error', 'Impossible de publier la version.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#versions');
}

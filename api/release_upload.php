<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

$endpoint = 'release_upload';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 30, 60);

if (api_method() !== 'POST') {
    api_log($endpoint, $ip, null, 405, 'method_not_allowed');
    api_json(['error' => 'Method Not Allowed'], 405);
}

$token = api_header('X-Upload-Token', 512);
$expected = api_env('XYNO_RELEASE_UPLOAD_TOKEN', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    api_log($endpoint, $ip, null, 401, 'unauthorized');
    api_json(['error' => 'Unauthorized'], 401);
}

$uuid = api_param('uuid', 64);
$type = strtolower(trim((string)($_POST['type'] ?? '')));
$platform = strtolower(trim((string)($_POST['platform'] ?? '')));
$version = trim((string)($_POST['version'] ?? ''));
$required = (int)($_POST['required'] ?? 0) ? 1 : 0;
$isActive = (int)($_POST['active'] ?? 1) ? 1 : 0;

if ($uuid === '' || $version === '' || ($type !== 'update' && $type !== 'installer')) {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

if (!preg_match('/^[0-9A-Za-z._-]{1,64}$/', $version)) {
    api_log($endpoint, $ip, $uuid, 400, 'invalid_version');
    api_json(['error' => 'Invalid version'], 400);
}

if ($type === 'installer' && !in_array($platform, ['win', 'mac', 'linux'], true)) {
    api_log($endpoint, $ip, $uuid, 400, 'invalid_platform');
    api_json(['error' => 'Invalid platform'], 400);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    api_log($endpoint, $ip, $uuid, 400, 'missing_file');
    api_json(['error' => 'Missing file'], 400);
}

$f = $_FILES['file'];
$err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    api_log($endpoint, $ip, $uuid, 400, 'upload_error_' . $err);
    api_json(['error' => 'Upload failed'], 400);
}

$tmpPath = (string)($f['tmp_name'] ?? '');
$origName = (string)($f['name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    api_log($endpoint, $ip, $uuid, 400, 'invalid_tmp');
    api_json(['error' => 'Upload failed'], 400);
}

$maxBytes = 800 * 1024 * 1024; // 800MB
$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
    api_log($endpoint, $ip, $uuid, 400, 'file_too_large');
    api_json(['error' => 'File too large'], 400);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        api_log($endpoint, $ip, $uuid, 404, 'launcher_not_found');
        api_json(['error' => 'Launcher not found'], 404);
    }

    $launcherId = (int)($launcher['id'] ?? 0);
    if ($launcherId <= 0) {
        api_log($endpoint, $ip, $uuid, 500, 'invalid_launcher_id');
        api_json(['error' => 'Server error'], 500);
    }

    // We enforce HTTPS URLs for distribution.
    $baseUrl = api_public_base_url();
    if (!preg_match('#^https://#i', $baseUrl)) {
        api_log($endpoint, $ip, $uuid, 500, 'https_required');
        api_json(['error' => 'HTTPS required'], 500);
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($type === 'update') {
        if ($ext !== 'zip') {
            api_log($endpoint, $ip, $uuid, 400, 'update_must_be_zip');
            api_json(['error' => 'Update must be a .zip'], 400);
        }
    } else {
        // installer
        $allowed = ['exe', 'dmg', 'appimage'];
        if (!in_array($ext, $allowed, true)) {
            api_log($endpoint, $ip, $uuid, 400, 'invalid_installer_ext');
            api_json(['error' => 'Invalid installer file'], 400);
        }
    }

    $subdir = $type === 'update'
        ? "files/{$uuid}/client/update"
        : "files/{$uuid}/client/installer/{$platform}";

    $publicRel = $type === 'update'
        ? "/{$subdir}/update-{$version}.zip"
        : "/{$subdir}/installer-{$platform}-{$version}.{$ext}";

    $destAbsDir = rtrim((string)realpath(__DIR__ . '/..'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($destAbsDir)) {
        mkdir($destAbsDir, 0755, true);
    }

    $destAbsPath = rtrim($destAbsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($publicRel);

    // Move to final path
    if (!move_uploaded_file($tmpPath, $destAbsPath)) {
        api_log($endpoint, $ip, $uuid, 500, 'move_failed');
        api_json(['error' => 'Upload failed'], 500);
    }

    $sha = strtolower((string)hash_file('sha256', $destAbsPath));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
        @unlink($destAbsPath);
        api_log($endpoint, $ip, $uuid, 500, 'sha_failed');
        api_json(['error' => 'Server error'], 500);
    }

    $fileUrl = api_public_url($publicRel);

    $pdo = db();
    $pdo->beginTransaction();

    if ($type === 'update') {
        if ($isActive) {
            $pdo->prepare('UPDATE launcher_client_releases SET is_active = 0 WHERE launcher_id = ?')->execute([$launcherId]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO launcher_client_releases (launcher_id, version_name, zip_url, zip_sha256, required, is_active, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE zip_url=VALUES(zip_url), zip_sha256=VALUES(zip_sha256), required=VALUES(required), is_active=VALUES(is_active)'
        );
        $ins->execute([$launcherId, $version, $fileUrl, $sha, $required, $isActive]);
    } else {
        if ($isActive) {
            $pdo->prepare('UPDATE launcher_downloads SET is_active = 0 WHERE launcher_id = ? AND platform = ?')->execute([$launcherId, $platform]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO launcher_downloads (launcher_id, platform, version_name, file_url, file_sha256, is_active, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE file_url=VALUES(file_url), file_sha256=VALUES(file_sha256), is_active=VALUES(is_active)'
        );
        $ins->execute([$launcherId, $platform, $version, $fileUrl, $sha, $isActive]);
    }

    $pdo->commit();

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    api_json([
        'ok' => true,
        'uuid' => $uuid,
        'type' => $type,
        'platform' => $type === 'installer' ? $platform : '',
        'version' => $version,
        'url' => $fileUrl,
        'sha256' => $sha,
        'active' => (bool)$isActive,
        'required' => (bool)$required,
    ], 200);
} catch (Throwable $e) {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e2) {
    }

    api_log($endpoint, $ip, $uuid ?: null, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

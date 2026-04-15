<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

$token = trim((string)($_POST['token'] ?? ''));
if ($token === '') {
    $token = api_header('X-Build-Token', 512);
}
$uuid = $_POST['uuid'] ?? '';

$expected = trim(api_env('XYNO_BUILD_TRIGGER_TOKEN', ''));
if ($expected === '') {
    api_json(['ok' => false, 'error' => 'forbidden_server_token_not_configured'], 403);
}

if (!hash_equals($expected, $token)) {
    api_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$uuid = trim((string)$uuid);
if (!preg_match('/^[a-f0-9-]+$/', $uuid)) {
    api_json(['ok' => false, 'error' => 'invalid_uuid'], 400);
}

if (!isset($_FILES['file'])) {
    api_json(['ok' => false, 'error' => 'no_file_uploaded'], 400);
}

$file = $_FILES['file'];

// sécurité basique
if ($file['error'] !== UPLOAD_ERR_OK) {
    api_json(['ok' => false, 'error' => 'upload_error'], 500);
}

// extension check
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'dmg') {
    api_json(['ok' => false, 'error' => 'invalid_file_type'], 400);
}

// destination
$dir = __DIR__ . "/../files/$uuid/client/installer/mac/";
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$version = date('YmdHis');
$filename = "installer-mac-$version.dmg";
$path = $dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $path)) {
    api_json(['ok' => false, 'error' => 'failed_to_save_file'], 500);
}

// Basic DMG sanity check (UDIF footer contains "koly" in the last 512 bytes).
$fp = @fopen($path, 'rb');
if ($fp === false) {
    @unlink($path);
    api_json(['ok' => false, 'error' => 'failed_to_read_file'], 500);
}
$size = @filesize($path);
if ($size === false || $size < 1024) {
    fclose($fp);
    @unlink($path);
    api_json(['ok' => false, 'error' => 'invalid_dmg_too_small'], 400);
}
if (@fseek($fp, -512, SEEK_END) !== 0) {
    fclose($fp);
    @unlink($path);
    api_json(['ok' => false, 'error' => 'invalid_dmg'], 400);
}
$tail = (string)@fread($fp, 512);
fclose($fp);
if ($tail === '' || strpos($tail, 'koly') === false) {
    @unlink($path);
    api_json(['ok' => false, 'error' => 'invalid_dmg_signature_missing'], 400);
}

// hash
$sha = hash_file('sha256', $path);

// URL publique
$url = api_public_url("/files/$uuid/client/installer/mac/$filename");

// DB (comme ton build local)
$pdo = db();

// désactiver anciens
$pdo->prepare("UPDATE launcher_downloads SET is_active = 0 WHERE platform = 'mac' AND launcher_id = (SELECT id FROM launchers WHERE uuid = ?)")->execute([$uuid]);

// insert nouveau
$pdo->prepare("
    INSERT INTO launcher_downloads (launcher_id, platform, version_name, file_url, file_sha256, is_active, created_at)
    VALUES (
        (SELECT id FROM launchers WHERE uuid = ?),
        'mac',
        ?, ?, ?, 1, NOW()
    )
")->execute([$uuid, $version, $url, $sha]);

api_json([
    'ok' => true,
    'url' => $url,
    'sha256' => $sha,
    'bytes' => (int)$size,
    'filename' => $filename,
]);

<?php

require_once __DIR__ . '/../config/bootstrap.php';

$token = $_POST['token'] ?? '';
$uuid = $_POST['uuid'] ?? '';

if ($token !== api_env('XYNO_BUILD_TRIGGER_TOKEN', '')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!preg_match('/^[a-f0-9-]+$/', $uuid)) {
    http_response_code(400);
    exit('Invalid UUID');
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    exit('No file uploaded');
}

$file = $_FILES['file'];

// sécurité basique
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(500);
    exit('Upload error');
}

// extension check
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'dmg') {
    http_response_code(400);
    exit('Invalid file type');
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
    http_response_code(500);
    exit('Failed to save file');
}

// Basic DMG sanity check (UDIF footer contains "koly" in the last 512 bytes).
$fp = @fopen($path, 'rb');
if ($fp === false) {
    @unlink($path);
    http_response_code(500);
    exit('Failed to read file');
}
$size = @filesize($path);
if ($size === false || $size < 1024) {
    fclose($fp);
    @unlink($path);
    http_response_code(400);
    exit('Invalid DMG (too small)');
}
if (@fseek($fp, -512, SEEK_END) !== 0) {
    fclose($fp);
    @unlink($path);
    http_response_code(400);
    exit('Invalid DMG');
}
$tail = (string)@fread($fp, 512);
fclose($fp);
if ($tail === '' || strpos($tail, 'koly') === false) {
    @unlink($path);
    http_response_code(400);
    exit('Invalid DMG (signature missing)');
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

echo json_encode([
    'ok' => true,
    'url' => $url,
    'sha256' => $sha,
    'bytes' => (int)$size,
    'filename' => $filename
]);

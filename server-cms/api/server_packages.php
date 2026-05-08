<?php

/**
 * XynoServer — Gestion des plugins/mods d'un serveur
 *
 * POST   /server-cms/api/server_packages.php   → ajouter plugin ou mod
 * DELETE /server-cms/api/server_packages.php   → retirer plugin ou mod
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = current_user();
if ($user === null) json_response(['ok' => false, 'error' => 'Non authentifié'], 401);

$pdo    = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$raw  = (string)file_get_contents('php://input');
$body = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}
if (empty($body)) $body = $_POST;

// CSRF
$token = (string)($body['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify($token)) json_response(['ok' => false, 'error' => 'CSRF invalide'], 403);

$serverUuid = trim((string)($body['server_uuid'] ?? $_GET['server_uuid'] ?? ''));
if ($serverUuid === '') json_response(['ok' => false, 'error' => 'server_uuid requis'], 400);

$server = get_user_server($pdo, $user['id'], $serverUuid);
if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

$packageType = trim((string)($body['package_type'] ?? '')); // plugin | mod
if (!in_array($packageType, ['plugin', 'mod'], true)) {
    json_response(['ok' => false, 'error' => 'package_type doit être "plugin" ou "mod"'], 422);
}

$table = $packageType === 'plugin' ? 'mc_server_plugins' : 'mc_server_mods';

// ─────────────────────────────────────────────────────────────────────────
// POST — ajouter
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $name       = trim((string)($body['name'] ?? ''));
    $version    = trim((string)($body['version'] ?? ''));
    $source     = trim((string)($body['source'] ?? 'modrinth'));
    $externalId = trim((string)($body['external_id'] ?? ''));
    $slug       = trim((string)($body['slug'] ?? ''));
    $fileUrl    = trim((string)($body['file_url'] ?? ''));
    $fileName   = trim((string)($body['file_name'] ?? ''));
    $fileSize   = max(0, (int)($body['file_size'] ?? 0));
    $fileHash   = trim((string)($body['file_hash'] ?? ''));

    if ($name === '') json_response(['ok' => false, 'error' => 'name requis'], 422);
    if ($version === '') json_response(['ok' => false, 'error' => 'version requis'], 422);

    $validSources = $packageType === 'plugin'
        ? ['modrinth', 'hangar', 'manual']
        : ['modrinth', 'curseforge', 'manual'];
    if (!in_array($source, $validSources, true)) $source = 'manual';

    $stmt = $pdo->prepare(
        "INSERT INTO `{$table}` "
        . '(server_id, source, external_id, slug, name, version, file_url, file_name, file_size, file_hash) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE '
        . 'version = VALUES(version), file_url = VALUES(file_url), '
        . 'file_name = VALUES(file_name), file_size = VALUES(file_size), file_hash = VALUES(file_hash)'
    );
    $stmt->execute([
        $server['id'],
        $source,
        $externalId ?: null,
        $slug ?: null,
        $name,
        $version,
        $fileUrl ?: null,
        $fileName ?: null,
        $fileSize,
        $fileHash ?: null,
    ]);

    $id = (int)$pdo->lastInsertId();
    $row = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
    $row->execute([$id ?: $server['id']]);

    json_response(['ok' => true, 'package' => $row->fetch()], 201);
}

// ─────────────────────────────────────────────────────────────────────────
// DELETE — retirer
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $packageId = (int)($body['package_id'] ?? $_GET['package_id'] ?? 0);
    if ($packageId <= 0) json_response(['ok' => false, 'error' => 'package_id requis'], 400);

    $del = $pdo->prepare("DELETE FROM `{$table}` WHERE id = ? AND server_id = ?");
    $del->execute([$packageId, $server['id']]);

    if ($del->rowCount() === 0) {
        json_response(['ok' => false, 'error' => 'Package introuvable'], 404);
    }

    json_response(['ok' => true, 'deleted' => $packageId]);
}

json_response(['ok' => false, 'error' => 'Méthode non supportée'], 405);

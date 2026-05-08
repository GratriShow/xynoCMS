<?php

/**
 * XynoServer — Versions d'un projet Modrinth
 * GET /server-cms/api/modrinth_versions.php?project_id=...&mc_version=...&loader=...
 *
 * Retourne les versions disponibles pour un projet Modrinth donné,
 * filtrées par version MC et loader.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');

$projectId = trim((string)($_GET['project_id'] ?? ''));
$mcVersion = trim((string)($_GET['mc_version'] ?? ''));
$loader    = trim((string)($_GET['loader'] ?? ''));

if ($projectId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'project_id requis']);
    exit;
}

$params = ['loaders' => json_encode(array_filter([$loader]))];
if ($mcVersion !== '') {
    $params['game_versions'] = json_encode([$mcVersion]);
}

$url = 'https://api.modrinth.com/v2/project/' . urlencode($projectId) . '/version?' . http_build_query($params);
$res = http_get($url);

if ($res['status'] !== 200) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Modrinth API indisponible']);
    exit;
}

$data = json_decode($res['body'], true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Réponse invalide']);
    exit;
}

$versions = [];
foreach (array_slice($data, 0, 10) as $v) {
    $primaryFile = null;
    foreach (($v['files'] ?? []) as $f) {
        if ($f['primary'] ?? false) {
            $primaryFile = $f;
            break;
        }
    }
    if ($primaryFile === null && !empty($v['files'])) {
        $primaryFile = $v['files'][0];
    }

    $versions[] = [
        'id'             => $v['id'] ?? '',
        'name'           => $v['name'] ?? '',
        'version_number' => $v['version_number'] ?? '',
        'game_versions'  => $v['game_versions'] ?? [],
        'loaders'        => $v['loaders'] ?? [],
        'date_published' => $v['date_published'] ?? '',
        'downloads'      => $v['downloads'] ?? 0,
        'file' => $primaryFile ? [
            'url'      => $primaryFile['url'] ?? '',
            'filename' => $primaryFile['filename'] ?? '',
            'size'     => $primaryFile['size'] ?? 0,
            'hash'     => $primaryFile['hashes']['sha1'] ?? '',
        ] : null,
    ];
}

echo json_encode(['ok' => true, 'versions' => $versions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

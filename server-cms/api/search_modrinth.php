<?php

/**
 * XynoServer — Proxy API Modrinth
 * GET /server-cms/api/search_modrinth.php
 *
 * Paramètres :
 *   q          : terme de recherche
 *   type       : "plugin" | "mod" (mappe sur facets Modrinth)
 *   mc_version : ex "1.20.4" (optionnel)
 *   loader     : paper|spigot|forge|fabric|vanilla (optionnel)
 *   limit      : 10..50 (défaut 20)
 *   offset     : pagination (défaut 0)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');

$q         = trim((string)($_GET['q'] ?? ''));
$type      = trim((string)($_GET['type'] ?? 'mod'));  // plugin | mod
$mcVersion = trim((string)($_GET['mc_version'] ?? ''));
$loader    = trim((string)($_GET['loader'] ?? ''));
$limit     = max(5, min(50, (int)($_GET['limit'] ?? 20)));
$offset    = max(0, (int)($_GET['offset'] ?? 0));

// ── Construire les facets Modrinth ─────────────────────────────────────────

$facets = [];

// Type de projet
if ($type === 'plugin') {
    $facets[] = '["project_type:plugin"]';
} else {
    $facets[] = '["project_type:mod"]';
}

// Loader
$loaderMap = [
    'paper'   => 'paper',
    'spigot'  => 'spigot',
    'forge'   => 'forge',
    'fabric'  => 'fabric',
    'vanilla' => 'vanilla',
];
if (isset($loaderMap[$loader])) {
    $facets[] = '["categories:' . $loaderMap[$loader] . '"]';
} elseif ($type === 'plugin') {
    // Par défaut pour les plugins : paper ou spigot
    $facets[] = '["categories:paper","categories:spigot","categories:bukkit"]';
}

// Version MC
if ($mcVersion !== '' && preg_match('/^[\d.]+$/', $mcVersion)) {
    $facets[] = '["versions:' . $mcVersion . '"]';
}

$facetsStr = '[' . implode(',', $facets) . ']';

// ── Requête Modrinth ───────────────────────────────────────────────────────

$params = http_build_query([
    'query'  => $q,
    'facets' => $facetsStr,
    'limit'  => $limit,
    'offset' => $offset,
    'index'  => 'relevance',
]);

$url = 'https://api.modrinth.com/v2/search?' . $params;
$res = http_get($url, ['Accept: application/json']);

if ($res['status'] !== 200) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Modrinth API indisponible', 'status' => $res['status']]);
    exit;
}

$data = json_decode($res['body'], true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Réponse Modrinth invalide']);
    exit;
}

// ── Normaliser les résultats ───────────────────────────────────────────────

$hits = [];
foreach (($data['hits'] ?? []) as $hit) {
    $hits[] = [
        'id'            => $hit['project_id'] ?? $hit['slug'] ?? '',
        'slug'          => $hit['slug'] ?? '',
        'name'          => $hit['title'] ?? '',
        'description'   => $hit['description'] ?? '',
        'downloads'     => $hit['downloads'] ?? 0,
        'icon_url'      => $hit['icon_url'] ?? null,
        'categories'    => $hit['categories'] ?? [],
        'versions'      => $hit['versions'] ?? [],
        'project_type'  => $hit['project_type'] ?? '',
        'source_url'    => 'https://modrinth.com/project/' . ($hit['slug'] ?? ''),
    ];
}

echo json_encode([
    'ok'         => true,
    'total'      => $data['total_hits'] ?? count($hits),
    'offset'     => $data['offset'] ?? $offset,
    'limit'      => $limit,
    'hits'       => $hits,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

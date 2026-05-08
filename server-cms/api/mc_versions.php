<?php

/**
 * XynoServer — API Versions Minecraft
 * GET /server-cms/api/mc_versions.php?type=vanilla|paper|forge|fabric&mc_version=1.20.4
 *
 * Retourne la liste des versions MC disponibles, ou les builds/loaders
 * d'un type spécifique.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$type = trim((string)($_GET['type'] ?? 'vanilla'));
$mcVersion = trim((string)($_GET['mc_version'] ?? ''));

try {
    $result = match ($type) {
        'vanilla' => fetch_vanilla_versions(),
        'paper'   => $mcVersion ? fetch_paper_builds($mcVersion) : fetch_paper_versions(),
        'spigot'  => fetch_spigot_versions(),
        'forge'   => $mcVersion ? fetch_forge_builds($mcVersion) : fetch_forge_versions(),
        'fabric'  => fetch_fabric_versions($mcVersion),
        default   => throw new InvalidArgumentException('Type invalide'),
    };

    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────
// Fonctions de fetch

function fetch_vanilla_versions(): array
{
    $res = http_get('https://launchermeta.mojang.com/mc/game/version_manifest_v2.json');
    if ($res['status'] !== 200) throw new RuntimeException('Mojang API indisponible');

    $data = json_decode($res['body'], true);
    if (!is_array($data)) throw new RuntimeException('Réponse Mojang invalide');

    $versions = [];
    foreach (($data['versions'] ?? []) as $v) {
        if (($v['type'] ?? '') === 'release') {
            $versions[] = [
                'id'           => $v['id'],
                'release_time' => $v['releaseTime'] ?? '',
                'type'         => 'release',
                'url'          => $v['url'] ?? '',
            ];
        }
    }

    return array_slice($versions, 0, 40); // dernières 40 releases
}

function fetch_paper_versions(): array
{
    $res = http_get('https://api.papermc.io/v2/projects/paper');
    if ($res['status'] !== 200) throw new RuntimeException('PaperMC API indisponible');

    $data = json_decode($res['body'], true);
    if (!is_array($data)) throw new RuntimeException('Réponse Paper invalide');

    $versions = array_reverse($data['versions'] ?? []);
    return array_slice($versions, 0, 30);
}

function fetch_paper_builds(string $mcVersion): array
{
    $v = urlencode($mcVersion);
    $res = http_get("https://api.papermc.io/v2/projects/paper/versions/{$v}");
    if ($res['status'] !== 200) return ['builds' => []];

    $data = json_decode($res['body'], true);
    $builds = array_reverse($data['builds'] ?? []);

    // Retourner les 10 derniers builds
    return ['builds' => array_slice($builds, 0, 10)];
}

function fetch_spigot_versions(): array
{
    // SpigotMC n'a pas d'API publique JSON officielle.
    // On renvoie une liste statique des versions supportées.
    return [
        '1.21.4', '1.21.3', '1.21.2', '1.21.1', '1.21',
        '1.20.6', '1.20.4', '1.20.2', '1.20.1', '1.20',
        '1.19.4', '1.19.3', '1.19.2', '1.19.1', '1.19',
        '1.18.2', '1.18.1', '1.18',
        '1.17.1', '1.17',
        '1.16.5', '1.16.4', '1.16.3',
    ];
}

function fetch_forge_versions(): array
{
    // Forge Maven metadata
    $res = http_get('https://files.minecraftforge.net/net/minecraftforge/forge/maven-metadata.json');
    if ($res['status'] !== 200) {
        // Fallback statique si l'API est down
        return [
            '1.21.4', '1.21.3', '1.21.1', '1.20.1', '1.19.4',
            '1.18.2', '1.17.1', '1.16.5', '1.12.2',
        ];
    }

    $data = json_decode($res['body'], true);
    if (!is_array($data)) return [];

    // Les clés sont les versions MC
    $versions = array_keys($data);
    rsort($versions);
    return array_slice($versions, 0, 30);
}

function fetch_forge_builds(string $mcVersion): array
{
    $res = http_get('https://files.minecraftforge.net/net/minecraftforge/forge/maven-metadata.json');
    if ($res['status'] !== 200) return ['builds' => []];

    $data = json_decode($res['body'], true);
    if (!is_array($data)) return ['builds' => []];

    $builds = $data[$mcVersion] ?? [];
    rsort($builds);
    return ['builds' => array_slice($builds, 0, 10)];
}

function fetch_fabric_versions(string $mcVersion = ''): array
{
    // Fabric Loader versions
    $loaderRes = http_get('https://meta.fabricmc.net/v2/versions/loader');
    $loaders = [];
    if ($loaderRes['status'] === 200) {
        $loaderData = json_decode($loaderRes['body'], true);
        foreach (($loaderData ?? []) as $l) {
            if (($l['stable'] ?? false)) {
                $loaders[] = $l['version'] ?? '';
            }
        }
    }
    $loaders = array_slice($loaders, 0, 10);

    // Versions MC supportées par Fabric
    $mcRes = http_get('https://meta.fabricmc.net/v2/versions/game');
    $mcVersions = [];
    if ($mcRes['status'] === 200) {
        $mcData = json_decode($mcRes['body'], true);
        foreach (($mcData ?? []) as $v) {
            if (($v['stable'] ?? false)) {
                $mcVersions[] = $v['version'] ?? '';
            }
        }
    }
    $mcVersions = array_slice($mcVersions, 0, 30);

    return [
        'mc_versions'    => $mcVersions,
        'loader_versions' => $loaders,
    ];
}

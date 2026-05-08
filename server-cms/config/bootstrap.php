<?php

declare(strict_types=1);

/**
 * XynoServer CMS — Bootstrap
 * Réutilise la DB et l'auth de xynoCMS parent.
 */
require_once __DIR__ . '/../../config/bootstrap.php';

// ── Constantes server-cms ──────────────────────────────────────────────────

define('SERVER_CMS_ROOT', dirname(__DIR__));
define('SERVER_CMS_VERSION', '1.0.0');

// URL de base du server-cms (ex: /server-cms)
function server_cms_base(): string
{
    return base_path() . '/server-cms';
}

// ── Générateur d'API key unique ────────────────────────────────────────────
function generate_api_key(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

// ── Types de serveurs supportés ────────────────────────────────────────────
function mc_server_types(): array
{
    return [
        'vanilla' => 'Vanilla',
        'paper'   => 'Paper',
        'spigot'  => 'Spigot',
        'forge'   => 'Forge',
        'fabric'  => 'Fabric',
    ];
}

// ── Vérification appartenance serveur ──────────────────────────────────────
function get_user_server(PDO $pdo, int $userId, string $serverUuid): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM mc_servers WHERE uuid = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$serverUuid, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Réponse JSON helper ────────────────────────────────────────────────────
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Vérification API key serveur (pour bridge launcher) ───────────────────
function get_server_by_api_key(PDO $pdo, string $apiKey): ?array
{
    if (strlen($apiKey) < 32) return null;
    $stmt = $pdo->prepare('SELECT * FROM mc_servers WHERE api_key = ? LIMIT 1');
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Fetch HTTP simple (pour APIs externes) ────────────────────────────────
function http_get(string $url, array $headers = []): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", array_merge([
                'User-Agent: XynoServerCMS/1.0',
                'Accept: application/json',
            ], $headers)),
            'timeout'         => 8,
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $code = 0;

    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $m)) {
                $code = (int)$m[1];
            }
        }
    }

    return [
        'status' => $code,
        'body'   => $body !== false ? $body : '',
    ];
}

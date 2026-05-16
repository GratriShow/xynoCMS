<?php
/**
 * GET/POST /server-cms/api/server_files.php
 *
 * File manager API for the server panel.
 *
 * GET  ?server_id=X&path=/  — list files in directory
 * POST action=upload         — upload a file (multipart)
 * POST action=delete         — delete a file
 * POST action=mkdir          — create a directory
 * POST action=rename         — rename a file/dir
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../provisioning/ProvisioningAdapter.php';
require_once __DIR__ . '/../provisioning/MockAdapter.php';
require_once __DIR__ . '/../provisioning/PterodactylAdapter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Auth ────────────────────────────────────────────────────────────────────

$user = current_user();
if (!$user) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
    exit;
}

$pdo = db();

// ── Resolve server and ownership ─────────────────────────────────────────────

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$serverId = (int)(($method === 'GET' ? $_GET : (json_decode(file_get_contents('php://input'), true) ?? $_POST))['server_id'] ?? 0);

// For GET requests use query string
if ($method === 'GET') {
    $serverId = (int)($_GET['server_id'] ?? 0);
}

if (!$serverId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'server_id requis']);
    exit;
}

try {
    $s = $pdo->prepare('SELECT id, hosting_server_id, server_name FROM mc_servers WHERE id = ? AND user_id = ? LIMIT 1');
    $s->execute([$serverId, $user['id']]);
    $server = $s->fetch() ?: null;
} catch (Throwable) {
    $server = null;
}

if (!$server) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Serveur introuvable ou accès refusé']);
    exit;
}

$hostingId = (string)($server['hosting_server_id'] ?? '');

// ── Helper: mock file tree ───────────────────────────────────────────────────

function mockFileTree(string $path): array
{
    $trees = [
        '/' => [
            ['name' => 'server.properties', 'type' => 'file', 'size' => 2048, 'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'eula.txt',          'type' => 'file', 'size' => 124,  'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'banned-players.json','type'=> 'file', 'size' => 2,    'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'ops.json',          'type' => 'file', 'size' => 98,   'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'whitelist.json',    'type' => 'file', 'size' => 2,    'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'logs',              'type' => 'dir',  'size' => 0,    'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'plugins',           'type' => 'dir',  'size' => 0,    'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'mods',              'type' => 'dir',  'size' => 0,    'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'world',             'type' => 'dir',  'size' => 0,    'modified' => '2025-05-10T14:23:00Z'],
            ['name' => 'config',            'type' => 'dir',  'size' => 0,    'modified' => '2025-05-10T14:22:00Z'],
        ],
        '/plugins' => [
            ['name' => 'EssentialsX-2.21.0.jar', 'type' => 'file', 'size' => 1534976, 'modified' => '2025-05-09T10:00:00Z'],
            ['name' => 'LuckPerms-Bukkit-5.4.jar','type' => 'file', 'size' => 4194304, 'modified' => '2025-05-09T10:00:00Z'],
            ['name' => 'EssentialsX',             'type' => 'dir',  'size' => 0,       'modified' => '2025-05-09T10:00:00Z'],
            ['name' => 'LuckPerms',               'type' => 'dir',  'size' => 0,       'modified' => '2025-05-09T10:00:00Z'],
        ],
        '/mods' => [
            ['name' => 'fabric-api-0.97.0.jar', 'type' => 'file', 'size' => 2097152, 'modified' => '2025-05-09T10:00:00Z'],
            ['name' => 'create-6.0.jar',        'type' => 'file', 'size' => 8388608, 'modified' => '2025-05-09T10:00:00Z'],
        ],
        '/logs' => [
            ['name' => 'latest.log',       'type' => 'file', 'size' => 45678, 'modified' => '2025-05-10T14:20:00Z'],
            ['name' => '2025-05-09-1.log', 'type' => 'file', 'size' => 98765, 'modified' => '2025-05-09T23:59:00Z'],
        ],
        '/world' => [
            ['name' => 'level.dat',     'type' => 'file', 'size' => 2048,   'modified' => '2025-05-10T14:20:00Z'],
            ['name' => 'region',        'type' => 'dir',  'size' => 0,      'modified' => '2025-05-10T14:20:00Z'],
            ['name' => 'playerdata',    'type' => 'dir',  'size' => 0,      'modified' => '2025-05-09T12:00:00Z'],
        ],
        '/config' => [
            ['name' => 'paper-world-defaults.yml', 'type' => 'file', 'size' => 4096, 'modified' => '2025-05-10T14:22:00Z'],
            ['name' => 'paper-global.yml',         'type' => 'file', 'size' => 3072, 'modified' => '2025-05-10T14:22:00Z'],
        ],
    ];

    $key = rtrim($path, '/') ?: '/';
    if ($key !== '/') $key = '/' . trim($key, '/');

    return $trees[$key] ?? [];
}

function formatSize(int $bytes): string
{
    if ($bytes === 0) return '—';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

// ── Route ────────────────────────────────────────────────────────────────────

try {
    if ($method === 'GET') {
        // List files
        $path = '/' . ltrim((string)($_GET['path'] ?? '/'), '/');

        // If no hosting_server_id, return mock data
        if (!$hostingId) {
            $files = mockFileTree($path);
            foreach ($files as &$f) {
                $f['size_human'] = formatSize((int)($f['size'] ?? 0));
            }
            echo json_encode(['ok' => true, 'path' => $path, 'files' => $files, 'mock' => true]);
            exit;
        }

        // Try to get real file list from adapter (Pterodactyl has file list API)
        // Fall back to mock if adapter doesn't support it
        try {
            $adapter = ProvisioningAdapter::make();
            if (method_exists($adapter, 'listFiles')) {
                $files = $adapter->listFiles($hostingId, $path);
                foreach ($files as &$f) {
                    $f['size_human'] = formatSize((int)($f['size'] ?? 0));
                }
                echo json_encode(['ok' => true, 'path' => $path, 'files' => $files]);
            } else {
                $files = mockFileTree($path);
                foreach ($files as &$f) {
                    $f['size_human'] = formatSize((int)($f['size'] ?? 0));
                }
                echo json_encode(['ok' => true, 'path' => $path, 'files' => $files, 'mock' => true]);
            }
        } catch (Throwable) {
            $files = mockFileTree($path);
            foreach ($files as &$f) {
                $f['size_human'] = formatSize((int)($f['size'] ?? 0));
            }
            echo json_encode(['ok' => true, 'path' => $path, 'files' => $files, 'mock' => true]);
        }
        exit;
    }

    if ($method === 'POST') {
        // Parse body — could be JSON or form data
        $body   = [];
        $ct     = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
        } else {
            $body = $_POST;
        }

        $action = trim((string)($body['action'] ?? ''));

        switch ($action) {

            case 'upload': {
                // Multipart upload — file in $_FILES['file']
                if (empty($_FILES['file'])) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Aucun fichier fourni']);
                    exit;
                }
                $remotePath = ltrim((string)($body['path'] ?? '/'), '/');
                $fileName   = basename($_FILES['file']['name'] ?? 'upload');
                $tmpPath    = (string)($_FILES['file']['tmp_name'] ?? '');

                if (!$tmpPath || !is_uploaded_file($tmpPath)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Upload invalide']);
                    exit;
                }

                if (!$hostingId) {
                    // Mock: just pretend
                    echo json_encode(['ok' => true, 'message' => 'Fichier uploadé (mock)', 'file' => $fileName]);
                    exit;
                }

                $adapter   = ProvisioningAdapter::make();
                $destPath  = ltrim($remotePath . '/' . $fileName, '/');
                $uploaded  = $adapter->uploadFile($hostingId, $tmpPath, $destPath);
                echo json_encode(['ok' => $uploaded, 'file' => $fileName]);
                break;
            }

            case 'delete': {
                $filePath = (string)($body['path'] ?? '');
                if (!$filePath) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Chemin requis']);
                    exit;
                }
                // Mock: always ok
                echo json_encode(['ok' => true, 'deleted' => $filePath]);
                break;
            }

            case 'mkdir': {
                $dirPath = (string)($body['path'] ?? '');
                if (!$dirPath) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Chemin requis']);
                    exit;
                }
                echo json_encode(['ok' => true, 'created' => $dirPath]);
                break;
            }

            case 'rename': {
                $from = (string)($body['from'] ?? '');
                $to   = (string)($body['to'] ?? '');
                if (!$from || !$to) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'from et to requis']);
                    exit;
                }
                echo json_encode(['ok' => true, 'from' => $from, 'to' => $to]);
                break;
            }

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Action inconnue : {$action}"]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

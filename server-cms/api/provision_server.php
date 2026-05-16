<?php
/**
 * POST /server-cms/api/provision_server.php
 *
 * Crée un serveur Minecraft via le driver de provisioning configuré.
 * Appelé automatiquement après validation d'un paiement Stripe.
 *
 * Corps JSON attendu :
 *   server_id    int    — ID du serveur en DB (mc_servers)
 *   action       string — 'create'|'start'|'stop'|'restart'|'delete'|'status'|'command'|'backup'
 *   command      string — (pour action='command') La commande à envoyer
 *
 * Auth : session PHP admin ou token interne (PROVISIONING_SECRET).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../provisioning/ProvisioningAdapter.php';
require_once __DIR__ . '/../provisioning/MockAdapter.php';
require_once __DIR__ . '/../provisioning/PterodactylAdapter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────

function authorizeRequest(): bool
{
    // 1. Token interne (utilisé par les webhooks et les tâches CRON)
    $secret = $_ENV['PROVISIONING_SECRET'] ?? getenv('PROVISIONING_SECRET') ?: '';
    $token  = $_SERVER['HTTP_X_PROVISIONING_TOKEN'] ?? '';
    if ($secret && $token && hash_equals($secret, $token)) return true;

    // 2. Session admin PHP
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['admin_id']) || !empty($_SESSION['user_id']);
}

if (!authorizeRequest()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Non autorisé']);
    exit;
}

// ── Méthode ──────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$raw    = file_get_contents('php://input');
$body   = json_decode($raw ?: '', true);
$action = trim((string)($body['action'] ?? ''));

if (!$action) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètre action manquant']);
    exit;
}

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'DB indisponible']);
    exit;
}

// ── Instanciation de l'adapter ────────────────────────────────────────────────

try {
    $adapter = ProvisioningAdapter::make();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Adapter error : ' . $e->getMessage()]);
    exit;
}

// ── Actions ───────────────────────────────────────────────────────────────────

try {
    switch ($action) {

        // ── Créer un serveur ─────────────────────────────────────────────────
        case 'create': {
            $serverId = (int)($body['server_id'] ?? 0);
            if (!$serverId) throw new \InvalidArgumentException('server_id requis');

            $server = $pdo->query("SELECT * FROM mc_servers WHERE id = {$serverId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$server) throw new \RuntimeException('Serveur introuvable en DB');

            if (!empty($server['hosting_server_id'])) {
                echo json_encode(['ok' => false, 'error' => 'Serveur déjà provisionné', 'hosting_server_id' => $server['hosting_server_id']]);
                exit;
            }

            $plan = $pdo->query(
                "SELECT * FROM mc_server_plans WHERE slug = '" . $pdo->quote($server['plan_slug'] ?? 'spark') . "' LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            $result = $adapter->createServer([
                'plan'        => $server['plan_slug'] ?? 'spark',
                'server_name' => "XynoServer-{$serverId}-" . ($server['server_name'] ?? 'server'),
                'ram_mb'      => $plan['ram_mb']    ?? 2048,
                'cpu_cores'   => $plan['cpu_cores'] ?? 1,
                'storage_gb'  => $plan['storage_gb'] ?? 10,
                'server_type' => $server['server_type'] ?? 'paper',
                'mc_version'  => $server['mc_version'] ?? 'latest',
                'max_players' => $plan['max_players'] ?? 10,
                'motd'        => $server['motd'] ?? 'Serveur XynoWeb',
            ]);

            // Mise à jour en DB
            $stmt = $pdo->prepare(
                'UPDATE mc_servers SET
                    hosting_server_id = :hid,
                    server_ip         = :ip,
                    server_port       = :port,
                    status            = :status,
                    updated_at        = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':hid'    => $result['server_id'],
                ':ip'     => $result['ip'],
                ':port'   => $result['port'],
                ':status' => 'provisioning',
                ':id'     => $serverId,
            ]);

            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        // ── Start / Stop / Restart / Delete ──────────────────────────────────
        case 'start':
        case 'stop':
        case 'restart':
        case 'delete': {
            $serverId = (int)($body['server_id'] ?? 0);
            if (!$serverId) throw new \InvalidArgumentException('server_id requis');

            $server = $pdo->query("SELECT hosting_server_id FROM mc_servers WHERE id = {$serverId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$server || !$server['hosting_server_id']) throw new \RuntimeException('Serveur non provisionné');

            $hid    = $server['hosting_server_id'];
            $result = match ($action) {
                'start'   => $adapter->startServer($hid),
                'stop'    => $adapter->stopServer($hid),
                'restart' => $adapter->restartServer($hid),
                'delete'  => $adapter->deleteServer($hid),
            };

            if ($action === 'delete' && ($result['ok'] ?? false)) {
                $pdo->prepare('UPDATE mc_servers SET hosting_server_id = NULL, status = :s, updated_at = NOW() WHERE id = :id')
                    ->execute([':s' => 'deleted', ':id' => $serverId]);
            } elseif ($result['ok'] ?? false) {
                $newStatus = match ($action) {
                    'start'   => 'running',
                    'stop'    => 'stopped',
                    'restart' => 'running',
                    default   => null,
                };
                if ($newStatus) {
                    $pdo->prepare('UPDATE mc_servers SET status = :s, updated_at = NOW() WHERE id = :id')
                        ->execute([':s' => $newStatus, ':id' => $serverId]);
                }
            }

            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        // ── Statut ───────────────────────────────────────────────────────────
        case 'status': {
            $serverId = (int)($body['server_id'] ?? 0);
            if (!$serverId) throw new \InvalidArgumentException('server_id requis');

            $server = $pdo->query("SELECT hosting_server_id FROM mc_servers WHERE id = {$serverId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$server || !$server['hosting_server_id']) {
                echo json_encode(['ok' => false, 'error' => 'Serveur non provisionné']);
                exit;
            }

            $status = $adapter->getStatus($server['hosting_server_id']);
            echo json_encode(['ok' => true, 'status' => $status]);
            break;
        }

        // ── Commande ─────────────────────────────────────────────────────────
        case 'command': {
            $serverId = (int)($body['server_id'] ?? 0);
            $command  = trim((string)($body['command'] ?? ''));
            if (!$serverId || $command === '') throw new \InvalidArgumentException('server_id et command requis');

            $server = $pdo->query("SELECT hosting_server_id FROM mc_servers WHERE id = {$serverId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$server || !$server['hosting_server_id']) throw new \RuntimeException('Serveur non provisionné');

            $result = $adapter->sendCommand($server['hosting_server_id'], $command);
            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        // ── Backup ───────────────────────────────────────────────────────────
        case 'backup': {
            $serverId = (int)($body['server_id'] ?? 0);
            if (!$serverId) throw new \InvalidArgumentException('server_id requis');

            $server = $pdo->query("SELECT hosting_server_id FROM mc_servers WHERE id = {$serverId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$server || !$server['hosting_server_id']) throw new \RuntimeException('Serveur non provisionné');

            $result = $adapter->createBackup($server['hosting_server_id']);
            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Action inconnue : {$action}"]);
    }

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

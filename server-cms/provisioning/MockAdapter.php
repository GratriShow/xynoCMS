<?php
/**
 * MockAdapter — Simule le provisioning sans aucune infra réelle.
 *
 * Toutes les opérations réussissent immédiatement avec des données fictives.
 * Parfait pour le développement et les tests UI avant d'avoir un vrai hébergeur.
 *
 * Les serveurs "créés" sont stockés dans un fichier JSON local (mock_servers.json).
 */

declare(strict_types=1);

require_once __DIR__ . '/ProvisioningAdapter.php';

class MockAdapter extends ProvisioningAdapter
{
    private string $storeFile;

    public function __construct()
    {
        $this->storeFile = sys_get_temp_dir() . '/xynoweb_mock_servers.json';
    }

    // ── Store helpers ─────────────────────────────────────────────────────────

    private function readStore(): array
    {
        if (!file_exists($this->storeFile)) return [];
        $data = json_decode(file_get_contents($this->storeFile), true);
        return is_array($data) ? $data : [];
    }

    private function writeStore(array $data): void
    {
        file_put_contents($this->storeFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getServer(string $serverId): ?array
    {
        $store = $this->readStore();
        return $store[$serverId] ?? null;
    }

    // ── Interface ─────────────────────────────────────────────────────────────

    public function createServer(array $params): array
    {
        $serverId = 'mock-' . bin2hex(random_bytes(8));
        $specs    = $this->planSpecs($params['plan'] ?? 'spark');

        $server = [
            'server_id'  => $serverId,
            'ip'         => '127.0.0.1',
            'port'       => 25565,
            'status'     => 'running',
            'panel_url'  => null,
            'plan'       => $params['plan'] ?? 'spark',
            'name'       => $params['server_name'] ?? 'Mock Server',
            'ram_mb'     => $params['ram_mb'] ?? $specs['ram_mb'],
            'cpu_cores'  => $params['cpu_cores'] ?? $specs['cpu_cores'],
            'players'    => 0,
            'max_players'=> $params['max_players'] ?? $specs['max_players'],
            'tps'        => 20.0,
            'started_at' => time(),
            'backups'    => [],
        ];

        $store = $this->readStore();
        $store[$serverId] = $server;
        $this->writeStore($store);

        return [
            'server_id' => $serverId,
            'ip'        => $server['ip'],
            'port'      => $server['port'],
            'status'    => 'provisioning',
            'panel_url' => null,
        ];
    }

    public function startServer(string $serverId): array
    {
        $store = $this->readStore();
        if (isset($store[$serverId])) {
            $store[$serverId]['status'] = 'running';
            $store[$serverId]['started_at'] = time();
            $this->writeStore($store);
        }
        return ['ok' => true, 'status' => 'running'];
    }

    public function stopServer(string $serverId): array
    {
        $store = $this->readStore();
        if (isset($store[$serverId])) {
            $store[$serverId]['status'] = 'stopped';
            $this->writeStore($store);
        }
        return ['ok' => true, 'status' => 'stopped'];
    }

    public function restartServer(string $serverId): array
    {
        $this->stopServer($serverId);
        sleep(0); // Pas de vrai délai en mode mock
        return $this->startServer($serverId);
    }

    public function getStatus(string $serverId): array
    {
        $server = $this->getServer($serverId);
        if (!$server) {
            return ['status' => 'error', 'online' => false, 'ram_used_mb' => 0,
                    'ram_max_mb' => 0, 'cpu_percent' => 0, 'players_online' => 0,
                    'players_max' => 0, 'tps' => null, 'uptime_seconds' => 0];
        }

        $isRunning = ($server['status'] ?? 'stopped') === 'running';
        $uptime    = $isRunning ? (time() - ($server['started_at'] ?? time())) : 0;

        return [
            'status'         => $server['status'] ?? 'stopped',
            'online'         => $isRunning,
            'ram_used_mb'    => $isRunning ? (int)(($server['ram_mb'] ?? 2048) * 0.6) : 0,
            'ram_max_mb'     => $server['ram_mb'] ?? 2048,
            'cpu_percent'    => $isRunning ? 35.0 : 0.0,
            'players_online' => $isRunning ? ($server['players'] ?? 0) : 0,
            'players_max'    => $server['max_players'] ?? 10,
            'tps'            => $isRunning ? 20.0 : null,
            'uptime_seconds' => $uptime,
        ];
    }

    public function sendCommand(string $serverId, string $command): array
    {
        $server = $this->getServer($serverId);
        if (!$server) return ['ok' => false, 'output' => 'Serveur introuvable'];

        // Simule quelques commandes
        $output = match (true) {
            str_starts_with($command, 'list')     => '[Mock] Players online (0/10): ',
            str_starts_with($command, 'say ')     => '[Mock] Message broadcasté',
            str_starts_with($command, 'op ')      => '[Mock] Op accordé',
            str_starts_with($command, 'whitelist')=> '[Mock] Whitelist mise à jour',
            str_starts_with($command, 'stop')     => '[Mock] Arrêt en cours...',
            default                               => "[Mock] Commande exécutée : {$command}",
        };

        return ['ok' => true, 'output' => $output];
    }

    public function uploadFile(string $serverId, string $localPath, string $remotePath): bool
    {
        // Simule un upload réussi
        return true;
    }

    public function createBackup(string $serverId): array
    {
        $store = $this->readStore();
        if (!isset($store[$serverId])) return ['ok' => false, 'backup_id' => null];

        $backupId = 'backup-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $store[$serverId]['backups'][] = [
            'id'         => $backupId,
            'name'       => 'Backup ' . date('d/m/Y H:i'),
            'size_mb'    => rand(50, 500),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->writeStore($store);

        return ['ok' => true, 'backup_id' => $backupId];
    }

    public function listBackups(string $serverId): array
    {
        $server = $this->getServer($serverId);
        return $server['backups'] ?? [];
    }

    public function deleteServer(string $serverId): array
    {
        $store = $this->readStore();
        unset($store[$serverId]);
        $this->writeStore($store);
        return ['ok' => true];
    }
}

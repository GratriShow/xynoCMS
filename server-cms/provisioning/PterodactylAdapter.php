<?php
/**
 * PterodactylAdapter — Provisioning via panel Pterodactyl.
 *
 * Pterodactyl est un panel open source de gestion de serveurs de jeux.
 * Il expose une API REST complète pour créer, démarrer, stopper des serveurs
 * dans des conteneurs Docker isolés.
 *
 * Config nécessaire dans .env :
 *   PTERODACTYL_URL       = https://panel.ton-hebergeur.fr
 *   PTERODACTYL_API_KEY   = ptla_xxxxxxxxxxxx    (clé Application API)
 *   PTERODACTYL_NODE_ID   = 1                    (ID du nœud par défaut)
 *   PTERODACTYL_EGG_ID    = 3                    (ID de l'egg Minecraft Paper)
 *   PTERODACTYL_NEST_ID   = 1                    (ID du nest Games)
 *   PTERODACTYL_USER_ID   = 1                    (ID user propriétaire des serveurs)
 *
 * Documentation API : https://dashflo.net/docs/api/pterodactyl/v1/
 */

declare(strict_types=1);

require_once __DIR__ . '/ProvisioningAdapter.php';

class PterodactylAdapter extends ProvisioningAdapter
{
    private string $baseUrl;
    private string $apiKey;
    private int $nodeId;
    private int $eggId;
    private int $nestId;
    private int $ownerId;

    public function __construct()
    {
        $this->baseUrl  = rtrim((string)($_ENV['PTERODACTYL_URL']     ?? getenv('PTERODACTYL_URL')     ?: ''), '/');
        $this->apiKey   = (string)($_ENV['PTERODACTYL_API_KEY']        ?? getenv('PTERODACTYL_API_KEY')  ?: '');
        $this->nodeId   = (int)($_ENV['PTERODACTYL_NODE_ID']           ?? getenv('PTERODACTYL_NODE_ID')  ?: 1);
        $this->eggId    = (int)($_ENV['PTERODACTYL_EGG_ID']            ?? getenv('PTERODACTYL_EGG_ID')   ?: 3);
        $this->nestId   = (int)($_ENV['PTERODACTYL_NEST_ID']           ?? getenv('PTERODACTYL_NEST_ID')  ?: 1);
        $this->ownerId  = (int)($_ENV['PTERODACTYL_USER_ID']           ?? getenv('PTERODACTYL_USER_ID')  ?: 1);

        if (!$this->baseUrl || !$this->apiKey) {
            throw new \RuntimeException('PTERODACTYL_URL et PTERODACTYL_API_KEY sont requis dans .env');
        }
    }

    // ── Requête HTTP vers l'API Pterodactyl ──────────────────────────────────

    private function request(string $method, string $endpoint, array $body = []): array
    {
        $url = $this->baseUrl . '/api/application' . $endpoint;
        $payload = !empty($body) ? json_encode($body) : null;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("Erreur cURL Pterodactyl : {$curlErr}");
        }

        $data = json_decode((string)$response, true);

        if ($httpCode >= 400) {
            $msg = $data['errors'][0]['detail'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Pterodactyl API error : {$msg}");
        }

        return is_array($data) ? $data : [];
    }

    /** Requête sur l'API Client (pour les actions serveur) */
    private function clientRequest(string $method, string $identifier, string $action, array $body = []): array
    {
        $url = $this->baseUrl . "/api/client/servers/{$identifier}" . $action;
        $payload = !empty($body) ? json_encode($body) : null;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$response, true);

        if ($httpCode >= 400) {
            $msg = $data['errors'][0]['detail'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Pterodactyl Client API error : {$msg}");
        }

        return is_array($data) ? $data : [];
    }

    // ── Map des egg IDs par type de serveur ──────────────────────────────────
    // À adapter selon les eggs installés sur ton panel Pterodactyl

    private function eggIdForType(string $serverType): int
    {
        // Valeurs par défaut — à ajuster selon votre installation
        return match (strtolower($serverType)) {
            'paper', 'spigot' => $this->eggId,      // Egg Paper MC (nest Games)
            'forge'           => $this->eggId + 1,  // Egg Forge MC
            'fabric'          => $this->eggId + 2,  // Egg Fabric MC
            'vanilla'         => $this->eggId + 3,  // Egg Vanilla MC
            default           => $this->eggId,
        };
    }

    // ── Interface ─────────────────────────────────────────────────────────────

    public function createServer(array $params): array
    {
        $specs = $this->planSpecs($params['plan'] ?? 'spark');

        $mcVersion  = $params['mc_version']  ?? 'latest';
        $serverType = $params['server_type'] ?? 'paper';
        $eggId      = $this->eggIdForType($serverType);

        $payload = [
            'name'         => $params['server_name'] ?? 'XynoServer',
            'user'         => $this->ownerId,
            'egg'          => $eggId,
            'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21',
            'startup'      => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}} --nogui',
            'environment'  => [
                'SERVER_JARFILE'  => 'server.jar',
                'SERVER_MEMORY'   => (string)($params['ram_mb'] ?? $specs['ram_mb']),
                'MINECRAFT_VERSION' => $mcVersion,
                'SERVER_TYPE'     => strtoupper($serverType),
            ],
            'limits' => [
                'memory' => $params['ram_mb'] ?? $specs['ram_mb'],
                'swap'   => 0,
                'disk'   => ($params['storage_gb'] ?? $specs['storage_gb']) * 1024, // Mo
                'io'     => 500,
                'cpu'    => ($params['cpu_cores'] ?? $specs['cpu_cores']) * 100,
            ],
            'feature_limits' => [
                'databases'  => 0,
                'backups'    => 5,
                'allocations'=> 1,
            ],
            'allocation' => [
                'default' => $this->getFreeAllocation(),
            ],
        ];

        $response = $this->request('POST', '/servers', $payload);
        $server   = $response['attributes'] ?? [];

        return [
            'server_id' => $server['identifier'] ?? $server['uuid'] ?? '',
            'ip'        => $this->getAllocationIp($server),
            'port'      => $this->getAllocationPort($server),
            'status'    => 'provisioning',
            'panel_url' => $this->baseUrl . '/server/' . ($server['identifier'] ?? ''),
        ];
    }

    public function startServer(string $serverId): array
    {
        $this->clientRequest('POST', $serverId, '/power', ['signal' => 'start']);
        return ['ok' => true, 'status' => 'starting'];
    }

    public function stopServer(string $serverId): array
    {
        $this->clientRequest('POST', $serverId, '/power', ['signal' => 'stop']);
        return ['ok' => true, 'status' => 'stopping'];
    }

    public function restartServer(string $serverId): array
    {
        $this->clientRequest('POST', $serverId, '/power', ['signal' => 'restart']);
        return ['ok' => true, 'status' => 'restarting'];
    }

    public function getStatus(string $serverId): array
    {
        try {
            $data   = $this->clientRequest('GET', $serverId, '/resources');
            $attrs  = $data['attributes'] ?? [];
            $state  = $attrs['current_state'] ?? 'stopped';
            $res    = $attrs['resources'] ?? [];

            return [
                'status'         => $state,
                'online'         => $state === 'running',
                'ram_used_mb'    => (int)(($res['memory_bytes'] ?? 0) / 1024 / 1024),
                'ram_max_mb'     => (int)(($res['memory_limit_bytes'] ?? 0) / 1024 / 1024),
                'cpu_percent'    => round((float)($res['cpu_absolute'] ?? 0), 1),
                'players_online' => 0, // Pterodactyl ne remonte pas les joueurs nativement
                'players_max'    => 0,
                'tps'            => null, // Nécessite un plugin de monitoring côté serveur
                'uptime_seconds' => (int)($res['uptime'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error', 'online' => false,
                'ram_used_mb' => 0, 'ram_max_mb' => 0, 'cpu_percent' => 0,
                'players_online' => 0, 'players_max' => 0, 'tps' => null, 'uptime_seconds' => 0,
            ];
        }
    }

    public function sendCommand(string $serverId, string $command): array
    {
        try {
            $this->clientRequest('POST', $serverId, '/command', ['command' => $command]);
            return ['ok' => true, 'output' => 'Commande envoyée'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }

    public function uploadFile(string $serverId, string $localPath, string $remotePath): bool
    {
        // Upload via l'API File Manager de Pterodactyl
        // POST /api/client/servers/{id}/files/write?file={remotePath}
        $url  = $this->baseUrl . "/api/client/servers/{$serverId}/files/write?file=" . urlencode($remotePath);
        $data = file_get_contents($localPath);
        if ($data === false) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: text/plain',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $httpCode = curl_getinfo(curl_exec($ch) ? $ch : $ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode < 400;
    }

    public function createBackup(string $serverId): array
    {
        try {
            $data   = $this->clientRequest('POST', $serverId, '/backups');
            $backupId = $data['attributes']['uuid'] ?? null;
            return ['ok' => true, 'backup_id' => $backupId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'backup_id' => null];
        }
    }

    public function listBackups(string $serverId): array
    {
        try {
            $data    = $this->clientRequest('GET', $serverId, '/backups');
            $backups = $data['data'] ?? [];
            return array_map(fn($b) => [
                'id'         => $b['attributes']['uuid'] ?? '',
                'name'       => $b['attributes']['name'] ?? '',
                'size_mb'    => (int)(($b['attributes']['bytes'] ?? 0) / 1024 / 1024),
                'created_at' => $b['attributes']['created_at'] ?? '',
            ], $backups);
        } catch (\Throwable) {
            return [];
        }
    }

    public function deleteServer(string $serverId): array
    {
        try {
            // D'abord récupérer l'ID numérique depuis l'identifier court
            $data     = $this->clientRequest('GET', $serverId, '');
            $numericId = $data['attributes']['server_id'] ?? null;
            if ($numericId) {
                $this->request('DELETE', "/servers/{$numericId}");
            }
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false];
        }
    }

    // ── Helpers internes ─────────────────────────────────────────────────────

    /** Trouve une allocation libre sur le nœud configuré */
    private function getFreeAllocation(): int
    {
        $data = $this->request('GET', "/nodes/{$this->nodeId}/allocations?per_page=100");
        foreach ($data['data'] ?? [] as $alloc) {
            $attrs = $alloc['attributes'] ?? [];
            if (empty($attrs['assigned'])) {
                return (int)$attrs['id'];
            }
        }
        throw new \RuntimeException('Aucune allocation libre sur le nœud Pterodactyl');
    }

    private function getAllocationIp(array $serverAttrs): string
    {
        $allocs = $serverAttrs['relationships']['allocations']['data'] ?? [];
        return $allocs[0]['attributes']['ip'] ?? '0.0.0.0';
    }

    private function getAllocationPort(array $serverAttrs): int
    {
        $allocs = $serverAttrs['relationships']['allocations']['data'] ?? [];
        return (int)($allocs[0]['attributes']['port'] ?? 25565);
    }
}

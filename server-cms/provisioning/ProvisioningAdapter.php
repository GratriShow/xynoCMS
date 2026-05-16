<?php
/**
 * ProvisioningAdapter — Interface abstraite pour la création/gestion de serveurs.
 *
 * Toute implémentation (Mock, Pterodactyl, Hetzner, custom…) doit étendre
 * cette classe et implémenter les méthodes ci-dessous.
 *
 * Utilisation :
 *   $adapter = ProvisioningAdapter::make();  // Lit PROVISIONING_DRIVER depuis .env
 *   $server  = $adapter->createServer([...]);
 */

declare(strict_types=1);

abstract class ProvisioningAdapter
{
    // ─────────────────────────────────────────────────────────────────────────
    // Factory — sélectionne le bon driver selon la config
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Instancie l'adapter configuré.
     *
     * PROVISIONING_DRIVER dans .env :
     *   mock        — Simule tout localement (dev/test)
     *   pterodactyl — Panel Pterodactyl auto-hébergé
     *   hetzner     — Hetzner Cloud API (VMs à la volée)
     *   custom      — Ton propre système (à implémenter)
     */
    public static function make(): static
    {
        $driver = strtolower(trim((string)(defined('PROVISIONING_DRIVER')
            ? PROVISIONING_DRIVER
            : ($_ENV['PROVISIONING_DRIVER'] ?? getenv('PROVISIONING_DRIVER') ?: 'mock')
        )));

        return match ($driver) {
            'pterodactyl' => new PterodactylAdapter(),
            'hetzner'     => new HetznerAdapter(),
            'mock'        => new MockAdapter(),
            default       => throw new \RuntimeException("Driver de provisioning inconnu : {$driver}"),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contrat — méthodes à implémenter
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crée un nouveau serveur Minecraft et retourne ses infos.
     *
     * @param array{
     *   plan: string,           // 'spark'|'core'|'pro'|'max'
     *   server_name: string,    // Nom affiché dans le panel
     *   ram_mb: int,            // RAM allouée en Mo
     *   cpu_cores: int,         // vCPU
     *   storage_gb: int,        // Disque en Go
     *   server_type: string,    // 'vanilla'|'paper'|'forge'|'fabric'
     *   mc_version: string,     // ex: '1.21.4'
     *   max_players: int,
     *   motd: string,
     * } $params
     *
     * @return array{
     *   server_id: string,      // ID unique chez l'hébergeur
     *   ip: string,             // IP publique
     *   port: int,              // Port Minecraft (défaut 25565)
     *   status: string,         // 'provisioning'
     *   panel_url: string|null, // URL du panel hébergeur (optionnel)
     * }
     *
     * @throws \RuntimeException en cas d'erreur de provisioning
     */
    abstract public function createServer(array $params): array;

    /**
     * Démarre un serveur arrêté.
     *
     * @return array{ ok: bool, status: string }
     */
    abstract public function startServer(string $serverId): array;

    /**
     * Arrête proprement un serveur (SIGTERM, sauvegarde monde).
     *
     * @return array{ ok: bool, status: string }
     */
    abstract public function stopServer(string $serverId): array;

    /**
     * Redémarre un serveur.
     *
     * @return array{ ok: bool, status: string }
     */
    abstract public function restartServer(string $serverId): array;

    /**
     * Retourne le statut et les métriques d'un serveur.
     *
     * @return array{
     *   status: string,         // 'running'|'stopped'|'starting'|'error'
     *   online: bool,
     *   ram_used_mb: int,
     *   ram_max_mb: int,
     *   cpu_percent: float,
     *   players_online: int,
     *   players_max: int,
     *   tps: float|null,        // Ticks Per Second (si disponible)
     *   uptime_seconds: int,
     * }
     */
    abstract public function getStatus(string $serverId): array;

    /**
     * Envoie une commande RCON au serveur.
     *
     * @return array{ ok: bool, output: string }
     */
    abstract public function sendCommand(string $serverId, string $command): array;

    /**
     * Upload un fichier sur le serveur (config, plugin, etc.).
     *
     * @param string $localPath   Chemin local du fichier
     * @param string $remotePath  Chemin distant (ex: 'plugins/MyPlugin.jar')
     */
    abstract public function uploadFile(string $serverId, string $localPath, string $remotePath): bool;

    /**
     * Déclenche un backup immédiat.
     *
     * @return array{ ok: bool, backup_id: string|null }
     */
    abstract public function createBackup(string $serverId): array;

    /**
     * Liste les backups disponibles.
     *
     * @return list<array{ id: string, name: string, size_mb: int, created_at: string }>
     */
    abstract public function listBackups(string $serverId): array;

    /**
     * Supprime définitivement un serveur et libère ses ressources.
     *
     * @return array{ ok: bool }
     */
    abstract public function deleteServer(string $serverId): array;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers communs (non-abstract)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne les specs d'un plan (RAM, CPU, disque).
     */
    protected function planSpecs(string $plan): array
    {
        return match (strtolower($plan)) {
            'spark' => ['ram_mb' => 2048,  'cpu_cores' => 1, 'storage_gb' => 10,  'max_players' => 10],
            'core'  => ['ram_mb' => 4096,  'cpu_cores' => 2, 'storage_gb' => 25,  'max_players' => 20],
            'pro'   => ['ram_mb' => 8192,  'cpu_cores' => 2, 'storage_gb' => 50,  'max_players' => 50],
            'max'   => ['ram_mb' => 16384, 'cpu_cores' => 4, 'storage_gb' => 100, 'max_players' => 100],
            default => throw new \InvalidArgumentException("Plan inconnu : {$plan}"),
        };
    }
}

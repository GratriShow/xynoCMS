<?php

/**
 * XynoServer — Bridge Launcher ↔ Serveur
 *
 * POST /server-cms/api/launcher_link.php
 *   → Lier/délier un launcher xynoCMS à un serveur
 *   → body: { server_uuid, launcher_uuid, action: "link"|"unlink", _csrf }
 *
 * GET /server-cms/api/launcher_link.php?server_uuid=...
 *   → Retourne les launchers liés à ce serveur
 *
 * Ce endpoint est aussi utilisé par le launcher lui-même (via api_key serveur)
 * pour récupérer les infos de connexion :
 *
 * GET /server-cms/api/launcher_link.php?launcher_uuid=...&server_api_key=...
 *   → Retourne { ip, port, whitelist_mode } pour config auto du launcher
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo    = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ─────────────────────────────────────────────────────────────────────────
// GET — lecture publique (authentifiée par session OU api_key serveur)
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $serverApiKey   = trim((string)($_GET['server_api_key'] ?? ''));
    $launcherUuid   = trim((string)($_GET['launcher_uuid'] ?? ''));
    $serverUuid     = trim((string)($_GET['server_uuid'] ?? ''));

    // ── Mode API key (utilisé par le launcher Electron) ──────────────────
    if ($serverApiKey !== '' && $launcherUuid !== '') {
        $server = get_server_by_api_key($pdo, $serverApiKey);
        if (!$server) {
            json_response(['ok' => false, 'error' => 'API key invalide'], 401);
        }

        // Vérifier que ce launcher est bien lié à ce serveur
        $link = $pdo->prepare(
            'SELECT * FROM mc_server_launcher_links WHERE server_id = ? AND launcher_uuid = ? LIMIT 1'
        );
        $link->execute([$server['id'], $launcherUuid]);
        if (!$link->fetch()) {
            json_response(['ok' => false, 'error' => 'Launcher non lié à ce serveur'], 403);
        }

        // Retourner les infos de connexion pour le launcher
        $config = json_decode((string)($server['server_config'] ?? '{}'), true) ?: [];
        json_response([
            'ok'            => true,
            'server_name'   => $server['name'],
            'server_ip'     => $server['server_ip'] ?? '',
            'server_port'   => (int)$server['server_port'],
            'mc_version'    => $server['mc_version'],
            'server_type'   => $server['server_type'],
            'online_mode'   => (bool)($config['online-mode'] ?? true),
            'whitelist'     => (bool)($config['white-list'] ?? false),
            'max_players'   => (int)($config['max-players'] ?? 20),
            'status'        => $server['status'],
        ]);
    }

    // ── Mode session (dashboard) ──────────────────────────────────────────
    $user = current_user();
    if ($user === null) json_response(['ok' => false, 'error' => 'Non authentifié'], 401);

    if ($serverUuid === '') json_response(['ok' => false, 'error' => 'server_uuid requis'], 400);

    $server = get_user_server($pdo, $user['id'], $serverUuid);
    if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

    $links = $pdo->prepare(
        'SELECT sll.*, l.name AS launcher_name '
        . 'FROM mc_server_launcher_links sll '
        . 'LEFT JOIN launchers l ON l.uuid = sll.launcher_uuid '
        . 'WHERE sll.server_id = ? '
        . 'ORDER BY sll.linked_at DESC'
    );
    $links->execute([$server['id']]);

    json_response(['ok' => true, 'links' => $links->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────
// POST — lier / délier (session obligatoire)
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $user = current_user();
    if ($user === null) json_response(['ok' => false, 'error' => 'Non authentifié'], 401);

    $raw  = (string)file_get_contents('php://input');
    $body = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    if (empty($body)) $body = $_POST;

    $token = (string)($body['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_verify($token)) json_response(['ok' => false, 'error' => 'CSRF invalide'], 403);

    $serverUuid   = trim((string)($body['server_uuid'] ?? ''));
    $launcherUuid = trim((string)($body['launcher_uuid'] ?? ''));
    $action       = trim((string)($body['action'] ?? 'link'));

    if ($serverUuid === '' || $launcherUuid === '') {
        json_response(['ok' => false, 'error' => 'server_uuid et launcher_uuid requis'], 400);
    }

    $server = get_user_server($pdo, $user['id'], $serverUuid);
    if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

    // Vérifier que le launcher appartient au même utilisateur
    $launcher = $pdo->prepare('SELECT id, uuid, name FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $launcher->execute([$launcherUuid, $user['id']]);
    $launcherRow = $launcher->fetch();
    if (!$launcherRow) {
        json_response(['ok' => false, 'error' => 'Launcher introuvable ou non autorisé'], 404);
    }

    if ($action === 'link') {
        $pdo->prepare(
            'INSERT IGNORE INTO mc_server_launcher_links (server_id, launcher_uuid) VALUES (?, ?)'
        )->execute([$server['id'], $launcherUuid]);

        // Retourne les infos pour que le launcher puisse se configurer
        json_response([
            'ok'              => true,
            'action'          => 'linked',
            'server_name'     => $server['name'],
            'server_ip'       => $server['server_ip'] ?? '',
            'server_port'     => (int)$server['server_port'],
            'launcher_name'   => $launcherRow['name'],
            // Clé API serveur à injecter dans la config du launcher
            'server_api_key'  => get_server_api_key($pdo, $server['id']),
        ]);
    }

    if ($action === 'unlink') {
        $pdo->prepare(
            'DELETE FROM mc_server_launcher_links WHERE server_id = ? AND launcher_uuid = ?'
        )->execute([$server['id'], $launcherUuid]);
        json_response(['ok' => true, 'action' => 'unlinked']);
    }

    json_response(['ok' => false, 'error' => 'Action invalide (link|unlink)'], 400);
}

json_response(['ok' => false, 'error' => 'Méthode non supportée'], 405);

// ─────────────────────────────────────────────────────────────────────────
// Helper : récupère l'api_key (endpoint interne seulement)
// ─────────────────────────────────────────────────────────────────────────
function get_server_api_key(PDO $pdo, int $serverId): string
{
    $s = $pdo->prepare('SELECT api_key FROM mc_servers WHERE id = ? LIMIT 1');
    $s->execute([$serverId]);
    $row = $s->fetch();
    return (string)($row['api_key'] ?? '');
}

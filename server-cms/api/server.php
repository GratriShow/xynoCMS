<?php

/**
 * XynoServer — CRUD Serveurs
 *
 * POST   /server-cms/api/server.php          → créer un serveur
 * PUT    /server-cms/api/server.php?uuid=... → modifier
 * DELETE /server-cms/api/server.php?uuid=... → supprimer
 * GET    /server-cms/api/server.php?uuid=... → détail
 * GET    /server-cms/api/server.php          → liste
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = current_user();
if ($user === null) {
    json_response(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$pdo    = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uuid   = trim((string)($_GET['uuid'] ?? ''));

// ── Corps JSON pour POST/PUT ───────────────────────────────────────────────
$body = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    // Fallback $_POST
    if (empty($body)) $body = $_POST;
}

// ── CSRF vérification (POST/PUT/DELETE) ───────────────────────────────────
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $token = (string)($body['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_verify($token)) {
        json_response(['ok' => false, 'error' => 'CSRF invalide'], 403);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// GET — liste ou détail
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($uuid !== '') {
        $server = get_user_server($pdo, $user['id'], $uuid);
        if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

        // Récupère plugins/mods/liens
        $plugins = $pdo->prepare('SELECT * FROM mc_server_plugins WHERE server_id = ? ORDER BY added_at DESC');
        $plugins->execute([$server['id']]);

        $mods = $pdo->prepare('SELECT * FROM mc_server_mods WHERE server_id = ? ORDER BY added_at DESC');
        $mods->execute([$server['id']]);

        $links = $pdo->prepare('SELECT * FROM mc_server_launcher_links WHERE server_id = ?');
        $links->execute([$server['id']]);

        $players = $pdo->prepare('SELECT * FROM mc_server_players WHERE server_id = ? ORDER BY added_at DESC');
        $players->execute([$server['id']]);

        json_response([
            'ok'      => true,
            'server'  => sanitize_server_row($server),
            'plugins' => $plugins->fetchAll(),
            'mods'    => $mods->fetchAll(),
            'links'   => $links->fetchAll(),
            'players' => $players->fetchAll(),
        ]);
    }

    // Liste
    $stmt = $pdo->prepare(
        'SELECT s.*, '
        . '(SELECT COUNT(*) FROM mc_server_plugins p WHERE p.server_id = s.id) AS plugin_count, '
        . '(SELECT COUNT(*) FROM mc_server_mods m WHERE m.server_id = s.id) AS mod_count '
        . 'FROM mc_servers s WHERE s.user_id = ? ORDER BY s.created_at DESC'
    );
    $stmt->execute([$user['id']]);
    $servers = array_map('sanitize_server_row', $stmt->fetchAll());
    json_response(['ok' => true, 'servers' => $servers]);
}

// ─────────────────────────────────────────────────────────────────────────
// POST — créer un serveur
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $name         = trim((string)($body['name'] ?? ''));
    $description  = trim((string)($body['description'] ?? ''));
    $serverType   = trim((string)($body['server_type'] ?? 'paper'));
    $mcVersion    = trim((string)($body['mc_version'] ?? ''));
    $loaderVersion = trim((string)($body['loader_version'] ?? ''));
    $serverIp     = trim((string)($body['server_ip'] ?? ''));
    $serverPort   = max(1, min(65535, (int)($body['server_port'] ?? 25565)));
    $ramMb        = max(512, min(16384, (int)($body['ram_mb'] ?? 2048)));

    // Validation
    if ($name === '' || strlen($name) > 190) {
        json_response(['ok' => false, 'error' => 'Nom invalide (1–190 caractères)'], 422);
    }
    if (!array_key_exists($serverType, mc_server_types())) {
        json_response(['ok' => false, 'error' => 'Type de serveur invalide'], 422);
    }
    if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $mcVersion)) {
        json_response(['ok' => false, 'error' => 'Version Minecraft invalide'], 422);
    }

    // Config par défaut (server.properties)
    $defaultConfig = [
        'motd'               => $name,
        'max-players'        => 20,
        'online-mode'        => true,
        'difficulty'         => 'normal',
        'gamemode'           => 'survival',
        'pvp'                => true,
        'enable-command-block' => false,
        'spawn-protection'   => 16,
        'view-distance'      => 10,
        'simulation-distance' => 10,
        'white-list'         => false,
        'enforce-whitelist'  => false,
    ];

    // Merge config custom
    if (!empty($body['server_config']) && is_array($body['server_config'])) {
        $defaultConfig = array_merge($defaultConfig, $body['server_config']);
    }

    $newUuid   = uuid_v4();
    $newApiKey = generate_api_key(32);

    $stmt = $pdo->prepare(
        'INSERT INTO mc_servers '
        . '(user_id, uuid, api_key, name, description, server_type, mc_version, loader_version, '
        . 'server_ip, server_port, server_config, ram_mb, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id'],
        $newUuid,
        $newApiKey,
        $name,
        $description ?: null,
        $serverType,
        $mcVersion,
        $loaderVersion ?: null,
        $serverIp ?: null,
        $serverPort,
        json_encode($defaultConfig),
        $ramMb,
        'configuring',
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Récupère le serveur créé
    $s = $pdo->prepare('SELECT * FROM mc_servers WHERE id = ? LIMIT 1');
    $s->execute([$newId]);
    $newServer = $s->fetch();

    json_response(['ok' => true, 'server' => sanitize_server_row($newServer)], 201);
}

// ─────────────────────────────────────────────────────────────────────────
// PUT — modifier un serveur
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    if ($uuid === '') json_response(['ok' => false, 'error' => 'UUID requis'], 400);

    $server = get_user_server($pdo, $user['id'], $uuid);
    if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

    $fields = [];
    $values = [];

    $allowed = [
        'name', 'description', 'server_ip', 'server_port',
        'ram_mb', 'status', 'loader_version',
    ];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $v = $body[$f];
            if ($f === 'server_port') $v = max(1, min(65535, (int)$v));
            if ($f === 'ram_mb') $v = max(512, min(16384, (int)$v));
            if ($f === 'status' && !in_array($v, ['configuring','ready','running','stopped'], true)) continue;
            $fields[] = "`{$f}` = ?";
            $values[] = $v;
        }
    }

    // Config JSON
    if (!empty($body['server_config']) && is_array($body['server_config'])) {
        $existing = json_decode((string)($server['server_config'] ?? '{}'), true) ?: [];
        $merged = array_merge($existing, $body['server_config']);
        $fields[] = '`server_config` = ?';
        $values[] = json_encode($merged);
    }

    if (empty($fields)) json_response(['ok' => false, 'error' => 'Rien à modifier'], 400);

    $values[] = $server['id'];
    $pdo->prepare('UPDATE mc_servers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

    $s = $pdo->prepare('SELECT * FROM mc_servers WHERE id = ? LIMIT 1');
    $s->execute([$server['id']]);
    json_response(['ok' => true, 'server' => sanitize_server_row($s->fetch())]);
}

// ─────────────────────────────────────────────────────────────────────────
// DELETE — supprimer un serveur
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if ($uuid === '') json_response(['ok' => false, 'error' => 'UUID requis'], 400);

    $server = get_user_server($pdo, $user['id'], $uuid);
    if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

    $pdo->prepare('DELETE FROM mc_servers WHERE id = ?')->execute([$server['id']]);
    json_response(['ok' => true, 'deleted' => $uuid]);
}

json_response(['ok' => false, 'error' => 'Méthode non supportée'], 405);

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function sanitize_server_row(array $row): array
{
    // Ne jamais exposer l'api_key dans les listes publiques
    unset($row['api_key']);

    // Décoder la config JSON
    if (isset($row['server_config']) && is_string($row['server_config'])) {
        $decoded = json_decode($row['server_config'], true);
        $row['server_config'] = is_array($decoded) ? $decoded : [];
    }

    return $row;
}

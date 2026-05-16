<?php
/**
 * POST /server-cms/api/server_settings.php
 *
 * Saves server configuration settings from the panel's Settings tab.
 *
 * Accepts JSON body or form POST:
 *   server_id        int     — Required
 *   server_name      string  — Display name
 *   motd             string  — Message of the Day
 *   max_players      int
 *   difficulty       string  — peaceful|easy|normal|hard
 *   gamemode         string  — survival|creative|adventure|spectator
 *   pvp              bool
 *   whitelist        bool
 *   online_mode      bool
 *   view_distance    int
 *   spawn_protection int
 *   java_flags       string  — JVM flags
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Auth ────────────────────────────────────────────────────────────────────

$user = current_user();
if (!$user) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────

$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = [];
if (str_contains($ct, 'application/json')) {
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
} else {
    $body = $_POST;
}

$serverId = (int)($body['server_id'] ?? 0);
if (!$serverId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'server_id requis']);
    exit;
}

// ── Verify ownership ─────────────────────────────────────────────────────────

$pdo = db();
try {
    $s = $pdo->prepare('SELECT id, server_config FROM mc_servers WHERE id = ? AND user_id = ? LIMIT 1');
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

// ── Sanitize & validate ──────────────────────────────────────────────────────

$allowedDifficulties = ['peaceful', 'easy', 'normal', 'hard'];
$allowedGamemodes    = ['survival', 'creative', 'adventure', 'spectator'];

$serverName     = trim((string)($body['server_name'] ?? ''));
$motd           = trim((string)($body['motd'] ?? ''));
$maxPlayers     = min(500, max(1, (int)($body['max_players'] ?? 20)));
$difficulty     = in_array($body['difficulty'] ?? '', $allowedDifficulties, true) ? $body['difficulty'] : 'normal';
$gamemode       = in_array($body['gamemode'] ?? '', $allowedGamemodes, true) ? $body['gamemode'] : 'survival';
$pvp            = filter_var($body['pvp'] ?? true, FILTER_VALIDATE_BOOLEAN);
$whitelist      = filter_var($body['whitelist'] ?? false, FILTER_VALIDATE_BOOLEAN);
$onlineMode     = filter_var($body['online_mode'] ?? true, FILTER_VALIDATE_BOOLEAN);
$viewDistance   = min(32, max(2, (int)($body['view_distance'] ?? 10)));
$spawnProtect   = min(255, max(0, (int)($body['spawn_protection'] ?? 16)));
$javaFlags      = trim((string)($body['java_flags'] ?? ''));

// ── Merge into server_config JSON ────────────────────────────────────────────

$existing = json_decode((string)($server['server_config'] ?? '{}'), true) ?: [];

$config = array_merge($existing, [
    'difficulty'       => $difficulty,
    'gamemode'         => $gamemode,
    'pvp'              => $pvp,
    'whitelist'        => $whitelist,
    'online_mode'      => $onlineMode,
    'view_distance'    => $viewDistance,
    'spawn_protection' => $spawnProtect,
    'java_flags'       => $javaFlags,
    'updated_at'       => date('c'),
]);

// ── Update DB ────────────────────────────────────────────────────────────────

try {
    $fields = ['server_config = ?', 'updated_at = NOW()'];
    $params = [json_encode($config)];

    if ($serverName !== '') {
        $fields[] = 'server_name = ?';
        $params[]  = $serverName;
    }
    if ($motd !== '') {
        $fields[] = 'motd = ?';
        $params[]  = $motd;
    }
    $fields[] = 'max_players = ?';
    $params[]  = $maxPlayers;

    $params[] = $serverId;
    $sql = 'UPDATE mc_servers SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);

    echo json_encode([
        'ok'      => true,
        'message' => 'Paramètres sauvegardés.',
        'config'  => $config,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur DB : ' . $e->getMessage()]);
}

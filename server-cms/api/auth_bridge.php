<?php

/**
 * XynoServer — Auth Bridge (Whitelist partagée)
 *
 * Permet au launcher de vérifier si un joueur MC est autorisé
 * à rejoindre le serveur lié.
 *
 * GET /server-cms/api/auth_bridge.php
 *   ?server_api_key=...
 *   &mc_username=...
 *   &mc_uuid=... (optionnel)
 *
 * Réponse :
 *   { ok: true, allowed: true|false, reason: "..." }
 *
 * POST /server-cms/api/auth_bridge.php
 *   Ajouter/retirer un joueur de la whitelist (session requise)
 *   body: { server_uuid, mc_username, mc_uuid, action: "add"|"remove"|"toggle", _csrf }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo    = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ─────────────────────────────────────────────────────────────────────────
// GET — vérification whitelist (API publique par api_key)
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $serverApiKey = trim((string)($_GET['server_api_key'] ?? ''));
    $mcUsername   = trim((string)($_GET['mc_username'] ?? ''));
    $mcUuid       = trim((string)($_GET['mc_uuid'] ?? ''));

    if ($serverApiKey === '') json_response(['ok' => false, 'error' => 'server_api_key requis'], 400);
    if ($mcUsername === '')  json_response(['ok' => false, 'error' => 'mc_username requis'], 400);

    $server = get_server_by_api_key($pdo, $serverApiKey);
    if (!$server) json_response(['ok' => false, 'error' => 'API key invalide'], 401);

    $config     = json_decode((string)($server['server_config'] ?? '{}'), true) ?: [];
    $whitelistOn = (bool)($config['white-list'] ?? false);

    // Si whitelist désactivée : tout le monde est autorisé
    if (!$whitelistOn) {
        json_response([
            'ok'      => true,
            'allowed' => true,
            'reason'  => 'whitelist_disabled',
        ]);
    }

    // Vérification dans la whitelist
    $stmt = $pdo->prepare(
        'SELECT id, whitelisted FROM mc_server_players '
        . 'WHERE server_id = ? AND (mc_username = ? OR (mc_uuid IS NOT NULL AND mc_uuid = ?)) '
        . 'LIMIT 1'
    );
    $stmt->execute([$server['id'], $mcUsername, $mcUuid ?: $mcUsername]);
    $player = $stmt->fetch();

    if (!$player) {
        json_response(['ok' => true, 'allowed' => false, 'reason' => 'not_whitelisted']);
    }

    if ((int)$player['whitelisted'] !== 1) {
        json_response(['ok' => true, 'allowed' => false, 'reason' => 'banned']);
    }

    // Mettre à jour l'uuid Mojang si fourni
    if ($mcUuid !== '' && $player) {
        $pdo->prepare('UPDATE mc_server_players SET mc_uuid = ? WHERE id = ? AND mc_uuid IS NULL')
            ->execute([$mcUuid, $player['id']]);
    }

    json_response(['ok' => true, 'allowed' => true, 'reason' => 'whitelisted']);
}

// ─────────────────────────────────────────────────────────────────────────
// POST — gestion whitelist (session requise)
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

    $serverUuid  = trim((string)($body['server_uuid'] ?? ''));
    $mcUsername  = trim((string)($body['mc_username'] ?? ''));
    $mcUuid      = trim((string)($body['mc_uuid'] ?? ''));
    $action      = trim((string)($body['action'] ?? 'add'));

    if ($serverUuid === '') json_response(['ok' => false, 'error' => 'server_uuid requis'], 400);
    if ($mcUsername === '') json_response(['ok' => false, 'error' => 'mc_username requis'], 400);

    // Validation username MC
    if (!preg_match('/^[A-Za-z0-9_]{2,16}$/', $mcUsername)) {
        json_response(['ok' => false, 'error' => 'Nom de joueur MC invalide (2–16 caractères alphanumériques)'], 422);
    }

    $server = get_user_server($pdo, $user['id'], $serverUuid);
    if (!$server) json_response(['ok' => false, 'error' => 'Serveur introuvable'], 404);

    // Résolution UUID Mojang si non fourni
    if ($mcUuid === '' && $action === 'add') {
        $mojangRes = http_get("https://api.mojang.com/users/profiles/minecraft/{$mcUsername}");
        if ($mojangRes['status'] === 200) {
            $mojangData = json_decode($mojangRes['body'], true);
            if (is_array($mojangData) && !empty($mojangData['id'])) {
                // Format UUID avec tirets
                $raw_id = $mojangData['id'];
                $mcUuid = preg_replace(
                    '/^(.{8})(.{4})(.{4})(.{4})(.{12})$/',
                    '$1-$2-$3-$4-$5',
                    $raw_id
                ) ?: $raw_id;
            }
        }
    }

    switch ($action) {
        case 'add':
            $pdo->prepare(
                'INSERT INTO mc_server_players (server_id, mc_username, mc_uuid, added_by, whitelisted) '
                . 'VALUES (?, ?, ?, ?, 1) '
                . 'ON DUPLICATE KEY UPDATE mc_uuid = VALUES(mc_uuid), whitelisted = 1'
            )->execute([$server['id'], $mcUsername, $mcUuid ?: null, $user['id']]);

            json_response([
                'ok'          => true,
                'action'      => 'added',
                'mc_username' => $mcUsername,
                'mc_uuid'     => $mcUuid ?: null,
            ]);

        case 'remove':
            $del = $pdo->prepare(
                'DELETE FROM mc_server_players WHERE server_id = ? AND mc_username = ?'
            );
            $del->execute([$server['id'], $mcUsername]);
            json_response(['ok' => true, 'action' => 'removed', 'mc_username' => $mcUsername]);

        case 'toggle':
            $upd = $pdo->prepare(
                'UPDATE mc_server_players SET whitelisted = 1 - whitelisted '
                . 'WHERE server_id = ? AND mc_username = ?'
            );
            $upd->execute([$server['id'], $mcUsername]);
            json_response(['ok' => true, 'action' => 'toggled', 'mc_username' => $mcUsername]);

        default:
            json_response(['ok' => false, 'error' => 'Action invalide (add|remove|toggle)'], 400);
    }
}

json_response(['ok' => false, 'error' => 'Méthode non supportée'], 405);

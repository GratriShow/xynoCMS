<?php
/**
 * POST /server-cms/api/server_players.php
 * Gestion de la whitelist / liste de joueurs d'un serveur.
 *
 * action=add    : ajouter un joueur (mc_username requis)
 * action=remove : supprimer un joueur (player_id requis)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Non authentifié']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct,'application/json')
    ? (json_decode(file_get_contents('php://input') ?: '', true) ?? [])
    : $_POST;

$serverId = (int)($body['server_id'] ?? 0);
$action   = trim((string)($body['action'] ?? ''));
if (!$serverId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'server_id requis']); exit; }

$pdo = db();

// Vérifier propriété
try {
    $s = $pdo->prepare('SELECT id FROM mc_servers WHERE id = ? AND user_id = ? LIMIT 1');
    $s->execute([$serverId, $user['id']]);
    if (!$s->fetch()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit; }
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

try {
    if ($action === 'add') {
        $username = trim((string)($body['mc_username'] ?? ''));
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{1,16}$/', $username)) {
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Pseudo invalide (1-16 caractères alphanumériques)']); exit;
        }

        // Optionnel : résoudre UUID Mojang
        $mcUuid = null;
        try {
            $res = @file_get_contents('https://api.mojang.com/users/profiles/minecraft/' . urlencode($username));
            if ($res) {
                $data = json_decode($res, true);
                if (!empty($data['id'])) {
                    // Formater en UUID avec tirets
                    $raw = $data['id'];
                    $mcUuid = substr($raw,0,8).'-'.substr($raw,8,4).'-'.substr($raw,12,4).'-'.substr($raw,16,4).'-'.substr($raw,20);
                }
            }
        } catch (Throwable) {}

        $stmt = $pdo->prepare(
            'INSERT INTO mc_server_players (server_id, mc_username, mc_uuid, added_by, whitelisted)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE mc_uuid = VALUES(mc_uuid), whitelisted = 1'
        );
        $stmt->execute([$serverId, $username, $mcUuid, $user['id']]);
        $newId = (int)$pdo->lastInsertId();
        if (!$newId) {
            // Already exists — get its id
            $s = $pdo->prepare('SELECT id FROM mc_server_players WHERE server_id=? AND mc_username=? LIMIT 1');
            $s->execute([$serverId, $username]); $row = $s->fetch();
            $newId = $row ? (int)$row['id'] : 0;
        }
        echo json_encode(['ok'=>true,'player'=>['id'=>$newId,'mc_username'=>$username,'mc_uuid'=>$mcUuid,'whitelisted'=>1,'added_at'=>date('Y-m-d H:i:s')]]);

    } elseif ($action === 'remove') {
        $playerId = (int)($body['player_id'] ?? 0);
        if (!$playerId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'player_id requis']); exit; }
        $pdo->prepare('DELETE FROM mc_server_players WHERE id = ? AND server_id = ?')->execute([$playerId, $serverId]);
        echo json_encode(['ok'=>true,'deleted'=>$playerId]);

    } else {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>"action inconnue: $action"]);
    }
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

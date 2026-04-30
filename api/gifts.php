<?php

declare(strict_types=1);

/**
 * Gift redemption API endpoint
 *
 * POST /api/gifts.php
 * Body: { "code": "GIFT123ABC" }
 *
 * Response:
 * {
 *   "ok": true,
 *   "gift": {
 *     "id": 1,
 *     "type": "coupon",
 *     "value": 50,
 *     "description": "Black Friday 50%"
 *   }
 * }
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login_api();
$pdo  = db();

if (!is_post()) {
    api_error('Only POST requests allowed', 405);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    api_error('Invalid JSON body', 400);
}

$code = trim((string)($body['code'] ?? ''));

if ($code === '') {
    api_error('Code is required', 400);
}

try {
    // Check if code was already redeemed by this user
    $st = $pdo->prepare(
        "SELECT redeemed_at FROM gift_recipients WHERE user_id = ? AND code = ? LIMIT 1"
    );
    $st->execute([(int)$user['id'], $code]);
    $already_redeemed = $st->fetch();

    if ($already_redeemed && !empty($already_redeemed['redeemed_at'])) {
        api_error('This code has already been redeemed', 400);
    }

    // Find the gift
    $gift_info = null;

    // Try as single code first
    $st = $pdo->prepare(
        "SELECT g.id, g.type, g.value, g.description, g.expires_at FROM gifts g WHERE g.code = ? AND g.expires_at > NOW() LIMIT 1"
    );
    $st->execute([$code]);
    $gift_info = $st->fetch();

    if (!$gift_info) {
        // Try as unique code
        $st = $pdo->prepare(
            "SELECT g.id, g.type, g.value, g.description, g.expires_at, gc.id as code_id "
          . "FROM gift_codes gc "
          . "INNER JOIN gifts g ON g.id = gc.gift_id "
          . "WHERE gc.code = ? AND gc.redeemed_at IS NULL AND g.expires_at > NOW() "
          . "LIMIT 1"
        );
        $st->execute([$code]);
        $gift_info = $st->fetch();
    }

    if (!$gift_info) {
        api_error('Invalid or expired code', 400);
    }

    // Apply the gift
    $gift_id = (int)$gift_info['id'];
    $gift_type = (string)$gift_info['type'];
    $gift_value = (int)$gift_info['value'];
    $user_id = (int)$user['id'];

    // Mark as redeemed in gift_recipients
    $pdo->prepare(
        "UPDATE gift_recipients SET redeemed_at = NOW() WHERE user_id = ? AND code = ? LIMIT 1"
    )->execute([$user_id, $code]);

    // Mark code as redeemed if it's a unique code
    if (!empty($gift_info['code_id'])) {
        $pdo->prepare(
            "UPDATE gift_codes SET redeemed_by = ?, redeemed_at = NOW() WHERE id = ? LIMIT 1"
        )->execute([$user_id, (int)$gift_info['code_id']]);
    }

    // Log the redemption
    admin_log(0, 'redeem_gift_api', 'gift', $gift_id, "user=$user_id");

    // Return success
    api_response([
        'ok'   => true,
        'gift' => [
            'id'          => $gift_id,
            'type'        => $gift_type,
            'value'       => $gift_value,
            'description' => (string)$gift_info['description'],
        ]
    ]);

} catch (PDOException $e) {
    api_error('Database error', 500);
} catch (Throwable $e) {
    api_error('Unexpected error', 500);
}

function admin_log(int $adminId, string $action, string $targetType = '', ?int $targetId = null, ?string $notes = null): void
{
    try {
        $pdo = db();
        $st = $pdo->prepare(
            'INSERT INTO admin_actions (admin_id, action, target_type, target_id, notes, ip, created_at) '
          . 'VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([$adminId, $action, $targetType, $targetId, $notes, api_client_ip()]);
    } catch (Throwable $e) { /* never break the flow */ }
}

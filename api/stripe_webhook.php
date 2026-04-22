<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * POST /api/stripe_webhook.php
 *
 * Stripe webhook endpoint. Handles:
 *   - checkout.session.completed       → mark purchase paid
 *   - checkout.session.async_payment_succeeded → same
 *   - charge.refunded / charge.refund.updated → mark purchase refunded
 *
 * Signature verification: Stripe sends `Stripe-Signature: t=<ts>,v1=<sig>`.
 * We compute HMAC-SHA256("${t}.${rawBody}", STRIPE_WEBHOOK_SECRET) and
 * compare it against every `v1=` value (can be multiple during key rotation).
 *
 * Env:
 *   - STRIPE_WEBHOOK_SECRET  (whsec_...)
 *   - STRIPE_WEBHOOK_TOLERANCE_SECONDS (optional, default 300)
 */

// We MUST read the raw body BEFORE any other PHP input parsing happens.
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) {
    $rawBody = '';
}

header('Content-Type: application/json; charset=utf-8');

function wh_respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wh_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$secret = trim(api_env('STRIPE_WEBHOOK_SECRET', ''));
if ($secret === '') {
    wh_respond(500, ['ok' => false, 'error' => 'webhook_secret_not_configured']);
}

$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if ($sigHeader === '') {
    wh_respond(400, ['ok' => false, 'error' => 'missing_signature']);
}

// Parse "t=...,v1=...,v1=...,v0=..."
$parts = [];
foreach (explode(',', $sigHeader) as $piece) {
    $piece = trim($piece);
    if ($piece === '') continue;
    $eq = strpos($piece, '=');
    if ($eq === false) continue;
    $k = substr($piece, 0, $eq);
    $v = substr($piece, $eq + 1);
    if (!isset($parts[$k])) $parts[$k] = [];
    $parts[$k][] = $v;
}

$timestamp = (int)($parts['t'][0] ?? 0);
$v1Values  = $parts['v1'] ?? [];

if ($timestamp <= 0 || empty($v1Values)) {
    wh_respond(400, ['ok' => false, 'error' => 'malformed_signature']);
}

// Replay protection (default 5 min tolerance).
$tolerance = (int)api_env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', '300');
if ($tolerance <= 0) $tolerance = 300;
if (abs(time() - $timestamp) > $tolerance) {
    wh_respond(400, ['ok' => false, 'error' => 'timestamp_outside_tolerance']);
}

// Constant-time compare the HMAC against every v1 candidate.
$signedPayload = $timestamp . '.' . $rawBody;
$expected = hash_hmac('sha256', $signedPayload, $secret);

$valid = false;
foreach ($v1Values as $candidate) {
    if (is_string($candidate) && hash_equals($expected, $candidate)) {
        $valid = true;
        break;
    }
}
if (!$valid) {
    wh_respond(400, ['ok' => false, 'error' => 'invalid_signature']);
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    wh_respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$type = (string)($event['type'] ?? '');
$data = is_array($event['data'] ?? null) ? $event['data'] : [];
$object = is_array($data['object'] ?? null) ? $data['object'] : [];

try {
    $pdo = db();

    switch ($type) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded': {
            // Only mark paid if payment_status is actually 'paid' or 'no_payment_required'.
            $paymentStatus = (string)($object['payment_status'] ?? '');
            if (!in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
                // Still 200 so Stripe doesn't retry, but record nothing yet.
                wh_respond(200, ['ok' => true, 'ignored' => 'payment_not_paid', 'payment_status' => $paymentStatus]);
            }

            $sessionId     = (string)($object['id'] ?? '');
            $paymentIntent = (string)($object['payment_intent'] ?? '');
            $amount        = (int)($object['amount_total'] ?? 0);
            $currency      = strtolower((string)($object['currency'] ?? 'eur'));
            $meta          = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
            $clientRef     = (string)($object['client_reference_id'] ?? '');

            // Derive launcher_id + item_key from metadata first, then from client_reference_id.
            $launcherId = (int)($meta['launcher_id'] ?? 0);
            $itemKey    = strtolower(trim((string)($meta['item_key'] ?? '')));

            if (($launcherId <= 0 || $itemKey === '') && $clientRef !== '') {
                $bits = explode(':', $clientRef, 2);
                if ($launcherId <= 0 && isset($bits[0])) $launcherId = (int)$bits[0];
                if ($itemKey === '' && isset($bits[1]))  $itemKey = strtolower(trim((string)$bits[1]));
            }

            if ($launcherId <= 0 || $itemKey === '' || !in_array($itemKey, api_marketplace_catalog_keys(), true)) {
                wh_respond(200, ['ok' => true, 'ignored' => 'unresolvable_reference']);
            }

            // Flip/insert the purchase row to 'paid' — idempotent.
            $stmt = $pdo->prepare(
                'INSERT INTO marketplace_purchases '
              . '(launcher_id, item_key, stripe_session_id, stripe_payment_intent, '
              . ' amount_cents, currency, status, purchased_at, created_at) '
              . 'VALUES (?, ?, ?, ?, ?, ?, \'paid\', NOW(), NOW()) '
              . 'ON DUPLICATE KEY UPDATE '
              . '  stripe_session_id = VALUES(stripe_session_id), '
              . '  stripe_payment_intent = VALUES(stripe_payment_intent), '
              . '  amount_cents = VALUES(amount_cents), '
              . '  currency = VALUES(currency), '
              . '  status = \'paid\', '
              . '  purchased_at = COALESCE(purchased_at, NOW())'
            );
            $stmt->execute([
                $launcherId,
                $itemKey,
                $sessionId !== '' ? $sessionId : null,
                $paymentIntent !== '' ? $paymentIntent : null,
                $amount,
                $currency,
            ]);

            wh_respond(200, ['ok' => true, 'applied' => 'paid', 'launcher_id' => $launcherId, 'item_key' => $itemKey]);
        }

        case 'checkout.session.expired':
        case 'checkout.session.async_payment_failed': {
            // Nothing to do — the row will stay 'pending' and never unlock.
            wh_respond(200, ['ok' => true, 'ignored' => $type]);
        }

        case 'charge.refunded':
        case 'charge.refund.updated': {
            // Use the payment_intent to find our row.
            $paymentIntent = (string)($object['payment_intent'] ?? '');
            if ($paymentIntent === '') {
                wh_respond(200, ['ok' => true, 'ignored' => 'no_payment_intent']);
            }

            $stmt = $pdo->prepare(
                'UPDATE marketplace_purchases SET status = \'refunded\' '
              . 'WHERE stripe_payment_intent = ? AND status = \'paid\''
            );
            $stmt->execute([$paymentIntent]);
            wh_respond(200, ['ok' => true, 'applied' => 'refunded', 'rows' => $stmt->rowCount()]);
        }

        default:
            // Unhandled event type — still 200 so Stripe marks it as delivered.
            wh_respond(200, ['ok' => true, 'ignored' => $type]);
    }
} catch (Throwable $e) {
    // 500 so Stripe retries transient errors, but do not leak stack traces.
    $msg = $e->getMessage();
    if (strpos($msg, 'marketplace_purchases') !== false
        || strpos($msg, "doesn't exist") !== false
        || strpos($msg, 'does not exist') !== false) {
        wh_respond(500, ['ok' => false, 'error' => 'migration_missing']);
    }
    wh_respond(500, ['ok' => false, 'error' => 'db_error']);
}

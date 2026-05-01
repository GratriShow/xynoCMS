<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/subscription_helpers.php';
require_once __DIR__ . '/email_helpers.php';

/**
 * Helper : best-effort lookup of (email, launcher_name) from a subscription row.
 * Returns null if anything is missing — never throws.
 */
function wh_lookup_user_for_sub(PDO $pdo, string $stripeSubId): ?array
{
    if ($stripeSubId === '') return null;
    try {
        $st = $pdo->prepare(
            "SELECT u.id AS user_id, u.email AS email, l.name AS launcher_name, "
          . "       s.plan, s.period, s.amount_cents, s.currency, s.expires_at "
          . "FROM subscriptions s "
          . "INNER JOIN users u ON u.id = s.user_id "
          . "LEFT JOIN launchers l ON l.id = s.launcher_id "
          . "WHERE s.stripe_subscription_id = ? LIMIT 1"
        );
        $st->execute([$stripeSubId]);
        $row = $st->fetch();
        return $row ? $row : null;
    } catch (Throwable $e) { return null; }
}

function wh_lookup_user_for_session(PDO $pdo, string $stripeSessionId): ?array
{
    if ($stripeSessionId === '') return null;
    try {
        $st = $pdo->prepare(
            "SELECT u.id AS user_id, u.email AS email, l.name AS launcher_name, "
          . "       s.plan, s.period, s.amount_cents, s.currency "
          . "FROM subscriptions s "
          . "INNER JOIN users u ON u.id = s.user_id "
          . "LEFT JOIN launchers l ON l.id = s.launcher_id "
          . "WHERE s.stripe_session_id = ? LIMIT 1"
        );
        $st->execute([$stripeSessionId]);
        $row = $st->fetch();
        return $row ? $row : null;
    } catch (Throwable $e) { return null; }
}

/**
 * POST /api/stripe_webhook.php
 *
 * Stripe webhook endpoint. Handles two product flows:
 *
 *   Marketplace (one-shot, mode=payment) :
 *     - checkout.session.completed                → mark purchase paid
 *     - checkout.session.async_payment_succeeded  → same
 *     - charge.refunded / charge.refund.updated   → mark purchase refunded
 *
 *   Subscriptions (recurring, mode=subscription) :
 *     - checkout.session.completed                → activate subscription
 *     - customer.subscription.updated             → status sync
 *     - customer.subscription.deleted             → mark expired/cancelled
 *     - invoice.payment_succeeded / invoice.paid  → renew expires_at
 *     - invoice.payment_failed                    → status past_due
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
            // Check metadata kind first to detect hosting upgrades (can be mode=payment or mode=subscription)
            $meta = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
            $kind = strtolower(trim((string)($meta['kind'] ?? '')));

            if ($kind === 'hosting_upgrade') {
                // Hosting upgrades: one-time payment (mode=payment) or legacy subscription (mode=subscription)
                wh_handle_hosting_upgrade_checkout($pdo, $object, $type);
                // wh_handle_* always exits via wh_respond.
            }

            // mode=subscription is handled separately from one-shot purchases.
            $mode = strtolower((string)($object['mode'] ?? ''));
            if ($mode === 'subscription') {
                wh_handle_subscription_checkout($pdo, $object, $type);
                // wh_handle_* always exits via wh_respond.
            }

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

        // ===== Subscription lifecycle =====================================

        case 'customer.subscription.updated': {
            wh_handle_subscription_updated($pdo, $object);
        }

        case 'customer.subscription.deleted': {
            wh_handle_subscription_deleted($pdo, $object);
        }

        case 'invoice.paid':
        case 'invoice.payment_succeeded': {
            wh_handle_invoice_paid($pdo, $object);
        }

        case 'invoice.payment_failed': {
            wh_handle_invoice_failed($pdo, $object);
        }

        default:
            // Unhandled event type — still 200 so Stripe marks it as delivered.
            wh_respond(200, ['ok' => true, 'ignored' => $type]);
    }
} catch (Throwable $e) {
    // 500 so Stripe retries transient errors, but do not leak stack traces.
    $msg = $e->getMessage();
    if (strpos($msg, 'marketplace_purchases') !== false
        || strpos($msg, 'subscriptions') !== false
        || strpos($msg, "doesn't exist") !== false
        || strpos($msg, 'does not exist') !== false) {
        wh_respond(500, ['ok' => false, 'error' => 'migration_missing']);
    }
    wh_respond(500, ['ok' => false, 'error' => 'db_error']);
}

// =============================================================================
// Subscription helpers (called from the switch above).
// =============================================================================

/**
 * Activate a subscription after a successful Checkout in subscription mode.
 *
 * Stripe sends us:
 *   - object.id                 → checkout session id (cs_...)
 *   - object.subscription       → sub_... (the recurring object)
 *   - object.customer           → cus_...
 *   - object.metadata           → { launcher_id, plan, period, user_id, ... }
 *   - object.amount_total       → cents charged for the first cycle
 */
function wh_handle_subscription_checkout(PDO $pdo, array $object, string $eventType): void
{
    $sessionId      = (string)($object['id'] ?? '');
    $subscriptionId = (string)($object['subscription'] ?? '');
    $customerId     = (string)($object['customer'] ?? '');
    $amount         = (int)($object['amount_total'] ?? 0);
    $currency       = strtolower((string)($object['currency'] ?? 'eur'));
    $meta           = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
    $clientRef      = (string)($object['client_reference_id'] ?? '');

    $launcherId = (int)($meta['launcher_id'] ?? 0);
    $userId     = (int)($meta['user_id'] ?? 0);
    $plan       = subscription_normalize_plan((string)($meta['plan'] ?? ''));
    $period     = subscription_normalize_period((string)($meta['period'] ?? ''));

    // Fallback: client_reference_id = "<launcherId>:<plan>:<period>"
    if (($launcherId <= 0 || $plan === '' || $period === '') && $clientRef !== '') {
        $bits = explode(':', $clientRef);
        if ($launcherId <= 0 && isset($bits[0])) $launcherId = (int)$bits[0];
        if ($plan === ''     && isset($bits[1])) $plan       = subscription_normalize_plan($bits[1]);
        if ($period === ''   && isset($bits[2])) $period     = subscription_normalize_period($bits[2]);
    }

    if ($launcherId <= 0) {
        wh_respond(200, ['ok' => true, 'ignored' => 'unresolvable_launcher', 'event' => $eventType]);
    }

    // Compute next_billing_at from the period (most reliable source we have here).
    $nextBilling = subscription_next_billing($period ?: 'monthly');

    // Flip the pending row to 'active'. We key on stripe_session_id so the
    // operation is idempotent across retries.
    try {
        $stmt = $pdo->prepare(
            "UPDATE subscriptions "
          . "SET status = 'active', "
          . "    stripe_subscription_id = ?, "
          . "    stripe_customer_id = ?, "
          . "    amount_cents = ?, "
          . "    currency = ?, "
          . "    expires_at = ?, "
          . "    next_billing_at = ?, "
          . "    cancelled_at = NULL "
          . "WHERE stripe_session_id = ? "
          . "LIMIT 1"
        );
        $stmt->execute([
            $subscriptionId !== '' ? $subscriptionId : null,
            $customerId !== ''     ? $customerId     : null,
            $amount,
            $currency,
            $nextBilling,
            $nextBilling,
            $sessionId,
        ]);
        $rows = $stmt->rowCount();
    } catch (Throwable $e) {
        $rows = 0;
    }

    // If the pre-insert from subscription_helpers.php never happened (e.g.
    // payment was triggered without going through our endpoint), insert a
    // fresh row keyed on the Stripe subscription id.
    if ($rows === 0 && $subscriptionId !== '' && $userId > 0 && $plan !== '' && $period !== '') {
        try {
            $ins = $pdo->prepare(
                "INSERT INTO subscriptions "
              . "(user_id, launcher_id, status, plan, period, "
              . " stripe_session_id, stripe_subscription_id, stripe_customer_id, "
              . " amount_cents, currency, expires_at, next_billing_at, created_at) "
              . "VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $ins->execute([
                $userId,
                $launcherId,
                $plan,
                $period,
                $sessionId !== '' ? $sessionId : null,
                $subscriptionId,
                $customerId !== '' ? $customerId : null,
                $amount,
                $currency,
                $nextBilling,
                $nextBilling,
            ]);
            $rows = $ins->rowCount();
        } catch (Throwable $e) {
            // ignore; we still want to 200 to Stripe
        }
    }

    // Email de confirmation (best-effort, n'empêche jamais le webhook).
    try {
        $info = wh_lookup_user_for_session($pdo, $sessionId);
        if ($info && !empty($info['email'])) {
            send_payment_success_email(
                (string)$info['email'],
                (int)$info['user_id'],
                (string)($info['launcher_name'] ?? 'Mon launcher'),
                (string)($info['plan'] ?? $plan),
                (string)($info['period'] ?? $period),
                (int)($info['amount_cents'] ?? $amount),
                (string)($info['currency'] ?? $currency)
            );
        }
    } catch (Throwable $e) { /* ignore */ }

    wh_respond(200, [
        'ok'              => true,
        'applied'         => 'subscription_active',
        'launcher_id'     => $launcherId,
        'rows_updated'    => $rows,
        'subscription_id' => $subscriptionId,
    ]);
}

/**
 * customer.subscription.updated — sync status (active / past_due / cancelled).
 *
 * Stripe payload keys we care about:
 *   - object.id                  → sub_...
 *   - object.status              → active | past_due | unpaid | canceled | ...
 *   - object.cancel_at_period_end → bool
 *   - object.current_period_end   → unix ts
 */
function wh_handle_subscription_updated(PDO $pdo, array $object): void
{
    $subId  = (string)($object['id'] ?? '');
    $status = strtolower((string)($object['status'] ?? ''));
    $cape   = (bool)($object['cancel_at_period_end'] ?? false);
    $cpe    = (int)($object['current_period_end'] ?? 0);

    if ($subId === '') {
        wh_respond(200, ['ok' => true, 'ignored' => 'no_subscription_id']);
    }

    // Map Stripe status → our enum.
    $localStatus = 'active';
    if ($status === 'past_due' || $status === 'unpaid' || $status === 'incomplete') {
        $localStatus = 'past_due';
    } elseif ($status === 'canceled' || $status === 'cancelled' || $status === 'incomplete_expired') {
        $localStatus = 'cancelled';
    } elseif ($status === 'trialing' || $status === 'active') {
        $localStatus = 'active';
    }

    // If user clicked "Cancel at period end" in their portal, we keep them
    // active until the period ends but flag cancelled_at.
    $cancelledAt = $cape ? date('Y-m-d H:i:s') : null;
    $expiresAt   = $cpe > 0 ? date('Y-m-d H:i:s', $cpe) : null;

    $stmt = $pdo->prepare(
        "UPDATE subscriptions "
      . "SET status = ?, "
      . "    expires_at = COALESCE(?, expires_at), "
      . "    next_billing_at = COALESCE(?, next_billing_at), "
      . "    cancelled_at = COALESCE(?, cancelled_at) "
      . "WHERE stripe_subscription_id = ? "
      . "LIMIT 1"
    );
    $stmt->execute([$localStatus, $expiresAt, $expiresAt, $cancelledAt, $subId]);

    wh_respond(200, [
        'ok'      => true,
        'applied' => 'subscription_updated',
        'status'  => $localStatus,
        'rows'    => $stmt->rowCount(),
    ]);
}

/**
 * customer.subscription.deleted — the subscription is fully terminated.
 */
function wh_handle_subscription_deleted(PDO $pdo, array $object): void
{
    $subId = (string)($object['id'] ?? '');
    if ($subId === '') {
        wh_respond(200, ['ok' => true, 'ignored' => 'no_subscription_id']);
    }

    $stmt = $pdo->prepare(
        "UPDATE subscriptions "
      . "SET status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE 'expired' END, "
      . "    cancelled_at = COALESCE(cancelled_at, NOW()) "
      . "WHERE stripe_subscription_id = ? "
      . "LIMIT 1"
    );
    $stmt->execute([$subId]);

    try {
        $info = wh_lookup_user_for_sub($pdo, $subId);
        if ($info && !empty($info['email'])) {
            $exp = !empty($info['expires_at']) ? date('d/m/Y', strtotime((string)$info['expires_at'])) : date('d/m/Y');
            send_subscription_cancelled_email(
                (string)$info['email'],
                (int)$info['user_id'],
                (string)($info['launcher_name'] ?? 'Mon launcher'),
                $exp
            );
        }
    } catch (Throwable $e) { /* ignore */ }

    wh_respond(200, [
        'ok'      => true,
        'applied' => 'subscription_deleted',
        'rows'    => $stmt->rowCount(),
    ]);
}

/**
 * invoice.paid / invoice.payment_succeeded — bump next_billing_at + expires_at
 * forward by one period (uses Stripe's authoritative period_end).
 */
function wh_handle_invoice_paid(PDO $pdo, array $object): void
{
    $subId = (string)($object['subscription'] ?? '');
    if ($subId === '') {
        wh_respond(200, ['ok' => true, 'ignored' => 'no_subscription']);
    }

    // Stripe provides 'lines.data[0].period.end' or top-level
    // 'period_end' on invoice; prefer the line item.
    $periodEnd = 0;
    $lines     = is_array($object['lines']['data'] ?? null) ? $object['lines']['data'] : [];
    foreach ($lines as $line) {
        if (is_array($line) && is_array($line['period'] ?? null)) {
            $periodEnd = max($periodEnd, (int)($line['period']['end'] ?? 0));
        }
    }
    if ($periodEnd === 0) {
        $periodEnd = (int)($object['period_end'] ?? 0);
    }

    $expiresAt = $periodEnd > 0 ? date('Y-m-d H:i:s', $periodEnd) : null;

    $stmt = $pdo->prepare(
        "UPDATE subscriptions "
      . "SET status = CASE WHEN status IN ('cancelled') THEN status ELSE 'active' END, "
      . "    expires_at = COALESCE(?, expires_at), "
      . "    next_billing_at = COALESCE(?, next_billing_at) "
      . "WHERE stripe_subscription_id = ? "
      . "LIMIT 1"
    );
    $stmt->execute([$expiresAt, $expiresAt, $subId]);

    wh_respond(200, [
        'ok'      => true,
        'applied' => 'invoice_paid',
        'rows'    => $stmt->rowCount(),
    ]);
}

/**
 * invoice.payment_failed — flip to past_due (we don't auto-cancel ; Stripe's
 * Smart Retries will re-attempt, and customer.subscription.deleted will fire
 * if the subscription is finally killed).
 */
function wh_handle_invoice_failed(PDO $pdo, array $object): void
{
    $subId = (string)($object['subscription'] ?? '');
    if ($subId === '') {
        wh_respond(200, ['ok' => true, 'ignored' => 'no_subscription']);
    }

    $stmt = $pdo->prepare(
        "UPDATE subscriptions "
      . "SET status = 'past_due' "
      . "WHERE stripe_subscription_id = ? AND status NOT IN ('cancelled') "
      . "LIMIT 1"
    );
    $stmt->execute([$subId]);

    try {
        $info = wh_lookup_user_for_sub($pdo, $subId);
        if ($info && !empty($info['email'])) {
            send_payment_failed_email(
                (string)$info['email'],
                (int)$info['user_id'],
                (string)($info['launcher_name'] ?? 'Mon launcher')
            );
        }
    } catch (Throwable $e) { /* ignore */ }

    wh_respond(200, [
        'ok'      => true,
        'applied' => 'invoice_failed',
        'rows'    => $stmt->rowCount(),
    ]);
}

/**
 * Handle hosting upgrade checkout (one-time payment).
 *
 * When a user adds hosting (+5€/month) to an existing subscription via
 * /api/subscription_hosting_upgrade.php:
 *   1. Payment mode is 'payment' (one-time charge for prorata)
 *   2. After payment succeeds, we create a recurring subscription for 5€/month
 *   3. Recurring subscription starts at next_billing_at (launcher renewal date)
 *
 * We update the hosting_upgrades table to 'active', flip launcher.hosting to 1,
 * and create the recurring subscription via Stripe API.
 */
function wh_handle_hosting_upgrade_checkout(PDO $pdo, array $object, string $eventType): void
{
    $sessionId      = (string)($object['id'] ?? '');
    $customerId     = (string)($object['customer'] ?? '');
    $meta           = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

    $launcherId   = (int)($meta['launcher_id'] ?? 0);
    $userId       = (int)($meta['user_id'] ?? 0);
    $launcherUuid = (string)($meta['launcher_uuid'] ?? '');
    $nextBillingAt = (string)($meta['next_billing_at'] ?? '');

    if ($launcherId <= 0 || $sessionId === '') {
        wh_respond(200, ['ok' => true, 'ignored' => 'unresolvable_hosting_upgrade', 'event' => $eventType]);
    }

    // Update hosting_upgrades row to 'active'
    try {
        $stmt = $pdo->prepare(
            "UPDATE hosting_upgrades "
            . "SET status = 'active', "
            . "    updated_at = NOW() "
            . "WHERE stripe_session_id = ? "
            . "LIMIT 1"
        );
        $stmt->execute([$sessionId]);
    } catch (Throwable $e) {
        // Table may not exist yet
    }

    // Update launcher.hosting = 1 to enable hosting for this launcher
    try {
        $stmt = $pdo->prepare(
            "UPDATE launchers "
            . "SET hosting = 1, hosting_price_cents = 500 "
            . "WHERE id = ? "
            . "LIMIT 1"
        );
        $stmt->execute([$launcherId]);
    } catch (Throwable $e) {
        // Column may not exist yet
    }

    // Create recurring subscription for 5€/month starting at next_billing_at
    // This is done via Stripe API, not via the checkout session
    if ($customerId !== '' && $nextBillingAt !== '') {
        try {
            $secretKey = trim(api_env('STRIPE_SECRET_KEY', ''));
            $currency  = strtolower(trim(api_env('MARKETPLACE_CURRENCY', 'eur'))) ?: 'eur';

            if ($secretKey === '') {
                throw new Exception('STRIPE_SECRET_KEY not configured');
            }

            // Create subscription via Stripe API
            $subPayload = [
                'customer'            => $customerId,
                'items[0][price_data][currency]'           => $currency,
                'items[0][price_data][unit_amount]'        => 500,  // 5€ in cents
                'items[0][price_data][recurring][interval]' => 'month',
                'items[0][price_data][product_data][name]' => 'XynoLauncher Hébergement — 5€/mois',
                'items[0][quantity]'                        => 1,
                'billing_cycle_anchor' => (int)strtotime($nextBillingAt),
                'off_session'          => 'true',
                'metadata[launcher_id]'   => (string)$launcherId,
                'metadata[launcher_uuid]' => $launcherUuid,
                'metadata[user_id]'       => (string)$userId,
                'metadata[kind]'          => 'hosting_recurring',
            ];

            $ch = curl_init('https://api.stripe.com/v1/subscriptions');
            if ($ch === false) {
                throw new Exception('curl_init failed');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($subPayload, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode($secretKey . ':'),
                    'Stripe-Version: 2024-06-20',
                    'Accept: application/json',
                ],
            ]);

            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Stripe API success is 2xx
            if ($code < 200 || $code >= 300) {
                $decoded = json_decode((string)$resp, true);
                $errMsg  = is_array($decoded['error'] ?? null)
                    ? (string)($decoded['error']['message'] ?? 'Unknown error')
                    : 'HTTP ' . $code;
                throw new Exception('Stripe subscription creation failed: ' . $errMsg);
            }
        } catch (Throwable $e) {
            // Log the error but don't fail the webhook response
            // Hosting is already enabled; subscription will be retried or created manually if needed
            error_log('Failed to create hosting recurring subscription: ' . $e->getMessage());
        }
    }

    // Send confirmation email (best-effort)
    try {
        $stmt = $pdo->prepare(
            "SELECT u.email, u.id, l.name FROM users u "
            . "LEFT JOIN launchers l ON l.id = ? "
            . "WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$launcherId, $userId]);
        $info = $stmt->fetch();
        if ($info && !empty($info['email'])) {
            send_hosting_upgrade_email(
                (string)$info['email'],
                (int)$info['id'],
                (string)($info['name'] ?? 'Mon launcher')
            );
        }
    } catch (Throwable $e) { /* ignore */ }

    wh_respond(200, [
        'ok'              => true,
        'applied'         => 'hosting_upgrade_active',
        'launcher_id'     => $launcherId,
        'launcher_uuid'   => $launcherUuid,
    ]);
}

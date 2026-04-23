<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * POST /api/marketplace_checkout.php
 *
 * Creates a Stripe Checkout session for a one-shot, per-launcher marketplace item.
 *
 * Form fields:
 *   - csrf_token     (required)
 *   - launcher_uuid  (required; must be owned by current session user)
 *   - item_key       (required; must be in api_marketplace_catalog_keys())
 *
 * Flow:
 *   1. require_login + CSRF check
 *   2. verify the launcher belongs to the current user
 *   3. refuse if the launcher already owns the item
 *   4. insert a `pending` row in marketplace_purchases (so the webhook can
 *      flip it to `paid` by session_id)
 *   5. hit POST https://api.stripe.com/v1/checkout/sessions with Basic auth
 *      (sk_test_* / sk_live_* as user:password) and form-encoded body
 *   6. redirect to the returned session.url
 *
 * Stripe keys are read from env (config/.env.local or server env):
 *   - STRIPE_SECRET_KEY
 *   - STRIPE_PUBLIC_KEY     (not used here — exposed to the dashboard)
 *   - MARKETPLACE_CURRENCY  (default 'eur')
 */

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
$itemKey      = strtolower(trim((string)($_POST['item_key'] ?? '')));

if ($launcherUuid === '' || $itemKey === '') {
    flash_set('error', 'Requête invalide.');
    redirect('/dashboard.php');
}

// Item must exist in the local catalog.
$item = api_marketplace_get_item($itemKey);
if ($item === null) {
    flash_set('error', 'Article introuvable.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
}

$secretKey = trim(api_env('STRIPE_SECRET_KEY', ''));
if ($secretKey === '') {
    flash_set(
        'error',
        'Stripe n’est pas configuré : définis STRIPE_SECRET_KEY dans config/.env.local '
      . '(voir config/.env.local.example).'
    );
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
}

try {
    $pdo = db();

    // Ownership check: this user must own this launcher.
    $own = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $own->execute([$launcherUuid, $user['id']]);
    $launcherRow = $own->fetch();
    if (!$launcherRow) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }
    $launcherId = (int)($launcherRow['id'] ?? 0);

    // Already owned? No double-charge.
    if (api_marketplace_owns($launcherId, $itemKey)) {
        flash_set('success', 'Article déjà débloqué pour ce launcher.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
    }

    $amount   = (int)($item['price_cents'] ?? 0);
    $currency = strtolower(trim((string)($item['currency'] ?? 'eur'))) ?: 'eur';
    $label    = (string)($item['name'] ?? $itemKey);
    $descr    = (string)($item['description'] ?? '');

    if ($amount <= 0) {
        flash_set('error', 'Prix invalide pour cet article.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
    }

    // success/cancel URLs — stay on the same dashboard launcher tab.
    $successUrl = api_public_url(
        '/dashboard.php?launcher=' . rawurlencode($launcherUuid)
      . '&tab=marketplace&mp_success=1&session_id={CHECKOUT_SESSION_ID}#tab-marketplace'
    );
    $cancelUrl = api_public_url(
        '/dashboard.php?launcher=' . rawurlencode($launcherUuid)
      . '&tab=marketplace&mp_cancel=1#tab-marketplace'
    );

    // Build the Stripe form body.
    // NOTE: Stripe expects nested params as bracketed form fields, and
    // http_build_query() produces that exact encoding with numeric keys.
    $payload = [
        'mode'                    => 'payment',
        'payment_method_types'    => ['card'],
        'success_url'             => $successUrl,
        'cancel_url'              => $cancelUrl,
        'client_reference_id'     => $launcherId . ':' . $itemKey,
        'metadata'                => [
            'launcher_id'  => (string)$launcherId,
            'launcher_uuid'=> $launcherUuid,
            'item_key'     => $itemKey,
            'user_id'      => (string)$user['id'],
        ],
        'line_items'              => [
            [
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $amount,
                    'product_data' => array_filter([
                        'name'        => $label,
                        'description' => $descr !== '' ? $descr : null,
                    ], fn ($v) => $v !== null),
                ],
            ],
        ],
    ];

    $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    if ($ch === false) {
        flash_set('error', 'Impossible de contacter Stripe (curl indisponible).');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
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
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $resp === '') {
        flash_set('error', 'Erreur réseau Stripe : ' . ($err !== '' ? $err : 'réponse vide'));
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
    }

    $decoded = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        $stripeMsg = is_array($decoded['error'] ?? null)
            ? (string)($decoded['error']['message'] ?? 'Erreur Stripe')
            : ('HTTP ' . $code);
        flash_set('error', 'Stripe a refusé la requête : ' . $stripeMsg);
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
    }

    $sessionId = (string)($decoded['id'] ?? '');
    $sessionUrl = (string)($decoded['url'] ?? '');
    $paymentIntent = (string)($decoded['payment_intent'] ?? '');

    if ($sessionId === '' || $sessionUrl === '') {
        flash_set('error', 'Réponse Stripe incomplète.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
    }

    // Pre-insert a pending row. The webhook will flip status to 'paid'.
    // If a prior pending row exists for the same (launcher_id, item_key),
    // we refresh its session id so only the latest attempt matters.
    $ins = $pdo->prepare(
        'INSERT INTO marketplace_purchases '
      . '(launcher_id, item_key, stripe_session_id, stripe_payment_intent, '
      . ' amount_cents, currency, status, created_at) '
      . 'VALUES (?, ?, ?, ?, ?, ?, \'pending\', NOW()) '
      . 'ON DUPLICATE KEY UPDATE '
      . '  stripe_session_id = VALUES(stripe_session_id), '
      . '  stripe_payment_intent = VALUES(stripe_payment_intent), '
      . '  amount_cents = VALUES(amount_cents), '
      . '  currency = VALUES(currency), '
      . '  status = CASE WHEN status = \'paid\' THEN status ELSE \'pending\' END'
    );
    $ins->execute([
        $launcherId,
        $itemKey,
        $sessionId,
        $paymentIntent !== '' ? $paymentIntent : null,
        $amount,
        $currency,
    ]);

    // Off to Stripe Checkout.
    header('Location: ' . $sessionUrl);
    exit;
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'marketplace_purchases') !== false
        || strpos($msg, "doesn't exist") !== false
        || strpos($msg, 'does not exist') !== false) {
        flash_set(
            'error',
            'Les tables marketplace sont manquantes. Importe `migrations_v3.sql` depuis la section SQL du dashboard.'
        );
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=monitoring#tab-monitoring');
    }
    flash_set('error', 'Erreur base de données : ' . $msg);
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=marketplace#tab-marketplace');
}

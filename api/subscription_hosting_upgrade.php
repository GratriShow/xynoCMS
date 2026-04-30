<?php

declare(strict_types=1);

/**
 * API endpoint for upgrading a launcher to add the hosting option (+5€/month).
 *
 * POST /api/subscription_hosting_upgrade.php
 *   - launcher_uuid: string (required, from GET/POST)
 *   - Returns JSON: {'ok': true, 'url': '...', 'session_id': '...'}
 *              or   {'ok': false, 'error': '...'}
 *
 * Logic:
 *   1. Verify user is logged in and owns the launcher
 *   2. Check launcher doesn't already have hosting enabled
 *   3. Fetch the active subscription (must exist)
 *   4. Calculate prorata hosting cost for remaining days in month
 *   5. Create Stripe Checkout session for hosting add-on
 *   6. Pre-insert hosting upgrade record in database
 *   7. Return checkout URL
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/subscription_helpers.php';
require_once __DIR__ . '/../api/files_helpers.php';

$user = require_login();

if (!is_post()) {
    flash_set('error', 'Méthode non autorisée.');
    redirect('/dashboard.php');
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}

api_rate_limit('/api/subscription_hosting_upgrade', api_client_ip(), 30, 60);

try {
    $pdo = db();

    // Ownership check: this user must own this launcher.
    $stmt = $pdo->prepare(
        'SELECT id, uuid, name, hosting FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$launcherUuid, $user['id']]);
    $launcher = $stmt->fetch();

    if (!$launcher) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }

    $launcherId = (int)$launcher['id'];
    $alreadyHosting = (int)($launcher['hosting'] ?? 0) === 1;

    // Check if hosting is already enabled
    if ($alreadyHosting) {
        flash_set('success', 'Hébergement déjà actif pour ce launcher.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
    }

    // Fetch active subscription (must exist)
    $stmt = $pdo->prepare(
        "SELECT id, plan, period, status, created_at, next_billing_at FROM subscriptions "
        . "WHERE launcher_id = ? AND status = 'active' "
        . "ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$launcherId]);
    $subscription = $stmt->fetch();

    if (!$subscription) {
        flash_set('error', 'Aucun abonnement actif. Veuillez d\'abord vous abonner.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=general#tab-general');
    }
} catch (Throwable $e) {
    flash_set('error', 'Erreur base de données : ' . $e->getMessage());
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=general#tab-general');
}

$plan = (string)($subscription['plan'] ?? '');
$period = (string)($subscription['period'] ?? '');
$nextBillingAt = (string)($subscription['next_billing_at'] ?? '');

// Calculate base hosting cost (5€/month = 500 cents)
$hostingMonthCents = 500;

// Calculate prorata hosting cost until next subscription renewal
// Use the subscription's next_billing_at date, not calendar month end
$now = new DateTime();
$billingDate = $nextBillingAt ? new DateTime($nextBillingAt) : new DateTime('last day of this month');
$daysLeft = $now->diff($billingDate)->days + 1;
$daysInMonth = (int)$billingDate->format('d');
$prorataHostingCents = (int)round(($daysLeft / $daysInMonth) * $hostingMonthCents);

// Get currency from config (default EUR)
$currency = strtolower(trim(api_env('MARKETPLACE_CURRENCY', 'eur'))) ?: 'eur';

// Build Stripe checkout session for hosting add-on
$secretKey = trim(api_env('STRIPE_SECRET_KEY', ''));
if ($secretKey === '') {
    flash_set('error', "Stripe n'est pas configuré : définis STRIPE_SECRET_KEY dans config/.env.local.");
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
}

$productName = 'XynoLauncher Hébergement Xyno — +5€/mois';

// success/cancel URLs — back to the dashboard
$successUrl = api_public_url(
    '/dashboard.php?launcher=' . rawurlencode($launcherUuid)
  . '&tab=files&hosting_success=1&session_id={CHECKOUT_SESSION_ID}#tab-files'
);
$cancelUrl = api_public_url(
    '/dashboard.php?launcher=' . rawurlencode($launcherUuid)
  . '&tab=files&hosting_cancel=1#tab-files'
);

// Build Stripe payload
// For hosting, we charge prorata this month, then recurring 5€/month starting next month
$payload = [
    'mode'                 => 'subscription',
    'payment_method_types' => ['card'],
    'success_url'          => $successUrl,
    'cancel_url'           => $cancelUrl,
    'client_reference_id'  => $launcherId . ':hosting:' . $period,
    'allow_promotion_codes' => 'true',
    'metadata' => [
        'launcher_id'   => (string)$launcherId,
        'launcher_uuid' => $launcherUuid,
        'kind'          => 'hosting_upgrade',
        'user_id'       => (string)$user['id'],
    ],
    'subscription_data' => [
        'metadata' => [
            'launcher_id'   => (string)$launcherId,
            'launcher_uuid' => $launcherUuid,
            'user_id'       => (string)$user['id'],
        ],
        // Start the billing cycle on the 1st of next month
        'billing_cycle_anchor' => (int)strtotime('first day of next month midnight'),
    ],
    'line_items' => [
        [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $prorataHostingCents,
                'recurring' => [
                    'interval'       => 'month',
                    'interval_count' => 1,
                ],
                'product_data' => [
                    'name' => $productName,
                ],
            ],
        ],
    ],
];

$body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
if ($ch === false) {
    flash_set('error', 'Impossible de contacter Stripe (curl indisponible).');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
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
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
}

$decoded = json_decode((string)$resp, true);
if ($code < 200 || $code >= 300 || !is_array($decoded)) {
    $stripeMsg = is_array($decoded['error'] ?? null)
        ? (string)($decoded['error']['message'] ?? 'Erreur Stripe')
        : ('HTTP ' . $code);
    flash_set('error', 'Stripe a refusé la requête : ' . $stripeMsg);
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
}

$sessionId  = (string)($decoded['id'] ?? '');
$sessionUrl = (string)($decoded['url'] ?? '');

if ($sessionId === '' || $sessionUrl === '') {
    flash_set('error', 'Réponse Stripe incomplète.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
}

// Pre-insert hosting upgrade record in database
// This allows the webhook to update launcher.hosting = 1 when checkout succeeds
try {
    $stmt = $pdo->prepare(
        'INSERT INTO hosting_upgrades '
        . '(user_id, launcher_id, stripe_session_id, prorata_cents, status, created_at) '
        . 'VALUES (?, ?, ?, ?, \'pending\', NOW())'
    );
    $stmt->execute([
        $user['id'],
        $launcherId,
        $sessionId,
        $prorataHostingCents,
    ]);
} catch (Throwable $e) {
    // Check if table exists and is migrated
    $msg = $e->getMessage();
    if (stripos($msg, 'hosting_upgrades') !== false
        || stripos($msg, "doesn't exist") !== false
        || stripos($msg, 'unknown column') !== false) {
        flash_set('error', 'Les colonnes hébergement manquent dans la base. Importe `migrations_*.sql` (phpMyAdmin → Importer) puis réessaie.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
    }
    flash_set('error', 'Erreur base de données : ' . $msg);
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=files#tab-files');
}

api_log('/api/subscription_hosting_upgrade', api_client_ip(), $launcherUuid, 200, 'hosting_upgrade_initiated');

// Redirect to Stripe Checkout
header('Location: ' . $sessionUrl);
exit;

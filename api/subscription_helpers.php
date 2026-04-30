<?php

declare(strict_types=1);

/**
 * Subscription helpers for xynoCMS.
 *
 * Centralizes the price matrix (plan × period), the discount logic and
 * the Stripe Checkout Session creation for recurring launcher
 * subscriptions.
 *
 * The values here MUST stay in sync with the static `data-price-*`
 * attributes shown in `pricing.php`.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * Base price (cents/month) for each plan, before any discount.
 * Multiply by months and apply discount to compute the per-period charge.
 */
function subscription_plan_base_cents(): array
{
    return [
        'starter' => 900,   // 9 €/mo
        'pro'     => 1900,  // 19 €/mo
        'premium' => 3900,  // 39 €/mo
    ];
}

/**
 * Period config:
 *  - months          : nombre de mois facturés à chaque cycle (1/3/6/12)
 *  - discount        : remise multiplicative (-0/-5/-10/-15 %)
 *  - stripe_interval : 'month' ou 'year' pour Stripe `recurring.interval`
 *  - stripe_count    : valeur de `recurring.interval_count` côté Stripe
 *  - label           : libellé humain pour les flashs / dashboard
 */
function subscription_period_config(): array
{
    return [
        'monthly'    => ['months' => 1,  'discount' => 1.00, 'stripe_interval' => 'month', 'stripe_count' => 1,  'label' => 'Mensuel'],
        'quarterly'  => ['months' => 3,  'discount' => 0.95, 'stripe_interval' => 'month', 'stripe_count' => 3,  'label' => 'Trimestriel'],
        'semestrial' => ['months' => 6,  'discount' => 0.90, 'stripe_interval' => 'month', 'stripe_count' => 6,  'label' => 'Semestriel'],
        'yearly'     => ['months' => 12, 'discount' => 0.85, 'stripe_interval' => 'year',  'stripe_count' => 1,  'label' => 'Annuel'],
    ];
}

/**
 * Hosting cost per month in cents (5€/mo).
 */
function subscription_hosting_cost_cents(): int
{
    return 500; // 5€
}

/**
 * Compute the amount (in cents) charged at every Stripe billing cycle for
 * the given plan + period. Returns 0 for unknown plan/period.
 *
 * @param string $plan       Plan name (starter|pro|premium)
 * @param string $period     Period (monthly|quarterly|semestrial|yearly)
 * @param bool $hosting      Add Xyno hosting cost (+5€/mo)
 */
function subscription_amount_cents(string $plan, string $period, bool $hosting = false): int
{
    $plan   = strtolower(trim($plan));
    $period = strtolower(trim($period));

    $plans   = subscription_plan_base_cents();
    $periods = subscription_period_config();

    if (!isset($plans[$plan]) || !isset($periods[$period])) {
        return 0;
    }

    $base = $plans[$plan];
    $cfg  = $periods[$period];

    // Calculate plan cost for the period
    $planCost = (int)round($base * $cfg['months'] * $cfg['discount']);

    // Add hosting cost if selected (5€/mo × months × discount)
    if ($hosting) {
        $hostingBase = subscription_hosting_cost_cents();
        $hostingCost = (int)round($hostingBase * $cfg['months'] * $cfg['discount']);
        $planCost += $hostingCost;
    }

    return $planCost;
}

/**
 * Validate a plan string. Returns canonical lowercase plan or '' if invalid.
 */
function subscription_normalize_plan(string $plan): string
{
    $plan = strtolower(trim($plan));
    return isset(subscription_plan_base_cents()[$plan]) ? $plan : '';
}

/**
 * Validate a period string. Returns canonical lowercase period or '' if invalid.
 */
function subscription_normalize_period(string $period): string
{
    $period = strtolower(trim($period));
    return isset(subscription_period_config()[$period]) ? $period : '';
}

/**
 * Display label for the plan (capitalised).
 */
function subscription_plan_label(string $plan): string
{
    return [
        'starter' => 'Starter',
        'pro'     => 'Pro',
        'premium' => 'Premium',
    ][strtolower(trim($plan))] ?? ucfirst(strtolower(trim($plan)));
}

/**
 * Result of subscription_create_stripe_checkout().
 *
 * On success: ['ok' => true, 'url' => 'https://checkout.stripe.com/...', 'session_id' => 'cs_...']
 * On error  : ['ok' => false, 'error' => 'human readable message']
 */
function subscription_create_stripe_checkout(
    int $userId,
    int $launcherId,
    string $launcherUuid,
    string $plan,
    string $period,
    bool $hosting = false
): array {
    $plan   = subscription_normalize_plan($plan);
    $period = subscription_normalize_period($period);

    if ($plan === '' || $period === '') {
        return ['ok' => false, 'error' => 'Plan ou période invalide.'];
    }

    $amount = subscription_amount_cents($plan, $period, $hosting);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Tarif introuvable pour ce plan.'];
    }

    $secretKey = trim(api_env('STRIPE_SECRET_KEY', ''));
    if ($secretKey === '') {
        return [
            'ok'    => false,
            'error' => "Stripe n'est pas configuré : définis STRIPE_SECRET_KEY dans config/.env.local "
                     . "(voir config/.env.local.example).",
        ];
    }

    $currency = strtolower(trim(api_env('MARKETPLACE_CURRENCY', 'eur'))) ?: 'eur';
    $cfg = subscription_period_config()[$period];

    $planLabel   = subscription_plan_label($plan);
    $periodLabel = $cfg['label'];
    $productName = sprintf('XynoLauncher %s — %s', $planLabel, $periodLabel);

    // success/cancel URLs — back to the dashboard with a feedback flag.
    $successUrl = api_public_url(
        '/dashboard.php?launcher=' . rawurlencode($launcherUuid)
      . '&tab=general&sub_success=1&session_id={CHECKOUT_SESSION_ID}#tab-general'
    );
    $cancelUrl = api_public_url(
        '/dashboard.php?launcher=' . rawurlencode($launcherUuid)
      . '&tab=general&sub_cancel=1#tab-general'
    );

    $payload = [
        'mode'                 => 'subscription',
        'payment_method_types' => ['card'],
        'success_url'          => $successUrl,
        'cancel_url'           => $cancelUrl,
        'client_reference_id'  => $launcherId . ':' . $plan . ':' . $period,
        'allow_promotion_codes' => 'true',
        'metadata' => [
            'launcher_id'   => (string)$launcherId,
            'launcher_uuid' => $launcherUuid,
            'plan'          => $plan,
            'period'        => $period,
            'user_id'       => (string)$userId,
            'kind'          => 'launcher_subscription',
        ],
        'subscription_data' => [
            'metadata' => [
                'launcher_id'   => (string)$launcherId,
                'launcher_uuid' => $launcherUuid,
                'plan'          => $plan,
                'period'        => $period,
                'user_id'       => (string)$userId,
            ],
        ],
        'line_items' => [
            [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amount,
                    'recurring' => [
                        'interval'       => $cfg['stripe_interval'],
                        'interval_count' => $cfg['stripe_count'],
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
        return ['ok' => false, 'error' => 'Impossible de contacter Stripe (curl indisponible).'];
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
        return ['ok' => false, 'error' => 'Erreur réseau Stripe : ' . ($err !== '' ? $err : 'réponse vide')];
    }

    $decoded = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        $stripeMsg = is_array($decoded['error'] ?? null)
            ? (string)($decoded['error']['message'] ?? 'Erreur Stripe')
            : ('HTTP ' . $code);
        return ['ok' => false, 'error' => 'Stripe a refusé la requête : ' . $stripeMsg];
    }

    $sessionId  = (string)($decoded['id'] ?? '');
    $sessionUrl = (string)($decoded['url'] ?? '');

    if ($sessionId === '' || $sessionUrl === '') {
        return ['ok' => false, 'error' => 'Réponse Stripe incomplète.'];
    }

    // Pre-insert / refresh the subscriptions row in 'pending' state so the
    // webhook can flip it to 'active' by stripe_session_id. We DO NOT touch
    // a row that is already 'active' (avoids race conditions with renewals).
    try {
        $pdo = db();
        $ins = $pdo->prepare(
            'INSERT INTO subscriptions '
          . '(user_id, launcher_id, status, plan, period, stripe_session_id, '
          . ' amount_cents, currency, created_at) '
          . 'VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $userId,
            $launcherId,
            $plan,
            $period,
            $sessionId,
            $amount,
            $currency,
        ]);
    } catch (Throwable $e) {
        // Schéma pas migré ? On laisse remonter une erreur lisible côté
        // appelant — on n'envoie PAS l'utilisateur sur Stripe si on n'est
        // pas capable de tracer l'intention en base.
        $msg = $e->getMessage();
        if (stripos($msg, 'subscriptions') !== false
            || stripos($msg, "doesn't exist") !== false
            || stripos($msg, 'unknown column') !== false
            || stripos($msg, 'pending') !== false) {
            return [
                'ok'    => false,
                'error' => 'Les colonnes Stripe manquent dans `subscriptions`. '
                         . 'Importe `migrations_v4.sql` (phpMyAdmin → Importer) puis réessaie.',
            ];
        }
        return ['ok' => false, 'error' => 'Erreur base de données : ' . $msg];
    }

    return [
        'ok'         => true,
        'url'        => $sessionUrl,
        'session_id' => $sessionId,
    ];
}

/**
 * Compute the next billing date given a period config and a starting time.
 * Used by the webhook when the subscription is activated.
 */
function subscription_next_billing(string $period, ?int $startTs = null): ?string
{
    $cfg = subscription_period_config()[strtolower($period)] ?? null;
    if ($cfg === null) {
        return null;
    }
    $startTs = $startTs ?? time();
    $months  = (int)$cfg['months'];
    $next    = strtotime('+' . $months . ' months', $startTs);
    if ($next === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $next);
}

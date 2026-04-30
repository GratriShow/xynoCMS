<?php

declare(strict_types=1);

/**
 * Petits helpers Stripe pour la console admin.
 *
 * Toutes les fonctions renvoient un tableau :
 *   ['ok' => true, 'data' => ...]   ou
 *   ['ok' => false, 'error' => 'message lisible', 'http' => 0|status]
 *
 * Aucun helper ici n'écrit en base : c'est l'appelant (subscription_actions.php
 * ou le webhook) qui décide quoi persister. Cela permet aussi de tester les
 * actions sans toucher Stripe via un dry-run dans le helper appelant.
 */

require_once __DIR__ . '/../api/utils.php';

function admin_stripe_secret(): string
{
    return trim(api_env('STRIPE_SECRET_KEY', ''));
}

/**
 * Bas-niveau : lance une requête Stripe avec auth Basic.
 *
 *   $method  : 'GET' | 'POST' | 'DELETE'
 *   $path    : '/v1/subscriptions/sub_xxx'
 *   $payload : tableau associatif (PHP) → http_build_query (form-encoded)
 */
function admin_stripe_request(string $method, string $path, array $payload = []): array
{
    $secret = admin_stripe_secret();
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Stripe non configuré (STRIPE_SECRET_KEY absent du .env).', 'http' => 0];
    }

    $url = 'https://api.stripe.com' . $path;
    $ch  = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl indisponible.', 'http' => 0];
    }

    $headers = [
        'Authorization: Basic ' . base64_encode($secret . ':'),
        'Stripe-Version: 2024-06-20',
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];

    if ($method !== 'GET' && !empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($method === 'GET' && !empty($payload)) {
        $opts[CURLOPT_URL] = $url . '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Erreur réseau Stripe : ' . $err, 'http' => 0];
    }

    $decoded = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded['error'] ?? null)
            ? (string)($decoded['error']['message'] ?? 'Erreur Stripe')
            : ('HTTP ' . $code);
        return ['ok' => false, 'error' => $msg, 'http' => $code, 'raw' => $decoded];
    }

    return ['ok' => true, 'data' => is_array($decoded) ? $decoded : [], 'http' => $code];
}

/**
 * Récupère un objet subscription Stripe avec son default_payment_method
 * et le client lié (pour afficher la dernière carte / nom client).
 */
function admin_stripe_subscription(string $stripeSubId): array
{
    if ($stripeSubId === '') return ['ok' => false, 'error' => 'subscription_id vide.', 'http' => 0];
    return admin_stripe_request('GET', '/v1/subscriptions/' . rawurlencode($stripeSubId), [
        'expand[]' => 'default_payment_method',
        'expand[]' => 'latest_invoice',
        'expand[]' => 'customer',
    ]);
}

/**
 * Liste des paiements (charges) du client Stripe attaché à la sub.
 */
function admin_stripe_charges(string $customerId, int $limit = 10): array
{
    if ($customerId === '') return ['ok' => false, 'error' => 'customer_id vide.', 'http' => 0];
    return admin_stripe_request('GET', '/v1/charges', [
        'customer' => $customerId,
        'limit'    => max(1, min(100, $limit)),
    ]);
}

/**
 * Annule une subscription Stripe à la fin de la période courante.
 *   cancel_at_period_end = true
 */
function admin_stripe_cancel_at_period_end(string $stripeSubId): array
{
    return admin_stripe_request('POST', '/v1/subscriptions/' . rawurlencode($stripeSubId), [
        'cancel_at_period_end' => 'true',
    ]);
}

/**
 * Annulation immédiate d'une subscription Stripe (acces coupé tout de suite).
 */
function admin_stripe_cancel_now(string $stripeSubId): array
{
    return admin_stripe_request('DELETE', '/v1/subscriptions/' . rawurlencode($stripeSubId));
}

/**
 * Réactive (uncancel) une subscription qui était cancel_at_period_end=true,
 * tant que la fin de période n'est pas atteinte.
 */
function admin_stripe_resume(string $stripeSubId): array
{
    return admin_stripe_request('POST', '/v1/subscriptions/' . rawurlencode($stripeSubId), [
        'cancel_at_period_end' => 'false',
    ]);
}

/**
 * Crée un coupon Stripe one-shot et l'attache à la subscription.
 *   $percentOff : ex. 100 pour 100% off
 *   $duration   : 'once' | 'forever' | 'repeating'
 *   $months     : nombre de cycles si 'repeating'
 */
function admin_stripe_create_coupon_and_apply(
    string $stripeSubId,
    int $percentOff,
    string $duration = 'once',
    int $months = 1,
    string $name = ''
): array {
    if ($stripeSubId === '') return ['ok' => false, 'error' => 'subscription_id vide.', 'http' => 0];

    $percentOff = max(1, min(100, $percentOff));
    $payload = [
        'percent_off' => (string)$percentOff,
        'duration'    => $duration,
        'name'        => $name !== '' ? $name : ('Geste commercial XynoLauncher · ' . $percentOff . '%'),
    ];
    if ($duration === 'repeating') {
        $payload['duration_in_months'] = (string)max(1, $months);
    }

    $coupon = admin_stripe_request('POST', '/v1/coupons', $payload);
    if (!$coupon['ok']) return $coupon;

    $couponId = (string)($coupon['data']['id'] ?? '');
    if ($couponId === '') {
        return ['ok' => false, 'error' => 'Stripe a créé le coupon mais sans id.', 'http' => 0];
    }

    return admin_stripe_request('POST', '/v1/subscriptions/' . rawurlencode($stripeSubId), [
        'coupon' => $couponId,
    ]);
}

/**
 * Refund d'une charge.
 */
function admin_stripe_refund(string $chargeId, ?int $amountCents = null): array
{
    if ($chargeId === '') return ['ok' => false, 'error' => 'charge_id vide.', 'http' => 0];
    $payload = ['charge' => $chargeId];
    if ($amountCents !== null && $amountCents > 0) {
        $payload['amount'] = (string)$amountCents;
    }
    return admin_stripe_request('POST', '/v1/refunds', $payload);
}

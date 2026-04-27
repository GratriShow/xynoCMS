<?php

declare(strict_types=1);

/**
 * GET /api/pricing_checkout.php?plan=...&period=...
 *
 * "First purchase" entry point used by the CTAs on pricing.php.
 *
 * Flow:
 *   - Plan / period are validated against subscription_helpers.
 *   - If the visitor is NOT logged in: stash {plan, period} in the session
 *     and bounce them to /auth/register.php (after auth, register/login.php
 *     re-enter this endpoint).
 *   - If they are logged in: forward to /builder.php?plan=...&period=... so
 *     the user can name + configure their launcher and submit the form.
 *     The builder POSTs to /launcher/create_launcher.php which finishes the
 *     flow by creating the Stripe Checkout session.
 *
 * NOTE: We deliberately do NOT auto-create a launcher or jump straight to
 * Stripe here. The user MUST go through builder.php so they can pick a
 * launcher name + hosting option BEFORE paying.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/subscription_helpers.php';

start_secure_session();

$plan   = subscription_normalize_plan((string)($_GET['plan']   ?? ''));
$period = subscription_normalize_period((string)($_GET['period'] ?? ''));

if ($plan === '' || $period === '') {
    flash_set('error', 'Plan ou periode invalide.');
    redirect('/pricing.php');
}

$user = current_user();
if ($user === null) {
    // Stash the desired plan so register / login can resume the flow right
    // after auth. /auth/register.php and /auth/login.php read this back and
    // bounce back here, which then forwards to /builder.php.
    $_SESSION['pending_checkout'] = [
        'plan'      => $plan,
        'period'    => $period,
        'queued_at' => time(),
    ];
    flash_set('info', 'Cree ton compte pour finaliser ton abonnement ' . ucfirst($plan) . '.');
    redirect('/auth/register.php');
}

// Already logged in: forward to the builder so they can name + configure
// their launcher. The "create launcher + Stripe Checkout" step happens
// when they submit the builder form (see /launcher/create_launcher.php).
redirect('/builder.php?plan=' . urlencode($plan) . '&period=' . urlencode($period));

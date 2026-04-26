<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/subscription_helpers.php';

/**
 * POST /api/subscription_checkout.php
 *
 * Creates a Stripe Checkout Session in `mode=subscription` for a launcher
 * the current user owns.
 *
 * Form fields:
 *   - csrf_token     (required)
 *   - launcher_uuid  (required; must be owned by current session user)
 *   - plan           (required; starter | pro | premium)
 *   - period         (required; monthly | quarterly | semestrial | yearly)
 *
 * Flow:
 *   1. require_login + CSRF check
 *   2. verify the launcher belongs to the current user
 *   3. refuse if the launcher already has an *active* subscription
 *   4. delegate Stripe call + pre-insert to subscription_create_stripe_checkout()
 *   5. redirect to the returned session.url (Stripe-hosted Checkout)
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
$plan         = subscription_normalize_plan((string)($_POST['plan'] ?? ''));
$period       = subscription_normalize_period((string)($_POST['period'] ?? ''));

if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}
if ($plan === '' || $period === '') {
    flash_set('error', 'Plan ou période invalide.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=general#tab-general');
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

    // Refuse if there is already an *active* subscription that hasn't expired.
    try {
        $chk = $pdo->prepare(
            "SELECT id, status, expires_at FROM subscriptions "
          . "WHERE launcher_id = ? AND status = 'active' "
          . "ORDER BY id DESC LIMIT 1"
        );
        $chk->execute([$launcherId]);
        $existing = $chk->fetch();
        if ($existing) {
            $expiresTs = $existing['expires_at'] ? strtotime((string)$existing['expires_at']) : 0;
            if ($expiresTs === 0 || $expiresTs > time()) {
                flash_set('success', 'Cet abonnement est déjà actif.');
                redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=general#tab-general');
            }
        }
    } catch (Throwable $e) {
        // table missing → migrations not run, surfaced by helper below.
    }
} catch (Throwable $e) {
    flash_set('error', 'Erreur base de données : ' . $e->getMessage());
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=general#tab-general');
}

$result = subscription_create_stripe_checkout(
    (int)$user['id'],
    $launcherId,
    $launcherUuid,
    $plan,
    $period
);

if (!($result['ok'] ?? false)) {
    flash_set('error', (string)($result['error'] ?? 'Erreur inconnue.'));
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=general#tab-general');
}

header('Location: ' . (string)$result['url']);
exit;

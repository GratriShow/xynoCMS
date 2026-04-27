<?php

declare(strict_types=1);

/**
 * GET /api/pricing_checkout.php?plan=...&period=...
 *
 * "First purchase" entry point used by the CTAs on pricing.php.
 *
 * Behaviour:
 *   - Plan and period are validated against subscription_helpers.
 *   - If the visitor is NOT logged in: stash {plan, period} in the session
 *     and bounce them to /auth/register.php (resumes after auth).
 *   - If they are logged in:
 *       * If they already own a launcher with an *active* subscription, send
 *         them to the dashboard (no double-billing surprise).
 *       * If they own at least one launcher (no active sub), reuse the most
 *         recent one for the new subscription.
 *       * Otherwise, auto-create a minimal default launcher so the Stripe
 *         Checkout has something to attach to. The user can rename / tweak
 *         everything from the dashboard afterwards.
 *   - Then create a Stripe Checkout session via subscription_create_stripe_checkout().
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
    // Stash the desired plan so register / login can resume checkout right
    // after auth. /auth/register.php and /auth/login.php read this back.
    $_SESSION['pending_checkout'] = [
        'plan'      => $plan,
        'period'    => $period,
        'queued_at' => time(),
    ];
    flash_set('info', 'Cree ton compte pour finaliser ton abonnement ' . ucfirst($plan) . '.');
    redirect('/auth/register.php');
}

try {
    $pdo = db();

    // 1) Already paying for one of his launchers? Send him to the dashboard
    //    rather than charging the same plan twice.
    try {
        $chk = $pdo->prepare(
            "SELECT l.uuid FROM subscriptions s "
          . "INNER JOIN launchers l ON l.id = s.launcher_id "
          . "WHERE s.user_id = ? AND s.status = 'active' "
          . "ORDER BY s.id DESC LIMIT 1"
        );
        $chk->execute([(int)$user['id']]);
        $activeRow = $chk->fetch();
        if ($activeRow) {
            flash_set('info', 'Tu as deja un abonnement actif. Choisis le launcher concerne dans ton dashboard pour le modifier.');
            redirect('/dashboard.php?launcher=' . urlencode((string)$activeRow['uuid']) . '&tab=general#sub-card');
        }
    } catch (Throwable $e) {
        // subscriptions table missing -> migrations_v4 not run yet, on continue
    }

    // 2) Pick the latest existing launcher for this user (if any).
    $st = $pdo->prepare(
        'SELECT id, uuid FROM launchers WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
    );
    $st->execute([(int)$user['id']]);
    $launcher = $st->fetch();

    // 3) Otherwise, create a minimal default launcher so Stripe has something
    //    to attach the subscription to. The user customises everything later.
    if (!$launcher) {
        $newUuid = uuid_v4();
        $apiKey  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $defName    = 'Mon launcher';
        $defVersion = '1.20.4';
        $defLoader  = 'fabric';
        $defTheme   = 'Violet Neon';
        try {
            $ins = $pdo->prepare(
                'INSERT INTO launchers (user_id, uuid, api_key, name, description, version, loader, theme, modules, created_at) '
              . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([
                (int)$user['id'], $newUuid, $apiKey,
                $defName, '', $defVersion, $defLoader, $defTheme, '',
            ]);
        } catch (PDOException $e2) {
            // Backward-compat path : "modules" column may not exist yet.
            if (stripos($e2->getMessage(), 'unknown column') !== false
                && stripos($e2->getMessage(), 'modules') !== false) {
                $ins = $pdo->prepare(
                    'INSERT INTO launchers (user_id, uuid, api_key, name, description, version, loader, theme, created_at) '
                  . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $ins->execute([
                    (int)$user['id'], $newUuid, $apiKey,
                    $defName, '', $defVersion, $defLoader, $defTheme,
                ]);
            } else {
                throw $e2;
            }
        }
        $launcher = [
            'id'   => (int)$pdo->lastInsertId(),
            'uuid' => $newUuid,
        ];
    }

    // 4) Start Stripe Checkout for the chosen plan / period.
    $result = subscription_create_stripe_checkout(
        (int)$user['id'],
        (int)$launcher['id'],
        (string)$launcher['uuid'],
        $plan,
        $period
    );

    if (!($result['ok'] ?? false)) {
        flash_set('error', 'Stripe : ' . (string)($result['error'] ?? 'erreur inconnue.'));
        redirect('/dashboard.php?launcher=' . urlencode((string)$launcher['uuid']) . '&tab=general#sub-card');
    }

    header('Location: ' . (string)$result['url']);
    exit;

} catch (Throwable $e) {
    flash_set('error', 'Erreur base de donnees : ' . $e->getMessage());
    redirect('/pricing.php');
}

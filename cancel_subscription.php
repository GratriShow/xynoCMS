<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/api/utils.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php');
}

$subId = (int)($_POST['subscription_id'] ?? 0);
if ($subId <= 0) {
    flash_set('error', 'Abonnement introuvable.');
    redirect('/dashboard.php');
}

try {
    $pdo = db();

    // Vérifie que l'abonnement appartient bien à l'utilisateur ;
    // on charge aussi stripe_subscription_id pour pouvoir le canceler côté Stripe.
    try {
        $chk = $pdo->prepare(
            'SELECT id, status, stripe_subscription_id FROM subscriptions '
          . 'WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $chk->execute([$subId, $user['id']]);
    } catch (PDOException $e) {
        // Schéma pré-v4 sans stripe_subscription_id : fallback.
        $chk = $pdo->prepare('SELECT id, status FROM subscriptions WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$subId, $user['id']]);
    }
    $sub = $chk->fetch();
    if (!$sub) {
        flash_set('error', 'Cet abonnement ne t’appartient pas.');
        redirect('/dashboard.php');
    }

    if (strtolower((string)($sub['status'] ?? '')) !== 'active') {
        flash_set('error', 'Seul un abonnement actif peut être résilié.');
        redirect('/dashboard.php');
    }

    // Tell Stripe to cancel at period end (so the user keeps access until
    // the end of the current paid period). We do this best-effort: if the
    // call fails (network, missing key, no stripe_subscription_id because
    // the sub was created via FREE100), we still flip the local status so
    // the dashboard reflects the user's choice immediately.
    $stripeSubId = (string)($sub['stripe_subscription_id'] ?? '');
    $secretKey   = trim(api_env('STRIPE_SECRET_KEY', ''));

    if ($stripeSubId !== '' && $secretKey !== '') {
        $ch = curl_init('https://api.stripe.com/v1/subscriptions/' . rawurlencode($stripeSubId));
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => http_build_query(['cancel_at_period_end' => 'true'], '', '&', PHP_QUERY_RFC3986),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode($secretKey . ':'),
                    'Stripe-Version: 2024-06-20',
                    'Accept: application/json',
                ],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // Tentative 1 : statut 'cancelled' + cancelled_at (schéma v3)
    // Tentative 2 : statut 'cancelled' seul (schéma v3 sans la colonne)
    // Tentative 3 : si l'ENUM n'autorise pas 'cancelled' (schéma v1/v2),
    //   on renvoie un message explicite avec le lien vers la migration SQL.
    $ok = false;
    try {
        $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
        $upd->execute([$subId]);
        $ok = true;
    } catch (PDOException $e1) {
        try {
            $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?");
            $upd->execute([$subId]);
            $ok = true;
        } catch (PDOException $e2) {
            // L'ENUM `status` ne connaît pas 'cancelled' — schéma pas encore migré.
            flash_set(
                'error',
                "Impossible de résilier : ta base de données n'a pas encore la migration v3. "
              . "Importe `migrations_v3.sql` (phpMyAdmin › Importer) puis réessaie. "
              . "Détail : " . $e2->getMessage()
            );
            redirect('/dashboard.php');
        }
    }

    if (!$ok) {
        flash_set('error', 'Résiliation échouée — réessaie dans une minute.');
        redirect('/dashboard.php');
    }

} catch (Throwable $e) {
    flash_set('error', 'Impossible de résilier l’abonnement : ' . $e->getMessage());
    redirect('/dashboard.php');
}

flash_set('success', 'Abonnement résilié. Ton accès reste actif jusqu’à la fin de la période en cours.');
redirect('/dashboard.php');

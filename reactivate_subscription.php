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

    // Charge stripe_subscription_id pour pouvoir annuler la programmation de
    // résiliation côté Stripe. Fallback pour les schémas pré-v4.
    try {
        $chk = $pdo->prepare(
            'SELECT id, status, expires_at, stripe_subscription_id FROM subscriptions '
          . 'WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $chk->execute([$subId, $user['id']]);
    } catch (PDOException $e) {
        $chk = $pdo->prepare('SELECT id, status, expires_at FROM subscriptions WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$subId, $user['id']]);
    }
    $sub = $chk->fetch();
    if (!$sub) {
        flash_set('error', 'Cet abonnement ne t’appartient pas.');
        redirect('/dashboard.php');
    }

    if (strtolower((string)($sub['status'] ?? '')) === 'active') {
        flash_set('error', 'Cet abonnement est déjà actif.');
        redirect('/dashboard.php');
    }

    // Seulement si la période en cours n'est pas terminée, on réactive sans repayer.
    $expiresTs = $sub['expires_at'] ? strtotime((string)$sub['expires_at']) : 0;
    if ($expiresTs && $expiresTs < time()) {
        flash_set('error', 'La période de ton abonnement est terminée — il faut choisir une nouvelle formule.');
        redirect('/pricing.php');
    }

    // Tell Stripe to UNDO the scheduled cancellation. Best-effort: if the
    // call fails (no stripe_subscription_id, network, missing key) we still
    // flip the local status so the dashboard reflects the user's choice.
    $stripeSubId = (string)($sub['stripe_subscription_id'] ?? '');
    $secretKey   = trim(api_env('STRIPE_SECRET_KEY', ''));

    if ($stripeSubId !== '' && $secretKey !== '') {
        $ch = curl_init('https://api.stripe.com/v1/subscriptions/' . rawurlencode($stripeSubId));
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => http_build_query(['cancel_at_period_end' => 'false'], '', '&', PHP_QUERY_RFC3986),
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

    // Tentative 1 : statut 'active' + cancelled_at = NULL (schéma v3+)
    // Tentative 2 : statut 'active' seul (schéma v3 sans la colonne cancelled_at)
    $ok = false;
    try {
        $upd = $pdo->prepare("UPDATE subscriptions SET status = 'active', cancelled_at = NULL WHERE id = ?");
        $upd->execute([$subId]);
        $ok = true;
    } catch (PDOException $e1) {
        try {
            $upd = $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE id = ?");
            $upd->execute([$subId]);
            $ok = true;
        } catch (PDOException $e2) {
            flash_set('error', 'Réactivation échouée : ' . $e2->getMessage());
            redirect('/dashboard.php');
        }
    }

    if (!$ok) {
        flash_set('error', 'Réactivation échouée — réessaie dans une minute.');
        redirect('/dashboard.php');
    }
} catch (Throwable $e) {
    flash_set('error', 'Impossible de réactiver l’abonnement : ' . $e->getMessage());
    redirect('/dashboard.php');
}

flash_set('success', 'Abonnement réactivé. Le renouvellement automatique reprendra à la prochaine période.');
redirect('/dashboard.php');

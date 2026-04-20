<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php#facturation');
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php#facturation');
}

$subId = (int)($_POST['subscription_id'] ?? 0);
if ($subId <= 0) {
    flash_set('error', 'Abonnement introuvable.');
    redirect('/dashboard.php#facturation');
}

try {
    $pdo = db();

    $chk = $pdo->prepare('SELECT id, status, expires_at FROM subscriptions WHERE id = ? AND user_id = ? LIMIT 1');
    $chk->execute([$subId, $user['id']]);
    $sub = $chk->fetch();
    if (!$sub) {
        flash_set('error', 'Cet abonnement ne t’appartient pas.');
        redirect('/dashboard.php#facturation');
    }

    if (strtolower((string)($sub['status'] ?? '')) === 'active') {
        flash_set('error', 'Cet abonnement est déjà actif.');
        redirect('/dashboard.php#facturation');
    }

    // Seulement si la période en cours n'est pas terminée, on réactive sans repayer.
    $expiresTs = $sub['expires_at'] ? strtotime((string)$sub['expires_at']) : 0;
    if ($expiresTs && $expiresTs < time()) {
        flash_set('error', 'La période de ton abonnement est terminée — il faut choisir une nouvelle formule.');
        redirect('/pricing.php');
    }

    $upd = $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE id = ?");
    $upd->execute([$subId]);
} catch (Throwable $e) {
    flash_set('error', 'Impossible de réactiver l’abonnement (erreur base de données).');
    redirect('/dashboard.php#facturation');
}

flash_set('success', 'Abonnement réactivé.');
redirect('/dashboard.php#facturation');

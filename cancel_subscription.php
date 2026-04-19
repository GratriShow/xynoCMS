<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php#facturation');
}

if (!csrf_check($_POST['csrf_token'] ?? '')) {
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

    // Vérifie que l'abonnement appartient bien à l'utilisateur
    $chk = $pdo->prepare('SELECT id, status FROM subscriptions WHERE id = ? AND user_id = ? LIMIT 1');
    $chk->execute([$subId, $user['id']]);
    $sub = $chk->fetch();
    if (!$sub) {
        flash_set('error', 'Cet abonnement ne t’appartient pas.');
        redirect('/dashboard.php#facturation');
    }

    if (strtolower((string)($sub['status'] ?? '')) !== 'active') {
        flash_set('error', 'Seul un abonnement actif peut être résilié.');
        redirect('/dashboard.php#facturation');
    }

    // Résiliation "soft" : on passe le statut à "cancelled".
    // expires_at reste inchangé : l'accès court jusqu'à la fin de la période.
    $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
    $upd->execute([$subId]);
} catch (PDOException $e) {
    // Fallback si la colonne cancelled_at n'existe pas encore dans la table.
    try {
        $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?");
        $upd->execute([$subId]);
    } catch (Throwable $e2) {
        flash_set('error', 'Impossible de résilier l’abonnement (erreur base de données).');
        redirect('/dashboard.php#facturation');
    }
}

flash_set('success', 'Abonnement résilié. Ton accès reste actif jusqu’à la fin de la période en cours.');
redirect('/dashboard.php#facturation');

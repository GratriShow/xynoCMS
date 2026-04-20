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
            redirect('/dashboard.php#sql');
        }
    }

    if (!$ok) {
        flash_set('error', 'Résiliation échouée — réessaie dans une minute.');
        redirect('/dashboard.php#facturation');
    }

} catch (Throwable $e) {
    flash_set('error', 'Impossible de résilier l’abonnement : ' . $e->getMessage());
    redirect('/dashboard.php#facturation');
}

flash_set('success', 'Abonnement résilié. Ton accès reste actif jusqu’à la fin de la période en cours.');
redirect('/dashboard.php#facturation');

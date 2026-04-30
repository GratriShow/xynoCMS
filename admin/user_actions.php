<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

if (!is_post()) redirect('users.php');

if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
    flash_set('error', 'Session expirée.');
    redirect('users.php');
}

$action = (string)($_POST['action'] ?? '');
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    flash_set('error', 'Utilisateur invalide.');
    redirect('users.php');
}

if ($userId === (int)$admin['id'] && in_array($action, ['soft_delete', 'revoke_admin'], true)) {
    flash_set('error', "Tu ne peux pas appliquer cette action sur ton propre compte.");
    redirect('user.php?id=' . $userId);
}

switch ($action) {
    case 'soft_delete':
        $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? LIMIT 1')->execute([$userId]);
        admin_log($admin['id'], 'soft_delete_user', 'user', $userId);
        flash_set('success', "Compte marqué comme supprimé (RGPD, 30 jours de grâce).");
        break;
    case 'restore':
        $pdo->prepare('UPDATE users SET deleted_at = NULL WHERE id = ? LIMIT 1')->execute([$userId]);
        admin_log($admin['id'], 'restore_user', 'user', $userId);
        flash_set('success', 'Compte restauré.');
        break;
    case 'grant_admin':
        $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = ? LIMIT 1')->execute([$userId]);
        admin_log($admin['id'], 'grant_admin', 'user', $userId);
        flash_set('success', 'Promu admin.');
        break;
    case 'revoke_admin':
        $pdo->prepare('UPDATE users SET is_admin = 0 WHERE id = ? LIMIT 1')->execute([$userId]);
        admin_log($admin['id'], 'revoke_admin', 'user', $userId);
        flash_set('success', 'Droits admin retirés.');
        break;
    default:
        flash_set('error', 'Action inconnue.');
}

redirect('user.php?id=' . $userId);

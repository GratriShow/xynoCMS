<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

if (!is_post()) redirect('gifts.php');

if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
    flash_set('error', 'Session expirée.');
    redirect('gifts.php');
}

$action = (string)($_POST['action'] ?? '');
$giftId = (int)($_POST['gift_id'] ?? 0);

if ($giftId <= 0) {
    flash_set('error', 'Cadeau invalide.');
    redirect('gifts.php');
}

switch ($action) {
    case 'delete':
        try {
            // Delete audit log
            $pdo->prepare('DELETE FROM gift_audit_log WHERE gift_id = ?')->execute([$giftId]);
            // Delete recipients
            $pdo->prepare('DELETE FROM gift_recipients WHERE gift_id = ?')->execute([$giftId]);
            // Delete codes
            $pdo->prepare('DELETE FROM gift_codes WHERE gift_id = ?')->execute([$giftId]);
            // Delete gift
            $pdo->prepare('DELETE FROM gifts WHERE id = ?')->execute([$giftId]);

            admin_log((int)$admin['id'], 'delete_gift', 'gift', $giftId);
            flash_set('success', 'Cadeau supprimé.');
        } catch (Throwable $e) {
            flash_set('error', 'Erreur lors de la suppression : ' . $e->getMessage());
        }
        redirect('gifts.php');
        break;

    default:
        flash_set('error', 'Action inconnue.');
        redirect('gifts.php');
}

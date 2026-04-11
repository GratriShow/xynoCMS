<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    flash_set('error', 'Session expirée. Ré-essaie.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
$versionId = (int)($_POST['version_id'] ?? 0);

if ($launcherUuid === '' || $versionId <= 0) {
    flash_set('error', 'Requête invalide.');
    redirect('/dashboard.php');
}

try {
    $pdo = db();

    // Ownership check.
    $sel = $pdo->prepare('SELECT id, uuid FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $sel->execute([$launcherUuid, $user['id']]);
    $launcherRow = $sel->fetch();
    if (!$launcherRow) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }

    $launcherId = (int)$launcherRow['id'];

    // Ensure version belongs to that launcher.
    $v = $pdo->prepare('SELECT id FROM launcher_versions WHERE id = ? AND launcher_id = ? LIMIT 1');
    $v->execute([$versionId, $launcherId]);
    $row = $v->fetch();
    if (!$row) {
        flash_set('error', 'Version introuvable.');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#versions');
    }

    $pdo->beginTransaction();

    $off = $pdo->prepare('UPDATE launcher_versions SET is_active = 0 WHERE launcher_id = ? AND is_active = 1');
    $off->execute([$launcherId]);

    $on = $pdo->prepare('UPDATE launcher_versions SET is_active = 1 WHERE id = ? AND launcher_id = ?');
    $on->execute([$versionId, $launcherId]);

    $pdo->commit();

    flash_set('success', 'Version activée.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#versions');
} catch (Throwable $e) {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e2) {
    }

    flash_set('error', 'Impossible d’activer la version.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#versions');
}

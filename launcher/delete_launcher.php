<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}

$pdo = db();

$del = $pdo->prepare('DELETE FROM launchers WHERE uuid = ? AND user_id = ?');
$del->execute([$launcherUuid, $user['id']]);

flash_set('success', 'Launcher supprimé.');
redirect('/dashboard.php');

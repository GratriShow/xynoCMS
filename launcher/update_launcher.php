<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$version = trim((string)($_POST['version'] ?? ''));
$loader = trim((string)($_POST['loader'] ?? ''));
$theme = trim((string)($_POST['theme'] ?? ''));

if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}

if ($name === '' || $version === '' || $loader === '' || $theme === '') {
    flash_set('error', 'Champs manquants : nom, version, loader et thème sont requis.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid));
}

$allowedLoaders = ['fabric', 'forge', 'quilt'];
if (!in_array(strtolower($loader), $allowedLoaders, true)) {
    flash_set('error', 'Loader invalide.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid));
}

$pdo = db();

$check = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
$check->execute([$launcherUuid, $user['id']]);
$row = $check->fetch();
if (!$row) {
    flash_set('error', 'Accès refusé.');
    redirect('/dashboard.php');
}

$upd = $pdo->prepare('UPDATE launchers SET name = ?, description = ?, version = ?, loader = ?, theme = ? WHERE uuid = ? AND user_id = ?');
$upd->execute([
    $name,
    $description,
    $version,
    strtolower($loader),
    $theme,
    $launcherUuid,
    $user['id'],
]);

flash_set('success', 'Launcher mis à jour.');
redirect('/dashboard.php?launcher=' . urlencode($launcherUuid));

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

$launcherId = (int)($row['id'] ?? 0);

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

// ---- Upload logo (optionnel) ----
$logoNotice = '';
if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $uploadErr = (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE);
    $size      = (int)($_FILES['logo']['size'] ?? 0);
    $maxBytes  = 2 * 1024 * 1024; // 2 Mo

    if ($uploadErr !== UPLOAD_ERR_OK) {
        $logoNotice = ' (logo non uploadé : erreur ' . $uploadErr . ')';
    } elseif ($size > $maxBytes) {
        $logoNotice = ' (logo trop lourd, max 2 Mo)';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($_FILES['logo']['tmp_name']);
        $allowed = [
            'image/png'  => 'png',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            $logoNotice = ' (format de logo non supporté : ' . $mime . ')';
        } else {
            $ext = $allowed[$mime];
            $dir = __DIR__ . '/../uploads/launchers/' . $launcherId;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            // Ecrit toujours en logo.png (extension canonique) pour simplifier l'affichage.
            // Si l'upload est en .ico / .jpg / .webp, on garde l'extension d'origine aussi.
            $canonical = $dir . '/logo.png';
            if ($ext === 'png') {
                if (@move_uploaded_file($_FILES['logo']['tmp_name'], $canonical)) {
                    @chmod($canonical, 0644);
                } else {
                    $logoNotice = ' (logo non enregistré)';
                }
            } else {
                $target = $dir . '/logo.' . $ext;
                if (@move_uploaded_file($_FILES['logo']['tmp_name'], $target)) {
                    @chmod($target, 0644);
                    // Pas de conversion PNG côté PHP : le build pipeline s'en chargera,
                    // ou le dashboard affichera le fichier natif.
                } else {
                    $logoNotice = ' (logo non enregistré)';
                }
            }
        }
    }
}

flash_set('success', 'Launcher mis à jour.' . $logoNotice);
redirect('/dashboard.php?launcher=' . urlencode($launcherUuid));

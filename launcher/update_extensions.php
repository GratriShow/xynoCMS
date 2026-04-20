<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php#extensions');
}

if (!csrf_check($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php#extensions');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php#extensions');
}

// Liste blanche stricte : on n'accepte QUE les extensions présentes au catalogue.
// (Cohérent avec `$availableExtensions` dans dashboard.php.)
$allowedKeys = [
    'news','player_count','server_status','discord','leaderboard','shop',
    'voting','quests','events','skin_api','capes','social_feed',
    'crash_reporter','analytics','modpack','changelog','ram_slider','java_manager',
];

$pdo = db();

// Ownership check
$check = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
$check->execute([$launcherUuid, $user['id']]);
$row = $check->fetch();
if (!$row) {
    flash_set('error', 'Accès refusé.');
    redirect('/dashboard.php#extensions');
}
$launcherId = (int)($row['id'] ?? 0);

$posted = (array)($_POST['ext'] ?? []);

try {
    $upsert = $pdo->prepare(
        'INSERT INTO launcher_extensions (launcher_id, ext_key, enabled, api_url, api_key, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), api_url = VALUES(api_url), api_key = VALUES(api_key), updated_at = NOW()'
    );

    foreach ($allowedKeys as $key) {
        $data    = (array)($posted[$key] ?? []);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $apiUrl  = trim((string)($data['api_url'] ?? ''));
        $apiKey  = trim((string)($data['api_key'] ?? ''));

        // Garde-fous : url valide (ou vide) + longueurs max
        if ($apiUrl !== '' && !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            $apiUrl = '';
        }
        if (strlen($apiUrl) > 512) $apiUrl = substr($apiUrl, 0, 512);
        if (strlen($apiKey) > 255) $apiKey = substr($apiKey, 0, 255);

        $upsert->execute([$launcherId, $key, $enabled, $apiUrl !== '' ? $apiUrl : null, $apiKey !== '' ? $apiKey : null]);
    }

    flash_set('success', 'Extensions enregistrées.');
} catch (PDOException $e) {
    // Code 42S02 = table inconnue — on oriente vers la migration.
    if (str_contains($e->getMessage(), "launcher_extensions")) {
        flash_set(
            'error',
            "Impossible d'enregistrer les extensions : la table `launcher_extensions` n'existe pas. "
          . 'Importe `migrations_v3.sql` depuis la section SQL du dashboard.'
        );
        redirect('/dashboard.php#sql');
    }
    flash_set('error', 'Erreur base de données : ' . $e->getMessage());
}

redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#extensions');

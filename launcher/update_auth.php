<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php#auth');
}

if (!csrf_check($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php#auth');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php#auth');
}

$mode = strtolower(trim((string)($_POST['mode'] ?? 'microsoft')));
if (!in_array($mode, ['microsoft', 'custom', 'offline'], true)) {
    $mode = 'microsoft';
}

$loginUrl   = trim((string)($_POST['login_url']   ?? ''));
$verifyUrl  = trim((string)($_POST['verify_url']  ?? ''));
$refreshUrl = trim((string)($_POST['refresh_url'] ?? ''));
$apiKey     = trim((string)($_POST['api_key']     ?? ''));

// Validation URL : on nettoie les champs invalides, on ne bloque pas.
foreach (['loginUrl', 'verifyUrl', 'refreshUrl'] as $var) {
    if ($$var !== '' && !filter_var($$var, FILTER_VALIDATE_URL)) {
        $$var = '';
    }
    if (strlen($$var) > 512) $$var = substr($$var, 0, 512);
}
if (strlen($apiKey) > 255) $apiKey = substr($apiKey, 0, 255);

// En mode « custom », on exige au moins login_url + verify_url — sinon l'Electron
// n'a aucun endpoint pour vérifier le token. On redirige avec un message explicite.
if ($mode === 'custom' && ($loginUrl === '' || $verifyUrl === '')) {
    flash_set('error', 'En mode « API Bearer », renseigne au moins l\'URL de login et l\'URL de vérification.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#auth');
}

try {
    $pdo = db();

    // Ownership check
    $check = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $check->execute([$launcherUuid, $user['id']]);
    $row = $check->fetch();
    if (!$row) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php#auth');
    }
    $launcherId = (int)($row['id'] ?? 0);

    $upsert = $pdo->prepare(
        'INSERT INTO launcher_auth (launcher_id, mode, login_url, verify_url, refresh_url, api_key, updated_at) '
      . 'VALUES (?, ?, ?, ?, ?, ?, NOW()) '
      . 'ON DUPLICATE KEY UPDATE mode = VALUES(mode), login_url = VALUES(login_url), verify_url = VALUES(verify_url), refresh_url = VALUES(refresh_url), api_key = VALUES(api_key), updated_at = NOW()'
    );
    $upsert->execute([
        $launcherId,
        $mode,
        $loginUrl   !== '' ? $loginUrl   : null,
        $verifyUrl  !== '' ? $verifyUrl  : null,
        $refreshUrl !== '' ? $refreshUrl : null,
        $apiKey     !== '' ? $apiKey     : null,
    ]);

    $label = $mode === 'microsoft' ? 'Microsoft OAuth'
           : ($mode === 'custom'   ? 'API Bearer personnalisée'
           :                          'Offline');
    flash_set('success', 'Authentification enregistrée : ' . $label . '.');
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'launcher_auth') !== false
     || strpos($msg, "doesn't exist") !== false
     || strpos($msg, 'does not exist') !== false) {
        flash_set(
            'error',
            "Impossible d'enregistrer : la table `launcher_auth` n'existe pas. "
          . 'Importe `migrations_v3.sql` depuis la section SQL du dashboard.'
        );
        redirect('/dashboard.php#sql');
    }
    flash_set('error', 'Erreur base de données : ' . $msg);
}

redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#auth');

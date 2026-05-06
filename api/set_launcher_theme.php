<?php
/**
 * API endpoint: Set launcher theme
 * POST /api/set_launcher_theme.php
 *
 * Saves the selected theme for a launcher.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
$themeId = trim((string)($_POST['theme_id'] ?? ''));

if ($launcherUuid === '' || $themeId === '') {
    flash_set('error', 'Paramètres manquants.');
    redirect('/dashboard.php');
}

// Validate theme exists
$themesDir = __DIR__ . '/../launcher/themes';
$themePath = $themesDir . '/' . $themeId;
if (!is_dir($themePath) || !file_exists($themePath . '/index.html')) {
    flash_set('error', 'Thème invalide.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=apparence');
}

try {
    $pdo = db();

    // Verify user owns this launcher
    $stmt = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$launcherUuid, $user['id']]);
    $launcher = $stmt->fetch();

    if (!$launcher) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }

    $launcherId = (int)$launcher['id'];

    // Update theme
    $updateStmt = $pdo->prepare('UPDATE launchers SET theme = ? WHERE id = ? LIMIT 1');
    $updateStmt->execute([$themeId, $launcherId]);

    flash_set('success', 'Thème mis à jour! Le nouveau thème sera appliqué au prochain lancement du launcher.');

    // JSON response for AJAX calls (customizer live preview)
    if (!empty($_POST['_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
} catch (Throwable $e) {
    if (!empty($_POST['_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    flash_set('error', 'Erreur: ' . $e->getMessage());
}

redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=apparence');

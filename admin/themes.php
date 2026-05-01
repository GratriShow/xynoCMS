<?php
/**
 * Theme Manager - Launcher Theme Selection & Preview
 * /admin/themes.php
 *
 * Allows admin to:
 * - View all available launcher themes
 * - Preview themes
 * - Select active theme
 * - Get theme info
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';

$user = require_login();

// Only allow admin
if (!is_admin($user)) {
    flash_set('error', 'Accès refusé.');
    redirect('/dashboard.php');
}

// Scan available themes
function get_available_themes(): array {
    $themesDir = __DIR__ . '/../launcher/themes';
    $themes = [];

    if (!is_dir($themesDir)) {
        return $themes;
    }

    foreach (scandir($themesDir) as $entry) {
        if ($entry[0] === '.') continue;
        $themePath = $themesDir . '/' . $entry;
        if (!is_dir($themePath)) continue;

        $indexFile = $themePath . '/index.html';
        if (!file_exists($indexFile)) continue;

        // Read theme metadata from index.html
        $htmlContent = file_get_contents($indexFile);
        $title = 'Launcher Theme';
        $description = 'Custom launcher theme';
        $thumbnail = '🎮';

        // Extract title from HTML
        if (preg_match('/<title>([^<]+)<\/title>/i', $htmlContent, $m)) {
            $title = $m[1];
        }

        // Get theme info from badge or header
        if (preg_match('/badge["\']>([^<]+)<\/div>/i', $htmlContent, $m)) {
            $badge = $m[1];
        }

        // Determine emoji based on theme
        $emoji = '🎮';
        if (stripos($entry, 'cosmic') !== false) $emoji = '🌌';
        if (stripos($entry, 'neon') !== false) $emoji = '⚡';
        if (stripos($entry, 'tactical') !== false) $emoji = '🎯';
        if (stripos($entry, 'mystic') !== false) $emoji = '🔮';
        if (stripos($entry, 'minecraft') !== false) $emoji = '🌲';
        if (stripos($entry, 'default') !== false) $emoji = '📦';

        $themes[] = [
            'id' => $entry,
            'name' => ucwords(str_replace('-', ' ', $entry)),
            'title' => $title,
            'description' => $description,
            'emoji' => $emoji,
            'path' => 'launcher/themes/' . $entry,
            'has_renderer' => file_exists($themePath . '/renderer.js'),
            'has_style' => file_exists($themePath . '/style.css'),
        ];
    }

    usort($themes, fn($a, $b) => strcmp($a['id'], $b['id']));
    return $themes;
}

// Get current theme from manifest
function get_current_theme(): string {
    $db = db();
    try {
        $stmt = $db->query("SELECT value FROM settings WHERE key = 'launcher_theme' LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : 'default';
    } catch (Throwable $e) {
        return 'default';
    }
}

// Set active theme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_theme') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Jeton CSRF invalide.');
        redirect('/admin/themes.php');
    }

    $themeId = trim((string)($_POST['theme_id'] ?? ''));
    $themes = get_available_themes();
    $validThemes = array_map(fn($t) => $t['id'], $themes);

    if (!in_array($themeId, $validThemes, true)) {
        flash_set('error', 'Thème invalide.');
        redirect('/admin/themes.php');
    }

    try {
        $db = db();
        $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
        $stmt->execute(['launcher_theme', $themeId, $themeId]);

        flash_set('success', 'Thème mis à jour avec succès. Le thème sera appliqué au prochain démarrage du launcher.');
        redirect('/admin/themes.php');
    } catch (Throwable $e) {
        flash_set('error', 'Erreur lors de la mise à jour : ' . $e->getMessage());
        redirect('/admin/themes.php');
    }
}

$themes = get_available_themes();
$currentTheme = get_current_theme();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des thèmes - Admin</title>
    <link rel="stylesheet" href="/assets/admin.css">
    <style>
        .themes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            margin: 24px 0;
        }

        .theme-card {
            background: #f5f5f5;
            border: 2px solid #ddd;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
        }

        .theme-card:hover {
            border-color: #4CAF50;
            box-shadow: 0 8px 24px rgba(76, 175, 80, 0.15);
            transform: translateY(-4px);
        }

        .theme-card.active {
            border-color: #4CAF50;
            background: rgba(76, 175, 80, 0.05);
            box-shadow: 0 0 20px rgba(76, 175, 80, 0.2);
        }

        .theme-icon {
            font-size: 60px;
            text-align: center;
            margin-bottom: 16px;
        }

        .theme-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .theme-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 16px;
            flex: 1;
        }

        .theme-meta {
            font-size: 12px;
            color: #999;
            margin-bottom: 16px;
            display: flex;
            gap: 12px;
        }

        .theme-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .theme-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            flex: 1;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
        }

        .btn-primary {
            background: #4CAF50;
            color: white;
        }

        .btn-primary:hover {
            background: #388E3C;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            border: 1px solid #ddd;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .btn.active {
            background: #4CAF50;
            color: white;
        }

        .badge {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .preview-container {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
        }

        .preview-iframe {
            width: 100%;
            height: 600px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .admin-header h1 {
            margin: 0;
            font-size: 28px;
        }

        .info-box {
            background: #e8f5e9;
            border-left: 4px solid #4CAF50;
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 4px;
            color: #2e7d32;
        }
    </style>
</head>
<body class="admin">
    <div class="container">
        <div class="admin-header">
            <div>
                <h1>🎨 Gestion des thèmes</h1>
                <p style="color: #666; margin: 8px 0 0;">Sélectionnez et prévisualisez les thèmes du launcher</p>
            </div>
            <a href="/admin/" class="btn btn-secondary">← Retour</a>
        </div>

        <?php if ($message = flash_get('success')): ?>
            <div class="info-box" style="background: #e8f5e9; color: #2e7d32; border-left-color: #4CAF50;">
                ✓ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($message = flash_get('error')): ?>
            <div class="info-box" style="background: #ffebee; color: #c62828; border-left-color: #f44336;">
                ✗ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <strong>💡 Conseil:</strong> Le thème actuellement sélectionné est appliqué au launcher au prochain démarrage.
            Thème actif: <strong><?= htmlspecialchars(ucwords(str_replace('-', ' ', $currentTheme))) ?></strong>
        </div>

        <h2>Thèmes disponibles</h2>
        <div class="themes-grid">
            <?php foreach ($themes as $theme): ?>
                <div class="theme-card <?= $theme['id'] === $currentTheme ? 'active' : '' ?>">
                    <div class="theme-icon"><?= $theme['emoji'] ?></div>
                    <div class="theme-name"><?= htmlspecialchars($theme['name']) ?></div>
                    <div class="theme-desc"><?= htmlspecialchars($theme['description']) ?></div>

                    <div class="theme-meta">
                        <span>
                            <?= $theme['has_style'] ? '✓ CSS' : '✗ CSS' ?>
                        </span>
                        <span>
                            <?= $theme['has_renderer'] ? '✓ JS' : '✗ JS' ?>
                        </span>
                        <?php if ($theme['id'] === $currentTheme): ?>
                            <span><span class="badge">Actif</span></span>
                        <?php endif; ?>
                    </div>

                    <div class="theme-actions">
                        <a href="/<?= htmlspecialchars($theme['path']) ?>/index.html" target="_blank" class="btn btn-secondary">
                            👁 Aperçu
                        </a>
                        <?php if ($theme['id'] !== $currentTheme): ?>
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="set_theme">
                                <input type="hidden" name="theme_id" value="<?= htmlspecialchars($theme['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <button type="submit" class="btn btn-primary" style="width: 100%; margin: 0;">
                                    ✓ Sélectionner
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn active" disabled style="width: 100%; margin: 0;">
                                ✓ Sélectionné
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($themes)): ?>
            <div class="info-box" style="background: #fff3cd; color: #856404; border-left-color: #ffc107; text-align: center;">
                ⚠️ Aucun thème trouvé. Vérifiez que le dossier <code>/launcher/themes</code> existe et contient des thèmes.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

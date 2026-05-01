<?php
/**
 * Theme Selector - Launcher Theme Selection
 * /dashboard/themes.php
 *
 * Allows users to:
 * - View available launcher themes
 * - Preview themes
 * - Select theme for their launcher
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';

$user = require_login();
$pdo = db();

// Get user's launchers
$stmt = $pdo->prepare('SELECT id, uuid, name, theme FROM launchers WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$launchers = $stmt->fetchAll();

// Get selected launcher
$selectedUuid = trim((string)($_GET['launcher'] ?? ''));
$selected = null;
$selectedId = null;

if ($selectedUuid !== '' && !empty($launchers)) {
    foreach ($launchers as $l) {
        if ((string)$l['uuid'] === $selectedUuid) {
            $selected = $l;
            $selectedId = (int)($l['id'] ?? 0);
            break;
        }
    }
}

// If no launcher selected, select the first one
if ($selected === null && !empty($launchers)) {
    $selected = $launchers[0];
    $selectedId = (int)($selected['id'] ?? 0);
}

// Get available themes
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
        if (!file_exists($themePath . '/index.html')) continue;

        // Determine emoji based on theme name
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
            'emoji' => $emoji,
            'path' => 'launcher/themes/' . $entry,
        ];
    }

    usort($themes, fn($a, $b) => strcmp($a['id'], $b['id']));
    return $themes;
}

// Handle theme selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_theme') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Jeton CSRF invalide.');
        redirect('/dashboard.php?launcher=' . urlencode($selectedUuid) . '&tab=themes');
    }

    $themeId = trim((string)($_POST['theme_id'] ?? ''));
    $themes = get_available_themes();
    $validThemes = array_map(fn($t) => $t['id'], $themes);

    if (!in_array($themeId, $validThemes, true)) {
        flash_set('error', 'Thème invalide.');
        redirect('/dashboard.php?launcher=' . urlencode($selectedUuid) . '&tab=themes');
    }

    if (!$selected) {
        flash_set('error', 'Launcher non trouvé.');
        redirect('/dashboard.php?launcher=' . urlencode($selectedUuid) . '&tab=themes');
    }

    try {
        $updateStmt = $pdo->prepare('UPDATE launchers SET theme = ? WHERE id = ? AND user_id = ? LIMIT 1');
        $updateStmt->execute([$themeId, $selectedId, $user['id']]);

        flash_set('success', 'Thème mis à jour avec succès! Le nouveau thème sera appliqué au prochain lancement du launcher.');
        redirect('/dashboard.php?launcher=' . urlencode($selectedUuid) . '&tab=themes');
    } catch (Throwable $e) {
        flash_set('error', 'Erreur lors de la mise à jour : ' . $e->getMessage());
        redirect('/dashboard.php?launcher=' . urlencode($selectedUuid) . '&tab=themes');
    }
}

$themes = get_available_themes();
$currentTheme = $selected ? (string)($selected['theme'] ?? 'default') : 'default';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($selected ? $selected['name'] : 'Launcher') ?> · Thèmes</title>
  <link rel="stylesheet" href="/assets/style.css" />
  <style>
    .themes-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 32px;
    }

    .launcher-selector {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-bottom: 24px;
    }

    .launcher-selector select {
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.05);
      color: white;
      font-size: 14px;
      cursor: pointer;
    }

    .launcher-selector select:hover,
    .launcher-selector select:focus {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.2);
      outline: none;
    }

    .themes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
      margin-top: 24px;
    }

    .theme-card {
      background: rgba(255, 255, 255, 0.03);
      border: 2px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 20px;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      flex-direction: column;
      text-decoration: none;
      color: inherit;
    }

    .theme-card:hover {
      border-color: rgba(76, 175, 80, 0.5);
      background: rgba(76, 175, 80, 0.05);
      box-shadow: 0 8px 24px rgba(76, 175, 80, 0.15);
      transform: translateY(-4px);
    }

    .theme-card.active {
      border-color: rgba(76, 175, 80, 0.8);
      background: rgba(76, 175, 80, 0.1);
      box-shadow: 0 0 20px rgba(76, 175, 80, 0.2);
    }

    .theme-icon {
      font-size: 56px;
      text-align: center;
      margin-bottom: 16px;
    }

    .theme-name {
      font-size: 16px;
      font-weight: 600;
      color: #fff;
      margin-bottom: 4px;
    }

    .theme-status {
      font-size: 12px;
      color: #8a8aa0;
      margin-bottom: 16px;
      flex: 1;
    }

    .theme-status.active {
      color: #4caf50;
      font-weight: 600;
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
      display: inline-block;
    }

    .btn-primary {
      background: #4caf50;
      color: white;
    }

    .btn-primary:hover {
      background: #388e3c;
    }

    .btn-secondary {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .btn-secondary:hover {
      background: rgba(255, 255, 255, 0.15);
    }

    .btn-secondary:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .info-banner {
      background: rgba(76, 175, 80, 0.1);
      border-left: 4px solid #4caf50;
      padding: 16px;
      border-radius: 6px;
      margin-bottom: 24px;
      color: #fff;
    }

    .info-banner strong {
      color: #4caf50;
    }

    @media (max-width: 768px) {
      .themes-grid {
        grid-template-columns: 1fr;
      }

      .themes-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
      }
    }
  </style>
</head>
<body class="dashboard">
  <div class="container">
    <div class="themes-header">
      <div>
        <h1>🎨 Thèmes du Launcher</h1>
        <p style="color: #8a8aa0; margin: 8px 0 0;">Personnalisez l'apparence de votre launcher</p>
      </div>
      <a href="/dashboard.php" class="btn btn-secondary">← Retour</a>
    </div>

    <?php if (!empty($launchers)): ?>
      <div class="launcher-selector">
        <label for="launcher-select" style="color: #8a8aa0; font-size: 13px; text-transform: uppercase; letter-spacing: .4px;">Launcher:</label>
        <select id="launcher-select" onchange="if(this.value) window.location.href='/dashboard.php?launcher=' + encodeURIComponent(this.value) + '&tab=themes'">
          <option value="">-- Sélectionner --</option>
          <?php foreach ($launchers as $l): ?>
            <option value="<?= htmlspecialchars($l['uuid']) ?>" <?= ($l['uuid'] === $selectedUuid) ? 'selected' : '' ?>>
              <?= htmlspecialchars($l['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($message = flash_get('success')): ?>
      <div class="info-banner" style="background: rgba(76, 175, 80, 0.1); border-left-color: #4caf50; color: #fff;">
        ✓ <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <?php if ($message = flash_get('error')): ?>
      <div class="info-banner" style="background: rgba(244, 67, 54, 0.1); border-left-color: #f44336; color: #fca5a5;">
        ✗ <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <?php if ($selected): ?>
      <div class="info-banner">
        <strong>💡 Conseil:</strong> Le thème sélectionné sera appliqué au prochain lancement du launcher. Thème actuel: <strong><?= htmlspecialchars(ucwords(str_replace('-', ' ', $currentTheme))) ?></strong>
      </div>
    <?php endif; ?>

    <h2>Thèmes disponibles</h2>
    <div class="themes-grid">
      <?php foreach ($themes as $theme): ?>
        <div class="theme-card <?= $theme['id'] === $currentTheme ? 'active' : '' ?>">
          <div class="theme-icon"><?= $theme['emoji'] ?></div>
          <div class="theme-name"><?= htmlspecialchars($theme['name']) ?></div>
          <div class="theme-status <?= $theme['id'] === $currentTheme ? 'active' : '' ?>">
            <?= $theme['id'] === $currentTheme ? '✓ Sélectionné' : 'Disponible' ?>
          </div>

          <div class="theme-actions">
            <a href="/<?= htmlspecialchars($theme['path']) ?>/index.html" target="_blank" class="btn btn-secondary">
              👁 Aperçu
            </a>
            <?php if ($selected && $theme['id'] !== $currentTheme): ?>
              <form method="POST" style="flex: 1; margin: 0;">
                <input type="hidden" name="action" value="set_theme">
                <input type="hidden" name="theme_id" value="<?= htmlspecialchars($theme['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" class="btn btn-primary" style="width: 100%; margin: 0;">
                  ✓ Sélectionner
                </button>
              </form>
            <?php elseif (!$selected): ?>
              <button class="btn btn-secondary" disabled style="width: 100%; margin: 0;">
                Sélectionner un launcher
              </button>
            <?php else: ?>
              <button class="btn btn-secondary" disabled style="width: 100%; margin: 0;">
                ✓ Actif
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($themes)): ?>
      <div class="info-banner" style="background: rgba(255, 193, 7, 0.1); border-left-color: #ffc107; text-align: center;">
        ⚠️ Aucun thème trouvé. Contactez votre administrateur.
      </div>
    <?php endif; ?>

    <?php if (empty($launchers)): ?>
      <div class="info-banner" style="background: rgba(255, 193, 7, 0.1); border-left-color: #ffc107; text-align: center;">
        ⚠️ Aucun launcher trouvé. <a href="/dashboard.php?tab=general" style="color: #ffc107; text-decoration: underline;">Créez un launcher</a> pour commencer.
      </div>
    <?php endif; ?>
  </div>
</body>
</html>

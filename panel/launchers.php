<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

$isAdmin = false;
try {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $r = $s->fetch();
    $isAdmin = $r && (int)($r['is_admin'] ?? 0) === 1;
} catch (Throwable) {}

// Flash
$flash    = flash_get('success');
$flashErr = flash_get('error');

// Launchers
$launchers = [];
try {
    $s = $pdo->prepare(
        'SELECT l.uuid, l.name, l.description, l.version, l.loader, l.theme, l.created_at,
                (SELECT COUNT(*) FROM launcher_versions v WHERE v.launcher_id = l.id) AS version_count,
                (SELECT COUNT(*) FROM launcher_mods m WHERE m.launcher_id = l.id) AS mod_count
         FROM launchers l WHERE l.user_id = ? ORDER BY l.created_at DESC'
    );
    $s->execute([$user['id']]);
    $launchers = $s->fetchAll();
} catch (Throwable) {}

$sidebarCounts = ['launchers' => count($launchers)];
$pageTitle     = 'XynoLauncher';
$activeSection = 'launchers';
$breadcrumbs   = [['label' => 'XynoLauncher']];
$topbarActions = [
    ['label' => '+ Nouveau launcher', 'url' => base_path() . '/dashboard/dashboard.php', 'primary' => true],
];

require_once __DIR__ . '/_layout.php';
?>

<div class="panel-page-header">
  <div>
    <h1 class="panel-page-title">🚀 XynoLauncher</h1>
    <p class="panel-page-subtitle"><?= count($launchers) ?> launcher<?= count($launchers) !== 1 ? 's' : '' ?> configuré<?= count($launchers) !== 1 ? 's' : '' ?></p>
  </div>
  <a href="<?= base_path() ?>/dashboard/dashboard.php" class="btn btn-primary">+ Nouveau launcher</a>
</div>

<?php if ($flash): ?>
  <div class="flash-msg flash-success">✓ <?= e($flash) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
  <div class="flash-msg flash-error">✕ <?= e($flashErr) ?></div>
<?php endif; ?>

<?php if (empty($launchers)): ?>
  <div class="panel-card">
    <div class="empty-state">
      <div class="empty-state-icon">🚀</div>
      <div class="empty-state-title">Aucun launcher créé</div>
      <div class="empty-state-text">Créez votre premier launcher Minecraft pour commencer à distribuer votre modpack.</div>
      <a href="<?= base_path() ?>/dashboard/dashboard.php" class="btn btn-primary">Créer mon premier launcher</a>
    </div>
  </div>
<?php else: ?>

  <div class="item-list">
    <?php foreach ($launchers as $l): ?>
      <?php
        $loaderColors = ['forge' => '#ff8c42', 'fabric' => '#7c5cff', 'quilt' => '#9b59b6', 'vanilla' => '#00d68f'];
        $loaderColor  = $loaderColors[strtolower($l['loader'] ?? '')] ?? '#888';
      ?>
      <div class="item-row" style="padding:18px 20px;">
        <div class="item-icon" style="background:<?= $loaderColor ?>22;border-color:<?= $loaderColor ?>44;font-size:22px;">
          🎮
        </div>
        <div class="item-info">
          <div class="item-name" style="font-size:15px;"><?= e($l['name'] ?? 'Sans nom') ?></div>
          <div class="item-meta">
            <?= e($l['version'] ?? '?') ?>
            &nbsp;·&nbsp;
            <span style="color:<?= $loaderColor ?>;font-weight:600;"><?= e(ucfirst($l['loader'] ?? 'vanilla')) ?></span>
            &nbsp;·&nbsp;
            <?= (int)($l['version_count'] ?? 0) ?> version<?= (int)($l['version_count'] ?? 0) !== 1 ? 's' : '' ?>
            &nbsp;·&nbsp;
            <?= (int)($l['mod_count'] ?? 0) ?> mod<?= (int)($l['mod_count'] ?? 0) !== 1 ? 's' : '' ?>
          </div>
          <?php if (!empty($l['description'])): ?>
            <div class="item-meta" style="margin-top:4px;font-size:11px;opacity:.7;"><?= e(mb_substr((string)$l['description'], 0, 80)) ?><?= mb_strlen((string)$l['description']) > 80 ? '…' : '' ?></div>
          <?php endif; ?>
        </div>
        <div class="item-actions">
          <a href="<?= base_path() ?>/panel/launcher.php?uuid=<?= urlencode((string)$l['uuid']) ?>" class="btn btn-ghost btn-sm">Gérer</a>
          <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode((string)$l['uuid']) ?>" class="btn btn-ghost btn-sm" title="Ancien dashboard">⚙</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

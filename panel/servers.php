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

$flash    = flash_get('success');
$flashErr = flash_get('error');

$servers = [];
try {
    $s = $pdo->prepare(
        'SELECT s.id, s.server_name, s.server_type, s.mc_version, s.status,
                s.server_ip, s.server_port, s.plan_slug, s.created_at,
                (SELECT COUNT(*) FROM mc_server_launcher_links l WHERE l.server_id = s.id) AS link_count
         FROM mc_servers s WHERE s.user_id = ? ORDER BY s.created_at DESC'
    );
    $s->execute([$user['id']]);
    $servers = $s->fetchAll();
} catch (Throwable) {}

$serversOnline = count(array_filter($servers, fn($sv) => strtolower((string)($sv['status'] ?? '')) === 'running'));

$sidebarCounts = ['servers_online' => $serversOnline];
$pageTitle     = 'XynoServer';
$activeSection = 'servers';
$breadcrumbs   = [['label' => 'XynoServer']];
$topbarActions = [
    ['label' => '+ Nouveau serveur', 'url' => base_path() . '/server-cms/dashboard/create.php', 'primary' => true],
];

$typeColors = ['vanilla' => '#00d68f', 'paper' => '#ff6b6b', 'spigot' => '#ffbe00', 'forge' => '#ff8c42', 'fabric' => '#7c5cff'];
$typeIcons  = ['vanilla' => '🟢', 'paper' => '📄', 'spigot' => '🔌', 'forge' => '⚙️', 'fabric' => '🧵'];

require_once __DIR__ . '/_layout.php';
?>

<div class="panel-page-header">
  <div>
    <h1 class="panel-page-title">🖥️ XynoServer</h1>
    <p class="panel-page-subtitle">
      <?= count($servers) ?> serveur<?= count($servers) !== 1 ? 's' : '' ?>
      <?php if ($serversOnline > 0): ?>
        — <span style="color:#00d68f;font-weight:600;"><?= $serversOnline ?> en ligne</span>
      <?php endif; ?>
    </p>
  </div>
  <a href="<?= base_path() ?>/server-cms/dashboard/create.php" class="btn btn-primary">+ Nouveau serveur</a>
</div>

<?php if ($flash): ?>
  <div class="flash-msg flash-success">✓ <?= e($flash) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
  <div class="flash-msg flash-error">✕ <?= e($flashErr) ?></div>
<?php endif; ?>

<?php if (empty($servers)): ?>
  <div class="panel-card">
    <div class="empty-state">
      <div class="empty-state-icon">🖥️</div>
      <div class="empty-state-title">Aucun serveur créé</div>
      <div class="empty-state-text">Déployez votre premier serveur Minecraft en quelques clics.</div>
      <a href="<?= base_path() ?>/server-cms/dashboard/create.php" class="btn btn-primary">Créer un serveur</a>
    </div>
  </div>
<?php else: ?>

  <div class="item-list">
    <?php foreach ($servers as $sv):
      $st = strtolower((string)($sv['status'] ?? 'stopped'));
      $pillClass = match($st) { 'running' => 'pill-green', 'starting' => 'pill-amber', 'stopping' => 'pill-amber', default => 'pill-grey' };
      $pillLabel = match($st) { 'running' => '● En ligne', 'starting' => '◌ Démarrage', 'stopping' => '◌ Arrêt', default => '○ Arrêté' };
      $type  = strtolower((string)($sv['server_type'] ?? 'vanilla'));
      $color = $typeColors[$type] ?? '#888';
      $icon  = $typeIcons[$type] ?? '🟢';
      $plan  = strtolower((string)($sv['plan_slug'] ?? ''));
      $planLabel = match($plan) { 'spark' => 'Spark', 'core' => 'Core', 'pro' => 'Pro', 'max' => 'Max', default => ucfirst($plan) ?: 'Standard' };
    ?>
      <div class="item-row" style="padding:18px 20px;">
        <div class="item-icon" style="background:<?= $color ?>18;border-color:<?= $color ?>33;font-size:22px;"><?= $icon ?></div>
        <div class="item-info">
          <div class="item-name" style="font-size:15px;"><?= e($sv['server_name'] ?? 'Sans nom') ?></div>
          <div class="item-meta">
            <span style="color:<?= $color ?>;font-weight:600;"><?= e(ucfirst($type)) ?></span>
            &nbsp;·&nbsp; <?= e($sv['mc_version'] ?? '?') ?>
            <?php if (!empty($sv['server_ip'])): ?>
              &nbsp;·&nbsp; <code style="font-size:11px;"><?= e($sv['server_ip']) ?>:<?= (int)($sv['server_port'] ?? 25565) ?></code>
            <?php endif; ?>
            &nbsp;·&nbsp; Plan <?= e($planLabel) ?>
            <?php if ((int)($sv['link_count'] ?? 0) > 0): ?>
              &nbsp;·&nbsp; <?= (int)$sv['link_count'] ?> launcher<?= (int)$sv['link_count'] !== 1 ? 's' : '' ?> lié<?= (int)$sv['link_count'] !== 1 ? 's' : '' ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="item-actions">
          <span class="pill <?= $pillClass ?>" style="font-size:11px;"><?= $pillLabel ?></span>
          <a href="<?= base_path() ?>/panel/server.php?id=<?= (int)$sv['id'] ?>" class="btn btn-ghost btn-sm">Gérer</a>
          <a href="<?= base_path() ?>/server-cms/dashboard/console.php?id=<?= (int)$sv['id'] ?>" class="btn btn-ghost btn-sm" title="Console">🖥</a>
          <a href="<?= base_path() ?>/server-cms/dashboard/monitoring.php?id=<?= (int)$sv['id'] ?>" class="btn btn-ghost btn-sm" title="Monitoring">📊</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

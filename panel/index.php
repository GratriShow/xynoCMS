<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

// Admin check
$isAdmin = false;
try {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $r = $s->fetch();
    $isAdmin = $r && (int)($r['is_admin'] ?? 0) === 1;
} catch (Throwable) {}

// Stats
$launcherCount = 0;
$serverCount   = 0;
$serversOnline = 0;
$totalPlayers  = 0;
$recentLaunchers = [];
$recentServers   = [];

try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM launchers WHERE user_id = ?');
    $s->execute([$user['id']]);
    $launcherCount = (int)$s->fetchColumn();

    $s = $pdo->prepare('SELECT COUNT(*) FROM mc_servers WHERE user_id = ?');
    $s->execute([$user['id']]);
    $serverCount = (int)$s->fetchColumn();

    $s = $pdo->prepare('SELECT COUNT(*) FROM mc_servers WHERE user_id = ? AND status = ?');
    $s->execute([$user['id'], 'running']);
    $serversOnline = (int)$s->fetchColumn();

    $s = $pdo->prepare('SELECT uuid, name, version, loader, created_at FROM launchers WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
    $s->execute([$user['id']]);
    $recentLaunchers = $s->fetchAll();

    $s = $pdo->prepare('SELECT id, server_name, server_type, mc_version, status, created_at FROM mc_servers WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
    $s->execute([$user['id']]);
    $recentServers = $s->fetchAll();
} catch (Throwable) {}

// Sidebar counts
$sidebarCounts = ['launchers' => $launcherCount, 'servers_online' => $serversOnline];

$pageTitle     = 'Vue d\'ensemble';
$activeSection = 'overview';
$breadcrumbs   = [['label' => 'Vue d\'ensemble']];
$topbarActions = [
    ['label' => '+ Créer un launcher', 'url' => base_path() . '/panel/launchers.php?new=1', 'primary' => true],
];

require_once __DIR__ . '/_layout.php';
?>

<div class="panel-page-header">
  <div>
    <h1 class="panel-page-title">👋 Bonjour<?= !empty($user['email']) ? ', ' . e(explode('@', $user['email'])[0]) : '' ?></h1>
    <p class="panel-page-subtitle">Voici un aperçu de votre espace XynoWeb.</p>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-card-label">Launchers</div>
    <div class="stat-card-value"><?= $launcherCount ?></div>
    <div class="stat-card-sub">XynoLauncher configurés</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Serveurs</div>
    <div class="stat-card-value"><?= $serverCount ?></div>
    <div class="stat-card-sub">XynoServer créés</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">En ligne</div>
    <div class="stat-card-value" style="<?= $serversOnline > 0 ? 'background:linear-gradient(135deg,#00d68f,#00b07a);' : '' ?>"><?= $serversOnline ?></div>
    <div class="stat-card-sub">Serveurs actifs maintenant</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Produits</div>
    <div class="stat-card-value">2</div>
    <div class="stat-card-sub">1 à venir (XynoSite)</div>
  </div>
</div>

<!-- Quick actions + recent items grid -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Recent launchers -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">🚀 Derniers launchers</div>
        <div class="panel-card-subtitle">Vos launchers XynoLauncher récents</div>
      </div>
      <a href="<?= base_path() ?>/panel/launchers.php" class="btn btn-ghost btn-sm">Voir tout</a>
    </div>
    <?php if (empty($recentLaunchers)): ?>
      <div class="empty-state" style="padding:30px 20px;">
        <div class="empty-state-icon">🚀</div>
        <div class="empty-state-title">Aucun launcher</div>
        <div class="empty-state-text">Créez votre premier launcher pour commencer.</div>
        <a href="<?= base_path() ?>/panel/launchers.php?new=1" class="btn btn-primary btn-sm">Créer un launcher</a>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($recentLaunchers as $l): ?>
          <a href="<?= base_path() ?>/panel/launcher.php?uuid=<?= urlencode((string)$l['uuid']) ?>" class="item-row">
            <div class="item-icon">🎮</div>
            <div class="item-info">
              <div class="item-name"><?= e($l['name'] ?? 'Sans nom') ?></div>
              <div class="item-meta"><?= e($l['version'] ?? '?') ?> — <?= e(ucfirst($l['loader'] ?? 'vanilla')) ?></div>
            </div>
            <span>›</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent servers -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">🖥️ Derniers serveurs</div>
        <div class="panel-card-subtitle">Vos serveurs Minecraft XynoServer</div>
      </div>
      <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost btn-sm">Voir tout</a>
    </div>
    <?php if (empty($recentServers)): ?>
      <div class="empty-state" style="padding:30px 20px;">
        <div class="empty-state-icon">🖥️</div>
        <div class="empty-state-title">Aucun serveur</div>
        <div class="empty-state-text">Déployez votre premier serveur Minecraft.</div>
        <a href="<?= base_path() ?>/panel/servers.php?new=1" class="btn btn-primary btn-sm">Créer un serveur</a>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($recentServers as $sv): ?>
          <?php
            $st = strtolower((string)($sv['status'] ?? 'stopped'));
            $pillClass = match($st) { 'running' => 'pill-green', 'starting' => 'pill-amber', default => 'pill-grey' };
            $pillLabel = match($st) { 'running' => 'En ligne', 'starting' => 'Démarrage', default => 'Arrêté' };
          ?>
          <a href="<?= base_path() ?>/panel/server.php?id=<?= (int)$sv['id'] ?>" class="item-row">
            <div class="item-icon">⛏️</div>
            <div class="item-info">
              <div class="item-name"><?= e($sv['server_name'] ?? 'Sans nom') ?></div>
              <div class="item-meta"><?= e(ucfirst($sv['server_type'] ?? '')) ?> <?= e($sv['mc_version'] ?? '') ?></div>
            </div>
            <span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Product cards -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
  <div class="panel-card" style="border-color:var(--accent-border);background:var(--surface-2);">
    <div style="font-size:28px;margin-bottom:12px;">🚀</div>
    <div style="font-size:15px;font-weight:700;margin-bottom:6px;">XynoLauncher</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.5;">
      Créez et distribuez votre launcher Minecraft personnalisé avec mods, modpacks et authentification.
    </div>
    <a href="<?= base_path() ?>/panel/launchers.php" class="btn btn-primary" style="width:100%;justify-content:center;">Gérer les launchers</a>
  </div>

  <div class="panel-card" style="border-color:rgba(0,214,143,.2);background:var(--surface-2);">
    <div style="font-size:28px;margin-bottom:12px;">🖥️</div>
    <div style="font-size:15px;font-weight:700;margin-bottom:6px;">XynoServer</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.5;">
      Déployez et gérez vos serveurs Minecraft : console live, monitoring temps réel, backups automatiques.
    </div>
    <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost" style="width:100%;justify-content:center;border-color:rgba(0,214,143,.25);color:#00d68f;">Gérer les serveurs</a>
  </div>

  <div class="panel-card" style="opacity:.5;">
    <div style="font-size:28px;margin-bottom:12px;">🌐</div>
    <div style="font-size:15px;font-weight:700;margin-bottom:6px;">XynoSite <span style="font-size:11px;color:var(--muted);font-weight:400;">(Bientôt)</span></div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.5;">
      Créez le site web de votre serveur Minecraft avec pages de vote, shop et classements de joueurs.
    </div>
    <button disabled class="btn btn-ghost" style="width:100%;justify-content:center;cursor:not-allowed;">En développement</button>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

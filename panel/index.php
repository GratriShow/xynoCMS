<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

$isAdmin = false;
try {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]); $r = $s->fetch();
    $isAdmin = $r && (int)($r['is_admin'] ?? 0) === 1;
} catch (Throwable) {}

$launcherCount = 0; $serverCount = 0; $serversOnline = 0;
$recentLaunchers = []; $recentServers = [];

try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM launchers WHERE user_id = ?'); $s->execute([$user['id']]); $launcherCount = (int)$s->fetchColumn();
    $s = $pdo->prepare('SELECT COUNT(*) FROM mc_servers WHERE user_id = ?'); $s->execute([$user['id']]); $serverCount = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM mc_servers WHERE user_id = ? AND status = 'running'"); $s->execute([$user['id']]); $serversOnline = (int)$s->fetchColumn();
    $s = $pdo->prepare('SELECT uuid, name, version, loader FROM launchers WHERE user_id = ? ORDER BY created_at DESC LIMIT 4'); $s->execute([$user['id']]); $recentLaunchers = $s->fetchAll();
    // Compatibilité : colonne `name` (ancienne) ou `server_name` (après migration 003)
    try {
        $s = $pdo->prepare('SELECT id, server_name, server_type, mc_version, status FROM mc_servers WHERE user_id = ? ORDER BY created_at DESC LIMIT 4');
        $s->execute([$user['id']]); $recentServers = $s->fetchAll();
    } catch (Throwable) {
        $s = $pdo->prepare('SELECT id, name AS server_name, server_type, mc_version, status FROM mc_servers WHERE user_id = ? ORDER BY created_at DESC LIMIT 4');
        $s->execute([$user['id']]); $recentServers = $s->fetchAll();
    }
} catch (Throwable) {}

$sidebarCounts = ['launchers' => $launcherCount, 'servers_online' => $serversOnline];
$pageTitle     = 'Vue d\'ensemble';
$activeSection = 'overview';
$breadcrumbs   = [['label' => 'Vue d\'ensemble']];
$topbarActions = [['label' => '+ Nouveau launcher', 'url' => base_path() . '/dashboard/dashboard.php', 'primary' => true]];

require_once __DIR__ . '/_layout.php';
?>

<div class="panel-page-header">
  <div>
    <div class="panel-page-title">👋 Bonjour, <?= e(explode('@', (string)($user['email'] ?? 'vous'))[0]) ?></div>
    <div class="panel-page-subtitle">Voici votre espace XynoWeb</div>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card">
    <div class="stat-label">Launchers</div>
    <div class="stat-value"><?= $launcherCount ?></div>
    <div class="stat-sub">configurés</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Serveurs</div>
    <div class="stat-value"><?= $serverCount ?></div>
    <div class="stat-sub">créés</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">En ligne</div>
    <div class="stat-value <?= $serversOnline > 0 ? 'green' : '' ?>"><?= $serversOnline ?></div>
    <div class="stat-sub">actifs maintenant</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Produits actifs</div>
    <div class="stat-value">2</div>
    <div class="stat-sub">XynoSite bientôt</div>
  </div>
</div>

<!-- Recent items -->
<div class="grid-2" style="margin-bottom:20px;">

  <div class="panel-card">
    <div class="card-header">
      <div>
        <div class="card-title">🚀 Derniers launchers</div>
        <div class="card-subtitle"><?= $launcherCount ?> au total</div>
      </div>
      <a href="<?= base_path() ?>/panel/launchers.php" class="btn btn-ghost btn-sm">Voir tout →</a>
    </div>
    <?php if (empty($recentLaunchers)): ?>
      <div class="empty-state" style="padding:30px;">
        <div class="empty-icon">🚀</div>
        <div class="empty-title">Aucun launcher</div>
        <div class="empty-text">Créez votre premier launcher Minecraft.</div>
        <a href="<?= base_path() ?>/dashboard/dashboard.php" class="btn btn-primary btn-sm">Créer un launcher</a>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($recentLaunchers as $l):
          $lc = ['forge'=>'#ff8c42','fabric'=>'#7c5cff','quilt'=>'#9b59b6','neoforge'=>'#e67e22'][$l['loader'] ?? ''] ?? '#00d68f';
        ?>
          <a href="<?= base_path() ?>/panel/launcher.php?uuid=<?= urlencode((string)$l['uuid']) ?>" class="item-row">
            <div class="item-icon" style="background:<?= $lc ?>18;border-color:<?= $lc ?>33;">🎮</div>
            <div class="item-info">
              <div class="item-name"><?= e($l['name'] ?? 'Sans nom') ?></div>
              <div class="item-meta"><?= e($l['version'] ?? '?') ?> · <?= e(ucfirst($l['loader'] ?? 'vanilla')) ?></div>
            </div>
            <span style="color:#3a3a60;font-size:16px;">›</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel-card">
    <div class="card-header">
      <div>
        <div class="card-title">🖥️ Derniers serveurs</div>
        <div class="card-subtitle"><?= $serverCount ?> au total</div>
      </div>
      <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost btn-sm">Voir tout →</a>
    </div>
    <?php if (empty($recentServers)): ?>
      <div class="empty-state" style="padding:30px;">
        <div class="empty-icon">🖥️</div>
        <div class="empty-title">Aucun serveur</div>
        <div class="empty-text">Déployez votre premier serveur Minecraft.</div>
        <a href="<?= base_path() ?>/server-cms/dashboard/create.php" class="btn btn-primary btn-sm">Créer un serveur</a>
      </div>
    <?php else: ?>
      <?php
        $svTypeColors = ['vanilla'=>'#00d68f','paper'=>'#ff6b6b','spigot'=>'#ffbe00','forge'=>'#ff8c42','fabric'=>'#7c5cff','neoforge'=>'#e67e22'];
        $svTypeIcons  = ['vanilla'=>'🟢','paper'=>'📄','spigot'=>'🔌','forge'=>'⚙️','fabric'=>'🧵','neoforge'=>'🔥'];
      ?>
      <div class="item-list">
        <?php foreach ($recentServers as $sv):
          $st   = strtolower((string)($sv['status'] ?? 'stopped'));
          $type = strtolower((string)($sv['server_type'] ?? 'vanilla'));
          $sc   = $svTypeColors[$type] ?? '#888888';
          $si   = $svTypeIcons[$type]  ?? '🖥️';
          $pc   = match($st) { 'running' => 'pill-green', 'starting','stopping' => 'pill-amber', default => 'pill-grey' };
          $pl   = match($st) { 'running' => '● En ligne', 'starting' => '◌ Démarre', 'stopping' => '◌ Arrêt', default => '○ Arrêté' };
        ?>
          <a href="<?= base_path() ?>/panel/server.php?id=<?= (int)$sv['id'] ?>" class="item-row">
            <div class="item-icon" style="background:<?= $sc ?>18;border-color:<?= $sc ?>33;"><?= $si ?></div>
            <div class="item-info">
              <div class="item-name"><?= e($sv['server_name'] ?? 'Sans nom') ?></div>
              <div class="item-meta">
                <span style="color:<?= $sc ?>;font-weight:600;"><?= e(ucfirst($type)) ?></span>
                <?php if (!empty($sv['mc_version'])): ?> · <?= e($sv['mc_version']) ?><?php endif; ?>
              </div>
            </div>
            <span class="pill <?= $pc ?>"><?= $pl ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Product cards -->
<div class="grid-3">
  <div class="panel-card" style="border-color:rgba(124,92,255,.2);">
    <div style="font-size:26px;margin-bottom:10px;">🚀</div>
    <div style="font-size:14px;font-weight:700;color:#e0e0f0;margin-bottom:6px;">XynoLauncher</div>
    <div style="font-size:12px;color:#4848a0;line-height:1.6;margin-bottom:16px;">Launcher Minecraft personnalisé — mods, modpacks, auth flexible.</div>
    <a href="<?= base_path() ?>/panel/launchers.php" class="btn btn-primary" style="width:100%;justify-content:center;">Gérer →</a>
  </div>
  <div class="panel-card" style="border-color:rgba(0,214,143,.15);">
    <div style="font-size:26px;margin-bottom:10px;">🖥️</div>
    <div style="font-size:14px;font-weight:700;color:#e0e0f0;margin-bottom:6px;">XynoServer</div>
    <div style="font-size:12px;color:#4848a0;line-height:1.6;margin-bottom:16px;">Console live, monitoring temps réel, backups automatiques.</div>
    <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost" style="width:100%;justify-content:center;color:#00d68f;border-color:rgba(0,214,143,.2);">Gérer →</a>
  </div>
  <div class="panel-card" style="opacity:.4;pointer-events:none;">
    <div style="font-size:26px;margin-bottom:10px;">🌐</div>
    <div style="font-size:14px;font-weight:700;color:#e0e0f0;margin-bottom:6px;">XynoSite <span style="font-size:11px;color:#3a3a60;font-weight:400;">bientôt</span></div>
    <div style="font-size:12px;color:#4848a0;line-height:1.6;margin-bottom:16px;">Site communauté, shop, votes joueurs — en développement.</div>
    <button disabled class="btn btn-ghost" style="width:100%;justify-content:center;cursor:not-allowed;">En développement</button>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

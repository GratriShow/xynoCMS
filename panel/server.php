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

$serverId = (int)($_GET['id'] ?? 0);
if (!$serverId) { redirect('/panel/servers.php'); }

$server = null;
try {
    $s = $pdo->prepare(
        'SELECT s.*, p.name AS plan_name, p.ram_mb, p.max_players
         FROM mc_servers s LEFT JOIN mc_server_plans p ON p.slug = s.plan_slug
         WHERE s.id = ? AND s.user_id = ? LIMIT 1'
    );
    $s->execute([$serverId, $user['id']]); $server = $s->fetch() ?: null;
} catch (Throwable) {}
if (!$server) { redirect('/panel/servers.php'); }

$plugins = []; $mods = [];
try {
    $s = $pdo->prepare('SELECT name, version, added_at FROM mc_server_plugins WHERE server_id = ? ORDER BY added_at DESC LIMIT 30'); $s->execute([$serverId]); $plugins = $s->fetchAll();
    $s = $pdo->prepare('SELECT name, version, added_at FROM mc_server_mods WHERE server_id = ? ORDER BY added_at DESC LIMIT 30'); $s->execute([$serverId]); $mods = $s->fetchAll();
} catch (Throwable) {}

$links = [];
try {
    $s = $pdo->prepare(
        'SELECT lnk.launcher_uuid, l.name AS launcher_name, l.version, l.loader, lnk.linked_at
         FROM mc_server_launcher_links lnk LEFT JOIN launchers l ON l.uuid = lnk.launcher_uuid
         WHERE lnk.server_id = ? ORDER BY lnk.linked_at DESC'
    ); $s->execute([$serverId]); $links = $s->fetchAll();
} catch (Throwable) {}

$players = [];
try {
    $s = $pdo->prepare('SELECT username, uuid, added_at FROM mc_server_players WHERE server_id = ? ORDER BY added_at DESC LIMIT 30'); $s->execute([$serverId]); $players = $s->fetchAll();
} catch (Throwable) {}

$st    = strtolower((string)($server['status'] ?? 'stopped'));
$pc    = match($st) { 'running' => 'pill-green', 'starting', 'stopping' => 'pill-amber', default => 'pill-grey' };
$pl    = match($st) { 'running' => '● En ligne', 'starting' => '◌ Démarrage', 'stopping' => '◌ Arrêt', default => '○ Arrêté' };
$type  = strtolower((string)($server['server_type'] ?? 'vanilla'));
$typeColors = ['vanilla' => '#00d68f', 'paper' => '#ff6b6b', 'spigot' => '#ffbe00', 'forge' => '#ff8c42', 'fabric' => '#7c5cff'];
$typeIcons  = ['vanilla' => '🟢', 'paper' => '📄', 'spigot' => '🔌', 'forge' => '⚙️', 'fabric' => '🧵'];
$color   = $typeColors[$type] ?? '#888';
$icon    = $typeIcons[$type]  ?? '🖥️';
$isPlugin = in_array($type, ['paper', 'spigot'], true);
$serverUuid = (string)($server['uuid'] ?? '');

$sidebarCounts = ['servers_online' => ($st === 'running' ? 1 : 0)];
$pageTitle     = $server['server_name'] ?? 'Serveur';
$activeSection = 'servers';
$breadcrumbs   = [
    ['label' => 'XynoServer', 'url' => base_path() . '/panel/servers.php'],
    ['label' => $server['server_name'] ?? 'Serveur'],
];
$topbarActions = [
    ['label' => '🖥 Console', 'url' => base_path() . '/server-cms/dashboard/console.php?id=' . $serverId],
    ['label' => '📊 Monitoring', 'url' => base_path() . '/server-cms/dashboard/monitoring.php?id=' . $serverId],
];

require_once __DIR__ . '/_layout.php';
?>

<style>
  .pwr {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 8px;
    font-size: 13px; font-weight: 600; border: none;
    cursor: pointer; transition: .12s; text-decoration: none; font-family: inherit;
  }
  .pwr-start   { background: rgba(0,214,143,.12); color: #00d68f; border: 1px solid rgba(0,214,143,.22); }
  .pwr-start:hover   { background: rgba(0,214,143,.22); }
  .pwr-stop    { background: rgba(255,77,106,.12);  color: #ff4d6a; border: 1px solid rgba(255,77,106,.22); }
  .pwr-stop:hover    { background: rgba(255,77,106,.22); }
  .pwr-restart { background: rgba(255,190,0,.1);   color: #ffbe00; border: 1px solid rgba(255,190,0,.2); }
  .pwr-restart:hover { background: rgba(255,190,0,.2); }
</style>

<!-- Header -->
<div class="panel-page-header">
  <div style="display:flex;align-items:center;gap:14px;">
    <div style="width:48px;height:48px;border-radius:12px;background:<?= $color ?>18;border:1px solid <?= $color ?>33;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;"><?= $icon ?></div>
    <div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div class="panel-page-title"><?= e($server['server_name'] ?? 'Sans nom') ?></div>
        <span class="pill <?= $pc ?>"><?= $pl ?></span>
      </div>
      <div style="display:flex;gap:12px;margin-top:4px;flex-wrap:wrap;font-size:12px;color:#4848a0;">
        <span style="color:<?= $color ?>;font-weight:600;"><?= e(ucfirst($type)) ?></span>
        <span><?= e($server['mc_version'] ?? '?') ?></span>
        <?php if (!empty($server['plan_name'])): ?><span>Plan <?= e($server['plan_name']) ?></span><?php endif; ?>
        <?php if (!empty($server['server_ip'])): ?><code style="font-size:11px;color:#7c5cff;"><?= e($server['server_ip']) ?>:<?= (int)($server['server_port'] ?? 25565) ?></code><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="panel-header-actions" id="power-btns">
    <?php if ($st !== 'running' && $st !== 'starting'): ?>
      <button class="pwr pwr-start" onclick="sendAction('start')">▶ Démarrer</button>
    <?php endif; ?>
    <?php if ($st === 'running' || $st === 'starting'): ?>
      <button class="pwr pwr-restart" onclick="sendAction('restart')">↺ Redémarrer</button>
      <button class="pwr pwr-stop" onclick="sendAction('stop')">■ Arrêter</button>
    <?php endif; ?>
    <?php if ($serverUuid): ?>
      <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode($serverUuid) ?>" class="pwr btn-ghost">⚙ Config</a>
    <?php endif; ?>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(5,1fr);">
  <div class="stat-card"><div class="stat-label">RAM</div><div class="stat-value"><?= (int)($server['ram_mb'] ?? 2048) ?></div><div class="stat-sub">Mo alloués</div></div>
  <div class="stat-card"><div class="stat-label">Joueurs max</div><div class="stat-value"><?= (int)($server['max_players'] ?? 10) ?></div><div class="stat-sub">slots</div></div>
  <div class="stat-card"><div class="stat-label"><?= $isPlugin ? 'Plugins' : 'Mods' ?></div><div class="stat-value"><?= $isPlugin ? count($plugins) : count($mods) ?></div><div class="stat-sub">installés</div></div>
  <div class="stat-card"><div class="stat-label">Launchers</div><div class="stat-value"><?= count($links) ?></div><div class="stat-sub">liés</div></div>
  <div class="stat-card"><div class="stat-label">Whitelist</div><div class="stat-value"><?= count($players) ?></div><div class="stat-sub">joueurs</div></div>
</div>

<div class="grid-2" style="margin-bottom:16px;">

  <!-- Quick access -->
  <div class="panel-card">
    <div class="card-header"><div class="card-title">🔗 Accès rapide</div></div>
    <div class="item-list">
      <a href="<?= base_path() ?>/server-cms/dashboard/console.php?id=<?= $serverId ?>" class="item-row" style="padding:12px 14px;">
        <div class="item-icon" style="font-size:16px;">🖥️</div>
        <div class="item-info"><div class="item-name">Console live</div><div class="item-meta">Commandes RCON, logs temps réel</div></div>
        <span style="color:#3a3a60;font-size:16px;">›</span>
      </a>
      <a href="<?= base_path() ?>/server-cms/dashboard/monitoring.php?id=<?= $serverId ?>" class="item-row" style="padding:12px 14px;">
        <div class="item-icon" style="font-size:16px;">📊</div>
        <div class="item-info"><div class="item-name">Monitoring</div><div class="item-meta">RAM, CPU, TPS, joueurs connectés</div></div>
        <span style="color:#3a3a60;font-size:16px;">›</span>
      </a>
      <?php if ($serverUuid): ?>
      <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode($serverUuid) ?>" class="item-row" style="padding:12px 14px;">
        <div class="item-icon" style="font-size:16px;">⚙️</div>
        <div class="item-info"><div class="item-name">Configuration complète</div><div class="item-meta">Plugins, mods, whitelist, options avancées</div></div>
        <span style="color:#3a3a60;font-size:16px;">›</span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Linked launchers -->
  <div class="panel-card">
    <div class="card-header">
      <div><div class="card-title">🚀 Launchers liés</div><div class="card-subtitle"><?= count($links) ?> connecté<?= count($links) !== 1 ? 's' : '' ?></div></div>
    </div>
    <?php if (empty($links)): ?>
      <div class="empty-state" style="padding:20px;">
        <div class="empty-text">Aucun launcher connecté. Liez-en un depuis la config complète.</div>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($links as $lnk): ?>
          <a href="<?= base_path() ?>/panel/launcher.php?uuid=<?= urlencode((string)$lnk['launcher_uuid']) ?>" class="item-row" style="padding:10px 12px;">
            <div class="item-icon" style="font-size:16px;">🎮</div>
            <div class="item-info">
              <div class="item-name" style="font-size:13px;"><?= e($lnk['launcher_name'] ?? 'Launcher') ?></div>
              <div class="item-meta"><?= e($lnk['version'] ?? '') ?> <?= $lnk['loader'] ? '· ' . e(ucfirst($lnk['loader'])) : '' ?></div>
            </div>
            <span style="color:#3a3a60;font-size:14px;">›</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Plugins / Mods -->
<div class="panel-card" style="margin-bottom:16px;">
  <div class="card-header">
    <div><div class="card-title"><?= $isPlugin ? '🔌 Plugins' : '⚙️ Mods' ?></div><div class="card-subtitle"><?= $isPlugin ? count($plugins) : count($mods) ?> installé<?= ($isPlugin ? count($plugins) : count($mods)) !== 1 ? 's' : '' ?></div></div>
    <?php if ($serverUuid): ?>
      <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode($serverUuid) ?>" class="btn btn-ghost btn-sm">Gérer</a>
    <?php endif; ?>
  </div>
  <?php $items = $isPlugin ? $plugins : $mods; ?>
  <?php if (empty($items)): ?>
    <div style="text-align:center;padding:20px;color:#3a3a60;font-size:13px;">Aucun <?= $isPlugin ? 'plugin' : 'mod' ?> installé.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
      <?php foreach ($items as $item): ?>
        <div style="background:#0a0a1a;border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:10px 12px;">
          <div style="font-size:13px;font-weight:600;color:#c0c0e0;margin-bottom:2px;"><?= e($item['name'] ?? '?') ?></div>
          <div style="font-size:11px;color:#3a3a60;">v<?= e($item['version'] ?? '?') ?> · <?= e(date('d/m/Y', strtotime((string)($item['added_at'] ?? '')))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Whitelist -->
<?php if (!empty($players)): ?>
<div class="panel-card">
  <div class="card-header">
    <div><div class="card-title">👥 Whitelist</div><div class="card-subtitle"><?= count($players) ?> joueur<?= count($players) !== 1 ? 's' : '' ?></div></div>
    <?php if ($serverUuid): ?><a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode($serverUuid) ?>" class="btn btn-ghost btn-sm">Gérer</a><?php endif; ?>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:6px;">
    <?php foreach (array_slice($players, 0, 24) as $p): ?>
      <span class="pill pill-violet" style="font-size:12px;"><?= e($p['username'] ?? '?') ?></span>
    <?php endforeach; ?>
    <?php if (count($players) > 24): ?>
      <span class="pill pill-grey">+<?= count($players) - 24 ?> autres</span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
async function sendAction(action) {
  document.querySelectorAll('#power-btns .pwr').forEach(b => { b.disabled = true; b.style.opacity = '.4'; });
  try {
    const r = await fetch('<?= base_path() ?>/server-cms/api/provision_server.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, server_id: <?= $serverId ?> })
    });
    const d = await r.json();
    if (d.ok) { setTimeout(() => location.reload(), 1200); }
    else { alert('Erreur : ' + (d.error || 'inconnue')); location.reload(); }
  } catch(e) { alert('Erreur réseau'); location.reload(); }
}
</script>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

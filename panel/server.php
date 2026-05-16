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

$serverId = (int)($_GET['id'] ?? 0);
if (!$serverId) { redirect('/panel/servers.php'); }

// Load server
$server = null;
try {
    $s = $pdo->prepare(
        'SELECT s.*, p.name AS plan_name, p.ram_mb, p.max_players
         FROM mc_servers s
         LEFT JOIN mc_server_plans p ON p.slug = s.plan_slug
         WHERE s.id = ? AND s.user_id = ? LIMIT 1'
    );
    $s->execute([$serverId, $user['id']]);
    $server = $s->fetch() ?: null;
} catch (Throwable) {}

if (!$server) { redirect('/panel/servers.php'); }

// Plugins / mods
$plugins = []; $mods = [];
try {
    $s = $pdo->prepare('SELECT name, version, file_name, added_at FROM mc_server_plugins WHERE server_id = ? ORDER BY added_at DESC LIMIT 30');
    $s->execute([$serverId]); $plugins = $s->fetchAll();
    $s = $pdo->prepare('SELECT name, version, file_name, added_at FROM mc_server_mods WHERE server_id = ? ORDER BY added_at DESC LIMIT 30');
    $s->execute([$serverId]); $mods = $s->fetchAll();
} catch (Throwable) {}

// Linked launchers
$links = [];
try {
    $s = $pdo->prepare(
        'SELECT lnk.launcher_uuid, l.name AS launcher_name, l.version, l.loader, lnk.linked_at
         FROM mc_server_launcher_links lnk
         LEFT JOIN launchers l ON l.uuid = lnk.launcher_uuid
         WHERE lnk.server_id = ? ORDER BY lnk.linked_at DESC'
    );
    $s->execute([$serverId]); $links = $s->fetchAll();
} catch (Throwable) {}

// Players whitelist
$players = [];
try {
    $s = $pdo->prepare('SELECT username, uuid, added_at FROM mc_server_players WHERE server_id = ? ORDER BY added_at DESC LIMIT 30');
    $s->execute([$serverId]); $players = $s->fetchAll();
} catch (Throwable) {}

$st = strtolower((string)($server['status'] ?? 'stopped'));
$pillClass = match($st) { 'running' => 'pill-green', 'starting' => 'pill-amber', 'stopping' => 'pill-amber', default => 'pill-grey' };
$pillLabel = match($st) { 'running' => '● En ligne', 'starting' => '◌ Démarrage', 'stopping' => '◌ Arrêt', default => '○ Arrêté' };

$type  = strtolower((string)($server['server_type'] ?? 'vanilla'));
$typeColors = ['vanilla' => '#00d68f', 'paper' => '#ff6b6b', 'spigot' => '#ffbe00', 'forge' => '#ff8c42', 'fabric' => '#7c5cff'];
$typeIcons  = ['vanilla' => '🟢', 'paper' => '📄', 'spigot' => '🔌', 'forge' => '⚙️', 'fabric' => '🧵'];
$color = $typeColors[$type] ?? '#888';
$icon  = $typeIcons[$type]  ?? '🖥️';

$isPlugin = in_array($type, ['paper', 'spigot'], true);

$sidebarCounts = ['servers_online' => ($st === 'running' ? 1 : 0)];
$pageTitle     = e($server['server_name'] ?? 'Serveur');
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
  .power-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: var(--radius-sm);
    font-size: 13px; font-weight: 600; border: none;
    cursor: pointer; transition: .15s; text-decoration: none;
  }
  .power-btn-start  { background: rgba(0,214,143,.15); color: #00d68f; border: 1px solid rgba(0,214,143,.25); }
  .power-btn-start:hover  { background: rgba(0,214,143,.25); }
  .power-btn-stop   { background: rgba(255,77,106,.15);  color: #ff4d6a; border: 1px solid rgba(255,77,106,.25); }
  .power-btn-stop:hover   { background: rgba(255,77,106,.25); }
  .power-btn-restart{ background: rgba(255,190,0,.12);   color: #ffbe00; border: 1px solid rgba(255,190,0,.2); }
  .power-btn-restart:hover{ background: rgba(255,190,0,.22); }
</style>

<!-- Header -->
<div class="panel-page-header">
  <div style="display:flex;align-items:center;gap:16px;">
    <div style="width:52px;height:52px;border-radius:14px;background:<?= $color ?>18;border:2px solid <?= $color ?>33;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;"><?= $icon ?></div>
    <div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h1 class="panel-page-title" style="margin:0;"><?= e($server['server_name'] ?? 'Sans nom') ?></h1>
        <span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:5px;display:flex;gap:12px;flex-wrap:wrap;">
        <span style="color:<?= $color ?>;font-weight:600;"><?= e(ucfirst($type)) ?></span>
        <span><?= e($server['mc_version'] ?? '?') ?></span>
        <?php if (!empty($server['plan_name'])): ?><span>Plan <?= e($server['plan_name']) ?></span><?php endif; ?>
        <?php if (!empty($server['server_ip'])): ?>
          <code style="font-size:11px;color:var(--accent-light);"><?= e($server['server_ip']) ?>:<?= (int)($server['server_port'] ?? 25565) ?></code>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Power buttons -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;" id="power-buttons">
    <?php if ($st !== 'running' && $st !== 'starting'): ?>
      <button class="power-btn power-btn-start" onclick="sendAction('start')">▶ Démarrer</button>
    <?php endif; ?>
    <?php if ($st === 'running' || $st === 'starting'): ?>
      <button class="power-btn power-btn-restart" onclick="sendAction('restart')">↺ Redémarrer</button>
      <button class="power-btn power-btn-stop" onclick="sendAction('stop')">■ Arrêter</button>
    <?php endif; ?>
    <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode((string)($server['uuid'] ?? $serverId)) ?>" class="power-btn" style="background:var(--surface);color:var(--text);border:1px solid var(--border-2);">⚙ Config complète</a>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(5,1fr);">
  <div class="stat-card">
    <div class="stat-card-label">RAM allouée</div>
    <div class="stat-card-value"><?= (int)($server['ram_mb'] ?? 2048) ?></div>
    <div class="stat-card-sub">Mo</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Joueurs max</div>
    <div class="stat-card-value"><?= (int)($server['max_players'] ?? 10) ?></div>
    <div class="stat-card-sub">slots</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label"><?= $isPlugin ? 'Plugins' : 'Mods' ?></div>
    <div class="stat-card-value"><?= $isPlugin ? count($plugins) : count($mods) ?></div>
    <div class="stat-card-sub">installés</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Launchers</div>
    <div class="stat-card-value"><?= count($links) ?></div>
    <div class="stat-card-sub">liés</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Whitelist</div>
    <div class="stat-card-value"><?= count($players) ?></div>
    <div class="stat-card-sub">joueurs</div>
  </div>
</div>

<!-- Main grid -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Quick links -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div class="panel-card-title">🔗 Accès rapide</div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="<?= base_path() ?>/server-cms/dashboard/console.php?id=<?= $serverId ?>" class="item-row" style="padding:12px 14px;">
        <div class="item-icon" style="font-size:18px;">🖥️</div>
        <div class="item-info">
          <div class="item-name" style="font-size:13px;">Console live</div>
          <div class="item-meta">Commandes RCON, logs temps réel</div>
        </div>
        <span>›</span>
      </a>
      <a href="<?= base_path() ?>/server-cms/dashboard/monitoring.php?id=<?= $serverId ?>" class="item-row" style="padding:12px 14px;">
        <div class="item-icon" style="font-size:18px;">📊</div>
        <div class="item-info">
          <div class="item-name" style="font-size:13px;">Monitoring</div>
          <div class="item-meta">RAM, CPU, TPS, joueurs connectés</div>
        </div>
        <span>›</span>
      </a>
      <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode((string)($server['uuid'] ?? $serverId)) ?>" class="item-row" style="padding:12px 14px;">
        <div class="item-icon" style="font-size:18px;">⚙️</div>
        <div class="item-info">
          <div class="item-name" style="font-size:13px;">Configuration complète</div>
          <div class="item-meta">Plugins, mods, whitelist, options</div>
        </div>
        <span>›</span>
      </a>
    </div>
  </div>

  <!-- Linked launchers -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">🚀 Launchers liés</div>
        <div class="panel-card-subtitle">XynoLauncher qui pointent vers ce serveur</div>
      </div>
    </div>
    <?php if (empty($links)): ?>
      <div class="empty-state" style="padding:20px;">
        <div class="empty-state-text">Aucun launcher connecté. Liez-en un depuis la config complète.</div>
        <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode((string)($server['uuid'] ?? $serverId)) ?>" class="btn btn-ghost btn-sm" style="margin-top:8px;">Config complète</a>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($links as $lnk): ?>
          <a href="<?= base_path() ?>/panel/launcher.php?uuid=<?= urlencode((string)$lnk['launcher_uuid']) ?>" class="item-row" style="padding:10px 14px;">
            <div class="item-icon" style="font-size:16px;">🎮</div>
            <div class="item-info">
              <div class="item-name" style="font-size:13px;"><?= e($lnk['launcher_name'] ?? 'Launcher') ?></div>
              <div class="item-meta"><?= e($lnk['version'] ?? '') ?> — <?= e(ucfirst($lnk['loader'] ?? '')) ?></div>
            </div>
            <span>›</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Plugins / Mods list -->
<div class="panel-card" style="margin-bottom:20px;">
  <div class="panel-card-header">
    <div>
      <div class="panel-card-title"><?= $isPlugin ? '🔌 Plugins installés' : '⚙️ Mods installés' ?></div>
      <div class="panel-card-subtitle"><?= $isPlugin ? count($plugins) : count($mods) ?> au total</div>
    </div>
    <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode((string)($server['uuid'] ?? $serverId)) ?>" class="btn btn-ghost btn-sm">Gérer</a>
  </div>
  <?php $items = $isPlugin ? $plugins : $mods; ?>
  <?php if (empty($items)): ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;">
      Aucun <?= $isPlugin ? 'plugin' : 'mod' ?> installé. Ajoutez-en depuis la config complète.
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">
      <?php foreach ($items as $item): ?>
        <div style="background:var(--surface-2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:10px 12px;">
          <div style="font-size:13px;font-weight:600;margin-bottom:2px;"><?= e($item['name'] ?? 'Inconnu') ?></div>
          <div style="font-size:11px;color:var(--muted);">v<?= e($item['version'] ?? '?') ?> · <?= e(date('d/m/Y', strtotime((string)($item['added_at'] ?? '')))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Whitelist -->
<?php if (!empty($players)): ?>
<div class="panel-card">
  <div class="panel-card-header">
    <div>
      <div class="panel-card-title">👥 Whitelist</div>
      <div class="panel-card-subtitle"><?= count($players) ?> joueur<?= count($players) !== 1 ? 's' : '' ?> autorisé<?= count($players) !== 1 ? 's' : '' ?></div>
    </div>
    <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode((string)($server['uuid'] ?? $serverId)) ?>" class="btn btn-ghost btn-sm">Gérer</a>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;">
    <?php foreach (array_slice($players, 0, 20) as $p): ?>
      <div class="pill pill-violet" style="font-size:12px;padding:5px 12px;">
        <?= e($p['username'] ?? 'Inconnu') ?>
      </div>
    <?php endforeach; ?>
    <?php if (count($players) > 20): ?>
      <div class="pill pill-grey" style="font-size:12px;">+<?= count($players) - 20 ?> autres</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
async function sendAction(action) {
  const btns = document.querySelectorAll('.power-btn');
  btns.forEach(b => { b.disabled = true; b.style.opacity = '.5'; });

  try {
    const r = await fetch('<?= base_path() ?>/server-cms/api/provision_server.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, server_id: <?= $serverId ?> })
    });
    const d = await r.json();
    if (d.ok) {
      setTimeout(() => location.reload(), 1500);
    } else {
      alert('Erreur : ' + (d.error || 'inconnue'));
      btns.forEach(b => { b.disabled = false; b.style.opacity = '1'; });
    }
  } catch(e) {
    alert('Erreur réseau');
    btns.forEach(b => { b.disabled = false; b.style.opacity = '1'; });
  }
}
</script>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

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

$uuid = trim((string)($_GET['uuid'] ?? ''));
if ($uuid === '') { redirect('/panel/launchers.php'); }

$launcher = null;
try {
    $s = $pdo->prepare('SELECT * FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $s->execute([$uuid, $user['id']]);
    $launcher = $s->fetch() ?: null;
} catch (Throwable) {}
if (!$launcher) { redirect('/panel/launchers.php'); }

$launcherId  = (int)$launcher['id'];
$apiKey      = (string)($launcher['api_key'] ?? '');
$apiEndpoint = (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : '') . base_path() . '/api/v1/launcher.php';

$versions = [];
try {
    $s = $pdo->prepare('SELECT id, version_name, is_active, created_at FROM launcher_versions WHERE launcher_id = ? ORDER BY created_at DESC, id DESC LIMIT 30');
    $s->execute([$launcherId]); $versions = $s->fetchAll();
} catch (Throwable) {}

$installers = ['win' => null, 'mac' => null, 'linux' => null];
try {
    $s = $pdo->prepare('SELECT platform, version_name, is_active, created_at FROM launcher_downloads WHERE launcher_id = ? ORDER BY is_active DESC, created_at DESC');
    $s->execute([$launcherId]);
    foreach ($s->fetchAll() as $row) {
        $p = (string)($row['platform'] ?? '');
        if (isset($installers[$p]) && $installers[$p] === null) $installers[$p] = $row;
    }
} catch (Throwable) {}

$logs = [];
try {
    $s = $pdo->prepare('SELECT created_at, level, source, message FROM launcher_logs WHERE launcher_id = ? ORDER BY created_at DESC, id DESC LIMIT 25');
    $s->execute([$launcherId]); $logs = $s->fetchAll();
} catch (Throwable) {}

$dlHour = 0; $dlDay = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM launcher_downloads_log WHERE launcher_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    $s->execute([$launcherId]); $dlHour = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM launcher_downloads_log WHERE launcher_id = ? AND created_at >= NOW() - INTERVAL 1 DAY");
    $s->execute([$launcherId]); $dlDay = (int)$s->fetchColumn();
} catch (Throwable) {}

$linkedServers = [];
try {
    $s = $pdo->prepare(
        'SELECT s.id, s.server_name, s.server_type, s.mc_version, s.status
         FROM mc_servers s
         INNER JOIN mc_server_launcher_links lnk ON lnk.server_id = s.id
         INNER JOIN launchers l ON l.id = lnk.launcher_id
         WHERE l.uuid = ? AND s.user_id = ?'
    );
    $s->execute([$uuid, $user['id']]); $linkedServers = $s->fetchAll();
} catch (Throwable) {}

$loaderColors = ['forge' => '#ff8c42', 'fabric' => '#7c5cff', 'quilt' => '#9b59b6', 'neoforge' => '#e67e22'];
$lc = $loaderColors[strtolower($launcher['loader'] ?? '')] ?? '#00d68f';

$sidebarCounts = ['launchers' => 1];
$pageTitle     = $launcher['name'] ?? 'Launcher';
$activeSection = 'launchers';
$breadcrumbs   = [
    ['label' => 'XynoLauncher', 'url' => base_path() . '/panel/launchers.php'],
    ['label' => $launcher['name'] ?? 'Launcher'],
];
$topbarActions = [
    ['label' => '⚙ Dashboard complet', 'url' => base_path() . '/dashboard/dashboard.php?launcher=' . urlencode($uuid)],
];

require_once __DIR__ . '/_layout.php';
?>

<!-- Header -->
<div class="panel-page-header">
  <div style="display:flex;align-items:center;gap:14px;">
    <div style="width:48px;height:48px;border-radius:12px;background:<?= $lc ?>18;border:1px solid <?= $lc ?>33;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">🎮</div>
    <div>
      <div class="panel-page-title"><?= e($launcher['name'] ?? 'Sans nom') ?></div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:600;color:<?= $lc ?>;background:<?= $lc ?>18;padding:2px 8px;border-radius:999px;border:1px solid <?= $lc ?>33;"><?= e(ucfirst($launcher['loader'] ?? 'vanilla')) ?></span>
        <span style="font-size:11px;color:#4848a0;"><?= e($launcher['version'] ?? '?') ?></span>
        <span style="font-size:11px;color:#3a3a60;font-family:monospace;">uuid: <?= e(substr($uuid, 0, 8)) ?>…</span>
      </div>
    </div>
  </div>
  <div class="panel-header-actions">
    <a href="<?= base_path() ?>/dashboard/files.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost btn-sm">📁 Fichiers</a>
    <a href="<?= base_path() ?>/dashboard/upload.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost btn-sm">⬆ Upload</a>
    <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-primary btn-sm">⚙ Gérer</a>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card"><div class="stat-label">Versions</div><div class="stat-value"><?= count($versions) ?></div><div class="stat-sub">modpacks publiés</div></div>
  <div class="stat-card"><div class="stat-label">DL / heure</div><div class="stat-value"><?= $dlHour ?></div><div class="stat-sub">limite 120/h</div></div>
  <div class="stat-card"><div class="stat-label">DL / jour</div><div class="stat-value"><?= $dlDay ?></div><div class="stat-sub">limite 1 500/j</div></div>
  <div class="stat-card"><div class="stat-label">Serveurs liés</div><div class="stat-value"><?= count($linkedServers) ?></div><div class="stat-sub">XynoServer</div></div>
</div>

<div class="grid-2" style="margin-bottom:16px;">

  <!-- Versions -->
  <div class="panel-card">
    <div class="card-header">
      <div><div class="card-title">📦 Versions du modpack</div><div class="card-subtitle">Historique des releases</div></div>
      <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost btn-sm">Gérer</a>
    </div>
    <?php if (empty($versions)): ?>
      <div class="empty-state" style="padding:24px;"><div class="empty-title">Aucune version</div><div class="empty-text">Publiez une version depuis le dashboard.</div></div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach (array_slice($versions, 0, 6) as $v): ?>
          <div class="item-row" style="padding:10px 12px;gap:10px;">
            <div class="item-info">
              <div class="item-name" style="font-size:13px;"><?= e($v['version_name'] ?? 'v?') ?></div>
              <div class="item-meta"><?= e(date('d/m/Y H:i', strtotime((string)($v['created_at'] ?? '')))) ?></div>
            </div>
            <?php if ((int)($v['is_active'] ?? 0)): ?>
              <span class="pill pill-green">Actif</span>
            <?php else: ?>
              <span class="pill pill-grey">Inactif</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Installers -->
  <div class="panel-card">
    <div class="card-header">
      <div><div class="card-title">⬇ Installeurs</div><div class="card-subtitle">Packages par système d'exploitation</div></div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php
        $osList = ['win' => ['🪟', 'Windows', '.exe'], 'mac' => ['🍎', 'macOS', '.dmg'], 'linux' => ['🐧', 'Linux', '.AppImage']];
        foreach ($osList as $key => [$ico, $label, $ext]):
          $inst = $installers[$key];
      ?>
        <div class="item-row" style="padding:10px 12px;gap:10px;">
          <div class="item-icon" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.08);font-size:16px;width:34px;height:34px;"><?= $ico ?></div>
          <div class="item-info">
            <div class="item-name" style="font-size:12px;"><?= $label ?> <span style="color:#3a3a60;font-weight:400;"><?= $ext ?></span></div>
            <?php if ($inst): ?>
              <div class="item-meta">v<?= e($inst['version_name'] ?? '?') ?> · <?= e(date('d/m/Y', strtotime((string)($inst['created_at'] ?? '')))) ?></div>
            <?php else: ?>
              <div class="item-meta" style="color:#2a2a50;">Non uploadé</div>
            <?php endif; ?>
          </div>
          <?php if ($inst && !empty($inst['is_active'])): ?><span class="pill pill-green">Actif</span>
          <?php elseif ($inst): ?><span class="pill pill-amber">Inactif</span>
          <?php else: ?><span class="pill pill-grey">—</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:16px;">

  <!-- API Key -->
  <div class="panel-card">
    <div class="card-header">
      <div><div class="card-title">🔑 Clé API</div><div class="card-subtitle">Pour connecter le launcher à l'API</div></div>
    </div>
    <?php if ($apiKey): ?>
      <div style="background:#050510;border:1px solid rgba(124,92,255,.15);border-radius:8px;padding:10px 12px;font-family:'JetBrains Mono',monospace;font-size:11px;color:#b8a4ff;word-break:break-all;margin-bottom:12px;user-select:all;">
        <?= e($apiKey) ?>
      </div>
      <div style="font-size:11px;color:#3a3a60;">Endpoint : <code style="color:#7c5cff;"><?= e($apiEndpoint) ?></code></div>
    <?php else: ?>
      <div class="empty-state" style="padding:20px;">
        <div class="empty-text">Aucune clé API générée.</div>
        <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost btn-sm">Dashboard complet</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Linked servers -->
  <div class="panel-card">
    <div class="card-header">
      <div><div class="card-title">🖥️ Serveurs liés</div><div class="card-subtitle">XynoServer connectés à ce launcher</div></div>
      <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost btn-sm">Voir tout</a>
    </div>
    <?php if (empty($linkedServers)): ?>
      <div class="empty-state" style="padding:20px;"><div class="empty-text">Aucun serveur lié. Connectez-en un depuis la config du serveur.</div></div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($linkedServers as $sv):
          $sst = strtolower((string)($sv['status'] ?? 'stopped'));
          $spc = match($sst) { 'running' => 'pill-green', 'starting' => 'pill-amber', default => 'pill-grey' };
          $spl = match($sst) { 'running' => '● En ligne', 'starting' => '◌ Démarre', default => '○ Arrêté' };
        ?>
          <a href="<?= base_path() ?>/panel/server.php?id=<?= (int)$sv['id'] ?>" class="item-row" style="padding:10px 12px;">
            <div class="item-info">
              <div class="item-name" style="font-size:13px;"><?= e($sv['server_name'] ?? '?') ?></div>
              <div class="item-meta"><?= e(ucfirst($sv['server_type'] ?? '')) ?> <?= e($sv['mc_version'] ?? '') ?></div>
            </div>
            <span class="pill <?= $spc ?>"><?= $spl ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Logs -->
<div class="panel-card">
  <div class="card-header">
    <div><div class="card-title">📋 Logs récents</div><div class="card-subtitle">25 dernières entrées</div></div>
  </div>
  <?php if (empty($logs)): ?>
    <div style="text-align:center;padding:24px;color:#3a3a60;font-size:13px;">Aucun log disponible.</div>
  <?php else: ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:0;">
      <?php foreach ($logs as $log):
        $lvl = strtolower((string)($log['level'] ?? 'info'));
        $lc2 = match($lvl) { 'error' => '#ff4d6a', 'warn' => '#ffbe00', 'info' => '#5b8dff', default => '#3a3a60' };
      ?>
        <div style="display:flex;gap:12px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.03);align-items:baseline;">
          <span style="color:#2a2a50;flex-shrink:0;width:52px;"><?= e(date('H:i:s', strtotime((string)($log['created_at'] ?? '')))) ?></span>
          <span style="color:<?= $lc2 ?>;flex-shrink:0;width:34px;font-weight:600;"><?= e(strtoupper(substr($lvl, 0, 4))) ?></span>
          <span style="color:#3a3a60;flex-shrink:0;width:80px;overflow:hidden;text-overflow:ellipsis;"><?= e(substr((string)($log['source'] ?? ''), 0, 12)) ?></span>
          <span style="color:#8888c0;"><?= e($log['message'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

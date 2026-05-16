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

$uuid = trim((string)($_GET['uuid'] ?? ''));
if ($uuid === '') { redirect('/panel/launchers.php'); }

// Load launcher
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

// Versions
$versions = [];
try {
    $s = $pdo->prepare('SELECT id, version_name, is_active, created_at FROM launcher_versions WHERE launcher_id = ? ORDER BY created_at DESC, id DESC LIMIT 30');
    $s->execute([$launcherId]);
    $versions = $s->fetchAll();
} catch (Throwable) {}

// Installers
$installers = ['win' => null, 'mac' => null, 'linux' => null];
try {
    $s = $pdo->prepare('SELECT platform, version_name, file_url, is_active, created_at FROM launcher_downloads WHERE launcher_id = ? ORDER BY is_active DESC, created_at DESC');
    $s->execute([$launcherId]);
    foreach ($s->fetchAll() as $row) {
        $p = (string)($row['platform'] ?? '');
        if (isset($installers[$p]) && $installers[$p] === null) $installers[$p] = $row;
    }
} catch (Throwable) {}

// Recent logs
$logs = [];
try {
    $s = $pdo->prepare('SELECT created_at, level, source, message FROM launcher_logs WHERE launcher_id = ? ORDER BY created_at DESC, id DESC LIMIT 20');
    $s->execute([$launcherId]);
    $logs = $s->fetchAll();
} catch (Throwable) {}

// Stats
$dlDay = 0; $dlHour = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM launcher_downloads_log WHERE launcher_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    $s->execute([$launcherId]); $dlHour = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM launcher_downloads_log WHERE launcher_id = ? AND created_at >= NOW() - INTERVAL 1 DAY");
    $s->execute([$launcherId]); $dlDay = (int)$s->fetchColumn();
} catch (Throwable) {}

// Linked servers
$linkedServers = [];
try {
    $s = $pdo->prepare(
        'SELECT s.id, s.server_name, s.server_type, s.mc_version, s.status
         FROM mc_servers s
         INNER JOIN mc_server_launcher_links lnk ON lnk.server_id = s.id
         INNER JOIN launchers l ON l.id = lnk.launcher_id
         WHERE l.uuid = ? AND s.user_id = ?'
    );
    $s->execute([$uuid, $user['id']]);
    $linkedServers = $s->fetchAll();
} catch (Throwable) {}

$loaderColors = ['forge' => '#ff8c42', 'fabric' => '#7c5cff', 'quilt' => '#9b59b6', 'neoforge' => '#e67e22', 'vanilla' => '#00d68f'];
$loaderColor  = $loaderColors[strtolower($launcher['loader'] ?? '')] ?? '#888';

$sidebarCounts = ['launchers' => 1];
$pageTitle     = e($launcher['name'] ?? 'Launcher');
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

<div class="panel-page-header">
  <div style="display:flex;align-items:center;gap:16px;">
    <div style="width:52px;height:52px;border-radius:14px;background:<?= $loaderColor ?>22;border:2px solid <?= $loaderColor ?>44;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">🎮</div>
    <div>
      <h1 class="panel-page-title" style="margin:0;"><?= e($launcher['name'] ?? 'Sans nom') ?></h1>
      <div style="display:flex;align-items:center;gap:10px;margin-top:5px;">
        <span style="font-size:12px;color:<?= $loaderColor ?>;font-weight:600;background:<?= $loaderColor ?>18;padding:2px 8px;border-radius:999px;border:1px solid <?= $loaderColor ?>33;"><?= e(ucfirst($launcher['loader'] ?? 'vanilla')) ?></span>
        <span style="font-size:12px;color:var(--muted);"><?= e($launcher['version'] ?? '?') ?></span>
        <span style="font-size:12px;color:var(--muted-2);">UUID: <?= e(substr($uuid, 0, 8)) ?>…</span>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="<?= base_path() ?>/dashboard/files.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost">📁 Fichiers</a>
    <a href="<?= base_path() ?>/dashboard/upload.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost">⬆ Upload</a>
    <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-primary">⚙ Gérer</a>
  </div>
</div>

<!-- Stats row -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card">
    <div class="stat-card-label">Versions</div>
    <div class="stat-card-value"><?= count($versions) ?></div>
    <div class="stat-card-sub">modpacks publiés</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">DL cette heure</div>
    <div class="stat-card-value"><?= $dlHour ?></div>
    <div class="stat-card-sub">limite 120/h</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">DL aujourd'hui</div>
    <div class="stat-card-value"><?= $dlDay ?></div>
    <div class="stat-card-sub">limite 1500/j</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Serveurs liés</div>
    <div class="stat-card-value"><?= count($linkedServers) ?></div>
    <div class="stat-card-sub">XynoServer</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Versions / modpacks -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">📦 Versions du modpack</div>
        <div class="panel-card-subtitle">Historique des releases</div>
      </div>
      <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost btn-sm">Gérer</a>
    </div>
    <?php if (empty($versions)): ?>
      <div class="empty-state" style="padding:24px;">
        <div class="empty-state-title">Aucune version</div>
        <div class="empty-state-text">Publiez une première version depuis le dashboard.</div>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach (array_slice($versions, 0, 5) as $v): ?>
          <div class="item-row" style="padding:10px 14px;">
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
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">⬇ Installeurs</div>
        <div class="panel-card-subtitle">Packages de téléchargement par OS</div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php
        $osList = [
          'win'   => ['🪟', 'Windows', '.exe'],
          'mac'   => ['🍎', 'macOS', '.dmg'],
          'linux' => ['🐧', 'Linux', '.AppImage'],
        ];
        foreach ($osList as $key => [$ico, $label, $ext]):
          $inst = $installers[$key];
      ?>
        <div class="item-row" style="padding:12px 14px;">
          <div class="item-icon" style="background:var(--surface-3);border-color:var(--border-2);font-size:18px;"><?= $ico ?></div>
          <div class="item-info">
            <div class="item-name" style="font-size:13px;"><?= $label ?> <span style="color:var(--muted);font-weight:400;"><?= $ext ?></span></div>
            <?php if ($inst): ?>
              <div class="item-meta">v<?= e($inst['version_name'] ?? '?') ?> — <?= e(date('d/m/Y', strtotime((string)($inst['created_at'] ?? '')))) ?></div>
            <?php else: ?>
              <div class="item-meta" style="color:var(--muted-2);">Non uploadé</div>
            <?php endif; ?>
          </div>
          <?php if ($inst && !empty($inst['is_active'])): ?>
            <span class="pill pill-green">Actif</span>
          <?php elseif ($inst): ?>
            <span class="pill pill-amber">Inactif</span>
          <?php else: ?>
            <span class="pill pill-grey">—</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- API key + linked servers -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <div class="panel-card">
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">🔑 Clé API</div>
        <div class="panel-card-subtitle">Pour connecter le launcher à l'API XynoWeb</div>
      </div>
    </div>
    <?php if ($apiKey): ?>
      <div style="background:var(--bg-0);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:10px 14px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--accent-light);word-break:break-all;margin-bottom:12px;">
        <?= e($apiKey) ?>
      </div>
      <div style="font-size:12px;color:var(--muted);">
        Endpoint : <code style="color:var(--accent-light);"><?= e($apiEndpoint) ?></code>
      </div>
    <?php else: ?>
      <div class="empty-state" style="padding:20px;">
        <div class="empty-state-text">Aucune clé API. Générez-en une depuis le dashboard complet.</div>
        <a href="<?= base_path() ?>/dashboard/dashboard.php?launcher=<?= urlencode($uuid) ?>" class="btn btn-ghost btn-sm" style="margin-top:8px;">Dashboard complet</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel-card">
    <div class="panel-card-header">
      <div>
        <div class="panel-card-title">🖥️ Serveurs liés</div>
        <div class="panel-card-subtitle">XynoServer connectés à ce launcher</div>
      </div>
      <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost btn-sm">Voir tout</a>
    </div>
    <?php if (empty($linkedServers)): ?>
      <div class="empty-state" style="padding:20px;">
        <div class="empty-state-text">Aucun serveur lié. Connectez un XynoServer depuis sa page de gestion.</div>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($linkedServers as $sv):
          $st = strtolower((string)($sv['status'] ?? 'stopped'));
          $pc = match($st) { 'running' => 'pill-green', 'starting' => 'pill-amber', default => 'pill-grey' };
          $pl = match($st) { 'running' => 'En ligne', 'starting' => 'Démarrage', default => 'Arrêté' };
        ?>
          <a href="<?= base_path() ?>/panel/server.php?id=<?= (int)$sv['id'] ?>" class="item-row" style="padding:10px 14px;">
            <div class="item-info">
              <div class="item-name" style="font-size:13px;"><?= e($sv['server_name'] ?? '?') ?></div>
              <div class="item-meta"><?= e(ucfirst($sv['server_type'] ?? '')) ?> <?= e($sv['mc_version'] ?? '') ?></div>
            </div>
            <span class="pill <?= $pc ?>"><?= $pl ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Logs -->
<div class="panel-card">
  <div class="panel-card-header">
    <div>
      <div class="panel-card-title">📋 Logs récents</div>
      <div class="panel-card-subtitle">20 dernières entrées du launcher</div>
    </div>
  </div>
  <?php if (empty($logs)): ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;">Aucun log pour le moment.</div>
  <?php else: ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;max-height:260px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;">
      <?php foreach ($logs as $log):
        $lvl = strtolower((string)($log['level'] ?? 'info'));
        $lvlColor = match($lvl) { 'error' => '#ff4d6a', 'warn' => '#ffbe00', 'info' => '#7c9eff', default => '#888' };
      ?>
        <div style="display:flex;gap:12px;padding:4px 0;border-bottom:1px solid var(--border-0);">
          <span style="color:var(--muted-2);flex-shrink:0;"><?= e(date('H:i:s', strtotime((string)($log['created_at'] ?? '')))) ?></span>
          <span style="color:<?= $lvlColor ?>;flex-shrink:0;width:36px;"><?= e(strtoupper(substr($lvl, 0, 4))) ?></span>
          <span style="color:var(--muted);flex-shrink:0;"><?= e(substr((string)($log['source'] ?? ''), 0, 12)) ?></span>
          <span style="color:var(--text);"><?= e($log['message'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

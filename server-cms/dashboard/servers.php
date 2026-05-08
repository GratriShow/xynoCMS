<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

// Récupère les serveurs de l'utilisateur
$stmt = $pdo->prepare(
    'SELECT s.*, '
    . '(SELECT COUNT(*) FROM mc_server_plugins p WHERE p.server_id = s.id) AS plugin_count, '
    . '(SELECT COUNT(*) FROM mc_server_mods m WHERE m.server_id = s.id) AS mod_count, '
    . '(SELECT COUNT(*) FROM mc_server_launcher_links l WHERE l.server_id = s.id) AS link_count '
    . 'FROM mc_servers s WHERE s.user_id = ? ORDER BY s.created_at DESC'
);
$stmt->execute([$user['id']]);
$servers = $stmt->fetchAll();

$flash = flash_get('success');
$flashErr = flash_get('error');

$typeColors = [
    'vanilla' => '#00d68f',
    'paper'   => '#ff6b6b',
    'spigot'  => '#ffbe00',
    'forge'   => '#ff8c42',
    'fabric'  => '#7c5cff',
];
$typeIcons = [
    'vanilla' => '🟢',
    'paper'   => '📄',
    'spigot'  => '🔌',
    'forge'   => '⚙️',
    'fabric'  => '🧵',
];

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>XynoServer — Mes serveurs</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= e(base_path()) ?>/assets/style.css" />
  <style>
    /* ── XynoServer overrides ── */
    .server-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 16px;
      margin-top: 24px;
    }
    .server-card {
      background: var(--d-surface);
      border: 1px solid var(--d-border);
      border-radius: var(--d-radius-lg);
      padding: 22px;
      transition: border-color .2s, transform .15s;
      position: relative;
      overflow: hidden;
    }
    .server-card:hover {
      border-color: var(--d-border-2);
      transform: translateY(-2px);
    }
    .server-card-accent {
      position: absolute; top: 0; left: 0; right: 0;
      height: 3px;
    }
    .server-card-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 12px; margin-bottom: 14px;
    }
    .server-card-title {
      font-size: 16px; font-weight: 700; color: var(--d-text);
      margin: 0 0 4px; line-height: 1.3;
    }
    .server-card-meta {
      display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 9px; border-radius: 20px;
      font-size: 11px; font-weight: 600; letter-spacing: .02em;
      border: 1px solid transparent;
    }
    .badge-type { background: rgba(124,92,255,.15); color: var(--d-accent-2); border-color: rgba(124,92,255,.25); }
    .badge-status-configuring { background: rgba(255,190,0,.1);  color: #ffbe00; border-color: rgba(255,190,0,.25); }
    .badge-status-ready       { background: rgba(0,214,143,.1);  color: #00d68f; border-color: rgba(0,214,143,.25); }
    .badge-status-running     { background: rgba(0,214,143,.2);  color: #00d68f; border-color: rgba(0,214,143,.4); }
    .badge-status-stopped     { background: rgba(255,77,106,.1); color: #ff4d6a; border-color: rgba(255,77,106,.25); }
    .server-stats {
      display: flex; gap: 16px; margin-top: 12px;
      padding-top: 12px; border-top: 1px solid var(--d-border);
    }
    .server-stat { text-align: center; }
    .server-stat-value { font-size: 18px; font-weight: 700; color: var(--d-text); }
    .server-stat-label { font-size: 11px; color: var(--d-text-3); margin-top: 1px; }
    .server-card-actions {
      display: flex; gap: 8px; margin-top: 16px;
    }
    .btn-sm {
      padding: 7px 14px; font-size: 12px; font-weight: 600;
      border-radius: var(--d-radius-sm); border: 1px solid var(--d-border-2);
      background: var(--d-elevated); color: var(--d-text);
      cursor: pointer; transition: background .15s, border-color .15s;
      text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
    }
    .btn-sm:hover { background: var(--surface-3); border-color: var(--d-border-3); }
    .btn-sm-primary {
      background: var(--d-accent); border-color: var(--d-accent); color: #fff;
    }
    .btn-sm-primary:hover { background: var(--accent-hover); }
    .empty-state {
      text-align: center; padding: 60px 20px;
      background: var(--d-surface); border: 1px dashed var(--d-border-2);
      border-radius: var(--d-radius-lg); margin-top: 24px;
    }
    .empty-state-icon { font-size: 48px; margin-bottom: 16px; }
    .empty-state-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
    .empty-state-desc { color: var(--d-text-2); margin-bottom: 24px; }
    .page-header {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 12px; margin-bottom: 4px;
    }
    .page-title { font-size: 24px; font-weight: 800; margin: 0; }
    .page-sub { color: var(--d-text-2); font-size: 14px; margin-top: 4px; }
    .alert {
      padding: 12px 16px; border-radius: var(--d-radius); margin-bottom: 20px;
      border: 1px solid transparent; font-size: 14px;
    }
    .alert-success { background: rgba(0,214,143,.1); border-color: rgba(0,214,143,.25); color: #00d68f; }
    .alert-error   { background: rgba(255,77,106,.1); border-color: rgba(255,77,106,.25); color: #ff4d6a; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="container nav-inner">
    <a href="<?= e(base_path()) ?>/" class="brand">
      <div class="brand-mark" style="width:32px;height:32px;background:var(--grad-primary);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:14px;">X</div>
      <span>XynoCMS</span>
    </a>
    <div style="display:flex;align-items:center;gap:16px;">
      <a href="<?= e(base_path()) ?>/dashboard.php" class="btn-sm">🚀 Launchers</a>
      <a href="<?= e(server_cms_base()) ?>/dashboard/servers.php" class="btn-sm btn-sm-primary">🖥️ Serveurs</a>
      <a href="<?= e(base_path()) ?>/auth/logout.php" class="btn-sm" style="color:var(--d-text-2);">Déconnexion</a>
    </div>
  </div>
</nav>

<!-- Contenu -->
<div class="container" style="padding: 32px var(--gutter);">

  <?php if ($flash): ?>
    <div class="alert alert-success">✅ <?= e($flash) ?></div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
    <div class="alert alert-error">❌ <?= e($flashErr) ?></div>
  <?php endif; ?>

  <div class="page-header">
    <div>
      <h1 class="page-title">🖥️ Mes serveurs Minecraft</h1>
      <p class="page-sub">Crée et configure tes serveurs Minecraft — connecte-les à tes launchers</p>
    </div>
    <a href="<?= e(server_cms_base()) ?>/dashboard/create.php" class="btn-sm btn-sm-primary" style="padding:10px 20px;font-size:14px;">
      + Nouveau serveur
    </a>
  </div>

  <?php if (empty($servers)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">🖥️</div>
    <div class="empty-state-title">Aucun serveur pour l'instant</div>
    <p class="empty-state-desc">Crée ton premier serveur Minecraft en quelques étapes.<br>Vanilla, Paper, Forge ou Fabric — tout est supporté.</p>
    <a href="<?= e(server_cms_base()) ?>/dashboard/create.php" class="btn-sm btn-sm-primary" style="padding:12px 24px;font-size:14px;">
      🚀 Créer mon premier serveur
    </a>
  </div>

  <?php else: ?>
  <div class="server-grid">
    <?php foreach ($servers as $srv): ?>
    <?php
      $type  = (string)($srv['server_type'] ?? 'paper');
      $color = $typeColors[$type] ?? '#7c5cff';
      $icon  = $typeIcons[$type] ?? '🖥️';
      $status = (string)($srv['status'] ?? 'configuring');
    ?>
    <div class="server-card">
      <div class="server-card-accent" style="background:<?= e($color) ?>;opacity:.7;"></div>

      <div class="server-card-header">
        <div>
          <h3 class="server-card-title"><?= e($srv['name']) ?></h3>
          <div class="server-card-meta">
            <span class="badge badge-type"><?= e($icon . ' ' . strtoupper($type)) ?></span>
            <span class="badge badge-status-<?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
            <span class="badge" style="background:var(--d-surface-2);color:var(--d-text-2);border-color:var(--d-border);">
              MC <?= e((string)$srv['mc_version']) ?>
            </span>
          </div>
        </div>
        <?php if (!empty($srv['server_ip'])): ?>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:11px;color:var(--d-text-3);">Adresse</div>
          <div style="font-size:13px;font-weight:600;color:var(--d-text);font-family:monospace;">
            <?= e((string)$srv['server_ip']) ?>:<?= e((string)$srv['server_port']) ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($srv['description'])): ?>
      <p style="font-size:13px;color:var(--d-text-2);margin:0 0 12px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
        <?= e((string)$srv['description']) ?>
      </p>
      <?php endif; ?>

      <div class="server-stats">
        <div class="server-stat">
          <div class="server-stat-value"><?= (int)$srv['plugin_count'] + (int)$srv['mod_count'] ?></div>
          <div class="server-stat-label">Plugins/Mods</div>
        </div>
        <div class="server-stat">
          <div class="server-stat-value"><?= (int)$srv['link_count'] ?></div>
          <div class="server-stat-label">Launchers liés</div>
        </div>
        <div class="server-stat">
          <div class="server-stat-value"><?= (int)$srv['ram_mb'] ?>Mo</div>
          <div class="server-stat-label">RAM</div>
        </div>
        <div class="server-stat" style="margin-left:auto;">
          <div class="server-stat-value" style="font-size:12px;color:var(--d-text-3);">
            <?= e(date('d/m/Y', strtotime((string)$srv['created_at']))) ?>
          </div>
          <div class="server-stat-label">Créé le</div>
        </div>
      </div>

      <div class="server-card-actions">
        <a href="<?= e(server_cms_base()) ?>/dashboard/manage.php?uuid=<?= e((string)$srv['uuid']) ?>" class="btn-sm btn-sm-primary">
          ⚙️ Gérer
        </a>
        <a href="<?= e(server_cms_base()) ?>/api/generate_config.php?uuid=<?= e((string)$srv['uuid']) ?>&file=server.properties" class="btn-sm">
          ⬇️ server.properties
        </a>
        <a href="<?= e(server_cms_base()) ?>/api/generate_config.php?uuid=<?= e((string)$srv['uuid']) ?>&file=start.sh" class="btn-sm">
          ⬇️ start.sh
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /container -->
</body>
</html>

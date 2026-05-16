<?php
/**
 * Unified Panel — Shared sidebar layout
 * Include at the top of every panel page, AFTER defining $pageTitle and $activeSection.
 *
 * $activeSection: 'overview' | 'launchers' | 'servers' | 'sites' | 'settings' | 'admin'
 * $pageTitle:     string shown in <title>
 * $user:          array from require_login()
 * $isAdmin:       bool
 */

$_panelBase = base_path() ?: '';
$_section   = $activeSection ?? 'overview';

// Breadcrumbs may be set by the parent file
$_crumbs = $breadcrumbs ?? [];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= e($pageTitle ?? 'Panel') ?> — XynoWeb</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= $_panelBase ?>/assets/style.css"/>
  <style>
    /* ── Panel shell ─────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: 'Inter', system-ui, sans-serif;
      background: var(--bg-0);
      color: var(--text);
      min-height: 100vh;
      display: flex;
    }

    /* ── Sidebar ─────────────────────────────────────────────── */
    #panel-sidebar {
      position: fixed;
      top: 0; left: 0; bottom: 0;
      width: 240px;
      background: var(--bg-1);
      border-right: 1px solid var(--border-1);
      display: flex;
      flex-direction: column;
      z-index: 100;
      transition: transform .25s ease;
    }

    .sidebar-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 20px 20px 16px;
      border-bottom: 1px solid var(--border-1);
      text-decoration: none;
    }
    .sidebar-logo-icon {
      width: 34px; height: 34px;
      background: var(--grad-primary);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      font-weight: 800;
      color: #fff;
      flex-shrink: 0;
      box-shadow: 0 0 16px var(--accent-glow);
    }
    .sidebar-logo-name {
      font-size: 16px; font-weight: 700;
      color: var(--text);
      letter-spacing: -.02em;
    }
    .sidebar-logo-name span { color: var(--accent); }

    .sidebar-section-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--muted-2);
      padding: 18px 20px 6px;
    }

    .sidebar-nav { flex: 1; padding: 6px 10px; overflow-y: auto; }

    .sidebar-item {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 9px 12px;
      border-radius: var(--radius-sm);
      color: var(--muted);
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 500;
      transition: background .15s, color .15s;
      margin-bottom: 2px;
      cursor: pointer;
      border: none;
      background: none;
      width: 100%;
      text-align: left;
    }
    .sidebar-item:hover { background: var(--surface); color: var(--text); }
    .sidebar-item.active {
      background: var(--accent-soft);
      color: var(--accent-light);
      border: 1px solid var(--accent-border);
    }
    .sidebar-item.active .sidebar-icon { color: var(--accent); }
    .sidebar-item.disabled {
      opacity: .35;
      cursor: not-allowed;
      pointer-events: none;
    }

    .sidebar-icon { font-size: 16px; width: 20px; flex-shrink: 0; text-align: center; }
    .sidebar-badge {
      margin-left: auto;
      background: var(--accent-soft);
      color: var(--accent-light);
      border: 1px solid var(--accent-border);
      border-radius: 999px;
      padding: 1px 7px;
      font-size: 10px;
      font-weight: 600;
    }
    .sidebar-badge.green {
      background: rgba(0,214,143,.1);
      color: #00d68f;
      border-color: rgba(0,214,143,.2);
    }

    .sidebar-divider { height: 1px; background: var(--border-1); margin: 10px 10px; }

    .sidebar-footer {
      border-top: 1px solid var(--border-1);
      padding: 12px 10px;
    }

    .sidebar-user {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 10px;
      border-radius: var(--radius-sm);
    }
    .sidebar-avatar {
      width: 32px; height: 32px;
      border-radius: 50%;
      background: var(--grad-primary);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px;
      font-weight: 700;
      color: #fff;
      flex-shrink: 0;
    }
    .sidebar-user-info { flex: 1; min-width: 0; }
    .sidebar-user-email {
      font-size: 11px;
      color: var(--muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .sidebar-user-role {
      font-size: 10px;
      color: var(--accent);
      font-weight: 600;
      margin-top: 1px;
    }

    /* ── Main content area ───────────────────────────────────── */
    #panel-main {
      margin-left: 240px;
      min-height: 100vh;
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    /* ── Top bar ─────────────────────────────────────────────── */
    #panel-topbar {
      height: 56px;
      background: var(--bg-1);
      border-bottom: 1px solid var(--border-1);
      display: flex;
      align-items: center;
      padding: 0 28px;
      gap: 12px;
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .topbar-breadcrumbs {
      display: flex; align-items: center; gap: 6px;
      font-size: 13px;
    }
    .topbar-crumb { color: var(--muted); text-decoration: none; }
    .topbar-crumb:hover { color: var(--text); }
    .topbar-crumb-sep { color: var(--muted-2); }
    .topbar-crumb.current { color: var(--text); font-weight: 500; }

    .topbar-spacer { flex: 1; }

    .topbar-btn {
      display: flex; align-items: center; gap: 7px;
      padding: 7px 14px;
      background: var(--surface);
      border: 1px solid var(--border-2);
      border-radius: var(--radius-sm);
      color: var(--text);
      font-size: 13px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      transition: background .15s, border-color .15s;
    }
    .topbar-btn:hover { background: var(--surface-2); border-color: var(--border-3); }
    .topbar-btn.primary {
      background: var(--accent);
      border-color: transparent;
      color: #fff;
    }
    .topbar-btn.primary:hover { background: var(--accent-hover); }

    /* ── Page content ────────────────────────────────────────── */
    #panel-content {
      flex: 1;
      padding: 28px;
    }

    /* ── Mobile hamburger ────────────────────────────────────── */
    #sidebar-toggle {
      display: none;
      background: none;
      border: 1px solid var(--border-2);
      color: var(--text);
      border-radius: var(--radius-sm);
      padding: 6px 10px;
      cursor: pointer;
      font-size: 18px;
    }

    @media (max-width: 900px) {
      #panel-sidebar {
        transform: translateX(-100%);
        transition: transform .25s ease;
      }
      #panel-sidebar.open { transform: translateX(0); }
      #panel-main { margin-left: 0; }
      #sidebar-toggle { display: flex; align-items: center; }
      #panel-content { padding: 16px; }
    }

    /* ── Shared panel utilities ──────────────────────────────── */
    .panel-page-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
    }
    .panel-page-title { font-size: 22px; font-weight: 700; margin: 0; }
    .panel-page-subtitle { font-size: 13px; color: var(--muted); margin: 4px 0 0; }

    .panel-card {
      background: var(--surface);
      border: 1px solid var(--border-1);
      border-radius: var(--radius-lg);
      padding: 20px;
    }
    .panel-card-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 16px;
    }
    .panel-card-title { font-size: 14px; font-weight: 600; color: var(--text); margin: 0; }
    .panel-card-subtitle { font-size: 12px; color: var(--muted); margin: 2px 0 0; }

    .stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .stat-card {
      background: var(--surface);
      border: 1px solid var(--border-1);
      border-radius: var(--radius-md);
      padding: 18px 20px;
    }
    .stat-card-label {
      font-size: 11px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .07em;
      color: var(--muted-2); margin-bottom: 8px;
    }
    .stat-card-value {
      font-size: 28px; font-weight: 800; line-height: 1;
      background: var(--grad-text);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .stat-card-sub { font-size: 12px; color: var(--muted); margin-top: 4px; }

    .item-list { display: flex; flex-direction: column; gap: 10px; }
    .item-row {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 16px;
      background: var(--surface-2);
      border: 1px solid var(--border-1);
      border-radius: var(--radius-md);
      text-decoration: none;
      color: var(--text);
      transition: border-color .15s, background .15s;
    }
    .item-row:hover { border-color: var(--accent-border); background: var(--surface-3); }
    .item-icon {
      width: 40px; height: 40px;
      border-radius: var(--radius-sm);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; flex-shrink: 0;
      background: var(--accent-soft);
      border: 1px solid var(--accent-border);
    }
    .item-info { flex: 1; min-width: 0; }
    .item-name { font-size: 14px; font-weight: 600; }
    .item-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .item-actions { display: flex; gap: 8px; align-items: center; }

    .pill {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px; font-weight: 600;
    }
    .pill-green  { background: rgba(0,214,143,.1); color: #00d68f; border: 1px solid rgba(0,214,143,.2); }
    .pill-violet { background: var(--accent-soft); color: var(--accent-light); border: 1px solid var(--accent-border); }
    .pill-amber  { background: rgba(255,190,0,.1); color: #ffbe00; border: 1px solid rgba(255,190,0,.2); }
    .pill-red    { background: rgba(255,77,106,.1); color: #ff4d6a; border: 1px solid rgba(255,77,106,.2); }
    .pill-grey   { background: var(--surface-2); color: var(--muted); border: 1px solid var(--border-1); }

    .btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 8px 16px;
      border-radius: var(--radius-sm);
      font-size: 13px; font-weight: 600;
      text-decoration: none; cursor: pointer;
      border: none; transition: .15s;
    }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-ghost { background: var(--surface); color: var(--text); border: 1px solid var(--border-2); }
    .btn-ghost:hover { background: var(--surface-2); }
    .btn-danger { background: rgba(255,77,106,.15); color: #ff4d6a; border: 1px solid rgba(255,77,106,.25); }
    .btn-danger:hover { background: rgba(255,77,106,.25); }
    .btn-sm { padding: 5px 11px; font-size: 12px; }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted);
    }
    .empty-state-icon { font-size: 40px; margin-bottom: 12px; }
    .empty-state-title { font-size: 16px; font-weight: 600; color: var(--text); margin: 0 0 6px; }
    .empty-state-text { font-size: 13px; margin: 0 0 20px; }

    .flash-msg {
      padding: 12px 16px;
      border-radius: var(--radius-sm);
      font-size: 13px;
      margin-bottom: 20px;
      display: flex; align-items: center; gap: 10px;
    }
    .flash-success { background: rgba(0,214,143,.1); border: 1px solid rgba(0,214,143,.2); color: #00d68f; }
    .flash-error   { background: rgba(255,77,106,.1); border: 1px solid rgba(255,77,106,.2); color: #ff4d6a; }
  </style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav id="panel-sidebar">
  <a href="<?= $_panelBase ?>/panel/" class="sidebar-logo">
    <div class="sidebar-logo-icon">X</div>
    <span class="sidebar-logo-name">Xyno<span>Web</span></span>
  </a>

  <div class="sidebar-nav">
    <div class="sidebar-section-label">Navigation</div>

    <a href="<?= $_panelBase ?>/panel/" class="sidebar-item <?= $_section === 'overview' ? 'active' : '' ?>">
      <span class="sidebar-icon">⊞</span> Vue d'ensemble
    </a>

    <div class="sidebar-section-label" style="margin-top:8px;">Produits</div>

    <a href="<?= $_panelBase ?>/panel/launchers.php" class="sidebar-item <?= $_section === 'launchers' ? 'active' : '' ?>">
      <span class="sidebar-icon">🚀</span> XynoLauncher
      <?php if (!empty($sidebarCounts['launchers'])): ?>
        <span class="sidebar-badge"><?= (int)$sidebarCounts['launchers'] ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= $_panelBase ?>/panel/servers.php" class="sidebar-item <?= $_section === 'servers' ? 'active' : '' ?>">
      <span class="sidebar-icon">🖥️</span> XynoServer
      <?php if (!empty($sidebarCounts['servers_online'])): ?>
        <span class="sidebar-badge green"><?= (int)$sidebarCounts['servers_online'] ?> en ligne</span>
      <?php endif; ?>
    </a>

    <span class="sidebar-item disabled">
      <span class="sidebar-icon">🌐</span> XynoSite
      <span class="sidebar-badge" style="margin-left:auto;">Bientôt</span>
    </span>

    <div class="sidebar-divider"></div>

    <a href="<?= $_panelBase ?>/panel/settings.php" class="sidebar-item <?= $_section === 'settings' ? 'active' : '' ?>">
      <span class="sidebar-icon">⚙</span> Paramètres
    </a>

    <?php if (!empty($isAdmin)): ?>
    <a href="<?= $_panelBase ?>/server-cms/dashboard/admin/" class="sidebar-item">
      <span class="sidebar-icon">🛡</span> Administration
    </a>
    <?php endif; ?>

    <a href="<?= $_panelBase ?>/auth/logout.php" class="sidebar-item" style="color:var(--danger);">
      <span class="sidebar-icon">↩</span> Déconnexion
    </a>
  </div>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-avatar"><?= strtoupper(substr((string)($user['email'] ?? 'U'), 0, 1)) ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-email"><?= e($user['email'] ?? '') ?></div>
        <div class="sidebar-user-role"><?= !empty($isAdmin) ? 'Administrateur' : 'Utilisateur' ?></div>
      </div>
    </div>
  </div>
</nav>

<!-- ── Main ── -->
<div id="panel-main">
  <header id="panel-topbar">
    <button id="sidebar-toggle" onclick="document.getElementById('panel-sidebar').classList.toggle('open')">☰</button>

    <nav class="topbar-breadcrumbs">
      <a href="<?= $_panelBase ?>/panel/" class="topbar-crumb">Panel</a>
      <?php foreach ($_crumbs as $crumb): ?>
        <span class="topbar-crumb-sep">/</span>
        <?php if (!empty($crumb['url'])): ?>
          <a href="<?= e($crumb['url']) ?>" class="topbar-crumb"><?= e($crumb['label']) ?></a>
        <?php else: ?>
          <span class="topbar-crumb current"><?= e($crumb['label']) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="topbar-spacer"></div>

    <?php if (!empty($topbarActions)): ?>
      <?php foreach ($topbarActions as $act): ?>
        <a href="<?= e($act['url']) ?>" class="topbar-btn <?= !empty($act['primary']) ? 'primary' : '' ?>">
          <?= !empty($act['icon']) ? $act['icon'] . ' ' : '' ?><?= e($act['label']) ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </header>

  <main id="panel-content">

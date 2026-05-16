<?php
/**
 * Unified Panel — Shared sidebar layout
 * Include at the top of every panel page, AFTER defining $pageTitle and $activeSection.
 *
 * $activeSection: 'overview' | 'launchers' | 'servers' | 'settings'
 * $pageTitle:     string shown in <title>
 * $user:          array from require_login()
 * $isAdmin:       bool
 * $sidebarCounts: ['launchers' => int, 'servers_online' => int]
 * $breadcrumbs:   [['label' => '', 'url' => ''], ...]  (last item has no url)
 * $topbarActions: [['label' => '', 'url' => '', 'primary' => bool], ...]
 */

$_panelBase = base_path() ?: '';
$_section   = $activeSection ?? 'overview';
$_crumbs    = $breadcrumbs ?? [];
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
    /* ── Reset & base ──────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: var(--bg-0);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      font-size: 14px;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }

    a { color: inherit; }

    /* ═══════════════════════════════════════════
       SIDEBAR
    ═══════════════════════════════════════════ */
    #panel-sidebar {
      position: fixed;
      top: 0; left: 0; bottom: 0;
      width: 220px;
      background: #08081a;
      border-right: 1px solid rgba(255,255,255,.06);
      display: flex;
      flex-direction: column;
      z-index: 200;
      overflow: hidden;
    }

    /* Logo */
    .sb-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 18px 16px 16px;
      border-bottom: 1px solid rgba(255,255,255,.06);
      text-decoration: none;
      flex-shrink: 0;
    }
    .sb-logo-icon {
      width: 32px; height: 32px;
      background: linear-gradient(135deg, #7c5cff, #5b8dff);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; font-weight: 800; color: #fff;
      flex-shrink: 0;
      box-shadow: 0 0 14px rgba(124,92,255,.4);
      letter-spacing: -.02em;
    }
    .sb-logo-text {
      font-size: 15px; font-weight: 700; color: #f0f0fc;
      letter-spacing: -.02em; line-height: 1;
    }
    .sb-logo-text em { color: #7c5cff; font-style: normal; }

    /* Nav scroll area */
    .sb-nav {
      flex: 1;
      overflow-y: auto;
      padding: 8px 8px;
      scrollbar-width: none;
    }
    .sb-nav::-webkit-scrollbar { display: none; }

    /* Section label */
    .sb-section {
      font-size: 10px; font-weight: 600;
      letter-spacing: .1em; text-transform: uppercase;
      color: #3a3a60;
      padding: 14px 8px 4px;
    }

    /* Nav item */
    .sb-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 10px;
      border-radius: 8px;
      color: #6868a0;
      text-decoration: none;
      font-size: 13px;
      font-weight: 500;
      transition: background .12s, color .12s;
      margin-bottom: 1px;
      cursor: pointer;
      border: none;
      background: none;
      width: 100%;
      text-align: left;
      line-height: 1.4;
      white-space: nowrap;
      overflow: hidden;
    }
    .sb-item:hover { background: rgba(255,255,255,.04); color: #c0c0e0; }
    .sb-item.active {
      background: rgba(124,92,255,.12);
      color: #b8a4ff;
      border: 1px solid rgba(124,92,255,.2);
    }
    .sb-item.disabled {
      opacity: .3;
      cursor: not-allowed;
      pointer-events: none;
    }
    .sb-item.danger { color: #ff4d6a; }
    .sb-item.danger:hover { background: rgba(255,77,106,.08); }

    .sb-icon { width: 18px; flex-shrink: 0; text-align: center; font-size: 14px; }
    .sb-label { flex: 1; overflow: hidden; text-overflow: ellipsis; }
    .sb-badge {
      flex-shrink: 0;
      background: rgba(124,92,255,.15);
      color: #b8a4ff;
      border-radius: 999px;
      padding: 1px 6px;
      font-size: 10px;
      font-weight: 600;
      min-width: 20px;
      text-align: center;
    }
    .sb-badge.green { background: rgba(0,214,143,.12); color: #00d68f; }

    .sb-divider {
      height: 1px;
      background: rgba(255,255,255,.05);
      margin: 8px 0;
    }

    /* User footer */
    .sb-footer {
      border-top: 1px solid rgba(255,255,255,.06);
      padding: 10px 8px;
      flex-shrink: 0;
    }
    .sb-user {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 10px;
      border-radius: 8px;
    }
    .sb-avatar {
      width: 30px; height: 30px;
      border-radius: 50%;
      background: linear-gradient(135deg, #7c5cff, #5b8dff);
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; color: #fff;
      flex-shrink: 0;
    }
    .sb-user-info { flex: 1; min-width: 0; }
    .sb-user-email {
      font-size: 11px; color: #6868a0;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .sb-user-role { font-size: 10px; color: #7c5cff; font-weight: 600; margin-top: 1px; }

    /* ═══════════════════════════════════════════
       MAIN CONTENT
    ═══════════════════════════════════════════ */
    #panel-main {
      margin-left: 220px;
      min-height: 100vh;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    /* Top bar */
    #panel-topbar {
      height: 52px;
      background: #08081a;
      border-bottom: 1px solid rgba(255,255,255,.06);
      display: flex;
      align-items: center;
      padding: 0 24px;
      gap: 10px;
      position: sticky;
      top: 0;
      z-index: 100;
      flex-shrink: 0;
    }
    .tb-hamburger {
      display: none;
      background: none;
      border: 1px solid rgba(255,255,255,.08);
      color: #8888c0;
      border-radius: 6px;
      padding: 5px 8px;
      cursor: pointer;
      font-size: 15px;
      line-height: 1;
    }
    .tb-breadcrumb {
      display: flex; align-items: center; gap: 5px;
      font-size: 12px;
      overflow: hidden;
      flex: 1;
    }
    .tb-crumb { color: #4848a0; text-decoration: none; white-space: nowrap; }
    .tb-crumb:hover { color: #8888c0; }
    .tb-crumb.current { color: #c0c0e0; font-weight: 500; overflow: hidden; text-overflow: ellipsis; }
    .tb-sep { color: #2a2a50; }
    .tb-spacer { flex: 1; }
    .tb-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .tb-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 7px;
      color: #c0c0e0;
      font-size: 12px; font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      white-space: nowrap;
      transition: background .12s, border-color .12s;
      font-family: inherit;
    }
    .tb-btn:hover { background: rgba(255,255,255,.07); border-color: rgba(255,255,255,.12); }
    .tb-btn.primary { background: #7c5cff; border-color: transparent; color: #fff; }
    .tb-btn.primary:hover { background: #9274ff; }

    /* Page content */
    #panel-content {
      flex: 1;
      padding: 24px 28px 40px;
      min-width: 0;
    }

    /* Mobile */
    @media (max-width: 860px) {
      #panel-sidebar { transform: translateX(-100%); transition: transform .22s; }
      #panel-sidebar.open { transform: translateX(0); box-shadow: 4px 0 40px rgba(0,0,0,.6); }
      #panel-main { margin-left: 0; }
      .tb-hamburger { display: flex; align-items: center; }
      #panel-content { padding: 16px; }
    }

    /* ═══════════════════════════════════════════
       SHARED PANEL COMPONENTS
    ═══════════════════════════════════════════ */

    /* Page header */
    .panel-page-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
    }
    .panel-page-title {
      font-size: 20px; font-weight: 700;
      color: #f0f0fc; letter-spacing: -.02em;
    }
    .panel-page-subtitle { font-size: 13px; color: #6868a0; margin-top: 3px; }
    .panel-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

    /* Stats grid */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 12px;
      margin-bottom: 20px;
    }
    .stat-card {
      background: #0e0e22;
      border: 1px solid rgba(255,255,255,.07);
      border-radius: 12px;
      padding: 16px 18px;
    }
    .stat-label {
      font-size: 10px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .08em;
      color: #3a3a60; margin-bottom: 8px;
    }
    .stat-value {
      font-size: 26px; font-weight: 800; line-height: 1;
      color: #c4b5fd;
    }
    .stat-value.green { color: #00d68f; }
    .stat-sub { font-size: 11px; color: #4848a0; margin-top: 5px; }

    /* Cards */
    .panel-card {
      background: #0e0e22;
      border: 1px solid rgba(255,255,255,.07);
      border-radius: 14px;
      padding: 20px;
    }
    .panel-card + .panel-card { margin-top: 0; }

    .card-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      margin-bottom: 16px; gap: 10px;
    }
    .card-title { font-size: 13px; font-weight: 600; color: #e0e0f0; }
    .card-subtitle { font-size: 11px; color: #4848a0; margin-top: 2px; }

    /* Item list */
    .item-list { display: flex; flex-direction: column; gap: 8px; }

    .item-row {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 14px;
      background: #131330;
      border: 1px solid rgba(255,255,255,.06);
      border-radius: 10px;
      text-decoration: none;
      color: #e0e0f0;
      transition: border-color .12s, background .12s;
    }
    .item-row:hover { border-color: rgba(124,92,255,.3); background: #191940; }

    .item-icon {
      width: 38px; height: 38px;
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; flex-shrink: 0;
      background: rgba(124,92,255,.1);
      border: 1px solid rgba(124,92,255,.2);
    }
    .item-info { flex: 1; min-width: 0; }
    .item-name { font-size: 13px; font-weight: 600; color: #e0e0f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-meta { font-size: 11px; color: #5858a0; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }

    /* Pills / badges */
    .pill {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 11px; font-weight: 600;
      white-space: nowrap;
    }
    .pill-green  { background: rgba(0,214,143,.1);  color: #00d68f; border: 1px solid rgba(0,214,143,.2); }
    .pill-violet { background: rgba(124,92,255,.12); color: #b8a4ff; border: 1px solid rgba(124,92,255,.2); }
    .pill-amber  { background: rgba(255,190,0,.1);   color: #ffbe00; border: 1px solid rgba(255,190,0,.2); }
    .pill-red    { background: rgba(255,77,106,.1);  color: #ff4d6a; border: 1px solid rgba(255,77,106,.2); }
    .pill-grey   { background: rgba(255,255,255,.05); color: #5858a0; border: 1px solid rgba(255,255,255,.08); }

    /* Buttons */
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px;
      border-radius: 7px;
      font-size: 13px; font-weight: 600;
      text-decoration: none; cursor: pointer;
      border: none; transition: .12s;
      font-family: inherit; line-height: 1;
      white-space: nowrap;
    }
    .btn-primary { background: #7c5cff; color: #fff; }
    .btn-primary:hover { background: #9274ff; }
    .btn-ghost { background: rgba(255,255,255,.04); color: #c0c0e0; border: 1px solid rgba(255,255,255,.08); }
    .btn-ghost:hover { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.14); }
    .btn-danger { background: rgba(255,77,106,.1); color: #ff4d6a; border: 1px solid rgba(255,77,106,.2); }
    .btn-danger:hover { background: rgba(255,77,106,.18); }
    .btn-sm { padding: 5px 10px; font-size: 12px; }

    /* Flash messages */
    .flash {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 14px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 18px;
    }
    .flash-ok  { background: rgba(0,214,143,.08);  border: 1px solid rgba(0,214,143,.18);  color: #00d68f; }
    .flash-err { background: rgba(255,77,106,.08); border: 1px solid rgba(255,77,106,.18); color: #ff4d6a; }

    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 48px 20px;
      color: #4848a0;
    }
    .empty-icon  { font-size: 36px; margin-bottom: 12px; }
    .empty-title { font-size: 15px; font-weight: 600; color: #c0c0e0; margin-bottom: 6px; }
    .empty-text  { font-size: 13px; margin-bottom: 18px; }

    /* Two-col grid */
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .grid-3 {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }
    @media (max-width: 700px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
    @media (max-width: 1000px) { .grid-3 { grid-template-columns: 1fr 1fr; } }
  </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════ -->
<nav id="panel-sidebar">
  <a href="<?= $_panelBase ?>/panel/" class="sb-logo">
    <div class="sb-logo-icon">X</div>
    <span class="sb-logo-text">Xyno<em>Web</em></span>
  </a>

  <div class="sb-nav">
    <div class="sb-section">Navigation</div>

    <a href="<?= $_panelBase ?>/panel/" class="sb-item <?= $_section === 'overview' ? 'active' : '' ?>">
      <span class="sb-icon">▦</span>
      <span class="sb-label">Vue d'ensemble</span>
    </a>

    <div class="sb-section" style="margin-top:4px;">Produits</div>

    <a href="<?= $_panelBase ?>/panel/launchers.php" class="sb-item <?= $_section === 'launchers' ? 'active' : '' ?>">
      <span class="sb-icon">🚀</span>
      <span class="sb-label">XynoLauncher</span>
      <?php if (!empty($sidebarCounts['launchers'])): ?>
        <span class="sb-badge"><?= (int)$sidebarCounts['launchers'] ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= $_panelBase ?>/panel/servers.php" class="sb-item <?= $_section === 'servers' ? 'active' : '' ?>">
      <span class="sb-icon">🖥️</span>
      <span class="sb-label">XynoServer</span>
      <?php if (!empty($sidebarCounts['servers_online'])): ?>
        <span class="sb-badge green"><?= (int)$sidebarCounts['servers_online'] ?></span>
      <?php endif; ?>
    </a>

    <span class="sb-item disabled">
      <span class="sb-icon">🌐</span>
      <span class="sb-label">XynoSite</span>
      <span class="sb-badge" style="background:rgba(255,255,255,.06);color:#3a3a60;">Bientôt</span>
    </span>

    <div class="sb-divider"></div>

    <a href="<?= $_panelBase ?>/panel/settings.php" class="sb-item <?= $_section === 'settings' ? 'active' : '' ?>">
      <span class="sb-icon">⚙</span>
      <span class="sb-label">Paramètres</span>
    </a>

    <?php if (!empty($isAdmin)): ?>
    <a href="<?= $_panelBase ?>/server-cms/dashboard/admin/" class="sb-item">
      <span class="sb-icon">🛡</span>
      <span class="sb-label">Administration</span>
    </a>
    <?php endif; ?>

    <a href="<?= $_panelBase ?>/auth/logout.php" class="sb-item danger">
      <span class="sb-icon">↩</span>
      <span class="sb-label">Déconnexion</span>
    </a>
  </div>

  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr((string)($user['email'] ?? 'U'), 0, 1)) ?></div>
      <div class="sb-user-info">
        <div class="sb-user-email"><?= e($user['email'] ?? '') ?></div>
        <div class="sb-user-role"><?= !empty($isAdmin) ? 'Admin' : 'Utilisateur' ?></div>
      </div>
    </div>
  </div>
</nav>

<!-- ══════════════════════════════════════════════════
     MAIN
══════════════════════════════════════════════════ -->
<div id="panel-main">

  <header id="panel-topbar">
    <button class="tb-hamburger" onclick="document.getElementById('panel-sidebar').classList.toggle('open')">☰</button>

    <nav class="tb-breadcrumb">
      <a href="<?= $_panelBase ?>/panel/" class="tb-crumb">Panel</a>
      <?php foreach ($_crumbs as $crumb): ?>
        <span class="tb-sep">/</span>
        <?php if (!empty($crumb['url'])): ?>
          <a href="<?= e($crumb['url']) ?>" class="tb-crumb"><?= e($crumb['label']) ?></a>
        <?php else: ?>
          <span class="tb-crumb current"><?= e($crumb['label']) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="tb-spacer"></div>

    <?php if (!empty($topbarActions)): ?>
      <div class="tb-actions">
        <?php foreach ($topbarActions as $act): ?>
          <a href="<?= e($act['url']) ?>" class="tb-btn <?= !empty($act['primary']) ? 'primary' : '' ?>">
            <?= e($act['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </header>

  <main id="panel-content">

<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

$serverId = (int)($_GET['id'] ?? 0);
if (!$serverId) { redirect('/panel/servers.php'); }

$server = null;
// Étape 1 : récupérer le serveur (sans JOIN — robuste quelle que soit la structure)
try {
    $s = $pdo->prepare(
        'SELECT id, uuid, server_name, server_type, mc_version,
                status, server_ip, server_port, server_config, ram_mb,
                created_at, hosting_server_id,
                COALESCE(plan_slug, \'spark\') AS plan_slug
         FROM mc_servers
         WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $s->execute([$serverId, $user['id']]);
    $server = $s->fetch() ?: null;
} catch (Throwable) {}
if (!$server) { redirect('/panel/servers.php'); }

// Étape 2 : enrichir avec le plan (optionnel — ne bloque pas si la table manque)
$server['plan_name']        = null;
$server['plan_ram_mb']      = (int)($server['ram_mb'] ?? 2048);
$server['plan_max_players'] = 20;
$server['disk_gb']          = 10;
try {
    $planSlug = (string)($server['plan_slug'] ?? 'spark');
    $s = $pdo->prepare(
        'SELECT name AS plan_name, ram_mb, max_players, storage_gb AS disk_gb
         FROM mc_server_plans WHERE slug = ? LIMIT 1'
    );
    $s->execute([$planSlug]);
    $plan = $s->fetch() ?: [];
    if ($plan) {
        $server['plan_name']        = $plan['plan_name'];
        $server['plan_ram_mb']      = (int)$plan['ram_mb'];
        $server['plan_max_players'] = (int)$plan['max_players'];
        $server['disk_gb']          = (int)$plan['disk_gb'];
    }
} catch (Throwable) {}

$isAdmin = false;
try {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]); $r = $s->fetch();
    $isAdmin = $r && (int)($r['is_admin'] ?? 0) === 1;
} catch (Throwable) {}

$plugins = []; $mods = [];
try {
    $s = $pdo->prepare('SELECT id, name, version, slug, external_id, source, added_at FROM mc_server_plugins WHERE server_id = ? ORDER BY added_at DESC LIMIT 100'); $s->execute([$serverId]); $plugins = $s->fetchAll();
    $s = $pdo->prepare('SELECT id, name, version, slug, external_id, source, added_at FROM mc_server_mods WHERE server_id = ? ORDER BY added_at DESC LIMIT 100'); $s->execute([$serverId]); $mods = $s->fetchAll();
} catch (Throwable) {}

$links = [];
try {
    $s = $pdo->prepare('SELECT lnk.launcher_uuid, l.name AS launcher_name, l.version, l.loader FROM mc_server_launcher_links lnk LEFT JOIN launchers l ON l.uuid = lnk.launcher_uuid WHERE lnk.server_id = ?');
    $s->execute([$serverId]); $links = $s->fetchAll();
} catch (Throwable) {}

$players = [];
try {
    $s = $pdo->prepare('SELECT id, mc_username, mc_uuid, whitelisted, added_at FROM mc_server_players WHERE server_id = ? ORDER BY added_at DESC');
    $s->execute([$serverId]); $players = $s->fetchAll();
} catch (Throwable) {}

$config = json_decode((string)($server['server_config'] ?? '{}'), true) ?: [];

$st = strtolower((string)($server['status'] ?? 'stopped'));
$type  = strtolower((string)($server['server_type'] ?? 'vanilla'));
$typeColors = ['vanilla' => '#00d68f', 'paper' => '#ff6b6b', 'spigot' => '#ffbe00', 'forge' => '#ff8c42', 'fabric' => '#7c5cff', 'neoforge' => '#e67e22'];
$typeIcons  = ['vanilla' => '⬜', 'paper' => '📄', 'spigot' => '🔌', 'forge' => '⚙️', 'fabric' => '🧵', 'neoforge' => '🔥'];
$color = $typeColors[$type] ?? '#888';
$icon  = $typeIcons[$type]  ?? '🖥️';
$isPlugin = in_array($type, ['paper', 'spigot'], true);
$serverUuid = (string)($server['uuid'] ?? '');

$apiBase = base_path() . '/server-cms/api/provision_server.php';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= e($server['server_name'] ?? 'Serveur') ?> — XynoServer</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; overflow: hidden; }
    body { font-family: 'Inter', system-ui, sans-serif; background: #05050e; color: #e0e0f0; font-size: 13px; line-height: 1.5; -webkit-font-smoothing: antialiased; display: flex; }

    /* ── Left sidebar ── */
    #sv-sidebar {
      width: 230px; min-width: 230px;
      background: #08081a;
      border-right: 1px solid rgba(255,255,255,.07);
      display: flex; flex-direction: column;
      height: 100vh; overflow: hidden; flex-shrink: 0;
    }

    .sv-back {
      display: flex; align-items: center; gap: 8px;
      padding: 14px 16px;
      border-bottom: 1px solid rgba(255,255,255,.06);
      color: #4a4a80; font-size: 12px; font-weight: 500;
      text-decoration: none; transition: color .15s;
    }
    .sv-back:hover { color: #8888c0; }

    .sv-server-info {
      padding: 16px;
      border-bottom: 1px solid rgba(255,255,255,.06);
    }
    .sv-server-icon {
      width: 40px; height: 40px;
      border-radius: 10px;
      background: <?= $color ?>18;
      border: 1px solid <?= $color ?>33;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; margin-bottom: 10px;
    }
    .sv-server-name { font-size: 14px; font-weight: 700; color: #f0f0fc; margin-bottom: 4px; word-break: break-word; }
    .sv-server-type { font-size: 11px; color: <?= $color ?>; font-weight: 600; margin-bottom: 6px; }
    .sv-status-row { display: flex; align-items: center; gap: 6px; }
    .sv-status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
    .sv-status-dot.running { background: #00d68f; box-shadow: 0 0 6px #00d68f88; animation: pulse 2s infinite; }
    .sv-status-dot.stopped { background: #3a3a60; }
    .sv-status-dot.starting,.sv-status-dot.stopping { background: #ffbe00; animation: pulse 1s infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
    .sv-status-text { font-size: 11px; color: #6868a0; font-weight: 500; }

    .sv-ip {
      margin-top: 8px; padding: 6px 8px;
      background: #0a0a1a; border: 1px solid rgba(255,255,255,.06);
      border-radius: 6px; font-family: 'JetBrains Mono', monospace;
      font-size: 10px; color: #5858a0; word-break: break-all;
    }

    /* power buttons in sidebar */
    .sv-power {
      padding: 12px 16px;
      border-bottom: 1px solid rgba(255,255,255,.06);
      display: flex; gap: 6px; flex-wrap: wrap;
    }
    .pwr {
      flex: 1; min-width: 60px;
      display: flex; align-items: center; justify-content: center; gap: 5px;
      padding: 7px 6px; border-radius: 7px;
      font-size: 11px; font-weight: 600; border: none;
      cursor: pointer; transition: .12s; font-family: inherit; white-space: nowrap;
    }
    .pwr:disabled { opacity: .35; cursor: not-allowed; }
    .pwr-start   { background: rgba(0,214,143,.12); color: #00d68f; border: 1px solid rgba(0,214,143,.22); }
    .pwr-start:not(:disabled):hover   { background: rgba(0,214,143,.22); }
    .pwr-stop    { background: rgba(255,77,106,.12);  color: #ff4d6a; border: 1px solid rgba(255,77,106,.22); }
    .pwr-stop:not(:disabled):hover    { background: rgba(255,77,106,.22); }
    .pwr-restart { background: rgba(255,190,0,.1);   color: #ffbe00; border: 1px solid rgba(255,190,0,.2); }
    .pwr-restart:not(:disabled):hover { background: rgba(255,190,0,.2); }
    .pwr-kill    { background: rgba(255,77,106,.08);  color: #ff4d6a99; border: 1px solid rgba(255,77,106,.12); }
    .pwr-kill:not(:disabled):hover    { background: rgba(255,77,106,.15); color: #ff4d6a; }

    /* nav tabs */
    .sv-nav { flex: 1; overflow-y: auto; padding: 8px; scrollbar-width: none; }
    .sv-nav::-webkit-scrollbar { display: none; }
    .sv-nav-section { font-size: 10px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #2a2a50; padding: 12px 8px 4px; }
    .sv-tab {
      display: flex; align-items: center; gap: 9px;
      padding: 8px 10px; border-radius: 7px;
      color: #4a4a80; font-size: 12.5px; font-weight: 500;
      cursor: pointer; transition: .12s; margin-bottom: 1px;
      border: none; background: none; width: 100%; text-align: left; font-family: inherit;
    }
    .sv-tab:hover { background: rgba(255,255,255,.04); color: #a0a0d0; }
    .sv-tab.active { background: rgba(124,92,255,.12); color: #b8a4ff; border: 1px solid rgba(124,92,255,.2); }
    .sv-tab-icon { width: 16px; text-align: center; font-size: 13px; flex-shrink: 0; }
    .sv-tab-badge {
      margin-left: auto; background: rgba(124,92,255,.15); color: #b8a4ff;
      border-radius: 999px; padding: 1px 6px; font-size: 10px; font-weight: 600;
    }

    .sv-plan-info {
      padding: 12px 16px; border-top: 1px solid rgba(255,255,255,.06);
      font-size: 11px; color: #3a3a60; line-height: 1.7;
    }
    .sv-plan-name { font-size: 12px; font-weight: 600; color: #6060a0; margin-bottom: 4px; }

    /* ── Main area ── */
    #sv-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

    /* Top bar */
    #sv-topbar {
      height: 48px; background: #08081a;
      border-bottom: 1px solid rgba(255,255,255,.06);
      display: flex; align-items: center; padding: 0 20px; gap: 10px;
      flex-shrink: 0;
    }
    .sv-topbar-title { font-size: 13px; font-weight: 600; color: #c0c0e0; }
    .sv-topbar-sub { font-size: 11px; color: #3a3a60; margin-left: 4px; }
    #sv-connection-status { margin-left: auto; display: flex; align-items: center; gap: 5px; font-size: 11px; color: #3a3a60; }
    #sv-connection-dot { width: 6px; height: 6px; border-radius: 50%; background: #3a3a60; }
    #sv-connection-dot.ok { background: #00d68f; }

    /* Tab panels */
    #sv-content { flex: 1; overflow: hidden; position: relative; }
    .sv-panel { display: none; height: 100%; overflow-y: auto; padding: 20px; }
    .sv-panel.active { display: block; }
    .sv-panel::-webkit-scrollbar { width: 4px; }
    .sv-panel::-webkit-scrollbar-track { background: transparent; }
    .sv-panel::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 2px; }

    /* Shared components */
    .pc { background: #0e0e22; border: 1px solid rgba(255,255,255,.07); border-radius: 12px; padding: 18px; }
    .pc + .pc { margin-top: 14px; }
    .pc-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; gap: 10px; }
    .pc-title { font-size: 13px; font-weight: 600; color: #e0e0f0; }
    .pc-sub { font-size: 11px; color: #3a3a60; margin-top: 2px; }
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .g3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }
    .g4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
    @media(max-width:900px){.g4{grid-template-columns:repeat(2,1fr)}.g3{grid-template-columns:1fr 1fr}.g2{grid-template-columns:1fr}}

    .kpi { background: #0a0a1a; border: 1px solid rgba(255,255,255,.06); border-radius: 10px; padding: 14px 16px; }
    .kpi-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: #2a2a50; margin-bottom: 8px; }
    .kpi-value { font-size: 24px; font-weight: 800; color: #c4b5fd; line-height: 1; }
    .kpi-sub { font-size: 11px; color: #3a3a60; margin-top: 4px; }

    .pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .p-green  { background: rgba(0,214,143,.1); color: #00d68f; border: 1px solid rgba(0,214,143,.2); }
    .p-amber  { background: rgba(255,190,0,.1);  color: #ffbe00; border: 1px solid rgba(255,190,0,.2); }
    .p-red    { background: rgba(255,77,106,.1); color: #ff4d6a; border: 1px solid rgba(255,77,106,.2); }
    .p-grey   { background: rgba(255,255,255,.05); color: #4a4a80; border: 1px solid rgba(255,255,255,.07); }
    .p-violet { background: rgba(124,92,255,.12); color: #b8a4ff; border: 1px solid rgba(124,92,255,.2); }

    .btn { display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;border:none;cursor:pointer;text-decoration:none;font-family:inherit;transition:.12s; }
    .btn-primary { background:#7c5cff;color:#fff; }
    .btn-primary:hover { background:#9274ff; }
    .btn-ghost { background:rgba(255,255,255,.04);color:#c0c0e0;border:1px solid rgba(255,255,255,.08); }
    .btn-ghost:hover { background:rgba(255,255,255,.08); }
    .btn-danger { background:rgba(255,77,106,.1);color:#ff4d6a;border:1px solid rgba(255,77,106,.2); }
    .btn-sm { padding:4px 9px;font-size:11px; }

    .ilist { display: flex; flex-direction: column; gap: 6px; }
    .irow {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; background: #0a0a1a;
      border: 1px solid rgba(255,255,255,.05); border-radius: 8px;
    }
    .irow-info { flex:1;min-width:0; }
    .irow-name { font-size:12px;font-weight:600;color:#c0c0e0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
    .irow-meta { font-size:11px;color:#3a3a60;margin-top:1px; }
    .irow-icon { width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;background:rgba(124,92,255,.1);border:1px solid rgba(124,92,255,.2); }

    .empty { text-align:center;padding:40px 20px;color:#2a2a50; }
    .empty-icon { font-size:32px;margin-bottom:10px; }
    .empty-title { font-size:14px;font-weight:600;color:#6060a0;margin-bottom:6px; }

    /* ═══ CONSOLE ═══ */
    #console-panel { padding: 0; display: flex; flex-direction: column; }
    #console-panel.active { display: flex; }
    #console-output {
      flex: 1; background: #020208; padding: 14px 16px;
      font-family: 'JetBrains Mono', monospace; font-size: 12px; line-height: 1.65;
      overflow-y: auto; min-height: 0;
    }
    #console-output::-webkit-scrollbar { width: 4px; }
    #console-output::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); }
    .c-line { display: flex; gap: 10px; padding: 1px 0; }
    .c-time { color: #2a2a50; flex-shrink: 0; user-select: none; }
    .c-level { flex-shrink: 0; width: 36px; font-weight: 600; }
    .c-level.INFO  { color: #5b8dff; }
    .c-level.WARN  { color: #ffbe00; }
    .c-level.ERROR { color: #ff4d6a; }
    .c-level.CMD   { color: #b8a4ff; }
    .c-level.SYS   { color: #00d68f; }
    .c-msg { color: #8888c0; word-break: break-all; }
    .c-msg.ERROR { color: #ff7a8a; }
    .c-msg.WARN  { color: #ffd060; }
    .c-msg.CMD   { color: #c4b5fd; }
    .c-msg.SYS   { color: #60d8a8; }
    #console-input-bar {
      display: flex; gap: 8px; align-items: center;
      padding: 10px 12px; background: #08081a;
      border-top: 1px solid rgba(255,255,255,.06);
      flex-shrink: 0;
    }
    #console-prompt { color: #3a3a60; font-family: 'JetBrains Mono', monospace; font-size: 13px; flex-shrink: 0; }
    #console-cmd {
      flex: 1; background: transparent; border: none; outline: none;
      color: #c4b5fd; font-family: 'JetBrains Mono', monospace; font-size: 13px;
      caret-color: #7c5cff;
    }
    #console-cmd::placeholder { color: #2a2a50; }
    .console-toolbar {
      display: flex; align-items: center; gap: 8px; padding: 8px 12px;
      background: #060612; border-bottom: 1px solid rgba(255,255,255,.05);
      flex-shrink: 0;
    }
    .ct-btn {
      font-size: 11px; font-weight: 600; padding: 4px 10px;
      border-radius: 5px; border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.04); color: #6060a0; cursor: pointer; font-family: inherit;
      transition: .12s;
    }
    .ct-btn:hover { background: rgba(255,255,255,.08); color: #a0a0d0; }
    .ct-label { font-size: 11px; color: #2a2a50; margin-left: auto; }
    #console-autoscroll-toggle { accent-color: #7c5cff; cursor: pointer; }

    /* ═══ MONITORING ═══ */
    .chart-card { background: #0a0a1a; border: 1px solid rgba(255,255,255,.06); border-radius: 10px; padding: 16px; }
    .chart-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: #3a3a60; margin-bottom: 4px; }
    .chart-val { font-size: 22px; font-weight: 800; color: #c4b5fd; margin-bottom: 12px; line-height: 1; }

    /* ═══ FILE MANAGER ═══ */
    #fm-bar { display:flex;gap:8px;align-items:center;padding:10px 0;margin-bottom:12px; }
    #fm-path { font-family:'JetBrains Mono',monospace;font-size:11px;color:#5858a0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
    .fm-entry {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 12px; border-radius: 7px;
      cursor: pointer; transition: background .1s; margin-bottom: 2px;
      border: 1px solid transparent;
    }
    .fm-entry:hover { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.06); }
    .fm-entry-icon { font-size: 14px; width: 22px; text-align: center; flex-shrink: 0; }
    .fm-entry-name { font-size: 12px; color: #c0c0e0; font-weight: 500; flex: 1; }
    .fm-entry-size { font-size: 11px; color: #3a3a60; }

    /* ═══ BACKUPS ═══ */
    .backup-row {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 14px; background: #0a0a1a;
      border: 1px solid rgba(255,255,255,.05); border-radius: 8px; margin-bottom: 8px;
    }
    .backup-icon { font-size: 20px; }
    .backup-info { flex: 1; min-width: 0; }
    .backup-name { font-size: 12px; font-weight: 600; color: #c0c0e0; }
    .backup-meta { font-size: 11px; color: #3a3a60; margin-top: 1px; }
    .backup-size { font-size: 11px; color: #5858a0; margin-right: 8px; }

    /* ═══ SETTINGS ═══ */
    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: #3a3a60; margin-bottom: 6px; }
    .form-input {
      width: 100%; background: #0a0a1a; border: 1px solid rgba(255,255,255,.08);
      border-radius: 7px; padding: 8px 12px; color: #e0e0f0;
      font-size: 13px; outline: none; font-family: inherit; transition: border-color .12s;
    }
    .form-input:focus { border-color: #7c5cff; }
    .form-select { appearance: none; cursor: pointer; }
    .form-hint { font-size: 11px; color: #2a2a50; margin-top: 4px; }
    .form-section-title { font-size: 13px; font-weight: 700; color: #8080b0; margin: 20px 0 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,.05); }

    /* Mobile */
    @media(max-width:760px){
      #sv-sidebar{display:none;}
      #sv-sidebar.open{display:flex;position:fixed;z-index:200;top:0;left:0;height:100%;box-shadow:4px 0 30px rgba(0,0,0,.7);}
    }
  </style>
</head>
<body>

<!-- ═══════════ LEFT SIDEBAR ═══════════ -->
<div id="sv-sidebar">
  <a href="<?= base_path() ?>/panel/servers.php" class="sv-back">
    ← Tous les serveurs
  </a>

  <div class="sv-server-info">
    <div class="sv-server-icon"><?= $icon ?></div>
    <div class="sv-server-name"><?= e($server['server_name'] ?? 'Serveur') ?></div>
    <div class="sv-server-type"><?= e(ucfirst($type)) ?> <?= e($server['mc_version'] ?? '') ?></div>
    <div class="sv-status-row">
      <div class="sv-status-dot <?= $st ?>" id="sb-status-dot"></div>
      <span class="sv-status-text" id="sb-status-text"><?= match($st) { 'running' => 'En ligne', 'starting' => 'Démarrage…', 'stopping' => 'Arrêt…', default => 'Arrêté' } ?></span>
    </div>
    <?php if (!empty($server['server_ip'])): ?>
      <div class="sv-ip"><?= e($server['server_ip']) ?>:<?= (int)($server['server_port'] ?? 25565) ?></div>
    <?php endif; ?>
  </div>

  <!-- Power -->
  <div class="sv-power" id="power-zone">
    <button class="pwr pwr-start" id="btn-start" onclick="sendPower('start')">▶ Start</button>
    <button class="pwr pwr-stop"  id="btn-stop"  onclick="sendPower('stop')">■ Stop</button>
    <button class="pwr pwr-restart" id="btn-restart" onclick="sendPower('restart')" style="flex-basis:100%;">↺ Restart</button>
  </div>

  <!-- Nav -->
  <div class="sv-nav">
    <div class="sv-nav-section">Gestion</div>
    <button class="sv-tab active" onclick="switchTab('overview',this)">
      <span class="sv-tab-icon">▦</span> Vue d'ensemble
    </button>
    <button class="sv-tab" onclick="switchTab('console',this)">
      <span class="sv-tab-icon">🖥</span> Console
    </button>
    <button class="sv-tab" onclick="switchTab('monitoring',this)">
      <span class="sv-tab-icon">📊</span> Monitoring
    </button>
    <div class="sv-nav-section">Fichiers</div>
    <button class="sv-tab" onclick="switchTab('files',this)">
      <span class="sv-tab-icon">📁</span> Gestionnaire
    </button>
    <button class="sv-tab" onclick="switchTab('backups',this)">
      <span class="sv-tab-icon">💾</span> Backups
      <span class="sv-tab-badge" id="backup-count">—</span>
    </button>
    <div class="sv-nav-section">Configuration</div>
    <button class="sv-tab" onclick="switchTab('plugins',this)">
      <span class="sv-tab-icon"><?= $isPlugin ? '🔌' : '⚙️' ?></span>
      <?= $isPlugin ? 'Plugins' : 'Mods' ?>
      <span class="sv-tab-badge"><?= $isPlugin ? count($plugins) : count($mods) ?></span>
    </button>
    <button class="sv-tab" onclick="switchTab('players',this)">
      <span class="sv-tab-icon">👥</span> Whitelist
      <span class="sv-tab-badge"><?= count($players) ?></span>
    </button>
    <button class="sv-tab" onclick="switchTab('settings',this)">
      <span class="sv-tab-icon">⚙</span> Paramètres
    </button>
  </div>

  <div class="sv-plan-info">
    <div class="sv-plan-name">Plan <?= e($server['plan_name'] ?? ucfirst((string)($server['plan_slug'] ?? 'Standard'))) ?></div>
    <?= (int)($server['ram_mb'] ?? 0) ?> Mo RAM ·
    <?= !empty($server['disk_gb']) ? (int)$server['disk_gb'] . ' Go' : '—' ?> Disque ·
    <?= (int)($server['max_players'] ?? 0) ?> slots
  </div>
</div>

<!-- ═══════════ MAIN ═══════════ -->
<div id="sv-main">
  <div id="sv-topbar">
    <span class="sv-topbar-title" id="tb-tab-label">Vue d'ensemble</span>
    <span class="sv-topbar-sub">— <?= e($server['server_name'] ?? '') ?></span>
    <div id="sv-connection-status">
      <div id="sv-connection-dot"></div>
      <span id="sv-connection-label">Connexion…</span>
    </div>
  </div>

  <div id="sv-content">

    <!-- ══════ TAB: OVERVIEW ══════ -->
    <div class="sv-panel active" id="tab-overview">
      <!-- KPIs live -->
      <div class="g4" style="margin-bottom:14px;">
        <div class="kpi"><div class="kpi-label">RAM</div><div class="kpi-value" id="ov-ram">—</div><div class="kpi-sub">Mo / <?= (int)($server['ram_mb'] ?? 2048) ?> Mo</div></div>
        <div class="kpi"><div class="kpi-label">CPU</div><div class="kpi-value" id="ov-cpu">—</div><div class="kpi-sub">% utilisation</div></div>
        <div class="kpi"><div class="kpi-label">TPS</div><div class="kpi-value" id="ov-tps">—</div><div class="kpi-sub">/ 20 ticks/s</div></div>
        <div class="kpi"><div class="kpi-label">Joueurs</div><div class="kpi-value" id="ov-players">—</div><div class="kpi-sub">/ <?= (int)($server['max_players'] ?? 10) ?> max</div></div>
      </div>

      <div class="g2">
        <!-- Server info -->
        <div class="pc">
          <div class="pc-hdr"><div><div class="pc-title">Informations serveur</div></div></div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php
              $infoRows = [
                ['Type', ucfirst($type)],
                ['Version MC', $server['mc_version'] ?? '—'],
                ['Plan', $server['plan_name'] ?? ucfirst((string)($server['plan_slug'] ?? '—'))],
                ['RAM', (int)($server['ram_mb'] ?? 0) . ' Mo'],
                ['Joueurs max', (int)($server['max_players'] ?? 0) . ' slots'],
                ['IP:Port', !empty($server['server_ip']) ? ($server['server_ip'] . ':' . ($server['server_port'] ?? 25565)) : '—'],
                ['UUID', strlen($serverUuid) > 12 ? substr($serverUuid, 0, 8) . '…' : ($serverUuid ?: '—')],
                ['Créé le', date('d/m/Y', strtotime((string)($server['created_at'] ?? 'now')))],
              ];
              foreach ($infoRows as [$k, $v]):
            ?>
              <div style="display:flex;align-items:baseline;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);">
                <span style="font-size:11px;color:#3a3a60;font-weight:500;"><?= e($k) ?></span>
                <span style="font-size:12px;color:#a0a0c0;font-family:<?= in_array($k,['IP:Port','UUID']) ? "'JetBrains Mono',monospace" : 'inherit' ?>;font-size:11px;"><?= e((string)$v) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Linked launchers + quick actions -->
        <div style="display:flex;flex-direction:column;gap:14px;">
          <div class="pc">
            <div class="pc-hdr">
              <div><div class="pc-title">🚀 Launchers liés</div></div>
              <a href="<?= base_path() ?>/panel/servers.php" class="btn btn-ghost btn-sm">←</a>
            </div>
            <?php if (empty($links)): ?>
              <div class="empty" style="padding:16px;"><div class="empty-title">Aucun launcher lié</div></div>
            <?php else: ?>
              <div class="ilist">
                <?php foreach ($links as $lnk): ?>
                  <a href="<?= base_path() ?>/panel/launcher.php?uuid=<?= urlencode((string)$lnk['launcher_uuid']) ?>" class="irow" style="text-decoration:none;">
                    <div class="irow-icon" style="font-size:13px;">🎮</div>
                    <div class="irow-info">
                      <div class="irow-name"><?= e($lnk['launcher_name'] ?? '?') ?></div>
                      <div class="irow-meta"><?= e($lnk['version'] ?? '') ?> · <?= e(ucfirst($lnk['loader'] ?? '')) ?></div>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="pc">
            <div class="pc-hdr"><div class="pc-title">Accès rapide</div></div>
            <div style="display:flex;flex-direction:column;gap:6px;">
              <button class="btn btn-ghost" style="justify-content:flex-start;gap:10px;" onclick="switchTab('console',document.querySelector('[onclick*=console]'))">🖥 Console live</button>
              <button class="btn btn-ghost" style="justify-content:flex-start;gap:10px;" onclick="switchTab('monitoring',document.querySelector('[onclick*=monitoring]'))">📊 Monitoring</button>
              <button class="btn btn-ghost" style="justify-content:flex-start;gap:10px;" onclick="switchTab('backups',document.querySelector('[onclick*=backups]'))">💾 Créer un backup</button>
              <?php if ($serverUuid): ?>
                <a href="<?= base_path() ?>/server-cms/dashboard/manage.php?uuid=<?= urlencode($serverUuid) ?>" class="btn btn-ghost" style="justify-content:flex-start;gap:10px;">⚙ Config avancée</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════ TAB: CONSOLE ══════ -->
    <div class="sv-panel" id="tab-console" style="padding:0;display:none;flex-direction:column;">
      <div class="console-toolbar">
        <button class="ct-btn" onclick="consoleClear()">Effacer</button>
        <button class="ct-btn" onclick="consoleScroll()">↓ Bas</button>
        <button class="ct-btn" id="btn-autoscroll" onclick="toggleAutoscroll()" style="background:rgba(124,92,255,.15);color:#b8a4ff;border-color:rgba(124,92,255,.2);">Auto-scroll ✓</button>
        <span class="ct-label" id="console-line-count">0 lignes</span>
      </div>
      <div id="console-output"></div>
      <div id="console-input-bar">
        <span id="console-prompt">$</span>
        <input id="console-cmd" type="text" placeholder="Entrez une commande Minecraft…" autocomplete="off" spellcheck="false"/>
        <button class="btn btn-primary btn-sm" onclick="consoleSend()">Envoyer</button>
      </div>
    </div>

    <!-- ══════ TAB: MONITORING ══════ -->
    <div class="sv-panel" id="tab-monitoring">
      <div class="g2" style="margin-bottom:14px;">
        <div class="chart-card">
          <div class="chart-title">RAM</div>
          <div class="chart-val" id="mon-ram-val">— <span style="font-size:13px;color:#3a3a60;">Mo</span></div>
          <canvas id="chart-ram" height="90"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-title">CPU</div>
          <div class="chart-val" id="mon-cpu-val">— <span style="font-size:13px;color:#3a3a60;">%</span></div>
          <canvas id="chart-cpu" height="90"></canvas>
        </div>
      </div>
      <div class="g2">
        <div class="chart-card">
          <div class="chart-title">TPS</div>
          <div class="chart-val" id="mon-tps-val">— <span style="font-size:13px;color:#3a3a60;">/ 20</span></div>
          <canvas id="chart-tps" height="90"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-title">Joueurs connectés</div>
          <div class="chart-val" id="mon-pl-val">— <span style="font-size:13px;color:#3a3a60;">joueurs</span></div>
          <canvas id="chart-pl" height="90"></canvas>
        </div>
      </div>
    </div>

    <!-- ══════ TAB: FILES ══════ -->
    <div class="sv-panel" id="tab-files" style="padding:0;display:none;flex-direction:column;">
      <div id="fm-bar" style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.06);background:#06060f;flex-wrap:wrap;">
        <button class="btn btn-ghost btn-sm" onclick="fmUp()" title="Dossier parent">↑ Parent</button>
        <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:0;background:#0a0a1a;border:1px solid rgba(255,255,255,.07);border-radius:7px;padding:5px 10px;">
          <span style="color:#3a3a60;font-size:11px;font-family:'JetBrains Mono',monospace;">📁</span>
          <span id="fm-path" style="font-size:12px;font-family:'JetBrains Mono',monospace;color:#8888c0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">/</span>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="fmRefresh()" title="Rafraîchir">↻</button>
        <button class="btn btn-ghost btn-sm" onclick="fmMkdir()" title="Nouveau dossier">📁+</button>
        <button class="btn btn-primary btn-sm" onclick="fmUploadClick()">⬆ Upload</button>
        <input type="file" id="fm-upload-input" style="display:none;" multiple onchange="fmUpload(this)"/>
      </div>
      <div id="fm-upload-progress" style="display:none;padding:6px 14px;background:rgba(124,92,255,.08);border-bottom:1px solid rgba(124,92,255,.2);font-size:12px;color:#b8a4ff;">
        ⏳ Upload en cours…
      </div>
      <div id="fm-list" style="flex:1;overflow-y:auto;padding:8px;">
        <div class="empty"><div class="empty-icon">📁</div><div class="empty-title">Chargement…</div></div>
      </div>
    </div>

    <!-- ══════ TAB: BACKUPS ══════ -->
    <div class="sv-panel" id="tab-backups">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div>
          <div style="font-size:15px;font-weight:700;color:#e0e0f0;">Backups</div>
          <div style="font-size:12px;color:#3a3a60;margin-top:3px;">Sauvegardez et restaurez l'état de votre serveur</div>
        </div>
        <button class="btn btn-primary" onclick="createBackup()">+ Créer un backup</button>
      </div>
      <div id="backup-list">
        <div class="empty"><div class="empty-icon">💾</div><div class="empty-title">Chargement des backups…</div></div>
      </div>
    </div>

    <!-- ══════ TAB: PLUGINS/MODS ══════ -->
    <div class="sv-panel" id="tab-plugins">
      <?php
        $pkgType  = $isPlugin ? 'plugin' : 'mod';
        $pkgLabel = $isPlugin ? 'Plugin' : 'Mod';
        $pkgIcon  = $isPlugin ? '🔌' : '⚙️';
        $pkgItems = $isPlugin ? $plugins : $mods;
        $pkgInstalled = array_column($pkgItems, null, 'slug'); // slug → row pour check rapide
      ?>
      <!-- Header -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:15px;font-weight:700;color:#e0e0f0;"><?= $pkgLabel ?>s</div>
          <div style="font-size:12px;color:#3a3a60;margin-top:2px;" id="pkg-installed-count"><?= count($pkgItems) ?> installé<?= count($pkgItems) !== 1 ? 's' : '' ?></div>
        </div>
        <!-- Barre de recherche Modrinth -->
        <div style="display:flex;gap:8px;flex:1;max-width:420px;">
          <input id="pkg-search-input" type="text" placeholder="Rechercher sur Modrinth…"
            style="flex:1;background:#0a0a1a;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:8px 12px;color:#e0e0f0;font-size:13px;font-family:inherit;outline:none;"
            onfocus="this.style.borderColor='#7c5cff'" onblur="this.style.borderColor='rgba(255,255,255,.1)'"
            onkeydown="if(event.key==='Enter')pkgSearch()"/>
          <button onclick="pkgSearch()" class="btn btn-primary btn-sm" style="white-space:nowrap;">🔍 Chercher</button>
        </div>
      </div>

      <!-- Résultats de recherche Modrinth -->
      <div id="pkg-search-results" style="display:none;margin-bottom:20px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#3a3a60;margin-bottom:8px;">Résultats Modrinth</div>
        <div id="pkg-results-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;"></div>
        <div id="pkg-results-more" style="margin-top:10px;text-align:center;display:none;">
          <button onclick="pkgSearchMore()" class="btn btn-ghost btn-sm">Charger plus</button>
        </div>
      </div>

      <!-- Liste installée -->
      <div>
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#3a3a60;margin-bottom:8px;" id="pkg-list-title">
          <?= $pkgLabel ?>s installés
        </div>
        <div id="pkg-installed-list">
          <?php if (empty($pkgItems)): ?>
            <div class="empty" id="pkg-empty-state">
              <div class="empty-icon"><?= $pkgIcon ?></div>
              <div class="empty-title">Aucun <?= strtolower($pkgLabel) ?> installé</div>
              <div class="empty-text">Cherchez sur Modrinth pour en ajouter.</div>
            </div>
          <?php else: ?>
            <?php foreach ($pkgItems as $item): ?>
              <div class="irow" id="pkg-row-<?= (int)$item['id'] ?>">
                <div style="width:34px;height:34px;border-radius:8px;background:rgba(124,92,255,.1);border:1px solid rgba(124,92,255,.2);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;"><?= $pkgIcon ?></div>
                <div class="irow-info">
                  <div class="irow-name"><?= e($item['name'] ?? '?') ?></div>
                  <div class="irow-meta">v<?= e($item['version'] ?? '?') ?> · <?= e(ucfirst($item['source'] ?? 'manual')) ?> · <?= e(date('d/m/Y', strtotime((string)($item['added_at'] ?? '')))) ?></div>
                </div>
                <?php if (!empty($item['slug'])): ?>
                  <a href="https://modrinth.com/<?= $pkgType === 'plugin' ? 'plugin' : 'mod' ?>/<?= urlencode($item['slug']) ?>" target="_blank"
                     style="font-size:11px;color:#3a3a60;text-decoration:none;" title="Voir sur Modrinth">↗</a>
                <?php endif; ?>
                <button onclick="pkgRemove(<?= (int)$item['id'] ?>, '<?= e(addslashes($item['name'] ?? '')) ?>')"
                  style="background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.2);color:#ff4d6a;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:.12s;"
                  onmouseenter="this.style.background='rgba(255,77,106,.2)'" onmouseleave="this.style.background='rgba(255,77,106,.1)'">
                  ✕ Retirer
                </button>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <script>
      const PKG_TYPE     = '<?= $pkgType ?>';
      const PKG_UUID     = '<?= e($serverUuid) ?>';
      const PKG_MC_VER   = '<?= e($server['mc_version'] ?? '') ?>';
      const PKG_LOADER   = '<?= e($type) ?>';
      const PKG_API_SEARCH = '<?= base_path() ?>/server-cms/api/search_modrinth.php';
      const PKG_API_VERS   = '<?= base_path() ?>/server-cms/api/modrinth_versions.php';
      const PKG_API_PKG    = '<?= base_path() ?>/server-cms/api/server_packages.php';
      const PKG_CSRF       = '<?= e(csrf_token()) ?>';
      const PKG_INSTALLED  = new Set(<?= json_encode(array_values(array_filter(array_column($pkgItems, 'slug')))) ?>);

      let pkgOffset = 0;
      let pkgLastQ  = '';

      async function pkgSearch(append = false) {
        const q = document.getElementById('pkg-search-input').value.trim();
        if (!q) return;
        if (!append) { pkgOffset = 0; pkgLastQ = q; }

        const grid = document.getElementById('pkg-results-grid');
        if (!append) { grid.innerHTML = '<div style="color:#3a3a60;font-size:13px;padding:12px;">Recherche…</div>'; }

        document.getElementById('pkg-search-results').style.display = 'block';

        const url = `${PKG_API_SEARCH}?q=${encodeURIComponent(q)}&type=${PKG_TYPE}&mc_version=${encodeURIComponent(PKG_MC_VER)}&loader=${encodeURIComponent(PKG_LOADER)}&limit=12&offset=${pkgOffset}`;
        const res = await fetch(url).then(r => r.json()).catch(() => null);
        if (!res?.ok) {
          if (!append) grid.innerHTML = '<div style="color:#ff4d6a;font-size:13px;padding:12px;">Erreur de recherche.</div>';
          return;
        }

        if (!append) grid.innerHTML = '';
        if (!res.hits?.length && !append) {
          grid.innerHTML = '<div style="color:#3a3a60;font-size:13px;padding:12px;">Aucun résultat.</div>';
          return;
        }

        res.hits.forEach(hit => {
          const installed = PKG_INSTALLED.has(hit.slug);
          const card = document.createElement('div');
          card.id = 'pkg-card-' + hit.slug;
          card.style.cssText = 'background:#0a0a1a;border:1px solid rgba(255,255,255,.07);border-radius:9px;padding:12px 14px;display:flex;flex-direction:column;gap:8px;';
          const dl = hit.downloads >= 1000000 ? (hit.downloads/1000000).toFixed(1)+'M' : hit.downloads >= 1000 ? (hit.downloads/1000).toFixed(0)+'k' : hit.downloads;
          card.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;">
              ${hit.icon_url ? `<img src="${hit.icon_url}" style="width:36px;height:36px;border-radius:7px;flex-shrink:0;object-fit:cover;" onerror="this.style.display='none'"/>` : '<div style="width:36px;height:36px;border-radius:7px;background:rgba(124,92,255,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;"><?= $pkgIcon ?></div>'}
              <div style="min-width:0;">
                <div style="font-size:13px;font-weight:600;color:#c0c0e0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${hit.name}</div>
                <div style="font-size:11px;color:#3a3a60;margin-top:2px;">⬇ ${dl} téléchargements</div>
              </div>
            </div>
            <div style="font-size:11px;color:#4848a0;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${hit.description}</div>
            <button id="pkgbtn-${hit.slug}" onclick="pkgInstall('${hit.slug}','${hit.name.replace(/'/g,"\\'")}','${hit.id}')"
              style="background:${installed?'rgba(0,214,143,.08)':'rgba(124,92,255,.12)'};border:1px solid ${installed?'rgba(0,214,143,.2)':'rgba(124,92,255,.25)'};color:${installed?'#00d68f':'#b8a4ff'};border-radius:7px;padding:6px 10px;font-size:12px;font-weight:600;cursor:${installed?'default':'pointer'};font-family:inherit;width:100%;transition:.12s;"
              ${installed?'disabled':''}>
              ${installed ? '✓ Installé' : '+ Installer'}
            </button>`;
          grid.appendChild(card);
        });

        pkgOffset += res.hits.length;
        const more = document.getElementById('pkg-results-more');
        more.style.display = (pkgOffset < res.total) ? 'block' : 'none';
      }

      function pkgSearchMore() { pkgSearch(true); }

      async function pkgInstall(slug, name, projectId) {
        const btn = document.getElementById('pkgbtn-' + slug);
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.textContent = '⏳ Récupération…';

        // 1. Récupère la dernière version compatible
        const versUrl = `${PKG_API_VERS}?project_id=${encodeURIComponent(projectId)}&mc_version=${encodeURIComponent(PKG_MC_VER)}&loader=${encodeURIComponent(PKG_LOADER)}`;
        const vers = await fetch(versUrl).then(r => r.json()).catch(() => null);
        const v = vers?.versions?.[0];

        const payload = {
          server_uuid:  PKG_UUID,
          package_type: PKG_TYPE,
          _csrf:        PKG_CSRF,
          name:         name,
          slug:         slug,
          external_id:  projectId,
          source:       'modrinth',
          version:      v?.version_number ?? 'latest',
          file_url:     v?.primary_file?.url ?? '',
          file_name:    v?.primary_file?.filename ?? '',
          file_size:    v?.primary_file?.size ?? 0,
          file_hash:    v?.primary_file?.hashes?.sha1 ?? '',
        };

        btn.textContent = '⏳ Installation…';
        const res = await fetch(PKG_API_PKG, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PKG_CSRF },
          body: JSON.stringify(payload),
        }).then(r => r.json()).catch(() => null);

        if (res?.ok) {
          PKG_INSTALLED.add(slug);
          btn.textContent = '✓ Installé';
          btn.style.background = 'rgba(0,214,143,.08)';
          btn.style.borderColor = 'rgba(0,214,143,.2)';
          btn.style.color = '#00d68f';
          pkgAddToList(res.package ?? { id: res.id, name, slug, version: payload.version, source: 'modrinth' });
        } else {
          btn.disabled = false;
          btn.textContent = '✕ Erreur';
          btn.style.color = '#ff4d6a';
          setTimeout(() => { btn.textContent = '+ Installer'; btn.style.color = '#b8a4ff'; }, 2000);
        }
      }

      function pkgAddToList(pkg) {
        const list = document.getElementById('pkg-installed-list');
        const empty = document.getElementById('pkg-empty-state');
        if (empty) empty.remove();

        const row = document.createElement('div');
        row.className = 'irow';
        row.id = 'pkg-row-' + pkg.id;
        row.innerHTML = `
          <div style="width:34px;height:34px;border-radius:8px;background:rgba(124,92,255,.1);border:1px solid rgba(124,92,255,.2);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;"><?= $pkgIcon ?></div>
          <div class="irow-info">
            <div class="irow-name">${pkg.name}</div>
            <div class="irow-meta">v${pkg.version} · Modrinth · à l'instant</div>
          </div>
          ${pkg.slug ? `<a href="https://modrinth.com/${PKG_TYPE === 'plugin' ? 'plugin' : 'mod'}/${pkg.slug}" target="_blank" style="font-size:11px;color:#3a3a60;text-decoration:none;">↗</a>` : ''}
          <button onclick="pkgRemove(${pkg.id},'${(pkg.name||'').replace(/'/g,"\\'")}') "
            style="background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.2);color:#ff4d6a;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:.12s;"
            onmouseenter="this.style.background='rgba(255,77,106,.2)'" onmouseleave="this.style.background='rgba(255,77,106,.1)'">
            ✕ Retirer
          </button>`;
        list.prepend(row);
        pkgUpdateCount(1);
      }

      async function pkgRemove(id, name) {
        if (!confirm(`Retirer "${name}" ?`)) return;
        const row = document.getElementById('pkg-row-' + id);
        if (row) row.style.opacity = '.4';

        const res = await fetch(PKG_API_PKG + '?server_uuid=' + encodeURIComponent(PKG_UUID) + '&package_type=' + PKG_TYPE + '&package_id=' + id + '&_csrf=' + encodeURIComponent(PKG_CSRF), {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PKG_CSRF },
          body: JSON.stringify({ server_uuid: PKG_UUID, package_type: PKG_TYPE, package_id: id, _csrf: PKG_CSRF }),
        }).then(r => r.json()).catch(() => null);

        if (res?.ok) {
          if (row) row.remove();
          pkgUpdateCount(-1);
          // Réactiver le bouton dans les résultats si visible
          const btn = document.getElementById('pkgbtn-' + (row?.dataset?.slug ?? ''));
          if (btn) { btn.disabled = false; btn.textContent = '+ Installer'; btn.style.color = '#b8a4ff'; }
        } else {
          if (row) row.style.opacity = '1';
          alert('Erreur lors de la suppression.');
        }
      }

      function pkgUpdateCount(delta) {
        const el = document.getElementById('pkg-installed-count');
        if (!el) return;
        const n = Math.max(0, parseInt(el.textContent) + delta);
        el.textContent = n + ' installé' + (n !== 1 ? 's' : '');
        // badge sidebar
        document.querySelectorAll('.sv-tab-badge').forEach(b => {
          if (b.closest('[onclick*="plugins"]')) b.textContent = n;
        });
      }
      </script>
    </div>

    <!-- ══════ TAB: PLAYERS ══════ -->
    <div class="sv-panel" id="tab-players">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:15px;font-weight:700;color:#e0e0f0;">Whitelist</div>
          <div style="font-size:12px;color:#3a3a60;margin-top:2px;" id="pl-count"><?= count($players) ?> joueur<?= count($players) !== 1 ? 's' : '' ?> autorisé<?= count($players) !== 1 ? 's' : '' ?></div>
        </div>
        <!-- Ajout rapide -->
        <div style="display:flex;gap:8px;align-items:center;">
          <input id="pl-add-input" type="text" placeholder="Pseudo Minecraft…" maxlength="16"
            style="background:#0a0a1a;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:7px 12px;color:#e0e0f0;font-size:13px;font-family:inherit;outline:none;width:180px;"
            onfocus="this.style.borderColor='#7c5cff'" onblur="this.style.borderColor='rgba(255,255,255,.1)'"
            onkeydown="if(event.key==='Enter')plAdd()"/>
          <button onclick="plAdd()" class="btn btn-primary btn-sm">+ Ajouter</button>
        </div>
      </div>

      <div id="pl-add-msg" style="display:none;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:12px;"></div>

      <div id="pl-list">
        <?php if (empty($players)): ?>
          <div class="empty" id="pl-empty">
            <div class="empty-icon">👥</div>
            <div class="empty-title">Whitelist vide</div>
            <div class="empty-text">Ajoutez des joueurs pour leur donner accès au serveur.</div>
          </div>
        <?php else: ?>
          <div class="ilist" id="pl-ilist">
            <?php foreach ($players as $p): ?>
              <div class="irow" id="pl-row-<?= (int)$p['id'] ?>">
                <img src="https://mc-heads.net/avatar/<?= urlencode($p['mc_username'] ?? 'Steve') ?>/32"
                     style="width:32px;height:32px;border-radius:6px;flex-shrink:0;image-rendering:pixelated;"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2232%22 height=%2232%22><rect fill=%22%23222%22 width=%2232%22 height=%2232%22 rx=%226%22/><text y=%2222%22 x=%228%22 font-size=%2218%22>👤</text></svg>'"/>
                <div class="irow-info">
                  <div class="irow-name"><?= e($p['mc_username'] ?? '?') ?></div>
                  <div class="irow-meta">
                    <?php if (!empty($p['mc_uuid'])): ?>
                      <code style="font-family:'JetBrains Mono',monospace;font-size:10px;color:#3a3a60;"><?= e($p['mc_uuid']) ?></code>
                    <?php else: ?>
                      <span style="color:#3a3a60;">UUID non résolu</span>
                    <?php endif; ?>
                    · ajouté le <?= e(date('d/m/Y', strtotime((string)($p['added_at'] ?? 'now')))) ?>
                  </div>
                </div>
                <span class="pill <?= (int)($p['whitelisted']??1) ? 'pill-green' : 'pill-grey' ?>" style="font-size:10px;">
                  <?= (int)($p['whitelisted']??1) ? 'Whitelisté' : 'Retiré' ?>
                </span>
                <button onclick="plRemove(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['mc_username']??'')) ?>')"
                  style="background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.2);color:#ff4d6a;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:.12s;white-space:nowrap;"
                  onmouseenter="this.style.background='rgba(255,77,106,.2)'" onmouseleave="this.style.background='rgba(255,77,106,.1)'">
                  ✕ Retirer
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <script>
      const PL_API = '<?= base_path() ?>/server-cms/api/server_players.php';
      const PL_SID = <?= $serverId ?>;

      function plMsg(text, ok) {
        const el = document.getElementById('pl-add-msg');
        el.style.display = 'block';
        el.textContent = text;
        el.style.background = ok ? 'rgba(0,214,143,.08)' : 'rgba(255,77,106,.08)';
        el.style.border = '1px solid ' + (ok ? 'rgba(0,214,143,.2)' : 'rgba(255,77,106,.2)');
        el.style.color = ok ? '#00d68f' : '#ff4d6a';
        setTimeout(() => el.style.display = 'none', 3500);
      }

      async function plAdd() {
        const inp = document.getElementById('pl-add-input');
        const username = inp.value.trim();
        if (!username) { plMsg('Entrez un pseudo Minecraft.', false); return; }
        inp.disabled = true;
        const res = await fetch(PL_API, {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ server_id: PL_SID, action: 'add', mc_username: username })
        }).then(r=>r.json()).catch(()=>null);

        inp.disabled = false; inp.value = '';
        if (!res?.ok) { plMsg(res?.error ?? 'Erreur lors de l\'ajout.', false); return; }
        plMsg('✓ ' + username + ' ajouté à la whitelist.', true);

        const p = res.player;
        const empty = document.getElementById('pl-empty');
        if (empty) empty.remove();

        let ilist = document.getElementById('pl-ilist');
        if (!ilist) {
          const list = document.getElementById('pl-list');
          list.innerHTML = '<div class="ilist" id="pl-ilist"></div>';
          ilist = document.getElementById('pl-ilist');
        }

        const row = document.createElement('div');
        row.className = 'irow'; row.id = 'pl-row-' + p.id;
        row.innerHTML = `
          <img src="https://mc-heads.net/avatar/${encodeURIComponent(p.mc_username)}/32"
               style="width:32px;height:32px;border-radius:6px;flex-shrink:0;image-rendering:pixelated;"
               onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2232%22 height=%2232%22><rect fill=%22%23222%22 width=%2232%22 height=%2232%22 rx=%226%22/><text y=%2222%22 x=%228%22 font-size=%2218%22>👤</text></svg>'"/>
          <div class="irow-info">
            <div class="irow-name">${escHtml(p.mc_username)}</div>
            <div class="irow-meta">${p.mc_uuid ? `<code style="font-family:'JetBrains Mono',monospace;font-size:10px;color:#3a3a60;">${escHtml(p.mc_uuid)}</code>` : 'UUID en résolution…'} · à l'instant</div>
          </div>
          <span class="pill pill-green" style="font-size:10px;">Whitelisté</span>
          <button onclick="plRemove(${p.id},'${escHtml(p.mc_username).replace(/'/g,"\\'")}') "
            style="background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.2);color:#ff4d6a;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:.12s;white-space:nowrap;"
            onmouseenter="this.style.background='rgba(255,77,106,.2)'" onmouseleave="this.style.background='rgba(255,77,106,.1)'">
            ✕ Retirer
          </button>`;
        ilist.prepend(row);
        plUpdateCount(1);

        // Envoyer la commande whitelist au serveur
        fetch(API_BASE, {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ action:'command', server_id: PL_SID, command: 'whitelist add ' + p.mc_username })
        }).catch(()=>{});
      }

      async function plRemove(id, username) {
        if (!confirm(`Retirer ${username} de la whitelist ?`)) return;
        const row = document.getElementById('pl-row-' + id);
        if (row) row.style.opacity = '.4';
        const res = await fetch(PL_API, {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ server_id: PL_SID, action: 'remove', player_id: id })
        }).then(r=>r.json()).catch(()=>null);

        if (res?.ok) {
          if (row) row.remove();
          plUpdateCount(-1);
          fetch(API_BASE, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'command', server_id: PL_SID, command: 'whitelist remove ' + username })
          }).catch(()=>{});
        } else {
          if (row) row.style.opacity = '1';
          plMsg(res?.error ?? 'Erreur lors de la suppression.', false);
        }
      }

      function plUpdateCount(delta) {
        const el = document.getElementById('pl-count');
        if (!el) return;
        const m = el.textContent.match(/\d+/);
        const n = Math.max(0, (m ? parseInt(m[0]) : 0) + delta);
        el.textContent = n + ' joueur' + (n!==1?'s':'') + ' autorisé' + (n!==1?'s':'');
        document.querySelectorAll('.sv-tab-badge').forEach(b => {
          if (b.closest('[onclick*="players"]')) b.textContent = n;
        });
      }
      </script>
    </div>

    <!-- ══════ TAB: SETTINGS ══════ -->
    <div class="sv-panel" id="tab-settings">
      <div style="max-width:680px;">
        <div class="form-section-title">⚙ Informations du serveur</div>
        <form onsubmit="saveSettings(event)">
          <div class="g2">
            <div class="form-group">
              <label class="form-label">Nom du serveur</label>
              <input class="form-input" name="server_name" value="<?= e($server['server_name'] ?? '') ?>" required/>
            </div>
            <div class="form-group">
              <label class="form-label">Version Minecraft</label>
              <input class="form-input" name="mc_version" value="<?= e($server['mc_version'] ?? '') ?>"/>
            </div>
          </div>
          <div class="g2">
            <div class="form-group">
              <label class="form-label">Type de serveur</label>
              <select class="form-input form-select" name="server_type">
                <?php foreach (['vanilla','paper','spigot','forge','fabric','neoforge'] as $t): ?>
                  <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Joueurs max</label>
              <input class="form-input" type="number" name="max_players" value="<?= (int)($server['max_players'] ?? 10) ?>" min="1" max="500"/>
            </div>
          </div>

          <div class="form-section-title">🔒 Sécurité & accès</div>
          <div class="g2">
            <div class="form-group">
              <label class="form-label">IP / Hostname</label>
              <input class="form-input" name="server_ip" value="<?= e($server['server_ip'] ?? '') ?>" placeholder="auto"/>
              <div class="form-hint">Laissez vide pour attribution automatique</div>
            </div>
            <div class="form-group">
              <label class="form-label">Port</label>
              <input class="form-input" type="number" name="server_port" value="<?= (int)($server['server_port'] ?? 25565) ?>" min="1024" max="65535"/>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">RCON Password</label>
            <input class="form-input" type="password" name="rcon_password" value="<?= e($config['rcon_password'] ?? '') ?>" placeholder="Mot de passe RCON (optionnel)"/>
          </div>

          <div class="form-section-title">🎮 Gameplay</div>
          <div class="form-group">
            <label class="form-label">MOTD <span style="color:#3a3a60;font-weight:400;">(message affiché dans la liste de serveurs)</span></label>
            <input class="form-input" name="motd" value="<?= e($server['motd'] ?? $config['motd'] ?? '') ?>" placeholder="Un serveur Minecraft génial"/>
          </div>
          <div class="g2">
            <div class="form-group">
              <label class="form-label">Mode de jeu</label>
              <select class="form-input form-select" name="gamemode">
                <?php foreach (['survival'=>'Survie','creative'=>'Créatif','adventure'=>'Aventure','spectator'=>'Spectateur'] as $gm => $gml): ?>
                  <option value="<?= $gm ?>" <?= ($config['gamemode'] ?? 'survival') === $gm ? 'selected' : '' ?>><?= $gml ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Difficulté</label>
              <select class="form-input form-select" name="difficulty">
                <?php foreach (['peaceful'=>'Paisible','easy'=>'Facile','normal'=>'Normal','hard'=>'Difficile'] as $d => $dl): ?>
                  <option value="<?= $d ?>" <?= ($config['difficulty'] ?? 'normal') === $d ? 'selected' : '' ?>><?= $dl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="g2">
            <div class="form-group">
              <label class="form-label">Distance de vue <span style="color:#3a3a60;font-weight:400;">(chunks)</span></label>
              <input class="form-input" type="number" name="view_distance" value="<?= (int)($config['view_distance'] ?? 10) ?>" min="2" max="32"/>
            </div>
            <div class="form-group">
              <label class="form-label">Protection spawn <span style="color:#3a3a60;font-weight:400;">(rayon blocs)</span></label>
              <input class="form-input" type="number" name="spawn_protection" value="<?= (int)($config['spawn_protection'] ?? 16) ?>" min="0" max="255"/>
            </div>
          </div>

          <div class="form-section-title">🔧 Options serveur</div>
          <div class="g2">
            <div class="form-group">
              <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                PVP
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                  <input type="checkbox" name="pvp" value="1" <?= !empty($config['pvp']) || !isset($config['pvp']) ? 'checked' : '' ?> style="accent-color:#7c5cff;width:16px;height:16px;"/>
                  <span style="font-size:12px;color:#8888c0;font-weight:400;">Activé</span>
                </label>
              </label>
            </div>
            <div class="form-group">
              <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                Mode en ligne <span style="font-size:10px;color:#3a3a60;">(auth Mojang)</span>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                  <input type="checkbox" name="online_mode" value="1" <?= !isset($config['online_mode']) || !empty($config['online_mode']) ? 'checked' : '' ?> style="accent-color:#7c5cff;width:16px;height:16px;"/>
                  <span style="font-size:12px;color:#8888c0;font-weight:400;">Activé</span>
                </label>
              </label>
            </div>
          </div>

          <div class="form-section-title">⚡ JVM & Performances</div>
          <div class="form-group">
            <label class="form-label">Flags JVM <span style="color:#3a3a60;font-weight:400;">(arguments Java optionnels)</span></label>
            <input class="form-input" name="java_flags" value="<?= e($config['java_flags'] ?? '') ?>" placeholder="-XX:+UseG1GC -XX:MaxGCPauseMillis=50" style="font-family:'JetBrains Mono',monospace;font-size:12px;"/>
            <div class="form-hint">Flags Aikar recommandés : <code style="font-size:10px;color:#7c5cff;">-XX:+UseG1GC -XX:+ParallelRefProcEnabled -XX:MaxGCPauseMillis=200</code></div>
          </div>

          <div style="display:flex;align-items:center;gap:10px;padding-top:12px;">
            <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
            <div id="settings-msg" style="font-size:12px;display:none;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;"></div>
          </div>
        </form>

        <div style="margin-top:30px;padding-top:20px;border-top:1px solid rgba(255,77,106,.15);">
          <div style="font-size:13px;font-weight:700;color:#ff4d6a;margin-bottom:10px;">⚠ Zone dangereuse</div>
          <div style="background:rgba(255,77,106,.05);border:1px solid rgba(255,77,106,.15);border-radius:10px;padding:14px;">
            <div style="font-size:12px;font-weight:600;color:#c0c0e0;margin-bottom:4px;">Supprimer ce serveur</div>
            <div style="font-size:11px;color:#4a4a80;margin-bottom:12px;">Cette action est irréversible. Toutes les données du serveur seront perdues.</div>
            <button class="btn btn-danger" onclick="deleteServer()">🗑 Supprimer le serveur</button>
          </div>
        </div>
      </div>
    </div>

  </div><!-- #sv-content -->
</div><!-- #sv-main -->

<script>
const SERVER_ID  = <?= $serverId ?>;
const API_BASE   = '<?= base_path() ?>/server-cms/api/provision_server.php';
const MAX_POINTS = 40;
const MAX_LINES  = 500;

let currentStatus = '<?= $st ?>';
let autoScroll    = true;
let consoleLines  = 0;
let consoleBuffer = [];
let pollTimer     = null;

/* ══════════════════════════════════
   TAB SWITCHING
══════════════════════════════════ */
const FLEX_TABS = new Set(['console', 'files']);
function switchTab(id, btn) {
  document.querySelectorAll('.sv-panel').forEach(p => { p.classList.remove('active'); p.style.display = 'none'; });
  document.querySelectorAll('.sv-tab').forEach(t => t.classList.remove('active'));
  const panel = document.getElementById('tab-' + id);
  if (panel) { panel.classList.add('active'); panel.style.display = FLEX_TABS.has(id) ? 'flex' : 'block'; }
  if (btn) btn.classList.add('active');
  const labels = { overview:'Vue d\'ensemble', console:'Console', monitoring:'Monitoring', files:'Fichiers', backups:'Backups', plugins:'<?= $isPlugin ? "Plugins" : "Mods" ?>', players:'Whitelist', settings:'Paramètres' };
  document.getElementById('tb-tab-label').textContent = labels[id] || id;
  if (id === 'files') fmRefresh();
  if (id === 'backups') loadBackups();
  if (id === 'monitoring') initCharts();
}

/* ══════════════════════════════════
   POWER CONTROLS
══════════════════════════════════ */
async function sendPower(action) {
  document.querySelectorAll('.pwr').forEach(b => b.disabled = true);
  addConsoleLine('CMD', '> Server ' + action + ' requested');
  try {
    const r = await fetch(API_BASE, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action, server_id: SERVER_ID })
    });
    const d = await r.json();
    if (d.ok) { addConsoleLine('SYS', 'Action "' + action + '" envoyée avec succès.'); }
    else { addConsoleLine('ERROR', 'Erreur: ' + (d.error || 'inconnue')); }
  } catch(e) { addConsoleLine('ERROR', 'Erreur réseau: ' + e.message); }
  setTimeout(pollStatus, 1500);
}

/* ══════════════════════════════════
   STATUS POLLING
══════════════════════════════════ */
async function pollStatus() {
  try {
    const r = await fetch(API_BASE, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'status', server_id: SERVER_ID })
    });
    const d = await r.json();
    if (!d.ok) return;
    const s = d.status || {};
    currentStatus = s.online ? 'running' : 'stopped';

    // Connection indicator
    document.getElementById('sv-connection-dot').className = 'ok';
    document.getElementById('sv-connection-label').textContent = 'Connecté';

    // Status dot + text
    const dot = document.getElementById('sb-status-dot');
    dot.className = 'sv-status-dot ' + currentStatus;
    document.getElementById('sb-status-text').textContent = currentStatus === 'running' ? 'En ligne' : 'Arrêté';

    // Power buttons
    const isOn = s.online;
    document.getElementById('btn-start').disabled   = isOn;
    document.getElementById('btn-stop').disabled    = !isOn;
    document.getElementById('btn-restart').disabled = !isOn;

    // Overview KPIs
    const fmt = v => v != null ? v : '—';
    document.getElementById('ov-ram').textContent     = s.ram_used_mb != null ? s.ram_used_mb : '—';
    document.getElementById('ov-cpu').textContent     = s.cpu_percent != null ? s.cpu_percent.toFixed(1)+'%' : '—';
    document.getElementById('ov-tps').textContent     = s.tps != null ? s.tps.toFixed(1) : '—';
    document.getElementById('ov-players').textContent = s.players_online != null ? s.players_online : '—';

    // Monitoring values
    document.getElementById('mon-ram-val').innerHTML = (s.ram_used_mb ?? '—') + ' <span style="font-size:13px;color:#3a3a60;">Mo</span>';
    document.getElementById('mon-cpu-val').innerHTML = (s.cpu_percent != null ? s.cpu_percent.toFixed(1) : '—') + ' <span style="font-size:13px;color:#3a3a60;">%</span>';
    document.getElementById('mon-tps-val').innerHTML = (s.tps != null ? s.tps.toFixed(1) : '—') + ' <span style="font-size:13px;color:#3a3a60;">/ 20</span>';
    document.getElementById('mon-pl-val').innerHTML  = (s.players_online ?? '—') + ' <span style="font-size:13px;color:#3a3a60;">joueurs</span>';

    // Push to charts
    if (chartsReady) {
      pushChart(charts.ram,     s.ram_used_mb ?? null);
      pushChart(charts.cpu,     s.cpu_percent ?? null);
      pushChart(charts.tps,     s.tps         ?? null);
      pushChart(charts.players, s.players_online ?? null);
    }

    // Console: if server has new log lines
    if (s.log_lines && Array.isArray(s.log_lines)) {
      s.log_lines.forEach(line => addConsoleLine(line.level || 'INFO', line.message || ''));
    }

  } catch(e) {
    document.getElementById('sv-connection-dot').className = '';
    document.getElementById('sv-connection-label').textContent = 'Déconnecté';
  }
}

/* ══════════════════════════════════
   CONSOLE
══════════════════════════════════ */
function addConsoleLine(level, msg, time) {
  const out = document.getElementById('console-output');
  if (!out) return;

  // Trim buffer
  while (consoleBuffer.length >= MAX_LINES) {
    consoleBuffer.shift();
    if (out.firstChild) out.removeChild(out.firstChild);
    consoleLines--;
  }

  const t = time || new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const div = document.createElement('div');
  div.className = 'c-line';
  const lv = (level || 'INFO').toUpperCase();
  div.innerHTML = `<span class="c-time">${t}</span><span class="c-level ${lv}">${lv}</span><span class="c-msg ${lv}">${escHtml(msg)}</span>`;
  out.appendChild(div);
  consoleLines++;
  consoleBuffer.push({level:lv, msg, t});

  document.getElementById('console-line-count').textContent = consoleLines + ' lignes';
  if (autoScroll) out.scrollTop = out.scrollHeight;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function consoleClear() {
  document.getElementById('console-output').innerHTML = '';
  consoleLines = 0; consoleBuffer = [];
  document.getElementById('console-line-count').textContent = '0 lignes';
}

function consoleScroll() {
  const out = document.getElementById('console-output');
  out.scrollTop = out.scrollHeight;
}

function toggleAutoscroll() {
  autoScroll = !autoScroll;
  const btn = document.getElementById('btn-autoscroll');
  btn.textContent = 'Auto-scroll ' + (autoScroll ? '✓' : '✗');
  btn.style.background = autoScroll ? 'rgba(124,92,255,.15)' : 'rgba(255,255,255,.04)';
  btn.style.color = autoScroll ? '#b8a4ff' : '#4a4a80';
}

async function consoleSend() {
  const inp = document.getElementById('console-cmd');
  const cmd = inp.value.trim();
  if (!cmd) return;
  inp.value = '';
  cmdHistory.unshift(cmd); cmdHistIdx = -1;
  if (cmdHistory.length > 100) cmdHistory.pop();
  addConsoleLine('CMD', '> ' + cmd);
  try {
    const r = await fetch(API_BASE, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'command', server_id: SERVER_ID, command: cmd })
    });
    const d = await r.json();
    if (!d.ok) addConsoleLine('ERROR', 'Erreur: ' + (d.error || 'inconnue'));
  } catch(e) { addConsoleLine('ERROR', 'Erreur réseau'); }
}

let cmdHistory = [], cmdHistIdx = -1;
document.getElementById('console-cmd').addEventListener('keydown', e => {
  if (e.key === 'Enter') { consoleSend(); return; }
  if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (cmdHistIdx < cmdHistory.length - 1) cmdHistIdx++;
    e.target.value = cmdHistory[cmdHistIdx] ?? '';
  } else if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (cmdHistIdx > 0) cmdHistIdx--;
    else { cmdHistIdx = -1; e.target.value = ''; return; }
    e.target.value = cmdHistory[cmdHistIdx] ?? '';
  }
});

/* ══════════════════════════════════
   MONITORING CHARTS
══════════════════════════════════ */
let chartsReady = false;
let charts = {};

function makeChart(id, color, maxVal) {
  const ctx = document.getElementById(id)?.getContext('2d');
  if (!ctx) return null;
  return new Chart(ctx, {
    type: 'line',
    data: {
      labels: Array(MAX_POINTS).fill(''),
      datasets: [{ data: Array(MAX_POINTS).fill(null), borderColor: color, backgroundColor: color.replace('rgb','rgba').replace(')',',0.06)'), borderWidth: 2, pointRadius: 0, fill: true, tension: 0.4 }]
    },
    options: {
      animation: false, responsive: true, maintainAspectRatio: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { display: false },
        y: { min: 0, max: maxVal || undefined, grid: { color: 'rgba(255,255,255,.03)' }, ticks: { color: '#3a3a60', font: { size: 10 } } }
      }
    }
  });
}

function initCharts() {
  if (chartsReady) return;
  charts.ram     = makeChart('chart-ram', 'rgb(124,92,255)', <?= (int)($server['ram_mb'] ?? 2048) ?>);
  charts.cpu     = makeChart('chart-cpu', 'rgb(59,130,246)', 100);
  charts.tps     = makeChart('chart-tps', 'rgb(0,214,143)', 20);
  charts.players = makeChart('chart-pl',  'rgb(251,191,36)', <?= (int)($server['max_players'] ?? 20) ?>);
  chartsReady = true;
}

function pushChart(chart, value) {
  if (!chart) return;
  chart.data.datasets[0].data.push(value);
  chart.data.labels.push('');
  if (chart.data.datasets[0].data.length > MAX_POINTS) {
    chart.data.datasets[0].data.shift();
    chart.data.labels.shift();
  }
  chart.update('none');
}

/* ══════════════════════════════════
   FILE MANAGER
══════════════════════════════════ */
const FM_API = '<?= base_path() ?>/server-cms/api/server_files.php';
let fmCurrentPath = '/';

async function fmRefresh() {
  const list = document.getElementById('fm-list');
  list.innerHTML = '<div class="empty"><div class="empty-title" style="color:#3a3a60;">Chargement…</div></div>';
  document.getElementById('fm-path').textContent = fmCurrentPath;
  try {
    const url = `${FM_API}?server_id=${SERVER_ID}&path=${encodeURIComponent(fmCurrentPath)}`;
    const d = await fetch(url).then(r=>r.json());
    if (!d.ok) { list.innerHTML = `<div class="empty"><div class="empty-title">Erreur : ${escHtml(d.error||'inconnue')}</div></div>`; return; }
    if (d.mock) {
      const notice = document.createElement('div');
      notice.style.cssText = 'padding:6px 10px;background:rgba(255,190,0,.06);border-radius:6px;font-size:11px;color:#ffbe00;margin-bottom:8px;';
      notice.textContent = '⚠ Aperçu simulé — connectez un driver de provisioning pour les fichiers réels.';
      list.innerHTML = '';
      list.appendChild(notice);
      renderFmList(d.files || [], list);
    } else {
      renderFmList(d.files || [], list);
    }
  } catch(e) {
    list.innerHTML = '<div class="empty"><div class="empty-icon">⚠️</div><div class="empty-title">Fichiers inaccessibles</div><div style="font-size:11px;color:#3a3a60;margin-top:6px;">Nécessite un driver de provisioning connecté (Pterodactyl…).</div></div>';
  }
}

function renderFmList(files, container) {
  const list = container || document.getElementById('fm-list');
  if (!files.length) {
    const el = document.createElement('div');
    el.className = 'empty';
    el.innerHTML = '<div class="empty-icon">📂</div><div class="empty-title">Dossier vide</div>';
    list.appendChild(el);
    return;
  }
  // Sort: dirs first, then files alphabetically
  files.sort((a,b) => {
    const ad = a.type==='dir', bd = b.type==='dir';
    if (ad !== bd) return ad ? -1 : 1;
    return (a.name||'').localeCompare(b.name||'');
  });
  const wrap = document.createElement('div');
  wrap.style.cssText = 'display:flex;flex-direction:column;';
  files.forEach(f => {
    const isDir = f.type === 'dir';
    const ico = isDir ? '📁' : fileIcon(f.name);
    const size = isDir ? '' : (f.size_human || fmFmtSize(f.size||0));
    const mod  = f.modified ? new Date(f.modified).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
    const row = document.createElement('div');
    row.className = 'fm-entry';
    row.dataset.name = f.name;
    row.dataset.isDir = isDir ? '1' : '0';
    row.innerHTML = `
      <span class="fm-entry-icon" style="cursor:${isDir?'pointer':'default'};" onclick="${isDir?`fmNavigate('${escHtml(f.name)}')`:'void(0)'};">${ico}</span>
      <span class="fm-entry-name" onclick="${isDir?`fmNavigate('${escHtml(f.name)}')`:''}" style="cursor:${isDir?'pointer':'default'};flex:1;">${escHtml(f.name)}</span>
      <span class="fm-entry-size" style="font-size:10px;color:#2a2a50;width:70px;text-align:right;flex-shrink:0;">${size}</span>
      <span style="font-size:10px;color:#2a2a50;width:110px;text-align:right;flex-shrink:0;font-family:'JetBrains Mono',monospace;">${mod}</span>
      <div style="display:flex;gap:4px;flex-shrink:0;">
        <button onclick="fmRename('${escHtml(f.name)}')" title="Renommer" style="background:none;border:none;color:#4848a0;cursor:pointer;padding:3px 5px;border-radius:4px;font-size:12px;transition:.1s;" onmouseenter="this.style.color='#b8a4ff'" onmouseleave="this.style.color='#4848a0'">✏</button>
        <button onclick="fmDelete('${escHtml(f.name)}')" title="Supprimer" style="background:none;border:none;color:#4848a0;cursor:pointer;padding:3px 5px;border-radius:4px;font-size:12px;transition:.1s;" onmouseenter="this.style.color='#ff4d6a'" onmouseleave="this.style.color='#4848a0'">🗑</button>
      </div>`;
    wrap.appendChild(row);
  });
  list.appendChild(wrap);
}

function fileIcon(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  const map = {
    jar:'☕', zip:'📦', tar:'📦', gz:'📦',
    json:'📋', yml:'📋', yaml:'📋', toml:'📋', properties:'📋', cfg:'📋', conf:'📋',
    txt:'📝', log:'📝', md:'📝',
    png:'🖼', jpg:'🖼', jpeg:'🖼', gif:'🖼', webp:'🖼',
    sh:'⚙', bat:'⚙', py:'🐍', js:'📜',
    'class':'☕',
  };
  return map[ext] || '📄';
}

function fmFmtSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
  return (bytes/1048576).toFixed(1) + ' MB';
}

function fmNavigate(name) {
  fmCurrentPath = (fmCurrentPath === '/' ? '' : fmCurrentPath) + '/' + name;
  fmRefresh();
}

function fmUp() {
  if (fmCurrentPath === '/' || fmCurrentPath === '') return;
  const parts = fmCurrentPath.replace(/\/$/, '').split('/').filter(Boolean);
  parts.pop();
  fmCurrentPath = '/' + parts.join('/');
  if (fmCurrentPath === '') fmCurrentPath = '/';
  fmRefresh();
}

function fmUploadClick() { document.getElementById('fm-upload-input').click(); }

async function fmUpload(input) {
  const prog = document.getElementById('fm-upload-progress');
  prog.style.display = 'block';
  prog.textContent = `⏳ Upload de ${input.files.length} fichier(s)…`;
  let done = 0;
  for (const file of input.files) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('server_id', SERVER_ID);
    fd.append('path', fmCurrentPath);
    try {
      const r = await fetch(FM_API, { method: 'POST', body: fd }).then(r=>r.json());
      done++;
      prog.textContent = `⏳ ${done}/${input.files.length} uploadé(s)…`;
    } catch(e) {}
  }
  input.value = '';
  prog.style.display = 'none';
  fmRefresh();
}

async function fmDelete(name) {
  if (!confirm(`Supprimer "${name}" ? Cette action est irréversible.`)) return;
  const fullPath = (fmCurrentPath === '/' ? '' : fmCurrentPath) + '/' + name;
  const r = await fetch(FM_API, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'delete', server_id: SERVER_ID, path: fullPath })
  }).then(r=>r.json()).catch(()=>null);
  if (r?.ok) { fmRefresh(); }
  else { alert('Erreur: ' + (r?.error || 'inconnue')); }
}

async function fmRename(name) {
  const newName = prompt(`Renommer "${name}" en :`, name);
  if (!newName || newName === name) return;
  const base = fmCurrentPath === '/' ? '' : fmCurrentPath;
  const r = await fetch(FM_API, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'rename', server_id: SERVER_ID, from: base+'/'+name, to: base+'/'+newName })
  }).then(r=>r.json()).catch(()=>null);
  if (r?.ok) { fmRefresh(); }
  else { alert('Erreur: ' + (r?.error || 'inconnue')); }
}

async function fmMkdir() {
  const name = prompt('Nom du nouveau dossier :');
  if (!name) return;
  const base = fmCurrentPath === '/' ? '' : fmCurrentPath;
  const r = await fetch(FM_API, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'mkdir', server_id: SERVER_ID, path: base+'/'+name })
  }).then(r=>r.json()).catch(()=>null);
  if (r?.ok) { fmRefresh(); }
  else { alert('Erreur: ' + (r?.error || 'inconnue')); }
}

/* ══════════════════════════════════
   BACKUPS
══════════════════════════════════ */
async function loadBackups() {
  const list = document.getElementById('backup-list');
  list.innerHTML = '<div class="empty"><div class="empty-title">Chargement…</div></div>';
  try {
    const r = await fetch(API_BASE, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'list_backups', server_id: SERVER_ID })
    });
    const d = await r.json();
    const backups = d.backups || [];
    document.getElementById('backup-count').textContent = backups.length || '0';
    if (!backups.length) { list.innerHTML = '<div class="empty"><div class="empty-icon">💾</div><div class="empty-title">Aucun backup disponible</div><div style="font-size:11px;color:#3a3a60;margin-top:6px;">Créez votre premier backup pour protéger vos données.</div></div>'; return; }
    list.innerHTML = backups.map((b, i) => `
      <div class="backup-row">
        <span class="backup-icon">💾</span>
        <div class="backup-info">
          <div class="backup-name">${escHtml(b.name || 'Backup #' + (i+1))}</div>
          <div class="backup-meta">${b.created_at ? new Date(b.created_at).toLocaleString('fr-FR') : '—'} ${b.is_successful ? '' : '<span style="color:#ff4d6a;">· Échec</span>'}</div>
        </div>
        <span class="backup-size">${fmFmtSize(b.bytes || 0)}</span>
        ${b.is_successful ? `<button class="btn btn-ghost btn-sm" onclick="downloadBackup('${escHtml(b.uuid||'')}')">⬇</button>` : ''}
      </div>`).join('');
  } catch(e) {
    list.innerHTML = '<div class="empty"><div class="empty-icon">⚠️</div><div class="empty-title">Backups non disponibles</div><div style="font-size:11px;color:#3a3a60;margin-top:6px;">Nécessite un driver de provisioning connecté.</div></div>';
  }
}

async function createBackup() {
  const btn = event.target;
  btn.disabled = true; btn.textContent = 'Création…';
  try {
    const r = await fetch(API_BASE, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'backup', server_id: SERVER_ID })
    });
    const d = await r.json();
    if (d.ok) { btn.textContent = '✓ Backup lancé'; setTimeout(() => loadBackups(), 2000); }
    else { btn.textContent = 'Erreur: ' + (d.error||'inconnue'); }
  } catch(e) { btn.textContent = 'Erreur réseau'; }
  setTimeout(() => { btn.disabled = false; btn.textContent = '+ Créer un backup'; }, 4000);
}

async function downloadBackup(uuid) { alert('Téléchargement du backup ' + uuid + ' (fonctionnalité Pterodactyl)'); }

/* ══════════════════════════════════
   SETTINGS
══════════════════════════════════ */
async function saveSettings(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  // Checkboxes non cochées ne sont pas dans FormData — on les force à 0
  ['pvp','online_mode'].forEach(k => { if (!fd.has(k)) fd.set(k, '0'); });
  const data = Object.fromEntries(fd.entries());
  const msg = document.getElementById('settings-msg');
  msg.style.display = 'flex'; msg.textContent = '⏳ Sauvegarde…'; msg.style.color = '#8888c0';
  msg.style.background = 'rgba(255,255,255,.04)'; msg.style.border = 'none'; msg.style.padding = '0';
  try {
    const r = await fetch('<?= base_path() ?>/server-cms/api/server_settings.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ server_id: SERVER_ID, ...data })
    });
    const d = await r.json();
    if (d.ok) {
      msg.textContent = '✓ Paramètres sauvegardés';
      msg.style.cssText = 'display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:12px;background:rgba(0,214,143,.08);border:1px solid rgba(0,214,143,.2);color:#00d68f;';
    } else {
      msg.textContent = '✕ ' + (d.error||'Erreur inconnue');
      msg.style.cssText = 'display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:12px;background:rgba(255,77,106,.08);border:1px solid rgba(255,77,106,.2);color:#ff4d6a;';
    }
    setTimeout(() => msg.style.display = 'none', 4000);
  } catch(e) {
    msg.textContent = '✕ Erreur réseau'; msg.style.color = '#ff4d6a';
    setTimeout(() => msg.style.display = 'none', 3000);
  }
}

async function deleteServer() {
  if (!confirm('⚠ Supprimer définitivement ce serveur ? Cette action est IRRÉVERSIBLE.')) return;
  if (!confirm('Confirmez une dernière fois la suppression du serveur "<?= e(addslashes($server['server_name'] ?? '')) ?>"')) return;
  try {
    const r = await fetch(API_BASE, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'delete', server_id: SERVER_ID })
    });
    const d = await r.json();
    if (d.ok) { window.location.href = '<?= base_path() ?>/panel/servers.php'; }
    else { alert('Erreur: ' + (d.error||'inconnue')); }
  } catch(e) { alert('Erreur réseau'); }
}

/* ══════════════════════════════════
   INIT
══════════════════════════════════ */
addConsoleLine('SYS', 'XynoServer Panel — Serveur #<?= $serverId ?> chargé');
addConsoleLine('SYS', 'Connexion au serveur en cours…');
pollStatus();
setInterval(pollStatus, 5000);
</script>
</body>
</html>

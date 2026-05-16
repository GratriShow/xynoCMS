<?php
/**
 * Console live — Dashboard client XynoServer
 * Logs en temps réel (polling) + envoi de commandes RCON
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$userId   = (int)$_SESSION['user_id'];
$serverId = (int)($_GET['id'] ?? 0);

if (!$serverId) { header('Location: /server-cms/dashboard/servers.php'); exit; }

$pdo = db_connect();
$server = $pdo->prepare('SELECT s.*, p.name as plan_name, p.ram_mb FROM mc_servers s
    LEFT JOIN mc_server_plans p ON p.slug = s.plan_slug
    WHERE s.id = ? AND s.user_id = ? LIMIT 1');
$server->execute([$serverId, $userId]);
$server = $server->fetch(PDO::FETCH_ASSOC);

if (!$server) { header('Location: /server-cms/dashboard/servers.php'); exit; }
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Console — <?= htmlspecialchars($server['server_name'] ?? 'Serveur') ?> — XynoServer</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="/assets/style.css"/>
  <style>
    .console-wrap { display:grid; grid-template-rows:1fr auto; height:calc(100vh - var(--nav-h) - 80px); min-height:400px; }
    .console-log  { background:#0a0a12; border:1px solid var(--border-1); border-radius:var(--radius-md) var(--radius-md) 0 0;
                    overflow-y:auto; padding:16px; font-family:'JetBrains Mono',monospace; font-size:12.5px; line-height:1.7; }
    .console-log .line { display:flex; gap:10px; }
    .console-log .ts   { color:#444466; flex-shrink:0; }
    .console-log .lvl-INFO  { color:#60a5fa; }
    .console-log .lvl-WARN  { color:#fbbf24; }
    .console-log .lvl-ERROR { color:#f87171; }
    .console-log .lvl-CMD   { color:#a78bfa; }
    .console-log .msg        { color:#c8d6e5; word-break:break-all; }
    .console-input { display:flex; gap:0; }
    .console-input input  { flex:1; background:#0d0d1e; border:1px solid var(--border-2); border-right:0;
                            border-radius:0 0 0 var(--radius-md); padding:10px 14px; color:var(--text);
                            font-family:'JetBrains Mono',monospace; font-size:13px; outline:none; }
    .console-input input:focus { border-color:var(--accent); }
    .console-input button { background:var(--accent); color:#fff; border:none; padding:0 20px;
                            border-radius:0 0 var(--radius-md) 0; cursor:pointer; font-weight:600;
                            font-size:13px; transition:.15s; }
    .console-input button:hover { background:var(--accent-hover); }
    .stat-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .stat-chip { background:var(--surface-2); border:1px solid var(--border-1); border-radius:var(--radius-sm);
                 padding:6px 14px; font-size:12px; display:flex; align-items:center; gap:8px; }
    .stat-chip .val { font-weight:700; color:var(--accent-light); }
    .dot { width:8px; height:8px; border-radius:50%; background:var(--success); animation:pulse 2s infinite; }
    .dot.off { background:var(--danger); animation:none; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
    .btn-sm { padding:6px 14px; border-radius:var(--radius-sm); border:1px solid var(--border-2);
              background:var(--surface-2); color:var(--text); font-size:12px; cursor:pointer; transition:.15s; }
    .btn-sm:hover { background:var(--surface-3); }
    .btn-sm.danger { border-color:rgba(255,77,106,.3); color:var(--danger); }
    .btn-sm.danger:hover { background:rgba(255,77,106,.1); }
    .btn-sm.success { border-color:rgba(0,214,143,.3); color:var(--success); }
    .btn-sm.success:hover { background:rgba(0,214,143,.1); }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../_nav.php'; ?>

<div class="container" style="padding-top:24px">

  <!-- En-tête -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
      <a href="/server-cms/dashboard/manage.php?id=<?= $serverId ?>" style="color:var(--muted);font-size:13px;text-decoration:none;">
        ← Retour au serveur
      </a>
      <h1 style="margin:6px 0 0;font-size:22px;">Console live
        <span style="font-size:15px;color:var(--muted);font-weight:400;margin-left:8px;"><?= htmlspecialchars($server['server_name'] ?? '') ?></span>
      </h1>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn-sm success" id="btn-start" onclick="serverAction('start')">▶ Démarrer</button>
      <button class="btn-sm danger"  id="btn-stop"  onclick="serverAction('stop')">■ Arrêter</button>
      <button class="btn-sm"         id="btn-restart" onclick="serverAction('restart')">↺ Redémarrer</button>
      <button class="btn-sm"         onclick="clearConsole()">🗑 Vider</button>
    </div>
  </div>

  <!-- Stat bar -->
  <div class="stat-bar" id="stat-bar">
    <div class="stat-chip"><span class="dot off" id="dot-status"></span> <span id="txt-status">Chargement…</span></div>
    <div class="stat-chip">RAM <span class="val" id="txt-ram">—</span></div>
    <div class="stat-chip">CPU <span class="val" id="txt-cpu">—</span></div>
    <div class="stat-chip">TPS <span class="val" id="txt-tps">—</span></div>
    <div class="stat-chip">Joueurs <span class="val" id="txt-players">—</span></div>
    <div class="stat-chip">Uptime <span class="val" id="txt-uptime">—</span></div>
  </div>

  <!-- Console -->
  <div class="console-wrap">
    <div class="console-log" id="console-log">
      <div class="line"><span class="ts">--:--:--</span><span class="lvl-INFO">[INFO]</span><span class="msg">Console prête. Connexion au serveur en cours…</span></div>
    </div>
    <div class="console-input">
      <input type="text" id="cmd-input" placeholder="Entrez une commande Minecraft (ex: list, op Player, say Hello…)" autocomplete="off"/>
      <button onclick="sendCommand()">Envoyer</button>
    </div>
  </div>

</div>

<script>
const SERVER_ID  = <?= $serverId ?>;
const POLL_MS    = 3000;
const LOG_EL     = document.getElementById('console-log');
const CMD_INPUT  = document.getElementById('cmd-input');
let   polling    = null;
let   logBuffer  = [];
const MAX_LINES  = 300;

// ── Utils ─────────────────────────────────────────────────────────────────────
function ts() {
  const d = new Date();
  return [d.getHours(), d.getMinutes(), d.getSeconds()].map(n => String(n).padStart(2,'0')).join(':');
}

function addLine(level, msg) {
  if (logBuffer.length >= MAX_LINES) {
    logBuffer.shift();
    LOG_EL.querySelector('.line')?.remove();
  }
  const div = document.createElement('div');
  div.className = 'line';
  div.innerHTML = `<span class="ts">${ts()}</span><span class="lvl-${level}">[${level}]</span><span class="msg"> ${escHtml(msg)}</span>`;
  LOG_EL.appendChild(div);
  logBuffer.push(msg);
  LOG_EL.scrollTop = LOG_EL.scrollHeight;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function clearConsole() {
  LOG_EL.innerHTML = '';
  logBuffer = [];
  addLine('INFO', 'Console vidée.');
}

function fmtUptime(s) {
  if (!s) return '—';
  const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
  return h ? `${h}h ${m}m` : m ? `${m}m ${sec}s` : `${sec}s`;
}

// ── Statut serveur ────────────────────────────────────────────────────────────
async function refreshStatus() {
  try {
    const r = await fetch('/server-cms/api/provision_server.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'status', server_id: SERVER_ID }),
    });
    const data = await r.json();
    if (!data.ok) return;

    const s = data.status;
    const dot    = document.getElementById('dot-status');
    const txtSt  = document.getElementById('txt-status');
    const online = s.online;

    dot.className  = 'dot' + (online ? '' : ' off');
    txtSt.textContent = online ? 'En ligne' : (s.status ?? 'Hors ligne');

    document.getElementById('txt-ram').textContent =
      s.ram_used_mb ? `${s.ram_used_mb} / ${s.ram_max_mb} Mo` : '—';
    document.getElementById('txt-cpu').textContent =
      s.cpu_percent != null ? `${s.cpu_percent.toFixed(1)} %` : '—';
    document.getElementById('txt-tps').textContent =
      s.tps != null ? s.tps.toFixed(1) : '—';
    document.getElementById('txt-players').textContent =
      s.players_online != null ? `${s.players_online} / ${s.players_max}` : '—';
    document.getElementById('txt-uptime').textContent = fmtUptime(s.uptime_seconds);

  } catch (e) {
    console.warn('[console] Erreur statut :', e);
  }
}

// ── Polling des logs ──────────────────────────────────────────────────────────
// Note : En production, remplacer par un vrai WebSocket connecté à l'API
// hébergeur (Pterodactyl WebSocket ou RCON). Pour l'instant, le polling
// de /api/server_logs.php retourne les dernières lignes de log.
async function pollLogs() {
  try {
    const r = await fetch(`/server-cms/api/server_logs.php?server_id=${SERVER_ID}&since=${Date.now() - POLL_MS}`);
    if (!r.ok) return;
    const data = await r.json();
    if (Array.isArray(data.lines)) {
      data.lines.forEach(line => addLine(line.level || 'INFO', line.msg || line));
    }
  } catch (e) {
    // Silencieux — le serveur peut être hors ligne
  }
}

// ── Actions serveur ───────────────────────────────────────────────────────────
async function serverAction(action) {
  addLine('CMD', `Envoi de l'action : ${action}…`);
  try {
    const r = await fetch('/server-cms/api/provision_server.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, server_id: SERVER_ID }),
    });
    const data = await r.json();
    if (data.ok) {
      addLine('INFO', `Action "${action}" exécutée avec succès.`);
    } else {
      addLine('ERROR', `Erreur : ${data.error ?? 'Inconnue'}`);
    }
  } catch (e) {
    addLine('ERROR', `Erreur réseau : ${e.message}`);
  }
  await refreshStatus();
}

// ── Commande RCON ─────────────────────────────────────────────────────────────
async function sendCommand() {
  const cmd = CMD_INPUT.value.trim();
  if (!cmd) return;
  CMD_INPUT.value = '';
  addLine('CMD', `> ${cmd}`);
  try {
    const r = await fetch('/server-cms/api/provision_server.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'command', server_id: SERVER_ID, command: cmd }),
    });
    const data = await r.json();
    if (data.ok && data.result?.output) {
      addLine('INFO', data.result.output);
    } else if (!data.ok) {
      addLine('ERROR', data.error ?? 'Erreur inconnue');
    }
  } catch (e) {
    addLine('ERROR', `Erreur réseau : ${e.message}`);
  }
}

CMD_INPUT.addEventListener('keydown', e => { if (e.key === 'Enter') sendCommand(); });

// ── Init ─────────────────────────────────────────────────────────────────────
refreshStatus();
setInterval(refreshStatus, 5000);
setInterval(pollLogs, POLL_MS);
addLine('INFO', 'Polling des logs activé (toutes les <?= POLL_MS / 1000 ?? 3 ?>s).');
</script>
</body>
</html>

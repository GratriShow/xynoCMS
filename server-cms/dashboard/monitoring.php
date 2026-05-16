<?php
/**
 * Monitoring — Dashboard client XynoServer
 * RAM, CPU, TPS, joueurs connectés en temps réel (graphiques)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$userId   = (int)$_SESSION['user_id'];
$serverId = (int)($_GET['id'] ?? 0);
if (!$serverId) { header('Location: /server-cms/dashboard/servers.php'); exit; }

$pdo = db();
$server = $pdo->prepare('SELECT s.*, p.name as plan_name, p.ram_mb, p.max_players
    FROM mc_servers s LEFT JOIN mc_server_plans p ON p.slug = s.plan_slug
    WHERE s.id = ? AND s.user_id = ? LIMIT 1');
$server->execute([$serverId, $userId]);
$server = $server->fetch(PDO::FETCH_ASSOC);
if (!$server) { header('Location: /server-cms/dashboard/servers.php'); exit; }
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Monitoring — <?= htmlspecialchars($server['server_name'] ?? '') ?> — XynoServer</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="/assets/style.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    .monitor-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
    @media(max-width:760px) { .monitor-grid { grid-template-columns:1fr; } }
    .chart-card { background:var(--surface); border:1px solid var(--border-1); border-radius:var(--radius-lg); padding:20px; }
    .chart-card h3 { margin:0 0 4px; font-size:13px; color:var(--muted); font-weight:500; }
    .chart-card .big-val { font-size:28px; font-weight:700; color:var(--text); margin:0 0 14px; }
    .chart-card .big-val span { font-size:14px; color:var(--muted); font-weight:400; }
    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
    @media(max-width:760px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } }
    .kpi { background:var(--surface); border:1px solid var(--border-1); border-radius:var(--radius-md); padding:16px; }
    .kpi .label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
    .kpi .value { font-size:24px; font-weight:700; }
    .kpi .sub   { font-size:11px; color:var(--muted); margin-top:3px; }
    .status-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px;
                   border-radius:999px; font-size:12px; font-weight:600; }
    .status-pill.online  { background:rgba(0,214,143,.12); color:#00d68f; border:1px solid rgba(0,214,143,.25); }
    .status-pill.offline { background:rgba(255,77,106,.12); color:#ff4d6a; border:1px solid rgba(255,77,106,.25); }
    .dot-anim { width:7px; height:7px; border-radius:50%; background:currentColor; animation:pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../_nav.php'; ?>

<div class="container" style="padding-top:24px;padding-bottom:40px;">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
    <div>
      <a href="/server-cms/dashboard/manage.php?id=<?= $serverId ?>" style="color:var(--muted);font-size:13px;text-decoration:none;">← Retour</a>
      <h1 style="margin:6px 0 0;font-size:22px;">Monitoring
        <span style="font-size:15px;color:var(--muted);font-weight:400;margin-left:8px;"><?= htmlspecialchars($server['server_name'] ?? '') ?></span>
      </h1>
    </div>
    <div id="status-badge" class="status-pill offline"><span class="dot-anim"></span> Vérification…</div>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid">
    <div class="kpi">
      <div class="label">RAM utilisée</div>
      <div class="value" id="kpi-ram">—</div>
      <div class="sub" id="kpi-ram-sub">sur <?= ($server['ram_mb'] ?? 2048) ?> Mo</div>
    </div>
    <div class="kpi">
      <div class="label">CPU</div>
      <div class="value" id="kpi-cpu">—</div>
      <div class="sub">utilisation</div>
    </div>
    <div class="kpi">
      <div class="label">TPS</div>
      <div class="value" id="kpi-tps">—</div>
      <div class="sub">/ 20 ticks/s</div>
    </div>
    <div class="kpi">
      <div class="label">Joueurs</div>
      <div class="value" id="kpi-players">—</div>
      <div class="sub">/ <?= ($server['max_players'] ?? 10) ?> max</div>
    </div>
  </div>

  <!-- Graphiques -->
  <div class="monitor-grid">
    <div class="chart-card">
      <h3>RAM (Mo)</h3>
      <div class="big-val" id="chart-ram-val">— <span>Mo</span></div>
      <canvas id="chart-ram" height="120"></canvas>
    </div>
    <div class="chart-card">
      <h3>CPU (%)</h3>
      <div class="big-val" id="chart-cpu-val">— <span>%</span></div>
      <canvas id="chart-cpu" height="120"></canvas>
    </div>
    <div class="chart-card">
      <h3>TPS (Ticks/s)</h3>
      <div class="big-val" id="chart-tps-val">— <span>/ 20</span></div>
      <canvas id="chart-tps" height="120"></canvas>
    </div>
    <div class="chart-card">
      <h3>Joueurs connectés</h3>
      <div class="big-val" id="chart-pl-val">— <span>joueurs</span></div>
      <canvas id="chart-players" height="120"></canvas>
    </div>
  </div>

</div>

<script>
const SERVER_ID  = <?= $serverId ?>;
const MAX_POINTS = 30;

// ── Chart factory ─────────────────────────────────────────────────────────────
function makeChart(id, color, max) {
  const ctx = document.getElementById(id).getContext('2d');
  return new Chart(ctx, {
    type: 'line',
    data: {
      labels: Array(MAX_POINTS).fill(''),
      datasets: [{
        data: Array(MAX_POINTS).fill(null),
        borderColor: color,
        backgroundColor: color.replace(')', ', 0.08)').replace('rgb', 'rgba'),
        borderWidth: 2,
        pointRadius: 0,
        fill: true,
        tension: 0.4,
      }]
    },
    options: {
      animation: false,
      responsive: true,
      plugins: { legend: { display: false }, tooltip: { enabled: false } },
      scales: {
        x: { display: false },
        y: {
          min: 0,
          max: max || undefined,
          grid: { color: 'rgba(255,255,255,.04)' },
          ticks: { color: '#555577', font: { size: 10 } },
        },
      },
    },
  });
}

const charts = {
  ram:     makeChart('chart-ram',     'rgb(124,92,255)',   <?= $server['ram_mb'] ?? 2048 ?>),
  cpu:     makeChart('chart-cpu',     'rgb(59,130,246)',   100),
  tps:     makeChart('chart-tps',     'rgb(0,214,143)',    20),
  players: makeChart('chart-players', 'rgb(251,191,36)',   <?= $server['max_players'] ?? 10 ?>),
};

function pushPoint(chart, value) {
  chart.data.datasets[0].data.push(value);
  chart.data.labels.push('');
  if (chart.data.datasets[0].data.length > MAX_POINTS) {
    chart.data.datasets[0].data.shift();
    chart.data.labels.shift();
  }
  chart.update('none');
}

// ── Polling ───────────────────────────────────────────────────────────────────
async function poll() {
  try {
    const r = await fetch('/server-cms/api/provision_server.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'status', server_id: SERVER_ID }),
    });
    const data = await r.json();
    if (!data.ok) return;
    const s = data.status;

    // Badge
    const badge = document.getElementById('status-badge');
    badge.className = 'status-pill ' + (s.online ? 'online' : 'offline');
    badge.innerHTML = `<span class="dot-anim"></span> ${s.online ? 'En ligne' : 'Hors ligne'}`;

    // KPIs
    document.getElementById('kpi-ram').textContent     = s.ram_used_mb != null ? s.ram_used_mb + ' Mo' : '—';
    document.getElementById('kpi-cpu').textContent     = s.cpu_percent != null ? s.cpu_percent.toFixed(1) + ' %' : '—';
    document.getElementById('kpi-tps').textContent     = s.tps != null ? s.tps.toFixed(1) : '—';
    document.getElementById('kpi-players').textContent = s.players_online != null ? s.players_online : '—';

    // Chart values
    document.getElementById('chart-ram-val').innerHTML  = `${s.ram_used_mb ?? '—'} <span>Mo</span>`;
    document.getElementById('chart-cpu-val').innerHTML  = `${s.cpu_percent != null ? s.cpu_percent.toFixed(1) : '—'} <span>%</span>`;
    document.getElementById('chart-tps-val').innerHTML  = `${s.tps != null ? s.tps.toFixed(1) : '—'} <span>/ 20</span>`;
    document.getElementById('chart-pl-val').innerHTML   = `${s.players_online ?? '—'} <span>joueurs</span>`;

    // Push to charts
    pushPoint(charts.ram,     s.ram_used_mb ?? null);
    pushPoint(charts.cpu,     s.cpu_percent ?? null);
    pushPoint(charts.tps,     s.tps         ?? null);
    pushPoint(charts.players, s.players_online ?? null);

  } catch(e) { console.warn('[monitoring] poll error:', e); }
}

poll();
setInterval(poll, 5000);
</script>
</body>
</html>

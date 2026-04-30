<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$q      = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? 'all'); // all | online | offline | with_sub

// Online window : un launcher est considéré "en ligne" s'il a envoyé un
// heartbeat dans les 90 dernières secondes (tolérance 3× le tick standard).
$onlineSeconds = 90;

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(l.name LIKE ? OR l.uuid LIKE ? OR u.email LIKE ?)';
    $args[]  = '%' . $q . '%';
    $args[]  = '%' . $q . '%';
    $args[]  = '%' . $q . '%';
}
if ($filter === 'online')   { $where[] = '(l.last_seen_at IS NOT NULL AND l.last_seen_at >= NOW() - INTERVAL ' . $onlineSeconds . ' SECOND)'; }
if ($filter === 'offline')  { $where[] = '(l.last_seen_at IS NULL OR l.last_seen_at < NOW() - INTERVAL ' . $onlineSeconds . ' SECOND)'; }
if ($filter === 'with_sub') { $where[] = '(EXISTS (SELECT 1 FROM subscriptions s WHERE s.launcher_id = l.id AND s.status = \'active\'))'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$counts = ['total' => 0, 'online' => 0, 'offline' => 0, 'with_sub' => 0];

try {
    // Totaux pour les pills.
    $counts['total']    = (int)$pdo->query('SELECT COUNT(*) FROM launchers')->fetchColumn();
    $counts['online']   = (int)$pdo->query('SELECT COUNT(*) FROM launchers WHERE last_seen_at >= NOW() - INTERVAL ' . $onlineSeconds . ' SECOND')->fetchColumn();
    $counts['offline']  = $counts['total'] - $counts['online'];
    $counts['with_sub'] = (int)$pdo->query("SELECT COUNT(DISTINCT launcher_id) FROM subscriptions WHERE status = 'active'")->fetchColumn();

    $sql = "SELECT l.id, l.uuid, l.name, l.version, l.loader, l.theme, l.created_at, "
         . "       l.last_seen_at, l.last_app_version, l.last_os, "
         . "       u.id AS user_id, u.email AS user_email, "
         . "       (SELECT COUNT(*) FROM launcher_heartbeats hb "
         . "          WHERE hb.launcher_id = l.id AND hb.created_at >= NOW() - INTERVAL 1 DAY) AS hb_24h, "
         . "       (SELECT AVG(hb.tick_rate_ms) FROM launcher_heartbeats hb "
         . "          WHERE hb.launcher_id = l.id AND hb.created_at >= NOW() - INTERVAL 1 DAY "
         . "            AND hb.tick_rate_ms IS NOT NULL AND hb.tick_rate_ms < 120000) AS tick_avg, "
         . "       (SELECT s.status FROM subscriptions s WHERE s.launcher_id = l.id "
         . "          ORDER BY (s.status = 'active') DESC, s.created_at DESC LIMIT 1) AS sub_status "
         . "FROM launchers l "
         . "LEFT JOIN users u ON u.id = l.user_id "
         . "$whereSql "
         . "ORDER BY (l.last_seen_at IS NOT NULL) DESC, l.last_seen_at DESC, l.created_at DESC "
         . "LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('error', 'Migration v6 manquante : importe migrations_v6.sql.');
}

function osIcon(string $os): string
{
    $os = strtolower($os);
    if (str_contains($os, 'darwin') || str_contains($os, 'mac')) return '🍎';
    if (str_contains($os, 'win'))                                  return '🪟';
    if (str_contains($os, 'linux'))                                return '🐧';
    return '·';
}

function relativeTime(?string $datetime): string
{
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 0)        return 'à l\'instant';
    if ($diff < 60)       return $diff . 's';
    if ($diff < 3600)     return floor($diff / 60) . 'min';
    if ($diff < 86400)    return floor($diff / 3600) . 'h';
    if ($diff < 86400*30) return floor($diff / 86400) . 'j';
    return date('d/m/y', strtotime($datetime));
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Launchers · Admin · XynoLauncher</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:16px}
    .stat{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px}
    .stat .lbl{color:#8a8aa0;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
    .stat .val{font-size:22px;font-weight:700;margin-top:4px;color:#fff}
    .admin-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .admin-table tr:hover{background:rgba(255,255,255,.02)}
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
    .filter-bar input{flex:1;min-width:240px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);
      color:#fff;padding:10px 12px;border-radius:10px;font-size:14px}
    .filter-bar a{padding:8px 12px;border-radius:999px;font-size:13px;text-decoration:none;color:#c4b5fd;
      background:rgba(124,58,237,.10);border:1px solid rgba(124,58,237,.25)}
    .filter-bar a.active{background:#7c3aed;color:#fff;border-color:#7c3aed}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-online{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-offline{background:rgba(255,255,255,.05);color:#8a8aa0;border:1px solid rgba(255,255,255,.1)}
    .pill-active{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-pending{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-cancelled{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
    .dot-on{background:#34d399;box-shadow:0 0 8px rgba(52,211,153,.6)}
    .dot-off{background:#475569}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#8a8aa0}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('launchers'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge">Admin</p>
        <h1 class="section-title" style="margin:10px 0 0">Launchers (<?php echo (int)$counts['total']; ?>)</h1>
        <p class="section-desc" style="margin-top:8px">État live des launchers déployés. Heartbeats reçus toutes les ~30 secondes.</p>

        <div class="stat-grid">
          <div class="stat"><div class="lbl">Total</div><div class="val"><?php echo (int)$counts['total']; ?></div></div>
          <div class="stat"><div class="lbl">En ligne</div><div class="val" style="color:#34d399"><?php echo (int)$counts['online']; ?></div></div>
          <div class="stat"><div class="lbl">Hors ligne</div><div class="val" style="color:#8a8aa0"><?php echo (int)$counts['offline']; ?></div></div>
          <div class="stat"><div class="lbl">Avec abo actif</div><div class="val" style="color:#a78bfa"><?php echo (int)$counts['with_sub']; ?></div></div>
        </div>

        <form method="get" action="/admin/launchers.php" class="filter-bar">
          <input type="hidden" name="filter" value="<?php echo e($filter); ?>" />
          <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Rechercher par nom, UUID, email client..." autocomplete="off" />
          <button class="btn btn-primary" type="submit">Filtrer</button>
        </form>

        <div class="filter-bar" style="margin-top:8px">
          <a href="/admin/launchers.php?q=<?php echo urlencode($q); ?>&filter=all"      class="<?php echo $filter==='all'?'active':''; ?>">Tous</a>
          <a href="/admin/launchers.php?q=<?php echo urlencode($q); ?>&filter=online"   class="<?php echo $filter==='online'?'active':''; ?>">En ligne</a>
          <a href="/admin/launchers.php?q=<?php echo urlencode($q); ?>&filter=offline"  class="<?php echo $filter==='offline'?'active':''; ?>">Hors ligne</a>
          <a href="/admin/launchers.php?q=<?php echo urlencode($q); ?>&filter=with_sub" class="<?php echo $filter==='with_sub'?'active':''; ?>">Avec abo actif</a>
        </div>

        <table class="admin-table">
          <thead>
            <tr>
              <th>Statut</th>
              <th>Launcher</th>
              <th>Client</th>
              <th>Version</th>
              <th>OS</th>
              <th>Tick avg 24h</th>
              <th>Pings 24h</th>
              <th>Vu</th>
              <th>Abo</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $online = !empty($r['last_seen_at']) && (time() - strtotime((string)$r['last_seen_at'])) <= $onlineSeconds;
                $tickAvg = isset($r['tick_avg']) ? (int)round((float)$r['tick_avg'] / 1000) : 0;
                $sub = strtolower((string)($r['sub_status'] ?? ''));
                $subCls = '';
                if ($sub === 'active') $subCls = 'pill-active';
                elseif ($sub === 'pending') $subCls = 'pill-pending';
                elseif (in_array($sub, ['cancelled','expired','past_due'], true)) $subCls = 'pill-cancelled';
              ?>
              <tr>
                <td>
                  <?php if ($online): ?>
                    <span class="dot dot-on"></span><span class="pill pill-online">Online</span>
                  <?php else: ?>
                    <span class="dot dot-off"></span><span class="pill pill-offline">Offline</span>
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?php echo e((string)($r['name'] ?: '(sans nom)')); ?></strong><br>
                  <span class="mono"><?php echo e(substr((string)$r['uuid'], 0, 12)); ?>…</span>
                </td>
                <td>
                  <?php if (!empty($r['user_email'])): ?>
                    <a href="/admin/user.php?id=<?php echo (int)$r['user_id']; ?>" style="color:#a78bfa;text-decoration:none"><?php echo e((string)$r['user_email']); ?></a>
                  <?php else: ?>
                    <span style="color:#8a8aa0">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php $v = (string)($r['last_app_version'] ?: $r['version']); ?>
                  <?php echo $v !== '' ? e($v) : '<span style="color:#8a8aa0">—</span>'; ?>
                </td>
                <td>
                  <?php $os = (string)($r['last_os'] ?? ''); ?>
                  <?php echo $os !== '' ? osIcon($os) . ' ' . e($os) : '<span style="color:#8a8aa0">—</span>'; ?>
                </td>
                <td>
                  <?php if ($tickAvg > 0): ?>
                    <strong><?php echo $tickAvg; ?>s</strong>
                  <?php else: ?>
                    <span style="color:#8a8aa0">—</span>
                  <?php endif; ?>
                </td>
                <td><?php echo (int)($r['hb_24h'] ?? 0); ?></td>
                <td><?php echo e(relativeTime((string)($r['last_seen_at'] ?? ''))); ?></td>
                <td>
                  <?php if ($sub !== ''): ?>
                    <span class="pill <?php echo $subCls; ?>"><?php echo e($sub); ?></span>
                  <?php else: ?>
                    <span style="color:#8a8aa0">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['user_id'])): ?>
                    <a class="btn btn-ghost" style="padding:4px 10px;font-size:12px" href="/admin/user.php?id=<?php echo (int)$r['user_id']; ?>">Détail →</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="10" style="color:#8a8aa0;padding:20px">Aucun launcher trouvé.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

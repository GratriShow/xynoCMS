<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

// Stats globales -------------------------------------------------------------
$stats = [
    'users_total'       => 0,
    'users_30d'         => 0,
    'users_deleted'     => 0,
    'launchers_total'   => 0,
    'launchers_online'  => 0,
    'subs_active'       => 0,
    'subs_pending'      => 0,
    'subs_past_due'     => 0,
    'subs_cancelled'    => 0,
    'mrr_cents'         => 0,
    'emails_sent_24h'   => 0,
    'heartbeats_24h'    => 0,
];

try {
    $stats['users_total']    = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['users_30d']      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
    try { $stats['users_deleted'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL')->fetchColumn(); } catch (Throwable $e) {}
    $stats['launchers_total'] = (int)$pdo->query('SELECT COUNT(*) FROM launchers')->fetchColumn();
    $stats['subs_active']     = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn();
    $stats['subs_pending']    = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'pending'")->fetchColumn();
    $stats['subs_past_due']   = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'past_due'")->fetchColumn();
    $stats['subs_cancelled']  = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('cancelled','expired')")->fetchColumn();
    $stats['mrr_cents']       = (int)$pdo->query("SELECT COALESCE(SUM(amount_cents), 0) FROM subscriptions WHERE status = 'active' AND period = 'monthly'")->fetchColumn();
    try { $stats['emails_sent_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE status = 'sent' AND created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['launchers_online'] = (int)$pdo->query("SELECT COUNT(*) FROM launchers WHERE last_seen_at >= NOW() - INTERVAL 90 SECOND")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['heartbeats_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM launcher_heartbeats WHERE created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn(); } catch (Throwable $e) {}
} catch (Throwable $e) { /* tolérer schémas anciens */ }

// Derniers signups
$recentUsers = [];
try {
    $st = $pdo->query("SELECT id, email, created_at, last_login_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 8");
    $recentUsers = $st->fetchAll();
} catch (Throwable $e) {
    $st = $pdo->query("SELECT id, email, created_at FROM users ORDER BY created_at DESC LIMIT 8");
    $recentUsers = $st->fetchAll();
}

// Derniers abonnements actifs
$recentSubs = [];
try {
    $st = $pdo->query(
        "SELECT s.id, s.plan, s.period, s.status, s.amount_cents, s.created_at, "
      . "       u.email AS user_email, l.name AS launcher_name "
      . "FROM subscriptions s "
      . "LEFT JOIN users u ON u.id = s.user_id "
      . "LEFT JOIN launchers l ON l.id = s.launcher_id "
      . "ORDER BY s.created_at DESC LIMIT 8"
    );
    $recentSubs = $st->fetchAll();
} catch (Throwable $e) {}

$success = flash_get('success');
$error   = flash_get('error');
$mrr = number_format($stats['mrr_cents'] / 100, 2, ',', ' ');

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin · XynoLauncher</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:16px}
    .stat{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px}
    .stat .lbl{color:#8a8aa0;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
    .stat .val{font-size:24px;font-weight:700;margin-top:4px;color:#fff}
    .admin-table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .admin-table tr:hover{background:rgba(255,255,255,.02)}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-active{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-pending{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-cancelled{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    .pill-other{background:rgba(124,58,237,.15);color:#c4b5fd;border:1px solid rgba(124,58,237,.3)}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('dashboard'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge">Admin</p>
        <h1 class="section-title" style="margin:10px 0 0">Vue d'ensemble</h1>
        <p class="section-desc" style="margin-top:8px">Statistiques temps réel et derniers événements de la plateforme.</p>

        <?php if ($success): ?><div class="notice" data-show="true" style="margin:12px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($error); ?></div><?php endif; ?>

        <div class="stat-grid">
          <div class="stat"><div class="lbl">Utilisateurs</div><div class="val"><?php echo (int)$stats['users_total']; ?></div><div class="lbl" style="margin-top:6px">+<?php echo (int)$stats['users_30d']; ?> les 30 derniers jours</div></div>
          <div class="stat"><div class="lbl">Abonnements actifs</div><div class="val" style="color:#34d399"><?php echo (int)$stats['subs_active']; ?></div><div class="lbl" style="margin-top:6px"><?php echo (int)$stats['subs_pending']; ?> pending · <?php echo (int)$stats['subs_past_due']; ?> past due</div></div>
          <div class="stat"><div class="lbl">MRR estimé (mensuels)</div><div class="val"><?php echo e($mrr); ?> €</div><div class="lbl" style="margin-top:6px">Stripe (subscriptions actives, plan monthly)</div></div>
          <div class="stat"><div class="lbl">Launchers</div><div class="val"><?php echo (int)$stats['launchers_total']; ?></div><div class="lbl" style="margin-top:6px"><span style="color:#34d399">●</span> <?php echo (int)$stats['launchers_online']; ?> en ligne</div></div>
          <div class="stat"><div class="lbl">Heartbeats 24h</div><div class="val"><?php echo (int)$stats['heartbeats_24h']; ?></div><div class="lbl" style="margin-top:6px">Pings reçus depuis les launchers</div></div>
          <div class="stat"><div class="lbl">Comptes supprimés (RGPD)</div><div class="val"><?php echo (int)$stats['users_deleted']; ?></div><div class="lbl" style="margin-top:6px">Soft-delete, fenêtre 30j</div></div>
          <div class="stat"><div class="lbl">Emails 24h</div><div class="val"><?php echo (int)$stats['emails_sent_24h']; ?></div></div>
        </div>

        <article class="card" style="margin-top:24px">
          <h2 style="margin:0 0 6px;font-size:16px">Nouveaux comptes</h2>
          <table class="admin-table">
            <thead><tr><th>Email</th><th>Inscription</th><th>Dernière connexion</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($recentUsers as $u): ?>
                <tr>
                  <td><?php echo e((string)$u['email']); ?></td>
                  <td><?php echo e(date('d/m/Y H:i', strtotime((string)$u['created_at']))); ?></td>
                  <td><?php echo !empty($u['last_login_at']) ? e(date('d/m/Y H:i', strtotime((string)$u['last_login_at']))) : '<span style="color:#8a8aa0">—</span>'; ?></td>
                  <td><a class="btn btn-ghost" style="padding:4px 10px;font-size:12px" href="/admin/user.php?id=<?php echo (int)$u['id']; ?>">Détail</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($recentUsers)): ?><tr><td colspan="4" style="color:#8a8aa0">Aucun utilisateur.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </article>

        <article class="card" style="margin-top:18px">
          <h2 style="margin:0 0 6px;font-size:16px">Derniers abonnements</h2>
          <table class="admin-table">
            <thead><tr><th>Email</th><th>Launcher</th><th>Plan</th><th>Status</th><th>Montant</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($recentSubs as $s): ?>
                <?php
                  $status = strtolower((string)($s['status'] ?? ''));
                  $cls = 'pill-other';
                  if ($status === 'active')   $cls = 'pill-active';
                  elseif ($status === 'pending') $cls = 'pill-pending';
                  elseif (in_array($status, ['cancelled','expired','past_due'], true)) $cls = 'pill-cancelled';
                  $amount = number_format(((int)($s['amount_cents'] ?? 0)) / 100, 2, ',', ' ');
                ?>
                <tr>
                  <td><?php echo e((string)($s['user_email'] ?? '')); ?></td>
                  <td><?php echo e((string)($s['launcher_name'] ?? '—')); ?></td>
                  <td><?php echo e(ucfirst((string)($s['plan'] ?? ''))); ?> · <?php echo e((string)($s['period'] ?? '')); ?></td>
                  <td><span class="pill <?php echo $cls; ?>"><?php echo e($status); ?></span></td>
                  <td><?php echo e($amount); ?> €</td>
                  <td><?php echo e(date('d/m H:i', strtotime((string)$s['created_at']))); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($recentSubs)): ?><tr><td colspan="6" style="color:#8a8aa0">Aucun abonnement.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </article>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

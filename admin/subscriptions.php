<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$status = (string)($_GET['status'] ?? 'all');
$where  = '';
$args   = [];
if (in_array($status, ['active','pending','past_due','cancelled','expired'], true)) {
    $where = "WHERE s.status = ?";
    $args[] = $status;
}

$rows = [];
try {
    $sql = "SELECT s.id, s.plan, s.period, s.status, s.amount_cents, s.currency, s.expires_at, s.next_billing_at, "
         . "       s.cancelled_at, s.stripe_subscription_id, s.created_at, "
         . "       u.id AS user_id, u.email AS user_email, l.name AS launcher_name, l.uuid AS launcher_uuid "
         . "FROM subscriptions s "
         . "LEFT JOIN users u ON u.id = s.user_id "
         . "LEFT JOIN launchers l ON l.id = s.launcher_id "
         . "$where "
         . "ORDER BY (s.status = 'active') DESC, s.created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
} catch (Throwable $e) {}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Abonnements · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .admin-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
    .filter-bar a{padding:8px 12px;border-radius:999px;font-size:13px;text-decoration:none;color:#c4b5fd;
      background:rgba(124,58,237,.10);border:1px solid rgba(124,58,237,.25)}
    .filter-bar a.active{background:#7c3aed;color:#fff;border-color:#7c3aed}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-active{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-pending{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-cancelled{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    .pill-other{background:rgba(124,58,237,.15);color:#c4b5fd;border:1px solid rgba(124,58,237,.3)}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('subscriptions'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge">Admin</p>
        <h1 class="section-title" style="margin:10px 0 0">Abonnements (<?php echo count($rows); ?>)</h1>

        <div class="filter-bar">
          <?php $tabs = ['all'=>'Tous','active'=>'Actifs','pending'=>'Pending','past_due'=>'Past due','cancelled'=>'Cancelled','expired'=>'Expirés']; ?>
          <?php foreach ($tabs as $k => $lbl): ?>
            <a href="/admin/subscriptions.php?status=<?php echo urlencode($k); ?>" class="<?php echo $status===$k?'active':''; ?>"><?php echo e($lbl); ?></a>
          <?php endforeach; ?>
        </div>

        <table class="admin-table">
          <thead><tr><th>Client</th><th>Launcher</th><th>Plan</th><th>Status</th><th>Montant</th><th>Expire</th><th>Stripe</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $st = strtolower((string)$r['status']);
                $cls = 'pill-other';
                if ($st === 'active')   $cls = 'pill-active';
                elseif ($st === 'pending') $cls = 'pill-pending';
                elseif (in_array($st, ['cancelled','expired','past_due'], true)) $cls = 'pill-cancelled';
                $amount = number_format(((int)$r['amount_cents']) / 100, 2, ',', ' ');
              ?>
              <tr>
                <td><a href="/admin/user.php?id=<?php echo (int)$r['user_id']; ?>" style="color:#a78bfa;text-decoration:none"><?php echo e((string)$r['user_email']); ?></a></td>
                <td><?php echo e((string)($r['launcher_name'] ?? '—')); ?></td>
                <td><?php echo e(ucfirst((string)$r['plan'])); ?> · <?php echo e((string)$r['period']); ?></td>
                <td><span class="pill <?php echo $cls; ?>"><?php echo e($st); ?></span></td>
                <td><?php echo e($amount); ?> <?php echo e(strtoupper((string)$r['currency'])); ?></td>
                <td><?php echo !empty($r['expires_at']) ? e(date('d/m/Y', strtotime((string)$r['expires_at']))) : '—'; ?></td>
                <td><?php if (!empty($r['stripe_subscription_id'])): ?><a target="_blank" rel="noopener" href="https://dashboard.stripe.com/test/subscriptions/<?php echo e((string)$r['stripe_subscription_id']); ?>" style="color:#a78bfa;font-size:12px;font-family:monospace"><?php echo e(substr((string)$r['stripe_subscription_id'], 0, 12)); ?>…</a><?php else: ?>—<?php endif; ?></td>
                <td><a class="btn btn-ghost" style="padding:4px 10px;font-size:12px" href="/admin/subscription.php?id=<?php echo (int)$r['id']; ?>">Détail →</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" style="color:#8a8aa0;padding:20px">Aucun abonnement.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

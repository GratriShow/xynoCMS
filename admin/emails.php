<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$status = (string)($_GET['status'] ?? 'all');
$where  = '';
$args   = [];
if (in_array($status, ['sent','failed','queued'], true)) {
    $where = 'WHERE status = ?';
    $args[] = $status;
}

$logs = [];
try {
    $sql = "SELECT id, user_id, to_email, subject, template, status, error, created_at "
         . "FROM email_log $where ORDER BY created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $logs = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('error', 'Migration v5 manquante (email_log).');
}

$counts = ['sent' => 0, 'failed' => 0, 'queued' => 0];
try {
    $cs = $pdo->query("SELECT status, COUNT(*) AS c FROM email_log GROUP BY status");
    foreach ($cs as $row) {
        $counts[(string)$row['status']] = (int)$row['c'];
    }
} catch (Throwable $e) {}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Logs emails · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/style.css" />
  <script src="../assets/main.js" defer></script>
  <style>
    .admin-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
    .filter-bar a{padding:8px 12px;border-radius:999px;font-size:13px;text-decoration:none;color:#c4b5fd;
      background:rgba(124,58,237,.10);border:1px solid rgba(124,58,237,.25)}
    .filter-bar a.active{background:#7c3aed;color:#fff;border-color:#7c3aed}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-sent{background:rgba(16,185,129,.18);color:#34d399}
    .pill-failed{background:rgba(239,68,68,.18);color:#fca5a5}
    .pill-queued{background:rgba(234,179,8,.18);color:#fbbf24}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('emails'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge">Admin</p>
        <h1 class="section-title" style="margin:10px 0 0">Logs emails (<?php echo count($logs); ?>)</h1>
        <p class="section-desc" style="margin-top:8px">
          <?php echo (int)$counts['sent']; ?> envoyés · <?php echo (int)$counts['failed']; ?> échecs · <?php echo (int)$counts['queued']; ?> en queue
        </p>

        <div class="filter-bar">
          <?php $tabs = ['all'=>'Tous','sent'=>'Envoyés','failed'=>'Échecs','queued'=>'En queue']; ?>
          <?php foreach ($tabs as $k => $lbl): ?>
            <a href="emails.php?status=<?php echo urlencode($k); ?>" class="<?php echo $status===$k?'active':''; ?>"><?php echo e($lbl); ?></a>
          <?php endforeach; ?>
        </div>

        <table class="admin-table">
          <thead><tr><th>Date</th><th>Destinataire</th><th>Sujet</th><th>Template</th><th>Status</th><th>Erreur</th></tr></thead>
          <tbody>
            <?php foreach ($logs as $l): ?>
              <?php $cls = 'pill-queued'; if ($l['status'] === 'sent') $cls = 'pill-sent'; elseif ($l['status'] === 'failed') $cls = 'pill-failed'; ?>
              <tr>
                <td><?php echo e(date('d/m H:i', strtotime((string)$l['created_at']))); ?></td>
                <td>
                  <?php if (!empty($l['user_id'])): ?>
                    <a href="user.php?id=<?php echo (int)$l['user_id']; ?>" style="color:#a78bfa;text-decoration:none"><?php echo e((string)$l['to_email']); ?></a>
                  <?php else: ?>
                    <?php echo e((string)$l['to_email']); ?>
                  <?php endif; ?>
                </td>
                <td><?php echo e((string)$l['subject']); ?></td>
                <td><code style="color:#8a8aa0;font-size:12px"><?php echo e((string)$l['template']); ?></code></td>
                <td><span class="pill <?php echo $cls; ?>"><?php echo e((string)$l['status']); ?></span></td>
                <td style="color:#fca5a5;font-size:12px"><?php echo !empty($l['error']) ? e(substr((string)$l['error'], 0, 120)) : ''; ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="6" style="color:#8a8aa0;padding:20px">Aucun email loggé.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

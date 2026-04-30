<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$giftId = (int)($_GET['id'] ?? 0);
if ($giftId <= 0) redirect('gifts.php');

try {
    $st = $pdo->prepare(
        "SELECT g.id, g.type, g.description, g.value, g.single_code, g.code, g.expires_at, g.created_at, g.created_by, u.email as created_by_email "
      . "FROM gifts g "
      . "LEFT JOIN users u ON u.id = g.created_by "
      . "WHERE g.id = ? LIMIT 1"
    );
    $st->execute([$giftId]);
    $gift = $st->fetch();
} catch (Throwable $e) {
    $gift = null;
}

if (!$gift) {
    flash_set('error', 'Cadeau introuvable.');
    redirect('gifts.php');
}

// Fetch codes if not single code
$codes = [];
if (!(int)$gift['single_code']) {
    try {
        $st = $pdo->prepare(
            "SELECT id, code, redeemed_by, redeemed_at, created_at FROM gift_codes WHERE gift_id = ? ORDER BY created_at DESC"
        );
        $st->execute([$giftId]);
        $codes = $st->fetchAll();
    } catch (Throwable $e) {}
}

// Fetch recipients
$recipients = [];
try {
    $st = $pdo->prepare(
        "SELECT id, user_id, email, code, sent_at, redeemed_at FROM gift_recipients WHERE gift_id = ? ORDER BY sent_at DESC"
    );
    $st->execute([$giftId]);
    $recipients = $st->fetchAll();
} catch (Throwable $e) {}

// Fetch audit log
$audit = [];
try {
    $st = $pdo->prepare(
        "SELECT id, admin_id, action, details, created_at FROM gift_audit_log WHERE gift_id = ? ORDER BY created_at DESC LIMIT 50"
    );
    $st->execute([$giftId]);
    $audit = $st->fetchAll();
} catch (Throwable $e) {}

$success = flash_get('success');
$error   = flash_get('error');
$is_expired = strtotime((string)$gift['expires_at']) < time();

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cadeau #<?php echo (int)$gift['id']; ?> · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .admin-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-coupon{background:rgba(59,130,246,.18);color:#60a5fa;border:1px solid rgba(59,130,246,.3)}
    .pill-credit{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-redeemed{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-pending{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-expired{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    code.code-block{display:block;background:rgba(255,255,255,.05);padding:8px 12px;border-radius:6px;font-family:monospace;font-size:13px;margin:6px 0;word-break:break-all}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('gifts'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:960px">
        <p class="badge"><a href="/admin/gifts.php" style="color:#a78bfa">← Cadeaux</a></p>
        <h1 class="section-title" style="margin:10px 0 0"><?php echo e((string)$gift['description']); ?></h1>

        <?php if ($success): ?><div class="notice" data-show="true" style="margin:12px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($error); ?></div><?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">
          <a class="btn btn-primary" href="/admin/gift_send.php?id=<?php echo (int)$gift['id']; ?>">📧 Envoyer ce cadeau</a>
          <form method="post" action="/admin/gift_actions.php" style="display:inline" onsubmit="return confirm('Supprimer ce cadeau et tout son historique ?');">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="gift_id" value="<?php echo (int)$gift['id']; ?>" />
            <button class="btn" style="background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3);color:#fca5a5">Supprimer</button>
          </form>
        </div>

        <!-- Gift Info -->
        <article class="card" style="margin-top:8px">
          <h2 style="margin:0 0 6px;font-size:16px">Informations</h2>
          <dl class="dlist">
            <div><dt>Type</dt><dd><span class="pill <?php echo $gift['type'] === 'coupon' ? 'pill-coupon' : 'pill-credit'; ?>"><?php echo ucfirst(e((string)$gift['type'])); ?></span></dd></div>
            <div><dt>Valeur</dt><dd><?php echo (int)$gift['value']; ?><?php echo $gift['type'] === 'coupon' ? '%' : ' jours'; ?></dd></div>
            <div><dt>Description</dt><dd><?php echo e((string)$gift['description']); ?></dd></div>
            <div><dt>Code</dt><dd>
              <?php if ((int)$gift['single_code']): ?>
                Code unique : <code class="code-block"><?php echo e((string)$gift['code']); ?></code>
              <?php else: ?>
                Codes uniques générés par utilisateur
              <?php endif; ?>
            </dd></div>
            <div><dt>Expire le</dt><dd><?php echo e(date('d/m/Y à H:i', strtotime((string)$gift['expires_at']))); ?> <?php echo $is_expired ? '<span class="pill pill-expired">Expiré</span>' : '<span class="pill pill-pending">Actif</span>'; ?></dd></div>
            <div><dt>Créé par</dt><dd><?php echo e((string)$gift['created_by_email']); ?> le <?php echo e(date('d/m/Y H:i', strtotime((string)$gift['created_at']))); ?></dd></div>
          </dl>
        </article>

        <!-- Codes (if not single code) -->
        <?php if (!(int)$gift['single_code'] && !empty($codes)): ?>
          <article class="card" style="margin-top:14px">
            <h2 style="margin:0 0 6px;font-size:16px">Codes générés (<?php echo count($codes); ?>)</h2>
            <table class="admin-table">
              <thead><tr><th>Code</th><th>Statut</th><th>Rédemption</th></tr></thead>
              <tbody>
                <?php foreach ($codes as $c): ?>
                  <tr>
                    <td><code style="color:#8a8aa0;font-family:monospace"><?php echo e((string)$c['code']); ?></code></td>
                    <td><span class="pill <?php echo $c['redeemed_at'] ? 'pill-redeemed' : 'pill-pending'; ?>"><?php echo $c['redeemed_at'] ? 'Utilisé' : 'En attente'; ?></span></td>
                    <td style="font-size:12px;color:#8a8aa0"><?php echo $c['redeemed_at'] ? e(date('d/m/Y H:i', strtotime((string)$c['redeemed_at']))) : '—'; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </article>
        <?php endif; ?>

        <!-- Recipients -->
        <?php if (!empty($recipients)): ?>
          <article class="card" style="margin-top:14px">
            <h2 style="margin:0 0 6px;font-size:16px">Destinataires (<?php echo count($recipients); ?>)</h2>
            <table class="admin-table">
              <thead><tr><th>Email</th><th>Envoyé le</th><th>Statut</th><th>Rédemption</th></tr></thead>
              <tbody>
                <?php foreach ($recipients as $r): ?>
                  <tr>
                    <td><?php echo e((string)$r['email']); ?></td>
                    <td style="font-size:12px;color:#8a8aa0"><?php echo $r['sent_at'] ? e(date('d/m/Y H:i', strtotime((string)$r['sent_at']))) : '—'; ?></td>
                    <td><span class="pill <?php echo $r['redeemed_at'] ? 'pill-redeemed' : 'pill-pending'; ?>"><?php echo $r['redeemed_at'] ? 'Utilisé' : 'En attente'; ?></span></td>
                    <td style="font-size:12px;color:#8a8aa0"><?php echo $r['redeemed_at'] ? e(date('d/m/Y H:i', strtotime((string)$r['redeemed_at']))) : '—'; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </article>
        <?php endif; ?>

        <!-- Audit Log -->
        <?php if (!empty($audit)): ?>
          <article class="card" style="margin-top:14px">
            <h2 style="margin:0 0 6px;font-size:16px">Historique (<?php echo count($audit); ?>)</h2>
            <table class="admin-table">
              <thead><tr><th>Date</th><th>Action</th><th>Détails</th></tr></thead>
              <tbody>
                <?php foreach ($audit as $a): ?>
                  <tr>
                    <td style="font-size:12px;color:#8a8aa0"><?php echo e(date('d/m/Y H:i', strtotime((string)$a['created_at']))); ?></td>
                    <td><strong><?php echo e((string)$a['action']); ?></strong></td>
                    <td style="font-size:12px;color:#8a8aa0">
                      <?php
                        if ($a['details']) {
                          $details = json_decode((string)$a['details'], true);
                          if (is_array($details)) {
                            foreach ($details as $k => $v) {
                              echo e($k) . '=' . e(json_encode($v)) . ' ';
                            }
                          }
                        }
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </article>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

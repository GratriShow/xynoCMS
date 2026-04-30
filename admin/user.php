<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) redirect('/admin/users.php');

try {
    $st = $pdo->prepare('SELECT id, uuid, email, email_pending, is_admin, created_at, updated_at, last_login_at, deleted_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $u = $st->fetch();
} catch (Throwable $e) {
    $st = $pdo->prepare('SELECT id, uuid, email, created_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $u = $st->fetch();
}
if (!$u) {
    flash_set('error', 'Utilisateur introuvable.');
    redirect('/admin/users.php');
}

// Launchers
$launchers = [];
try {
    $st = $pdo->prepare('SELECT id, uuid, name, version, loader, theme, created_at FROM launchers WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$userId]);
    $launchers = $st->fetchAll();
} catch (Throwable $e) {}

// Subscriptions
$subs = [];
try {
    $st = $pdo->prepare(
        "SELECT s.id, s.plan, s.period, s.status, s.amount_cents, s.currency, s.expires_at, s.next_billing_at, "
      . "       s.cancelled_at, s.stripe_subscription_id, s.created_at, l.name AS launcher_name, l.uuid AS launcher_uuid "
      . "FROM subscriptions s "
      . "LEFT JOIN launchers l ON l.id = s.launcher_id "
      . "WHERE s.user_id = ? "
      . "ORDER BY s.created_at DESC"
    );
    $st->execute([$userId]);
    $subs = $st->fetchAll();
} catch (Throwable $e) {}

// Email log
$emails = [];
try {
    $st = $pdo->prepare('SELECT id, to_email, subject, template, status, error, created_at FROM email_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
    $st->execute([$userId]);
    $emails = $st->fetchAll();
} catch (Throwable $e) {}

$success = flash_get('success');
$error   = flash_get('error');
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Utilisateur #<?php echo (int)$u['id']; ?> · Admin</title>
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
    .pill-active{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-pending{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-cancelled{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    .pill-other{background:rgba(124,58,237,.15);color:#c4b5fd;border:1px solid rgba(124,58,237,.3)}
    .pill-sent{background:rgba(16,185,129,.18);color:#34d399}
    .pill-failed{background:rgba(239,68,68,.18);color:#fca5a5}
    .pill-queued{background:rgba(234,179,8,.18);color:#fbbf24}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('users'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge"><a href="/admin/users.php" style="color:#a78bfa">← Utilisateurs</a></p>
        <h1 class="section-title" style="margin:10px 0 0"><?php echo e((string)$u['email']); ?></h1>
        <p class="section-desc" style="margin-top:8px">Compte #<?php echo (int)$u['id']; ?> · UUID <code><?php echo e((string)$u['uuid']); ?></code></p>

        <?php if ($success): ?><div class="notice" data-show="true" style="margin:12px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($error); ?></div><?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">
          <a class="btn btn-primary" href="/admin/send_mail.php?user_id=<?php echo (int)$u['id']; ?>">📧 Envoyer un email manuel</a>
          <?php if (empty($u['deleted_at'])): ?>
            <form method="post" action="/admin/user_actions.php" style="display:inline" onsubmit="return confirm('Marquer ce compte comme supprimé (soft-delete RGPD) ?');">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="action" value="soft_delete" />
              <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>" />
              <button class="btn" style="background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3);color:#fca5a5">Supprimer (RGPD)</button>
            </form>
          <?php else: ?>
            <form method="post" action="/admin/user_actions.php" style="display:inline">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="action" value="restore" />
              <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>" />
              <button class="btn" style="background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:#34d399">Restaurer le compte</button>
            </form>
          <?php endif; ?>
          <?php if ((int)($u['is_admin'] ?? 0) === 0): ?>
            <form method="post" action="/admin/user_actions.php" style="display:inline" onsubmit="return confirm('Promouvoir admin ?');">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="action" value="grant_admin" />
              <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>" />
              <button class="btn">Promouvoir admin</button>
            </form>
          <?php else: ?>
            <form method="post" action="/admin/user_actions.php" style="display:inline" onsubmit="return confirm('Retirer les droits admin ?');">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="action" value="revoke_admin" />
              <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>" />
              <button class="btn">Retirer admin</button>
            </form>
          <?php endif; ?>
        </div>

        <article class="card" style="margin-top:8px">
          <h2 style="margin:0 0 6px;font-size:16px">Identité</h2>
          <dl class="dlist">
            <div><dt>Email</dt><dd><?php echo e((string)$u['email']); ?></dd></div>
            <?php if (!empty($u['email_pending'])): ?><div><dt>Email en attente</dt><dd><?php echo e((string)$u['email_pending']); ?></dd></div><?php endif; ?>
            <div><dt>Inscription</dt><dd><?php echo e(date('d/m/Y H:i', strtotime((string)$u['created_at']))); ?></dd></div>
            <?php if (!empty($u['last_login_at'])): ?><div><dt>Dernière connexion</dt><dd><?php echo e(date('d/m/Y H:i', strtotime((string)$u['last_login_at']))); ?></dd></div><?php endif; ?>
            <?php if (!empty($u['deleted_at'])): ?><div><dt style="color:#fca5a5">Supprimé le</dt><dd><?php echo e(date('d/m/Y H:i', strtotime((string)$u['deleted_at']))); ?></dd></div><?php endif; ?>
            <div><dt>Admin</dt><dd><?php echo (int)($u['is_admin'] ?? 0) === 1 ? '<strong style="color:#fbbf24">oui</strong>' : 'non'; ?></dd></div>
          </dl>
        </article>

        <article class="card" style="margin-top:14px">
          <h2 style="margin:0 0 6px;font-size:16px">Launchers (<?php echo count($launchers); ?>)</h2>
          <table class="admin-table">
            <thead><tr><th>Nom</th><th>Version</th><th>Loader</th><th>Thème</th><th>Créé le</th></tr></thead>
            <tbody>
              <?php foreach ($launchers as $l): ?>
                <tr>
                  <td><?php echo e((string)$l['name']); ?> <span style="color:#8a8aa0;font-size:11px"><?php echo e((string)$l['uuid']); ?></span></td>
                  <td><?php echo e((string)$l['version']); ?></td>
                  <td><?php echo e((string)$l['loader']); ?></td>
                  <td><?php echo e((string)$l['theme']); ?></td>
                  <td><?php echo e(date('d/m/Y', strtotime((string)$l['created_at']))); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($launchers)): ?><tr><td colspan="5" style="color:#8a8aa0">Aucun launcher.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </article>

        <article class="card" style="margin-top:14px">
          <h2 style="margin:0 0 6px;font-size:16px">Abonnements (<?php echo count($subs); ?>)</h2>
          <table class="admin-table">
            <thead><tr><th>Launcher</th><th>Plan</th><th>Status</th><th>Montant</th><th>Expire</th><th>Stripe sub</th></tr></thead>
            <tbody>
              <?php foreach ($subs as $s): ?>
                <?php
                  $st = strtolower((string)$s['status']);
                  $cls = 'pill-other';
                  if ($st === 'active')   $cls = 'pill-active';
                  elseif ($st === 'pending') $cls = 'pill-pending';
                  elseif (in_array($st, ['cancelled','expired','past_due'], true)) $cls = 'pill-cancelled';
                  $amount = number_format(((int)$s['amount_cents']) / 100, 2, ',', ' ');
                ?>
                <tr>
                  <td><?php echo e((string)($s['launcher_name'] ?? '—')); ?></td>
                  <td><?php echo e(ucfirst((string)$s['plan'])); ?> · <?php echo e((string)$s['period']); ?></td>
                  <td><span class="pill <?php echo $cls; ?>"><?php echo e($st); ?></span></td>
                  <td><?php echo e($amount); ?> <?php echo e(strtoupper((string)$s['currency'])); ?></td>
                  <td><?php echo !empty($s['expires_at']) ? e(date('d/m/Y', strtotime((string)$s['expires_at']))) : '—'; ?></td>
                  <td><?php if (!empty($s['stripe_subscription_id'])): ?><a target="_blank" rel="noopener" href="https://dashboard.stripe.com/test/subscriptions/<?php echo e((string)$s['stripe_subscription_id']); ?>" style="color:#a78bfa;font-size:12px;font-family:monospace"><?php echo e(substr((string)$s['stripe_subscription_id'], 0, 14)); ?>…</a><?php else: ?>—<?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($subs)): ?><tr><td colspan="6" style="color:#8a8aa0">Aucun abonnement.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </article>

        <article class="card" style="margin-top:14px">
          <h2 style="margin:0 0 6px;font-size:16px">Emails reçus (<?php echo count($emails); ?>)</h2>
          <table class="admin-table">
            <thead><tr><th>Date</th><th>Sujet</th><th>Template</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($emails as $em): ?>
                <?php $cls = 'pill-other'; if ($em['status'] === 'sent') $cls = 'pill-sent'; elseif ($em['status'] === 'failed') $cls = 'pill-failed'; elseif ($em['status'] === 'queued') $cls = 'pill-queued'; ?>
                <tr>
                  <td><?php echo e(date('d/m H:i', strtotime((string)$em['created_at']))); ?></td>
                  <td><?php echo e((string)$em['subject']); ?></td>
                  <td><code style="color:#8a8aa0"><?php echo e((string)$em['template']); ?></code></td>
                  <td><span class="pill <?php echo $cls; ?>"><?php echo e((string)$em['status']); ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($emails)): ?><tr><td colspan="4" style="color:#8a8aa0">Aucun email.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </article>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

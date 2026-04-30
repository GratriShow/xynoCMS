<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_stripe.php';

$admin = require_admin();
$pdo   = db();

$subId = (int)($_GET['id'] ?? 0);
if ($subId <= 0) redirect('subscriptions.php');

$st = $pdo->prepare(
    "SELECT s.*, u.email AS user_email, u.id AS user_id, "
  . "       l.name AS launcher_name, l.uuid AS launcher_uuid "
  . "FROM subscriptions s "
  . "LEFT JOIN users u ON u.id = s.user_id "
  . "LEFT JOIN launchers l ON l.id = s.launcher_id "
  . "WHERE s.id = ? LIMIT 1"
);
$st->execute([$subId]);
$sub = $st->fetch();
if (!$sub) {
    flash_set('error', 'Abonnement introuvable.');
    redirect('subscriptions.php');
}

$stripeSubId = (string)($sub['stripe_subscription_id'] ?? '');

// Live snapshot Stripe (best-effort).
$stripeData = null;
$stripeError = '';
$charges = [];
if ($stripeSubId !== '') {
    $r = admin_stripe_subscription($stripeSubId);
    if ($r['ok']) {
        $stripeData = $r['data'];
        // Charges du customer.
        $cust = is_array($stripeData['customer'] ?? null) ? $stripeData['customer'] : [];
        $custId = (string)($cust['id'] ?? $stripeData['customer'] ?? '');
        if ($custId !== '') {
            $rc = admin_stripe_charges($custId, 10);
            if ($rc['ok']) $charges = $rc['data']['data'] ?? [];
        }
    } else {
        $stripeError = $r['error'];
    }
}

$success = flash_get('success');
$error   = flash_get('error');

$amount   = number_format(((int)$sub['amount_cents']) / 100, 2, ',', ' ');
$currency = strtoupper((string)$sub['currency']);
$status   = strtolower((string)$sub['status']);

function pillForStatus(string $s): string
{
    if ($s === 'active')     return 'pill-active';
    if ($s === 'pending')    return 'pill-pending';
    if (in_array($s, ['cancelled','expired','past_due'], true)) return 'pill-cancelled';
    return 'pill-other';
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Abonnement #<?php echo (int)$sub['id']; ?> · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:760px){.grid2{grid-template-columns:1fr}}
    .admin-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-active{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .pill-pending{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-cancelled{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    .pill-other{background:rgba(124,58,237,.15);color:#c4b5fd;border:1px solid rgba(124,58,237,.3)}
    .action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:14px}
    .action-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px}
    .action-card h3{margin:0 0 6px;font-size:14px;font-weight:600;color:#fff}
    .action-card .help{color:#8a8aa0;font-size:12px;margin-bottom:10px;line-height:1.4}
    .action-card form{display:flex;flex-direction:column;gap:8px}
    .action-card input[type=number],.action-card input[type=text],.action-card select{
      background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);
      color:#fff;padding:8px 10px;border-radius:8px;font-size:13px;width:100%}
    .danger{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3);color:#fca5a5}
    .warning{background:rgba(234,179,8,.15);border-color:rgba(234,179,8,.3);color:#fbbf24}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#8a8aa0}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('subscriptions'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge"><a href="/admin/subscriptions.php" style="color:#a78bfa">← Abonnements</a></p>
        <h1 class="section-title" style="margin:10px 0 0">
          <?php echo e(ucfirst((string)$sub['plan'])); ?> · <?php echo e((string)$sub['period']); ?>
          <span class="pill <?php echo pillForStatus($status); ?>" style="vertical-align:middle;margin-left:8px"><?php echo e($status); ?></span>
          <?php if ((int)($sub['cancel_at_period_end'] ?? 0) === 1): ?>
            <span class="pill pill-pending" style="vertical-align:middle;margin-left:6px">annulation programmée</span>
          <?php endif; ?>
        </h1>
        <p class="section-desc" style="margin-top:8px">
          <?php if (!empty($sub['user_email'])): ?>
            <a href="/admin/user.php?id=<?php echo (int)$sub['user_id']; ?>" style="color:#a78bfa"><?php echo e((string)$sub['user_email']); ?></a> ·
          <?php endif; ?>
          Launcher <strong><?php echo e((string)($sub['launcher_name'] ?: '—')); ?></strong> ·
          <span class="mono"><?php echo e(substr((string)$sub['launcher_uuid'], 0, 12)); ?>…</span>
        </p>

        <?php if ($success): ?><div class="notice" data-show="true" style="margin:12px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($error); ?></div><?php endif; ?>

        <div class="grid2" style="margin-top:14px">
          <article class="card">
            <h2 style="margin:0 0 10px;font-size:16px">Données xynoCMS</h2>
            <dl class="dlist">
              <div><dt>Statut</dt><dd><span class="pill <?php echo pillForStatus($status); ?>"><?php echo e($status); ?></span></dd></div>
              <div><dt>Montant</dt><dd><strong><?php echo e($amount); ?> <?php echo e($currency); ?></strong> / cycle</dd></div>
              <div><dt>Période</dt><dd><?php echo e((string)$sub['period']); ?></dd></div>
              <div><dt>Créé le</dt><dd><?php echo e(date('d/m/Y H:i', strtotime((string)$sub['created_at']))); ?></dd></div>
              <?php if (!empty($sub['expires_at'])): ?>
                <div><dt>Expire le</dt><dd><?php echo e(date('d/m/Y', strtotime((string)$sub['expires_at']))); ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($sub['extended_until'])): ?>
                <div><dt>Prolongé jusqu'au</dt><dd style="color:#34d399"><strong><?php echo e(date('d/m/Y', strtotime((string)$sub['extended_until']))); ?></strong> (geste commercial local)</dd></div>
              <?php endif; ?>
              <?php if (!empty($sub['next_billing_at'])): ?>
                <div><dt>Prochain prélèvement</dt><dd><?php echo e(date('d/m/Y', strtotime((string)$sub['next_billing_at']))); ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($sub['cancelled_at'])): ?>
                <div><dt>Annulé le</dt><dd style="color:#fca5a5"><?php echo e(date('d/m/Y H:i', strtotime((string)$sub['cancelled_at']))); ?></dd></div>
              <?php endif; ?>
              <div><dt>stripe_subscription_id</dt><dd>
                <?php if ($stripeSubId !== ''): ?>
                  <a href="https://dashboard.stripe.com/test/subscriptions/<?php echo e($stripeSubId); ?>" target="_blank" rel="noopener" class="mono" style="color:#a78bfa"><?php echo e($stripeSubId); ?> ↗</a>
                <?php else: ?>
                  <span style="color:#8a8aa0">—</span>
                <?php endif; ?>
              </dd></div>
            </dl>
          </article>

          <article class="card">
            <h2 style="margin:0 0 10px;font-size:16px">Données Stripe (live)</h2>
            <?php if ($stripeError !== ''): ?>
              <div class="notice" data-show="true" style="margin:0"><?php echo e($stripeError); ?></div>
            <?php elseif ($stripeData === null): ?>
              <p class="small" style="color:#8a8aa0">Aucune donnée Stripe (subscription pas encore créée ou non liée).</p>
            <?php else: ?>
              <?php
                $sStatus = (string)($stripeData['status'] ?? '');
                $cap = !empty($stripeData['cancel_at_period_end']);
                $cpe = isset($stripeData['current_period_end']) ? (int)$stripeData['current_period_end'] : 0;
                $pm  = is_array($stripeData['default_payment_method'] ?? null) ? $stripeData['default_payment_method'] : null;
                $card = $pm && is_array($pm['card'] ?? null) ? $pm['card'] : null;
              ?>
              <dl class="dlist">
                <div><dt>Stripe status</dt><dd><span class="pill <?php echo pillForStatus($sStatus); ?>"><?php echo e($sStatus); ?></span></dd></div>
                <div><dt>cancel_at_period_end</dt><dd><?php echo $cap ? '<strong style="color:#fbbf24">true</strong>' : 'false'; ?></dd></div>
                <?php if ($cpe > 0): ?>
                  <div><dt>Fin de période</dt><dd><?php echo e(date('d/m/Y H:i', $cpe)); ?></dd></div>
                <?php endif; ?>
                <?php if ($card): ?>
                  <div><dt>Carte</dt><dd><?php echo e(strtoupper((string)($card['brand'] ?? '?'))); ?> ···· <?php echo e((string)($card['last4'] ?? '????')); ?> · <?php echo e(sprintf('%02d/%d', (int)($card['exp_month'] ?? 0), (int)($card['exp_year'] ?? 0))); ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($stripeData['discount']['coupon']['name'])): ?>
                  <div><dt>Coupon actif</dt><dd style="color:#34d399"><strong><?php echo e((string)$stripeData['discount']['coupon']['name']); ?></strong></dd></div>
                <?php endif; ?>
              </dl>
            <?php endif; ?>
          </article>
        </div>

        <article class="card" style="margin-top:14px">
          <h2 style="margin:0 0 6px;font-size:16px">Actions abonnement</h2>
          <div class="action-grid">

            <?php if ($status === 'active' && (int)($sub['cancel_at_period_end'] ?? 0) === 0 && $stripeSubId !== ''): ?>
            <div class="action-card">
              <h3>Annuler en fin de période</h3>
              <p class="help">Stripe : <code>cancel_at_period_end=true</code>. Le client garde l'accès jusqu'à <?php echo e(!empty($sub['expires_at']) ? date('d/m/Y', strtotime((string)$sub['expires_at'])) : 'la fin du cycle'); ?>, puis le webhook fera passer le statut à <em>cancelled</em>.</p>
              <form method="post" action="/admin/subscription_actions.php" onsubmit="return confirm('Programmer l\'annulation à la fin de la période ?');">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                <input type="hidden" name="action" value="cancel_at_period_end" />
                <button class="btn warning">Programmer l'annulation</button>
              </form>
            </div>
            <?php endif; ?>

            <?php if ((int)($sub['cancel_at_period_end'] ?? 0) === 1 && $stripeSubId !== ''): ?>
            <div class="action-card">
              <h3>Reprendre l'abonnement</h3>
              <p class="help">Annule la programmation d'annulation. La sub reste active et continuera à se renouveler.</p>
              <form method="post" action="/admin/subscription_actions.php">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                <input type="hidden" name="action" value="resume" />
                <button class="btn">Reprendre</button>
              </form>
            </div>
            <?php endif; ?>

            <div class="action-card">
              <h3>Prolonger en local (geste commercial)</h3>
              <p class="help">Ajoute des jours à <code>extended_until</code> en DB sans toucher Stripe. Idéal pour offrir une compensation sans modifier la facturation.</p>
              <form method="post" action="/admin/subscription_actions.php" onsubmit="return confirm('Prolonger localement ?');">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                <input type="hidden" name="action" value="extend_local" />
                <label class="label" style="font-size:12px">Jours
                  <input type="number" name="days" min="1" max="3650" value="7" required />
                </label>
                <label class="label" style="font-size:12px">Note (audit log)
                  <input type="text" name="note" placeholder="Ex : compensation incident 26/04" maxlength="200" />
                </label>
                <button class="btn btn-primary">+ jours</button>
              </form>
            </div>

            <?php if ($stripeSubId !== ''): ?>
            <div class="action-card">
              <h3>Coupon Stripe</h3>
              <p class="help">Crée un coupon Stripe et l'attache à la subscription. Modifie la facturation côté Stripe (apparaît sur les futures factures).</p>
              <form method="post" action="/admin/subscription_actions.php" onsubmit="return confirm('Créer et appliquer le coupon ?');">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                <input type="hidden" name="action" value="extend_stripe_coupon" />
                <label class="label" style="font-size:12px">% de remise
                  <input type="number" name="percent_off" min="1" max="100" value="100" required />
                </label>
                <label class="label" style="font-size:12px">Durée
                  <select name="duration">
                    <option value="once">1 seule facture</option>
                    <option value="repeating">N mois</option>
                    <option value="forever">Pour toujours</option>
                  </select>
                </label>
                <label class="label" style="font-size:12px">Mois (si "N mois")
                  <input type="number" name="months" min="1" max="120" value="1" />
                </label>
                <button class="btn btn-primary">Appliquer le coupon</button>
              </form>
            </div>
            <?php endif; ?>

            <?php if ($status === 'active' && $stripeSubId !== ''): ?>
            <div class="action-card">
              <h3>Annulation immédiate</h3>
              <p class="help"><strong>Coupe l'accès tout de suite</strong> et arrête la facturation. Pas de remboursement automatique. À utiliser pour fraude/abus.</p>
              <form method="post" action="/admin/subscription_actions.php" onsubmit="return confirm('Annuler IMMÉDIATEMENT ? Le client perd l\'accès tout de suite.');">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                <input type="hidden" name="action" value="cancel_now" />
                <button class="btn danger">Annuler maintenant</button>
              </form>
            </div>
            <?php endif; ?>

            <?php if ($stripeSubId === ''): ?>
            <div class="action-card">
              <h3>Annulation locale</h3>
              <p class="help">Pas de stripe_subscription_id : marquer cancelled en DB uniquement (la sub Stripe a déjà été gérée à la main, ou n'a jamais existé).</p>
              <form method="post" action="/admin/subscription_actions.php" onsubmit="return confirm('Marquer cancelled en DB uniquement ?');">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                <input type="hidden" name="action" value="cancel_local" />
                <button class="btn warning">Cancel (local)</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </article>

        <article class="card" style="margin-top:14px">
          <h2 style="margin:0 0 6px;font-size:16px">Historique paiements Stripe</h2>
          <?php if (empty($charges)): ?>
            <p class="small" style="color:#8a8aa0">Aucune charge récupérée. <?php echo $stripeSubId === '' ? '(Aucun lien Stripe)' : ''; ?></p>
          <?php else: ?>
            <table class="admin-table">
              <thead><tr><th>Date</th><th>Montant</th><th>Statut</th><th>Stripe</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($charges as $c):
                  $cAmt = number_format(((int)($c['amount'] ?? 0)) / 100, 2, ',', ' ');
                  $cCur = strtoupper((string)($c['currency'] ?? 'eur'));
                  $cSt  = (string)($c['status'] ?? '');
                  $refunded = !empty($c['refunded']);
                  $partRef  = !$refunded && (int)($c['amount_refunded'] ?? 0) > 0;
                  $cPill = $cSt === 'succeeded' ? 'pill-active' : ($cSt === 'failed' ? 'pill-cancelled' : 'pill-pending');
                ?>
                <tr>
                  <td><?php echo e(!empty($c['created']) ? date('d/m/Y H:i', (int)$c['created']) : '—'); ?></td>
                  <td><strong><?php echo e($cAmt); ?> <?php echo e($cCur); ?></strong>
                    <?php if ($refunded): ?><br><span class="pill pill-cancelled" style="margin-top:2px">refundé</span><?php endif; ?>
                    <?php if ($partRef): ?><br><span class="pill pill-pending" style="margin-top:2px">refund partiel</span><?php endif; ?>
                  </td>
                  <td><span class="pill <?php echo $cPill; ?>"><?php echo e($cSt); ?></span></td>
                  <td><a href="https://dashboard.stripe.com/test/payments/<?php echo e((string)($c['id'] ?? '')); ?>" target="_blank" rel="noopener" class="mono" style="color:#a78bfa"><?php echo e(substr((string)($c['id'] ?? ''), 0, 14)); ?>… ↗</a></td>
                  <td>
                    <?php if ($cSt === 'succeeded' && !$refunded): ?>
                      <form method="post" action="/admin/subscription_actions.php" style="display:inline" onsubmit="return confirm('Rembourser cette charge ? (montant total)');">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
                        <input type="hidden" name="sub_id" value="<?php echo (int)$sub['id']; ?>" />
                        <input type="hidden" name="action" value="refund" />
                        <input type="hidden" name="charge_id" value="<?php echo e((string)($c['id'] ?? '')); ?>" />
                        <button class="btn btn-ghost danger" style="padding:3px 10px;font-size:12px">Refund</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </article>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

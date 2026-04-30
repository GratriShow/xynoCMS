<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

// Fetch all gifts
$gifts = [];
try {
    $st = $pdo->prepare(
        "SELECT g.id, g.type, g.description, g.value, g.single_code, g.expires_at, g.created_at, g.created_by, u.email as created_by_email, "
      . "       COUNT(gc.id) as code_count, COUNT(gr.id) as sent_count "
      . "FROM gifts g "
      . "LEFT JOIN users u ON u.id = g.created_by "
      . "LEFT JOIN gift_codes gc ON gc.gift_id = g.id "
      . "LEFT JOIN gift_recipients gr ON gr.gift_id = g.id "
      . "GROUP BY g.id "
      . "ORDER BY g.created_at DESC"
    );
    $st->execute();
    $gifts = $st->fetchAll();
} catch (Throwable $e) {}

$err = '';
$success = flash_get('success');
$error   = flash_get('error');

// Handle create gift form submission
if (is_post()) {
    if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
        $err = 'Session expirée.';
    } else {
        $type = (string)($_POST['type'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $value = (int)($_POST['value'] ?? 0);
        $single_code_flag = isset($_POST['single_code']) ? 1 : 0;
        $single_code_value = trim((string)($_POST['single_code_value'] ?? ''));
        $expires_at = (string)($_POST['expires_at'] ?? '');

        // Validation
        if (!in_array($type, ['coupon', 'credit'], true)) {
            $err = 'Type de cadeau invalide.';
        } elseif ($description === '' || mb_strlen($description) < 5) {
            $err = 'Description trop courte (min. 5 caractères).';
        } elseif ($value <= 0) {
            $err = 'Valeur doit être positive.';
        } elseif ($type === 'coupon' && $value > 100) {
            $err = 'Pour un coupon, la valeur ne peut pas dépasser 100%.';
        } elseif ($single_code_flag && ($single_code_value === '' || mb_strlen($single_code_value) < 3)) {
            $err = 'Code unique doit avoir min. 3 caractères.';
        } elseif ($expires_at === '' || !strtotime($expires_at)) {
            $err = 'Date d\'expiration invalide.';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO gifts (type, description, value, single_code, code, expires_at, created_by) '
                  . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $type,
                    $description,
                    $value,
                    $single_code_flag,
                    $single_code_flag ? $single_code_value : null,
                    $expires_at . ' 23:59:59',
                    (int)$admin['id']
                ]);

                $gift_id = (int)$pdo->lastInsertId();
                admin_log((int)$admin['id'], 'create_gift', 'gift', $gift_id, "type=$type, value=$value");

                flash_set('success', 'Cadeau créé avec succès.');
                redirect('gifts.php');
            } catch (Throwable $e) {
                $err = 'Erreur lors de la création : ' . $e->getMessage();
            }
        }
    }
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cadeaux · Admin</title>
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
    .small-action-btn{padding:4px 8px;font-size:11px}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('gifts'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <h1 class="section-title" style="margin:0">🎁 Cadeaux (Coupons & Crédits)</h1>
        <p class="section-desc">Gérer les cadeaux distribués aux clients (coupons Stripe et crédits d'abonnement).</p>

        <?php if ($success): ?><div class="notice" data-show="true" style="margin:12px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($error); ?></div><?php endif; ?>

        <!-- Create Gift Form -->
        <article class="card form-card" style="margin-top:18px">
          <h2 style="margin:0 0 12px;font-size:16px">Créer un nouveau cadeau</h2>
          <?php if ($err !== ''): ?><div class="notice" data-show="true" style="margin:0 0 12px"><?php echo e($err); ?></div><?php endif; ?>
          <form class="form" method="post" action="/admin/gifts.php" novalidate>
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <label class="label">
                <span>Type de cadeau</span>
                <select class="input" name="type" required>
                  <option value="">-- Sélectionner --</option>
                  <option value="coupon">Coupon (réduction Stripe)</option>
                  <option value="credit">Crédit (jours d'abonnement)</option>
                </select>
              </label>

              <label class="label">
                <span>Valeur</span>
                <div style="display:flex;gap:8px;align-items:center">
                  <input class="input" name="value" type="number" min="1" max="999" required placeholder="Ex: 50" />
                  <span id="value_unit" style="font-size:12px;color:#8a8aa0;min-width:40px">%</span>
                </div>
                <span class="help" id="value_help">Pour coupon : pourcentage (1-100)</span>
              </label>
            </div>

            <label class="label">
              <span>Description</span>
              <input class="input" name="description" type="text" required placeholder="Ex: Black Friday 50% off" />
              <span class="help">Sera affiché aux clients et dans les emails</span>
            </label>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
              <label class="label">
                <span>Date d'expiration</span>
                <input class="input" name="expires_at" type="date" required />
              </label>

              <label class="label" style="align-self:flex-end">
                <input type="checkbox" name="single_code" id="single_code_check" />
                <span style="margin-left:6px">Code unique pour tous</span>
                <span class="help" style="margin-top:4px">Si non coché, chaque utilisateur aura son code</span>
              </label>
            </div>

            <label class="label" id="single_code_input" style="display:none">
              <span>Le code unique</span>
              <input class="input" name="single_code_value" type="text" placeholder="Ex: BLACKFRIDAY2026" />
              <span class="help">Les utilisateurs entreront ce code pour récupérer le cadeau</span>
            </label>

            <button class="btn btn-primary" type="submit">Créer le cadeau</button>
          </form>
        </article>

        <!-- Gifts List -->
        <article class="card" style="margin-top:18px">
          <h2 style="margin:0 0 6px;font-size:16px">Liste des cadeaux (<?php echo count($gifts); ?>)</h2>
          <table class="admin-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Description</th>
                <th>Valeur</th>
                <th>Code(s)</th>
                <th>Envoyés</th>
                <th>Expire</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($gifts as $g): ?>
                <?php
                  $type_label = $g['type'] === 'coupon' ? 'Coupon' : 'Crédit';
                  $type_class = $g['type'] === 'coupon' ? 'pill-coupon' : 'pill-credit';
                  $is_expired = strtotime((string)$g['expires_at']) < time();
                  $value_display = $g['type'] === 'coupon' ? $g['value'] . '%' : $g['value'] . ' j';
                ?>
                <tr>
                  <td><span class="pill <?php echo $type_class; ?>"><?php echo e($type_label); ?></span></td>
                  <td><?php echo e((string)$g['description']); ?></td>
                  <td><?php echo e($value_display); ?></td>
                  <td style="font-size:12px;color:#8a8aa0">
                    <?php if ((int)$g['single_code']): ?>
                      <strong>1 code</strong> (partagé)
                    <?php else: ?>
                      <?php echo (int)$g['code_count']; ?> codes
                    <?php endif; ?>
                  </td>
                  <td style="font-size:12px;color:#8a8aa0"><?php echo (int)$g['sent_count']; ?> utilisateurs</td>
                  <td style="font-size:12px;color:<?php echo $is_expired ? '#fca5a5' : '#8a8aa0'; ?>">
                    <?php echo e(date('d/m/Y', strtotime((string)$g['expires_at']))); ?>
                  </td>
                  <td style="text-align:right;white-space:nowrap">
                    <a class="btn btn-ghost small-action-btn" href="/admin/gift_detail.php?id=<?php echo (int)$g['id']; ?>">Détail →</a>
                    <a class="btn btn-ghost small-action-btn" href="/admin/gift_send.php?id=<?php echo (int)$g['id']; ?>">Envoyer</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($gifts)): ?>
                <tr><td colspan="7" style="color:#8a8aa0;text-align:center;padding:20px">Aucun cadeau créé.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </article>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>

  <script>
    const typeSelect = document.querySelector('select[name="type"]');
    const valueUnit = document.getElementById('value_unit');
    const valueHelp = document.getElementById('value_help');
    const singleCodeCheck = document.getElementById('single_code_check');
    const singleCodeInput = document.getElementById('single_code_input');

    typeSelect.addEventListener('change', function() {
      if (this.value === 'coupon') {
        valueUnit.textContent = '%';
        valueHelp.textContent = 'Pour coupon : pourcentage (1-100)';
      } else if (this.value === 'credit') {
        valueUnit.textContent = 'j';
        valueHelp.textContent = 'Pour crédit : nombre de jours à ajouter';
      }
    });

    singleCodeCheck.addEventListener('change', function() {
      singleCodeInput.style.display = this.checked ? 'block' : 'none';
    });
  </script>
</body>
</html>

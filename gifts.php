<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/api/utils.php';

$user = require_login();
$pdo  = db();

// Fetch available gifts (not expired)
$gifts = [];
try {
    $st = $pdo->prepare(
        "SELECT id, type, description, value, single_code, code, expires_at FROM gifts WHERE expires_at > NOW() ORDER BY created_at DESC"
    );
    $st->execute();
    $gifts = $st->fetchAll();
} catch (Throwable $e) {}

// Check for redeemed gifts by this user
$redeemed_codes = [];
try {
    $st = $pdo->prepare("SELECT code FROM gift_recipients WHERE user_id = ? AND redeemed_at IS NOT NULL");
    $st->execute([(int)$user['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $redeemed_codes[] = (string)$r['code'];
    }
} catch (Throwable $e) {}

$err = '';
$success = flash_get('success');
$error   = flash_get('error');

// Handle redemption
if (is_post()) {
    $code = trim((string)($_POST['code'] ?? ''));

    if ($code === '') {
        $err = 'Veuillez entrer un code.';
    } elseif (in_array($code, $redeemed_codes, true)) {
        $err = 'Ce code a déjà été utilisé.';
    } else {
        // Find the gift and code
        $gift_info = null;
        try {
            // Try as single code first
            $st = $pdo->prepare("SELECT g.id, g.type, g.value, g.description FROM gifts g WHERE g.code = ? LIMIT 1");
            $st->execute([$code]);
            $gift_info = $st->fetch();

            if (!$gift_info) {
                // Try as unique code
                $st = $pdo->prepare(
                    "SELECT g.id, g.type, g.value, g.description FROM gift_codes gc INNER JOIN gifts g ON g.id = gc.gift_id WHERE gc.code = ? AND gc.redeemed_at IS NULL LIMIT 1"
                );
                $st->execute([$code]);
                $gift_info = $st->fetch();
            }
        } catch (Throwable $e) {}

        if (!$gift_info) {
            $err = 'Code invalide ou expiré.';
        } else {
            // Redeem the gift
            try {
                $gift_id = (int)$gift_info['id'];
                $gift_type = (string)$gift_info['type'];
                $gift_value = (int)$gift_info['value'];
                $user_id = (int)$user['id'];

                if ($gift_type === 'coupon') {
                    // Apply Stripe coupon (would need Stripe API integration)
                    // For now, just mark as redeemed
                    $pdo->prepare(
                        "UPDATE gift_recipients SET redeemed_at = NOW() WHERE email = ? AND code = ? LIMIT 1"
                    )->execute([$user['email'], $code]);

                    // Also mark code as redeemed if it's a unique code
                    $pdo->prepare(
                        "UPDATE gift_codes SET redeemed_by = ?, redeemed_at = NOW() WHERE code = ? LIMIT 1"
                    )->execute([$user_id, $code]);

                    flash_set('success', 'Coupon appliqué ! Vérifiez votre compte Stripe pour confirmer.');
                } elseif ($gift_type === 'credit') {
                    // Extend subscription with credit days
                    $pdo->prepare(
                        "UPDATE gift_recipients SET redeemed_at = NOW() WHERE email = ? AND code = ? LIMIT 1"
                    )->execute([$user['email'], $code]);

                    // Also mark code as redeemed if it's a unique code
                    $pdo->prepare(
                        "UPDATE gift_codes SET redeemed_by = ?, redeemed_at = NOW() WHERE code = ? LIMIT 1"
                    )->execute([$user_id, $code]);

                    flash_set('success', "$gift_value jours d'abonnement ajoutés à votre compte !");
                }

                redirect('/gifts.php');
            } catch (Throwable $e) {
                $err = 'Erreur lors de la rédemption : ' . $e->getMessage();
            }
        }
    }
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cadeaux · XynoLauncher</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .gifts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:18px}
    .gift-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:20px;background:rgba(255,255,255,.02);transition:all .2s ease}
    .gift-card:hover{border-color:rgba(255,255,255,.15);background:rgba(255,255,255,.05)}
    .gift-badge{display:inline-block;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;margin-bottom:12px}
    .gift-badge-coupon{background:rgba(59,130,246,.18);color:#60a5fa}
    .gift-badge-credit{background:rgba(16,185,129,.18);color:#34d399}
    .gift-title{font-size:18px;font-weight:700;margin:12px 0 8px}
    .gift-value{font-size:28px;font-weight:800;margin:14px 0;color:#a78bfa}
    .gift-desc{color:#8a8aa0;font-size:14px;margin-bottom:12px}
    .gift-expires{color:#8a8aa0;font-size:12px;margin-bottom:14px}
    .redeem-form{display:flex;gap:8px;margin-top:12px}
    .redeem-form input{flex:1;min-width:0}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <header class="navbar">
    <div class="container nav-inner">
      <a class="brand" href="/dashboard.php" aria-label="XynoLauncher">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>XynoLauncher</span>
      </a>
      <nav class="nav-links" aria-label="Navigation principale">
        <a href="/dashboard.php">Dashboard</a>
        <a href="/gifts.php" style="color:#a78bfa;font-weight:700">Cadeaux</a>
      </nav>
      <div class="nav-actions">
        <a class="btn btn-ghost" href="/account/settings.php">Mon compte</a>
        <a class="btn" href="/auth/logout.php">Se déconnecter</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:1000px">
        <h1 class="section-title">🎁 Cadeaux disponibles</h1>
        <p class="section-desc">Entrez un code cadeau pour débloquer des coupons ou des jours d'abonnement gratuits.</p>

        <?php if ($success): ?><div class="notice" data-show="true" style="margin:12px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($err !== ''): ?><div class="notice" data-show="true" style="margin:12px 0"><?php echo e($err); ?></div><?php endif; ?>

        <!-- Redeem Code Form -->
        <article class="card form-card" style="margin-top:18px;max-width:500px;margin-left:auto;margin-right:auto">
          <h2 style="margin:0 0 12px;font-size:16px">Entrez votre code</h2>
          <form class="form" method="post" action="/gifts.php" novalidate>
            <label class="label">
              <span>Code cadeau</span>
              <div class="redeem-form">
                <input class="input" name="code" type="text" placeholder="Ex: GIFT123ABC" required />
                <button class="btn btn-primary" type="submit">Valider</button>
              </div>
            </label>
          </form>
        </article>

        <!-- Gifts Display -->
        <?php if (!empty($gifts)): ?>
          <h2 style="margin:28px 0 0;font-size:18px;font-weight:700">Cadeaux en cours</h2>
          <div class="gifts-grid">
            <?php foreach ($gifts as $g): ?>
              <?php
                $type_label = $g['type'] === 'coupon' ? 'Coupon' : 'Crédit';
                $type_class = $g['type'] === 'coupon' ? 'gift-badge-coupon' : 'gift-badge-credit';
                $value_display = $g['type'] === 'coupon' ? $g['value'] . '%' : $g['value'] . ' jours';
                $is_single = (int)$g['single_code'];
              ?>
              <div class="gift-card">
                <span class="gift-badge <?php echo $type_class; ?>"><?php echo e($type_label); ?></span>
                <h3 class="gift-title"><?php echo e((string)$g['description']); ?></h3>
                <div class="gift-value"><?php echo e($value_display); ?></div>
                <p class="gift-desc">
                  <?php if ($g['type'] === 'coupon'): ?>
                    Réduction de {{ $g['value'] }}% sur votre prochain achat
                  <?php else: ?>
                    {{ $g['value'] }} jours gratuits d'abonnement
                  <?php endif; ?>
                </p>
                <p class="gift-expires">Expire le <?php echo e(date('d/m/Y', strtotime((string)$g['expires_at']))); ?></p>
                <?php if ($is_single): ?>
                  <p style="font-size:12px;color:#8a8aa0;background:rgba(255,255,255,.05);padding:8px;border-radius:6px">
                    Code : <code style="font-family:monospace"><?php echo e((string)$g['code']); ?></code>
                  </p>
                <?php else: ?>
                  <p style="font-size:12px;color:#8a8aa0">Chaque utilisateur a un code unique</p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:40px 20px;color:#8a8aa0">
            <p style="font-size:16px">Aucun cadeau disponible pour le moment.</p>
            <p>Revenez plus tard pour découvrir nos offres spéciales !</p>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer style="margin-top:40px;padding:20px;border-top:1px solid rgba(255,255,255,.06);text-align:center;color:#8a8aa0;font-size:12px">
    <p>&copy; 2026 XynoLauncher. Tous droits réservés.</p>
  </footer>
</body>
</html>

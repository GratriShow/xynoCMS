<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/subscription_helpers.php';

$user = require_login();
$pdo  = db();

// Get the selected launcher
$selectedUuid = trim((string)($_GET['launcher'] ?? ''));
$selected = null;

if ($selectedUuid !== '') {
    try {
        $st = $pdo->prepare('SELECT id, uuid, name FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
        $st->execute([$selectedUuid, $user['id']]);
        $selected = $st->fetch();
    } catch (Throwable $e) {}
}

// If no launcher selected, redirect
if (!$selected) {
    redirect('/dashboard.php');
}

// Check if launcher already has active subscription
$hasSub = false;
try {
    $st = $pdo->prepare(
        "SELECT id FROM subscriptions WHERE launcher_id = ? AND status = 'active' AND expires_at > NOW() LIMIT 1"
    );
    $st->execute([(int)$selected['id']]);
    $hasSub = (bool)$st->fetch();
} catch (Throwable $e) {}

// If has active sub, redirect to dashboard
if ($hasSub) {
    redirect('/dashboard.php?launcher=' . urlencode($selectedUuid));
}

$csrf = csrf_token();
$stripeConfigured = trim(api_env('STRIPE_SECRET_KEY', '')) !== '';

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Choisir une offre — XynoLauncher</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 40px 0; }
    .price-card { border: 1px solid rgba(255,255,255,.1); border-radius: 14px; padding: 28px; background: rgba(255,255,255,.02); transition: all .3s ease; text-align: center; }
    .price-card:hover { border-color: rgba(124,58,237,.5); background: rgba(124,58,237,.05); }
    .price-card.featured { border-color: rgba(124,58,237,.6); background: linear-gradient(135deg, rgba(124,58,237,.1), rgba(34,211,238,.05)); transform: scale(1.02); }
    .price-card h3 { font-size: 22px; margin: 0 0 8px; color: #fff; }
    .price-card .price { font-size: 42px; font-weight: 800; color: #a78bfa; margin: 16px 0; }
    .price-card .price-period { color: #8a8aa0; font-size: 14px; margin-bottom: 20px; }
    .price-card .features { text-align: left; margin: 20px 0; font-size: 14px; color: #d4d4d8; }
    .price-card .features li { margin: 8px 0; padding-left: 24px; position: relative; }
    .price-card .features li:before { content: "✓"; position: absolute; left: 0; color: #34d399; font-weight: 700; }
    .price-card form { margin-top: 20px; }
    .form-group { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
    .form-group select { padding: 10px; border: 1px solid rgba(255,255,255,.1); border-radius: 8px; background: rgba(255,255,255,.05); color: #fff; font-size: 14px; }
    .btn-subscribe { width: 100%; padding: 12px; margin-top: 12px; background: linear-gradient(135deg, #7c3aed, #5b21b6); border: 0; color: #fff; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 14px; transition: all .2s ease; }
    .btn-subscribe:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(124,58,237,.4); }
    .btn-subscribe:disabled { opacity: 0.5; cursor: not-allowed; }
    .back-link { margin-bottom: 20px; }
    .back-link a { color: #a78bfa; text-decoration: none; font-size: 14px; }
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
        <a href="/index.php">Accueil</a>
        <a href="/pricing.php">Tarifs</a>
      </nav>
      <div class="nav-actions">
        <a class="btn btn-ghost" href="/account/settings.php">Mon compte</a>
        <a class="btn" href="/auth/logout.php">Se déconnecter</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:1200px">
        <div class="back-link">
          <a href="/dashboard.php">← Retour aux launchers</a>
        </div>

        <h1 class="section-title">🚀 Activer <?php echo e((string)$selected['name']); ?></h1>
        <p class="section-desc">Choisissez une formule pour déployer votre launcher en production. Vous recevrez tous les outils et l'infrastructure nécessaires.</p>

        <div class="pricing-grid">
          <?php foreach (['starter', 'pro', 'premium'] as $plan):
            $basePrice = subscription_plan_base_cents();
            $baseCents = $basePrice[$plan];
            $baseEuro = $baseCents / 100;
            $isFeatured = ($plan === 'pro');
          ?>
            <div class="price-card <?php echo $isFeatured ? 'featured' : ''; ?>">
              <h3><?php echo ucfirst($plan); ?></h3>
              <p style="color: #8a8aa0; font-size: 14px; margin: 6px 0 12px; height: 40px">
                <?php
                  $descs = [
                    'starter' => 'Idéal pour débuter — features essentielles',
                    'pro'     => 'Recommandé — tous les outils inclus',
                    'premium' => 'Complet — support prioritaire + features avancées',
                  ];
                  echo $descs[$plan] ?? '';
                ?>
              </p>

              <div style="margin: 20px 0; border-top: 1px solid rgba(255,255,255,.1); border-bottom: 1px solid rgba(255,255,255,.1); padding: 20px 0">
                <div class="price"><?php echo number_format($baseEuro, 0); ?>€<span style="font-size: 18px; color: #8a8aa0">/mois</span></div>
                <p style="font-size: 12px; color: #8a8aa0; margin: 8px 0">Facturation flexible : mensuelle, trimestrielle, semestrielle ou annuelle</p>
              </div>

              <ul class="features">
                <?php
                  $features = [
                    'starter' => [
                      '1 launcher',
                      'Launcher Windows/Mac/Linux',
                      '15 extensions incluses',
                      'Support email',
                      'Mises à jour auto',
                    ],
                    'pro' => [
                      '3 launchers',
                      'Launcher Windows/Mac/Linux',
                      'Toutes les extensions',
                      'Support prioritaire',
                      'Mises à jour auto',
                      'Analytics avancées',
                    ],
                    'premium' => [
                      'Launchers illimités',
                      'Launcher Windows/Mac/Linux',
                      'Toutes les extensions',
                      'Support 24/7 dédié',
                      'Mises à jour auto',
                      'Analytics avancées',
                      'Consultation personnalisée',
                    ],
                  ];
                  foreach ($features[$plan] ?? [] as $feat):
                ?>
                  <li><?php echo $feat; ?></li>
                <?php endforeach; ?>
              </ul>

              <form action="/api/subscription_checkout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="plan" value="<?php echo e($plan); ?>" />

                <div class="form-group">
                  <select name="period" required style="grid-column: 1 / -1">
                    <option value="">Choisir périodicité...</option>
                    <option value="monthly" selected>Mensuel (plein tarif)</option>
                    <option value="quarterly">Trimestriel (-5%)</option>
                    <option value="semestrial">Semestriel (-10%)</option>
                    <option value="yearly">Annuel (-15%)</option>
                  </select>
                </div>

                <button class="btn-subscribe" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>
                  Souscrire maintenant →
                </button>

                <?php if (!$stripeConfigured): ?>
                  <p style="margin-top: 8px; font-size: 12px; color: var(--danger,#ff7676)">⚠ Stripe non configuré. Contact l'administrateur.</p>
                <?php endif; ?>
              </form>
            </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top: 60px; padding: 20px; background: rgba(255,255,255,.03); border-radius: 12px; border: 1px solid rgba(255,255,255,.06)">
          <h3 style="margin: 0 0 12px; color: #fff">Besoin d'aide?</h3>
          <p style="margin: 0; color: #8a8aa0; font-size: 14px">Consultez nos <a href="/pricing.php" style="color: #a78bfa">tarifs détaillés</a> ou contactez notre support pour toute question.</p>
        </div>
      </div>
    </section>
  </main>

  <footer style="margin-top: 40px; padding: 20px; border-top: 1px solid rgba(255,255,255,.06); text-align: center; color: #8a8aa0; font-size: 12px">
    <p>&copy; 2026 XynoLauncher. Tous droits réservés.</p>
  </footer>
</body>
</html>

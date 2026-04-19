<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

start_secure_session();

$user = current_user();

$success = flash_get('success');
$error = flash_get('error');

// Plan / fréquence récupérés depuis la page tarifs (lecture seule ici).
$planFromUrl   = strtolower((string)($_GET['plan']   ?? ''));
$periodFromUrl = strtolower((string)($_GET['period'] ?? ''));

$planLabels = [
    'starter' => 'Starter',
    'pro'     => 'Pro',
    'premium' => 'Premium',
];
$periodLabels = [
    'monthly'    => 'Mensuel',
    'quarterly'  => 'Trimestriel',
    'semestrial' => 'Semestriel',
    'yearly'     => 'Annuel',
];
$planLabel   = $planLabels[$planFromUrl]     ?? '';
$periodLabel = $periodLabels[$periodFromUrl] ?? '';

// Defaults attribués automatiquement : le plan choisi à la souscription
// donne déjà accès à tous les thèmes, modules et versions — rien à choisir ici.
// Tout peut être ajusté plus tard depuis le dashboard.
$defaultTheme   = 'Violet Neon';
$defaultVersion = '1.21.4';
$defaultLoader  = 'fabric';
$defaultModules = 'modpack,news,discord,autoupdate,analytics';

// Hébergement : seul vrai choix restant côté création (affecte la facturation).
$hostingMonthly = 5;

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Créer un launcher — XynoLauncher</title>
  <meta name="description" content="Crée ton launcher en moins d'une minute : un nom, une description, on s'occupe du reste." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <header class="navbar">
    <div class="container nav-inner">
      <a class="brand" href="index.php" aria-label="XynoLauncher">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>XynoLauncher</span>
      </a>

      <nav class="nav-links" aria-label="Navigation principale">
        <a href="index.php">Accueil</a>
        <a href="pricing.php">Tarifs</a>
        <a href="builder.php" aria-current="page">Builder</a>
        <a href="self-hosting.php">Auto-hébergement</a>
        <?php if ($user !== null): ?><a href="dashboard.php">Dashboard</a><?php endif; ?>
      </nav>

      <div class="nav-actions">
        <?php if ($user === null): ?>
          <a class="btn btn-ghost" href="login.php">Connexion</a>
          <a class="btn btn-primary" href="register.php">Créer un compte</a>
        <?php else: ?>
          <a class="btn btn-ghost" href="dashboard.php">Dashboard</a>
          <a class="btn" href="logout.php">Se déconnecter</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container">

        <div class="callout" style="margin-bottom:22px">
          <div>
            <span class="section-eyebrow">Création de launcher</span>
            <h1 class="section-title" style="margin-top:10px">Un nom, une description. On s’occupe du reste.</h1>
            <p class="section-desc" style="margin-top:10px">Ton offre t’ouvre déjà l’accès à tous les thèmes, modules et versions Minecraft. Tout se personnalise ensuite depuis le dashboard.</p>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <?php if ($planLabel !== ''): ?>
              <span class="badge badge-accent">Offre : <strong style="color:#fff"><?php echo e($planLabel); ?></strong></span>
            <?php endif; ?>
            <?php if ($periodLabel !== ''): ?>
              <span class="badge">Facturation : <strong style="color:rgba(255,255,255,.92)"><?php echo e($periodLabel); ?></strong></span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="grid-2" style="align-items:stretch">

          <section class="card card-lg">
            <span class="section-eyebrow">Informations</span>
            <h2 class="section-title" style="margin-top:10px;font-size:22px">Ton launcher</h2>
            <p class="section-desc" style="margin-top:6px">Visible par tes joueurs et dans le dashboard.</p>

            <?php if ($user === null): ?>
              <div style="margin-top:18px">
                <p class="small">Tu dois être connecté pour créer un launcher.</p>
                <div class="cta-row" style="margin-top:12px">
                  <a class="btn btn-primary" href="login.php">Se connecter</a>
                  <a class="btn" href="register.php">Créer un compte</a>
                </div>
              </div>
            <?php else: ?>
              <form class="form" action="create_launcher.php" method="post">
                <label class="label">
                  <span>Nom du launcher</span>
                  <input class="input" name="name" placeholder="Ex : Xyno RP" maxlength="80" required autofocus />
                </label>

                <label class="label">
                  <span>Description (optionnel)</span>
                  <input class="input" name="description" placeholder="Ex : Serveur RP 1.21 · Communauté francophone" maxlength="160" />
                </label>

                <fieldset class="card" style="margin-top:6px;background:var(--surface-2)">
                  <legend class="small" style="padding:0 8px;color:var(--muted)">Hébergement des fichiers de jeu</legend>
                  <div style="display:grid;gap:10px;margin-top:4px">
                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                      <input type="radio" name="hosting" value="no" checked />
                      <span>
                        <strong style="color:#fff">Auto-hébergement — gratuit</strong><br>
                        <span class="small">Tu héberges toi-même tes mods et assets (S3, Cloudflare R2, VPS, hébergement mutualisé…). L'API du launcher reste chez nous. <a href="self-hosting.php" target="_blank" rel="noopener">Comment ça marche →</a></span>
                      </span>
                    </label>
                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                      <input type="radio" name="hosting" value="yes" />
                      <span>
                        <strong style="color:#fff">Hébergement Xyno — +<?= (int)$hostingMonthly ?>€/mois</strong><br>
                        <span class="small">On héberge aussi tes mods : tu n'as rien à configurer, pas de stockage tiers à gérer.</span>
                      </span>
                    </label>
                  </div>
                </fieldset>

                <!-- Entitlements hérités du plan : tout activé côté création, configurable ensuite depuis le dashboard. -->
                <input type="hidden" name="theme"   value="<?php echo e($defaultTheme); ?>" />
                <input type="hidden" name="version" value="<?php echo e($defaultVersion); ?>" />
                <input type="hidden" name="loader"  value="<?php echo e($defaultLoader); ?>" />
                <input type="hidden" name="modules" value="<?php echo e($defaultModules); ?>" />
                <input type="hidden" name="plan"    value="<?php echo e($planFromUrl); ?>" />
                <input type="hidden" name="period"  value="<?php echo e($periodFromUrl); ?>" />

                <div class="cta-row" style="margin-top:6px">
                  <button class="btn btn-primary btn-lg" type="submit">Créer mon launcher</button>
                  <a class="btn btn-ghost" href="dashboard.php">Plus tard</a>
                </div>

                <p class="small" style="margin:2px 0 0">Thème, modules et version Minecraft se règlent ensuite dans le dashboard — tu peux tout changer à la volée.</p>
              </form>
            <?php endif; ?>
          </section>

          <aside class="card card-lg" aria-label="Ce qui est inclus">
            <span class="section-eyebrow">Inclus avec ton offre</span>
            <h2 class="section-title" style="margin-top:10px;font-size:22px">Accès immédiat</h2>
            <p class="section-desc" style="margin-top:6px">Dès la création, tu disposes de tout ce qui fait un bon launcher.</p>

            <ul class="list" style="margin-top:16px">
              <li><span class="check" aria-hidden="true"></span><span>Les 3 thèmes premium : Violet Neon, Glacier, Cosmic</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Tous les modules : auto-update, news, Discord, modpack, analytics</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Support Fabric · Forge · Quilt, versions 1.19 → 1.21</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Builds Windows · macOS · Linux signés via GitHub Actions</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Dashboard admin et API Xyno gérés côté plateforme — rien à installer</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Tout est modifiable depuis le dashboard, à tout moment</span></li>
            </ul>

            <div style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border-1)">
              <p class="small" style="margin:0">Tu n’as pas encore choisi d’offre ?</p>
              <div class="cta-row" style="margin-top:10px">
                <a class="btn" href="pricing.php">Voir les tarifs</a>
                <a class="btn btn-ghost" href="self-hosting.php">Auto-héberger</a>
              </div>
            </div>
          </aside>

        </div>

      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div>
        <div class="brand" style="margin-bottom:10px">
          <span class="brand-mark" aria-hidden="true"></span>
          <span>XynoLauncher</span>
        </div>
        <p class="small">© <span id="year">2026</span> XynoLauncher — Plateforme SaaS de launchers Minecraft.</p>
      </div>
      <div>
        <h4>Produit</h4>
        <p class="small"><a href="pricing.php">Tarifs</a></p>
        <p class="small"><a href="builder.php">Builder</a></p>
        <p class="small"><a href="self-hosting.php">Auto-hébergement</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="login.php">Connexion</a></p>
        <p class="small"><a href="register.php">Inscription</a></p>
        <?php if ($user !== null): ?><p class="small"><a href="dashboard.php">Dashboard</a></p><?php endif; ?>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

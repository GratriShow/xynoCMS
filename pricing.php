<?php

declare(strict_types=1);

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tarifs — XynoLauncher</title>
  <meta name="description" content="Compare les offres XynoLauncher en mensuel et annuel, avec réduction visible sur l’annuel." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <script src="assets/main.js" defer></script>
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
        <a href="builder.php">Builder</a>
        <a href="dashboard.php">Dashboard</a>
      </nav>

      <div class="nav-actions">
        <a class="btn btn-ghost" href="login.php">Connexion</a>
        <a class="btn btn-primary" href="builder.php">Commencer</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container" data-pricing-root>
        <h1 class="h-title" style="font-size:clamp(30px,3.4vw,44px)">Des offres claires, prêtes pour la prod</h1>
        <p class="section-desc">Passe en annuel et économise : réduction visible, facturation simple, upgrade à tout moment.</p>

        <div class="callout" style="margin-top:14px; padding:16px">
          <div>
            <p class="badge">Règle simple</p>
            <p class="section-desc" style="margin-top:8px">
              <strong style="color:rgba(255,255,255,.92)">Abonnement par launcher.</strong>
              Chaque abonnement correspond à ton launcher. Besoin de plusieurs launchers ? Souscris plusieurs abonnements.
            </p>
          </div>
          <div class="badge">Orienté conversion • lisible • scalable</div>
        </div>

        <div class="toggle" data-billing-toggle data-target="pricing-target" aria-label="Facturation">
          <button type="button" data-billing="monthly" aria-pressed="true">Mensuel</button>
          <button type="button" data-billing="yearly" aria-pressed="false">Annuel</button>
          <span class="badge" data-yearly-savings hidden>Économise ~20%</span>
        </div>

        <div id="pricing-target" class="pricing-grid" aria-label="Cartes de prix">
          <article class="card" data-plan="basic" data-price-monthly="9" data-price-yearly="86">
            <p class="badge">Basic</p>
            <p class="price"><span data-price>9</span>€ <small data-price-suffix>/mois</small></p>
            <p class="section-desc" style="margin-top:8px">Pour lancer un premier launcher rapidement, sans options superflues.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>Launcher inclus</li>
              <li><span class="check" aria-hidden="true"></span>Thèmes (accès de base)</li>
              <li><span class="check" aria-hidden="true"></span>Modules limités</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary" data-choose href="builder.php?plan=basic&billing=monthly">Choisir</a>
            </div>
            <p class="small" style="margin:10px 0 0">Abonnement par launcher.</p>
          </article>

          <article class="card" data-plan="pro" data-price-monthly="19" data-price-yearly="182" style="border-color:rgba(124,58,237,.35)">
            <p class="badge">Pro • Le plus populaire</p>
            <p class="price"><span data-price>19</span>€ <small data-price-suffix>/mois</small></p>
            <p class="section-desc" style="margin-top:8px">Le meilleur ratio conversion / fonctionnalités.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>Launcher inclus</li>
              <li><span class="check" aria-hidden="true"></span>Modules avancés</li>
              <li><span class="check" aria-hidden="true"></span>Auto-update + analytics</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary" data-choose href="builder.php?plan=pro&billing=monthly">Choisir</a>
            </div>
            <p class="small" style="margin:10px 0 0">Abonnement par launcher.</p>
          </article>

          <article class="card" data-plan="premium" data-price-monthly="39" data-price-yearly="374">
            <p class="badge">Premium</p>
            <p class="price"><span data-price>39</span>€ <small data-price-suffix>/mois</small></p>
            <p class="section-desc" style="margin-top:8px">Pour une expérience premium : tout activé, prêt à scaler.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>Launcher inclus</li>
              <li><span class="check" aria-hidden="true"></span>Toutes les fonctionnalités</li>
              <li><span class="check" aria-hidden="true"></span>Support prioritaire</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary" data-choose href="builder.php?plan=premium&billing=monthly">Choisir</a>
            </div>
            <p class="small" style="margin:10px 0 0">Abonnement par launcher.</p>
          </article>
        </div>

        <div class="callout" style="margin-top:18px">
          <div>
            <h2 class="section-title" style="margin:0">Besoin d’un devis ?</h2>
            <p class="section-desc" style="margin-top:8px">Commence par le builder : tu obtiens un récap clair, prêt à être connecté à Stripe.</p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary" href="builder.php?plan=pro&billing=yearly">Configurer</a>
            <a class="btn" href="index.php">Retour accueil</a>
          </div>
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
        <p class="small">Tarification simple pour un SaaS orienté conversion.</p>
        <p class="small">© <span id="year">2026</span> XynoLauncher.</p>
      </div>
      <div>
        <h4>Produit</h4>
        <p class="small"><a href="pricing.php">Tarifs</a></p>
        <p class="small"><a href="builder.php">Builder</a></p>
        <p class="small"><a href="dashboard.php">Dashboard</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="login.php">Connexion</a></p>
        <p class="small"><a href="register.php">Inscription</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

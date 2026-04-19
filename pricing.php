<?php

declare(strict_types=1);

// Adresse de support. Remplace par ton adresse réelle si besoin.
$supportEmail = 'support@xynoweb.fr';

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tarifs — XynoLauncher</title>
  <meta name="description" content="3 formules claires, facturation mensuelle, options incluses par plan. Besoin d’un design personnalisé ? Contacte le support." />
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
      <div class="container">
        <h1 class="h-title" style="font-size:clamp(30px,3.4vw,44px)">Des offres claires, sans surprise</h1>
        <p class="section-desc">Trois formules, facturation mensuelle. Choisis celle qui correspond à ton launcher, upgrade à tout moment.</p>

        <div class="callout" style="margin-top:14px; padding:16px">
          <div>
            <p class="badge">Règle simple</p>
            <p class="section-desc" style="margin-top:8px">
              <strong style="color:rgba(255,255,255,.92)">Abonnement par launcher.</strong>
              Chaque formule couvre un launcher. Tu en veux plusieurs ? Un abonnement par launcher.
            </p>
          </div>
          <div class="badge">Tarifs mensuels • TVA incluse</div>
        </div>

        <div class="pricing-grid" aria-label="Cartes de prix" style="margin-top:24px">

          <!-- =================== STARTER =================== -->
          <article class="card" data-plan="starter">
            <p class="badge">Starter</p>
            <p class="price"><span>9</span>€ <small>/mois</small></p>
            <p class="section-desc" style="margin-top:8px">Parfait pour démarrer un premier launcher sans prise de tête.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>1 launcher inclus</li>
              <li><span class="check" aria-hidden="true"></span>1 plateforme au choix (Windows, macOS ou Linux)</li>
              <li><span class="check" aria-hidden="true"></span>1 thème au choix parmi Violet Neon / Glacier / Cosmic</li>
              <li><span class="check" aria-hidden="true"></span>Modules essentiels : Play, Paramètres</li>
              <li><span class="check" aria-hidden="true"></span>Auto-update du launcher</li>
              <li><span class="check" aria-hidden="true"></span>Support communautaire</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary" href="builder.php?plan=starter">Choisir Starter</a>
            </div>
            <p class="small" style="margin:10px 0 0">Abonnement par launcher.</p>
          </article>

          <!-- =================== PRO (highlight) =================== -->
          <article class="card" data-plan="pro" style="border-color:rgba(124,58,237,.5); box-shadow:0 0 0 1px rgba(124,58,237,.25), 0 20px 60px -20px rgba(124,58,237,.35)">
            <p class="badge">Pro • Le plus populaire</p>
            <p class="price"><span>19</span>€ <small>/mois</small></p>
            <p class="section-desc" style="margin-top:8px">Le bon équilibre pour une vraie communauté active.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>1 launcher inclus</li>
              <li><span class="check" aria-hidden="true"></span>Les 3 plateformes (Windows + macOS + Linux)</li>
              <li><span class="check" aria-hidden="true"></span>Les 3 thèmes débloqués</li>
              <li><span class="check" aria-hidden="true"></span>Tous les modules : Play, Paramètres, News, Discord, Mods</li>
              <li><span class="check" aria-hidden="true"></span>Auto-update + rollback sur version précédente</li>
              <li><span class="check" aria-hidden="true"></span>Analytics de base (téléchargements, lancements)</li>
              <li><span class="check" aria-hidden="true"></span>Support par email (réponse sous 48 h)</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary" href="builder.php?plan=pro">Choisir Pro</a>
            </div>
            <p class="small" style="margin:10px 0 0">Abonnement par launcher.</p>
          </article>

          <!-- =================== PREMIUM =================== -->
          <article class="card" data-plan="premium">
            <p class="badge">Premium</p>
            <p class="price"><span>39</span>€ <small>/mois</small></p>
            <p class="section-desc" style="margin-top:8px">Tout le Pro + branding complet et support prioritaire.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>Tout ce qui est inclus dans Pro</li>
              <li><span class="check" aria-hidden="true"></span>Branding complet (logo, splashscreen, icônes de l’app)</li>
              <li><span class="check" aria-hidden="true"></span>Personnalisation des couleurs du thème</li>
              <li><span class="check" aria-hidden="true"></span>Analytics avancés (joueurs actifs, rétention, crash reports)</li>
              <li><span class="check" aria-hidden="true"></span>Canal bêta pour pré-publier des mises à jour</li>
              <li><span class="check" aria-hidden="true"></span>Support prioritaire (réponse sous 24 h)</li>
              <li><span class="check" aria-hidden="true"></span>Accès anticipé aux nouvelles fonctionnalités</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary" href="builder.php?plan=premium">Choisir Premium</a>
            </div>
            <p class="small" style="margin:10px 0 0">Abonnement par launcher.</p>
          </article>

        </div>

        <!-- =================== DESIGN SUR-MESURE =================== -->
        <div class="callout" style="margin-top:28px; padding:20px; background:linear-gradient(135deg,rgba(124,58,237,.12),rgba(34,211,238,.06)); border-color:rgba(124,58,237,.3)">
          <div>
            <p class="badge">Design sur-mesure</p>
            <h2 class="section-title" style="margin:10px 0 6px">Tu veux un design personnalisé ?</h2>
            <p class="section-desc" style="margin-top:6px">
              Thème unique, interface sur-mesure, illustrations spécifiques, identité de marque complète&nbsp;:
              on travaille directement avec toi pour créer un launcher qui te ressemble à 100 %.
              Contacte notre support pour obtenir un devis personnalisé.
            </p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>?subject=Demande%20de%20design%20personnalis%C3%A9%20-%20XynoLauncher">Contacter le support</a>
          </div>
        </div>

        <p class="small" style="margin-top:18px; color:rgba(255,255,255,.55); text-align:center">
          Les prix affichés sont en euros TTC, facturés mensuellement.
          Tu peux changer de formule ou résilier à tout moment depuis ton dashboard.
        </p>
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
        <p class="small"><a href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>">Support</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

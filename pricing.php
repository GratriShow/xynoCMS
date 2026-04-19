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
  <meta name="description" content="3 formules, 4 fréquences de facturation (mensuel, trimestriel, semestriel, annuel). Hébergement Xyno ou auto-hébergement. Jusqu’à −15 % en annuel." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <script src="assets/main.js" defer></script>
  <style>
    /* --- Billing toggle (mensuel/trim/sem/annuel) --- */
    .billing-toggle{
      display:inline-flex; flex-wrap:wrap; gap:6px;
      padding:6px; margin-top:14px;
      background:rgba(255,255,255,.04);
      border:1px solid rgba(255,255,255,.08);
      border-radius:999px;
    }
    .bt-btn{
      appearance:none; cursor:pointer;
      padding:8px 14px; border-radius:999px;
      background:transparent; color:rgba(255,255,255,.75);
      border:1px solid transparent;
      font:600 13px/1 'Inter', system-ui, sans-serif;
      display:inline-flex; align-items:center; gap:6px;
      transition:background .15s ease, color .15s ease, border-color .15s ease;
    }
    .bt-btn:hover{ color:#fff; background:rgba(255,255,255,.04); }
    .bt-btn.is-active{
      color:#fff;
      background:linear-gradient(135deg, rgba(124,58,237,.35), rgba(34,211,238,.2));
      border-color:rgba(124,58,237,.45);
      box-shadow:0 0 0 1px rgba(124,58,237,.35) inset;
    }
    .bt-btn .pill{
      font-size:11px; font-weight:700;
      padding:2px 6px; border-radius:999px;
      background:rgba(34,211,238,.15); color:#7de4ff;
      border:1px solid rgba(34,211,238,.35);
    }
    .billing-note{
      margin:4px 0 0; color:rgba(255,255,255,.55);
      font-size:13px;
    }
  </style>
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
        <a href="self-hosting.php">Auto-hébergement</a>
        <a href="dashboard.php">Dashboard</a>
      </nav>

      <div class="nav-actions">
        <a class="btn btn-ghost" href="login.php">Connexion</a>
        <a class="btn btn-primary" href="register.php">Commencer</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <h1 class="h-title" style="font-size:clamp(30px,3.4vw,44px)">Des offres claires, sans surprise</h1>
        <p class="section-desc">Trois formules, quatre fréquences de facturation. Choisis la cadence qui te convient, change d’avis à tout moment.</p>

        <!-- =================== Billing toggle =================== -->
        <div class="billing-toggle" role="tablist" aria-label="Fréquence de facturation">
          <button type="button" class="bt-btn is-active" data-period="monthly" role="tab" aria-selected="true">Mensuel</button>
          <button type="button" class="bt-btn" data-period="quarterly" role="tab" aria-selected="false">Trimestriel <span class="pill">−5%</span></button>
          <button type="button" class="bt-btn" data-period="semestrial" role="tab" aria-selected="false">Semestriel <span class="pill">−10%</span></button>
          <button type="button" class="bt-btn" data-period="yearly" role="tab" aria-selected="false">Annuel <span class="pill">−15%</span></button>
        </div>

        <div class="callout" style="margin-top:14px; padding:16px">
          <div>
            <p class="badge">Règle simple</p>
            <p class="section-desc" style="margin-top:8px">
              <strong style="color:rgba(255,255,255,.92)">Abonnement par launcher.</strong>
              Chaque formule couvre un launcher. Tu choisis ensuite ton hébergement (Xyno ou auto) directement dans l’étape de configuration.
            </p>
          </div>
          <div class="badge">TVA incluse • Sans engagement</div>
        </div>

        <div class="pricing-grid" aria-label="Cartes de prix" style="margin-top:24px">

          <!-- =================== STARTER =================== -->
          <article class="card" data-plan="starter"
            data-price-monthly="9"      data-billed-monthly="Facturé 9€ chaque mois"
            data-price-quarterly="8,55" data-billed-quarterly="Soit 25,65€ facturés tous les 3 mois"
            data-price-semestrial="8,10" data-billed-semestrial="Soit 48,60€ facturés tous les 6 mois"
            data-price-yearly="7,65"    data-billed-yearly="Soit 91,80€ facturés une fois par an">
            <p class="badge">Starter</p>
            <p class="price"><span class="price-amount">9</span>€ <small class="price-period">/mois</small></p>
            <p class="billing-note">Facturé 9€ chaque mois</p>
            <p class="section-desc" style="margin-top:10px">Parfait pour démarrer un premier launcher sans prise de tête.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span>1 launcher inclus</li>
              <li><span class="check" aria-hidden="true"></span>1 plateforme au choix (Windows, macOS ou Linux)</li>
              <li><span class="check" aria-hidden="true"></span>1 thème au choix parmi Violet Neon / Glacier / Cosmic</li>
              <li><span class="check" aria-hidden="true"></span>Modules essentiels : Play, Paramètres</li>
              <li><span class="check" aria-hidden="true"></span>Auto-update du launcher</li>
              <li><span class="check" aria-hidden="true"></span>Support communautaire</li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary cta-subscribe" data-base="builder.php?plan=starter" href="builder.php?plan=starter&amp;period=monthly">Choisir Starter</a>
            </div>
            <p class="small" style="margin:10px 0 0">Tu configures ton launcher dans la foulée.</p>
          </article>

          <!-- =================== PRO (highlight) =================== -->
          <article class="card" data-plan="pro"
            data-price-monthly="19"      data-billed-monthly="Facturé 19€ chaque mois"
            data-price-quarterly="18,05" data-billed-quarterly="Soit 54,15€ facturés tous les 3 mois"
            data-price-semestrial="17,10" data-billed-semestrial="Soit 102,60€ facturés tous les 6 mois"
            data-price-yearly="16,15"    data-billed-yearly="Soit 193,80€ facturés une fois par an"
            style="border-color:rgba(124,58,237,.5); box-shadow:0 0 0 1px rgba(124,58,237,.25), 0 20px 60px -20px rgba(124,58,237,.35)">
            <p class="badge">Pro • Le plus populaire</p>
            <p class="price"><span class="price-amount">19</span>€ <small class="price-period">/mois</small></p>
            <p class="billing-note">Facturé 19€ chaque mois</p>
            <p class="section-desc" style="margin-top:10px">Le bon équilibre pour une vraie communauté active.</p>
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
              <a class="btn btn-primary cta-subscribe" data-base="builder.php?plan=pro" href="builder.php?plan=pro&amp;period=monthly">Choisir Pro</a>
            </div>
            <p class="small" style="margin:10px 0 0">Tu configures ton launcher dans la foulée.</p>
          </article>

          <!-- =================== PREMIUM =================== -->
          <article class="card" data-plan="premium"
            data-price-monthly="39"      data-billed-monthly="Facturé 39€ chaque mois"
            data-price-quarterly="37,05" data-billed-quarterly="Soit 111,15€ facturés tous les 3 mois"
            data-price-semestrial="35,10" data-billed-semestrial="Soit 210,60€ facturés tous les 6 mois"
            data-price-yearly="33,15"    data-billed-yearly="Soit 397,80€ facturés une fois par an">
            <p class="badge">Premium</p>
            <p class="price"><span class="price-amount">39</span>€ <small class="price-period">/mois</small></p>
            <p class="billing-note">Facturé 39€ chaque mois</p>
            <p class="section-desc" style="margin-top:10px">Tout le Pro + branding complet et support prioritaire.</p>
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
              <a class="btn btn-primary cta-subscribe" data-base="builder.php?plan=premium" href="builder.php?plan=premium&amp;period=monthly">Choisir Premium</a>
            </div>
            <p class="small" style="margin:10px 0 0">Tu configures ton launcher dans la foulée.</p>
          </article>

        </div>

        <!-- =================== DESIGN SUR-MESURE =================== -->
        <div class="callout" style="margin-top:28px; padding:20px; background:linear-gradient(135deg,rgba(124,58,237,.12),rgba(34,211,238,.06)); border-color:rgba(124,58,237,.3)">
          <div>
            <p class="badge">Design sur-mesure</p>
            <h2 class="section-title" style="margin:10px 0 6px">Tu veux un design personnalisé&nbsp;?</h2>
            <p class="section-desc" style="margin-top:6px">
              Thème unique, interface sur-mesure, illustrations spécifiques, identité de marque complète&nbsp;:
              on travaille directement avec toi pour créer un launcher qui te ressemble à 100&nbsp;%.
              Contacte notre support pour obtenir un devis personnalisé.
            </p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>?subject=Demande%20de%20design%20personnalis%C3%A9%20-%20XynoLauncher">Contacter le support</a>
          </div>
        </div>

        <p class="small" style="margin-top:18px; color:rgba(255,255,255,.55); text-align:center">
          Les prix affichés sont en euros TTC. Tu peux changer de formule, de fréquence ou résilier à tout moment depuis ton dashboard.
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
        <p class="small"><a href="self-hosting.php">Auto-hébergement</a></p>
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
    // Footer year
    document.getElementById('year').textContent = String(new Date().getFullYear());

    // --- Billing toggle ------------------------------------------------------
    (function () {
      var btns  = document.querySelectorAll('.bt-btn');
      var cards = document.querySelectorAll('.card[data-plan]');
      var ctas  = document.querySelectorAll('.cta-subscribe');

      function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

      function applyPeriod(period) {
        btns.forEach(function (b) {
          var on = b.dataset.period === period;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        cards.forEach(function (card) {
          var priceKey  = 'price'  + cap(period);   // ex: priceMonthly
          var billedKey = 'billed' + cap(period);
          var priceVal  = card.dataset[priceKey];
          var billedVal = card.dataset[billedKey];

          var amountEl = card.querySelector('.price-amount');
          var noteEl   = card.querySelector('.billing-note');

          if (amountEl && priceVal)  amountEl.textContent = priceVal;
          if (noteEl   && billedVal) noteEl.textContent   = billedVal;
        });

        ctas.forEach(function (a) {
          var base = a.dataset.base;
          if (base) a.href = base + '&period=' + period;
        });
      }

      btns.forEach(function (b) {
        b.addEventListener('click', function () {
          applyPeriod(b.dataset.period);
        });
      });
    })();
  </script>
</body>
</html>

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
  <style>
    /* --- Billing toggle (mensuel/trim/sem/annuel) : aligné sur .toggle --- */
    .billing-toggle{
      display:inline-flex; flex-wrap:wrap; gap: 4px;
      padding: 5px; margin-top: 14px;
      background: var(--surface);
      border: 1px solid var(--border-2);
      border-radius: 999px;
    }
    .bt-btn{
      appearance:none; cursor:pointer;
      padding: 9px 14px; border-radius: 999px;
      background: transparent; color: var(--muted);
      border: 0;
      font: 700 13px/1 Inter, system-ui, sans-serif;
      display:inline-flex; align-items:center; gap: 6px;
      transition: background .15s, color .15s;
    }
    .bt-btn:hover{ color:#fff; }
    .bt-btn.is-active{
      color:#fff;
      background: var(--grad-primary);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,.14);
    }
    .bt-btn .pill{
      font-size: 11px; font-weight: 700;
      padding: 2px 7px; border-radius: 999px;
      background: rgba(34,211,238,.15); color:#7de4ff;
      border: 1px solid rgba(34,211,238,.35);
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
        <a href="pricing.php" aria-current="page">Tarifs</a>
        <a href="self-hosting.php">Auto-hébergement</a>
        <a href="builder.php">Builder</a>
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
        <div class="section-head" style="max-width:820px">
          <span class="section-eyebrow">Tarifs</span>
          <h1 class="section-title">Des offres claires, sans surprise.</h1>
          <p class="section-desc">Trois formules, quatre fréquences de facturation. Choisis la cadence qui te convient, change d’avis à tout moment.</p>
        </div>

        <!-- =================== Billing toggle =================== -->
        <div class="billing-toggle" role="tablist" aria-label="Fréquence de facturation">
          <button type="button" class="bt-btn is-active" data-period="monthly" role="tab" aria-selected="true">Mensuel</button>
          <button type="button" class="bt-btn" data-period="quarterly" role="tab" aria-selected="false">Trimestriel <span class="pill">−5%</span></button>
          <button type="button" class="bt-btn" data-period="semestrial" role="tab" aria-selected="false">Semestriel <span class="pill">−10%</span></button>
          <button type="button" class="bt-btn" data-period="yearly" role="tab" aria-selected="false">Annuel <span class="pill">−15%</span></button>
        </div>

        <div class="callout" style="margin-top:18px">
          <div>
            <span class="section-eyebrow">Règle simple</span>
            <p class="section-desc" style="margin-top:8px">
              <strong style="color:#fff">Un abonnement par launcher.</strong>
              Toutes les formules donnent déjà accès aux 3 thèmes et à tous les modules. Tu choisis ton hébergement (Xyno ou auto) au moment de la création.
            </p>
          </div>
          <span class="badge">TVA incluse · Sans engagement</span>
        </div>

        <div class="pricing-grid" aria-label="Cartes de prix" style="margin-top:24px">

          <!-- =================== STARTER =================== -->
          <article class="pricing-card" data-plan="starter"
            data-price-monthly="9"      data-billed-monthly="Facturé 9€ chaque mois"
            data-price-quarterly="8,55" data-billed-quarterly="Soit 25,65€ facturés tous les 3 mois"
            data-price-semestrial="8,10" data-billed-semestrial="Soit 48,60€ facturés tous les 6 mois"
            data-price-yearly="7,65"    data-billed-yearly="Soit 91,80€ facturés une fois par an">
            <span class="badge">Starter</span>
            <p class="price"><span class="price-amount">9</span>€ <small class="price-period">/mois</small></p>
            <span class="billing-note">Facturé 9€ chaque mois</span>
            <p class="section-desc" style="margin-top:12px">Parfait pour démarrer un premier launcher sans prise de tête.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span><span>1 launcher inclus</span></li>
              <li><span class="check" aria-hidden="true"></span><span>1 plateforme au choix (Windows, macOS ou Linux)</span></li>
              <li><span class="check" aria-hidden="true"></span><span>1 thème au choix parmi Violet Neon / Glacier / Cosmic</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Modules essentiels : Play, Paramètres</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Auto-update du launcher</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Support communautaire</span></li>
            </ul>
            <div class="cta-row">
              <a class="btn cta-subscribe" data-base="builder.php?plan=starter" href="builder.php?plan=starter&amp;period=monthly">Choisir Starter</a>
            </div>
            <p class="small" style="margin:12px 0 0">Tu configures ton launcher dans la foulée.</p>
          </article>

          <!-- =================== PRO (highlight) =================== -->
          <article class="pricing-card is-featured" data-plan="pro"
            data-price-monthly="19"      data-billed-monthly="Facturé 19€ chaque mois"
            data-price-quarterly="18,05" data-billed-quarterly="Soit 54,15€ facturés tous les 3 mois"
            data-price-semestrial="17,10" data-billed-semestrial="Soit 102,60€ facturés tous les 6 mois"
            data-price-yearly="16,15"    data-billed-yearly="Soit 193,80€ facturés une fois par an">
            <span class="badge badge-accent">Pro · Le plus choisi</span>
            <p class="price"><span class="price-amount">19</span>€ <small class="price-period">/mois</small></p>
            <span class="billing-note">Facturé 19€ chaque mois</span>
            <p class="section-desc" style="margin-top:12px">Le bon équilibre pour une vraie communauté active.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span><span>1 launcher inclus</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Les 3 plateformes (Windows + macOS + Linux)</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Les 3 thèmes débloqués</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Tous les modules : Play, Paramètres, News, Discord, Mods</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Auto-update + rollback sur version précédente</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Analytics de base (téléchargements, lancements)</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Support par email (réponse sous 48 h)</span></li>
            </ul>
            <div class="cta-row">
              <a class="btn btn-primary cta-subscribe" data-base="builder.php?plan=pro" href="builder.php?plan=pro&amp;period=monthly">Choisir Pro</a>
            </div>
            <p class="small" style="margin:12px 0 0">Tu configures ton launcher dans la foulée.</p>
          </article>

          <!-- =================== PREMIUM =================== -->
          <article class="pricing-card" data-plan="premium"
            data-price-monthly="39"      data-billed-monthly="Facturé 39€ chaque mois"
            data-price-quarterly="37,05" data-billed-quarterly="Soit 111,15€ facturés tous les 3 mois"
            data-price-semestrial="35,10" data-billed-semestrial="Soit 210,60€ facturés tous les 6 mois"
            data-price-yearly="33,15"    data-billed-yearly="Soit 397,80€ facturés une fois par an">
            <span class="badge">Premium</span>
            <p class="price"><span class="price-amount">39</span>€ <small class="price-period">/mois</small></p>
            <span class="billing-note">Facturé 39€ chaque mois</span>
            <p class="section-desc" style="margin-top:12px">Tout le Pro + branding complet et support prioritaire.</p>
            <ul class="list">
              <li><span class="check" aria-hidden="true"></span><span>Tout ce qui est inclus dans Pro</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Branding complet (logo, splashscreen, icônes de l’app)</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Personnalisation des couleurs du thème</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Analytics avancés (joueurs actifs, rétention, crash reports)</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Canal bêta pour pré-publier des mises à jour</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Support prioritaire (réponse sous 24 h)</span></li>
              <li><span class="check" aria-hidden="true"></span><span>Accès anticipé aux nouvelles fonctionnalités</span></li>
            </ul>
            <div class="cta-row">
              <a class="btn cta-subscribe" data-base="builder.php?plan=premium" href="builder.php?plan=premium&amp;period=monthly">Choisir Premium</a>
            </div>
            <p class="small" style="margin:12px 0 0">Tu configures ton launcher dans la foulée.</p>
          </article>

        </div>

        <!-- =================== DESIGN SUR-MESURE =================== -->
        <div class="callout" style="margin-top:32px">
          <div>
            <span class="section-eyebrow">Design sur-mesure</span>
            <h2 class="section-title" style="margin-top:10px;font-size:24px">Tu veux un design personnalisé ?</h2>
            <p class="section-desc" style="margin-top:8px">
              Thème unique, interface sur-mesure, illustrations spécifiques, identité de marque complète :
              on travaille directement avec toi pour créer un launcher qui te ressemble à 100 %.
              Contacte-nous pour un devis.
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
      var cards = document.querySelectorAll('[data-plan]');
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

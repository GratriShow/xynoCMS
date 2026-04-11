<?php

declare(strict_types=1);

// Herd/Laravel-style setups may rewrite every request to index.php.
// If that happens, dispatch to the requested existing .php file so
// links like /login.php don't render the landing page.
$requestPath = (string) (parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$requestPath = $requestPath === '' ? '/' : $requestPath;

if ($requestPath !== '/' && $requestPath !== '/index.php' && str_ends_with($requestPath, '.php')) {
  $publicRoot = realpath(__DIR__) ?: __DIR__;
  $candidate = __DIR__ . $requestPath;
  $candidateReal = realpath($candidate);

  if ($candidateReal !== false
    && str_starts_with($candidateReal, $publicRoot)
    && is_file($candidateReal)
    && is_readable($candidateReal)
  ) {
    require $candidateReal;
    exit;
  }
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>XynoLauncher — Crée ton launcher Minecraft personnalisé</title>
  <meta name="description" content="Plateforme SaaS pour créer, configurer et déployer un launcher Minecraft à ton image. Thèmes, modules, auto-update et hébergement optionnel." />
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
    <section class="hero">
      <div class="container hero-grid">
        <div>
          <p class="badge h-eyebrow">SaaS • Launchers Minecraft • Abonnements</p>
          <h1 class="h-title">Crée ton launcher Minecraft personnalisé</h1>
          <p class="h-subtitle">Un builder simple, des thèmes premium, des modules prêts à l’emploi et un déploiement rapide. Conçu pour convertir et scaler.</p>

          <div class="cta-row">
            <a class="btn btn-primary" href="builder.php">Commencer</a>
            <a class="btn" href="pricing.php">Voir les prix</a>
          </div>

          <div class="kpis" aria-label="Indicateurs">
            <div class="kpi"><strong>5 min</strong><br><span class="small">pour générer un prototype</span></div>
            <div class="kpi"><strong>Thèmes</strong><br><span class="small">prévisualisés & sélectionnables</span></div>
            <div class="kpi"><strong>Modules</strong><br><span class="small">auto-update, news, Discord</span></div>
          </div>
        </div>

        <div class="mockup" aria-label="Mockup launcher">
          <div class="mockup-top">
            <div class="mockup-dots" aria-hidden="true">
              <span class="dot red"></span>
              <span class="dot yellow"></span>
              <span class="dot green"></span>
            </div>
            <span class="badge">Launcher Preview</span>
          </div>
          <div class="mockup-body">
            <div class="mockup-side">
              <div class="mockup-pill" style="width: 80%"></div>
              <div class="mockup-pill" style="width: 60%"></div>
              <div class="mockup-pill" style="width: 72%"></div>
              <div class="mockup-pill" style="width: 50%"></div>
            </div>
            <div class="mockup-main">
              <div class="mockup-card">
                <p class="badge">Version 1.21.4 • Fabric</p>
                <h3 style="margin:10px 0 6px;letter-spacing:-0.02em;">Joue en un clic</h3>
                <p style="margin:0;color:rgba(255,255,255,.72);">Auto-update, gestion de mods et profils, news intégrées.</p>
              </div>
              <div class="mockup-card">
                <p class="badge">Thème Violet</p>
                <div class="mockup-pill" style="height: 14px;width: 92%; margin: 12px 0 0"></div>
                <div class="mockup-pill" style="height: 14px;width: 86%; margin: 10px 0 0"></div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>

    <section class="section">
      <div class="container">
        <h2 class="section-title">Fonctionnalités pensées pour la conversion</h2>
        <p class="section-desc">Chaque étape est conçue pour vendre un abonnement : preview immédiate, options claires, récapitulatif et upgrade en un clic.</p>

        <div class="grid-3">
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M7 12h10M7 7h10M7 17h6" stroke="rgba(255,255,255,.9)" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </div>
            <h3>Builder multi-étapes</h3>
            <p>Choix du thème, modules, version, loader et hébergement avec un prix dynamique.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2l3 7 7 3-7 3-3 7-3-7-7-3 7-3 3-7z" stroke="rgba(255,255,255,.9)" stroke-width="2" stroke-linejoin="round"/>
              </svg>
            </div>
            <h3>Thèmes premium</h3>
            <p>3 thèmes prêts pour une identité forte, avec aperçu avant achat.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 12l5 5L20 7" stroke="rgba(255,255,255,.9)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <h3>Déploiement simplifié</h3>
            <p>Hébergement optionnel et fichiers organisés pour passer en prod sans friction.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container">
        <h2 class="section-title">Thèmes</h2>
        <p class="section-desc">Choisis un style et personnalise ensuite : couleurs, layout, modules et options.</p>

        <div class="grid-3">
          <article class="card">
            <div class="theme-preview" aria-hidden="true"></div>
            <h3>Violet Neon</h3>
            <p>Look premium, accents violet/bleu, contrastes élevés.</p>
          </article>
          <article class="card">
            <div class="theme-preview" style="background: radial-gradient(240px 120px at 30% 30%, rgba(34,211,238,.32), transparent 60%), radial-gradient(220px 140px at 70% 50%, rgba(37,99,235,.26), transparent 60%), rgba(255,255,255,.04);" aria-hidden="true"></div>
            <h3>Glacier</h3>
            <p>Minimal et lisible, idéal pour un launcher clean.</p>
          </article>
          <article class="card">
            <div class="theme-preview" style="background: radial-gradient(240px 120px at 30% 30%, rgba(124,58,237,.30), transparent 60%), radial-gradient(220px 140px at 70% 50%, rgba(251,146,60,.18), transparent 60%), rgba(255,255,255,.04);" aria-hidden="true"></div>
            <h3>Cosmic</h3>
            <p>Ambiance "gaming" moderne, parfaite pour des serveurs RP/PvP.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section-sm">
      <div class="container">
        <div class="callout">
          <div>
            <h2 class="section-title" style="margin:0">Prêt à lancer ton SaaS ?</h2>
            <p class="section-desc" style="margin-top:8px">Démarre avec le builder, puis connecte le paiement et ton backend quand tu es prêt.</p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary" href="builder.php">Commencer</a>
            <a class="btn" href="pricing.php">Voir les prix</a>
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
        <p class="small">Plateforme SaaS pour créer des launchers Minecraft orientés conversion.</p>
        <p class="small">© <span id="year">2026</span> XynoLauncher. Tous droits réservés.</p>
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
    // Tiny, page-local
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

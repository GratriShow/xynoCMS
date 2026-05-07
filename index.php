<?php

declare(strict_types=1);

// Herd/Laravel-style setups may rewrite every request to index.php.
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$requestPath = $requestPath === '' ? '/' : $requestPath;

if ($requestPath !== '/' && $requestPath !== '/index.php' && str_ends_with($requestPath, '.php')) {
  $publicRoot = realpath(__DIR__) ?: __DIR__;
  $candidate  = __DIR__ . $requestPath;
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
  <title>XynoLauncher — Crée ton launcher Minecraft en quelques minutes</title>
  <meta name="description" content="Plateforme SaaS pour créer, configurer et déployer un launcher Minecraft à ton image. 3 thèmes premium, modules prêts à l'emploi, auto-update et hébergement en option." />
  <link rel="canonical" href="https://xynocms.xynoweb.fr/" />
  <meta property="og:type"        content="website" />
  <meta property="og:url"         content="https://xynocms.xynoweb.fr/" />
  <meta property="og:title"       content="XynoLauncher — Crée ton launcher Minecraft en quelques minutes" />
  <meta property="og:description" content="Builder no-code, Stripe intégré, auto-update, hébergement Xyno ou auto-hébergé. 3 thèmes premium pour faire décoller ton serveur." />
  <meta property="og:image"       content="https://xynocms.xynoweb.fr/assets/social/og-default.svg" />
  <meta property="og:image:width"  content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:site_name"   content="XynoLauncher" />
  <meta property="og:locale"      content="fr_FR" />
  <meta name="twitter:card"        content="summary_large_image" />
  <meta name="twitter:title"       content="XynoLauncher — Crée ton launcher Minecraft" />
  <meta name="twitter:description" content="Builder no-code, Stripe intégré, auto-update, hébergement Xyno ou auto-hébergé." />
  <meta name="twitter:image"       content="https://xynocms.xynoweb.fr/assets/social/og-default.svg" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <style>
    /* ── Page-specific inline styles ── */

    /* Feature cards two-column layout */
    .feat-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap: 14px;
    }
    .feat-grid .card-wide { grid-column: span 2; }
    @media (max-width: 860px) {
      .feat-grid { grid-template-columns: 1fr; }
      .feat-grid .card-wide { grid-column: span 1; }
    }

    /* Wide feat card — horizontal layout */
    .feat-card.card-wide {
      display: flex; align-items: flex-start; gap: 24px;
    }
    .feat-card.card-wide .feat-icon { margin-bottom: 0; flex-shrink: 0; }
    .feat-card.card-wide .feat-body { min-width: 0; }

    /* Icon */
    .feat-icon {
      width: 48px; height: 48px; border-radius: 14px;
      display: grid; place-items: center;
      background: var(--accent-soft);
      border: 1px solid var(--accent-border);
      color: var(--accent-light);
      margin-bottom: 20px; flex-shrink: 0;
    }

    /* Thin bottom gradient on hero */
    .hero-fade {
      position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
      background: linear-gradient(90deg, transparent 0%, var(--border-1) 30%, var(--border-2) 50%, var(--border-1) 70%, transparent 100%);
    }

    /* Footer brand */
    .footer-brand {
      display: flex; align-items: center; gap: 10px;
      font-weight: 800; letter-spacing: -.03em; font-size: 15px;
      color: var(--text); margin-bottom: 14px;
    }

    /* CTA inner layout tweaks */
    .cta-inner .section-eyebrow { display: flex; justify-content: center; }
    .cta-inner .section-title   { font-size: clamp(32px, 4.5vw, 56px); text-align: center; }
    .cta-inner .section-desc    { margin-inline: auto; text-align: center; }
    .cta-inner .cta-row         { justify-content: center; margin-top: 36px; }
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <!-- ── NAVBAR ── -->
  <header class="navbar" id="navbar">
    <div class="container nav-inner">
      <a class="brand" href="index.php" aria-label="XynoLauncher">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>XynoLauncher</span>
      </a>
      <nav class="nav-links" aria-label="Navigation principale">
        <a href="index.php" aria-current="page">Accueil</a>
        <a href="#designs">Designs</a>
        <a href="pricing.php">Tarifs</a>
        <a href="self-hosting.php">Auto-hébergement</a>
        <a href="builder.php">Builder</a>
      </nav>
      <div class="nav-actions">
        <a class="btn btn-ghost" href="login.php">Connexion</a>
        <a class="btn btn-primary" href="pricing.php">Commencer →</a>
      </div>
    </div>
  </header>

  <main id="contenu">

    <!-- ════════════════════════════════
         HERO — centré, launchers en strip
         ════════════════════════════════ -->
    <section class="hero" style="position:relative">
      <div class="container">
        <div class="hero-center">

          <a class="h-announce" href="pricing.php">
            <span>Nouveau</span>
            <span style="opacity:.4">·</span>
            <span>3 thèmes premium inclus dès le plan Starter</span>
            <span class="arr">→</span>
          </a>

          <h1 class="h-title">
            Un launcher Minecraft<br>
            <span class="grad">à l'image de ton serveur.</span>
          </h1>

          <p class="h-subtitle">
            Configure, déploie, encaisse. XynoLauncher assemble ton launcher sur mesure —
            Fabric, Forge ou Quilt, builds Windows / macOS / Linux signés,
            backend CMS et auto-update compris.
          </p>

          <div class="cta-row">
            <a class="btn btn-primary btn-lg" href="pricing.php">Choisir une offre →</a>
            <a class="btn btn-lg" href="#designs">Voir les designs</a>
          </div>

          <div class="kpis" aria-label="Indicateurs clés">
            <div class="kpi"><strong>~5 min</strong> · pour un premier build</div>
            <div class="kpi"><strong>1.7 → 1.21</strong> · toutes les versions</div>
            <div class="kpi"><strong>3 OS</strong> · builds natifs signés</div>
            <div class="kpi"><strong>Résiliation</strong> · en 1 clic</div>
          </div>
        </div>

        <!-- 3 Launchers en strip sous le hero -->
        <div class="hero-launchers" aria-hidden="true">

          <div class="launcher l-violet">
            <div class="launcher-titlebar">
              <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
              <span class="launcher-title">Neon Club · Launcher</span>
              <div class="launcher-title-actions"><span></span><span></span></div>
            </div>
            <div class="launcher-body">
              <aside class="launcher-sidebar">
                <div class="launcher-logo">N</div>
                <div class="launcher-nav">
                  <div class="launcher-nav-item active">Play</div>
                  <div class="launcher-nav-item">Mods</div>
                  <div class="launcher-nav-item">Shop</div>
                  <div class="launcher-nav-item">⚙︎</div>
                </div>
              </aside>
              <div class="launcher-main">
                <div class="launcher-hero-banner">
                  <span class="tag">NEON NIGHT</span>
                  <h4>Saison neuronale · −40% VIP</h4>
                </div>
                <div class="launcher-play-row">
                  <div class="launcher-play">LANCER</div>
                  <span class="launcher-version-chip">1.21.4 · Fabric</span>
                </div>
                <div class="launcher-news">
                  <div class="launcher-news-item"><b>Patch 2.4</b><span>Refonte skills</span></div>
                  <div class="launcher-news-item"><b>Boss raid</b><span>Vendredi 21h</span></div>
                </div>
              </div>
            </div>
            <div class="launcher-status">
              <span class="online">online</span>
              <span>312 / 500 joueurs</span>
            </div>
          </div>

          <div class="launcher l-glacier">
            <div class="launcher-titlebar">
              <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
              <span class="launcher-title">XynoRP · Launcher</span>
              <div class="launcher-title-actions"><span></span><span></span></div>
            </div>
            <div class="launcher-body">
              <aside class="launcher-sidebar">
                <div class="launcher-logo">X</div>
                <div class="launcher-nav">
                  <div class="launcher-nav-item active">Play</div>
                  <div class="launcher-nav-item">News</div>
                  <div class="launcher-nav-item">Wiki</div>
                  <div class="launcher-nav-item">⚙︎</div>
                </div>
              </aside>
              <div class="launcher-main">
                <div class="launcher-hero-banner">
                  <span class="tag">GLACIER</span>
                  <h4>Bienvenue sur XynoRP</h4>
                </div>
                <div class="launcher-play-row">
                  <div class="launcher-play">JOUER</div>
                  <span class="launcher-version-chip">1.21.4 · Fabric</span>
                </div>
                <div class="launcher-news">
                  <div class="launcher-news-item"><b>Saison 3</b><span>Biomes custom</span></div>
                  <div class="launcher-news-item"><b>Event week-end</b><span>Drop ×2</span></div>
                </div>
              </div>
            </div>
            <div class="launcher-status">
              <span class="online">online</span>
              <span>Ping 18ms · 412 joueurs</span>
            </div>
          </div>

          <div class="launcher l-cosmic">
            <div class="launcher-titlebar">
              <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
              <span class="launcher-title">Cosmos MC · Launcher</span>
              <div class="launcher-title-actions"><span></span><span></span></div>
            </div>
            <div class="launcher-body">
              <aside class="launcher-sidebar">
                <div class="launcher-logo">✦</div>
                <div class="launcher-nav">
                  <div class="launcher-nav-item active">Play</div>
                  <div class="launcher-nav-item">Quêtes</div>
                  <div class="launcher-nav-item">Shop</div>
                  <div class="launcher-nav-item">⚙︎</div>
                </div>
              </aside>
              <div class="launcher-main">
                <div class="launcher-hero-banner">
                  <span class="tag">COSMIC</span>
                  <h4>Voyage stellaire · saison 4</h4>
                </div>
                <div class="launcher-play-row">
                  <div class="launcher-play">EXPLORER</div>
                  <span class="launcher-version-chip">1.21.4 · Quilt</span>
                </div>
                <div class="launcher-news">
                  <div class="launcher-news-item"><b>Planète Nova</b><span>Dispo ce soir</span></div>
                  <div class="launcher-news-item"><b>Compagnons</b><span>Système dispo</span></div>
                </div>
              </div>
            </div>
            <div class="launcher-status">
              <span class="online">online</span>
              <span>Ping 31ms · 720 joueurs</span>
            </div>
          </div>

        </div><!-- /hero-launchers -->
      </div>
      <div class="hero-fade" aria-hidden="true"></div>
    </section>

    <!-- ════════════════════════════════
         TRUST STRIP
         ════════════════════════════════ -->
    <section class="trust-strip" aria-label="Chiffres clés">
      <div class="container">
        <div class="trust-row">
          <div class="trust-item">
            <strong>3</strong>
            <span>Thèmes premium</span>
          </div>
          <div class="trust-item">
            <strong>5+</strong>
            <span>Modules plug-and-play</span>
          </div>
          <div class="trust-item">
            <strong>3 OS</strong>
            <span>Builds natifs signés</span>
          </div>
          <div class="trust-item">
            <strong>&lt; 5 min</strong>
            <span>De l'offre au launcher</span>
          </div>
        </div>
      </div>
    </section>

    <!-- ════════════════════════════════
         DESIGNS — 3 thèmes showcase
         ════════════════════════════════ -->
    <section id="designs" class="section">
      <div class="container">
        <div class="section-head centered">
          <span class="section-eyebrow">3 Designs prêts à l'emploi</span>
          <h2 class="section-title">Choisis un look,<br><span class="grad">on gère le reste.</span></h2>
          <p class="section-desc">Chaque thème est un design complet : titlebar, navigation, bannière, bouton de lancement, news et statut. Tu personnalises le nom, les couleurs et les textes depuis le dashboard — sans rebuild.</p>
        </div>

        <div class="launcher-showcase">

          <div>
            <div class="launcher l-violet">
              <div class="launcher-titlebar">
                <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
                <span class="launcher-title">Neon Club · Launcher</span>
                <div class="launcher-title-actions"><span></span><span></span></div>
              </div>
              <div class="launcher-body">
                <aside class="launcher-sidebar">
                  <div class="launcher-logo">N</div>
                  <div class="launcher-nav">
                    <div class="launcher-nav-item active">Play</div>
                    <div class="launcher-nav-item">Mods</div>
                    <div class="launcher-nav-item">Shop</div>
                    <div class="launcher-nav-item">⚙︎</div>
                  </div>
                </aside>
                <div class="launcher-main">
                  <div class="launcher-hero-banner">
                    <span class="tag">NEON NIGHT</span>
                    <h4>Saison neuronale · −40% VIP</h4>
                  </div>
                  <div class="launcher-play-row">
                    <div class="launcher-play">LANCER</div>
                    <span class="launcher-version-chip">1.21.4 · Fabric</span>
                  </div>
                  <div class="launcher-news">
                    <div class="launcher-news-item"><b>Patch 2.4</b><span>Refonte des skills</span></div>
                    <div class="launcher-news-item"><b>Boss raid</b><span>Vendredi 21h</span></div>
                  </div>
                </div>
              </div>
              <div class="launcher-status"><span class="online">online</span><span>312 / 500 joueurs</span></div>
            </div>
            <div class="launcher-caption">
              <h3>Violet Neon</h3>
              <p>Identité forte, accents fluo · idéal pour un serveur RP / PvP moderne.</p>
            </div>
          </div>

          <div>
            <div class="launcher l-glacier">
              <div class="launcher-titlebar">
                <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
                <span class="launcher-title">Frostline · Launcher</span>
                <div class="launcher-title-actions"><span></span><span></span></div>
              </div>
              <div class="launcher-body">
                <aside class="launcher-sidebar">
                  <div class="launcher-logo">F</div>
                  <div class="launcher-nav">
                    <div class="launcher-nav-item active">Play</div>
                    <div class="launcher-nav-item">News</div>
                    <div class="launcher-nav-item">Wiki</div>
                    <div class="launcher-nav-item">⚙︎</div>
                  </div>
                </aside>
                <div class="launcher-main">
                  <div class="launcher-hero-banner">
                    <span class="tag">WINTER</span>
                    <h4>Frostline · v2</h4>
                  </div>
                  <div class="launcher-play-row">
                    <div class="launcher-play">JOUER</div>
                    <span class="launcher-version-chip">1.20.6 · Forge</span>
                  </div>
                  <div class="launcher-news">
                    <div class="launcher-news-item"><b>Nouvelle map</b><span>Biome Tundra</span></div>
                    <div class="launcher-news-item"><b>Mode Duo</b><span>Queue activée</span></div>
                  </div>
                </div>
              </div>
              <div class="launcher-status"><span class="online">online</span><span>Ping 22ms · 184 joueurs</span></div>
            </div>
            <div class="launcher-caption">
              <h3>Glacier</h3>
              <p>Minimal, lisible, glacial · parfait pour les serveurs survie / techniques.</p>
            </div>
          </div>

          <div>
            <div class="launcher l-cosmic">
              <div class="launcher-titlebar">
                <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
                <span class="launcher-title">Cosmos MC · Launcher</span>
                <div class="launcher-title-actions"><span></span><span></span></div>
              </div>
              <div class="launcher-body">
                <aside class="launcher-sidebar">
                  <div class="launcher-logo">✦</div>
                  <div class="launcher-nav">
                    <div class="launcher-nav-item active">Play</div>
                    <div class="launcher-nav-item">Quêtes</div>
                    <div class="launcher-nav-item">Boutique</div>
                    <div class="launcher-nav-item">⚙︎</div>
                  </div>
                </aside>
                <div class="launcher-main">
                  <div class="launcher-hero-banner">
                    <span class="tag">COSMIC</span>
                    <h4>Voyage stellaire · saison 4</h4>
                  </div>
                  <div class="launcher-play-row">
                    <div class="launcher-play">EXPLORER</div>
                    <span class="launcher-version-chip">1.21.4 · Quilt</span>
                  </div>
                  <div class="launcher-news">
                    <div class="launcher-news-item"><b>Planète Nova</b><span>Dispo ce soir</span></div>
                    <div class="launcher-news-item"><b>Compagnons</b><span>Système dispo</span></div>
                  </div>
                </div>
              </div>
              <div class="launcher-status"><span class="online">online</span><span>Ping 31ms · 720 joueurs</span></div>
            </div>
            <div class="launcher-caption">
              <h3>Cosmic</h3>
              <p>Gaming spatial, couleurs chaudes · top pour un serveur lore / aventure.</p>
            </div>
          </div>

        </div><!-- /launcher-showcase -->

        <div class="cta-row" style="justify-content:center; margin-top:36px">
          <a class="btn btn-primary" href="pricing.php">Débloquer les 3 designs</a>
          <a class="btn" href="self-hosting.php">Héberger tes mods toi-même</a>
        </div>
      </div>
    </section>

    <!-- ════════════════════════════════
         FONCTIONNALITÉS
         ════════════════════════════════ -->
    <section class="section" style="padding-top:0" aria-label="Fonctionnalités">
      <div class="container">
        <div class="section-head">
          <span class="section-eyebrow">Ce qui est inclus</span>
          <h2 class="section-title">Tout ce qu'il faut pour<br><span class="grad">convertir, sans friction.</span></h2>
          <p class="section-desc">Logo custom, toutes les versions Minecraft de 1.7 à 1.21, logs en direct, anti-abus, facturation transparente : tout est câblé dès la création.</p>
        </div>

        <div class="feat-grid">

          <article class="card card-lg card-glow feat-card card-wide">
            <div class="feat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            </div>
            <div class="feat-body">
              <h3>Builder éclair</h3>
              <p>Un nom, une description, et ton launcher est prêt à être distribué. Tout le reste se configure depuis le dashboard : logo, couleurs, news, mods. Aucune ligne de code requise.</p>
            </div>
          </article>

          <article class="card card-glow feat-card">
            <div class="feat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7 7 3-7 3-3 7-3-7-7-3 7-3 3-7z"/></svg>
            </div>
            <h3>3 thèmes + ton logo</h3>
            <p>Violet Neon, Glacier, Cosmic : 3 designs complets avec couleurs, gradients et typo propres. Upload ton logo dans l'app Electron en quelques secondes.</p>
          </article>

          <article class="card card-glow feat-card">
            <div class="feat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <h3>Toutes les versions Minecraft</h3>
            <p>De <strong style="color:var(--text)">1.7.10</strong> à <strong style="color:var(--text)">1.21.4</strong>, Fabric · Forge · Quilt. Tu changes de version à la volée depuis le dashboard — sans rebuild manuel.</p>
          </article>

          <article class="card card-lg card-glow feat-card card-wide">
            <div class="feat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            </div>
            <div class="feat-body">
              <h3>Builds multi-OS + auto-update silencieux</h3>
              <p>GitHub Actions compile et signe ton launcher pour Windows, macOS et Linux. Tes joueurs reçoivent les mises à jour en arrière-plan, sans interruption. Tu publies une release en 1 clic depuis le dashboard.</p>
            </div>
          </article>

          <article class="card card-glow feat-card">
            <div class="feat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l9 4v6c0 5-4 9-9 10-5-1-9-5-9-10V6l9-4z"/></svg>
            </div>
            <h3>Logs &amp; anti-abus</h3>
            <p>Logs en direct dans le dashboard. Rate-limit par IP, HMAC signé, builds bornés : les abus de downloads ou de builds sont bloqués côté plateforme.</p>
          </article>

          <article class="card card-glow feat-card">
            <div class="feat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </div>
            <h3>Facturation transparente</h3>
            <p>Date du prochain versement affichée dans le dashboard. Résiliation en 1 clic, ton accès reste actif jusqu'à la fin de la période payée.</p>
          </article>

        </div><!-- /feat-grid -->
      </div>
    </section>

    <!-- ════════════════════════════════
         COMMENT ÇA MARCHE
         ════════════════════════════════ -->
    <section class="section-sm" aria-label="Comment ça marche">
      <div class="container">
        <div class="section-head centered">
          <span class="section-eyebrow">En 3 étapes</span>
          <h2 class="section-title">De l'inscription<br><span class="grad">au premier joueur.</span></h2>
        </div>

        <div class="steps-grid">
          <article class="step-item">
            <div class="step-num">01</div>
            <h3>Choisis ton offre</h3>
            <p>Starter, Pro ou Premium — toutes les offres donnent accès aux thèmes et modules. La différence : la marque blanche, les modules avancés et le support prioritaire.</p>
          </article>
          <article class="step-item">
            <div class="step-num">02</div>
            <h3>Crée le launcher</h3>
            <p>Un nom, une description, un choix d'hébergement. Ton launcher est créé dans ton dashboard avec ses clés API et ses premiers builds disponibles immédiatement.</p>
          </article>
          <article class="step-item">
            <div class="step-num">03</div>
            <h3>Distribue et mets à jour</h3>
            <p>Envoie le lien à tes joueurs. Publie des news, releases et événements depuis le dashboard en un clic — tes joueurs reçoivent tout en arrière-plan.</p>
          </article>
        </div>
      </div>
    </section>

    <!-- ════════════════════════════════
         CTA BAND
         ════════════════════════════════ -->
    <section class="cta-band">
      <div class="container">
        <div class="cta-inner">
          <span class="section-eyebrow">Prêt à te lancer ?</span>
          <h2 class="section-title">Lance ton launcher Minecraft<br><span class="grad">aujourd'hui.</span></h2>
          <p class="section-desc">
            Tous les thèmes, ton logo custom, toutes les versions Minecraft.
            Facturation claire, résiliation en 1 clic.
            Auto-hébergement gratuit disponible.
          </p>
          <div class="cta-row">
            <a class="btn btn-primary btn-lg" href="pricing.php">Voir les tarifs →</a>
            <a class="btn btn-lg" href="self-hosting.php">Comment auto-héberger</a>
          </div>
        </div>
      </div>
    </section>

  </main>

  <!-- ── FOOTER ── -->
  <footer class="footer">
    <div class="container">
      <div class="footer-grid">

        <div>
          <div class="footer-brand">
            <span class="brand-mark" aria-hidden="true"></span>
            <span>XynoLauncher</span>
          </div>
          <p class="footer-brand-desc">Plateforme SaaS pour créer des launchers Minecraft — pensée conversion, pas bricolage.</p>
        </div>

        <div>
          <h4>Produit</h4>
          <nav class="footer-links">
            <a href="pricing.php">Tarifs</a>
            <a href="#designs">Designs</a>
            <a href="builder.php">Builder</a>
            <a href="self-hosting.php">Auto-hébergement</a>
          </nav>
        </div>

        <div>
          <h4>Compte</h4>
          <nav class="footer-links">
            <a href="login.php">Connexion</a>
            <a href="register.php">Inscription</a>
            <a href="dashboard.php">Dashboard</a>
          </nav>
        </div>

        <div>
          <h4>Légal</h4>
          <nav class="footer-links">
            <a href="mentions-legales.php">Mentions légales</a>
            <a href="politique-confidentialite.php">Confidentialité</a>
            <a href="politique-cookies.php">Cookies</a>
            <a href="cgu.php">CGU</a>
            <a href="cgv.php">CGV</a>
          </nav>
        </div>

      </div><!-- /footer-grid -->

      <div class="footer-bottom">
        <span>© <span id="year">2026</span> XynoLauncher. Tous droits réservés.</span>
        <span style="color:var(--muted-2)">Fait avec soin pour les créateurs de serveurs Minecraft.</span>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
    (function () {
      var nav = document.getElementById('navbar');
      if (!nav) return;
      var tick = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
      tick();
      window.addEventListener('scroll', tick, { passive: true });
    })();
  </script>
</body>
</html>

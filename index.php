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
  <title>XynoLauncher — Crée ton launcher Minecraft en quelques minutes</title>
  <meta name="description" content="Plateforme SaaS pour créer, configurer et déployer un launcher Minecraft à ton image. 3 thèmes premium, modules prêts à l'emploi, auto-update et hébergement en option." />
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
        <a href="index.php" aria-current="page">Accueil</a>
        <a href="#designs">Designs</a>
        <a href="pricing.php">Tarifs</a>
        <a href="self-hosting.php">Auto-hébergement</a>
        <a href="builder.php">Builder</a>
      </nav>

      <div class="nav-actions">
        <a class="btn btn-ghost" href="login.php">Connexion</a>
        <a class="btn btn-primary" href="pricing.php">Commencer</a>
      </div>
    </div>
  </header>

  <main id="contenu">

    <!-- =========================================================
         HERO
         ========================================================= -->
    <section class="hero">
      <div class="container hero-grid">
        <div>
          <span class="badge badge-accent h-eyebrow">Nouveau · 3 thèmes premium inclus</span>
          <h1 class="h-title">
            Un launcher Minecraft<br>
            <span class="grad">à l’image de ton serveur.</span>
          </h1>
          <p class="h-subtitle">
            Configure, déploie, encaisse. XynoLauncher assemble ton launcher sur mesure
            — Fabric, Forge ou Quilt, builds Windows/macOS/Linux signés,
            backend CMS et auto-update compris.
          </p>

          <div class="cta-row">
            <a class="btn btn-primary btn-lg" href="pricing.php">Choisir une offre</a>
            <a class="btn btn-lg" href="#designs">Voir les designs</a>
          </div>

          <div class="kpis" aria-label="Indicateurs">
            <div class="kpi"><strong>~5 min</strong> · pour un premier build</div>
            <div class="kpi"><strong>1.7 → 1.21</strong> · toutes les versions</div>
            <div class="kpi"><strong>Logo &amp; logs</strong> · inclus, sans rebuild</div>
          </div>
        </div>

        <!-- Hero mockup : le thème Violet Neon en grand -->
        <?php
          // Réutilise le même markup que les showcases pour rester cohérent.
          $heroVariant = 'l-violet';
          $heroTitle = 'XynoRP · Launcher';
          $heroBanner = 'Bienvenue sur XynoRP';
          $heroTag = 'VIOLET NEON';
        ?>
        <div class="launcher <?= $heroVariant ?>" style="max-width:520px;margin-left:auto">
          <div class="launcher-titlebar">
            <div class="launcher-dots"><span class="red"></span><span class="yellow"></span><span class="green"></span></div>
            <span class="launcher-title"><?= htmlspecialchars($heroTitle) ?></span>
            <div class="launcher-title-actions"><span></span><span></span></div>
          </div>
          <div class="launcher-body">
            <aside class="launcher-sidebar">
              <div class="launcher-logo">X</div>
              <div class="launcher-nav">
                <div class="launcher-nav-item active">Play</div>
                <div class="launcher-nav-item">Mods</div>
                <div class="launcher-nav-item">News</div>
                <div class="launcher-nav-item">Skin</div>
                <div class="launcher-nav-item">⚙︎</div>
              </div>
            </aside>
            <div class="launcher-main">
              <div class="launcher-hero-banner">
                <span class="tag"><?= $heroTag ?></span>
                <h4><?= htmlspecialchars($heroBanner) ?></h4>
              </div>
              <div class="launcher-play-row">
                <div class="launcher-play">JOUER</div>
                <span class="launcher-version-chip">1.21.4 · Fabric</span>
              </div>
              <div class="launcher-news">
                <div class="launcher-news-item"><b>Saison 3 ouverte</b><span>Nouveaux biomes custom</span></div>
                <div class="launcher-news-item"><b>Event week-end</b><span>Drop x2 sur toutes les mines</span></div>
              </div>
            </div>
          </div>
          <div class="launcher-status">
            <span class="online">Serveur en ligne</span>
            <span>Ping 18ms · 412 joueurs</span>
          </div>
        </div>

      </div>
    </section>

    <!-- =========================================================
         TRUST / CHIFFRES
         ========================================================= -->
    <section class="section-sm" aria-label="Chiffres">
      <div class="container">
        <div class="trust-row">
          <div class="trust-item"><strong>3</strong><span>Thèmes premium</span></div>
          <div class="trust-item"><strong>5+</strong><span>Modules plug-and-play</span></div>
          <div class="trust-item"><strong>3 OS</strong><span>Builds natifs signés</span></div>
          <div class="trust-item"><strong>&lt; 5 min</strong><span>De l’offre au launcher</span></div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         DESIGNS
         ========================================================= -->
    <section id="designs" class="section">
      <div class="container">
        <div class="section-head">
          <span class="section-eyebrow">3 Designs prêts à l’emploi</span>
          <h2 class="section-title">Choisis un look, on gère le reste.</h2>
          <p class="section-desc">Chaque thème est un design complet : titlebar, navigation, bannière, bouton de lancement, news et barre de statut. Tu personnalises le nom, les couleurs et les textes depuis le dashboard.</p>
        </div>

        <div class="launcher-showcase">

          <!-- Violet Neon -->
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
              <div class="launcher-status">
                <span class="online">online</span>
                <span>312 / 500 joueurs</span>
              </div>
            </div>
            <div class="launcher-caption">
              <h3>Violet Neon</h3>
              <p>Identité forte, accents fluo · parfait pour un serveur RP / PvP moderne.</p>
            </div>
          </div>

          <!-- Glacier -->
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
              <div class="launcher-status">
                <span class="online">online</span>
                <span>Ping 22ms · 184 joueurs</span>
              </div>
            </div>
            <div class="launcher-caption">
              <h3>Glacier</h3>
              <p>Minimal, lisible, glacial · idéal pour les serveurs survie / techniques.</p>
            </div>
          </div>

          <!-- Cosmic -->
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
              <div class="launcher-status">
                <span class="online">online</span>
                <span>Ping 31ms · 720 joueurs</span>
              </div>
            </div>
            <div class="launcher-caption">
              <h3>Cosmic</h3>
              <p>Gaming spatial, couleurs chaudes · top pour un serveur lore / aventure.</p>
            </div>
          </div>

        </div>

        <div class="cta-row" style="justify-content:center;margin-top:28px">
          <a class="btn btn-primary" href="pricing.php">Débloquer les 3 designs</a>
          <a class="btn" href="self-hosting.php">Héberger tes mods toi-même</a>
        </div>
      </div>
    </section>

    <!-- =========================================================
         FONCTIONNALITÉS
         ========================================================= -->
    <section class="section" aria-label="Fonctionnalités">
      <div class="container">
        <div class="section-head">
          <span class="section-eyebrow">Ce qui est inclus</span>
          <h2 class="section-title">Tout ce qu’il faut pour convertir, sans friction.</h2>
          <p class="section-desc">Logo custom, toutes les versions Minecraft de 1.7 à 1.21, logs en direct, anti-abus, facturation transparente : tout est câblé dès la création.</p>
        </div>

        <div class="grid-3">
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M7 12h10M7 7h10M7 17h6" stroke="rgba(255,255,255,.95)" stroke-width="2" stroke-linecap="round"/></svg>
            </div>
            <h3>Builder éclair</h3>
            <p>Un nom, une description, et ton launcher est prêt à être distribué. Tout le reste se règle ensuite depuis le dashboard.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 2l3 7 7 3-7 3-3 7-3-7-7-3 7-3 3-7z" stroke="rgba(255,255,255,.95)" stroke-width="2" stroke-linejoin="round"/></svg>
            </div>
            <h3>3 thèmes + ton logo</h3>
            <p>Violet Neon, Glacier, Cosmic : 3 designs complets. Upload ton propre logo sur l'app Electron en quelques secondes.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h10M4 18h16" stroke="rgba(255,255,255,.95)" stroke-width="2" stroke-linecap="round"/></svg>
            </div>
            <h3>Toutes les versions Minecraft</h3>
            <p>De <strong style="color:#fff">1.7.10</strong> à <strong style="color:#fff">1.21.4</strong>, Fabric · Forge · Quilt. Tu changes de version à la volée depuis le dashboard — sans rebuild manuel.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="rgba(255,255,255,.95)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h3>Builds multi-OS + auto-update</h3>
            <p>GitHub Actions compile et signe ton launcher pour Windows, macOS et Linux. Tes joueurs reçoivent les mises à jour en silence.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 2l9 4v6c0 5-4 9-9 10-5-1-9-5-9-10V6l9-4z" stroke="rgba(255,255,255,.95)" stroke-width="2" stroke-linejoin="round"/></svg>
            </div>
            <h3>Logs &amp; anti-abus</h3>
            <p>Consulte les logs de ton launcher en direct. Rate-limit par IP, HMAC signé, builds bornés : l'abus de downloads ou de builds est bloqué côté plateforme.</p>
          </article>
          <article class="card">
            <div class="icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7h18v10H3zM3 11h18" stroke="rgba(255,255,255,.95)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h3>Facturation transparente</h3>
            <p>Date du prochain versement affichée dans le dashboard. Résiliation en 1 clic, ton accès reste actif jusqu'à la fin de la période payée.</p>
          </article>
        </div>
      </div>
    </section>

    <!-- =========================================================
         PROCESS / COMMENT ÇA MARCHE
         ========================================================= -->
    <section class="section-sm" aria-label="Comment ça marche">
      <div class="container">
        <div class="section-head">
          <span class="section-eyebrow">En 3 étapes</span>
          <h2 class="section-title">De l’inscription au premier joueur.</h2>
        </div>

        <div class="grid-3">
          <article class="card">
            <span class="badge">01</span>
            <h3 style="margin-top:10px">Choisis ton offre</h3>
            <p>Starter, Pro ou Premium — toutes les offres donnent accès aux thèmes et modules. La différence : la marque blanche, les modules avancés et le support.</p>
          </article>
          <article class="card">
            <span class="badge">02</span>
            <h3 style="margin-top:10px">Crée le launcher</h3>
            <p>Un nom, une description, un choix d’hébergement. Ton launcher est créé dans ton dashboard avec ses clés API.</p>
          </article>
          <article class="card">
            <span class="badge">03</span>
            <h3 style="margin-top:10px">Distribue et mets à jour</h3>
            <p>Envoie le lien de téléchargement à tes joueurs. Publie des news, releases et événements depuis le dashboard en un clic.</p>
          </article>
        </div>
      </div>
    </section>

    <!-- =========================================================
         CTA FINAL
         ========================================================= -->
    <section class="section-sm">
      <div class="container">
        <div class="callout">
          <div>
            <span class="section-eyebrow">Prêt ?</span>
            <h2 class="section-title" style="margin-top:10px">Lance ton launcher Minecraft aujourd’hui.</h2>
            <p class="section-desc" style="margin-top:10px">Tous les thèmes, ton logo custom, toutes les versions Minecraft. Facturation claire, résiliation en 1 clic. Auto-hébergement gratuit disponible.</p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary btn-lg" href="pricing.php">Voir les tarifs</a>
            <a class="btn btn-lg" href="self-hosting.php">Comment auto-héberger</a>
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
        <p class="small">Plateforme SaaS pour créer des launchers Minecraft — pensée conversion, pas bricolage.</p>
        <p class="small">© <span id="year">2026</span> XynoLauncher. Tous droits réservés.</p>
      </div>
      <div>
        <h4>Produit</h4>
        <p class="small"><a href="pricing.php">Tarifs</a></p>
        <p class="small"><a href="#designs">Designs</a></p>
        <p class="small"><a href="builder.php">Builder</a></p>
        <p class="small"><a href="self-hosting.php">Auto-hébergement</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="login.php">Connexion</a></p>
        <p class="small"><a href="register.php">Inscription</a></p>
        <p class="small"><a href="dashboard.php">Dashboard</a></p>
      </div>
    </div>
  </footer>

  <script>
    // Année dynamique
    document.getElementById('year').textContent = String(new Date().getFullYear());

    // Petit "scrolled" sur la navbar
    (function(){
      var nav = document.querySelector('.navbar');
      if (!nav) return;
      var onScroll = function(){
        if (window.scrollY > 4) nav.classList.add('scrolled');
        else nav.classList.remove('scrolled');
      };
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    })();
  </script>
</body>
</html>

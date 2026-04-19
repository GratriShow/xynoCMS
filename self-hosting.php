<?php

declare(strict_types=1);

$supportEmail = 'support@xynoweb.fr';

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Auto-hébergement des fichiers — XynoLauncher</title>
  <meta name="description" content="Garde le contrôle de tes mods et assets : tu les héberges, nous gérons le launcher. Une clé API à poser dans un fichier, et c'est parti." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <style>
    .doc-grid{
      display:grid; gap: 24px; margin-top: 24px;
      grid-template-columns: 1fr;
    }
    @media (min-width: 960px){
      .doc-grid{ grid-template-columns: 280px 1fr; align-items:start; }
    }
    .toc{
      position: sticky; top: 84px;
      padding: 18px; border-radius: 14px;
      background: var(--surface);
      border: 1px solid var(--border-1);
    }
    .toc h4{
      margin: 0 0 12px; font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .14em;
      color: var(--muted-2);
    }
    .toc ol{
      margin: 0; padding-left: 20px;
      display:flex; flex-direction: column; gap: 6px;
    }
    .toc a{ color: var(--muted); font-size: 14px; }
    .toc a:hover{ color: var(--text); }

    .doc-section{
      padding: 24px; border-radius: var(--radius-lg);
      background: var(--surface);
      border: 1px solid var(--border-1);
      margin-bottom: 16px;
    }
    .doc-section h2{ margin: 0 0 4px; font-size: 22px; letter-spacing: -.02em; font-weight: 800; }
    .doc-section h3{ margin: 18px 0 6px; font-size: 15px; color: var(--text); font-weight: 700; }
    .doc-section p{ color: var(--muted); line-height: 1.65; }
    .doc-section ul, .doc-section ol{
      color: var(--muted); line-height: 1.75; padding-left: 22px;
    }
    .doc-section li + li{ margin-top: 4px; }

    pre.code{
      margin: 8px 0 0;
      padding: 14px 16px;
      background: rgba(0,0,0,.45);
      border: 1px solid var(--border-2);
      border-radius: 10px;
      overflow-x: auto;
      font: 500 13px/1.6 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
      color: #eaeaea;
    }
    code.inline{
      padding: 2px 6px; border-radius: 6px;
      background: rgba(255,255,255,.07); border: 1px solid var(--border-1);
      font: 500 12px/1 'JetBrains Mono', ui-monospace, monospace;
      color: #fff;
    }

    .callout-info{
      margin-top: 14px; padding: 14px 16px;
      border-radius: 12px;
      background: rgba(59,130,246,.08);
      border: 1px solid rgba(59,130,246,.30);
      color: var(--text);
      font-size: 14px;
    }
    .callout-info strong{ color: #93c5fd; }

    /* Petit schéma d'architecture en CSS pur */
    .arch{
      display: grid;
      grid-template-columns: 1fr auto 1fr auto 1fr;
      gap: 12px; align-items: stretch;
      margin-top: 16px;
    }
    @media (max-width: 860px){
      .arch{ grid-template-columns: 1fr; }
      .arch .arrow{ transform: rotate(90deg); height: 20px; }
    }
    .arch .node{
      padding: 16px; border-radius: 14px;
      border: 1px solid var(--border-2);
      background: var(--surface-2);
      text-align: center;
    }
    .arch .node b{
      display: block; color:#fff; margin-bottom: 4px;
      font-weight: 700; letter-spacing: -.01em;
    }
    .arch .node span{
      display: block; color: var(--muted-2); font-size: 12px;
    }
    .arch .node.accent{
      background: var(--grad-soft);
      border-color: rgba(139,92,246,.45);
    }
    .arch .arrow{
      align-self: center;
      font-size: 22px; color: var(--muted-2);
      min-width: 14px; text-align: center;
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
        <a href="self-hosting.php" aria-current="page">Auto-hébergement</a>
        <a href="builder.php">Builder</a>
      </nav>

      <div class="nav-actions">
        <a class="btn btn-ghost" href="login.php">Connexion</a>
        <a class="btn btn-primary" href="pricing.php">Commencer</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container">

        <div class="section-head" style="max-width:820px">
          <span class="section-eyebrow">Auto-hébergement</span>
          <h1 class="section-title">Héberge tes fichiers de jeu. On s'occupe du reste.</h1>
          <p class="section-desc">Tu gardes le contrôle total sur tes mods, resource packs et assets. Le launcher et son API restent chez nous. Tu n'as aucun CMS à installer — juste une clé API à poser dans un fichier, et un endroit où stocker tes fichiers.</p>
        </div>

        <!-- ================ SCHEMA D'ARCHITECTURE ================ -->
        <div class="doc-section" id="architecture" style="margin-top: 18px">
          <h2>Comment ça marche</h2>
          <p>Trois briques, chacune à sa place. Tu n'héberges que les fichiers — tout le reste est géré par la plateforme Xyno.</p>

          <div class="arch" aria-label="Schéma d'architecture">
            <div class="node">
              <b>Le launcher</b>
              <span>Installé chez tes joueurs<br>(Windows · macOS · Linux)</span>
            </div>
            <div class="arrow" aria-hidden="true">→</div>
            <div class="node accent">
              <b>API Xyno</b>
              <span>Auth, config, news, updates<br>sur notre VPS</span>
            </div>
            <div class="arrow" aria-hidden="true">→</div>
            <div class="node">
              <b>Ton stockage</b>
              <span>Mods, resource packs,<br>configs, skins…</span>
            </div>
          </div>

          <div class="callout-info">
            <strong>En clair :</strong> le launcher des joueurs parle à notre API pour savoir quoi faire, et
            l'API lui indique où télécharger les fichiers — sur <em>ton</em> stockage. Tu ne gères jamais
            d'authentification, de base de données, de HTTPS ou de signatures.
          </div>
        </div>

        <!-- ================ DOC LAYOUT ================ -->
        <div class="doc-grid">
          <aside class="toc" aria-label="Sommaire">
            <h4>Sommaire</h4>
            <ol>
              <li><a href="#architecture">Comment ça marche</a></li>
              <li><a href="#prereq">Ce qu'il te faut</a></li>
              <li><a href="#apikey">Récupérer la clé API</a></li>
              <li><a href="#config">Fichier de base</a></li>
              <li><a href="#assets">Uploader tes mods</a></li>
              <li><a href="#publish">Publier une version</a></li>
              <li><a href="#limits">Ce qui reste chez nous</a></li>
              <li><a href="#help">Besoin d'aide ?</a></li>
            </ol>
          </aside>

          <div>

            <!-- ========== Prérequis ========== -->
            <section id="prereq" class="doc-section">
              <h2>1. Ce qu'il te faut</h2>
              <p>L'auto-hébergement Xyno est volontairement léger. Pas de VPS à configurer, pas de base de données, pas de PHP. Il te faut juste un endroit capable de servir des fichiers en HTTPS :</p>
              <ul>
                <li>Un <strong>bucket S3</strong> (AWS, Scaleway, OVH Object Storage…)</li>
                <li>Ou un <strong>Cloudflare R2</strong> / <strong>Bunny Storage</strong> — excellents rapports qualité/prix</li>
                <li>Ou un simple <strong>VPS</strong> avec nginx qui sert un dossier statique</li>
                <li>Ou une <strong>hébergement mutualisé</strong> classique (OVH, o2switch, PlanetHoster…)</li>
              </ul>
              <p>Seule vraie contrainte : les fichiers doivent être accessibles via une URL publique en HTTPS, par exemple <code class="inline">https://assets.ton-serveur.fr/mods/foo.jar</code>.</p>
              <div class="callout-info">
                <strong>Pas d'hébergement ?</strong> On peut aussi tout héberger pour toi. Ajoute l'option <em>Hébergement Xyno</em> au moment de créer ton launcher (+5 €/mois) et ignore les étapes 2 à 4.
              </div>
            </section>

            <!-- ========== API KEY ========== -->
            <section id="apikey" class="doc-section">
              <h2>2. Récupérer ta clé API</h2>
              <ol>
                <li>Crée ton compte sur <a href="register.php">XynoLauncher</a> et choisis une <a href="pricing.php">offre</a>.</li>
                <li>Dans le builder, renseigne le nom de ton launcher et clique sur <em>Créer</em>.</li>
                <li>Ton dashboard affiche alors une section <strong>Intégration</strong> avec :</li>
              </ol>
              <ul>
                <li>Une <strong>clé API</strong> (garde-la secrète)</li>
                <li>L'URL de l'API Xyno à utiliser (ex : <code class="inline">https://api.xynolauncher.fr</code>)</li>
                <li>Un fichier <code class="inline">xyno.config.json</code> pré-rempli à télécharger</li>
              </ul>
              <div class="callout-info">
                <strong>La clé API identifie ton launcher</strong> — elle est valide tant que ton abonnement est actif. Si tu la renouvelles (depuis le dashboard), les joueurs n'auront rien à faire, le launcher reprendra tout seul.
              </div>
            </section>

            <!-- ========== CONFIG FILE ========== -->
            <section id="config" class="doc-section">
              <h2>3. Le fichier de base</h2>
              <p>C'est le seul fichier que tu déposes sur ton stockage. Il dit à l'API Xyno où trouver tes assets. Exemple :</p>
              <pre class="code">{
  "launcher_id": "ln_8f2b...c3d",
  "api_key":     "xyno_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "api_base":    "https://api.xynolauncher.fr",
  "assets_base": "https://assets.ton-serveur.fr",
  "manifest":    "manifest.json"
}</pre>
              <p>Les trois lignes importantes :</p>
              <ul>
                <li><code class="inline">api_key</code> — celle donnée par le dashboard</li>
                <li><code class="inline">assets_base</code> — la racine où tu uploades tes mods / resource packs</li>
                <li><code class="inline">manifest</code> — le fichier d'index qui liste toutes les versions (on le génère pour toi à chaque release)</li>
              </ul>
              <p>Dépose <code class="inline">xyno.config.json</code> à la racine de ton stockage, à côté de tes mods. Le launcher sait le retrouver automatiquement.</p>
            </section>

            <!-- ========== ASSETS ========== -->
            <section id="assets" class="doc-section">
              <h2>4. Uploader tes mods et assets</h2>
              <p>Organise les fichiers comme tu le souhaites, tant que la structure correspond au manifest. Une convention simple :</p>
              <pre class="code">assets.ton-serveur.fr/
├── xyno.config.json
├── manifest.json
├── mods/
│   ├── fabric-api-0.100.jar
│   ├── sodium-0.5.jar
│   └── …
├── resourcepacks/
│   └── pack-xynorp.zip
└── configs/
    └── options.txt</pre>
              <p>À chaque mise à jour, tu ajoutes ou remplaces les fichiers, puis tu pousses une nouvelle release depuis le dashboard (voir étape suivante). Les joueurs reçoivent la mise à jour automatiquement au prochain lancement du launcher.</p>
              <div class="callout-info">
                <strong>Astuce bande passante :</strong> active le cache côté stockage (Cache-Control 1 an) et utilise un CDN si ta communauté est grande. Cloudflare R2 + Cloudflare CDN donne un excellent résultat pour quelques euros par mois.
              </div>
            </section>

            <!-- ========== PUBLISH ========== -->
            <section id="publish" class="doc-section">
              <h2>5. Publier une version</h2>
              <ol>
                <li>Upload tes fichiers de jeu sur ton stockage.</li>
                <li>Va dans <em>Dashboard → Ton launcher → Releases</em>.</li>
                <li>Clique sur <em>Nouvelle release</em>, donne-lui un numéro (ex : <code class="inline">1.2.0</code>) et un nom.</li>
                <li>L'API scanne ton <code class="inline">assets_base</code>, génère automatiquement un <code class="inline">manifest.json</code> signé, et le publie à côté de tes fichiers.</li>
                <li>Le launcher de tes joueurs détecte la nouvelle release au prochain lancement et met à jour silencieusement ce qui a changé.</li>
              </ol>
              <p>Besoin d'automatiser ? Un webhook GitHub / une ligne de <code class="inline">curl</code> dans ton CI suffit :</p>
              <pre class="code">curl -X POST https://api.xynolauncher.fr/v2/releases \
  -H "Authorization: Bearer $XYNO_API_KEY" \
  -d '{"version":"1.2.0","notes":"Bugfixes &amp; new mod"}'</pre>
            </section>

            <!-- ========== LIMITS ========== -->
            <section id="limits" class="doc-section">
              <h2>6. Ce qui reste chez nous</h2>
              <p>Pour que tu n'aies rien à maintenir côté serveur, la plateforme Xyno garde à sa charge :</p>
              <ul>
                <li>Authentification des joueurs et gestion des sessions</li>
                <li>Génération et signature des manifests</li>
                <li>Auto-update du launcher lui-même (les exécutables Windows / macOS / Linux)</li>
                <li>News, événements, analytics opt-in</li>
                <li>Builds multi-OS via GitHub Actions (tu ne compiles rien)</li>
                <li>Rotation des clés API, révocation, anti-abus</li>
              </ul>
              <p>Toi, tu gardes le contrôle exclusif sur&nbsp;:</p>
              <ul>
                <li>Tes fichiers de jeu (mods, resource packs, configs, skins, map…)</li>
                <li>Leur organisation et leurs droits d'accès sur ton stockage</li>
                <li>La possibilité de changer de fournisseur de stockage à tout moment — tu mets à jour <code class="inline">assets_base</code> dans le dashboard, c'est tout</li>
              </ul>
            </section>

            <!-- ========== Help ========== -->
            <section id="help" class="doc-section">
              <h2>7. Besoin d'aide ?</h2>
              <p>L'auto-hébergement Xyno est pensé pour rester simple. Si tu bloques sur la mise en place :</p>
              <ul>
                <li>La FAQ du dashboard répond aux questions les plus courantes.</li>
                <li>Le support répond par email (réponse best-effort).</li>
                <li>Si tu préfères ne pas gérer le stockage, passe sur l'option <em>Hébergement Xyno</em> — c'est activable à tout moment depuis le dashboard, sans avoir à prévenir tes joueurs.</li>
              </ul>
              <div class="cta-row" style="margin-top: 14px">
                <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>?subject=Aide%20auto-h%C3%A9bergement%20XynoLauncher">Contacter le support</a>
                <a class="btn btn-ghost" href="pricing.php">Voir l'option hébergement Xyno</a>
              </div>
            </section>

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
        <p class="small">Un launcher professionnel, sans t'occuper du backend.</p>
        <p class="small">© <span id="year">2026</span> XynoLauncher.</p>
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
        <p class="small"><a href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>">Support</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

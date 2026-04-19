<?php

declare(strict_types=1);

$supportEmail = 'support@xynoweb.fr';

// Lien de téléchargement de l'archive auto-hébergement (placeholder).
// Remplace par la vraie URL/chemin quand l'archive sera prête.
$selfHostZip     = 'downloads/xynocms-selfhost-latest.zip';
$selfHostVersion = '1.0.0';
$selfHostSize    = '~15 Mo';

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Auto-hébergement — XynoLauncher</title>
  <meta name="description" content="Télécharge l’archive complète du CMS XynoLauncher et installe-la sur ton propre serveur. Guide pas-à-pas inclus." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <script src="assets/main.js" defer></script>
  <style>
    .doc-grid{
      display:grid; gap:24px; margin-top:24px;
      grid-template-columns: 1fr;
    }
    @media (min-width: 960px){
      .doc-grid{ grid-template-columns: 280px 1fr; align-items:start; }
    }
    .toc{
      position:sticky; top:80px;
      padding:18px; border-radius:14px;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.08);
    }
    .toc h4{ margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.5)}
    .toc ol{ margin:0; padding-left:18px; display:flex; flex-direction:column; gap:6px;}
    .toc a{ color:rgba(255,255,255,.85); text-decoration:none; font-size:14px;}
    .toc a:hover{ color:#a78bfa; }

    .doc-section{
      padding:24px; border-radius:16px;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.08);
      margin-bottom:18px;
    }
    .doc-section h2{
      margin:0 0 8px; font-size:22px;
    }
    .doc-section h3{
      margin:18px 0 6px; font-size:16px; color:rgba(255,255,255,.92);
    }
    .doc-section p{ color:rgba(255,255,255,.75); line-height:1.6;}
    .doc-section ul, .doc-section ol{ color:rgba(255,255,255,.8); line-height:1.7; padding-left:22px;}

    .download-box{
      padding:22px; border-radius:16px;
      background:linear-gradient(135deg, rgba(124,58,237,.18), rgba(34,211,238,.08));
      border:1px solid rgba(124,58,237,.4);
      box-shadow:0 0 0 1px rgba(124,58,237,.25), 0 20px 60px -20px rgba(124,58,237,.35);
      display:flex; flex-wrap:wrap; gap:16px; align-items:center; justify-content:space-between;
    }
    .download-box .meta{
      display:flex; gap:14px; flex-wrap:wrap;
      color:rgba(255,255,255,.7); font-size:13px;
    }
    .download-box .meta strong{ color:#fff; font-weight:600; }

    pre.code{
      margin:8px 0 0;
      padding:14px 16px;
      background:rgba(0,0,0,.45);
      border:1px solid rgba(255,255,255,.08);
      border-radius:10px;
      overflow-x:auto;
      font:500 13px/1.6 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
      color:#e6e6e6;
    }
    code.inline{
      padding:2px 6px; border-radius:6px;
      background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.1);
      font:500 12px/1 'JetBrains Mono', ui-monospace, monospace;
      color:#fff;
    }

    .callout-warn{
      margin-top:14px; padding:14px 16px;
      border-radius:12px;
      background:rgba(251,191,36,.06);
      border:1px solid rgba(251,191,36,.3);
      color:rgba(255,255,255,.85);
      font-size:14px;
    }
    .callout-warn strong{ color:#fbbf24; }
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
        <p class="badge">Auto-hébergement</p>
        <h1 class="h-title" style="font-size:clamp(28px,3.2vw,40px); margin-top:8px">Héberge XynoLauncher sur ton propre serveur</h1>
        <p class="section-desc" style="max-width:780px">
          Tu préfères garder le contrôle&nbsp;? Télécharge l’archive complète du CMS et installe-la sur ton propre VPS.
          Compte 30 minutes à 1 heure d’installation selon ton aisance avec Linux. Le support reste disponible si tu bloques.
        </p>

        <!-- ================= DOWNLOAD BOX ================= -->
        <div class="download-box" style="margin-top:18px">
          <div>
            <p class="badge">Archive CMS · ZIP</p>
            <h2 class="section-title" style="margin:8px 0 4px">XynoCMS — Self-hosting bundle</h2>
            <div class="meta">
              <span>Version&nbsp;: <strong>v<?= htmlspecialchars($selfHostVersion) ?></strong></span>
              <span>Taille&nbsp;: <strong><?= htmlspecialchars($selfHostSize) ?></strong></span>
              <span>Inclut&nbsp;: <strong>CMS + schéma SQL + README</strong></span>
            </div>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary" href="<?= htmlspecialchars($selfHostZip) ?>" download>Télécharger l’archive ZIP</a>
          </div>
        </div>

        <div class="callout-warn">
          <strong>Note&nbsp;:</strong> l’archive contient le CMS PHP et le template Electron du launcher.
          Les builds multi-OS (Windows, macOS, Linux) sont fournis via GitHub Actions&nbsp;: tu devras
          fork le repo template et configurer 2 secrets GitHub. C’est expliqué étape par étape ci-dessous.
        </div>

        <!-- ================= DOC LAYOUT ================= -->
        <div class="doc-grid">
          <aside class="toc" aria-label="Sommaire">
            <h4>Sommaire</h4>
            <ol>
              <li><a href="#prereq">Prérequis serveur</a></li>
              <li><a href="#install">Installation du CMS</a></li>
              <li><a href="#nginx">Configuration nginx + HTTPS</a></li>
              <li><a href="#env">Fichier .env</a></li>
              <li><a href="#github">GitHub Actions (build)</a></li>
              <li><a href="#first-launcher">Créer ton 1er launcher</a></li>
              <li><a href="#updates">Mises à jour du CMS</a></li>
              <li><a href="#help">Besoin d’aide&nbsp;?</a></li>
            </ol>
          </aside>

          <div>

            <!-- ========== Prerequisites ========== -->
            <section id="prereq" class="doc-section">
              <h2>1. Prérequis serveur</h2>
              <p>Tu peux installer XynoCMS sur n’importe quel VPS Linux qui respecte ces minimums&nbsp;:</p>
              <ul>
                <li>Distribution&nbsp;: Debian 12, Ubuntu 22.04 LTS ou Ubuntu 24.04 LTS</li>
                <li>RAM&nbsp;: 1 Go minimum (2 Go recommandé)</li>
                <li>Disque&nbsp;: 20 Go (les installeurs Electron pèsent ~100 Mo chacun)</li>
                <li>PHP 8.3 (FPM) avec extensions&nbsp;: <code class="inline">pdo_mysql</code>, <code class="inline">curl</code>, <code class="inline">zip</code>, <code class="inline">gd</code>, <code class="inline">mbstring</code>, <code class="inline">openssl</code></li>
                <li>MySQL 8.x ou MariaDB 10.11+</li>
                <li>nginx (recommandé) ou Apache 2.4 avec <code class="inline">mod_rewrite</code></li>
                <li>Un nom de domaine (sous-domaine accepté) pointant vers le VPS</li>
                <li>HTTPS obligatoire (Let’s Encrypt — gratuit)</li>
                <li>Compte GitHub gratuit (pour les builds multi-OS via Actions)</li>
              </ul>
            </section>

            <!-- ========== Install ========== -->
            <section id="install" class="doc-section">
              <h2>2. Installation du CMS</h2>

              <h3>2.1 — Installer les paquets système</h3>
              <pre class="code">sudo apt update
sudo apt install -y nginx mariadb-server unzip curl git \
  php8.3-fpm php8.3-mysql php8.3-curl php8.3-zip \
  php8.3-gd php8.3-mbstring php8.3-xml</pre>

              <h3>2.2 — Créer la base de données</h3>
              <pre class="code">sudo mysql -e "CREATE DATABASE xynocms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'xyno'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';"
sudo mysql -e "GRANT ALL PRIVILEGES ON xynocms.* TO 'xyno'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"</pre>

              <h3>2.3 — Déployer l’archive</h3>
              <pre class="code">sudo mkdir -p /var/www/xynocms
sudo unzip xynocms-selfhost-latest.zip -d /var/www/xynocms
sudo chown -R www-data:www-data /var/www/xynocms
cd /var/www/xynocms
sudo -u www-data mysql xynocms &lt; sql/schema.sql</pre>

              <h3>2.4 — Régler les limites PHP (uploads d’installeurs)</h3>
              <p>Les installeurs Linux/macOS peuvent dépasser 100 Mo. Crée un override&nbsp;:</p>
              <pre class="code">sudo tee /etc/php/8.3/fpm/conf.d/99-xynocms.ini &gt; /dev/null &lt;&lt;'INI'
upload_max_filesize = 1024M
post_max_size       = 1024M
memory_limit        = 256M
max_execution_time  = 120
INI
sudo systemctl restart php8.3-fpm</pre>
            </section>

            <!-- ========== Nginx ========== -->
            <section id="nginx" class="doc-section">
              <h2>3. Configuration nginx + HTTPS</h2>

              <h3>3.1 — Vhost nginx</h3>
              <p>Crée <code class="inline">/etc/nginx/sites-available/xynocms</code>&nbsp;:</p>
              <pre class="code">server {
    listen 80;
    server_name ton-domaine.fr;
    root /var/www/xynocms;
    index index.php index.html;

    client_max_body_size 1024M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }

    # Bloque l'accès direct aux dossiers privés
    location ~ ^/(config|sql|launcher)/ { deny all; return 404; }
}</pre>
              <pre class="code">sudo ln -s /etc/nginx/sites-available/xynocms /etc/nginx/sites-enabled/
sudo nginx -t &amp;&amp; sudo systemctl reload nginx</pre>

              <h3>3.2 — HTTPS gratuit (Let’s Encrypt)</h3>
              <pre class="code">sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d ton-domaine.fr</pre>
              <p>Certbot configurera automatiquement le HTTPS et le renouvellement.</p>
            </section>

            <!-- ========== .env ========== -->
            <section id="env" class="doc-section">
              <h2>4. Fichier <code class="inline">.env</code></h2>
              <p>Copie le modèle et renseigne les valeurs&nbsp;:</p>
              <pre class="code">cd /var/www/xynocms
sudo -u www-data cp .env.example .env
sudo -u www-data nano .env</pre>
              <p>Variables minimales à configurer&nbsp;:</p>
              <pre class="code">XYNO_BASE_URL=https://ton-domaine.fr

DB_HOST=127.0.0.1
DB_NAME=xynocms
DB_USER=xyno
DB_PASS=CHANGE_ME_STRONG_PASSWORD

# Secret pour signer les sessions (chaîne aléatoire 64 caractères)
XYNO_APP_SECRET=...

# GitHub Actions (voir section 5)
XYNO_GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxx
XYNO_GITHUB_REPO=ton-user/xyno-launcher-template

# Tokens partagés avec le workflow GitHub Actions
BUILD_FETCH_TOKEN=...   # chaîne aléatoire 64 caractères
RELEASE_UPLOAD_TOKEN=... # chaîne aléatoire 64 caractères</pre>
              <p>Pour générer un secret aléatoire&nbsp;:</p>
              <pre class="code">openssl rand -hex 32</pre>
            </section>

            <!-- ========== GitHub ========== -->
            <section id="github" class="doc-section">
              <h2>5. GitHub Actions (build multi-OS)</h2>
              <p>Les installeurs Windows / macOS / Linux sont buildés gratuitement par GitHub Actions. Tu n’as rien à compiler localement.</p>

              <h3>5.1 — Fork le repo template</h3>
              <ol>
                <li>Crée un nouveau repo <em>privé</em> sur ton compte GitHub.</li>
                <li>Pousse-y le contenu du dossier <code class="inline">launcher/</code> + <code class="inline">.github/</code> de l’archive.</li>
                <li>Note bien le nom complet du repo, ex&nbsp;: <code class="inline">ton-user/xyno-launcher-template</code> — il sert de valeur pour <code class="inline">XYNO_GITHUB_REPO</code>.</li>
              </ol>

              <h3>5.2 — Créer un Personal Access Token</h3>
              <ol>
                <li>Va sur <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">github.com/settings/tokens</a>.</li>
                <li>« Generate new token (classic) » avec les scopes <code class="inline">repo</code> et <code class="inline">workflow</code>.</li>
                <li>Colle la valeur dans <code class="inline">XYNO_GITHUB_TOKEN</code> de ton <code class="inline">.env</code>.</li>
              </ol>

              <h3>5.3 — Ajouter les secrets côté repo</h3>
              <p>Sur ton repo GitHub&nbsp;: <em>Settings → Secrets and variables → Actions → New repository secret</em>. Ajoute&nbsp;:</p>
              <ul>
                <li><code class="inline">BUILD_FETCH_TOKEN</code> — même valeur que dans ton <code class="inline">.env</code></li>
                <li><code class="inline">RELEASE_UPLOAD_TOKEN</code> — même valeur que dans ton <code class="inline">.env</code></li>
              </ul>
              <p>Ces deux jetons permettent au workflow de récupérer la config depuis ton CMS et d’y déposer les installeurs en retour.</p>
            </section>

            <!-- ========== First launcher ========== -->
            <section id="first-launcher" class="doc-section">
              <h2>6. Créer ton premier launcher</h2>
              <ol>
                <li>Ouvre <code class="inline">https://ton-domaine.fr/register.php</code> et crée ton compte admin.</li>
                <li>Va dans le <a href="dashboard.php">dashboard</a>, clique sur « Nouveau launcher ».</li>
                <li>Configure le nom, le thème, les modules, les assets (logo, splash, icônes).</li>
                <li>Clique sur « Lancer un build » et choisis les OS (Windows / macOS / Linux).</li>
                <li>Le build prend ~10 minutes la première fois (puis 3-5 min grâce au cache GitHub Actions).</li>
                <li>Une fois terminé, les installeurs sont téléchargeables depuis le dashboard pour tes utilisateurs finaux.</li>
              </ol>
            </section>

            <!-- ========== Updates ========== -->
            <section id="updates" class="doc-section">
              <h2>7. Mises à jour du CMS</h2>
              <p>Les nouvelles versions du CMS sont publiées sur cette même page. Pour mettre à jour&nbsp;:</p>
              <pre class="code">cd /var/www/xynocms
sudo -u www-data cp .env /tmp/xyno.env.backup
# Télécharge la nouvelle archive et dézippe par-dessus
sudo unzip -o xynocms-selfhost-latest.zip -d /var/www/xynocms
sudo cp /tmp/xyno.env.backup /var/www/xynocms/.env
sudo chown -R www-data:www-data /var/www/xynocms
# Applique les éventuelles migrations SQL
sudo -u www-data mysql xynocms &lt; sql/migrations/latest.sql</pre>
              <p>Pense à sauvegarder ta base de données avant chaque mise à jour&nbsp;:</p>
              <pre class="code">mysqldump -u xyno -p xynocms &gt; backup-$(date +%F).sql</pre>
            </section>

            <!-- ========== Help ========== -->
            <section id="help" class="doc-section">
              <h2>8. Besoin d’aide&nbsp;?</h2>
              <p>L’auto-hébergement c’est puissant mais ça demande un peu de patience. Si tu bloques&nbsp;:</p>
              <ul>
                <li>Le README inclus dans l’archive contient une FAQ détaillée.</li>
                <li>Tu peux contacter le support pour des questions ponctuelles (réponse best-effort).</li>
                <li>Si tu préfères ne pas t’en occuper, l’option <a href="pricing.php#hebergement">hébergement Xyno</a> est là pour ça.</li>
              </ul>
              <div class="cta-row" style="margin-top:14px">
                <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>?subject=Aide%20auto-h%C3%A9bergement%20XynoCMS">Contacter le support</a>
                <a class="btn btn-ghost" href="pricing.php">Voir l’option hébergement Xyno</a>
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
        <p class="small">CMS open-friendly pour ton launcher Minecraft.</p>
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
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

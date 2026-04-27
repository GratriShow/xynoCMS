<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

start_secure_session();
$user = current_user();

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Mentions légales — XynoLauncher</title>
  <meta name="description" content="Mentions légales de la plateforme XynoLauncher (XynoWeb)." />
  <meta name="robots" content="index,follow" />
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
        <?php if ($user !== null): ?><a href="dashboard.php">Dashboard</a><?php endif; ?>
      </nav>

      <div class="nav-actions">
        <?php if ($user === null): ?>
          <a class="btn" href="auth/login.php">Connexion</a>
          <a class="btn btn-primary" href="auth/register.php">Créer un compte</a>
        <?php else: ?>
          <a class="btn btn-primary" href="dashboard.php">Dashboard</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:880px">
        <p class="badge">Légal</p>
        <h1 class="section-title" style="margin:10px 0 0">Mentions légales</h1>
        <p class="section-desc" style="margin-top:8px">Informations légales relatives à l'éditeur du site et à son hébergement, conformément à l'article 6-III de la loi n°&nbsp;2004-575 du 21 juin 2004 (LCEN).</p>
        <p class="small" style="margin-top:6px;color:var(--muted)">Dernière mise à jour : 27 avril 2026.</p>

        <article class="card" style="margin-top:22px;padding:24px">
          <h2 style="margin:0 0 8px 0">1. Éditeur du site</h2>
          <p>Le site <strong>xynocms.xynoweb.fr</strong> (et plus généralement les services <strong>XynoLauncher</strong>) est édité par&nbsp;:</p>
          <ul>
            <li><strong>Lucas Noel</strong>, exerçant en qualité de micro-entrepreneur</li>
            <li>Nom commercial&nbsp;: <strong>XynoWeb</strong></li>
            <li>SIRET&nbsp;: <strong>993&nbsp;123&nbsp;934&nbsp;00016</strong></li>
            <li>Adresse du siège&nbsp;: 1415 rue à Baudets, 62240 Wirwignes, France</li>
            <li>Email&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a></li>
            <li>TVA&nbsp;: <em>TVA non applicable, art. 293 B du CGI</em> (franchise en base de TVA)</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">2. Directeur de la publication</h2>
          <p>Lucas Noel, en qualité d'exploitant de la micro-entreprise XynoWeb.</p>

          <h2 style="margin:24px 0 8px 0">3. Hébergement</h2>
          <p>Le site est hébergé par&nbsp;:</p>
          <ul>
            <li><strong>LifeHeberg</strong></li>
            <li>Site web&nbsp;: <a href="https://www.lifeheberg.com" target="_blank" rel="noopener">www.lifeheberg.com</a></li>
          </ul>
          <p>La gestion DNS du domaine <strong>xynoweb.fr</strong> est assurée par <strong>OVH SAS</strong> — 2 rue Kellermann, 59100 Roubaix, France — <a href="https://www.ovh.com" target="_blank" rel="noopener">www.ovh.com</a>.</p>

          <h2 style="margin:24px 0 8px 0">4. Propriété intellectuelle</h2>
          <p>L'ensemble du site (structure, code source, textes, visuels, logo XynoLauncher, charte graphique) est la propriété exclusive de Lucas&nbsp;Noel&nbsp;/&nbsp;XynoWeb, à l'exception des éléments tiers expressément mentionnés (logos partenaires, polices Google Fonts, marques Stripe et Minecraft®). Toute reproduction, représentation, modification, publication ou adaptation, totale ou partielle, sans autorisation écrite préalable, est interdite et constitue une contrefaçon sanctionnée par les articles L.335-2 et suivants du Code de la propriété intellectuelle.</p>
          <p class="small">Minecraft® est une marque déposée de Mojang Studios / Microsoft Corporation. XynoLauncher n'est ni affilié ni endossé par Mojang Studios ou Microsoft. Les utilisateurs restent responsables de la conformité de leurs propres launchers avec les EULAs et politiques de Mojang Studios.</p>

          <h2 style="margin:24px 0 8px 0">5. Liens hypertextes</h2>
          <p>Le site peut contenir des liens vers des sites tiers (Stripe, GitHub, Discord, sites de joueurs des launchers édités via la plateforme, etc.). XynoWeb n'exerce aucun contrôle sur ces sites et décline toute responsabilité quant à leur contenu, leurs politiques de confidentialité ou leurs pratiques.</p>

          <h2 style="margin:24px 0 8px 0">6. Signaler un contenu illicite</h2>
          <p>Conformément à la LCEN, tout contenu manifestement illicite hébergé via la plateforme peut être signalé à&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>. Le signalement doit comporter la description précise des faits, la localisation (URL), et les coordonnées du signalant.</p>

          <h2 style="margin:24px 0 8px 0">7. Données personnelles</h2>
          <p>Le traitement des données personnelles fait l'objet d'une notice dédiée&nbsp;: voir la <a href="politique-confidentialite.php">Politique de confidentialité</a> et la <a href="politique-cookies.php">Politique cookies</a>.</p>

          <h2 style="margin:24px 0 8px 0">8. Droit applicable</h2>
          <p>Le présent site est soumis au droit français. En cas de litige, et après tentative de résolution amiable, les tribunaux français seront seuls compétents.</p>
        </article>
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
        <p class="small">© <span id="year">2026</span> XynoWeb — Lucas Noel. SIRET 993&nbsp;123&nbsp;934&nbsp;00016.</p>
      </div>
      <div>
        <h4>Produit</h4>
        <p class="small"><a href="pricing.php">Tarifs</a></p>
        <p class="small"><a href="builder.php">Builder</a></p>
        <p class="small"><a href="index.php">Accueil</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="auth/register.php">Inscription</a></p>
        <p class="small"><a href="auth/login.php">Connexion</a></p>
      </div>
      <div>
        <h4>Légal</h4>
        <p class="small"><a href="mentions-legales.php">Mentions légales</a></p>
        <p class="small"><a href="politique-confidentialite.php">Confidentialité</a></p>
        <p class="small"><a href="politique-cookies.php">Cookies</a></p>
        <p class="small"><a href="cgu.php">CGU</a></p>
        <p class="small"><a href="cgv.php">CGV</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

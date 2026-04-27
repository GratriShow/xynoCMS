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
  <title>Conditions Générales d'Utilisation — XynoLauncher</title>
  <meta name="description" content="Conditions Générales d'Utilisation de la plateforme XynoLauncher." />
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
        <h1 class="section-title" style="margin:10px 0 0">Conditions Générales d'Utilisation</h1>
        <p class="section-desc" style="margin-top:8px">Règles d'usage de la plateforme XynoLauncher applicables à tout utilisateur.</p>
        <p class="small" style="margin-top:6px;color:var(--muted)">Version en vigueur : 27 avril 2026.</p>

        <article class="card" style="margin-top:22px;padding:24px">

          <h2 style="margin:0 0 8px 0">Article 1 — Objet</h2>
          <p>Les présentes Conditions Générales d'Utilisation (ci-après «&nbsp;CGU&nbsp;») ont pour objet de définir les modalités d'accès et d'utilisation de la plateforme <strong>XynoLauncher</strong> (ci-après «&nbsp;la Plateforme&nbsp;») éditée par Lucas Noel — micro-entreprise XynoWeb (ci-après «&nbsp;l'Éditeur&nbsp;»), par toute personne physique ou morale (ci-après «&nbsp;l'Utilisateur&nbsp;»).</p>
          <p>La Plateforme propose un service SaaS de génération, configuration et hébergement de launchers Minecraft personnalisés.</p>

          <h2 style="margin:24px 0 8px 0">Article 2 — Acceptation et entrée en vigueur</h2>
          <p>L'utilisation de la Plateforme implique l'acceptation pleine et entière des présentes CGU. La création d'un compte ou la souscription d'un abonnement vaut acceptation expresse et sans réserve.</p>
          <p>L'Éditeur se réserve le droit de modifier les CGU à tout moment. Les Utilisateurs sont informés des modifications substantielles par email au moins 30 jours avant leur entrée en vigueur. La poursuite de l'utilisation après cette date vaut acceptation des nouvelles CGU.</p>

          <h2 style="margin:24px 0 8px 0">Article 3 — Création de compte</h2>
          <p>L'accès aux fonctionnalités principales (builder, dashboard, abonnements) nécessite la création d'un compte via le formulaire d'inscription. L'Utilisateur s'engage à&nbsp;:</p>
          <ul>
            <li>Fournir un email valide dont il est titulaire</li>
            <li>Choisir un mot de passe d'au moins 8 caractères</li>
            <li>Maintenir la confidentialité de ses identifiants — l'Utilisateur est seul responsable des actions effectuées depuis son compte</li>
            <li>Notifier sans délai l'Éditeur en cas de compromission de ses identifiants</li>
          </ul>
          <p>L'Éditeur se réserve le droit de suspendre ou supprimer tout compte présentant un usage frauduleux, abusif ou contraire aux présentes CGU.</p>

          <h2 style="margin:24px 0 8px 0">Article 4 — Description du service</h2>
          <p>La Plateforme permet à l'Utilisateur de&nbsp;:</p>
          <ul>
            <li>Créer un ou plusieurs launchers Minecraft personnalisés (nom, version, thème, modules)</li>
            <li>Téléverser et gérer les mods, configurations et assets associés (zone Fichiers)</li>
            <li>Générer des builds Windows, macOS et Linux signés via GitHub Actions</li>
            <li>Souscrire à un abonnement (Starter, Pro, Premium) débloquant les fonctionnalités payantes</li>
            <li>Accéder au dashboard pour consulter logs, statistiques et facturation</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">Article 5 — Engagements de l'Utilisateur</h2>
          <p>L'Utilisateur s'engage à utiliser la Plateforme dans le respect des lois en vigueur et des présentes CGU. Sont notamment interdits&nbsp;:</p>
          <ul>
            <li>L'usage du service à des fins illicites, frauduleuses ou contraires à l'ordre public</li>
            <li>Le téléversement de contenus violant le droit d'auteur, la propriété intellectuelle ou les EULAs de tiers (notamment ceux de Mojang Studios / Microsoft)</li>
            <li>Le téléversement de malwares, virus, ou tout code visant à nuire</li>
            <li>La diffusion de contenus diffamatoires, haineux, discriminatoires, à caractère sexuel impliquant des mineurs, ou portant atteinte à la dignité humaine</li>
            <li>La revente, sous-location ou mise à disposition de la Plateforme à un tiers sans accord écrit de l'Éditeur</li>
            <li>Le contournement des limitations techniques, des quotas ou des mécanismes de paiement</li>
            <li>L'usage automatisé abusif (scraping massif, déni de service, ingénierie inverse de l'API)</li>
          </ul>
          <p>En cas de manquement, l'Éditeur peut suspendre ou résilier le compte sans préavis ni remboursement, sans préjudice des poursuites judiciaires éventuelles.</p>

          <h2 style="margin:24px 0 8px 0">Article 6 — Propriété intellectuelle</h2>
          <p><strong>Sur les éléments de la Plateforme&nbsp;:</strong> tous les éléments composant la Plateforme (code source, design, logo XynoLauncher, marques, bases de données) restent la propriété exclusive de l'Éditeur. L'Utilisateur dispose d'un droit d'usage personnel et non exclusif limité à la durée du contrat.</p>
          <p><strong>Sur les contenus de l'Utilisateur&nbsp;:</strong> l'Utilisateur conserve la pleine propriété des contenus qu'il téléverse (mods, assets, configurations, nom du launcher, logo). Il accorde à l'Éditeur une licence non exclusive, gratuite et limitée à la durée du contrat, à seule fin de pouvoir héberger, traiter, et distribuer ces contenus aux utilisateurs finaux du launcher concerné. Aucune exploitation commerciale par l'Éditeur en dehors du service n'est autorisée.</p>
          <p>L'Utilisateur garantit qu'il dispose des droits nécessaires sur les contenus qu'il téléverse, et indemnisera l'Éditeur de toute réclamation tierce à ce titre.</p>

          <h2 style="margin:24px 0 8px 0">Article 7 — Disponibilité du service</h2>
          <p>L'Éditeur s'efforce de maintenir la Plateforme accessible 7&nbsp;j/7, 24&nbsp;h/24, mais sans aucune garantie de disponibilité ininterrompue. Des opérations de maintenance, mises à jour ou incidents techniques peuvent entraîner des interruptions, qui seront, dans la mesure du possible, annoncées à l'avance.</p>
          <p>L'Éditeur ne saurait être tenu responsable des interruptions imputables à un tiers (hébergeur, réseau, fournisseur d'accès) ou à un cas de force majeure.</p>

          <h2 style="margin:24px 0 8px 0">Article 8 — Responsabilité</h2>
          <p>La Plateforme est fournie «&nbsp;telle quelle&nbsp;». L'Éditeur ne garantit pas l'adéquation du service à un usage particulier au-delà des fonctionnalités décrites. Il ne pourra être tenu responsable&nbsp;:</p>
          <ul>
            <li>Des dommages indirects (perte de chiffre d'affaires, perte de joueurs, atteinte à l'image)</li>
            <li>Des contenus téléversés par les Utilisateurs</li>
            <li>D'un usage non conforme aux CGU ou à la documentation</li>
            <li>D'une perte de données résultant d'une mauvaise manipulation par l'Utilisateur</li>
          </ul>
          <p>En tout état de cause, et sauf faute lourde, la responsabilité de l'Éditeur ne pourra excéder le montant total payé par l'Utilisateur au titre des 12 derniers mois.</p>

          <h2 style="margin:24px 0 8px 0">Article 9 — Données personnelles</h2>
          <p>Les données personnelles collectées sont traitées conformément à la <a href="politique-confidentialite.php">Politique de confidentialité</a>, qui forme partie intégrante des présentes CGU.</p>

          <h2 style="margin:24px 0 8px 0">Article 10 — Suspension et résiliation</h2>
          <p>L'Utilisateur peut supprimer son compte à tout moment depuis le dashboard ou en écrivant à <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>. Cette suppression entraîne la résiliation immédiate de tout abonnement actif (sans remboursement de la période entamée — voir CGV).</p>
          <p>L'Éditeur peut suspendre ou résilier un compte en cas de violation des CGU, de défaut de paiement, ou pour tout motif légitime, après notification, sauf urgence.</p>

          <h2 style="margin:24px 0 8px 0">Article 11 — Loi applicable et juridiction</h2>
          <p>Les présentes CGU sont régies par le droit français. En cas de litige, les parties s'efforceront de trouver une solution amiable. À défaut, et conformément à l'article L. 612-1 du Code de la consommation, le consommateur peut recourir gratuitement au médiateur de la consommation. Les juridictions françaises sont seules compétentes pour les litiges qui n'auraient pu être résolus à l'amiable.</p>

          <h2 style="margin:24px 0 8px 0">Article 12 — Contact</h2>
          <p>Toute question relative aux CGU&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>.</p>

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

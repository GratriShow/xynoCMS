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
  <title>Politique de confidentialité — XynoLauncher</title>
  <meta name="description" content="Politique de confidentialité et traitement des données personnelles de la plateforme XynoLauncher (RGPD)." />
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
        <p class="badge">Légal · RGPD</p>
        <h1 class="section-title" style="margin:10px 0 0">Politique de confidentialité</h1>
        <p class="section-desc" style="margin-top:8px">Comment XynoWeb collecte, utilise et protège tes données personnelles, conformément au Règlement Général sur la Protection des Données (UE 2016/679) et à la loi Informatique et Libertés.</p>
        <p class="small" style="margin-top:6px;color:var(--muted)">Dernière mise à jour : 27 avril 2026.</p>

        <article class="card" style="margin-top:22px;padding:24px">

          <h2 style="margin:0 0 8px 0">1. Responsable du traitement</h2>
          <p>Le responsable du traitement des données personnelles est&nbsp;:</p>
          <ul>
            <li><strong>Lucas Noel</strong> — Micro-entreprise <strong>XynoWeb</strong></li>
            <li>SIRET 993&nbsp;123&nbsp;934&nbsp;00016</li>
            <li>1415 rue à Baudets, 62240 Wirwignes, France</li>
            <li>Contact RGPD&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a></li>
          </ul>
          <p class="small">Compte tenu de l'activité (micro-entreprise, traitement à petite échelle, absence de données sensibles), aucun délégué à la protection des données (DPO) n'est désigné. Les demandes RGPD sont traitées directement par le responsable du traitement.</p>

          <h2 style="margin:24px 0 8px 0">2. Données collectées et finalités</h2>
          <p>Les données suivantes sont collectées dans le cadre du service&nbsp;:</p>

          <h3 style="margin:14px 0 6px 0;font-size:16px">2.1 Compte utilisateur</h3>
          <ul>
            <li><strong>Email</strong> — identifiant de connexion + communications de service</li>
            <li><strong>Mot de passe</strong> — stocké haché (bcrypt via <code>password_hash</code>), jamais en clair</li>
            <li><strong>UUID + identifiant interne</strong> — pour relier le compte aux launchers et abonnements</li>
            <li><strong>Date de création du compte</strong></li>
          </ul>

          <h3 style="margin:14px 0 6px 0;font-size:16px">2.2 Launchers créés</h3>
          <ul>
            <li>Nom, description, version Minecraft, loader, thème, modules, configuration</li>
            <li>Clé API (générée aléatoirement, utilisée par l'application Electron)</li>
            <li>Fichiers que tu uploades dans la zone Fichiers (mods, assets, configs)</li>
          </ul>

          <h3 style="margin:14px 0 6px 0;font-size:16px">2.3 Abonnements et facturation</h3>
          <ul>
            <li><strong>Identifiant client Stripe</strong> + identifiant d'abonnement</li>
            <li>Plan choisi, période, statut, dates (création, renouvellement, expiration)</li>
            <li>Historique de facturation conservé chez Stripe (numéro de facture, montant, devise, statut)</li>
          </ul>
          <p class="small">⚠️ Les données de carte bancaire <strong>ne transitent jamais par les serveurs XynoWeb</strong>. Elles sont collectées et stockées exclusivement par Stripe (PCI-DSS niveau 1).</p>

          <h3 style="margin:14px 0 6px 0;font-size:16px">2.4 Logs techniques</h3>
          <ul>
            <li>Logs de connexion utilisateurs et téléchargements (anti-abus)</li>
            <li>Logs serveur web (adresse IP, user-agent, page consultée, code HTTP) — conservation max. 12 mois</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">3. Bases légales du traitement (art. 6 RGPD)</h2>
          <ul>
            <li><strong>Exécution du contrat</strong> (art. 6.1.b) — création de compte, fourniture du service launcher, gestion d'abonnement</li>
            <li><strong>Obligation légale</strong> (art. 6.1.c) — conservation des factures (10 ans, Code de commerce art. L123-22)</li>
            <li><strong>Intérêt légitime</strong> (art. 6.1.f) — sécurité, prévention de la fraude, anti-abus, logs serveur</li>
            <li><strong>Consentement</strong> (art. 6.1.a) — bannière cookies (uniquement dépôt de cookies non essentiels, le cas échéant)</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">4. Destinataires des données — sous-traitants</h2>
          <p>Les données sont strictement réservées à XynoWeb et à ses sous-traitants techniques&nbsp;:</p>
          <ul>
            <li><strong>LifeHeberg</strong> — hébergement des serveurs et de la base de données. Données stockées en France / UE.</li>
            <li><strong>Stripe Payments Europe Ltd</strong> (Irlande) — traitement des paiements et de la facturation. <a href="https://stripe.com/fr/privacy" target="_blank" rel="noopener">Politique Stripe</a>. Stripe peut transférer certaines données aux États-Unis, encadrées par les Clauses Contractuelles Types (CCT) de la Commission européenne et le Data Privacy Framework (DPF).</li>
            <li><strong>OVH SAS</strong> (France) — gestion DNS du domaine xynoweb.fr.</li>
            <li><strong>Google Fonts</strong> — police Inter chargée depuis fonts.googleapis.com. Une requête HTTP est effectuée par le navigateur du visiteur (transmettant son adresse IP). Aucun cookie. Voir la <a href="politique-cookies.php">Politique cookies</a>.</li>
          </ul>
          <p>Aucune donnée n'est revendue, louée ou cédée à des tiers à des fins commerciales ou publicitaires.</p>

          <h2 style="margin:24px 0 8px 0">5. Durées de conservation</h2>
          <ul>
            <li><strong>Compte utilisateur</strong> — pendant toute la durée d'utilisation, puis 3 ans après la dernière connexion (intérêt légitime de prospection éventuelle), avant suppression définitive.</li>
            <li><strong>Abonnements & paiements</strong> — données conservées 10 ans après la fin de l'abonnement (obligation comptable, Code de commerce).</li>
            <li><strong>Logs serveur</strong> — 12 mois maximum.</li>
            <li><strong>Logs anti-abus (téléchargements, builds)</strong> — 6 mois.</li>
            <li><strong>Email de support</strong> — 3 ans après le dernier échange.</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">6. Tes droits</h2>
          <p>En vertu des articles 15 à 22 du RGPD, tu disposes des droits suivants&nbsp;:</p>
          <ul>
            <li><strong>Accès</strong> (art. 15) — obtenir une copie de tes données</li>
            <li><strong>Rectification</strong> (art. 16) — corriger des données inexactes</li>
            <li><strong>Effacement</strong> (art. 17) — supprimer ton compte et tes données (sauf obligations légales de conservation)</li>
            <li><strong>Limitation</strong> (art. 18) — geler temporairement le traitement</li>
            <li><strong>Portabilité</strong> (art. 20) — récupérer tes données dans un format structuré (JSON)</li>
            <li><strong>Opposition</strong> (art. 21) — t'opposer à un traitement fondé sur l'intérêt légitime</li>
            <li><strong>Retrait du consentement</strong> (art. 7) — pour les traitements fondés sur le consentement, à tout moment</li>
            <li><strong>Directives post-mortem</strong> (loi Informatique et Libertés, art. 85)</li>
          </ul>
          <p>Pour exercer ces droits&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>. Réponse sous 30 jours maximum (prolongeable de 60 jours en cas de demande complexe). Une preuve d'identité peut être demandée en cas de doute raisonnable.</p>

          <h2 style="margin:24px 0 8px 0">7. Réclamation auprès de la CNIL</h2>
          <p>Si tu estimes que tes droits ne sont pas respectés, tu peux introduire une réclamation auprès de la <strong>CNIL</strong> (autorité de contrôle française) :</p>
          <ul>
            <li>3 place de Fontenoy, TSA 80715, 75334 Paris Cedex 07</li>
            <li><a href="https://www.cnil.fr" target="_blank" rel="noopener">www.cnil.fr</a> — formulaire de plainte en ligne disponible</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">8. Sécurité</h2>
          <p>XynoWeb met en œuvre des mesures techniques et organisationnelles raisonnables pour protéger tes données&nbsp;: chiffrement HTTPS (TLS), hachage bcrypt des mots de passe, sessions PHP sécurisées (cookies HttpOnly + SameSite), prepared statements PDO contre les injections SQL, contrôle d'accès par UUID, sauvegardes régulières. En cas de violation de données susceptible d'engendrer un risque pour les droits et libertés des personnes, la CNIL sera notifiée sous 72h et les utilisateurs concernés informés sans délai.</p>

          <h2 style="margin:24px 0 8px 0">9. Mineurs</h2>
          <p>Le service n'est pas destiné aux mineurs de moins de 15&nbsp;ans. La création d'un compte par un mineur de moins de 15&nbsp;ans nécessite le consentement préalable d'un titulaire de l'autorité parentale (art. 8 RGPD, art. 45 LIL).</p>

          <h2 style="margin:24px 0 8px 0">10. Modifications</h2>
          <p>Cette politique peut être mise à jour. La date de "dernière mise à jour" en haut de la page indique la dernière révision. En cas de modification substantielle, les utilisateurs seront informés par email.</p>

          <h2 style="margin:24px 0 8px 0">11. Contact</h2>
          <p>Pour toute question relative à cette politique ou au traitement de tes données&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>.</p>
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

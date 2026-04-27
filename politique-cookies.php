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
  <title>Politique cookies — XynoLauncher</title>
  <meta name="description" content="Politique d'utilisation des cookies sur la plateforme XynoLauncher." />
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
        <h1 class="section-title" style="margin:10px 0 0">Politique cookies</h1>
        <p class="section-desc" style="margin-top:8px">Quels cookies XynoLauncher dépose, à quoi ils servent et comment les gérer.</p>
        <p class="small" style="margin-top:6px;color:var(--muted)">Dernière mise à jour : 27 avril 2026.</p>

        <article class="card" style="margin-top:22px;padding:24px">

          <h2 style="margin:0 0 8px 0">1. Qu'est-ce qu'un cookie&nbsp;?</h2>
          <p>Un cookie est un petit fichier texte déposé par un site sur ton terminal (ordinateur, mobile, tablette) lors de ta visite. Il permet notamment au site de te reconnaître d'une page à l'autre, de mémoriser tes préférences, ou d'assurer la sécurité d'une session connectée.</p>
          <p>La présente politique s'applique aux cookies et technologies similaires (localStorage, sessionStorage) déposés depuis le domaine <strong>xynoweb.fr</strong> et ses sous-domaines.</p>

          <h2 style="margin:24px 0 8px 0">2. Le résumé en une phrase</h2>
          <p>XynoLauncher <strong>n'utilise pas de cookies de mesure d'audience, de profilage ni de publicité</strong>. Les seuls cookies déposés sont strictement nécessaires au fonctionnement du site (connexion, sécurité, paiement). À ce titre, et conformément aux lignes directrices de la <a href="https://www.cnil.fr/fr/cookies-et-autres-traceurs/regles/cookies-et-autres-traceurs/cookies-quelles-sont-les-regles" target="_blank" rel="noopener">CNIL</a>, ton consentement préalable n'est pas requis pour les déposer.</p>

          <h2 style="margin:24px 0 8px 0">3. Liste détaillée des cookies déposés</h2>

          <h3 style="margin:16px 0 8px 0;font-size:16px">3.1 Cookies essentiels (XynoWeb)</h3>
          <div style="overflow-x:auto;margin-top:8px">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
              <thead>
                <tr style="text-align:left;border-bottom:1px solid var(--border-1)">
                  <th style="padding:8px 6px">Nom</th>
                  <th style="padding:8px 6px">Finalité</th>
                  <th style="padding:8px 6px">Durée</th>
                </tr>
              </thead>
              <tbody>
                <tr style="border-bottom:1px solid var(--border-1)">
                  <td style="padding:8px 6px"><code>PHPSESSID</code></td>
                  <td style="padding:8px 6px">Identifiant de session — garde l'utilisateur connecté entre deux pages</td>
                  <td style="padding:8px 6px">Session (supprimé à la fermeture du navigateur)</td>
                </tr>
                <tr style="border-bottom:1px solid var(--border-1)">
                  <td style="padding:8px 6px"><code>xyno_cookie_consent</code></td>
                  <td style="padding:8px 6px">Mémorise le fait que tu as vu et fermé la bannière cookies</td>
                  <td style="padding:8px 6px">12 mois</td>
                </tr>
              </tbody>
            </table>
          </div>

          <h3 style="margin:16px 0 8px 0;font-size:16px">3.2 Cookies tiers — Stripe (paiement)</h3>
          <p>Lors du processus de paiement, Stripe peut déposer ses propres cookies essentiels à la sécurité de la transaction (anti-fraude, prévention CSRF, persistance de session Checkout). Ces cookies sont déposés par <strong>stripe.com</strong> et <strong>checkout.stripe.com</strong>, pas par XynoWeb. Voir <a href="https://stripe.com/fr/cookie-settings" target="_blank" rel="noopener">la politique cookies Stripe</a>. Cookies typiques&nbsp;: <code>__stripe_mid</code>, <code>__stripe_sid</code>, <code>m</code>.</p>

          <h3 style="margin:16px 0 8px 0;font-size:16px">3.3 Polices Google Fonts</h3>
          <p>La police Inter est chargée depuis <strong>fonts.googleapis.com</strong>. Cette requête HTTP transmet l'adresse IP du visiteur à Google mais <strong>aucun cookie n'est déposé</strong> par Google Fonts (cf. <a href="https://developers.google.com/fonts/faq/privacy" target="_blank" rel="noopener">FAQ Google Fonts</a>). Si tu préfères éviter cette requête, tu peux bloquer les fonts tierces dans ton navigateur.</p>

          <h3 style="margin:16px 0 8px 0;font-size:16px">3.4 Pas d'autres trackers</h3>
          <p>XynoLauncher <strong>n'utilise pas</strong> Google Analytics, Meta Pixel, Hotjar, Matomo, ni aucun autre outil de mesure d'audience ou de publicité ciblée. Aucun cookie de profilage n'est déposé.</p>

          <h2 style="margin:24px 0 8px 0">4. Comment refuser ou supprimer les cookies&nbsp;?</h2>
          <p>Tous les navigateurs modernes permettent de gérer les cookies, par exemple&nbsp;:</p>
          <ul>
            <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Chrome</a></li>
            <li><a href="https://support.mozilla.org/fr/kb/protection-renforcee-contre-pistage-firefox-ordinateur" target="_blank" rel="noopener">Firefox</a></li>
            <li><a href="https://support.apple.com/fr-fr/guide/safari/sfri11471/mac" target="_blank" rel="noopener">Safari</a></li>
            <li><a href="https://support.microsoft.com/fr-fr/microsoft-edge/supprimer-les-cookies-dans-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" rel="noopener">Edge</a></li>
          </ul>
          <p class="small">⚠️ Refuser le cookie <code>PHPSESSID</code> empêchera le maintien de ta connexion. Le service ne pourra alors plus fonctionner correctement (impossible d'accéder au dashboard, au builder, etc.).</p>

          <h2 style="margin:24px 0 8px 0">5. Modifications</h2>
          <p>Si nous ajoutons un nouveau cookie (par exemple un outil de mesure d'audience), cette politique sera mise à jour et une nouvelle bannière de consentement explicite sera affichée avant tout dépôt.</p>

          <h2 style="margin:24px 0 8px 0">6. Contact</h2>
          <p>Pour toute question : <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>. Pour exercer tes droits RGPD, voir la <a href="politique-confidentialite.php">Politique de confidentialité</a>.</p>

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

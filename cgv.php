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
  <title>Conditions Générales de Vente — XynoLauncher</title>
  <meta name="description" content="Conditions Générales de Vente des abonnements XynoLauncher." />
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
        <h1 class="section-title" style="margin:10px 0 0">Conditions Générales de Vente</h1>
        <p class="section-desc" style="margin-top:8px">Modalités de souscription, paiement et résiliation des abonnements XynoLauncher.</p>
        <p class="small" style="margin-top:6px;color:var(--muted)">Version en vigueur : 27 avril 2026.</p>

        <article class="card" style="margin-top:22px;padding:24px">

          <h2 style="margin:0 0 8px 0">Article 1 — Objet et champ d'application</h2>
          <p>Les présentes Conditions Générales de Vente (ci-après «&nbsp;CGV&nbsp;») régissent la souscription et le paiement des abonnements proposés par <strong>Lucas Noel — micro-entreprise XynoWeb</strong>, SIRET 993&nbsp;123&nbsp;934&nbsp;00016, sur la plateforme <strong>XynoLauncher</strong> (ci-après «&nbsp;la Plateforme&nbsp;»).</p>
          <p>Toute souscription d'un abonnement payant emporte acceptation pleine et entière des présentes CGV ainsi que des <a href="cgu.php">CGU</a>.</p>

          <h2 style="margin:24px 0 8px 0">Article 2 — Caractéristiques des offres</h2>
          <p>Trois formules d'abonnement sont proposées (descriptions et tarifs détaillés sur la page <a href="pricing.php">Tarifs</a>)&nbsp;:</p>
          <ul>
            <li><strong>Starter</strong> — pour démarrer un launcher</li>
            <li><strong>Pro</strong> — pour les communautés établies</li>
            <li><strong>Premium</strong> — pour les usages avancés</li>
          </ul>
          <p>Une option d'<strong>hébergement Xyno</strong> peut être ajoutée moyennant supplément. À défaut, l'Utilisateur héberge lui-même les fichiers de jeu (auto-hébergement gratuit). Les caractéristiques précises de chaque formule (quotas, modules inclus, support) sont indiquées sur la page <a href="pricing.php">Tarifs</a> et au moment de la souscription.</p>

          <h2 style="margin:24px 0 8px 0">Article 3 — Prix</h2>
          <p>Les prix sont indiqués en euros (€), <strong>net de TVA</strong> en application de la franchise en base de TVA dont bénéficie l'Éditeur (article 293 B du CGI — mention&nbsp;: <em>«&nbsp;TVA non applicable, art. 293 B du CGI&nbsp;»</em>).</p>
          <p>L'Éditeur se réserve le droit de modifier ses tarifs à tout moment. Les nouvelles conditions tarifaires ne s'appliquent qu'aux abonnements souscrits ou renouvelés postérieurement à leur publication. Les Utilisateurs sont informés par email au moins 30 jours avant tout changement affectant un abonnement en cours.</p>

          <h2 style="margin:24px 0 8px 0">Article 4 — Modalités de paiement</h2>
          <p>Le paiement s'effectue exclusivement par carte bancaire via la plateforme <strong>Stripe Payments Europe Ltd</strong> (Irlande), prestataire certifié PCI-DSS niveau&nbsp;1. Les données de carte ne sont jamais stockées sur les serveurs de l'Éditeur.</p>
          <p>Le paiement est exigible dès la confirmation de la souscription. En cas d'échec de prélèvement (carte expirée, refus bancaire, fonds insuffisants), Stripe effectue plusieurs tentatives de relance. À l'issue de la procédure Stripe, et faute de régularisation, l'abonnement est suspendu puis résilié de plein droit.</p>

          <h2 style="margin:24px 0 8px 0">Article 5 — Reconduction tacite</h2>
          <p>Les abonnements sont conclus pour la période choisie (mensuelle, trimestrielle, semestrielle ou annuelle) et se reconduisent <strong>tacitement à l'identique</strong> à chaque échéance, par prélèvement automatique via Stripe, sauf résiliation préalable par l'Utilisateur.</p>
          <p>Conformément à l'article L. 215-1 du Code de la consommation, l'Utilisateur consommateur est informé par email au plus tard un mois avant l'échéance de sa faculté de ne pas reconduire le contrat.</p>

          <h2 style="margin:24px 0 8px 0">Article 6 — Droit de rétractation</h2>
          <p>Conformément à l'article L. 221-18 du Code de la consommation, l'Utilisateur consommateur dispose en principe d'un délai de <strong>14 jours</strong> à compter de la souscription pour se rétracter sans motif.</p>
          <p>Toutefois, conformément à l'article L. 221-28-1° du Code de la consommation, <strong>le droit de rétractation ne peut être exercé pour les contrats de fourniture de services pleinement exécutés avant la fin du délai de rétractation et dont l'exécution a commencé après l'accord préalable exprès du consommateur et renoncement exprès à son droit de rétractation.</strong></p>
          <p>En souscrivant à un abonnement payant, l'Utilisateur&nbsp;:</p>
          <ul>
            <li><strong>Demande expressément que la fourniture du service débute immédiatement</strong>, sans attendre l'expiration du délai de rétractation, afin d'accéder sans délai aux fonctionnalités payantes</li>
            <li><strong>Reconnaît perdre son droit de rétractation</strong> dès l'exécution complète du service, c'est-à-dire dès la mise à disposition effective des fonctionnalités payantes</li>
          </ul>
          <p>En cas de doute, l'Utilisateur peut malgré tout adresser sa demande de rétractation à <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>&nbsp;: chaque demande sera examinée individuellement.</p>

          <h2 style="margin:24px 0 8px 0">Article 7 — Résiliation par l'Utilisateur</h2>
          <p>L'Utilisateur peut résilier son abonnement à tout moment depuis le dashboard (onglet «&nbsp;Général&nbsp;» → carte abonnement → «&nbsp;Annuler&nbsp;») ou en écrivant à <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>.</p>
          <p>La résiliation prend effet <strong>au terme de la période en cours</strong> (mensuelle, trimestrielle, etc.) déjà payée. Aucun remboursement au prorata n'est effectué, sauf cas exceptionnel apprécié par l'Éditeur.</p>

          <h2 style="margin:24px 0 8px 0">Article 8 — Résiliation par l'Éditeur</h2>
          <p>L'Éditeur peut résilier de plein droit l'abonnement, sans préavis ni indemnité&nbsp;:</p>
          <ul>
            <li>En cas de défaut de paiement persistant après les relances Stripe</li>
            <li>En cas de violation grave des CGU ou des présentes CGV (usage frauduleux, contenu illicite, etc.)</li>
            <li>En cas de cessation d'activité de l'Éditeur, avec un préavis de 30 jours minimum</li>
          </ul>

          <h2 style="margin:24px 0 8px 0">Article 9 — Facturation</h2>
          <p>Une facture est émise automatiquement pour chaque échéance et accessible depuis l'espace Stripe Customer Portal (lien fourni dans le dashboard). Les factures sont conservées 10 ans conformément aux obligations comptables (Code de commerce art. L123-22).</p>

          <h2 style="margin:24px 0 8px 0">Article 10 — Service après-vente et réclamations</h2>
          <p>Pour toute réclamation relative à un abonnement&nbsp;: <a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a>. Réponse sous 5 jours ouvrés.</p>

          <h2 style="margin:24px 0 8px 0">Article 11 — Médiation de la consommation</h2>
          <p>Conformément à l'article L. 612-1 du Code de la consommation, l'Utilisateur consommateur peut, en cas de litige non résolu à l'amiable, recourir gratuitement à un médiateur de la consommation. La liste des médiateurs agréés est disponible sur <a href="https://www.economie.gouv.fr/mediation-conso" target="_blank" rel="noopener">economie.gouv.fr/mediation-conso</a>.</p>
          <p>En cas de litige transfrontalier, le consommateur peut également utiliser la plateforme européenne RLL (Règlement en Ligne des Litiges)&nbsp;: <a href="https://ec.europa.eu/consumers/odr" target="_blank" rel="noopener">ec.europa.eu/consumers/odr</a>.</p>

          <h2 style="margin:24px 0 8px 0">Article 12 — Force majeure</h2>
          <p>Aucune des parties ne pourra être tenue responsable d'un manquement à ses obligations résultant d'un cas de force majeure au sens de l'article 1218 du Code civil.</p>

          <h2 style="margin:24px 0 8px 0">Article 13 — Loi applicable et juridiction</h2>
          <p>Les présentes CGV sont soumises au droit français. Tout litige relève de la compétence des juridictions françaises, sous réserve des dispositions impératives applicables aux consommateurs (qui peuvent saisir la juridiction du lieu de leur domicile).</p>

          <h2 style="margin:24px 0 8px 0">Article 14 — Contact</h2>
          <p><a href="mailto:contact@xynoweb.fr">contact@xynoweb.fr</a> — XynoWeb, 1415 rue à Baudets, 62240 Wirwignes, France.</p>

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

<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

start_secure_session();

$user = current_user();

$success = flash_get('success');
$error = flash_get('error');

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Builder — XynoLauncher</title>
  <meta name="description" content="Tunnel de configuration : thème, modules, version, hébergement, récap." />
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
        <?php if ($user === null): ?>
          <a class="btn btn-ghost" href="login.php">Connexion</a>
          <a class="btn btn-primary" href="register.php">Créer un compte</a>
        <?php else: ?>
          <a class="btn btn-ghost" href="dashboard.php">Dashboard</a>
          <a class="btn" href="logout.php">Se déconnecter</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container" data-builder>
        <div class="callout" style="margin-bottom:14px">
          <div>
            <h1 class="section-title" style="margin:0">Tunnel de configuration</h1>
            <p class="section-desc" style="margin-top:8px">Configure ton launcher puis enregistre-le dans ton compte.</p>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="badge">Offre : <strong data-plan-pill style="color:rgba(255,255,255,.92)">PRO</strong></span>
            <span class="badge">Facturation : <strong data-billing-pill style="color:rgba(255,255,255,.92)">Mensuel</strong></span>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="wizard">
          <aside class="card steps" aria-label="Étapes">
            <a class="step" href="#" data-step-link="1" aria-current="step">
              <span class="step-num">1</span>
              <span>
                <strong style="color:rgba(255,255,255,.92)">Thème</strong><br>
                <span class="small">Choix visuel</span>
              </span>
            </a>
            <a class="step" href="#" data-step-link="2">
              <span class="step-num">2</span>
              <span>
                <strong style="color:rgba(255,255,255,.92)">Configuration</strong><br>
                <span class="small">Connexion + modules</span>
              </span>
            </a>
            <a class="step" href="#" data-step-link="3">
              <span class="step-num">3</span>
              <span>
                <strong style="color:rgba(255,255,255,.92)">Minecraft</strong><br>
                <span class="small">Version + loader</span>
              </span>
            </a>
            <a class="step" href="#" data-step-link="4">
              <span class="step-num">4</span>
              <span>
                <strong style="color:rgba(255,255,255,.92)">Hébergement</strong><br>
                <span class="small">Oui / non</span>
              </span>
            </a>
            <a class="step" href="#" data-step-link="5">
              <span class="step-num">5</span>
              <span>
                <strong style="color:rgba(255,255,255,.92)">Récap</strong><br>
                <span class="small">Enregistrement</span>
              </span>
            </a>
          </aside>

          <section class="card" aria-label="Contenu du builder">
            <div class="notice" data-notice data-show="false"></div>

            <!-- Step 1 -->
            <section data-step="1">
              <h2 class="section-title" style="margin:0">Étape 1 — Choisis un thème</h2>
              <p class="section-desc">Clique sur une carte pour sélectionner.</p>

              <div class="choice-grid" aria-label="Choix du thème">
                <article class="card choice" role="button" tabindex="0" data-theme="Violet Neon" aria-selected="false">
                  <div class="theme-preview" aria-hidden="true"></div>
                  <h3>Violet Neon</h3>
                  <p>Accent violet/bleu, look premium.</p>
                </article>
                <article class="card choice" role="button" tabindex="0" data-theme="Glacier" aria-selected="false">
                  <div class="theme-preview" style="background: radial-gradient(240px 120px at 30% 30%, rgba(34,211,238,.32), transparent 60%), radial-gradient(220px 140px at 70% 50%, rgba(37,99,235,.26), transparent 60%), rgba(255,255,255,.04);" aria-hidden="true"></div>
                  <h3>Glacier</h3>
                  <p>Minimal, lisible, moderne.</p>
                </article>
                <article class="card choice" role="button" tabindex="0" data-theme="Cosmic" aria-selected="false">
                  <div class="theme-preview" style="background: radial-gradient(240px 120px at 30% 30%, rgba(124,58,237,.30), transparent 60%), radial-gradient(220px 140px at 70% 50%, rgba(251,146,60,.18), transparent 60%), rgba(255,255,255,.04);" aria-hidden="true"></div>
                  <h3>Cosmic</h3>
                  <p>Ambiance gaming, forte identité.</p>
                </article>
              </div>

              <div class="nav-row">
                <span class="small">Astuce : tu peux venir depuis la page tarifs (plan + mensuel/annuel pré-sélectionnés).</span>
                <button class="btn btn-primary" type="button" data-next>Continuer</button>
              </div>
            </section>

            <!-- Step 2 -->
            <section data-step="2" hidden>
              <h2 class="section-title" style="margin:0">Étape 2 — Configuration</h2>
              <p class="section-desc">Définis la connexion et active les modules utiles.</p>

              <div class="two-col" style="margin-top:14px">
                <label class="label">
                  <span>Type de connexion</span>
                  <select data-connection>
                    <option value="microsoft">Microsoft</option>
                    <option value="mojang">Mojang (legacy)</option>
                    <option value="offline">Offline</option>
                  </select>
                </label>

                <label class="label">
                  <span>Offre (depuis tarifs)</span>
                  <select data-plan-select>
                    <option value="basic">Basic</option>
                    <option value="pro">Pro</option>
                    <option value="premium">Premium</option>
                  </select>
                </label>
              </div>

              <div class="grid-3" style="margin-top:14px" aria-label="Modules">
                <label class="card" style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" data-module="modpack" />
                  <div>
                    <h3 style="margin:0 0 6px">Modpack intégré</h3>
                    <p style="margin:0;color:rgba(255,255,255,.72)">Distribution de mods/ressources contrôlée.</p>
                  </div>
                </label>
                <label class="card" style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" data-module="news" />
                  <div>
                    <h3 style="margin:0 0 6px">News</h3>
                    <p style="margin:0;color:rgba(255,255,255,.72)">Bannières et annonces dans le launcher.</p>
                  </div>
                </label>
                <label class="card" style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" data-module="discord" />
                  <div>
                    <h3 style="margin:0 0 6px">Discord</h3>
                    <p style="margin:0;color:rgba(255,255,255,.72)">Lien direct + statut serveur.</p>
                  </div>
                </label>
                <label class="card" style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" data-module="autoupdate" />
                  <div>
                    <h3 style="margin:0 0 6px">Auto-update</h3>
                    <p style="margin:0;color:rgba(255,255,255,.72)">Mise à jour silencieuse des assets.</p>
                  </div>
                </label>
                <label class="card" style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" data-module="analytics" />
                  <div>
                    <h3 style="margin:0 0 6px">Analytics</h3>
                    <p style="margin:0;color:rgba(255,255,255,.72)">Mesure de lancement/versions, opt-in.</p>
                  </div>
                </label>
                <div class="card" style="opacity:.9">
                  <p class="badge">Conseil</p>
                  <p style="margin:10px 0 0;color:rgba(255,255,255,.72)">Active seulement ce qui apporte une vraie valeur.</p>
                </div>
              </div>

              <div class="nav-row">
                <button class="btn" type="button" data-back>Retour</button>
                <button class="btn btn-primary" type="button" data-next>Continuer</button>
              </div>
            </section>

            <!-- Step 3 -->
            <section data-step="3" hidden>
              <h2 class="section-title" style="margin:0">Étape 3 — Version + loader</h2>
              <p class="section-desc">Choisis la version Minecraft et le loader principal.</p>

              <div class="two-col" style="margin-top:14px">
                <label class="label">
                  <span>Version Minecraft</span>
                  <select data-mc-version>
                    <option value="1.21.4">1.21.4</option>
                    <option value="1.20.6">1.20.6</option>
                    <option value="1.19.4">1.19.4</option>
                  </select>
                </label>

                <label class="label">
                  <span>Loader</span>
                  <select data-loader>
                    <option value="fabric">Fabric</option>
                    <option value="forge">Forge</option>
                    <option value="quilt">Quilt</option>
                  </select>
                </label>
              </div>

              <div class="nav-row">
                <button class="btn" type="button" data-back>Retour</button>
                <button class="btn btn-primary" type="button" data-next>Continuer</button>
              </div>
            </section>

            <!-- Step 4 -->
            <section data-step="4" hidden>
              <h2 class="section-title" style="margin:0">Étape 4 — Hébergement</h2>
              <p class="section-desc">Active l’hébergement si tu veux une solution clé-en-main.</p>

              <div class="grid-2" style="margin-top:14px" aria-label="Hébergement">
                <article class="card choice" role="button" tabindex="0" data-hosting="no" aria-selected="true">
                  <p class="badge">Non</p>
                  <h3>Je gère mon hébergement</h3>
                  <p>Tu gardes la main sur l’infrastructure et le stockage.</p>
                </article>
                <article class="card choice" role="button" tabindex="0" data-hosting="yes" aria-selected="false">
                  <p class="badge">Oui</p>
                  <h3>Hébergement Xyno</h3>
                  <p>CDN + stockage, simple à connecter au launcher.</p>
                </article>
              </div>

              <div class="nav-row">
                <button class="btn" type="button" data-back>Retour</button>
                <button class="btn btn-primary" type="button" data-next>Continuer</button>
              </div>
            </section>

            <!-- Step 5 -->
            <section data-step="5" hidden>
              <h2 class="section-title" style="margin:0">Étape 5 — Récapitulatif</h2>
              <p class="section-desc">Vérifie la configuration puis enregistre le launcher.</p>

              <div class="toggle" data-billing-toggle aria-label="Facturation" style="margin-top:14px">
                <button type="button" data-billing="monthly" aria-pressed="true">Mensuel</button>
                <button type="button" data-billing="yearly" aria-pressed="false">Annuel</button>
                <span class="badge">-20% en annuel</span>
              </div>

              <div class="two-col" style="margin-top:14px">
                <div class="summary" data-summary aria-label="Récapitulatif"></div>

                <div class="card">
                  <p class="badge">Enregistrer</p>

                  <?php if ($user === null): ?>
                    <p class="small" style="margin:10px 0 0">Tu dois être connecté pour enregistrer un launcher.</p>
                    <div class="cta-row" style="margin-top:14px">
                      <a class="btn btn-primary" href="login.php">Se connecter</a>
                      <a class="btn" href="register.php">Créer un compte</a>
                    </div>
                  <?php else: ?>
                    <form class="form" action="create_launcher.php" method="post" data-create-launcher>
                      <label class="label" style="margin-top:10px">
                        <span>Nom du launcher</span>
                        <input class="input" name="name" placeholder="Ex: Xyno RP" required />
                      </label>

                      <label class="label">
                        <span>Description</span>
                        <input class="input" name="description" placeholder="(optionnel)" />
                      </label>

                      <label class="label">
                        <span>Code promo</span>
                        <input class="input" data-promo placeholder="Ex: FREE100" />
                        <span class="help">Démo : <strong style="color:rgba(255,255,255,.92)">FREE100</strong> (100%).</span>
                      </label>

                      <input type="hidden" name="theme" value="" data-out-theme />
                      <input type="hidden" name="version" value="" data-out-version />
                      <input type="hidden" name="loader" value="" data-out-loader />
                      <input type="hidden" name="modules" value="" data-out-modules />
                      <input type="hidden" name="promo" value="" data-out-promo />

                      <div class="cta-row" style="margin-top:14px">
                        <button class="btn btn-primary" type="submit">Créer le launcher</button>
                        <a class="btn" href="dashboard.php">Aller au dashboard</a>
                      </div>

                      <p class="small" style="margin:10px 0 0">Le paiement n’est pas géré ici : ceci enregistre uniquement le launcher.</p>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <div class="nav-row">
                <button class="btn" type="button" data-back>Retour</button>
                <a class="btn" href="dashboard.php">Dashboard</a>
              </div>
            </section>
          </section>
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
        <p class="small">© <span id="year">2026</span> XynoLauncher.</p>
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
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

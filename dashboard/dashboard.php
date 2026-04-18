<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

$pdo = db();

$stmt = $pdo->prepare('SELECT uuid, name, description, version, loader, theme, created_at FROM launchers WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$launchers = $stmt->fetchAll();

$selectedUuid = trim((string)($_GET['launcher'] ?? ''));
$selected = null;
if ($selectedUuid !== '') {
    foreach ($launchers as $l) {
        if ((string)$l['uuid'] === $selectedUuid) {
            $selected = $l;
            break;
        }
    }
}

// If a launcher is selected, load its id + api_key safely for display
$selectedKey = null;
$selectedId = null;
$versions = [];
$versionsAvailable = true;

if ($selected !== null) {
  $k = $pdo->prepare('SELECT id, api_key FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
  $k->execute([(string)$selected['uuid'], $user['id']]);
  $row = $k->fetch();
  if ($row) {
    $selectedId = (int)($row['id'] ?? 0);
    $selectedKey = (string)($row['api_key'] ?? '');
    if ($selectedKey === '') {
      $selectedKey = null;
    }
  }

  if ($selectedId && $selectedId > 0) {
    try {
      $v = $pdo->prepare('SELECT id, version_name, created_at, is_active FROM launcher_versions WHERE launcher_id = ? ORDER BY created_at DESC, id DESC');
      $v->execute([$selectedId]);
      $versions = $v->fetchAll();
    } catch (Throwable $e) {
      $versionsAvailable = false;
      $versions = [];
    }
  }
}

$csrf = csrf_token();

$success = flash_get('success');
$error = flash_get('error');

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard — XynoLauncher</title>
  <meta name="description" content="Panel utilisateur : liste et gestion des launchers." />
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
        <a class="btn btn-ghost" href="builder.php">Créer un launcher</a>
        <a class="btn" href="logout.php">Se déconnecter</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="container dashboard">
      <aside class="card sidebar" aria-label="Sidebar">
        <p class="badge">Compte</p>
        <h2 style="margin:10px 0 0;letter-spacing:-0.02em"><?php echo e($user['email']); ?></h2>
        <p class="small" style="margin:6px 0 0">UUID : <?php echo e($user['uuid']); ?></p>

        <nav class="side-links" aria-label="Menu dashboard">
          <a href="#launchers">Launchers</a>
          <a href="#parametres">Paramètres</a>
          <a href="dashboard/upload.php">Fichiers</a>
        </nav>
      </aside>

      <section aria-label="Contenu principal">
        <div class="callout">
          <div>
            <h1 class="section-title" style="margin:0">Dashboard</h1>
            <p class="section-desc" style="margin-top:8px">Gère tes launchers.</p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn btn-primary" href="builder.php">Créer un launcher</a>
            <a class="btn" href="pricing.php">Voir les prix</a>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($error); ?></div>
        <?php endif; ?>

        <section id="launchers" class="section-sm">
          <h2 class="section-title">Tes launchers</h2>
          <p class="section-desc"><?php echo count($launchers) ? 'Voici tes projets.' : 'Aucun launcher pour le moment.'; ?></p>

          <div class="launcher-grid" aria-label="Liste des launchers">
            <?php foreach ($launchers as $l): ?>
              <article class="card">
                <p class="badge">Projet</p>
                <h3 style="margin:10px 0 6px"><?php echo e((string)$l['name']); ?></h3>
                <p style="margin:0;color:rgba(255,255,255,.72)"><?php echo e((string)$l['version']); ?> • <?php echo e((string)$l['loader']); ?> • <?php echo e((string)$l['theme']); ?></p>
                <div class="cta-row">
                  <a class="btn" href="dashboard.php?launcher=<?php echo urlencode((string)$l['uuid']); ?>#parametres">Configurer</a>
                  <a class="btn btn-primary" href="download_launcher.php?uuid=<?php echo urlencode((string)$l['uuid']); ?>">Télécharger</a>
                  <form action="delete_launcher.php" method="post" style="margin:0">
                    <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$l['uuid']); ?>" />
                    <button class="btn btn-ghost" type="submit">Supprimer</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="parametres" class="section-sm">
          <h2 class="section-title">Configuration</h2>
          <p class="section-desc">Modifie un launcher existant.</p>

          <div class="card">
            <?php if ($selected === null): ?>
              <p class="small" style="margin:0">Sélectionne un launcher dans la liste pour l’éditer.</p>
            <?php else: ?>
              <form class="form" aria-label="Configuration launcher" action="update_launcher.php" method="post">
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />

                <div class="two-col">
                  <label class="label">
                    <span>UUID</span>
                    <input class="input" value="<?php echo e((string)$selected['uuid']); ?>" readonly />
                  </label>
                  <label class="label">
                    <span>API Key</span>
                    <input class="input" value="<?php echo e((string)($selectedKey ?? '')); ?>" readonly />
                    <span class="help">À garder secret (utilisé par ton launcher Electron).</span>
                  </label>
                </div>

                <div class="callout" style="margin-top:14px; padding:14px">
                  <div>
                    <p class="badge">Distribution</p>
                    <p class="section-desc" style="margin-top:8px">Télécharge l’installer adapté à ton OS (détection automatique).</p>
                  </div>
                  <div class="cta-row" style="margin:0">
                    <a class="btn btn-primary" href="download_launcher.php?uuid=<?php echo urlencode((string)$selected['uuid']); ?>">Télécharger launcher</a>
                  </div>
                </div>

                <div class="two-col">
                  <label class="label">
                    <span>Nom du launcher</span>
                    <input class="input" name="name" placeholder="Ex: Xyno RP" value="<?php echo e((string)$selected['name']); ?>" required />
                  </label>
                  <label class="label">
                    <span>Thème</span>
                    <select name="theme" required>
                      <?php foreach (['Violet Neon','Glacier','Cosmic'] as $theme): ?>
                        <option value="<?php echo e($theme); ?>" <?php echo ((string)$selected['theme'] === $theme) ? 'selected' : ''; ?>><?php echo e($theme); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>

                <label class="label">
                  <span>Description</span>
                  <input class="input" name="description" placeholder="(optionnel)" value="<?php echo e((string)$selected['description']); ?>" />
                </label>

                <div class="two-col">
                  <label class="label">
                    <span>Version Minecraft</span>
                    <select name="version" required>
                      <?php foreach (['1.21.4','1.20.6','1.19.4'] as $ver): ?>
                        <option value="<?php echo e($ver); ?>" <?php echo ((string)$selected['version'] === $ver) ? 'selected' : ''; ?>><?php echo e($ver); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="label">
                    <span>Loader</span>
                    <select name="loader" required>
                      <?php foreach (['fabric','forge','quilt'] as $ld): ?>
                        <option value="<?php echo e($ld); ?>" <?php echo ((string)$selected['loader'] === $ld) ? 'selected' : ''; ?>><?php echo e(ucfirst($ld)); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>

                <div class="nav-row">
                  <button class="btn btn-primary" type="submit">Enregistrer</button>
                  <a class="btn" href="builder.php">Créer un nouveau launcher</a>
                </div>
              </form>

              <div class="card" style="margin-top:12px; padding:14px">
                <p class="badge">Build</p>
                <p class="section-desc" style="margin-top:8px">Génère un installer via GitHub Actions et envoie-le sur le VPS.</p>
                <div class="cta-row" style="margin:12px 0 0">
                  <button class="btn btn-primary" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'mac')">Générer macOS</button>
                  <button class="btn" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'windows')">Générer Windows</button>
                  <button class="btn" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'linux')">Générer Linux</button>
                </div>
                <p class="small" style="margin:10px 0 0;color:rgba(255,255,255,.72)">Le build peut prendre plusieurs minutes. Vous pourrez le télécharger une fois terminé.</p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section id="versions" class="section-sm">
          <h2 class="section-title">Versions</h2>
          <p class="section-desc">Publie un état figé des fichiers (stable pour le launcher) et active une version existante.</p>

          <div class="card">
            <?php if ($selected === null): ?>
              <p class="small" style="margin:0">Sélectionne un launcher pour publier une version.</p>
            <?php elseif (!$versionsAvailable): ?>
              <p class="small" style="margin:0">Le versioning n’est pas disponible (table <code>launcher_versions</code> absente). Importe <code>migrations_api.sql</code>.</p>
            <?php else: ?>
              <div class="nav-row" style="align-items:center">
                <form action="publish_version.php" method="post" style="margin:0">
                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                  <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                  <button class="btn btn-primary" type="submit">Publier une version</button>
                </form>
                <p class="small" style="margin:0;color:rgba(255,255,255,.72)">
                  Le manifest servi au client est celui de la version active.
                </p>
              </div>

              <?php if (!count($versions)): ?>
                <p class="small" style="margin:12px 0 0">Aucune version publiée pour l’instant.</p>
              <?php else: ?>
                <div style="margin-top:14px;display:grid;gap:10px">
                  <?php foreach ($versions as $ver): ?>
                    <div class="card" style="padding:14px">
                      <div class="nav-row" style="align-items:center">
                        <div>
                          <p class="badge" style="margin:0"><?php echo ((int)($ver['is_active'] ?? 0) === 1) ? 'Active' : 'Historique'; ?></p>
                          <h3 style="margin:10px 0 6px"><?php echo e((string)($ver['version_name'] ?? '')); ?></h3>
                          <p class="small" style="margin:0;color:rgba(255,255,255,.72)">Publié le <?php echo e((string)($ver['created_at'] ?? '')); ?></p>
                        </div>
                        <div class="cta-row" style="margin:0">
                          <?php if ((int)($ver['is_active'] ?? 0) !== 1): ?>
                            <form action="activate_version.php" method="post" style="margin:0">
                              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                              <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                              <input type="hidden" name="version_id" value="<?php echo e((string)($ver['id'] ?? '')); ?>" />
                              <button class="btn" type="submit">Activer</button>
                            </form>
                          <?php else: ?>
                            <span class="small" style="color:rgba(255,255,255,.72)">En cours</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </section>
      </section>
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
        <p class="small"><a href="index.php">Landing</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="logout.php">Déconnexion</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());

    async function triggerLauncherBuild(uuid, os) {
        const btn = event.target;
        const originalText = btn.innerText;
        btn.innerText = "Génération en cours...";
        btn.disabled = true;

        try {
            const response = await fetch('api/trigger_build.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ uuid: uuid, target_os: os })
            });

            const result = await response.json();

            if (response.ok) {
                alert("Build " + os.toUpperCase() + " lancé avec succès sur GitHub Actions !");
            } else {
                alert("Erreur : " + (result.error || "Impossible de lancer le build."));
            }
        } catch (e) {
            alert("Erreur de connexion au serveur.");
        } finally {
            btn.innerText = originalText;
            btn.disabled = false;
        }
    }
  </script>
</body>
</html>
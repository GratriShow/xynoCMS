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

// Latest active installer per platform for the selected launcher.
$installers = ['win' => null, 'mac' => null, 'linux' => null];
if ($selectedId && $selectedId > 0) {
  try {
    $q = $pdo->prepare(
      'SELECT platform, version_name, file_url, file_sha256, is_active, created_at '
      . 'FROM launcher_downloads '
      . 'WHERE launcher_id = ? '
      . 'ORDER BY is_active DESC, created_at DESC, id DESC'
    );
    $q->execute([$selectedId]);
    foreach ($q->fetchAll() as $row) {
      $p = (string)($row['platform'] ?? '');
      if (!array_key_exists($p, $installers)) continue;
      if ($installers[$p] !== null) continue; // keep the first (active/latest)
      $installers[$p] = [
        'version' => (string)($row['version_name'] ?? ''),
        'is_active' => (int)($row['is_active'] ?? 0) === 1,
        'created_at' => (string)($row['created_at'] ?? ''),
      ];
    }
  } catch (Throwable $e) {
    // Table may be missing; leave installers empty.
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

                <div class="card" style="margin-top:14px; padding:14px">
                  <p class="badge">Installers disponibles</p>
                  <p class="section-desc" style="margin-top:8px">Télécharge l’installer propre à chaque OS. Les fichiers sont renommés automatiquement <code><?php echo e((string)$selected['name']); ?>Launcher.{ext}</code>.</p>

                  <div style="margin-top:12px; display:grid; gap:10px">
                    <?php
                      $platforms = [
                        'win'   => ['label' => 'Windows', 'ext' => 'exe'],
                        'mac'   => ['label' => 'macOS',   'ext' => 'dmg'],
                        'linux' => ['label' => 'Linux',   'ext' => 'AppImage'],
                      ];
                    ?>
                    <?php foreach ($platforms as $pKey => $pMeta): ?>
                      <?php $inst = $installers[$pKey] ?? null; ?>
                      <div class="nav-row" style="align-items:center; gap:12px; padding:10px 12px; background:rgba(255,255,255,.03); border-radius:10px">
                        <div>
                          <strong><?php echo e($pMeta['label']); ?></strong>
                          <?php if ($inst): ?>
                            <span class="small" style="margin-left:10px; color:rgba(255,255,255,.72)">
                              Version <?php echo e($inst['version'] ?: '?'); ?>
                              <?php if ($inst['is_active']): ?> • <span class="badge" style="padding:2px 8px">Actif</span><?php endif; ?>
                            </span>
                          <?php else: ?>
                            <span class="small" style="margin-left:10px; color:rgba(255,255,255,.55)">Pas encore généré</span>
                          <?php endif; ?>
                        </div>
                        <div class="cta-row" style="margin:0">
                          <?php if ($inst): ?>
                            <a class="btn btn-primary" href="download_launcher.php?uuid=<?php echo urlencode((string)$selected['uuid']); ?>&amp;platform=<?php echo e($pKey); ?>">Télécharger</a>
                          <?php else: ?>
                            <button class="btn" type="button" disabled>Indisponible</button>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
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
                  <button class="btn btn-primary" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'mac', event)">Générer macOS</button>
                  <button class="btn" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'windows', event)">Générer Windows</button>
                  <button class="btn" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'linux', event)">Générer Linux</button>
                </div>
                <p class="small" style="margin:10px 0 0;color:rgba(255,255,255,.72)">Le build peut prendre plusieurs minutes. Suivez l'avancement en temps réel ci-dessous.</p>

                <!-- Live build progress panel -->
                <div id="build-progress" class="build-progress" data-uuid="<?php echo e((string)$selected['uuid']); ?>" hidden style="margin-top:14px">
                  <div class="nav-row" style="align-items:center;gap:10px;margin-bottom:10px">
                    <p class="badge" id="build-progress-title" style="margin:0">Build en cours</p>
                    <span class="small" id="build-progress-version" style="color:rgba(255,255,255,.72)"></span>
                    <span class="small" id="build-progress-elapsed" style="color:rgba(255,255,255,.55)"></span>
                    <a id="build-progress-runlink" class="small" href="#" target="_blank" rel="noopener" style="margin-left:auto;display:none">Voir sur GitHub →</a>
                  </div>
                  <div id="build-progress-list" style="display:grid;gap:8px"></div>
                </div>
              </div>

              <style>
                .build-progress .bp-row{
                  display:grid;grid-template-columns:88px 1fr 140px;gap:12px;align-items:center;
                  padding:10px 12px;background:rgba(255,255,255,.03);border-radius:10px;
                }
                .build-progress .bp-label{font-weight:600}
                .build-progress .bp-bar{
                  height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;position:relative
                }
                .build-progress .bp-fill{
                  position:absolute;inset:0;border-radius:999px;
                  background:linear-gradient(90deg,rgba(124,58,237,.85),rgba(34,211,238,.85));
                  width:40%;
                  animation:bpPulse 1.6s ease-in-out infinite;
                }
                .build-progress .bp-row[data-state="success"] .bp-fill{
                  animation:none;width:100%;background:linear-gradient(90deg,#10b981,#34d399)
                }
                .build-progress .bp-row[data-state="failure"] .bp-fill,
                .build-progress .bp-row[data-state="cancelled"] .bp-fill{
                  animation:none;width:100%;background:linear-gradient(90deg,#ef4444,#f87171)
                }
                .build-progress .bp-row[data-state="skipped"] .bp-fill{
                  animation:none;width:100%;background:rgba(255,255,255,.18)
                }
                .build-progress .bp-state{
                  text-align:right;font-size:13px;color:rgba(255,255,255,.78);font-variant-numeric:tabular-nums
                }
                .build-progress .bp-row[data-state="success"] .bp-state{color:#34d399}
                .build-progress .bp-row[data-state="failure"] .bp-state,
                .build-progress .bp-row[data-state="cancelled"] .bp-state{color:#f87171}
                @keyframes bpPulse{
                  0%{transform:translateX(-60%);width:40%}
                  50%{transform:translateX(40%);width:60%}
                  100%{transform:translateX(120%);width:40%}
                }
              </style>
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

    // ----------- Build trigger + live progress polling -----------

    const PLATFORM_LABELS = { win: 'Windows', mac: 'macOS', linux: 'Linux' };
    const STATE_LABELS = {
      queued: 'En attente…',
      in_progress: 'Build en cours…',
      success: 'Terminé',
      failure: 'Échec',
      cancelled: 'Annulé',
      skipped: 'Ignoré',
    };
    const TERMINAL_GLOBAL = new Set(['success', 'failure', 'partial', 'cancelled']);

    let buildPoller = null;
    let buildStartTs = 0;

    async function triggerLauncherBuild(uuid, os, evt) {
        const btn = evt && evt.target;
        const originalText = btn ? btn.innerText : null;
        if (btn) {
          btn.innerText = 'Démarrage…';
          btn.disabled = true;
        }

        try {
            const response = await fetch('/api/trigger_build.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ uuid: uuid, targets: [os] })
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                alert('Erreur : ' + (result.error || ('HTTP ' + response.status)));
                return;
            }

            // Kick off the live progress panel.
            startBuildProgress(uuid, result.version || '', [osToShort(os)]);
        } catch (e) {
            alert('Erreur de connexion au serveur : ' + e.message);
        } finally {
            if (btn) {
              btn.innerText = originalText;
              btn.disabled = false;
            }
        }
    }

    function osToShort(os) {
      if (os === 'windows') return 'win';
      return os;
    }

    function startBuildProgress(uuid, version, targetsShort) {
      const panel = document.getElementById('build-progress');
      if (!panel) return;
      panel.hidden = false;
      panel.dataset.uuid = uuid;
      panel.dataset.version = version || '';

      document.getElementById('build-progress-version').textContent = version ? ('Version ' + version) : '';
      document.getElementById('build-progress-title').textContent = 'Build en cours';
      const runLink = document.getElementById('build-progress-runlink');
      runLink.style.display = 'none';
      runLink.removeAttribute('href');

      // Seed rows for the requested platforms.
      const list = document.getElementById('build-progress-list');
      list.innerHTML = '';
      for (const p of targetsShort) {
        list.appendChild(renderRow(p, 'queued'));
      }

      buildStartTs = Date.now();
      tickElapsed();
      if (buildPoller) clearInterval(buildPoller);
      buildPoller = setInterval(() => pollBuildStatus(uuid, version), 3000);
      // Immediate first poll so the UI doesn't sit stale for 3s.
      pollBuildStatus(uuid, version);
    }

    function renderRow(platform, state) {
      const row = document.createElement('div');
      row.className = 'bp-row';
      row.dataset.platform = platform;
      row.dataset.state = state;
      row.innerHTML = `
        <div class="bp-label">${PLATFORM_LABELS[platform] || platform}</div>
        <div class="bp-bar"><div class="bp-fill"></div></div>
        <div class="bp-state">${STATE_LABELS[state] || state}</div>
      `;
      return row;
    }

    function setRowState(platform, state) {
      const row = document.querySelector('.bp-row[data-platform="' + platform + '"]');
      if (!row) {
        const list = document.getElementById('build-progress-list');
        if (list) list.appendChild(renderRow(platform, state));
        return;
      }
      row.dataset.state = state;
      const stateCell = row.querySelector('.bp-state');
      if (stateCell) stateCell.textContent = STATE_LABELS[state] || state;
    }

    function tickElapsed() {
      const el = document.getElementById('build-progress-elapsed');
      if (!el || !buildStartTs) return;
      const s = Math.max(0, Math.floor((Date.now() - buildStartTs) / 1000));
      const m = Math.floor(s / 60);
      const rem = s % 60;
      el.textContent = m > 0 ? (m + 'm ' + rem + 's écoulées') : (rem + 's écoulées');
    }

    async function pollBuildStatus(uuid, version) {
      tickElapsed();
      try {
        const qs = new URLSearchParams({ uuid });
        if (version) qs.set('version', version);
        const r = await fetch('/api/build_status_public.php?' + qs.toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!r.ok) return;
        const data = await r.json();

        if (data.run_url) {
          const link = document.getElementById('build-progress-runlink');
          link.href = data.run_url;
          link.style.display = '';
        }

        const per = data.per_platform || {};
        for (const [plat, state] of Object.entries(per)) {
          setRowState(plat, state);
        }

        const global = data.global || 'queued';
        if (TERMINAL_GLOBAL.has(global)) {
          clearInterval(buildPoller);
          buildPoller = null;
          const title = document.getElementById('build-progress-title');
          if (global === 'success') {
            title.textContent = 'Build terminé ✓';
          } else if (global === 'failure') {
            title.textContent = 'Build échoué ✗';
          } else if (global === 'cancelled') {
            title.textContent = 'Build annulé';
          } else {
            title.textContent = 'Build terminé (partiel)';
          }
          // Give the user a way to refresh the installers section.
          setTimeout(() => {
            const installersSection = document.querySelector('.card p.badge');
            // Soft refresh to reload download links:
            if (global === 'success' || global === 'partial') {
              location.reload();
            }
          }, 1500);
        }
      } catch (_) {
        // Network blip — keep polling silently.
      }
    }

    // If the page loads and there's already a build in flight for the selected
    // launcher, auto-attach the progress panel so the user sees status on reload.
    (function restoreProgressOnLoad() {
      const panel = document.getElementById('build-progress');
      if (!panel) return;
      const uuid = panel.dataset.uuid;
      if (!uuid) return;
      fetch('/api/build_status_public.php?' + new URLSearchParams({ uuid }).toString(), {
        credentials: 'same-origin',
      }).then(r => r.ok ? r.json() : null).then(data => {
        if (!data || !data.version) return;
        const targets = data.targets && data.targets.length ? data.targets : Object.keys(data.per_platform || {});
        if (!targets.length) return;
        if (TERMINAL_GLOBAL.has(data.global)) return; // Don't resurrect finished builds.
        startBuildProgress(uuid, data.version, targets);
      }).catch(() => {});
    })();
  </script>
</body>
</html>
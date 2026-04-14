<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo = db();

$launchersStmt = $pdo->prepare('SELECT id, uuid, name, version, loader, theme, created_at FROM launchers WHERE user_id = ? ORDER BY created_at DESC');
$launchersStmt->execute([$user['id']]);
$launchers = $launchersStmt->fetchAll();

$selectedUuid = trim((string)($_GET['launcher'] ?? $_POST['launcher_uuid'] ?? ''));
$selected = null;

if ($selectedUuid !== '') {
    foreach ($launchers as $l) {
        if ((string)$l['uuid'] === $selectedUuid) {
            $selected = $l;
            break;
        }
    }
}

if ($selected === null && count($launchers)) {
    $selected = $launchers[0];
    $selectedUuid = (string)$selected['uuid'];
}

$wantsJson = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$success = flash_get('success');
$error = flash_get('error');

$allowedTypes = ['mod', 'config', 'asset', 'version'];
$knownModules = ['modpack', 'news', 'discord', 'autoupdate', 'analytics'];

function dashboard_json(array $payload, int $status = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_post()) {
    $action = (string)($_POST['action'] ?? 'upload');

    if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'CSRF'], 400);
        }
        flash_set('error', 'Session expirée. Ré-essaie.');
        redirect('/dashboard/upload.php?launcher=' . urlencode($selectedUuid));
    }

    if ($selected === null) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'no_launcher'], 400);
        }
        flash_set('error', 'Aucun launcher sélectionné.');
        redirect('/dashboard.php');
    }

    // Strong ownership check (server-side)
    $launcherRowStmt = $pdo->prepare('SELECT id, uuid, version, loader, modules FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $launcherRowStmt->execute([(string)$selected['uuid'], $user['id']]);
    $launcherRow = $launcherRowStmt->fetch();
    if (!$launcherRow) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }

    if ($action === 'delete') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId <= 0) {
            if ($wantsJson) {
                dashboard_json(['ok' => false, 'error' => 'invalid_file'], 400);
            }
            flash_set('error', 'Fichier invalide.');
            redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
        }

        $sel = $pdo->prepare('SELECT id, path, name FROM files WHERE id = ? AND launcher_id = ? LIMIT 1');
        $sel->execute([$fileId, (int)$launcherRow['id']]);
        $fileRow = $sel->fetch();
        if (!$fileRow) {
            if ($wantsJson) {
                dashboard_json(['ok' => false, 'error' => 'not_found'], 404);
            }
            flash_set('error', 'Fichier introuvable.');
            redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
        }

        $relativePath = (string)$fileRow['path'];
        try {
            $diskPath = files_build_disk_path_from_relative($relativePath);
            if (is_file($diskPath)) {
                @unlink($diskPath);
            }
        } catch (Throwable $e) {
            // ignore disk issues, still delete DB row
        }

        $del = $pdo->prepare('DELETE FROM files WHERE id = ? AND launcher_id = ?');
        $del->execute([(int)$fileRow['id'], (int)$launcherRow['id']]);

        try {
          $touch = $pdo->prepare('UPDATE launchers SET files_changed_at = NOW() WHERE id = ?');
          $touch->execute([(int)$launcherRow['id']]);
        } catch (Throwable $e) {
        }

        if ($wantsJson) {
            dashboard_json(['ok' => true], 200);
        }

        flash_set('success', 'Fichier supprimé.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    // upload
    $type = strtolower(trim((string)($_POST['type'] ?? '')));
    $module = strtolower(trim((string)($_POST['module'] ?? '')));
    $mcVersion = trim((string)($_POST['mc_version'] ?? ''));

    if (!in_array($type, $allowedTypes, true)) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'invalid_type'], 400);
        }
        flash_set('error', 'Type invalide.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    if ($module !== '' && !in_array($module, $knownModules, true)) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'invalid_module'], 400);
        }
        flash_set('error', 'Module invalide.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    // Only config/assets are typically module-scoped. Keep consistent.
    if (!in_array($type, ['config', 'asset'], true)) {
        $module = '';
    }

    if ($type !== 'version') {
        $mcVersion = '';
    } else {
        if ($mcVersion === '') {
            $mcVersion = (string)($launcherRow['version'] ?? '');
        }
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'missing_file'], 400);
        }
        flash_set('error', 'Aucun fichier reçu.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    $f = $_FILES['file'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $msg = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux.',
            UPLOAD_ERR_PARTIAL => 'Upload incomplet.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier.',
            default => 'Erreur upload.',
        };
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'upload_error', 'message' => $msg], 400);
        }
        flash_set('error', $msg);
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    $origName = (string)($f['name'] ?? '');
    $size = (int)($f['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'invalid_upload'], 400);
        }
        flash_set('error', 'Upload invalide.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    if ($size <= 0) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'empty_file'], 400);
        }
        flash_set('error', 'Fichier vide.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    if ($size > files_max_upload_bytes()) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'too_large'], 400);
        }
        flash_set('error', 'Fichier trop volumineux (max 200MB).');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    $safeName = sanitize_filename($origName);
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $allowedExt = files_allowed_extensions($type);
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'invalid_extension'], 400);
        }
        flash_set('error', 'Extension non autorisée pour ce type.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    // Extra hard block for dangerous extensions
    $blocked = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'js', 'html', 'htm', 'svg'];
    if (in_array($ext, $blocked, true)) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'blocked_extension'], 400);
        }
        flash_set('error', 'Extension bloquée.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }

    try {
      $minecraftPath = minecraft_relative_path($type, $safeName, $module, $mcVersion);
      $relativePath = files_build_relative_path((string)$launcherRow['uuid'], $type, $safeName, $mcVersion, $module);
      $diskPath = files_build_disk_path_from_relative($relativePath);
        ensure_dir(dirname($diskPath));

        if (!move_uploaded_file($tmp, $diskPath)) {
            throw new RuntimeException('move_failed');
        }

        @chmod($diskPath, 0644);

        $hash = sha1_file($diskPath);
        if ($hash === false) {
            throw new RuntimeException('hash_failed');
        }

        $realSize = filesize($diskPath);
        if ($realSize === false) {
            $realSize = $size;
        }

        $oldDiskPathToRemove = '';
        try {
          $pdo->beginTransaction();

          // If file exists for same minecraft path, remember old disk path for cleanup.
          $selExisting = $pdo->prepare('SELECT id, path FROM files WHERE launcher_id = ? AND relative_path = ? LIMIT 1');
          $selExisting->execute([(int)$launcherRow['id'], $minecraftPath]);
          $existing = $selExisting->fetch();
          if ($existing && isset($existing['path'])) {
            $existingPath = (string)$existing['path'];
            if ($existingPath !== '' && $existingPath !== $relativePath) {
              try {
                $oldDiskPathToRemove = files_build_disk_path_from_relative($existingPath);
              } catch (Throwable $e) {
                $oldDiskPathToRemove = '';
              }
            }
          }

          // Store both: `relative_path` (Minecraft) and `path` (public /files/...)
          $ins = $pdo->prepare(
            'INSERT INTO files (launcher_id, type, module, mc_version, version, name, relative_path, path, hash, size, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'type = VALUES(type), module = VALUES(module), mc_version = VALUES(mc_version), version = VALUES(version), name = VALUES(name), '
            . 'path = VALUES(path), hash = VALUES(hash), size = VALUES(size), updated_at = NOW()'
          );
          $ins->execute([
            (int)$launcherRow['id'],
            $type,
            $module,
            $mcVersion,
            '',
            $safeName,
            $minecraftPath,
            $relativePath,
            $hash,
            (int)$realSize,
          ]);

          $touch = $pdo->prepare('UPDATE launchers SET files_changed_at = NOW() WHERE id = ?');
          $touch->execute([(int)$launcherRow['id']]);

          $pdo->commit();
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          // If DB write fails, remove the file we just uploaded to avoid orphans.
          if (is_file($diskPath)) {
            @unlink($diskPath);
          }
          throw $e;
        }

        // Cleanup old disk file if path changed (module collision fix).
        if ($oldDiskPathToRemove !== '' && $oldDiskPathToRemove !== $diskPath && is_file($oldDiskPathToRemove)) {
          @unlink($oldDiskPathToRemove);
        }

        if ($wantsJson) {
            dashboard_json(['ok' => true, 'redirect' => path_for('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']))], 200);
        }

        flash_set('success', 'Fichier uploadé.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    } catch (PDOException $e) {
        $raw = $e->getMessage();
        $msg = 'Erreur base de données.';
        if (stripos($raw, 'unknown column') !== false || stripos($raw, 'doesn\'t exist') !== false) {
        $msg = 'Base non à jour : importe `xynocms.sql` ou applique `migrations_api.sql`.';
        }
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'db', 'message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    } catch (Throwable $e) {
        if ($wantsJson) {
            dashboard_json(['ok' => false, 'error' => 'server'], 500);
        }
        flash_set('error', 'Erreur serveur pendant l’upload.');
        redirect('/dashboard/upload.php?launcher=' . urlencode((string)$launcherRow['uuid']));
    }
}

$files = [];
if ($selected !== null) {
    try {
        $stmt = $pdo->prepare('SELECT id, type, module, mc_version, name, path, hash, size, created_at FROM files WHERE launcher_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([(int)$selected['id']]);
        $files = $stmt->fetchAll();
    } catch (Throwable $e) {
        $files = [];
    }
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Fichiers — Dashboard</title>
  <meta name="description" content="Upload et gestion des fichiers (mods, config, assets, versions)." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/style.css" />
  <script src="../assets/main.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <header class="navbar">
    <div class="container nav-inner">
      <a class="brand" href="../index.php" aria-label="XynoLauncher">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>XynoLauncher</span>
      </a>

      <nav class="nav-links" aria-label="Navigation principale">
        <a href="../index.php">Accueil</a>
        <a href="../pricing.php">Tarifs</a>
        <a href="../builder.php">Builder</a>
        <a href="../dashboard.php">Dashboard</a>
      </nav>

      <div class="nav-actions">
        <a class="btn btn-ghost" href="../builder.php">Créer un launcher</a>
        <a class="btn" href="../logout.php">Se déconnecter</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="container dashboard" data-upload-page>
      <aside class="card sidebar" aria-label="Sidebar">
        <p class="badge">Compte</p>
        <h2 style="margin:10px 0 0;letter-spacing:-0.02em"><?php echo e($user['email']); ?></h2>
        <p class="small" style="margin:6px 0 0">UUID : <?php echo e($user['uuid']); ?></p>

        <nav class="side-links" aria-label="Menu dashboard">
          <a href="../dashboard.php#launchers">Launchers</a>
          <a href="../dashboard.php#parametres">Paramètres</a>
          <a href="upload.php" aria-current="page">Fichiers</a>
        </nav>
      </aside>

      <section aria-label="Contenu principal">
        <div class="callout">
          <div>
            <h1 class="section-title" style="margin:0">Fichiers</h1>
            <p class="section-desc" style="margin-top:8px">Upload et gestion (mods, config, assets, versions).</p>
          </div>
          <div class="cta-row" style="margin:0">
            <a class="btn" href="../dashboard.php">Retour dashboard</a>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($error); ?></div>
        <?php endif; ?>

        <section class="section-sm">
          <div class="card">
            <p class="badge">Cible</p>

            <?php if (!count($launchers)): ?>
              <p class="small" style="margin:10px 0 0">Crée d’abord un launcher via le builder.</p>
              <div class="cta-row" style="margin-top:14px">
                <a class="btn btn-primary" href="../builder.php">Ouvrir le builder</a>
              </div>
            <?php else: ?>
              <form class="form" method="get" action="upload.php" style="margin-top:10px">
                <label class="label">
                  <span>Launcher</span>
                  <select name="launcher" onchange="this.form.submit()">
                    <?php foreach ($launchers as $l): ?>
                      <option value="<?php echo e((string)$l['uuid']); ?>" <?php echo ((string)$l['uuid'] === $selectedUuid) ? 'selected' : ''; ?>><?php echo e((string)$l['name']); ?> — <?php echo e((string)$l['version']); ?> • <?php echo e((string)$l['loader']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </form>
            <?php endif; ?>
          </div>
        </section>

        <?php if ($selected !== null): ?>
        <section class="section-sm">
          <div class="card">
            <p class="badge">Upload</p>

            <form class="form" method="post" enctype="multipart/form-data" action="upload.php" data-upload-form style="margin-top:10px">
              <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
              <input type="hidden" name="action" value="upload" />

              <div class="two-col">
                <label class="label">
                  <span>Type</span>
                  <select name="type" data-upload-type required>
                    <option value="mod">mod</option>
                    <option value="config">config</option>
                    <option value="asset">asset</option>
                    <option value="version">version</option>
                  </select>
                  <span class="help">Le launcher rangera automatiquement en /mods, /config, /assets.</span>
                </label>

                <label class="label" data-upload-module style="display:none">
                  <span>Module lié (optionnel)</span>
                  <select name="module">
                    <option value="">— aucun —</option>
                    <?php foreach ($knownModules as $m): ?>
                      <option value="<?php echo e($m); ?>"><?php echo e($m); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span class="help">Ex: config “news” envoyée seulement si module activé.</span>
                </label>

                <label class="label" data-upload-mc-version style="display:none">
                  <span>Minecraft version (pour type=version)</span>
                  <input class="input" name="mc_version" placeholder="Ex: 1.20.1" value="<?php echo e((string)$selected['version']); ?>" />
                </label>
              </div>

              <div class="card" data-dropzone style="margin-top:12px;border-style:dashed">
                <p class="small" style="margin:0;color:rgba(255,255,255,.78)">Glisse-dépose un fichier ici, ou choisis un fichier :</p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
                  <input type="file" name="file" required />
                  <button class="btn btn-primary" type="submit">Uploader</button>
                </div>

                <div style="margin-top:12px;display:none" data-upload-progress>
                  <div class="badge">Progression</div>
                  <div class="card" style="padding:10px;margin-top:8px">
                    <div style="height:10px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden">
                      <div data-upload-bar style="height:10px;width:0%;background:rgba(255,255,255,.65)"></div>
                    </div>
                    <p class="small" style="margin:8px 0 0" data-upload-label>0%</p>
                  </div>
                </div>
              </div>

              <p class="small" style="margin:12px 0 0">Max: 200MB. Extensions autorisées selon type (ex: .jar pour mods).</p>
            </form>
          </div>
        </section>

        <section class="section-sm">
          <div class="card">
            <p class="badge">Fichiers existants</p>

            <?php if (!count($files)): ?>
              <p class="small" style="margin:10px 0 0">Aucun fichier pour ce launcher.</p>
            <?php else: ?>
              <div style="overflow:auto;margin-top:10px">
                <table style="width:100%;border-collapse:collapse">
                  <thead>
                    <tr style="text-align:left;color:rgba(255,255,255,.72)">
                      <th style="padding:8px 10px">Type</th>
                      <th style="padding:8px 10px">Nom</th>
                      <th style="padding:8px 10px">Module</th>
                      <th style="padding:8px 10px">MC</th>
                      <th style="padding:8px 10px">Taille</th>
                      <th style="padding:8px 10px">Hash</th>
                      <th style="padding:8px 10px">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($files as $row): ?>
                      <tr style="border-top:1px solid rgba(255,255,255,.08)">
                        <td style="padding:10px"><?php echo e((string)($row['type'] ?? '')); ?></td>
                        <td style="padding:10px"><a href="<?php echo e(path_for((string)$row['path'])); ?>" target="_blank" rel="noreferrer"><?php echo e((string)$row['name']); ?></a></td>
                        <td style="padding:10px"><?php echo e((string)($row['module'] ?? '')); ?></td>
                        <td style="padding:10px"><?php echo e((string)($row['mc_version'] ?? '')); ?></td>
                        <td style="padding:10px"><?php echo e(number_format(((int)$row['size']) / 1024, 1, ',', ' ')); ?> KB</td>
                        <td style="padding:10px"><code style="font-size:12px"><?php echo e(substr((string)$row['hash'], 0, 10)); ?></code></td>
                        <td style="padding:10px">
                          <form method="post" action="upload.php" style="margin:0" onsubmit="return confirm('Supprimer ce fichier ?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                            <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                            <input type="hidden" name="action" value="delete" />
                            <input type="hidden" name="file_id" value="<?php echo e((string)$row['id']); ?>" />
                            <button class="btn btn-ghost" type="submit">Supprimer</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>

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
        <p class="small"><a href="../pricing.php">Tarifs</a></p>
        <p class="small"><a href="../builder.php">Builder</a></p>
        <p class="small"><a href="../dashboard.php">Dashboard</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="../logout.php">Déconnexion</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

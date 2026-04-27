<?php

declare(strict_types=1);

/**
 * Dashboard › Fichiers (refonte v4)
 *
 * Quatre sous-onglets :
 *   - Browser   : explorateur de fichiers + dossiers (filtrage, recherche, multi-suppr, rename, move)
 *   - Stats     : quotas par plan, usage par type, top 10 fichiers
 *   - Logs      : journal `file_events` (upload / delete / download / rename / move)
 *   - Joueurs   : `launcher_users` + sessions (last_seen, version launcher, ban/unban)
 *
 * Toutes les actions POST sont CSRF + ownership-gated, et écrivent un
 * file_events ou launcher_users pour la trace.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/files_helpers.php';

$user = require_login();
$pdo  = db();

// ----------------------------------------------------------------------------
// Launcher courant
// ----------------------------------------------------------------------------
$launchersStmt = $pdo->prepare(
    'SELECT id, uuid, name, version, loader, theme, created_at '
  . 'FROM launchers WHERE user_id = ? ORDER BY created_at DESC'
);
$launchersStmt->execute([$user['id']]);
$launchers = $launchersStmt->fetchAll();

$selectedUuid = trim((string)($_GET['launcher'] ?? $_POST['launcher_uuid'] ?? ''));
$selected = null;
foreach ($launchers as $l) {
    if ((string)$l['uuid'] === $selectedUuid) {
        $selected = $l;
        break;
    }
}
if ($selected === null && count($launchers)) {
    $selected = $launchers[0];
    $selectedUuid = (string)$selected['uuid'];
}

// ----------------------------------------------------------------------------
// Paywall : si l'abonnement de ce launcher n'est pas actif, on renvoie sur
// le dashboard ou le bloc d'abonnement permet de souscrire (la zone fichiers
// est completement verrouillee tant que le plan n'est pas paye).
// ----------------------------------------------------------------------------
if ($selected !== null) {
    try {
        $ps = $pdo->prepare(
            "SELECT status FROM subscriptions "
          . "WHERE launcher_id = ? AND user_id = ? "
          . "ORDER BY (status = 'active') DESC, created_at DESC LIMIT 1"
        );
        $ps->execute([(int)$selected['id'], $user['id']]);
        $rowSub = $ps->fetch();
        $statusSub = $rowSub ? strtolower((string)$rowSub['status']) : '';
        if ($statusSub !== 'active') {
            flash_set('error', 'Active ton abonnement pour acceder a la zone Fichiers de ce launcher.');
            redirect('/dashboard.php?launcher=' . urlencode($selectedUuid) . '&tab=general#sub-card');
        }
    } catch (Throwable $e) {
        // table subscriptions absente (pre-v4) -> on laisse passer pour ne pas bloquer le dev local
    }
}

// ----------------------------------------------------------------------------
// Actions POST
// ----------------------------------------------------------------------------
if (is_post()) {
    if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('error', 'Session expirée — recharge la page.');
        redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid));
    }
    if ($selected === null) {
        flash_set('error', 'Aucun launcher sélectionné.');
        redirect('/dashboard.php');
    }
    $launcherId = (int)$selected['id'];
    $action     = (string)($_POST['action'] ?? '');

    // -- Suppression simple ou multi-fichiers ---------------------------------
    if ($action === 'delete' || $action === 'bulk_delete') {
        $ids = [];
        if ($action === 'delete') {
            $fid = (int)($_POST['file_id'] ?? 0);
            if ($fid > 0) $ids[] = $fid;
        } else {
            foreach ((array)($_POST['file_ids'] ?? []) as $v) {
                $v = (int)$v;
                if ($v > 0) $ids[] = $v;
            }
        }
        $deleted = 0;
        foreach (array_unique($ids) as $fid) {
            try {
                $sel = $pdo->prepare('SELECT id, name, type, path, size FROM files WHERE id = ? AND launcher_id = ? LIMIT 1');
                $sel->execute([$fid, $launcherId]);
                $row = $sel->fetch();
                if (!$row) continue;
                try {
                    $disk = files_build_disk_path_from_relative((string)$row['path']);
                    if (is_file($disk)) @unlink($disk);
                } catch (Throwable $e) { /* ignore */ }
                $del = $pdo->prepare('DELETE FROM files WHERE id = ? AND launcher_id = ?');
                $del->execute([$fid, $launcherId]);
                file_event_log(
                    $pdo, $launcherId, $fid, 'delete',
                    (string)$row['name'], (string)$row['path'], (int)$row['size'],
                    'user', (int)$user['id']
                );
                $deleted++;
            } catch (Throwable $e) { /* ignore one-off failure */ }
        }
        try {
            $touch = $pdo->prepare('UPDATE launchers SET files_changed_at = NOW() WHERE id = ?');
            $touch->execute([$launcherId]);
        } catch (Throwable $e) { /* ignore */ }

        flash_set('success', $deleted . ' fichier(s) supprimé(s).');
        redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid) . '&sub=' . urlencode((string)($_POST['sub'] ?? 'browser')));
    }

    // -- Renommage (logique seule : on change `name`, pas le chemin disque) ---
    if ($action === 'rename') {
        $fid     = (int)($_POST['file_id'] ?? 0);
        $newName = sanitize_filename((string)($_POST['new_name'] ?? ''));
        if ($fid <= 0 || $newName === '') {
            flash_set('error', 'Nom invalide.');
            redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid));
        }
        try {
            $sel = $pdo->prepare('SELECT id, name, path FROM files WHERE id = ? AND launcher_id = ? LIMIT 1');
            $sel->execute([$fid, $launcherId]);
            $row = $sel->fetch();
            if (!$row) {
                flash_set('error', 'Fichier introuvable.');
                redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid));
            }
            $upd = $pdo->prepare('UPDATE files SET name = ?, updated_at = NOW() WHERE id = ?');
            $upd->execute([$newName, $fid]);
            file_event_log(
                $pdo, $launcherId, $fid, 'rename',
                $newName, (string)$row['path'], 0,
                'user', (int)$user['id']
            );
            flash_set('success', 'Fichier renommé en « ' . $newName . ' ».');
        } catch (Throwable $e) {
            flash_set('error', 'Renommage impossible : ' . $e->getMessage());
        }
        redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid));
    }

    // -- Déplacement dans un dossier logique ---------------------------------
    if ($action === 'move' || $action === 'bulk_move') {
        $folder = file_safe_folder_path((string)($_POST['folder_path'] ?? ''));
        $ids    = [];
        if ($action === 'move') {
            $fid = (int)($_POST['file_id'] ?? 0);
            if ($fid > 0) $ids[] = $fid;
        } else {
            foreach ((array)($_POST['file_ids'] ?? []) as $v) {
                $v = (int)$v;
                if ($v > 0) $ids[] = $v;
            }
        }
        $moved = 0;
        foreach (array_unique($ids) as $fid) {
            try {
                $sel = $pdo->prepare('SELECT id, name, path FROM files WHERE id = ? AND launcher_id = ? LIMIT 1');
                $sel->execute([$fid, $launcherId]);
                $row = $sel->fetch();
                if (!$row) continue;
                $upd = $pdo->prepare('UPDATE files SET folder_path = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$folder, $fid]);
                file_event_log(
                    $pdo, $launcherId, $fid, 'move',
                    (string)$row['name'], $folder, 0,
                    'user', (int)$user['id']
                );
                $moved++;
            } catch (Throwable $e) { /* ignore */ }
        }
        flash_set('success', $moved . ' fichier(s) déplacé(s) vers « ' . ($folder === '' ? '/' : $folder) . ' ».');
        redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid) . '&sub=browser&folder=' . urlencode($folder));
    }

    // -- Bannir / débannir un joueur du launcher -----------------------------
    if ($action === 'ban_user' || $action === 'unban_user') {
        $uid = (int)($_POST['launcher_user_id'] ?? 0);
        if ($uid <= 0) {
            flash_set('error', 'Joueur invalide.');
            redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid) . '&sub=players');
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (strlen($reason) > 250) $reason = substr($reason, 0, 250);
        try {
            if ($action === 'ban_user') {
                $upd = $pdo->prepare(
                    'UPDATE launcher_users SET banned_at = NOW(), ban_reason = ? WHERE id = ? AND launcher_id = ?'
                );
                $upd->execute([$reason, $uid, $launcherId]);
                flash_set('success', 'Joueur banni.');
            } else {
                $upd = $pdo->prepare(
                    'UPDATE launcher_users SET banned_at = NULL, ban_reason = NULL WHERE id = ? AND launcher_id = ?'
                );
                $upd->execute([$uid, $launcherId]);
                flash_set('success', 'Joueur débanni.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Action impossible : ' . $e->getMessage());
        }
        redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid) . '&sub=players');
    }

    flash_set('error', 'Action inconnue.');
    redirect('/dashboard/files.php?launcher=' . urlencode($selectedUuid));
}

// ----------------------------------------------------------------------------
// Sous-onglets
// ----------------------------------------------------------------------------
$validSubs = ['browser', 'stats', 'logs', 'players'];
$subTab    = (string)($_GET['sub'] ?? 'browser');
if (!in_array($subTab, $validSubs, true)) $subTab = 'browser';

$success = flash_get('success');
$error   = flash_get('error');
$csrf    = csrf_token();

$launcherId = $selected ? (int)$selected['id'] : 0;

// ----------------------------------------------------------------------------
// Données : Browser (filtres + tri + dossier courant)
// ----------------------------------------------------------------------------
$folderFilter = file_safe_folder_path((string)($_GET['folder'] ?? ''));
$typeFilter   = strtolower(trim((string)($_GET['type'] ?? '')));
$search       = trim((string)($_GET['q'] ?? ''));
$validTypes   = ['mod', 'config', 'asset', 'version'];
if (!in_array($typeFilter, $validTypes, true)) $typeFilter = '';

$browserHasFolder = false; // bool : la colonne folder_path existe-t-elle ?
$folders = [];             // sous-dossiers contenus dans le dossier courant
$files   = [];

if ($launcherId > 0 && $subTab === 'browser') {
    // 1) test rapide si folder_path existe
    try {
        $pdo->query("SELECT folder_path FROM files LIMIT 1");
        $browserHasFolder = true;
    } catch (Throwable $e) {
        $browserHasFolder = false;
    }

    // 2) liste des sous-dossiers immédiats du dossier courant
    if ($browserHasFolder) {
        try {
            $prefix = $folderFilter === '' ? '' : ($folderFilter . '/');
            // récupère tous les folder_path puis on slice côté PHP : plus simple
            // que SUBSTRING_INDEX qui se comporte différemment sous MariaDB.
            $r = $pdo->prepare('SELECT DISTINCT folder_path FROM files WHERE launcher_id = ?');
            $r->execute([$launcherId]);
            $set = [];
            foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $fp = (string)$fp;
                if ($fp === '' || $fp === $folderFilter) continue;
                if ($prefix === '') {
                    $first = explode('/', $fp, 2)[0];
                    if ($first !== '') $set[$first] = true;
                } elseif (str_starts_with($fp, $prefix)) {
                    $rest = substr($fp, strlen($prefix));
                    $first = explode('/', $rest, 2)[0];
                    if ($first !== '') $set[$folderFilter . '/' . $first] = true;
                }
            }
            $folders = array_keys($set);
            sort($folders, SORT_STRING | SORT_FLAG_CASE);
        } catch (Throwable $e) {
            $folders = [];
        }
    }

    // 3) fichiers du dossier courant + filtres
    try {
        $cols = 'id, type, module, mc_version, name, path, hash, size, created_at, updated_at';
        if ($browserHasFolder) $cols .= ', folder_path';
        try {
            $pdo->query('SELECT download_count FROM files LIMIT 1');
            $cols .= ', download_count';
            $hasDl = true;
        } catch (Throwable $e) {
            $hasDl = false;
        }

        $sql  = "SELECT $cols FROM files WHERE launcher_id = ?";
        $args = [$launcherId];
        if ($browserHasFolder) {
            $sql   .= ' AND folder_path = ?';
            $args[] = $folderFilter;
        }
        if ($typeFilter !== '') {
            $sql   .= ' AND type = ?';
            $args[] = $typeFilter;
        }
        if ($search !== '') {
            $sql   .= ' AND name LIKE ?';
            $args[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY type ASC, name ASC LIMIT 1000';
        $st   = $pdo->prepare($sql);
        $st->execute($args);
        $files = $st->fetchAll();
    } catch (Throwable $e) {
        $files = [];
    }
}

// ----------------------------------------------------------------------------
// Données : Stats
// ----------------------------------------------------------------------------
$stats     = ['total_bytes' => 0, 'total_count' => 0, 'by_type' => [], 'top_files' => []];
$activePlan = '';
$quotaBytes = 0;
$quotaPct   = 0;
$quotaWarn  = false;
if ($launcherId > 0 && in_array($subTab, ['stats', 'browser'], true)) {
    $stats      = file_stats_for_launcher($pdo, $launcherId);
    $activePlan = file_active_plan_for_launcher($pdo, $launcherId);
    $quotaBytes = file_quota_for_plan($activePlan);
    if ($quotaBytes > 0) {
        $quotaPct  = (int)min(100, floor(($stats['total_bytes'] * 100) / $quotaBytes));
        $quotaWarn = $quotaPct >= 80;
    }
}

// ----------------------------------------------------------------------------
// Données : Logs
// ----------------------------------------------------------------------------
$events = [];
$eventsAvailable = true;
$eventsByType    = [];
$dlDay  = 0;
$dlWeek = 0;
if ($launcherId > 0 && $subTab === 'logs') {
    try {
        $st = $pdo->prepare(
            'SELECT id, file_id, event, actor, path, name, size, ip, user_agent, created_at '
          . 'FROM file_events WHERE launcher_id = ? ORDER BY created_at DESC, id DESC LIMIT 200'
        );
        $st->execute([$launcherId]);
        $events = $st->fetchAll();

        $st = $pdo->prepare(
            'SELECT event, COUNT(*) AS c FROM file_events WHERE launcher_id = ? GROUP BY event'
        );
        $st->execute([$launcherId]);
        foreach ($st->fetchAll() as $r) {
            $eventsByType[(string)$r['event']] = (int)$r['c'];
        }

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM file_events WHERE launcher_id = ? "
          . "AND event = 'download' AND created_at >= (NOW() - INTERVAL 1 DAY)"
        );
        $st->execute([$launcherId]);
        $dlDay = (int)$st->fetchColumn();

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM file_events WHERE launcher_id = ? "
          . "AND event = 'download' AND created_at >= (NOW() - INTERVAL 7 DAY)"
        );
        $st->execute([$launcherId]);
        $dlWeek = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $eventsAvailable = false;
        $events = [];
    }
}

// ----------------------------------------------------------------------------
// Données : Joueurs
// ----------------------------------------------------------------------------
$players = [];
$playersAvailable = true;
$playersTotal     = 0;
$playersBanned    = 0;
$playersOnline    = 0;
$sessions         = [];
$sessionsAvailable = true;
if ($launcherId > 0 && $subTab === 'players') {
    try {
        $st = $pdo->prepare(
            'SELECT id, username, email, created_at, last_seen_at, banned_at, ban_reason '
          . 'FROM launcher_users WHERE launcher_id = ? '
          . 'ORDER BY (banned_at IS NOT NULL) DESC, last_seen_at DESC, id DESC LIMIT 200'
        );
        $st->execute([$launcherId]);
        $players = $st->fetchAll();

        $st = $pdo->prepare('SELECT COUNT(*) FROM launcher_users WHERE launcher_id = ?');
        $st->execute([$launcherId]);
        $playersTotal = (int)$st->fetchColumn();

        $st = $pdo->prepare('SELECT COUNT(*) FROM launcher_users WHERE launcher_id = ? AND banned_at IS NOT NULL');
        $st->execute([$launcherId]);
        $playersBanned = (int)$st->fetchColumn();

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM launcher_users WHERE launcher_id = ? '
          . 'AND last_seen_at IS NOT NULL AND last_seen_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $st->execute([$launcherId]);
        $playersOnline = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $playersAvailable = false;
        $players = [];
    }

    try {
        $st = $pdo->prepare(
            'SELECT id, launcher_user_id, username, ip, user_agent, launcher_version, started_at, last_seen_at '
          . 'FROM launcher_user_sessions WHERE launcher_id = ? '
          . 'ORDER BY last_seen_at DESC, id DESC LIMIT 50'
        );
        $st->execute([$launcherId]);
        $sessions = $st->fetchAll();
    } catch (Throwable $e) {
        $sessionsAvailable = false;
        $sessions = [];
    }
}

// ----------------------------------------------------------------------------
// Helpers vue
// ----------------------------------------------------------------------------
$subUrl = function (string $sub, array $extra = []) use ($selectedUuid): string {
    $qs = array_merge(['launcher' => $selectedUuid, 'sub' => $sub], $extra);
    return 'files.php?' . http_build_query($qs);
};

$crumbs = [];
if ($folderFilter !== '') {
    $cur = '';
    foreach (explode('/', $folderFilter) as $seg) {
        $cur = $cur === '' ? $seg : ($cur . '/' . $seg);
        $crumbs[] = ['label' => $seg, 'path' => $cur];
    }
}

$typeIcon = ['mod' => '🧩', 'config' => '⚙️', 'asset' => '🎨', 'version' => '📦'];

$eventLabel = [
    'upload'   => ['Upload',     '⬆️'],
    'delete'   => ['Suppression','🗑️'],
    'download' => ['Téléchargement', '⬇️'],
    'rename'   => ['Renommage',  '✏️'],
    'move'     => ['Déplacement','📂'],
];

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Fichiers — Dashboard</title>
  <meta name="description" content="Gestion des fichiers, stats, logs et joueurs du launcher." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/style.css" />
  <script src="../assets/main.js" defer></script>
  <style>
    .files-shell{display:grid;grid-template-columns:240px 1fr;gap:24px;align-items:start}
    @media (max-width: 880px){.files-shell{grid-template-columns:1fr}}
    .files-side .nav-pill{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;color:var(--muted);text-decoration:none}
    .files-side .nav-pill:hover{background:rgba(255,255,255,.04);color:#fff}
    .files-side .nav-pill.is-active{background:rgba(255,255,255,.06);color:#fff;font-weight:600}
    .files-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    .file-card{display:flex;flex-direction:column;gap:8px;padding:14px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
    .file-card .file-name{font-weight:600;word-break:break-all}
    .file-card .file-meta{display:flex;flex-wrap:wrap;gap:6px}
    .quota-bar{position:relative;height:10px;background:rgba(255,255,255,.06);border-radius:6px;overflow:hidden}
    .quota-bar > span{display:block;height:100%;background:linear-gradient(90deg,#7c5cff,#22d3ee);transition:width .3s ease}
    .quota-bar.is-warn > span{background:linear-gradient(90deg,#ff7676,#f59e0b)}
    .stats-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
    .stat-card{padding:14px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
    .stat-card .v{font-size:24px;font-weight:700;letter-spacing:-.02em;display:block;margin-top:4px}
    .stat-card .h{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    table.files-table{width:100%;border-collapse:collapse}
    table.files-table th,table.files-table td{padding:10px 8px;text-align:left;font-size:14px;border-bottom:1px solid rgba(255,255,255,.06)}
    table.files-table th{color:var(--muted);font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.08em}
    .breadcrumbs a{color:var(--muted);text-decoration:none}
    .breadcrumbs a:hover{color:#fff}
    .breadcrumbs .sep{color:var(--muted);margin:0 6px}
    .file-row-actions{display:flex;gap:6px;flex-wrap:wrap}
    .ev-pill{display:inline-flex;align-items:center;gap:6px;padding:2px 8px;border-radius:999px;font-size:12px;background:rgba(255,255,255,.06)}
    .ev-pill.upload{background:rgba(34,211,238,.18);color:#7ee9f7}
    .ev-pill.delete{background:rgba(255,118,118,.18);color:#ff9f9f}
    .ev-pill.download{background:rgba(124,92,255,.22);color:#bcaaff}
    .ev-pill.rename,.ev-pill.move{background:rgba(245,158,11,.2);color:#fcd34d}
    .player-row.is-banned{opacity:.6}
    .player-row.is-online{background:rgba(34,211,238,.06)}
  </style>
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
    <section class="container" style="padding:24px 16px">
      <p class="dash-crumbs">
        <a href="../dashboard.php">Dashboard</a>
        <?php if ($selected): ?>
          &nbsp;›&nbsp;
          <a href="../dashboard.php?launcher=<?php echo urlencode($selectedUuid); ?>"><?php echo e((string)$selected['name']); ?></a>
          &nbsp;›&nbsp;
          <strong>Fichiers</strong>
        <?php endif; ?>
      </p>

      <div class="dash-head">
        <div>
          <h1 style="margin:0">📁 Fichiers du launcher</h1>
          <p class="sub">
            <?php if ($selected): ?>
              <span class="chip accent"><?php echo e((string)$selected['name']); ?></span>
              <span class="chip plain" style="margin-left:6px"><?php echo e((string)$selected['version']); ?></span>
              <span class="chip plain" style="margin-left:6px"><?php echo e((string)$selected['loader']); ?></span>
              <?php if ($activePlan !== ''): ?>
                <span class="chip violet" style="margin-left:6px">Plan <?php echo e(ucfirst($activePlan)); ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="chip muted">Aucun launcher</span>
            <?php endif; ?>
          </p>
        </div>
        <div class="dash-head-actions">
          <?php if (count($launchers) > 1): ?>
            <form class="form" method="get" action="files.php" style="margin:0">
              <input type="hidden" name="sub" value="<?php echo e($subTab); ?>" />
              <select name="launcher" onchange="this.form.submit()">
                <?php foreach ($launchers as $l): ?>
                  <option value="<?php echo e((string)$l['uuid']); ?>" <?php echo ((string)$l['uuid'] === $selectedUuid) ? 'selected' : ''; ?>>
                    <?php echo e((string)$l['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
          <?php if ($selected): ?>
            <a class="btn" href="../dashboard.php?launcher=<?php echo urlencode($selectedUuid); ?>&tab=general#tab-general">← Retour</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($success); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($error); ?></div>
      <?php endif; ?>

      <?php if (!$selected): ?>
        <p class="callout">Crée un launcher d'abord depuis le builder.</p>
      <?php else: ?>

        <div class="files-shell" style="margin-top:16px">
          <aside class="card files-side" aria-label="Sous-onglets">
            <div class="badge" style="margin-bottom:8px">Sections</div>
            <a class="nav-pill <?php echo $subTab==='browser'?'is-active':''; ?>" href="<?php echo e($subUrl('browser')); ?>">📂 Browser</a>
            <a class="nav-pill <?php echo $subTab==='stats'  ?'is-active':''; ?>" href="<?php echo e($subUrl('stats'));   ?>">📊 Stats &amp; quotas</a>
            <a class="nav-pill <?php echo $subTab==='logs'   ?'is-active':''; ?>" href="<?php echo e($subUrl('logs'));    ?>">📋 Logs</a>
            <a class="nav-pill <?php echo $subTab==='players'?'is-active':''; ?>" href="<?php echo e($subUrl('players')); ?>">👤 Joueurs</a>

            <hr style="border:0;border-top:1px solid rgba(255,255,255,.08);margin:14px 0" />

            <div class="badge" style="margin-bottom:8px">Quota</div>
            <p class="small" style="margin:0 0 6px;color:var(--muted)">
              <?php echo file_format_size($stats['total_bytes']); ?> / <?php echo file_format_size($quotaBytes); ?>
            </p>
            <div class="quota-bar <?php echo $quotaWarn ? 'is-warn' : ''; ?>">
              <span style="width: <?php echo (int)$quotaPct; ?>%"></span>
            </div>
            <p class="small" style="margin:6px 0 0;color:<?php echo $quotaWarn ? '#fcd34d' : 'var(--muted)'; ?>">
              <?php echo $quotaWarn ? '⚠ Plus de 80 % du quota utilisé' : ($quotaPct . ' % utilisé'); ?>
            </p>
            <?php if ($activePlan === ''): ?>
              <a class="btn btn-ghost" style="margin-top:10px" href="../dashboard.php?launcher=<?php echo urlencode($selectedUuid); ?>&tab=general#tab-general">Souscrire pour augmenter</a>
            <?php endif; ?>
          </aside>

          <div>
            <?php if ($subTab === 'browser'): /* ===================== BROWSER ===================== */ ?>

              <section class="card" style="padding:14px">
                <form method="get" action="files.php" class="form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0">
                  <input type="hidden" name="launcher" value="<?php echo e($selectedUuid); ?>" />
                  <input type="hidden" name="sub" value="browser" />
                  <input type="hidden" name="folder" value="<?php echo e($folderFilter); ?>" />
                  <label class="label" style="flex:1;min-width:180px"><span>Recherche</span>
                    <input class="input" type="search" name="q" value="<?php echo e($search); ?>" placeholder="Nom du fichier…" /></label>
                  <label class="label" style="min-width:160px"><span>Type</span>
                    <select name="type">
                      <option value="">Tous</option>
                      <?php foreach ($validTypes as $tt): ?>
                        <option value="<?php echo e($tt); ?>" <?php echo $typeFilter===$tt?'selected':''; ?>><?php echo e(ucfirst($tt)); ?></option>
                      <?php endforeach; ?>
                    </select></label>
                  <button class="btn btn-primary" type="submit">Filtrer</button>
                  <?php if ($search !== '' || $typeFilter !== '' || $folderFilter !== ''): ?>
                    <a class="btn btn-ghost" href="<?php echo e($subUrl('browser')); ?>">Réinitialiser</a>
                  <?php endif; ?>
                </form>
              </section>

              <p class="breadcrumbs" style="margin:14px 0">
                <a href="<?php echo e($subUrl('browser')); ?>">/</a>
                <?php foreach ($crumbs as $c): ?>
                  <span class="sep">›</span>
                  <a href="<?php echo e($subUrl('browser', ['folder' => $c['path']])); ?>"><?php echo e($c['label']); ?></a>
                <?php endforeach; ?>
              </p>

              <?php if (!$browserHasFolder): ?>
                <div class="notice" data-show="true" style="margin-bottom:12px">
                  ⚠ La colonne <code>files.folder_path</code> n'existe pas encore.
                  Importe <code>migrations_v4.sql</code> dans phpMyAdmin pour activer l'organisation par dossiers.
                </div>
              <?php endif; ?>

              <?php if (count($folders)): ?>
                <section style="margin-bottom:14px">
                  <h3 style="margin:0 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)">Dossiers</h3>
                  <div class="files-grid">
                    <?php foreach ($folders as $f): $label = basename($f); ?>
                      <a class="file-card" href="<?php echo e($subUrl('browser', ['folder' => $f])); ?>" style="text-decoration:none;color:inherit">
                        <div class="file-meta"><span class="chip plain">📂 Dossier</span></div>
                        <div class="file-name">📁 <?php echo e($label); ?></div>
                        <div class="small" style="color:var(--muted)"><?php echo e($f); ?></div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; ?>

              <section>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                  <h3 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)">Fichiers (<?php echo count($files); ?>)</h3>
                  <a class="btn btn-primary" href="upload.php?launcher=<?php echo urlencode($selectedUuid); ?>">+ Uploader</a>
                </div>

                <?php if (!count($files)): ?>
                  <p class="callout" style="margin:0">Aucun fichier ici. Utilise l'upload pour ajouter des mods, configs, assets ou versions.</p>
                <?php else: ?>
                  <form method="post" action="files.php?launcher=<?php echo urlencode($selectedUuid); ?>&sub=browser" id="bulk-form" data-bulk-form>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                    <input type="hidden" name="launcher_uuid" value="<?php echo e($selectedUuid); ?>" />
                    <input type="hidden" name="sub" value="browser" />
                    <input type="hidden" name="action" value="bulk_delete" id="bulk-action" />

                    <div class="card" style="padding:14px;margin-bottom:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                      <span class="small" style="color:var(--muted)">
                        <span data-bulk-counter>0</span> sélectionné(s) ·
                      </span>
                      <button class="btn btn-ghost" type="button" data-bulk-toggle>Tout cocher</button>
                      <button class="btn" type="button" onclick="document.getElementById('bulk-action').value='bulk_delete';if(confirm('Supprimer les fichiers sélectionnés ?'))document.getElementById('bulk-form').submit();">🗑 Supprimer</button>
                      <details style="display:inline-block">
                        <summary class="btn" style="display:inline-block">📂 Déplacer vers…</summary>
                        <div style="margin-top:8px;display:flex;gap:6px;align-items:end">
                          <label class="label" style="margin:0">
                            <span>Dossier (ex. <code>maps/2024</code>)</span>
                            <input class="input" type="text" name="folder_path" value="<?php echo e($folderFilter); ?>" />
                          </label>
                          <button class="btn btn-primary" type="button" onclick="document.getElementById('bulk-action').value='bulk_move';document.getElementById('bulk-form').submit();">Déplacer</button>
                        </div>
                      </details>
                    </div>

                    <div class="card" style="padding:0;overflow:hidden">
                      <table class="files-table">
                        <thead>
                          <tr>
                            <th style="width:36px"><input type="checkbox" data-bulk-master /></th>
                            <th>Nom</th>
                            <th>Type</th>
                            <th>Taille</th>
                            <?php if ($browserHasFolder): ?><th>Dossier</th><?php endif; ?>
                            <th>Maj</th>
                            <th style="width:160px"></th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($files as $f): ?>
                            <tr>
                              <td><input type="checkbox" name="file_ids[]" value="<?php echo (int)$f['id']; ?>" data-bulk-item /></td>
                              <td>
                                <div style="display:flex;flex-direction:column;gap:2px">
                                  <strong><?php echo e((string)$f['name']); ?></strong>
                                  <span class="small" style="color:var(--muted)"><?php echo e((string)$f['path']); ?></span>
                                </div>
                              </td>
                              <td>
                                <span class="chip plain"><?php echo $typeIcon[(string)$f['type']] ?? '📄'; ?> <?php echo e((string)$f['type']); ?></span>
                                <?php if (!empty($f['module'])): ?>
                                  <span class="chip plain"><?php echo e((string)$f['module']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($f['mc_version'])): ?>
                                  <span class="chip plain"><?php echo e((string)$f['mc_version']); ?></span>
                                <?php endif; ?>
                              </td>
                              <td><?php echo e(file_format_size((int)$f['size'])); ?></td>
                              <?php if ($browserHasFolder): ?>
                                <td>
                                  <?php $fp = (string)($f['folder_path'] ?? ''); ?>
                                  <?php echo $fp === '' ? '<span class="small" style="color:var(--muted)">/</span>' : e($fp); ?>
                                </td>
                              <?php endif; ?>
                              <td><span class="small" style="color:var(--muted)"><?php echo e((string)($f['updated_at'] ?? $f['created_at'])); ?></span></td>
                              <td>
                                <div class="file-row-actions">
                                  <a class="btn btn-ghost" href="<?php echo e((string)$f['path']); ?>" target="_blank" rel="noopener">⬇</a>
                                  <button class="btn btn-ghost" type="button" onclick="(function(id){var n=prompt('Nouveau nom :');if(!n)return;var f=document.createElement('form');f.method='post';f.action='files.php?launcher=<?php echo urlencode($selectedUuid); ?>';f.innerHTML='<input name=csrf_token value=\'<?php echo e($csrf); ?>\'><input name=action value=rename><input name=file_id value='+id+'><input name=new_name>';f.querySelector('input[name=new_name]').value=n;document.body.appendChild(f);f.submit();})(<?php echo (int)$f['id']; ?>)">✏</button>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </form>

                  <script>
                    (function(){
                      var form = document.getElementById('bulk-form');
                      if(!form) return;
                      var master = form.querySelector('[data-bulk-master]');
                      var items  = form.querySelectorAll('[data-bulk-item]');
                      var counter = form.querySelector('[data-bulk-counter]');
                      var toggle  = form.querySelector('[data-bulk-toggle]');
                      function update(){
                        var n = 0;
                        items.forEach(function(c){ if(c.checked) n++; });
                        counter.textContent = n;
                      }
                      master && master.addEventListener('change', function(){
                        items.forEach(function(c){ c.checked = master.checked; });
                        update();
                      });
                      items.forEach(function(c){ c.addEventListener('change', update); });
                      toggle && toggle.addEventListener('click', function(){
                        var allChecked = true;
                        items.forEach(function(c){ if(!c.checked) allChecked = false; });
                        items.forEach(function(c){ c.checked = !allChecked; });
                        if(master) master.checked = !allChecked;
                        update();
                      });
                    })();
                  </script>
                <?php endif; ?>
              </section>

            <?php elseif ($subTab === 'stats'): /* ===================== STATS ===================== */ ?>

              <section class="card" style="padding:18px">
                <h2 style="margin:0 0 14px">📊 Quota et usage</h2>
                <div class="stats-cards">
                  <div class="stat-card">
                    <span class="h">Stockage utilisé</span>
                    <span class="v"><?php echo e(file_format_size($stats['total_bytes'])); ?></span>
                    <span class="small" style="color:var(--muted)">sur <?php echo e(file_format_size($quotaBytes)); ?></span>
                  </div>
                  <div class="stat-card">
                    <span class="h">Fichiers</span>
                    <span class="v"><?php echo (int)$stats['total_count']; ?></span>
                    <span class="small" style="color:var(--muted)">tous types confondus</span>
                  </div>
                  <div class="stat-card">
                    <span class="h">Plan actif</span>
                    <span class="v"><?php echo $activePlan === '' ? '—' : e(ucfirst($activePlan)); ?></span>
                    <span class="small" style="color:var(--muted)"><?php echo $activePlan === '' ? 'Free tier (250 MB)' : 'Quota Stripe payant'; ?></span>
                  </div>
                  <div class="stat-card">
                    <span class="h">Pourcentage</span>
                    <span class="v"><?php echo (int)$quotaPct; ?> %</span>
                    <span class="small" style="color:<?php echo $quotaWarn ? '#fcd34d' : 'var(--muted)'; ?>"><?php echo $quotaWarn ? 'Approche du plafond' : 'OK'; ?></span>
                  </div>
                </div>
                <div class="quota-bar <?php echo $quotaWarn ? 'is-warn' : ''; ?>" style="margin-top:18px">
                  <span style="width: <?php echo (int)$quotaPct; ?>%"></span>
                </div>
              </section>

              <section class="card" style="padding:18px;margin-top:14px">
                <h2 style="margin:0 0 14px">🥧 Répartition par type</h2>
                <?php if (!count($stats['by_type'])): ?>
                  <p class="small" style="color:var(--muted);margin:0">Aucune donnée — uploade des fichiers d'abord.</p>
                <?php else: ?>
                  <table class="files-table">
                    <thead><tr><th>Type</th><th>Fichiers</th><th>Taille</th><th>Part</th></tr></thead>
                    <tbody>
                      <?php foreach ($stats['by_type'] as $type => $row): ?>
                        <?php $pct = $stats['total_bytes'] > 0 ? (int)round(($row['bytes']*100)/$stats['total_bytes']) : 0; ?>
                        <tr>
                          <td><?php echo $typeIcon[$type] ?? '📄'; ?> <?php echo e(ucfirst((string)$type)); ?></td>
                          <td><?php echo (int)$row['count']; ?></td>
                          <td><?php echo e(file_format_size((int)$row['bytes'])); ?></td>
                          <td>
                            <div class="quota-bar" style="max-width:120px"><span style="width: <?php echo $pct; ?>%"></span></div>
                            <span class="small" style="color:var(--muted)"><?php echo $pct; ?> %</span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </section>

              <section class="card" style="padding:18px;margin-top:14px">
                <h2 style="margin:0 0 14px">🏆 Top 10 fichiers les plus gros</h2>
                <?php if (!count($stats['top_files'])): ?>
                  <p class="small" style="color:var(--muted);margin:0">Aucun fichier indexé.</p>
                <?php else: ?>
                  <table class="files-table">
                    <thead><tr><th>Nom</th><th>Type</th><th>Taille</th><th>Ajouté</th></tr></thead>
                    <tbody>
                      <?php foreach ($stats['top_files'] as $tf): ?>
                        <tr>
                          <td><?php echo e((string)$tf['name']); ?></td>
                          <td><?php echo $typeIcon[(string)$tf['type']] ?? '📄'; ?> <?php echo e((string)$tf['type']); ?></td>
                          <td><?php echo e(file_format_size((int)$tf['size'])); ?></td>
                          <td><span class="small" style="color:var(--muted)"><?php echo e((string)$tf['created_at']); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </section>

            <?php elseif ($subTab === 'logs'): /* ===================== LOGS ===================== */ ?>

              <section class="card" style="padding:18px">
                <h2 style="margin:0 0 14px">📋 Activité fichiers (200 dernières)</h2>

                <?php if (!$eventsAvailable): ?>
                  <div class="notice" data-show="true">
                    ⚠ Table <code>file_events</code> manquante. Importe <code>migrations_v4.sql</code> pour activer le journal.
                  </div>
                <?php else: ?>
                  <div class="stats-cards" style="margin-bottom:14px">
                    <div class="stat-card">
                      <span class="h">Téléchargements 24 h</span>
                      <span class="v"><?php echo (int)$dlDay; ?></span>
                    </div>
                    <div class="stat-card">
                      <span class="h">Téléchargements 7 j</span>
                      <span class="v"><?php echo (int)$dlWeek; ?></span>
                    </div>
                    <?php foreach (['upload','delete','rename','move'] as $k): ?>
                      <div class="stat-card">
                        <span class="h"><?php echo e(ucfirst($k)); ?> (total)</span>
                        <span class="v"><?php echo (int)($eventsByType[$k] ?? 0); ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <?php if (!count($events)): ?>
                    <p class="small" style="color:var(--muted);margin:0">Aucune activité enregistrée pour ce launcher.</p>
                  <?php else: ?>
                    <table class="files-table">
                      <thead>
                        <tr>
                          <th>Quand</th>
                          <th>Action</th>
                          <th>Cible</th>
                          <th>Taille</th>
                          <th>Auteur</th>
                          <th>IP</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($events as $ev): ?>
                          <?php
                            $k = (string)$ev['event'];
                            $label = $eventLabel[$k] ?? [$k, '•'];
                          ?>
                          <tr>
                            <td><span class="small" style="color:var(--muted)"><?php echo e((string)$ev['created_at']); ?></span></td>
                            <td><span class="ev-pill <?php echo e($k); ?>"><?php echo $label[1]; ?> <?php echo e($label[0]); ?></span></td>
                            <td>
                              <strong><?php echo e((string)($ev['name'] ?: '(sans nom)')); ?></strong>
                              <?php if (!empty($ev['path'])): ?>
                                <div class="small" style="color:var(--muted)"><?php echo e((string)$ev['path']); ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?php echo $ev['size'] > 0 ? e(file_format_size((int)$ev['size'])) : '<span class="small" style="color:var(--muted)">—</span>'; ?></td>
                            <td><span class="chip plain"><?php echo e((string)$ev['actor']); ?></span></td>
                            <td><span class="small" style="color:var(--muted)"><?php echo e((string)($ev['ip'] ?? '')); ?></span></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                <?php endif; ?>
              </section>

            <?php elseif ($subTab === 'players'): /* ===================== PLAYERS ===================== */ ?>

              <section class="card" style="padding:18px">
                <h2 style="margin:0 0 14px">👤 Comptes joueurs du launcher</h2>

                <?php if (!$playersAvailable): ?>
                  <div class="notice" data-show="true">
                    ⚠ Table <code>launcher_users</code> manquante. Importe <code>migrations_v4.sql</code> pour activer la gestion joueurs.
                  </div>
                <?php else: ?>
                  <div class="stats-cards" style="margin-bottom:14px">
                    <div class="stat-card">
                      <span class="h">Total comptes</span>
                      <span class="v"><?php echo (int)$playersTotal; ?></span>
                    </div>
                    <div class="stat-card">
                      <span class="h">En ligne (15 min)</span>
                      <span class="v"><?php echo (int)$playersOnline; ?></span>
                    </div>
                    <div class="stat-card">
                      <span class="h">Bannis</span>
                      <span class="v"><?php echo (int)$playersBanned; ?></span>
                    </div>
                  </div>

                  <?php if (!count($players)): ?>
                    <p class="small" style="color:var(--muted);margin:0">Personne ne s'est encore connecté à ton launcher.</p>
                  <?php else: ?>
                    <table class="files-table">
                      <thead>
                        <tr>
                          <th>Pseudo</th>
                          <th>Email</th>
                          <th>Vu pour la dernière fois</th>
                          <th>Inscrit</th>
                          <th>État</th>
                          <th style="width:200px"></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($players as $p): ?>
                          <?php
                            $isBanned = !empty($p['banned_at']);
                            $lastSeenTs = $p['last_seen_at'] ? strtotime((string)$p['last_seen_at']) : 0;
                            $isOnline = $lastSeenTs && (time() - $lastSeenTs) < 900;
                            $cls = ($isBanned ? 'is-banned ' : '') . ($isOnline ? 'is-online' : '');
                          ?>
                          <tr class="player-row <?php echo e(trim($cls)); ?>">
                            <td><strong><?php echo e((string)$p['username']); ?></strong></td>
                            <td><span class="small" style="color:var(--muted)"><?php echo e((string)($p['email'] ?? '')); ?></span></td>
                            <td><span class="small" style="color:var(--muted)"><?php echo e((string)($p['last_seen_at'] ?: '—')); ?></span></td>
                            <td><span class="small" style="color:var(--muted)"><?php echo e((string)$p['created_at']); ?></span></td>
                            <td>
                              <?php if ($isBanned): ?>
                                <span class="chip danger">Banni</span>
                                <?php if (!empty($p['ban_reason'])): ?>
                                  <span class="small" style="color:var(--muted)">— <?php echo e((string)$p['ban_reason']); ?></span>
                                <?php endif; ?>
                              <?php elseif ($isOnline): ?>
                                <span class="chip ok">En ligne</span>
                              <?php else: ?>
                                <span class="chip muted">Hors ligne</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if ($isBanned): ?>
                                <form method="post" action="files.php?launcher=<?php echo urlencode($selectedUuid); ?>&sub=players" style="margin:0">
                                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                                  <input type="hidden" name="launcher_uuid" value="<?php echo e($selectedUuid); ?>" />
                                  <input type="hidden" name="action" value="unban_user" />
                                  <input type="hidden" name="launcher_user_id" value="<?php echo (int)$p['id']; ?>" />
                                  <button class="btn" type="submit">Débannir</button>
                                </form>
                              <?php else: ?>
                                <form method="post" action="files.php?launcher=<?php echo urlencode($selectedUuid); ?>&sub=players" style="margin:0;display:flex;gap:6px"
                                      onsubmit="return confirm('Bannir <?php echo e((string)$p['username']); ?> ?');">
                                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                                  <input type="hidden" name="launcher_uuid" value="<?php echo e($selectedUuid); ?>" />
                                  <input type="hidden" name="action" value="ban_user" />
                                  <input type="hidden" name="launcher_user_id" value="<?php echo (int)$p['id']; ?>" />
                                  <input class="input" type="text" name="reason" placeholder="Raison (option.)" style="max-width:140px" />
                                  <button class="btn btn-ghost" type="submit">Bannir</button>
                                </form>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                <?php endif; ?>
              </section>

              <section class="card" style="padding:18px;margin-top:14px">
                <h2 style="margin:0 0 14px">🛰 Sessions launcher (50 dernières)</h2>
                <?php if (!$sessionsAvailable): ?>
                  <div class="notice" data-show="true">
                    ⚠ Table <code>launcher_user_sessions</code> manquante. Importe <code>migrations_v4.sql</code>.
                  </div>
                <?php elseif (!count($sessions)): ?>
                  <p class="small" style="color:var(--muted);margin:0">Aucune session enregistrée.</p>
                <?php else: ?>
                  <table class="files-table">
                    <thead>
                      <tr>
                        <th>Pseudo</th>
                        <th>IP</th>
                        <th>User-Agent</th>
                        <th>Version launcher</th>
                        <th>Démarrée</th>
                        <th>Dernière action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($sessions as $s): ?>
                        <tr>
                          <td><strong><?php echo e((string)$s['username']); ?></strong></td>
                          <td><span class="small" style="color:var(--muted)"><?php echo e((string)$s['ip']); ?></span></td>
                          <td><span class="small" style="color:var(--muted)"><?php echo e((string)$s['user_agent']); ?></span></td>
                          <td><span class="chip plain"><?php echo e((string)$s['launcher_version']); ?></span></td>
                          <td><span class="small" style="color:var(--muted)"><?php echo e((string)$s['started_at']); ?></span></td>
                          <td><span class="small" style="color:var(--muted)"><?php echo e((string)$s['last_seen_at']); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </section>

            <?php endif; ?>
          </div>
        </div>

      <?php endif; /* selected launcher */ ?>
    </section>
  </main>

  <footer class="container small" style="text-align:center;padding:30px 16px;color:var(--muted)">
    © <?php echo date('Y'); ?> XynoCMS — gestion fichiers v4.
  </footer>
</body>
</html>

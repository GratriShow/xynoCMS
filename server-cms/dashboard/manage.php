<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

$uuid = trim((string)($_GET['uuid'] ?? ''));
if ($uuid === '') redirect('/server-cms/dashboard/servers.php');

$server = get_user_server($pdo, $user['id'], $uuid);
if (!$server) {
    flash_set('error', 'Serveur introuvable.');
    redirect('/server-cms/dashboard/servers.php');
}

// Plugins
$pluginsStmt = $pdo->prepare('SELECT * FROM mc_server_plugins WHERE server_id = ? ORDER BY added_at DESC');
$pluginsStmt->execute([$server['id']]);
$plugins = $pluginsStmt->fetchAll();

// Mods
$modsStmt = $pdo->prepare('SELECT * FROM mc_server_mods WHERE server_id = ? ORDER BY added_at DESC');
$modsStmt->execute([$server['id']]);
$mods = $modsStmt->fetchAll();

// Launchers liés
$linksStmt = $pdo->prepare(
    'SELECT sll.*, l.name AS launcher_name '
    . 'FROM mc_server_launcher_links sll '
    . 'LEFT JOIN launchers l ON l.uuid = sll.launcher_uuid '
    . 'WHERE sll.server_id = ? ORDER BY sll.linked_at DESC'
);
$linksStmt->execute([$server['id']]);
$links = $linksStmt->fetchAll();

// Joueurs whitelist
$playersStmt = $pdo->prepare('SELECT * FROM mc_server_players WHERE server_id = ? ORDER BY added_at DESC');
$playersStmt->execute([$server['id']]);
$players = $playersStmt->fetchAll();

// Launchers disponibles (non encore liés)
$allLaunchersStmt = $pdo->prepare(
    'SELECT uuid, name FROM launchers WHERE user_id = ? '
    . 'AND uuid NOT IN (SELECT launcher_uuid FROM mc_server_launcher_links WHERE server_id = ?) '
    . 'ORDER BY name ASC'
);
$allLaunchersStmt->execute([$user['id'], $server['id']]);
$availableLaunchers = $allLaunchersStmt->fetchAll();

$config = json_decode((string)($server['server_config'] ?? '{}'), true) ?: [];

$typeIcons = ['vanilla'=>'🟢','paper'=>'📄','spigot'=>'🔌','forge'=>'⚙️','fabric'=>'🧵'];
$serverType = (string)($server['server_type'] ?? 'paper');
$icon = $typeIcons[$serverType] ?? '🖥️';

// Détermine si c'est un serveur plugins ou mods
$isPlugin = in_array($serverType, ['paper','spigot'], true);
$isMod    = in_array($serverType, ['forge','fabric'], true);

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>XynoServer — <?= e($server['name']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= e(base_path()) ?>/assets/style.css" />
  <style>
    .manage-layout { display: grid; grid-template-columns: 300px 1fr; gap: 20px; margin: 28px auto; padding: 0 var(--gutter); max-width: 1280px; }
    @media (max-width: 900px) { .manage-layout { grid-template-columns: 1fr; } }

    /* Sidebar */
    .sidebar-card {
      background: var(--d-surface); border: 1px solid var(--d-border);
      border-radius: var(--d-radius-lg); overflow: hidden; height: fit-content;
      position: sticky; top: 80px;
    }
    .sidebar-header {
      padding: 20px; border-bottom: 1px solid var(--d-border);
      background: linear-gradient(180deg, var(--d-surface-2) 0%, transparent 100%);
    }
    .sidebar-title { font-size: 17px; font-weight: 800; margin: 0 0 6px; }
    .sidebar-meta { font-size: 12px; color: var(--d-text-2); }
    .sidebar-nav { padding: 8px 0; }
    .sidebar-nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 20px; font-size: 14px; font-weight: 500;
      color: var(--d-text-2); cursor: pointer;
      transition: all .12s; border-left: 2px solid transparent;
    }
    .sidebar-nav-item:hover { color: var(--d-text); background: var(--d-elevated); }
    .sidebar-nav-item.active { color: var(--d-text); background: var(--d-accent-soft); border-left-color: var(--accent); }
    .sidebar-api-key {
      padding: 14px 20px; border-top: 1px solid var(--d-border);
      background: var(--d-elevated);
    }
    .sidebar-api-label { font-size: 11px; color: var(--d-text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
    .api-key-box {
      display: flex; align-items: center; gap: 6px;
      background: var(--d-surface); border: 1px solid var(--d-border-2);
      border-radius: var(--d-radius-sm); padding: 7px 10px;
    }
    .api-key-text {
      font-family: monospace; font-size: 11px; color: var(--d-text-2);
      overflow: hidden; white-space: nowrap; text-overflow: ellipsis; flex: 1;
    }
    .copy-btn {
      font-size: 12px; cursor: pointer; color: var(--d-text-3); flex-shrink: 0;
      border: none; background: none; padding: 0; line-height: 1;
    }
    .copy-btn:hover { color: var(--d-text); }

    /* Main area */
    .main-section { display: none; }
    .main-section.active { display: block; }
    .section-card {
      background: var(--d-surface); border: 1px solid var(--d-border);
      border-radius: var(--d-radius-lg); padding: 24px; margin-bottom: 16px;
    }
    .section-title { font-size: 17px; font-weight: 800; margin: 0 0 4px; }
    .section-sub { color: var(--d-text-2); font-size: 13px; margin: 0 0 20px; }

    /* Forms */
    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--d-text); margin-bottom: 6px; }
    .form-label span { color: var(--d-text-3); font-weight: 400; }
    .form-input {
      width: 100%; padding: 9px 12px; background: var(--d-elevated);
      border: 1px solid var(--d-border-2); border-radius: var(--d-radius);
      color: var(--d-text); font-size: 13px; font-family: inherit; transition: border-color .15s;
    }
    .form-input:focus { outline: none; border-color: var(--accent); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }

    /* Buttons */
    .btn { padding: 9px 18px; font-size: 13px; font-weight: 600; border-radius: var(--d-radius); border: 1px solid var(--d-border-2); background: var(--d-elevated); color: var(--d-text); cursor: pointer; transition: all .15s; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn:hover { background: var(--surface-3); }
    .btn-primary { background: var(--grad-primary); border-color: transparent; color: #fff; }
    .btn-primary:hover { filter: brightness(1.1); }
    .btn-danger { background: rgba(255,77,106,.12); border-color: rgba(255,77,106,.3); color: #ff4d6a; }
    .btn-danger:hover { background: rgba(255,77,106,.22); }
    .btn-sm { padding: 6px 12px; font-size: 12px; }

    /* Package list */
    .package-list { display: flex; flex-direction: column; gap: 8px; }
    .package-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 14px; background: var(--d-elevated);
      border: 1px solid var(--d-border); border-radius: var(--d-radius);
    }
    .package-icon { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; background: var(--d-surface-2); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .package-info { flex: 1; min-width: 0; }
    .package-name { font-size: 13px; font-weight: 700; color: var(--d-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .package-version { font-size: 11px; color: var(--d-text-3); margin-top: 1px; }

    /* Search results */
    .search-results { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; max-height: 400px; overflow-y: auto; }
    .search-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px; background: var(--d-elevated);
      border: 1px solid var(--d-border); border-radius: var(--d-radius);
      transition: border-color .12s;
    }
    .search-item:hover { border-color: var(--d-border-2); }
    .search-icon { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: var(--d-surface-2); display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    .search-info { flex: 1; min-width: 0; }
    .search-name { font-size: 14px; font-weight: 700; color: var(--d-text); }
    .search-desc { font-size: 12px; color: var(--d-text-2); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .search-downloads { font-size: 11px; color: var(--d-text-3); margin-top: 2px; }
    .btn-add { font-size: 12px; padding: 6px 12px; white-space: nowrap; }

    /* Links */
    .link-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; background: var(--d-elevated); border: 1px solid var(--d-border); border-radius: var(--d-radius); }
    .player-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--d-elevated); border: 1px solid var(--d-border); border-radius: var(--d-radius); }
    .player-avatar { width: 32px; height: 32px; border-radius: 4px; background: var(--d-surface-2); display: flex; align-items: center; justify-content: center; font-size: 14px; }

    /* Status badge */
    .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .status-configuring { background: rgba(255,190,0,.12); color: #ffbe00; border: 1px solid rgba(255,190,0,.3); }
    .status-ready       { background: rgba(0,214,143,.12); color: #00d68f; border: 1px solid rgba(0,214,143,.3); }
    .status-running     { background: rgba(0,214,143,.2);  color: #00d68f; border: 1px solid rgba(0,214,143,.5); }
    .status-stopped     { background: rgba(255,77,106,.12);color: #ff4d6a; border: 1px solid rgba(255,77,106,.3); }

    .spinner { width: 14px; height: 14px; border: 2px solid var(--d-border-2); border-top-color: var(--accent); border-radius: 50%; animation: spin .7s linear infinite; display: inline-block; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; background: var(--d-surface-2); border: 1px solid var(--d-border-2); border-radius: var(--d-radius); font-size: 13px; font-weight: 600; box-shadow: var(--shadow-md); z-index: 200; transition: opacity .3s; }
    .toast.success { border-color: rgba(0,214,143,.4); color: #00d68f; }
    .toast.error   { border-color: rgba(255,77,106,.4); color: #ff4d6a; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="container nav-inner">
    <a href="<?= e(base_path()) ?>/" class="brand">
      <div class="brand-mark" style="width:32px;height:32px;background:var(--grad-primary);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:14px;">X</div>
      <span>XynoCMS</span>
    </a>
    <div style="display:flex;align-items:center;gap:16px;">
      <a href="<?= e(base_path()) ?>/dashboard.php" class="btn btn-sm">🚀 Launchers</a>
      <a href="<?= e(server_cms_base()) ?>/dashboard/servers.php" class="btn btn-sm" style="background:var(--d-accent-soft);border-color:rgba(124,92,255,.3);">🖥️ Serveurs</a>
    </div>
  </div>
</nav>

<div class="manage-layout">

  <!-- ── Sidebar ── -->
  <aside>
    <div class="sidebar-card">
      <div class="sidebar-header">
        <div style="font-size:28px;margin-bottom:8px;"><?= e($icon) ?></div>
        <div class="sidebar-title"><?= e($server['name']) ?></div>
        <div class="sidebar-meta">
          <?= e(strtoupper($serverType)) ?> · MC <?= e((string)$server['mc_version']) ?><br>
          <span class="status-badge status-<?= e((string)$server['status']) ?>" style="margin-top:6px;">
            <?= e(ucfirst((string)$server['status'])) ?>
          </span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="sidebar-nav-item active" onclick="showSection('overview')">📊 Vue d'ensemble</div>
        <?php if ($isPlugin): ?>
        <div class="sidebar-nav-item" onclick="showSection('plugins')">🔌 Plugins (<?= count($plugins) ?>)</div>
        <?php endif; ?>
        <?php if ($isMod): ?>
        <div class="sidebar-nav-item" onclick="showSection('mods')">⚙️ Mods (<?= count($mods) ?>)</div>
        <?php endif; ?>
        <div class="sidebar-nav-item" onclick="showSection('launchers')">🚀 Launchers liés (<?= count($links) ?>)</div>
        <div class="sidebar-nav-item" onclick="showSection('whitelist')">👥 Whitelist (<?= count($players) ?>)</div>
        <div class="sidebar-nav-item" onclick="showSection('config')">⚙️ Configuration</div>
        <div class="sidebar-nav-item" onclick="showSection('files')">⬇️ Télécharger les fichiers</div>
      </nav>

      <div class="sidebar-api-key">
        <div class="sidebar-api-label">API Key serveur</div>
        <div class="api-key-box">
          <span class="api-key-text" id="api-key-display"><?= e(str_repeat('•', 20)) ?></span>
          <button class="copy-btn" onclick="toggleApiKey()" title="Afficher / Copier">👁️</button>
        </div>
        <div style="font-size:10px;color:var(--d-text-3);margin-top:5px;">Utilisée par le launcher pour se connecter</div>
      </div>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main>

    <!-- ── Vue d'ensemble ── -->
    <div id="section-overview" class="main-section active">
      <div class="section-card">
        <h2 class="section-title">📊 Vue d'ensemble</h2>
        <p class="section-sub">Informations générales et actions rapides.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
          <?php
            $stats = [
              ['Type', strtoupper($serverType), '🖥️'],
              ['Version MC', $server['mc_version'], '🎮'],
              ['RAM', $server['ram_mb'] . ' Mo', '💾'],
              ['Port', $server['server_port'], '🌐'],
              ['Plugins/Mods', count($plugins) + count($mods), '📦'],
              ['Launchers liés', count($links), '🔗'],
            ];
          ?>
          <?php foreach ($stats as [$label, $val, $ico]): ?>
          <div style="background:var(--d-elevated);border:1px solid var(--d-border);border-radius:var(--d-radius);padding:14px;text-align:center;">
            <div style="font-size:22px;margin-bottom:6px;"><?= $ico ?></div>
            <div style="font-size:18px;font-weight:700;color:var(--d-text);"><?= e((string)$val) ?></div>
            <div style="font-size:11px;color:var(--d-text-3);margin-top:2px;"><?= e($label) ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($server['server_ip'])): ?>
        <div style="padding:14px;background:var(--d-elevated);border:1px solid var(--d-border);border-radius:var(--d-radius);display:flex;align-items:center;gap:12px;">
          <span style="font-size:20px;">🌐</span>
          <div>
            <div style="font-size:12px;color:var(--d-text-3);font-weight:600;">Adresse de connexion</div>
            <div style="font-size:15px;font-weight:700;font-family:monospace;color:var(--d-text);">
              <?= e((string)$server['server_ip']) ?>:<?= e((string)$server['server_port']) ?>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div style="padding:14px;background:rgba(255,190,0,.08);border:1px solid rgba(255,190,0,.2);border-radius:var(--d-radius);color:#ffbe00;font-size:13px;">
          ⚠️ Aucune IP configurée. Renseigne-la dans <strong>Configuration</strong> pour que le launcher puisse s'y connecter.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Plugins ── -->
    <?php if ($isPlugin): ?>
    <div id="section-plugins" class="main-section">
      <div class="section-card">
        <h2 class="section-title">🔌 Plugins</h2>
        <p class="section-sub">Recherche et ajoute des plugins Bukkit / Paper via Modrinth.</p>

        <!-- Recherche -->
        <div style="display:flex;gap:8px;margin-bottom:12px;">
          <input class="form-input" type="text" id="plugin-search" placeholder="Rechercher un plugin (ex: EssentialsX, Vault, WorldEdit…)" style="flex:1;" onkeydown="if(event.key==='Enter')searchPackage('plugin')">
          <button class="btn btn-primary" onclick="searchPackage('plugin')">🔍 Chercher</button>
        </div>
        <div id="plugin-results"></div>
      </div>

      <div class="section-card">
        <h2 class="section-title">Plugins installés (<?= count($plugins) ?>)</h2>
        <div class="package-list" id="plugins-list">
          <?php foreach ($plugins as $p): ?>
          <div class="package-item" id="pkg-plugin-<?= (int)$p['id'] ?>">
            <div class="package-icon">🔌</div>
            <div class="package-info">
              <div class="package-name"><?= e((string)$p['name']) ?></div>
              <div class="package-version">v<?= e((string)$p['version']) ?> · <?= e(strtoupper((string)$p['source'])) ?></div>
            </div>
            <button class="btn btn-danger btn-sm" onclick="removePackage(<?= (int)$p['id'] ?>, 'plugin', this)">✕ Retirer</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($plugins)): ?>
          <div style="text-align:center;padding:24px;color:var(--d-text-3);font-size:13px;">
            Aucun plugin installé. Utilise la recherche ci-dessus.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Mods ── -->
    <?php if ($isMod): ?>
    <div id="section-mods" class="main-section">
      <div class="section-card">
        <h2 class="section-title">⚙️ Mods</h2>
        <p class="section-sub">Recherche et ajoute des mods <?= e(ucfirst($serverType)) ?> via Modrinth.</p>

        <div style="display:flex;gap:8px;margin-bottom:12px;">
          <input class="form-input" type="text" id="mod-search" placeholder="Rechercher un mod (ex: JEI, Create, Waystones…)" style="flex:1;" onkeydown="if(event.key==='Enter')searchPackage('mod')">
          <button class="btn btn-primary" onclick="searchPackage('mod')">🔍 Chercher</button>
        </div>
        <div id="mod-results"></div>
      </div>

      <div class="section-card">
        <h2 class="section-title">Mods installés (<?= count($mods) ?>)</h2>
        <div class="package-list" id="mods-list">
          <?php foreach ($mods as $m): ?>
          <div class="package-item" id="pkg-mod-<?= (int)$m['id'] ?>">
            <div class="package-icon">⚙️</div>
            <div class="package-info">
              <div class="package-name"><?= e((string)$m['name']) ?></div>
              <div class="package-version">v<?= e((string)$m['version']) ?> · <?= e(strtoupper((string)$m['source'])) ?></div>
            </div>
            <button class="btn btn-danger btn-sm" onclick="removePackage(<?= (int)$m['id'] ?>, 'mod', this)">✕ Retirer</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($mods)): ?>
          <div style="text-align:center;padding:24px;color:var(--d-text-3);font-size:13px;">
            Aucun mod installé. Utilise la recherche ci-dessus.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Launchers liés ── -->
    <div id="section-launchers" class="main-section">
      <div class="section-card">
        <h2 class="section-title">🚀 Launchers liés</h2>
        <p class="section-sub">Les launchers liés reçoivent automatiquement l'IP et le port de ce serveur.</p>

        <?php if (!empty($availableLaunchers)): ?>
        <div style="display:flex;gap:8px;margin-bottom:20px;">
          <select class="form-input" id="link-launcher-select" style="flex:1;">
            <option value="">-- Choisir un launcher --</option>
            <?php foreach ($availableLaunchers as $l): ?>
            <option value="<?= e((string)$l['uuid']) ?>"><?= e((string)$l['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" onclick="linkLauncher()">🔗 Lier</button>
        </div>
        <?php else: ?>
        <div style="padding:12px;background:var(--d-elevated);border-radius:var(--d-radius);font-size:13px;color:var(--d-text-2);margin-bottom:20px;">
          Tous tes launchers sont déjà liés, ou tu n'en as pas encore créé.
          <a href="<?= e(base_path()) ?>/dashboard.php" style="color:var(--accent);font-weight:600;"> Créer un launcher →</a>
        </div>
        <?php endif; ?>

        <div style="display:flex;flex-direction:column;gap:8px;" id="links-list">
          <?php foreach ($links as $lnk): ?>
          <div class="link-item" id="link-<?= e((string)$lnk['launcher_uuid']) ?>">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:20px;">🚀</span>
              <div>
                <div style="font-size:14px;font-weight:700;color:var(--d-text);"><?= e((string)($lnk['launcher_name'] ?? $lnk['launcher_uuid'])) ?></div>
                <div style="font-size:11px;color:var(--d-text-3);">Lié le <?= e(date('d/m/Y', strtotime((string)$lnk['linked_at']))) ?></div>
              </div>
            </div>
            <button class="btn btn-danger btn-sm" onclick="unlinkLauncher('<?= e((string)$lnk['launcher_uuid']) ?>', this)">✕ Délier</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($links)): ?>
          <div id="links-empty" style="text-align:center;padding:24px;color:var(--d-text-3);font-size:13px;">
            Aucun launcher lié. Utilise le sélecteur ci-dessus.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Whitelist ── -->
    <div id="section-whitelist" class="main-section">
      <div class="section-card">
        <h2 class="section-title">👥 Whitelist des joueurs</h2>
        <p class="section-sub">Joueurs autorisés à rejoindre le serveur. L'UUID Mojang est résolu automatiquement.</p>

        <div style="display:flex;gap:8px;margin-bottom:12px;">
          <input class="form-input" type="text" id="player-username" placeholder="Pseudo Minecraft (ex: Notch)" style="flex:1;" maxlength="16"
            onkeydown="if(event.key==='Enter')addPlayer()">
          <button class="btn btn-primary" onclick="addPlayer()">➕ Ajouter</button>
        </div>

        <div style="display:flex;flex-direction:column;gap:8px;" id="players-list">
          <?php foreach ($players as $pl): ?>
          <div class="player-item" id="player-<?= e((string)$pl['mc_username']) ?>">
            <div class="player-avatar">
              <img src="https://minotar.net/avatar/<?= e((string)$pl['mc_username']) ?>/32"
                   onerror="this.style.display='none'"
                   style="width:32px;height:32px;border-radius:4px;" loading="lazy" />
            </div>
            <div style="flex:1;">
              <div style="font-size:13px;font-weight:700;color:var(--d-text);"><?= e((string)$pl['mc_username']) ?></div>
              <div style="font-size:11px;color:var(--d-text-3);"><?= e((string)($pl['mc_uuid'] ?? 'UUID non résolu')) ?></div>
            </div>
            <span style="font-size:11px;font-weight:600;padding:3px 8px;border-radius:20px;
              background:<?= $pl['whitelisted'] ? 'rgba(0,214,143,.12)' : 'rgba(255,77,106,.12)' ?>;
              color:<?= $pl['whitelisted'] ? '#00d68f' : '#ff4d6a' ?>;">
              <?= $pl['whitelisted'] ? 'Autorisé' : 'Banni' ?>
            </span>
            <button class="btn btn-sm" onclick="togglePlayer('<?= e((string)$pl['mc_username']) ?>', this)" title="Activer/Désactiver">
              <?= $pl['whitelisted'] ? '🔇 Bannir' : '✅ Autoriser' ?>
            </button>
            <button class="btn btn-danger btn-sm" onclick="removePlayer('<?= e((string)$pl['mc_username']) ?>', this)">✕</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($players)): ?>
          <div id="players-empty" style="text-align:center;padding:24px;color:var(--d-text-3);font-size:13px;">
            Aucun joueur dans la whitelist.
          </div>
          <?php endif; ?>
        </div>

        <div style="margin-top:16px;">
          <a href="<?= e(server_cms_base()) ?>/api/generate_config.php?uuid=<?= e($uuid) ?>&file=whitelist.json" class="btn">
            ⬇️ Télécharger whitelist.json
          </a>
        </div>
      </div>
    </div>

    <!-- ── Configuration ── -->
    <div id="section-config" class="main-section">
      <div class="section-card">
        <h2 class="section-title">⚙️ Configuration du serveur</h2>
        <p class="section-sub">Modifie les paramètres de ton serveur. Les fichiers générés seront mis à jour.</p>

        <form onsubmit="saveConfig(event)">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">IP du serveur</label>
              <input class="form-input" type="text" id="cfg-ip" value="<?= e((string)($server['server_ip'] ?? '')) ?>" placeholder="play.monserveur.fr">
            </div>
            <div class="form-group">
              <label class="form-label">Port</label>
              <input class="form-input" type="number" id="cfg-port" value="<?= e((string)$server['server_port']) ?>" min="1" max="65535">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">RAM (Mo)</label>
              <input class="form-input" type="number" id="cfg-ram" value="<?= e((string)$server['ram_mb']) ?>" min="512" max="16384" step="512">
            </div>
            <div class="form-group">
              <label class="form-label">Joueurs max</label>
              <input class="form-input" type="number" id="cfg-maxplayers" value="<?= (int)($config['max-players'] ?? 20) ?>" min="1" max="1000">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">MOTD (message d'accueil)</label>
              <input class="form-input" type="text" id="cfg-motd" value="<?= e((string)($config['motd'] ?? $server['name'])) ?>" maxlength="59">
            </div>
            <div class="form-group">
              <label class="form-label">Difficulté</label>
              <select class="form-input" id="cfg-difficulty">
                <?php foreach (['peaceful'=>'Paisible','easy'=>'Facile','normal'=>'Normal','hard'=>'Difficile'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= ($config['difficulty'] ?? 'normal') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group" style="text-align:right;margin-top:8px;">
            <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Fichiers ── -->
    <div id="section-files" class="main-section">
      <div class="section-card">
        <h2 class="section-title">⬇️ Télécharger les fichiers de configuration</h2>
        <p class="section-sub">Tous les fichiers nécessaires pour démarrer ton serveur.</p>

        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php
          $files = [
            ['server.properties', 'Configuration principale du serveur', '⚙️'],
            ['eula.txt',          'Accord de licence Mojang (EULA)',       '📜'],
            ['start.sh',          'Script de démarrage Linux / macOS',      '🐧'],
            ['start.bat',         'Script de démarrage Windows',            '🪟'],
            ['whitelist.json',    'Liste blanche des joueurs autorisés',     '👥'],
          ];
          ?>
          <?php foreach ($files as [$fname, $desc, $ico]): ?>
          <div style="display:flex;align-items:center;gap:14px;padding:14px;background:var(--d-elevated);border:1px solid var(--d-border);border-radius:var(--d-radius);">
            <span style="font-size:24px;"><?= $ico ?></span>
            <div style="flex:1;">
              <div style="font-size:14px;font-weight:700;color:var(--d-text);font-family:monospace;"><?= e($fname) ?></div>
              <div style="font-size:12px;color:var(--d-text-2);"><?= e($desc) ?></div>
            </div>
            <a href="<?= e(server_cms_base()) ?>/api/generate_config.php?uuid=<?= e($uuid) ?>&file=<?= e($fname) ?>" class="btn btn-primary btn-sm">
              ⬇️ Télécharger
            </a>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:20px;padding:14px;background:rgba(124,92,255,.08);border:1px solid rgba(124,92,255,.2);border-radius:var(--d-radius);font-size:13px;color:var(--d-text-2);">
          <strong style="color:var(--d-text);">💡 Instructions :</strong> Place tous ces fichiers dans un même dossier,
          puis lance <code style="background:var(--d-elevated);padding:1px 5px;border-radius:4px;">start.sh</code> (Linux/Mac)
          ou <code style="background:var(--d-elevated);padding:1px 5px;border-radius:4px;">start.bat</code> (Windows).
          Java 17+ est requis pour MC 1.17 et plus.
        </div>
      </div>
    </div>

  </main>
</div>

<!-- Toast -->
<div id="toast" class="toast" style="display:none;"></div>

<script>
const CSRF       = <?= json_encode(csrf_token()) ?>;
const SERVER_UUID = <?= json_encode($uuid) ?>;
const MC_VERSION  = <?= json_encode($server['mc_version']) ?>;
const SERVER_TYPE = <?= json_encode($serverType) ?>;
const API_BASE    = '<?= e(server_cms_base()) ?>/api';
const API_KEY     = <?= json_encode((string)($server['api_key'] ?? '')) ?>;

let apiKeyVisible = false;

// ── Navigation ─────────────────────────────────────────────────────────────
function showSection(id) {
  document.querySelectorAll('.main-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.sidebar-nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById(`section-${id}`).classList.add('active');
  event.currentTarget.classList.add('active');
}

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast ${type}`;
  t.style.display = 'block';
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => { t.style.display = 'none'; t.style.opacity = '1'; }, 300); }, 3000);
}

// ── API Key ────────────────────────────────────────────────────────────────
function toggleApiKey() {
  const el = document.getElementById('api-key-display');
  apiKeyVisible = !apiKeyVisible;
  if (apiKeyVisible) {
    el.textContent = API_KEY;
    navigator.clipboard?.writeText(API_KEY);
    showToast('API Key copiée !');
  } else {
    el.textContent = '•'.repeat(20);
  }
}

// ── Search packages ────────────────────────────────────────────────────────
async function searchPackage(type) {
  const inputId   = type === 'plugin' ? 'plugin-search' : 'mod-search';
  const resultsId = type === 'plugin' ? 'plugin-results' : 'mod-results';
  const q = document.getElementById(inputId).value.trim();
  if (!q) return;

  const el = document.getElementById(resultsId);
  el.innerHTML = '<div style="padding:12px;color:var(--d-text-2);font-size:13px;"><span class="spinner"></span> Recherche en cours…</div>';

  const loaderMap = { paper:'paper', spigot:'spigot', forge:'forge', fabric:'fabric', vanilla:'vanilla' };
  const loader = loaderMap[SERVER_TYPE] || '';

  const res = await fetch(`${API_BASE}/search_modrinth.php?q=${encodeURIComponent(q)}&type=${type}&mc_version=${MC_VERSION}&loader=${loader}&limit=10`);
  const data = await res.json();

  if (!data.ok || !data.hits?.length) {
    el.innerHTML = '<div style="padding:12px;color:var(--d-text-3);font-size:13px;">Aucun résultat.</div>';
    return;
  }

  el.innerHTML = '<div class="search-results">' + data.hits.map(h => `
    <div class="search-item">
      ${h.icon_url
        ? `<img class="search-icon" src="${h.icon_url}" alt="" loading="lazy" />`
        : `<div class="search-icon">${type === 'plugin' ? '🔌' : '⚙️'}</div>`}
      <div class="search-info">
        <div class="search-name">${esc(h.name)}</div>
        <div class="search-desc">${esc(h.description)}</div>
        <div class="search-downloads">⬇️ ${h.downloads.toLocaleString('fr')} téléchargements</div>
      </div>
      <button class="btn btn-primary btn-add" onclick="addPackage(${JSON.stringify(h).replace(/"/g,'&quot;')}, '${type}', this)">
        + Ajouter
      </button>
    </div>
  `).join('') + '</div>';
}

async function addPackage(hit, type, btn) {
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>';

  // Chercher la dernière version compatible
  let fileInfo = { url:'', name:'', size:0, hash:'' };
  let version = 'latest';

  try {
    const loaderMap = { paper:'paper', spigot:'spigot', forge:'forge', fabric:'fabric', vanilla:'vanilla' };
    const loader = loaderMap[SERVER_TYPE] || '';
    const vRes = await fetch(`${API_BASE}/modrinth_versions.php?project_id=${hit.id}&mc_version=${MC_VERSION}&loader=${loader}`);
    const vData = await vRes.json();
    if (vData.ok && vData.versions?.length) {
      const v = vData.versions[0];
      version = v.version_number;
      if (v.file) {
        fileInfo = { url: v.file.url, name: v.file.filename, size: v.file.size, hash: v.file.hash };
      }
    }
  } catch(e) {}

  const payload = {
    _csrf: CSRF,
    server_uuid: SERVER_UUID,
    package_type: type,
    source: 'modrinth',
    external_id: hit.id,
    slug: hit.slug,
    name: hit.name,
    version: version,
    file_url: fileInfo.url,
    file_name: fileInfo.name,
    file_size: fileInfo.size,
    file_hash: fileInfo.hash,
  };

  const res = await fetch(`${API_BASE}/server_packages.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) {
    showToast(`✅ ${hit.name} ajouté !`);
    btn.textContent = '✅ Ajouté';
    const listId = type === 'plugin' ? 'plugins-list' : 'mods-list';
    const list = document.getElementById(listId);
    const emptyEl = document.getElementById(type === 'plugin' ? '' : 'players-empty');
    const item = document.createElement('div');
    item.className = 'package-item';
    item.id = `pkg-${type}-${data.package.id}`;
    item.innerHTML = `
      <div class="package-icon">${type==='plugin'?'🔌':'⚙️'}</div>
      <div class="package-info">
        <div class="package-name">${esc(hit.name)}</div>
        <div class="package-version">v${esc(version)} · MODRINTH</div>
      </div>
      <button class="btn btn-danger btn-sm" onclick="removePackage(${data.package.id}, '${type}', this)">✕ Retirer</button>
    `;
    list.appendChild(item);
  } else {
    showToast('❌ ' + (data.error || 'Erreur'), 'error');
    btn.disabled = false;
    btn.textContent = '+ Ajouter';
  }
}

async function removePackage(id, type, btn) {
  if (!confirm('Retirer ce package ?')) return;
  btn.disabled = true;

  const payload = { _csrf: CSRF, server_uuid: SERVER_UUID, package_type: type, package_id: id };
  const res = await fetch(`${API_BASE}/server_packages.php`, {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) {
    document.getElementById(`pkg-${type}-${id}`)?.remove();
    showToast('Package retiré.');
  } else {
    showToast('❌ ' + (data.error || 'Erreur'), 'error');
    btn.disabled = false;
  }
}

// ── Launcher linking ───────────────────────────────────────────────────────
async function linkLauncher() {
  const sel = document.getElementById('link-launcher-select');
  const launcherUuid = sel.value;
  if (!launcherUuid) { alert('Sélectionne un launcher.'); return; }

  const payload = { _csrf: CSRF, server_uuid: SERVER_UUID, launcher_uuid: launcherUuid, action: 'link' };
  const res = await fetch(`${API_BASE}/launcher_link.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) {
    showToast(`✅ Launcher lié ! IP/Port envoyés.`);
    const list = document.getElementById('links-list');
    document.getElementById('links-empty')?.remove();
    const item = document.createElement('div');
    item.className = 'link-item';
    item.id = `link-${launcherUuid}`;
    item.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">🚀</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--d-text);">${esc(data.launcher_name || launcherUuid)}</div>
          <div style="font-size:11px;color:var(--d-text-3);">Lié maintenant</div>
        </div>
      </div>
      <button class="btn btn-danger btn-sm" onclick="unlinkLauncher('${launcherUuid}', this)">✕ Délier</button>
    `;
    list.appendChild(item);
    sel.querySelector(`[value="${launcherUuid}"]`)?.remove();
  } else {
    showToast('❌ ' + (data.error || 'Erreur'), 'error');
  }
}

async function unlinkLauncher(launcherUuid, btn) {
  if (!confirm('Délier ce launcher ?')) return;
  btn.disabled = true;

  const payload = { _csrf: CSRF, server_uuid: SERVER_UUID, launcher_uuid: launcherUuid, action: 'unlink' };
  const res = await fetch(`${API_BASE}/launcher_link.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) {
    document.getElementById(`link-${launcherUuid}`)?.remove();
    showToast('Launcher délié.');
  } else {
    showToast('❌ ' + (data.error || 'Erreur'), 'error');
    btn.disabled = false;
  }
}

// ── Whitelist ──────────────────────────────────────────────────────────────
async function addPlayer() {
  const input = document.getElementById('player-username');
  const username = input.value.trim();
  if (!username) return;

  if (!/^[A-Za-z0-9_]{2,16}$/.test(username)) {
    showToast('❌ Pseudo MC invalide (2-16 caractères)', 'error');
    return;
  }

  const payload = { _csrf: CSRF, server_uuid: SERVER_UUID, mc_username: username, action: 'add' };
  const res = await fetch(`${API_BASE}/auth_bridge.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) {
    input.value = '';
    document.getElementById('players-empty')?.remove();
    const list = document.getElementById('players-list');
    const item = document.createElement('div');
    item.className = 'player-item';
    item.id = `player-${username}`;
    item.innerHTML = `
      <div class="player-avatar">
        <img src="https://minotar.net/avatar/${username}/32" onerror="this.style.display='none'"
             style="width:32px;height:32px;border-radius:4px;" loading="lazy" />
      </div>
      <div style="flex:1;">
        <div style="font-size:13px;font-weight:700;color:var(--d-text);">${esc(username)}</div>
        <div style="font-size:11px;color:var(--d-text-3);">${esc(data.mc_uuid || 'UUID en cours de résolution…')}</div>
      </div>
      <span style="font-size:11px;font-weight:600;padding:3px 8px;border-radius:20px;background:rgba(0,214,143,.12);color:#00d68f;">Autorisé</span>
      <button class="btn btn-sm" onclick="togglePlayer('${username}', this)">🔇 Bannir</button>
      <button class="btn btn-danger btn-sm" onclick="removePlayer('${username}', this)">✕</button>
    `;
    list.appendChild(item);
    showToast(`✅ ${username} ajouté à la whitelist.`);
  } else {
    showToast('❌ ' + (data.error || 'Erreur'), 'error');
  }
}

async function removePlayer(username, btn) {
  if (!confirm(`Retirer ${username} de la whitelist ?`)) return;
  btn.disabled = true;

  const payload = { _csrf: CSRF, server_uuid: SERVER_UUID, mc_username: username, action: 'remove' };
  const res = await fetch(`${API_BASE}/auth_bridge.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) {
    document.getElementById(`player-${username}`)?.remove();
    showToast(`${username} retiré.`);
  } else {
    showToast('❌ ' + data.error, 'error');
    btn.disabled = false;
  }
}

async function togglePlayer(username, btn) {
  const payload = { _csrf: CSRF, server_uuid: SERVER_UUID, mc_username: username, action: 'toggle' };
  const res = await fetch(`${API_BASE}/auth_bridge.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (data.ok) { location.reload(); }
  else showToast('❌ ' + data.error, 'error');
}

// ── Config save ────────────────────────────────────────────────────────────
async function saveConfig(e) {
  e.preventDefault();
  const payload = {
    _csrf: CSRF,
    server_ip: document.getElementById('cfg-ip').value.trim(),
    server_port: parseInt(document.getElementById('cfg-port').value) || 25565,
    ram_mb: parseInt(document.getElementById('cfg-ram').value) || 2048,
    server_config: {
      'max-players': parseInt(document.getElementById('cfg-maxplayers').value) || 20,
      'motd': document.getElementById('cfg-motd').value.trim(),
      'difficulty': document.getElementById('cfg-difficulty').value,
    },
  };

  const res = await fetch(`${API_BASE}/server.php?uuid=${SERVER_UUID}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();

  if (data.ok) showToast('✅ Configuration sauvegardée !');
  else showToast('❌ ' + (data.error || 'Erreur'), 'error');
}

// ── Helpers ────────────────────────────────────────────────────────────────
function esc(str) {
  const d = document.createElement('div');
  d.textContent = String(str ?? '');
  return d.innerHTML;
}
</script>
</body>
</html>

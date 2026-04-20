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

// ---- Abonnement : prochain versement, statut ----
$subscription = null;
try {
  $s = $pdo->prepare(
    "SELECT s.id, s.status, s.expires_at, s.created_at, s.launcher_id, l.name AS launcher_name, l.uuid AS launcher_uuid "
    . "FROM subscriptions s "
    . "LEFT JOIN launchers l ON l.id = s.launcher_id "
    . "WHERE s.user_id = ? "
    . "ORDER BY (s.status = 'active') DESC, s.created_at DESC "
    . "LIMIT 1"
  );
  $s->execute([$user['id']]);
  $subscription = $s->fetch() ?: null;
} catch (Throwable $e) {
  $subscription = null;
}

// ---- Logs du launcher sélectionné (graceful fallback si table absente) ----
$launcherLogs = [];
$launcherLogsAvailable = true;
if ($selectedId && $selectedId > 0) {
  try {
    $lg = $pdo->prepare(
      'SELECT created_at, level, source, message FROM launcher_logs '
      . 'WHERE launcher_id = ? ORDER BY created_at DESC, id DESC LIMIT 50'
    );
    $lg->execute([$selectedId]);
    $launcherLogs = $lg->fetchAll();
  } catch (Throwable $e) {
    $launcherLogsAvailable = false;
    $launcherLogs = [];
  }
}

// ---- Compteurs anti-abus (downloads + builds 24h) ----
$abuse = [
  'available'  => true,
  'dl_hour'    => 0,
  'dl_day'     => 0,
  'build_day'  => 0,
  'limit_dl_hour'   => 120,
  'limit_dl_day'    => 1500,
  'limit_build_day' => 20,
];
if ($selectedId && $selectedId > 0) {
  try {
    $aq = $pdo->prepare("SELECT COUNT(*) FROM launcher_downloads_log WHERE launcher_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)");
    $aq->execute([$selectedId]);
    $abuse['dl_hour'] = (int)$aq->fetchColumn();

    $aq = $pdo->prepare("SELECT COUNT(*) FROM launcher_downloads_log WHERE launcher_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)");
    $aq->execute([$selectedId]);
    $abuse['dl_day'] = (int)$aq->fetchColumn();

    $aq = $pdo->prepare("SELECT COUNT(*) FROM launcher_builds_log WHERE launcher_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)");
    $aq->execute([$selectedId]);
    $abuse['build_day'] = (int)$aq->fetchColumn();
  } catch (Throwable $e) {
    $abuse['available'] = false;
  }
}

// ---- Liste des versions Minecraft supportées (1.7 → dernière) ----
$mcVersions = [
  '1.21.4','1.21.3','1.21.1','1.21',
  '1.20.6','1.20.4','1.20.2','1.20.1',
  '1.19.4','1.19.2',
  '1.18.2',
  '1.17.1',
  '1.16.5',
  '1.15.2',
  '1.14.4',
  '1.13.2',
  '1.12.2',
  '1.11.2',
  '1.10.2',
  '1.9.4',
  '1.8.9','1.8.8',
  '1.7.10',
];

// ---- Catalogue d'extensions disponibles pour un launcher Minecraft ----
// `needs_api` = true  → le client fournit URL + API key de son service
// `needs_api` = false → on n'a besoin de rien côté client, c'est géré par Xyno
$availableExtensions = [
  ['key' => 'news',           'name' => 'News & actualités',      'desc' => "Feed d'actu affiché sur la page Play du launcher.",                'needs_api' => true,  'category' => 'contenu'],
  ['key' => 'player_count',   'name' => 'Compteur de joueurs',    'desc' => 'Nombre de joueurs en ligne en temps réel.',                         'needs_api' => true,  'category' => 'serveur'],
  ['key' => 'server_status',  'name' => 'Statut serveur (ping)',  'desc' => "État (online/offline), ping et version côté launcher.",            'needs_api' => true,  'category' => 'serveur'],
  ['key' => 'discord',        'name' => 'Discord widget',         'desc' => 'Widget live + lien d\'invitation depuis le launcher.',              'needs_api' => true,  'category' => 'social'],
  ['key' => 'leaderboard',    'name' => 'Classement / Top joueurs','desc' => 'Top kills, tempts, votes — affichage direct dans l\'app.',         'needs_api' => true,  'category' => 'social'],
  ['key' => 'shop',           'name' => 'Boutique / Shop',        'desc' => "Liens produit + ventes flash + promos visibles à l'ouverture.",   'needs_api' => true,  'category' => 'monétisation'],
  ['key' => 'voting',         'name' => 'Votes sites serveur',    'desc' => 'Vote-for-rewards : le joueur vote, reçoit ses récompenses.',        'needs_api' => true,  'category' => 'monétisation'],
  ['key' => 'quests',         'name' => 'Quêtes / missions',      'desc' => 'Objectifs actifs et récompenses, synchronisés avec ton back-end.',   'needs_api' => true,  'category' => 'contenu'],
  ['key' => 'events',         'name' => 'Events à venir',         'desc' => 'Agenda des prochains events in-game affiché à l\'ouverture.',       'needs_api' => true,  'category' => 'contenu'],
  ['key' => 'skin_api',       'name' => 'API Skins custom',       'desc' => 'Charge les skins depuis ton propre serveur (endpoint skin).',       'needs_api' => true,  'category' => 'gameplay'],
  ['key' => 'capes',          'name' => 'Capes & accessoires',    'desc' => 'Système de capes custom par UUID (API renvoie les accessoires).',    'needs_api' => true,  'category' => 'gameplay'],
  ['key' => 'social_feed',    'name' => 'Feed YouTube / Twitch',  'desc' => 'Dernières vidéos ou lives de tes créateurs affichés en slider.',    'needs_api' => true,  'category' => 'social'],
  ['key' => 'crash_reporter', 'name' => 'Rapport de crashs',      'desc' => 'Remonte automatiquement les crashs dans le dashboard.',             'needs_api' => false, 'category' => 'système'],
  ['key' => 'analytics',      'name' => 'Analytics de lancement', 'desc' => 'Stats anonymisées (versions, OS, temps de chargement).',            'needs_api' => false, 'category' => 'système'],
  ['key' => 'modpack',        'name' => 'Gestion modpacks',       'desc' => 'Sélecteur de modpacks (plusieurs profils de mods par version).',    'needs_api' => false, 'category' => 'gameplay'],
  ['key' => 'changelog',      'name' => 'Changelog auto',         'desc' => 'Affiche le dernier changelog à la première ouverture après update.', 'needs_api' => false, 'category' => 'contenu'],
  ['key' => 'ram_slider',     'name' => 'Slider RAM avancé',      'desc' => 'Permet au joueur de choisir la RAM allouée (min/max) au lancement.','needs_api' => false, 'category' => 'gameplay'],
  ['key' => 'java_manager',   'name' => 'Manager Java',           'desc' => 'Télécharge et sélectionne la bonne version de Java automatiquement.','needs_api' => false, 'category' => 'système'],
];

// ---- Extensions activées pour le launcher sélectionné ----
$launcherExtensions = [];
$extensionsAvailable = true;
if ($selectedId && $selectedId > 0) {
  try {
    $eq = $pdo->prepare('SELECT ext_key, enabled, api_url, api_key FROM launcher_extensions WHERE launcher_id = ?');
    $eq->execute([$selectedId]);
    foreach ($eq->fetchAll() as $row) {
      $launcherExtensions[(string)$row['ext_key']] = [
        'enabled' => (int)($row['enabled'] ?? 0) === 1,
        'api_url' => (string)($row['api_url'] ?? ''),
        'api_key' => (string)($row['api_key'] ?? ''),
      ];
    }
  } catch (Throwable $e) {
    $extensionsAvailable = false;
  }
}

// ---- Auth personnalisée du launcher (mode + URLs Bearer) ----
$launcherAuth = [
  'mode'        => 'microsoft',
  'login_url'   => '',
  'verify_url'  => '',
  'refresh_url' => '',
  'api_key'     => '',
];
$authAvailable = true;
if ($selectedId && $selectedId > 0) {
  try {
    $aq = $pdo->prepare('SELECT mode, login_url, verify_url, refresh_url, api_key FROM launcher_auth WHERE launcher_id = ? LIMIT 1');
    $aq->execute([$selectedId]);
    $row = $aq->fetch();
    if ($row) {
      $launcherAuth = [
        'mode'        => (string)($row['mode'] ?? 'microsoft'),
        'login_url'   => (string)($row['login_url'] ?? ''),
        'verify_url'  => (string)($row['verify_url'] ?? ''),
        'refresh_url' => (string)($row['refresh_url'] ?? ''),
        'api_key'     => (string)($row['api_key'] ?? ''),
      ];
    }
  } catch (Throwable $e) {
    $authAvailable = false;
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
          <a href="#facturation">Facturation</a>
          <a href="#launchers">Launchers</a>
          <a href="#parametres">Paramètres</a>
          <a href="#extensions">Extensions</a>
          <a href="#auth">Authentification</a>
          <a href="#versions">Versions</a>
          <a href="#logs">Logs</a>
          <a href="#securite">Anti-abus</a>
          <a href="#sql">SQL à exécuter</a>
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

        <section id="facturation" class="section-sm">
          <h2 class="section-title">Facturation</h2>
          <p class="section-desc">Ton abonnement et ton prochain versement.</p>

          <div class="card">
            <?php if ($subscription === null): ?>
              <div class="nav-row" style="align-items:center">
                <div>
                  <span class="badge">Aucun abonnement actif</span>
                  <p class="section-desc" style="margin-top:8px">Tu n’as pas encore souscrit à une offre. Les launchers restent accessibles en auto-hébergement jusqu’à souscription.</p>
                </div>
                <div class="cta-row" style="margin:0">
                  <a class="btn btn-primary" href="pricing.php">Choisir une offre</a>
                </div>
              </div>
            <?php else:
              $subStatus = strtolower((string)($subscription['status'] ?? ''));
              $subExpires = (string)($subscription['expires_at'] ?? '');
              $subExpiresTs = $subExpires ? strtotime($subExpires) : null;
              $nextPaymentFr = '';
              $daysLeft = null;
              if ($subExpiresTs) {
                $nextPaymentFr = date('d/m/Y', $subExpiresTs);
                $daysLeft = max(0, (int)floor(($subExpiresTs - time()) / 86400));
              }
              $statusLabel = $subStatus === 'active' ? 'Actif'
                           : ($subStatus === 'cancelled' ? 'Résilié — fin à la date ci-dessous'
                           : ($subStatus === 'past_due' ? 'Paiement en retard' : ucfirst($subStatus ?: 'inactif')));
            ?>
              <div class="two-col" style="gap:18px">
                <div>
                  <span class="badge badge-accent"><?php echo e($statusLabel); ?></span>
                  <p class="section-desc" style="margin-top:8px">
                    <?php if ($subscription['launcher_name']): ?>
                      Abonnement pour <strong style="color:#fff"><?php echo e((string)$subscription['launcher_name']); ?></strong>.
                    <?php else: ?>
                      Abonnement actif.
                    <?php endif; ?>
                  </p>
                  <?php if ($nextPaymentFr): ?>
                    <p class="section-desc" style="margin-top:6px">
                      <?php if ($subStatus === 'cancelled'): ?>
                        Ton accès reste actif jusqu’au <strong style="color:#fff"><?php echo e($nextPaymentFr); ?></strong>
                        <?php if ($daysLeft !== null): ?>(<?= (int)$daysLeft ?> jour<?= $daysLeft > 1 ? 's' : '' ?> restant<?= $daysLeft > 1 ? 's' : '' ?>)<?php endif; ?>.
                      <?php else: ?>
                        Prochain versement le <strong style="color:#fff"><?php echo e($nextPaymentFr); ?></strong>
                        <?php if ($daysLeft !== null): ?>(dans <?= (int)$daysLeft ?> jour<?= $daysLeft > 1 ? 's' : '' ?>)<?php endif; ?>.
                      <?php endif; ?>
                    </p>
                  <?php endif; ?>
                </div>

                <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end;justify-content:center">
                  <a class="btn" href="pricing.php">Changer de formule</a>
                  <?php if ($subStatus === 'active'): ?>
                    <form action="cancel_subscription.php" method="post" style="margin:0" onsubmit="return confirm('Résilier ton abonnement ? Tu garderas l’accès jusqu’à la fin de la période en cours.');">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="subscription_id" value="<?php echo e((string)($subscription['id'] ?? '')); ?>" />
                      <button class="btn btn-ghost" type="submit">Résilier l’abonnement</button>
                    </form>
                  <?php elseif ($subStatus === 'cancelled'): ?>
                    <form action="reactivate_subscription.php" method="post" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="subscription_id" value="<?php echo e((string)($subscription['id'] ?? '')); ?>" />
                      <button class="btn btn-primary" type="submit">Réactiver</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </section>

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
              <form class="form" aria-label="Configuration launcher" action="update_launcher.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />

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
                      <?php
                        // Si la version actuelle du launcher n'est pas dans la liste,
                        // on l'ajoute en tête pour ne pas la perdre.
                        $currentVer = (string)$selected['version'];
                        $allVersions = $mcVersions;
                        if ($currentVer !== '' && !in_array($currentVer, $allVersions, true)) {
                          array_unshift($allVersions, $currentVer);
                        }
                      ?>
                      <?php foreach ($allVersions as $ver): ?>
                        <option value="<?php echo e($ver); ?>" <?php echo ($currentVer === $ver) ? 'selected' : ''; ?>><?php echo e($ver); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <span class="help">Toutes les versions supportées de 1.7.10 à 1.21.4.</span>
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

                <div class="card" style="margin-top:6px;padding:14px;background:var(--surface-2)">
                  <span class="badge">Logo de l’app Electron</span>
                  <p class="section-desc" style="margin-top:8px">Upload ton logo (PNG 512×512 recommandé). Il sera utilisé comme icône de l’exécutable Windows / macOS / Linux et dans la fenêtre du launcher.</p>

                  <div class="two-col" style="align-items:end;margin-top:10px">
                    <label class="label">
                      <span>Fichier logo (PNG / ICO)</span>
                      <input class="input" type="file" name="logo" accept="image/png,image/x-icon,image/jpeg,image/webp" />
                      <span class="help">Max 2 Mo · carré recommandé · fond transparent accepté.</span>
                    </label>

                    <div class="card" style="padding:10px;background:rgba(0,0,0,.25);display:flex;align-items:center;gap:12px">
                      <div style="width:64px;height:64px;border-radius:14px;border:1px solid var(--border-2);background:
                        <?php $logoUrl = 'uploads/launchers/' . (int)$selectedId . '/logo.png'; $hasLogo = is_file(__DIR__ . '/../' . $logoUrl); ?>
                        <?php if ($hasLogo): ?>
                          url('<?php echo e('/' . $logoUrl . '?v=' . filemtime(__DIR__ . '/../' . $logoUrl)); ?>') center/cover no-repeat, var(--grad-soft);
                        <?php else: ?>
                          var(--grad-soft);
                        <?php endif; ?>
                      "></div>
                      <div>
                        <strong style="color:#fff;font-size:14px">Logo actuel</strong><br>
                        <span class="small"><?php echo $hasLogo ? 'Personnalisé' : 'Aucun — logo Xyno par défaut'; ?></span>
                      </div>
                    </div>
                  </div>
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

        <section id="logs" class="section-sm">
          <h2 class="section-title">Logs du launcher</h2>
          <p class="section-desc">Les 50 derniers événements remontés par le launcher installé chez tes joueurs (crashs, erreurs, infos).</p>

          <div class="card">
            <?php if ($selected === null): ?>
              <p class="small" style="margin:0">Sélectionne un launcher pour voir ses logs.</p>
            <?php elseif (!$launcherLogsAvailable): ?>
              <p class="small" style="margin:0">Les logs ne sont pas encore activés (table <code>launcher_logs</code> absente). Importe la migration associée pour activer la remontée.</p>
            <?php elseif (!count($launcherLogs)): ?>
              <p class="small" style="margin:0">Aucun événement pour l’instant. Les logs apparaissent ici dès qu’un joueur lance le launcher.</p>
            <?php else: ?>
              <div style="display:grid;gap:6px;max-height:420px;overflow:auto">
                <?php foreach ($launcherLogs as $row):
                  $lvl = strtolower((string)($row['level'] ?? 'info'));
                  $color = $lvl === 'error' ? '#f87171'
                        : ($lvl === 'warn' || $lvl === 'warning' ? '#fbbf24'
                        : ($lvl === 'debug' ? 'rgba(255,255,255,.5)'
                        : '#60a5fa'));
                ?>
                  <div style="display:grid;grid-template-columns:160px 70px 120px 1fr;gap:10px;padding:8px 10px;background:rgba(255,255,255,.03);border:1px solid var(--border-1);border-radius:10px;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12px;line-height:1.5">
                    <span style="color:var(--muted-2)"><?php echo e((string)($row['created_at'] ?? '')); ?></span>
                    <span style="color:<?php echo $color; ?>;font-weight:700;text-transform:uppercase"><?php echo e($lvl); ?></span>
                    <span style="color:var(--muted)"><?php echo e((string)($row['source'] ?? '—')); ?></span>
                    <span style="color:var(--text);word-break:break-word"><?php echo e((string)($row['message'] ?? '')); ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="small" style="margin:12px 0 0">Les logs sont rotatés automatiquement tous les 30 jours.</p>
            <?php endif; ?>
          </div>
        </section>

        <section id="securite" class="section-sm">
          <h2 class="section-title">Anti-abus</h2>
          <p class="section-desc">Limites automatiques sur les downloads et les builds pour éviter l’abus d’API et protéger ta facturation.</p>

          <div class="card">
            <?php if ($selected === null): ?>
              <p class="small" style="margin:0">Sélectionne un launcher pour voir ses compteurs anti-abus.</p>
            <?php elseif (!$abuse['available']): ?>
              <div class="nav-row" style="align-items:center;margin:0;padding:0;border:0">
                <div>
                  <span class="badge">Protection active</span>
                  <p class="section-desc" style="margin-top:8px">L’API applique déjà des limites globales (IP + clé API) côté passerelle. Les compteurs détaillés s’activent dès que les tables <code>launcher_downloads_log</code> et <code>launcher_builds_log</code> sont importées.</p>
                </div>
              </div>
            <?php else:
              $pctDlHour   = $abuse['limit_dl_hour']   > 0 ? min(100, ($abuse['dl_hour']   / $abuse['limit_dl_hour'])   * 100) : 0;
              $pctDlDay    = $abuse['limit_dl_day']    > 0 ? min(100, ($abuse['dl_day']    / $abuse['limit_dl_day'])    * 100) : 0;
              $pctBuildDay = $abuse['limit_build_day'] > 0 ? min(100, ($abuse['build_day'] / $abuse['limit_build_day']) * 100) : 0;

              $barFn = function(float $pct): string {
                $color = $pct >= 90 ? 'linear-gradient(90deg,#f87171,#ef4444)'
                      : ($pct >= 70 ? 'linear-gradient(90deg,#fbbf24,#f59e0b)'
                      : 'linear-gradient(90deg,#8b5cf6,#22d3ee)');
                return '<div style="height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden"><div style="width:' . number_format($pct, 1) . '%;height:100%;background:' . $color . ';border-radius:999px"></div></div>';
              };
            ?>
              <div style="display:grid;gap:14px">
                <div>
                  <div class="nav-row" style="margin:0;padding:0;border:0;gap:10px">
                    <strong style="color:#fff">Téléchargements · dernière heure</strong>
                    <span class="small" style="color:var(--muted)"><?php echo (int)$abuse['dl_hour'] . ' / ' . (int)$abuse['limit_dl_hour']; ?></span>
                  </div>
                  <div style="margin-top:6px"><?php echo $barFn($pctDlHour); ?></div>
                </div>

                <div>
                  <div class="nav-row" style="margin:0;padding:0;border:0;gap:10px">
                    <strong style="color:#fff">Téléchargements · dernières 24 h</strong>
                    <span class="small" style="color:var(--muted)"><?php echo (int)$abuse['dl_day'] . ' / ' . (int)$abuse['limit_dl_day']; ?></span>
                  </div>
                  <div style="margin-top:6px"><?php echo $barFn($pctDlDay); ?></div>
                </div>

                <div>
                  <div class="nav-row" style="margin:0;padding:0;border:0;gap:10px">
                    <strong style="color:#fff">Builds · dernières 24 h</strong>
                    <span class="small" style="color:var(--muted)"><?php echo (int)$abuse['build_day'] . ' / ' . (int)$abuse['limit_build_day']; ?></span>
                  </div>
                  <div style="margin-top:6px"><?php echo $barFn($pctBuildDay); ?></div>
                </div>
              </div>

              <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-1);display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                <div>
                  <span class="badge">Rate-limit par IP</span>
                  <p class="small" style="margin-top:6px">Max 60 req/min, puis 429.</p>
                </div>
                <div>
                  <span class="badge">HMAC signé</span>
                  <p class="small" style="margin-top:6px">Chaque requête launcher est signée — anti-replay 5 min.</p>
                </div>
                <div>
                  <span class="badge">Builds bornés</span>
                  <p class="small" style="margin-top:6px">20 builds / 24 h / launcher par défaut (ajustable).</p>
                </div>
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

        <section id="extensions" class="section-sm">
          <h2 class="section-title">Extensions</h2>
          <p class="section-desc">Active les modules visibles dans ton launcher. Pour les extensions qui se branchent à ton back-end (news, joueurs en ligne, boutique…), renseigne l’URL de ton API et — si besoin — une clé d’accès.</p>

          <div class="card">
            <?php if ($selected === null): ?>
              <p class="small" style="margin:0">Sélectionne un launcher pour configurer ses extensions.</p>
            <?php elseif (!$extensionsAvailable): ?>
              <p class="small" style="margin:0">La table <code>launcher_extensions</code> n’existe pas encore. Importe <a href="#sql">migrations_v3.sql</a> pour activer cette section.</p>
            <?php else: ?>
              <form class="form" action="launcher/update_extensions.php" method="post" aria-label="Configuration extensions">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />

                <?php
                  // Regroupe par catégorie pour un affichage plus lisible.
                  $catOrder = ['contenu','serveur','social','monétisation','gameplay','système'];
                  $byCat = [];
                  foreach ($availableExtensions as $ext) {
                    $byCat[$ext['category']][] = $ext;
                  }
                ?>

                <?php foreach ($catOrder as $cat): ?>
                  <?php if (empty($byCat[$cat])) continue; ?>
                  <fieldset class="card" style="margin-top:10px;background:rgba(255,255,255,.02);padding:16px">
                    <legend class="badge" style="padding:2px 10px"><?php echo e(ucfirst($cat)); ?></legend>
                    <div style="display:grid;gap:14px;margin-top:6px">
                      <?php foreach ($byCat[$cat] as $ext):
                        $state = $launcherExtensions[$ext['key']] ?? ['enabled' => false, 'api_url' => '', 'api_key' => ''];
                      ?>
                        <div style="display:grid;gap:10px;padding:12px;border:1px solid var(--border-1);border-radius:12px;background:rgba(255,255,255,.02)">
                          <label style="display:flex;gap:12px;align-items:flex-start;cursor:pointer">
                            <input type="checkbox" name="ext[<?php echo e($ext['key']); ?>][enabled]" value="1" <?php echo $state['enabled'] ? 'checked' : ''; ?> />
                            <span>
                              <strong style="color:#fff"><?php echo e($ext['name']); ?></strong><br>
                              <span class="small"><?php echo e($ext['desc']); ?></span>
                            </span>
                          </label>

                          <?php if ($ext['needs_api']): ?>
                            <div class="two-col" style="gap:10px">
                              <label class="label" style="margin:0">
                                <span>URL de ton API</span>
                                <input class="input" type="url" name="ext[<?php echo e($ext['key']); ?>][api_url]" placeholder="https://api.ton-serveur.com/<?php echo e($ext['key']); ?>" value="<?php echo e($state['api_url']); ?>" />
                              </label>
                              <label class="label" style="margin:0">
                                <span>Clé API (optionnel)</span>
                                <input class="input" type="text" name="ext[<?php echo e($ext['key']); ?>][api_key]" placeholder="Bearer xxxxxx" value="<?php echo e($state['api_key']); ?>" autocomplete="off" />
                              </label>
                            </div>
                            <span class="help">Le launcher appellera <code>GET {URL}</code> avec l’en-tête <code>Authorization: Bearer {clé}</code> si renseignée.</span>
                          <?php else: ?>
                            <span class="help">Aucune configuration requise — géré côté Xyno.</span>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </fieldset>
                <?php endforeach; ?>

                <div class="cta-row" style="margin-top:14px">
                  <button class="btn btn-primary" type="submit">Enregistrer les extensions</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </section>

        <section id="auth" class="section-sm">
          <h2 class="section-title">Authentification</h2>
          <p class="section-desc">Choisis comment tes joueurs se connectent au launcher : via leur compte Microsoft, via ta propre API Bearer, ou en mode offline (dev seulement).</p>

          <div class="card">
            <?php if ($selected === null): ?>
              <p class="small" style="margin:0">Sélectionne un launcher pour configurer son authentification.</p>
            <?php elseif (!$authAvailable): ?>
              <p class="small" style="margin:0">La table <code>launcher_auth</code> n’existe pas encore. Importe <a href="#sql">migrations_v3.sql</a> pour activer cette section.</p>
            <?php else:
              $authMode = $launcherAuth['mode'] ?: 'microsoft';
            ?>
              <form class="form" action="launcher/update_auth.php" method="post" aria-label="Configuration authentification">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />

                <fieldset class="card" style="margin-top:6px;background:rgba(255,255,255,.02);padding:16px">
                  <legend class="small" style="padding:0 8px;color:var(--muted)">Mode</legend>
                  <div style="display:grid;gap:12px;margin-top:4px">
                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                      <input type="radio" name="mode" value="microsoft" <?php echo $authMode === 'microsoft' ? 'checked' : ''; ?> />
                      <span>
                        <strong style="color:#fff">Microsoft (recommandé)</strong><br>
                        <span class="small">OAuth Microsoft standard — compatible comptes premium Minecraft. Aucun paramétrage requis.</span>
                      </span>
                    </label>

                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                      <input type="radio" name="mode" value="custom" <?php echo $authMode === 'custom' ? 'checked' : ''; ?> />
                      <span>
                        <strong style="color:#fff">API Bearer personnalisée</strong><br>
                        <span class="small">Ton serveur gère l’authentification. Le launcher envoie <code>email + password</code> à ton API qui renvoie un token Bearer.</span>
                      </span>
                    </label>

                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                      <input type="radio" name="mode" value="offline" <?php echo $authMode === 'offline' ? 'checked' : ''; ?> />
                      <span>
                        <strong style="color:#fff">Offline (dev uniquement)</strong><br>
                        <span class="small">Pseudo libre côté client, aucune vérification serveur. À ne pas utiliser en production.</span>
                      </span>
                    </label>
                  </div>
                </fieldset>

                <fieldset class="card" style="margin-top:14px;background:rgba(255,255,255,.02);padding:16px" data-auth-custom>
                  <legend class="small" style="padding:0 8px;color:var(--muted)">Endpoints de ton API (mode « API Bearer »)</legend>

                  <div class="two-col" style="gap:10px;margin-top:4px">
                    <label class="label">
                      <span>URL de login (POST email+password → token)</span>
                      <input class="input" type="url" name="login_url" placeholder="https://api.ton-serveur.com/auth/login" value="<?php echo e($launcherAuth['login_url']); ?>" />
                    </label>
                    <label class="label">
                      <span>URL de vérification du token (GET, Bearer)</span>
                      <input class="input" type="url" name="verify_url" placeholder="https://api.ton-serveur.com/auth/me" value="<?php echo e($launcherAuth['verify_url']); ?>" />
                    </label>
                  </div>

                  <div class="two-col" style="gap:10px;margin-top:10px">
                    <label class="label">
                      <span>URL de refresh du token (optionnel)</span>
                      <input class="input" type="url" name="refresh_url" placeholder="https://api.ton-serveur.com/auth/refresh" value="<?php echo e($launcherAuth['refresh_url']); ?>" />
                    </label>
                    <label class="label">
                      <span>Clé API partagée (X-Api-Key, optionnel)</span>
                      <input class="input" type="text" name="api_key" placeholder="clé privée envoyée en header X-Api-Key" value="<?php echo e($launcherAuth['api_key']); ?>" autocomplete="off" />
                    </label>
                  </div>

                  <div class="callout" style="margin-top:14px">
                    <div>
                      <span class="badge">Contrat attendu</span>
                      <p class="small" style="margin-top:8px">
                        <code>POST {login_url}</code> avec <code>{"email":"…","password":"…"}</code> → réponse JSON <code>{"token":"…","uuid":"…","username":"…"}</code>.<br>
                        <code>GET {verify_url}</code> avec <code>Authorization: Bearer {token}</code> → <code>200 OK</code> si valide.
                      </p>
                    </div>
                  </div>
                </fieldset>

                <div class="cta-row" style="margin-top:14px">
                  <button class="btn btn-primary" type="submit">Enregistrer l’authentification</button>
                </div>
              </form>

              <script>
                (function () {
                  var radios = document.querySelectorAll('input[name="mode"]');
                  var custom = document.querySelector('[data-auth-custom]');
                  function refresh() {
                    var selected = document.querySelector('input[name="mode"]:checked');
                    if (!custom || !selected) return;
                    custom.style.opacity = selected.value === 'custom' ? '1' : '.5';
                    custom.style.pointerEvents = selected.value === 'custom' ? 'auto' : 'none';
                  }
                  radios.forEach(function (r) { r.addEventListener('change', refresh); });
                  refresh();
                })();
              </script>
            <?php endif; ?>
          </div>
        </section>

        <section id="sql" class="section-sm">
          <h2 class="section-title">SQL à exécuter</h2>
          <p class="section-desc">Quelques fonctionnalités récentes nécessitent de mettre à jour ta base MySQL. Copie le script ci-dessous dans <strong style="color:#fff">phpMyAdmin → Importer</strong> (ou <code>mysql -u user -p xynocms &lt; migrations_v3.sql</code>). Le script est idempotent : tu peux le rejouer sans risque.</p>

          <div class="card">
            <div class="nav-row" style="align-items:center;margin:0;padding:0;border:0;gap:10px">
              <div>
                <span class="badge badge-accent">migrations_v3.sql</span>
                <p class="small" style="margin-top:6px">Ajoute : <code>subscriptions.status = 'cancelled'</code>, <code>launcher_extensions</code>, <code>launcher_auth</code>, <code>launcher_logs</code>, <code>launcher_downloads_log</code>, <code>launcher_builds_log</code>.</p>
              </div>
              <div class="cta-row" style="margin:0">
                <button class="btn" type="button" onclick="copySqlV3(this)">Copier</button>
                <a class="btn btn-primary" href="migrations_v3.sql" download>Télécharger</a>
              </div>
            </div>

            <pre id="sql-v3" style="margin-top:14px;padding:14px;background:#0b0b14;border:1px solid var(--border-1);border-radius:12px;overflow:auto;max-height:420px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.55;color:#e2e8f0"><?php
              $sqlPath = __DIR__ . '/../migrations_v3.sql';
              $sqlBody = is_readable($sqlPath) ? (string)file_get_contents($sqlPath) : "-- Fichier migrations_v3.sql introuvable côté serveur.\n-- Demande-le à Xyno ou télécharge-le depuis le repo.";
              echo e($sqlBody);
            ?></pre>

            <p class="small" style="margin:12px 0 0">Après l’import, reviens sur cette page — les sections <a href="#extensions">Extensions</a>, <a href="#auth">Authentification</a> et <a href="#facturation">Résiliation</a> seront débloquées automatiquement.</p>
          </div>

          <script>
            function copySqlV3(btn) {
              var pre = document.getElementById('sql-v3');
              if (!pre) return;
              var text = pre.innerText;
              if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                  var prev = btn.innerText;
                  btn.innerText = 'Copié ✓';
                  setTimeout(function () { btn.innerText = prev; }, 1800);
                });
              } else {
                var r = document.createRange(); r.selectNode(pre);
                var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
                try { document.execCommand('copy'); btn.innerText = 'Copié ✓'; } catch (_) {}
                s.removeAllRanges();
              }
            }
          </script>
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
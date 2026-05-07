<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/subscription_helpers.php';

$user = require_login();

$pdo = db();

// Show admin link in the navbar only for accounts with is_admin = 1.
$isAdmin = false;
try {
    $stIsAdmin = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $stIsAdmin->execute([$user['id']]);
    $rowIsAdmin = $stIsAdmin->fetch();
    $isAdmin = $rowIsAdmin && (int)($rowIsAdmin['is_admin'] ?? 0) === 1;
} catch (Throwable $e) {
    // Migration v5 not applied yet — silently treat as non-admin.
    $isAdmin = false;
}

$stmt = $pdo->prepare('SELECT uuid, name, description, version, loader, theme, background_path, created_at FROM launchers WHERE user_id = ? ORDER BY created_at DESC');
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

// Loaded when a launcher is selected -----------------------------------------
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

// Installers per platform for the selected launcher --------------------------
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
      if ($installers[$p] !== null) continue;
      $installers[$p] = [
        'version' => (string)($row['version_name'] ?? ''),
        'is_active' => (int)($row['is_active'] ?? 0) === 1,
        'created_at' => (string)($row['created_at'] ?? ''),
      ];
    }
  } catch (Throwable $e) { /* table may be missing */ }
}

// Abonnement -----------------------------------------------------------------
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

// Logs launcher -------------------------------------------------------------
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

// Compteurs anti-abus --------------------------------------------------------
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

// Versions Minecraft supportées ----------------------------------------------
$mcVersions = [
  '1.21.4','1.21.3','1.21.1','1.21',
  '1.20.6','1.20.4','1.20.2','1.20.1',
  '1.19.4','1.19.2',
  '1.18.2','1.17.1','1.16.5','1.15.2',
  '1.14.4','1.13.2','1.12.2','1.11.2',
  '1.10.2','1.9.4','1.8.9','1.8.8','1.7.10',
];

// Catalogue d'extensions disponibles -----------------------------------------
$availableExtensions = [
  ['key' => 'news',           'name' => 'News & actualités',      'desc' => "Feed d'actu affiché sur la page Play du launcher.",                'needs_api' => true,  'category' => 'contenu'],
  ['key' => 'player_count',   'name' => 'Compteur de joueurs',    'desc' => 'Nombre de joueurs en ligne en temps réel.',                         'needs_api' => true,  'category' => 'serveur'],
  ['key' => 'server_status',  'name' => 'Statut serveur (ping)',  'desc' => "État (online/offline), ping et version côté launcher.",            'needs_api' => true,  'category' => 'serveur'],
  ['key' => 'discord',        'name' => 'Discord widget',         'desc' => 'Widget live + lien d\'invitation depuis le launcher.',              'needs_api' => true,  'category' => 'social'],
  ['key' => 'leaderboard',    'name' => 'Classement / Top joueurs','desc' => 'Top kills, temps, votes — affichage direct dans l\'app.',         'needs_api' => true,  'category' => 'social'],
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
  ['key' => 'anticheat',      'name' => 'Anti-cheat classique',   'desc' => "Détection basique des injections (DLL, -javaagent) avant le lancement.", 'needs_api' => false, 'category' => 'système'],
  ['key' => 'discord_rpc',    'name' => 'Discord Rich Presence',  'desc' => "Affiche « Joue à {ton launcher} » sur Discord avec nombre de joueurs.", 'needs_api' => false, 'category' => 'social'],
  ['key' => 'maintenance',    'name' => 'Système de maintenance', 'desc' => "Expose une mini-API qui renvoie {active, message, until}. Quand active, bloque le lancement.", 'needs_api' => true,  'category' => 'système'],
];

// Extensions activées --------------------------------------------------------
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

// Auth personnalisée ---------------------------------------------------------
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

// Marketplace ---------------------------------------------------------------
$marketplaceAvailable = true;
$marketplaceCatalog   = api_marketplace_catalog();
$marketplaceOwnedSet  = [];
$marketplaceSettings  = [];
$stripePublicKey      = trim(api_env('STRIPE_PUBLIC_KEY', ''));
$stripeConfigured     = trim(api_env('STRIPE_SECRET_KEY', '')) !== '';

if ($selectedId && $selectedId > 0) {
  try {
    $pdo->query('SELECT 1 FROM marketplace_purchases LIMIT 1');
    $marketplaceOwnedSet = array_fill_keys(api_marketplace_owned_keys($selectedId), true);
    $marketplaceSettings = api_marketplace_settings_get($selectedId);
  } catch (Throwable $e) {
    $marketplaceAvailable = false;
  }
}
$ownedCount = count($marketplaceOwnedSet);

// Abonnement du launcher sélectionné -----------------------------------------
// Charge la souscription la plus récente liée au launcher courant (peu importe
// le statut) afin que la section "Abonnement" du tab Général affiche soit un
// CTA d'achat (aucun abo / pending / past_due / expired / cancelled), soit
// l'état actif avec l'option "Résilier".
$selectedSub = null;
if ($selectedId && $selectedId > 0) {
  try {
    $ss = $pdo->prepare(
      'SELECT id, status, plan, period, amount_cents, currency, expires_at, '
    . '       next_billing_at, cancelled_at, stripe_subscription_id, created_at '
    . 'FROM subscriptions '
    . 'WHERE launcher_id = ? AND user_id = ? '
    . "ORDER BY (status = 'active') DESC, (status = 'pending') DESC, created_at DESC "
    . 'LIMIT 1'
    );
    $ss->execute([$selectedId, $user['id']]);
    $selectedSub = $ss->fetch() ?: null;
  } catch (Throwable $e) {
    $selectedSub = null; // table missing or pre-v4 schema
  }
}

// Paywall : verrou sur le detail launcher quand l'abonnement n'est pas 'active'.
// On grise toutes les zones d'edition (formulaires, builds, marketplace...) et
// on affiche un modal + banner pour pousser l'utilisateur a souscrire. Le bloc
// "Abonnement de ce launcher" du tab General reste pleinement interactif pour
// permettre de souscrire ou reactiver.
$paywallLocked = !($selectedSub && strtolower((string)($selectedSub['status'] ?? '')) === 'active');

// Quick helpers used below ----------------------------------------------------
$owns = function (string $k) use ($marketplaceOwnedSet): bool {
  return isset($marketplaceOwnedSet[$k]);
};
$catalogByKey = [];
foreach ($marketplaceCatalog as $item) { $catalogByKey[(string)$item['key']] = $item; }
$priceFor = function (string $key) use ($catalogByKey): string {
  if (!isset($catalogByKey[$key])) return '';
  $it = $catalogByKey[$key];
  return number_format(((int)$it['price_cents']) / 100, 2, ',', ' ') . ' ' . strtoupper((string)$it['currency']);
};

// Query-string feedback -------------------------------------------------------
$mpSuccess  = isset($_GET['mp_success']) && (string)$_GET['mp_success'] === '1';
$mpCancel   = isset($_GET['mp_cancel'])  && (string)$_GET['mp_cancel']  === '1';
$subSuccess = isset($_GET['sub_success']) && (string)$_GET['sub_success'] === '1';
$subCancel  = isset($_GET['sub_cancel'])  && (string)$_GET['sub_cancel']  === '1';

// Active tab ------------------------------------------------------------------
$validTabs = ['general','extensions','apparence','auth','versions','monitoring','marketplace'];
$activeTab = (string)($_GET['tab'] ?? '');
if (!in_array($activeTab, $validTabs, true)) {
  if ($mpSuccess || $mpCancel) {
    $activeTab = 'marketplace';
  } elseif ($subSuccess || $subCancel) {
    $activeTab = 'general';
  } else {
    $activeTab = 'general';
  }
}

$csrf    = csrf_token();
$success = flash_get('success');
$error   = flash_get('error');

// Category label map (nicer than raw slugs) ----------------------------------
$catLabel = [
  'contenu'      => 'Contenu',
  'serveur'      => 'Serveur',
  'social'       => 'Social',
  'monétisation' => 'Monétisation',
  'gameplay'     => 'Gameplay',
  'système'      => 'Système',
];
$catClass = [
  'contenu'      => 'cat-contenu',
  'serveur'      => 'cat-serveur',
  'social'       => 'cat-social',
  'monétisation' => 'cat-monetisation',
  'gameplay'     => 'cat-gameplay',
  'système'      => 'cat-systeme',
];

// Group extensions by category for the Extensions tab
$extByCat = [];
foreach ($availableExtensions as $ext) { $extByCat[$ext['category']][] = $ext; }
$catOrder = ['contenu','serveur','social','monétisation','gameplay','système'];

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
        <?php if ($isAdmin): ?>
          <a class="btn btn-ghost" href="/admin/index.php">Admin</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="builder.php">Créer un launcher</a>
        <a class="btn btn-ghost" href="../account/settings.php">Mon compte</a>
        <a class="btn" href="logout.php">Se déconnecter</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="container dash">

      <?php if ($success): ?>
        <div class="notice" data-show="true" style="margin-bottom:14px"><?php echo e($success); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="notice" data-show="true" style="margin-bottom:14px"><?php echo e($error); ?></div>
      <?php endif; ?>

      <?php if ($selected === null): /* -------- Level 1: home view -------- */ ?>

        <?php
          $subStatus = $subscription ? strtolower((string)($subscription['status'] ?? '')) : '';
          $subExpires = $subscription ? (string)($subscription['expires_at'] ?? '') : '';
          $subExpiresTs = $subExpires ? strtotime($subExpires) : null;
          $subNext = $subExpiresTs ? date('d/m/Y', $subExpiresTs) : '';
          $subDaysLeft = $subExpiresTs ? max(0, (int)floor(($subExpiresTs - time()) / 86400)) : null;
          $statusChip = match ($subStatus) {
            'active'    => ['ok',     'Actif'],
            'cancelled' => ['warn',   'Résilié (fin le ' . ($subNext ?: '?') . ')'],
            'past_due'  => ['danger', 'Paiement en retard'],
            ''          => ['muted',  'Aucun abonnement'],
            default     => ['muted',  ucfirst($subStatus)],
          };
        ?>

        <section class="dash-hero">
          <div>
            <span class="badge">Compte</span>
            <h1 style="margin-top:8px">Bonjour <?php echo e(explode('@', $user['email'])[0]); ?> 👋</h1>
            <p>Pilote tes launchers, tes extensions et ta facturation depuis un seul endroit.</p>
            <div class="dash-stats">
              <div class="dash-stat">
                <span class="label">Launchers</span>
                <span class="value"><?php echo (int)count($launchers); ?></span>
                <span class="hint"><?php echo count($launchers) ? 'projets actifs' : 'aucun projet — crée ton premier launcher'; ?></span>
              </div>
              <div class="dash-stat <?php echo $subStatus === 'active' ? 'is-accent' : ($subStatus === 'past_due' ? 'is-danger' : ''); ?>">
                <span class="label">Abonnement</span>
                <span class="value" style="font-size:18px"><span class="chip <?php echo e($statusChip[0]); ?>"><?php echo e($statusChip[1]); ?></span></span>
                <span class="hint">
                  <?php if ($subNext && $subStatus === 'active'): ?>
                    Prochain versement le <?php echo e($subNext); ?><?php if ($subDaysLeft !== null): ?> (dans <?= (int)$subDaysLeft ?> j)<?php endif; ?>
                  <?php elseif ($subscription): ?>
                    <?php echo e((string)($subscription['launcher_name'] ?: 'Abonnement')); ?>
                  <?php else: ?>
                    Choisis une offre pour déployer en production.
                  <?php endif; ?>
                </span>
              </div>
              <div class="dash-stat">
                <span class="label">Extensions acquises</span>
                <span class="value">—</span>
                <span class="hint">Sélectionne un launcher pour voir le détail.</span>
              </div>
            </div>
          </div>
          <div class="dash-hero-actions">
            <a class="btn btn-primary btn-lg" href="builder.php">+ Créer un launcher</a>
            <a class="btn" href="pricing.php">Voir les offres</a>
            <?php if ($subscription && $subStatus === 'active'): ?>
              <form action="cancel_subscription.php" method="post" style="margin:0" onsubmit="return confirm('Résilier ton abonnement ? Tu garderas l\'accès jusqu\'à la fin de la période.');">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="subscription_id" value="<?php echo e((string)($subscription['id'] ?? '')); ?>" />
                <button class="btn btn-ghost" type="submit">Résilier</button>
              </form>
            <?php elseif ($subscription && $subStatus === 'cancelled'): ?>
              <form action="reactivate_subscription.php" method="post" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="subscription_id" value="<?php echo e((string)($subscription['id'] ?? '')); ?>" />
                <button class="btn" type="submit">Réactiver</button>
              </form>
            <?php endif; ?>
          </div>
        </section>

        <div class="cat-head" style="margin-top:28px"><span class="cat-dot"></span>Tes launchers</div>

        <?php if (!count($launchers)): ?>
          <a class="launcher-create" href="builder.php">
            <div class="big">+</div>
            <strong>Créer ton premier launcher</strong>
            <span>Personnalisation, thème et mods — 5 minutes.</span>
          </a>
        <?php else: ?>
          <div class="launcher-pick">
            <?php foreach ($launchers as $l): ?>
              <article class="card">
                <div class="meta-row">
                  <span class="chip accent">Projet</span>
                  <span class="chip plain"><?php echo e((string)$l['version']); ?></span>
                  <span class="chip plain"><?php echo e((string)$l['loader']); ?></span>
                </div>
                <h3><?php echo e((string)$l['name']); ?></h3>
                <p class="small" style="margin:0;color:var(--muted)"><?php echo e((string)($l['description'] ?: 'Aucune description.')); ?></p>
                <div class="meta-row" style="margin-top:auto">
                  <span class="chip plain">🎨 <?php echo e((string)$l['theme']); ?></span>
                  <span class="chip plain">Créé le <?php echo e(date('d/m/Y', strtotime((string)$l['created_at']))); ?></span>
                </div>
                <div class="actions">
                  <a class="btn btn-primary" href="dashboard.php?launcher=<?php echo urlencode((string)$l['uuid']); ?>">Ouvrir →</a>
                  <a class="btn" href="download_launcher.php?uuid=<?php echo urlencode((string)$l['uuid']); ?>" title="Télécharger l'installer le plus récent">⬇</a>
                </div>
              </article>
            <?php endforeach; ?>
            <a class="launcher-create" href="builder.php">
              <div class="big">+</div>
              <strong>Créer un nouveau launcher</strong>
              <span>5 minutes chrono</span>
            </a>
          </div>
        <?php endif; ?>

      <?php else: /* -------- Level 2: launcher detail view -------- */ ?>

        <?php
          // Redirect to pricing paywall if no active subscription
          if (!$selectedSub || strtolower((string)($selectedSub['status'] ?? '')) !== 'active') {
              redirect('/pricing_paywall.php?launcher=' . urlencode((string)$selected['uuid']));
          }
        ?>

        <?php
          $uuidQ = urlencode((string)$selected['uuid']);
          $tabUrl = fn (string $t): string => 'dashboard.php?launcher=' . $uuidQ . '&tab=' . $t . '#tab-' . $t;
          $tabs = [
            'general'     => ['Général',        '⚙️'],
            'extensions'  => ['Extensions',     '🧩'],
            'apparence'   => ['Apparence',      '🎨'],
            'auth'        => ['Authentification','🔐'],
            'versions'    => ['Versions & Builds','📦'],
            'files'       => ['Fichiers',       '📁'],
            'monitoring'  => ['Monitoring',     '📈'],
            'marketplace' => ['Marketplace',    '🛒'],
          ];
          // Tabs that point to an external page (instead of an in-page panel).
          // Absolute URL: dashboard.php is reached via /dashboard.php (the
          // root wrapper), so a relative "files.php" would resolve to
          // /files.php (404). The real page lives in /dashboard/files.php.
          $externalTabs = [
            'files' => '/dashboard/files.php?launcher=' . $uuidQ,
          ];
        ?>

        <p class="dash-crumbs">
          <a href="dashboard.php">Dashboard</a>
          &nbsp;›&nbsp;
          <strong><?php echo e((string)$selected['name']); ?></strong>
        </p>

        <div class="dash-head">
          <div>
            <h1><?php echo e((string)$selected['name']); ?></h1>
            <p class="sub">
              <span class="chip accent"><?php echo e((string)$selected['version']); ?></span>
              <span class="chip plain" style="margin-left:6px"><?php echo e((string)$selected['loader']); ?></span>
              <span class="chip plain" style="margin-left:6px">🎨 <?php echo e((string)$selected['theme']); ?></span>
              <?php if ($ownedCount): ?>
                <span class="chip violet" style="margin-left:6px"><?php echo (int)$ownedCount; ?> extension<?php echo $ownedCount > 1 ? 's' : ''; ?> premium</span>
              <?php endif; ?>
            </p>
          </div>
          <div class="dash-head-actions">
            <a class="btn btn-primary" href="download_launcher.php?uuid=<?php echo $uuidQ; ?>">⬇ Télécharger l'installer</a>
            <?php if (count($launchers) > 1): ?>
              <details class="launcher-switch">
                <summary>Changer de launcher</summary>
                <div class="menu">
                  <?php foreach ($launchers as $l): ?>
                    <a class="<?php echo (string)$l['uuid'] === (string)$selected['uuid'] ? 'is-active' : ''; ?>"
                       href="dashboard.php?launcher=<?php echo urlencode((string)$l['uuid']); ?>">
                      <span><?php echo e((string)$l['name']); ?></span>
                      <span class="chip plain"><?php echo e((string)$l['version']); ?></span>
                    </a>
                  <?php endforeach; ?>
                  <a href="builder.php" style="color:#d6c5ff">+ Nouveau launcher</a>
                </div>
              </details>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($mpSuccess): ?>
          <div class="notice" data-show="true" style="margin-bottom:14px">Paiement confirmé ✓ — si la feature n'apparaît pas tout de suite, patiente quelques secondes (Stripe finalise la transaction).</div>
        <?php endif; ?>
        <?php if ($mpCancel): ?>
          <div class="notice" data-show="true" style="margin-bottom:14px">Paiement annulé. Aucun prélèvement n'a été effectué.</div>
        <?php endif; ?>
        <?php if ($subSuccess): ?>
          <div class="notice" data-show="true" style="margin-bottom:14px">Abonnement confirmé ✓ — Stripe finalise le premier prélèvement, ton launcher passera en "Actif" dans quelques secondes.</div>
        <?php endif; ?>
        <?php if ($subCancel): ?>
          <div class="notice" data-show="true" style="margin-bottom:14px">Abonnement annulé. Aucun prélèvement n'a été effectué — tu peux relancer la souscription depuis le tab Général.</div>
        <?php endif; ?>

        <nav class="tabs" aria-label="Onglets launcher">
          <?php foreach ($tabs as $tk => $tmeta): ?>
            <?php
              $isExternal = isset($externalTabs[$tk]);
              $href       = $isExternal ? $externalTabs[$tk] : $tabUrl($tk);
            ?>
            <a href="<?php echo e($href); ?>"
               <?php if (!$isExternal): ?>data-tab-link="<?php echo e($tk); ?>"<?php endif; ?>
               class="<?php echo (!$isExternal && $activeTab === $tk) ? 'is-active' : ''; ?>"
               aria-current="<?php echo (!$isExternal && $activeTab === $tk) ? 'page' : 'false'; ?>">
              <span class="tab-ic"><?php echo $tmeta[1]; ?></span>
              <?php echo e($tmeta[0]); ?>
            </a>
          <?php endforeach; ?>
        </nav>

        <?php if ($paywallLocked): ?>
          <style>
            .paywall-banner{
              position:sticky; top:8px; z-index:6;
              margin:14px 0 22px; padding:16px 20px;
              background:linear-gradient(135deg, rgba(124,58,237,.20), rgba(34,211,238,.10));
              border:1px solid rgba(124,58,237,.45);
              border-radius:14px;
              display:flex; gap:14px; align-items:center; justify-content:space-between;
              flex-wrap:wrap; backdrop-filter:blur(8px);
            }
            .paywall-banner .pw-text h3{margin:0 0 4px;color:#fff;font-size:15px;font-weight:700}
            .paywall-banner .pw-text p{margin:0;color:var(--muted,#a8a8b8);font-size:13px}
            .paywall-banner .btn-primary{
              background:linear-gradient(135deg,#7c3aed,#5b21b6); border:0; color:#fff;
              padding:10px 16px; border-radius:10px; font-weight:600; text-decoration:none;
              white-space:nowrap;
            }

            .paywall-overlay{ position:relative; }
            .paywall-overlay > *:not(.paywall-allowed){
              filter:grayscale(.6) blur(.5px);
              opacity:.55;
              pointer-events:none !important;
              user-select:none !important;
            }
            .paywall-overlay > *:not(.paywall-allowed) input,
            .paywall-overlay > *:not(.paywall-allowed) select,
            .paywall-overlay > *:not(.paywall-allowed) textarea,
            .paywall-overlay > *:not(.paywall-allowed) button,
            .paywall-overlay > *:not(.paywall-allowed) a{
              pointer-events:none !important;
            }

            .paywall-modal{
              position:fixed; inset:0; z-index:9999;
              background:rgba(8,10,18,.72); backdrop-filter:blur(6px);
              display:flex; align-items:center; justify-content:center;
              padding:20px; animation:pwFade .2s ease-out;
            }
            .paywall-modal[hidden]{display:none}
            .paywall-modal-card{
              max-width:460px; width:100%;
              background:#14141d; color:#e6e6f2;
              border:1px solid rgba(124,58,237,.35);
              border-radius:18px; padding:28px;
              box-shadow:0 24px 70px rgba(0,0,0,.55);
              text-align:center;
            }
            .paywall-modal-icon{font-size:42px; line-height:1; margin-bottom:10px}
            .paywall-modal-card h3{margin:0 0 8px; font-size:20px; color:#fff}
            .paywall-modal-card p{margin:0 0 20px; color:#a8a8b8; font-size:14px; line-height:1.55}
            .paywall-modal-actions{display:flex; gap:10px; justify-content:center; flex-wrap:wrap}
            .paywall-modal-actions .btn-primary{
              background:linear-gradient(135deg,#7c3aed,#5b21b6); border:0; color:#fff;
              padding:11px 18px; border-radius:10px; font-weight:600; text-decoration:none;
            }
            .paywall-modal-actions .btn-ghost{
              background:transparent; border:1px solid rgba(255,255,255,.18);
              color:#cfcfe0; padding:11px 18px; border-radius:10px; font-weight:500;
              cursor:pointer;
            }
            @keyframes pwFade{ from{opacity:0} to{opacity:1} }
          </style>

          <aside class="paywall-banner" role="status">
            <div class="pw-text">
              <h3>🔒 Abonnement requis pour ce launcher</h3>
              <p>L'edition (apparence, auth, builds, fichiers, marketplace) est verrouillee tant que l'abonnement n'est pas actif.</p>
            </div>
            <a class="btn-primary" href="<?php echo e($tabUrl('general')); ?>#sub-card">Souscrire maintenant →</a>
          </aside>

          <div class="paywall-modal" id="paywallModal" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
            <div class="paywall-modal-card">
              <div class="paywall-modal-icon">🔒</div>
              <h3 id="pwTitle">Active ton abonnement</h3>
              <p>Pour configurer ce launcher (apparence, auth, builds, fichiers, marketplace), tu dois activer une formule. Le bloc <strong>Abonnement de ce launcher</strong> est juste en dessous, dans l'onglet General.</p>
              <div class="paywall-modal-actions">
                <a class="btn-primary" href="<?php echo e($tabUrl('general')); ?>#sub-card">Choisir une formule →</a>
                <button class="btn-ghost" type="button" data-paywall-dismiss>Plus tard</button>
              </div>
            </div>
          </div>

          <script>
            (function(){
              var modal = document.getElementById('paywallModal');
              if (!modal) return;
              // Auto-dismiss when navigating to the sub-card
              document.querySelectorAll('[data-paywall-dismiss]').forEach(function(b){
                b.addEventListener('click', function(){ modal.hidden = true; });
              });
              modal.querySelectorAll('a[href*="#sub-card"]').forEach(function(a){
                a.addEventListener('click', function(){ setTimeout(function(){ modal.hidden = true; }, 50); });
              });
              // Click outside the card → dismiss
              modal.addEventListener('click', function(ev){
                if (ev.target === modal) modal.hidden = true;
              });
              // ESC dismisses
              document.addEventListener('keydown', function(ev){
                if (ev.key === 'Escape' && !modal.hidden) modal.hidden = true;
              });
            })();
          </script>
        <?php endif; ?>

        <!-- ============ TAB: Général ============ -->
        <section id="tab-general" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="general" <?php if ($activeTab !== 'general') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">⚙️ Paramètres généraux</h2>
            <p class="panel-desc">Identité du launcher, version Minecraft, logo et installers à distribuer.</p>
          </div>

          <?php
            // -------- Abonnement de CE launcher --------
            $selSubStatus = $selectedSub ? strtolower((string)($selectedSub['status'] ?? '')) : '';
            $selSubPlan   = $selectedSub ? (string)($selectedSub['plan']   ?? '') : '';
            $selSubPeriod = $selectedSub ? (string)($selectedSub['period'] ?? '') : '';
            $selSubExp    = $selectedSub ? (string)($selectedSub['expires_at'] ?? '') : '';
            $selSubExpTs  = $selSubExp ? strtotime($selSubExp) : 0;
            $selSubNext   = $selectedSub ? (string)($selectedSub['next_billing_at'] ?? '') : '';
            $selSubNextTs = $selSubNext ? strtotime($selSubNext) : 0;
            $selSubAmt    = (int)($selectedSub['amount_cents'] ?? 0);
            $selSubCcy    = strtoupper((string)($selectedSub['currency'] ?? 'EUR'));
            $isActiveSub  = $selSubStatus === 'active';
            $isPendingSub = $selSubStatus === 'pending';
            $needsSub     = !$selectedSub || in_array($selSubStatus, ['expired','cancelled',''], true);

            $planLabels = [
              'starter' => 'Starter (9 €/mo)',
              'pro'     => 'Pro (19 €/mo)',
              'premium' => 'Premium (39 €/mo)',
            ];
            $periodLabels = [
              'monthly'    => 'Mensuel',
              'quarterly'  => 'Trimestriel (-5 %)',
              'semestrial' => 'Semestriel (-10 %)',
              'yearly'     => 'Annuel (-15 %)',
            ];
          ?>
          <section id="sub-card" class="sub-card paywall-allowed" aria-label="Abonnement du launcher">
            <div class="sub-card-head">
              <div>
                <h3>💳 Abonnement de ce launcher</h3>
                <p>
                  <?php if ($isActiveSub): ?>
                    Plan actif — Stripe gère le renouvellement automatique.
                  <?php elseif ($isPendingSub): ?>
                    Paiement en cours de validation par Stripe.
                  <?php elseif ($selSubStatus === 'cancelled'): ?>
                    Résilié — l'accès reste ouvert jusqu'à la fin de la période en cours.
                  <?php elseif ($selSubStatus === 'past_due'): ?>
                    Paiement en retard — Stripe va relancer la carte automatiquement.
                  <?php else: ?>
                    Aucune souscription active — choisis une formule pour ouvrir le launcher en production.
                  <?php endif; ?>
                </p>
              </div>
            </div>

            <?php if ($selectedSub): ?>
              <div class="meta-row" style="gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <span class="chip <?php echo $isActiveSub ? 'ok' : ($selSubStatus === 'past_due' ? 'danger' : ($selSubStatus === 'cancelled' ? 'warn' : 'muted')); ?>">
                  <?php echo e(ucfirst($selSubStatus)); ?>
                </span>
                <?php if ($selSubPlan !== ''): ?>
                  <span class="chip plain">Plan : <?php echo e(subscription_plan_label($selSubPlan)); ?></span>
                <?php endif; ?>
                <?php if ($selSubPeriod !== '' && isset($periodLabels[$selSubPeriod])): ?>
                  <span class="chip plain"><?php echo e($periodLabels[$selSubPeriod]); ?></span>
                <?php endif; ?>
                <?php if ($selSubAmt > 0): ?>
                  <span class="chip plain">
                    <?php echo number_format($selSubAmt / 100, 2, ',', ' '); ?> <?php echo e($selSubCcy); ?> / cycle
                  </span>
                <?php endif; ?>
                <?php if ($selSubNextTs > 0 && $isActiveSub): ?>
                  <span class="chip plain">Prochain prélèvement : <?php echo e(date('d/m/Y', $selSubNextTs)); ?></span>
                <?php elseif ($selSubExpTs > 0): ?>
                  <span class="chip plain">
                    <?php echo $isActiveSub ? 'Expire le' : 'Accès jusqu\'au'; ?> <?php echo e(date('d/m/Y', $selSubExpTs)); ?>
                  </span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($needsSub): ?>
              <form action="api/subscription_checkout.php" method="post" class="form" style="gap:12px">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <div class="two-col">
                  <label class="label"><span>Plan</span>
                    <select name="plan" required>
                      <?php foreach ($planLabels as $pk => $pl): ?>
                        <option value="<?php echo e($pk); ?>" <?php echo $pk === 'pro' ? 'selected' : ''; ?>><?php echo e($pl); ?></option>
                      <?php endforeach; ?>
                    </select></label>
                  <label class="label"><span>Périodicité</span>
                    <select name="period" required>
                      <?php foreach ($periodLabels as $pk => $pl): ?>
                        <option value="<?php echo e($pk); ?>" <?php echo $pk === 'monthly' ? 'selected' : ''; ?>><?php echo e($pl); ?></option>
                      <?php endforeach; ?>
                    </select></label>
                </div>
                <div class="meta-row" style="gap:8px;align-items:center">
                  <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled aria-disabled="true"'; ?>>
                    Souscrire avec Stripe →
                  </button>
                  <a class="btn btn-ghost" href="pricing.php">Voir le détail des offres</a>
                  <?php if (!$stripeConfigured): ?>
                    <span class="help" style="color:var(--danger,#ff7676)">⚠ Stripe non configuré : ajoute STRIPE_SECRET_KEY dans config/.env.local.</span>
                  <?php endif; ?>
                </div>
              </form>
            <?php elseif ($isActiveSub): ?>
              <div class="meta-row" style="gap:10px">
                <form action="cancel_subscription.php" method="post" style="margin:0"
                      onsubmit="return confirm('Résilier l\'abonnement de ce launcher ? Tu garderas l\'accès jusqu\'à la fin de la période en cours.');">
                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                  <input type="hidden" name="subscription_id" value="<?php echo e((string)($selectedSub['id'] ?? '')); ?>" />
                  <button class="btn btn-ghost" type="submit">Résilier</button>
                </form>
                <a class="btn" href="pricing.php">Changer de formule</a>
              </div>
            <?php elseif ($selSubStatus === 'cancelled'): ?>
              <form action="reactivate_subscription.php" method="post" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="subscription_id" value="<?php echo e((string)($selectedSub['id'] ?? '')); ?>" />
                <button class="btn btn-primary" type="submit">Réactiver l'abonnement</button>
              </form>
            <?php elseif ($isPendingSub): ?>
              <p class="help">⏳ Stripe finalise le paiement — cette section passera en "Actif" automatiquement (recharge la page dans quelques secondes).</p>
            <?php elseif ($selSubStatus === 'past_due'): ?>
              <p class="help">⚠ Stripe va retenter le prélèvement. Mets à jour ta carte depuis <a href="https://dashboard.stripe.com/test/customers" target="_blank" rel="noopener">le portail Stripe</a> si besoin.</p>
            <?php endif; ?>
          </section>

          <form class="form sub-card" aria-label="Configuration launcher" action="update_launcher.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />

            <div class="sub-card-head">
              <div><h3>Identité</h3><p>Ce que tes joueurs verront dans le launcher.</p></div>
            </div>

            <div class="two-col">
              <label class="label"><span>Nom du launcher</span>
                <input class="input" name="name" placeholder="Ex: Xyno RP" value="<?php echo e((string)$selected['name']); ?>" required /></label>
              <label class="label"><span>Thème</span>
                <select name="theme" required>
                  <?php
                    $availableThemes = [
                      'Dark Tactical'  => 'Dark Tactical — black ops, accents rouges',
                      'Mystic Purple'  => 'Mystic Purple — gradients violets, glassmorphism',
                      'Neon Frontier'  => 'Neon Frontier — cyberpunk cyan/vert',
                      'Cosmic'         => 'Cosmic — tons spatiaux',
                      'Violet Neon'    => 'Violet Neon (legacy)',
                      'Glacier'        => 'Glacier (legacy)',
                    ];
                    $currentTheme = (string)$selected['theme'];
                    if ($currentTheme !== '' && !isset($availableThemes[$currentTheme])) {
                      $availableThemes = [$currentTheme => $currentTheme] + $availableThemes;
                    }
                  ?>
                  <?php foreach ($availableThemes as $themeKey => $themeLabel): ?>
                    <option value="<?php echo e($themeKey); ?>" <?php echo ($currentTheme === $themeKey) ? 'selected' : ''; ?>><?php echo e($themeLabel); ?></option>
                  <?php endforeach; ?>
                </select></label>
            </div>

            <label class="label"><span>Description</span>
              <input class="input" name="description" placeholder="(optionnel)" value="<?php echo e((string)$selected['description']); ?>" /></label>

            <div class="two-col">
              <label class="label"><span>Version Minecraft</span>
                <select name="version" required>
                  <?php
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
                <span class="help">Toutes les versions supportées de 1.7.10 à 1.21.4.</span></label>
              <label class="label"><span>Loader</span>
                <select name="loader" required>
                  <?php foreach (['fabric','forge','quilt'] as $ld): ?>
                    <option value="<?php echo e($ld); ?>" <?php echo ((string)$selected['loader'] === $ld) ? 'selected' : ''; ?>><?php echo e(ucfirst($ld)); ?></option>
                  <?php endforeach; ?>
                </select></label>
            </div>

            <div class="sub-card-head" style="margin-top:4px"><div><h3>Logo du launcher</h3><p>Affiché dans la fenêtre du launcher (en haut à gauche). Carré 512×512 recommandé, transparence acceptée.</p></div></div>
            <?php
              // Detect any of the four supported logo extensions on disk; the
              // launcher's manifest endpoint does the same scan in this exact
              // order, so picking the first match here matches what the user
              // sees in the launcher.
              $logoExt = '';
              foreach (['png', 'ico', 'jpg', 'webp'] as $ext) {
                  if (is_file(__DIR__ . '/../uploads/launchers/' . (int)$selectedId . '/logo.' . $ext)) {
                      $logoExt = $ext;
                      break;
                  }
              }
              $hasLogo = $logoExt !== '';
              $logoRel = $hasLogo ? ('uploads/launchers/' . (int)$selectedId . '/logo.' . $logoExt) : '';
            ?>
            <div class="two-col" style="align-items:end">
              <label class="label"><span>Fichier logo (PNG / ICO / JPG / WEBP)</span>
                <input class="input" type="file" name="logo" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/jpeg,image/webp" />
                <span class="help">Max 2 Mo · carré recommandé · transparence acceptée pour PNG/WEBP.</span></label>
              <div style="padding:12px;background:rgba(0,0,0,.25);border-radius:12px;display:flex;align-items:center;gap:12px">
                <div style="width:64px;height:64px;border-radius:14px;border:1px solid var(--border-2);background:
                  <?php if ($hasLogo): ?>
                    url('<?php echo e('/' . $logoRel . '?v=' . filemtime(__DIR__ . '/../' . $logoRel)); ?>') center/contain no-repeat, var(--grad-soft);
                  <?php else: ?>
                    var(--grad-soft);
                  <?php endif; ?>"></div>
                <div>
                  <strong style="color:#fff;font-size:14px">Logo actuel</strong><br>
                  <span class="small"><?php echo $hasLogo ? 'Personnalisé (' . strtoupper($logoExt) . ')' : 'Logo Xyno par défaut'; ?></span>
                  <?php if ($hasLogo): ?>
                    <br><label class="small" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;cursor:pointer">
                      <input type="checkbox" name="remove_logo" value="1" /> Supprimer le logo
                    </label>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="sub-card-head" style="margin-top:4px"><div><h3>Background du launcher</h3><p>Image affichée derrière l'interface du launcher. PNG / JPG / WEBP, max 5 Mo. Idéalement 1920×1080 ou plus.</p></div></div>
            <?php
              $bgPath = trim((string)($selected['background_path'] ?? ''));
              $hasBg = ($bgPath !== '') && is_file(__DIR__ . '/../uploads/launchers/' . (int)$selectedId . '/' . $bgPath);
              $bgUrl = $hasBg ? ('uploads/launchers/' . (int)$selectedId . '/' . $bgPath) : '';
            ?>
            <div class="two-col" style="align-items:end">
              <label class="label"><span>Fichier image (PNG / JPG / WEBP)</span>
                <input class="input" type="file" name="background" accept="image/png,image/jpeg,image/webp" />
                <span class="help">Affiché en plein cadre. Privilégie une image qui passe bien en fond (peu de détails au centre).</span></label>
              <div style="padding:12px;background:rgba(0,0,0,.25);border-radius:12px;display:flex;align-items:center;gap:12px">
                <div style="width:120px;height:68px;border-radius:8px;border:1px solid var(--border-2);background:
                  <?php if ($hasBg): ?>
                    url('<?php echo e('/' . $bgUrl . '?v=' . filemtime(__DIR__ . '/../' . $bgUrl)); ?>') center/cover no-repeat, var(--grad-soft);
                  <?php else: ?>
                    var(--grad-soft);
                  <?php endif; ?>"></div>
                <div>
                  <strong style="color:#fff;font-size:14px">Background actuel</strong><br>
                  <span class="small"><?php echo $hasBg ? 'Personnalisé' : 'Background du thème par défaut'; ?></span>
                  <?php if ($hasBg): ?>
                    <br><label class="small" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;cursor:pointer">
                      <input type="checkbox" name="remove_background" value="1" /> Supprimer le background
                    </label>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="cta-row" style="margin-top:10px">
              <button class="btn btn-primary" type="submit">Enregistrer</button>
            </div>
          </form>

          <div class="sub-card">
            <div class="sub-card-head"><div><h3>Clés &amp; identifiants</h3><p>Lecture seule. L'API key est secrète : elle est utilisée côté Electron.</p></div></div>
            <div class="two-col">
              <label class="label"><span>UUID</span><input class="input" value="<?php echo e((string)$selected['uuid']); ?>" readonly /></label>
              <label class="label"><span>API Key</span><input class="input" value="<?php echo e((string)($selectedKey ?? '')); ?>" readonly /><span class="help">À garder secret.</span></label>
            </div>
          </div>

          <div class="sub-card">
            <div class="sub-card-head"><div><h3>Installers générés</h3><p>Un installer par OS. Les fichiers sont automatiquement renommés <code><?php echo e((string)$selected['name']); ?>Launcher.{ext}</code>.</p></div></div>
            <div style="display:grid;gap:10px">
              <?php
                $platforms = [
                  'win'   => ['label' => 'Windows', 'ext' => 'exe'],
                  'mac'   => ['label' => 'macOS',   'ext' => 'dmg'],
                  'linux' => ['label' => 'Linux',   'ext' => 'AppImage'],
                ];
              ?>
              <?php foreach ($platforms as $pKey => $pMeta): $inst = $installers[$pKey] ?? null; ?>
                <div class="nav-row" style="align-items:center;gap:12px;padding:10px 14px;background:rgba(255,255,255,.03);border-radius:10px;border:1px solid var(--border-1)">
                  <div>
                    <strong><?php echo e($pMeta['label']); ?></strong>
                    <?php if ($inst): ?>
                      <span class="small" style="margin-left:10px;color:var(--muted)">Version <?php echo e($inst['version'] ?: '?'); ?></span>
                      <?php if ($inst['is_active']): ?><span class="chip ok" style="margin-left:6px">Actif</span><?php endif; ?>
                    <?php else: ?>
                      <span class="chip muted" style="margin-left:10px">Pas encore généré</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($inst): ?>
                    <a class="btn btn-primary" href="download_launcher.php?uuid=<?php echo $uuidQ; ?>&amp;platform=<?php echo e($pKey); ?>">Télécharger</a>
                  <?php else: ?>
                    <a class="btn" href="<?php echo e($tabUrl('versions')); ?>">Lancer un build</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="danger-zone">
            <h3>⚠ Zone dangereuse</h3>
            <p>Supprimer le launcher efface définitivement sa configuration, ses extensions et ses versions publiées. Irréversible.</p>
            <form action="delete_launcher.php" method="post" style="margin-top:10px" onsubmit="return confirm('Supprimer ce launcher ? Cette action est définitive.');">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
              <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
              <button class="btn" type="submit">Supprimer ce launcher</button>
            </form>
          </div>
        </section>

        <!-- ============ TAB: Extensions ============ -->
        <section id="tab-extensions" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="extensions" <?php if ($activeTab !== 'extensions') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">🧩 Extensions</h2>
            <p class="panel-desc">21 modules prêts à l'emploi, activables en un clic. Les modules marqués « Premium » s'achètent dans l'onglet <a href="<?php echo e($tabUrl('marketplace')); ?>">Marketplace</a> et se configurent directement ici.</p>
          </div>

          <?php if (!$extensionsAvailable): ?>
            <div class="sub-card"><p class="small" style="margin:0">La table <code>launcher_extensions</code> n'existe pas encore. Importe <code>migrations_v3.sql</code> depuis l'onglet Général.</p></div>
          <?php else: ?>
            <form class="form" action="update_extensions.php" method="post" aria-label="Configuration extensions">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
              <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />

              <?php foreach ($catOrder as $cat): if (empty($extByCat[$cat])) continue; ?>
                <div class="cat-head <?php echo e($catClass[$cat] ?? ''); ?>"><span class="cat-dot"></span><?php echo e($catLabel[$cat] ?? ucfirst($cat)); ?></div>
                <div class="item-list">
                  <?php foreach ($extByCat[$cat] as $ext):
                    $state = $launcherExtensions[$ext['key']] ?? ['enabled' => false, 'api_url' => '', 'api_key' => ''];
                    $hasAdvanced = ($ext['key'] === 'discord_rpc' || $ext['key'] === 'anticheat');
                    $advKey      = $ext['key'] === 'discord_rpc' ? 'discord_rpc_advanced' : ($ext['key'] === 'anticheat' ? 'anticheat_advanced' : '');
                    $advOwned    = $advKey !== '' && $owns($advKey);
                  ?>
                    <div class="item-row <?php echo $state['enabled'] ? 'is-owned' : ''; ?>">
                      <div>
                        <div class="head">
                          <label style="display:flex;gap:10px;align-items:center;cursor:pointer;margin:0">
                            <input type="checkbox" name="ext[<?php echo e($ext['key']); ?>][enabled]" value="1" <?php echo $state['enabled'] ? 'checked' : ''; ?> />
                            <h4><?php echo e($ext['name']); ?></h4>
                          </label>
                          <?php if ($state['enabled']): ?><span class="chip ok">Actif</span><?php endif; ?>
                          <?php if ($hasAdvanced): ?>
                            <?php if ($advOwned): ?>
                              <span class="chip violet">Pro débloqué</span>
                            <?php else: ?>
                              <span class="chip plain">Pro disponible</span>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>
                        <p><?php echo e($ext['desc']); ?></p>

                        <?php if ($ext['needs_api']): ?>
                          <div class="two-col" style="margin-top:10px;gap:10px">
                            <label class="label" style="margin:0">
                              <span>URL de ton API</span>
                              <input class="input" type="url" name="ext[<?php echo e($ext['key']); ?>][api_url]" placeholder="https://api.ton-serveur.com/<?php echo e($ext['key']); ?>" value="<?php echo e($state['api_url']); ?>" />
                            </label>
                            <label class="label" style="margin:0">
                              <span>Clé API (optionnel)</span>
                              <input class="input" type="text" name="ext[<?php echo e($ext['key']); ?>][api_key]" placeholder="Bearer xxxxxx" value="<?php echo e($state['api_key']); ?>" autocomplete="off" />
                            </label>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>

              <div class="cta-row" style="margin-top:14px">
                <button class="btn btn-primary" type="submit">Enregistrer les extensions</button>
              </div>
            </form>

            <?php /* --- Discord RPC advanced (marketplace-gated) --- */ ?>
            <div class="cat-head" style="margin-top:24px"><span class="cat-dot" style="background:#f472b6;box-shadow:0 0 0 4px rgba(244,114,182,.15)"></span>Configurations avancées (Premium)</div>

            <?php if ($advOwned = $owns('discord_rpc_advanced')):
              $da = is_array($marketplaceSettings['discord_advanced'] ?? null) ? $marketplaceSettings['discord_advanced'] : [];
              $btns = is_array($da['buttons'] ?? null) ? array_values($da['buttons']) : [];
              $btn0 = $btns[0] ?? ['label'=>'','url'=>''];
              $btn1 = $btns[1] ?? ['label'=>'','url'=>''];
            ?>
              <form class="sub-card" method="post" action="update_marketplace_settings.php" aria-label="Discord RPC avancé">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="sections[]" value="discord" />
                <input type="hidden" name="return_tab" value="extensions" />
                <div class="sub-card-head">
                  <div><h3>Discord Rich Presence — Pro</h3><p>Personnalise texte, détails et jusqu'à 2 boutons sur la présence Discord de tes joueurs.</p></div>
                  <span class="chip violet">Acquis</span>
                </div>
                <div class="two-col">
                  <label class="label"><span>client_id Discord</span>
                    <input class="input" type="text" name="discord_advanced[client_id]" value="<?php echo e((string)($da['client_id'] ?? '')); ?>" placeholder="123456789012345678" /></label>
                  <label class="label"><span>Détails (1re ligne)</span>
                    <input class="input" type="text" name="discord_advanced[details]" value="<?php echo e((string)($da['details'] ?? '')); ?>" placeholder="Joue à {launcher_name}" /></label>
                </div>
                <div class="two-col">
                  <label class="label"><span>État (2e ligne)</span>
                    <input class="input" type="text" name="discord_advanced[state]" value="<?php echo e((string)($da['state'] ?? '')); ?>" placeholder="En attente…" /></label>
                  <label class="label"><span>Bouton 1 — label</span>
                    <input class="input" type="text" name="discord_advanced[buttons][0][label]" value="<?php echo e((string)($btn0['label'] ?? '')); ?>" placeholder="Site web" /></label>
                </div>
                <div class="two-col">
                  <label class="label"><span>Bouton 1 — URL</span>
                    <input class="input" type="url" name="discord_advanced[buttons][0][url]" value="<?php echo e((string)($btn0['url'] ?? '')); ?>" placeholder="https://…" /></label>
                  <label class="label"><span>Bouton 2 — label</span>
                    <input class="input" type="text" name="discord_advanced[buttons][1][label]" value="<?php echo e((string)($btn1['label'] ?? '')); ?>" placeholder="Discord" /></label>
                </div>
                <label class="label"><span>Bouton 2 — URL</span>
                  <input class="input" type="url" name="discord_advanced[buttons][1][url]" value="<?php echo e((string)($btn1['url'] ?? '')); ?>" placeholder="https://discord.gg/…" /></label>
                <div class="cta-row" style="margin-top:6px"><button class="btn btn-primary" type="submit">Enregistrer Discord Pro</button></div>
              </form>
            <?php else: ?>
              <div class="sub-card is-locked" style="border-style:dashed">
                <div class="sub-card-head">
                  <div><h3>🔒 Discord Rich Presence — Pro</h3><p>Débloque le texte libre, le client_id personnalisé et 2 boutons CTA sur la présence Discord.</p></div>
                  <div class="lock-hint" style="margin:0">
                    <strong><?php echo e($priceFor('discord_rpc_advanced') ?: '10,00 EUR'); ?></strong>
                    <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                      <input type="hidden" name="item_key" value="discord_rpc_advanced" />
                      <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Débloquer</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($owns('anticheat_advanced')):
              $aa = is_array($marketplaceSettings['anticheat_advanced'] ?? null) ? $marketplaceSettings['anticheat_advanced'] : [];
              $blist = is_array($aa['process_blacklist'] ?? null) ? implode("\n", $aa['process_blacklist']) : '';
            ?>
              <form class="sub-card" method="post" action="update_marketplace_settings.php" aria-label="Anti-cheat avancé">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="sections[]" value="anticheat" />
                <input type="hidden" name="return_tab" value="extensions" />
                <div class="sub-card-head">
                  <div><h3>Anti-cheat — Pro</h3><p>Vérification SHA-256 du client + blacklist de processus scannés avant le lancement.</p></div>
                  <span class="chip violet">Acquis</span>
                </div>
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" name="anticheat_advanced[require_sha256]" value="1" <?php echo !empty($aa['require_sha256']) ? 'checked' : ''; ?> />
                  <span><strong style="color:#fff">Exiger l'intégrité SHA-256 du client</strong><br><span class="small">Bloque le lancement si la somme du .asar diffère.</span></span>
                </label>
                <label class="label"><span>Processus bloqués (un par ligne)</span>
                  <textarea class="input" name="anticheat_advanced[process_blacklist]" rows="4" placeholder="cheatclient.exe&#10;xray.exe"><?php echo e($blist); ?></textarea></label>
                <div class="cta-row" style="margin-top:6px"><button class="btn btn-primary" type="submit">Enregistrer Anti-cheat Pro</button></div>
              </form>
            <?php else: ?>
              <div class="sub-card is-locked" style="border-style:dashed">
                <div class="sub-card-head">
                  <div><h3>🔒 Anti-cheat — Pro</h3><p>Débloque la vérification d'intégrité SHA-256 et la blacklist de processus (Optifine cheat, X-ray, etc.).</p></div>
                  <div class="lock-hint" style="margin:0">
                    <strong><?php echo e($priceFor('anticheat_advanced') ?: '10,00 EUR'); ?></strong>
                    <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                      <input type="hidden" name="item_key" value="anticheat_advanced" />
                      <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Débloquer</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <!-- ============ TAB: Apparence ============ -->
        <section id="tab-apparence" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="apparence" <?php if ($activeTab !== 'apparence') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">🎨 Apparence &amp; contenu dynamique</h2>
            <p class="panel-desc">Couleurs du launcher, musique d'ambiance, popup, compte à rebours, mention « Powered by Xyno ». Tout est géré par la Marketplace — chaque bloc se débloque indépendamment.</p>
          </div>


          <!-- ============ THEME SELECTOR ============ -->
          <div class="sub-card">
            <div class="sub-card-head">
              <div><h3>🎨 Thème du Launcher</h3><p>Sélectionnez l'apparence visuelle de votre launcher. Cliquez pour prévisualiser.</p></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
              <?php
                // Get available themes
                $themesDir = __DIR__ . '/../launcher/themes';
                $themes = [];
                if (is_dir($themesDir)) {
                  foreach (scandir($themesDir) as $entry) {
                    if ($entry[0] === '.') continue;
                    $themePath = $themesDir . '/' . $entry;
                    if (!is_dir($themePath) || !file_exists($themePath . '/index.html')) continue;

                    // Theme colors for preview
                    $colors = [
                      'cosmic' => ['#1a1a2e', '#16213e', '#0f3460'],
                      'neon-frontier' => ['#0a0e27', '#1a1f3a', '#2a2f5a'],
                      'dark-tactical' => ['#1a1a1a', '#2d2d2d', '#404040'],
                      'mystic-purple' => ['#1a0f2e', '#2d1b4e', '#3d2b6e'],
                      'minecraft-forest' => ['#0a0e27', '#1a3a3a', '#0d1f2d'],
                      'default' => ['#0c0c14', '#1a1a2e', '#2a2a3e'],
                    ];

                    $themeColors = $colors[$entry] ?? ['#1a1a1a', '#2a2a2a', '#3a3a3a'];

                    $emoji = '🎮';
                    if (stripos($entry, 'cosmic') !== false) $emoji = '🌌';
                    if (stripos($entry, 'neon') !== false) $emoji = '⚡';
                    if (stripos($entry, 'tactical') !== false) $emoji = '🎯';
                    if (stripos($entry, 'mystic') !== false) $emoji = '🔮';
                    if (stripos($entry, 'minecraft') !== false) $emoji = '🌲';
                    if (stripos($entry, 'default') !== false) $emoji = '📦';

                    $themes[] = ['id' => $entry, 'name' => ucwords(str_replace('-', ' ', $entry)), 'emoji' => $emoji, 'colors' => $themeColors];
                  }
                  usort($themes, fn($a, $b) => strcmp($a['id'], $b['id']));
                }
                $currentTheme = (string)($selected['theme'] ?? 'default');
              ?>
              <?php foreach ($themes as $theme): ?>
                <form method="post" action="api/set_launcher_theme.php" style="margin: 0;" title="<?php echo e($theme['name']); ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                  <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                  <input type="hidden" name="theme_id" value="<?php echo e($theme['id']); ?>" />
                  <button type="submit" style="width: 100%; height: 160px; padding: 0; background: linear-gradient(135deg, <?php echo $theme['colors'][0]; ?> 0%, <?php echo $theme['colors'][1]; ?> 50%, <?php echo $theme['colors'][2]; ?> 100%); border: 3px solid <?php echo $theme['id'] === $currentTheme ? '#4caf50' : 'rgba(255, 255, 255, 0.1)'; ?>; border-radius: 8px; cursor: pointer; color: white; text-align: center; transition: all 0.3s ease; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;" onmouseover="this.style.borderColor='rgba(76, 175, 80, 0.8)'; this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 20px rgba(76, 175, 80, 0.3)'" onmouseout="this.style.borderColor='<?php echo $theme['id'] === $currentTheme ? '#4caf50' : 'rgba(255, 255, 255, 0.1)'; ?>'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <div style="font-size: 40px;"><?php echo $theme['emoji']; ?></div>
                    <div style="font-weight: 600; font-size: 13px;"><?php echo e($theme['name']); ?></div>
                    <?php if ($theme['id'] === $currentTheme): ?>
                      <div style="font-size: 11px; color: #4caf50; margin-top: 4px; padding: 2px 6px; background: rgba(76, 175, 80, 0.2); border-radius: 3px;">✓ Actif</div>
                    <?php endif; ?>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>

            <!-- Preview Section -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
              <h4 style="margin-bottom: 12px; color: #fff;">Aperçu complet du thème actuel</h4>
              <iframe src="<?php echo e('/' . (empty($currentTheme) || $currentTheme === 'default' ? 'launcher/themes/default' : 'launcher/themes/' . $currentTheme) . '/index.html'); ?>" style="width: 100%; height: 400px; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; background: #0c0c14;"></iframe>
            </div>
          </div>

          <?php if (!$marketplaceAvailable): ?>
            <div class="sub-card"><p class="small" style="margin:0">Les tables marketplace n'existent pas encore. Importe <code>migrations_v3.sql</code>.</p></div>
          <?php else: ?>
            <form class="form" method="post" action="update_marketplace_settings.php" aria-label="Paramètres apparence">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
              <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
              <input type="hidden" name="return_tab" value="apparence" />
              <input type="hidden" name="sections[]" value="copyright" />
              <input type="hidden" name="sections[]" value="colors" />
              <input type="hidden" name="sections[]" value="music" />
              <input type="hidden" name="sections[]" value="popup_promo" />
              <input type="hidden" name="sections[]" value="countdown" />

              <?php /* Copyright */ ?>
              <div class="sub-card <?php echo $owns('remove_copyright') ? '' : 'is-locked'; ?>">
                <div class="sub-card-head">
                  <div><h3>🧼 Retirer « Powered by XynoWeb »</h3><p>Cache le footer Xyno du launcher.</p></div>
                  <?php if ($owns('remove_copyright')): ?><span class="chip violet">Acquis</span><?php else: ?><span class="chip plain"><?php echo e($priceFor('remove_copyright')); ?></span><?php endif; ?>
                </div>
                <?php if ($owns('remove_copyright')): ?>
                  <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                    <input type="checkbox" name="hide_copyright" value="1" <?php echo !empty($marketplaceSettings['hide_copyright']) ? 'checked' : ''; ?> />
                    <span>Cacher le footer Xyno.</span>
                  </label>
                <?php else: ?>
                  <div class="lock-hint"><span>🔒 Débloque pour masquer la mention Xyno.</span>
                    <button class="btn btn-primary" type="button" <?php echo $stripeConfigured ? '' : 'disabled'; ?> onclick="_xyCheckout('remove_copyright')">Débloquer</button>
                  </div>
                <?php endif; ?>
              </div>

              <?php /* Colors */
                $colors = is_array($marketplaceSettings['colors'] ?? null) ? $marketplaceSettings['colors'] : [];
              ?>
              <div class="sub-card <?php echo $owns('colors_custom') ? '' : 'is-locked'; ?>">
                <div class="sub-card-head">
                  <div><h3>🎨 Palette personnalisée</h3><p>Primaire, accent, fond et surface. Laisse vide pour garder le thème par défaut.</p></div>
                  <?php if ($owns('colors_custom')): ?><span class="chip violet">Acquis</span><?php else: ?><span class="chip plain"><?php echo e($priceFor('colors_custom')); ?></span><?php endif; ?>
                </div>
                <?php if ($owns('colors_custom')): ?>
                  <div class="two-col">
                    <label class="label"><span>Primaire</span><input class="input" type="text" name="colors[primary]" value="<?php echo e((string)($colors['primary'] ?? '')); ?>" placeholder="#8b5cf6" /></label>
                    <label class="label"><span>Accent</span><input class="input" type="text" name="colors[accent]" value="<?php echo e((string)($colors['accent'] ?? '')); ?>" placeholder="#22d3ee" /></label>
                  </div>
                  <div class="two-col">
                    <label class="label"><span>Fond</span><input class="input" type="text" name="colors[bg]" value="<?php echo e((string)($colors['bg'] ?? '')); ?>" placeholder="#0b1020" /></label>
                    <label class="label"><span>Surface</span><input class="input" type="text" name="colors[surface]" value="<?php echo e((string)($colors['surface'] ?? '')); ?>" placeholder="#11162a" /></label>
                  </div>
                <?php else: ?>
                  <div class="lock-hint"><span>🔒 Débloque pour reskiner le launcher.</span>
                    <button class="btn btn-primary" type="button" <?php echo $stripeConfigured ? '' : 'disabled'; ?> onclick="_xyCheckout('colors_custom')">Débloquer</button>
                  </div>
                <?php endif; ?>
              </div>

              <?php /* Music */
                $mu = is_array($marketplaceSettings['music'] ?? null) ? $marketplaceSettings['music'] : [];
              ?>
              <div class="sub-card <?php echo $owns('music') ? '' : 'is-locked'; ?>">
                <div class="sub-card-head">
                  <div><h3>🎵 Musique d'ambiance</h3><p>Piste audio jouée en boucle à l'ouverture du launcher.</p></div>
                  <?php if ($owns('music')): ?><span class="chip violet">Acquis</span><?php else: ?><span class="chip plain"><?php echo e($priceFor('music')); ?></span><?php endif; ?>
                </div>
                <?php if ($owns('music')): ?>
                  <label class="label"><span>URL piste audio (mp3/ogg)</span>
                    <input class="input" type="url" name="music[url]" value="<?php echo e((string)($mu['url'] ?? '')); ?>" placeholder="https://cdn.ton-serveur.com/lobby.mp3" /></label>
                  <div class="two-col">
                    <label style="display:flex;gap:10px;align-items:center;padding-top:24px">
                      <input type="checkbox" name="music[loop]" value="1" <?php echo !empty($mu['loop']) ? 'checked' : ''; ?> />
                      <span>Lecture en boucle</span>
                    </label>
                    <label class="label"><span>Volume par défaut (0.0 – 1.0)</span>
                      <input class="input" type="number" min="0" max="1" step="0.05" name="music[volume]" value="<?php echo e((string)($mu['volume'] ?? '0.5')); ?>" /></label>
                  </div>
                <?php else: ?>
                  <div class="lock-hint"><span>🔒 Débloque pour jouer une piste d'ambiance.</span>
                    <button class="btn btn-primary" type="button" <?php echo $stripeConfigured ? '' : 'disabled'; ?> onclick="_xyCheckout('music')">Débloquer</button>
                  </div>
                <?php endif; ?>
              </div>

              <?php /* Popup promo */
                $pp = is_array($marketplaceSettings['popup_promo'] ?? null) ? $marketplaceSettings['popup_promo'] : [];
              ?>
              <div class="sub-card <?php echo $owns('popup_promo') ? '' : 'is-locked'; ?>">
                <div class="sub-card-head">
                  <div><h3>📣 Popup promo</h3><p>Message HTML modal affiché à l'ouverture jusqu'à une date donnée.</p></div>
                  <?php if ($owns('popup_promo')): ?><span class="chip violet">Acquis</span><?php else: ?><span class="chip plain"><?php echo e($priceFor('popup_promo')); ?></span><?php endif; ?>
                </div>
                <?php if ($owns('popup_promo')): ?>
                  <!-- Hidden field – populated by the visual editor JS before form submit -->
                  <input type="hidden" name="popup_promo[html]" id="ppHtmlField" value="<?php echo e((string)($pp['html'] ?? '')); ?>">

                  <!-- ── Text editor ── -->
                  <div class="label" style="gap:0">
                    <span style="margin-bottom:8px">Contenu du popup</span>
                    <!-- Toolbar -->
                    <div id="ppToolbar" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;padding:6px 8px;border:1px solid var(--border-2);border-radius:var(--radius-sm) var(--radius-sm) 0 0;background:var(--surface-2)">
                      <button type="button" class="btn btn-ghost" data-cmd="bold"        title="Gras (Ctrl+B)"      style="font-weight:700;padding:3px 9px;font-size:13px">B</button>
                      <button type="button" class="btn btn-ghost" data-cmd="italic"      title="Italique (Ctrl+I)"  style="font-style:italic;padding:3px 9px;font-size:13px">I</button>
                      <button type="button" class="btn btn-ghost" data-cmd="underline"   title="Souligner (Ctrl+U)" style="text-decoration:underline;padding:3px 9px;font-size:13px">U</button>
                      <div style="width:1px;height:20px;background:var(--border-2);margin:0 2px;align-self:center"></div>
                      <button type="button" class="btn btn-ghost" data-cmd="justifyLeft"   title="Aligner à gauche" style="padding:3px 9px;font-size:13px">⬅</button>
                      <button type="button" class="btn btn-ghost" data-cmd="justifyCenter" title="Centrer"          style="padding:3px 9px;font-size:14px">≡</button>
                      <button type="button" class="btn btn-ghost" data-cmd="justifyRight"  title="Aligner à droite" style="padding:3px 9px;font-size:13px">➡</button>
                      <div style="width:1px;height:20px;background:var(--border-2);margin:0 2px;align-self:center"></div>
                      <label title="Couleur du texte" style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted);cursor:pointer;margin:0">
                        <span style="font-weight:700;font-size:13px">A</span>
                        <input type="color" id="ppColor" value="#ffffff" style="width:24px;height:24px;padding:1px;border:1px solid var(--border-2);border-radius:5px;cursor:pointer;background:transparent">
                      </label>
                    </div>
                    <!-- Editable area -->
                    <div id="ppEditor" contenteditable="true"
                      style="min-height:80px;max-height:220px;overflow-y:auto;padding:10px 12px;border:1px solid var(--border-2);border-top:none;background:var(--bg-1);color:var(--text);font-size:13.5px;font-family:inherit;line-height:1.55;outline:none;border-radius:0 0 var(--radius-sm) var(--radius-sm)"
                      aria-label="Texte du popup"></div>
                  </div>

                  <!-- ── Images ── -->
                  <div class="label" style="margin-top:12px">
                    <span>Images</span>
                    <div id="ppImageList" style="display:grid;gap:8px"></div>
                    <button type="button" id="ppAddImg" class="btn btn-ghost" style="align-self:start;font-size:13px">+ Ajouter une image</button>
                    <p class="help" style="margin:0">1 image → affichée seule &nbsp;·&nbsp; 2+ images → slider horizontal (CSS scroll-snap)</p>
                  </div>

                  <!-- ── Preview ── -->
                  <div class="label" style="margin-top:12px">
                    <span>Aperçu</span>
                    <div id="ppPreview" style="border:1px solid var(--border-2);border-radius:var(--radius-sm);padding:14px 16px;background:#1a1a2e;min-height:50px;font-size:13px;color:#e0e0ff;font-family:sans-serif;max-height:260px;overflow:auto"></div>
                    <div id="ppCharCount" style="font-size:11px;color:var(--muted-2);text-align:right;margin-top:2px">0 / 2000 caractères</div>
                  </div>

                  <label class="label" style="margin-top:12px"><span>Valable jusqu'au (ISO 8601, ex. 2026-06-01T00:00:00Z)</span>
                    <input class="input" type="text" name="popup_promo[until]" value="<?php echo e((string)($pp['until'] ?? '')); ?>" placeholder="2026-06-01T00:00:00Z" /></label>

                  <script>
                  (function () {
                    'use strict';
                    var editor    = document.getElementById('ppEditor');
                    var imgList   = document.getElementById('ppImageList');
                    var preview   = document.getElementById('ppPreview');
                    var hidden    = document.getElementById('ppHtmlField');
                    var counter   = document.getElementById('ppCharCount');
                    var colorPick = document.getElementById('ppColor');

                    /* ── Toolbar buttons ── */
                    document.getElementById('ppToolbar').addEventListener('mousedown', function (e) {
                      var btn = e.target.closest('[data-cmd]');
                      if (!btn) return;
                      e.preventDefault();
                      document.execCommand(btn.dataset.cmd, false, null);
                      editor.focus();
                    });
                    colorPick.addEventListener('input', function () {
                      document.execCommand('foreColor', false, this.value);
                      editor.focus();
                    });

                    /* ── Image rows ── */
                    function addImageRow(url) {
                      var row = document.createElement('div');
                      row.style.cssText = 'display:flex;gap:8px;align-items:center';

                      var inp = document.createElement('input');
                      inp.type = 'text';
                      inp.placeholder = 'https://...';
                      inp.value = url || '';
                      inp.style.cssText = 'flex:1;padding:8px 11px;border:1px solid var(--border-2);border-radius:var(--radius-sm);background:var(--bg-1);color:var(--text);font-size:13px;font-family:inherit;outline:none;min-width:0';

                      var thumb = document.createElement('img');
                      thumb.alt = '';
                      thumb.style.cssText = 'width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--border-2);flex-shrink:0;display:' + ((url && url.trim()) ? 'block' : 'none');
                      if (url && url.trim()) thumb.src = url;
                      thumb.addEventListener('error', function () { this.style.display = 'none'; });
                      thumb.addEventListener('load',  function () { this.style.display = 'block'; });

                      var del = document.createElement('button');
                      del.type = 'button';
                      del.title = 'Supprimer';
                      del.className = 'btn btn-ghost';
                      del.textContent = '✕';
                      del.style.cssText = 'padding:5px 9px;font-size:14px;flex-shrink:0';

                      inp.addEventListener('input', function () {
                        var v = this.value.trim();
                        if (v) { thumb.src = v; } else { thumb.style.display = 'none'; }
                        updateAll();
                      });
                      del.addEventListener('click', function () {
                        row.remove();
                        updateAll();
                      });

                      row.appendChild(inp);
                      row.appendChild(thumb);
                      row.appendChild(del);
                      imgList.appendChild(row);
                    }

                    document.getElementById('ppAddImg').addEventListener('click', function () {
                      addImageRow('');
                      var inputs = imgList.querySelectorAll('input[type="text"]');
                      if (inputs.length) inputs[inputs.length - 1].focus();
                    });

                    /* ── HTML build ── */
                    function escAttr(s) {
                      return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    }

                    function getImages() {
                      var inputs = imgList.querySelectorAll('input[type="text"]');
                      return Array.prototype.filter.call(inputs, function (i) { return i.value.trim(); })
                                  .map(function (i) { return i.value.trim(); });
                    }

                    function buildHtml() {
                      var textRaw = editor.innerHTML
                        .replace(/<div><br\s*\/?><\/div>/gi, '<br>')
                        .replace(/<div>/gi, '<br>').replace(/<\/div>/gi, '')
                        .replace(/^<br\s*\/?>/i, '')
                        .trim();

                      var valid = getImages();
                      var parts = [];

                      if (valid.length === 1) {
                        parts.push('<img src="' + escAttr(valid[0]) + '" style="max-width:100%;display:block;border-radius:6px;margin:0 0 8px">');
                      } else if (valid.length > 1) {
                        var imgs = valid.map(function (u) {
                          return '<img src="' + escAttr(u) + '" style="min-width:100%;max-height:160px;object-fit:cover;scroll-snap-align:start;display:block">';
                        }).join('');
                        parts.push('<div style="display:flex;overflow-x:auto;scroll-snap-type:x mandatory;border-radius:6px;margin:0 0 8px">' + imgs + '</div>');
                      }

                      if (textRaw) parts.push(textRaw);
                      return parts.join('');
                    }

                    function updateAll() {
                      var html = buildHtml();
                      hidden.value = html;
                      preview.innerHTML = html;
                      var len = html.length;
                      counter.textContent = len + ' / 2000 caractères';
                      counter.style.color = len > 2000 ? '#c33' : (len > 1800 ? '#c47000' : '');
                    }

                    editor.addEventListener('input', updateAll);

                    /* ── Parse existing HTML on load ── */
                    (function parseExisting() {
                      var raw = hidden.value;
                      if (!raw) return;

                      // Extract img srcs
                      var srcRe = /<img[^>]+src="([^"]*)"/gi, m, srcs = [];
                      while ((m = srcRe.exec(raw)) !== null) {
                        var ta = document.createElement('textarea');
                        ta.innerHTML = m[1];
                        srcs.push(ta.value);
                      }

                      // Extract text: remove slider wrapper and standalone imgs
                      var text = raw
                        .replace(/<div[^>]*scroll-snap-type[^>]*>[\s\S]*?<\/div>/gi, '')
                        .replace(/<img[^>]*>/gi, '')
                        .trim();

                      if (text) editor.innerHTML = text;
                      srcs.forEach(function (s) { addImageRow(s); });
                      updateAll();
                    }());

                    /* ── Sync on submit (safety net) ── */
                    if (hidden.form) {
                      hidden.form.addEventListener('submit', function () {
                        hidden.value = buildHtml();
                      }, true);
                    }
                  }());
                  </script>
                <?php else: ?>
                  <div class="lock-hint"><span>🔒 Débloque pour pousser des annonces modales.</span>
                    <button class="btn btn-primary" type="button" <?php echo $stripeConfigured ? '' : 'disabled'; ?> onclick="_xyCheckout('popup_promo')">Débloquer</button>
                  </div>
                <?php endif; ?>
              </div>

              <?php /* Countdown */
                $cd = is_array($marketplaceSettings['countdown'] ?? null) ? $marketplaceSettings['countdown'] : [];
              ?>
              <div class="sub-card <?php echo $owns('countdown') ? '' : 'is-locked'; ?>">
                <div class="sub-card-head">
                  <div><h3>⏱ Compte à rebours</h3><p>Widget qui affiche le temps restant jusqu'à un event — se rafraîchit chaque seconde.</p></div>
                  <?php if ($owns('countdown')): ?><span class="chip violet">Acquis</span><?php else: ?><span class="chip plain"><?php echo e($priceFor('countdown')); ?></span><?php endif; ?>
                </div>
                <?php if ($owns('countdown')): ?>
                  <div class="two-col">
                    <label class="label"><span>Titre</span>
                      <input class="input" type="text" name="countdown[title]" value="<?php echo e((string)($cd['title'] ?? '')); ?>" placeholder="Event serveur" /></label>
                    <label class="label"><span>Date cible (ISO 8601)</span>
                      <input class="input" type="text" name="countdown[date]" value="<?php echo e((string)($cd['date'] ?? '')); ?>" placeholder="2026-06-01T20:00:00Z" /></label>
                  </div>
                <?php else: ?>
                  <div class="lock-hint"><span>🔒 Débloque pour afficher un countdown sur la page Play.</span>
                    <button class="btn btn-primary" type="button" <?php echo $stripeConfigured ? '' : 'disabled'; ?> onclick="_xyCheckout('countdown')">Débloquer</button>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($owns('remove_copyright') || $owns('colors_custom') || $owns('music') || $owns('popup_promo') || $owns('countdown')): ?>
                <div class="cta-row" style="margin-top:14px">
                  <button class="btn btn-primary" type="submit">Enregistrer Apparence</button>
                </div>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </section>

        <!-- ============ TAB: Auth ============ -->
        <section id="tab-auth" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="auth" <?php if ($activeTab !== 'auth') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">🔐 Authentification</h2>
            <p class="panel-desc">Microsoft (comptes premium Minecraft), API Bearer personnalisée, ou offline pour tes tests.</p>
          </div>

          <?php if (!$authAvailable): ?>
            <div class="sub-card"><p class="small" style="margin:0">La table <code>launcher_auth</code> n'existe pas encore. Importe <code>migrations_v3.sql</code>.</p></div>
          <?php else: $authMode = $launcherAuth['mode'] ?: 'microsoft'; ?>
            <form class="form sub-card" action="update_auth.php" method="post" aria-label="Configuration authentification">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
              <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />

              <div class="sub-card-head"><div><h3>Mode d'authentification</h3><p>Un seul mode actif par launcher.</p></div></div>
              <div style="display:grid;gap:12px">
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="radio" name="mode" value="microsoft" <?php echo $authMode === 'microsoft' ? 'checked' : ''; ?> />
                  <span><strong style="color:#fff">Microsoft <span class="chip ok plain">Recommandé</span></strong><br><span class="small">OAuth Microsoft standard — compatible comptes premium. Aucun paramétrage requis.</span></span>
                </label>
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="radio" name="mode" value="custom" <?php echo $authMode === 'custom' ? 'checked' : ''; ?> />
                  <span><strong style="color:#fff">API Bearer personnalisée</strong><br><span class="small">Ton serveur gère l'auth. Le launcher envoie <code>email + password</code> à ton API qui renvoie un Bearer.</span></span>
                </label>
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="radio" name="mode" value="offline" <?php echo $authMode === 'offline' ? 'checked' : ''; ?> />
                  <span><strong style="color:#fff">Offline <span class="chip warn plain">Dev uniquement</span></strong><br><span class="small">Pseudo libre côté client, aucune vérification. À ne pas utiliser en production.</span></span>
                </label>
              </div>

              <div data-auth-custom style="display:grid;gap:12px;margin-top:10px;padding-top:14px;border-top:1px dashed var(--border-1)">
                <div class="sub-card-head"><div><h3>Endpoints de ton API (mode Bearer)</h3><p>Seulement utilisés si le mode « API Bearer » est sélectionné.</p></div></div>
                <div class="two-col">
                  <label class="label"><span>URL de login (POST email+password → token)</span>
                    <input class="input" type="url" name="login_url" placeholder="https://api.ton-serveur.com/auth/login" value="<?php echo e($launcherAuth['login_url']); ?>" /></label>
                  <label class="label"><span>URL de vérification du token (GET, Bearer)</span>
                    <input class="input" type="url" name="verify_url" placeholder="https://api.ton-serveur.com/auth/me" value="<?php echo e($launcherAuth['verify_url']); ?>" /></label>
                </div>
                <div class="two-col">
                  <label class="label"><span>URL de refresh (optionnel)</span>
                    <input class="input" type="url" name="refresh_url" placeholder="https://api.ton-serveur.com/auth/refresh" value="<?php echo e($launcherAuth['refresh_url']); ?>" /></label>
                  <label class="label"><span>Clé API partagée (X-Api-Key, optionnel)</span>
                    <input class="input" type="text" name="api_key" placeholder="clé privée" value="<?php echo e($launcherAuth['api_key']); ?>" autocomplete="off" /></label>
                </div>
                <p class="small" style="color:var(--muted);margin:0"><code>POST {login_url}</code> avec <code>{"email":"…","password":"…"}</code> → <code>{"token":"…","uuid":"…","username":"…"}</code>. <code>GET {verify_url}</code> avec <code>Authorization: Bearer {token}</code> → <code>200 OK</code>.</p>
              </div>

              <div class="cta-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Enregistrer l'authentification</button></div>
            </form>
          <?php endif; ?>

          <?php /* Multi-account (marketplace) */ ?>
          <?php if ($marketplaceAvailable): ?>
            <?php if ($owns('multi_account')): ?>
              <form class="sub-card" method="post" action="update_marketplace_settings.php" aria-label="Multi-comptes">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="sections[]" value="multi_account" />
                <input type="hidden" name="return_tab" value="auth" />
                <div class="sub-card-head">
                  <div><h3>👥 Multi-comptes Microsoft</h3><p>Laisse les joueurs jongler entre plusieurs comptes Microsoft via un picker au démarrage.</p></div>
                  <span class="chip violet">Acquis</span>
                </div>
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" name="multi_account[enabled]" value="1" <?php echo !empty(($marketplaceSettings['multi_account'] ?? [])['enabled']) ? 'checked' : ''; ?> />
                  <span>Activer le picker multi-comptes au démarrage.</span>
                </label>
                <div class="cta-row" style="margin-top:6px"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
              </form>
            <?php else: ?>
              <div class="sub-card is-locked" style="border-style:dashed">
                <div class="sub-card-head">
                  <div><h3>🔒 Multi-comptes Microsoft</h3><p>Plusieurs comptes Microsoft dans le même launcher, sélection au démarrage.</p></div>
                  <div class="lock-hint" style="margin:0">
                    <strong><?php echo e($priceFor('multi_account') ?: '10,00 EUR'); ?></strong>
                    <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                      <input type="hidden" name="item_key" value="multi_account" />
                      <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Débloquer</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <!-- ============ TAB: Versions ============ -->
        <section id="tab-versions" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="versions" <?php if ($activeTab !== 'versions') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">📦 Versions &amp; Builds</h2>
            <p class="panel-desc">Publie un état figé des fichiers (le manifest servi au client) et génère les installers via GitHub Actions.</p>
          </div>

          <div class="sub-card">
            <div class="sub-card-head"><div><h3>Générer un installer</h3><p>Le build tourne dans GitHub Actions et remonte sur ce VPS. Suis l'avancée en temps réel.</p></div></div>
            <div class="cta-row" style="margin:0">
              <button class="btn btn-primary" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'mac', event)">Générer macOS</button>
              <button class="btn" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'windows', event)">Générer Windows</button>
              <button class="btn" type="button" onclick="triggerLauncherBuild('<?php echo e((string)$selected['uuid']); ?>', 'linux', event)">Générer Linux</button>
            </div>

            <div id="build-progress" class="build-progress" data-uuid="<?php echo e((string)$selected['uuid']); ?>" hidden style="margin-top:14px">
              <div class="nav-row" style="align-items:center;gap:10px;margin-bottom:10px">
                <span class="chip violet" id="build-progress-title">Build en cours</span>
                <span class="small" id="build-progress-version" style="color:var(--muted)"></span>
                <span class="small" id="build-progress-elapsed" style="color:var(--muted-2)"></span>
                <a id="build-progress-runlink" class="small" href="#" target="_blank" rel="noopener" style="margin-left:auto;display:none">Voir sur GitHub →</a>
              </div>
              <div id="build-progress-list" style="display:grid;gap:8px"></div>
            </div>
          </div>

          <div class="sub-card">
            <div class="sub-card-head"><div><h3>Historique des versions</h3><p>Publie une nouvelle version pour figer l'état actuel du manifest et la servir aux clients.</p></div></div>
            <?php if (!$versionsAvailable): ?>
              <p class="small" style="margin:0">Le versioning n'est pas disponible (table <code>launcher_versions</code> absente). Importe <code>migrations_api.sql</code>.</p>
            <?php else: ?>
              <form action="publish_version.php" method="post" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <button class="btn btn-primary" type="submit">+ Publier une nouvelle version</button>
              </form>

              <?php if (!count($versions)): ?>
                <p class="small" style="margin:0">Aucune version publiée pour l'instant.</p>
              <?php else: ?>
                <div style="display:grid;gap:10px">
                  <?php foreach ($versions as $ver): $active = (int)($ver['is_active'] ?? 0) === 1; ?>
                    <div class="item-row <?php echo $active ? 'is-owned' : ''; ?>">
                      <div>
                        <div class="head">
                          <h4><?php echo e((string)($ver['version_name'] ?? '')); ?></h4>
                          <?php if ($active): ?><span class="chip ok">Active</span><?php else: ?><span class="chip muted">Historique</span><?php endif; ?>
                        </div>
                        <p>Publié le <?php echo e((string)($ver['created_at'] ?? '')); ?></p>
                      </div>
                      <div class="right">
                        <?php if (!$active): ?>
                          <form action="activate_version.php" method="post" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                            <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                            <input type="hidden" name="version_id" value="<?php echo e((string)($ver['id'] ?? '')); ?>" />
                            <button class="btn" type="submit">Activer</button>
                          </form>
                        <?php else: ?>
                          <span class="chip plain">En cours</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <style>
            .build-progress .bp-row{
              display:grid;grid-template-columns:88px 1fr 140px;gap:12px;align-items:center;
              padding:10px 12px;background:rgba(255,255,255,.03);border-radius:10px;
            }
            .build-progress .bp-label{font-weight:600}
            .build-progress .bp-bar{ height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;position:relative }
            .build-progress .bp-fill{
              position:absolute;inset:0;border-radius:999px;
              background:linear-gradient(90deg,rgba(124,58,237,.85),rgba(34,211,238,.85));
              width:40%; animation:bpPulse 1.6s ease-in-out infinite;
            }
            .build-progress .bp-row[data-state="success"] .bp-fill{ animation:none;width:100%;background:linear-gradient(90deg,#10b981,#34d399) }
            .build-progress .bp-row[data-state="failure"] .bp-fill,
            .build-progress .bp-row[data-state="cancelled"] .bp-fill{ animation:none;width:100%;background:linear-gradient(90deg,#ef4444,#f87171) }
            .build-progress .bp-row[data-state="skipped"] .bp-fill{ animation:none;width:100%;background:rgba(255,255,255,.18) }
            .build-progress .bp-state{ text-align:right;font-size:13px;color:var(--muted);font-variant-numeric:tabular-nums }
            .build-progress .bp-row[data-state="success"] .bp-state{color:#34d399}
            .build-progress .bp-row[data-state="failure"] .bp-state,
            .build-progress .bp-row[data-state="cancelled"] .bp-state{color:#f87171}
            @keyframes bpPulse{
              0%{transform:translateX(-60%);width:40%}
              50%{transform:translateX(40%);width:60%}
              100%{transform:translateX(120%);width:40%}
            }
          </style>
        </section>

        <!-- ============ TAB: Monitoring ============ -->
        <section id="tab-monitoring" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="monitoring" <?php if ($activeTab !== 'monitoring') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">📈 Monitoring &amp; sécurité</h2>
            <p class="panel-desc">Logs remontés par le launcher, limites anti-abus, et deux modules de sécurité premium (protection des fichiers, API REST publique).</p>
          </div>

          <div class="sub-card">
            <div class="sub-card-head"><div><h3>Limites anti-abus</h3><p>Compteurs 1 h / 24 h. Passé le plafond, l'API renvoie 429.</p></div></div>
            <?php if (!$abuse['available']): ?>
              <p class="small" style="margin:0">Les compteurs détaillés s'activent dès que les tables <code>launcher_downloads_log</code> et <code>launcher_builds_log</code> sont importées.</p>
            <?php else:
              $pctDlHour   = $abuse['limit_dl_hour']   > 0 ? min(100, ($abuse['dl_hour']   / $abuse['limit_dl_hour'])   * 100) : 0;
              $pctDlDay    = $abuse['limit_dl_day']    > 0 ? min(100, ($abuse['dl_day']    / $abuse['limit_dl_day'])    * 100) : 0;
              $pctBuildDay = $abuse['limit_build_day'] > 0 ? min(100, ($abuse['build_day'] / $abuse['limit_build_day']) * 100) : 0;
              $clsFn = fn (float $p): string => $p >= 90 ? 'danger' : ($p >= 70 ? 'warn' : '');
            ?>
              <div style="display:grid;gap:14px">
                <div class="meter">
                  <div class="meter-head"><strong>Téléchargements · dernière heure</strong><span class="meter-val"><?php echo (int)$abuse['dl_hour'] . ' / ' . (int)$abuse['limit_dl_hour']; ?></span></div>
                  <div class="meter-bar"><div class="meter-fill <?php echo $clsFn($pctDlHour); ?>" style="width:<?php echo number_format($pctDlHour, 1); ?>%"></div></div>
                </div>
                <div class="meter">
                  <div class="meter-head"><strong>Téléchargements · 24 h</strong><span class="meter-val"><?php echo (int)$abuse['dl_day'] . ' / ' . (int)$abuse['limit_dl_day']; ?></span></div>
                  <div class="meter-bar"><div class="meter-fill <?php echo $clsFn($pctDlDay); ?>" style="width:<?php echo number_format($pctDlDay, 1); ?>%"></div></div>
                </div>
                <div class="meter">
                  <div class="meter-head"><strong>Builds · 24 h</strong><span class="meter-val"><?php echo (int)$abuse['build_day'] . ' / ' . (int)$abuse['limit_build_day']; ?></span></div>
                  <div class="meter-bar"><div class="meter-fill <?php echo $clsFn($pctBuildDay); ?>" style="width:<?php echo number_format($pctBuildDay, 1); ?>%"></div></div>
                </div>
              </div>
              <div class="panel-grid cols-3" style="margin-top:14px">
                <div><span class="chip plain">Rate-limit IP</span><p class="small" style="margin-top:6px;color:var(--muted)">Max 60 req/min, puis 429.</p></div>
                <div><span class="chip plain">HMAC signé</span><p class="small" style="margin-top:6px;color:var(--muted)">Requêtes signées, anti-replay 5 min.</p></div>
                <div><span class="chip plain">Builds bornés</span><p class="small" style="margin-top:6px;color:var(--muted)">20 builds / 24 h / launcher.</p></div>
              </div>
            <?php endif; ?>
          </div>

          <div class="sub-card">
            <div class="sub-card-head"><div><h3>Logs du launcher</h3><p>Les 50 derniers événements (crashs, erreurs, infos) remontés par les installations chez les joueurs.</p></div></div>
            <?php if (!$launcherLogsAvailable): ?>
              <p class="small" style="margin:0">Les logs ne sont pas encore activés (table <code>launcher_logs</code> absente).</p>
            <?php elseif (!count($launcherLogs)): ?>
              <p class="small" style="margin:0">Aucun événement pour l'instant. Les logs apparaissent ici dès qu'un joueur lance le launcher.</p>
            <?php else: ?>
              <div class="log-list">
                <?php foreach ($launcherLogs as $row): $lvl = strtolower((string)($row['level'] ?? 'info')); ?>
                  <div class="log-row">
                    <span class="log-ts"><?php echo e((string)($row['created_at'] ?? '')); ?></span>
                    <span class="log-lvl <?php echo e($lvl); ?>"><?php echo e($lvl); ?></span>
                    <span class="log-src"><?php echo e((string)($row['source'] ?? '—')); ?></span>
                    <span class="log-msg"><?php echo e((string)($row['message'] ?? '')); ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="small" style="margin:10px 0 0;color:var(--muted)">Rotation auto tous les 30 jours.</p>
            <?php endif; ?>
          </div>

          <?php if ($marketplaceAvailable): ?>
            <?php /* file_protection */ ?>
            <?php if ($owns('file_protection')): ?>
              <form class="sub-card" method="post" action="update_marketplace_settings.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="sections[]" value="file_protection" />
                <input type="hidden" name="return_tab" value="monitoring" />
                <div class="sub-card-head">
                  <div><h3>🛡 Protection des fichiers</h3><p>Obfuscation XOR des payloads téléchargés côté client.</p></div>
                  <span class="chip violet">Acquis</span>
                </div>
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" name="file_protection[enabled]" value="1" <?php echo !empty(($marketplaceSettings['file_protection'] ?? [])['enabled']) ? 'checked' : ''; ?> />
                  <span>Activer l'obfuscation XOR.</span>
                </label>
                <div class="cta-row" style="margin-top:6px"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
              </form>
            <?php else: ?>
              <div class="sub-card is-locked" style="border-style:dashed">
                <div class="sub-card-head">
                  <div><h3>🔒 Protection des fichiers</h3><p>Obfuscation XOR sur les payloads téléchargés pour décourager le reverse engineering.</p></div>
                  <div class="lock-hint" style="margin:0">
                    <strong><?php echo e($priceFor('file_protection') ?: '10,00 EUR'); ?></strong>
                    <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                      <input type="hidden" name="item_key" value="file_protection" />
                      <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Débloquer</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php /* rest_api */ ?>
            <?php if ($owns('rest_api')): ?>
              <form class="sub-card" method="post" action="update_marketplace_settings.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="sections[]" value="rest_api" />
                <input type="hidden" name="return_tab" value="monitoring" />
                <div class="sub-card-head">
                  <div><h3>🌐 API REST publique</h3><p>Expose les endpoints Xyno signés (lecture stats, liste joueurs, etc.).</p></div>
                  <span class="chip violet">Acquis</span>
                </div>
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" name="rest_api[enabled]" value="1" <?php echo !empty(($marketplaceSettings['rest_api'] ?? [])['enabled']) ? 'checked' : ''; ?> />
                  <span>Activer l'API REST publique.</span>
                </label>
                <div class="cta-row" style="margin-top:6px"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
              </form>
            <?php else: ?>
              <div class="sub-card is-locked" style="border-style:dashed">
                <div class="sub-card-head">
                  <div><h3>🔒 API REST publique</h3><p>Des endpoints publics signés pour brancher ton site à Xyno (stats, joueurs en ligne…).</p></div>
                  <div class="lock-hint" style="margin:0">
                    <strong><?php echo e($priceFor('rest_api') ?: '10,00 EUR'); ?></strong>
                    <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                      <input type="hidden" name="item_key" value="rest_api" />
                      <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Débloquer</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php /* shop URL */
              $sh = is_array($marketplaceSettings['shop'] ?? null) ? $marketplaceSettings['shop'] : [];
            ?>
            <?php if ($owns('shop')): ?>
              <form class="sub-card" method="post" action="update_marketplace_settings.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                <input type="hidden" name="sections[]" value="shop" />
                <input type="hidden" name="return_tab" value="monitoring" />
                <div class="sub-card-head">
                  <div><h3>🛍 Bouton « Boutique »</h3><p>URL vers ta boutique (Tebex, Tipeee, Stripe Payment Link). Affiche un bouton « Boutique » sur la page Play.</p></div>
                  <span class="chip violet">Acquis</span>
                </div>
                <label class="label"><span>URL boutique</span>
                  <input class="input" type="url" name="shop[url]" value="<?php echo e((string)($sh['url'] ?? '')); ?>" placeholder="https://boutique.ton-serveur.com" /></label>
                <div class="cta-row" style="margin-top:6px"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
              </form>
            <?php else: ?>
              <div class="sub-card is-locked" style="border-style:dashed">
                <div class="sub-card-head">
                  <div><h3>🔒 Bouton « Boutique »</h3><p>Ajoute un bouton Boutique sur la page Play du launcher pointant vers ton URL de paiement.</p></div>
                  <div class="lock-hint" style="margin:0">
                    <strong><?php echo e($priceFor('shop') ?: '10,00 EUR'); ?></strong>
                    <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                      <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                      <input type="hidden" name="item_key" value="shop" />
                      <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Débloquer</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <details class="sub-card" style="margin-top:18px">
            <summary style="cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:10px">
              <div><h3 style="margin:0">🗃 Migration SQL (avancé)</h3><p class="small" style="margin:4px 0 0;color:var(--muted)">Script idempotent à rejouer quand Xyno pousse de nouvelles tables.</p></div>
              <span class="chip plain">Afficher</span>
            </summary>
            <div style="margin-top:10px">
              <div class="nav-row" style="align-items:center;margin:0;padding:0;border:0;gap:10px">
                <span class="chip violet">migrations_v3.sql</span>
                <div class="cta-row" style="margin:0">
                  <button class="btn" type="button" onclick="copySqlV3(this)">Copier</button>
                  <a class="btn btn-primary" href="migrations_v3.sql" download>Télécharger</a>
                </div>
              </div>
              <pre id="sql-v3" style="margin-top:14px;padding:14px;background:#0b0b14;border:1px solid var(--border-1);border-radius:12px;overflow:auto;max-height:420px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.55;color:#e2e8f0"><?php
                $sqlPath = __DIR__ . '/../migrations_v3.sql';
                $sqlBody = is_readable($sqlPath) ? (string)file_get_contents($sqlPath) : "-- Fichier migrations_v3.sql introuvable côté serveur.";
                echo e($sqlBody);
              ?></pre>
            </div>
          </details>
        </section>

        <!-- ============ TAB: Marketplace ============ -->
        <section id="tab-marketplace" class="tab-panel panel <?php echo $paywallLocked ? 'paywall-overlay' : ''; ?>" data-tab-panel="marketplace" <?php if ($activeTab !== 'marketplace') echo 'hidden'; ?>>
          <div class="panel-intro">
            <h2 class="panel-title">🛒 Marketplace</h2>
            <p class="panel-desc">Extensions premium achetables à l'unité, <strong>par launcher</strong>. Un achat débloque la feature à vie pour ce launcher uniquement. Paiement unique via Stripe Checkout.</p>
          </div>

          <?php if (!$marketplaceAvailable): ?>
            <div class="sub-card"><p class="small" style="margin:0">Les tables marketplace n'existent pas encore. Importe <code>migrations_v3.sql</code> depuis l'onglet Monitoring.</p></div>
          <?php else: ?>
            <?php if (!$stripeConfigured): ?>
              <div class="sub-card" style="border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.05)">
                <div class="sub-card-head">
                  <div><h3 style="color:#ffd38b">Stripe non configuré</h3><p>Définis <code>STRIPE_SECRET_KEY</code> et <code>STRIPE_WEBHOOK_SECRET</code> dans <code>config/.env.local</code> pour activer les paiements. Voir <code>config/.env.local.example</code>.</p></div>
                </div>
              </div>
            <?php endif; ?>

            <?php
              $mpByCat = [];
              foreach ($marketplaceCatalog as $item) { $mpByCat[$item['category']][] = $item; }
            ?>

            <?php foreach ($mpByCat as $cat => $items): ?>
              <div class="cat-head" style="margin-top:18px"><span class="cat-dot"></span><?php echo e((string)($items[0]['category_label'] ?? ucfirst((string)$cat))); ?></div>
              <div class="shop-grid">
                <?php foreach ($items as $item):
                  $owned = isset($marketplaceOwnedSet[$item['key']]);
                  $price = number_format(((int)$item['price_cents']) / 100, 2, ',', ' ') . ' ' . strtoupper((string)$item['currency']);
                ?>
                  <div class="shop-card <?php echo $owned ? 'is-owned' : ''; ?>">
                    <div class="title-row">
                      <h4><?php echo e((string)$item['name']); ?></h4>
                      <?php if ($owned): ?><span class="chip ok">Acquis</span><?php else: ?><span class="chip violet"><?php echo e($price); ?></span><?php endif; ?>
                    </div>
                    <p><?php echo e((string)$item['description']); ?></p>
                    <div class="foot">
                      <?php if ($owned): ?>
                        <span class="small" style="color:var(--muted)">Paiement unique reçu ✓</span>
                      <?php else: ?>
                        <span class="price"><?php echo e($price); ?></span>
                        <form method="post" action="api/marketplace_checkout.php" style="margin:0">
                          <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                          <input type="hidden" name="launcher_uuid" value="<?php echo e((string)$selected['uuid']); ?>" />
                          <input type="hidden" name="item_key" value="<?php echo e((string)$item['key']); ?>" />
                          <button class="btn btn-primary" type="submit" <?php echo $stripeConfigured ? '' : 'disabled'; ?>>Acheter</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>

            <div class="sub-card" style="margin-top:22px">
              <div class="sub-card-head"><div><h3>Où configurer les features achetées ?</h3><p>Chaque achat ouvre automatiquement son bloc de réglage dans l'onglet adéquat.</p></div></div>
              <div class="panel-grid cols-2">
                <div><span class="chip plain">🎨 Apparence</span><p class="small" style="margin-top:6px;color:var(--muted)">Couleurs, musique, popup, countdown, masquage du footer Xyno.</p></div>
                <div><span class="chip plain">🧩 Extensions</span><p class="small" style="margin-top:6px;color:var(--muted)">Configurations avancées Discord RPC + Anti-cheat.</p></div>
                <div><span class="chip plain">🔐 Authentification</span><p class="small" style="margin-top:6px;color:var(--muted)">Multi-comptes Microsoft.</p></div>
                <div><span class="chip plain">📈 Monitoring</span><p class="small" style="margin-top:6px;color:var(--muted)">Protection fichiers, API REST, bouton Boutique.</p></div>
              </div>
            </div>
          <?php endif; ?>
        </section>

      <?php endif; /* end level 2 */ ?>

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
        <p class="small"><a href="../account/settings.php">Paramètres</a></p>
        <p class="small"><a href="logout.php">Déconnexion</a></p>
      </div>
      <div>
        <h4>Légal</h4>
        <p class="small"><a href="../mentions-legales.php">Mentions légales</a></p>
        <p class="small"><a href="../politique-confidentialite.php">Confidentialité</a></p>
        <p class="small"><a href="../politique-cookies.php">Cookies</a></p>
        <p class="small"><a href="../cgu.php">CGU</a></p>
        <p class="small"><a href="../cgv.php">CGV</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());

    // ---------- Marketplace checkout (évite les formulaires imbriqués) ----------
    var _xyCsrf = '<?php echo addslashes(e($csrf)); ?>';
    var _xyLauncherUuid = '<?php echo addslashes(e((string)($selected['uuid'] ?? ''))); ?>';
    function _xyCheckout(itemKey) {
      var f = document.createElement('form');
      f.method = 'POST';
      f.action = 'api/marketplace_checkout.php';
      [['csrf_token', _xyCsrf], ['launcher_uuid', _xyLauncherUuid], ['item_key', itemKey]].forEach(function(pair) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = pair[0]; i.value = pair[1];
        f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
    }

    // ---------- Tab switching (hash + query-string sync) ----------
    (function () {
      const links  = Array.from(document.querySelectorAll('[data-tab-link]'));
      const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
      if (!links.length || !panels.length) return;

      function activate(tab) {
        let any = false;
        panels.forEach(p => {
          const match = p.dataset.tabPanel === tab;
          if (match) any = true;
          p.hidden = !match;
        });
        links.forEach(a => {
          const active = a.dataset.tabLink === tab;
          a.classList.toggle('is-active', active);
          a.setAttribute('aria-current', active ? 'page' : 'false');
        });
        return any;
      }

      links.forEach(a => {
        a.addEventListener('click', function (e) {
          const tab = this.dataset.tabLink;
          if (!tab) return;
          e.preventDefault();
          activate(tab);
          // Keep URL in sync without a full reload so forms remember their tab.
          const u = new URL(window.location.href);
          u.searchParams.set('tab', tab);
          u.hash = 'tab-' + tab;
          window.history.replaceState(null, '', u.toString());
        });
      });

      // If the URL hash points at a tab (e.g. #tab-marketplace) on landing,
      // honour it — otherwise the server-side active tab stays selected.
      if (location.hash && location.hash.indexOf('#tab-') === 0) {
        activate(location.hash.slice(5));
      }
    })();

    // ---------- Auth custom mode toggle ----------
    (function () {
      const radios = document.querySelectorAll('input[name="mode"]');
      const custom = document.querySelector('[data-auth-custom]');
      if (!radios.length || !custom) return;
      function refresh() {
        const sel = document.querySelector('input[name="mode"]:checked');
        if (!sel) return;
        custom.style.opacity = sel.value === 'custom' ? '1' : '.5';
        custom.style.pointerEvents = sel.value === 'custom' ? 'auto' : 'none';
      }
      radios.forEach(r => r.addEventListener('change', refresh));
      refresh();
    })();

    // ---------- SQL migration copy ----------
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

    // ---------- Build trigger + live progress polling ----------
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
      if (btn) { btn.innerText = 'Démarrage…'; btn.disabled = true; }
      try {
        const response = await fetch('/api/trigger_build.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ uuid: uuid, targets: [os] })
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
          alert('Erreur : ' + (result.error || ('HTTP ' + response.status)));
          return;
        }
        startBuildProgress(uuid, result.version || '', [osToShort(os)]);
      } catch (e) {
        alert('Erreur de connexion au serveur : ' + e.message);
      } finally {
        if (btn) { btn.innerText = originalText; btn.disabled = false; }
      }
    }

    function osToShort(os) { return os === 'windows' ? 'win' : os; }

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

      const list = document.getElementById('build-progress-list');
      list.innerHTML = '';
      for (const p of targetsShort) { list.appendChild(renderRow(p, 'queued')); }

      buildStartTs = Date.now();
      tickElapsed();
      if (buildPoller) clearInterval(buildPoller);
      buildPoller = setInterval(() => pollBuildStatus(uuid, version), 3000);
      pollBuildStatus(uuid, version);
    }

    function renderRow(platform, state) {
      const row = document.createElement('div');
      row.className = 'bp-row';
      row.dataset.platform = platform;
      row.dataset.state = state;
      row.innerHTML = '<div class="bp-label">' + (PLATFORM_LABELS[platform] || platform) +
        '</div><div class="bp-bar"><div class="bp-fill"></div></div>' +
        '<div class="bp-state">' + (STATE_LABELS[state] || state) + '</div>';
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
          credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        if (!r.ok) return;
        const data = await r.json();
        if (data.run_url) {
          const link = document.getElementById('build-progress-runlink');
          link.href = data.run_url;
          link.style.display = '';
        }
        const per = data.per_platform || {};
        for (const [plat, state] of Object.entries(per)) { setRowState(plat, state); }
        const global = data.global || 'queued';
        if (TERMINAL_GLOBAL.has(global)) {
          clearInterval(buildPoller);
          buildPoller = null;
          const title = document.getElementById('build-progress-title');
          if (global === 'success') title.textContent = 'Build terminé ✓';
          else if (global === 'failure') title.textContent = 'Build échoué ✗';
          else if (global === 'cancelled') title.textContent = 'Build annulé';
          else title.textContent = 'Build terminé (partiel)';
          setTimeout(() => {
            if (global === 'success' || global === 'partial') location.reload();
          }, 1500);
        }
      } catch (_) { /* silent */ }
    }

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
        if (TERMINAL_GLOBAL.has(data.global)) return;
        startBuildProgress(uuid, data.version, targets);
      }).catch(() => {});
    })();
  </script>
</body>
</html>

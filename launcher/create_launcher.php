<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/subscription_helpers.php';

$user = require_login();

if (!is_post()) {
    redirect('/builder.php');
}

$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$version = trim((string)($_POST['version'] ?? ''));
$loader = trim((string)($_POST['loader'] ?? ''));
$theme = trim((string)($_POST['theme'] ?? ''));
$modulesRaw = trim((string)($_POST['modules'] ?? ''));
$promo = trim((string)($_POST['promo'] ?? ''));
$plan = subscription_normalize_plan((string)($_POST['plan'] ?? ''));
$period = subscription_normalize_period((string)($_POST['period'] ?? ''));

if ($name === '' || $version === '' || $loader === '' || $theme === '') {
    flash_set('error', 'Champs manquants : nom, version, loader et thème sont requis.');
    redirect('/builder.php');
}

$allowedLoaders = ['fabric', 'forge', 'quilt'];
if (!in_array(strtolower($loader), $allowedLoaders, true)) {
    flash_set('error', 'Loader invalide.');
    redirect('/builder.php');
}

$uuid = uuid_v4();

// API key (secret) used by Electron launcher to call /api/*.php
$apiKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

$knownModules = ['modpack', 'news', 'discord', 'autoupdate', 'analytics'];
$modules = [];
if ($modulesRaw !== '') {
    foreach (explode(',', $modulesRaw) as $m) {
        $m = strtolower(trim($m));
        if ($m !== '' && in_array($m, $knownModules, true)) {
            $modules[$m] = true;
        }
    }
}
$modulesCsv = implode(',', array_keys($modules));

try {
    $pdo = db();

    try {
        $stmt = $pdo->prepare('INSERT INTO launchers (user_id, uuid, api_key, name, description, version, loader, theme, modules, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $user['id'],
            $uuid,
            $apiKey,
            $name,
            $description,
            $version,
            strtolower($loader),
            $theme,
            $modulesCsv,
        ]);
    } catch (PDOException $e2) {
        $raw2 = $e2->getMessage();
        if (stripos($raw2, 'unknown column') !== false && stripos($raw2, 'modules') !== false) {
            // Backward compatible insert.
            $stmt = $pdo->prepare('INSERT INTO launchers (user_id, uuid, api_key, name, description, version, loader, theme, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $user['id'],
                $uuid,
                $apiKey,
                $name,
                $description,
                $version,
                strtolower($loader),
                $theme,
            ]);
        } else {
            throw $e2;
        }
    }

    $launcherId = (int)$pdo->lastInsertId();
    $launcherUuidNew = $uuid;

    // While you are not selling yet, FREE100 grants an active subscription.
    if (strtoupper($promo) === 'FREE100') {
        try {
            $sub = $pdo->prepare("INSERT INTO subscriptions (user_id, launcher_id, status, expires_at, created_at) VALUES (?, ?, 'active', DATE_ADD(NOW(), INTERVAL 10 YEAR), NOW())");
            $sub->execute([$user['id'], $launcherId]);
        } catch (Throwable $e) {
            // Ignore if subscriptions table isn't installed yet.
        }
    }
} catch (PDOException $e) {
    $msg = 'Impossible de créer le launcher (erreur base de données).';
    $raw = $e->getMessage();
    if (stripos($raw, 'unknown column') !== false && (stripos($raw, 'api_key') !== false)) {
        $msg = 'Base non à jour : ajoute la colonne api_key dans launchers (importe `migrations_api.sql` ou ré-importe `xynocms.sql`).';
    }
    flash_set('error', $msg);
    redirect('/builder.php');
}

flash_set('success', 'Launcher créé. API Key : ' . $apiKey);

// If a paid plan was selected on pricing.php and we are NOT on the FREE100
// promo, send the user straight to Stripe Checkout to activate the
// subscription for the freshly-created launcher.
if ($plan !== '' && $period !== '' && strtoupper($promo) !== 'FREE100') {
    $checkout = subscription_create_stripe_checkout(
        (int)$user['id'],
        (int)$launcherId,
        (string)$launcherUuidNew,
        $plan,
        $period
    );

    if (($checkout['ok'] ?? false) === true) {
        header('Location: ' . (string)$checkout['url']);
        exit;
    }

    // Stripe error: keep the launcher (it's already created) and surface
    // a clear message + the manual subscribe button on the dashboard.
    flash_set(
        'error',
        'Launcher créé, mais le paiement Stripe n\'a pas pu démarrer : '
      . (string)($checkout['error'] ?? 'erreur inconnue.')
      . ' Tu peux relancer la souscription depuis le dashboard.'
    );
    redirect('/dashboard.php?launcher=' . urlencode((string)$launcherUuidNew) . '&tab=general#tab-general');
}

redirect('/dashboard.php');

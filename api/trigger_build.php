<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * POST /api/trigger_build.php
 *
 * Déclenche un build GitHub Actions pour UN launcher (par UUID),
 * sur 1 à 3 OS simultanément (windows, linux, mac).
 *
 * Auth : session utilisateur (le launcher doit appartenir au user connecté).
 *
 * Body JSON :
 *   {
 *     "uuid": "<launcher-uuid>",
 *     "targets": ["windows","linux","mac"]   // optionnel, default: tout
 *   }
 *
 * Côté serveur :
 *  - aucun secret en dur (on lit XYNO_GITHUB_TOKEN, XYNO_GITHUB_REPO depuis .env)
 *  - rate-limit par user pour éviter de spammer GitHub Actions
 *  - trace du build (uuid, version, requested_at, requested_by, status) en DB
 *  - la version est un timestamp court : YYYYMMDD-HHMM
 */

header('Content-Type: application/json');

if (api_method() !== 'POST') {
    api_json(['error' => 'Method Not Allowed'], 405);
}

// Utiliser la session custom du CMS (cookie "xyno_session") — pas session_start() nu.
start_secure_session();
if (!isset($_SESSION['user_id'])) {
    api_json(['error' => 'Non autorisé. Veuillez vous connecter.'], 401);
}
$userId = (int)$_SESSION['user_id'];

// Rate-limit : max 10 builds par minute par IP (coût GitHub Actions).
api_rate_limit('trigger_build', api_client_ip(), 10, 60);

// --- Lecture config serveur ---
$githubToken = api_env('XYNO_GITHUB_TOKEN', '');
$githubRepo  = api_env('XYNO_GITHUB_REPO', '');  // ex "GratriShow/xynoCMS"
if ($githubToken === '' || $githubRepo === '' || !str_contains($githubRepo, '/')) {
    api_json(['error' => 'build_backend_not_configured'], 500);
}

// --- Lecture body ---
$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    api_json(['error' => 'invalid_json_body'], 400);
}
$uuid = trim((string)($input['uuid'] ?? ''));
$targets = $input['targets'] ?? ['windows', 'linux', 'mac'];

if ($uuid === '' || !preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
    api_json(['error' => 'invalid_uuid'], 400);
}
if (!is_array($targets) || empty($targets)) {
    api_json(['error' => 'invalid_targets'], 400);
}
$allowed = ['windows', 'linux', 'mac'];
$targets = array_values(array_intersect($allowed, array_map('strtolower', $targets)));
if (empty($targets)) {
    api_json(['error' => 'no_valid_target'], 400);
}

// --- Vérifier que le launcher appartient au user ---
$pdo = db();
$stmt = $pdo->prepare('SELECT id, name FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
$stmt->execute([$uuid, $userId]);
$launcher = $stmt->fetch();
if (!$launcher) {
    api_json(['error' => 'launcher_not_found_or_forbidden'], 403);
}
$launcherId = (int)$launcher['id'];

// --- Générer une version courte (timestamp UTC). ---
$version = gmdate('Ymd-Hi');

// --- Base URL publique du CMS (doit être en HTTPS). ---
$cmsBaseUrl = api_public_base_url();
if (!preg_match('#^https://#i', $cmsBaseUrl)) {
    api_json(['error' => 'cms_base_url_must_be_https'], 500);
}

// --- Trace DB du build (table launcher_builds). Create-on-demand si absente. ---
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `launcher_builds` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `launcher_id` INT NOT NULL,
            `uuid` VARCHAR(36) NOT NULL,
            `version` VARCHAR(32) NOT NULL,
            `targets` VARCHAR(64) NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
            `run_url` VARCHAR(512) NULL,
            `requested_by` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `launcher_builds_launcher_id_idx` (`launcher_id`),
            KEY `launcher_builds_uuid_idx` (`uuid`),
            UNIQUE KEY `launcher_builds_uniq` (`uuid`, `version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Seed per-platform status in the same "per:xxx=queued;... | global:in_progress"
    // format used by api/build_status.php, so the public status endpoint can render
    // a coherent progress bar from the very first poll.
    $shortTargets = array_map(fn($t) => $t === 'windows' ? 'win' : $t, $targets);
    $seededStatus = 'per:' . implode(';', array_map(fn($t) => "$t=queued", $shortTargets)) . '|global:in_progress';

    $ins = $pdo->prepare(
        'INSERT INTO launcher_builds (launcher_id, uuid, version, targets, status, requested_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $launcherId,
        $uuid,
        $version,
        implode(',', $targets),
        $seededStatus,
        $userId,
    ]);
} catch (Throwable $e) {
    // Ne bloque pas le build : la trace DB est best-effort.
    error_log('[trigger_build] DB trace failed: ' . $e->getMessage());
}

// --- Appel GitHub API : repository_dispatch ---
$payload = json_encode([
    'event_type' => 'build_custom_launcher',
    'client_payload' => [
        'uuid' => $uuid,
        'version' => $version,
        'targets' => implode(',', $targets),
        'cms_base_url' => $cmsBaseUrl,
    ],
], JSON_THROW_ON_ERROR);

$ch = curl_init("https://api.github.com/repos/{$githubRepo}/dispatches");
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $githubToken,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: xynoCMS-trigger',
        'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode === 204) {
    api_json([
        'ok' => true,
        'uuid' => $uuid,
        'version' => $version,
        'targets' => $targets,
        'status' => 'queued',
    ]);
}

api_json([
    'ok' => false,
    'error' => 'github_dispatch_failed',
    'http_code' => $httpCode,
    'curl_error' => $curlErr,
    'details' => json_decode((string)$response, true),
], 502);

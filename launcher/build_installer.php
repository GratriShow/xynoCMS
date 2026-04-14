<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/build_launcher_lib.php';

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    flash_set('error', 'Session expirée. Ré-essaie.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
$platform = strtolower(trim((string)($_POST['platform'] ?? 'mac')));

if ($launcherUuid === '' || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $launcherUuid)) {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}

if (!in_array($platform, ['win', 'mac', 'linux'], true)) {
    flash_set('error', 'Plateforme invalide.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#parametres');
}

function runner_url_for_platform(string $platform): string
{
    $key = '';
    if ($platform === 'win') {
        $key = 'XYNO_BUILD_RUNNER_URL_WIN';
    } elseif ($platform === 'mac') {
        $key = 'XYNO_BUILD_RUNNER_URL_MAC';
    } else {
        $key = 'XYNO_BUILD_RUNNER_URL_LINUX';
    }

    $url = trim(api_env($key, ''));
    if ($url === '') {
        return '';
    }

    // Accept either base URL or full endpoint.
    if (preg_match('#^https?://#i', $url) !== 1) {
        return '';
    }

    if (stripos($url, '/api/build_launcher.php') === false) {
        $url = rtrim($url, '/') . '/api/build_launcher.php';
    }

    return $url;
}

/**
 * @return array{ok:bool, uuid?:string, version?:string, url?:string, error?:string}
 */
function call_remote_runner(string $runnerUrl, string $token, string $uuid, string $platform): array
{
    $payload = http_build_query([
        'uuid' => $uuid,
        'platform' => $platform,
    ], '', '&');

    if (function_exists('curl_init')) {
        $ch = curl_init($runnerUrl);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init_failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Build-Token: ' . $token,
            ],
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 7200,
            CURLOPT_HEADER => false,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'error' => 'curl_exec_failed: ' . $err];
        }
        $decoded = json_decode((string)$resp, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'invalid_json_response'];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n" . "X-Build-Token: {$token}\r\n",
            'content' => $payload,
            'timeout' => 7200,
            'ignore_errors' => true,
        ],
    ]);

    $resp = file_get_contents($runnerUrl, false, $ctx);
    if (!is_string($resp)) {
        return ['ok' => false, 'error' => 'http_request_failed'];
    }
    $decoded = json_decode((string)$resp, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'invalid_json_response'];
}

try {
    $pdo = db();
    $sel = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $sel->execute([$launcherUuid, $user['id']]);
    $row = $sel->fetch();
    if (!$row) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }

    $ip = api_client_ip();
    $endpoint = 'build_installer';

    $token = api_env('XYNO_BUILD_TRIGGER_TOKEN', '');
    if ($token === '') {
        flash_set('error', 'Build non configuré (token manquant).');
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#parametres');
    }

    $runnerUrl = runner_url_for_platform($platform);
    if ($runnerUrl !== '') {
        $decoded = call_remote_runner($runnerUrl, $token, $launcherUuid, $platform);
        if (($decoded['ok'] ?? false) !== true) {
            $msg = (string)($decoded['error'] ?? 'Build failed');
            throw new BuildLauncherException(500, 'remote_runner_failed', $msg);
        }
        $version = (string)($decoded['version'] ?? '');
        $fileUrl = (string)($decoded['url'] ?? '');

        flash_set('success', 'Build terminé (' . strtoupper($platform) . ') ' . ($version !== '' ? '• ' . $version : '') . ($fileUrl !== '' ? ' • OK' : ''));
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#parametres');
    }

    // Default: build locally on the same machine as the CMS.
    $result = build_launcher_perform($launcherUuid, $platform, $endpoint, $ip);

    $version = (string)($result['version'] ?? '');
    $fileUrl = (string)($result['url'] ?? '');

    flash_set('success', 'Build terminé (' . strtoupper($platform) . ') ' . ($version !== '' ? '• ' . $version : '') . ($fileUrl !== '' ? ' • OK' : ''));
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#parametres');
} catch (BuildLauncherException $e) {
    error_log('[build_installer] user_id=' . (int)$user['id'] . ' uuid=' . $launcherUuid . ' platform=' . $platform . ' code=' . $e->logCode);
    flash_set('error', $e->publicMessage);
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#parametres');
} catch (Throwable $e) {
    // Keep details in server logs, show a short user-facing message.
    error_log('[build_installer] user_id=' . (int)$user['id'] . ' uuid=' . $launcherUuid . ' platform=' . $platform . ' err=' . $e->getMessage());
    flash_set('error', 'Build impossible. Vérifie la config du builder sur le serveur.');
    redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '#parametres');
}

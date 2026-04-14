<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/build_launcher_lib.php';

$endpoint = 'build_launcher';
$ip = api_client_ip();

function build_api_error(string $endpoint, string $ip, ?string $uuid, int $status, string $code, string $publicMessage): never
{
    api_log($endpoint, $ip, $uuid, $status, $code);
    api_json(['error' => $publicMessage], $status);
}

function build_realpath_or_empty(string $path): string
{
    $rp = realpath($path);
    return is_string($rp) ? $rp : '';
}

function build_recursive_delete_dir(string $dirAbs, string $mustStartWithAbs): void
{
    $dirAbs = rtrim($dirAbs, DIRECTORY_SEPARATOR);
    $mustStartWithAbs = rtrim($mustStartWithAbs, DIRECTORY_SEPARATOR);

    if ($dirAbs === '' || $mustStartWithAbs === '') {
        return;
    }

    // Safety: only allow deleting inside the launcher directory.
    if (strpos($dirAbs . DIRECTORY_SEPARATOR, $mustStartWithAbs . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    if (!is_dir($dirAbs)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirAbs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        $path = $fileInfo->getPathname();
        if ($fileInfo->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dirAbs);
}

/**
 * Run a command with streaming capture.
 *
 * IMPORTANT: pass command as an array to bypass the shell (prevents injection).
 *
 * @param list<string> $cmd
 * @return array{exit:int, stdout:string, stderr:string, seconds:float}
 */
function build_run_cmd(array $cmd, string $cwd, int $timeoutSeconds = 3600): array
{
    $start = microtime(true);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'Failed to start process', 'seconds' => 0.0];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';

    while (true) {
        $status = proc_get_status($proc);
        $running = is_array($status) ? (bool)($status['running'] ?? false) : false;

        $read = [];
        if (is_resource($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (is_resource($pipes[2])) {
            $read[] = $pipes[2];
        }

        $write = null;
        $except = null;
        if (!empty($read)) {
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $r) {
                $chunk = stream_get_contents($r);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($r === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        $elapsed = microtime(true) - $start;
        if ($elapsed > $timeoutSeconds) {
            @proc_terminate($proc, 9);
            $stderr .= "\nTimed out after {$timeoutSeconds}s";
            break;
        }

        if (!$running) {
            break;
        }

        usleep(50000);
    }

    // Drain remaining output
    if (is_resource($pipes[1])) {
        $more = stream_get_contents($pipes[1]);
        if (is_string($more) && $more !== '') {
            $stdout .= $more;
        }
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2])) {
        $more = stream_get_contents($pipes[2]);
        if (is_string($more) && $more !== '') {
            $stderr .= $more;
        }
        fclose($pipes[2]);
    }

    $exitCode = proc_close($proc);
    $seconds = microtime(true) - $start;

    return [
        'exit' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'seconds' => $seconds,
    ];
}

function build_find_newest_exe(string $distAbs): string
{
    if (!is_dir($distAbs)) {
        return '';
    }

    $best = '';
    $bestTime = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($distAbs, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        $name = strtolower($fileInfo->getFilename());
        if (!str_ends_with($name, '.exe')) {
            continue;
        }
        $mtime = (int)$fileInfo->getMTime();
        if ($mtime >= $bestTime) {
            $bestTime = $mtime;
            $best = $fileInfo->getPathname();
        }
    }

    return $best;
}

function build_find_newest_artifact(string $distAbs, array $extensionsLower): string
{
    if (!is_dir($distAbs)) {
        return '';
    }

    $normalized = [];
    foreach ($extensionsLower as $ext) {
        $ext = strtolower(trim((string)$ext));
        $ext = ltrim($ext, '.');
        if ($ext !== '') {
            $normalized[$ext] = true;
        }
    }
    if (!$normalized) {
        return '';
    }

    $best = '';
    $bestTime = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($distAbs, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        $ext = strtolower($fileInfo->getExtension());
        if ($ext === '' || !isset($normalized[$ext])) {
            continue;
        }
        $mtime = (int)$fileInfo->getMTime();
        if ($mtime >= $bestTime) {
            $bestTime = $mtime;
            $best = $fileInfo->getPathname();
        }
    }

    return $best;
}

function build_read_launcher_version_from_package_json(string $launcherDirAbs): string
{
    $pkgPath = rtrim($launcherDirAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'package.json';
    if (!is_file($pkgPath)) {
        return '';
    }
    $raw = @file_get_contents($pkgPath);
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '';
    }
    $v = (string)($decoded['version'] ?? '');
    $v = trim($v);
    if ($v === '' || strlen($v) > 64) {
        return '';
    }
    if (!preg_match('/^[0-9A-Za-z._-]{1,64}$/', $v)) {
        return '';
    }
    return $v;
}

if (api_method() !== 'POST') {
    build_api_error($endpoint, $ip, null, 405, 'method_not_allowed', 'Method Not Allowed');
}

$token = api_header('X-Build-Token', 512);
$expected = api_env('XYNO_BUILD_TRIGGER_TOKEN', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    build_api_error($endpoint, $ip, null, 401, 'unauthorized', 'Unauthorized');
}

// Accept uuid via querystring, form-data, or JSON body.
$uuid = api_param('uuid', 64);
if ($uuid === '') {
    $uuid = trim((string)($_POST['uuid'] ?? ''));
}
if ($uuid === '') {
    $body = api_read_json_body(65536);
    $uuid = trim((string)($body['uuid'] ?? ''));
}
if ($uuid === '' || strlen($uuid) > 64) {
    build_api_error($endpoint, $ip, null, 400, 'missing_uuid', 'Missing uuid');
}
if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
    build_api_error($endpoint, $ip, $uuid, 400, 'invalid_uuid', 'Invalid uuid');
}

// Optional platform: win|mac|linux (defaults to win)
$platform = strtolower(trim((string)($_POST['platform'] ?? '')));
if ($platform === '') {
    $platform = strtolower(trim((string)($_GET['platform'] ?? '')));
}
if ($platform === '') {
    $body = api_read_json_body(65536);
    $platform = strtolower(trim((string)($body['platform'] ?? '')));
}
if ($platform === '') {
    $platform = 'win';
}
if (!in_array($platform, ['win', 'mac', 'linux'], true)) {
    build_api_error($endpoint, $ip, $uuid, 400, 'invalid_platform', 'Invalid platform');
}

// New implementation (shared library). Keep the legacy code below unreachable.
try {
    $result = build_launcher_perform($uuid, $platform, $endpoint, $ip);
    api_json([
        'ok' => true,
        'uuid' => $result['uuid'],
        'version' => $result['version'],
        'url' => $result['url'],
    ], 200);
} catch (BuildLauncherException $e) {
    api_json(['error' => $e->publicMessage], $e->httpStatus);
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        build_api_error($endpoint, $ip, $uuid, 404, 'launcher_not_found', 'Launcher not found');
    }

    $launcherId = (int)($launcher['id'] ?? 0);
    if ($launcherId <= 0) {
        build_api_error($endpoint, $ip, $uuid, 500, 'invalid_launcher_id', 'Server error');
    }

    $projectRoot = build_realpath_or_empty(__DIR__ . '/..');
    if ($projectRoot === '') {
        build_api_error($endpoint, $ip, $uuid, 500, 'invalid_project_root', 'Server error');
    }

    $launcherDir = build_realpath_or_empty($projectRoot . '/launcher');
    if ($launcherDir === '' || !is_dir($launcherDir)) {
        build_api_error($endpoint, $ip, $uuid, 500, 'launcher_dir_missing', 'Launcher project not found');
    }

    $distDir = $launcherDir . DIRECTORY_SEPARATOR . 'dist';

    // Prevent concurrent builds (shared /launcher folder).
    $lockPath = $launcherDir . DIRECTORY_SEPARATOR . '.build.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if (!is_resource($lockFp)) {
        build_api_error($endpoint, $ip, $uuid, 500, 'lock_open_failed', 'Server error');
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        build_api_error($endpoint, $ip, $uuid, 409, 'build_in_progress', 'Build already in progress');
    }

    $t0 = microtime(true);

    // Clean previous build artifacts to avoid picking a stale .exe.
    build_recursive_delete_dir($distDir, $launcherDir);

    $r1 = build_run_cmd(['npm', 'install'], $launcherDir, 3600);
    if ($r1['exit'] !== 0) {
        $tail = substr(trim($r1['stderr'] !== '' ? $r1['stderr'] : $r1['stdout']), -4000);
        error_log("[{$endpoint}] npm install failed (uuid={$uuid}) exit={$r1['exit']} tail=" . $tail);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        build_api_error($endpoint, $ip, $uuid, 500, 'npm_install_failed', 'Build failed (npm install)');
    }

    $builderArgs = ['npx', 'electron-builder'];
    if ($platform === 'win') {
        $builderArgs[] = '--win';
    } elseif ($platform === 'mac') {
        $builderArgs[] = '--mac';
    } else {
        $builderArgs[] = '--linux';
    }
    $builderArgs[] = '--publish';
    $builderArgs[] = 'never';

    $r2 = build_run_cmd($builderArgs, $launcherDir, 7200);
    if ($r2['exit'] !== 0) {
        $tail = substr(trim($r2['stderr'] !== '' ? $r2['stderr'] : $r2['stdout']), -8000);
        error_log("[{$endpoint}] electron-builder failed (uuid={$uuid}) exit={$r2['exit']} tail=" . $tail);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        build_api_error($endpoint, $ip, $uuid, 500, 'electron_builder_failed', 'Build failed (electron-builder)');
    }

    $artifactAbs = '';
    $artifactExt = '';
    if ($platform === 'win') {
        $artifactAbs = build_find_newest_artifact($distDir, ['exe']);
        $artifactExt = 'exe';
    } elseif ($platform === 'mac') {
        // Default electron-builder target on mac is .dmg (unless overridden)
        $artifactAbs = build_find_newest_artifact($distDir, ['dmg']);
        $artifactExt = 'dmg';
    } else {
        // Default electron-builder target on linux is often .AppImage
        $artifactAbs = build_find_newest_artifact($distDir, ['appimage']);
        $artifactExt = 'AppImage';
    }

    if ($artifactAbs === '' || !is_file($artifactAbs)) {
        error_log("[{$endpoint}] build completed but artifact not found (uuid={$uuid} platform={$platform})");
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $msg = $platform === 'win'
            ? 'Build succeeded but no .exe was produced'
            : ($platform === 'mac' ? 'Build succeeded but no .dmg was produced' : 'Build succeeded but no AppImage was produced');
        build_api_error($endpoint, $ip, $uuid, 500, 'artifact_not_found', $msg);
    }

    $version = build_read_launcher_version_from_package_json($launcherDir);
    if ($version === '') {
        $version = trim((string)($launcher['version'] ?? ''));
    }
    if ($version === '' || strlen($version) > 64 || !preg_match('/^[0-9A-Za-z._-]{1,64}$/', $version)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        build_api_error($endpoint, $ip, $uuid, 500, 'invalid_version', 'Server error');
    }

    $subdir = "files/{$uuid}/client/installer/{$platform}";
    $publicRel = "/{$subdir}/installer-{$platform}-{$version}.{$artifactExt}";

    $destAbsDir = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($destAbsDir)) {
        mkdir($destAbsDir, 0755, true);
    }
    if (!is_dir($destAbsDir)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        build_api_error($endpoint, $ip, $uuid, 500, 'dest_dir_failed', 'Server error');
    }

    $destAbsPath = rtrim($destAbsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($publicRel);

    // Move build artifact to public files directory.
    if (!@rename($artifactAbs, $destAbsPath)) {
        // Fallback cross-device move
        if (!@copy($artifactAbs, $destAbsPath) || !@unlink($artifactAbs)) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            build_api_error($endpoint, $ip, $uuid, 500, 'move_failed', 'Server error');
        }
    }

    $sha = strtolower((string)hash_file('sha256', $destAbsPath));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
        @unlink($destAbsPath);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        build_api_error($endpoint, $ip, $uuid, 500, 'sha_failed', 'Server error');
    }

    $fileUrl = api_public_url($publicRel);

    $pdo = db();
    $pdo->beginTransaction();

    // Activate the new installer and deactivate previous ones.
    $pdo->prepare('UPDATE launcher_downloads SET is_active = 0 WHERE launcher_id = ? AND platform = ?')->execute([$launcherId, $platform]);

    $ins = $pdo->prepare(
        'INSERT INTO launcher_downloads (launcher_id, platform, version_name, file_url, file_sha256, is_active, created_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW()) '
        . 'ON DUPLICATE KEY UPDATE file_url=VALUES(file_url), file_sha256=VALUES(file_sha256), is_active=VALUES(is_active)'
    );
    $ins->execute([$launcherId, $platform, $version, $fileUrl, $sha]);

    $pdo->commit();

    $seconds = microtime(true) - $t0;
    error_log(sprintf('[%s] build ok uuid=%s version=%s platform=%s seconds=%.2f', $endpoint, $uuid, $version, $platform, $seconds));

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    api_json([
        'ok' => true,
        'uuid' => $uuid,
        'version' => $version,
        'url' => $fileUrl,
    ], 200);
} catch (Throwable $e) {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e2) {
    }

    error_log("[{$endpoint}] server_error uuid={$uuid} msg=" . $e->getMessage());
    api_log($endpoint, $ip, $uuid ?? null, 500, 'server_error');
    api_json(['error' => 'Server error'], 500);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

if (!class_exists('BuildLauncherException')) {
    final class BuildLauncherException extends RuntimeException
    {
        public int $httpStatus;
        public string $logCode;
        public string $publicMessage;

        public function __construct(int $httpStatus, string $logCode, string $publicMessage)
        {
            parent::__construct($logCode);
            $this->httpStatus = $httpStatus;
            $this->logCode = $logCode;
            $this->publicMessage = $publicMessage;
        }
    }
}

if (!function_exists('build_realpath_or_empty')) {
    function build_realpath_or_empty(string $path): string
    {
        $rp = realpath($path);
        return is_string($rp) ? $rp : '';
    }
}

if (!function_exists('build_recursive_delete_dir')) {
    function build_recursive_delete_dir(string $dirAbs, string $mustStartWithAbs): void
    {
    $dirAbs = rtrim($dirAbs, DIRECTORY_SEPARATOR);
    $mustStartWithAbs = rtrim($mustStartWithAbs, DIRECTORY_SEPARATOR);

    if ($dirAbs === '' || $mustStartWithAbs === '') {
        return;
    }
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
}

/**
 * @param list<string> $cmd
 * @return array{exit:int, stdout:string, stderr:string, seconds:float}
 */
if (!function_exists('build_run_cmd')) {
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
}

if (!function_exists('build_find_newest_artifact')) {
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
}

if (!function_exists('build_read_launcher_version_from_package_json')) {
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
}

/**
 * @return array{uuid:string, platform:string, version:string, url:string, sha256:string, seconds:float}
 */
if (!function_exists('build_launcher_perform')) {
    function build_first_executable(array $candidates): string
    {
        foreach ($candidates as $p) {
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return '';
    }

    function build_find_on_path(string $binName): string
    {
        $binName = trim($binName);
        if ($binName === '' || strpos($binName, "\0") !== false) {
            return '';
        }

        $path = (string)getenv('PATH');
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $parts = explode(PATH_SEPARATOR, $path);
        foreach ($parts as $dir) {
            $dir = trim((string)$dir);
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binName;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    function build_ensure_path_contains(array $dirs): void
    {
        $current = trim((string)getenv('PATH'));
        $parts = $current !== '' ? explode(PATH_SEPARATOR, $current) : [];
        $set = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $set[$p] = true;
            }
        }

        $toAdd = [];
        foreach ($dirs as $d) {
            $d = trim((string)$d);
            if ($d === '' || isset($set[$d])) {
                continue;
            }
            $toAdd[] = $d;
            $set[$d] = true;
        }

        if (!$toAdd) {
            return;
        }

        $newPath = $current;
        if ($newPath !== '' && substr($newPath, -1) !== PATH_SEPARATOR) {
            $newPath .= PATH_SEPARATOR;
        }
        $newPath .= implode(PATH_SEPARATOR, $toAdd);

        $_ENV['PATH'] = $newPath;
        $_SERVER['PATH'] = $newPath;
        @putenv('PATH=' . $newPath);
    }

    function build_bin_from_env(string $envName, string $default, array $fallbackCandidates = []): string
    {
        $v = api_env($envName, '');
        $v = trim($v);
        if ($v !== '' && strlen($v) <= 300 && strpos($v, "\0") === false) {
            // If an absolute path is provided, ensure it's executable.
            if ($v[0] === '/') {
                if (!is_file($v)) {
                    return $default;
                }
                if (!is_executable($v)) {
                    return $default;
                }
                return $v;
            }

            // Non-absolute override: try PATH resolution first.
            $onPath = build_find_on_path($v);
            if ($onPath !== '') {
                return $onPath;
            }
            return $v;
        }

        $onPath = build_find_on_path($default);
        if ($onPath !== '') {
            return $onPath;
        }

        $found = build_first_executable($fallbackCandidates);
        if ($found !== '') {
            return $found;
        }
        return $default;
    }

    function build_bin_required(string $envName, string $default, array $fallbackCandidates, string $publicLabel): string
    {
        $v = trim((string)api_env($envName, ''));
        if ($v !== '') {
            if (strlen($v) > 300 || strpos($v, "\0") !== false) {
                throw new BuildLauncherException(500, strtolower($publicLabel) . '_bin_invalid', "{$envName} invalide.");
            }
            if ($v[0] === '/') {
                if (!is_file($v) || !is_executable($v)) {
                    throw new BuildLauncherException(500, strtolower($publicLabel) . '_bin_invalid', "{$envName} pointe vers un binaire introuvable/non exécutable : {$v}");
                }
                return $v;
            }

            $onPath = build_find_on_path($v);
            if ($onPath !== '') {
                return $onPath;
            }
            // Let proc_open try to resolve it, but error will be clearer later if it fails.
            return $v;
        }

        $onPath = build_find_on_path($default);
        if ($onPath !== '') {
            return $onPath;
        }

        $found = build_first_executable($fallbackCandidates);
        if ($found !== '') {
            return $found;
        }

        return $default;
    }

    function build_launcher_perform(string $uuid, string $platform, string $endpoint, string $ip): array
    {
    $uuid = trim($uuid);
    $platform = strtolower(trim($platform));

    if ($uuid === '' || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
        throw new BuildLauncherException(400, 'invalid_uuid', 'Invalid uuid');
    }
    if (!in_array($platform, ['win', 'mac', 'linux'], true)) {
        throw new BuildLauncherException(400, 'invalid_platform', 'Invalid platform');
    }

    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        throw new BuildLauncherException(404, 'launcher_not_found', 'Launcher not found');
    }

    $launcherId = (int)($launcher['id'] ?? 0);
    if ($launcherId <= 0) {
        throw new BuildLauncherException(500, 'invalid_launcher_id', 'Server error');
    }

    $projectRoot = build_realpath_or_empty(__DIR__ . '/..');
    if ($projectRoot === '') {
        throw new BuildLauncherException(500, 'invalid_project_root', 'Server error');
    }

    $launcherDir = build_realpath_or_empty($projectRoot . '/launcher');
    if ($launcherDir === '' || !is_dir($launcherDir)) {
        throw new BuildLauncherException(500, 'launcher_dir_missing', 'Launcher project not found');
    }

    $distDir = $launcherDir . DIRECTORY_SEPARATOR . 'dist';

    $lockPath = $launcherDir . DIRECTORY_SEPARATOR . '.build.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if (!is_resource($lockFp)) {
        throw new BuildLauncherException(500, 'lock_open_failed', 'Server error');
    }

    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        throw new BuildLauncherException(409, 'build_in_progress', 'Build already in progress');
    }

    $t0 = microtime(true);

    try {
        build_recursive_delete_dir($distDir, $launcherDir);

        // Web/PHP processes often have a reduced PATH; ensure common bin dirs are included.
        build_ensure_path_contains([
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            '/opt/homebrew/bin',
            '/snap/bin',
        ]);

        $npmBin = build_bin_required('XYNO_NPM_BIN', 'npm', [
            '/usr/local/bin/npm',
            '/opt/homebrew/bin/npm',
            '/usr/bin/npm',
        ], 'npm');
        $npxBin = build_bin_required('XYNO_NPX_BIN', 'npx', [
            '/usr/local/bin/npx',
            '/opt/homebrew/bin/npx',
            '/usr/bin/npx',
        ], 'npx');

        $r1 = build_run_cmd([$npmBin, 'install'], $launcherDir, 3600);
        if ($r1['exit'] !== 0) {
            $tail = substr(trim($r1['stderr'] !== '' ? $r1['stderr'] : $r1['stdout']), -4000);
            error_log("[{$endpoint}] npm install failed (uuid={$uuid}) exit={$r1['exit']} tail=" . $tail);

            if ($r1['exit'] === 127 || stripos($tail, 'failed to start process') !== false) {
                throw new BuildLauncherException(
                    500,
                    'npm_not_found',
                    'Build failed: npm introuvable sur le serveur (PATH). Configure XYNO_NPM_BIN (chemin absolu) ou installe npm pour l’utilisateur du serveur web.'
                );
            }

            throw new BuildLauncherException(500, 'npm_install_failed', 'Build failed (npm install)');
        }

        $builderBin = $launcherDir . '/node_modules/.bin/electron-builder';
        $builderArgs = [$builderBin];
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

            if ($r2['exit'] === 127 || stripos($tail, 'failed to start process') !== false) {
                throw new BuildLauncherException(
                    500,
                    'npx_not_found',
                    'Build failed: npx introuvable sur le serveur (PATH). Configure XYNO_NPX_BIN (chemin absolu) ou installe npx pour l’utilisateur du serveur web.'
                );
            }
            throw new BuildLauncherException(500, 'electron_builder_failed', 'Build failed (electron-builder)');
        }

        $artifactAbs = '';
        $artifactExt = '';
        if ($platform === 'win') {
            $artifactAbs = build_find_newest_artifact($distDir, ['exe']);
            $artifactExt = 'exe';
        } elseif ($platform === 'mac') {
            $artifactAbs = build_find_newest_artifact($distDir, ['dmg']);
            $artifactExt = 'dmg';
        } else {
            $artifactAbs = build_find_newest_artifact($distDir, ['appimage']);
            $artifactExt = 'AppImage';
        }

        if ($artifactAbs === '' || !is_file($artifactAbs)) {
            error_log("[{$endpoint}] artifact not found (uuid={$uuid} platform={$platform})");
            $msg = $platform === 'win'
                ? 'Build succeeded but no .exe was produced'
                : ($platform === 'mac' ? 'Build succeeded but no .dmg was produced' : 'Build succeeded but no AppImage was produced');
            throw new BuildLauncherException(500, 'artifact_not_found', $msg);
        }

        $version = build_read_launcher_version_from_package_json($launcherDir);
        if ($version === '') {
            $version = trim((string)($launcher['version'] ?? ''));
        }
        if ($version === '' || strlen($version) > 64 || !preg_match('/^[0-9A-Za-z._-]{1,64}$/', $version)) {
            throw new BuildLauncherException(500, 'invalid_version', 'Server error');
        }

        $subdir = "files/{$uuid}/client/installer/{$platform}";
        $publicRel = "/{$subdir}/installer-{$platform}-{$version}.{$artifactExt}";

        $destAbsDir = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
        if (!is_dir($destAbsDir)) {
            mkdir($destAbsDir, 0755, true);
        }
        if (!is_dir($destAbsDir)) {
            throw new BuildLauncherException(500, 'dest_dir_failed', 'Server error');
        }

        $destAbsPath = rtrim($destAbsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($publicRel);

        if (!@rename($artifactAbs, $destAbsPath)) {
            if (!@copy($artifactAbs, $destAbsPath) || !@unlink($artifactAbs)) {
                throw new BuildLauncherException(500, 'move_failed', 'Server error');
            }
        }

        $sha = strtolower((string)hash_file('sha256', $destAbsPath));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            @unlink($destAbsPath);
            throw new BuildLauncherException(500, 'sha_failed', 'Server error');
        }

        $fileUrl = api_public_url($publicRel);

        $pdo = db();
        $pdo->beginTransaction();

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
        api_log($endpoint, $ip, $uuid, 200, 'ok');

        return [
            'uuid' => $uuid,
            'platform' => $platform,
            'version' => $version,
            'url' => $fileUrl,
            'sha256' => $sha,
            'seconds' => $seconds,
        ];
    } catch (BuildLauncherException $e) {
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e2) {
        }

        api_log($endpoint, $ip, $uuid, $e->httpStatus, $e->logCode);
        throw $e;
    } catch (Throwable $e) {
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e2) {
        }

        error_log("[{$endpoint}] server_error uuid={$uuid} msg=" . $e->getMessage());
        api_log($endpoint, $ip, $uuid, 500, 'server_error');
            throw new BuildLauncherException(500, 'server_error', 'Server error');
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }
}

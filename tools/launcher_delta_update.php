#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function stderr(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

function usage(int $code = 1): never
{
    stderr('Usage: php tools/launcher_delta_update.php --manifest <url> --minecraft <dir> [--cache <file>] [--no-delete] [--dry-run]');
    stderr('Example:');
    stderr('  php tools/launcher_delta_update.php --manifest "http://127.0.0.1:4173/api/manifest.php?uuid=...&key=..." --minecraft "$HOME/.minecraft"');
    exit($code);
}

function path_join(string $a, string $b): string
{
    $a = rtrim($a, DIRECTORY_SEPARATOR);
    $b = ltrim($b, DIRECTORY_SEPARATOR);
    return $a . DIRECTORY_SEPARATOR . $b;
}

function ensure_dir(string $dir): void
{
    if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
        return;
    }
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('mkdir_failed: ' . $dir);
    }
}

function normalize_rel_path(string $p): string
{
    $p = str_replace('\\', '/', trim($p));
    $p = ltrim($p, '/');
    // forbid traversal and null bytes
    if ($p === '' || str_contains($p, "\0") || preg_match('#(^|/)\.{1,2}(/|$)#', $p)) {
        throw new InvalidArgumentException('invalid_path');
    }
    return $p;
}

function http_get_json(string $url, ?string $ifNoneMatch, array &$responseHeaders, int &$statusCode): ?array
{
    $responseHeaders = [];
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init_failed');
        }

        $headers = [];
        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $headers[] = 'If-None-Match: ' . $ifNoneMatch;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ]);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('curl_exec_failed: ' . $err);
        }

        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        foreach (preg_split('/\r\n|\n|\r/', $rawHeaders) as $line) {
            $line = trim((string)$line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $responseHeaders[strtolower($k)] = $v;
        }

        if ($statusCode === 304) {
            return null;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('http_error_' . $statusCode);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('invalid_json');
        }
        return $data;
    }

    $headers = [
        'Accept: application/json',
    ];
    if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
        $headers[] = 'If-None-Match: ' . $ifNoneMatch;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $body = is_string($body) ? $body : '';

    $statusCode = 0;
    $resp = $http_response_header ?? [];
    foreach ($resp as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $statusCode = (int)$m[1];
        }
        if (str_contains($line, ':')) {
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $responseHeaders[strtolower($k)] = $v;
        }
    }

    if ($statusCode === 304) {
        return null;
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('http_error_' . $statusCode);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('invalid_json');
    }

    return $data;
}

function http_download_to_file(string $url, string $destTmpPath, ?string $etag = null): void
{
    if (function_exists('curl_init')) {
        $fh = fopen($destTmpPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('open_tmp_failed');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new RuntimeException('curl_init_failed');
        }

        $headers = [];
        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok !== true) {
            throw new RuntimeException('download_failed: ' . $err);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('download_http_error_' . $status);
        }

        return;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 0,
        ],
    ]);

    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) {
        throw new RuntimeException('download_open_failed');
    }

    $out = fopen($destTmpPath, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('open_tmp_failed');
    }

    while (!feof($in)) {
        $chunk = fread($in, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        if ($chunk !== '') {
            fwrite($out, $chunk);
        }
    }

    fclose($in);
    fclose($out);
}

function prune_empty_dirs(string $rootDir): void
{
    if (!is_dir($rootDir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $p) {
        if ($p->isDir()) {
            $dir = $p->getPathname();
            $entries = @scandir($dir);
            if (is_array($entries) && count($entries) <= 2) {
                @rmdir($dir);
            }
        }
    }
}

$args = $argv;
array_shift($args);

$manifestUrl = null;
$minecraftDir = null;
$cacheFile = null;
$noDelete = false;
$dryRun = false;

for ($i = 0; $i < count($args); $i++) {
    $a = (string)$args[$i];
    if ($a === '--manifest' && isset($args[$i + 1])) {
        $manifestUrl = (string)$args[++$i];
        continue;
    }
    if ($a === '--minecraft' && isset($args[$i + 1])) {
        $minecraftDir = (string)$args[++$i];
        continue;
    }
    if ($a === '--cache' && isset($args[$i + 1])) {
        $cacheFile = (string)$args[++$i];
        continue;
    }
    if ($a === '--no-delete') {
        $noDelete = true;
        continue;
    }
    if ($a === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($a === '--help' || $a === '-h') {
        usage(0);
    }

    stderr('Unknown arg: ' . $a);
    usage(1);
}

if ($manifestUrl === null || $minecraftDir === null) {
    usage(1);
}

$minecraftDir = rtrim($minecraftDir, "/\\");
if ($minecraftDir === '' || !is_dir($minecraftDir)) {
    stderr('Invalid --minecraft directory');
    exit(2);
}

if ($cacheFile === null || trim($cacheFile) === '') {
    $cacheFile = path_join($minecraftDir, '.xynocms-cache.json');
}

$cache = [
    'manifest_etag' => '',
    'files' => [],
];

if (is_file($cacheFile)) {
    $raw = file_get_contents($cacheFile);
    if (is_string($raw) && $raw !== '') {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $cache['manifest_etag'] = (string)($parsed['manifest_etag'] ?? '');
            $cache['files'] = is_array($parsed['files'] ?? null) ? (array)$parsed['files'] : [];
        }
    }
}

$responseHeaders = [];
$statusCode = 0;
$manifest = http_get_json($manifestUrl, $cache['manifest_etag'] !== '' ? $cache['manifest_etag'] : null, $responseHeaders, $statusCode);

if ($manifest === null) {
    echo "Up to date (manifest 304)\n";
    exit(0);
}

$files = $manifest['files'] ?? null;
if (!is_array($files)) {
    stderr('Manifest missing files[]');
    exit(3);
}

$desired = [];
$totalSize = 0;
foreach ($files as $f) {
    if (!is_array($f)) {
        continue;
    }
    $path = normalize_rel_path((string)($f['path'] ?? ''));
    $hash = strtolower(trim((string)($f['hash'] ?? '')));
    $size = (int)($f['size'] ?? 0);
    $url = (string)($f['url'] ?? '');

    if ($url === '') {
        continue;
    }

    $desired[$path] = [
        'path' => $path,
        'hash' => $hash,
        'size' => $size,
        'url' => $url,
    ];
    $totalSize += max(0, $size);
}

$downloaded = 0;
$deleted = 0;
$checked = 0;
$bytesDownloaded = 0;

foreach ($desired as $rel => $meta) {
    $checked++;

    $localPath = path_join($minecraftDir, str_replace('/', DIRECTORY_SEPARATOR, $rel));
    $localDir = dirname($localPath);

    $needsDownload = false;

    if (!is_file($localPath)) {
        $needsDownload = true;
    } else {
        $localSize = filesize($localPath);
        $localMtime = filemtime($localPath);
        $localSize = $localSize === false ? -1 : (int)$localSize;
        $localMtime = $localMtime === false ? 0 : (int)$localMtime;

        if ($meta['size'] > 0 && $localSize !== $meta['size']) {
            $needsDownload = true;
        } else {
            $cached = $cache['files'][$rel] ?? null;
            if (is_array($cached)
                && (int)($cached['size'] ?? -2) === $localSize
                && (int)($cached['mtime'] ?? -1) === $localMtime
                && (string)($cached['hash'] ?? '') === (string)$meta['hash']
                && (string)$meta['hash'] !== ''
            ) {
                $needsDownload = false;
            } else {
                // Hash only when needed (cache miss or file changed).
                $computed = sha1_file($localPath);
                if ($computed === false) {
                    $needsDownload = true;
                } else {
                    $computed = strtolower($computed);
                    if ($meta['hash'] !== '' && $computed !== $meta['hash']) {
                        $needsDownload = true;
                    } else {
                        $cache['files'][$rel] = [
                            'size' => $localSize,
                            'mtime' => $localMtime,
                            'hash' => $computed,
                        ];
                    }
                }
            }
        }
    }

    if (!$needsDownload) {
        continue;
    }

    if ($dryRun) {
        echo "Would download: {$rel}\n";
        continue;
    }

    ensure_dir($localDir);

    $tmp = $localPath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    try {
        http_download_to_file((string)$meta['url'], $tmp);

        $dlSize = filesize($tmp);
        $dlSize = $dlSize === false ? 0 : (int)$dlSize;

        if ($meta['size'] > 0 && $dlSize !== $meta['size']) {
            throw new RuntimeException('size_mismatch');
        }

        if ($meta['hash'] !== '') {
            $dlHash = sha1_file($tmp);
            if ($dlHash === false || strtolower($dlHash) !== $meta['hash']) {
                throw new RuntimeException('hash_mismatch');
            }
        }

        if (is_file($localPath)) {
            @unlink($localPath);
        }

        if (!rename($tmp, $localPath)) {
            throw new RuntimeException('rename_failed');
        }

        @chmod($localPath, 0644);

        $localSize = filesize($localPath);
        $localMtime = filemtime($localPath);
        $localSize = $localSize === false ? $dlSize : (int)$localSize;
        $localMtime = $localMtime === false ? time() : (int)$localMtime;

        $cache['files'][$rel] = [
            'size' => $localSize,
            'mtime' => $localMtime,
            'hash' => $meta['hash'] !== '' ? (string)$meta['hash'] : (string)(sha1_file($localPath) ?: ''),
        ];

        $downloaded++;
        $bytesDownloaded += max(0, $localSize);
        echo "Downloaded: {$rel}\n";
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

if (!$noDelete) {
    // Delete only inside the directories referenced by the manifest (ex: mods/, config/, assets/, versions/).
    $roots = [];
    foreach (array_keys($desired) as $p) {
        $seg = explode('/', $p, 2)[0] ?? '';
        $seg = trim((string)$seg);
        if ($seg !== '') {
            $roots[$seg] = true;
        }
    }

    foreach (array_keys($roots) as $root) {
        $rootDir = path_join($minecraftDir, $root);
        if (!is_dir($rootDir)) {
            continue;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $p) {
            if (!$p->isFile()) {
                continue;
            }

            $abs = $p->getPathname();
            $relPath = substr($abs, strlen(rtrim($minecraftDir, DIRECTORY_SEPARATOR)) + 1);
            $relPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);

            try {
                $relPath = normalize_rel_path($relPath);
            } catch (Throwable $e) {
                continue;
            }

            if (!isset($desired[$relPath])) {
                if ($dryRun) {
                    echo "Would delete: {$relPath}\n";
                    continue;
                }

                @unlink($abs);
                unset($cache['files'][$relPath]);
                $deleted++;
                echo "Deleted: {$relPath}\n";
            }
        }

        if (!$dryRun) {
            prune_empty_dirs($rootDir);
        }
    }
}

$cache['manifest_etag'] = (string)($responseHeaders['etag'] ?? '');

if (!$dryRun) {
    $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_string($encoded)) {
        @file_put_contents($cacheFile, $encoded . "\n");
    }
}

echo "\nSummary: checked={$checked} downloaded={$downloaded} deleted={$deleted} bytes_downloaded={$bytesDownloaded}\n";

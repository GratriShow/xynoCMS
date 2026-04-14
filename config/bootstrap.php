<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

// Optional local env file for dev/prod (kept out of version control).
// Format: KEY=VALUE, supports quotes, ignores blank lines and comments (# ...).
function load_env_local(string $filePath): void
{
    if (!is_file($filePath)) {
        return;
    }

    $raw = @file_get_contents($filePath);
    if (!is_string($raw) || $raw === '') {
        return;
    }

    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        if ($key === '' || !preg_match('/^[A-Z0-9_]{2,64}$/', $key)) {
            continue;
        }

        // Remove optional surrounding quotes.
        if (strlen($value) >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && $value[strlen($value) - 1] === $q) {
                $value = substr($value, 1, -1);
            }
        }

        // Do not override already-provided env.
        $existing = $_ENV[$key] ?? ($_SERVER[$key] ?? getenv($key));
        if (is_string($existing) && trim($existing) !== '') {
            continue;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        @putenv($key . '=' . $value);
    }
}

load_env_local(__DIR__ . '/.env.local');

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_name('xyno_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION['__init'])) {
        $_SESSION['__init'] = 1;
        session_regenerate_id(true);
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName === '') {
        return '';
    }

    $dir = str_replace('\\', '/', dirname($scriptName));
    $dir = rtrim($dir, '/');
    if ($dir === '' || $dir === '.') {
        return '';
    }

    // App root can be a parent of known subfolders (api/dashboard/auth/launcher).
    $segments = explode('/', trim($dir, '/'));
    $last = strtolower((string)end($segments));
    if (in_array($last, ['api', 'dashboard', 'auth', 'launcher'], true)) {
        array_pop($segments);
    }

    $base = implode('/', array_filter($segments, fn ($s) => $s !== ''));
    return $base === '' ? '' : '/' . $base;
}

function path_for(string $path): string
{
    if ($path === '') {
        return base_path() ?: '/';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if ($path[0] === '/') {
        return base_path() . $path;
    }

    $base = base_path();
    return ($base !== '' ? $base . '/' : '/') . $path;
}

function redirect(string $to): never
{
    header('Location: ' . path_for($to));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function current_user(): ?array
{
    start_secure_session();

    if (empty($_SESSION['user_id']) || empty($_SESSION['user_uuid']) || empty($_SESSION['user_email'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'uuid' => (string) $_SESSION['user_uuid'],
        'email' => (string) $_SESSION['user_email'],
    ];
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/login.php');
    }

    return $user;
}

function flash_set(string $key, string $message): void
{
    start_secure_session();
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    start_secure_session();
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $msg = (string) $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function csrf_token(): string
{
    start_secure_session();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    start_secure_session();
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    if ($stored === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($stored, (string)$token);
}

function public_root(): string
{
    return dirname(__DIR__);
}

function files_storage_root(): string
{
    return public_root() . '/files';
}

function files_type_to_dir(string $type): string
{
    return match (strtolower($type)) {
        'mod' => 'mods',
        'config' => 'config',
        'asset' => 'assets',
        'version' => 'versions',
        default => '',
    };
}

function sanitize_filename(string $name): string
{
    $name = trim($name);
    $name = str_replace("\0", '', $name);
    $name = str_replace(['\\', '/'], '-', $name);
    $name = basename($name);

    // Keep it simple: allow letters/numbers/._- and strip the rest.
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
    $name = trim($name, '.- ');

    if ($name === '') {
        $name = 'file';
    }

    // Prevent special dot-files and reserved names
    if ($name === '.' || $name === '..') {
        $name = 'file';
    }

    // Hard limit
    if (strlen($name) > 180) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = substr($base, 0, 180 - (strlen($ext) ? (strlen($ext) + 1) : 0));
        $name = $base . (strlen($ext) ? ('.' . $ext) : '');
    }

    return $name;
}

function files_allowed_extensions(string $type): array
{
    return match (strtolower($type)) {
        'mod' => ['jar'],
        'config' => ['json', 'cfg', 'toml', 'properties', 'txt', 'yml', 'yaml'],
        'asset' => ['png', 'jpg', 'jpeg', 'webp', 'json', 'txt', 'ogg', 'mp3'],
        'version' => ['json', 'jar', 'zip'],
        default => [],
    };
}

function files_max_upload_bytes(): int
{
    // 200 MB default. Also depends on php.ini (upload_max_filesize/post_max_size).
    return 200 * 1024 * 1024;
}

function sanitize_path_segment(string $value, int $maxLen = 64): string
{
    $value = trim($value);
    $value = str_replace("\0", '', $value);
    $value = preg_replace('/[^0-9A-Za-z._-]+/', '-', $value) ?? '';
    $value = trim($value, '.- ');
    if ($value === '') {
        return '';
    }
    if (strlen($value) > $maxLen) {
        $value = substr($value, 0, $maxLen);
        $value = rtrim($value, '.- ');
    }
    return $value;
}

function minecraft_relative_path(string $type, string $fileName, string $module = '', string $mcVersion = ''): string
{
    $dir = files_type_to_dir($type);
    if ($dir === '') {
        throw new RuntimeException('Invalid file type');
    }

    $safeName = sanitize_filename($fileName);

    $rel = $dir;
    $type = strtolower($type);

    if (in_array($type, ['config', 'asset'], true)) {
        $moduleSeg = sanitize_path_segment($module);
        if ($moduleSeg !== '') {
            $rel .= '/' . $moduleSeg;
        }
    }

    if ($type === 'version') {
        $mcSeg = sanitize_path_segment($mcVersion, 32);
        if ($mcSeg !== '') {
            $rel .= '/' . $mcSeg;
        }
    }

    $rel .= '/' . $safeName;

    if (str_contains($rel, "\0") || preg_match('#(^|/)\.{1,2}(/|$)#', $rel)) {
        throw new RuntimeException('Invalid relative path');
    }

    return $rel;
}

function files_build_relative_path(string $launcherUuid, string $type, string $fileName, string $mcVersion = '', string $module = ''): string
{
    $dir = files_type_to_dir($type);
    if ($dir === '') {
        throw new RuntimeException('Invalid file type');
    }

    $launcherUuid = trim($launcherUuid);
    if ($launcherUuid === '' || strlen($launcherUuid) > 64) {
        throw new RuntimeException('Invalid launcher');
    }

    $safeName = sanitize_filename($fileName);
    $path = '/files/' . $launcherUuid . '/' . $dir;

    // Prevent collisions between module-scoped files on disk.
    $type = strtolower($type);
    if (in_array($type, ['config', 'asset'], true)) {
        $moduleSeg = sanitize_path_segment($module);
        if ($moduleSeg !== '') {
            $path .= '/' . $moduleSeg;
        }
    }

    if ($type === 'version') {
        $mcVersion = trim($mcVersion);
        if ($mcVersion !== '') {
            $mcVersion = sanitize_path_segment($mcVersion, 32);
            if ($mcVersion !== '') {
                $path .= '/' . $mcVersion;
            }
        }
    }

    return $path . '/' . $safeName;
}

function files_build_disk_path_from_relative(string $relativePath): string
{
    // Ensure relativePath always starts with /files/
    if (!str_starts_with($relativePath, '/files/')) {
        throw new RuntimeException('Invalid relative path');
    }

    if (str_contains($relativePath, "\0") || preg_match('#(^|/)\.\.(?:/|$)#', $relativePath)) {
        throw new RuntimeException('Invalid path');
    }

    return public_root() . $relativePath;
}

function ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory');
    }
}

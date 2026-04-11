<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function api_json_header(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

function api_json(array $payload, int $statusCode = 200): never
{
    api_json_header();
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_param(string $key, int $maxLen = 500): string
{
    $value = (string)($_GET[$key] ?? '');
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) > $maxLen) {
        return '';
    }
    return $value;
}

function api_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return '0.0.0.0';
    }
    return $ip;
}

function api_log(string $endpoint, string $ip, ?string $launcherUuid, int $statusCode, ?string $message = null): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO api_logs (endpoint, ip, launcher_uuid, status_code, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$endpoint, $ip, $launcherUuid, $statusCode, $message]);
    } catch (Throwable $e) {
        // Logging must never break the API.
    }
}

function api_rate_limit(string $endpoint, string $ip, int $limit = 120, int $windowSeconds = 60): void
{
    // Simple sliding-window by minute bucket. If table doesn't exist, fail open.
    try {
        $pdo = db();
        $bucket = (new DateTimeImmutable('now'))->setTime((int)date('H'), (int)date('i'), 0);
        $windowStart = $bucket->format('Y-m-d H:i:s');

        $pdo->beginTransaction();

        $sel = $pdo->prepare('SELECT id, count FROM api_rate_limits WHERE ip = ? AND endpoint = ? AND window_start = ? FOR UPDATE');
        $sel->execute([$ip, $endpoint, $windowStart]);
        $row = $sel->fetch();

        if ($row) {
            $count = (int)$row['count'] + 1;
            $upd = $pdo->prepare('UPDATE api_rate_limits SET count = ? WHERE id = ?');
            $upd->execute([$count, (int)$row['id']]);
        } else {
            $count = 1;
            $ins = $pdo->prepare('INSERT INTO api_rate_limits (ip, endpoint, window_start, count) VALUES (?, ?, ?, ?)');
            $ins->execute([$ip, $endpoint, $windowStart, $count]);
        }

        $pdo->commit();

        if ($count > $limit) {
            api_log($endpoint, $ip, null, 429, 'rate_limited');
            api_json(['error' => 'Too Many Requests'], 429);
        }
    } catch (Throwable $e) {
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e2) {
        }
        // Fail open if rate limiting is not available.
    }
}

function api_get_launcher_by_uuid(string $uuid): ?array
{
    $pdo = db();

    try {
        $stmt = $pdo->prepare('SELECT id, user_id, uuid, api_key, client_integrity_sha256, name, description, version, loader, theme, modules, last_ping FROM launchers WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if ($row) {
            if (!isset($row['modules']) || $row['modules'] === null) {
                $row['modules'] = '';
            }
            if (!isset($row['client_integrity_sha256']) || $row['client_integrity_sha256'] === null) {
                $row['client_integrity_sha256'] = '';
            }
            return $row;
        }
        return null;
    } catch (PDOException $e) {
        // Backward compatibility (older schema without `modules`)
        $raw = $e->getMessage();
        if (stripos($raw, 'unknown column') === false) {
            throw $e;
        }

        $missingModules = stripos($raw, 'modules') !== false;
        $missingIntegrity = stripos($raw, 'client_integrity_sha256') !== false;
        if (!$missingModules && !$missingIntegrity) {
            throw $e;
        }

        $stmt = $pdo->prepare('SELECT id, user_id, uuid, api_key, name, description, version, loader, theme, last_ping FROM launchers WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['modules'] = '';
        $row['client_integrity_sha256'] = '';
        return $row;
    }
}

function api_parse_modules(?string $modulesCsv): array
{
    $modulesCsv = (string)($modulesCsv ?? '');
    $modulesCsv = trim($modulesCsv);
    if ($modulesCsv === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $modulesCsv) as $m) {
        $m = strtolower(trim($m));
        if ($m !== '') {
            $out[$m] = true;
        }
    }
    return $out;
}

function api_public_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    }

    // Use app base path (from bootstrap), not the current script directory.
    $base = base_path();
    return $scheme . '://' . $host . $base;
}

function api_public_url(string $pathOrUrl): string
{
    if ($pathOrUrl === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $pathOrUrl)) {
        return $pathOrUrl;
    }
    if ($pathOrUrl[0] !== '/') {
        $pathOrUrl = '/' . $pathOrUrl;
    }
    return rtrim(api_public_base_url(), '/') . $pathOrUrl;
}

function api_validate_key(array $launcher, string $key): bool
{
    $stored = (string)($launcher['api_key'] ?? '');
    if ($stored === '' || $key === '') {
        return false;
    }
    return hash_equals($stored, $key);
}

function api_check_subscription(int $launcherId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT status, expires_at FROM subscriptions WHERE launcher_id = ? ORDER BY expires_at IS NULL DESC, expires_at DESC, id DESC LIMIT 1');
    $stmt->execute([$launcherId]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    if ((string)$row['status'] !== 'active') {
        return false;
    }

    $expiresAt = $row['expires_at'];
    if ($expiresAt === null || $expiresAt === '') {
        return true;
    }

    return strtotime((string)$expiresAt) > time();
}

function api_touch_last_ping(int $launcherId): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE launchers SET last_ping = NOW() WHERE id = ?');
        $stmt->execute([$launcherId]);
    } catch (Throwable $e) {
    }
}

// -----------------------------
// Launcher client auto-update
// -----------------------------

function api_get_latest_client_release(int $launcherId): ?array
{
    if ($launcherId <= 0) {
        return null;
    }

    $pdo = db();

    try {
        $stmt = $pdo->prepare(
            'SELECT version_name, zip_url, zip_sha256, required '
            . 'FROM launcher_client_releases '
            . 'WHERE launcher_id = ? AND is_active = 1 '
            . 'ORDER BY created_at DESC, id DESC '
            . 'LIMIT 1'
        );
        $stmt->execute([$launcherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'version' => (string)($row['version_name'] ?? ''),
            'zip_url' => (string)($row['zip_url'] ?? ''),
            'zip_sha256' => strtolower(trim((string)($row['zip_sha256'] ?? ''))),
            'required' => (int)($row['required'] ?? 0) ? true : false,
        ];
    } catch (Throwable $e) {
        // Table may not exist yet; fail closed at the endpoint level.
        return null;
    }
}

// -----------------------------
// Launcher dynamic content (news + config)
// -----------------------------

function api_get_launcher_news(int $launcherId, int $limit = 5): array
{
    if ($launcherId <= 0) {
        return [];
    }
    $limit = max(1, min(20, $limit));

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT title, content, published_at '
            . 'FROM launcher_news '
            . 'WHERE launcher_id = ? '
            . 'ORDER BY published_at DESC, id DESC '
            . 'LIMIT ' . (int)$limit
        );
        $stmt->execute([$launcherId]);

        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = [
                'title' => (string)($row['title'] ?? ''),
                'content' => (string)($row['content'] ?? ''),
                'date' => (string)($row['published_at'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function api_get_launcher_config(int $launcherId): array
{
    if ($launcherId <= 0) {
        return [];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT config_key, config_value FROM launcher_configs WHERE launcher_id = ?');
        $stmt->execute([$launcherId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $k = strtolower(trim((string)($row['config_key'] ?? '')));
            if ($k === '' || strlen($k) > 64) {
                continue;
            }
            // Only allow safe keys: a-z 0-9 _ -
            if (!preg_match('/^[a-z0-9_-]+$/', $k)) {
                continue;
            }
            $out[$k] = (string)($row['config_value'] ?? '');
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

// -----------------------------
// v2 signed requests + JWT
// -----------------------------

function api_header(string $name, int $maxLen = 4000): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = (string)($_SERVER[$key] ?? '');
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) > $maxLen) {
        return '';
    }
    return $value;
}

function api_method(): string
{
    $m = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $m = strtoupper(trim($m));
    return $m !== '' ? $m : 'GET';
}

function api_request_path(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') {
        return (string)($_SERVER['SCRIPT_NAME'] ?? '');
    }
    $path = parse_url($uri, PHP_URL_PATH);
    return is_string($path) ? $path : '';
}

function api_read_raw_body(int $maxBytes = 262144): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw)) {
        $cached = '';
        return '';
    }
    if (strlen($raw) > $maxBytes) {
        $cached = '';
        return '';
    }
    $cached = $raw;
    return $raw;
}

function api_read_json_body(int $maxBytes = 262144): array
{
    $raw = api_read_raw_body($maxBytes);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function api_base64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function api_base64url_decode(string $b64url): string
{
    $s = strtr($b64url, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($s, true);
    return is_string($out) ? $out : '';
}

function api_env(string $name, string $default = ''): string
{
    // Prefer superglobals (some servers inject env here instead of process env).
    $fromEnv = $_ENV[$name] ?? null;
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return trim($fromEnv);
    }

    $fromServer = $_SERVER[$name] ?? null;
    if (is_string($fromServer) && trim($fromServer) !== '') {
        return trim($fromServer);
    }

    $v = getenv($name);
    if ($v === false) {
        return $default;
    }
    $v = trim((string)$v);
    return $v !== '' ? $v : $default;
}

function api_jwt_secret(): string
{
    // Configure in server env: XYNO_JWT_SECRET (32+ chars recommended)
    return api_env('XYNO_JWT_SECRET', '');
}

function api_hmac_sha256_hex(string $key, string $data): string
{
    return hash_hmac('sha256', $data, $key);
}

function api_sha256_hex(string $data): string
{
    return hash('sha256', $data);
}

function api_jwt_sign(array $claims, int $ttlSeconds): string
{
    $secret = api_jwt_secret();
    if ($secret === '') {
        return '';
    }

    $now = time();
    $claims = array_merge($claims, [
        'iat' => $now,
        'nbf' => $now - 1,
        'exp' => $now + max(1, $ttlSeconds),
    ]);

    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $h = api_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = api_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $sig = api_base64url_encode(hash_hmac('sha256', $h . '.' . $p, $secret, true));
    return $h . '.' . $p . '.' . $sig;
}

function api_jwt_verify(string $token): ?array
{
    $secret = api_jwt_secret();
    if ($secret === '') {
        return null;
    }

    $token = trim($token);
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$h64, $p64, $s64] = $parts;
    if ($h64 === '' || $p64 === '' || $s64 === '') {
        return null;
    }

    $expected = api_base64url_encode(hash_hmac('sha256', $h64 . '.' . $p64, $secret, true));
    if (!hash_equals($expected, $s64)) {
        return null;
    }

    $payloadRaw = api_base64url_decode($p64);
    if ($payloadRaw === '') {
        return null;
    }
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        return null;
    }

    $now = time();
    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    $nbf = isset($payload['nbf']) ? (int)$payload['nbf'] : 0;
    if ($exp <= 0 || $now >= $exp) {
        return null;
    }
    if ($nbf > 0 && $now + 1 < $nbf) {
        return null;
    }
    return $payload;
}

function api_get_launcher_by_id(int $launcherId): ?array
{
    if ($launcherId <= 0) {
        return null;
    }
    $pdo = db();
    try {
        $stmt = $pdo->prepare('SELECT id, user_id, uuid, api_key, client_integrity_sha256, name, description, version, loader, theme, modules, last_ping FROM launchers WHERE id = ? LIMIT 1');
        $stmt->execute([$launcherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!isset($row['modules']) || $row['modules'] === null) {
            $row['modules'] = '';
        }
        if (!isset($row['client_integrity_sha256']) || $row['client_integrity_sha256'] === null) {
            $row['client_integrity_sha256'] = '';
        }
        return $row;
    } catch (PDOException $e) {
        $raw = $e->getMessage();
        if (stripos($raw, 'unknown column') === false) {
            throw $e;
        }
        $stmt = $pdo->prepare('SELECT id, user_id, uuid, api_key, name, description, version, loader, theme, last_ping FROM launchers WHERE id = ? LIMIT 1');
        $stmt->execute([$launcherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['modules'] = '';
        $row['client_integrity_sha256'] = '';
        return $row;
    }
}

function api_nonce_try_use(int $launcherId, string $sessionKey, string $nonce): bool
{
    $nonce = trim($nonce);
    $sessionKey = trim($sessionKey);
    if ($launcherId <= 0 || $sessionKey === '' || $nonce === '') {
        return false;
    }
    if (strlen($sessionKey) > 64 || strlen($nonce) > 96) {
        return false;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO api_nonces (launcher_id, session_key, nonce, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$launcherId, $sessionKey, $nonce]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function api_v2_cleanup(): void
{
    // Best-effort cleanup; never fail requests.
    try {
        $pdo = db();
        $pdo->exec('DELETE FROM api_nonces WHERE created_at < (NOW() - INTERVAL 30 MINUTE)');
        $pdo->exec('DELETE FROM api_sessions WHERE expires_at < (NOW() - INTERVAL 1 HOUR)');
    } catch (Throwable $e) {
        // ignore
    }
}

function api_session_create(int $launcherId, string $ip, string $userAgent, int $ttlSeconds = 1800): ?array
{
    if ($launcherId <= 0) {
        return null;
    }

    $sessionId = uuid_v4();
    $secretHex = bin2hex(random_bytes(32));
    $ttlSeconds = max(60, min(86400, $ttlSeconds));
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO api_sessions (session_id, launcher_id, secret_hex, ip, user_agent, created_at, last_seen_at, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, NULL)');
        $stmt->execute([$sessionId, $launcherId, $secretHex, $ip, substr($userAgent, 0, 250), $expiresAt]);
        return [
            'session_id' => $sessionId,
            'secret_hex' => $secretHex,
            'expires_at' => $expiresAt,
            'ttl' => $ttlSeconds,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function api_session_get(string $sessionId): ?array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '' || strlen($sessionId) > 64) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT session_id, launcher_id, secret_hex, ip, user_agent, created_at, last_seen_at, expires_at, revoked_at FROM api_sessions WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function api_session_touch(string $sessionId): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE api_sessions SET last_seen_at = NOW() WHERE session_id = ?');
        $stmt->execute([$sessionId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function api_v2_require_auth(string $endpoint, bool $requireSubscription = true): array
{
    $ip = api_client_ip();

    $auth = api_header('Authorization');
    if ($auth === '' || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        api_log($endpoint, $ip, null, 401, 'missing_bearer');
        api_json(['error' => 'Unauthorized'], 401);
    }
    $token = trim((string)$m[1]);
    $claims = api_jwt_verify($token);
    if ($claims === null) {
        api_log($endpoint, $ip, null, 401, 'invalid_token');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $sessionId = api_header('X-Session-Id', 128);
    $ts = api_header('X-TS', 64);
    $nonce = api_header('X-Nonce', 128);
    $sig = api_header('X-Sig', 128);

    $sidClaim = isset($claims['sid']) ? (string)$claims['sid'] : '';
    if ($sessionId === '' || $sidClaim === '' || !hash_equals($sidClaim, $sessionId)) {
        api_log($endpoint, $ip, null, 401, 'sid_mismatch');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $launcherId = isset($claims['lid']) ? (int)$claims['lid'] : 0;
    $launcherUuid = isset($claims['uuid']) ? (string)$claims['uuid'] : null;

    $session = api_session_get($sessionId);
    if ($session === null) {
        api_log($endpoint, $ip, $launcherUuid, 401, 'unknown_session');
        api_json(['error' => 'Unauthorized'], 401);
    }
    if ((int)$session['launcher_id'] !== $launcherId) {
        api_log($endpoint, $ip, $launcherUuid, 401, 'session_launcher_mismatch');
        api_json(['error' => 'Unauthorized'], 401);
    }
    if (!empty($session['revoked_at'])) {
        api_log($endpoint, $ip, $launcherUuid, 401, 'session_revoked');
        api_json(['error' => 'Unauthorized'], 401);
    }
    $expiresAt = (string)($session['expires_at'] ?? '');
    if ($expiresAt === '' || strtotime($expiresAt) <= time()) {
        api_log($endpoint, $ip, $launcherUuid, 401, 'session_expired');
        api_json(['error' => 'Unauthorized'], 401);
    }

    // Optional IP binding
    $enforceIp = api_env('XYNO_API_ENFORCE_IP', '0') === '1';
    if ($enforceIp) {
        $sessIp = (string)($session['ip'] ?? '');
        if ($sessIp !== '' && $sessIp !== $ip) {
            api_log($endpoint, $ip, $launcherUuid, 401, 'ip_mismatch');
            api_json(['error' => 'Unauthorized'], 401);
        }
    }

    $tsInt = (int)$ts;
    $now = time();
    if ($ts === '' || $tsInt <= 0 || abs($now - $tsInt) > 120) {
        api_log($endpoint, $ip, $launcherUuid, 400, 'bad_ts');
        api_json(['error' => 'Bad request'], 400);
    }
    if ($nonce === '' || strlen($nonce) < 8 || strlen($nonce) > 96) {
        api_log($endpoint, $ip, $launcherUuid, 400, 'bad_nonce');
        api_json(['error' => 'Bad request'], 400);
    }
    if ($sig === '' || strlen($sig) < 32 || strlen($sig) > 128) {
        api_log($endpoint, $ip, $launcherUuid, 400, 'bad_sig');
        api_json(['error' => 'Bad request'], 400);
    }

    $sessionKey = 'sid:' . $sessionId;
    if (!api_nonce_try_use($launcherId, $sessionKey, $nonce)) {
        api_log($endpoint, $ip, $launcherUuid, 409, 'replay');
        api_json(['error' => 'Replay detected'], 409);
    }

    $method = api_method();
    $path = api_request_path();
    $rawBody = api_read_raw_body();
    $bodyHash = api_sha256_hex($rawBody);

    $base = $method . "\n" . $path . "\n" . $tsInt . "\n" . $nonce . "\n" . $bodyHash;
    $expectedSig = api_hmac_sha256_hex((string)($session['secret_hex'] ?? ''), $base);
    if (!hash_equals($expectedSig, $sig)) {
        api_log($endpoint, $ip, $launcherUuid, 401, 'bad_hmac');
        api_json(['error' => 'Unauthorized'], 401);
    }

    $launcher = api_get_launcher_by_id($launcherId);
    if ($launcher === null) {
        api_log($endpoint, $ip, $launcherUuid, 401, 'invalid_launcher');
        api_json(['error' => 'Unauthorized'], 401);
    }

    if ($requireSubscription) {
        $active = api_check_subscription((int)$launcher['id']);
        if (!$active) {
            api_touch_last_ping((int)$launcher['id']);
            api_log($endpoint, $ip, (string)$launcher['uuid'], 403, 'subscription_inactive');
            api_json(['error' => 'Subscription expired'], 403);
        }
    }

    api_session_touch($sessionId);
    api_touch_last_ping((int)$launcher['id']);
    api_v2_cleanup();

    return [
        'launcher' => $launcher,
        'claims' => $claims,
        'session' => $session,
        'ip' => $ip,
    ];
}

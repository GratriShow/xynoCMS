<?php

declare(strict_types=1);

/**
 * Helpers for the refonte of the "Fichiers" area in the dashboard.
 *
 *  - file_event_log()     write a row to file_events (best-effort)
 *  - file_format_size()   1.2 MB / 245 KB style
 *  - file_quota_for_plan() per-plan storage cap (bytes)
 *  - file_active_plan_for_launcher() looks up subscription plan, fallback 'free'
 *  - file_safe_folder_path() sanitize a "logical folder" string the user types
 */

require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Best-effort insert into file_events. Silently swallows any error so the
 * caller can ignore failures (e.g. table missing on a stale schema).
 */
function file_event_log(
    PDO $pdo,
    int $launcherId,
    ?int $fileId,
    string $event,
    string $name = '',
    string $path = '',
    int $size = 0,
    string $actor = 'user',
    ?int $actorId = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO file_events '
          . '(launcher_id, file_id, event, actor, actor_id, path, name, size, ip, user_agent, created_at) '
          . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $launcherId,
            $fileId,
            $event,
            $actor,
            $actorId,
            $path,
            $name,
            $size,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // schéma pas migré : on ignore en silence
    }
}

/**
 * Convert a byte count to a short, human-readable label.
 */
function file_format_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    $val = (float)$bytes;
    do {
        $val /= 1024;
        $i++;
    } while ($val >= 1024 && $i < count($units) - 1);
    return number_format($val, $val >= 100 ? 0 : ($val >= 10 ? 1 : 2), ',', ' ') . ' ' . $units[$i];
}

/**
 * Storage quota per plan, in bytes.
 *  - free / no plan : 250 MB (just enough to test)
 *  - starter        : 10 GB
 *  - pro            : 10 GB
 *  - premium        : 10 GB
 */
function file_quota_for_plan(string $plan): int
{
    return match (strtolower(trim($plan))) {
        'starter' => 10 * 1024 * 1024 * 1024,
        'pro'     => 10 * 1024 * 1024 * 1024,
        'premium' => 10 * 1024 * 1024 * 1024,
        default   => 250 * 1024 * 1024,
    };
}

/**
 * Returns the active plan for the launcher (if any active subscription),
 * otherwise '' (treated as the free tier by quota helpers).
 */
function file_active_plan_for_launcher(PDO $pdo, int $launcherId): string
{
    try {
        $stmt = $pdo->prepare(
            "SELECT plan FROM subscriptions "
          . "WHERE launcher_id = ? AND status = 'active' "
          . "ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$launcherId]);
        $plan = (string)($stmt->fetchColumn() ?: '');
        return strtolower(trim($plan));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Sanitize a logical folder path the user types in the dashboard.
 * Allows: a/b/c style with letters, digits, dots, dashes, underscores.
 * Strips empty segments, leading/trailing slashes and traversal attempts.
 * Empty input → '' (root).
 */
function file_safe_folder_path(string $raw, int $maxLen = 255): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $raw = str_replace('\\', '/', $raw);
    $raw = trim($raw, '/');
    if ($raw === '' || str_contains($raw, "\0")) {
        return '';
    }
    $segments = [];
    foreach (explode('/', $raw) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '.' || $seg === '..') continue;
        $seg = preg_replace('/[^A-Za-z0-9._-]+/', '-', $seg) ?? '';
        $seg = trim($seg, '.- ');
        if ($seg === '') continue;
        if (strlen($seg) > 64) $seg = substr($seg, 0, 64);
        $segments[] = $seg;
    }
    $out = implode('/', $segments);
    if (strlen($out) > $maxLen) {
        $out = substr($out, 0, $maxLen);
        $out = rtrim($out, '/');
    }
    return $out;
}

/**
 * Aggregate stats for the launcher's files.
 *  - total_bytes, total_count
 *  - by_type   : ['mod' => ['count'=>x,'bytes'=>y], ...]
 *  - top_files : top 10 biggest files
 */
function file_stats_for_launcher(PDO $pdo, int $launcherId): array
{
    $out = [
        'total_bytes' => 0,
        'total_count' => 0,
        'by_type'     => [],
        'top_files'   => [],
    ];
    try {
        $r = $pdo->prepare('SELECT COALESCE(SUM(size),0), COUNT(*) FROM files WHERE launcher_id = ?');
        $r->execute([$launcherId]);
        $row = $r->fetch(PDO::FETCH_NUM);
        $out['total_bytes'] = (int)($row[0] ?? 0);
        $out['total_count'] = (int)($row[1] ?? 0);

        $r = $pdo->prepare(
            'SELECT type, COUNT(*) AS c, COALESCE(SUM(size),0) AS s '
          . 'FROM files WHERE launcher_id = ? GROUP BY type'
        );
        $r->execute([$launcherId]);
        foreach ($r->fetchAll() as $tr) {
            $out['by_type'][(string)$tr['type']] = [
                'count' => (int)$tr['c'],
                'bytes' => (int)$tr['s'],
            ];
        }

        $r = $pdo->prepare(
            'SELECT id, name, type, size, created_at '
          . 'FROM files WHERE launcher_id = ? ORDER BY size DESC LIMIT 10'
        );
        $r->execute([$launcherId]);
        $out['top_files'] = $r->fetchAll();
    } catch (Throwable $e) {
        // schema not ready
    }
    return $out;
}

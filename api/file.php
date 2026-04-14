<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

$endpoint = 'file';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 600, 60);

$uuid = api_param('uuid', 64);
$key = api_param('key', 128);
$id = (int)api_param('id', 32);

if ($uuid === '' || $key === '' || $id <= 0) {
    api_log($endpoint, $ip, $uuid ?: null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

function file_send_status(int $status, string $message): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_range(?string $header, int $size): ?array
{
    if ($header === null || $header === '') {
        return null;
    }

    // Only support single range: bytes=start-end
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
        return null;
    }

    $startRaw = $m[1];
    $endRaw = $m[2];

    if ($startRaw === '' && $endRaw === '') {
        return null;
    }

    if ($startRaw === '') {
        // suffix range: last N bytes
        $suffix = (int)$endRaw;
        if ($suffix <= 0) {
            return null;
        }
        $suffix = min($suffix, $size);
        $start = $size - $suffix;
        $end = $size - 1;
        return [$start, $end];
    }

    $start = (int)$startRaw;
    $end = ($endRaw === '') ? ($size - 1) : (int)$endRaw;

    if ($start < 0 || $end < 0 || $start > $end) {
        return null;
    }

    if ($start >= $size) {
        return null;
    }

    $end = min($end, $size - 1);
    return [$start, $end];
}

try {
    $launcher = api_get_launcher_by_uuid($uuid);
    if ($launcher === null) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_launcher');
        file_send_status(401, 'Unauthorized');
    }

    if (!api_validate_key($launcher, $key)) {
        api_log($endpoint, $ip, $uuid, 401, 'invalid_key');
        file_send_status(401, 'Unauthorized');
    }

    $isActive = api_check_subscription((int)$launcher['id']);
    if (!$isActive) {
        api_touch_last_ping((int)$launcher['id']);
        api_log($endpoint, $ip, $uuid, 403, 'subscription_inactive');
        file_send_status(403, 'Subscription expired');
    }

    api_touch_last_ping((int)$launcher['id']);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, path, name, hash, size FROM files WHERE id = ? AND launcher_id = ? LIMIT 1');
    $stmt->execute([$id, (int)$launcher['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        api_log($endpoint, $ip, $uuid, 404, 'not_found');
        file_send_status(404, 'Not found');
    }

    $relativeServerPath = (string)($row['path'] ?? '');
    if ($relativeServerPath === '') {
        api_log($endpoint, $ip, $uuid, 404, 'missing_path');
        file_send_status(404, 'Not found');
    }

    try {
        $diskPath = files_build_disk_path_from_relative($relativeServerPath);
    } catch (Throwable $e) {
        api_log($endpoint, $ip, $uuid, 404, 'invalid_path');
        file_send_status(404, 'Not found');
    }

    if (!is_file($diskPath)) {
        api_log($endpoint, $ip, $uuid, 404, 'missing_file');
        file_send_status(404, 'Not found');
    }

    $size = filesize($diskPath);
    if ($size === false) {
        $size = (int)($row['size'] ?? 0);
    }
    $size = (int)$size;

    $fileName = (string)($row['name'] ?? 'file');
    if ($fileName === '') {
        $fileName = 'file';
    }

    $etag = '"' . (string)($row['hash'] ?? '') . '"';
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
    header('Accept-Ranges: bytes');
    if ($etag !== '""') {
        header('ETag: ' . $etag);
    }

    $ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($etag !== '""' && $ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
        http_response_code(304);
        exit;
    }

    $range = parse_range((string)($_SERVER['HTTP_RANGE'] ?? ''), $size);
    $fh = fopen($diskPath, 'rb');
    if ($fh === false) {
        api_log($endpoint, $ip, $uuid, 500, 'open_failed');
        file_send_status(500, 'Server error');
    }

    if ($range) {
        [$start, $end] = $range;
        $length = $end - $start + 1;

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($fh);
        api_log($endpoint, $ip, $uuid, 206, 'ok_range');
        exit;
    }

    http_response_code(200);
    header('Content-Length: ' . $size);

    while (!feof($fh)) {
        $chunk = fread($fh, 8192);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
    }
    fclose($fh);

    api_log($endpoint, $ip, $uuid, 200, 'ok');
    exit;
} catch (Throwable $e) {
    api_log($endpoint, $ip, $uuid, 500, 'server_error');
    file_send_status(500, 'Server error');
}

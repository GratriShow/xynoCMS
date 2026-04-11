<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils.php';

$endpoint = 'v2_file';
$ip = api_client_ip();
api_rate_limit($endpoint, $ip, 900, 60);

$id = (int)api_param('id', 32);
if ($id <= 0) {
    api_log($endpoint, $ip, null, 400, 'missing_params');
    api_json(['error' => 'Missing parameters'], 400);
}

function v2_file_send_status(int $status, string $message): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function v2_parse_range(?string $header, int $size): ?array
{
    if ($header === null || $header === '') {
        return null;
    }

    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
        return null;
    }

    $startRaw = $m[1];
    $endRaw = $m[2];

    if ($startRaw === '' && $endRaw === '') {
        return null;
    }

    if ($startRaw === '') {
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
    $ctx = api_v2_require_auth($endpoint, true);
    $launcher = $ctx['launcher'];

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, path, name, hash, size FROM files WHERE id = ? AND launcher_id = ? LIMIT 1');
    $stmt->execute([$id, (int)$launcher['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        api_log($endpoint, $ip, (string)$launcher['uuid'], 404, 'not_found');
        v2_file_send_status(404, 'Not found');
    }

    $relativeServerPath = (string)($row['path'] ?? '');
    if ($relativeServerPath === '') {
        api_log($endpoint, $ip, (string)$launcher['uuid'], 404, 'missing_path');
        v2_file_send_status(404, 'Not found');
    }

    try {
        $diskPath = files_build_disk_path_from_relative($relativeServerPath);
    } catch (Throwable $e) {
        api_log($endpoint, $ip, (string)$launcher['uuid'], 404, 'invalid_path');
        v2_file_send_status(404, 'Not found');
    }

    if (!is_file($diskPath)) {
        api_log($endpoint, $ip, (string)$launcher['uuid'], 404, 'missing_file');
        v2_file_send_status(404, 'Not found');
    }

    $size = (int)filesize($diskPath);
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
    $range = v2_parse_range(is_string($rangeHeader) ? $rangeHeader : null, $size);

    $fp = fopen($diskPath, 'rb');
    if ($fp === false) {
        api_log($endpoint, $ip, (string)$launcher['uuid'], 500, 'open_failed');
        v2_file_send_status(500, 'Server error');
    }

    $fileName = (string)($row['name'] ?? 'file');
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?? 'file';
    $safeName = trim($safeName, '.- ');
    if ($safeName === '') {
        $safeName = 'file';
    }

    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Type: application/octet-stream');

    if ($range === null) {
        header('Content-Length: ' . $size);
        http_response_code(200);
        fpassthru($fp);
        fclose($fp);
        api_log($endpoint, $ip, (string)$launcher['uuid'], 200, 'ok');
        exit;
    }

    [$start, $end] = $range;
    $length = $end - $start + 1;

    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . $length);
    http_response_code(206);

    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, (int)min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }

    fclose($fp);
    api_log($endpoint, $ip, (string)$launcher['uuid'], 206, 'partial_ok');
    exit;
} catch (Throwable $e) {
    api_log($endpoint, $ip, null, 500, 'server_error');
    v2_file_send_status(500, 'Server error');
}

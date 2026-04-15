<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$uuid = trim((string)($_GET['uuid'] ?? ''));
$platform = strtolower(trim((string)($_GET['platform'] ?? '')));

if ($uuid === '' || strlen($uuid) > 64) {
    http_response_code(400);
    echo 'Missing uuid.';
    exit;
}

$allowed = ['win', 'mac', 'linux'];
if ($platform !== '' && !in_array($platform, $allowed, true)) {
    http_response_code(400);
    echo 'Invalid platform.';
    exit;
}

function detect_platform_from_ua(string $ua): string
{
    $u = strtolower($ua);
    if (strpos($u, 'windows') !== false) return 'win';
    if (strpos($u, 'mac os') !== false || strpos($u, 'macintosh') !== false) return 'mac';
    // Default: linux
    return 'linux';
}

function sanitize_download_basename(string $name): string
{
  $name = trim($name);
  if ($name === '') return 'Launcher';
  $name = preg_replace('/\s+/', ' ', $name) ?? $name;
  // Keep it filesystem/browser friendly
  $name = preg_replace('/[^A-Za-z0-9._ -]/', '-', $name) ?? $name;
  $name = trim($name, " .-");
  return $name !== '' ? $name : 'Launcher';
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

function send_file_download(string $diskPath, string $downloadName): never
{
  if (!is_file($diskPath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
  }

  $size = filesize($diskPath);
  if ($size === false) {
    http_response_code(500);
    echo 'Server error.';
    exit;
  }

  // Avoid corrupting binary downloads via output buffering/compression
  @ini_set('zlib.output_compression', '0');
  if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
      @ob_end_clean();
    }
  }
  @set_time_limit(0);

  $ext = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
  $contentType = match ($ext) {
    'dmg' => 'application/x-apple-diskimage',
    'exe' => 'application/vnd.microsoft.portable-executable',
    'appimage' => 'application/octet-stream',
    default => 'application/octet-stream',
  };

  header('X-Content-Type-Options: nosniff');
  header('Content-Type: ' . $contentType);
  header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
  header('Accept-Ranges: bytes');

  $range = parse_range((string)($_SERVER['HTTP_RANGE'] ?? ''), (int)$size);
  $fh = fopen($diskPath, 'rb');
  if ($fh === false) {
    http_response_code(500);
    echo 'Server error.';
    exit;
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
      $chunk = fread($fh, (int)min(1024 * 1024, $remaining));
      if ($chunk === false || $chunk === '') break;
      echo $chunk;
      $remaining -= strlen($chunk);
    }
    fclose($fh);
    exit;
  }

  http_response_code(200);
  header('Content-Length: ' . $size);
  while (!feof($fh)) {
    $chunk = fread($fh, 1024 * 1024);
    if ($chunk === false) break;
    echo $chunk;
  }
  fclose($fh);
  exit;
}

if ($platform === '') {
    $platform = detect_platform_from_ua((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, name FROM launchers WHERE uuid = ? LIMIT 1');
    $stmt->execute([$uuid]);
    $launcher = $stmt->fetch();
    if (!$launcher) {
        http_response_code(404);
        echo 'Launcher not found.';
        exit;
    }

    $launcherId = (int)($launcher['id'] ?? 0);
    $launcherName = (string)($launcher['name'] ?? 'Launcher');

    $warning = '';

    $links = ['win' => null, 'mac' => null, 'linux' => null];

    $row = null;
    try {
      $sel = $pdo->prepare(
        'SELECT file_url, version_name '
        . 'FROM launcher_downloads '
        . 'WHERE launcher_id = ? AND platform = ? AND is_active = 1 '
        . 'ORDER BY created_at DESC, id DESC '
        . 'LIMIT 1'
      );
      $sel->execute([$launcherId, $platform]);
      $row = $sel->fetch();
    } catch (Throwable $e) {
      $msg = strtolower((string)$e->getMessage());
      if (str_contains($msg, 'launcher_downloads') && (str_contains($msg, "doesn't exist") || str_contains($msg, 'does not exist'))) {
        $warning = 'Distribution non initialisée : importe `migrations_api.sql` (table launcher_downloads manquante).';
      }
      $row = null;
    }

    // If nothing is marked active yet, serve the latest uploaded installer for this platform.
    if (!$row) {
      try {
        $selLatest = $pdo->prepare(
          'SELECT file_url, version_name '
          . 'FROM launcher_downloads '
          . 'WHERE launcher_id = ? AND platform = ? '
          . 'ORDER BY is_active DESC, created_at DESC, id DESC '
          . 'LIMIT 1'
        );
        $selLatest->execute([$launcherId, $platform]);
        $row = $selLatest->fetch();
      } catch (Throwable $e) {
        // Keep $row null.
      }
    }

    if ($row && isset($row['file_url']) && is_string($row['file_url']) && trim($row['file_url']) !== '') {
      $url = trim((string)$row['file_url']);

      // Prefer serving locally so we can control the download name.
      $urlPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
      if ($urlPath !== '' && str_starts_with($urlPath, '/files/') && strpos($urlPath, '..') === false) {
        $filesRoot = realpath(__DIR__ . '/files');
        $candidate = realpath(__DIR__ . $urlPath);
        if ($filesRoot && $candidate && str_starts_with($candidate, $filesRoot . DIRECTORY_SEPARATOR)) {
          $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
          $base = sanitize_download_basename($launcherName);
          $compact = preg_replace('/\s+/', '', $base) ?? $base;
          if (!preg_match('/launcher$/i', $compact)) {
            $base .= 'Launcher';
          }
          $downloadName = $base . ($ext !== '' ? ('.' . $ext) : '');
          send_file_download($candidate, $downloadName);
        }
      }

      // Fallback: external URL (or not on local disk)
      header('Location: ' . $url, true, 302);
      exit;
    }

    // No installer for this platform: show fallback page with whatever is available.
    try {
      $all = $pdo->prepare(
        'SELECT platform, file_url, version_name '
        . 'FROM launcher_downloads '
        . 'WHERE launcher_id = ? '
        . 'ORDER BY platform ASC, is_active DESC, created_at DESC, id DESC'
      );
      $all->execute([$launcherId]);
      $rows = $all->fetchAll();

      foreach ($rows as $r) {
        $p = (string)($r['platform'] ?? '');
        if (!isset($links[$p]) || $links[$p] !== null) continue;
        $u = trim((string)($r['file_url'] ?? ''));
        if ($u !== '') {
          $links[$p] = [
            'url' => $u,
            'version' => (string)($r['version_name'] ?? ''),
          ];
        }
      }
    } catch (Throwable $e) {
      // Keep empty links.
      if ($warning === '') {
        $msg = strtolower((string)$e->getMessage());
        if (str_contains($msg, 'launcher_downloads') && (str_contains($msg, "doesn't exist") || str_contains($msg, 'does not exist'))) {
          $warning = 'Distribution non initialisée : importe `migrations_api.sql` (table launcher_downloads manquante).';
        }
      }
    }

    if ($warning === '' && !$links['win'] && !$links['mac'] && !$links['linux']) {
      $warning = "Aucun installer n’a encore été généré pour ce launcher. Lance un build (ex: via /api/build_launcher.php) ou upload un installer.";
    }

    http_response_code(404);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Server error.';
    exit;
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Téléchargement — <?php echo e($launcherName); ?></title>
  <link rel="stylesheet" href="assets/style.css" />
</head>
<body>
  <main class="container" style="padding:40px 0">
    <div class="callout">
      <div>
        <p class="badge">Téléchargement</p>
        <h1 class="section-title" style="margin:10px 0 0">Installer indisponible</h1>
        <p class="section-desc" style="margin-top:8px">Aucun installer actif n’est disponible pour <strong><?php echo e($platform); ?></strong>.</p>
      </div>
    </div>

    <div class="card" style="margin-top:14px">
      <h2 class="section-title" style="margin:0">Liens disponibles</h2>
      <p class="section-desc" style="margin-top:8px">Si tu as un installer actif, il apparaîtra ici.</p>

      <?php if (isset($warning) && $warning): ?>
        <div class="notice" data-show="true" style="margin: 12px 0"><?php echo e($warning); ?></div>
      <?php endif; ?>

      <div class="cta-row" style="margin-top:14px">
        <?php if ($links['win']): ?>
          <a class="btn btn-primary" href="<?php echo e($links['win']['url']); ?>">Windows<?php echo $links['win']['version'] ? ' • ' . e($links['win']['version']) : ''; ?></a>
        <?php endif; ?>
        <?php if ($links['mac']): ?>
          <a class="btn btn-primary" href="<?php echo e($links['mac']['url']); ?>">macOS<?php echo $links['mac']['version'] ? ' • ' . e($links['mac']['version']) : ''; ?></a>
        <?php endif; ?>
        <?php if ($links['linux']): ?>
          <a class="btn btn-primary" href="<?php echo e($links['linux']['url']); ?>">Linux<?php echo $links['linux']['version'] ? ' • ' . e($links['linux']['version']) : ''; ?></a>
        <?php endif; ?>
      </div>

      <p class="small" style="margin:12px 0 0">UUID launcher : <?php echo e($uuid); ?></p>
    </div>
  </main>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$q          = trim((string)($_GET['q'] ?? ''));
$action     = trim((string)($_GET['action'] ?? ''));
$adminFilter = (int)($_GET['admin'] ?? 0);

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(a.notes LIKE ? OR a.target_type LIKE ? OR a.action LIKE ? OR u.email LIKE ?)';
    $args[]  = '%' . $q . '%';
    $args[]  = '%' . $q . '%';
    $args[]  = '%' . $q . '%';
    $args[]  = '%' . $q . '%';
}
if ($action !== '')      { $where[] = 'a.action = ?'; $args[] = $action; }
if ($adminFilter > 0)    { $where[] = 'a.admin_id = ?'; $args[] = $adminFilter; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$actions = [];
$admins  = [];

try {
    // Liste des actions distinctes pour le filtre.
    $actions = $pdo->query("SELECT DISTINCT action FROM admin_actions ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    $admins  = $pdo->query("SELECT DISTINCT a.admin_id, u.email FROM admin_actions a LEFT JOIN users u ON u.id = a.admin_id ORDER BY u.email")->fetchAll();

    $sql = "SELECT a.id, a.admin_id, a.action, a.target_type, a.target_id, a.notes, a.ip, a.created_at, "
         . "       u.email AS admin_email "
         . "FROM admin_actions a "
         . "LEFT JOIN users u ON u.id = a.admin_id "
         . "$whereSql "
         . "ORDER BY a.created_at DESC LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('error', 'Migration v5 manquante (admin_actions).');
}

function targetLink(string $type, ?int $id): string
{
    if (!$id) return '';
    if ($type === 'user')         return '/admin/user.php?id=' . $id;
    if ($type === 'subscription') return '/admin/subscription.php?id=' . $id;
    if ($type === 'launcher')     return '/admin/launchers.php?q=' . $id;
    return '';
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Audit log · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    .admin-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:top}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
    .filter-bar input,.filter-bar select{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);
      color:#fff;padding:10px 12px;border-radius:10px;font-size:14px;min-width:160px}
    .filter-bar input[name=q]{flex:1;min-width:240px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;
      background:rgba(124,58,237,.15);color:#c4b5fd;border:1px solid rgba(124,58,237,.3)}
    .pill-danger{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
    .pill-warn{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-ok{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(16,185,129,.3)}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#8a8aa0}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('audit'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge">Admin</p>
        <h1 class="section-title" style="margin:10px 0 0">Audit log (<?php echo count($rows); ?>)</h1>
        <p class="section-desc" style="margin-top:8px">Historique de toutes les actions de la console admin.</p>

        <form method="get" action="/admin/audit.php" class="filter-bar">
          <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Rechercher dans notes, action, target..." autocomplete="off" />
          <select name="action">
            <option value="">— Toutes les actions —</option>
            <?php foreach ($actions as $a): ?>
              <option value="<?php echo e((string)$a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>><?php echo e((string)$a); ?></option>
            <?php endforeach; ?>
          </select>
          <select name="admin">
            <option value="0">— Tous les admins —</option>
            <?php foreach ($admins as $a): ?>
              <option value="<?php echo (int)$a['admin_id']; ?>" <?php echo $adminFilter === (int)$a['admin_id'] ? 'selected' : ''; ?>><?php echo e((string)($a['email'] ?? ('#' . $a['admin_id']))); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" type="submit">Filtrer</button>
        </form>

        <table class="admin-table">
          <thead><tr><th>Date</th><th>Admin</th><th>Action</th><th>Cible</th><th>Note</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r):
              $a = (string)$r['action'];
              $cls = 'pill';
              if (str_contains($a, 'cancel') || str_contains($a, 'delete') || str_contains($a, 'failed') || str_contains($a, 'refund')) $cls = 'pill pill-danger';
              elseif (str_contains($a, 'extend') || str_contains($a, 'coupon') || str_contains($a, 'grant')) $cls = 'pill pill-warn';
              elseif (str_contains($a, 'send_email') || str_contains($a, 'restore')) $cls = 'pill pill-ok';
              $tlink = targetLink((string)$r['target_type'], $r['target_id'] !== null ? (int)$r['target_id'] : null);
            ?>
              <tr>
                <td><span class="mono"><?php echo e(date('d/m H:i:s', strtotime((string)$r['created_at']))); ?></span></td>
                <td>
                  <?php if (!empty($r['admin_email'])): ?>
                    <a href="/admin/user.php?id=<?php echo (int)$r['admin_id']; ?>" style="color:#a78bfa;text-decoration:none"><?php echo e((string)$r['admin_email']); ?></a>
                  <?php else: ?>
                    <span class="mono">#<?php echo (int)$r['admin_id']; ?></span>
                  <?php endif; ?>
                </td>
                <td><span class="<?php echo $cls; ?>"><?php echo e($a); ?></span></td>
                <td>
                  <?php if ($r['target_type']): ?>
                    <span class="mono"><?php echo e((string)$r['target_type']); ?>#<?php echo (int)$r['target_id']; ?></span>
                    <?php if ($tlink): ?> <a href="<?php echo e($tlink); ?>" style="color:#a78bfa">→</a><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td style="max-width:420px;word-break:break-word"><?php echo e((string)($r['notes'] ?? '')); ?></td>
                <td><span class="mono"><?php echo e((string)($r['ip'] ?? '')); ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="6" style="color:#8a8aa0;padding:20px">Aucune action enregistrée.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

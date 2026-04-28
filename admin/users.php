<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? 'all'); // all | active | deleted | admin

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(u.email LIKE ? OR u.uuid LIKE ?)';
    $args[]  = '%' . $q . '%';
    $args[]  = '%' . $q . '%';
}
if ($filter === 'active')   { $where[] = '(u.deleted_at IS NULL)'; }
if ($filter === 'deleted')  { $where[] = '(u.deleted_at IS NOT NULL)'; }
if ($filter === 'admin')    { $where[] = '(u.is_admin = 1)'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$users = [];
try {
    $sql = "SELECT u.id, u.uuid, u.email, u.is_admin, u.created_at, u.last_login_at, u.deleted_at, "
         . "       (SELECT COUNT(*) FROM launchers l WHERE l.user_id = u.id) AS launchers_count, "
         . "       (SELECT COUNT(*) FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'active') AS subs_active "
         . "FROM users u "
         . "$whereSql "
         . "ORDER BY u.created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $users = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('error', 'Migration v5 manquante. Exécute migrations_v5.sql.');
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Utilisateurs · Admin · XynoLauncher</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/style.css" />
  <script src="../assets/main.js" defer></script>
  <style>
    .admin-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px}
    .admin-table th,.admin-table td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
    .admin-table th{color:#8a8aa0;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .admin-table tr:hover{background:rgba(255,255,255,.02)}
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
    .filter-bar input{flex:1;min-width:240px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);
      color:#fff;padding:10px 12px;border-radius:10px;font-size:14px}
    .filter-bar a{padding:8px 12px;border-radius:999px;font-size:13px;text-decoration:none;color:#c4b5fd;
      background:rgba(124,58,237,.10);border:1px solid rgba(124,58,237,.25)}
    .filter-bar a.active{background:#7c3aed;color:#fff;border-color:#7c3aed}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
    .pill-admin{background:rgba(234,179,8,.18);color:#fbbf24;border:1px solid rgba(234,179,8,.3)}
    .pill-deleted{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('users'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <p class="badge">Admin</p>
        <h1 class="section-title" style="margin:10px 0 0">Utilisateurs (<?php echo count($users); ?>)</h1>

        <form method="get" action="users.php" class="filter-bar">
          <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Rechercher par email ou UUID..." autocomplete="off" />
          <button class="btn btn-primary" type="submit">Filtrer</button>
        </form>

        <div class="filter-bar" style="margin-top:8px">
          <a href="users.php?q=<?php echo urlencode($q); ?>&filter=all" class="<?php echo $filter==='all'?'active':''; ?>">Tous</a>
          <a href="users.php?q=<?php echo urlencode($q); ?>&filter=active" class="<?php echo $filter==='active'?'active':''; ?>">Actifs</a>
          <a href="users.php?q=<?php echo urlencode($q); ?>&filter=deleted" class="<?php echo $filter==='deleted'?'active':''; ?>">Supprimés</a>
          <a href="users.php?q=<?php echo urlencode($q); ?>&filter=admin" class="<?php echo $filter==='admin'?'active':''; ?>">Admins</a>
        </div>

        <table class="admin-table">
          <thead><tr><th>ID</th><th>Email</th><th>Inscription</th><th>Dernière co.</th><th>Launchers</th><th>Abos actifs</th><th>Statut</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td>#<?php echo (int)$u['id']; ?></td>
                <td><?php echo e((string)$u['email']); ?></td>
                <td><?php echo e(date('d/m/Y', strtotime((string)$u['created_at']))); ?></td>
                <td><?php echo !empty($u['last_login_at']) ? e(date('d/m H:i', strtotime((string)$u['last_login_at']))) : '<span style="color:#8a8aa0">—</span>'; ?></td>
                <td><?php echo (int)$u['launchers_count']; ?></td>
                <td><strong style="color:<?php echo (int)$u['subs_active']>0?'#34d399':'#8a8aa0'; ?>"><?php echo (int)$u['subs_active']; ?></strong></td>
                <td>
                  <?php if ((int)($u['is_admin'] ?? 0) === 1): ?><span class="pill pill-admin">admin</span> <?php endif; ?>
                  <?php if (!empty($u['deleted_at'])): ?><span class="pill pill-deleted">supprimé</span><?php endif; ?>
                </td>
                <td><a class="btn btn-ghost" style="padding:4px 10px;font-size:12px" href="user.php?id=<?php echo (int)$u['id']; ?>">Détail →</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?><tr><td colspan="8" style="color:#8a8aa0;padding:20px">Aucun utilisateur trouvé.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

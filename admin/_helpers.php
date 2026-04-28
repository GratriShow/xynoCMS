<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/email_helpers.php';

/**
 * Renvoie l'utilisateur courant si c'est un admin, sinon redirige.
 */
function require_admin(): array
{
    $user = require_login();
    $pdo  = db();
    try {
        $st = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
        $st->execute([$user['id']]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        flash_set('error', 'Migration v5 manquante (is_admin).');
        redirect('/dashboard.php');
    }
    if (!$row || (int)($row['is_admin'] ?? 0) !== 1) {
        flash_set('error', 'Accès admin refusé.');
        redirect('/dashboard.php');
    }
    return $user;
}

function admin_log(int $adminId, string $action, string $targetType = '', ?int $targetId = null, ?string $notes = null): void
{
    try {
        $pdo = db();
        $st = $pdo->prepare(
            'INSERT INTO admin_actions (admin_id, action, target_type, target_id, notes, ip, created_at) '
          . 'VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([$adminId, $action, $targetType, $targetId, $notes, api_client_ip()]);
    } catch (Throwable $e) { /* never break the flow */ }
}

/**
 * Affiche la navbar admin commune.
 */
function admin_render_nav(string $active = ''): void
{
    $items = [
        'dashboard'     => ['Vue d\'ensemble', 'index.php'],
        'users'         => ['Utilisateurs',    'users.php'],
        'subscriptions' => ['Abonnements',     'subscriptions.php'],
        'emails'        => ['Logs emails',     'emails.php'],
    ];
    echo '<header class="navbar"><div class="container nav-inner">';
    echo '<a class="brand" href="../index.php" aria-label="XynoLauncher"><span class="brand-mark" aria-hidden="true"></span><span>XynoLauncher · Admin</span></a>';
    echo '<nav class="nav-links" aria-label="Navigation admin">';
    foreach ($items as $k => $it) {
        $cls = $k === $active ? ' style="color:#a78bfa;font-weight:700"' : '';
        echo '<a href="' . e($it[1]) . '"' . $cls . '>' . e($it[0]) . '</a>';
    }
    echo '</nav>';
    echo '<div class="nav-actions">';
    echo '<a class="btn btn-ghost" href="../dashboard.php">Mon dashboard</a>';
    echo '<a class="btn" href="../auth/logout.php">Se déconnecter</a>';
    echo '</div>';
    echo '</div></header>';
}

function admin_render_footer(): void
{
    echo '<footer class="footer"><div class="container footer-grid">';
    echo '<div><div class="brand" style="margin-bottom:10px"><span class="brand-mark" aria-hidden="true"></span><span>XynoLauncher</span></div><p class="small">Console admin réservée à l\'équipe XynoWeb.</p></div>';
    echo '<div></div><div></div><div></div>';
    echo '</div></footer>';
}

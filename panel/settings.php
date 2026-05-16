<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

$isAdmin = false;
try {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $r = $s->fetch();
    $isAdmin = $r && (int)($r['is_admin'] ?? 0) === 1;
} catch (Throwable) {}

// Load full user profile
$profile = null;
try {
    $s = $pdo->prepare('SELECT id, email, created_at FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $profile = $s->fetch() ?: null;
} catch (Throwable) {}

$flash    = flash_get('success');
$flashErr = flash_get('error');

// POST: change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current  = (string)($_POST['current_password'] ?? '');
        $newPw    = (string)($_POST['new_password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if ($newPw !== $confirm) {
            flash_set('error', 'Les nouveaux mots de passe ne correspondent pas.');
        } elseif (strlen($newPw) < 8) {
            flash_set('error', 'Le nouveau mot de passe doit faire au moins 8 caractères.');
        } else {
            try {
                $s = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                $s->execute([$user['id']]);
                $row = $s->fetch();
                if ($row && password_verify($current, (string)($row['password_hash'] ?? ''))) {
                    $hash = password_hash($newPw, PASSWORD_BCRYPT);
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
                    flash_set('success', 'Mot de passe mis à jour avec succès.');
                } else {
                    flash_set('error', 'Mot de passe actuel incorrect.');
                }
            } catch (Throwable $e) {
                flash_set('error', 'Erreur lors de la mise à jour.');
            }
        }
        redirect('/panel/settings.php');
    }
}

$sidebarCounts = [];
$pageTitle     = 'Paramètres';
$activeSection = 'settings';
$breadcrumbs   = [['label' => 'Paramètres']];

require_once __DIR__ . '/_layout.php';

$flash    = flash_get('success');
$flashErr = flash_get('error');
?>

<div class="panel-page-header">
  <div>
    <h1 class="panel-page-title">⚙ Paramètres</h1>
    <p class="panel-page-subtitle">Gérez votre compte et vos préférences</p>
  </div>
</div>

<?php if ($flash): ?>
  <div class="flash-msg flash-success">✓ <?= e($flash) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
  <div class="flash-msg flash-error">✕ <?= e($flashErr) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:860px;">

  <!-- Profile info -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div class="panel-card-title">👤 Informations du compte</div>
    </div>

    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
      <div style="width:56px;height:56px;border-radius:50%;background:var(--grad-primary);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;flex-shrink:0;">
        <?= strtoupper(substr((string)($user['email'] ?? 'U'), 0, 1)) ?>
      </div>
      <div>
        <div style="font-size:15px;font-weight:600;"><?= e(explode('@', (string)($user['email'] ?? ''))[0]) ?></div>
        <div style="font-size:12px;color:var(--muted);"><?= e($user['email'] ?? '') ?></div>
        <?php if ($isAdmin): ?>
          <span class="pill pill-violet" style="font-size:10px;margin-top:4px;">Administrateur</span>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:12px;">
      <div style="background:var(--surface-2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:12px 14px;">
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted-2);margin-bottom:4px;">Email</div>
        <div style="font-size:13px;"><?= e($profile['email'] ?? $user['email'] ?? '—') ?></div>
      </div>
      <div style="background:var(--surface-2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:12px 14px;">
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted-2);margin-bottom:4px;">Membre depuis</div>
        <div style="font-size:13px;"><?= !empty($profile['created_at']) ? e(date('d/m/Y', strtotime((string)$profile['created_at']))) : '—' ?></div>
      </div>
      <div style="background:var(--surface-2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:12px 14px;">
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted-2);margin-bottom:4px;">ID utilisateur</div>
        <div style="font-size:13px;font-family:monospace;color:var(--muted);">#<?= (int)$user['id'] ?></div>
      </div>
    </div>
  </div>

  <!-- Change password -->
  <div class="panel-card">
    <div class="panel-card-header">
      <div class="panel-card-title">🔒 Changer le mot de passe</div>
    </div>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="change_password"/>

      <div style="display:flex;flex-direction:column;gap:14px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;">Mot de passe actuel</label>
          <input type="password" name="current_password" required
            style="width:100%;background:var(--surface-2);border:1px solid var(--border-2);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text);font-size:13px;outline:none;"
            onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border-2)'"/>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;">Nouveau mot de passe</label>
          <input type="password" name="new_password" required minlength="8"
            style="width:100%;background:var(--surface-2);border:1px solid var(--border-2);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text);font-size:13px;outline:none;"
            onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border-2)'"/>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;">Confirmer le nouveau mot de passe</label>
          <input type="password" name="confirm_password" required minlength="8"
            style="width:100%;background:var(--surface-2);border:1px solid var(--border-2);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text);font-size:13px;outline:none;"
            onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border-2)'"/>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Mettre à jour</button>
      </div>
    </form>
  </div>

</div>

<!-- Danger zone -->
<div class="panel-card" style="max-width:860px;margin-top:20px;border-color:rgba(255,77,106,.2);">
  <div class="panel-card-header">
    <div class="panel-card-title" style="color:var(--danger);">⚠ Zone dangereuse</div>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="font-size:13px;font-weight:500;">Se déconnecter de toutes les sessions</div>
      <div style="font-size:12px;color:var(--muted);margin-top:2px;">Invalide votre session courante et vous déconnecte.</div>
    </div>
    <a href="<?= base_path() ?>/auth/logout.php" class="btn btn-danger btn-sm">Se déconnecter</a>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

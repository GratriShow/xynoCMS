<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
$pdo  = db();

$isAdmin = false;
try {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]); $r = $s->fetch();
    $isAdmin = $r && (int)($r['is_admin'] ?? 0) === 1;
} catch (Throwable) {}

$profile = null;
try {
    $s = $pdo->prepare('SELECT id, email, created_at FROM users WHERE id = ? LIMIT 1');
    $s->execute([$user['id']]); $profile = $s->fetch() ?: null;
} catch (Throwable) {}

// POST: change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    if (($_POST['action'] ?? '') === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $newPw   = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($newPw !== $confirm) {
            flash_set('error', 'Les mots de passe ne correspondent pas.');
        } elseif (strlen($newPw) < 8) {
            flash_set('error', 'Le mot de passe doit faire au moins 8 caractères.');
        } else {
            try {
                $s = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                $s->execute([$user['id']]); $row = $s->fetch();
                if ($row && password_verify($current, (string)($row['password_hash'] ?? ''))) {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPw, PASSWORD_BCRYPT), $user['id']]);
                    flash_set('success', 'Mot de passe mis à jour.');
                } else {
                    flash_set('error', 'Mot de passe actuel incorrect.');
                }
            } catch (Throwable) { flash_set('error', 'Erreur lors de la mise à jour.'); }
        }
        redirect('/panel/settings.php');
    }
}

$flash    = flash_get('success');
$flashErr = flash_get('error');

$sidebarCounts = [];
$pageTitle     = 'Paramètres';
$activeSection = 'settings';
$breadcrumbs   = [['label' => 'Paramètres']];

require_once __DIR__ . '/_layout.php';
?>

<div class="panel-page-header">
  <div>
    <div class="panel-page-title">⚙ Paramètres</div>
    <div class="panel-page-subtitle">Gérez votre compte et vos préférences</div>
  </div>
</div>

<?php if ($flash):    ?><div class="flash flash-ok">✓ <?= e($flash) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash flash-err">✕ <?= e($flashErr) ?></div><?php endif; ?>

<div class="grid-2" style="max-width:820px;margin-bottom:16px;">

  <!-- Profile -->
  <div class="panel-card">
    <div class="card-header"><div class="card-title">👤 Informations du compte</div></div>

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">
      <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#7c5cff,#5b8dff);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0;">
        <?= strtoupper(substr((string)($user['email'] ?? 'U'), 0, 1)) ?>
      </div>
      <div>
        <div style="font-size:14px;font-weight:600;color:#e0e0f0;"><?= e(explode('@', (string)($user['email'] ?? ''))[0]) ?></div>
        <div style="font-size:12px;color:#4848a0;"><?= e($user['email'] ?? '') ?></div>
        <?php if ($isAdmin): ?><span class="pill pill-violet" style="font-size:10px;margin-top:4px;">Administrateur</span><?php endif; ?>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php
        $fields = [
          'Email'         => e($profile['email'] ?? $user['email'] ?? '—'),
          'Membre depuis' => !empty($profile['created_at']) ? e(date('d/m/Y', strtotime((string)$profile['created_at']))) : '—',
          'ID utilisateur'=> '<code style="font-size:12px;color:#7c5cff;">#' . (int)$user['id'] . '</code>',
        ];
        foreach ($fields as $label => $value):
      ?>
        <div style="background:#0a0a1a;border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:10px 12px;">
          <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#2a2a50;margin-bottom:4px;"><?= $label ?></div>
          <div style="font-size:13px;color:#c0c0e0;"><?= $value ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Change password -->
  <div class="panel-card">
    <div class="card-header"><div class="card-title">🔒 Changer le mot de passe</div></div>

    <form method="post" action="" style="display:flex;flex-direction:column;gap:14px;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="change_password"/>

      <?php
        $inputs = [
          ['Mot de passe actuel', 'current_password', false],
          ['Nouveau mot de passe', 'new_password', true],
          ['Confirmer le nouveau', 'confirm_password', true],
        ];
        foreach ($inputs as [$label, $name, $minlen]):
      ?>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#3a3a60;margin-bottom:6px;"><?= $label ?></label>
          <input type="password" name="<?= $name ?>" required <?= $minlen ? 'minlength="8"' : '' ?>
            style="width:100%;background:#0a0a1a;border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:9px 12px;color:#e0e0f0;font-size:13px;outline:none;font-family:inherit;transition:border-color .12s;"
            onfocus="this.style.borderColor='#7c5cff'" onblur="this.style.borderColor='rgba(255,255,255,.08)'"/>
        </div>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:4px;">Mettre à jour</button>
    </form>
  </div>
</div>

<!-- Danger zone -->
<div class="panel-card" style="max-width:820px;border-color:rgba(255,77,106,.15);">
  <div class="card-header"><div class="card-title" style="color:#ff4d6a;">⚠ Zone dangereuse</div></div>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="font-size:13px;font-weight:500;color:#c0c0e0;">Se déconnecter</div>
      <div style="font-size:12px;color:#4848a0;margin-top:2px;">Invalide votre session et vous déconnecte.</div>
    </div>
    <a href="<?= base_path() ?>/auth/logout.php" class="btn btn-danger btn-sm">Déconnexion</a>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>

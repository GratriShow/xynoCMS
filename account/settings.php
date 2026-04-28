<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/email_helpers.php';

$user = require_login();
$pdo  = db();

$errEmail = $errPwd = $errDelete = '';
$okEmail  = $okPwd  = '';

// Charge l'état complet (email_pending, dates) ----------------------------
try {
    $st = $pdo->prepare('SELECT email, email_pending, created_at, updated_at, last_login_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$user['id']]);
    $u = $st->fetch();
} catch (Throwable $e) {
    // base v4 sans email_pending → fallback minimal
    $st = $pdo->prepare('SELECT email, created_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$user['id']]);
    $u = $st->fetch();
}
if (!$u) {
    redirect('/auth/logout.php');
}
$emailPending = (string)($u['email_pending'] ?? '');

if (is_post()) {
    $action = (string)($_POST['action'] ?? '');
    $token  = (string)($_POST['_csrf'] ?? '');
    if (!csrf_verify($token)) {
        flash_set('error', 'Session expirée — réessaie.');
        redirect('/account/settings.php');
    }

    if ($action === 'change_email') {
        $newEmail = trim((string)($_POST['new_email'] ?? ''));
        $confirmPwd = (string)($_POST['current_password'] ?? '');

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errEmail = 'Email invalide.';
        } elseif (strtolower($newEmail) === strtolower((string)$u['email'])) {
            $errEmail = 'C\'est déjà ton email actuel.';
        } else {
            $check = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $check->execute([$user['id']]);
            $row = $check->fetch();
            if (!$row || !password_verify($confirmPwd, (string)$row['password'])) {
                $errEmail = 'Mot de passe incorrect.';
            } else {
                $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $exists->execute([$newEmail, $user['id']]);
                if ($exists->fetch()) {
                    $errEmail = 'Cet email est déjà utilisé par un autre compte.';
                } else {
                    try {
                        $pdo->prepare('UPDATE users SET email_pending = ?, updated_at = NOW() WHERE id = ? LIMIT 1')
                            ->execute([$newEmail, $user['id']]);
                    } catch (Throwable $e) {
                        flash_set('error', 'Migration v5 manquante. Exécute migrations_v5.sql avant de continuer.');
                        redirect('/account/settings.php');
                    }
                    $tok = email_token_create($user['id'], 'email_change', $newEmail, 86400);
                    send_email_change_verification($newEmail, $user['id'], $tok);
                    flash_set('success', "Un email de confirmation a été envoyé à $newEmail. Clique sur le lien pour valider.");
                    redirect('/account/settings.php');
                }
            }
        }
    } elseif ($action === 'change_password') {
        $oldPwd = (string)($_POST['current_password'] ?? '');
        $newPwd = (string)($_POST['new_password'] ?? '');
        $cfmPwd = (string)($_POST['new_password_confirm'] ?? '');

        $check = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $check->execute([$user['id']]);
        $row = $check->fetch();
        if (!$row || !password_verify($oldPwd, (string)$row['password'])) {
            $errPwd = 'Mot de passe actuel incorrect.';
        } elseif (strlen($newPwd) < 8) {
            $errPwd = 'Nouveau mot de passe trop court (min. 8 caractères).';
        } elseif ($newPwd !== $cfmPwd) {
            $errPwd = 'Les mots de passe ne correspondent pas.';
        } else {
            $hash = password_hash($newPwd, PASSWORD_DEFAULT);
            try {
                $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? LIMIT 1')
                    ->execute([$hash, $user['id']]);
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ? LIMIT 1')
                    ->execute([$hash, $user['id']]);
            }
            flash_set('success', 'Mot de passe mis à jour.');
            redirect('/account/settings.php');
        }
    } elseif ($action === 'delete_account') {
        $confirmPwd = (string)($_POST['current_password'] ?? '');
        $confirmTxt = trim((string)($_POST['confirm_text'] ?? ''));

        $check = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $check->execute([$user['id']]);
        $row = $check->fetch();
        if (!$row || !password_verify($confirmPwd, (string)$row['password'])) {
            $errDelete = 'Mot de passe incorrect.';
        } elseif (strtoupper($confirmTxt) !== 'SUPPRIMER') {
            $errDelete = "Tape SUPPRIMER en majuscules pour confirmer.";
        } else {
            try {
                $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? LIMIT 1')->execute([$user['id']]);
            } catch (Throwable $e) {
                flash_set('error', 'Migration v5 manquante.');
                redirect('/account/settings.php');
            }
            // Notification email
            $purgeAt = date('d/m/Y', time() + 30 * 86400);
            try { send_account_deleted_email((string)$u['email'], $user['id'], $purgeAt); } catch (Throwable $e) {}
            // Détruit la session
            $_SESSION = [];
            session_destroy();
            // Redirige vers la home avec un message
            start_secure_session();
            flash_set('info', 'Ton compte est supprimé. Tu as 30 jours pour annuler en te reconnectant.');
            redirect('/index.php');
        }
    }
}

$success = flash_get('success');
$error   = flash_get('error');
$csrf    = csrf_token();

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Mon compte — XynoLauncher</title>
  <meta name="description" content="Gère ton compte XynoLauncher : email, mot de passe, suppression de compte." />
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/style.css" />
  <script src="../assets/main.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <header class="navbar">
    <div class="container nav-inner">
      <a class="brand" href="../index.php" aria-label="XynoLauncher">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>XynoLauncher</span>
      </a>

      <nav class="nav-links" aria-label="Navigation principale">
        <a href="../index.php">Accueil</a>
        <a href="../pricing.php">Tarifs</a>
        <a href="../builder.php">Builder</a>
        <a href="../dashboard.php">Dashboard</a>
      </nav>

      <div class="nav-actions">
        <a class="btn btn-ghost" href="settings.php">Mon compte</a>
        <a class="btn" href="../auth/logout.php">Se déconnecter</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:760px">
        <p class="badge">Mon compte</p>
        <h1 class="section-title" style="margin:10px 0 0">Paramètres</h1>
        <p class="section-desc" style="margin-top:8px">Gère ton email, ton mot de passe et la suppression de ton compte.</p>

        <?php if ($success): ?>
          <div class="notice" data-show="true" style="margin:16px 0;border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.10)"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="notice" data-show="true" style="margin:16px 0"><?php echo e($error); ?></div>
        <?php endif; ?>

        <article class="card form-card" style="margin-top:18px" aria-label="Identité">
          <h2 style="margin:0 0 10px;font-size:18px">Identité</h2>
          <dl class="dlist">
            <div><dt>Email actuel</dt><dd><?php echo e((string)$u['email']); ?></dd></div>
            <?php if ($emailPending !== ''): ?>
              <div><dt>Email en attente</dt><dd><?php echo e($emailPending); ?> <span class="help">(en attente de confirmation par lien email)</span></dd></div>
            <?php endif; ?>
            <div><dt>Compte créé le</dt><dd><?php echo e(date('d/m/Y', strtotime((string)$u['created_at']))); ?></dd></div>
            <?php if (!empty($u['last_login_at'])): ?>
              <div><dt>Dernière connexion</dt><dd><?php echo e(date('d/m/Y H:i', strtotime((string)$u['last_login_at']))); ?></dd></div>
            <?php endif; ?>
          </dl>
        </article>

        <article class="card form-card" style="margin-top:18px" aria-label="Changer email">
          <h2 style="margin:0 0 10px;font-size:18px">Changer mon email</h2>
          <p class="help" style="margin:-4px 0 12px">Tu recevras un email de confirmation sur la nouvelle adresse pour valider le changement.</p>
          <?php if ($errEmail !== ''): ?>
            <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($errEmail); ?></div>
          <?php endif; ?>
          <form class="form" method="post" action="settings.php" novalidate>
            <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>" />
            <input type="hidden" name="action" value="change_email" />
            <label class="label">
              <span>Nouvel email</span>
              <input class="input" name="new_email" type="email" placeholder="nouveau@email.com" autocomplete="email" required />
            </label>
            <label class="label">
              <span>Mot de passe actuel</span>
              <input class="input" name="current_password" type="password" placeholder="••••••••" autocomplete="current-password" required />
            </label>
            <button class="btn btn-primary" type="submit">Envoyer le lien de confirmation</button>
          </form>
        </article>

        <article class="card form-card" style="margin-top:18px" aria-label="Changer mot de passe">
          <h2 style="margin:0 0 10px;font-size:18px">Changer mon mot de passe</h2>
          <?php if ($errPwd !== ''): ?>
            <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($errPwd); ?></div>
          <?php endif; ?>
          <form class="form" method="post" action="settings.php" novalidate>
            <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>" />
            <input type="hidden" name="action" value="change_password" />
            <label class="label">
              <span>Mot de passe actuel</span>
              <input class="input" name="current_password" type="password" placeholder="••••••••" autocomplete="current-password" required />
            </label>
            <label class="label">
              <span>Nouveau mot de passe</span>
              <input class="input" name="new_password" type="password" placeholder="••••••••" autocomplete="new-password" required />
              <span class="help">Min. 8 caractères.</span>
            </label>
            <label class="label">
              <span>Confirmation</span>
              <input class="input" name="new_password_confirm" type="password" placeholder="••••••••" autocomplete="new-password" required />
            </label>
            <button class="btn btn-primary" type="submit">Mettre à jour</button>
          </form>
        </article>

        <article class="card form-card" style="margin-top:18px;border-color:rgba(239,68,68,.35)" aria-label="Supprimer mon compte">
          <h2 style="margin:0 0 10px;font-size:18px;color:#fca5a5">Zone de danger</h2>
          <p class="help" style="margin:-4px 0 12px">
            La suppression archive ton compte pendant <strong>30 jours</strong>. Tes abonnements Stripe sont automatiquement résiliés.
            Tu peux annuler en te reconnectant avec ton ancien email avant la fin du délai.
            Au-delà, tes données personnelles sont effacées (les factures sont conservées 10 ans pour des raisons comptables).
          </p>
          <?php if ($errDelete !== ''): ?>
            <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($errDelete); ?></div>
          <?php endif; ?>
          <form class="form" method="post" action="settings.php" novalidate onsubmit="return confirm('Confirmer la suppression de ton compte ? Cette action commence le délai de 30 jours.');">
            <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>" />
            <input type="hidden" name="action" value="delete_account" />
            <label class="label">
              <span>Mot de passe actuel</span>
              <input class="input" name="current_password" type="password" placeholder="••••••••" autocomplete="current-password" required />
            </label>
            <label class="label">
              <span>Tape <code>SUPPRIMER</code> pour confirmer</span>
              <input class="input" name="confirm_text" type="text" placeholder="SUPPRIMER" required />
            </label>
            <button class="btn" type="submit" style="background:#ef4444;border-color:#ef4444;color:#fff">Supprimer mon compte</button>
          </form>
        </article>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div>
        <div class="brand" style="margin-bottom:10px">
          <span class="brand-mark" aria-hidden="true"></span>
          <span>XynoLauncher</span>
        </div>
        <p class="small">© <span id="year">2026</span> XynoLauncher.</p>
      </div>
      <div>
        <h4>Produit</h4>
        <p class="small"><a href="../pricing.php">Tarifs</a></p>
        <p class="small"><a href="../builder.php">Builder</a></p>
        <p class="small"><a href="../index.php">Landing</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="settings.php">Paramètres</a></p>
        <p class="small"><a href="../auth/logout.php">Déconnexion</a></p>
      </div>
      <div>
        <h4>Légal</h4>
        <p class="small"><a href="../mentions-legales.php">Mentions légales</a></p>
        <p class="small"><a href="../politique-confidentialite.php">Confidentialité</a></p>
        <p class="small"><a href="../politique-cookies.php">Cookies</a></p>
        <p class="small"><a href="../cgu.php">CGU</a></p>
        <p class="small"><a href="../cgv.php">CGV</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

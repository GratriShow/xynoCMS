<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

start_secure_session();

$user = current_user();
if ($user !== null) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';

if (is_post()) {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email et mot de passe requis.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, uuid, email, password FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, (string)$row['password'])) {
            $error = 'Identifiants incorrects.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['id'];
            $_SESSION['user_uuid'] = (string)$row['uuid'];
            $_SESSION['user_email'] = (string)$row['email'];

            redirect('/dashboard.php');
        }
    }
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Connexion — XynoLauncher</title>
  <meta name="description" content="Connexion à la plateforme XynoLauncher." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <script src="assets/main.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <header class="navbar">
    <div class="container nav-inner">
      <a class="brand" href="index.php" aria-label="XynoLauncher">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>XynoLauncher</span>
      </a>

      <nav class="nav-links" aria-label="Navigation principale">
        <a href="index.php">Accueil</a>
        <a href="pricing.php">Tarifs</a>
        <a href="builder.php">Builder</a>
        <a href="dashboard.php">Dashboard</a>
      </nav>

      <div class="nav-actions">
        <a class="btn" href="register.php">Créer un compte</a>
        <a class="btn btn-primary" href="builder.php">Commencer</a>
      </div>
    </div>
  </header>

  <main id="contenu">
    <section class="section">
      <div class="container">
        <article class="card form-card" aria-label="Connexion">
          <p class="badge">Connexion</p>
          <h1 class="section-title" style="margin:10px 0 0">Ravi de te revoir</h1>
          <p class="section-desc" style="margin-top:8px">Connecte-toi pour accéder à ton dashboard.</p>

          <?php $flash = flash_get('info'); ?>
          <?php if ($flash): ?>
            <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($flash); ?></div>
          <?php endif; ?>

          <?php if ($error !== ''): ?>
            <div class="notice" data-show="true" style="margin-bottom:12px"><?php echo e($error); ?></div>
          <?php endif; ?>

          <form class="form" action="login.php" method="post" novalidate>
            <label class="label">
              <span>Email</span>
              <input class="input" name="email" type="email" placeholder="ton@email.com" autocomplete="email" required value="<?php echo e($email); ?>" />
            </label>
            <label class="label">
              <span>Mot de passe</span>
              <input class="input" name="password" type="password" placeholder="••••••••" autocomplete="current-password" required />
            </label>

            <button class="btn btn-primary" type="submit">Se connecter</button>

            <p class="small" style="margin:8px 0 0">Pas de compte ? <a href="register.php" style="text-decoration:underline">Inscription</a></p>
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
        <p class="small"><a href="pricing.php">Tarifs</a></p>
        <p class="small"><a href="builder.php">Builder</a></p>
        <p class="small"><a href="index.php">Landing</a></p>
      </div>
      <div>
        <h4>Compte</h4>
        <p class="small"><a href="register.php">Inscription</a></p>
        <p class="small"><a href="login.php">Connexion</a></p>
      </div>
    </div>
  </footer>

  <script>
    document.getElementById('year').textContent = String(new Date().getFullYear());
  </script>
</body>
</html>

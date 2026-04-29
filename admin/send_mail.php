<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$admin = require_admin();
$pdo   = db();

$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($userId <= 0) redirect('/admin/users.php');

$st = $pdo->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
$st->execute([$userId]);
$target = $st->fetch();
if (!$target) {
    flash_set('error', 'Utilisateur introuvable.');
    redirect('/admin/users.php');
}

$err = '';
$subject = '';
$message = '';
$result = null;

if (is_post()) {
    if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
        $err = 'Session expirée.';
    } else {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = (string)($_POST['message'] ?? '');

        if ($subject === '' || mb_strlen($subject) < 3) {
            $err = 'Sujet trop court (min. 3 caractères).';
        } elseif (mb_strlen($subject) > 200) {
            $err = 'Sujet trop long (max. 200 caractères).';
        } elseif (trim($message) === '' || mb_strlen($message) < 10) {
            $err = 'Message trop court (min. 10 caractères).';
        } else {
            // Convert basic line breaks → <p> for HTML rendering.
            $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', $message)));
            $html = '';
            foreach ($paragraphs as $p) {
                $html .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            if ($html === '') {
                $html = '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
            }

            $result = send_admin_custom_email((string)$target['email'], $userId, (int)$admin['id'], $subject, $html);
            admin_log($admin['id'], 'send_email', 'user', $userId, 'subject=' . $subject);

            if ($result['ok']) {
                flash_set('success', "Email envoyé à " . $target['email']);
                redirect('/admin/user.php?id=' . $userId);
            } else {
                $err = 'Envoi échoué : ' . (string)($result['error'] ?? 'erreur inconnue');
            }
        }
    }
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Envoyer un email · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/style.css" />
  <script src="../assets/main.js" defer></script>
  <style>
    textarea.input{min-height:240px;resize:vertical;font-family:Inter,system-ui,sans-serif;line-height:1.5}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('users'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:760px">
        <p class="badge"><a href="user.php?id=<?php echo (int)$userId; ?>" style="color:#a78bfa">← Retour au compte</a></p>
        <h1 class="section-title" style="margin:10px 0 0">Envoyer un email manuel</h1>
        <p class="section-desc" style="margin-top:8px">Destinataire : <strong><?php echo e((string)$target['email']); ?></strong> · expéditeur : <code>reply@xynoweb.fr</code></p>

        <?php if ($err !== ''): ?>
          <div class="notice" data-show="true" style="margin:14px 0"><?php echo e($err); ?></div>
        <?php endif; ?>

        <article class="card form-card" style="max-width:none;margin-top:18px">
          <form class="form" method="post" action="send_mail.php" novalidate>
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
            <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>" />
            <label class="label">
              <span>Sujet</span>
              <input class="input" name="subject" type="text" maxlength="200" required value="<?php echo e($subject); ?>" placeholder="Ex : Concernant ton abonnement..." />
            </label>
            <label class="label">
              <span>Message</span>
              <textarea class="input" name="message" required placeholder="Bonjour,&#10;&#10;..."><?php echo e($message); ?></textarea>
              <span class="help">Markdown non supporté ; les sauts de ligne sont préservés. L'email passe par le template XynoLauncher (entête, footer légal automatiques).</span>
            </label>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button class="btn btn-primary" type="submit">Envoyer maintenant</button>
              <a class="btn btn-ghost" href="user.php?id=<?php echo (int)$userId; ?>">Annuler</a>
            </div>
          </form>
        </article>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>
</body>
</html>

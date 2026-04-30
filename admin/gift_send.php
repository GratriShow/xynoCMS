<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../api/email_helpers.php';

$admin = require_admin();
$pdo   = db();

$giftId = (int)($_GET['id'] ?? $_POST['gift_id'] ?? 0);
if ($giftId <= 0) redirect('gifts.php');

try {
    $st = $pdo->prepare('SELECT id, type, description, value, single_code, code, expires_at FROM gifts WHERE id = ? LIMIT 1');
    $st->execute([$giftId]);
    $gift = $st->fetch();
} catch (Throwable $e) {
    $gift = null;
}

if (!$gift) {
    flash_set('error', 'Cadeau introuvable.');
    redirect('gifts.php');
}

$err = '';
$success = flash_get('success');
$error   = flash_get('error');

// Handle gift sending
if (is_post()) {
    if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
        $err = 'Session expirée.';
    } else {
        $recipient_type = (string)($_POST['recipient_type'] ?? '');
        $csv_emails = trim((string)($_POST['csv_emails'] ?? ''));

        // Determine recipients based on type
        $recipient_emails = [];

        if ($recipient_type === 'all') {
            // All users
            try {
                $st = $pdo->prepare('SELECT email FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC');
                $st->execute();
                $rows = $st->fetchAll();
                foreach ($rows as $r) {
                    $recipient_emails[] = (string)$r['email'];
                }
            } catch (Throwable $e) {}
        } elseif ($recipient_type === 'active_launcher') {
            // Users with active launcher
            try {
                $st = $pdo->prepare(
                    "SELECT DISTINCT u.email FROM users u "
                  . "INNER JOIN launchers l ON l.user_id = u.id "
                  . "WHERE u.deleted_at IS NULL "
                  . "ORDER BY u.created_at DESC"
                );
                $st->execute();
                $rows = $st->fetchAll();
                foreach ($rows as $r) {
                    $recipient_emails[] = (string)$r['email'];
                }
            } catch (Throwable $e) {}
        } elseif ($recipient_type === 'csv') {
            // Parse CSV emails
            if ($csv_emails === '') {
                $err = 'Veuillez fournir au moins une adresse email.';
            } else {
                $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $csv_emails)));
                foreach ($lines as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $recipient_emails[] = $email;
                    }
                }
                if (empty($recipient_emails)) {
                    $err = 'Aucune adresse email valide trouvée.';
                }
            }
        } else {
            $err = 'Type de destinataire invalide.';
        }

        // Send gifts if no error
        if ($err === '' && !empty($recipient_emails)) {
            $sent_count = 0;
            $failed_count = 0;

            foreach ($recipient_emails as $email) {
                // Generate code if needed
                $code = null;
                if ((int)$gift['single_code']) {
                    $code = (string)$gift['code'];
                } else {
                    // Generate unique code
                    do {
                        $code = 'GIFT' . strtoupper(bin2hex(random_bytes(5)));
                    } while ($pdo->query("SELECT id FROM gift_codes WHERE code = '" . $code . "' LIMIT 1")->fetch());

                    // Insert code
                    try {
                        $pdo->prepare('INSERT INTO gift_codes (gift_id, code) VALUES (?, ?)')->execute([$giftId, $code]);
                    } catch (Throwable $e) {
                        $failed_count++;
                        continue;
                    }
                }

                // Record recipient
                try {
                    $pdo->prepare(
                        'INSERT INTO gift_recipients (gift_id, user_id, email, code, sent_at) VALUES (?, NULL, ?, ?, NOW())'
                    )->execute([$giftId, $email, $code]);
                } catch (Throwable $e) {
                    $failed_count++;
                    continue;
                }

                // Send email (using existing send_admin_custom_email if available)
                $html = render_gift_email($gift, $code);
                $result = send_gift_email($email, (int)$giftId, (int)$admin['id'], $gift, $code, $html);

                if ($result['ok']) {
                    $sent_count++;
                } else {
                    $failed_count++;
                }
            }

            // Log action
            admin_log(
                (int)$admin['id'],
                'send_gift',
                'gift',
                $giftId,
                json_encode(['recipients' => count($recipient_emails), 'sent' => $sent_count, 'failed' => $failed_count])
            );

            $message = "Cadeau envoyé à $sent_count utilisateurs";
            if ($failed_count > 0) {
                $message .= " ($failed_count erreur(s))";
            }
            flash_set('success', $message);
            redirect('gift_detail.php?id=' . $giftId);
        }
    }
}

function render_gift_email($gift, $code) {
    $type_label = $gift['type'] === 'coupon' ? 'coupon' : 'crédit d\'abonnement';
    $value_display = $gift['type'] === 'coupon' ? $gift['value'] . '%' : $gift['value'] . ' jours';
    $app_url = app_url();

    $html = "<h2>" . htmlspecialchars($gift['description'], ENT_QUOTES, 'UTF-8') . "</h2>"
          . "<p>Vous avez reçu un <strong>$type_label</strong> de <strong>$value_display</strong>.</p>"
          . "<p style=\"margin:20px 0\">Votre code :</p>"
          . "<p style=\"font-size:18px;font-weight:700;font-family:monospace;background:rgba(0,0,0,.2);padding:12px;border-radius:6px;text-align:center\">$code</p>"
          . "<p><a href=\"$app_url/gifts.php\" style=\"display:inline-block;padding:10px 20px;background:#a78bfa;color:white;text-decoration:none;border-radius:6px;font-weight:600\">Entrez votre code</a></p>"
          . "<p style=\"color:#666;font-size:14px\">Ou copiez-collez le code ci-dessus sur la page des cadeaux.</p>";

    return $html;
}

function send_gift_email($email, $gift_id, $admin_id, $gift, $code, $html) {
    $subject = "🎁 " . htmlspecialchars($gift['description'], ENT_QUOTES, 'UTF-8');
    $result = send_email(
        $email,
        $subject,
        $html,
        'gift_received',
        [
            'admin_id' => $admin_id,
            'reply_to' => email_reply_to()
        ]
    );
    return $result;
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Envoyer cadeau #<?php echo (int)$gift['id']; ?> · Admin</title>
  <meta name="robots" content="noindex,nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
  <script src="/assets/main.js" defer></script>
  <style>
    textarea.input{min-height:120px;resize:vertical;font-family:Inter,system-ui,sans-serif;line-height:1.5}
  </style>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php admin_render_nav('gifts'); ?>

  <main id="contenu">
    <section class="section">
      <div class="container" style="max-width:760px">
        <p class="badge"><a href="/admin/gift_detail.php?id=<?php echo (int)$gift['id']; ?>" style="color:#a78bfa">← Retour au cadeau</a></p>
        <h1 class="section-title" style="margin:10px 0 0">Envoyer un cadeau</h1>
        <p class="section-desc">Distribution : <strong><?php echo e((string)$gift['description']); ?></strong></p>

        <?php if ($err !== ''): ?>
          <div class="notice" data-show="true" style="margin:14px 0"><?php echo e($err); ?></div>
        <?php endif; ?>

        <article class="card form-card" style="max-width:none;margin-top:18px">
          <form class="form" method="post" action="/admin/gift_send.php" novalidate>
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>" />
            <input type="hidden" name="gift_id" value="<?php echo (int)$gift['id']; ?>" />

            <label class="label">
              <span>À qui envoyer ce cadeau ?</span>
              <select class="input" name="recipient_type" required>
                <option value="">-- Sélectionner --</option>
                <option value="all">À tous les utilisateurs</option>
                <option value="active_launcher">Aux utilisateurs avec launcher actif</option>
                <option value="csv">À une liste personnalisée (emails séparés par des sauts de ligne)</option>
              </select>
            </label>

            <label class="label" id="csv_field" style="display:none">
              <span>Adresses emails</span>
              <textarea class="input" name="csv_emails" placeholder="user@example.com&#10;another@example.com&#10;..."></textarea>
              <span class="help">Une adresse email par ligne</span>
            </label>

            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button class="btn btn-primary" type="submit">Envoyer le cadeau</button>
              <a class="btn btn-ghost" href="/admin/gift_detail.php?id=<?php echo (int)$gift['id']; ?>">Annuler</a>
            </div>
          </form>
        </article>
      </div>
    </section>
  </main>

  <?php admin_render_footer(); ?>

  <script>
    const recipientSelect = document.querySelector('select[name="recipient_type"]');
    const csvField = document.getElementById('csv_field');

    recipientSelect.addEventListener('change', function() {
      csvField.style.display = this.value === 'csv' ? 'block' : 'none';
    });
  </script>
</body>
</html>

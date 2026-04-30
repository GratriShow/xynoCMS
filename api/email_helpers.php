<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/utils.php';

/**
 * Email transactional helpers (Resend.com).
 *
 * Configuration via .env.local :
 *   RESEND_API_KEY = re_xxxxxxxxxxxxx
 *   EMAIL_FROM     = reply@xynoweb.fr
 *   EMAIL_FROM_NAME= XynoLauncher
 *   EMAIL_REPLY_TO = contact@xynoweb.fr
 *   APP_URL        = https://xynocms.xynoweb.fr   (utilisé pour les liens absolus)
 *
 * Si RESEND_API_KEY n'est pas configuré, l'email est seulement loggé en base
 * avec status='queued' (utile en dev).
 */

function email_from_address(): string
{
    return api_env('EMAIL_FROM', 'reply@xynoweb.fr');
}

function email_from_name(): string
{
    return api_env('EMAIL_FROM_NAME', 'XynoLauncher');
}

function email_reply_to(): string
{
    return api_env('EMAIL_REPLY_TO', 'contact@xynoweb.fr');
}

function app_url(): string
{
    $u = trim(api_env('APP_URL', ''));
    if ($u === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'xynocms.xynoweb.fr');
        $u = $scheme . '://' . $host;
    }
    return rtrim($u, '/');
}

/**
 * Insère une ligne dans email_log et renvoie son id.
 */
function email_log_create(?int $userId, string $to, string $subject, string $template, ?int $adminId = null): int
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO email_log (user_id, to_email, subject, template, status, sent_by_admin_id, created_at) '
            . "VALUES (?, ?, ?, ?, 'queued', ?, NOW())"
        );
        $stmt->execute([$userId, $to, $subject, $template, $adminId]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function email_log_mark(int $logId, string $status, ?string $providerId = null, ?string $error = null): void
{
    if ($logId <= 0) return;
    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE email_log SET status = ?, provider_id = ?, error = ? WHERE id = ? LIMIT 1');
        $stmt->execute([$status, $providerId, $error, $logId]);
    } catch (Throwable $e) {
        // Logging must never break the flow.
    }
}

/**
 * Envoie un email via Resend.com.
 *
 * @param  string $toEmail   Destinataire
 * @param  string $subject   Sujet
 * @param  string $html      Corps HTML
 * @param  string $template  Identifiant template (pour le log) : 'welcome', 'payment_ok', etc.
 * @param  array  $opts      Options : ['user_id' => int, 'admin_id' => int, 'reply_to' => str, 'text' => str]
 * @return array  ['ok' => bool, 'id' => string|null, 'error' => string|null, 'log_id' => int]
 */
function send_email(string $toEmail, string $subject, string $html, string $template = 'custom', array $opts = []): array
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => null, 'error' => 'invalid_recipient', 'log_id' => 0];
    }

    $userId  = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $adminId = isset($opts['admin_id']) ? (int)$opts['admin_id'] : null;
    $logId   = email_log_create($userId, $toEmail, $subject, $template, $adminId);

    $apiKey = trim(api_env('RESEND_API_KEY', ''));
    if ($apiKey === '') {
        // Pas de clé Resend : on log seulement (dev / staging sans envoi).
        email_log_mark($logId, 'failed', null, 'RESEND_API_KEY missing');
        return ['ok' => false, 'id' => null, 'error' => 'no_api_key', 'log_id' => $logId];
    }

    $fromName = email_from_name();
    $fromAddr = email_from_address();
    $from     = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddr) : $fromAddr;
    $replyTo  = (string)($opts['reply_to'] ?? email_reply_to());

    $payload = [
        'from'    => $from,
        'to'      => [$toEmail],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($replyTo !== '') {
        $payload['reply_to'] = $replyTo;
    }
    if (!empty($opts['text'])) {
        $payload['text'] = (string)$opts['text'];
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http >= 400) {
        $msg = ($err !== '' ? $err : ('http_' . $http));
        if (is_string($resp) && $resp !== '') {
            $msg .= ' | ' . substr($resp, 0, 300);
        }
        email_log_mark($logId, 'failed', null, $msg);
        return ['ok' => false, 'id' => null, 'error' => $msg, 'log_id' => $logId];
    }

    $data = is_string($resp) ? json_decode($resp, true) : null;
    $id   = is_array($data) ? (string)($data['id'] ?? '') : '';
    email_log_mark($logId, 'sent', $id !== '' ? $id : null, null);
    return ['ok' => true, 'id' => $id, 'error' => null, 'log_id' => $logId];
}

/* -------------------------------------------------------------------------
 *  Templates HTML
 * ------------------------------------------------------------------------- */

function email_layout(string $title, string $bodyHtml, string $ctaUrl = '', string $ctaLabel = ''): string
{
    $appUrl = app_url();
    $year   = (int)date('Y');
    $cta = '';
    if ($ctaUrl !== '' && $ctaLabel !== '') {
        $cta = '<p style="margin:28px 0;text-align:center"><a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8')
             . '" style="display:inline-block;background:#7c3aed;color:#ffffff;text-decoration:none;font-weight:600;padding:14px 26px;border-radius:10px;font-size:15px">'
             . htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8')
             . '</a></p>';
    }

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;padding:0;background:#0b0b0f;font-family:Inter,Arial,sans-serif;color:#e8e8ee;line-height:1.6">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b0b0f;padding:30px 16px">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#13131a;border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden">'
        . '<tr><td style="padding:24px 28px;border-bottom:1px solid rgba(255,255,255,.06)">'
        . '<a href="' . htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') . '" style="text-decoration:none;color:#fff">'
        . '<span style="display:inline-block;width:22px;height:22px;background:linear-gradient(135deg,#7c3aed,#22d3ee);border-radius:6px;vertical-align:middle;margin-right:8px"></span>'
        . '<strong style="font-size:16px;letter-spacing:.2px;vertical-align:middle">XynoLauncher</strong>'
        . '</a></td></tr>'
        . '<tr><td style="padding:28px">'
        . '<h1 style="margin:0 0 12px;font-size:22px;color:#ffffff">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . $bodyHtml
        . $cta
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;border-top:1px solid rgba(255,255,255,.06);font-size:12px;color:#8a8aa0">'
        . 'XynoLauncher — Micro-entreprise XynoWeb · 1415 rue à Baudets, 62240 Wirwignes<br>'
        . 'Tu reçois ce mail parce que tu as un compte XynoLauncher. <a href="' . htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') . '/account/settings.php" style="color:#a78bfa">Gérer mon compte</a>.<br>'
        . '© ' . $year . ' XynoLauncher.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function send_welcome_email(string $toEmail, int $userId): array
{
    $body  = '<p>Bienvenue sur <strong>XynoLauncher</strong> ! 🎮</p>'
           . '<p>Ton compte est actif. Tu peux maintenant créer ton premier launcher Minecraft sur-mesure et le distribuer à ta communauté.</p>'
           . '<p style="color:#a8a8b8;font-size:14px">Si tu as souscrit un plan, le paiement Stripe se déroule juste après la création du launcher.</p>';
    return send_email(
        $toEmail,
        'Bienvenue sur XynoLauncher 🎮',
        email_layout('Bienvenue !', $body, app_url() . '/builder.php', 'Créer mon launcher'),
        'welcome',
        ['user_id' => $userId]
    );
}

function send_payment_success_email(string $toEmail, int $userId, string $launcherName, string $plan, string $period, int $amountCents, string $currency = 'eur'): array
{
    $amount = number_format($amountCents / 100, 2, ',', ' ') . ' ' . strtoupper($currency);
    $body  = '<p>Ton abonnement <strong>' . htmlspecialchars(ucfirst($plan), ENT_QUOTES, 'UTF-8') . '</strong> ('
           . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . ') a bien été activé pour le launcher '
           . '<strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
           . '<p>Montant prélevé : <strong>' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
           . '<p style="color:#a8a8b8;font-size:14px">Une facture détaillée est disponible sur ton dashboard. Tu peux résilier à tout moment.</p>';
    return send_email(
        $toEmail,
        'Paiement reçu — abonnement actif ✅',
        email_layout('Paiement confirmé', $body, app_url() . '/dashboard.php', 'Aller au dashboard'),
        'payment_ok',
        ['user_id' => $userId]
    );
}

function send_payment_failed_email(string $toEmail, int $userId, string $launcherName): array
{
    $body  = '<p>Le prélèvement Stripe pour ton launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong> a échoué.</p>'
           . '<p>Stripe va automatiquement réessayer dans les prochains jours. Tu peux mettre ta carte à jour dès maintenant pour éviter une interruption de service.</p>';
    return send_email(
        $toEmail,
        '⚠ Échec de paiement — mets ta carte à jour',
        email_layout('Échec de paiement', $body, app_url() . '/dashboard.php', 'Mettre à jour ma carte'),
        'payment_failed',
        ['user_id' => $userId]
    );
}

function send_subscription_cancelled_email(string $toEmail, int $userId, string $launcherName, string $expiresAt): array
{
    $body  = '<p>Ton abonnement pour le launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong> a été résilié.</p>'
           . '<p>Tu gardes l\'accès jusqu\'au <strong>' . htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
           . '<p style="color:#a8a8b8;font-size:14px">Tu peux réactiver ton abonnement à tout moment depuis le dashboard.</p>';
    return send_email(
        $toEmail,
        'Abonnement résilié',
        email_layout('Résiliation confirmée', $body, app_url() . '/dashboard.php', 'Réactiver mon abo'),
        'subscription_cancelled',
        ['user_id' => $userId]
    );
}

function send_email_change_verification(string $toEmail, int $userId, string $token): array
{
    $url   = app_url() . '/account/confirm-email.php?token=' . urlencode($token);
    $body  = '<p>Tu as demandé à changer l\'adresse email de ton compte XynoLauncher.</p>'
           . '<p>Pour confirmer, clique sur le bouton ci-dessous. Le lien est valable <strong>24 heures</strong>.</p>'
           . '<p style="color:#a8a8b8;font-size:13px">Si ce n\'est pas toi, ignore simplement cet email — aucun changement n\'aura lieu.</p>';
    return send_email(
        $toEmail,
        'Confirme ta nouvelle adresse email',
        email_layout('Confirmation de changement d\'email', $body, $url, 'Confirmer mon nouvel email'),
        'email_change',
        ['user_id' => $userId]
    );
}

function send_account_deleted_email(string $toEmail, int $userId, string $purgeAt): array
{
    $body  = '<p>Ton compte XynoLauncher a été marqué pour suppression.</p>'
           . '<p>Conformément au RGPD, nous conservons tes données <strong>30 jours</strong> avant purge définitive — tu peux te reconnecter avant le <strong>' . htmlspecialchars($purgeAt, ENT_QUOTES, 'UTF-8') . '</strong> pour annuler la suppression.</p>'
           . '<p style="color:#a8a8b8;font-size:13px">Les abonnements Stripe en cours sont automatiquement résiliés. Les données de facturation sont conservées 10 ans (obligation comptable, art. L.123-22 du Code de commerce).</p>';
    return send_email(
        $toEmail,
        'Compte supprimé — fenêtre de récupération 30 jours',
        email_layout('Compte supprimé', $body, app_url() . '/auth/login.php', 'Annuler la suppression'),
        'account_deleted',
        ['user_id' => $userId]
    );
}

/**
 * Annulation programmée à la fin de la période (cancel_at_period_end).
 */
function send_subscription_cancel_scheduled_email(string $toEmail, int $userId, string $launcherName, string $endsAt): array
{
    $body  = '<p>L\'annulation de ton abonnement pour le launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong> a été programmée.</p>'
           . '<p>Tu gardes l\'accès complet jusqu\'au <strong>' . htmlspecialchars($endsAt, ENT_QUOTES, 'UTF-8') . '</strong>. Aucun nouveau prélèvement ne sera effectué.</p>'
           . '<p style="color:#a8a8b8;font-size:14px">Tu peux annuler cette résiliation à tout moment depuis ton dashboard tant que la date n\'est pas atteinte.</p>';
    return send_email(
        $toEmail,
        'Résiliation programmée pour la fin de période',
        email_layout('Résiliation programmée', $body, app_url() . '/dashboard.php', 'Voir mon abonnement'),
        'sub_cancel_scheduled',
        ['user_id' => $userId]
    );
}

/**
 * Reprise d'un abonnement (annulation programmée annulée).
 */
function send_subscription_resumed_email(string $toEmail, int $userId, string $launcherName): array
{
    $body  = '<p>Bonne nouvelle : ton abonnement pour le launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong> a été repris.</p>'
           . '<p>L\'annulation programmée a été annulée et la facturation reprend son cours normal.</p>';
    return send_email(
        $toEmail,
        'Abonnement repris ✅',
        email_layout('Abonnement repris', $body, app_url() . '/dashboard.php', 'Voir mon dashboard'),
        'sub_resumed',
        ['user_id' => $userId]
    );
}

/**
 * Geste commercial : prolongation locale de N jours.
 */
function send_subscription_extended_email(string $toEmail, int $userId, string $launcherName, int $days, string $newDate, string $note = ''): array
{
    $body  = '<p>L\'équipe XynoLauncher t\'offre <strong>' . (int)$days . ' jour' . ($days > 1 ? 's' : '') . '</strong> supplémentaire' . ($days > 1 ? 's' : '') . ' sur ton abonnement pour le launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
           . '<p>Ton accès est désormais garanti jusqu\'au <strong>' . htmlspecialchars($newDate, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
    if ($note !== '') {
        $body .= '<p style="background:rgba(124,58,237,.10);border-left:3px solid #7c3aed;padding:10px 14px;border-radius:6px;color:#c4b5fd;font-size:14px"><em>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</em></p>';
    }
    $body .= '<p style="color:#a8a8b8;font-size:14px">Aucune action de ta part n\'est nécessaire — la prolongation est appliquée automatiquement.</p>';
    return send_email(
        $toEmail,
        '🎁 +' . (int)$days . ' jour' . ($days > 1 ? 's' : '') . ' sur ton abonnement',
        email_layout('Prolongation offerte', $body, app_url() . '/dashboard.php', 'Voir mon abonnement'),
        'sub_extended',
        ['user_id' => $userId]
    );
}

/**
 * Coupon Stripe appliqué (geste commercial côté facturation).
 */
function send_subscription_coupon_email(string $toEmail, int $userId, string $launcherName, int $percentOff, string $duration, int $months = 1): array
{
    $durationLabel = match ($duration) {
        'forever'   => 'sur toutes tes prochaines factures',
        'repeating' => 'pendant les ' . (int)$months . ' prochain' . ($months > 1 ? 's' : '') . ' mois',
        default     => 'sur ta prochaine facture',
    };
    $body  = '<p>L\'équipe XynoLauncher t\'offre une réduction de <strong>' . (int)$percentOff . ' %</strong> ' . $durationLabel . ' pour ton launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
           . '<p>La réduction est automatiquement appliquée — rien à faire de ton côté. Tu la verras sur ta prochaine facture Stripe.</p>';
    return send_email(
        $toEmail,
        '🎁 Réduction de ' . (int)$percentOff . '% appliquée',
        email_layout('Réduction offerte', $body, app_url() . '/dashboard.php', 'Voir mon abonnement'),
        'sub_coupon',
        ['user_id' => $userId]
    );
}

/**
 * Remboursement Stripe.
 */
function send_refund_email(string $toEmail, int $userId, string $launcherName, int $amountCents, string $currency = 'eur'): array
{
    $amount = number_format($amountCents / 100, 2, ',', ' ') . ' ' . strtoupper($currency);
    $body  = '<p>Un remboursement de <strong>' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</strong> a été initié sur ton paiement pour le launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
           . '<p>Le montant sera recrédité sur ton moyen de paiement d\'origine sous <strong>5 à 10 jours ouvrés</strong> (selon ta banque).</p>'
           . '<p style="color:#a8a8b8;font-size:14px">Si tu ne vois pas le remboursement passé ce délai, contacte-nous à <a href="mailto:contact@xynoweb.fr" style="color:#a78bfa">contact@xynoweb.fr</a>.</p>';
    return send_email(
        $toEmail,
        'Remboursement de ' . $amount,
        email_layout('Remboursement effectué', $body),
        'sub_refund',
        ['user_id' => $userId]
    );
}

function send_admin_custom_email(string $toEmail, int $userId, int $adminId, string $subject, string $messageHtml): array
{
    $body  = $messageHtml
           . '<hr style="border:none;border-top:1px solid rgba(255,255,255,.08);margin:24px 0">'
           . '<p style="color:#8a8aa0;font-size:12px">Message envoyé manuellement par l\'équipe XynoLauncher en réponse à ton compte. Tu peux répondre à cet email pour nous contacter.</p>';
    return send_email(
        $toEmail,
        $subject,
        email_layout($subject, $body),
        'admin_manual',
        ['user_id' => $userId, 'admin_id' => $adminId]
    );
}

/**
 * Hosting upgrade confirmation email.
 */
function send_hosting_upgrade_email(string $toEmail, int $userId, string $launcherName): array
{
    $body  = '<p>Ton launcher <strong>' . htmlspecialchars($launcherName, ENT_QUOTES, 'UTF-8') . '</strong> est maintenant hébergé sur nos serveurs Xyno ! 🚀</p>'
           . '<p>L\'option d\'hébergement (+<strong>5€/mois</strong>) est active et facturée au prorata ce mois-ci, puis intégralement à partir du mois prochain.</p>'
           . '<p style="color:#a8a8b8;font-size:14px">Tu peux accéder à la gestion des fichiers de ton launcher directement depuis le dashboard.</p>';
    return send_email(
        $toEmail,
        'Hébergement Xyno activé ✅',
        email_layout('Hébergement activé', $body, app_url() . '/dashboard.php', 'Accéder au dashboard'),
        'hosting_upgrade',
        ['user_id' => $userId]
    );
}

/* -------------------------------------------------------------------------
 *  Tokens (email change / account delete)
 * ------------------------------------------------------------------------- */

function email_token_create(int $userId, string $kind, ?string $payload = null, int $ttlSeconds = 86400): string
{
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + $ttlSeconds);
    $pdo     = db();
    $stmt    = $pdo->prepare(
        'INSERT INTO user_tokens (user_id, token, kind, payload, expires_at, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$userId, $token, $kind, $payload, $expires]);
    return $token;
}

function email_token_consume(string $token, string $kind): ?array
{
    if ($token === '' || strlen($token) !== 64) {
        return null;
    }
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT id, user_id, payload, expires_at, used_at FROM user_tokens WHERE token = ? AND kind = ? LIMIT 1'
    );
    $stmt->execute([$token, $kind]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!empty($row['used_at'])) {
        return null;
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        return null;
    }
    $u = $pdo->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = ? LIMIT 1');
    $u->execute([(int)$row['id']]);
    return [
        'user_id' => (int)$row['user_id'],
        'payload' => (string)($row['payload'] ?? ''),
    ];
}

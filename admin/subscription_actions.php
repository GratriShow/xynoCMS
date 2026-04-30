<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_stripe.php';

$admin = require_admin();
$pdo   = db();

if (!is_post()) {
    redirect('subscriptions.php');
}
if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
    flash_set('error', 'Session expirée.');
    redirect('subscriptions.php');
}

$action = (string)($_POST['action'] ?? '');
$subId  = (int)($_POST['sub_id']  ?? 0);

if ($subId <= 0) {
    flash_set('error', 'sub_id manquant.');
    redirect('subscriptions.php');
}

// Charge la subscription cible.
$st = $pdo->prepare(
    "SELECT s.*, u.email AS user_email, l.name AS launcher_name "
  . "FROM subscriptions s "
  . "LEFT JOIN users u ON u.id = s.user_id "
  . "LEFT JOIN launchers l ON l.id = s.launcher_id "
  . "WHERE s.id = ? LIMIT 1"
);
$st->execute([$subId]);
$sub = $st->fetch();
if (!$sub) {
    flash_set('error', 'Abonnement introuvable.');
    redirect('subscriptions.php');
}

$stripeSubId = (string)($sub['stripe_subscription_id'] ?? '');
$backUrl = 'subscription.php?id=' . $subId;

// -------------------------------------------------------------------------
// Dispatch
// -------------------------------------------------------------------------

switch ($action) {
    // ---------------------------------------------------------------------
    // Annuler en fin de période (Stripe)
    // ---------------------------------------------------------------------
    case 'cancel_at_period_end': {
        if ($stripeSubId === '') {
            flash_set('error', 'Pas de stripe_subscription_id : annulation locale uniquement possible.');
            redirect($backUrl);
        }
        $res = admin_stripe_cancel_at_period_end($stripeSubId);
        if (!$res['ok']) {
            flash_set('error', 'Stripe a refusé : ' . $res['error']);
            admin_log($admin['id'], 'sub_cancel_period_end_failed', 'subscription', $subId, $res['error']);
            redirect($backUrl);
        }
        try {
            $upd = $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 1 WHERE id = ?');
            $upd->execute([$subId]);
        } catch (Throwable $e) { /* migration v6 manquante */ }
        admin_log($admin['id'], 'sub_cancel_period_end', 'subscription', $subId, 'stripe_sub=' . $stripeSubId);

        // Notification utilisateur (best-effort).
        if (!empty($sub['user_email']) && (int)($sub['user_id'] ?? 0) > 0) {
            $endsAt = !empty($sub['expires_at'])
                ? date('d/m/Y', strtotime((string)$sub['expires_at']))
                : 'la fin de la période en cours';
            try {
                send_subscription_cancel_scheduled_email(
                    (string)$sub['user_email'],
                    (int)$sub['user_id'],
                    (string)($sub['launcher_name'] ?? '—'),
                    $endsAt
                );
            } catch (Throwable $e) {}
        }

        flash_set('success', 'Abonnement programmé pour annulation à la fin de la période. Email envoyé au client.');
        redirect($backUrl);
    }

    // ---------------------------------------------------------------------
    // Annulation immédiate (Stripe)
    // ---------------------------------------------------------------------
    case 'cancel_now': {
        if ($stripeSubId === '') {
            flash_set('error', 'Pas de stripe_subscription_id.');
            redirect($backUrl);
        }
        $res = admin_stripe_cancel_now($stripeSubId);
        if (!$res['ok']) {
            flash_set('error', 'Stripe a refusé : ' . $res['error']);
            admin_log($admin['id'], 'sub_cancel_now_failed', 'subscription', $subId, $res['error']);
            redirect($backUrl);
        }
        try {
            $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
            $upd->execute([$subId]);
        } catch (Throwable $e) {}
        admin_log($admin['id'], 'sub_cancel_now', 'subscription', $subId, 'stripe_sub=' . $stripeSubId);

        // Notification utilisateur (annulation immédiate = expires_at = aujourd'hui).
        if (!empty($sub['user_email']) && (int)($sub['user_id'] ?? 0) > 0) {
            try {
                send_subscription_cancelled_email(
                    (string)$sub['user_email'],
                    (int)$sub['user_id'],
                    (string)($sub['launcher_name'] ?? '—'),
                    date('d/m/Y')
                );
            } catch (Throwable $e) {}
        }

        flash_set('success', 'Abonnement annulé immédiatement. Email envoyé au client.');
        redirect($backUrl);
    }

    // ---------------------------------------------------------------------
    // Reprendre un abonnement programmé pour annulation
    // ---------------------------------------------------------------------
    case 'resume': {
        if ($stripeSubId === '') {
            flash_set('error', 'Pas de stripe_subscription_id.');
            redirect($backUrl);
        }
        $res = admin_stripe_resume($stripeSubId);
        if (!$res['ok']) {
            flash_set('error', 'Stripe a refusé : ' . $res['error']);
            redirect($backUrl);
        }
        try {
            $upd = $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 0 WHERE id = ?');
            $upd->execute([$subId]);
        } catch (Throwable $e) {}
        admin_log($admin['id'], 'sub_resume', 'subscription', $subId, 'stripe_sub=' . $stripeSubId);

        // Notification utilisateur.
        if (!empty($sub['user_email']) && (int)($sub['user_id'] ?? 0) > 0) {
            try {
                send_subscription_resumed_email(
                    (string)$sub['user_email'],
                    (int)$sub['user_id'],
                    (string)($sub['launcher_name'] ?? '—')
                );
            } catch (Throwable $e) {}
        }

        flash_set('success', 'Annulation programmée annulée — abonnement repris. Email envoyé au client.');
        redirect($backUrl);
    }

    // ---------------------------------------------------------------------
    // Prolongation locale (geste commercial : ajout de jours en DB)
    // ---------------------------------------------------------------------
    case 'extend_local': {
        $days = max(1, min(3650, (int)($_POST['days'] ?? 0)));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($days <= 0) {
            flash_set('error', 'Nombre de jours invalide.');
            redirect($backUrl);
        }

        try {
            // Base : la valeur la plus tardive entre extended_until, expires_at et NOW().
            $row = $pdo->prepare('SELECT extended_until, expires_at FROM subscriptions WHERE id = ?');
            $row->execute([$subId]);
            $cur = $row->fetch();
            $base = max(
                $cur['extended_until'] ? strtotime((string)$cur['extended_until']) : 0,
                $cur['expires_at']     ? strtotime((string)$cur['expires_at'])     : 0,
                time()
            );
            $newDate = date('Y-m-d H:i:s', $base + ($days * 86400));

            $upd = $pdo->prepare('UPDATE subscriptions SET extended_until = ? WHERE id = ?');
            $upd->execute([$newDate, $subId]);
        } catch (Throwable $e) {
            flash_set('error', 'Migration v6 manquante (extended_until).');
            redirect($backUrl);
        }

        admin_log($admin['id'], 'sub_extend_local', 'subscription', $subId, '+' . $days . 'j' . ($note ? ' · ' . $note : ''));

        // Notification utilisateur.
        if (!empty($sub['user_email']) && (int)($sub['user_id'] ?? 0) > 0) {
            try {
                send_subscription_extended_email(
                    (string)$sub['user_email'],
                    (int)$sub['user_id'],
                    (string)($sub['launcher_name'] ?? '—'),
                    $days,
                    date('d/m/Y', strtotime($newDate)),
                    $note
                );
            } catch (Throwable $e) {}
        }

        flash_set('success', 'Abonnement prolongé de ' . $days . ' jour(s) (local). Nouvelle date : ' . substr($newDate, 0, 10) . '. Email envoyé.');
        redirect($backUrl);
    }

    // ---------------------------------------------------------------------
    // Coupon Stripe (geste commercial côté facturation)
    // ---------------------------------------------------------------------
    case 'extend_stripe_coupon': {
        if ($stripeSubId === '') {
            flash_set('error', 'Pas de stripe_subscription_id.');
            redirect($backUrl);
        }
        $percent  = max(1, min(100, (int)($_POST['percent_off'] ?? 0)));
        $duration = (string)($_POST['duration'] ?? 'once'); // once | forever | repeating
        $months   = max(1, (int)($_POST['months'] ?? 1));
        if (!in_array($duration, ['once','forever','repeating'], true)) $duration = 'once';

        $res = admin_stripe_create_coupon_and_apply($stripeSubId, $percent, $duration, $months);
        if (!$res['ok']) {
            flash_set('error', 'Stripe a refusé : ' . $res['error']);
            admin_log($admin['id'], 'sub_coupon_failed', 'subscription', $subId, $res['error']);
            redirect($backUrl);
        }

        admin_log($admin['id'], 'sub_coupon', 'subscription', $subId,
            $percent . '% · ' . $duration . ($duration === 'repeating' ? ' (' . $months . 'mois)' : ''));

        // Notification utilisateur.
        if (!empty($sub['user_email']) && (int)($sub['user_id'] ?? 0) > 0) {
            try {
                send_subscription_coupon_email(
                    (string)$sub['user_email'],
                    (int)$sub['user_id'],
                    (string)($sub['launcher_name'] ?? '—'),
                    $percent,
                    $duration,
                    $months
                );
            } catch (Throwable $e) {}
        }

        flash_set('success', 'Coupon ' . $percent . '% appliqué (' . $duration . '). Email envoyé au client.');
        redirect($backUrl);
    }

    // ---------------------------------------------------------------------
    // Refund d'une charge Stripe
    // ---------------------------------------------------------------------
    case 'refund': {
        $chargeId = (string)($_POST['charge_id'] ?? '');
        $amount   = (int)($_POST['amount_cents'] ?? 0);

        if ($chargeId === '') {
            flash_set('error', 'charge_id manquant.');
            redirect($backUrl);
        }

        $res = admin_stripe_refund($chargeId, $amount > 0 ? $amount : null);
        if (!$res['ok']) {
            flash_set('error', 'Stripe a refusé le refund : ' . $res['error']);
            admin_log($admin['id'], 'sub_refund_failed', 'subscription', $subId, 'charge=' . $chargeId . ' err=' . $res['error']);
            redirect($backUrl);
        }
        admin_log($admin['id'], 'sub_refund', 'subscription', $subId,
            'charge=' . $chargeId . ($amount > 0 ? (' · ' . number_format($amount / 100, 2, ',', ' ') . '€') : ' · total'));

        // Notification utilisateur. On utilise le montant remboursé renvoyé par Stripe si dispo.
        if (!empty($sub['user_email']) && (int)($sub['user_id'] ?? 0) > 0) {
            $refundedCents = (int)($res['data']['amount'] ?? $amount);
            $refundedCur   = (string)($res['data']['currency'] ?? ($sub['currency'] ?? 'eur'));
            if ($refundedCents > 0) {
                try {
                    send_refund_email(
                        (string)$sub['user_email'],
                        (int)$sub['user_id'],
                        (string)($sub['launcher_name'] ?? '—'),
                        $refundedCents,
                        $refundedCur
                    );
                } catch (Throwable $e) {}
            }
        }

        flash_set('success', 'Refund Stripe effectué. Email envoyé au client.');
        redirect($backUrl);
    }

    // ---------------------------------------------------------------------
    // Annulation locale uniquement (filet de sécurité)
    // ---------------------------------------------------------------------
    case 'cancel_local': {
        try {
            $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
            $upd->execute([$subId]);
        } catch (Throwable $e) {
            flash_set('error', 'DB : ' . $e->getMessage());
            redirect($backUrl);
        }
        admin_log($admin['id'], 'sub_cancel_local', 'subscription', $subId, 'no_stripe_call');
        flash_set('success', 'Abonnement marqué cancelled (local uniquement).');
        redirect($backUrl);
    }
}

flash_set('error', 'Action inconnue : ' . $action);
redirect($backUrl);

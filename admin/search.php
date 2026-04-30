<?php

declare(strict_types=1);

/**
 * Recherche globale admin (endpoint JSON appelé par la palette Cmd+K).
 *
 * GET /admin/search.php?q=...
 * Renvoie : { ok: true, results: [{ kind, label, sublabel, url }, ...] }
 *
 * Les sources interrogées (en parallèle) :
 *   - users          (par email / uuid)
 *   - launchers      (par name / uuid / user email)
 *   - subscriptions  (par stripe_subscription_id / user email)
 *   - emails         (par to_email / subject)
 */

require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$admin = require_admin();
$pdo   = db();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';
$results = [];

// Users
try {
    $st = $pdo->prepare(
        'SELECT id, email, uuid, is_admin FROM users '
      . 'WHERE email LIKE ? OR uuid LIKE ? '
      . 'ORDER BY (is_admin = 1) DESC, created_at DESC LIMIT 8'
    );
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $u) {
        $results[] = [
            'kind'     => 'user',
            'label'    => '👤 ' . $u['email'] . ((int)$u['is_admin'] === 1 ? ' [admin]' : ''),
            'sublabel' => substr((string)$u['uuid'], 0, 12) . '…',
            'url'      => '/admin/user.php?id=' . (int)$u['id'],
        ];
    }
} catch (Throwable $e) {}

// Launchers
try {
    $st = $pdo->prepare(
        'SELECT l.id, l.uuid, l.name, l.user_id, u.email AS user_email '
      . 'FROM launchers l LEFT JOIN users u ON u.id = l.user_id '
      . 'WHERE l.name LIKE ? OR l.uuid LIKE ? OR u.email LIKE ? '
      . 'ORDER BY l.created_at DESC LIMIT 8'
    );
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll() as $l) {
        $results[] = [
            'kind'     => 'launcher',
            'label'    => '🚀 ' . ($l['name'] ?: '(sans nom)'),
            'sublabel' => ($l['user_email'] ?? '') . ' · ' . substr((string)$l['uuid'], 0, 12) . '…',
            'url'      => '/admin/launchers.php?q=' . urlencode((string)$l['uuid']),
        ];
    }
} catch (Throwable $e) {}

// Subscriptions
try {
    $st = $pdo->prepare(
        "SELECT s.id, s.plan, s.period, s.status, s.stripe_subscription_id, "
      . "       u.email AS user_email "
      . "FROM subscriptions s LEFT JOIN users u ON u.id = s.user_id "
      . "WHERE s.stripe_subscription_id LIKE ? OR u.email LIKE ? "
      . "ORDER BY (s.status = 'active') DESC, s.created_at DESC LIMIT 8"
    );
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $s) {
        $emoji = $s['status'] === 'active' ? '✅' : ($s['status'] === 'cancelled' ? '🚫' : '💳');
        $results[] = [
            'kind'     => 'subscription',
            'label'    => $emoji . ' ' . ucfirst((string)$s['plan']) . ' · ' . $s['period'],
            'sublabel' => ($s['user_email'] ?? '') . ' · ' . $s['status'],
            'url'      => '/admin/subscription.php?id=' . (int)$s['id'],
        ];
    }
} catch (Throwable $e) {}

// Emails
try {
    $st = $pdo->prepare(
        'SELECT id, to_email, subject, status, user_id FROM email_log '
      . 'WHERE to_email LIKE ? OR subject LIKE ? '
      . 'ORDER BY created_at DESC LIMIT 6'
    );
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $em) {
        $emoji = $em['status'] === 'sent' ? '📧' : ($em['status'] === 'failed' ? '⚠️' : '✉️');
        $results[] = [
            'kind'     => 'email',
            'label'    => $emoji . ' ' . $em['subject'],
            'sublabel' => $em['to_email'] . ' · ' . $em['status'],
            'url'      => $em['user_id'] ? ('/admin/user.php?id=' . (int)$em['user_id']) : '/admin/emails.php',
        ];
    }
} catch (Throwable $e) {}

echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

<?php
/**
 * POST /api/v2/offline_auth.php
 *
 * Authentifie un joueur en mode offline (sans compte Mojang premium).
 * Appelé par le launcher XynoWeb quand le mode 'xyno' est actif.
 *
 * Corps JSON attendu :
 *   launcher_id  string  — ID du launcher XynoWeb
 *   username     string  — Pseudo choisi par le joueur
 *   mode         string  — "offline"
 *   ts           int     — Timestamp Unix (anti-replay ±5 min)
 *   sig          string  — HMAC-SHA256 optionnel : sha256(launcher_id:username:ts)
 *
 * Réponse JSON :
 *   { ok: true, uuid: "...", xyno_token: "..." }  — succès
 *   { ok: false, error: "..." }                   — échec
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

// Rate limiting : max 10 tentatives / 60s par IP
RateLimiter::check('offline_auth', 10, 60);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Méthode ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// ── Lecture du corps ─────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corps JSON invalide']);
    exit;
}

$launcherId = trim((string)($body['launcher_id'] ?? ''));
$username    = trim((string)($body['username']    ?? ''));
$mode        = trim((string)($body['mode']        ?? ''));
$ts          = (int)($body['ts'] ?? 0);
$sig         = trim((string)($body['sig']         ?? ''));

// ── Validations de base ───────────────────────────────────────────────────────
if ($launcherId === '' || $username === '' || $mode !== 'offline') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètres manquants ou invalides']);
    exit;
}

// Username : 3-16 caractères, lettres/chiffres/_/-
if (!preg_match('/^[a-zA-Z0-9_\-]{3,16}$/', $username)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Username invalide (3-16 chars, alphanumérique/_/-)']);
    exit;
}

// Anti-replay : timestamp ±5 minutes
$now = time();
if ($ts < $now - 300 || $ts > $now + 300) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Timestamp invalide ou expiré']);
    exit;
}

// ── Récupération du launcher + vérification HMAC ──────────────────────────────
try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Service temporairement indisponible']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, user_id, offline_auth_enabled, hmac_secret
     FROM launchers
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$launcherId]);
$launcher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$launcher) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Launcher introuvable']);
    exit;
}

if (empty($launcher['offline_auth_enabled'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Authentification offline désactivée pour ce launcher']);
    exit;
}

// Vérification HMAC si le launcher a un secret configuré
if (!empty($launcher['hmac_secret'])) {
    if ($sig === '') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Signature HMAC manquante']);
        exit;
    }
    $expected = hash_hmac('sha256', "{$launcherId}:{$username}:{$ts}", $launcher['hmac_secret']);
    if (!hash_equals($expected, $sig)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Signature HMAC invalide']);
        exit;
    }
}

// ── Génération / récupération du profil offline ───────────────────────────────

/**
 * UUID v3 offline compatible Minecraft vanilla.
 * Identique à java.util.UUID.nameUUIDFromBytes("OfflinePlayer:<username>")
 */
function offlineUuid(string $username): string {
    $hash = md5('OfflinePlayer:' . $username, true);
    $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x30); // version 3
    $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80); // variant RFC4122
    $hex = bin2hex($hash);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20)
    );
}

$uuid = offlineUuid($username);

// Chercher si le joueur offline existe déjà pour ce launcher
$stmt = $pdo->prepare(
    'SELECT id, xyno_token FROM offline_players
     WHERE launcher_id = ? AND username = ?
     LIMIT 1'
);
$stmt->execute([$launcherId, $username]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // Joueur connu : on retourne son token existant (ou on le renouvelle)
    $xynoToken = $existing['xyno_token'];

    // Mise à jour du last_seen
    $pdo->prepare('UPDATE offline_players SET last_seen = NOW() WHERE id = ?')
        ->execute([$existing['id']]);
} else {
    // Nouveau joueur offline : on crée son profil
    $xynoToken = bin2hex(random_bytes(32)); // Token opaque 64 chars hex

    $stmt = $pdo->prepare(
        'INSERT INTO offline_players (launcher_id, username, uuid, xyno_token, created_at, last_seen)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([$launcherId, $username, $uuid, $xynoToken]);
}

// ── Réponse ───────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'          => true,
    'uuid'        => $uuid,
    'username'    => $username,
    'xyno_token'  => $xynoToken,
], JSON_UNESCAPED_UNICODE);

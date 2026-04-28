<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/email_helpers.php';

start_secure_session();

$token = trim((string)($_GET['token'] ?? ''));
$result = email_token_consume($token, 'email_change');

if ($result === null) {
    flash_set('error', 'Lien invalide ou expiré.');
    redirect('/auth/login.php');
}

$pdo = db();
$newEmail = trim((string)($result['payload'] ?? ''));
$userId   = (int)$result['user_id'];

if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'Lien invalide.');
    redirect('/auth/login.php');
}

// Vérifie qu'aucun autre compte n'a réservé cet email entre-temps.
$check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
$check->execute([$newEmail, $userId]);
if ($check->fetch()) {
    flash_set('error', 'Cet email est déjà utilisé par un autre compte.');
    redirect('/account/settings.php');
}

$pdo->prepare('UPDATE users SET email = ?, email_pending = NULL, updated_at = NOW() WHERE id = ? LIMIT 1')
    ->execute([$newEmail, $userId]);

// Si la session correspond à ce user, on synchronise l'email pour que les
// pages suivantes affichent le bon email immédiatement.
if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
    $_SESSION['user_email'] = $newEmail;
}

flash_set('success', 'Adresse email confirmée et mise à jour ✓');
redirect('/account/settings.php');

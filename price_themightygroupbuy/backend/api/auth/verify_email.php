<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/email.php';

method('GET', 'POST');

$token = trim($_GET['token'] ?? input()['token'] ?? '');
if (!$token) jsonResponse(['error' => 'Verification token is required.'], 422);

$stmt = db()->prepare(
    'SELECT * FROM pc_users WHERE email_token = ? AND email_verified_at IS NULL LIMIT 1'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    // Already verified, or invalid token
    $stmt2 = db()->prepare('SELECT id FROM pc_users WHERE email_verified_at IS NOT NULL AND email_token = ? LIMIT 1');
    $stmt2->execute([$token]);
    if ($stmt2->fetch()) {
        jsonResponse(['message' => 'Email already verified. You can log in.']);
    }
    jsonResponse(['error' => 'Invalid or expired verification token.'], 404);
}

db()->prepare(
    'UPDATE pc_users SET email_verified_at = NOW(), email_token = NULL WHERE id = ?'
)->execute([$user['id']]);

sendWelcomeEmail($user['email'], $user['display_name']);

jsonResponse(['message' => 'Email verified. You can now log in.']);

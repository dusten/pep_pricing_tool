<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/email.php';

method('POST');
$d = input();

$email = trim(strtolower($d['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'A valid email address is required.'], 422);
}

// Same response regardless of whether email exists — don't leak
rateLimit('forgot_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 600);

$stmt = db()->prepare('SELECT * FROM pc_users WHERE email = ? AND email_verified_at IS NOT NULL LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    // Burn roughly the same wall-clock time as the real path below so response
    // timing can't be used to distinguish "no such account" from "email sent".
    password_verify('', '$2y$10$abcdefghijklmnopqrstuuOeWEwvVJ.tPXO1lF7bMbAX/z2p1SMy');
}

if ($user) {
    // Invalidate any existing reset tokens for this user
    db()->prepare('DELETE FROM pc_password_resets WHERE user_id = ?')->execute([$user['id']]);

    $token     = generateToken();
    $tokenHash = hashToken($token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    db()->prepare(
        'INSERT INTO pc_password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)'
    )->execute([$user['id'], $tokenHash, $expiresAt]);

    $resetUrl = APP_URL . '/reset-password?token=' . $token;
    sendPasswordResetEmail($user['email'], $user['display_name'], $resetUrl);
}

// Always return 200 — don't confirm whether email exists
jsonResponse(['message' => 'If that email is registered, you\'ll receive a reset link shortly.']);

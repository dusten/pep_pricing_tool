<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/email.php';

// POST /me/email  body: { new_email, password }
// Sends a confirmation link to the new address; old address stays active
// and functional until that link is used (see auth/verify_email_change.php).
method('POST');
$user = requireAuth();
$d    = input();

$newEmail = trim(strtolower($d['new_email'] ?? ''));
$password = (string)($d['password'] ?? '');

if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'A valid email address is required.'], 422);
if (!password_verify($password, $user['password_hash'])) jsonResponse(['error' => 'Password is incorrect.'], 401);

$stmt = db()->prepare('SELECT id FROM pc_users WHERE email = ? AND id != ? LIMIT 1');
$stmt->execute([$newEmail, $user['id']]);
if ($stmt->fetch()) jsonResponse(['error' => 'That email is already in use.'], 409);

$token = generateToken(16);
db()->prepare('UPDATE pc_users SET pending_email = ?, email_change_token = ? WHERE id = ?')
    ->execute([$newEmail, $token, $user['id']]);
cacheBustSession($user['_token_hash']); // so the immediate fetchMe() shows "confirmation pending"

$confirmUrl = APP_URL . '/verify-email-change?token=' . $token;
sendVerificationEmail($newEmail, $user['display_name'], $confirmUrl);

jsonResponse(['message' => "Confirmation link sent to $newEmail. Your current email stays active until you confirm."]);

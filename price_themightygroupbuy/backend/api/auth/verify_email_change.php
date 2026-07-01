<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /auth/verify-email-change?token=...
method('GET', 'POST');
$token = trim($_GET['token'] ?? input()['token'] ?? '');
if (!$token) jsonResponse(['error' => 'Confirmation token is required.'], 422);

$stmt = db()->prepare('SELECT * FROM pc_users WHERE email_change_token = ? LIMIT 1');
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user || !$user['pending_email']) jsonResponse(['error' => 'Invalid or already-used confirmation token.'], 404);

db()->prepare('UPDATE pc_users SET email = ?, pending_email = NULL, email_change_token = NULL WHERE id = ?')
    ->execute([$user['pending_email'], $user['id']]);

jsonResponse(['message' => 'Email address updated.']);

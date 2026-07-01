<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('POST');
$d = input();

$token    = trim($d['token'] ?? '');
$password = $d['password'] ?? '';

if (!$token)                jsonResponse(['error' => 'Reset token is required.'], 422);
if (strlen($password) < 8)  jsonResponse(['error' => 'Password must be at least 8 characters.'], 422);
if (strlen($password) > 200) jsonResponse(['error' => 'Password is too long.'], 422);

$tokenHash = hashToken($token);
$stmt = db()->prepare(
    'SELECT * FROM pc_password_resets WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1'
);
$stmt->execute([$tokenHash]);
$reset = $stmt->fetch();

if (!$reset) jsonResponse(['error' => 'This reset link is invalid or has expired.'], 404);

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE pc_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
    $pdo->prepare('UPDATE pc_password_resets SET used_at = NOW() WHERE id = ?')
        ->execute([$reset['id']]);
    // Invalidate all existing sessions for this user
    $pdo->prepare('DELETE FROM pc_sessions WHERE user_id = ?')->execute([$reset['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[reset-password] ' . $e->getMessage());
    jsonResponse(['error' => 'Something went wrong. Please try again.'], 500);
}

jsonResponse(['message' => 'Password reset successfully. You can now log in.']);

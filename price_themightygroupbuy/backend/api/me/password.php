<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /me/password  body: { current_password, new_password }
// Deletes every other session row so they stop matching requireAuth()'s join;
// this session survives because it's excluded by id, so the caller stays logged in.
method('POST');
$user = requireAuth();
$d    = input();

$current = (string)($d['current_password'] ?? '');
$new     = (string)($d['new_password'] ?? '');

if (!password_verify($current, $user['password_hash'])) {
    jsonResponse(['error' => 'Current password is incorrect.'], 401);
}
if (strlen($new) < 8) {
    jsonResponse(['error' => 'New password must be at least 8 characters.'], 422);
}
if (strlen($new) > 200) {
    jsonResponse(['error' => 'New password is too long.'], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE pc_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    $pdo->prepare('DELETE FROM pc_sessions WHERE user_id = ? AND id != ?')
        ->execute([$user['id'], $user['session_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[me/password] ' . $e->getMessage());
    jsonResponse(['error' => 'Something went wrong. Please try again.'], 500);
}

jsonResponse(['message' => 'Password changed. All other sessions have been signed out.']);

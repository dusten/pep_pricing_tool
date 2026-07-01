<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /me/sessions/revoke-all — sign out every session except this one
method('POST');
$user = requireAuth();

db()->prepare('DELETE FROM pc_sessions WHERE user_id = ? AND id != ?')
    ->execute([$user['id'], $user['session_id']]);

jsonResponse(['message' => 'All other sessions have been signed out.']);

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
$user = requireAuth();

$stmt = db()->prepare(
    'SELECT ip, user_agent, created_at FROM pc_login_history
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
);
$stmt->execute([$user['id']]);

jsonResponse(['logins' => $stmt->fetchAll()]);

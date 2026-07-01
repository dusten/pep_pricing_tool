<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('POST');

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
    $hash = hashToken($m[1]);
    db()->prepare('DELETE FROM pc_sessions WHERE token_hash = ?')->execute([$hash]);
    cacheBustSession($hash);
}

jsonResponse(['message' => 'Logged out.']);

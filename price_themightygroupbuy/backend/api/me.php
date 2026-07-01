<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

method('GET', 'PATCH');
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $d      = input();
    $fields = [];
    $vals   = [];

    if (isset($d['theme']) && in_array($d['theme'], ['system','light','dark'], true)) {
        $fields[] = 'theme = ?';
        $vals[]   = $d['theme'];
    }
    if (isset($d['display_name']) && mb_strlen(trim($d['display_name'])) >= 2) {
        $fields[] = 'display_name = ?';
        $vals[]   = trim($d['display_name']);
    }

    if ($fields) {
        $vals[] = $user['id'];
        db()->prepare('UPDATE pc_users SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($vals);
    }

    // Re-fetch updated user
    $stmt = db()->prepare('SELECT * FROM pc_users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch();
}

jsonResponse(userShape($user));

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

method('GET', 'POST');

// Public settings safe to expose to the frontend
const PUBLIC_SETTINGS = [
    'waitlist_mode',
    'maintenance_mode',
    'free_tier_query_limit',
    'free_tier_window_hours',
    'annual_discount_months_free',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Admins (valid bearer token) get every setting; everyone else gets the public subset.
    $isAdmin = false;
    if (preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $s = db()->prepare(
            'SELECT u.is_admin FROM pc_users u JOIN pc_sessions s ON s.user_id = u.id
             WHERE s.token_hash = ? AND s.expires_at > NOW() LIMIT 1'
        );
        $s->execute([hashToken($m[1])]);
        $isAdmin = !empty($s->fetch()['is_admin']);
    }

    if ($isAdmin) {
        $rows = db()->query('SELECT `key`, value FROM pc_app_settings')->fetchAll();
    } else {
        $stmt = db()->prepare(
            'SELECT `key`, value FROM pc_app_settings WHERE `key` IN (' .
            implode(',', array_fill(0, count(PUBLIC_SETTINGS), '?')) . ')'
        );
        $stmt->execute(PUBLIC_SETTINGS);
        $rows = $stmt->fetchAll();
    }
    $settings = [];
    foreach ($rows as $row) $settings[$row['key']] = $row['value'];
    jsonResponse($settings);
}

// POST — admin only
$admin = requireAdmin();
$d     = input();

$allowed = [
    'waitlist_mode', 'maintenance_mode', 'referral_credit_usd',
    'free_tier_query_limit', 'free_tier_window_hours',
    'annual_discount_months_free', 'session_lifetime_days',
];

$updated = [];
$stmt    = db()->prepare('INSERT INTO pc_app_settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = ?');
foreach ($allowed as $key) {
    if (!array_key_exists($key, $d)) continue;
    $val = (string)$d[$key];
    $stmt->execute([$key, $val, $val]);
    $updated[$key] = $val;
}

if ($updated) {
    logAdminAction((int)$admin['id'], 'update_app_settings', $updated);
}

jsonResponse(['updated' => $updated]);

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
    $stmt = db()->prepare(
        'SELECT `key`, value FROM pc_app_settings WHERE `key` IN (' .
        implode(',', array_fill(0, count(PUBLIC_SETTINGS), '?')) . ')'
    );
    $stmt->execute(PUBLIC_SETTINGS);
    $settings = [];
    foreach ($stmt->fetchAll() as $row) $settings[$row['key']] = $row['value'];
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

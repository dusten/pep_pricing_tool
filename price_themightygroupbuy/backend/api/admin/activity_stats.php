<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/activity-stats?range=day|week|month
// Signups, logins, searches, and WhatsApp-link clicks over the selected
// trailing window. Ships as a first cut for the admin Overview tab — no
// per-vendor/per-user breakdown yet, just totals.
method('GET');
requireAdmin();

$RANGES = ['day' => '1 DAY', 'week' => '7 DAY', 'month' => '30 DAY'];
$range  = array_key_exists($_GET['range'] ?? '', $RANGES) ? $_GET['range'] : 'day';
$interval = $RANGES[$range];

$data = cacheGet('admin_activity_stats', $range, 600, function () use ($interval, $range) {
    $count = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
    return [
        'range'           => $range,
        'signups'         => $count("SELECT COUNT(*) FROM pc_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)"),
        'logins'          => $count("SELECT COUNT(*) FROM pc_login_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)"),
        'searches'        => $count("SELECT COUNT(*) FROM pc_query_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)"),
        'whatsapp_clicks' => $count("SELECT COUNT(*) FROM pc_whatsapp_clicks WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)"),
    ];
});

jsonResponse($data);

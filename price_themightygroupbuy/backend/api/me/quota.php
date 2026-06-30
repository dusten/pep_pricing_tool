<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
$user = requireAuth();

$limit   = (int)getAppSetting('free_tier_query_limit', '3');
$hours   = (int)getAppSetting('free_tier_window_hours', '72');
$isFree  = $user['tier'] === 'free' ||
           !in_array($user['tier_status'], ['active', 'trialing'], true);

if (!$isFree) {
    // Paid users: unlimited
    jsonResponse([
        'tier'      => $user['tier'],
        'unlimited' => true,
        'used'      => null,
        'limit'     => null,
        'remaining' => null,
        'resets_at' => null,
    ]);
}

// Count distinct filter hashes in the rolling window
$stmt = db()->prepare(
    'SELECT COUNT(DISTINCT filter_hash) AS used,
            MIN(created_at)            AS oldest
     FROM pc_query_log
     WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
);
$stmt->execute([$user['id'], $hours]);
$row  = $stmt->fetch();
$used = (int)($row['used'] ?? 0);

$resetsAt = $row['oldest']
    ? date('Y-m-d H:i:s', strtotime($row['oldest']) + $hours * 3600)
    : null;

jsonResponse([
    'tier'      => 'free',
    'unlimited' => false,
    'used'      => $used,
    'limit'     => $limit,
    'remaining' => max(0, $limit - $used),
    'resets_at' => $resetsAt,
]);

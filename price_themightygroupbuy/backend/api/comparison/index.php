<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/comparison_query.php';

method('GET');
$user = requireAuth();
$startedAt = microtime(true);

$productIds = array_map('intval', (array)($_GET['products'] ?? []));
$vendorIds  = array_map('intval', (array)($_GET['vendors']  ?? []));
$specIds    = array_map('intval', (array)($_GET['specs']    ?? []));
$category   = in_array($_GET['category'] ?? '', ['glp1','peptide','hormone','blend','consumable','other'], true)
    ? $_GET['category'] : null;
$multiOnly  = in_array($_GET['multi_only'] ?? '', ['1', 'true'], true);

// ── Free-tier metering ──────────────────────────────────────────────
$isAdmin = !empty($user['is_admin']);
$isFree  = !$isAdmin && ($user['tier'] === 'free' || !in_array($user['tier_status'], ['active', 'trialing'], true));

sort($productIds); sort($vendorIds); sort($specIds); // normalize before hashing so param order doesn't matter
$filterHash = sha1(json_encode(compact('productIds', 'vendorIds', 'specIds', 'category', 'multiOnly')));

if ($isFree) {
    $limit = (int)getAppSetting('free_tier_query_limit', '3');
    $hours = (int)getAppSetting('free_tier_window_hours', '72');
    $stmt  = db()->prepare(
        'SELECT DISTINCT filter_hash FROM pc_query_log WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
    );
    $stmt->execute([$user['id'], $hours]);
    $usedHashes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $isNewQuery = !in_array($filterHash, $usedHashes, true);

    if ($isNewQuery && count($usedHashes) >= $limit) {
        $oldest = db()->prepare(
            'SELECT MIN(created_at) FROM pc_query_log WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
        );
        $oldest->execute([$user['id'], $hours]);
        $resetsAt = date('Y-m-d H:i:s', strtotime((string)$oldest->fetchColumn()) + $hours * 3600);
        jsonResponse([
            'error' => 'query_limit', 'remaining' => 0, 'resets_at' => $resetsAt,
            'message' => 'You have used all comparisons for this period. Upgrade for unlimited access.',
        ], 402);
    }
    if ($isNewQuery) {
        db()->prepare('INSERT INTO pc_query_log (user_id, filter_hash) VALUES (?,?)')->execute([$user['id'], $filterHash]);
    }
}

$rows = runComparisonQuery($productIds, $vendorIds, $specIds, $category, $multiOnly);

// ── Query performance logging (for the admin replay/debug tool) ────────
// Budget: ~1-2s p95. duration_ms over that is flagged slow so admins can
// filter straight to the queries worth investigating.
$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
db()->prepare(
    'INSERT INTO pc_comparison_log (user_id, selection_params, duration_ms, result_count, slow_flag) VALUES (?,?,?,?,?)'
)->execute([
    $user['id'],
    json_encode(compact('productIds', 'vendorIds', 'specIds', 'category', 'multiOnly')),
    $durationMs,
    count($rows),
    $durationMs > 1500 ? 1 : 0,
]);

jsonResponse(['rows' => $rows]);

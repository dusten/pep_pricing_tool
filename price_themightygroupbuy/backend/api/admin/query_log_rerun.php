<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/comparison_query.php';

// POST /admin/query-log/{id}/rerun — re-executes the logged query live for
// debugging. Deliberately does not write a new pc_comparison_log row (this
// is an admin replaying a report, not a real user query) and is never cached.
method('POST');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_comparison_log WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$log = $stmt->fetch();
if (!$log) jsonResponse(['error' => 'Query log entry not found.'], 404);

$p = json_decode($log['selection_params'], true) ?? [];

$startedAt = microtime(true);
$rows = runComparisonQuery(
    (array)($p['productIds'] ?? []),
    (array)($p['vendorIds']  ?? []),
    (array)($p['specIds']    ?? []),
    $p['category'] ?? null,
    (bool)($p['multiOnly']   ?? false),
    (bool)($p['verifiedOnly'] ?? false)
);
$newDurationMs = (int)round((microtime(true) - $startedAt) * 1000);

logAdminAction((int)$admin['id'], 'rerun_comparison_query', ['log_id' => $id]);

jsonResponse([
    'original_duration_ms' => (int)$log['duration_ms'],
    'new_duration_ms'      => $newDurationMs,
    'result_count'         => count($rows),
    'rows'                 => $rows,
]);

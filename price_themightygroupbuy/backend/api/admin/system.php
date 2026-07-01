<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/system — live infra health. Always computed fresh (never cached —
// that would defeat the point of an ops dashboard). Manual-refresh by design;
// the frontend controls polling, this endpoint just answers on request.
method('GET');
requireAdmin();

// ── Cache ────────────────────────────────────────────────────────
$cache = ['available' => false];
$mc = mc();
if ($mc) {
    $stats = $mc->getStats();
    $s = $stats ? reset($stats) : null;
    if ($s) {
        $hits   = (int)($s['get_hits']   ?? 0);
        $misses = (int)($s['get_misses'] ?? 0);
        $total  = $hits + $misses;
        $cache = [
            'available'    => true,
            'hit_rate_pct' => $total > 0 ? round($hits / $total * 100, 1) : null,
            'bytes_used'   => (int)($s['bytes'] ?? 0),
            'bytes_limit'  => (int)($s['limit_maxbytes'] ?? 0),
            'uptime_sec'   => (int)($s['uptime'] ?? 0),
            'curr_items'   => (int)($s['curr_items'] ?? 0),
        ];
    }
}

// ── Database ─────────────────────────────────────────────────────
$statusRows = db()->query(
    "SHOW STATUS WHERE Variable_name IN ('Threads_connected','Questions','Slow_queries','Uptime')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$database = [
    'connections'  => (int)($statusRows['Threads_connected'] ?? 0),
    'total_queries'=> (int)($statusRows['Questions'] ?? 0),
    'slow_queries' => (int)($statusRows['Slow_queries'] ?? 0),
    'uptime_sec'   => (int)($statusRows['Uptime'] ?? 0),
];

// ── Slow query log — read from performance_schema's own aggregated-by-digest
// table rather than tailing/parsing the slow-query log file: it's already
// deduplicated (one row per normalized query shape, not per execution) and
// needs no server config changes to populate.
$slowQueries = [];
try {
    $slowQueries = db()->query(
        "SELECT DIGEST_TEXT AS query, COUNT_STAR AS exec_count,
                ROUND(AVG_TIMER_WAIT / 1000000000, 2) AS avg_ms,
                ROUND(MAX_TIMER_WAIT / 1000000000, 2) AS max_ms
         FROM performance_schema.events_statements_summary_by_digest
         WHERE DIGEST_TEXT IS NOT NULL
         ORDER BY AVG_TIMER_WAIT DESC LIMIT 15"
    )->fetchAll();
    foreach ($slowQueries as &$q) {
        $q['exec_count'] = (int)$q['exec_count'];
        $q['avg_ms']     = (float)$q['avg_ms'];
        $q['max_ms']     = (float)$q['max_ms'];
    }
} catch (Throwable $e) {
    // performance_schema may be disabled — degrade gracefully, don't fail the whole tab
    error_log('[admin/system] performance_schema unavailable: ' . $e->getMessage());
}

// ── Maintenance run history ─────────────────────────────────────
$maintenance = db()->query(
    'SELECT job, status, details, ran_at FROM pc_maintenance_runs ORDER BY ran_at DESC LIMIT 20'
)->fetchAll();

jsonResponse([
    'cache'         => $cache,
    'database'      => $database,
    'slow_queries'  => $slowQueries,
    'maintenance'   => $maintenance,
]);

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
// GLOBAL, not session, status — every request opens a fresh PDO connection
// (no persistent connections), so plain SHOW STATUS reads that new
// connection's own near-empty session counters and never visibly moves.
// Threads_connected/Uptime stay server-wide (accurate either way — this
// box's total connection/uptime state, not per-schema). total_queries
// deliberately does NOT use MySQL's global Questions counter — that's
// shared across both apps on this box (see CountingPDO in config.php for
// why) — it reads this app's own Memcached-tracked count instead.
$statusRows = db()->query(
    "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Slow_queries','Uptime')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$appQueryCount = $mc ? (int)($mc->get('app_query_count') ?: 0) : null;

$database = [
    'connections'     => (int)($statusRows['Threads_connected'] ?? 0),
    'total_queries'   => $appQueryCount,      // this app only, since Memcached last restarted
    'slow_queries'    => (int)($statusRows['Slow_queries'] ?? 0), // server-wide; see the dedicated slow-query section below for this app's own
    'uptime_sec'      => (int)($statusRows['Uptime'] ?? 0),
];

// Slow queries live in their own endpoint now (admin/slow_queries.php) —
// pc_slow_query_cache, fed hourly by the pc_import_slow_queries EVENT from
// this db's own rows in mysql.slow_log (server-wide table, shared with the
// grp app on this box; both events are scoped by `db` so they don't race).

// ── Maintenance run history ─────────────────────────────────────
$maintenance = db()->query(
    'SELECT job, status, details, ran_at FROM pc_maintenance_runs ORDER BY ran_at DESC LIMIT 20'
)->fetchAll();

jsonResponse([
    'cache'         => $cache,
    'database'      => $database,
    'maintenance'   => $maintenance,
]);

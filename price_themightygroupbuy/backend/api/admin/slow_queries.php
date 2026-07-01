<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/slow-queries?status=new|acknowledged|resolved
// Fed hourly by the pc_import_slow_queries EVENT — see migrations/005_slow_query_cache.sql.
method('GET');
requireAdmin();

$status = in_array($_GET['status'] ?? '', ['new', 'acknowledged', 'resolved'], true) ? $_GET['status'] : null;

$where  = [];
$params = [];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }

$sql = 'SELECT id, query_time_secs, lock_time_secs, rows_sent, rows_examined, query_sql,
               first_seen_at, last_seen_at, occurrence_count, status, status_note, status_updated_at
        FROM pc_slow_query_cache' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY status = "resolved" ASC, query_time_secs DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['id']               = (int)$r['id'];
    $r['query_time_secs']  = (float)$r['query_time_secs'];
    $r['lock_time_secs']   = (float)$r['lock_time_secs'];
    $r['rows_sent']        = (int)$r['rows_sent'];
    $r['rows_examined']    = (int)$r['rows_examined'];
    $r['occurrence_count'] = (int)$r['occurrence_count'];
}

jsonResponse(['queries' => $rows]);

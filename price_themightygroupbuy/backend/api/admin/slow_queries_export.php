<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/slow-queries/export?status=new|acknowledged|resolved — CSV of every
// tracked slow query with full stats (no LIMIT 100, unlike the list endpoint).
method('GET');
$admin = requireAdmin();

$status = in_array($_GET['status'] ?? '', ['new', 'acknowledged', 'resolved'], true) ? $_GET['status'] : null;

$sql = 'SELECT id, query_time_secs, lock_time_secs, rows_sent, rows_examined, occurrence_count,
               first_seen_at, last_seen_at, status, status_note, status_updated_at, query_sql
        FROM pc_slow_query_cache' . ($status ? ' WHERE status = ?' : '') . '
        ORDER BY query_time_secs DESC';
$stmt = db()->prepare($sql);
$stmt->execute($status ? [$status] : []);
$rows = $stmt->fetchAll();

logAdminAction((int)$admin['id'], 'export_slow_queries_csv', ['count' => count($rows), 'status' => $status]);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="slow-queries-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['id', 'query_time_secs', 'lock_time_secs', 'rows_sent', 'rows_examined', 'occurrence_count',
               'first_seen_at', 'last_seen_at', 'status', 'status_note', 'status_updated_at', 'query_sql']);
foreach ($rows as $r) {
    fputcsv($out, [$r['id'], $r['query_time_secs'], $r['lock_time_secs'], $r['rows_sent'], $r['rows_examined'],
                   $r['occurrence_count'], $r['first_seen_at'], $r['last_seen_at'], $r['status'],
                   $r['status_note'], $r['status_updated_at'], $r['query_sql']]);
}
fclose($out);
exit;

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/waitlist/export?ids=1,2,3 — CSV of all entries, or just the given subset
method('GET');
$admin = requireAdmin();

$ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
if ($ids) {
    $stmt = db()->prepare(
        'SELECT * FROM pc_waitlist WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at DESC'
    );
    $stmt->execute($ids);
} else {
    $stmt = db()->query('SELECT * FROM pc_waitlist ORDER BY created_at DESC');
}
$rows = $stmt->fetchAll();

logAdminAction((int)$admin['id'], 'export_waitlist_csv', ['count' => count($rows), 'subset' => (bool)$ids]);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="waitlist-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['id', 'email', 'name', 'status', 'invited_at', 'joined_at', 'created_at']);
foreach ($rows as $r) {
    $status = $r['joined_at'] ? 'confirmed' : ($r['invited_at'] ? 'invited' : 'pending');
    fputcsv($out, [$r['id'], $r['email'], $r['name'], $status, $r['invited_at'], $r['joined_at'], $r['created_at']]);
}
fclose($out);
exit;

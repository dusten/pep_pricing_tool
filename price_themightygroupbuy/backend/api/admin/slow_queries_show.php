<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// PATCH /admin/slow-queries/{id}  body: { status?, status_note? }
method('PATCH');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);
$d     = input();

$fields = [];
$vals   = [];
if (isset($d['status']) && in_array($d['status'], ['new', 'acknowledged', 'resolved'], true)) {
    $fields[] = 'status = ?';
    $vals[]   = $d['status'];
    $fields[] = 'status_updated_at = NOW()';
}
if (array_key_exists('status_note', $d)) {
    $fields[] = 'status_note = ?';
    $vals[]   = trim((string)$d['status_note']) ?: null;
}
if (!$fields) jsonResponse(['error' => 'No valid fields to update.'], 422);

$vals[] = $id;
db()->prepare('UPDATE pc_slow_query_cache SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
logAdminAction((int)$admin['id'], 'update_slow_query_status', ['id' => $id, 'fields' => array_keys($d)]);

jsonResponse(['message' => 'Updated.']);

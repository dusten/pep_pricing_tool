<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET  /admin/coa-queue              — next pending submission (single-card queue)
// GET  /admin/coa-queue?list=1[&status=pending|approved|rejected] — full list, any status
// POST /admin/coa-queue/{id}/approve
// POST /admin/coa-queue/{id}/reject
// POST /admin/coa-queue/{id}/revoke  — send an approved/rejected submission back to pending
method('GET', 'POST');
$admin  = requireAdmin();
$id     = isset($PARAMS['id']) ? (int)$PARAMS['id'] : null;
$action = $PARAMS['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list'])) {
    $where  = '';
    $params = [];
    if (in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true)) {
        $where = 'WHERE cs.status = ?';
        $params[] = $_GET['status'];
    }
    $stmt = db()->prepare(
        "SELECT cs.*, v.display_name AS vendor_name, p.canonical_name AS product_name, u.display_name AS submitted_by
         FROM pc_coa_submissions cs
         JOIN pc_vendors v ON v.id = cs.vendor_id
         LEFT JOIN pc_products p ON p.id = cs.product_id
         JOIN pc_users u ON u.id = cs.user_id
         $where
         ORDER BY cs.created_at DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id']         = (int)$row['id'];
        $row['vendor_id']  = (int)$row['vendor_id'];
        $row['product_id'] = $row['product_id'] !== null ? (int)$row['product_id'] : null;
    }
    jsonResponse(['rows' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->query(
        "SELECT cs.*, v.display_name AS vendor_name, p.canonical_name AS product_name, u.display_name AS submitted_by
         FROM pc_coa_submissions cs
         JOIN pc_vendors v ON v.id = cs.vendor_id
         LEFT JOIN pc_products p ON p.id = cs.product_id
         JOIN pc_users u ON u.id = cs.user_id
         WHERE cs.status = 'pending'
         ORDER BY cs.created_at ASC
         LIMIT 1"
    );
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['done' => true]);

    $row['id']         = (int)$row['id'];
    $row['vendor_id']  = (int)$row['vendor_id'];
    $row['product_id'] = $row['product_id'] !== null ? (int)$row['product_id'] : null;
    jsonResponse($row);
}

if (!$id || !in_array($action, ['approve', 'reject', 'revoke'], true)) {
    jsonResponse(['error' => 'Not found.'], 404);
}

$stmt = db()->prepare('SELECT id FROM pc_coa_submissions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
if (!$stmt->fetch()) jsonResponse(['error' => 'Submission not found.'], 404);

$newStatus = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'pending'][$action];
db()->prepare('UPDATE pc_coa_submissions SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
    ->execute([$newStatus, $admin['id'], $id]);

logAdminAction((int)$admin['id'], "{$action}_coa_submission", ['submission_id' => $id, 'new_status' => $newStatus]);
jsonResponse(['message' => ['approve' => 'Approved.', 'reject' => 'Rejected.', 'revoke' => 'Sent back to pending.'][$action]]);

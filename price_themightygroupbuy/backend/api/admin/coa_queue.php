<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET  /admin/coa-queue              — next pending submission (single-card queue)
// POST /admin/coa-queue/{id}/approve
// POST /admin/coa-queue/{id}/reject
method('GET', 'POST');
$admin  = requireAdmin();
$id     = isset($PARAMS['id']) ? (int)$PARAMS['id'] : null;
$action = $PARAMS['action'] ?? null;

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

if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(['error' => 'Not found.'], 404);
}

$stmt = db()->prepare('SELECT id FROM pc_coa_submissions WHERE id = ? AND status = ? LIMIT 1');
$stmt->execute([$id, 'pending']);
if (!$stmt->fetch()) jsonResponse(['error' => 'Submission not found (or already reviewed).'], 404);

db()->prepare('UPDATE pc_coa_submissions SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
    ->execute([$action === 'approve' ? 'approved' : 'rejected', $admin['id'], $id]);

logAdminAction((int)$admin['id'], "{$action}_coa_submission", ['submission_id' => $id]);
jsonResponse(['message' => $action === 'approve' ? 'Approved.' : 'Rejected.']);

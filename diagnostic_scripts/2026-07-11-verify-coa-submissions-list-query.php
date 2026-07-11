<?php
declare(strict_types=1);

/**
 * Verifies the SQL join used by the new admin COA-queue list endpoint
 * (GET /admin/coa-queue?list=1, backend/api/admin/coa_queue.php) resolves
 * vendor/product/submitter names correctly across every submission, not
 * just the single next-pending one the old single-card queue read.
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-coa-submissions-list-query.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$stmt = db()->query(
    "SELECT cs.id, cs.status, v.display_name AS vendor_name, p.canonical_name AS product_name, u.display_name AS submitted_by
     FROM pc_coa_submissions cs
     JOIN pc_vendors v ON v.id = cs.vendor_id
     LEFT JOIN pc_products p ON p.id = cs.product_id
     JOIN pc_users u ON u.id = cs.user_id
     ORDER BY cs.created_at DESC LIMIT 200"
);
foreach ($stmt->fetchAll() as $r) {
    echo "{$r['id']} | {$r['status']} | {$r['vendor_name']} | {$r['product_name']} | {$r['submitted_by']}\n";
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /coa/vendor-products?vendor_id=X — product dropdown for the COA
// submission form, scoped to what this vendor actually has an active price
// for (any authenticated user, not admin-only).
method('GET');
requireAuth();
$vendorId = (int)($_GET['vendor_id'] ?? 0);
if (!$vendorId) jsonResponse(['error' => 'vendor_id is required.'], 422);

$stmt = db()->prepare(
    'SELECT DISTINCT p.id, p.canonical_name FROM pc_products p
     JOIN pc_prices pr ON pr.product_id = p.id AND pr.vendor_id = ? AND pr.is_active = 1
     ORDER BY p.canonical_name'
);
$stmt->execute([$vendorId]);
$products = $stmt->fetchAll();
foreach ($products as &$p) $p['id'] = (int)$p['id'];

jsonResponse(['products' => $products]);

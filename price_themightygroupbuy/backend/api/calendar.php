<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

// GET /calendar?month=YYYY-MM — price-change events grouped by day for that month.
method('GET');
requireAuth();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonResponse(['error' => 'month must be YYYY-MM.'], 422);

$stmt = db()->prepare(
    "SELECT DATE(pr.created_at) AS day, v.display_name AS vendor, p.canonical_name AS product,
            s.spec_label AS spec, pr.price_usd, pr.price_per_unit
     FROM pc_prices pr
     JOIN pc_vendors v        ON v.id = pr.vendor_id
     JOIN pc_products p       ON p.id = pr.product_id
     JOIN pc_specifications s ON s.id = pr.specification_id
     WHERE pr.is_active = 1 AND DATE_FORMAT(pr.created_at, '%Y-%m') = ?
     ORDER BY pr.created_at DESC"
);
$stmt->execute([$month]);

$byDay = [];
foreach ($stmt->fetchAll() as $r) {
    $byDay[$r['day']][] = [
        'vendor'  => $r['vendor'],
        'product' => $r['product'],
        'spec'    => $r['spec'],
        'price'   => (float)$r['price_usd'],
        'price_per_unit' => (float)$r['price_per_unit'],
    ];
}

jsonResponse(['month' => $month, 'days' => $byDay]);

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /comparison/filters — vendor/product options for the filter bar (any authed user)
method('GET');
requireAuth();

// Same for every user — only changes when prices/vendors/products change,
// so it shares the 'pricing_data' cache group with the comparison results.
$data = cacheGet('pricing_data', 'filters', 300, function () {
    $vendors = db()->query(
        "SELECT DISTINCT v.id, v.display_name FROM pc_vendors v
         JOIN pc_prices pr ON pr.vendor_id = v.id AND pr.is_active = 1
         WHERE v.is_active = 1 ORDER BY v.display_name"
    )->fetchAll();
    $products = db()->query(
        "SELECT DISTINCT p.id, p.canonical_name, p.category FROM pc_products p
         JOIN pc_prices pr ON pr.product_id = p.id AND pr.is_active = 1
         ORDER BY p.canonical_name"
    )->fetchAll();
    foreach ($vendors as &$v)  { $v['id'] = (int)$v['id']; }
    foreach ($products as &$p) { $p['id'] = (int)$p['id']; }
    return ['vendors' => $vendors, 'products' => $products];
});

jsonResponse($data);

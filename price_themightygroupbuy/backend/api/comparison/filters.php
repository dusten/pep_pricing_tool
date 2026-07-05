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
        "SELECT DISTINCT v.id, v.display_name, v.is_verified FROM pc_vendors v
         JOIN pc_prices pr ON pr.vendor_id = v.id AND pr.is_active = 1
         WHERE v.is_active = 1 ORDER BY v.display_name"
    )->fetchAll();
    $products = db()->query(
        "SELECT DISTINCT p.id, p.canonical_name FROM pc_products p
         JOIN pc_prices pr ON pr.product_id = p.id AND pr.is_active = 1
         ORDER BY p.canonical_name"
    )->fetchAll();
    $classifications = db()->query('SELECT id, name FROM pc_classifications ORDER BY name')->fetchAll();
    // Real tiers in the data, not a hardcoded list — a new tier a vendor
    // introduces later shows up here without a code change.
    $tiers = db()->query('SELECT DISTINCT tier_kit_size FROM pc_prices WHERE is_active = 1 ORDER BY tier_kit_size')
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($vendors as &$v)  { $v['id'] = (int)$v['id']; $v['is_verified'] = (bool)$v['is_verified']; }
    foreach ($products as &$p) { $p['id'] = (int)$p['id']; }
    foreach ($classifications as &$c) { $c['id'] = (int)$c['id']; }
    $tiers = array_map('intval', $tiers);
    return ['vendors' => $vendors, 'products' => $products, 'classifications' => $classifications, 'tiers' => $tiers];
});

jsonResponse($data);

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

// GET /export/full — every active product/vendor/spec/price row as one JSON
// payload, Expert tier only. No filters — broader than the Comparison view.
method('GET');
$user = requireTier('expert');

$data = [
    'products' => db()->query(
        'SELECT id, canonical_name, notes FROM pc_products ORDER BY canonical_name'
    )->fetchAll(),
    'classifications' => db()->query(
        'SELECT id, name FROM pc_classifications ORDER BY name'
    )->fetchAll(),
    'product_classifications' => db()->query(
        'SELECT product_id, classification_id FROM pc_product_classifications'
    )->fetchAll(),
    'specifications' => db()->query(
        'SELECT id, product_id, spec_label, numeric_value, unit FROM pc_specifications ORDER BY product_id, numeric_value'
    )->fetchAll(),
    'vendors' => db()->query(
        'SELECT id, display_name, website, is_verified FROM pc_vendors WHERE is_active = 1 ORDER BY display_name'
    )->fetchAll(),
    'prices' => db()->query(
        'SELECT vendor_id, product_id, specification_id, price_usd, price_per_unit, kit_vial_count,
                tier_kit_size, vendor_sku, non_standard_kit
         FROM pc_prices WHERE is_active = 1'
    )->fetchAll(),
];

logUserAction((int)$user['id'], 'export_full', ['products' => count($data['products']), 'prices' => count($data['prices'])]);
cacheBust('admin_activity_trend'); // so the admin Activity dashboard reflects this export immediately

header('Content-Disposition: attachment; filename="full-export-' . date('Y-m-d') . '.json"');
jsonResponse($data);

<?php
declare(strict_types=1);

/**
 * Read-only: groups every active price row on products 33/55 (Lipo-C
 * family) by (vendor_id, normalized vendor_sku, price) to surface exact
 * duplicate listings created by repeated Claude reprocessing of the same
 * vendor file over time. Found 7 duplicate groups, informing
 * migration_scripts/2026-07-12-untangle_lipoc_mic_specs.php. See
 * Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md.
 */
chdir('/home/ec2-user/price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$stmt = db()->query(
    "SELECT pr.id price_id, pr.vendor_id, v.display_name, pr.product_id, pr.specification_id, s.spec_label, s.numeric_value, pr.price_usd, pr.vendor_sku
     FROM pc_prices pr JOIN pc_vendors v ON v.id=pr.vendor_id JOIN pc_specifications s ON s.id=pr.specification_id
     WHERE pr.product_id IN (33,55) AND pr.is_active=1"
);
$rows = $stmt->fetchAll();

// Group by vendor + normalized sku (strip case) + price to find same-listing duplicates
$groups = [];
foreach ($rows as $r) {
    $sku = strtolower(trim((string)$r['vendor_sku']));
    if ($sku === '') $sku = 'NOSKU:' . $r['vendor_id']; // don't cross-match blank-sku rows from different intents
    $key = $r['vendor_id'] . '|' . $sku . '|' . number_format((float)$r['price_usd'], 2);
    $groups[$key][] = $r;
}
foreach ($groups as $key => $g) {
    if (count($g) > 1) {
        echo "DUP GROUP ($key):\n";
        foreach ($g as $r) {
            echo "  price_id={$r['price_id']} product={$r['product_id']} spec={$r['specification_id']} ({$r['numeric_value']}mg \"{$r['spec_label']}\")\n";
        }
    }
}

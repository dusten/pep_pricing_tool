<?php
declare(strict_types=1);

/**
 * Read-only: dumps untruncated spec_label/numeric_value and every active
 * price row (vendor, price, vendor_sku) for products 33/55 (Lipo-C family).
 * Written to work around `mysql -e` CLI output truncating VARCHAR columns
 * in the terminal, which made it impossible to read full spec_label text
 * directly. Part of the 2026-07-12 Lipo-C/MIC untangling investigation, see
 * Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md.
 */
chdir('/home/ec2-user/price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$stmt = db()->query("SELECT id, product_id, spec_label, numeric_value FROM pc_specifications WHERE product_id IN (33,55) ORDER BY product_id, numeric_value");
foreach ($stmt->fetchAll() as $r) {
    echo "{$r['id']} | p{$r['product_id']} | {$r['numeric_value']} | {$r['spec_label']}\n";
}

echo "\n--- active prices per spec ---\n";
$stmt2 = db()->query(
    "SELECT pr.specification_id, pr.id price_id, v.display_name, pr.price_usd, pr.vendor_sku
     FROM pc_prices pr JOIN pc_vendors v ON v.id=pr.vendor_id
     WHERE pr.product_id IN (33,55) AND pr.is_active=1 ORDER BY pr.specification_id, v.display_name"
);
foreach ($stmt2->fetchAll() as $r) {
    echo "spec={$r['specification_id']} price_id={$r['price_id']} {$r['display_name']} \${$r['price_usd']} sku={$r['vendor_sku']}\n";
}

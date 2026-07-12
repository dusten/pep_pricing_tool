<?php
declare(strict_types=1);

/**
 * Verifies the price-distribution feature's 75% coverage gate correctly
 * excludes a low-coverage item (1-2 vendors) and that unit_stdev is null
 * when n<3, using a real low-coverage (product, spec) pair pulled live
 * from the catalog rather than a synthetic one.
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-distribution-coverage-gate.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/comparison_query.php';

$total = getActiveVendorCount();

$stmt = db()->query(
    "SELECT pr.product_id, pr.specification_id, p.canonical_name, s.spec_label, COUNT(DISTINCT pr.vendor_id) AS vc
     FROM pc_prices pr
     JOIN pc_products p ON p.id = pr.product_id
     JOIN pc_specifications s ON s.id = pr.specification_id
     JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1
     WHERE pr.is_active = 1 AND pr.tier_kit_size = 1
     GROUP BY pr.product_id, pr.specification_id
     HAVING vc <= 2
     LIMIT 1"
);
$low = $stmt->fetch();
echo "Low-coverage test: {$low['canonical_name']} {$low['spec_label']} ({$low['vc']}/{$total} vendors)\n";

$rows = runComparisonQuery([(int)$low['product_id']], [], [(int)$low['specification_id']], [], false);
$row  = $rows[0];
$coverage = round(count($row['vendors']) / $total * 100, 1);
echo "coverage_pct=$coverage -> qualifies=" . ($coverage >= 75 ? 'true (WRONG, should be false)' : 'false (correct)') . "\n";
echo "unit_stdev (n=" . count($row['vendors']) . "): " . json_encode($row['stats']['unit_stdev']) . " (should be null if n<3)\n";

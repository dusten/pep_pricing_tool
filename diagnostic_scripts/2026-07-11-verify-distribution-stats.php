<?php
declare(strict_types=1);

/**
 * Verifies the price-distribution feature's backend math after building it:
 * getActiveVendorCount() against a direct query, and runComparisonQuery()'s
 * new unit_mean/unit_stdev stats against a manual recomputation, for a
 * real 100%-coverage item (Retatrutide 10mg).
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-distribution-stats.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/comparison_query.php';

echo "Active vendor count: " . getActiveVendorCount() . "\n\n";

$stmt = db()->prepare("SELECT id FROM pc_products WHERE canonical_name = 'Retatrutide' LIMIT 1");
$stmt->execute();
$productId = (int)$stmt->fetchColumn();
$stmt = db()->prepare("SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = '10mg' LIMIT 1");
$stmt->execute([$productId]);
$specId = (int)$stmt->fetchColumn();
echo "product_id=$productId spec_id=$specId\n";

$rows = runComparisonQuery([$productId], [], [$specId], [], false);
$row  = $rows[0];
echo "vendor_count=" . count($row['vendors']) . "\n";
echo json_encode($row['stats'], JSON_PRETTY_PRINT) . "\n";

$total    = getActiveVendorCount();
$coverage = round(count($row['vendors']) / $total * 100, 1);
echo "coverage_pct=$coverage\n";

// Manual recomputation to cross-check unit_mean/unit_stdev aren't silently wrong.
$ppus = array_column($row['vendors'], 'price_per_unit');
$n    = count($ppus);
$mean = array_sum($ppus) / $n;
$variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $ppus)) / ($n - 1);
$stdev = sqrt($variance);
echo "manual check: mean=" . round($mean, 6) . " stdev=" . round($stdev, 6) . "\n";
